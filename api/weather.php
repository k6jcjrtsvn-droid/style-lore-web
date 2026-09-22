<?php
/**
 * GET /api/weather.php?lat=..&lon=.. — today's weather for "Today's
 * outfit" on Home, proxied through Open-Meteo (free, no key) so the
 * browser never talks to a third party and the CSP stays unchanged.
 *
 * Coordinates are rounded to 2 decimals (~1 km) before use, never logged
 * or stored; responses are cached on disk per rounded cell for 30 minutes
 * so a busy morning costs Open-Meteo one call per neighbourhood, not one
 * per person. If Open-Meteo is unreachable the last good reading for the
 * cell is served instead (up to WEATHER_STALE_MAX) rather than an error.
 *
 * Returns {tempC, highC, lowC, rainChance (0-100), code, label}.
 */
require_once __DIR__ . '/../includes/helpers.php';

const WEATHER_ATTEMPTS = 2;         // upstream tries before giving up
const WEATHER_CONNECT_TIMEOUT = 3;  // seconds to get a connection
const WEATHER_TIMEOUT = 4;          // seconds per attempt (2 x 4 = the old 8s ceiling)
const WEATHER_CACHE_TTL = 1800;     // 30 min: how long a reading is served as fresh
const WEATHER_STALE_MAX = 21600;    // 6 h: how long it may stand in when upstream is down

require_method('GET');

$lat = (float)($_GET['lat'] ?? 0);
$lon = (float)($_GET['lon'] ?? 0);
if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) {
    error_response('Location needed.', 400);
}
$lat = round($lat, 2);
$lon = round($lon, 2);

$cacheDir = sys_get_temp_dir() . '/style-lore-weather';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
$cacheFile = $cacheDir . '/' . md5($lat . ',' . $lon) . '.json';
header('Cache-Control: private, max-age=900');
if (is_file($cacheFile) && filemtime($cacheFile) > time() - WEATHER_CACHE_TTL) {
    $cached = file_get_contents($cacheFile);
    if ($cached) { header('Content-Type: application/json'); echo $cached; exit; }
}

$url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
     . '&current=temperature_2m,weather_code&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,weather_code&timezone=auto&forecast_days=1';
// Two attempts: a single dropped connection to Open-Meteo used to surface
// as a 502 on someone's Home screen (and an api_5xx report in support@).
// The per-attempt budget is halved so the worst case stays at the 8s this
// endpoint always had, and CONNECTTIMEOUT bounds a stalled handshake
// rather than letting it eat the whole budget.
$raw = null;
$code = 0;
for ($attempt = 0; $attempt < WEATHER_ATTEMPTS; $attempt++) {
    if ($attempt > 0) usleep(250000);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => WEATHER_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => WEATHER_TIMEOUT,
        CURLOPT_HTTPHEADER => ['User-Agent: Style-LORE/1.0 (style-lore.com)'],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $raw) break;
}
$data = $raw ? json_decode($raw, true) : null;
if ($code !== 200 || !is_array($data) || !isset($data['current']['temperature_2m'])) {
    // stale-if-error. The cache above is only trusted for 30 minutes, but
    // when Open-Meteo is unreachable a few-hours-old reading for this same
    // ~1 km cell is far better than an error card: the temperature has
    // barely moved and nobody is dressing by the minute. Only past
    // WEATHER_STALE_MAX is it honestly unusable.
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - WEATHER_STALE_MAX) {
        $stale = file_get_contents($cacheFile);
        if ($stale) {
            header('Content-Type: application/json');
            header('X-Weather-Stale: 1');
            echo $stale;
            exit;
        }
    }
    error_response("Couldn't get the weather right now.", 502);
}

$wcode = (int)($data['daily']['weather_code'][0] ?? $data['current']['weather_code'] ?? 0);
$labels = [
    0 => 'clear', 1 => 'mostly clear', 2 => 'partly cloudy', 3 => 'overcast',
    45 => 'fog', 48 => 'fog', 51 => 'drizzle', 53 => 'drizzle', 55 => 'drizzle',
    56 => 'freezing drizzle', 57 => 'freezing drizzle', 61 => 'light rain', 63 => 'rain', 65 => 'heavy rain',
    66 => 'freezing rain', 67 => 'freezing rain', 71 => 'light snow', 73 => 'snow', 75 => 'heavy snow', 77 => 'snow',
    80 => 'showers', 81 => 'showers', 82 => 'heavy showers', 85 => 'snow showers', 86 => 'snow showers',
    95 => 'thunderstorms', 96 => 'thunderstorms', 99 => 'thunderstorms',
];
$out = [
    'tempC' => round((float)$data['current']['temperature_2m']),
    'highC' => round((float)($data['daily']['temperature_2m_max'][0] ?? $data['current']['temperature_2m'])),
    'lowC' => round((float)($data['daily']['temperature_2m_min'][0] ?? $data['current']['temperature_2m'])),
    'rainChance' => (int)($data['daily']['precipitation_probability_max'][0] ?? 0),
    'code' => $wcode,
    'label' => $labels[$wcode] ?? 'cloudy',
];
$json = json_encode($out);
@file_put_contents($cacheFile, $json);
header('Content-Type: application/json');
echo $json;
