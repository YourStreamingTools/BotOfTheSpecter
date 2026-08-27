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

require_once __DIR__ . '/includes/host_metrics.php';

function loadStatusHostsConfig(): array {
    $path = is_file('/var/www/config/status_hosts.php')
        ? '/var/www/config/status_hosts.php'
        : (__DIR__ . '/../config/status_hosts.php');
    $cfg = is_file($path) ? (include $path) : [];
    return is_array($cfg) ? $cfg : [];
}

function loadWebIdentity(): array {
    $path = is_file('/var/www/config/web_identity.php')
        ? '/var/www/config/web_identity.php'
        : (__DIR__ . '/../config/web_identity.php');
    $cfg = is_file($path) ? (include $path) : [];
    return is_array($cfg) ? $cfg : [];
}

$mainConfig = include '/var/www/config/main.php';
$maintenanceMode = $mainConfig['maintenanceMode'] ?? false;
$maintenanceMessage = $mainConfig['maintenanceMessage'] ?? '';
$statusHostsCfg = loadStatusHostsConfig();
$webIdentity = loadWebIdentity();

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

    // Host inventory from config/status_hosts.php (web hosts + services).
    // Adding web2 is config-only once health_metrics.php is on that host.
    $webHosts = is_array($statusHostsCfg['web_hosts'] ?? null) ? $statusHostsCfg['web_hosts'] : [];
    $serviceHosts = is_array($statusHostsCfg['services'] ?? null) ? $statusHostsCfg['services'] : [];
    $localWebId = (string) ($webIdentity['server_name']
        ?? $statusHostsCfg['local_web_id']
        ?? 'web1');

    $hostStatuses = [];
    $serverDisplayNames = [];
    foreach ($webHosts as $host) {
        if (!is_array($host) || empty($host['id'])) {
            continue;
        }
        $id = (string) $host['id'];
        $label = (string) ($host['label'] ?? $id);
        $serverDisplayNames[$id] = $label;
        $pingHost = (string) ($host['ping_host'] ?? '');
        $pingPort = (int) ($host['ping_port'] ?? 443);
        $ping = $pingHost !== '' ? pingServer($pingHost, $pingPort) : -1;
        $hostStatuses[] = [
            'id' => $id,
            'label' => $label,
            'status' => $ping >= 0 ? 'OK' : 'OFF',
            'ping' => $ping,
        ];
    }
    foreach ($serviceHosts as $host) {
        if (!is_array($host) || empty($host['id'])) {
            continue;
        }
        $id = (string) $host['id'];
        $label = (string) ($host['label'] ?? $id);
        $serverDisplayNames[$id] = $label;
        $pingHost = (string) ($host['ping_host'] ?? '');
        $pingPort = (int) ($host['ping_port'] ?? 443);
        $ping = $pingHost !== '' ? pingServer($pingHost, $pingPort) : -1;
        $hostStatuses[] = [
            'id' => $id,
            'label' => $label,
            'status' => $ping >= 0 ? 'OK' : 'OFF',
            'ping' => $ping,
        ];
    }
    // Back-compat keys used by older cached clients / partial deploys
    $web1Status = ['status' => 'OFF', 'ping' => -1];
    $databaseServiceStatus = ['status' => 'OFF', 'ping' => -1];
    $apiServiceStatus = ['status' => 'OFF', 'ping' => -1];
    $notificationServiceStatus = ['status' => 'OFF', 'ping' => -1];
    $botServerStatus = ['status' => 'OFF', 'ping' => -1];
    foreach ($hostStatuses as $hs) {
        if ($hs['id'] === 'web1') {
            $web1Status = ['status' => $hs['status'], 'ping' => $hs['ping']];
        } elseif ($hs['id'] === 'sql') {
            $databaseServiceStatus = ['status' => $hs['status'], 'ping' => $hs['ping']];
        } elseif ($hs['id'] === 'api') {
            $apiServiceStatus = ['status' => $hs['status'], 'ping' => $hs['ping']];
        } elseif ($hs['id'] === 'websocket') {
            $notificationServiceStatus = ['status' => $hs['status'], 'ping' => $hs['ping']];
        } elseif ($hs['id'] === 'bots') {
            $botServerStatus = ['status' => $hs['status'], 'ping' => $hs['ping']];
        }
    }

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

    // Live system metrics: every web host metrics_url + each service metrics_url.
    // Local /proc fallback when this machine is that web host and remote fetch fails.
    $metrics = [];
    $appendMetric = static function (array &$metrics, array $m, string $fallbackName): void {
        if (empty($m['ok'])) {
            return;
        }
        $metrics[] = [
            'server_name' => (string) ($m['server_name'] ?? $fallbackName),
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
    };

    foreach ($webHosts as $host) {
        if (!is_array($host) || empty($host['id'])) {
            continue;
        }
        $id = (string) $host['id'];
        $url = trim((string) ($host['metrics_url'] ?? ''));
        $m = $url !== '' ? fetchData($url) : null;
        if ((!is_array($m) || empty($m['ok'])) && $id === $localWebId) {
            $m = specter_collect_host_metrics($id, 'web');
        }
        if (is_array($m)) {
            $appendMetric($metrics, $m, $id);
        }
    }
    foreach ($serviceHosts as $host) {
        if (!is_array($host) || empty($host['id'])) {
            continue;
        }
        $id = (string) $host['id'];
        $url = trim((string) ($host['metrics_url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $m = fetchData($url);
        if (is_array($m)) {
            $appendMetric($metrics, $m, $id);
        }
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
        // Preferred: ordered list from status_hosts.php (supports N web servers)
        'hostStatuses' => $hostStatuses,
        'serverDisplayNames' => $serverDisplayNames,
        // Back-compat for older clients
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
require __DIR__ . '/includes/spa.php';
