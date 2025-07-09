<?php
$heartbeatStatus = '';

function fetchData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function pingServer($host, $port) {
    $starttime = microtime(true);
    $file = @fsockopen($host, $port, $errno, $errstr, 2);
    $stoptime = microtime(true);
    $status = 0;

    if (!$file) {
        $status = -1;  // Site is down
    } else {
        fclose($file);
        $status = ($stoptime - $starttime) * 1000;
        $status = floor($status);
    }
    return $status;
}

// Fetch version data
$versionData = fetchData('https://api.botofthespecter.com/versions');
if ($versionData) {
    $betaVersion = $versionData['beta_version'];
    $stableVersion = $versionData['stable_version'];
} else {
    $betaVersion = $stableVersion = null;
}

// Directly ping the servers
$apiPingStatus = pingServer('172.105.189.43', 443);
$apiServiceStatus = ['status' => $apiPingStatus >= 0 ? 'OK' : 'OFF'];

$websocketPingStatus = pingServer('172.105.180.8', 443);
$notificationServiceStatus = ['status' => $websocketPingStatus >= 0 ? 'OK' : 'OFF'];

$databasePingStatus = pingServer('194.195.122.234', 3306);
$databaseServiceStatus = ['status' => $databasePingStatus >= 0 ? 'OK' : 'OFF'];

// Additional server monitoring
$botServerPingStatus = pingServer('172.105.191.96', 22);
$botServerStatus = ['status' => $botServerPingStatus >= 0 ? 'OK' : 'OFF'];

$streamUsEast1PingStatus = pingServer('172.235.150.18', 1935);
$streamUsEast1Status = ['status' => $streamUsEast1PingStatus >= 0 ? 'OK' : 'OFF'];

$streamUsWest1PingStatus = pingServer('172.232.173.107', 1935);
$streamUsWest1Status = ['status' => $streamUsWest1PingStatus >= 0 ? 'OK' : 'OFF'];

$streamAuEast1PingStatus = pingServer('172.105.161.23', 1935);
$streamAuEast1Status = ['status' => $streamAuEast1PingStatus >= 0 ? 'OK' : 'OFF'];

$web1PingStatus = pingServer('172.105.191.110', 443);
$web1Status = ['status' => $web1PingStatus >= 0 ? 'OK' : 'OFF'];

$billingPingStatus = pingServer('192.53.169.203', 443);
$billingStatus = ['status' => $billingPingStatus >= 0 ? 'OK' : 'OFF'];

// Fetch song request data
$songData = fetchData('https://api.botofthespecter.com/api/song');
if ($songData) {
    $songRequestsRemaining = $songData['requests_remaining'];
} else {
    $songRequestsRemaining = null;
}

// Fetch exchange rate request data
$exchangeRateData = fetchData('https://api.botofthespecter.com/api/exchangerate');
if ($exchangeRateData) {
    $exchangeRateRequestsRemaining = $exchangeRateData['requests_remaining'];
} else {
    $exchangeRateRequestsRemaining = null;
}

// Fetch weather request data
$weatherData = fetchData('https://api.botofthespecter.com/api/weather');
if ($weatherData) {
    $weatherRequestsRemaining = $weatherData['requests_remaining'];
} else {
    $weatherRequestsRemaining = null;
}

// AJAX endpoint for JS polling
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'apiServiceStatus' => $apiServiceStatus,
        'databaseServiceStatus' => $databaseServiceStatus,
        'notificationServiceStatus' => $notificationServiceStatus,
        'botServerStatus' => $botServerStatus,
        'streamUsEast1Status' => $streamUsEast1Status,
        'streamUsWest1Status' => $streamUsWest1Status,
        'streamAuEast1Status' => $streamAuEast1Status,
        'web1Status' => $web1Status,
        'billingStatus' => $billingStatus,
        'betaVersion' => $betaVersion,
        'stableVersion' => $stableVersion,
        'songRequestsRemaining' => $songRequestsRemaining,
        'exchangeRateRequestsRemaining' => $exchangeRateRequestsRemaining,
        'weatherRequestsRemaining' => $weatherRequestsRemaining
    ]);
    exit;
}

function checkServiceStatus($serviceName, $serviceData) {
    if ($serviceData && $serviceData['status'] === 'OK') {
        return "<p><strong>$serviceName:</strong> <span class='heartbeat beating'>❤️</span></p>";
    } else {
        return "<p><strong>$serviceName:</strong> <span>💀</span></p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Status</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 16px; color: #ffffff; height: 100vh; overflow: hidden; display: flex; flex-direction: column; align-items: flex-start; justify-content: flex-start; padding: 20px; }
        .info, .heartbeat-container, .error { font-size: 26px; margin: 10px 0; }
        .heartbeat-container { display: flex; align-items: center; margin-bottom: 20px; }
        .heartbeat { color: #ff4d4d; transition: transform 0.2s ease; }
        .heartbeat.beating { color: #76ff7a; animation: beat 1s infinite; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .error { color: #ff4d4d; }
        .countdown { margin-top: 10px; color: #ffffff; }
    </style>
</head>
<body>
<!-- Display Additional Service Statuses -->
<div class="info" id="service-status">
    <?= checkServiceStatus('API Service', $apiServiceStatus); ?>
    <?= checkServiceStatus('Database Service', $databaseServiceStatus); ?>
    <?= checkServiceStatus('Notification Service', $notificationServiceStatus); ?>
    <?= checkServiceStatus('Bot Server', $botServerStatus); ?>
    <?= checkServiceStatus('Stream US-East-1', $streamUsEast1Status); ?>
    <?= checkServiceStatus('Stream US-West-1', $streamUsWest1Status); ?>
    <?= checkServiceStatus('Stream AU-East-1', $streamAuEast1Status); ?>
    <?= checkServiceStatus('Web Server 1', $web1Status); ?>
    <?= checkServiceStatus('Billing Service', $billingStatus); ?>
</div>

<!-- Display Versions -->
<div class="info" id="version-info">
    <p><strong>Beta Version:</strong> <span id="beta-version"><?= isset($betaVersion) ? $betaVersion : 'N/A'; ?></span></p>
    <p><strong>Stable Version:</strong> <span id="stable-version"><?= isset($stableVersion) ? $stableVersion : 'N/A'; ?></span></p>
</div>

<!-- Display Song Request Info -->
<div class="info" id="song-info">
    <p><strong>Song Requests Remaining:</strong> <span id="song-requests"><?= isset($songRequestsRemaining) ? $songRequestsRemaining : 'N/A'; ?></span></p>
</div>

<!-- Display Exchange Rate Request Info -->
<div class="info" id="exchange-info">
    <p><strong>Exchange Rate Requests Remaining:</strong> <span id="exchange-requests"><?= isset($exchangeRateRequestsRemaining) ? $exchangeRateRequestsRemaining : 'N/A'; ?></span></p>
</div>

<!-- Display Weather Request Info -->
<div class="info" id="weather-info">
    <p><strong>Weather Requests Remaining Today:</strong> <span id="weather-requests"><?= isset($weatherRequestsRemaining) ? $weatherRequestsRemaining : 'N/A'; ?></span></p>
</div>

<script>
// Helper to update service status HTML
function renderServiceStatus(name, status) {
    if (status === 'OK') {
        return `<p><strong>${name}:</strong> <span class='heartbeat beating'>❤️</span></p>`;
    } else {
        return `<p><strong>${name}:</strong> <span>💀</span></p>`;
    }
}

// Fetch and update data every 60 seconds
function fetchAndUpdateStatus() {
    fetch(window.location.pathname + '?ajax=1')
        .then(res => res.json())
        .then(data => {
            // Update service statuses
            document.getElementById('service-status').innerHTML =
                renderServiceStatus('API Service', data.apiServiceStatus.status) +
                renderServiceStatus('Database Service', data.databaseServiceStatus.status) +
                renderServiceStatus('Notification Service', data.notificationServiceStatus.status) +
                renderServiceStatus('Bot Server', data.botServerStatus.status) +
                renderServiceStatus('Stream US-East-1', data.streamUsEast1Status.status) +
                renderServiceStatus('Stream US-West-1', data.streamUsWest1Status.status) +
                renderServiceStatus('Stream AU-East-1', data.streamAuEast1Status.status) +
                renderServiceStatus('Web Server 1', data.web1Status.status) +
                renderServiceStatus('Billing Service', data.billingStatus.status);
            // Update versions
            document.getElementById('beta-version').textContent = data.betaVersion ?? 'N/A';
            document.getElementById('stable-version').textContent = data.stableVersion ?? 'N/A';
            // Update song info
            document.getElementById('song-requests').textContent = data.songRequestsRemaining ?? 'N/A';
            // Update exchange info
            document.getElementById('exchange-requests').textContent = data.exchangeRateRequestsRemaining ?? 'N/A';
            // Update weather info
            document.getElementById('weather-requests').textContent = data.weatherRequestsRemaining ?? 'N/A';
        });
}

// Poll every 60 seconds
setInterval(fetchAndUpdateStatus, 60000);
// Also fetch immediately on load
fetchAndUpdateStatus();
</script>
</body>
</html>