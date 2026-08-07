<?php

function fetchData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
if ($response === false) {
        return null;
    }
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

$mainConfig = include '/var/www/config/main.php';
$maintenanceMode = $mainConfig['maintenanceMode'] ?? false;
$maintenanceMessage = $mainConfig['maintenanceMessage'] ?? '';

// Never let a browser or intermediary proxy cache this page or its AJAX
// response - maintenance state must always reflect the live config.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// JSON endpoint for the JS polling
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    // Serve the shared cached payload while it's fresh so concurrent pollers
    // share one ping/API/DB fan-out per window instead of each redoing it.
    $cacheFile = sys_get_temp_dir() . '/specter_status.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 45) {
        readfile($cacheFile);
        exit;
    }
    // Failed queries must return false (not throw) so the guards below can
    // degrade to defaults and the endpoint still emits valid JSON.
    mysqli_report(MYSQLI_REPORT_OFF);
    include '/var/www/config/db_connect.php';

    // Directly ping the servers
    $apiPingStatus = pingServer('api.botofthespecter.com', 443);
    $apiServiceStatus = ['status' => $apiPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $apiPingStatus];

    $websocketPingStatus = pingServer('websocket.botofthespecter.com', 443);
    $notificationServiceStatus = ['status' => $websocketPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $websocketPingStatus];

    $databasePingStatus = pingServer('sql.botofthespecter.com', 3306);
    $databaseServiceStatus = ['status' => $databasePingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $databasePingStatus];

    $botServerPingStatus = pingServer('bots.botofthespecter.com', 22);
    $botServerStatus = ['status' => $botServerPingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $botServerPingStatus];

    $web1PingStatus = pingServer('web1.botofthespecter.com', 443);
    $web1Status = ['status' => $web1PingStatus >= 0 ? 'OK' : 'OFF', 'ping' => $web1PingStatus];

    // Fetch version data
    $versionData = fetchData('https://api.botofthespecter.com/versions');
    $betaVersion = $versionData['beta_version'] ?? null;
    $stableVersion = $versionData['stable_version'] ?? null;
    $discordVersion = $versionData['discord_bot'] ?? null;

    // Fetch public API request limits
    $songData = fetchData('https://api.botofthespecter.com/api/song');
    $songRequestsRemaining = $songData['requests_remaining'] ?? null;

    $exchangeRateData = fetchData('https://api.botofthespecter.com/api/exchangerate');
    $exchangeRateRequestsRemaining = $exchangeRateData['requests_remaining'] ?? null;

    $weatherData = fetchData('https://api.botofthespecter.com/api/weather');
    $weatherRequestsRemaining = $weatherData['requests_remaining'] ?? null;

    // Live system metrics from each service's public GET /health/metrics.
    // Replaces the old website.system_metrics table (cron status_monitor on
    // legacy hosts stopped updating after the multi-host migration).
    $metrics = [];
    $metricsOrder = ['api', 'websocket', 'bots', 'sql'];
    $metricsUrls = [
        'api' => 'https://api.botofthespecter.com/health/metrics',
        'websocket' => 'https://websocket.botofthespecter.com/health/metrics',
        'bots' => 'https://bots.botofthespecter.com/health/metrics',
        'sql' => 'https://sql.botofthespecter.com/health/metrics',
    ];
    foreach ($metricsOrder as $serverKey) {
        $m = fetchData($metricsUrls[$serverKey]);
        if (!is_array($m) || empty($m['ok'])) {
            continue;
        }
        $metrics[] = [
            'server_name' => (string) ($m['server_name'] ?? $serverKey),
            'cpu_percent' => $m['cpu_percent'] ?? null,
            'ram_percent' => $m['ram_percent'] ?? null,
            'ram_used' => $m['ram_used'] ?? null,
            'ram_total' => $m['ram_total'] ?? null,
            'disk_percent' => $m['disk_percent'] ?? null,
            'disk_used' => $m['disk_used'] ?? null,
            'disk_total' => $m['disk_total'] ?? null,
            'net_sent' => $m['net_sent'] ?? null,
            'net_recv' => $m['net_recv'] ?? null,
        ];
    }

    // Fetch chat message counts by bot system.
    // Cast to int so JSON emits numbers (mysqli returns numeric columns as strings;
    // string += in JS concatenates and produces absurd messages/min rates).
    $botMessageCounts = [];
    $result = $conn->query("SELECT bot_system, messages_sent FROM bot_messages WHERE bot_system IN ('discordbot', 'twitch_stable', 'twitch_beta', 'twitch_custom')");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $botMessageCounts[$row['bot_system']] = (int) $row['messages_sent'];
        }
    }

    // Fetch total users
    $totalUsers = null;
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $totalUsers = $result->fetch_assoc()['count'] ?? null;
    }

    // Fetch users by signup year (last 4 years)
    $usersByYear = [];
    $result = $conn->query("SELECT YEAR(signup_date) as year, COUNT(*) as count FROM users GROUP BY YEAR(signup_date) ORDER BY year DESC LIMIT 4");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $usersByYear[] = $row;
        }
    }

    // Fetch beta users
    $betaUsers = [];
    $result = $conn->query("SELECT twitch_display_name FROM users WHERE beta_access = '1' AND twitch_display_name NOT IN ('BotOfTheSpecter', 'GamingForAustralia') ORDER BY id");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $betaUsers[] = $row['twitch_display_name'];
        }
    }

    // Live running-bot count from bots control API (totals only — never
    // channel names / PIDs on this public status page).
    $runningBots = null;
    $botsInventory = null;
    foreach ([
        '/var/www/dashboard/includes/bots_api_client.php',
        __DIR__ . '/../dashboard/includes/bots_api_client.php',
    ] as $botsClientPath) {
        if (is_file($botsClientPath)) {
            require_once $botsClientPath;
            break;
        }
    }
    if (function_exists('bots_api_running_bots')) {
        $botsResp = bots_api_running_bots();
        if (!empty($botsResp['ok']) && is_array($botsResp['data'])) {
            $botsInventory = $botsResp['data'];
        }
    } else {
        // Fallback when dashboard include is not on this host: same auth as
        // bots_api_client (config + admin_api_keys service=bots), short timeout.
        $botsCfgPath = is_file('/var/www/config/bots_api.php')
            ? '/var/www/config/bots_api.php'
            : (__DIR__ . '/../config/bots_api.php');
        $botsCfg = is_file($botsCfgPath) ? (include $botsCfgPath) : [];
        if (!is_array($botsCfg)) {
            $botsCfg = [];
        }
        $botsBase = rtrim((string) ($botsCfg['base_url'] ?? 'https://bots.botofthespecter.com'), '/');
        $botsService = strtolower(trim((string) ($botsCfg['admin_service'] ?? 'bots'))) ?: 'bots';
        $botsKey = (string) ($botsCfg['control_key'] ?? '');
        if ($botsKey === '' && isset($conn) && $conn) {
            $stmt = $conn->prepare('SELECT api_key FROM admin_api_keys WHERE LOWER(service) = LOWER(?) LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $botsService);
                $stmt->execute();
                $keyRes = $stmt->get_result();
                $keyRow = $keyRes ? $keyRes->fetch_assoc() : null;
                $stmt->close();
                if ($keyRow && !empty($keyRow['api_key'])) {
                    $botsKey = (string) $keyRow['api_key'];
                }
            }
        }
        if ($botsKey !== '') {
            $ch = curl_init($botsBase . '/api/running_bots');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-API-KEY: ' . $botsKey,
                    'X-BOTS-CONTROL-KEY: ' . $botsKey,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw !== false && $http >= 200 && $http < 300) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $botsInventory = $decoded;
                }
            }
        }
    }
    if (is_array($botsInventory)) {
        $totalsIn = is_array($botsInventory['totals'] ?? null) ? $botsInventory['totals'] : [];
        $runningBots = [
            'total' => (int) ($botsInventory['total'] ?? 0),
            'totals' => [
                'stable' => (int) ($totalsIn['stable'] ?? 0),
                'beta'   => (int) ($totalsIn['beta'] ?? 0),
                'v6'     => (int) ($totalsIn['v6'] ?? 0),
                'custom' => (int) ($totalsIn['custom'] ?? 0),
                'kick'   => (int) ($totalsIn['kick'] ?? 0),
            ],
        ];
    }

    $data = [
        'maintenanceMode' => $maintenanceMode,
        'maintenanceMessage' => $maintenanceMessage,
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
        'metrics' => $metrics,
        'botMessageCounts' => $botMessageCounts,
        // When this payload was built (unix seconds). Preserved in the shared
        // cache so clients can tell a cache hit from a fresh sample and avoid
        // treating identical snapshots as a zero-rate interval.
        'generatedAt' => time(),
        'runningBots' => $runningBots,
        'totalUsers' => $totalUsers,
        'usersByYear' => $usersByYear,
        'betaUsers' => $betaUsers
    ];
    $json = json_encode($data);
    // Atomic cache write so a concurrent poller never reads a partial file
    $tmp = tempnam(sys_get_temp_dir(), 'specterstatus');
    if ($tmp !== false && file_put_contents($tmp, $json) !== false) {
        if (!@rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }
    echo $json;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Status</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; }
        body { display: block; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #292929; color: #ffffff; min-height: 100vh; padding: 8px; padding-bottom: 28px; font-size: 16px; line-height: 1.45; }
        .container { width: 100%; max-width: 100%; margin: 0; }
        .title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .maintenance-banner { display: flex; align-items: center; gap: 8px; background: rgba(255,193,7,0.12); border: 1px solid #ffc107; color: #ffe08a; border-radius: 8px; padding: 8px 14px; margin-bottom: 8px; font-size: 0.95em; }
        .maintenance-banner[hidden] { display: none; }
        .maintenance-banner .icon { font-size: 1.1em; }
        .columns { margin-bottom: 0; }
        h1 { text-align: left; margin-bottom: 0; font-size: 1.6em; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .section { background: #292929; border-radius: 10px; padding: 10px 14px; backdrop-filter: blur(10px); margin: 0; }
        .section h2 { margin-bottom: 6px; font-size: 1.15em; border-bottom: 2px solid #ffffff; padding-bottom: 4px; }
        .status-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
        .status-item { background: rgba(255,255,255,0.05); padding: 8px 12px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .status-item strong { font-size: 1.05em; }
        .heartbeat { color: #ff4d4d; transition: transform 0.2s ease; font-size: 1.25em; }
        .heartbeat.beating { color: #76ff7a; animation: beat 1s infinite; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .info-item { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #292929; }
        .info-item:last-child { border-bottom: none; }
        .last-updated { text-align: center; font-size: 0.92em; opacity: 0.8; white-space: nowrap; }
        #system-metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 16px; }
        #system-metrics .status-item { background: transparent; align-items: flex-start; flex-direction: column; position: relative; padding: 4px 6px; gap: 2px; font-size: 0.95em; }
        #system-metrics .status-item > .metric-body { text-align: left; line-height: 1.4; }
        #system-metrics .metric-line { display: block; }
        #system-metrics .status-item small { position: absolute; top: 0; right: 0; }
        .metric-header { display: flex; justify-content: space-between; align-items: center; font-size: 1.03em; margin-bottom: 2px; }
        .user-list {
            /* column-width sets a target per-column width; browser fits as many
               columns as the container allows. At ~620px (half-page on a 1280px
               viewport) that's 3 columns; at ~940px (half-page on 1920px) it's 4.
               When names overflow vertically the container scrolls. */
            column-width: 200px;
            column-gap: 1.25rem;
            column-fill: balance;
            max-height: calc(100vh - 360px);
            min-height: 280px;
            overflow-y: auto;
            /* Keep last names clear of the fixed bottom-right brand logo */
            padding-bottom: 96px;
        }
        .user-list .info-item {
            padding: 3px 0;
            font-size: 0.97em;
            break-inside: avoid;
            -webkit-column-break-inside: avoid;
            page-break-inside: avoid;
        }
        #signups-section h2 { margin-bottom: 2px; font-size: 1em; }
        #signups-section h3 { margin: 4px 0 2px; font-size: 0.95em; }
        #signups-section .info-item { padding: 2px 0; }
        #signups-section .columns { margin-bottom: 0; }
        .bottom-row .section { padding-top: 8px; }
        /* OBS-friendly brand mark — bottom-right watermark (matches typical overlay placement) */
        .status-brand-logo {
            position: fixed;
            right: 14px;
            bottom: 12px;
            width: min(160px, 18vw);
            height: auto;
            max-height: 22vh;
            object-fit: contain;
            opacity: 0.92;
            pointer-events: none;
            z-index: 20;
            filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.55));
            user-select: none;
        }
        @media (max-width: 1100px) {
            .status-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            body { font-size: 14px; }
            .status-grid { grid-template-columns: repeat(2, 1fr); }
            #system-metrics { grid-template-columns: 1fr; }
            h1 { text-align: center; }
            .title-row { flex-direction: column; gap: 4px; }
            .last-updated { white-space: normal; }
            .status-brand-logo { width: 96px; right: 10px; bottom: 8px; }
            /* style.css only resets is-two-thirds/is-one-third at this width;
               the child combinator keeps the is-mobile year pairs side by side */
            .columns:not(.is-mobile) > .column.is-one-quarter,
            .columns:not(.is-mobile) > .column.is-half { flex: 1 1 100%; max-width: 100%; }
        }
    </style>
</head>
<body>
<img class="status-brand-logo"
     src="https://cdn.botofthespecter.com/logo.png"
     alt="BotOfTheSpecter"
     width="160"
     height="160"
     decoding="async">
<div class="container">
    <div class="maintenance-banner" id="maintenance-banner" <?php echo $maintenanceMode ? '' : 'hidden'; ?>>
        <span class="icon" aria-hidden="true">🛠️</span>
        <span id="maintenance-banner-text"><?php echo $maintenanceMessage; ?></span>
    </div>
    <div class="title-row">
        <h1>BotOfTheSpecter System Status</h1>
        <div class="last-updated" id="last-updated">Time right now: <span id="current-time">--:--:--</span> &nbsp;|&nbsp; Last updated: <span id="update-time">Loading...</span></div>
    </div>
    <!-- Service Statuses -->
    <div class="section">
            <div class="status-grid" id="service-status">
                <div class='status-item'><span class="has-text-weight-bold">Web Server 1:</span> Checking... <span aria-hidden="true">⏳</span></div>
                <div class='status-item'><span class="has-text-weight-bold">Database Service:</span> Checking... <span aria-hidden="true">⏳</span></div>
                <div class='status-item'><span class="has-text-weight-bold">API Service:</span> Checking... <span aria-hidden="true">⏳</span></div>
                <div class='status-item'><span class="has-text-weight-bold">WebSocket Service:</span> Checking... <span aria-hidden="true">⏳</span></div>
                <div class='status-item'><span class="has-text-weight-bold">Bot Server:</span> Checking... <span aria-hidden="true">⏳</span></div>
            </div>
    </div>
    <div class="columns">
        <div class="column is-one-quarter">
            <!-- System Versions -->
            <div class="section">
                <h2>System Versions</h2>
                <div id="version-info">
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Stable:</span> <span id="stable-version">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Beta:</span> <span id="beta-version">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Discord Bot:</span> <span id="discord-version">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Running Bots:</span> <span id="running-bots-total">Loading...</span></div>
                    <div class="info-item" id="running-bots-breakdown" hidden></div>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter">
            <!-- Public API Requests -->
            <div class="section">
                <h2>Public API Requests</h2>
                <div id="api-limits">
                    <div class="info-item"><span class="has-text-weight-bold">Song Identification Remaining:</span> <span id="song-requests">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Exchange Rate Remaining:</span> <span id="exchange-requests">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Weather Remaining:</span> <span id="weather-requests">Loading...</span></div>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter" id="signups-column">
            <!-- Extra Column 1 -->
            <div class="section" id="signups-section">
                <h2>Number of Signups</h2>
                <div>
                    <div class="info-item"><span class="has-text-weight-bold">Total:</span> <span id="total-users">Loading...</span></div>
                    <h3>Signups by Year</h3>
                    <div class="columns is-mobile">
                        <div class="column is-half">
                            <div class="info-item" id="year-item-0" hidden><span class="has-text-weight-bold"><span id="year-0"></span>:</span> <span id="count-0"></span></div>
                        </div>
                        <div class="column is-half">
                            <div class="info-item" id="year-item-1" hidden><span class="has-text-weight-bold"><span id="year-1"></span>:</span> <span id="count-1"></span></div>
                        </div>
                    </div>
                    <div class="columns is-mobile">
                        <div class="column is-half">
                            <div class="info-item" id="year-item-2" hidden><span class="has-text-weight-bold"><span id="year-2"></span>:</span> <span id="count-2"></span></div>
                        </div>
                        <div class="column is-half">
                            <div class="info-item" id="year-item-3" hidden><span class="has-text-weight-bold"><span id="year-3"></span>:</span> <span id="count-3"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter">
            <!-- Messages Sent Section -->
            <div class="section">
                <h2>Messages Sent</h2>
                <div id="message-counts">
                    <div class="info-item"><span class="has-text-weight-bold">Discord Bot:</span> <span id="discord-messages">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Stable:</span> <span id="stable-messages">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Beta:</span> <span id="beta-messages">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Chat Bot Custom:</span> <span id="custom-messages">Loading...</span></div>
                    <div class="info-item"><span class="has-text-weight-bold">Messages/min:</span> <span id="overall-msg-rate">—</span></div>
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
                    <div class="status-item">Loading...</div>
                </div>
            </div>
        </div>
        <div class="column is-half">
            <!-- Beta Users -->
            <div class="section">
                <h2>Friends that use BotOfTheSpecter</h2>
                <div class="beta-users user-list"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Helper function to format speed (metrics net_* are MB/s)
function formatSpeed(mbPerSec) {
    const n = Number(mbPerSec);
    if (!Number.isFinite(n) || n < 0) {
        return '—';
    }
    const bytesPerSec = n * 1000000; // Convert MB/s to bytes/s
    if (bytesPerSec >= 1000000) {
        return n.toFixed(2) + ' MB/s';
    } else if (bytesPerSec >= 1000) {
        return (bytesPerSec / 1000).toFixed(2) + ' KB/s';
    } else {
        return bytesPerSec.toFixed(2) + ' B/s';
    }
}

// Format numbers with thousands separators for display (handles null/undefined)
function formatNumber(n) {
    if (n === null || n === undefined) return 'N/A';
    if (typeof n === 'number' || !isNaN(n)) return Number(n).toLocaleString();
    return String(n);
}

// Escape strings before injecting them into innerHTML templates
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
}

// Helper to update service status HTML
const serverDisplayNames = {
    'web1': 'Web Server 1',
    'sql': 'Database Service',
    'api': 'API Service',
    'websocket': 'WebSocket Service',
    'bots': 'Bot Server'
};
function renderServiceStatus(name, statusData) {
    if (statusData.status === 'OK') {
        const ping = statusData.ping + 'ms';
        return `<div class='status-item'><span class="has-text-weight-bold">${name}:</span> ${ping} <span class='heartbeat beating' role='img' aria-label='Online'>❤️</span></div>`;
    } else if (statusData.status === 'DISABLED') {
        // Reserved for a future maintenance state; the endpoint only emits OK/OFF today
        return `<div class='status-item'><span class="has-text-weight-bold">${name}:</span> Disabled <span aria-hidden='true'>⏸️</span></div>`;
    } else {
        return `<div class='status-item'><span class="has-text-weight-bold">${name}:</span> Down <span aria-hidden='true'>💀</span></div>`;
    }
}

// Messages/min uses a rolling window of unique server samples (not raw
// poll-to-poll deltas). A single quiet minute or a shared-cache duplicate
// used to flash "0.0/min" between real rates.
const MSG_RATE_WINDOW_MS = 5 * 60 * 1000;
let msgRateSamples = []; // { tMs, total, generatedAt }
let lastMsgRateText = '—';

// Fetch and update data every 60 seconds
function fetchAndUpdateStatus() {
    // Add a cache-busting timestamp so each fetch returns fresh data
    let url = window.location.pathname + '?ajax=1&_=' + Date.now();
    fetch(url, { cache: 'no-store' })
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(data => {
            // Update maintenance banner
            const banner = document.getElementById('maintenance-banner');
            if (banner) {
                banner.hidden = !data.maintenanceMode;
                if (data.maintenanceMode) {
                    document.getElementById('maintenance-banner-text').innerHTML = data.maintenanceMessage || '';
                }
            }
            // Update service statuses
            // Use an explicit array to control the order of displayed services
            const serviceOrder = [
                { key: 'web1Status', label: 'Web Server 1' },
                { key: 'databaseServiceStatus', label: 'Database Service' },
                { key: 'apiServiceStatus', label: 'API Service' },
                { key: 'notificationServiceStatus', label: 'WebSocket Service' },
                { key: 'botServerStatus', label: 'Bot Server' }
            ];
            // Build HTML in the requested order
            let statusHtml = '';
            serviceOrder.forEach(svc => {
                const statusData = data[svc.key];
                if (statusData) {
                    statusHtml += renderServiceStatus(svc.label, statusData);
                }
            });
            document.getElementById('service-status').innerHTML = statusHtml;
            // Update versions
            document.getElementById('stable-version').textContent = data.stableVersion ?? 'N/A';
            document.getElementById('beta-version').textContent = data.betaVersion ?? 'N/A';
            document.getElementById('discord-version').textContent = data.discordVersion ?? 'N/A';
            // Running bots (live inventory totals from bots API — counts only)
            const runningTotalEl = document.getElementById('running-bots-total');
            const runningBreakEl = document.getElementById('running-bots-breakdown');
            if (data.runningBots && typeof data.runningBots.total === 'number') {
                runningTotalEl.textContent = formatNumber(data.runningBots.total);
                const t = data.runningBots.totals || {};
                const parts = [
                    ['Stable', t.stable],
                    ['Beta', t.beta],
                    ['V6', t.v6],
                    ['Custom', t.custom],
                    ['Kick', t.kick]
                ]
                    .filter(([, n]) => Number(n) > 0)
                    .map(([label, n]) => label + ': ' + formatNumber(n));
                if (parts.length && runningBreakEl) {
                    runningBreakEl.textContent = parts.join(' · ');
                    runningBreakEl.hidden = false;
                } else if (runningBreakEl) {
                    runningBreakEl.textContent = '';
                    runningBreakEl.hidden = true;
                }
            } else {
                runningTotalEl.textContent = 'N/A';
                if (runningBreakEl) {
                    runningBreakEl.textContent = '';
                    runningBreakEl.hidden = true;
                }
            }
            // Update song info
            document.getElementById('song-requests').textContent = formatNumber(data.songRequestsRemaining);
            // Update exchange info
            document.getElementById('exchange-requests').textContent = formatNumber(data.exchangeRateRequestsRemaining);
            // Update weather info
            document.getElementById('weather-requests').textContent = formatNumber(data.weatherRequestsRemaining);
            // Update message counts if present
            if (data.botMessageCounts) {
                const nowMs = Date.now();
                // Prefer server sample time so rate spans real data age, not just
                // client poll spacing (shared cache can be up to ~45s old).
                const generatedAt = Number(data.generatedAt) || 0;
                const sampleMs = generatedAt > 0 ? generatedAt * 1000 : nowMs;
                const msgSystems = [
                    { key: 'discordbot',    elMsg: 'discord-messages' },
                    { key: 'twitch_stable', elMsg: 'stable-messages'  },
                    { key: 'twitch_beta',   elMsg: 'beta-messages'    },
                    { key: 'twitch_custom', elMsg: 'custom-messages'  }
                ];
                let totalNow = 0;
                msgSystems.forEach(({ key, elMsg }) => {
                    // Always numeric: JSON/mysqli can still deliver strings from cache or older payloads
                    const count = Math.max(0, Number(data.botMessageCounts[key]) || 0);
                    document.getElementById(elMsg).textContent = count === 0 ? 'Not Counting Yet' : formatNumber(count);
                    totalNow += count;
                });

                const rateEl = document.getElementById('overall-msg-rate');
                if (totalNow <= 0) {
                    msgRateSamples = [];
                    lastMsgRateText = 'N/A';
                    rateEl.textContent = lastMsgRateText;
                } else {
                    let last = msgRateSamples[msgRateSamples.length - 1];
                    // Counter reset (manual or schema re-seed) — drop history
                    if (last && totalNow < last.total) {
                        msgRateSamples = [];
                        last = null;
                        lastMsgRateText = '—';
                    }
                    // Only record a new sample when the server built a new payload.
                    // Cache hits share generatedAt; counting them as 0-delta intervals
                    // was the main cause of number → 0.0 → number flicker.
                    const isNewSample = !last
                        || (generatedAt > 0 && last.generatedAt !== generatedAt)
                        || (generatedAt <= 0 && totalNow !== last.total);
                    if (isNewSample) {
                        msgRateSamples.push({
                            tMs: sampleMs,
                            total: totalNow,
                            generatedAt: generatedAt || sampleMs
                        });
                    }
                    // Keep ~5 minutes of unique samples (and never drop the newest)
                    const cutoff = sampleMs - MSG_RATE_WINDOW_MS;
                    msgRateSamples = msgRateSamples.filter((s, i, arr) =>
                        s.tMs >= cutoff || i === arr.length - 1
                    );

                    if (msgRateSamples.length >= 2) {
                        const oldest = msgRateSamples[0];
                        const newest = msgRateSamples[msgRateSamples.length - 1];
                        const elapsedMin = (newest.tMs - oldest.tMs) / 60000;
                        const delta = newest.total - oldest.total;
                        // Need a meaningful span so a near-zero window can't spike
                        if (elapsedMin >= 0.05 && delta >= 0) {
                            lastMsgRateText = (delta / elapsedMin).toFixed(1) + '/min';
                        }
                        // else keep lastMsgRateText (hold through bad/short windows)
                    } else {
                        // Warm-up: first unique sample only
                        lastMsgRateText = '—';
                    }
                    rateEl.textContent = lastMsgRateText;
                }
            }
            // Update signup data if present
            if (data.totalUsers !== undefined) {
                document.getElementById('total-users').textContent = formatNumber(data.totalUsers);
            }
            if (data.usersByYear) {
                data.usersByYear.forEach((yearData, index) => {
                    const yearElement = document.getElementById('year-' + index);
                    const countElement = document.getElementById('count-' + index);
                    const itemElement = document.getElementById('year-item-' + index);
                    if (yearElement && countElement) {
                        yearElement.textContent = yearData.year;
                        countElement.textContent = formatNumber(yearData.count);
                        if (itemElement) itemElement.hidden = false;
                    }
                });
            }
            // Update metrics if present (live /health/metrics from each API host)
            if (data.metrics) {
                let metricsHtml = '';
                if (!data.metrics.length) {
                    metricsHtml = '<div class="status-item">Metrics unavailable — services may still be deploying /health/metrics</div>';
                } else {
                    data.metrics.forEach(metric => {
                        const cpu = Number(metric.cpu_percent);
                        const ramPct = Number(metric.ram_percent);
                        const ramUsed = Number(metric.ram_used);
                        const ramTotal = Number(metric.ram_total);
                        const diskPct = Number(metric.disk_percent);
                        const diskUsed = Number(metric.disk_used);
                        const diskTotal = Number(metric.disk_total);
                        const cpuTxt = Number.isFinite(cpu) ? cpu.toFixed(1) + '%' : '—';
                        const ramTxt = Number.isFinite(ramPct)
                            ? ramPct.toFixed(1) + '% (' + (Number.isFinite(ramUsed) ? ramUsed.toFixed(1) : '—') + 'GB / ' + (Number.isFinite(ramTotal) ? ramTotal.toFixed(1) : '—') + 'GB)'
                            : '—';
                        const diskTxt = Number.isFinite(diskPct)
                            ? diskPct.toFixed(1) + '% (' + (Number.isFinite(diskUsed) ? diskUsed.toFixed(1) : '—') + 'GB / ' + (Number.isFinite(diskTotal) ? diskTotal.toFixed(1) : '—') + 'GB)'
                            : '—';
                        metricsHtml += `<div class="status-item">
                            <div class="metric-header">
                                <span class="has-text-weight-bold">Server: ${escapeHtml(serverDisplayNames[metric.server_name] || metric.server_name)}</span>
                            </div>
                            <div class="metric-body">
                                <span class="metric-line">CPU: ${cpuTxt} | RAM: ${ramTxt}</span>
                                <span class="metric-line">DISK: ${diskTxt}</span>
                                <span class="metric-line">NETWORK: ↑ ${formatSpeed(metric.net_sent)} ↓ ${formatSpeed(metric.net_recv)}</span>
                            </div>
                        </div>`;
                    });
                }
                document.getElementById('system-metrics').innerHTML = metricsHtml;
            }
            // Update beta users if the AJAX response includes them.
            // Use strict undefined check so empty arrays (no users) still replace the DOM.
            if (data.betaUsers !== undefined) {
                let usersHtml = '';
                data.betaUsers.forEach(user => {
                    usersHtml += `<div class="info-item"><span>${escapeHtml(user)}</span></div>`;
                });
                document.querySelector('.beta-users').innerHTML = usersHtml;
            }
            // Update last updated time
            document.getElementById('update-time').textContent = new Date().toLocaleTimeString();
        })
        .catch(err => {
            console.error('Status update failed:', err);
            document.getElementById('update-time').textContent = 'update failed - retrying';
        });
}

// Live local-time clock so visitors can compare "now" against "Last updated"
function updateClock() {
    document.getElementById('current-time').textContent = new Date().toLocaleTimeString();
}
setInterval(updateClock, 1000);
updateClock();

// Poll every 60 seconds
setInterval(fetchAndUpdateStatus, 60000);
// Also fetch immediately on load
fetchAndUpdateStatus();
</script>
</body>
</html>

