<?php
$heartbeatStatus = '';

include 'config/db_connect.php';

// Create system_metrics table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS system_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_name VARCHAR(255) NOT NULL,
    cpu_percent FLOAT,
    ram_percent FLOAT,
    ram_used FLOAT,
    ram_total FLOAT,
    disk_percent FLOAT,
    disk_used FLOAT,
    disk_total FLOAT,
    net_sent FLOAT,
    net_recv FLOAT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_server (server_name)
)");

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
    $discordVersion = $versionData['discord_bot'] ?? null;
} else {
    $betaVersion = $stableVersion = $discordVersion = null;
}

// Directly ping the servers
$apiPingStatus = pingServer('api.botofthespecter.com', 443);
$apiServiceStatus = ['status' => $apiPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $apiPingStatus];

$websocketPingStatus = pingServer('websock.botofthespecter.com', 443);
$notificationServiceStatus = ['status' => $websocketPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $websocketPingStatus];

$databasePingStatus = pingServer('sql.botofthespecter.com', 3306);
$databaseServiceStatus = ['status' => $databasePingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $databasePingStatus];

// Additional server monitoring
$botServerPingStatus = pingServer('bots.botofthespecter.com', 22);
$botServerStatus = ['status' => $botServerPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $botServerPingStatus];

$streamUsEast1PingStatus = pingServer('us-east-1.botofthespecter.video', 1935);
$streamUsEast1Status = ['status' => $streamUsEast1PingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $streamUsEast1PingStatus];
$streamUsEast1Status['status'] = 'DISABLED'; // Temporarily disabled

$streamUsWest1PingStatus = pingServer('us-west-1.botofthespecter.video', 1935);
$streamUsWest1Status = ['status' => $streamUsWest1PingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $streamUsWest1PingStatus];
$streamUsWest1Status['status'] = 'DISABLED'; // Temporarily disabled

$streamAuEast1PingStatus = pingServer('au-east-1.botofthespecter.video', 1935);
$streamAuEast1Status = ['status' => $streamAuEast1PingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $streamAuEast1PingStatus];

$web1PingStatus = pingServer('web1.botofthespecter.com', 443);
$web1Status = ['status' => $web1PingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $web1PingStatus];

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

// Fetch system metrics if requested
$metrics = [];
if (isset($_GET['metrics'])) {
    $result = $conn->query("SELECT * FROM system_metrics ORDER BY server_name");
    while ($row = $result->fetch_assoc()) {
        $metrics[] = $row;
    }
}

// AJAX endpoint for JS polling
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $data = [
        'apiServiceStatus' => $apiServiceStatus,
        'databaseServiceStatus' => $databaseServiceStatus,
        'notificationServiceStatus' => $notificationServiceStatus,
        'botServerStatus' => $botServerStatus,
        'streamUsEast1Status' => $streamUsEast1Status,
        'streamUsWest1Status' => $streamUsWest1Status,
        'streamAuEast1Status' => $streamAuEast1Status,
        'web1Status' => $web1Status,
        'betaVersion' => $betaVersion,
        'stableVersion' => $stableVersion,
        'discordVersion' => $discordVersion,
        'songRequestsRemaining' => $songRequestsRemaining,
        'exchangeRateRequestsRemaining' => $exchangeRateRequestsRemaining,
        'weatherRequestsRemaining' => $weatherRequestsRemaining
    ];
    if (isset($_GET['metrics'])) {
        $metricsAjax = [];
        $result = $conn->query("SELECT * FROM system_metrics ORDER BY server_name");
        while ($row = $result->fetch_assoc()) {
            $metricsAjax[] = $row;
        }
        $data['metrics'] = $metricsAjax;
    }
    echo json_encode($data);
    exit;
}

function checkServiceStatus($serviceName, $serviceData) {
    if ($serviceData && $serviceData['status'] === 'OK') {
        $ping = $serviceData['ping'] . 'ms';
        return "<div class='status-item'><strong>$serviceName:</strong> $ping <span class='heartbeat beating'>❤️</span></div>";
    } elseif ($serviceData && $serviceData['status'] === 'DISABLED') {
        return "<div class='status-item'><strong>$serviceName:</strong> Disabled <span>⏸️</span></div>";
    } else {
        return "<div class='status-item'><strong>$serviceName:</strong> Down <span>💀</span></div>";
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
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: transparent; color: #ffffff; min-height: 100vh; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 30px; font-size: 2.5em; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .section { background: #292929; border-radius: 10px; padding: 20px; margin-bottom: 20px; backdrop-filter: blur(10px); }
        .section h2 { margin-bottom: 15px; font-size: 1.5em; border-bottom: 2px solid #ffffff; padding-bottom: 5px; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        .status-item { background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .status-item strong { font-size: 1.1em; }
        .heartbeat { color: #ff4d4d; transition: transform 0.2s ease; font-size: 1.2em; }
        .heartbeat.beating { color: #76ff7a; animation: beat 1s infinite; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .info-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #292929; }
        .info-item:last-child { border-bottom: none; }
        .error { color: #ff4d4d; }
        .last-updated { text-align: center; margin-top: 20px; font-size: 0.9em; opacity: 0.8; }
    </style>
</head>
<body>
<div class="container">
    <h1>BotOfTheSpecter System Status</h1>
    <!-- Service Statuses -->
    <div class="section">
        <h2>Service Status</h2>
        <div class="status-grid" id="service-status">
            <?= checkServiceStatus('Web Server 1', $web1Status); ?>
            <?= checkServiceStatus('Bot Server', $botServerStatus); ?>
            <?= checkServiceStatus('Database Service', $databaseServiceStatus); ?>
            <?= checkServiceStatus('API Service', $apiServiceStatus); ?>
            <?= checkServiceStatus('Notification Service', $notificationServiceStatus); ?>
            <?= checkServiceStatus('Stream US-East-1', $streamUsEast1Status); ?>
            <?= checkServiceStatus('Stream US-West-1', $streamUsWest1Status); ?>
            <?= checkServiceStatus('Stream AU-East-1', $streamAuEast1Status); ?>
        </div>
    </div>
    <!-- Versions -->
    <div class="section">
        <h2>Versions</h2>
        <div id="version-info">
            <div class="info-item"><strong>Stable Version:</strong> <span id="stable-version"><?= isset($stableVersion) ? $stableVersion : 'N/A'; ?></span></div>
            <div class="info-item"><strong>Beta Version:</strong> <span id="beta-version"><?= isset($betaVersion) ? $betaVersion : 'N/A'; ?></span></div>
            <div class="info-item"><strong>Discord Bot Version:</strong> <span id="discord-version"><?= isset($discordVersion) ? $discordVersion : 'N/A'; ?></span></div>
        </div>
    </div>
    <!-- API Limits -->
    <div class="section">
        <h2>API Request Limits</h2>
        <div id="api-limits">
            <div class="info-item"><strong>Song Requests Remaining:</strong> <span id="song-requests"><?= isset($songRequestsRemaining) ? $songRequestsRemaining : 'N/A'; ?></span></div>
            <div class="info-item"><strong>Exchange Rate Requests Remaining:</strong> <span id="exchange-requests"><?= isset($exchangeRateRequestsRemaining) ? $exchangeRateRequestsRemaining : 'N/A'; ?></span></div>
            <div class="info-item"><strong>Weather Requests Remaining Today:</strong> <span id="weather-requests"><?= isset($weatherRequestsRemaining) ? $weatherRequestsRemaining : 'N/A'; ?></span></div>
        </div>
    </div>
    <?php if (isset($_GET['metrics'])): ?>
    <!-- System Metrics -->
    <div class="section">
        <h2>System Metrics</h2>
        <div id="system-metrics">
            <?php foreach ($metrics as $metric): ?>
            <div class="status-item">
                <strong>Server: <?= htmlspecialchars($metric['server_name']); ?></strong>
                <div>
                    CPU: <?= number_format($metric['cpu_percent'], 1); ?>% |
                    RAM: <?= number_format($metric['ram_percent'], 1); ?>% (<?= number_format($metric['ram_used'], 1); ?>GB / <?= number_format($metric['ram_total'], 1); ?>GB) |
                    Disk: <?= number_format($metric['disk_percent'], 1); ?>% (<?= number_format($metric['disk_used'], 1); ?>GB / <?= number_format($metric['disk_total'], 1); ?>GB) |
                    Net: ↑<?= number_format($metric['net_sent'], 1); ?>MB ↓<?= number_format($metric['net_recv'], 1); ?>MB
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="last-updated" id="last-updated">Last updated: <span id="update-time">Just now</span></div>
</div>

<script>
// Helper to update service status HTML
function renderServiceStatus(name, statusData) {
    if (statusData.status === 'OK') {
        const ping = statusData.ping + 'ms';
        return `<div class='status-item'><strong>${name}:</strong> ${ping} <span class='heartbeat beating'>❤️</span></div>`;
    } else if (statusData.status === 'DISABLED') {
        return `<div class='status-item'><strong>${name}:</strong> Disabled <span>⏸️</span></div>`;
    } else {
        return `<div class='status-item'><strong>${name}:</strong> Down <span>💀</span></div>`;
    }
}

// Fetch and update data every 60 seconds
function fetchAndUpdateStatus() {
    let url = window.location.pathname + '?ajax=1';
    if (window.location.search.includes('metrics')) {
        url += '&metrics=1';
    }
    fetch(url)
        .then(res => res.json())
        .then(data => {
            // Update service statuses
            document.getElementById('service-status').innerHTML =
                renderServiceStatus('Web Server 1', data.web1Status) +
                renderServiceStatus('Bot Server', data.botServerStatus) +
                renderServiceStatus('Database Service', data.databaseServiceStatus) +
                renderServiceStatus('API Service', data.apiServiceStatus) +
                renderServiceStatus('Notification Service', data.notificationServiceStatus) +
                renderServiceStatus('Stream US-East-1', data.streamUsEast1Status) +
                renderServiceStatus('Stream US-West-1', data.streamUsWest1Status) +
                renderServiceStatus('Stream AU-East-1', data.streamAuEast1Status);
            // Update versions
            document.getElementById('stable-version').textContent = data.stableVersion ?? 'N/A';
            document.getElementById('beta-version').textContent = data.betaVersion ?? 'N/A';
            document.getElementById('discord-version').textContent = data.discordVersion ?? 'N/A';
            // Update song info
            document.getElementById('song-requests').textContent = data.songRequestsRemaining ?? 'N/A';
            // Update exchange info
            document.getElementById('exchange-requests').textContent = data.exchangeRateRequestsRemaining ?? 'N/A';
            // Update weather info
            document.getElementById('weather-requests').textContent = data.weatherRequestsRemaining ?? 'N/A';
            // Update metrics if present
            if (data.metrics) {
                let metricsHtml = '';
                data.metrics.forEach(metric => {
                    metricsHtml += `<div class="status-item">
                        <strong>Server: ${metric.server_name}</strong>
                        <div>
                            CPU: ${parseFloat(metric.cpu_percent).toFixed(1)}% |
                            RAM: ${parseFloat(metric.ram_percent).toFixed(1)}% (${parseFloat(metric.ram_used).toFixed(1)}GB / ${parseFloat(metric.ram_total).toFixed(1)}GB) |
                            Disk: ${parseFloat(metric.disk_percent).toFixed(1)}% (${parseFloat(metric.disk_used).toFixed(1)}GB / ${parseFloat(metric.disk_total).toFixed(1)}GB) |
                            Net: ↑${parseFloat(metric.net_sent).toFixed(1)}MB ↓${parseFloat(metric.net_recv).toFixed(1)}MB
                        </div>
                    </div>`;
                });
                document.getElementById('system-metrics').innerHTML = metricsHtml;
            }
            // Update last updated time
            document.getElementById('update-time').textContent = new Date().toLocaleTimeString();
        });
}

// Poll every 60 seconds
setInterval(fetchAndUpdateStatus, 60000);
// Also fetch immediately on load
fetchAndUpdateStatus();
</script>
</body>
</html>