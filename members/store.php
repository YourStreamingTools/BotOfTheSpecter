<?php
/**
 * Members Point Store - shopfront for a channel's bot-points catalog.
 * URL: https://members.botofthespecter.com/{channel}/store
 *      https://members.botofthespecter.com/store.php?user={channel}
 *
 * Schema is provisioned by dashboard/includes/usr_database.php only.
 */
require_once '/var/www/lib/session_bootstrap.php';

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: https://members.botofthespecter.com/login.php');
    exit();
}

require_once '/var/www/config/database.php';

function store_esc($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function store_sanitize_username($input) {
    $u = strtolower(trim((string) $input));
    return preg_match('/^[a-z0-9_]{1,64}$/', $u) ? $u : null;
}

function store_resolve_channel() {
    if (isset($_GET['user']) && $_GET['user'] !== '') {
        return store_sanitize_username($_GET['user']);
    }
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $parts = $path === '' ? [] : explode('/', $path);
    if (count($parts) >= 2 && strtolower($parts[1]) === 'store') {
        return store_sanitize_username($parts[0]);
    }
    return null;
}

function store_json(array $payload, $http = 200) {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function store_table_exists(mysqli $db, $table) {
    $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $res && $res->num_rows > 0;
}

function store_media_public_url($channel, $relative, $isNewMedia, $kind) {
    $relative = str_replace('\\', '/', ltrim((string) $relative, '/'));
    $parts = array_map('rawurlencode', explode('/', $relative));
    $path = implode('/', $parts);
    if ($isNewMedia) {
        return 'https://media.botofthespecter.com/' . rawurlencode($channel) . '/' . $path;
    }
    if ($kind === 'video') {
        return 'https://videoalerts.botofthespecter.com/' . rawurlencode($channel) . '/' . $path;
    }
    return 'https://soundalerts.botofthespecter.com/' . rawurlencode($channel) . '/' . $path;
}

function store_notify($apiKey, $event, array $params = []) {
    $url = 'https://websocket.botofthespecter.com/notify?code=' . rawurlencode($apiKey)
        . '&event=' . rawurlencode($event);
    if (!empty($params)) {
        $url .= '&' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body !== false && $code >= 200 && $code < 300;
}

function store_get_balance(mysqli $db, $userId, $userName) {
    if ($userId !== '') {
        $stmt = $db->prepare('SELECT points FROM bot_points WHERE user_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['points'];
            }
        }
    }
    if ($userName !== '') {
        $stmt = $db->prepare('SELECT points FROM bot_points WHERE user_name = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $userName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['points'];
            }
        }
    }
    return 0;
}

function store_is_stream_online(mysqli $db) {
    if (!store_table_exists($db, 'stream_status')) {
        return false;
    }
    $res = $db->query('SELECT status FROM stream_status LIMIT 1');
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    return $row && strtolower((string) $row['status']) === 'true';
}

/**
 * Atomic checkout. Returns ['balance_after'=>int, 'item'=>array, 'point_name'=>string] or throws RuntimeException.
 */
function store_checkout(mysqli $db, array $settings, $itemId, $viewerId, $viewerLogin, $viewerDisplay, $now) {
    if (empty($settings['enabled'])) {
        throw new RuntimeException('The store is currently disabled.');
    }
    if (!empty($settings['paused'])) {
        throw new RuntimeException('The store is temporarily paused.');
    }
    if (!empty($settings['stream_online_only']) && !store_is_stream_online($db)) {
        throw new RuntimeException('Purchases are only allowed while the stream is live.');
    }

    $itemStmt = $db->prepare('SELECT * FROM point_store_items WHERE id = ? AND enabled = 1 LIMIT 1');
    $itemStmt->bind_param('i', $itemId);
    $itemStmt->execute();
    $item = $itemStmt->get_result()->fetch_assoc();
    $itemStmt->close();
    if (!$item) {
        throw new RuntimeException('Item not found or disabled.');
    }

    $cost = (int) $item['cost'];
    if ($cost < 1) {
        throw new RuntimeException('Invalid item price.');
    }

    $pointName = 'Points';
    $pn = $db->query('SELECT point_name FROM bot_settings LIMIT 1');
    if ($pn && ($pnRow = $pn->fetch_assoc()) && !empty($pnRow['point_name'])) {
        $pointName = $pnRow['point_name'];
    }

    $payload = [];
    if (!empty($item['payload'])) {
        $decoded = json_decode($item['payload'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $item['payload_decoded'] = $payload;

    if (store_table_exists($db, 'point_store_purchases')) {
        $globalCd = max(0, (int) ($settings['global_cooldown_seconds'] ?? 0));
        if ($globalCd > 0) {
            $cdStmt = $db->prepare('SELECT created_at FROM point_store_purchases WHERE (user_id = ? OR user_name = ?) ORDER BY id DESC LIMIT 1');
            $cdStmt->bind_param('ss', $viewerId, $viewerLogin);
            $cdStmt->execute();
            $cdRow = $cdStmt->get_result()->fetch_assoc();
            $cdStmt->close();
            if ($cdRow && !empty($cdRow['created_at'])) {
                $elapsed = $now - strtotime($cdRow['created_at']);
                if ($elapsed < $globalCd) {
                    throw new RuntimeException('Global store cooldown: try again in ' . max(1, $globalCd - $elapsed) . 's.');
                }
            }
        }
        $itemCd = max(0, (int) ($item['cooldown_seconds'] ?? 0));
        if ($itemCd > 0) {
            $icStmt = $db->prepare('SELECT created_at FROM point_store_purchases WHERE item_id = ? AND (user_id = ? OR user_name = ?) ORDER BY id DESC LIMIT 1');
            $icStmt->bind_param('iss', $itemId, $viewerId, $viewerLogin);
            $icStmt->execute();
            $icRow = $icStmt->get_result()->fetch_assoc();
            $icStmt->close();
            if ($icRow && !empty($icRow['created_at'])) {
                $elapsed = $now - strtotime($icRow['created_at']);
                if ($elapsed < $itemCd) {
                    throw new RuntimeException('Item cooldown: try again in ' . max(1, $itemCd - $elapsed) . 's.');
                }
            }
        }
        $maxGlobal = $settings['max_purchases_per_user_per_stream'] ?? null;
        if ($maxGlobal !== null && $maxGlobal !== '') {
            $maxG = (int) $maxGlobal;
            if ($maxG > 0) {
                $mgStmt = $db->prepare('SELECT COUNT(*) AS c FROM point_store_purchases WHERE (user_id = ? OR user_name = ?) AND created_at >= CURDATE()');
                $mgStmt->bind_param('ss', $viewerId, $viewerLogin);
                $mgStmt->execute();
                $mgRow = $mgStmt->get_result()->fetch_assoc();
                $mgStmt->close();
                if ($mgRow && (int) $mgRow['c'] >= $maxG) {
                    throw new RuntimeException('You have reached the max purchases for this stream.');
                }
            }
        }
        if ($item['max_per_stream'] !== null && $item['max_per_stream'] !== '') {
            $maxI = (int) $item['max_per_stream'];
            if ($maxI > 0) {
                $miStmt = $db->prepare('SELECT COUNT(*) AS c FROM point_store_purchases WHERE item_id = ? AND (user_id = ? OR user_name = ?) AND created_at >= CURDATE()');
                $miStmt->bind_param('iss', $itemId, $viewerId, $viewerLogin);
                $miStmt->execute();
                $miRow = $miStmt->get_result()->fetch_assoc();
                $miStmt->close();
                if ($miRow && (int) $miRow['c'] >= $maxI) {
                    throw new RuntimeException('You have reached the max purchases of this item for this stream.');
                }
            }
        }
    }

    $db->begin_transaction();
    try {
        if ($item['stock'] !== null && $item['stock'] !== '') {
            $stStmt = $db->prepare('UPDATE point_store_items SET stock = stock - 1 WHERE id = ? AND stock IS NOT NULL AND stock > 0');
            $stStmt->bind_param('i', $itemId);
            $stStmt->execute();
            $ok = $stStmt->affected_rows === 1;
            $stStmt->close();
            if (!$ok) {
                throw new RuntimeException('This item is out of stock.');
            }
        }

        $debited = false;
        $balanceAfter = 0;

        if ($viewerId !== '') {
            $dStmt = $db->prepare('UPDATE bot_points SET points = points - ?, user_name = ? WHERE user_id = ? AND points >= ?');
            $dStmt->bind_param('issi', $cost, $viewerLogin, $viewerId, $cost);
            $dStmt->execute();
            if ($dStmt->affected_rows === 1) {
                $debited = true;
            }
            $dStmt->close();
            if ($debited) {
                $bStmt = $db->prepare('SELECT points FROM bot_points WHERE user_id = ? LIMIT 1');
                $bStmt->bind_param('s', $viewerId);
                $bStmt->execute();
                $balanceAfter = (int) $bStmt->get_result()->fetch_assoc()['points'];
                $bStmt->close();
            }
        }

        if (!$debited && $viewerLogin !== '') {
            $dStmt = $db->prepare('UPDATE bot_points SET points = points - ? WHERE user_name = ? AND points >= ?');
            $dStmt->bind_param('isi', $cost, $viewerLogin, $cost);
            $dStmt->execute();
            if ($dStmt->affected_rows === 1) {
                $debited = true;
            }
            $dStmt->close();
            if ($debited) {
                $bStmt = $db->prepare('SELECT points FROM bot_points WHERE user_name = ? LIMIT 1');
                $bStmt->bind_param('s', $viewerLogin);
                $bStmt->execute();
                $balanceAfter = (int) $bStmt->get_result()->fetch_assoc()['points'];
                $bStmt->close();
            }
        }

        if (!$debited) {
            throw new RuntimeException('Not enough ' . $pointName . '.');
        }

        if (store_table_exists($db, 'point_store_purchases')) {
            $itemTitle = (string) $item['title'];
            $itemType = (string) $item['item_type'];
            $uidStr = $viewerId;
            $uname = $viewerLogin !== '' ? $viewerLogin : $viewerDisplay;
            $source = 'members';
            $status = 'completed';
            // i s s i s s i s s
            $pStmt = $db->prepare(
                'INSERT INTO point_store_purchases
                    (item_id, item_title, item_type, cost, user_id, user_name, balance_after, source, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // i s s i s s i s s
            $pStmt->bind_param(
                'issississ',
                $itemId,
                $itemTitle,
                $itemType,
                $cost,
                $uidStr,
                $uname,
                $balanceAfter,
                $source,
                $status
            );
            if (!$pStmt->execute()) {
                throw new RuntimeException('Could not record purchase.');
            }
            $pStmt->close();
        }

        $db->commit();
        return [
            'balance_after' => $balanceAfter,
            'item' => $item,
            'point_name' => $pointName,
            'cost' => $cost,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

// Identity
$channel = store_resolve_channel();
$viewerLogin = strtolower((string) ($_SESSION['twitch_username'] ?? ''));
$viewerId = (string) ($_SESSION['twitch_user_id'] ?? '');
$viewerDisplay = (string) ($_SESSION['display_name'] ?? $viewerLogin);
$isBuy = (isset($_POST['action']) && $_POST['action'] === 'buy');

if (empty($_SESSION['store_csrf'])) {
    $_SESSION['store_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['store_csrf'];

if (!$channel) {
    if ($isBuy) {
        store_json(['success' => false, 'message' => 'Missing channel.'], 400);
    }
    $pageTitle = 'Point Store';
    $activeNav = '';
    $topbarActions = '<a href="/" class="sp-btn sp-btn-secondary sp-btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Search</a>';
    ob_start();
    ?>
    <div class="sp-empty">
        <i class="fa-solid fa-store"></i>
        <h3>Channel required</h3>
        <p>Open a store via <code>/{channel}/store</code>.</p>
        <a href="/" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-arrow-left"></i> Search channels</a>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/layout.php';
    exit();
}

// Website meta
$notFound = false;
$isRestricted = false;
$isDeceased = false;
$memberProfileImage = null;
$memberDisplayName = $channel;
$streamerApiKey = null;
$isNewMedia = false;

$websiteConn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($websiteConn->connect_error) {
    if ($isBuy) {
        store_json(['success' => false, 'message' => 'Service unavailable.'], 503);
    }
    die('Database unavailable.');
}

// SHOW DATABASES does not support prepared-statement placeholders on MySQL.
// $channel is already restricted to [a-z0-9_]{1,64} by store_sanitize_username().
$chkRes = $websiteConn->query("SHOW DATABASES LIKE '" . $websiteConn->real_escape_string($channel) . "'");
if (!$chkRes || $chkRes->num_rows === 0) {
    $notFound = true;
}

if (!$notFound) {
    $ustmt = $websiteConn->prepare('SELECT is_deceased, profile_image, twitch_display_name, api_key, new_media FROM users WHERE username = ? LIMIT 1');
    if ($ustmt) {
        $ustmt->bind_param('s', $channel);
        $ustmt->execute();
        $ustmt->bind_result($isDeceasedVal, $profileImageVal, $displayNameVal, $apiKeyVal, $newMediaVal);
        if ($ustmt->fetch()) {
            $isDeceased = (int) $isDeceasedVal === 1;
            $memberProfileImage = $profileImageVal;
            $memberDisplayName = $displayNameVal ?: $channel;
            $streamerApiKey = $apiKeyVal;
            $isNewMedia = !empty($newMediaVal);
        }
        $ustmt->close();
    }
    if (!$isDeceased) {
        $rstmt = $websiteConn->prepare('SELECT 1 FROM restricted_users WHERE username = ? LIMIT 1');
        if ($rstmt) {
            $rstmt->bind_param('s', $channel);
            $rstmt->execute();
            $rstmt->store_result();
            $isRestricted = $rstmt->num_rows > 0;
            $rstmt->close();
        }
    }
}
$websiteConn->close();

// BUY
if ($isBuy) {
    if ($notFound || $isRestricted || $isDeceased) {
        store_json(['success' => false, 'message' => 'This channel store is unavailable.'], 403);
    }
    if ($viewerLogin === '' && $viewerId === '') {
        store_json(['success' => false, 'message' => 'Not signed in.'], 401);
    }
    $token = (string) ($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($csrfToken, $token)) {
        store_json(['success' => false, 'message' => 'Invalid session token. Refresh the page.'], 403);
    }
    $now = time();
    $last = (int) ($_SESSION['store_last_buy_at'] ?? 0);
    if ($now - $last < 2) {
        store_json(['success' => false, 'message' => 'Please wait a moment before buying again.'], 429);
    }
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId < 1) {
        store_json(['success' => false, 'message' => 'Invalid item.'], 400);
    }
    if (!$streamerApiKey) {
        store_json(['success' => false, 'message' => 'Channel is not configured for live redemptions.'], 503);
    }

    $db = new mysqli($db_servername, $db_username, $db_password, $channel);
    if ($db->connect_error) {
        store_json(['success' => false, 'message' => 'Could not open channel data.'], 503);
    }
    $db->set_charset('utf8mb4');

    if (!store_table_exists($db, 'point_store_items')
        || !store_table_exists($db, 'point_store_settings')
        || !store_table_exists($db, 'bot_points')
    ) {
        $db->close();
        store_json(['success' => false, 'message' => 'This channel has not set up a Point Store yet.'], 404);
    }

    $settings = [
        'enabled' => 0,
        'paused' => 0,
        'stream_online_only' => 0,
        'global_cooldown_seconds' => 0,
        'max_purchases_per_user_per_stream' => null,
    ];
    $sRes = $db->query('SELECT * FROM point_store_settings WHERE id = 1 LIMIT 1');
    if ($sRes && ($sRow = $sRes->fetch_assoc())) {
        $settings = $sRow;
    }

    try {
        $result = store_checkout($db, $settings, $itemId, $viewerId, $viewerLogin, $viewerDisplay, $now);
    } catch (RuntimeException $e) {
        $db->close();
        store_json(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('members store buy: ' . $e->getMessage());
        $db->close();
        store_json(['success' => false, 'message' => 'Purchase failed. Please try again.'], 500);
    }
    $db->close();

    $_SESSION['store_last_buy_at'] = $now;

    $item = $result['item'];
    $payload = $item['payload_decoded'] ?? [];
    $pointName = $result['point_name'];
    $cost = $result['cost'];
    $balanceAfter = $result['balance_after'];
    $notifyOk = true;

    // STORE event (bot chat / future clients)
    $storeParams = [
        'username' => $viewerLogin,
        'display_name' => $viewerDisplay,
        'user_id' => $viewerId,
        'item_id' => (string) $itemId,
        'item_title' => $item['title'],
        'item_type' => $item['item_type'],
        'cost' => (string) $cost,
        'point_name' => $pointName,
        'balance_after' => (string) $balanceAfter,
        'source' => 'members',
    ];
    if (!empty($payload['sound'])) {
        $storeParams['sound'] = $payload['sound'];
    }
    if (!empty($payload['video'])) {
        $storeParams['video'] = $payload['video'];
    }
    if (!empty($payload['text'])) {
        $storeParams['text'] = $payload['text'];
    }
    if (!store_notify($streamerApiKey, 'STORE', $storeParams)) {
        $notifyOk = false;
    }

    // Companion media events so overlays work without new overlay code
    $type = $item['item_type'];
    if ($type === 'sound_alert' && !empty($payload['sound'])) {
        $url = store_media_public_url($channel, $payload['sound'], $isNewMedia, 'sound');
        if (!store_notify($streamerApiKey, 'SOUND_ALERT', ['sound' => $url])) {
            $notifyOk = false;
        }
    } elseif ($type === 'video_alert' && !empty($payload['video'])) {
        $url = store_media_public_url($channel, $payload['video'], $isNewMedia, 'video');
        if (!store_notify($streamerApiKey, 'VIDEO_ALERT', ['video' => $url])) {
            $notifyOk = false;
        }
    } elseif ($type === 'tts' && !empty($payload['text'])) {
        if (!store_notify($streamerApiKey, 'TTS', ['text' => $payload['text']])) {
            $notifyOk = false;
        }
    }
    // chat_message: STORE payload carries text for bot handler

    store_json([
        'success' => true,
        'message' => 'Purchased ' . $item['title'] . ' for ' . $cost . ' ' . $pointName . '.',
        'balance' => $balanceAfter,
        'point_name' => $pointName,
        'item' => [
            'id' => (int) $item['id'],
            'title' => $item['title'],
            'cost' => $cost,
            'item_type' => $item['item_type'],
        ],
        'notify_ok' => $notifyOk,
    ]);
}

// PAGE RENDER
session_write_close();

$pageTitle = store_esc($memberDisplayName) . ' - Point Store';
$activeNav = '';
$topbarActions = '<a href="/' . rawurlencode($channel) . '" class="sp-btn sp-btn-secondary sp-btn-sm"><i class="fa-solid fa-arrow-left"></i> Channel</a>';

if ($notFound) {
    ob_start();
    ?>
    <div class="sp-empty">
        <i class="fa-solid fa-circle-question"></i>
        <h3>Channel not found</h3>
        <p>We couldn&rsquo;t find <strong><?php echo store_esc($channel); ?></strong> on BotOfTheSpecter.</p>
        <a href="/" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Search</a>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/layout.php';
    exit();
}

if ($isDeceased || $isRestricted) {
    ob_start();
    ?>
    <div class="sp-empty">
        <i class="fa-solid fa-store-slash"></i>
        <h3>Store unavailable</h3>
        <p>This channel&rsquo;s store cannot be viewed.</p>
        <a href="/" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Search</a>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/layout.php';
    exit();
}

$pointName = 'Points';
$balance = 0;
$settings = ['enabled' => 0, 'paused' => 0, 'stream_online_only' => 0];
$items = [];
$recent = [];
$storeReady = false;
$streamOnline = false;

$db = new mysqli($db_servername, $db_username, $db_password, $channel);
if (!$db->connect_error) {
    $db->set_charset('utf8mb4');
    $storeReady = store_table_exists($db, 'point_store_items')
        && store_table_exists($db, 'point_store_settings')
        && store_table_exists($db, 'bot_points');

    if ($storeReady) {
        $pn = $db->query('SELECT point_name FROM bot_settings LIMIT 1');
        if ($pn && ($pnRow = $pn->fetch_assoc()) && !empty($pnRow['point_name'])) {
            $pointName = $pnRow['point_name'];
        }
        $balance = store_get_balance($db, $viewerId, $viewerLogin);
        $streamOnline = store_is_stream_online($db);

        $sRes = $db->query('SELECT * FROM point_store_settings WHERE id = 1 LIMIT 1');
        if ($sRes && ($sRow = $sRes->fetch_assoc())) {
            $settings = $sRow;
        }

        $iRes = $db->query('SELECT id, title, slug, description, cost, item_type, payload, cooldown_seconds, stock, max_per_stream
            FROM point_store_items WHERE enabled = 1 ORDER BY sort_order ASC, cost ASC, title ASC');
        if ($iRes) {
            while ($row = $iRes->fetch_assoc()) {
                $row['payload_decoded'] = [];
                if (!empty($row['payload'])) {
                    $d = json_decode($row['payload'], true);
                    if (is_array($d)) {
                        $row['payload_decoded'] = $d;
                    }
                }
                $items[] = $row;
            }
        }

        if (store_table_exists($db, 'point_store_purchases') && ($viewerId !== '' || $viewerLogin !== '')) {
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

$typeIcons = [
    'sound_alert' => 'fa-volume-high',
    'video_alert' => 'fa-film',
    'tts' => 'fa-comment-dots',
    'chat_message' => 'fa-message',
];
$typeLabels = [
    'sound_alert' => 'Sound',
    'video_alert' => 'Video',
    'tts' => 'TTS',
    'chat_message' => 'Chat',
];

$storeOpen = $storeReady && !empty($settings['enabled']) && empty($settings['paused']);
$liveOnlyBlocked = $storeOpen && !empty($settings['stream_online_only']) && !$streamOnline;

ob_start();
?>
<div class="sp-page-header ms-store-header">
    <div class="ms-store-channel">
        <?php if (!empty($memberProfileImage)): ?>
            <img class="ms-store-avatar" src="<?php echo store_esc($memberProfileImage); ?>" alt="">
        <?php endif; ?>
        <div>
            <h1><?php echo store_esc($memberDisplayName); ?> Store</h1>
            <p class="ms-store-sub">Spend your <?php echo store_esc($pointName); ?> on streamer-approved rewards.</p>
        </div>
    </div>
    <div class="ms-store-balance-card" id="store-balance-card">
        <div class="ms-store-balance-label">Your balance</div>
        <div class="ms-store-balance-value">
            <span id="store-balance"><?php echo (int) $balance; ?></span>
            <span class="ms-store-balance-unit"><?php echo store_esc($pointName); ?></span>
        </div>
    </div>
</div>

<div id="store-toast" class="sp-alert" style="display:none;margin-bottom:1rem;"></div>

<?php if (!$storeReady): ?>
    <div class="sp-empty">
        <i class="fa-solid fa-store"></i>
        <h3>Store not set up</h3>
        <p>This channel hasn&rsquo;t configured a Point Store yet.</p>
    </div>
<?php elseif (empty($settings['enabled'])): ?>
    <div class="sp-empty">
        <i class="fa-solid fa-store-slash"></i>
        <h3>Store closed</h3>
        <p>The streamer has not enabled their Point Store.</p>
    </div>
<?php elseif (!empty($settings['paused'])): ?>
    <div class="sp-empty">
        <i class="fa-solid fa-pause"></i>
        <h3>Store paused</h3>
        <p>Purchases are temporarily paused. Check back soon.</p>
    </div>
<?php elseif ($liveOnlyBlocked): ?>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
        <i class="fa-solid fa-broadcast-tower"></i>
        This store only accepts purchases while the stream is live.
    </div>
    <?php if (empty($items)): ?>
        <div class="sp-empty"><i class="fa-solid fa-box-open"></i><h3>No items</h3><p>No store items are listed yet.</p></div>
    <?php else: ?>
        <div class="ms-store-grid ms-store-grid--disabled">
            <?php foreach ($items as $item): ?>
                <div class="ms-store-card">
                    <div class="ms-store-card-icon"><i class="fa-solid <?php echo store_esc($typeIcons[$item['item_type']] ?? 'fa-gift'); ?>"></i></div>
                    <div class="ms-store-card-body">
                        <div class="ms-store-card-type"><?php echo store_esc($typeLabels[$item['item_type']] ?? $item['item_type']); ?></div>
                        <h3 class="ms-store-card-title"><?php echo store_esc($item['title']); ?></h3>
                        <?php if (!empty($item['description'])): ?>
                            <p class="ms-store-card-desc"><?php echo store_esc($item['description']); ?></p>
                        <?php endif; ?>
                        <div class="ms-store-card-cost"><?php echo (int) $item['cost']; ?> <?php echo store_esc($pointName); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php elseif (empty($items)): ?>
    <div class="sp-empty">
        <i class="fa-solid fa-box-open"></i>
        <h3>No items yet</h3>
        <p>The streamer hasn&rsquo;t added any store items.</p>
    </div>
<?php else: ?>
    <div class="ms-store-grid" id="store-grid">
        <?php foreach ($items as $item):
            $canAfford = $balance >= (int) $item['cost'];
            $outOfStock = ($item['stock'] !== null && $item['stock'] !== '' && (int) $item['stock'] <= 0);
            $canBuy = $canAfford && !$outOfStock;
            ?>
            <div class="ms-store-card<?php echo $canBuy ? '' : ' is-locked'; ?>" data-item-id="<?php echo (int) $item['id']; ?>" data-cost="<?php echo (int) $item['cost']; ?>">
                <div class="ms-store-card-icon"><i class="fa-solid <?php echo store_esc($typeIcons[$item['item_type']] ?? 'fa-gift'); ?>"></i></div>
                <div class="ms-store-card-body">
                    <div class="ms-store-card-type"><?php echo store_esc($typeLabels[$item['item_type']] ?? $item['item_type']); ?></div>
                    <h3 class="ms-store-card-title"><?php echo store_esc($item['title']); ?></h3>
                    <?php if (!empty($item['description'])): ?>
                        <p class="ms-store-card-desc"><?php echo store_esc($item['description']); ?></p>
                    <?php endif; ?>
                    <div class="ms-store-card-cost"><?php echo (int) $item['cost']; ?> <?php echo store_esc($pointName); ?></div>
                    <?php if ($outOfStock): ?>
                        <span class="sp-badge sp-badge-closed">Out of stock</span>
                    <?php elseif (!$canAfford): ?>
                        <span class="sp-badge sp-badge-closed">Need more <?php echo store_esc($pointName); ?></span>
                    <?php endif; ?>
                </div>
                <div class="ms-store-card-actions">
                    <button type="button"
                            class="sp-btn sp-btn-primary sp-btn-sm store-buy-btn"
                            data-item-id="<?php echo (int) $item['id']; ?>"
                            data-title="<?php echo store_esc($item['title']); ?>"
                            <?php echo $canBuy ? '' : 'disabled'; ?>>
                        <i class="fa-solid fa-cart-shopping"></i> Buy
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($recent)): ?>
    <div class="sp-card" style="margin-top:1.5rem;">
        <div class="sp-card-header">
            <span class="sp-card-title"><i class="fa-solid fa-receipt"></i> Your recent purchases</span>
        </div>
        <div class="sp-card-body">
            <ul class="ms-store-recent">
                <?php foreach ($recent as $r): ?>
                    <li>
                        <strong><?php echo store_esc($r['item_title']); ?></strong>
                        <span><?php echo (int) $r['cost']; ?> <?php echo store_esc($pointName); ?></span>
                        <span class="ms-store-recent-time"><?php echo store_esc($r['created_at']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    var csrf = <?php echo json_encode($csrfToken); ?>;
    var channel = <?php echo json_encode($channel); ?>;
    var pointName = <?php echo json_encode($pointName); ?>;
    var balanceEl = document.getElementById('store-balance');
    var toast = document.getElementById('store-toast');
    var buyEnabled = <?php echo json_encode($storeOpen && !$liveOnlyBlocked); ?>;

    function showToast(msg, ok) {
        if (!toast) return;
        toast.style.display = '';
        toast.className = 'sp-alert ' + (ok ? 'sp-alert-success' : 'sp-alert-danger');
        toast.textContent = msg;
        setTimeout(function () { toast.style.display = 'none'; }, 5000);
    }

    function refreshAffordability(balance) {
        document.querySelectorAll('.ms-store-card').forEach(function (card) {
            var cost = parseInt(card.getAttribute('data-cost'), 10) || 0;
            var btn = card.querySelector('.store-buy-btn');
            if (!btn || btn.dataset.outOfStock === '1') return;
            var ok = balance >= cost;
            btn.disabled = !ok || !buyEnabled;
            card.classList.toggle('is-locked', !ok);
        });
    }

    document.querySelectorAll('.store-buy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled || !buyEnabled) return;
            var itemId = btn.getAttribute('data-item-id');
            var title = btn.getAttribute('data-title') || 'item';
            if (!confirm('Buy "' + title + '"?')) return;
            btn.disabled = true;
            var body = new FormData();
            body.append('action', 'buy');
            body.append('item_id', itemId);
            body.append('csrf', csrf);
            fetch(window.location.pathname + (window.location.search || ''), {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                if (res.j && res.j.success) {
                    showToast(res.j.message || 'Purchased!', true);
                    if (typeof res.j.balance === 'number' && balanceEl) {
                        balanceEl.textContent = String(res.j.balance);
                        refreshAffordability(res.j.balance);
                    }
                } else {
                    showToast((res.j && res.j.message) || 'Purchase failed.', false);
                    btn.disabled = false;
                    refreshAffordability(parseInt(balanceEl && balanceEl.textContent, 10) || 0);
                }
            }).catch(function () {
                showToast('Network error. Try again.', false);
                btn.disabled = false;
            });
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
