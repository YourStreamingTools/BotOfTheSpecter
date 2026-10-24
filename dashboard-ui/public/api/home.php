<?php
// Home data: the same FastAPI /v2/dashboard/* calls dashboard.php makes in the
// browser, done server-side with the session's api_key so it never reaches JS.
require __DIR__ . '/_session.php';
beta_session_start();
if (empty($_SESSION['access_token']) || empty($_SESSION['api_key'])) beta_json(401, ['ok' => false]);
$apiKey = (string) $_SESSION['api_key'];
session_write_close();

$window = in_array($_GET['window'] ?? '', ['today', '7d', '30d'], true) ? $_GET['window'] : '7d';
$calls = [
    'live' => '/dashboard/live',
    'summary' => '/dashboard/summary?' . http_build_query(['window' => $window]),
    'leaderboards' => '/dashboard/leaderboards?limit=5',
    'activity' => '/dashboard/activity?limit=10',
];
$mh = curl_multi_init();
$handles = [];
foreach ($calls as $name => $path) {
    $ch = curl_init('https://api.botofthespecter.com/v2' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['X-API-KEY: ' . $apiKey, 'Accept: application/json'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$name] = $ch;
}
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) curl_multi_select($mh);
} while ($running && $status === CURLM_OK);

$out = ['ok' => true, 'window' => $window];
foreach ($handles as $name => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    $data = $code === 200 ? json_decode((string) $body, true) : null;
    if ($data === null) error_log("beta-dashboard home: $name returned HTTP $code");
    $out[$name] = $data;
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);
$out['dashboard_version'] = BETA_DASHBOARD_VERSION;
beta_json(200, $out);
