<?php
/**
 * HTTP client for the tenant-scoped SQL data API (sql.botofthespecter.com).
 *
 * Server-side only. Uses the streamer's user API key — never MySQL credentials.
 * Prefer helpers: sql_api_select, sql_api_insert, sql_api_update, sql_api_delete.
 */

declare(strict_types=1);

/**
 * @return array{base_url:string,timeout:int,keys_dir:string,allow_legacy_mysql:bool}
 */
function sql_api_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = '/var/www/config/sql_api.php';
    if (!is_file($path)) {
        $path = dirname(__DIR__) . '/config/sql_api.php';
    }
    $loaded = is_file($path) ? (include $path) : [];
    if (!is_array($loaded)) {
        $loaded = [];
    }
    $cfg = [
        'base_url' => rtrim((string)($loaded['base_url'] ?? 'https://sql.botofthespecter.com'), '/'),
        'timeout' => (int)($loaded['timeout'] ?? 15),
        'keys_dir' => rtrim((string)($loaded['keys_dir'] ?? '/var/www/specterbotapp/keys'), '/'),
        'allow_legacy_mysql' => !empty($loaded['allow_legacy_mysql']),
    ];
    return $cfg;
}

/**
 * Resolve tenant username from HTTP Host (subdomain of specterbot.app).
 */
function sql_api_username_from_host(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $parts = explode('.', $host);
    if (count($parts) >= 3 && $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1] === 'specterbot.app') {
        $user = preg_replace('/[^a-z0-9_]/', '', $parts[0]) ?? '';
        return $user;
    }
    return '';
}

/**
 * Load user API key for a tenant from keys_dir/{username}.key
 */
function sql_api_load_user_key(string $username): string
{
    $username = strtolower(trim($username));
    if ($username === '' || !preg_match('/^[a-z0-9_]+$/', $username)) {
        return '';
    }
    $cfg = sql_api_config();
    $path = $cfg['keys_dir'] . '/' . $username . '.key';
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $key = trim((string)file_get_contents($path));
    // Refuse if file looks multi-line garbage
    if ($key === '' || str_contains($key, "\n") || str_contains($key, "\r")) {
        $key = trim(strtok($key, "\r\n") ?: '');
    }
    return $key;
}

/**
 * Low-level request.
 *
 * @param array<string, mixed>|null $jsonBody
 * @param array<string, scalar|null>|null $query
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_request(
    string $method,
    string $path,
    string $apiKey,
    ?array $jsonBody = null,
    ?array $query = null
): array {
    $cfg = sql_api_config();
    if ($apiKey === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'Missing user API key — place it in keys_dir/{username}.key',
        ];
    }
    $url = $cfg['base_url'] . $path;
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'X-API-KEY: ' . $apiKey,
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => min(5, $cfg['timeout']),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
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
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'curl error: ' . $err,
        ];
    }
    $data = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
    }
    $ok = $status >= 200 && $status < 300;
    $out = [
        'ok' => $ok,
        'status' => $status,
        'data' => $data,
    ];
    if (!$ok) {
        $detail = is_array($data) ? ($data['detail'] ?? $data['error'] ?? null) : null;
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }
        $out['error'] = is_string($detail) ? $detail : ('HTTP ' . $status);
    }
    return $out;
}

/**
 * Bootstrap globals for a SpecterBotApp page: $sql_api_username, $sql_api_key.
 *
 * @return array{ok:bool,username:string,error?:string}
 */
function sql_api_bootstrap(?string $username = null): array
{
    global $sql_api_username, $sql_api_key;

    $user = $username !== null && $username !== ''
        ? strtolower(trim($username))
        : sql_api_username_from_host();

    if ($user === '' || !preg_match('/^[a-z0-9_]+$/', $user)) {
        $sql_api_username = '';
        $sql_api_key = '';
        return ['ok' => false, 'username' => '', 'error' => 'Could not resolve tenant username from host'];
    }

    $key = sql_api_load_user_key($user);
    $sql_api_username = $user;
    $sql_api_key = $key;

    if ($key === '') {
        return [
            'ok' => false,
            'username' => $user,
            'error' => 'No API key file for tenant (keys_dir/' . $user . '.key)',
        ];
    }
    return ['ok' => true, 'username' => $user];
}

/**
 * @param array<string, mixed> $query
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_select(string $scope, string $table, array $query = []): array
{
    global $sql_api_key;
    $query['table'] = $table;
    return sql_api_request('GET', '/api/v1/' . rawurlencode($scope) . '/rows', (string)$sql_api_key, null, $query);
}

/**
 * Multi-filter select via POST /query
 *
 * @param list<array{column:string,op?:string,value?:mixed}>|null $filters
 * @param list<string>|null $columns
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_query(
    string $scope,
    string $table,
    ?array $filters = null,
    ?array $columns = null,
    int $limit = 100,
    int $offset = 0,
    ?string $orderBy = null,
    string $orderDir = 'asc'
): array {
    global $sql_api_key;
    $body = [
        'table' => $table,
        'limit' => $limit,
        'offset' => $offset,
        'order_dir' => $orderDir,
    ];
    if ($filters !== null) {
        $body['filters'] = $filters;
    }
    if ($columns !== null) {
        $body['columns'] = $columns;
    }
    if ($orderBy !== null) {
        $body['order_by'] = $orderBy;
    }
    return sql_api_request('POST', '/api/v1/' . rawurlencode($scope) . '/query', (string)$sql_api_key, $body);
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_insert(string $scope, string $table, array $data): array
{
    global $sql_api_key;
    return sql_api_request('POST', '/api/v1/' . rawurlencode($scope) . '/rows', (string)$sql_api_key, [
        'table' => $table,
        'data' => $data,
    ]);
}

/**
 * @param array<string, mixed> $data
 * @param list<array{column:string,op?:string,value?:mixed}> $filters
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_update(string $scope, string $table, array $data, array $filters): array
{
    global $sql_api_key;
    return sql_api_request('PATCH', '/api/v1/' . rawurlencode($scope) . '/rows', (string)$sql_api_key, [
        'table' => $table,
        'data' => $data,
        'filters' => $filters,
    ]);
}

/**
 * @param list<array{column:string,op?:string,value?:mixed}> $filters
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_delete(string $scope, string $table, array $filters): array
{
    global $sql_api_key;
    return sql_api_request('DELETE', '/api/v1/' . rawurlencode($scope) . '/rows', (string)$sql_api_key, [
        'table' => $table,
        'filters' => $filters,
    ]);
}

/**
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_ensure_modules(): array
{
    global $sql_api_key;
    return sql_api_request('POST', '/api/v1/modules/ensure', (string)$sql_api_key, []);
}

/**
 * @return array{ok:bool,status:int,data:mixed,error?:string}
 */
function sql_api_me(): array
{
    global $sql_api_key;
    return sql_api_request('GET', '/api/v1/me', (string)$sql_api_key);
}
