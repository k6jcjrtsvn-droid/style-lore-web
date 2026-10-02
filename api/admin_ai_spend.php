<?php
/**
 * GET /api/admin/ai-spend — what the AI features are costing, how fast, and
 * how long the credit lasts at that rate.
 *
 * WHY THIS EXISTS, and why it is shaped like this.
 *
 * The AI Stylist is the paid feature. It runs on prepaid Anthropic credits,
 * and when those credits run out it stops working for everybody at once —
 * including the people who have just paid for it. So the question "how long
 * until we run dry" needs an answer that does not depend on somebody
 * remembering to look at a billing page.
 *
 * Anthropic does not expose the remaining balance. Checked against the
 * official Usage and Cost API docs on 2 Oct 2026: there are two endpoints,
 * /v1/organizations/usage_report/messages and /v1/organizations/cost_report,
 * both report spend AFTER the fact, and neither reports a balance. Exposing
 * one is an open request on Anthropic's own SDK repository, not a feature.
 * Nothing written here or anywhere else can read that number.
 *
 * So the balance is reconstructed instead of read:
 *
 *   balance  = a figure typed off the Console by a human, with the instant
 *              they typed it (money_inputs.anthropic_balance_usd)
 *   minus    = every dollar metered since that instant
 *   gives    = an estimate, which this endpoint is careful to label as one
 *
 * and the spend side needs no credentials at all, because every Anthropic
 * response carries its own token counts and record_ai_spend() prices them as
 * they arrive. That is deliberate: the alternative is an organisation Admin
 * key, which can read the whole account's billing, sitting in config.php on
 * a shared host. Not worth it for a number we can derive.
 *
 * THE ESTIMATE IS AN ESTIMATE. It drifts from the Console — rounding, calls
 * that failed after being billed, a metering INSERT that lost a race. It is
 * for deciding when to top up, never for accounting. `confidence` says when
 * the typed balance has gone stale enough to stop trusting the projection.
 *
 * Read-only: SELECTs and nothing else. Returns aggregate money and no
 * customer names, emails or payment details. Same X-Admin-Secret as the
 * Money tab.
 */

require_once __DIR__ . '/../includes/helpers.php';

require_method('GET');

$sent = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? '');
$secret = (defined('ADMIN_SECRET') && ADMIN_SECRET !== '') ? ADMIN_SECRET
        : ((defined('ADMIN_KEY') && ADMIN_KEY !== '') ? ADMIN_KEY : '');
if ($secret === '' || !hash_equals($secret, $sent)) {
    error_response('Not authorised.', 403);
}

$pdo = db();
ensure_ai_spend_table($pdo);

$nowMs  = (int)round(microtime(true) * 1000);
$DAY    = 86400000;
$monthStartMs = (int)(gmmktime(0, 0, 0, (int)gmdate('n'), 1, (int)gmdate('Y')) * 1000);

$spend = static function (int $sinceMs) use ($pdo): array {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) AS calls, COALESCE(SUM(cost_usd),0) AS usd,
                                    COALESCE(SUM(input_tokens),0) AS in_tok,
                                    COALESCE(SUM(output_tokens),0) AS out_tok
                             FROM ai_spend WHERE created_at >= ?');
        $st->execute([$sinceMs]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['calls' => (int)($r['calls'] ?? 0), 'usd' => round((float)($r['usd'] ?? 0), 4),
                'inputTokens' => (int)($r['in_tok'] ?? 0), 'outputTokens' => (int)($r['out_tok'] ?? 0)];
    } catch (Throwable $e) {
        error_log('admin_ai_spend window: ' . $e->getMessage());
        return ['calls' => 0, 'usd' => 0.0, 'inputTokens' => 0, 'outputTokens' => 0, 'error' => true];
    }
};

$day7  = $spend($nowMs - 7 * $DAY);
$day1  = $spend($nowMs - $DAY);
$hour1 = $spend($nowMs - 3600000);
$month = $spend($monthStartMs);
$all   = $spend(0);

/* Per feature over the last 30 days, so a cost that is climbing can be
   attributed to a feature rather than just noticed in total. */
$byFeature = [];
try {
    $st = $pdo->prepare('SELECT feature, model, COUNT(*) AS calls, COALESCE(SUM(cost_usd),0) AS usd
                         FROM ai_spend WHERE created_at >= ?
                         GROUP BY feature, model ORDER BY usd DESC');
    $st->execute([$nowMs - 30 * $DAY]);
    foreach ($st as $r) {
        $calls = (int)$r['calls'];
        $byFeature[] = [
            'feature'     => (string)$r['feature'],
            'model'       => (string)$r['model'],
            'calls'       => $calls,
            'usd'         => round((float)$r['usd'], 4),
            'usdPerCall'  => $calls > 0 ? round((float)$r['usd'] / $calls, 6) : 0.0,
        ];
    }
} catch (Throwable $e) { error_log('admin_ai_spend byFeature: ' . $e->getMessage()); }

/* The cap, and how close to it we are. */
$budget = ai_monthly_budget_usd();
$capPct = $budget > 0 ? round($month['usd'] / $budget * 100, 1) : null;

/* The typed balance, and what is left of it. */
$balanceUsd = null; $balanceAsOf = 0;
try {
    $st = $pdo->prepare('SELECT v, updated_at FROM money_inputs WHERE k = ?');
    $st->execute(['anthropic_balance_usd']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['updated_at'] > 0 && (float)$row['v'] > 0) {
        $balanceUsd  = (float)$row['v'];
        $balanceAsOf = (int)$row['updated_at'];
    }
} catch (Throwable $e) { error_log('admin_ai_spend balance: ' . $e->getMessage()); }

/* Burn rate from the last 7 days, falling back to the last 24 hours when
   metering is younger than a week. A rate of zero is reported as zero and
   never as "forever" — dividing by it is how a monitor reports infinite
   runway the day before an outage. */
$firstSeenMs = 0;
try { $firstSeenMs = (int)$pdo->query('SELECT COALESCE(MIN(created_at),0) FROM ai_spend')->fetchColumn(); }
catch (Throwable $e) { error_log('admin_ai_spend first seen: ' . $e->getMessage()); }

$meteringDays = $firstSeenMs > 0 ? max(0.04, ($nowMs - $firstSeenMs) / $DAY) : 0.0;
$windowDays   = $meteringDays >= 7 ? 7.0 : max(1.0, $meteringDays);
$windowUsd    = $meteringDays >= 7 ? $day7['usd'] : $all['usd'];
$perDay       = $windowDays > 0 ? $windowUsd / $windowDays : 0.0;

$estRemaining = null; $daysLeft = null; $meteredSinceBalance = null;
if ($balanceUsd !== null) {
    $meteredSinceBalance = round(ai_spend_since($pdo, $balanceAsOf), 4);
    $estRemaining = round(max(0.0, $balanceUsd - $meteredSinceBalance), 4);
    if ($perDay > 0.000001) $daysLeft = round($estRemaining / $perDay, 1);
}

/* How much to trust the projection. A balance typed six weeks ago, with
   top-ups since, is worse than no figure at all if it is read as current. */
$balanceAgeDays = $balanceAsOf > 0 ? round(($nowMs - $balanceAsOf) / $DAY, 1) : null;
$confidence = 'none';
if ($balanceUsd === null)            $confidence = 'no_balance_recorded';
elseif ($balanceAgeDays > 45)        $confidence = 'stale';
elseif ($meteringDays < 3)           $confidence = 'thin_history';
else                                 $confidence = 'ok';

/* Times the AI features turned somebody away, and why. This is the line that
   says an outage is happening NOW rather than projected. */
$refusals = [];
try {
    $st = $pdo->prepare("SELECT meta, COUNT(*) AS n, MAX(created_at) AS last_at
                         FROM events WHERE event = 'ai_unavailable' AND created_at >= ?
                         GROUP BY meta ORDER BY n DESC LIMIT 20");
    $st->execute([$nowMs - 7 * $DAY]);
    foreach ($st as $r) {
        $refusals[] = ['reason' => (string)$r['meta'], 'count' => (int)$r['n'], 'lastAtMs' => (int)$r['last_at']];
    }
} catch (Throwable $e) { error_log('admin_ai_spend refusals: ' . $e->getMessage()); }

$refusals24h = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event = 'ai_unavailable' AND created_at >= ?");
    $st->execute([$nowMs - $DAY]);
    $refusals24h = (int)$st->fetchColumn();
} catch (Throwable $e) { error_log('admin_ai_spend refusals24h: ' . $e->getMessage()); }

/* One word for a watchdog to branch on, so the judgement lives here next to
   the figures rather than being re-derived by every caller. */
$status = 'ok'; $why = 'Spend is normal and there is runway.';
if ($refusals24h > 0) {
    $status = 'critical';
    $why = 'The AI features have turned ' . $refusals24h . ' request(s) away in the last 24 hours.';
} elseif ($budget > 0 && $capPct !== null && $capPct >= 100) {
    $status = 'critical';
    $why = 'The monthly cap of $' . number_format($budget, 2) . ' is reached — calls are being refused.';
} elseif ($daysLeft !== null && $daysLeft < 7) {
    $status = 'critical';
    $why = 'About ' . $daysLeft . ' days of credit left at the current rate.';
} elseif ($daysLeft !== null && $daysLeft < 21) {
    $status = 'warn';
    $why = 'About ' . $daysLeft . ' days of credit left at the current rate.';
} elseif ($budget > 0 && $capPct !== null && $capPct >= 75) {
    $status = 'warn';
    $why = 'This month is at ' . $capPct . '% of the $' . number_format($budget, 2) . ' cap.';
} elseif ($balanceUsd === null) {
    $status = 'warn';
    $why = 'No credit balance has been recorded, so nothing can project when it runs out.';
} elseif ($confidence === 'stale') {
    $status = 'warn';
    $why = 'The recorded balance is ' . $balanceAgeDays . ' days old — retype it from the Console.';
}

json_response([
    'ok'       => true,
    'asOfMs'   => $nowMs,
    'status'   => $status,
    'why'      => $why,
    'spend'    => [
        'lastHour'    => $hour1,
        'last24h'     => $day1,
        'last7d'      => $day7,
        'monthToDate' => $month,
        'allTime'     => $all,
    ],
    'byFeature' => $byFeature,
    'burn'      => [
        'usdPerDay'          => round($perDay, 4),
        'windowDays'         => $windowDays,
        'projectedMonthUsd'  => round($perDay * (float)gmdate('t'), 2),
        'meteringHistoryDays'=> round($meteringDays, 2),
    ],
    'cap'       => [
        'monthlyUsd'  => $budget,
        'usedPct'     => $capPct,
        'remainingUsd'=> $budget > 0 ? round(max(0.0, $budget - $month['usd']), 4) : null,
        'note'        => 'Our own ceiling, not Anthropic\'s. Raise AI_MONTHLY_BUDGET_USD in config.php.',
    ],
    'balance'   => [
        'recordedUsd'        => $balanceUsd,
        'recordedAtMs'       => $balanceAsOf ?: null,
        'recordedAgeDays'    => $balanceAgeDays,
        'meteredSince'       => $meteredSinceBalance,
        'estimatedRemaining' => $estRemaining,
        'estimatedDaysLeft'  => $daysLeft,
        'confidence'         => $confidence,
        'note'               => 'Estimated, not read: Anthropic exposes no balance endpoint. Retype anthropic_balance_usd on the Money tab after every top-up.',
    ],
    'refusals'  => ['last24h' => $refusals24h, 'last7dByReason' => $refusals],
]);
