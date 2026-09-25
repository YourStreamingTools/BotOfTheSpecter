<?php
// User-owned S3-compatible VOD archive (any endpoint). Secrets live in website.user_s3_settings.

function user_s3_tables_ready(mysqli $conn): bool
{
    $one = $conn->query("SHOW TABLES LIKE 'user_s3_settings'");
    $two = $conn->query("SHOW TABLES LIKE 'user_s3_uploads'");
    $ok = $one && $one->num_rows > 0 && $two && $two->num_rows > 0;
    if ($one) {
        $one->free();
    }
    if ($two) {
        $two->free();
    }
    return $ok;
}

function user_s3_load_aws(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $paths = [
        '/var/www/vendor/aws-autoloader.php',
        dirname(__DIR__) . '/../vendor/aws-autoloader.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = class_exists('Aws\\S3\\S3Client');
            return $loaded;
        }
    }
    $loaded = false;
    return false;
}

function user_s3_normalise_endpoint(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
        $raw = 'https://' . ltrim($raw, '/');
    }
    return rtrim($raw, '/');
}

function user_s3_ip_public(string $ip): bool
{
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, $flags);
}

function user_s3_host_public(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host !== '' && $host[0] === '[' && substr($host, -1) === ']') {
        $host = substr($host, 1, -1);
    }
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return user_s3_ip_public($host);
    }
    if (!preg_match('/^[a-z0-9._-]+$/i', $host) || strpos($host, '..') !== false) {
        return false;
    }
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $row) {
            if (!empty($row['ipv6'])) {
                $ips[] = (string) $row['ipv6'];
            }
        }
    }
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!user_s3_ip_public((string) $ip)) {
            return false;
        }
    }
    return true;
}

function user_s3_endpoint_ok(string $endpoint): bool
{
    $endpoint = user_s3_normalise_endpoint($endpoint);
    $parts = parse_url($endpoint);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return false;
    }
    $port = (int) ($parts['port'] ?? 0);
    if ($port < 0 || $port > 65535) {
        return false;
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return false;
    }
    return user_s3_host_public($host);
}

function user_s3_prefix_ok(string $prefix): bool
{
    $prefix = trim($prefix, '/');
    if ($prefix === '') {
        return true;
    }
    if (strlen($prefix) > 200 || strpos($prefix, '..') !== false) {
        return false;
    }
    return (bool) preg_match('#^[A-Za-z0-9._/-]+$#', $prefix);
}

function user_s3_bucket_ok(string $bucket): bool
{
    $bucket = trim($bucket);
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{1,61}[A-Za-z0-9]$/', $bucket);
}

function user_s3_object_key(string $prefix, string $username, string $filename): ?string
{
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $username) || !youtube_safe_filename($filename)) {
        return null;
    }
    $prefix = trim($prefix, '/');
    if ($prefix !== '' && !user_s3_prefix_ok($prefix)) {
        return null;
    }
    $base = $username . '/' . $filename;
    return $prefix === '' ? $base : ($prefix . '/' . $base);
}

function user_s3_settings_row(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !user_s3_tables_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT user_id, endpoint, region, bucket, prefix, access_key, secret_key, path_style, auto_copy, last_error, updated_at
         FROM user_s3_settings WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function user_s3_connected(?array $row): bool
{
    if (!$row) {
        return false;
    }
    return trim((string) ($row['endpoint'] ?? '')) !== ''
        && trim((string) ($row['bucket'] ?? '')) !== ''
        && trim((string) ($row['access_key'] ?? '')) !== ''
        && trim((string) ($row['secret_key'] ?? '')) !== '';
}

function user_s3_public_row(?array $row): array
{
    if (!$row) {
        return [
            'connected' => false,
            'endpoint' => '',
            'region' => '',
            'bucket' => '',
            'prefix' => '',
            'access_key' => '',
            'secret_set' => false,
            'path_style' => true,
            'auto_copy' => false,
            'last_error' => '',
        ];
    }
    $secret = (string) ($row['secret_key'] ?? '');
    return [
        'connected' => user_s3_connected($row),
        'endpoint' => (string) ($row['endpoint'] ?? ''),
        'region' => (string) ($row['region'] ?? ''),
        'bucket' => (string) ($row['bucket'] ?? ''),
        'prefix' => (string) ($row['prefix'] ?? ''),
        'access_key' => (string) ($row['access_key'] ?? ''),
        'secret_set' => $secret !== '',
        'secret_last4' => $secret !== '' ? substr($secret, -4) : '',
        'path_style' => (int) ($row['path_style'] ?? 1) === 1,
        'auto_copy' => (int) ($row['auto_copy'] ?? 0) === 1,
        'last_error' => (string) ($row['last_error'] ?? ''),
    ];
}

function user_s3_client(array $row)
{
    if (!user_s3_load_aws()) {
        return null;
    }
    $endpoint = user_s3_normalise_endpoint((string) ($row['endpoint'] ?? ''));
    $region = trim((string) ($row['region'] ?? ''));
    if ($region === '') {
        $region = 'us-east-1';
    }
    return new \Aws\S3\S3Client([
        'version' => 'latest',
        'region' => $region,
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => (int) ($row['path_style'] ?? 1) === 1,
        'credentials' => [
            'key' => (string) ($row['access_key'] ?? ''),
            'secret' => (string) ($row['secret_key'] ?? ''),
        ],
        'suppress_php_deprecation_warning' => true,
    ]);
}

function user_s3_test(array $row): array
{
    $endpoint = user_s3_normalise_endpoint((string) ($row['endpoint'] ?? ''));
    $bucket = trim((string) ($row['bucket'] ?? ''));
    if (!user_s3_endpoint_ok($endpoint)) {
        return ['ok' => false, 'error' => 'bad_endpoint'];
    }
    if (!user_s3_bucket_ok($bucket)) {
        return ['ok' => false, 'error' => 'bad_bucket'];
    }
    if (!user_s3_prefix_ok((string) ($row['prefix'] ?? ''))) {
        return ['ok' => false, 'error' => 'bad_prefix'];
    }
    if (!user_s3_load_aws()) {
        return ['ok' => false, 'error' => 'no_sdk'];
    }
    try {
        $client = user_s3_client($row);
        if (!$client) {
            return ['ok' => false, 'error' => 'no_sdk'];
        }
        $client->listObjectsV2([
            'Bucket' => $bucket,
            'MaxKeys' => 1,
            'Prefix' => trim((string) ($row['prefix'] ?? ''), '/'),
        ]);
        $prefix = trim((string) ($row['prefix'] ?? ''), '/');
        $probeKey = ($prefix === '' ? '' : $prefix . '/') . '.specter-vod-probe';
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $probeKey,
            'Body' => 'specter-vod-probe',
            'ContentType' => 'text/plain',
        ]);
        $client->deleteObject([
            'Bucket' => $bucket,
            'Key' => $probeKey,
        ]);
        return ['ok' => true];
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msg = preg_replace('/AKIA[0-9A-Z]{16}/', '[key]', $msg);
        return ['ok' => false, 'error' => 'test_failed', 'detail' => substr($msg, 0, 240)];
    }
}

function user_s3_save(mysqli $conn, int $userId, array $fields): array
{
    if ($userId <= 0 || !user_s3_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    $endpoint = user_s3_normalise_endpoint((string) ($fields['endpoint'] ?? ''));
    $region = trim((string) ($fields['region'] ?? ''));
    $bucket = trim((string) ($fields['bucket'] ?? ''));
    $prefix = trim((string) ($fields['prefix'] ?? ''), '/');
    $access = trim((string) ($fields['access_key'] ?? ''));
    $secret = (string) ($fields['secret_key'] ?? '');
    $pathStyle = !empty($fields['path_style']) ? 1 : 0;
    $autoCopy = !empty($fields['auto_copy']) ? 1 : 0;
    if (!user_s3_endpoint_ok($endpoint)) {
        return ['ok' => false, 'error' => 'bad_endpoint'];
    }
    if (!user_s3_bucket_ok($bucket)) {
        return ['ok' => false, 'error' => 'bad_bucket'];
    }
    if (!user_s3_prefix_ok($prefix)) {
        return ['ok' => false, 'error' => 'bad_prefix'];
    }
    if ($access === '') {
        return ['ok' => false, 'error' => 'bad_key'];
    }
    $existing = user_s3_settings_row($conn, $userId);
    if (trim($secret) === '') {
        if ($existing && trim((string) ($existing['secret_key'] ?? '')) !== '') {
            $secret = (string) $existing['secret_key'];
        } else {
            return ['ok' => false, 'error' => 'bad_secret'];
        }
    }
    $test = user_s3_test([
        'endpoint' => $endpoint,
        'region' => $region,
        'bucket' => $bucket,
        'prefix' => $prefix,
        'access_key' => $access,
        'secret_key' => $secret,
        'path_style' => $pathStyle,
    ]);
    $errText = !empty($test['ok']) ? null : (string) ($test['detail'] ?? $test['error'] ?? 'test_failed');
    if (empty($test['ok'])) {
        return ['ok' => false, 'error' => (string) ($test['error'] ?? 'test_failed'), 'detail' => $errText];
    }
    if ($existing) {
        $stmt = $conn->prepare(
            'UPDATE user_s3_settings
             SET endpoint = ?, region = ?, bucket = ?, prefix = ?, access_key = ?, secret_key = ?,
                 path_style = ?, auto_copy = ?, last_error = NULL
             WHERE user_id = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'db'];
        }
        $stmt->bind_param('ssssssiii', $endpoint, $region, $bucket, $prefix, $access, $secret, $pathStyle, $autoCopy, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'db'];
    }
    $stmt = $conn->prepare(
        'INSERT INTO user_s3_settings
            (user_id, endpoint, region, bucket, prefix, access_key, secret_key, path_style, auto_copy)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db'];
    }
    $stmt->bind_param('issssssii', $userId, $endpoint, $region, $bucket, $prefix, $access, $secret, $pathStyle, $autoCopy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'db'];
}

function user_s3_delete(mysqli $conn, int $userId): bool
{
    if ($userId <= 0 || !user_s3_tables_ready($conn)) {
        return false;
    }
    $stmt = $conn->prepare('DELETE FROM user_s3_settings WHERE user_id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function user_s3_uploads_for_user(mysqli $conn, int $userId, int $limit = 100): array
{
    if ($userId <= 0 || !user_s3_tables_ready($conn)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        'SELECT id, filename, title, object_key, status, error_message, bytes_sent, bytes_total, progress_percent,
                created_at, updated_at, UNIX_TIMESTAMP(updated_at) AS updated_unix
         FROM user_s3_uploads WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function user_s3_upload_map(mysqli $conn, int $userId): array
{
    $map = [];
    foreach (user_s3_uploads_for_user($conn, $userId, 100) as $row) {
        $name = (string) ($row['filename'] ?? '');
        if ($name !== '' && !isset($map[$name])) {
            $map[$name] = $row;
        }
    }
    return $map;
}

function user_s3_job_is_live(array $row): bool
{
    $status = (string) ($row['status'] ?? '');
    if ($status !== 'uploading') {
        return false;
    }
    $unix = (int) ($row['updated_unix'] ?? 0);
    if ($unix <= 0) {
        return true;
    }
    return (time() - $unix) < 30 * 60;
}

function user_s3_fail_stale_jobs(mysqli $conn, int $userId): int
{
    if ($userId <= 0 || !user_s3_tables_ready($conn)) {
        return 0;
    }
    $stmt = $conn->prepare(
        "UPDATE user_s3_uploads
         SET status = 'failed', error_message = 'stale_progress'
         WHERE user_id = ? AND status = 'uploading'
           AND updated_at < (NOW() - INTERVAL 1800 SECOND)"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $n = $ok ? (int) $stmt->affected_rows : 0;
    $stmt->close();
    return $n;
}

function user_s3_job_client_row(array $row): array
{
    $sent = (int) ($row['bytes_sent'] ?? 0);
    $total = (int) ($row['bytes_total'] ?? 0);
    $pct = $row['progress_percent'] ?? null;
    if ($pct === null || $pct === '') {
        $pct = $total > 0 ? round(100.0 * $sent / $total, 1) : 0.0;
    } else {
        $pct = round((float) $pct, 1);
    }
    return [
        'status' => (string) ($row['status'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'filename' => (string) ($row['filename'] ?? ''),
        'object_key' => (string) ($row['object_key'] ?? ''),
        'percent' => $pct,
        'bytes_sent' => $sent,
        'bytes_total' => $total,
        'updated_unix' => (int) ($row['updated_unix'] ?? 0),
        'live' => user_s3_job_is_live($row),
    ];
}

function user_s3_enqueue(mysqli $conn, int $userId, string $filename, ?string $title = null): array
{
    if ($userId <= 0 || !user_s3_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    if (!youtube_safe_filename($filename)) {
        return ['ok' => false, 'error' => 'bad_file'];
    }
    $settings = user_s3_settings_row($conn, $userId);
    if (!user_s3_connected($settings)) {
        return ['ok' => false, 'error' => 'not_linked'];
    }
    if ($title === null || trim($title) === '') {
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $title = trim(preg_replace('/\\s+/', ' ', str_replace(['_', '-'], ' ', (string) $title)));
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 180) : substr($title, 0, 180);
    $check = $conn->prepare(
        "SELECT id, status FROM user_s3_uploads
         WHERE user_id = ? AND filename = ? AND status IN ('queued','uploading','done')
         ORDER BY id DESC LIMIT 1"
    );
    if ($check) {
        $check->bind_param('is', $userId, $filename);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            $status = (string) ($existing['status'] ?? '');
            if ($status === 'done') {
                return ['ok' => true, 'already' => true, 'status' => 'done'];
            }
            return ['ok' => true, 'already' => true, 'status' => $status];
        }
    }
    $failed = $conn->prepare(
        "SELECT id FROM user_s3_uploads WHERE user_id = ? AND filename = ? AND status = 'failed' ORDER BY id DESC LIMIT 1"
    );
    if ($failed) {
        $failed->bind_param('is', $userId, $filename);
        $failed->execute();
        $failedRow = $failed->get_result()->fetch_assoc();
        $failed->close();
        if ($failedRow) {
            $id = (int) $failedRow['id'];
            $upd = $conn->prepare(
                "UPDATE user_s3_uploads SET status = 'queued', error_message = NULL, title = ?, object_key = NULL,
                        bytes_sent = 0, bytes_total = 0, progress_percent = 0
                 WHERE id = ?"
            );
            if (!$upd) {
                return ['ok' => false, 'error' => 'db'];
            }
            $upd->bind_param('si', $title, $id);
            $ok = $upd->execute();
            $upd->close();
            return $ok ? ['ok' => true, 'status' => 'queued'] : ['ok' => false, 'error' => 'db'];
        }
    }
    $ins = $conn->prepare(
        "INSERT INTO user_s3_uploads (user_id, filename, title, status) VALUES (?, ?, ?, 'queued')"
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'db'];
    }
    $ins->bind_param('iss', $userId, $filename, $title);
    $ok = $ins->execute();
    $ins->close();
    return $ok ? ['ok' => true, 'status' => 'queued'] : ['ok' => false, 'error' => 'db'];
}

function user_s3_error_message(string $err, string $detail = ''): string
{
    $map = [
        'bad_endpoint' => 's3_vod_bad_endpoint',
        'bad_bucket' => 's3_vod_bad_bucket',
        'bad_prefix' => 's3_vod_bad_prefix',
        'bad_key' => 's3_vod_bad_key',
        'bad_secret' => 's3_vod_bad_secret',
        'not_linked' => 's3_vod_not_linked',
        'not_ready' => 's3_vod_not_ready',
        'no_sdk' => 's3_vod_no_sdk',
        'test_failed' => 's3_vod_test_failed',
        'db' => 's3_vod_save_failed',
        'bad_file' => 's3_vod_send_failed',
    ];
    $key = $map[$err] ?? 's3_vod_save_failed';
    $msg = function_exists('t') ? t($key) : $key;
    if ($err === 'test_failed' && $detail !== '') {
        $msg .= ' ' . $detail;
    }
    return $msg;
}
