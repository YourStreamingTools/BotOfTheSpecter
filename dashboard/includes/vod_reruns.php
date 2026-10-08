<?php
// Scheduled VOD reruns (vod_reruns + vod_rerun_items); the stream server's rerun_scheduler.py plays them

const VOD_RERUN_MAX_ITEMS = 20;
const VOD_RERUN_MAX_UPCOMING = 10;
const VOD_RERUN_MAX_DAYS_AHEAD = 30;
const VOD_RERUN_MIN_LEAD_SECONDS = 120;
// Twitch ends any broadcast after 48 hours.
const VOD_RERUN_MAX_TOTAL_SECONDS = 48 * 3600;
const VOD_RERUN_TITLE_MAX = 140;
const VOD_RERUN_PREFIX = 'RERUN - ';

function vod_rerun_tables_ready(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'vod_rerun_items'");
    return $res && $res->num_rows > 0;
}

// Recorder files are named "YYYY-MM-DD HH-MM - {stream title} - {category}".
function vod_rerun_split_recorder_name(string $base): array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}-\d{2} - (.+) - ([^-][^\/]*)$/u', $base, $m)) {
        $title = trim($m[1]);
        $game = trim($m[2]);
        return [
            'title' => strcasecmp($title, 'Untitled') === 0 ? '' : $title,
            'game' => strcasecmp($game, 'Unknown Game') === 0 ? '' : $game,
        ];
    }
    return ['title' => $base, 'game' => ''];
}

function vod_rerun_candidate_token(string $storage, string $name, string $key): string
{
    return substr(hash('sha256', $storage . "\0" . $name . "\0" . $key), 0, 20);
}

// Playable library VODs: local files or the streamer's S3 (S4-only extended VODs are left out)
function vod_rerun_candidates(array $libraryFiles): array
{
    $out = [];
    foreach ($libraryFiles as $file) {
        $name = (string) ($file['name'] ?? '');
        $storage = (string) ($file['storage'] ?? 'local');
        if ($name === '' || !empty($file['is_partial']) || !preg_match('/\.mp4$/i', $name)) {
            continue;
        }
        if (!in_array($storage, ['local', 'user_s3'], true)) {
            continue;
        }
        if ($storage === 'local' && function_exists('recordingFileKind') && recordingFileKind($file) === 'recording') {
            continue;
        }
        $key = $storage === 'user_s3' ? (string) ($file['s3_key'] ?? '') : '';
        if ($storage === 'user_s3' && $key === '') {
            continue;
        }
        $display = function_exists('recordingDisplayName') ? recordingDisplayName($name, $file['title'] ?? '') : $name;
        $title = $display;
        $game = '';
        if (trim((string) ($file['title'] ?? '')) === '') {
            $split = vod_rerun_split_recorder_name($display);
            $title = $split['title'] !== '' ? $split['title'] : $display;
            $game = $split['game'];
        }
        $token = vod_rerun_candidate_token($storage, $name, $key);
        $out[$token] = [
            'token' => $token,
            'name' => $name,
            'storage' => $storage,
            'source_key' => $key,
            'display' => $display,
            'title' => $title,
            'game_name' => $game,
            'game_id' => '',
            'duration_seconds' => isset($file['duration_seconds']) && $file['duration_seconds'] !== null ? (int) $file['duration_seconds'] : null,
        ];
    }
    return $out;
}

function vod_rerun_helix_get(string $url, string $clientID, string $accessToken): ?array
{
    if ($clientID === '' || $accessToken === '') {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $clientID,
        'Authorization: Bearer ' . $accessToken,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

// Pre-fill each VOD's category: look its category name up on Twitch (exact name match).
function vod_rerun_resolve_games(array &$candidates, string $clientID, string $accessToken): void
{
    $names = [];
    foreach ($candidates as $c) {
        if ($c['game_name'] !== '') {
            $names[$c['game_name']] = true;
        }
    }
    if (!$names) {
        return;
    }
    $query = implode('&', array_map(static function ($n) {
        return 'name=' . rawurlencode($n);
    }, array_slice(array_keys($names), 0, 100)));
    $data = vod_rerun_helix_get('https://api.twitch.tv/helix/games?' . $query, $clientID, $accessToken);
    $byName = [];
    foreach (($data['data'] ?? []) as $game) {
        $byName[strtolower((string) ($game['name'] ?? ''))] = ['id' => (string) ($game['id'] ?? ''), 'name' => (string) ($game['name'] ?? '')];
    }
    foreach ($candidates as &$c) {
        $hit = $byName[strtolower($c['game_name'])] ?? null;
        if ($hit && $hit['id'] !== '') {
            $c['game_id'] = $hit['id'];
            $c['game_name'] = $hit['name'];
        } else {
            $c['game_name'] = '';
        }
    }
    unset($c);
}

function vod_rerun_search_categories(string $query, string $clientID, string $accessToken): array
{
    $data = vod_rerun_helix_get('https://api.twitch.tv/helix/search/categories?query=' . urlencode($query) . '&first=10', $clientID, $accessToken);
    $out = [];
    foreach (($data['data'] ?? []) as $item) {
        $id = (string) ($item['id'] ?? '');
        $name = (string) ($item['name'] ?? '');
        if ($id === '' || $name === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => $name,
            'box_art_url' => str_replace(['{width}', '{height}'], ['52', '72'], (string) ($item['box_art_url'] ?? '')),
        ];
    }
    return $out;
}

function vod_rerun_full_title(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    return mb_substr(VOD_RERUN_PREFIX . ($title !== '' ? $title : 'Rerun'), 0, VOD_RERUN_TITLE_MAX);
}

function vod_rerun_list(mysqli $conn, int $userId): array
{
    $reruns = [];
    $sql = "(SELECT * FROM vod_reruns WHERE user_id = ? AND status IN ('scheduled', 'live') ORDER BY scheduled_at ASC LIMIT 50)
            UNION ALL
            (SELECT * FROM vod_reruns WHERE user_id = ? AND status NOT IN ('scheduled', 'live') ORDER BY scheduled_at DESC LIMIT 15)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['items'] = [];
        $reruns[(int) $row['id']] = $row;
    }
    $stmt->close();
    if (!$reruns) {
        return [];
    }
    $ids = implode(',', array_map('intval', array_keys($reruns)));
    $res = $conn->query("SELECT * FROM vod_rerun_items WHERE rerun_id IN ($ids) ORDER BY rerun_id, position");
    while ($res && $item = $res->fetch_assoc()) {
        $reruns[(int) $item['rerun_id']]['items'][] = $item;
    }
    return array_values($reruns);
}

function vod_rerun_create(mysqli $conn, int $userId, string $username, string $timezone, string $localWhen, array $picked, array $candidates): array
{
    try {
        $tz = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $when = DateTime::createFromFormat('Y-m-d\TH:i', $localWhen, $tz);
    if (!$when) {
        return ['ok' => false, 'error' => 'bad_time'];
    }
    $startUnix = $when->getTimestamp();
    if ($startUnix < time() + VOD_RERUN_MIN_LEAD_SECONDS) {
        return ['ok' => false, 'error' => 'too_soon'];
    }
    if ($startUnix > time() + VOD_RERUN_MAX_DAYS_AHEAD * 86400) {
        return ['ok' => false, 'error' => 'too_far'];
    }
    $items = [];
    foreach ($picked as $row) {
        if (!is_array($row)) {
            continue;
        }
        $token = (string) ($row['vod'] ?? '');
        if (!isset($candidates[$token])) {
            return ['ok' => false, 'error' => 'vod_missing'];
        }
        $c = $candidates[$token];
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($row['title'] ?? '')));
        if ($title === '') {
            $title = $c['title'];
        }
        $gameId = trim((string) ($row['game_id'] ?? ''));
        $gameName = trim((string) ($row['game_name'] ?? ''));
        if ($gameId !== '' && !preg_match('/^[0-9]{1,20}$/', $gameId)) {
            $gameId = '';
        }
        if ($gameId === '') {
            $gameName = '';
        }
        $items[] = [
            'filename' => $c['name'],
            'storage' => $c['storage'],
            'source_key' => $c['source_key'] !== '' ? $c['source_key'] : null,
            'title' => mb_substr($title, 0, VOD_RERUN_TITLE_MAX - mb_strlen(VOD_RERUN_PREFIX)),
            'game_id' => $gameId !== '' ? $gameId : null,
            'game_name' => $gameName !== '' ? mb_substr($gameName, 0, 255) : null,
            'duration_seconds' => $c['duration_seconds'],
        ];
    }
    if (!$items) {
        return ['ok' => false, 'error' => 'no_vods'];
    }
    if (count($items) > VOD_RERUN_MAX_ITEMS) {
        return ['ok' => false, 'error' => 'too_many'];
    }
    $total = 0;
    foreach ($items as $item) {
        $total += (int) ($item['duration_seconds'] ?? 0);
    }
    if ($total > VOD_RERUN_MAX_TOTAL_SECONDS) {
        return ['ok' => false, 'error' => 'too_long'];
    }

    // One rerun on the channel at a time: refuse a start inside another scheduled rerun's run time (where lengths are known).
    $stmt = $conn->prepare(
        "SELECT r.id, r.scheduled_at, COALESCE(SUM(i.duration_seconds), 0) AS length_s
         FROM vod_reruns r LEFT JOIN vod_rerun_items i ON i.rerun_id = r.id
         WHERE r.user_id = ? AND r.status IN ('scheduled', 'live')
         GROUP BY r.id"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db'];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $upcoming = 0;
    $endUnix = $startUnix + max($total, 60);
    while ($row = $res->fetch_assoc()) {
        $upcoming++;
        $otherStart = (new DateTime((string) $row['scheduled_at'], new DateTimeZone('UTC')))->getTimestamp();
        $otherEnd = $otherStart + max((int) $row['length_s'], 60);
        if ($startUnix < $otherEnd && $otherStart < $endUnix) {
            $stmt->close();
            return ['ok' => false, 'error' => 'overlap'];
        }
    }
    $stmt->close();
    if ($upcoming >= VOD_RERUN_MAX_UPCOMING) {
        return ['ok' => false, 'error' => 'too_many_upcoming'];
    }

    $utc = (clone $when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $tzName = $tz->getName();
    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT INTO vod_reruns (user_id, username, scheduled_at, timezone, status) VALUES (?, ?, ?, ?, 'scheduled')");
        $ins->bind_param('isss', $userId, $username, $utc, $tzName);
        $ins->execute();
        $rerunId = (int) $conn->insert_id;
        $ins->close();
        $insItem = $conn->prepare(
            "INSERT INTO vod_rerun_items (rerun_id, user_id, position, filename, storage, source_key, title, game_id, game_name, duration_seconds)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $pos => $item) {
            $position = $pos + 1;
            $insItem->bind_param(
                'iiissssssi',
                $rerunId,
                $userId,
                $position,
                $item['filename'],
                $item['storage'],
                $item['source_key'],
                $item['title'],
                $item['game_id'],
                $item['game_name'],
                $item['duration_seconds']
            );
            $insItem->execute();
        }
        $insItem->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'db'];
    }
    return ['ok' => true, 'id' => $rerunId];
}

// A scheduled rerun is cancelled outright; a live one is flagged and the stream server stops it within a few seconds.
function vod_rerun_cancel(mysqli $conn, int $userId, int $rerunId): string
{
    $stmt = $conn->prepare("UPDATE vod_reruns SET status = 'cancelled', finished_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ? AND status = 'scheduled'");
    $stmt->bind_param('ii', $rerunId, $userId);
    $stmt->execute();
    $done = $stmt->affected_rows === 1;
    $stmt->close();
    if ($done) {
        $items = $conn->prepare("UPDATE vod_rerun_items SET status = 'cancelled' WHERE rerun_id = ? AND user_id = ? AND status IN ('pending', 'staging', 'staged')");
        $items->bind_param('ii', $rerunId, $userId);
        $items->execute();
        $items->close();
        return 'cancelled';
    }
    $stmt = $conn->prepare("UPDATE vod_reruns SET cancel_requested = 1 WHERE id = ? AND user_id = ? AND status = 'live'");
    $stmt->bind_param('ii', $rerunId, $userId);
    $stmt->execute();
    $stopping = $stmt->affected_rows === 1;
    $stmt->close();
    return $stopping ? 'stopping' : 'none';
}

function vod_rerun_remove(mysqli $conn, int $userId, int $rerunId): bool
{
    $stmt = $conn->prepare("DELETE FROM vod_reruns WHERE id = ? AND user_id = ? AND status IN ('done', 'failed', 'cancelled', 'skipped')");
    $stmt->bind_param('ii', $rerunId, $userId);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    if ($ok) {
        $items = $conn->prepare("DELETE FROM vod_rerun_items WHERE rerun_id = ? AND user_id = ?");
        $items->bind_param('ii', $rerunId, $userId);
        $items->execute();
        $items->close();
    }
    return $ok;
}

// Readable text for a worker reason code; unknown codes (e.g. an ffmpeg message) are shown as-is.
function vod_rerun_reason_text(?string $code): string
{
    $code = trim((string) $code);
    if ($code === '') {
        return '';
    }
    $known = [
        'channel_live', 'no_stream_key', 'twitch_token_invalid', 'twitch_missing_scope', 'twitch_check_failed',
        'missed_start', 'another_rerun_live', 'missing_file', 'nothing_played', 'interrupted', 'worker_error',
        'low_disk', 's3_not_connected',
    ];
    if (in_array($code, $known, true)) {
        return t('rerun_reason_' . $code);
    }
    if (strpos($code, 'twitch_channel_update_') === 0) {
        return t('rerun_reason_twitch_channel_update');
    }
    if (strpos($code, 's4_http_') === 0 || strpos($code, 's3_') === 0 || strpos($code, 'copy_failed') === 0) {
        return t('rerun_reason_copy_failed');
    }
    return $code;
}
