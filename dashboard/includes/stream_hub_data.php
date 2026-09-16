<?php
/**
 * Shared Stream hub data, POST handlers, and AJAX for streaming.php.
 * Expects dashboard bootstrap: $db, $conn, userdata, stream.php, twitch.php, youtube.php, stream_api_client.php.
 */

if (!function_exists('isSafeRecorderFileName')) {
    function isSafeRecorderFileName($fileName): bool
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
        return basename($fileName) === $fileName;
    }
}

if (!function_exists('recordingDisplayName')) {
    function recordingDisplayName($name, $title = ''): string
    {
        $title = trim((string) $title);
        if ($title !== '') {
            if (str_ends_with(strtolower($title), '.mp4')) {
                $title = substr($title, 0, -4);
            }
            return $title;
        }
        $base = (string) $name;
        $lower = strtolower($base);
        if (str_ends_with($lower, '.part')) {
            $base = substr($base, 0, -5);
            $lower = strtolower($base);
        }
        if (str_ends_with($lower, '.mp4')) {
            $base = substr($base, 0, -4);
        }
        return $base;
    }
}

if (!function_exists('recordingTwitchId')) {
    function recordingTwitchId(array $file): string
    {
        $tid = trim((string) ($file['twitch_video_id'] ?? ''));
        if ($tid !== '') {
            return $tid;
        }
        if (preg_match('/^twitch-([0-9]{1,20})\.mp4(?:\.part)?$/i', (string) ($file['name'] ?? ''), $m)) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('recordingFileKind')) {
    function recordingFileKind(array $file): string
    {
        $isTwitch = recordingTwitchId($file) !== '';
        if (!empty($file['is_partial'])) {
            return $isTwitch ? 'storing' : 'recording';
        }
        return $isTwitch ? 'stored' : 'recorded';
    }
}

if (!function_exists('formatBytes')) {
    function formatBytes($bytes): string
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}

function stream_hub_wants_json(): bool
{
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $xhr || str_contains($accept, 'application/json');
}

function stream_hub_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function stream_hub_redirect(string $hash = '', string $message = '', string $alertClass = ''): void
{
    if ($message !== '') {
        $_SESSION['stream_hub_message'] = $message;
        $_SESSION['stream_hub_alert'] = $alertClass !== '' ? $alertClass : 'is-info';
    }
    $target = 'streaming.php';
    if ($hash !== '') {
        $target .= '#' . ltrim($hash, '#');
    }
    header('Location: ' . $target);
    exit();
}

$isActAsUser = isset($isActAs) && $isActAs === true;
$userId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));
$recorderUsername = isset($username) && $username !== '' ? (string) $username : (string) ($_SESSION['username'] ?? 'unknown');
$streamApiBase = rtrim((string) ($stream_api_base ?? ''), '/');
$streamApiTimeout = (int) ($stream_api_timeout ?? 30);
$streamUserApiKey = (string) ($api_key ?? ($_SESSION['api_key'] ?? ''));
$vodsCdnBase = rtrim((string) ($vods_cdn_base ?? 'https://vods.botofthespecter.com'), '/');
$specterKey = $streamUserApiKey;
$rtmpsUrl = rtrim((string) ($stream_rtmps_url ?? 'rtmps://syd1.stream.botofthespecter.com:1935/app'), '/');
$canIngest = !empty($is_admin) || !empty($betaAccess) || (isset($betaPrograms) && is_array($betaPrograms) && in_array('streaming', $betaPrograms, true));
$canYoutube = function_exists('youtube_admin_testing') && youtube_admin_testing();
$unlimitedStorage = in_array(strtolower($recorderUsername), ['botofthespecter', 'gfaundead'], true);

$saveStatus = null;
$autoRecordEnabled = 0;
$twitchKey = '';
$forwardToTwitch = 0;
$settingsId = null;
$hasSlot = false;
$quotaBytes = 0;
$allowedForwardServices = ['youtube', 'kick', 'trovo'];
$forwardServiceLabels = [
    'youtube' => 'YouTube',
    'kick' => 'Kick.com',
    'trovo' => 'Trovo.live',
];
$forwardServiceIcons = [
    'youtube' => ['type' => 'img', 'value' => 'https://cdn.brandfetch.io/idVfYwcuQz/theme/dark/symbol.svg?c=1bxid64Mup7aczewSAYMX&t=1728452988041'],
    'kick' => ['type' => 'img', 'value' => 'https://cdn.brandfetch.io/id3gkQXO6j/w/400/h/400/theme/dark/icon.jpeg?c=1bxid64Mup7aczewSAYMX&t=1752548681236'],
    'trovo' => ['type' => 'img', 'value' => 'https://cdn.brandfetch.io/idiHGB0VOK/theme/dark/logo.svg?c=1bxid64Mup7aczewSAYMX&t=1772202851934'],
];
$forwardSettings = [];

$loadStmt = $db->prepare("SELECT enabled FROM auto_record_settings WHERE id = 1 LIMIT 1");
if ($loadStmt) {
    $loadStmt->execute();
    $loadResult = $loadStmt->get_result();
    if ($loadResult && $row = $loadResult->fetch_assoc()) {
        $autoRecordEnabled = (int) ($row['enabled'] ?? 0);
    }
    $loadStmt->close();
}

$loadFwdStmt = $db->prepare("SELECT service, stream_key, enabled FROM stream_forward_settings WHERE service IN ('youtube', 'kick', 'trovo')");
if ($loadFwdStmt) {
    $loadFwdStmt->execute();
    $loadFwdResult = $loadFwdStmt->get_result();
    while ($fwdRow = $loadFwdResult->fetch_assoc()) {
        $forwardSettings[$fwdRow['service']] = [
            'stream_key' => $fwdRow['stream_key'] ?? '',
            'enabled' => (int) ($fwdRow['enabled'] ?? 0),
        ];
    }
    $loadFwdStmt->close();
}
foreach ($allowedForwardServices as $svc) {
    if (!isset($forwardSettings[$svc])) {
        $forwardSettings[$svc] = ['stream_key' => '', 'enabled' => 0];
    }
}

$loadStreamStmt = $db->prepare("SELECT id, twitch_key, forward_to_twitch FROM streaming_settings ORDER BY id ASC LIMIT 1");
if ($loadStreamStmt) {
    $loadStreamStmt->execute();
    $loadStreamResult = $loadStreamStmt->get_result();
    if ($loadStreamResult && $row = $loadStreamResult->fetch_assoc()) {
        $settingsId = (int) ($row['id'] ?? 0);
        $twitchKey = (string) ($row['twitch_key'] ?? '');
        $forwardToTwitch = (int) ($row['forward_to_twitch'] ?? 0);
    }
    $loadStreamStmt->close();
}

if ($userId > 0 && isset($conn) && $conn instanceof mysqli) {
    $slotStmt = $conn->prepare("SELECT quota_bytes, bonus_bytes FROM stream_storage_slots WHERE user_id = ? LIMIT 1");
    if ($slotStmt) {
        $slotStmt->bind_param("i", $userId);
        $slotStmt->execute();
        $slotResult = $slotStmt->get_result();
        if ($slotResult && $slotRow = $slotResult->fetch_assoc()) {
            $hasSlot = true;
            $rawQuota = $slotRow['quota_bytes'];
            $quotaBytes = ($rawQuota === null) ? (100 * 1024 * 1024 * 1024) : (int) $rawQuota;
            $quotaBytes = $quotaBytes === 0 ? 0 : $quotaBytes + (int) ($slotRow['bonus_bytes'] ?? 0);
        }
        $slotStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recording_settings'])) {
    $autoRecordEnabled = isset($_POST['auto_record']) ? 1 : 0;
    $saveStmt = $db->prepare("REPLACE INTO auto_record_settings (id, enabled) VALUES (1, ?)");
    if ($saveStmt) {
        $saveStmt->bind_param("i", $autoRecordEnabled);
        $ok = $saveStmt->execute();
        $saveStmt->close();
        stream_hub_redirect('record', $ok ? t('recording_status_setting_updated') : t('recording_status_setting_save_failed'), $ok ? 'is-success' : 'is-danger');
    }
    stream_hub_redirect('record', t('recording_status_setting_prepare_failed'), 'is-danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_forward_settings'])) {
    $fwdSaveOk = true;
    foreach ($allowedForwardServices as $svc) {
        $streamKey = trim(strip_tags((string) ($_POST["forward_{$svc}_key"] ?? '')));
        $fwdEnabled = isset($_POST["forward_{$svc}_enabled"]) ? 1 : 0;
        $fwdStmt = $db->prepare(
            "INSERT INTO stream_forward_settings (service, stream_key, enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE stream_key = VALUES(stream_key), enabled = VALUES(enabled)"
        );
        if ($fwdStmt) {
            $fwdStmt->bind_param("ssi", $svc, $streamKey, $fwdEnabled);
            if (!$fwdStmt->execute()) {
                $fwdSaveOk = false;
            }
            $fwdStmt->close();
        } else {
            $fwdSaveOk = false;
        }
        $forwardSettings[$svc] = ['stream_key' => $streamKey, 'enabled' => $fwdEnabled];
    }
    stream_hub_redirect(
        'forward',
        $fwdSaveOk ? t('recording_status_forward_saved') : t('recording_status_forward_save_failed'),
        $fwdSaveOk ? 'is-success' : 'is-danger'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_streaming_settings'])) {
    $twitchKey = trim((string) ($_POST['twitch_key'] ?? ''));
    $forwardToTwitch = isset($_POST['forward_to_twitch']) ? 1 : 0;
    $ok = false;
    if ($settingsId) {
        $saveStmt = $db->prepare("UPDATE streaming_settings SET twitch_key = ?, forward_to_twitch = ? WHERE id = ?");
        if ($saveStmt) {
            $saveStmt->bind_param("sii", $twitchKey, $forwardToTwitch, $settingsId);
            $ok = $saveStmt->execute();
            $saveStmt->close();
        }
    } else {
        $saveStmt = $db->prepare("INSERT INTO streaming_settings (twitch_key, forward_to_twitch) VALUES (?, ?)");
        if ($saveStmt) {
            $saveStmt->bind_param("si", $twitchKey, $forwardToTwitch);
            $ok = $saveStmt->execute();
            if ($ok) {
                $settingsId = (int) $db->insert_id;
            }
            $saveStmt->close();
        }
    }
    stream_hub_redirect(
        'ingest',
        $ok ? t('streaming_settings_saved_success') : t('streaming_settings_save_failed'),
        $ok ? 'is-success' : 'is-danger'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'disconnect' && $canYoutube) {
        if ($isActAsUser) {
            if (stream_hub_wants_json()) {
                stream_hub_json(['ok' => false, 'message' => t('youtube_link_actas_disabled')], 403);
            }
            stream_hub_redirect('youtube', t('youtube_link_actas_disabled'), 'is-warning');
        }
        youtube_delete_link($conn, $userId);
        stream_hub_redirect('youtube', t('youtube_disconnected_success'), 'is-success');
    }
    if ($action === 'store_twitch_vod' && $canYoutube) {
        $wantsJson = stream_hub_wants_json();
        if ($isActAsUser) {
            if ($wantsJson) {
                stream_hub_json(['ok' => false, 'status' => 'actas', 'message' => t('youtube_link_actas_disabled')], 403);
            }
            stream_hub_redirect('library', t('youtube_link_actas_disabled'), 'is-warning');
        }
        $vodId = trim((string) ($_POST['vod_id'] ?? ''));
        $vodTitle = trim((string) ($_POST['vod_title'] ?? ''));
        $pull = streamApiRequest(
            $streamApiBase,
            $streamUserApiKey,
            '/api/me/recordings/pull-twitch',
            30,
            'POST',
            ['vod_id' => $vodId, 'title' => $vodTitle]
        );
        $http = (int) ($pull['http'] ?? 0);
        $pullBody = json_decode((string) ($pull['body'] ?? ''), true);
        $already = is_array($pullBody) && !empty($pullBody['already']);
        if ($pull['ok'] || $http === 202) {
            if ($wantsJson) {
                stream_hub_json([
                    'ok' => true,
                    'status' => $already ? 'stored' : 'pulling',
                    'vod_id' => $vodId,
                    'title' => $vodTitle,
                    'message' => t('youtube_vod_store_started'),
                ]);
            }
            stream_hub_redirect('library', t('youtube_vod_store_started'), 'is-success');
        }
        if ($http === 507) {
            if ($wantsJson) {
                stream_hub_json(['ok' => false, 'status' => 'full', 'vod_id' => $vodId, 'message' => t('youtube_vod_store_full')], 507);
            }
            stream_hub_redirect('library', t('youtube_vod_store_full'), 'is-warning');
        }
        if ($wantsJson) {
            stream_hub_json(['ok' => false, 'status' => 'failed', 'vod_id' => $vodId, 'message' => t('youtube_vod_store_failed')], 502);
        }
        stream_hub_redirect('library', t('youtube_vod_store_failed'), 'is-danger');
    }
    if ($action === 'send_twitch_youtube' && $canYoutube) {
        if ($isActAsUser) {
            stream_hub_redirect('youtube', t('youtube_link_actas_disabled'), 'is-warning');
        }
        $vodId = trim((string) ($_POST['vod_id'] ?? ''));
        $vodTitle = trim((string) ($_POST['vod_title'] ?? ''));
        $durationSeconds = youtube_parse_duration_seconds($_POST['vod_duration'] ?? '');
        $accessToken = (string) ($_SESSION['access_token'] ?? '');
        $helixVideo = youtube_helix_video($accessToken, (string) ($clientID ?? ''), $vodId);
        if (is_array($helixVideo)) {
            $durationSeconds = youtube_parse_duration_seconds($helixVideo['duration'] ?? '') ?? $durationSeconds;
            if ($vodTitle === '' && !empty($helixVideo['title'])) {
                $vodTitle = (string) $helixVideo['title'];
            }
        }
        $limit = youtube_upload_limit_reason($durationSeconds, null);
        if ($limit !== null) {
            stream_hub_redirect('import', t(youtube_upload_limit_lang_key($limit)), 'is-warning');
        }
        $queued = youtube_enqueue_twitch_vod(
            $conn,
            $userId,
            $vodId,
            $vodTitle !== '' ? $vodTitle : null,
            null,
            $durationSeconds,
            null
        );
        $failKey = 'youtube_vod_youtube_failed';
        $err = (string) ($queued['error'] ?? '');
        if ($err === 'too_long' || $err === 'too_large') {
            $failKey = youtube_upload_limit_lang_key($err);
        }
        stream_hub_redirect(
            'import',
            !empty($queued['ok']) ? t('youtube_vod_youtube_queued') : t($failKey),
            !empty($queued['ok']) ? 'is-success' : 'is-warning'
        );
    }
    if ($action === 'send_library_youtube' && $canYoutube) {
        $wantsJson = stream_hub_wants_json();
        if ($isActAsUser) {
            if ($wantsJson) {
                stream_hub_json(['ok' => false, 'message' => t('youtube_link_actas_disabled')], 403);
            }
            stream_hub_redirect('library', t('youtube_link_actas_disabled'), 'is-warning');
        }
        $filename = trim((string) ($_POST['filename'] ?? ''));
        $fileTitle = trim((string) ($_POST['file_title'] ?? ''));
        $twitchId = trim((string) ($_POST['twitch_video_id'] ?? ''));
        $sizeBytes = (int) ($_POST['file_size'] ?? 0);
        $sizeBytes = $sizeBytes > 0 ? $sizeBytes : null;
        $durationSeconds = youtube_parse_duration_seconds($_POST['file_duration'] ?? '');
        if (!youtube_safe_filename($filename)) {
            if ($wantsJson) {
                stream_hub_json(['ok' => false, 'message' => t('youtube_vod_youtube_failed')], 400);
            }
            stream_hub_redirect('library', t('youtube_vod_youtube_failed'), 'is-danger');
        }
        if ($twitchId === '' && preg_match('/^twitch-([0-9]{1,20})\.mp4$/i', $filename, $m)) {
            $twitchId = $m[1];
        }
        if ($twitchId !== '' && youtube_twitch_video_id_ok($twitchId)) {
            $helixVideo = youtube_helix_video((string) ($_SESSION['access_token'] ?? ''), (string) ($clientID ?? ''), $twitchId);
            if (is_array($helixVideo)) {
                $durationSeconds = youtube_parse_duration_seconds($helixVideo['duration'] ?? '') ?? $durationSeconds;
                if ($fileTitle === '' && !empty($helixVideo['title'])) {
                    $fileTitle = (string) $helixVideo['title'];
                }
            }
            $limit = youtube_upload_limit_reason($durationSeconds, $sizeBytes);
            if ($limit !== null) {
                $msg = t(youtube_upload_limit_lang_key($limit));
                if ($wantsJson) {
                    stream_hub_json(['ok' => false, 'error' => $limit, 'message' => $msg], 400);
                }
                stream_hub_redirect('library', $msg, 'is-warning');
            }
            $queued = youtube_enqueue_twitch_vod(
                $conn,
                $userId,
                $twitchId,
                $fileTitle !== '' ? $fileTitle : null,
                null,
                $durationSeconds,
                $sizeBytes
            );
        } else {
            $limit = youtube_upload_limit_reason($durationSeconds, $sizeBytes);
            if ($limit !== null) {
                $msg = t(youtube_upload_limit_lang_key($limit));
                if ($wantsJson) {
                    stream_hub_json(['ok' => false, 'error' => $limit, 'message' => $msg], 400);
                }
                stream_hub_redirect('library', $msg, 'is-warning');
            }
            $queued = youtube_enqueue_vod(
                $conn,
                $userId,
                $filename,
                $fileTitle !== '' ? $fileTitle : null,
                null,
                $durationSeconds,
                $sizeBytes
            );
        }
        $ok = !empty($queued['ok']);
        $err = (string) ($queued['error'] ?? '');
        $msg = $ok ? t('youtube_upload_queued') : t('youtube_vod_youtube_failed');
        if ($ok && !empty($queued['already']) && ($queued['status'] ?? '') === 'done') {
            $msg = t('youtube_upload_already_done');
        } elseif ($ok && !empty($queued['already'])) {
            $msg = t('youtube_upload_already_queued');
        } elseif (!$ok && ($err === 'too_long' || $err === 'too_large')) {
            $msg = t(youtube_upload_limit_lang_key($err));
        }
        if ($wantsJson) {
            stream_hub_json([
                'ok' => $ok,
                'status' => (string) ($queued['status'] ?? ''),
                'filename' => $filename,
                'message' => $msg,
            ], $ok ? 200 : 400);
        }
        stream_hub_redirect('library', $msg, $ok ? 'is-success' : 'is-warning');
    }
}

if (isset($_GET['extend'])) {
    header('Content-Type: application/json');
    $requestedFileName = isset($_GET['file']) ? (string) $_GET['file'] : '';
    if (!isSafeRecorderFileName($requestedFileName) || strtolower((string) pathinfo($requestedFileName, PATHINFO_EXTENSION)) !== 'mp4') {
        stream_hub_json(['ok' => false, 'error' => t('recording_http_invalid_file_name')], 400);
    }
    $ext = streamApiRequest(
        $streamApiBase,
        $streamUserApiKey,
        '/api/me/recordings/extend',
        0,
        'POST',
        ['name' => $requestedFileName]
    );
    if (!$ext['ok']) {
        stream_hub_json(['ok' => false, 'error' => t('recording_extend_failed')], $ext['http'] >= 400 ? (int) $ext['http'] : 502);
    }
    echo $ext['body'];
    exit();
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $requestedFileName = isset($_GET['file']) ? (string) $_GET['file'] : '';
    if (!isSafeRecorderFileName($requestedFileName) || strtolower((string) pathinfo($requestedFileName, PATHINFO_EXTENSION)) !== 'mp4') {
        http_response_code(400);
        echo t('recording_http_invalid_file_name');
        exit();
    }
    header('Location: ' . $vodsCdnBase . '/' . rawurlencode($recorderUsername) . '/' . rawurlencode($requestedFileName));
    exit();
}

$storageUsedBytes = 0;
$storageQuotaBytes = 100 * 1024 * 1024 * 1024;
$storageUnlimited = $unlimitedStorage || ($hasSlot && $quotaBytes === 0);
$libraryFiles = [];
$pullJobs = [];
$remoteFileError = null;
$helixTitles = [];
$helixDurations = [];
$twitchVideos = [];
$twitchVideosError = '';
$linkRow = null;
$linked = false;
$needsReauth = false;
$canUpload = false;
$youtubeJobs = [];

$list = streamApiRequest($streamApiBase, $streamUserApiKey, '/api/me/recordings', $streamApiTimeout);
if (!$list['ok']) {
    $remoteFileError = t('recording_error_could_not_connect');
} else {
    $payload = json_decode((string) $list['body'], true);
    if (is_array($payload)) {
        if (array_key_exists('used_bytes', $payload)) {
            $storageUsedBytes = max(0, (int) $payload['used_bytes']);
        }
        if (array_key_exists('quota_bytes', $payload)) {
            $storageQuotaBytes = max(0, (int) $payload['quota_bytes']);
        }
        if (array_key_exists('quota_unlimited', $payload)) {
            $storageUnlimited = !empty($payload['quota_unlimited']) || $storageQuotaBytes === 0 || $storageUnlimited;
        }
        if (!empty($payload['files']) && is_array($payload['files'])) {
            foreach ($payload['files'] as $row) {
                if (!is_array($row) || empty($row['name'])) {
                    continue;
                }
                $name = (string) $row['name'];
                $title = trim((string) ($row['title'] ?? ''));
                $mtime = null;
                if (!empty($row['modified_at'])) {
                    $parsed = strtotime((string) $row['modified_at']);
                    $mtime = $parsed !== false ? $parsed : null;
                }
                $downloadUrl = (string) ($row['download_url'] ?? '');
                if ($downloadUrl !== '') {
                    $downloadUrl = specter_vod_named_url($downloadUrl, $title, $name);
                }
                $size = (int) ($row['size_bytes'] ?? $row['size'] ?? 0);
                $libraryFiles[] = [
                    'name' => $name,
                    'title' => $title,
                    'size' => $size,
                    'size_bytes' => $size,
                    'modified' => $mtime,
                    'is_directory' => false,
                    'is_partial' => !empty($row['is_partial']),
                    'storage' => (string) ($row['storage'] ?? 'local'),
                    'can_extend' => !empty($row['can_extend']),
                    'download_url' => $downloadUrl,
                    'expires_at' => $row['expires_at'] ?? null,
                    'expires_unix' => (int) ($row['expires_at_unix'] ?? 0),
                    'twitch_video_id' => (string) ($row['twitch_video_id'] ?? ''),
                ];
            }
        }
        if (!empty($payload['pulls']) && is_array($payload['pulls'])) {
            $pullJobs = $payload['pulls'];
        }
    }
}

if ($canYoutube && isset($conn) && $conn instanceof mysqli) {
    $linkRow = youtube_token_row($conn, $userId);
    $linked = $linkRow
        && trim((string) ($linkRow['refresh_token'] ?? '')) !== ''
        && (int) ($linkRow['needs_reauth'] ?? 0) === 0;
    $needsReauth = $linkRow && (int) ($linkRow['needs_reauth'] ?? 0) === 1;
    $canUpload = $linked && (int) ($linkRow['can_upload'] ?? 0) === 1;
    $youtubeJobs = youtube_upload_map($conn, $userId);
}

$isAjax = isset($_GET['ajax']);
if (!$isAjax && $canYoutube) {
    $accessToken = (string) ($_SESSION['access_token'] ?? '');
    $channelUserId = trim((string) ($_SESSION['twitchUserId'] ?? ''));
    if ($accessToken !== '' && $channelUserId !== '' && !empty($clientID)) {
        $helixUrl = 'https://api.twitch.tv/helix/videos?' . http_build_query([
            'user_id' => $channelUserId,
            'first' => 20,
        ]);
        $ch = curl_init($helixUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Client-Id: ' . $clientID,
        ]);
        $helixBody = curl_exec($ch);
        $helixCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $helixJson = json_decode((string) $helixBody, true);
        if ($helixCode !== 200) {
            $twitchVideosError = t('youtube_vod_helix_failed');
        } else {
            $twitchVideos = is_array($helixJson['data'] ?? null) ? $helixJson['data'] : [];
        }
    }
    foreach ($twitchVideos as $video) {
        $hid = (string) ($video['id'] ?? '');
        if ($hid !== '') {
            $helixTitles[$hid] = (string) ($video['title'] ?? '');
            $helixDurations[$hid] = (string) ($video['duration'] ?? '');
        }
    }
    $titleBackfill = [];
    foreach ($libraryFiles as $stored) {
        $tid = (string) ($stored['twitch_video_id'] ?? '');
        if ($tid === '' && preg_match('/^twitch-([0-9]{1,20})\.mp4/i', (string) ($stored['name'] ?? ''), $m)) {
            $tid = $m[1];
        }
        $haveTitle = trim((string) ($stored['title'] ?? ''));
        if ($tid !== '' && $haveTitle === '' && !empty($helixTitles[$tid])) {
            $titleBackfill[] = ['vod_id' => $tid, 'title' => $helixTitles[$tid]];
        }
    }
    if ($titleBackfill && $streamApiBase !== '' && $streamUserApiKey !== '') {
        streamApiRequest(
            $streamApiBase,
            $streamUserApiKey,
            '/api/me/recordings/titles',
            15,
            'POST',
            ['items' => $titleBackfill]
        );
    }
}

if ($isAjax) {
    $youtubeJobsPublic = [];
    foreach ($youtubeJobs as $jobName => $jobRow) {
        if (!is_array($jobRow)) {
            continue;
        }
        $youtubeJobsPublic[(string) $jobName] = [
            'status' => (string) ($jobRow['status'] ?? ''),
            'youtube_video_id' => (string) ($jobRow['youtube_video_id'] ?? ''),
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'files' => $libraryFiles,
        'pulls' => $pullJobs,
        'storage' => [
            'used_bytes' => $storageUsedBytes,
            'quota_bytes' => $storageQuotaBytes,
            'unlimited' => $storageUnlimited,
        ],
        'remoteFileError' => $remoteFileError,
        'remoteFileSections' => $libraryFiles ? [['directory' => $recorderUsername, 'files' => $libraryFiles]] : [],
        'can_upload' => $canUpload && !$isActAsUser,
        'youtube_jobs' => $youtubeJobsPublic,
    ]);
    exit();
}

$hubMessage = '';
$hubMessageType = '';
if (isset($_SESSION['stream_hub_message'])) {
    $hubMessage = (string) $_SESSION['stream_hub_message'];
    $hubMessageType = (string) ($_SESSION['stream_hub_alert'] ?? 'is-info');
    unset($_SESSION['stream_hub_message'], $_SESSION['stream_hub_alert']);
}
if (isset($_SESSION['youtube_message'])) {
    $hubMessage = (string) $_SESSION['youtube_message'];
    $hubMessageType = (string) ($_SESSION['youtube_alert_class'] ?? 'is-info');
    unset($_SESSION['youtube_message'], $_SESSION['youtube_alert_class']);
}
if (isset($_SESSION['youtube_vod_message'])) {
    $hubMessage = (string) $_SESSION['youtube_vod_message'];
    $hubMessageType = (string) ($_SESSION['youtube_vod_alert_class'] ?? 'is-info');
    unset($_SESSION['youtube_vod_message'], $_SESSION['youtube_vod_alert_class']);
}
