<?php
/**
 * GET /api/weather.php?lat=..&lon=.. — today's weather for "Today's
 * outfit" on Home, proxied through Open-Meteo (free, no key) so the
 * browser never talks to a third party and the CSP stays unchanged.
 *
 * Coordinates are rounded to 2 decimals (~1 km) before use, never logged
 * or stored; responses are cached on disk per rounded cell for 30 minutes
 * so a busy morning costs Open-Meteo one call per neighbourhood, not one
 * per person.
 *
 * Returns {tempC, highC, lowC, rainChance (0-100), code, label}.
 */
require_once __DIR__ . '/../includes/helpers.php';

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
if (is_file($cacheFile) && filemtime($cacheFile) > time() - 1800) {
    $cached = file_get_contents($cacheFile);
    if ($cached) { header('Content-Type: application/json'); echo $cached; exit; }
}

$url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
     . '&current=temperature_2m,weather_code&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,weather_code&timezone=auto&forecast_days=1';
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['User-Agent: Style-LORE/1.0 (style-lore.com)']]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = $raw ? json_decode($raw, true) : null;
if ($code !== 200 || !is_array($data) || !isset($data['current']['temperature_2m'])) {
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
