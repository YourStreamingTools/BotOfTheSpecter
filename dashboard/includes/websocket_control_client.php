<?php
/**
 * HTTP client for the WebSocket host control API (lifecycle without SSH).
 * Server-side only — admin API key service "websocket".
 */

function websocket_control_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = '/var/www/config/websocket_control.php';
    if (!is_file($path)) {
        $path = __DIR__ . '/../../config/websocket_control.php';
    }
    $loaded = is_file($path) ? (include $path) : [];
    if (!is_array($loaded)) {
        $loaded = [];
    }
    $cfg = [
        'base_url' => rtrim($loaded['base_url'] ?? 'https://websocket.botofthespecter.com/control', '/'),
        'admin_service' => strtolower(trim((string)($loaded['admin_service'] ?? 'websocket'))),
        'control_key' => (string)($loaded['control_key'] ?? ''),
        'timeout' => (int)($loaded['timeout'] ?? 15),
    ];
    return $cfg;
}

function websocket_control_resolve_key(): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $cfg = websocket_control_config();
    if ($cfg['control_key'] !== '') {
        $resolved = $cfg['control_key'];
        return $resolved;
    }
    $service = $cfg['admin_service'] ?: 'websocket';
    global $conn;
    if (!isset($conn) || !$conn) {
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
function websocket_control_request(string $method, string $path, ?array $jsonBody = null, ?array $query = null): array {
    $cfg = websocket_control_config();
    $key = websocket_control_resolve_key();
    if ($key === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'No websocket control key — create service "' . ($cfg['admin_service'] ?: 'websocket') . '" under Admin → API Keys',
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
        'X-WS-CONTROL-KEY: ' . $key,
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_HTTPHEADER => $headers,
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
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err ?: 'curl error'];
    }
    $data = json_decode((string)$raw, true);
    if ($http >= 200 && $http < 300) {
        return ['ok' => true, 'status' => $http, 'data' => $data];
    }
    $msg = is_array($data) ? ($data['detail'] ?? $data['error'] ?? $raw) : $raw;
    if (is_array($msg)) {
        $msg = json_encode($msg);
    }
    return ['ok' => false, 'status' => $http, 'data' => $data, 'error' => (string)$msg];
}

function websocket_control_service_status(string $unit = 'websocket'): array {
    return websocket_control_request('GET', '/api/service/status', null, ['unit' => $unit]);
}

function websocket_control_service_action(string $action, string $unit = 'websocket'): array {
    $action = strtolower($action);
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Invalid action'];
    }
    return websocket_control_request('POST', '/api/service/' . $action, ['unit' => $unit]);
}
