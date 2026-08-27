<?php
// Non-AJAX requests serve the React shell. Ping/DB work only runs for ?ajax=1.
if (!isset($_GET['ajax'])) {
    require __DIR__ . '/includes/spa.php';
}

include '/var/www/config/db_connect.php';

function fetchData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    return json_decode($response, true);
}

function pingServer($host, $port) {
    $starttime = microtime(true);
    $file = @fsockopen($host, $port, $errno, $errstr, 2);
    $stoptime = microtime(true);
    $status = 0;
    if (!$file) {
        $status = -1;
    } else {
        fclose($file);
        $status = ($stoptime - $starttime) * 1000;
        $status = floor($status);
    }
    return $status;
}

$versionData = fetchData('https://api.botofthespecter.com/versions');
if ($versionData) {
    $betaVersion = $versionData['beta_version'];
    $stableVersion = $versionData['stable_version'];
    $discordVersion = $versionData['discord_bot'] ?? null;
} else {
    $betaVersion = $stableVersion = $discordVersion = null;
}

$apiPingStatus = pingServer('api.botofthespecter.com', 443);
$apiServiceStatus = ['status' => $apiPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $apiPingStatus];

$websocketetPingStatus = pingServer('websocket.botofthespecter.com', 443);
$notificationServiceStatus = ['status' => $websocketetPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $websocketetPingStatus];

$databasePingStatus = pingServer('sql.botofthespecter.com', 3306);
$databaseServiceStatus = ['status' => $databasePingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $databasePingStatus];

$botServerPingStatus = pingServer('bots.botofthespecter.com', 22);
$botServerStatus = ['status' => $botServerPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $botServerPingStatus];

$web1PingStatus = pingServer('web1.botofthespecter.com', 443);
$web1Status = ['status' => $web1PingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $web1PingStatus];

$songData = fetchData('https://api.botofthespecter.com/api/song');
$songRequestsRemaining = $songData['requests_remaining'] ?? null;

$exchangeRateData = fetchData('https://api.botofthespecter.com/api/exchangerate');
$exchangeRateRequestsRemaining = $exchangeRateData['requests_remaining'] ?? null;

$weatherData = fetchData('https://api.botofthespecter.com/api/weather');
$weatherRequestsRemaining = $weatherData['requests_remaining'] ?? null;

header('Content-Type: application/json');
$data = [
    'apiServiceStatus' => $apiServiceStatus,
    'databaseServiceStatus' => $databaseServiceStatus,
    'notificationServiceStatus' => $notificationServiceStatus,
    'botServerStatus' => $botServerStatus,
    'web1Status' => $web1Status,
    'betaVersion' => $betaVersion,
    'stableVersion' => $stableVersion,
    'discordVersion' => $discordVersion,
    'songRequestsRemaining' => $songRequestsRemaining,
    'exchangeRateRequestsRemaining' => $exchangeRateRequestsRemaining,
    'weatherRequestsRemaining' => $weatherRequestsRemaining,
];
$metricsAjax = [];
$result = $conn->query("SELECT * FROM system_metrics ORDER BY server_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $metricsAjax[] = $row;
    }
}
$data['metrics'] = $metricsAjax;
$betaUsersAjax = [];
$result = $conn->query("SELECT twitch_display_name FROM users WHERE beta_access = '1' AND twitch_display_name NOT IN ('BotOfTheSpecter', 'GamingForAustralia') ORDER BY id");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $betaUsersAjax[] = $row['twitch_display_name'];
    }
}
$data['betaUsersLeft'] = array_slice($betaUsersAjax, 0, 16);
$data['betaUsersRight'] = array_slice($betaUsersAjax, 16, 16);

$totalUsers = null;
$result = $conn->query("SELECT COUNT(*) as count FROM users");
if ($result) {
    $totalUsers = $result->fetch_assoc()['count'] ?? null;
}
$data['totalUsers'] = $totalUsers;

$usersByYear = [];
$result = $conn->query("SELECT YEAR(signup_date) as year, COUNT(*) as count FROM users GROUP BY YEAR(signup_date) ORDER BY year DESC LIMIT 4");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $usersByYear[] = $row;
    }
}
$data['usersByYear'] = $usersByYear;

echo json_encode($data);
exit;
