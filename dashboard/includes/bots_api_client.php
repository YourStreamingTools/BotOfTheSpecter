<?php
/**
 * HTTP client for the private bot-host control API (bots.botofthespecter.com).
 * Server-side only — never expose control_key to the browser.
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
        'control_key' => (string)($loaded['control_key'] ?? ''),
        'timeout' => (int)($loaded['timeout'] ?? 15),
    ];
    return $cfg;
}

/**
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function bots_api_request(string $method, string $path, ?array $jsonBody = null, ?array $query = null): array {
    $cfg = bots_api_config();
    if ($cfg['control_key'] === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'bots_api control_key not configured'];
    }
    $url = $cfg['base_url'] . $path;
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'X-API-KEY: ' . $cfg['control_key'],
        'X-BOTS-CONTROL-KEY: ' . $cfg['control_key'],
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

function bots_api_running_bots(): array {
    return bots_api_request('GET', '/api/running_bots');
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
