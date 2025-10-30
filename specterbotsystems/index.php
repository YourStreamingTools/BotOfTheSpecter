<?php
$heartbeatStatus = '';

include '/var/www/config/db_connect.php';

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

function format_speed($mb_per_sec) {
    $bytes_per_sec = $mb_per_sec * 1000000; // Convert MB/s to bytes/s
    if ($bytes_per_sec >= 1000000) {
        return number_format($mb_per_sec, 2) . ' MB/s';
    } elseif ($bytes_per_sec >= 1000) {
        return number_format($bytes_per_sec / 1000, 2) . ' KB/s';
    } else {
        return number_format($bytes_per_sec, 2) . ' B/s';
    }
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

$websocketetPingStatus = pingServer('websocket.botofthespecter.com', 443);
$notificationServiceStatus = ['status' => $websocketetPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $websocketetPingStatus];

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
$serverDisplayNames = [
    'web1' => 'Web Server 1',
    'bots' => 'Bot Server',
    'sql' => 'Database Service',
    'api' => 'API Service',
    'websocket' => 'WebSocket Service',
    'stream-au-east-1' => 'Stream AU-East-1',
    'stream-us-west-1' => 'Stream US-West-1',
    'stream-us-east-1' => 'Stream US-East-1'
];
$result = $conn->query("SELECT * FROM system_metrics ORDER BY server_name");
while ($row = $result->fetch_assoc()) {
    $metrics[] = $row;
}

// Do not preload beta users on the initial render so the client-side
// polling (AJAX) always fetches the latest data. The AJAX endpoint below
// still queries the database for fresh beta users on each request.
$userColumns = [[], []];

// Fetch total users
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];

// Fetch users by signup year (last 4 years)
$usersByYear = [];
$result = $conn->query("SELECT YEAR(signup_date) as year, COUNT(*) as count FROM users GROUP BY YEAR(signup_date) ORDER BY year DESC LIMIT 4");
while ($row = $result->fetch_assoc()) {
    $usersByYear[] = $row;
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
    $metricsAjax = [];
    $result = $conn->query("SELECT * FROM system_metrics ORDER BY server_name");
    while ($row = $result->fetch_assoc()) {
        $metricsAjax[] = $row;
    }
    $data['metrics'] = $metricsAjax;
    $betaUsersAjax = [];
    $result = $conn->query("SELECT twitch_display_name FROM users WHERE beta_access = '1' AND twitch_display_name NOT IN ('BotOfTheSpecter', 'GamingForAustralia') ORDER BY id");
    while ($row = $result->fetch_assoc()) {
        $betaUsersAjax[] = $row['twitch_display_name'];
    }
    $leftUsersAjax = array_slice($betaUsersAjax, 0, 16);
    $rightUsersAjax = array_slice($betaUsersAjax, 16, 16);
    $data['betaUsersLeft'] = $leftUsersAjax;
    $data['betaUsersRight'] = $rightUsersAjax;
    echo json_encode($data);
    exit;
}

function checkServiceStatus($serviceName, $serviceData) {
    if ($serviceData && $serviceData['status'] === 'OK') {
        $ping = $serviceData['ping'] . 'ms';
        return "<div class='status-item'><span class=\"has-text-weight-bold\">$serviceName:</span> $ping <span class='heartbeat beating'>❤️</span></div>";
    } elseif ($serviceData && $serviceData['status'] === 'DISABLED') {
        return "<div class='status-item'><span class=\"has-text-weight-bold\">$serviceName:</span> Disabled <span>⏸️</span></div>";
    } else {
        return "<div class='status-item'><span class=\"has-text-weight-bold\">$serviceName:</span> Down <span>💀</span></div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Status</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #292929; color: #ffffff; min-height: 100vh; padding: 5px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0px; }
        .columns { margin-bottom: 0; }
        h1 { text-align: center; margin-bottom: 0px; font-size: 1.5em; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .section { background: #292929; border-radius: 10px; padding: 10px; backdrop-filter: blur(10px); margin: 0; }
        .section h2 { margin-bottom: 5px; font-size: 1.1em; border-bottom: 2px solid #ffffff; padding-bottom: 5px; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 5px; }
        .status-item { background: rgba(255,255,255,0.05); padding: 5px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .status-item strong { font-size: 1.1em; }
        .heartbeat { color: #ff4d4d; transition: transform 0.2s ease; font-size: 1.2em; }
        .heartbeat.beating { color: #76ff7a; animation: beat 1s infinite; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .info-item { display: flex; justify-content: space-between; padding: 3px 0; border-bottom: 1px solid #292929; }
        .info-item:last-child { border-bottom: none; }
        .error { color: #ff4d4d; }
        .last-updated { text-align: center; margin-top: 5px; font-size: 0.9em; opacity: 0.8; }
        #system-metrics .status-item { background: transparent; align-items: flex-start; flex-direction: column; position: relative; }
        #system-metrics .status-item > div:last-child { text-align: left; }
        #system-metrics .status-item small { position: absolute; top: 0; right: 0; }
        .metric-header { display: flex; justify-content: space-between; align-items: center; }
        .beta-users { }
        .user-list { max-height: 500px; overflow-y: auto; }
        #signups-section h2 { margin-bottom: 0; font-size: 1em; }
        #signups-section .info-item { padding: 1px 0; }
        #signups-section .columns { margin-bottom: 0; }
        .bottom-row .section { padding-top: 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="title-row">
        <h1>BotOfTheSpecter System Status</h1>
        <div class="last-updated" id="last-updated">Last updated: <span id="update-time">Just now</span></div>
    </div>
    <!-- Service Statuses -->
    <div class="section">
        <div class="status-grid" id="service-status">
            <?= checkServiceStatus('Web Server 1', $web1Status); ?>
            <?= checkServiceStatus('Bot Server', $botServerStatus); ?>
            <?= checkServiceStatus('Database Service', $databaseServiceStatus); ?>
            <?= checkServiceStatus('API Service', $apiServiceStatus); ?>
            <?= checkServiceStatus('WebSocket Service', $notificationServiceStatus); ?>
            <?= checkServiceStatus('Stream AU-East-1', $streamAuEast1Status); ?>
            <?= checkServiceStatus('Stream US-West-1', $streamUsWest1Status); ?>
            <?= checkServiceStatus('Stream US-East-1', $streamUsEast1Status); ?>
        </div>
    </div>
    <div class="columns">
        <div class="column is-one-quarter">
            <!-- System Versions -->
            <div class="section">
                <h2>System Versions</h2>
                <div id="version-info">
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Stable:</span> <span id="stable-version"><?= isset($stableVersion) ? $stableVersion : 'N/A'; ?></span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Beta:</span> <span id="beta-version"><?= isset($betaVersion) ? $betaVersion : 'N/A'; ?></span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Discord Bot:</span> <span id="discord-version"><?= isset($discordVersion) ? $discordVersion : 'N/A'; ?></span></div>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter">
            <!-- Public API Requests -->
            <div class="section">
                <h2>Public API Requests</h2>
                <div id="api-limits">
                    <div class="info-item"><span class="has-text-weight-bold">Song Identification Remaing:</span> <span id="song-requests"><?= isset($songRequestsRemaining) ? $songRequestsRemaining : 'N/A'; ?></span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Exchange Rate Remaing:</span> <span id="exchange-requests"><?= isset($exchangeRateRequestsRemaining) ? $exchangeRateRequestsRemaining : 'N/A'; ?></span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Weather Remaing:</span> <span id="weather-requests"><?= isset($weatherRequestsRemaining) ? $weatherRequestsRemaining : 'N/A'; ?></span></div>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter" id="signups-column">
            <!-- Extra Column 1 -->
            <div class="section" id="signups-section">
                <h2>Number of Signups:</h2>
                <div>
                    <div class="info-item"><span class="has-text-weight-bold">Total:</span> <span><?php echo $totalUsers; ?></span></div>
                    <h2>Signups by Year:</h2>
                    <div class="columns is-mobile">
                        <div class="column is-half">
                            <?php if (isset($usersByYear[0])): ?>
                            <div class="info-item"><span class="has-text-weight-bold"><?php echo $usersByYear[0]['year']; ?>:</span> <span><?php echo $usersByYear[0]['count']; ?></span></div>
                            <?php endif; ?>
                        </div>
                        <div class="column is-half">
                            <?php if (isset($usersByYear[1])): ?>
                            <div class="info-item"><span class="has-text-weight-bold"><?php echo $usersByYear[1]['year']; ?>:</span> <span><?php echo $usersByYear[1]['count']; ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="columns is-mobile">
                        <div class="column is-half">
                            <?php if (isset($usersByYear[2])): ?>
                            <div class="info-item"><span class="has-text-weight-bold"><?php echo $usersByYear[2]['year']; ?>:</span> <span><?php echo $usersByYear[2]['count']; ?></span></div>
                            <?php endif; ?>
                        </div>
                        <div class="column is-half">
                            <?php if (isset($usersByYear[3])): ?>
                            <div class="info-item"><span class="has-text-weight-bold"><?php echo $usersByYear[3]['year']; ?>:</span> <span><?php echo $usersByYear[3]['count']; ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter">
            <!-- Extra Column 2 -->
            <div class="section">
                <!--<h2></h2>-->
                <div>
                    <div class="info-item"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="columns bottom-row">
        <div class="column is-half">
            <!-- System Metrics -->
            <div class="section">
                <h2>System Metrics</h2>
                <div id="system-metrics">
                    <?php foreach ($metrics as $metric): ?>
                    <div class="status-item">
                        <div class="metric-header">
                            <span class="has-text-weight-bold">Server: <?= htmlspecialchars($serverDisplayNames[$metric['server_name']] ?? $metric['server_name']); ?></span>
                        </div>
                        <div>
                            CPU: <?= number_format($metric['cpu_percent'], 1); ?>% |
                            RAM: <?= number_format($metric['ram_percent'], 1); ?>% (<?= number_format($metric['ram_used'], 1); ?>GB / <?= number_format($metric['ram_total'], 1); ?>GB)
                            <br>
                            Disk: <?= number_format($metric['disk_percent'], 1); ?>% (<?= number_format($metric['disk_used'], 1); ?>GB / <?= number_format($metric['disk_total'], 1); ?>GB) |
                            Net: ↑ <?= format_speed($metric['net_sent']); ?> ↓ <?= format_speed($metric['net_recv']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="column is-half">
            <!-- Beta Users -->
            <div class="section">
                <h2>Friends that use BotOfTheSpecter</h2>
                <div class="beta-users columns">
                    <?php foreach ($userColumns as $column): ?>
                    <div class="column is-half">
                        <div class="user-list">
                            <?php foreach ($column as $user): ?>
                            <div class="info-item"><span><?= htmlspecialchars($user); ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Helper function to format speed
function formatSpeed(mbPerSec) {
    const bytesPerSec = mbPerSec * 1000000; // Convert MB/s to bytes/s
    if (bytesPerSec >= 1000000) {
        return parseFloat(mbPerSec).toFixed(2) + ' MB/s';
    } else if (bytesPerSec >= 1000) {
        return (bytesPerSec / 1000).toFixed(2) + ' KB/s';
    } else {
        return bytesPerSec.toFixed(2) + ' B/s';
    }
}

// Helper to update service status HTML
const serverDisplayNames = {
    'web1': 'Web Server 1',
    'bots': 'Bot Server',
    'sql': 'Database Service',
    'api': 'API Service',
    'websocket': 'WebSocket Service',
    'stream-au-east-1': 'Stream AU-East-1',
    'stream-us-west-1': 'Stream US-West-1',
    'stream-us-east-1': 'Stream US-East-1'
};
function renderServiceStatus(name, statusData) {
    if (statusData.status === 'OK') {
        const ping = statusData.ping + 'ms';
        return `<div class='status-item'><span class="has-text-weight-bold">${name}:</span> ${ping} <span class='heartbeat beating'>❤️</span></div>`;
    } else if (statusData.status === 'DISABLED') {
        return `<div class='status-item'><span class="has-text-weight-bold">${name}:</span> Disabled <span>⏸️</span></div>`;
    } else {
        return `<div class='status-item'><span class="has-text-weight-bold">${name}:</span> Down <span>💀</span></div>`;
    }
}

// Fetch and update data every 60 seconds
function fetchAndUpdateStatus() {
    // Add a cache-busting timestamp so each fetch returns fresh data
    let url = window.location.pathname + '?ajax=1&metrics=1&_=' + Date.now();
    fetch(url)
        .then(res => res.json())
        .then(data => {
            // Update service statuses
            document.getElementById('service-status').innerHTML =
                renderServiceStatus('Web Server 1', data.web1Status) +
                renderServiceStatus('Bot Server', data.botServerStatus) +
                renderServiceStatus('Database Service', data.databaseServiceStatus) +
                renderServiceStatus('API Service', data.apiServiceStatus) +
                renderServiceStatus('WebSocket Service', data.notificationServiceStatus) +
                renderServiceStatus('Stream AU-East-1', data.streamAuEast1Status) +
                renderServiceStatus('Stream US-West-1', data.streamUsWest1Status) +
                renderServiceStatus('Stream US-East-1', data.streamUsEast1Status);
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
                        <div class="metric-header">
                            <span class="has-text-weight-bold">Server: ${serverDisplayNames[metric.server_name] || metric.server_name}</span>
                        </div>
                        <div>
                            CPU: ${parseFloat(metric.cpu_percent).toFixed(1)}% |
                            RAM: ${parseFloat(metric.ram_percent).toFixed(1)}% (${parseFloat(metric.ram_used).toFixed(1)}GB / ${parseFloat(metric.ram_total).toFixed(1)}GB)
                            <br>
                            Disk: ${parseFloat(metric.disk_percent).toFixed(1)}% (${parseFloat(metric.disk_used).toFixed(1)}GB / ${parseFloat(metric.disk_total).toFixed(1)}GB) |
                            Net: ↑ ${formatSpeed(metric.net_sent)} ↓ ${formatSpeed(metric.net_recv)}
                        </div>
                    </div>`;
                });
                document.getElementById('system-metrics').innerHTML = metricsHtml;
            }
            // Update beta users if the AJAX response includes them.
            // Use strict undefined check so empty arrays (no users) still replace the DOM.
            if (data.betaUsersLeft !== undefined && data.betaUsersRight !== undefined) {
                const userColumns = [data.betaUsersLeft, data.betaUsersRight];
                let usersHtml = '';
                userColumns.forEach(column => {
                    usersHtml += `<div class="column is-half"><div class="user-list">`;
                    column.forEach(user => {
                        usersHtml += `<div class="info-item"><span>${user}</span></div>`;
                    });
                    usersHtml += `</div></div>`;
                });
                document.querySelector('.beta-users').innerHTML = usersHtml;
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