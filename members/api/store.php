<?php
require_once dirname(__DIR__) . '/includes/session.php';
members_require_login_json();

$channel = members_sanitize_channel($_GET['user'] ?? $_GET['channel'] ?? '');
if ($channel === null) {
    json_out(['ok' => false, 'error' => 'Missing channel.', 'status' => 'invalid'], 400);
}

$viewerLogin = strtolower((string) ($_SESSION['twitch_username'] ?? ''));
$viewerId = (string) ($_SESSION['twitch_user_id'] ?? '');
$csrf = members_store_csrf();

$website = members_website_db();
$exists = members_db_exists($website, $channel);

$isDeceased = false;
$isRestricted = false;
$profileImage = null;
$displayName = $channel;

if ($exists) {
    $ustmt = $website->prepare('SELECT is_deceased, profile_image, twitch_display_name FROM users WHERE username = ? LIMIT 1');
    if ($ustmt) {
        $ustmt->bind_param('s', $channel);
        $ustmt->execute();
        $ustmt->bind_result($isDeceasedVal, $profileImageVal, $displayNameVal);
        if ($ustmt->fetch()) {
            $isDeceased = (int) $isDeceasedVal === 1;
            $profileImage = $profileImageVal ?: null;
            $displayName = $displayNameVal ?: $channel;
        }
        $ustmt->close();
    }
    if (!$isDeceased) {
        $rstmt = $website->prepare('SELECT 1 FROM restricted_users WHERE username = ? LIMIT 1');
        if ($rstmt) {
            $rstmt->bind_param('s', $channel);
            $rstmt->execute();
            $rstmt->store_result();
            $isRestricted = $rstmt->num_rows > 0;
            $rstmt->close();
        }
    }
}
$website->close();

$base = [
    'ok' => true,
    'channel' => $channel,
    'display_name' => $displayName,
    'profile_image' => $profileImage,
    'csrf' => $csrf,
];

if (!$exists) {
    session_write_close();
    json_out($base + ['status' => 'not_found']);
}
if ($isDeceased || $isRestricted) {
    session_write_close();
    json_out($base + ['status' => 'unavailable']);
}

$pointName = 'Points';
$balance = 0;
$settings = ['enabled' => 0, 'paused' => 0, 'stream_online_only' => 0];
$items = [];
$recent = [];
$storeReady = false;
$streamOnline = false;

$db = members_user_db($channel);
if ($db) {
    $storeReady = members_table_exists($db, 'point_store_items')
        && members_table_exists($db, 'point_store_settings')
        && members_table_exists($db, 'bot_points');

    if ($storeReady) {
        if (members_table_exists($db, 'bot_settings')) {
            $pn = $db->query('SELECT point_name FROM bot_settings LIMIT 1');
            if ($pn && ($pnRow = $pn->fetch_assoc()) && !empty($pnRow['point_name'])) {
                $pointName = (string) $pnRow['point_name'];
            }
        }

        if ($viewerId !== '') {
            $stmt = $db->prepare('SELECT points FROM bot_points WHERE user_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $viewerId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $balance = (int) $row['points'];
                }
            }
        }
        if ($balance === 0 && $viewerLogin !== '') {
            $stmt = $db->prepare('SELECT points FROM bot_points WHERE user_name = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $viewerLogin);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $balance = (int) $row['points'];
                }
            }
        }

        if (members_table_exists($db, 'stream_status')) {
            $res = $db->query('SELECT status FROM stream_status LIMIT 1');
            if ($res) {
                $row = $res->fetch_assoc();
                $streamOnline = $row && strtolower((string) $row['status']) === 'true';
            }
        }

        $sRes = $db->query('SELECT enabled, paused, stream_online_only FROM point_store_settings WHERE id = 1 LIMIT 1');
        if ($sRes && ($sRow = $sRes->fetch_assoc())) {
            $settings = $sRow;
        }

        $iRes = $db->query('SELECT id, title, slug, description, cost, item_type, cooldown_seconds, stock, max_per_stream
            FROM point_store_items WHERE enabled = 1 ORDER BY sort_order ASC, cost ASC, title ASC');
        if ($iRes) {
            while ($row = $iRes->fetch_assoc()) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                    'cost' => (int) $row['cost'],
                    'item_type' => $row['item_type'],
                    'cooldown_seconds' => (int) ($row['cooldown_seconds'] ?? 0),
                    'stock' => $row['stock'] === null || $row['stock'] === '' ? null : (int) $row['stock'],
                    'max_per_stream' => $row['max_per_stream'] === null || $row['max_per_stream'] === '' ? null : (int) $row['max_per_stream'],
                ];
            }
        }

        if (members_table_exists($db, 'point_store_purchases') && ($viewerId !== '' || $viewerLogin !== '')) {
            $rStmt = $db->prepare(
                'SELECT item_title, cost, created_at FROM point_store_purchases
                 WHERE (user_id = ? OR user_name = ?) ORDER BY id DESC LIMIT 5'
            );
            if ($rStmt) {
                $rStmt->bind_param('ss', $viewerId, $viewerLogin);
                $rStmt->execute();
                $rRes = $rStmt->get_result();
                while ($r = $rRes->fetch_assoc()) {
                    $recent[] = $r;
                }
                $rStmt->close();
            }
        }
    }
    $db->close();
}

session_write_close();
json_out($base + [
    'status' => 'ok',
    'store_ready' => $storeReady,
    'point_name' => $pointName,
    'balance' => $balance,
    'settings' => [
        'enabled' => (int) ($settings['enabled'] ?? 0),
        'paused' => (int) ($settings['paused'] ?? 0),
        'stream_online_only' => (int) ($settings['stream_online_only'] ?? 0),
    ],
    'stream_online' => $streamOnline,
    'items' => $items,
    'recent' => $recent,
]);
