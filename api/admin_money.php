<?php
/**
 * GET  /api/admin/money  — "what has Style-LORE actually made, and what is it
 * actually costing?", answered live, from every source at once.
 * POST /api/admin/money  — update the figures that have no API.
 *
 * WHY THIS EXISTS. The net-profit model lived in a calculator artifact with
 * hand-typed assumptions, so it was a forecast, not a fact. Money arrives in
 * four different places — Stripe for the website, Google Play and (later)
 * Apple through RevenueCat, and Amazon Associates — and until now nothing
 * added them up. This endpoint is the one place that does, and because it
 * runs on our own server with our own credentials it is current every time
 * it is opened, not as of whenever someone last updated a document.
 *
 * WHAT IS LIVE AND WHAT IS NOT — the distinction is load-bearing, and every
 * figure below says which it is, so nothing hand-typed months ago is ever
 * mistaken for a real number:
 *
 *   live      Stripe. Pulled from the balance-transaction ledger, which is
 *             the only source that already has the fees taken out — it nets
 *             refunds and disputes too, so it is what actually landed.
 *   live      RevenueCat, IF REVENUECAT_API_KEY and REVENUECAT_PROJECT_ID
 *             are set in config.php. Until they are, this channel reports
 *             "not configured" rather than guessing.
 *   modelled  The store cut on RevenueCat revenue. RevenueCat reports what
 *             the customer paid; Google and Apple keep a slice before we see
 *             it. The percentages are inputs (store_fee_pct, apple_fee_pct),
 *             not constants, because the fee schedules move.
 *   manual    Amazon Associates and the Anthropic API spend. Neither exposes
 *             a usable API here — Amazon blocks automated fetching, and
 *             Anthropic's billing is not available programmatically. These
 *             are typed in, and every one of them carries the timestamp of
 *             when a human last touched it, so a stale figure looks stale.
 *
 * READ-ONLY WHERE IT COUNTS. The GET runs SELECTs and outbound API reads and
 * nothing else. The POST writes ONLY to money_inputs — a table of numbers
 * that exist nowhere else — and can touch no other table, no subscription,
 * no account. It returns no customer names, no emails, no payment details
 * and no card data: revenue here is aggregate, which means the secret
 * leaking would cost us our own P&L and nobody else's privacy.
 *
 * TWO TIME FRAMES, deliberately, because one is misleading on its own.
 * Lifetime answers "has this made money yet"; this-month answers "is it
 * making money now". Costs are mostly monthly and revenue is mostly
 * lifetime, and quietly mixing the two is how a project talks itself into
 * believing it is profitable.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe.php';

function require_admin_secret_money(): void {
    $sent = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? '');
    if ($sent === '') $sent = (string)($_GET['secret'] ?? '');
    $secret = (defined('ADMIN_SECRET') && ADMIN_SECRET !== '') ? ADMIN_SECRET
            : ((defined('ADMIN_KEY') && ADMIN_KEY !== '') ? ADMIN_KEY : '');
    if ($secret === '' || !hash_equals($secret, $sent)) {
        error_response('Not authorized.', 401);
    }
}

/**
 * The figures no API will give us. updated_at = 0 means nobody has ever set
 * it and the value on screen is a placeholder — the admin page renders those
 * differently on purpose, because an unlabelled guess in a profit figure is
 * worse than a blank.
 */
function money_input_defs(): array {
    return [
        'costs_since'          => ['label' => 'Counting costs since',        'group' => 'model',   'unit' => 'date',    'default' => '2026-09-01'],
        'store_fee_pct'        => ['label' => 'Google Play fee',             'group' => 'model',   'unit' => 'percent', 'default' => '15'],
        'apple_fee_pct'        => ['label' => 'Apple fee',                   'group' => 'model',   'unit' => 'percent', 'default' => '15'],
        'amazon_lifetime'      => ['label' => 'Amazon commissions, lifetime','group' => 'revenue', 'unit' => 'usd',     'default' => '0'],
        'amazon_month'         => ['label' => 'Amazon commissions, this month','group'=>'revenue', 'unit' => 'usd',     'default' => '0'],
        'cost_hosting_monthly' => ['label' => 'Hosting (GoDaddy)',           'group' => 'cost',    'unit' => 'monthly', 'default' => '0'],
        'cost_domain_annual'   => ['label' => 'Domain',                      'group' => 'cost',    'unit' => 'annual',  'default' => '0'],
        'cost_apple_annual'    => ['label' => 'Apple Developer Program',     'group' => 'cost',    'unit' => 'annual',  'default' => '99'],
        'cost_resend_monthly'  => ['label' => 'Resend (email)',              'group' => 'cost',    'unit' => 'monthly', 'default' => '0'],
        'cost_other_monthly'   => ['label' => 'Anything else, monthly',      'group' => 'cost',    'unit' => 'monthly', 'default' => '0'],
        'cost_anthropic_month' => ['label' => 'Anthropic API, this month',   'group' => 'cost',    'unit' => 'mtd',     'default' => '0'],
        'cost_oneoff_total'    => ['label' => 'One-off costs to date',       'group' => 'cost',    'unit' => 'oneoff',  'default' => '25'],
    ];
}

function ensure_money_table(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS money_inputs (
           k VARCHAR(64) NOT NULL PRIMARY KEY,
           v VARCHAR(64) NOT NULL DEFAULT "",
           updated_at BIGINT NOT NULL DEFAULT 0
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/** Stored values merged over the defaults, each carrying whether a human set it. */
function money_inputs(PDO $pdo): array {
    ensure_money_table($pdo);
    $stored = [];
    foreach ($pdo->query('SELECT k, v, updated_at FROM money_inputs') as $row) {
        $stored[$row['k']] = ['v' => (string)$row['v'], 'updatedAt' => (int)$row['updated_at']];
    }
    $out = [];
    foreach (money_input_defs() as $k => $def) {
        $hit = $stored[$k] ?? null;
        $out[$k] = [
            'key'       => $k,
            'label'     => $def['label'],
            'group'     => $def['group'],
            'unit'      => $def['unit'],
            'value'     => $hit ? $hit['v'] : $def['default'],
            'updatedAt' => $hit ? $hit['updatedAt'] : 0,
            'isDefault' => !$hit || $hit['updatedAt'] === 0,
        ];
    }
    return $out;
}

function money_num(array $inputs, string $key): float {
    return (float)($inputs[$key]['value'] ?? 0);
}

/**
 * Stripe, from the balance-transaction ledger. `amount` is what the customer
 * was charged, `fee` is Stripe's cut and `net` is what reached the balance —
 * so summing `net` is the real answer, with refunds and disputes already
 * subtracted, and no modelling required.
 */
function money_stripe(int $monthStartSec): array {
    if (!function_exists('stripe_configured') || !stripe_configured()) {
        return ['ok' => false, 'configured' => false, 'error' => 'Stripe keys are not set in config.php.'];
    }
    $gross = 0.0; $fee = 0.0; $net = 0.0;
    $mGross = 0.0; $mFee = 0.0; $mNet = 0.0;
    $count = 0; $refunds = 0;
    $after = null; $pages = 0;
    do {
        $params = ['limit' => 100];
        if ($after) $params['starting_after'] = $after;
        $res = stripe_request('GET', '/v1/balance_transactions?' . http_build_query($params));
        if (isset($res['error'])) {
            return ['ok' => false, 'configured' => true, 'error' => (string)($res['error']['message'] ?? 'Stripe error')];
        }
        $data = $res['data'] ?? [];
        foreach ($data as $t) {
            $type = (string)($t['type'] ?? '');
            $a = ((float)($t['amount'] ?? 0)) / 100;
            $f = ((float)($t['fee'] ?? 0)) / 100;
            $n = ((float)($t['net'] ?? 0)) / 100;
            $created = (int)($t['created'] ?? 0);
            $gross += $a; $fee += $f; $net += $n;
            if ($created >= $monthStartSec) { $mGross += $a; $mFee += $f; $mNet += $n; }
            if ($type === 'charge' || $type === 'payment') $count++;
            if ($type === 'refund' || $type === 'payment_refund') $refunds++;
            $after = (string)($t['id'] ?? '');
        }
        $pages++;
    } while (!empty($res['has_more']) && $pages < 20);

    /* Active subscribers, for the run rate rather than the history. */
    $active = 0; $trialing = 0; $mrr = 0.0;
    $subs = stripe_request('GET', '/v1/subscriptions?' . http_build_query(['status' => 'all', 'limit' => 100]));
    if (!isset($subs['error'])) {
        foreach (($subs['data'] ?? []) as $s) {
            $status = (string)($s['status'] ?? '');
            if ($status !== 'active' && $status !== 'trialing') continue;
            if ($status === 'trialing') $trialing++; else $active++;
            foreach (($s['items']['data'] ?? []) as $it) {
                $amt = ((float)($it['price']['unit_amount'] ?? 0)) / 100;
                $interval = (string)($it['price']['recurring']['interval'] ?? 'month');
                $qty = (int)($it['quantity'] ?? 1);
                if ($status === 'trialing') continue; /* a trial is not revenue yet */
                $mrr += $interval === 'year' ? ($amt * $qty / 12) : ($amt * $qty);
            }
        }
    }
    return [
        'ok' => true, 'configured' => true,
        'lifetimeGross' => round($gross, 2), 'lifetimeFees' => round($fee, 2), 'lifetimeNet' => round($net, 2),
        'monthGross' => round($mGross, 2), 'monthFees' => round($mFee, 2), 'monthNet' => round($mNet, 2),
        'charges' => $count, 'refunds' => $refunds,
        'activeSubs' => $active, 'trialingSubs' => $trialing, 'mrr' => round($mrr, 2),
        'truncated' => $pages >= 20,
    ];
}

/**
 * RevenueCat's overview metrics — active subscriptions, trials, MRR and the
 * last 28 days of revenue across Google Play and (once it ships) Apple.
 *
 * Parsed by metric id rather than by position, and anything unrecognised is
 * passed through in `raw`, because this endpoint's exact field names are
 * RevenueCat's to change and a dashboard that silently reports zero when a
 * key is renamed is worse than one that says it does not understand.
 */
function money_revenuecat(): array {
    $key = defined('REVENUECAT_API_KEY') ? (string)REVENUECAT_API_KEY : '';
    $project = defined('REVENUECAT_PROJECT_ID') ? (string)REVENUECAT_PROJECT_ID : '';
    if ($key === '' || $project === '') {
        return ['ok' => false, 'configured' => false,
                'error' => 'Add REVENUECAT_API_KEY and REVENUECAT_PROJECT_ID to config.php to pull Play and Apple revenue live.'];
    }
    $ch = curl_init('https://api.revenuecat.com/v2/projects/' . rawurlencode($project) . '/metrics/overview');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'configured' => true, 'error' => 'RevenueCat unreachable: ' . $err];
    $json = json_decode($res, true);
    if (!is_array($json)) return ['ok' => false, 'configured' => true, 'error' => 'RevenueCat returned something that is not JSON.'];
    if ($code >= 400) {
        $msg = (string)($json['message'] ?? $json['error'] ?? ('HTTP ' . $code));
        return ['ok' => false, 'configured' => true, 'error' => 'RevenueCat: ' . $msg];
    }
    $byId = [];
    foreach (($json['metrics'] ?? []) as $m) {
        $id = (string)($m['id'] ?? '');
        if ($id !== '') $byId[$id] = $m['value'] ?? null;
    }
    $pick = function (array $ids) use ($byId) {
        foreach ($ids as $i) if (isset($byId[$i]) && $byId[$i] !== null) return (float)$byId[$i];
        return null;
    };
    return [
        'ok' => true, 'configured' => true,
        'activeSubs'   => $pick(['active_subscriptions', 'active_subscribers']),
        'activeTrials' => $pick(['active_trials']),
        'mrr'          => $pick(['mrr']),
        'revenue28d'   => $pick(['revenue', 'revenue_28d']),
        'raw'          => $byId,
    ];
}

$pdo = db();

/* ---- POST: update one or more of the hand-kept figures ------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_admin_secret_money();
    ensure_money_table($pdo);
    $body = request_json();
    $incoming = $body['inputs'] ?? null;
    if (!is_array($incoming) || !$incoming) error_response('Send {"inputs":{"key":"value"}}.', 400);
    $defs = money_input_defs();
    $now = current_time_ms();
    $saved = [];
    $stmt = $pdo->prepare('INSERT INTO money_inputs (k, v, updated_at) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)');
    foreach ($incoming as $k => $v) {
        $k = (string)$k;
        if (!isset($defs[$k])) continue;              /* unknown keys are ignored, not errors */
        $v = trim((string)$v);
        if (mb_strlen($v) > 64) $v = mb_substr($v, 0, 64);
        if ($defs[$k]['unit'] !== 'date') {
            if ($v === '' || !is_numeric($v)) continue; /* a blank is "leave it alone" */
            $v = (string)round((float)$v, 2);
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            continue;
        }
        $stmt->execute([$k, $v, $now]);
        $saved[] = $k;
    }
    json_response(['ok' => true, 'saved' => $saved, 'inputs' => array_values(money_inputs($pdo))]);
}

/* ---- GET: the whole picture -------------------------------------------- */
require_method('GET');
require_admin_secret_money();

$inputs   = money_inputs($pdo);
$warnings = [];

$nowMs        = current_time_ms();
$monthStart   = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
$daysElapsed  = (int)date('j');
$daysInMonth  = (int)date('t');

/* How many whole months of fixed cost we have carried, for the lifetime column. */
$since      = strtotime(((string)$inputs['costs_since']['value']) . ' 00:00:00') ?: $monthStart;
$monthsHeld = max(1.0, (time() - $since) / (365.2425 / 12 * 86400));

$stripe = money_stripe($monthStart);
$rc     = money_revenuecat();
if (!$stripe['ok'])  $warnings[] = 'Stripe: ' . $stripe['error'];
if (!$rc['ok'])      $warnings[] = 'Play / Apple: ' . $rc['error'];
if (!empty($stripe['truncated'])) $warnings[] = 'Stripe history is longer than 2,000 transactions; only the most recent were counted.';

/* Store revenue: RevenueCat reports what the customer paid, so take the cut off. */
$storeFee   = money_num($inputs, 'store_fee_pct') / 100;
$rcRev28    = $rc['ok'] ? (float)($rc['revenue28d'] ?? 0) : 0.0;
$rcMrr      = $rc['ok'] ? (float)($rc['mrr'] ?? 0) : 0.0;
$storeNet28 = $rcRev28 * (1 - $storeFee);

$amazonLife  = money_num($inputs, 'amazon_lifetime');
$amazonMonth = money_num($inputs, 'amazon_month');

$revenue = [
    [
        'id' => 'stripe', 'label' => 'Website (Stripe)', 'source' => 'live',
        'ok' => $stripe['ok'], 'error' => $stripe['ok'] ? null : $stripe['error'],
        'lifetimeGross' => $stripe['ok'] ? $stripe['lifetimeGross'] : null,
        'lifetimeFees'  => $stripe['ok'] ? $stripe['lifetimeFees'] : null,
        'lifetimeNet'   => $stripe['ok'] ? $stripe['lifetimeNet'] : null,
        'monthNet'      => $stripe['ok'] ? $stripe['monthNet'] : null,
        'mrr'           => $stripe['ok'] ? $stripe['mrr'] : null,
        'detail'        => $stripe['ok']
            ? ($stripe['activeSubs'] . ' active, ' . $stripe['trialingSubs'] . ' on trial · ' . $stripe['charges'] . ' charges, ' . $stripe['refunds'] . ' refunds')
            : null,
        'note' => 'Net of Stripe fees, refunds and disputes — straight from the balance ledger, nothing modelled.',
    ],
    [
        'id' => 'stores', 'label' => 'Google Play + Apple (RevenueCat)', 'source' => 'live+modelled',
        'ok' => $rc['ok'], 'error' => $rc['ok'] ? null : $rc['error'],
        'lifetimeGross' => $rc['ok'] ? round($rcRev28, 2) : null,
        'lifetimeFees'  => $rc['ok'] ? round($rcRev28 * $storeFee, 2) : null,
        'lifetimeNet'   => $rc['ok'] ? round($storeNet28, 2) : null,
        'monthNet'      => $rc['ok'] ? round($storeNet28, 2) : null,
        'mrr'           => $rc['ok'] ? round($rcMrr * (1 - $storeFee), 2) : null,
        'detail'        => $rc['ok']
            ? (((int)($rc['activeSubs'] ?? 0)) . ' active, ' . ((int)($rc['activeTrials'] ?? 0)) . ' on trial')
            : null,
        'note' => 'RevenueCat reports the last 28 days, not all time, and reports what the customer paid — the ' .
                  round($storeFee * 100) . '% store cut is taken off here, not by them.',
    ],
    [
        'id' => 'amazon', 'label' => 'Amazon Associates', 'source' => 'manual',
        'ok' => true, 'error' => null,
        'lifetimeGross' => $amazonLife, 'lifetimeFees' => 0.0, 'lifetimeNet' => $amazonLife,
        'monthNet' => $amazonMonth, 'mrr' => null,
        'updatedAt' => $inputs['amazon_lifetime']['updatedAt'],
        'isDefault' => $inputs['amazon_lifetime']['isDefault'],
        'detail' => null,
        'note' => 'Typed in — Amazon blocks automated fetching. Commissions accrue even while the tax interview is unfinished; only payout is held.',
    ],
];

$fixedMonthly = money_num($inputs, 'cost_hosting_monthly')
              + money_num($inputs, 'cost_resend_monthly')
              + money_num($inputs, 'cost_other_monthly')
              + money_num($inputs, 'cost_domain_annual') / 12
              + money_num($inputs, 'cost_apple_annual') / 12;
$anthropicMtd = money_num($inputs, 'cost_anthropic_month');
$oneOff       = money_num($inputs, 'cost_oneoff_total');

$costs = [];
foreach (money_input_defs() as $k => $def) {
    if ($def['group'] !== 'cost') continue;
    $v = money_num($inputs, $k);
    $monthly = $def['unit'] === 'annual' ? $v / 12 : ($def['unit'] === 'monthly' ? $v : 0.0);
    $costs[] = [
        'id' => $k, 'label' => $def['label'], 'unit' => $def['unit'],
        'value' => $v, 'monthly' => round($monthly, 2),
        'updatedAt' => $inputs[$k]['updatedAt'], 'isDefault' => $inputs[$k]['isDefault'],
    ];
}

$lifetimeNetRevenue = ($stripe['ok'] ? $stripe['lifetimeNet'] : 0) + $storeNet28 + $amazonLife;
$monthNetRevenue    = ($stripe['ok'] ? $stripe['monthNet'] : 0) + $storeNet28 + $amazonMonth;
$lifetimeCosts      = $fixedMonthly * $monthsHeld + $oneOff + $anthropicMtd;
$monthCosts         = $fixedMonthly + $anthropicMtd;
$runRateRevenue     = ($stripe['ok'] ? $stripe['mrr'] : 0) + $rcMrr * (1 - $storeFee);

json_response([
    'asOf'     => $nowMs,
    'currency' => 'USD',
    'month'    => [
        'label' => date('F Y'), 'startMs' => $monthStart * 1000,
        'daysElapsed' => $daysElapsed, 'daysInMonth' => $daysInMonth,
    ],
    'headline' => [
        'lifetimeNetRevenue' => round($lifetimeNetRevenue, 2),
        'lifetimeCosts'      => round($lifetimeCosts, 2),
        'lifetimeNetProfit'  => round($lifetimeNetRevenue - $lifetimeCosts, 2),
        'monthNetRevenue'    => round($monthNetRevenue, 2),
        'monthCosts'         => round($monthCosts, 2),
        'monthNetProfit'     => round($monthNetRevenue - $monthCosts, 2),
        'runRateRevenue'     => round($runRateRevenue, 2),
        'runRateCosts'       => round($fixedMonthly + $anthropicMtd, 2),
        'runRateProfit'      => round($runRateRevenue - $fixedMonthly - $anthropicMtd, 2),
        'monthsCounted'      => round($monthsHeld, 2),
    ],
    'revenue' => $revenue,
    'costs'   => $costs,
    'inputs'  => array_values($inputs),
    'config'  => ['stripe' => !empty($stripe['configured']), 'revenuecat' => !empty($rc['configured'])],
    'warnings' => $warnings,
    'note' => 'Lifetime revenue is every dollar that has landed; lifetime cost is the monthly run rate multiplied by the ' .
              'months since the "counting costs since" date, plus one-offs. Sales tax, VAT and income tax are not modelled — ' .
              'on the website you are the merchant of record, and that is an accountant\'s question.',
]);
