<?php
/**
 * YouTube Data API v3 helpers for user OAuth and VOD upload jobs.
 * Tokens live in website.youtube_tokens. PHP loads credentials from config/youtube.php.
 */

function youtube_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $youtube_client_id = '';
    $youtube_client_secret = '';
    $youtube_redirect_uri = 'https://dashboard.botofthespecter.com/youtubelink.php';
    $paths = [
        '/var/www/config/youtube.php',
        dirname(__DIR__) . '/../config/youtube.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require $path;
            break;
        }
    }
    $cfg = [
        'client_id' => trim((string) $youtube_client_id),
        'client_secret' => trim((string) $youtube_client_secret),
        'redirect_uri' => trim((string) $youtube_redirect_uri),
    ];
    if ($cfg['redirect_uri'] === '') {
        $cfg['redirect_uri'] = 'https://dashboard.botofthespecter.com/youtubelink.php';
    }
    return $cfg;
}

function youtube_configured(): bool
{
    $cfg = youtube_config();
    return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '';
}

function youtube_admin_testing(): bool
{
    global $is_admin;
    return !empty($is_admin);
}

function youtube_scopes(): array
{
    return [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/youtube.upload',
    ];
}

function youtube_has_upload_scope($scopeString): bool
{
    $scopes = preg_split('/\s+/', trim((string) $scopeString)) ?: [];
    $write = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube',
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/youtubepartner',
    ];
    foreach ($scopes as $scope) {
        if (in_array($scope, $write, true)) {
            return true;
        }
    }
    return false;
}

function youtube_privacy_allowed($value): string
{
    $value = strtolower(trim((string) $value));
    if (in_array($value, ['private', 'unlisted', 'public'], true)) {
        return $value;
    }
    return 'private';
}

function youtube_safe_filename($fileName): bool
{
    if (!is_string($fileName) || $fileName === '') {
        return false;
    }
    if (strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false || strpos($fileName, "\0") !== false) {
        return false;
    }
    if ($fileName === '.' || $fileName === '..') {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1) {
        return false;
    }
    if (basename($fileName) !== $fileName) {
        return false;
    }
    return strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)) === 'mp4';
}

function youtube_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['code' => 0, 'headers' => [], 'body' => '', 'json' => [], 'error' => $err];
    }
    $headerBlob = substr($raw, 0, $headerSize);
    $bodyText = substr($raw, $headerSize);
    $parsedHeaders = [];
    foreach (explode("\r\n", $headerBlob) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $parsedHeaders[$name] = trim(substr($line, $pos + 1));
    }
    $json = json_decode($bodyText, true);
    return [
        'code' => $code,
        'headers' => $parsedHeaders,
        'body' => $bodyText,
        'json' => is_array($json) ? $json : [],
        'error' => $err,
    ];
}

function youtube_auth_url(string $state): string
{
    $cfg = youtube_config();
    $params = [
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', youtube_scopes()),
        'access_type' => 'offline',
        'prompt' => 'consent select_account',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function youtube_exchange_code(string $code): array
{
    $cfg = youtube_config();
    $resp = youtube_http(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query([
            'code' => $code,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code',
        ])
    );
    return $resp;
}

function youtube_refresh_access(string $refreshToken): array
{
    $cfg = youtube_config();
    return youtube_http(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ])
    );
}

function youtube_revoke(string $token): void
{
    $token = trim($token);
    if ($token === '') {
        return;
    }
    youtube_http(
        'POST',
        'https://oauth2.googleapis.com/revoke',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['token' => $token]),
        15
    );
}

function youtube_google_reason(array $json): string
{
    $errors = $json['error']['errors'] ?? [];
    if (is_array($errors) && isset($errors[0]['reason'])) {
        return (string) $errors[0]['reason'];
    }
    if (isset($json['error']['status'])) {
        return (string) $json['error']['status'];
    }
    if (isset($json['error']) && is_string($json['error'])) {
        return (string) $json['error'];
    }
    return '';
}

function youtube_channels_mine(string $accessToken): array
{
    return youtube_http(
        'GET',
        'https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails,statistics,brandingSettings&mine=true',
        [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]
    );
}

function youtube_tables_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $result = $conn->query("SHOW TABLES LIKE 'youtube_tokens'");
    $ready = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $ready;
}

function youtube_token_row(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT id, user_id, channel_id, channel_title, channel_custom_url, channel_thumbnail,
                access_token, refresh_token, token_expiry, granted_scopes, can_upload,
                auto_upload, privacy_status, needs_reauth
         FROM youtube_tokens WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function youtube_is_linked(mysqli $conn, int $userId): bool
{
    $row = youtube_token_row($conn, $userId);
    if (!$row) {
        return false;
    }
    $refresh = trim((string) ($row['refresh_token'] ?? ''));
    return $refresh !== '' && (int) ($row['needs_reauth'] ?? 0) === 0;
}

function youtube_save_link(mysqli $conn, int $userId, array $tokens, array $channel): bool
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return false;
    }
    $access = (string) ($tokens['access_token'] ?? '');
    $refresh = (string) ($tokens['refresh_token'] ?? '');
    if ($access === '' || $refresh === '') {
        return false;
    }
    $scopes = (string) ($tokens['scope'] ?? '');
    $canUpload = youtube_has_upload_scope($scopes) ? 1 : 0;
    $expiresIn = (int) ($tokens['expires_in'] ?? 3600);
    if ($expiresIn < 60) {
        $expiresIn = 3600;
    }
    $expiry = gmdate('Y-m-d H:i:s', time() + $expiresIn);
    $channelId = (string) ($channel['id'] ?? '');
    $snippet = is_array($channel['snippet'] ?? null) ? $channel['snippet'] : [];
    $title = (string) ($snippet['title'] ?? '');
    $customUrl = (string) ($snippet['customUrl'] ?? '');
    $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
    $thumb = '';
    foreach (['medium', 'default', 'high'] as $size) {
        if (!empty($thumbs[$size]['url'])) {
            $thumb = (string) $thumbs[$size]['url'];
            break;
        }
    }
    $existing = youtube_token_row($conn, $userId);
    if ($existing) {
        $stmt = $conn->prepare(
            'UPDATE youtube_tokens
             SET channel_id = ?, channel_title = ?, channel_custom_url = ?, channel_thumbnail = ?,
                 access_token = ?, refresh_token = ?, token_expiry = ?, granted_scopes = ?,
                 can_upload = ?, needs_reauth = 0
             WHERE user_id = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'ssssssssii',
            $channelId,
            $title,
            $customUrl,
            $thumb,
            $access,
            $refresh,
            $expiry,
            $scopes,
            $canUpload,
            $userId
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    $stmt = $conn->prepare(
        'INSERT INTO youtube_tokens
            (user_id, channel_id, channel_title, channel_custom_url, channel_thumbnail,
             access_token, refresh_token, token_expiry, granted_scopes, can_upload, needs_reauth)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'issssssssi',
        $userId,
        $channelId,
        $title,
        $customUrl,
        $thumb,
        $access,
        $refresh,
        $expiry,
        $scopes,
        $canUpload
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function youtube_persist_refreshed_access(mysqli $conn, int $userId, string $accessToken, ?string $refreshToken, int $expiresIn, ?string $scopes = null): bool
{
    if ($userId <= 0 || $accessToken === '' || !youtube_tables_ready($conn)) {
        return false;
    }
    if ($expiresIn < 60) {
        $expiresIn = 3600;
    }
    $expiry = gmdate('Y-m-d H:i:s', time() + $expiresIn);
    if ($refreshToken) {
        if ($scopes !== null) {
            $canUpload = youtube_has_upload_scope($scopes) ? 1 : 0;
            $stmt = $conn->prepare(
                'UPDATE youtube_tokens
                 SET access_token = ?, refresh_token = ?, token_expiry = ?, granted_scopes = ?, can_upload = ?, needs_reauth = 0
                 WHERE user_id = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssssii', $accessToken, $refreshToken, $expiry, $scopes, $canUpload, $userId);
        } else {
            $stmt = $conn->prepare(
                'UPDATE youtube_tokens SET access_token = ?, refresh_token = ?, token_expiry = ?, needs_reauth = 0 WHERE user_id = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssi', $accessToken, $refreshToken, $expiry, $userId);
        }
    } else {
        $stmt = $conn->prepare(
            'UPDATE youtube_tokens SET access_token = ?, token_expiry = ?, needs_reauth = 0 WHERE user_id = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssi', $accessToken, $expiry, $userId);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function youtube_mark_reauth(mysqli $conn, int $userId): void
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE youtube_tokens SET access_token = '', refresh_token = '', needs_reauth = 1 WHERE user_id = ?"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function youtube_update_settings(mysqli $conn, int $userId, int $autoUpload, string $privacy): bool
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return false;
    }
    $autoUpload = $autoUpload ? 1 : 0;
    $privacy = youtube_privacy_allowed($privacy);
    $stmt = $conn->prepare(
        'UPDATE youtube_tokens SET auto_upload = ?, privacy_status = ? WHERE user_id = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isi', $autoUpload, $privacy, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function youtube_delete_link(mysqli $conn, int $userId): bool
{
    $row = youtube_token_row($conn, $userId);
    if ($row) {
        $revoke = trim((string) ($row['refresh_token'] ?? ''));
        if ($revoke === '') {
            $revoke = trim((string) ($row['access_token'] ?? ''));
        }
        if ($revoke !== '') {
            youtube_revoke($revoke);
        }
    }
    if (!youtube_tables_ready($conn)) {
        return false;
    }
    $stmt = $conn->prepare('DELETE FROM youtube_tokens WHERE user_id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function youtube_ensure_fresh_access(mysqli $conn, array $row): ?array
{
    $userId = (int) ($row['user_id'] ?? 0);
    $access = trim((string) ($row['access_token'] ?? ''));
    $refresh = trim((string) ($row['refresh_token'] ?? ''));
    if ($userId <= 0 || $refresh === '') {
        return null;
    }
    $expiry = strtotime((string) ($row['token_expiry'] ?? '')) ?: 0;
    $needsRefresh = $access === '' || $expiry === 0 || $expiry <= (time() + 120);
    if (!$needsRefresh) {
        return $row;
    }
    $resp = youtube_refresh_access($refresh);
    $json = $resp['json'];
    if ($resp['code'] !== 200 || empty($json['access_token'])) {
        if (($json['error'] ?? '') === 'invalid_grant') {
            youtube_mark_reauth($conn, $userId);
        }
        return null;
    }
    $newAccess = (string) $json['access_token'];
    $newRefresh = !empty($json['refresh_token']) ? (string) $json['refresh_token'] : $refresh;
    $expiresIn = (int) ($json['expires_in'] ?? 3600);
    $scopes = isset($json['scope']) ? (string) $json['scope'] : null;
    youtube_persist_refreshed_access($conn, $userId, $newAccess, $newRefresh, $expiresIn, $scopes);
    $row['access_token'] = $newAccess;
    $row['refresh_token'] = $newRefresh;
    $row['needs_reauth'] = 0;
    if ($scopes !== null) {
        $row['granted_scopes'] = $scopes;
        $row['can_upload'] = youtube_has_upload_scope($scopes) ? 1 : 0;
    }
    return $row;
}

function youtube_uploads_for_user(mysqli $conn, int $userId, int $limit = 25): array
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT id, filename, title, privacy_status, youtube_video_id, status, error_message,
                   created_at, updated_at, source, twitch_video_id
            FROM youtube_vod_uploads WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $stmt = $conn->prepare(
            'SELECT id, filename, title, privacy_status, youtube_video_id, status, error_message,
                    created_at, updated_at
             FROM youtube_vod_uploads WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
        );
    }
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function youtube_upload_map(mysqli $conn, int $userId): array
{
    $map = [];
    foreach (youtube_uploads_for_user($conn, $userId, 100) as $row) {
        $name = (string) ($row['filename'] ?? '');
        if ($name !== '' && !isset($map[$name])) {
            $map[$name] = $row;
        }
    }
    return $map;
}

function youtube_enqueue_vod(mysqli $conn, int $userId, string $filename, ?string $title = null, ?string $privacy = null): array
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    if (!youtube_safe_filename($filename)) {
        return ['ok' => false, 'error' => 'bad_file'];
    }
    $row = youtube_token_row($conn, $userId);
    if (!$row || (int) ($row['needs_reauth'] ?? 0) === 1 || trim((string) ($row['refresh_token'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'not_linked'];
    }
    if ((int) ($row['can_upload'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'no_upload_scope'];
    }
    $privacy = youtube_privacy_allowed($privacy !== null ? $privacy : ($row['privacy_status'] ?? 'private'));
    if ($title === null || trim($title) === '') {
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['_', '-'], ' ', (string) $title);
        $title = trim(preg_replace('/\s+/', ' ', $title));
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 100) : substr($title, 0, 100);
    $check = $conn->prepare(
        "SELECT id, status, youtube_video_id FROM youtube_vod_uploads
         WHERE user_id = ? AND filename = ? AND status IN ('queued','pulling','uploading','done')
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
                return [
                    'ok' => true,
                    'already' => true,
                    'status' => 'done',
                    'youtube_video_id' => $existing['youtube_video_id'] ?? '',
                ];
            }
            return ['ok' => true, 'already' => true, 'status' => $status];
        }
    }
    $failed = $conn->prepare(
        "SELECT id FROM youtube_vod_uploads WHERE user_id = ? AND filename = ? AND status = 'failed' ORDER BY id DESC LIMIT 1"
    );
    if ($failed) {
        $failed->bind_param('is', $userId, $filename);
        $failed->execute();
        $failedRow = $failed->get_result()->fetch_assoc();
        $failed->close();
        if ($failedRow) {
            $upd = $conn->prepare(
                "UPDATE youtube_vod_uploads
                 SET status = 'queued', error_message = NULL, title = ?, privacy_status = ?, youtube_video_id = NULL
                 WHERE id = ?"
            );
            if (!$upd) {
                return ['ok' => false, 'error' => 'db'];
            }
            $id = (int) $failedRow['id'];
            $upd->bind_param('ssi', $title, $privacy, $id);
            $ok = $upd->execute();
            $upd->close();
            return $ok ? ['ok' => true, 'status' => 'queued'] : ['ok' => false, 'error' => 'db'];
        }
    }
    $ins = $conn->prepare(
        'INSERT INTO youtube_vod_uploads (user_id, filename, title, privacy_status, status, source)
         VALUES (?, ?, ?, ?, \'queued\', \'stream\')'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'db'];
    }
    $ins->bind_param('isss', $userId, $filename, $title, $privacy);
    $ok = $ins->execute();
    $ins->close();
    return $ok ? ['ok' => true, 'status' => 'queued'] : ['ok' => false, 'error' => 'db'];
}

function youtube_twitch_video_id_ok($videoId): bool
{
    return is_string($videoId) && preg_match('/^[0-9]{1,20}$/', $videoId) === 1;
}

function youtube_twitch_filename(string $twitchVideoId): string
{
    return 'twitch-' . $twitchVideoId . '.mp4';
}

function youtube_twitch_job_map(mysqli $conn, int $userId): array
{
    $map = [];
    foreach (youtube_uploads_for_user($conn, $userId, 100) as $row) {
        $tid = trim((string) ($row['twitch_video_id'] ?? ''));
        if ($tid !== '' && !isset($map[$tid])) {
            $map[$tid] = $row;
        }
    }
    return $map;
}

function youtube_enqueue_twitch_vod(mysqli $conn, int $userId, string $twitchVideoId, ?string $title = null, ?string $privacy = null): array
{
    if ($userId <= 0 || !youtube_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    if (!youtube_twitch_video_id_ok($twitchVideoId)) {
        return ['ok' => false, 'error' => 'bad_video'];
    }
    $row = youtube_token_row($conn, $userId);
    if (!$row || (int) ($row['needs_reauth'] ?? 0) === 1 || trim((string) ($row['refresh_token'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'not_linked'];
    }
    if ((int) ($row['can_upload'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'no_upload_scope'];
    }
    $privacy = youtube_privacy_allowed($privacy !== null ? $privacy : ($row['privacy_status'] ?? 'private'));
    $filename = youtube_twitch_filename($twitchVideoId);
    if ($title === null || trim($title) === '') {
        $title = 'Twitch VOD ' . $twitchVideoId;
    }
    $title = function_exists('mb_substr') ? mb_substr(trim($title), 0, 100) : substr(trim($title), 0, 100);
    $check = $conn->prepare(
        "SELECT id, status, youtube_video_id FROM youtube_vod_uploads
         WHERE user_id = ? AND twitch_video_id = ? AND status IN ('queued','pulling','uploading','done')
         ORDER BY id DESC LIMIT 1"
    );
    if ($check) {
        $check->bind_param('is', $userId, $twitchVideoId);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            $status = (string) ($existing['status'] ?? '');
            if ($status === 'done') {
                return [
                    'ok' => true,
                    'already' => true,
                    'status' => 'done',
                    'youtube_video_id' => $existing['youtube_video_id'] ?? '',
                ];
            }
            return ['ok' => true, 'already' => true, 'status' => $status];
        }
    }
    $failed = $conn->prepare(
        "SELECT id FROM youtube_vod_uploads
         WHERE user_id = ? AND twitch_video_id = ? AND status = 'failed'
         ORDER BY id DESC LIMIT 1"
    );
    if ($failed) {
        $failed->bind_param('is', $userId, $twitchVideoId);
        $failed->execute();
        $failedRow = $failed->get_result()->fetch_assoc();
        $failed->close();
        if ($failedRow) {
            $upd = $conn->prepare(
                "UPDATE youtube_vod_uploads
                 SET status = 'queued', error_message = NULL, title = ?, privacy_status = ?,
                     youtube_video_id = NULL, filename = ?, source = 'twitch_vod'
                 WHERE id = ?"
            );
            if (!$upd) {
                return ['ok' => false, 'error' => 'db'];
            }
            $id = (int) $failedRow['id'];
            $upd->bind_param('sssi', $title, $privacy, $filename, $id);
            $ok = $upd->execute();
            $upd->close();
            return $ok ? ['ok' => true, 'status' => 'queued'] : ['ok' => false, 'error' => 'db'];
        }
    }
    $ins = $conn->prepare(
        "INSERT INTO youtube_vod_uploads
            (user_id, filename, title, privacy_status, status, source, twitch_video_id)
         VALUES (?, ?, ?, ?, 'queued', 'twitch_vod', ?)"
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'db'];
    }
    $ins->bind_param('issss', $userId, $filename, $title, $privacy, $twitchVideoId);
    $ok = $ins->execute();
    $ins->close();
    return $ok ? ['ok' => true, 'status' => 'queued'] : ['ok' => false, 'error' => 'db'];
}
