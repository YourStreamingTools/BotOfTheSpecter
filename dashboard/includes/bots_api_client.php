<?php
/**
 * HTTP client for the private bot-host control API (bots.botofthespecter.com).
 * Server-side only — uses admin API key service "bots" from admin_api_keys.
 */

function bots_api_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = '/var/www/config/bots_api.php';
    if (!is_file($path)) {
        $path = __DIR__ . '/../../config/bots_api.php';
    }
    $loaded = is_file($path) ? (include $path) : [];
    if (!is_array($loaded)) {
        $loaded = [];
    }
    $cfg = [
        'base_url' => rtrim($loaded['base_url'] ?? 'https://bots.botofthespecter.com', '/'),
        'admin_service' => strtolower(trim((string)($loaded['admin_service'] ?? 'bots'))),
        'control_key' => (string)($loaded['control_key'] ?? ''),
        'timeout' => (int)($loaded['timeout'] ?? 15),
    ];
    return $cfg;
}

/**
 * Resolve the bots control key from website.admin_api_keys (service=bots),
 * then optional config override. Super-admin "admin" is not used as the
 * outbound caller key — create a dedicated service=bots key.
 */
function bots_api_resolve_control_key(): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $cfg = bots_api_config();
    if ($cfg['control_key'] !== '') {
        $resolved = $cfg['control_key'];
        return $resolved;
    }
    $service = $cfg['admin_service'] ?: 'bots';
    global $conn;
    if (!isset($conn) || !$conn) {
        // Lazy-load DB if dashboard has not already
        $dbPath = '/var/www/config/db_connect.php';
        if (!is_file($dbPath)) {
            $dbPath = __DIR__ . '/../../config/db_connect.php';
        }
        if (is_file($dbPath)) {
            require_once $dbPath;
        }
    }
    if (isset($conn) && $conn) {
        $stmt = $conn->prepare("SELECT api_key FROM admin_api_keys WHERE LOWER(service) = LOWER(?) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $service);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row && !empty($row['api_key'])) {
                $resolved = (string)$row['api_key'];
                return $resolved;
            }
        }
    }
    $resolved = '';
    return $resolved;
}

/**
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function bots_api_request(string $method, string $path, ?array $jsonBody = null, ?array $query = null): array {
    $cfg = bots_api_config();
    $key = bots_api_resolve_control_key();
    if ($key === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'No bots control key — create service "' . ($cfg['admin_service'] ?: 'bots') . '" under Admin → API Keys',
        ];
    }
    $url = $cfg['base_url'] . $path;
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'X-API-KEY: ' . $key,
        'X-BOTS-CONTROL-KEY: ' . $key,
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => min(5, $cfg['timeout']),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => "curl error: $err"];
    }
    $data = null;
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
    }
    $ok = $status >= 200 && $status < 300;
    return [
        'ok' => $ok,
        'status' => $status,
        'data' => $data,
        'error' => $ok ? null : (is_array($data) ? ($data['detail'] ?? $raw) : (string)$raw),
    ];
}

function bots_api_health(): array {
    $cfg = bots_api_config();
    $url = $cfg['base_url'] . '/health';
    $timeout = min(5, max(2, (int)($cfg['timeout'] ?? 15)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => "curl error: $err"];
    }
    $data = json_decode((string)$raw, true);
    $ok = $status >= 200 && $status < 300 && is_array($data) && !empty($data['ok']);
    return ['ok' => $ok, 'status' => $status, 'data' => is_array($data) ? $data : null, 'error' => $ok ? null : (string)$raw];
}

function bots_api_running_bots(): array {
    return bots_api_request('GET', '/api/running_bots');
}

/**
 * Durable last-seen inventory (crash / OOM recovery). Prefer bots_api_running_bots()
 * which already embeds snapshot; this hits the dedicated endpoint when needed.
 */
function bots_api_running_bots_snapshot(): array {
    return bots_api_request('GET', '/api/running_bots/snapshot');
}

function bots_api_bot_status(string $channel, ?string $botType = null): array {
    $q = ['channel' => $channel];
    if ($botType) {
        $q['bot_type'] = $botType;
    }
    return bots_api_request('GET', '/api/bot/status', null, $q);
}

function bots_api_start_bot(array $body): array {
    return bots_api_request('POST', '/api/bot/start', $body);
}

function bots_api_stop_bot(string $channel, string $botType = 'stable'): array {
    return bots_api_request('POST', '/api/bot/stop', [
        'channel' => $channel,
        'bot_type' => $botType,
    ]);
}

// Stream online marker (logs/online/{channel}.txt on bot host).
function bots_api_online_marker(string $channel): array {
    $channel = strtolower(trim($channel));
    return bots_api_request('GET', '/api/online/' . rawurlencode($channel));
}

// Allowlisted bot-host maintenance script (refresh_spotify|refresh_streamelements|refresh_discord|refresh_custom_bot).
function bots_api_run_script(string $script): array {
    return bots_api_request('POST', '/api/ops/run_script', ['script' => $script]);
}

/**
 * Stop every Twitch bot variant that might be running for a channel.
 * Used when the Twitch login renames so processes launched with -channel oldname die.
 *
 * Order: prefer the type reported by status, then sweep stable/beta/v6/custom.
 * Kick/discord are separate processes and are not stopped here.
 *
 * @return array{
 *   ok:bool,
 *   channel:string,
 *   stopped:array<int,string>,
 *   already_stopped:array<int,string>,
 *   errors:array<int,string>,
 *   details:array<string,array>
 * }
 */
function bots_api_stop_all_for_channel(string $channel): array {
    $channel = strtolower(trim($channel));
    $out = [
        'ok' => true,
        'channel' => $channel,
        'stopped' => [],
        'already_stopped' => [],
        'errors' => [],
        'details' => [],
    ];
    if ($channel === '' || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $channel)) {
        $out['ok'] = false;
        $out['errors'][] = 'invalid channel';
        return $out;
    }

    $typesToTry = [];
    $status = bots_api_bot_status($channel, null);
    if ($status['ok'] && is_array($status['data'])) {
        $running = !empty($status['data']['running']);
        $foundType = isset($status['data']['bot_type']) ? (string) $status['data']['bot_type'] : '';
        if ($running && $foundType !== '') {
            $typesToTry[] = $foundType;
        }
    }
    foreach (['stable', 'beta', 'v6', 'custom'] as $t) {
        if (!in_array($t, $typesToTry, true)) {
            $typesToTry[] = $t;
        }
    }

    foreach ($typesToTry as $botType) {
        $resp = bots_api_stop_bot($channel, $botType);
        $out['details'][$botType] = $resp;
        if (!$resp['ok']) {
            // "not running" style responses still come back 200 with state already_stopped.
            // Non-2xx is a real failure (auth, network, hard kill fail).
            $out['ok'] = false;
            $err = is_string($resp['error'] ?? null) ? $resp['error'] : 'stop failed';
            $out['errors'][] = "$botType: $err";
            continue;
        }
        $data = is_array($resp['data']) ? $resp['data'] : [];
        $state = (string) ($data['state'] ?? '');
        if ($state === 'stopped') {
            $out['stopped'][] = $botType;
        } else {
            // already_stopped, or success with no process
            $out['already_stopped'][] = $botType;
        }
    }

    return $out;
}
