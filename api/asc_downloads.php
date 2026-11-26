<?php
/**
 * api/asc_downloads — how many times the iOS app has actually been
 * downloaded, read without a browser and without anyone logging in.
 *
 *   GET /api/asc_downloads.php?token=…[&from=YYYY-MM-DD][&to=YYYY-MM-DD][&fresh=1]
 *
 * WHY THIS EXISTS. api/asc_review_status.php answers "what does Apple think
 * of the app"; it says nothing about how it is doing. Downloads live in
 * Sales and Trends, which is a different API: not JSON but a gzipped TSV
 * per day, one row per app, country, and kind of transaction.
 *
 * WHAT A "DOWNLOAD" IS. Apple does not have one number called downloads.
 * Each row of the report carries a Product Type Identifier, and the same
 * person installing the app twice shows up under two different ones. The
 * identifiers are grouped here the way Apple's own reference groups them:
 *
 *   first-time   1, 1-B, 1E, 1EP, 1EU, 1F, 1T, F1, F1-B   a free or paid app
 *   redownload   3, 3F                                     Apple: "Re-download"
 *   update       7, 7F, 7T, F7                             Apple: "Update"
 *   in-app       IA*, FI1                                  not a download at all
 *
 * "First-time" is the number anyone means by downloads. Updates are much
 * larger than it once an app has any users, so an endpoint that summed
 * Units blindly would report a flattering and meaningless figure.
 *
 * Sales and Trends is next-day data on Pacific Time, so TODAY never has a
 * report and yesterday's appears by about 8 a.m. PT. Apple keeps daily
 * reports for one year.
 *
 * Read-only: nothing here writes to App Store Connect.
 */
require_once __DIR__ . '/../includes/helpers.php';
@include_once __DIR__ . '/../includes/watchdog_token.php';
@include_once dirname(__DIR__, 2) . '/asc_config.php';
require_once __DIR__ . '/../includes/asc_api.php';

$token = (string)($_GET['token'] ?? '');
if (!defined('WATCHDOG_READ_TOKEN') || WATCHDOG_READ_TOKEN === '' || !hash_equals(WATCHDOG_READ_TOKEN, $token)) {
    error_response('Not authorized.', 401);
}

foreach (['ASC_KEY_ID', 'ASC_ISSUER_ID', 'ASC_PRIVATE_KEY_PATH'] as $c) {
    if (!defined($c) || constant($c) === '') {
        error_response('App Store Connect API is not configured on this server (' . $c . ').', 503);
    }
}
if (!defined('ASC_VENDOR_NUMBER') || (string)ASC_VENDOR_NUMBER === '') {
    error_response(
        'ASC_VENDOR_NUMBER is not set. Sales reports are fetched per vendor, not per app: '
      . 'add the numeric Vendor # from App Store Connect > Payments and Financial Reports '
      . "to asc_config.php as define('ASC_VENDOR_NUMBER', '…'); — digits only.", 503);
}

$pem = @file_get_contents(ASC_PRIVATE_KEY_PATH);
if ($pem === false || trim($pem) === '') {
    error_response('Could not read the App Store Connect private key.', 503);
}

/* ---------------------------------------------------------------- dates
   Everything is Pacific Time, because that is the day boundary Apple cuts
   the reports on. Asking for a date in UTC terms is how a report comes
   back empty for a day that plainly had sales. */
$pt = new DateTimeZone('America/Los_Angeles');
$today = new DateTimeImmutable('now', $pt);
$latest = $today->modify('-1 day');                   // today has no report yet

$parseDate = static function (string $s, DateTimeZone $tz): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);
    return $d === false ? null : $d;
};

$to = $parseDate((string)($_GET['to'] ?? ''), $pt) ?? $latest;
if ($to > $latest) $to = $latest;

// Default start: the day the app went live if config says, else four weeks.
$defaultFrom = defined('ASC_FIRST_SALES_DATE') && ASC_FIRST_SALES_DATE !== ''
    ? ($parseDate((string)ASC_FIRST_SALES_DATE, $pt) ?? $to->modify('-27 days'))
    : $to->modify('-27 days');
$from = $parseDate((string)($_GET['from'] ?? ''), $pt) ?? $defaultFrom;
if ($from > $to) $from = $to;

// Apple keeps daily reports for a year, and a range is one request per day.
$oldest = $latest->modify('-364 days');
if ($from < $oldest) $from = $oldest;
$span = (int)$from->diff($to)->days + 1;
if ($span > 400) error_response('Range too long; daily reports only go back a year.', 400);

/* ---------------------------------------------------------------- cache
   One request per day of the range would mean refetching months of
   finished history on every run. A finished day never changes, so it is
   kept beside asc_config.php, above public_html. If that is not writable
   the endpoint still works, just slower. */
$cacheDir = dirname(__DIR__, 2) . '/asc_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
$cacheOk = is_dir($cacheDir) && is_writable($cacheDir);
$fresh   = (string)($_GET['fresh'] ?? '') === '1';

/* ------------------------------------------------------- the identifiers
   Apple's reference: Product Type Identifiers. The Type column is what is
   being grouped on here, not the Description, because Apple's description
   for code 3 says "App update" while its own Type column says
   "Re-download" -- the Type column is the one that matches the data. */
const ASC_FIRST_TIME = ['1', '1-B', '1E', '1EP', '1EU', '1F', '1T', 'F1', 'F1-B'];
const ASC_REDOWNLOAD = ['3', '3F'];
const ASC_UPDATE     = ['7', '7F', '7T', 'F7'];

function asc_bucket(string $pti): string {
    if (in_array($pti, ASC_FIRST_TIME, true)) return 'firstTime';
    if (in_array($pti, ASC_REDOWNLOAD, true)) return 'redownload';
    if (in_array($pti, ASC_UPDATE, true))     return 'update';
    if ($pti !== '' && ($pti[0] === 'I' || str_starts_with($pti, 'FI'))) return 'inApp';
    return 'other';
}

/**
 * One day's report, reduced to counts. Columns are looked up BY NAME from
 * the header row: Apple adds columns to this report between versions, and
 * anything that counted on a fixed position would start reporting prices
 * as units the day that happened.
 */
function asc_parse_day(string $tsv, ?string $appId): array {
    $lines = preg_split("/\r\n|\n|\r/", $tsv);
    $head  = null; $col = [];
    $out = ['firstTime' => 0, 'redownload' => 0, 'update' => 0, 'inApp' => 0, 'other' => 0,
            'countries' => [], 'unknownTypes' => []];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $f = explode("\t", $line);
        if ($head === null) {
            $head = $f;
            foreach ($f as $i => $name) $col[strtolower(trim($name))] = $i;
            continue;
        }
        $get = static function (string $name) use ($f, $col): string {
            $i = $col[strtolower($name)] ?? null;
            return $i === null ? '' : trim($f[$i] ?? '');
        };
        if ($appId !== null && $appId !== '') {
            $rowApp = $get('apple identifier');
            if ($rowApp !== '' && $rowApp !== $appId) continue;
        }
        $pti   = $get('product type identifier');
        $units = (int)$get('units');
        if ($units === 0) continue;                 // refunds net out; zeroes do nothing
        $b = asc_bucket($pti);
        $out[$b] += $units;
        if ($b === 'other' && $pti !== '') $out['unknownTypes'][$pti] = true;
        if ($b === 'firstTime') {
            $cc = $get('country code');
            if ($cc !== '') $out['countries'][$cc] = ($out['countries'][$cc] ?? 0) + $units;
        }
    }
    $out['unknownTypes'] = array_keys($out['unknownTypes']);
    return $out;
}

try {
    $jwt = asc_token($pem);
} catch (Throwable $e) {
    error_response('Could not build the App Store Connect token: ' . $e->getMessage(), 500);
}

$appId = defined('ASC_APP_ID') ? (string)ASC_APP_ID : null;
$days = []; $missing = []; $errors = []; $countries = []; $unknown = [];
$tot = ['firstTime' => 0, 'redownload' => 0, 'update' => 0, 'inApp' => 0, 'other' => 0];
$calls = 0;

for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
    $date = $d->format('Y-m-d');
    $cf   = $cacheOk ? $cacheDir . '/sales-' . ASC_VENDOR_NUMBER . '-' . $date . '.json' : null;

    $day = null;
    if ($cf !== null && !$fresh && is_file($cf)) {
        $cached = json_decode((string)@file_get_contents($cf), true);
        if (is_array($cached) && isset($cached['firstTime'])) $day = $cached;
    }

    if ($day === null) {
        // SALES / SUMMARY is version 1_0 at every frequency, per Apple's
        // own table of valid report types.
        $q = '/v1/salesReports?filter[frequency]=DAILY&filter[reportType]=SALES'
           . '&filter[reportSubType]=SUMMARY&filter[version]=1_0'
           . '&filter[vendorNumber]=' . rawurlencode((string)ASC_VENDOR_NUMBER)
           . '&filter[reportDate]=' . rawurlencode($date);
        $r = asc_get_report($q, $jwt);
        $calls++;
        if ($r['ok']) {
            $day = asc_parse_day($r['tsv'], $appId);
        } elseif ($r['status'] === 404) {
            // Apple's way of saying nobody downloaded anything that day.
            // It is not an error and must not be reported as one.
            $day = ['firstTime' => 0, 'redownload' => 0, 'update' => 0, 'inApp' => 0,
                    'other' => 0, 'countries' => [], 'unknownTypes' => []];
            $missing[] = $date;
        } else {
            $errors[] = ['date' => $date, 'status' => $r['status'], 'error' => $r['error']];
            continue;
        }
        if ($cf !== null) @file_put_contents($cf, json_encode($day), LOCK_EX);
    }

    foreach ($tot as $k => $_) $tot[$k] += (int)($day[$k] ?? 0);
    foreach (($day['countries'] ?? []) as $cc => $n) $countries[$cc] = ($countries[$cc] ?? 0) + (int)$n;
    foreach (($day['unknownTypes'] ?? []) as $t) $unknown[$t] = true;
    $days[] = ['date' => $date, 'firstTime' => (int)($day['firstTime'] ?? 0),
               'redownload' => (int)($day['redownload'] ?? 0), 'update' => (int)($day['update'] ?? 0)];
}

arsort($countries);
$top = [];
foreach (array_slice($countries, 0, 10, true) as $cc => $n) $top[] = ['country' => $cc, 'firstTime' => $n];

json_response([
    'asOf'        => gmdate('c'),
    'timezone'    => 'America/Los_Angeles (Apple cuts the day on PT)',
    'from'        => $from->format('Y-m-d'),
    'to'          => $to->format('Y-m-d'),
    'appId'       => $appId,
    'totals'      => [
        'firstTimeDownloads' => $tot['firstTime'],
        'redownloads'        => $tot['redownload'],
        'updates'            => $tot['update'],
        'inAppPurchases'     => $tot['inApp'],
        'unclassified'       => $tot['other'],
    ],
    'days'          => $days,
    'topCountries'  => $top,
    'daysWithNoReport' => $missing,
    'apiCalls'      => $calls,
    'cached'        => $cacheOk,
    'errors'        => $errors,
    'note' => 'firstTimeDownloads is what "downloads" normally means: Apple counts a '
            . 'redownload and an update under separate product type identifiers and they are '
            . 'reported separately here. Days with no report are days with no activity. '
            . 'Daily reports exist for one year; before that use the monthly ones.'
            . (count($unknown) ? ' Unrecognised product type identifiers seen: '
               . implode(', ', array_keys($unknown)) . '.' : ''),
]);
