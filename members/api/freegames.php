<?php
require_once dirname(__DIR__) . '/includes/session.php';
members_require_login_json();
session_write_close();

$ch = curl_init('https://api.botofthespecter.com/freestuff/games');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$resp = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $http !== 200) {
    json_out(['ok' => false, 'error' => 'Unable to fetch data from API'], 502);
}

$data = json_decode($resp, true);
if (!is_array($data) || !isset($data['games']) || !is_array($data['games'])) {
    json_out(['ok' => false, 'error' => 'Invalid response from API'], 502);
}

json_out([
    'ok' => true,
    'games' => $data['games'],
    'count' => $data['count'] ?? count($data['games']),
]);
