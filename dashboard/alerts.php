<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

require_once '/var/www/lib/require_auth.php';

$pageTitle = 'Specter Alerts';

require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include 'includes/user_db.php';
require_once __DIR__ . '/includes/upload_helpers.php';
require_once __DIR__ . '/includes/file_paths.php';
session_write_close();

$stmt = $db->prepare("SELECT timezone, media_migrated FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$media_migrated = (bool)($channelData['media_migrated'] ?? false);
$stmt->close();
date_default_timezone_set($timezone);

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Per-category variant caps. A follow is a follow — no condition can
// meaningfully split it, so 1 is the only sane number.
$variantLimits = [
    'follow'       => 1,
    'stream_bingo' => 4,   // one per bingo sub-event (Started / Event / Winner / Ended)
    // Enable/disable-only categories: they render through their own overlay theme
    // (ported into overlay/index.php), so a single on/off variant is all that fits.
    'weather'      => 1,
    'deaths'       => 1,
    'walkons'      => 1,
];
// Stream Bingo sub-events: each variant ties to one of these via the dropdown.
// Same pattern as channel_points where the variant picks the trigger.
$bingoSubtypes = [
    'STREAM_BINGO_STARTED'      => 'Game Started',
    'STREAM_BINGO_EVENT_CALLED' => 'Game Event',
    'STREAM_BINGO_WINNER'       => 'Game Winner',
    'STREAM_BINGO_ENDED'        => 'Game Ended',
];
$defaultAlerts = [
    // Native Twitch events
    ['follow', 'New follower', 0, null, "{username}\nfollowed!"],
    ['subscription', 'New subscriber', 0, 'months = 1', "{username}\njust subscribed!"],
    ['subscription', 'Resub', 1, 'months >= 2', "{username}\nresubscribed for {months} months!"],
    ['gift_subscription', 'Single gift sub', 0, null, "{username}\ngifted a sub!"],
    ['gift_subscription', 'Bulk gift subs', 1, 'gift_count >= 5', "{username}\ngifted {amount} subs!"],
    ['bits', 'First cheer', 0, 'is_first = 1', "{username}\njust cheered for the first time!"],
    ['bits', '100+ bits', 1, 'bits >= 100', "{username}\ncheered {amount} bits!"],
    ['bits', '1k+ bits', 2, 'bits >= 1000', "{username}\ndropped {amount} bits!"],
    ['bits', '10k+ bits', 3, 'bits >= 10000', "{username}\nrained {amount} bits!"],
    ['raid', 'Raid', 0, 'viewers >= 1', "{username}\nraiding with {viewers}!"],
    ['hype_train', 'Hype train started', 0, null, "The Hype Train is leaving the station!"],
    ['hype_train', 'Level 3 reached',    1, 'level >= 3', "Hype Train is at Level {level}!"],
    ['hype_train', 'Level 5 reached',    2, 'level >= 5', "MAX LEVEL — Hype Train hit Level {level}!"],
    ['charity', 'Charity donation',  0, null,             "{username}\ndonated {amount} to {charity_name}!"],
    ['charity', 'Large donation',    1, 'amount >= 100',  "Massive thank-you to {username}\n— {amount} for {charity_name}!"],
    ['charity', 'Mega donation',     2, 'amount >= 500',  "INCREDIBLE — {username} just dropped {amount} for {charity_name}!"],
    ['channel_points', 'Channel point reward', 0, null, "{username}\nredeemed a reward!"],
    // BotOfTheSpecter integrations — what makes this page ours
    ['discord_join', 'New Discord member', 0, null, "{username}\nhopped into the Discord!"],
    ['kofi', 'Donation',     0, "kofi_type = 'Donation'",     "{username}\ndonated {amount}!"],
    ['kofi', 'Subscription', 1, "kofi_type = 'Subscription'", "{username}\nsubscribed via Ko-fi!"],
    ['kofi', 'Shop Order',   2, "kofi_type = 'Shop Order'",   "{username}\nordered from the shop!"],
    ['patreon', 'New patron',      0, "patreon_type = 'pledge'",    "{username}\nbecame a patron!"],
    ['patreon', 'Pledge updated',   1, "patreon_type = 'update'",    "{username}\nupdated their pledge"],
    ['patreon', 'Patron left',      2, "patreon_type = 'cancelled'", "{username}\nended their support."],
    ['fourthwall', 'Order placed',  0, "fourthwall_type = 'ORDER_PLACED'",          "{username}\nbought {item} from the shop!"],
    ['fourthwall', 'Donation',      1, "fourthwall_type = 'DONATION'",              "{username}\ndonated {amount}!"],
    ['fourthwall', 'Giveaway',      2, "fourthwall_type = 'GIVEAWAY_PURCHASED'",    "{username}\npurchased a giveaway!"],
    ['fourthwall', 'Subscription',  3, "fourthwall_type = 'SUBSCRIPTION_PURCHASED'","{username}\nsubscribed via Fourthwall!"],
    ['subathon', 'Subathon time added', 0, null, "{added_minutes} minutes added to the subathon!"],
    ['stream_bingo', 'Game Started', 0, "bingo_event = 'STREAM_BINGO_STARTED'",      "Stream Bingo is starting!"],
    ['stream_bingo', 'Game Event',   1, "bingo_event = 'STREAM_BINGO_EVENT_CALLED'", "Event called:\n{bingo_event_name}"],
    ['stream_bingo', 'Game Winner',  2, "bingo_event = 'STREAM_BINGO_WINNER'",       "BINGO! {username}\ngot {rank_text}!"],
    ['stream_bingo', 'Game Ended',   3, "bingo_event = 'STREAM_BINGO_ENDED'",        "Stream Bingo has ended!"],
    ['watch_streak', 'Watch streak', 0, 'streak >= 7', "{username}\nis on a {streak}-stream watch streak!"],
    // Enable/disable-only: these render in their existing overlay theme via overlay/index.php.
    ['weather', 'Weather', 0, null, null],
    ['deaths', 'Death counter', 0, null, null],
    ['walkons', 'Walk-ons', 0, null, null],
];

// Seed defaults if table is empty
$countResult = $db->query("SELECT COUNT(*) AS cnt FROM twitch_alerts");
$count = $countResult->fetch_assoc()['cnt'];
if ($count == 0) {
    $insertStmt = $db->prepare("INSERT INTO twitch_alerts (alert_category, variant_name, variant_index, alert_condition, message_template) VALUES (?, ?, ?, ?, ?)");
    foreach ($defaultAlerts as $alert) {
        $insertStmt->bind_param('ssiss', $alert[0], $alert[1], $alert[2], $alert[3], $alert[4]);
        $insertStmt->execute();
    }
    $insertStmt->close();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    if ($action === 'save_alert') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid alert ID.']);
            exit;
        }
        $stmt = $db->prepare("UPDATE twitch_alerts SET
            variant_name = ?, enabled = ?, alert_condition = ?,
            duration = ?, animation_in = ?, animation_out = ?,
            animation_in_duration = ?, animation_out_duration = ?,
            layout_preset = ?, bg_color = ?, bg_opacity = ?,
            padding = ?, gap = ?, rounded_corners = ?, drop_shadow = ?,
            message_template = ?, font_family = ?, font_weight = ?, font_size = ?,
            text_alignment = ?, text_vertical_alignment = ?,
            text_color = ?, accent_color = ?, text_drop_shadow = ?, tts_enabled = ?,
            alert_image = ?, image_scale = ?, image_volume = ?,
            alert_sound = ?, sound_volume = ?,
            celebration_enabled = ?, celebration_effect = ?, celebration_intensity = ?, celebration_area = ?,
            screen_position = ?, position_x = ?, position_y = ?
            WHERE id = ?");
        $variant_name = $_POST['variant_name'] ?? '';
        $enabled = intval($_POST['enabled'] ?? 1);
        $alert_condition = $_POST['alert_condition'] ?? null;
        $duration = intval($_POST['duration'] ?? 8);
        $animation_in = $_POST['animation_in'] ?? 'fadeIn';
        $animation_out = $_POST['animation_out'] ?? 'fadeOut';
        $animation_in_duration = floatval($_POST['animation_in_duration'] ?? 1.0);
        $animation_out_duration = floatval($_POST['animation_out_duration'] ?? 1.0);
        $layout_preset = $_POST['layout_preset'] ?? 'above';
        $bg_color = $_POST['bg_color'] ?? '#FFFFFF';
        $bg_opacity = intval($_POST['bg_opacity'] ?? 0);
        $padding = intval($_POST['padding'] ?? 16);
        $gap = intval($_POST['gap'] ?? 16);
        $rounded_corners = intval($_POST['rounded_corners'] ?? 1);
        $drop_shadow = intval($_POST['drop_shadow'] ?? 1);
        $message_template = $_POST['message_template'] ?? '';
        $font_family = $_POST['font_family'] ?? 'Roboto';
        $font_weight = $_POST['font_weight'] ?? 'Semi-Bold';
        $font_size = intval($_POST['font_size'] ?? 24);
        $text_alignment = $_POST['text_alignment'] ?? 'center';
        $text_vertical_alignment = $_POST['text_vertical_alignment'] ?? 'center';
        $text_color = $_POST['text_color'] ?? '#FFFFFF';
        $accent_color = $_POST['accent_color'] ?? '#7C5CBF';
        $text_drop_shadow = intval($_POST['text_drop_shadow'] ?? 1);
        $tts_enabled = intval($_POST['tts_enabled'] ?? 0);
        $alert_image = $_POST['alert_image'] ?? null;
        $image_scale = intval($_POST['image_scale'] ?? 100);
        $image_volume = intval($_POST['image_volume'] ?? 0);
        $alert_sound = $_POST['alert_sound'] ?? null;
        $sound_volume = intval($_POST['sound_volume'] ?? 50);
        $celebration_enabled = intval($_POST['celebration_enabled'] ?? 0);
        $celebration_effect = $_POST['celebration_effect'] ?? 'fireworks';
        $celebration_intensity = $_POST['celebration_intensity'] ?? 'light';
        $celebration_area = $_POST['celebration_area'] ?? 'full';
        $screen_position = $_POST['screen_position'] ?? null;
        // Drag-to-place coordinates: box top-left as a 0-100 percentage of the 16:9
        // canvas. Clamped here so a bad payload can't push a box off-screen.
        $position_x = max(0, min(100, floatval($_POST['position_x'] ?? 0)));
        $position_y = max(0, min(100, floatval($_POST['position_y'] ?? 0)));
        // Type string realigned to the real column types: 'd' for the two DECIMAL
        // animation durations (previously 'i', which truncated fractional seconds),
        // 'screen_position' (s) plus the two DECIMAL drag coordinates (d, d) appended
        // before the trailing WHERE id (i).
        $stmt->bind_param('sisissddssiiiiisssissssiisiisiissssddi',
            $variant_name, $enabled, $alert_condition,
            $duration, $animation_in, $animation_out,
            $animation_in_duration, $animation_out_duration,
            $layout_preset, $bg_color, $bg_opacity,
            $padding, $gap, $rounded_corners, $drop_shadow,
            $message_template, $font_family, $font_weight, $font_size,
            $text_alignment, $text_vertical_alignment,
            $text_color, $accent_color, $text_drop_shadow, $tts_enabled,
            $alert_image, $image_scale, $image_volume,
            $alert_sound, $sound_volume,
            $celebration_enabled, $celebration_effect, $celebration_intensity, $celebration_area,
            $screen_position, $position_x, $position_y,
            $id
        );
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Variant saved.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    }
    if ($action === 'toggle_alert') {
        $id = intval($_POST['id'] ?? 0);
        $enabled = intval($_POST['enabled'] ?? 0);
        $stmt = $db->prepare("UPDATE twitch_alerts SET enabled = ? WHERE id = ?");
        $stmt->bind_param('ii', $enabled, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'create_variant') {
        $category = $_POST['category'] ?? '';
        if ($category === '') {
            echo json_encode(['success' => false, 'message' => 'Category required.']);
            exit;
        }
        if (isset($variantLimits[$category])) {
            $capStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM twitch_alerts WHERE alert_category = ?");
            $capStmt->bind_param('s', $category);
            $capStmt->execute();
            $capCnt = (int)$capStmt->get_result()->fetch_assoc()['cnt'];
            $capStmt->close();
            if ($capCnt >= $variantLimits[$category]) {
                echo json_encode(['success' => false, 'message' => 'This category only supports ' . $variantLimits[$category] . ' variant' . ($variantLimits[$category] === 1 ? '' : 's') . '.']);
                exit;
            }
        }
        // Pick a name that doesn't collide with siblings
        $base = 'New variant';
        $name = $base;
        $existingStmt = $db->prepare("SELECT variant_name FROM twitch_alerts WHERE alert_category = ?");
        $existingStmt->bind_param('s', $category);
        $existingStmt->execute();
        $existingNames = [];
        $res = $existingStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existingNames[$row['variant_name']] = true;
        }
        $existingStmt->close();
        $i = 2;
        while (isset($existingNames[$name])) {
            $name = "$base $i";
            $i++;
        }
        // Next variant_index for this category
        $idxRes = $db->query("SELECT COALESCE(MAX(variant_index), -1) + 1 AS next_idx FROM twitch_alerts WHERE alert_category = " . "'" . $db->real_escape_string($category) . "'");
        $nextIdx = (int)$idxRes->fetch_assoc()['next_idx'];
        $insStmt = $db->prepare("INSERT INTO twitch_alerts (alert_category, variant_name, variant_index, message_template) VALUES (?, ?, ?, ?)");
        $tpl = "{username}\nfired this alert!";
        $insStmt->bind_param('ssis', $category, $name, $nextIdx, $tpl);
        if ($insStmt->execute()) {
            $newId = $insStmt->insert_id;
            $insStmt->close();
            $row = $db->query("SELECT * FROM twitch_alerts WHERE id = $newId")->fetch_assoc();
            echo json_encode(['success' => true, 'variant' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create variant: ' . $insStmt->error]);
            $insStmt->close();
        }
        exit;
    }
    if ($action === 'delete_variant') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid variant ID.']);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM twitch_alerts WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Variant deleted.']);
        exit;
    }
    if ($action === 'set_category_randomize') {
        $category = $_POST['category'] ?? '';
        $randomize = intval($_POST['randomize'] ?? 0) ? 1 : 0;
        if ($category === '') {
            echo json_encode(['success' => false, 'message' => 'Category required.']);
            exit;
        }
        $stmt = $db->prepare("INSERT INTO twitch_alert_category_settings (category, randomize) VALUES (?, ?) ON DUPLICATE KEY UPDATE randomize = VALUES(randomize)");
        $stmt->bind_param('si', $category, $randomize);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'upload_alert_media') {
        include __DIR__ . '/includes/storage_used.php';
        if (!isset($_FILES['media_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
            exit;
        }
        $file = $_FILES['media_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload failed.']);
            exit;
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid upload.']);
            exit;
        }
        $allowedExts = ['webm', 'gif', 'png', 'jpg', 'jpeg', 'mp3'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExts)]);
            exit;
        }
        if (!upload_validate_extension_and_mime($file['tmp_name'], $ext, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'File contents do not match the declared file type.']);
            exit;
        }
        $remaining = $max_storage_size - $current_storage_used;
        if ($file['size'] > $remaining) {
            echo json_encode(['success' => false, 'message' => 'Storage limit exceeded.']);
            exit;
        }
        if (!is_dir($media_path)) {
            mkdir($media_path, 0755, true);
        }
        $safeName = upload_sanitize_filename($file['name'], $ext);
        $target = upload_unique_target($media_path, $safeName);
        if (move_uploaded_file($file['tmp_name'], $target['path'])) {
            echo json_encode(['success' => true, 'filename' => $target['name'], 'message' => 'File uploaded.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed.']);
        }
        exit;
    }
    if ($action === 'remove_alert_media') {
        $id = intval($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        if (!in_array($field, ['alert_image', 'alert_sound'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid field.']);
            exit;
        }
        $stmt = $db->prepare("UPDATE twitch_alerts SET $field = NULL WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Media removed from variant.']);
        exit;
    }
    if ($action === 'set_alert_position') {
        // Drag-to-place coordinates: the box top-left as a 0-100 percentage of the
        // 16:9 canvas. Replaces the old 9-preset string. Clamped server-side.
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid position.']);
            exit;
        }
        $position_x = max(0, min(100, floatval($_POST['x'] ?? 0)));
        $position_y = max(0, min(100, floatval($_POST['y'] ?? 0)));
        $stmt = $db->prepare("UPDATE twitch_alerts SET position_x = ?, position_y = ? WHERE id = ?");
        $stmt->bind_param('ddi', $position_x, $position_y, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

include '/var/www/config/twitch.php';
include 'includes/bot_control.php';
include "includes/mod_access.php";
include 'includes/storage_used.php';

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Enable/disable-only categories (weather, deaths, walk-ons) render through their own
// overlay theme. Users seeded before these categories existed won't have rows, so make
// sure a single on/off variant exists for each.
$simpleCategorySeeds = ['weather' => 'Weather', 'deaths' => 'Death counter', 'walkons' => 'Walk-ons'];
foreach ($simpleCategorySeeds as $simpleCat => $simpleLabel) {
    $chk = $db->prepare("SELECT COUNT(*) AS cnt FROM twitch_alerts WHERE alert_category = ?");
    $chk->bind_param('s', $simpleCat);
    $chk->execute();
    $chkExists = (int)$chk->get_result()->fetch_assoc()['cnt'];
    $chk->close();
    if ($chkExists === 0) {
        $ins = $db->prepare("INSERT INTO twitch_alerts (alert_category, variant_name, variant_index) VALUES (?, ?, 0)");
        $ins->bind_param('ss', $simpleCat, $simpleLabel);
        $ins->execute();
        $ins->close();
    }
}

# Data load for page render
$allAlerts = [];
$alertsByCategory = [];
$result = $db->query("SELECT * FROM twitch_alerts ORDER BY alert_category, variant_index");
while ($row = $result->fetch_assoc()) {
    $allAlerts[$row['id']] = $row;
    $alertsByCategory[$row['alert_category']][] = $row;
}
$alertsJson = json_encode($allAlerts);

// Per-category randomize flags
$categoryRandomize = [];
if ($r = $db->query("SELECT category, randomize FROM twitch_alert_category_settings")) {
    while ($row = $r->fetch_assoc()) {
        $categoryRandomize[$row['category']] = (int)$row['randomize'];
    }
    $r->free();
}

$categoryMeta = [
    'follow'            => ['icon' => 'fas fa-heart', 'label' => 'Follows'],
    'subscription'      => ['icon' => 'fas fa-star', 'label' => 'Subscriptions'],
    'gift_subscription' => ['icon' => 'fas fa-gift', 'label' => 'Gifted subs'],
    'bits'              => ['icon' => 'fas fa-gem', 'label' => 'Bits'],
    'raid'              => ['icon' => 'fas fa-bullhorn', 'label' => 'Raids'],
    'hype_train'        => ['icon' => 'fas fa-train', 'label' => 'Hype trains'],
    'charity'           => ['icon' => 'fas fa-hand-holding-heart', 'label' => 'Charity'],
    'channel_points'    => ['icon' => 'fas fa-circle-dot', 'label' => 'Channel points'],
    // BotOfTheSpecter-specific integration categories
    'discord_join'      => ['icon' => 'fab fa-discord', 'label' => 'Discord joins'],
    'kofi'              => ['icon' => 'fas fa-mug-hot', 'label' => 'Ko-fi tips'],
    'patreon'           => ['icon' => 'fab fa-patreon', 'label' => 'Patreon'],
    'fourthwall'        => ['icon' => 'fas fa-store', 'label' => 'Fourthwall'],
    'subathon'          => ['icon' => 'fas fa-hourglass-half', 'label' => 'Subathon'],
    'stream_bingo'      => ['icon' => 'fas fa-trophy', 'label' => 'Stream bingo'],
    'watch_streak'      => ['icon' => 'fas fa-fire', 'label' => 'Watch streaks'],
    // Enable/disable-only categories (render through their own overlay theme)
    'weather'           => ['icon' => 'fas fa-cloud-sun', 'label' => 'Weather'],
    'deaths'            => ['icon' => 'fas fa-skull', 'label' => 'Death counter'],
    'walkons'           => ['icon' => 'fas fa-door-open', 'label' => 'Walk-ons'],
];

// Animation options
$animationsIn = ['Fade in' => 'fadeIn', 'Slide left' => 'slideInLeft', 'Slide right' => 'slideInRight', 'Slide up' => 'slideInUp', 'Slide down' => 'slideInDown', 'Bounce' => 'bounceIn', 'Zoom' => 'zoomIn'];
$animationsOut = ['Fade out' => 'fadeOut', 'Slide left' => 'slideOutLeft', 'Slide right' => 'slideOutRight', 'Slide up' => 'slideOutUp', 'Slide down' => 'slideOutDown', 'Bounce' => 'bounceOut', 'Zoom' => 'zoomOut'];

// Font options
$fonts = ['Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Oswald', 'Raleway', 'Poppins', 'Nunito', 'Ubuntu', 'Bebas Neue', 'Bangers', 'Permanent Marker', 'Press Start 2P', 'Creepster'];
$fontWeights = ['Light' => '300', 'Regular' => '400', 'Medium' => '500', 'Semi-Bold' => '600', 'Bold' => '700', 'Extra-Bold' => '800'];

// Media base URL for preview thumbnails inside this configurator
$mediaBase = "https://media.botofthespecter.com/$username/";

$browserSourceUrl = "https://overlay.botofthespecter.com/?code=" . urlencode($api_key);
$totalVariants = count($allAlerts);

// Library files exposed to the picker — only types this builder can use
$libraryImageExts = ['png', 'jpg', 'jpeg', 'gif', 'webm'];
$librarySoundExts = ['mp3'];
$libraryImages = [];
$librarySounds = [];
if (is_dir($media_path)) {
    foreach (scandir($media_path) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!is_file($media_path . '/' . $f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $libraryImageExts, true)) $libraryImages[] = $f;
        elseif (in_array($ext, $librarySoundExts, true)) $librarySounds[] = $f;
    }
    sort($libraryImages, SORT_STRING | SORT_FLAG_CASE);
    sort($librarySounds, SORT_STRING | SORT_FLAG_CASE);
}

ob_start();
?>
<div class="alerts-page-shell">
    <div class="sp-alert sp-alert-info alerts-media-notice">
        <i class="fas fa-photo-film"></i>
        <span><?= t('alerts_media_notice') ?></span>
    </div>
    <!-- Top header bar — title, counter, save/discard -->
    <header class="alerts-top-bar">
        <div class="alerts-top-bar-left">
            <h1 class="alerts-page-title"><?= t('alerts_page_title') ?></h1>
            <span class="alerts-variant-counter">
                <strong id="alerts-variant-count"><?php echo $totalVariants; ?></strong> <?= t('alerts_variants_word') ?>
            </span>
        </div>
        <div class="alerts-top-bar-right">
            <button class="sp-btn sp-btn-ghost" id="alerts-discard-btn" disabled>
                <i class="fas fa-rotate-left"></i> <?= t('alerts_discard_btn') ?>
            </button>
            <button class="sp-btn sp-btn-primary" id="alerts-save-btn" disabled>
                <i class="fas fa-save"></i> <?= t('alerts_save_changes_btn') ?>
            </button>
        </div>
    </header>
    <!-- Three-column shell: variants | preview | settings -->
    <div class="alerts-shell">
        <!-- LEFT: variants sidebar -->
        <aside class="alerts-sidebar">
            <div class="alerts-sidebar-header">
                <span class="alerts-sidebar-label"><?= t('alerts_sidebar_variants') ?></span>
                <div class="alerts-sidebar-tools">
                    <a href="#" class="alerts-edit-multiple" id="alerts-edit-multiple-link"><?= t('alerts_edit_multiple') ?></a>
                </div>
            </div>
            <div class="alerts-categories-scroll">
                <?php foreach ($categoryMeta as $category => $meta):
                    $variants = $alertsByCategory[$category] ?? [];
                    $randomize = $categoryRandomize[$category] ?? 0;
                    $catLimit = $variantLimits[$category] ?? null;
                    $canAddVariant = ($catLimit === null) || (count($variants) < $catLimit);
                    $showRandomize = ($catLimit === null || $catLimit > 1);
                    $showControls = $showRandomize || $canAddVariant;
                ?>
                <section class="alerts-category" data-category="<?php echo htmlspecialchars($category); ?>">
                    <header class="alerts-category-header">
                        <span class="alerts-category-icon"><i class="<?php echo $meta['icon']; ?>"></i></span>
                        <span class="alerts-category-name"><?php echo htmlspecialchars(t('alerts_cat_' . $category)); ?></span>
                        <span class="alerts-category-count"><?php echo count($variants); ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-category-body">
                        <div class="alerts-category-controls"<?php echo $showControls ? '' : ' style="display:none;"'; ?>>
                            <?php if ($showRandomize): ?>
                            <label class="alerts-mini-toggle">
                                <input type="checkbox" class="alerts-randomize-toggle" data-category="<?php echo htmlspecialchars($category); ?>" <?php echo $randomize ? 'checked' : ''; ?>>
                                <span class="alerts-mini-toggle-slider"></span>
                                <span class="alerts-mini-toggle-text"><?= t('alerts_randomize') ?></span>
                            </label>
                            <?php endif; ?>
                            <button type="button" class="alerts-new-variant-btn" data-category="<?php echo htmlspecialchars($category); ?>" title="<?= htmlspecialchars(t('alerts_new_variant_title')) ?>"<?php echo $canAddVariant ? '' : ' style="display:none;"'; ?>>
                                <i class="fas fa-plus"></i> <?= t('alerts_new_variant_btn') ?>
                            </button>
                        </div>
                        <ul class="alerts-variant-list">
                            <?php foreach ($variants as $variant): ?>
                            <li class="alerts-variant-item" data-id="<?php echo $variant['id']; ?>">
                                <span class="alerts-variant-handle" title="<?= htmlspecialchars(t('alerts_drag_reorder')) ?>"><i class="fas fa-grip-vertical"></i></span>
                                <span class="alerts-variant-priority"><?php echo $variant['variant_index'] + 1; ?></span>
                                <div class="alerts-variant-info">
                                    <div class="variant-name"><?php echo htmlspecialchars($variant['variant_name']); ?></div>
                                    <?php if ($variant['alert_condition']): ?>
                                    <div class="variant-condition"><?php echo htmlspecialchars($variant['alert_condition']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <label class="alerts-mini-toggle" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="alerts-variant-enabled-toggle" data-id="<?php echo $variant['id']; ?>" <?php echo $variant['enabled'] ? 'checked' : ''; ?>>
                                    <span class="alerts-mini-toggle-slider"></span>
                                </label>
                            </li>
                            <?php endforeach; ?>
                            <?php if (empty($variants)): ?>
                            <li class="alerts-variant-empty"><?= t('alerts_no_variants_yet') ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
        </aside>
        <!-- CENTER: preview panel -->
        <div class="alerts-preview-panel">
            <div class="alerts-preview-actions">
                <button class="sp-btn sp-btn-secondary" id="preview-alert-btn" disabled>
                    <i class="fas fa-eye"></i> <?= t('alerts_replay_preview') ?>
                </button>
                <button class="sp-btn sp-btn-primary" id="test-alert-btn" disabled>
                    <i class="fas fa-paper-plane"></i> <?= t('alerts_trigger_live_test') ?>
                </button>
            </div>
            <div class="alerts-preview-area alerts-pos-canvas" id="preview-area">
                <div class="alerts-no-selection" id="preview-placeholder">
                    <?= t('alerts_preview_placeholder') ?>
                </div>
                <div class="alerts-preview-box alerts-pos-draggable" id="preview-box" style="display:none;">
                    <div class="alerts-preview-content" id="preview-content">
                        <img src="" alt="" class="preview-image" id="preview-img" style="display:none;">
                        <div class="preview-text" id="preview-text"></div>
                    </div>
                </div>
                <!-- Sample preview for the legacy-themed categories (weather, deaths) -->
                <div class="alerts-preview-legacy alerts-pos-draggable" id="preview-legacy" style="display:none;"></div>
                <!-- Representative walk-on card; drag target for the walk-ons category -->
                <div class="alerts-preview-walkon alerts-pos-draggable" id="preview-walkon-sample" style="display:none;">
                    <div class="apwk-avatar"></div>
                    <div class="apwk-name">DisplayName</div>
                </div>
                <!-- Snap guides + expand-close, children of the canvas -->
                <div class="alerts-pos-guide alerts-pos-guide-v" id="alerts-pos-guide-v"></div>
                <div class="alerts-pos-guide alerts-pos-guide-h" id="alerts-pos-guide-h"></div>
                <button type="button" class="alerts-pos-expand-close" id="alerts-pos-expand-close" title="<?= htmlspecialchars(t('alerts_collapse_editor')) ?>" aria-label="<?= htmlspecialchars(t('alerts_collapse_editor')) ?>">&times;</button>
            </div>
            <div class="alerts-pos-backdrop" id="alerts-pos-backdrop"></div>
            <div class="alerts-preview-options">
                <span class="alerts-preview-options-label"><?= t('alerts_preview_canvas') ?></span>
                <label class="alerts-mini-toggle">
                    <input type="checkbox" id="preview-autoplay" checked>
                    <span class="alerts-mini-toggle-slider"></span>
                    <span class="alerts-mini-toggle-text"><?= t('alerts_autoplay') ?></span>
                </label>
                <select id="alerts-canvas-size" class="sp-input" title="<?= htmlspecialchars(t('makers_canvas_range')) ?>">
                    <option value="1280x720">1280 &times; 720 (720p)</option>
                    <option value="1920x1080" selected>1920 &times; 1080 (1080p)</option>
                    <option value="2560x1440">2560 &times; 1440 (2K)</option>
                </select>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="alerts-pos-expand-btn" title="<?= htmlspecialchars(t('alerts_expand_editor')) ?>">
                    <i class="fas fa-expand"></i> <?= t('alerts_expand_editor') ?>
                </button>
                <div class="alerts-bg-swatches">
                    <button type="button" class="alerts-bg-swatch active" data-bg="transparent" title="<?= htmlspecialchars(t('alerts_bg_transparent')) ?>"></button>
                    <button type="button" class="alerts-bg-swatch" data-bg="dark" title="<?= htmlspecialchars(t('alerts_bg_dark')) ?>"></button>
                    <button type="button" class="alerts-bg-swatch" data-bg="light" title="<?= htmlspecialchars(t('alerts_bg_light')) ?>"></button>
                    <button type="button" class="alerts-bg-swatch" data-bg="red" title="<?= htmlspecialchars(t('alerts_bg_red')) ?>"></button>
                </div>
            </div>
        </div>
        <!-- RIGHT: settings panel -->
        <div class="alerts-settings-panel" id="settings-panel">
            <div class="alerts-no-selection" id="settings-placeholder">
                <?= t('alerts_settings_placeholder') ?>
            </div>
            <!-- Enable/disable-only categories (weather, deaths, walk-ons): they keep
                 their own overlay theme, so the only control here is on/off. -->
            <div id="simple-settings" style="display:none;">
                <section class="alerts-settings-section open">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-toggle-on"></i>
                        <span><?= t('alerts_section_general') ?></span>
                    </header>
                    <div class="alerts-settings-section-body">
                        <p class="alerts-help-text" id="simple-note-positioned"><?= t('alerts_simple_note') ?></p>
                        <p class="alerts-help-text" id="simple-note-walkon" style="display:none;"><?= t('alerts_walkon_note') ?></p>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label><?= t('alerts_enabled') ?></label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-simple-enabled">
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group" id="simple-position-group">
                            <label><?= t('alerts_screen_position') ?></label>
                            <p class="alerts-help-text"><i class="fas fa-up-down-left-right"></i> <?= t('alerts_drag_hint') ?></p>
                            <p class="alerts-help-text"><i class="fas fa-magnet"></i> <?= t('alerts_snap_hint') ?></p>
                        </div>
                    </div>
                </section>
            </div>
            <div id="settings-form" style="display:none;">
                <!-- General Settings -->
                <section class="alerts-settings-section open">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-sliders"></i>
                        <span><?= t('alerts_section_general') ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group" id="variant-name-group">
                            <label><?= t('alerts_variant_name') ?></label>
                            <input type="text" class="sp-input" id="set-variant-name">
                        </div>
                        <div class="alerts-form-group" id="variant-reward-group" style="display:none;">
                            <label><?= t('alerts_channel_point_reward') ?></label>
                            <select class="sp-select" id="set-reward-id">
                                <option value=""><?= t('alerts_select_a_reward') ?></option>
                            </select>
                            <small class="alerts-help-text"><?= t('alerts_reward_help') ?></small>
                        </div>
                        <div class="alerts-form-group" id="variant-bingo-group" style="display:none;">
                            <label><?= t('alerts_bingo_event') ?></label>
                            <select class="sp-select" id="set-bingo-event">
                                <option value=""><?= t('alerts_select_a_bingo_event') ?></option>
                                <?php foreach ($bingoSubtypes as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars(t('alerts_bingo_subtype_' . strtolower($key))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="alerts-help-text"><?= t('alerts_bingo_help') ?></small>
                        </div>
                        <div class="alerts-form-group" id="variant-condition-group">
                            <label><?= t('alerts_condition_label') ?> <span class="alerts-help"><?= t('alerts_condition_advanced') ?></span></label>
                            <input type="text" class="sp-input" id="set-alert-condition" placeholder="<?= htmlspecialchars(t('alerts_condition_placeholder')) ?>">
                            <small class="alerts-help-text"><?= t('alerts_condition_help') ?></small>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_onscreen_duration') ?></label>
                            <input type="number" class="sp-input" id="set-duration" min="1" max="99" value="8">
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label><?= t('alerts_in_animation') ?></label>
                                <div class="alerts-inline-pair">
                                    <select class="sp-select" id="set-animation-in">
                                        <?php foreach ($animationsIn as $label => $val): ?>
                                        <option value="<?php echo $val; ?>"><?php echo htmlspecialchars(t('alerts_anim_' . strtolower($val))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="sp-input alerts-inline-pair-mini" id="set-animation-in-duration" min="0.1" max="5" step="0.1" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label><?= t('alerts_out_animation') ?></label>
                                <div class="alerts-inline-pair">
                                    <select class="sp-select" id="set-animation-out">
                                        <?php foreach ($animationsOut as $label => $val): ?>
                                        <option value="<?php echo $val; ?>"><?php echo htmlspecialchars(t('alerts_anim_' . strtolower($val))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="sp-input alerts-inline-pair-mini" id="set-animation-out-duration" min="0.1" max="5" step="0.1" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label><?= t('alerts_enabled') ?></label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-enabled" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>
                <!-- Layout -->
                <section class="alerts-settings-section">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-th-large"></i>
                        <span><?= t('alerts_section_layout') ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label><?= t('alerts_screen_position') ?></label>
                            <p class="alerts-help-text"><i class="fas fa-up-down-left-right"></i> <?= t('alerts_drag_hint') ?></p>
                            <p class="alerts-help-text"><i class="fas fa-magnet"></i> <?= t('alerts_snap_hint') ?></p>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_image_position') ?></label>
                            <div class="layout-presets">
                                <button type="button" class="layout-preset-btn active" data-layout="above" title="<?= htmlspecialchars(t('alerts_layout_above')) ?>">
                                    <svg viewBox="0 0 48 36"><rect x="16" y="2" width="16" height="12" rx="2" fill="currentColor"/><rect x="8" y="18" width="32" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="24" width="24" height="3" rx="1" fill="currentColor" opacity="0.4"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="right" title="<?= htmlspecialchars(t('alerts_layout_right')) ?>">
                                    <svg viewBox="0 0 48 36"><rect x="30" y="6" width="14" height="24" rx="2" fill="currentColor"/><rect x="4" y="10" width="22" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="4" y="17" width="18" height="3" rx="1" fill="currentColor" opacity="0.4"/><rect x="4" y="24" width="20" height="3" rx="1" fill="currentColor" opacity="0.4"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="left" title="<?= htmlspecialchars(t('alerts_layout_left')) ?>">
                                    <svg viewBox="0 0 48 36"><rect x="4" y="6" width="14" height="24" rx="2" fill="currentColor"/><rect x="22" y="10" width="22" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="22" y="17" width="18" height="3" rx="1" fill="currentColor" opacity="0.4"/><rect x="22" y="24" width="20" height="3" rx="1" fill="currentColor" opacity="0.4"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="below" title="<?= htmlspecialchars(t('alerts_layout_below')) ?>">
                                    <svg viewBox="0 0 48 36"><rect x="8" y="2" width="32" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="8" width="24" height="3" rx="1" fill="currentColor" opacity="0.4"/><rect x="16" y="16" width="16" height="16" rx="2" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="behind" title="<?= htmlspecialchars(t('alerts_layout_behind')) ?>">
                                    <svg viewBox="0 0 48 36"><rect x="2" y="2" width="44" height="32" rx="2" fill="currentColor" opacity="0.5"/><rect x="8" y="12" width="32" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="18" width="24" height="3" rx="1" fill="currentColor" opacity="0.6"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label><?= t('alerts_background') ?></label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-bg-color" value="#FFFFFF">
                                    <input type="text" class="sp-input" id="set-bg-color-text" value="#FFFFFF">
                                </div>
                            </div>
                            <div class="alerts-form-group">
                                <label><?= t('alerts_opacity') ?></label>
                                <input type="number" class="sp-input" id="set-bg-opacity" min="0" max="100" value="0">
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label><?= t('alerts_padding') ?></label>
                                <input type="number" class="sp-input" id="set-padding" min="0" max="100" value="16">
                            </div>
                            <div class="alerts-form-group">
                                <label><?= t('alerts_gap') ?></label>
                                <input type="number" class="sp-input" id="set-gap" min="0" max="100" value="16">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label><?= t('alerts_rounded_corners') ?></label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-rounded-corners" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label><?= t('alerts_drop_shadow') ?></label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-drop-shadow" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>
                <!-- Text & Speech -->
                <section class="alerts-settings-section">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-font"></i>
                        <span><?= t('alerts_section_text_speech') ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label><?= t('alerts_message_template') ?></label>
                            <textarea id="set-message-template" placeholder="<?= t('alerts_message_template_placeholder') ?>"></textarea>
                            <div class="variable-hints" id="variable-hints">
                                <span><?= t('alerts_variables_label') ?></span>
                                <span id="variable-hints-list"></span>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_font') ?></label>
                            <div class="alerts-inline-pair">
                                <select class="sp-select" id="set-font-family">
                                    <?php foreach ($fonts as $font): ?>
                                    <option value="<?php echo $font; ?>"><?php echo $font; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="sp-select alerts-inline-pair-weight" id="set-font-weight">
                                    <?php foreach ($fontWeights as $label => $val): ?>
                                    <option value="<?php echo $label; ?>"><?php echo htmlspecialchars(t('alerts_weight_' . $val)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="sp-input alerts-inline-pair-mini" id="set-font-size" min="8" max="120" value="24">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_alignment') ?></label>
                            <div class="text-align-btns">
                                <button type="button" class="text-align-btn" data-align="left" title="<?= htmlspecialchars(t('alerts_align_left')) ?>"><i class="fas fa-align-left"></i></button>
                                <button type="button" class="text-align-btn active" data-align="center" title="<?= htmlspecialchars(t('alerts_align_center')) ?>"><i class="fas fa-align-center"></i></button>
                                <button type="button" class="text-align-btn" data-align="right" title="<?= htmlspecialchars(t('alerts_align_right')) ?>"><i class="fas fa-align-right"></i></button>
                                <button type="button" class="text-align-btn" data-align="justify" title="<?= htmlspecialchars(t('alerts_align_justify')) ?>"><i class="fas fa-align-justify"></i></button>
                                <span class="text-align-divider"></span>
                                <button type="button" class="text-align-btn valign-btn" data-valign="top" title="<?= htmlspecialchars(t('alerts_align_top')) ?>"><i class="fas fa-arrow-up"></i></button>
                                <button type="button" class="text-align-btn valign-btn active" data-valign="center" title="<?= htmlspecialchars(t('alerts_align_middle')) ?>"><i class="fas fa-arrows-up-down"></i></button>
                                <button type="button" class="text-align-btn valign-btn" data-valign="bottom" title="<?= htmlspecialchars(t('alerts_align_bottom')) ?>"><i class="fas fa-arrow-down"></i></button>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label><?= t('alerts_text_colour') ?></label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-text-color" value="#FFFFFF">
                                    <input type="text" class="sp-input" id="set-text-color-text" value="#FFFFFF">
                                </div>
                            </div>
                            <div class="alerts-form-group">
                                <label><?= t('alerts_accent_colour') ?></label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-accent-color" value="#7C5CBF">
                                    <input type="text" class="sp-input" id="set-accent-color-text" value="#7C5CBF">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label><?= t('alerts_text_drop_shadow') ?></label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-text-drop-shadow" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group alerts-form-group-divider">
                            <div class="alerts-toggle-wrap">
                                <div>
                                    <label class="alerts-mini-section-label"><?= t('alerts_tts_label') ?></label>
                                    <div class="alerts-help-text"><?= t('alerts_tts_help') ?></div>
                                </div>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-tts-enabled">
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>
                <!-- Visuals & Sound -->
                <section class="alerts-settings-section">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-image"></i>
                        <span><?= t('alerts_section_visuals_sound') ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label><?= t('alerts_alert_image') ?></label>
                            <div class="alerts-media-upload" id="image-upload-zone">
                                <div class="alerts-media-preview" id="image-preview"></div>
                                <div class="alerts-media-filename" id="image-filename"><?= t('alerts_no_image_selected') ?></div>
                                <div class="alerts-media-actions">
                                    <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="image-library-btn">
                                        <i class="fas fa-folder-open"></i> <?= t('alerts_browse_library') ?>
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="image-upload-btn">
                                        <i class="fas fa-upload"></i> <?= t('alerts_upload') ?>
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="image-remove-btn" style="display:none;">
                                        <i class="fas fa-times"></i> <?= t('alerts_remove') ?>
                                    </button>
                                </div>
                                <input type="file" id="image-file-input" accept=".webm,.gif,.png,.jpg,.jpeg">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_image_scale') ?></label>
                            <div class="alerts-range-wrap">
                                <input type="range" id="set-image-scale" min="0" max="200" value="100">
                                <span class="alerts-range-value" id="image-scale-val">100%</span>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_image_volume') ?></label>
                            <div class="alerts-range-wrap">
                                <input type="range" id="set-image-volume" min="0" max="100" value="0">
                                <span class="alerts-range-value" id="image-volume-val">0%</span>
                            </div>
                        </div>
                        <div class="alerts-form-group alerts-form-group-divider">
                            <label><?= t('alerts_alert_sound') ?></label>
                            <div class="alerts-media-upload" id="sound-upload-zone">
                                <div class="alerts-media-filename" id="sound-filename"><?= t('alerts_no_sound_selected') ?></div>
                                <div class="alerts-media-actions">
                                    <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="sound-library-btn">
                                        <i class="fas fa-folder-open"></i> <?= t('alerts_browse_library') ?>
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="sound-upload-btn">
                                        <i class="fas fa-upload"></i> <?= t('alerts_upload') ?>
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="sound-remove-btn" style="display:none;">
                                        <i class="fas fa-times"></i> <?= t('alerts_remove') ?>
                                    </button>
                                </div>
                                <input type="file" id="sound-file-input" accept=".mp3">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_sound_volume') ?></label>
                            <div class="alerts-range-wrap">
                                <i class="fas fa-volume-down alerts-range-icon"></i>
                                <input type="range" id="set-sound-volume" min="0" max="100" value="50">
                                <span class="alerts-range-value" id="sound-volume-val">50%</span>
                            </div>
                        </div>
                    </div>
                </section>
                <!-- Celebration -->
                <section class="alerts-settings-section">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-wand-magic-sparkles"></i>
                        <span><?= t('alerts_section_celebration') ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <div>
                                    <label class="alerts-mini-section-label"><?= t('alerts_particle_effect') ?></label>
                                    <div class="alerts-help-text"><?= t('alerts_particle_help') ?></div>
                                </div>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-celebration-enabled">
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_effect') ?></label>
                            <select class="sp-select" id="set-celebration-effect">
                                <option value="fireworks"><?= t('alerts_effect_fireworks') ?></option>
                                <option value="confetti"><?= t('alerts_effect_confetti') ?></option>
                                <option value="bubbles"><?= t('alerts_effect_bubbles') ?></option>
                            </select>
                        </div>
                        <div class="alerts-form-group">
                            <label><?= t('alerts_intensity') ?></label>
                            <select class="sp-select" id="set-celebration-intensity">
                                <option value="light"><?= t('alerts_intensity_light') ?></option>
                                <option value="medium"><?= t('alerts_intensity_medium') ?></option>
                                <option value="heavy"><?= t('alerts_intensity_heavy') ?></option>
                            </select>
                        </div>
                    </div>
                </section>
                <!-- Danger zone: delete variant -->
                <section class="alerts-settings-section alerts-settings-danger">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-trash"></i>
                        <span><?= t('alerts_delete_variant') ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <p class="alerts-help-text"><?= t('alerts_delete_variant_help') ?></p>
                        <button type="button" class="sp-btn sp-btn-danger" id="delete-variant-btn">
                            <i class="fas fa-trash"></i> <?= t('alerts_delete_this_variant') ?>
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <!-- Footer: priority hint + browser source URL with copy -->
    <footer class="alerts-footer">
        <div class="alerts-footer-left">
            <i class="fas fa-info-circle"></i>
            <?= t('alerts_footer_priority_hint') ?>
        </div>
        <div class="alerts-browser-source">
            <span class="alerts-browser-source-label"><?= t('alerts_obs_browser_source') ?></span>
            <input type="password" class="sp-input alerts-browser-source-url" id="alerts-browser-source-url" readonly value="<?php echo htmlspecialchars($browserSourceUrl); ?>" title="<?= htmlspecialchars(t('alerts_click_to_reveal')) ?>">
            <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="alerts-copy-url-btn">
                <i class="fas fa-copy"></i> <?= t('alerts_copy') ?>
            </button>
        </div>
    </footer>
</div>
<!-- Media library picker — used by both image and sound "Browse library" buttons -->
<div class="sp-modal-backdrop" id="alerts-library-modal" style="display:none;">
    <div class="sp-modal alerts-library-modal-card" role="dialog" aria-modal="true" aria-labelledby="alerts-library-modal-title">
        <header class="sp-modal-head">
            <span class="sp-modal-title" id="alerts-library-modal-title"><?= t('alerts_library_modal_title') ?></span>
            <button type="button" class="sp-modal-close" id="alerts-library-modal-close" aria-label="<?= htmlspecialchars(t('alerts_close')) ?>">&times;</button>
        </header>
        <div class="sp-modal-body">
            <p class="alerts-help-text"><?= t('alerts_library_modal_help') ?></p>
            <input type="search" class="sp-input alerts-library-search" id="alerts-library-search" placeholder="<?= htmlspecialchars(t('alerts_search_files')) ?>">
            <div class="alerts-library-grid" id="alerts-library-grid"></div>
            <div class="alerts-library-empty" id="alerts-library-empty" style="display:none;">
                <?= t('alerts_library_empty') ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
$(document).ready(function() {
    const alertsData = <?php echo $alertsJson; ?>;
    const mediaBase = <?php echo json_encode($mediaBase); ?>;
    const apiKey = <?php echo json_encode($api_key); ?>;
    const channelName = <?php echo json_encode($username); ?>;
    const libraryImages = <?php echo json_encode($libraryImages); ?>;
    const librarySounds = <?php echo json_encode($librarySounds); ?>;
    const channelPointRewards = <?php echo json_encode(array_values($channelPointRewards)); ?>;
    const bingoSubtypes = <?php echo json_encode($bingoSubtypes); ?>;
    const variantLimits = <?php echo json_encode($variantLimits); ?>;
    // Translated UI strings injected from PHP so JS never carries English literals.
    const i18n = {
        noRewardsSynced: <?php echo json_encode(t('alerts_no_rewards_synced')); ?>,
        unknownReward: <?php echo json_encode(t('alerts_unknown_reward')); ?>,
        unsavedSwitchConfirm: <?php echo json_encode(t('alerts_unsaved_switch_confirm')); ?>,
        previewUser: <?php echo json_encode(t('alerts_preview_user')); ?>,
        alertImageAlt: <?php echo json_encode(t('alerts_alert_image')); ?>,
        noImageSelected: <?php echo json_encode(t('alerts_no_image_selected')); ?>,
        noSoundSelected: <?php echo json_encode(t('alerts_no_sound_selected')); ?>,
        savedTitle: <?php echo json_encode(t('alerts_saved_title')); ?>,
        errorTitle: <?php echo json_encode(t('alerts_error_title')); ?>,
        saveFailed: <?php echo json_encode(t('alerts_save_failed')); ?>,
        discardConfirmTitle: <?php echo json_encode(t('alerts_discard_confirm_title')); ?>,
        discardConfirmText: <?php echo json_encode(t('alerts_discard_confirm_text')); ?>,
        discardConfirmYes: <?php echo json_encode(t('alerts_discard_confirm_yes')); ?>,
        keepEditing: <?php echo json_encode(t('alerts_keep_editing')); ?>,
        adding: <?php echo json_encode(t('alerts_adding')); ?>,
        cannotAddTitle: <?php echo json_encode(t('alerts_cannot_add_title')); ?>,
        addFailed: <?php echo json_encode(t('alerts_add_failed')); ?>,
        dragReorder: <?php echo json_encode(t('alerts_drag_reorder')); ?>,
        noVariantsYet: <?php echo json_encode(t('alerts_no_variants_yet')); ?>,
        deleteConfirmTitle: <?php echo json_encode(t('alerts_delete_confirm_title')); ?>,
        deleteConfirmText: <?php echo json_encode(t('alerts_delete_confirm_text')); ?>,
        deleteConfirmYes: <?php echo json_encode(t('alerts_delete_confirm_yes')); ?>,
        cancel: <?php echo json_encode(t('alerts_cancel')); ?>,
        deleting: <?php echo json_encode(t('alerts_deleting')); ?>,
        deleteFailed: <?php echo json_encode(t('alerts_delete_failed')); ?>,
        uploadFailedTitle: <?php echo json_encode(t('alerts_upload_failed_title')); ?>,
        bingoPickTitle: <?php echo json_encode(t('alerts_bingo_pick_title')); ?>,
        bingoPickText: <?php echo json_encode(t('alerts_bingo_pick_text')); ?>,
        testUnavailableTitle: <?php echo json_encode(t('alerts_test_unavailable_title')); ?>,
        testUnavailableText: <?php echo json_encode(t('alerts_test_unavailable_text')); ?>,
        testSentTitle: <?php echo json_encode(t('alerts_test_sent_title')); ?>,
        testSentText: <?php echo json_encode(t('alerts_test_sent_text')); ?>,
        testSendFailed: <?php echo json_encode(t('alerts_test_send_failed')); ?>,
        copied: <?php echo json_encode(t('alerts_copied')); ?>,
        editMultipleTitle: <?php echo json_encode(t('alerts_edit_multiple_title')); ?>,
        editMultipleText: <?php echo json_encode(t('alerts_edit_multiple_text')); ?>,
        chooseImage: <?php echo json_encode(t('alerts_choose_image')); ?>,
        chooseSound: <?php echo json_encode(t('alerts_choose_sound')); ?>
    };
    // Enable/disable-only categories — selecting one shows just an on/off switch;
    // the alert renders through its existing overlay theme in overlay/index.php.
    const simpleCategories = ['weather', 'deaths', 'walkons'];
    // ---- Drag-to-place position editor -------------------------------------
    // A NULL screen_position (no saved x/y) falls back to the per-category default.
    const positionDefaults = { weather: 'left-top', deaths: 'left-bottom' };
    function defaultPositionFor(cat) { return positionDefaults[cat] || 'center-center'; }
    const canvasWidths = { '1280x720': 1280, '1920x1080': 1920, '2560x1440': 2560 };
    var previewScale = 1;
    function clampNum(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
    // Which draggable box represents the currently selected variant.
    function activeDragBox() {
        if (!currentAlertId) return null;
        var a = alertsData[currentAlertId];
        if (!a) return null;
        if (a.alert_category === 'walkons') return document.getElementById('preview-walkon-sample');
        if (a.alert_category === 'weather' || a.alert_category === 'deaths') return document.getElementById('preview-legacy');
        return document.getElementById('preview-box');
    }
    // True-to-scale: a box on a smaller OBS canvas is relatively larger, so scale the
    // visible box by 1920 / chosenWidth (matches the makers editor).
    function applyPreviewScale() {
        var sel = document.getElementById('alerts-canvas-size');
        var w = canvasWidths[sel ? sel.value : '1920x1080'] || 1920;
        previewScale = 1920 / w;
        ['preview-box', 'preview-legacy', 'preview-walkon-sample'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.style.transformOrigin = 'top left';
                el.style.transform = 'scale(' + previewScale + ')';
            }
        });
    }
    // Convert an old 9-preset string to a top-left percent, using the box's measured
    // (rendered) size and ~2.5% margins to echo the overlay's old 24px corner feel.
    function presetToPercent(preset, wpct, hpct) {
        var m = 2.5;
        var p = String(preset || 'center-center').split('-');
        var h = p[0], v = p[1];
        var x = (h === 'left') ? m : (h === 'right' ? 100 - wpct - m : (100 - wpct) / 2);
        var y = (v === 'top') ? m : (v === 'bottom' ? 100 - hpct - m : (100 - hpct) / 2);
        return { x: clampNum(x, 0, Math.max(0, 100 - wpct)), y: clampNum(y, 0, Math.max(0, 100 - hpct)) };
    }
    // Place a box at x/y percent (top-left), clamped to the canvas using the element's
    // RENDERED size so the right/bottom edges stay on screen. Returns the clamped pair.
    function placeBoxPercent(el, x, y) {
        var canvas = document.getElementById('preview-area');
        var cr = canvas.getBoundingClientRect();
        var br = el.getBoundingClientRect();
        var wpct = cr.width ? (br.width / cr.width) * 100 : 0;
        var hpct = cr.height ? (br.height / cr.height) * 100 : 0;
        var cx = clampNum(x, 0, Math.max(0, 100 - wpct));
        var cy = clampNum(y, 0, Math.max(0, 100 - hpct));
        el.style.left = cx + '%';
        el.style.top = cy + '%';
        return { x: parseFloat(cx.toFixed(2)), y: parseFloat(cy.toFixed(2)) };
    }
    // Seed the active box from saved x/y, else a converted screen_position, else the
    // category default. Writes the resolved percent back into the working state.
    function seedActiveBox() {
        if (!currentAlertId) return;
        var a = alertsData[currentAlertId];
        var el = activeDragBox();
        if (!el || el.style.display === 'none') return;
        var hasPos = a.position_x !== null && a.position_x !== undefined && a.position_x !== '' &&
                     a.position_y !== null && a.position_y !== undefined && a.position_y !== '';
        var x, y;
        if (hasPos) {
            x = clampNum(parseFloat(a.position_x), 0, 100);
            y = clampNum(parseFloat(a.position_y), 0, 100);
        } else {
            var cr = document.getElementById('preview-area').getBoundingClientRect();
            var br = el.getBoundingClientRect();
            var wpct = cr.width ? (br.width / cr.width) * 100 : 0;
            var hpct = cr.height ? (br.height / cr.height) * 100 : 0;
            var pc = presetToPercent(a.screen_position || defaultPositionFor(a.alert_category), wpct, hpct);
            x = pc.x; y = pc.y;
        }
        var placed = placeBoxPercent(el, x, y);
        a.position_x = placed.x;
        a.position_y = placed.y;
    }
    // Re-clamp the active box after a canvas-size or expand change (position stays in %).
    function repositionActiveBox() {
        if (!currentAlertId) return;
        var el = activeDragBox();
        if (!el || el.style.display === 'none') return;
        var a = alertsData[currentAlertId];
        if (a.position_x === null || a.position_x === undefined || a.position_x === '') { seedActiveBox(); return; }
        var placed = placeBoxPercent(el, parseFloat(a.position_x), parseFloat(a.position_y));
        a.position_x = placed.x;
        a.position_y = placed.y;
    }
    $('#alerts-canvas-size').on('change', function() {
        applyPreviewScale();
        repositionActiveBox();
    });
    // Sample preview content for the legacy-themed categories (weather, deaths). The
    // walk-on sample is static markup; only weather/deaths need their card built here.
    function renderLegacyPreview(category) {
        var html = '';
        if (category === 'weather') {
            html = '<div class="alerts-preview-weather">'
                 + '<div class="apw-header"><span class="apw-loc">Sydney</span><span class="apw-temp">22°C</span></div>'
                 + '<div class="apw-details"><i class="fas fa-cloud-sun apw-icon"></i><span class="apw-status">Partly cloudy</span><span class="apw-wind">10 kph</span><span class="apw-humidity">60%</span></div>'
                 + '</div>';
        } else if (category === 'deaths') {
            html = '<div class="alerts-preview-deaths">'
                 + '<div class="apd-title"><span class="apd-emote"></span><span>Current Deaths</span></div>'
                 + '<div class="apd-game">Elden Ring</div>'
                 + '<div class="apd-count">42</div>'
                 + '</div>';
        }
        $('#preview-legacy').html(html);
    }
    // Pointer-drag + smart-snap + expand wiring for the preview-canvas boxes.
    (function setupPositionEditor() {
        var canvas = document.getElementById('preview-area');
        if (!canvas) return;
        var boxes = ['preview-box', 'preview-legacy', 'preview-walkon-sample']
            .map(function(id) { return document.getElementById(id); })
            .filter(Boolean);
        var guideV = document.getElementById('alerts-pos-guide-v');
        var guideH = document.getElementById('alerts-pos-guide-h');
        var SNAP_PX = 7; // snap pull distance in screen pixels (converted to % per axis)
        var dragging = null, grabX = 0, grabY = 0;
        // Candidate snap lines for one axis: canvas edges, center and thirds.
        function snapLines() { return [0, 100 / 3, 50, 200 / 3, 100]; }
        // Nudge the box so its near edge / center / far edge lands on a snap line.
        function snapAxis(pos, sizePct, lines, threshPct) {
            var anchors = [pos, pos + sizePct / 2, pos + sizePct];
            var best = null;
            for (var i = 0; i < lines.length; i++) {
                for (var a = 0; a < anchors.length; a++) {
                    var d = Math.abs(anchors[a] - lines[i]);
                    if (d <= threshPct && (best === null || d < best.dist)) {
                        best = { pos: pos + (lines[i] - anchors[a]), line: lines[i], dist: d };
                    }
                }
            }
            return best;
        }
        function showGuide(el, prop, pct) {
            if (!el) return;
            if (pct === null) { el.classList.remove('is-active'); return; }
            el.style[prop] = pct + '%';
            el.classList.add('is-active');
        }
        function hideGuides() {
            if (guideV) guideV.classList.remove('is-active');
            if (guideH) guideH.classList.remove('is-active');
        }
        boxes.forEach(function(box) {
            box.addEventListener('pointerdown', function(e) {
                if (!currentAlertId) return;
                dragging = box;
                box.setPointerCapture(e.pointerId);
                var r = box.getBoundingClientRect();
                grabX = e.clientX - r.left;
                grabY = e.clientY - r.top;
                e.preventDefault();
            });
            box.addEventListener('pointermove', function(e) {
                if (dragging !== box) return;
                var cr = canvas.getBoundingClientRect();
                var br = box.getBoundingClientRect();
                var wpct = (br.width / cr.width) * 100;
                var hpct = (br.height / cr.height) * 100;
                var rawX = ((e.clientX - cr.left - grabX) / cr.width) * 100;
                var rawY = ((e.clientY - cr.top - grabY) / cr.height) * 100;
                var snapX = null, snapY = null;
                if (!e.altKey) {
                    var sx = snapAxis(rawX, wpct, snapLines(), SNAP_PX / cr.width * 100);
                    var sy = snapAxis(rawY, hpct, snapLines(), SNAP_PX / cr.height * 100);
                    if (sx) { rawX = sx.pos; snapX = sx.line; }
                    if (sy) { rawY = sy.pos; snapY = sy.line; }
                }
                var xp = clampNum(rawX, 0, 100 - wpct);
                var yp = clampNum(rawY, 0, 100 - hpct);
                // Clamping at an edge can pull the box off the snapped line; only show a
                // guide when an anchor still sits on it.
                if (snapX !== null && ![xp, xp + wpct / 2, xp + wpct].some(function(v) { return Math.abs(v - snapX) < 0.4; })) snapX = null;
                if (snapY !== null && ![yp, yp + hpct / 2, yp + hpct].some(function(v) { return Math.abs(v - snapY) < 0.4; })) snapY = null;
                showGuide(guideV, 'left', snapX);
                showGuide(guideH, 'top', snapY);
                box.style.left = xp + '%';
                box.style.top = yp + '%';
                if (alertsData[currentAlertId]) {
                    alertsData[currentAlertId].position_x = parseFloat(xp.toFixed(2));
                    alertsData[currentAlertId].position_y = parseFloat(yp.toFixed(2));
                }
            });
            function endDrag() {
                if (dragging !== box) return;
                dragging = null;
                hideGuides();
                if (!currentAlertId) return;
                var a = alertsData[currentAlertId];
                if (simpleCategories.indexOf(a.alert_category) !== -1) {
                    // weather/deaths/walk-ons: single field, save immediately like the toggle.
                    $.post('', { action: 'set_alert_position', id: currentAlertId, x: a.position_x, y: a.position_y }, null, 'json');
                } else {
                    markDirty();
                }
            }
            box.addEventListener('pointerup', endDrag);
            box.addEventListener('pointercancel', endDrag);
        });
        // Expand the editor to a large centred panel; same canvas, chips and snapping.
        var expandBtn = document.getElementById('alerts-pos-expand-btn');
        var expandClose = document.getElementById('alerts-pos-expand-close');
        var backdrop = document.getElementById('alerts-pos-backdrop');
        function setExpanded(on) {
            canvas.classList.toggle('is-expanded', on);
            if (backdrop) backdrop.classList.toggle('is-active', on);
            repositionActiveBox();
        }
        if (expandBtn) expandBtn.addEventListener('click', function() { setExpanded(true); });
        if (expandClose) expandClose.addEventListener('click', function() { setExpanded(false); });
        if (backdrop) backdrop.addEventListener('click', function() { setExpanded(false); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && canvas.classList.contains('is-expanded')) setExpanded(false);
        });
    })();
    function updateAddButtonFor(category) {
        var $cat = $('.alerts-category[data-category="' + category + '"]');
        if (!$cat.length) return;
        var limit = variantLimits[category];
        var count = $cat.find('.alerts-variant-item').length;
        var $btn = $cat.find('.alerts-new-variant-btn');
        if (limit !== undefined && count >= limit) $btn.hide();
        else $btn.show();
        // Collapse the controls bar when nothing inside is visible (avoids the
        // empty padded row on single-variant categories at cap).
        var $controls = $cat.find('.alerts-category-controls');
        var hasVisibleChild = $controls.children().filter(function() {
            return $(this).css('display') !== 'none';
        }).length > 0;
        if (hasVisibleChild) $controls.show();
        else $controls.hide();
    }
    // Variables available to each category's message template. Only what the
    // overlay actually substitutes for that event type is listed.
    const categoryVariables = {
        follow:            ['{username}'],
        subscription:      ['{username}', '{months}', '{tier}'],
        gift_subscription: ['{username}', '{amount}', '{tier}'],
        bits:              ['{username}', '{amount}'],
        raid:              ['{username}', '{viewers}'],
        hype_train:        ['{level}'],
        charity:           ['{username}', '{amount}', '{charity_name}'],
        channel_points:    ['{username}'],
        discord_join:      ['{username}'],
        kofi:              ['{username}', '{amount}', '{message}', '{tier_name}'],
        patreon:           ['{username}', '{amount}', '{tier_name}', '{lifetime}'],
        fourthwall:        ['{username}', '{amount}', '{item}', '{message}', '{interval}'],
        subathon:          ['{added_minutes}'],
        stream_bingo:      ['{username}', '{rank_text}', '{bingo_event_name}', '{bingo_number}', '{events_count}'],
        watch_streak:      ['{username}', '{streak}']
    };
    function renderVariableHints(category) {
        var vars = categoryVariables[category] || ['{username}'];
        var $hints = $('#variable-hints');
        var $list = $('#variable-hints-list');
        if (vars.length === 0) {
            $hints.hide();
            return;
        }
        $hints.show();
        $list.html(vars.map(function(v) { return '<code>' + v + '</code>'; }).join(' '));
    }
    function extractRewardId(condition) {
        if (!condition) return '';
        var m = String(condition).match(/reward_id\s*=\s*['"]?([^'"\s]+)['"]?/);
        return m ? m[1] : '';
    }
    (function populateRewardDropdown() {
        var sel = $('#set-reward-id');
        if (!channelPointRewards.length) {
            sel.append('<option value="" disabled>' + escapeHtml(i18n.noRewardsSynced) + '</option>');
            return;
        }
        channelPointRewards.forEach(function(r) {
            var label = r.reward_title + (r.reward_cost ? ' (' + r.reward_cost + ' pts)' : '');
            sel.append('<option value="' + $('<div>').text(r.reward_id).html() + '">' + $('<div>').text(label).html() + '</option>');
        });
    })();
    function extractBingoEvent(condition) {
        if (!condition) return '';
        var m = String(condition).match(/bingo_event\s*=\s*['"]?([^'"\s]+)['"]?/);
        return m ? m[1] : '';
    }
    $('#set-bingo-event').on('change', function() {
        var key = this.value;
        if (!key || !bingoSubtypes[key]) return;
        var label = bingoSubtypes[key];
        $('#set-variant-name').val(label);
        $('#set-alert-condition').val("bingo_event = '" + key + "'");
        if (currentAlertId) {
            $('.alerts-variant-item[data-id="' + currentAlertId + '"] .variant-name').text(label);
        }
        markDirty();
    });
    function applyCategoryUI(category, condition) {
        renderVariableHints(category);
        if (category === 'channel_points') {
            $('#variant-name-group').hide();
            $('#variant-condition-group').hide();
            $('#variant-bingo-group').hide();
            $('#variant-reward-group').show();
            var rid = extractRewardId(condition);
            var sel = $('#set-reward-id');
            // If the variant references a reward that no longer exists, add a
            // synthetic option so the user can see the dangling reference
            if (rid && sel.find('option[value="' + $('<div>').text(rid).html() + '"]').length === 0) {
                sel.append('<option value="' + $('<div>').text(rid).html() + '" data-missing="1">' + escapeHtml(i18n.unknownReward.replace('%s', rid)) + '</option>');
            }
            sel.val(rid || '');
        } else if (category === 'stream_bingo') {
            $('#variant-name-group').hide();
            $('#variant-condition-group').hide();
            $('#variant-reward-group').hide();
            $('#variant-bingo-group').show();
            $('#set-bingo-event').val(extractBingoEvent(condition) || '');
        } else {
            $('#variant-name-group').show();
            $('#variant-condition-group').show();
            $('#variant-reward-group').hide();
            $('#variant-bingo-group').hide();
        }
    }
    $('#set-reward-id').on('change', function() {
        var id = this.value;
        var reward = channelPointRewards.find(function(r) { return r.reward_id === id; });
        if (!reward) return;
        $('#set-variant-name').val(reward.reward_title);
        $('#set-alert-condition').val("reward_id = '" + String(reward.reward_id).replace(/'/g, "\\'") + "'");
        // Update the sidebar name immediately so the user sees the change
        if (currentAlertId) {
            $('.alerts-variant-item[data-id="' + currentAlertId + '"] .variant-name').text(reward.reward_title);
        }
        markDirty();
    });
    let currentAlertId = null;
    let isDirty = false;
    let loadingVariant = false;
    function markDirty() {
        if (loadingVariant) return;
        if (!isDirty) {
            isDirty = true;
            $('#alerts-save-btn, #alerts-discard-btn').prop('disabled', false);
        }
    }
    function clearDirty() {
        isDirty = false;
        $('#alerts-save-btn, #alerts-discard-btn').prop('disabled', true);
    }
    $(document).on('click', '.alerts-category-header', function() {
        $(this).closest('.alerts-category').toggleClass('open');
    });
    // Settings section accordion
    $(document).on('click', '.alerts-settings-section-header', function() {
        $(this).closest('.alerts-settings-section').toggleClass('open');
    });
    $(document).on('click', '.alerts-variant-item', function(e) {
        if ($(e.target).closest('.alerts-mini-toggle').length) return;
        if ($(e.target).closest('.alerts-variant-handle').length) return;
        var id = $(this).data('id');
        if (isDirty && !confirm(i18n.unsavedSwitchConfirm)) return;
        $('.alerts-variant-item').removeClass('active');
        $(this).addClass('active');
        loadVariant(id);
    });
    function loadVariant(id) {
        currentAlertId = id;
        var a = alertsData[id];
        if (!a) return;
        if (simpleCategories.indexOf(a.alert_category) !== -1) {
            loadSimpleVariant(a);
            return;
        }
        loadingVariant = true;
        $('#simple-settings').hide();
        $('#settings-placeholder').hide();
        $('#settings-form').show();
        $('#preview-placeholder').hide();
        $('#preview-legacy').hide();
        $('#preview-walkon-sample').hide();
        $('#preview-box').show();
        $('#preview-alert-btn, #test-alert-btn').prop('disabled', false);
        // General
        $('#set-variant-name').val(a.variant_name);
        $('#set-alert-condition').val(a.alert_condition || '');
        applyCategoryUI(a.alert_category, a.alert_condition);
        $('#set-duration').val(a.duration);
        $('#set-animation-in').val(a.animation_in);
        $('#set-animation-out').val(a.animation_out);
        $('#set-animation-in-duration').val(a.animation_in_duration);
        $('#set-animation-out-duration').val(a.animation_out_duration);
        $('#set-enabled').prop('checked', a.enabled == 1);
        // Layout
        $('.layout-preset-btn').removeClass('active');
        $('.layout-preset-btn[data-layout="' + a.layout_preset + '"]').addClass('active');
        $('#set-bg-color').val(a.bg_color);
        $('#set-bg-color-text').val(a.bg_color);
        $('#set-bg-opacity').val(a.bg_opacity);
        $('#set-padding').val(a.padding);
        $('#set-gap').val(a.gap);
        $('#set-rounded-corners').prop('checked', a.rounded_corners == 1);
        $('#set-drop-shadow').prop('checked', a.drop_shadow == 1);
        // Text
        $('#set-message-template').val((a.message_template || '').replace(/\\n/g, '\n'));
        $('#set-font-family').val(a.font_family);
        $('#set-font-weight').val(a.font_weight);
        $('#set-font-size').val(a.font_size);
        $('.text-align-btn[data-align]').removeClass('active');
        $('.text-align-btn[data-align="' + a.text_alignment + '"]').addClass('active');
        $('.valign-btn').removeClass('active');
        $('.valign-btn[data-valign="' + a.text_vertical_alignment + '"]').addClass('active');
        $('#set-text-color').val(a.text_color);
        $('#set-text-color-text').val(a.text_color);
        $('#set-accent-color').val(a.accent_color);
        $('#set-accent-color-text').val(a.accent_color);
        $('#set-text-drop-shadow').prop('checked', a.text_drop_shadow == 1);
        $('#set-tts-enabled').prop('checked', a.tts_enabled == 1);
        // Visuals
        updateMediaPreview('image', a.alert_image);
        updateMediaPreview('sound', a.alert_sound);
        $('#set-image-scale').val(a.image_scale);
        $('#image-scale-val').text(a.image_scale + '%');
        $('#set-image-volume').val(a.image_volume);
        $('#image-volume-val').text(a.image_volume + '%');
        $('#set-sound-volume').val(a.sound_volume);
        $('#sound-volume-val').text(a.sound_volume + '%');
        // Celebration
        $('#set-celebration-enabled').prop('checked', a.celebration_enabled == 1);
        $('#set-celebration-effect').val(a.celebration_effect);
        $('#set-celebration-intensity').val(a.celebration_intensity);
        updatePreview();
        applyPreviewScale();
        seedActiveBox();
        loadingVariant = false;
        clearDirty();
    }
    // Enable/disable-only categories: on/off switch, optional screen position, and
    // (for weather/deaths) a sample preview in their own theme. Walk-ons is audio-only.
    function loadSimpleVariant(a) {
        loadingVariant = true;
        $('#settings-form').hide();
        $('#settings-placeholder').hide();
        $('#simple-settings').show();
        $('#preview-placeholder').hide();
        $('#preview-box').hide();
        $('#preview-alert-btn, #test-alert-btn').prop('disabled', true);
        $('#set-simple-enabled').prop('checked', a.enabled == 1);
        // All three (weather, deaths, walk-ons) have a draggable position + sample
        // preview; only the help note differs for walk-ons (mode is per-viewer).
        $('#simple-position-group').show();
        var isWalkon = a.alert_category === 'walkons';
        $('#simple-note-positioned').toggle(!isWalkon);
        $('#simple-note-walkon').toggle(isWalkon);
        // Show the draggable sample that represents this category.
        if (isWalkon) {
            $('#preview-legacy').hide();
            $('#preview-walkon-sample').show();
        } else {
            $('#preview-walkon-sample').hide();
            renderLegacyPreview(a.alert_category);
            $('#preview-legacy').show();
        }
        applyPreviewScale();
        seedActiveBox();
        loadingVariant = false;
        clearDirty();
    }
    // On/off here fires immediately (single field), like the per-row toggle.
    $('#set-simple-enabled').on('change', function() {
        if (!currentAlertId) return;
        var enabled = this.checked ? 1 : 0;
        if (alertsData[currentAlertId]) alertsData[currentAlertId].enabled = enabled;
        $('.alerts-variant-enabled-toggle[data-id="' + currentAlertId + '"]').prop('checked', this.checked);
        $.post('', { action: 'toggle_alert', id: currentAlertId, enabled: enabled }, null, 'json');
    });
    function updateMediaPreview(type, filename) {
        if (type === 'image') {
            if (filename) {
                var ext = filename.split('.').pop().toLowerCase();
                var url = mediaBase + filename;
                if (['webm'].includes(ext)) {
                    $('#image-preview').html('<video src="' + url + '" autoplay loop muted></video>');
                } else {
                    $('#image-preview').html('<img src="' + url + '" alt="' + escapeHtml(i18n.alertImageAlt) + '">');
                }
                $('#image-filename').text(filename);
                $('#image-remove-btn').show();
            } else {
                $('#image-preview').empty();
                $('#image-filename').text(i18n.noImageSelected);
                $('#image-remove-btn').hide();
            }
        } else {
            if (filename) {
                $('#sound-filename').text(filename);
                $('#sound-remove-btn').show();
            } else {
                $('#sound-filename').text(i18n.noSoundSelected);
                $('#sound-remove-btn').hide();
            }
        }
    }
    function updatePreview() {
        if (!currentAlertId) return;
        var a = alertsData[currentAlertId];
        var layout = $('.layout-preset-btn.active').data('layout') || a.layout_preset;
        var bgColor = $('#set-bg-color').val();
        var bgOpacity = parseInt($('#set-bg-opacity').val()) / 100;
        var padding = $('#set-padding').val();
        var gap = $('#set-gap').val();
        var rounded = $('#set-rounded-corners').is(':checked');
        var shadow = $('#set-drop-shadow').is(':checked');
        var fontFamily = $('#set-font-family').val();
        var fontWeight = $('#set-font-weight').val();
        var fontSize = $('#set-font-size').val();
        var textColor = $('#set-text-color').val();
        var accentColor = $('#set-accent-color').val();
        var textShadow = $('#set-text-drop-shadow').is(':checked');
        var textAlign = $('.text-align-btn[data-align].active').data('align') || 'center';
        var msg = $('#set-message-template').val() || '';
        var imageScale = parseInt($('#set-image-scale').val()) || 100;
        var weightMap = {'Light':'300','Regular':'400','Medium':'500','Semi-Bold':'600','Bold':'700','Extra-Bold':'800'};
        var cssWeight = weightMap[fontWeight] || '600';
        var r = parseInt(bgColor.substr(1,2),16);
        var g = parseInt(bgColor.substr(3,2),16);
        var b = parseInt(bgColor.substr(5,2),16);
        var bgRgba = 'rgba('+r+','+g+','+b+','+bgOpacity+')';
        var $content = $('#preview-content');
        $content.attr('class', 'alerts-preview-content layout-' + layout);
        $content.css({
            'background': bgRgba,
            'padding': padding + 'px',
            'gap': gap + 'px',
            'border-radius': rounded ? '12px' : '0',
            'box-shadow': shadow ? '0 4px 20px rgba(0,0,0,0.5)' : 'none'
        });
        var imgFile = a.alert_image;
        if (imgFile) {
            var imgUrl = mediaBase + imgFile;
            var ext = imgFile.split('.').pop().toLowerCase();
            var $img = $('#preview-img');
            if (ext === 'webm') {
                if ($img.is('img')) {
                    $img.replaceWith('<video id="preview-img" class="preview-image" autoplay loop muted></video>');
                    $img = $('#preview-img');
                }
                $img.attr('src', imgUrl);
            } else {
                if ($img.is('video')) {
                    $img.replaceWith('<img id="preview-img" class="preview-image" src="" alt="">');
                    $img = $('#preview-img');
                }
                $img.attr('src', imgUrl);
            }
            $img.css('transform', 'scale(' + (imageScale/100) + ')').show();
        } else {
            $('#preview-img').hide();
        }
        var displayMsg = msg
            .replace(/\{username\}/g, '<span class="preview-accent">' + escapeHtml(i18n.previewUser) + '</span>')
            .replace(/\{amount\}/g, '<span class="preview-accent">100</span>')
            .replace(/\{months\}/g, '<span class="preview-accent">3</span>')
            .replace(/\{viewers\}/g, '<span class="preview-accent">42</span>')
            .replace(/\{tier\}/g, '<span class="preview-accent">1</span>')
            .replace(/\{added_minutes\}/g, '<span class="preview-accent">5</span>')
            .replace(/\{streak\}/g, '<span class="preview-accent">7</span>')
            .replace(/\n/g, '<br>');
        var $text = $('#preview-text');
        $text.html(displayMsg);
        $text.css({
            'font-family': '"' + fontFamily + '", sans-serif',
            'font-weight': cssWeight,
            'font-size': fontSize + 'px',
            'color': textColor,
            'text-align': textAlign,
            'text-shadow': textShadow ? '0 2px 4px rgba(0,0,0,0.8)' : 'none'
        });
        $('.preview-accent').css('color', accentColor);
        loadGoogleFont(fontFamily);
    }
    var loadedFonts = {};
    function loadGoogleFont(fontName) {
        if (loadedFonts[fontName]) return;
        loadedFonts[fontName] = true;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontName) + ':wght@300;400;500;600;700;800&display=swap';
        document.head.appendChild(link);
    }
    // Live preview + dirty tracking on any form change
    $(document).on('input change', '#settings-form input, #settings-form select, #settings-form textarea', function() {
        updatePreview();
        markDirty();
    });
    $(document).on('click', '.layout-preset-btn', function() {
        $('.layout-preset-btn').removeClass('active');
        $(this).addClass('active');
        updatePreview();
        markDirty();
    });
    $(document).on('click', '.text-align-btn[data-align]', function() {
        $('.text-align-btn[data-align]').removeClass('active');
        $(this).addClass('active');
        updatePreview();
        markDirty();
    });
    $(document).on('click', '.valign-btn', function() {
        $('.valign-btn').removeClass('active');
        $(this).addClass('active');
        updatePreview();
        markDirty();
    });
    // Colour input sync (color picker <-> hex text)
    $('#set-bg-color').on('input', function() { $('#set-bg-color-text').val(this.value); });
    $('#set-bg-color-text').on('change', function() { $('#set-bg-color').val(this.value); updatePreview(); markDirty(); });
    $('#set-text-color').on('input', function() { $('#set-text-color-text').val(this.value); });
    $('#set-text-color-text').on('change', function() { $('#set-text-color').val(this.value); updatePreview(); markDirty(); });
    $('#set-accent-color').on('input', function() { $('#set-accent-color-text').val(this.value); });
    $('#set-accent-color-text').on('change', function() { $('#set-accent-color').val(this.value); updatePreview(); markDirty(); });
    // Range slider display updates
    $('#set-image-scale').on('input', function() { $('#image-scale-val').text(this.value + '%'); });
    $('#set-image-volume').on('input', function() { $('#image-volume-val').text(this.value + '%'); });
    $('#set-sound-volume').on('input', function() { $('#sound-volume-val').text(this.value + '%'); });
    // Preview background swatches
    $(document).on('click', '.alerts-bg-swatch', function() {
        $('.alerts-bg-swatch').removeClass('active');
        $(this).addClass('active');
        var bg = $(this).data('bg');
        var area = $('#preview-area');
        switch(bg) {
            case 'transparent':
                area.css({ 'background-color': 'transparent', 'background-image': 'repeating-conic-gradient(rgba(255,255,255,0.03) 0% 25%, transparent 0% 50%)' });
                break;
            case 'dark':  area.css({ 'background-color': '#18181b', 'background-image': 'none' }); break;
            case 'light': area.css({ 'background-color': '#fff',    'background-image': 'none' }); break;
            case 'red':   area.css({ 'background-color': '#e74c3c', 'background-image': 'none' }); break;
        }
    });
    $(document).on('change', '.alerts-variant-enabled-toggle', function(e) {
        e.stopPropagation();
        var id = $(this).data('id');
        var enabled = this.checked ? 1 : 0;
        if (alertsData[id]) alertsData[id].enabled = enabled;
        if (id == currentAlertId) {
            $('#set-enabled').prop('checked', this.checked);
            $('#set-simple-enabled').prop('checked', this.checked);
        }
        $.post('', { action: 'toggle_alert', id: id, enabled: enabled }, null, 'json');
    });
    // Per-category randomize toggle — also fires immediately, single-field
    $(document).on('change', '.alerts-randomize-toggle', function() {
        var category = $(this).data('category');
        var randomize = this.checked ? 1 : 0;
        $.post('', { action: 'set_category_randomize', category: category, randomize: randomize }, null, 'json');
    });
    $('#alerts-save-btn').on('click', function() {
        if (!currentAlertId || !isDirty) return;
        var data = collectFormData();
        $.ajax({
            url: '', type: 'POST', data: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Object.assign(alertsData[currentAlertId], data);
                    $('.alerts-variant-item[data-id="' + currentAlertId + '"] .variant-name').text(data.variant_name);
                    $('.alerts-variant-item[data-id="' + currentAlertId + '"] .variant-condition').text(data.alert_condition || '');
                    clearDirty();
                    Swal.fire({ icon: 'success', title: i18n.savedTitle, text: resp.message, timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: i18n.errorTitle, text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: i18n.errorTitle, text: i18n.saveFailed }); }
        });
    });
    $('#alerts-discard-btn').on('click', function() {
        if (!currentAlertId || !isDirty) return;
        Swal.fire({
            title: i18n.discardConfirmTitle,
            text: i18n.discardConfirmText,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: i18n.discardConfirmYes, cancelButtonText: i18n.keepEditing
        }).then(function(result) {
            if (result.isConfirmed) loadVariant(currentAlertId);
        });
    });
    function collectFormData() {
        return {
            action: 'save_alert', id: currentAlertId,
            variant_name: $('#set-variant-name').val(),
            enabled: $('#set-enabled').is(':checked') ? 1 : 0,
            alert_condition: $('#set-alert-condition').val() || null,
            duration: $('#set-duration').val(),
            animation_in: $('#set-animation-in').val(),
            animation_out: $('#set-animation-out').val(),
            animation_in_duration: $('#set-animation-in-duration').val(),
            animation_out_duration: $('#set-animation-out-duration').val(),
            layout_preset: $('.layout-preset-btn.active').data('layout'),
            bg_color: $('#set-bg-color').val(),
            bg_opacity: $('#set-bg-opacity').val(),
            padding: $('#set-padding').val(),
            gap: $('#set-gap').val(),
            rounded_corners: $('#set-rounded-corners').is(':checked') ? 1 : 0,
            drop_shadow: $('#set-drop-shadow').is(':checked') ? 1 : 0,
            message_template: $('#set-message-template').val(),
            font_family: $('#set-font-family').val(),
            font_weight: $('#set-font-weight').val(),
            font_size: $('#set-font-size').val(),
            text_alignment: $('.text-align-btn[data-align].active').data('align'),
            text_vertical_alignment: $('.valign-btn.active').data('valign'),
            text_color: $('#set-text-color').val(),
            accent_color: $('#set-accent-color').val(),
            text_drop_shadow: $('#set-text-drop-shadow').is(':checked') ? 1 : 0,
            tts_enabled: $('#set-tts-enabled').is(':checked') ? 1 : 0,
            alert_image: alertsData[currentAlertId].alert_image,
            image_scale: $('#set-image-scale').val(),
            image_volume: $('#set-image-volume').val(),
            alert_sound: alertsData[currentAlertId].alert_sound,
            sound_volume: $('#set-sound-volume').val(),
            celebration_enabled: $('#set-celebration-enabled').is(':checked') ? 1 : 0,
            celebration_effect: $('#set-celebration-effect').val(),
            celebration_intensity: $('#set-celebration-intensity').val(),
            celebration_area: 'full',
            // Drag-to-place: box top-left as a 0-100 percent of the 16:9 canvas.
            // screen_position is preserved unchanged for overlay back-compat fallback.
            screen_position: alertsData[currentAlertId].screen_position || null,
            position_x: alertsData[currentAlertId].position_x,
            position_y: alertsData[currentAlertId].position_y
        };
    }
    $(document).on('click', '.alerts-new-variant-btn', function(e) {
        e.stopPropagation();
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var category = $btn.data('category');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-pulse"></i> ' + escapeHtml(i18n.adding));
        $.post('', { action: 'create_variant', category: category }, function(resp) {
            $btn.prop('disabled', false).html(origHtml);
            if (!resp.success) {
                Swal.fire({ icon: 'error', title: i18n.cannotAddTitle, text: resp.message });
                return;
            }
            var v = resp.variant;
            alertsData[v.id] = v;
            var $cat = $('.alerts-category[data-category="' + category + '"]');
            $cat.find('.alerts-variant-empty').remove();
            var $list = $cat.find('.alerts-variant-list');
            var html = ''
                + '<li class="alerts-variant-item" data-id="' + v.id + '">'
                +   '<span class="alerts-variant-handle" title="' + escapeHtml(i18n.dragReorder) + '"><i class="fas fa-grip-vertical"></i></span>'
                +   '<span class="alerts-variant-priority">' + (parseInt(v.variant_index) + 1) + '</span>'
                +   '<div class="alerts-variant-info">'
                +     '<div class="variant-name">' + escapeHtml(v.variant_name) + '</div>'
                +     (v.alert_condition ? '<div class="variant-condition">' + escapeHtml(v.alert_condition) + '</div>' : '')
                +   '</div>'
                +   '<label class="alerts-mini-toggle" onclick="event.stopPropagation();">'
                +     '<input type="checkbox" class="alerts-variant-enabled-toggle" data-id="' + v.id + '" ' + (v.enabled == 1 ? 'checked' : '') + '>'
                +     '<span class="alerts-mini-toggle-slider"></span>'
                +   '</label>'
                + '</li>';
            $list.append(html);
            $cat.find('.alerts-category-count').text($list.find('.alerts-variant-item').length);
            updateAddButtonFor(category);
            updateVariantCount();
            $('.alerts-variant-item[data-id="' + v.id + '"]').click();
        }, 'json').fail(function() {
            $btn.prop('disabled', false).html(origHtml);
            Swal.fire({ icon: 'error', title: i18n.errorTitle, text: i18n.addFailed });
        });
    });
    $('#delete-variant-btn').on('click', function() {
        var $btn = $(this);
        if ($btn.prop('disabled') || !currentAlertId) return;
        var a = alertsData[currentAlertId];
        Swal.fire({
            title: i18n.deleteConfirmTitle.replace('%s', a.variant_name),
            text: i18n.deleteConfirmText,
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: i18n.deleteConfirmYes, cancelButtonText: i18n.cancel
        }).then(function(result) {
            if (!result.isConfirmed) return;
            var origHtml = $btn.html();
            var idToDelete = currentAlertId;
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-pulse"></i> ' + escapeHtml(i18n.deleting));
            $.post('', { action: 'delete_variant', id: idToDelete }, function(resp) {
                $btn.prop('disabled', false).html(origHtml);
                if (!resp.success) {
                    Swal.fire({ icon: 'error', title: i18n.errorTitle, text: resp.message });
                    return;
                }
                var $row = $('.alerts-variant-item[data-id="' + idToDelete + '"]');
                var $cat = $row.closest('.alerts-category');
                $row.remove();
                delete alertsData[idToDelete];
                if (currentAlertId === idToDelete) currentAlertId = null;
                var remaining = $cat.find('.alerts-variant-item').length;
                $cat.find('.alerts-category-count').text(remaining);
                if (remaining === 0) {
                    $cat.find('.alerts-variant-list').append('<li class="alerts-variant-empty">' + escapeHtml(i18n.noVariantsYet) + '</li>');
                }
                updateAddButtonFor($cat.data('category'));
                updateVariantCount();
                $('#settings-form').hide();
                $('#settings-placeholder').show();
                $('#preview-box').hide();
                $('#preview-placeholder').show();
                $('#preview-alert-btn, #test-alert-btn').prop('disabled', true);
                clearDirty();
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html(origHtml);
                Swal.fire({ icon: 'error', title: i18n.errorTitle, text: i18n.deleteFailed });
            });
        });
    });
    function updateVariantCount() {
        var total = Object.keys(alertsData).length;
        $('#alerts-variant-count').text(total);
    }
    $('#image-upload-btn').on('click', function() { $('#image-file-input').click(); });
    $('#image-file-input').on('change', function() {
        if (!this.files[0] || !currentAlertId) return;
        var formData = new FormData();
        formData.append('action', 'upload_alert_media');
        formData.append('media_file', this.files[0]);
        $.ajax({
            url: '', type: 'POST', data: formData, contentType: false, processData: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alertsData[currentAlertId].alert_image = resp.filename;
                    // Surface the new file in the picker without a reload
                    var ext = resp.filename.split('.').pop().toLowerCase();
                    var bucket = ext === 'mp3' ? librarySounds : libraryImages;
                    if (bucket.indexOf(resp.filename) === -1) bucket.push(resp.filename);
                    updateMediaPreview('image', resp.filename);
                    updatePreview();
                    markDirty();
                } else {
                    Swal.fire({ icon: 'error', title: i18n.uploadFailedTitle, text: resp.message });
                }
            }
        });
    });
    $('#image-remove-btn').on('click', function() {
        if (!currentAlertId) return;
        $.post('', { action: 'remove_alert_media', id: currentAlertId, field: 'alert_image' }, function(resp) {
            if (resp.success) {
                alertsData[currentAlertId].alert_image = null;
                updateMediaPreview('image', null);
                updatePreview();
            }
        }, 'json');
    });
    $('#sound-upload-btn').on('click', function() { $('#sound-file-input').click(); });
    $('#sound-file-input').on('change', function() {
        if (!this.files[0] || !currentAlertId) return;
        var formData = new FormData();
        formData.append('action', 'upload_alert_media');
        formData.append('media_file', this.files[0]);
        $.ajax({
            url: '', type: 'POST', data: formData, contentType: false, processData: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alertsData[currentAlertId].alert_sound = resp.filename;
                    if (librarySounds.indexOf(resp.filename) === -1) librarySounds.push(resp.filename);
                    updateMediaPreview('sound', resp.filename);
                    markDirty();
                } else {
                    Swal.fire({ icon: 'error', title: i18n.uploadFailedTitle, text: resp.message });
                }
            }
        });
    });
    $('#sound-remove-btn').on('click', function() {
        if (!currentAlertId) return;
        $.post('', { action: 'remove_alert_media', id: currentAlertId, field: 'alert_sound' }, function(resp) {
            if (resp.success) {
                alertsData[currentAlertId].alert_sound = null;
                updateMediaPreview('sound', null);
            }
        }, 'json');
    });
    $('#preview-alert-btn').on('click', function() {
        if (!currentAlertId) return;
        var $box = $('#preview-box');
        var animIn = $('#set-animation-in').val();
        var animOut = $('#set-animation-out').val();
        var duration = parseInt($('#set-duration').val()) * 1000;
        var animInDur = parseFloat($('#set-animation-in-duration').val());
        var animOutDur = parseFloat($('#set-animation-out-duration').val());
        $box.css('animation', 'none');
        void $box[0].offsetHeight;
        $box.css('animation', animIn + ' ' + animInDur + 's forwards');
        setTimeout(function() {
            $box.css('animation', animOut + ' ' + animOutDur + 's forwards');
        }, duration);
    });
    $('#test-alert-btn').on('click', function() {
        if (!currentAlertId) return;
        var a = alertsData[currentAlertId];
        var eventMap = {
            'follow':            { event: 'TWITCH_FOLLOW', params: { user: 'TestUser' } },
            'subscription':      { event: 'TWITCH_SUB', params: { user: 'TestUser', sub_tier: '1', sub_months: '3' } },
            'gift_subscription': { event: 'TWITCH_GIFT_SUB', params: { user: 'TestUser', sub_tier: '1', sub_months: '1' } },
            'bits':              { event: 'TWITCH_CHEER', params: { user: 'TestUser', cheer_amount: '100' } },
            'raid':              { event: 'TWITCH_RAID', params: { user: 'TestUser', raid_viewers: '42' } },
            'hype_train':        { event: 'TWITCH_HYPE_TRAIN', params: { level: '5' } },
            'charity':           { event: 'TWITCH_CHARITY', params: { user: 'TestUser', amount: '100.00 USD', charity_name: 'Example Charity' } },
            'discord_join':      { event: 'DISCORD_JOIN', params: { member: 'TestUser' } },
        };
        var config = eventMap[a.alert_category];
        // Fourthwall variants are tied to one of four sub-types via their condition;
        // pick the matching sub-type for the live test from the variant itself.
        if (!config && a.alert_category === 'fourthwall') {
            var fwMatch = (a.alert_condition || '').match(/fourthwall_type\s*=\s*['"]([^'"]+)['"]/);
            var fwType = fwMatch ? fwMatch[1] : 'DONATION';
            config = { event: 'FOURTHWALL', params: { fourthwall_type: fwType, user: 'TestUser', amount: '10.00', currency: 'USD', message: 'Awesome stream!', item: 'Test Item' } };
        }
        // Patreon variants are tied to one of three sub-types via their condition;
        // pick the matching sub-type for the live test from the variant itself.
        if (!config && a.alert_category === 'patreon') {
            var ptMatch = (a.alert_condition || '').match(/patreon_type\s*=\s*['"]([^'"]+)['"]/);
            var patreonType = ptMatch ? ptMatch[1] : 'pledge';
            config = { event: 'PATREON', params: { patreon_type: patreonType, user: 'TestUser', amount: '5.00', currency: 'USD', tier_name: 'Gold Tier', lifetime: '50.00' } };
        }
        // Ko-fi variants are tied to one of three sub-types via their condition;
        // pick the matching sub-type for the live test from the variant itself.
        if (!config && a.alert_category === 'kofi') {
            var ktMatch = (a.alert_condition || '').match(/kofi_type\s*=\s*['"]([^'"]+)['"]/);
            var kofiType = ktMatch ? ktMatch[1] : 'Donation';
            var kofiParams = { kofi_type: kofiType, user: 'TestUser', amount: '5.00', currency: 'USD' };
            if (kofiType === 'Donation')     kofiParams.message = 'Awesome stream!';
            if (kofiType === 'Subscription') kofiParams.tier_name = 'Gold';
            config = { event: 'KOFI', params: kofiParams };
        }
        // Stream bingo variants are tied to specific sub-events via their condition;
        // pick the right sub-event for the live test from the variant itself.
        if (!config && a.alert_category === 'stream_bingo') {
            var be = extractBingoEvent(a.alert_condition);
            if (!be) {
                Swal.fire({ icon: 'info', title: i18n.bingoPickTitle, text: i18n.bingoPickText });
                return;
            }
            var bingoParams = {};
            if (be === 'STREAM_BINGO_WINNER')         bingoParams = { player_name: 'TestUser', rank: 1, rank_text: '1st' };
            else if (be === 'STREAM_BINGO_EVENT_CALLED') bingoParams = { display_number: 42, event_name: 'Test bingo event' };
            else if (be === 'STREAM_BINGO_STARTED')      bingoParams = { is_sub_only: 0, events_count: 25 };
            // STREAM_BINGO_ENDED carries no extra params
            config = { event: be, params: bingoParams };
        }
        if (!config) {
            Swal.fire({ icon: 'info', title: i18n.testUnavailableTitle, text: i18n.testUnavailableText });
            return;
        }
        var params = Object.assign({ event: config.event, api_key: apiKey, channel_name: channelName }, config.params);
        $.post('/api/notify_event.php', params, function(resp) {
            if (resp.success) {
                Swal.fire({ icon: 'success', title: i18n.testSentTitle, text: i18n.testSentText, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: i18n.errorTitle, text: resp.message || i18n.testSendFailed });
            }
        }, 'json');
    });
    // Click-to-reveal on the OBS browser source URL — masked by default so the
    // API key embedded in the URL isn't visible in screen recordings.
    $('#alerts-browser-source-url').on('focus click', function() {
        this.type = 'text';
        this.select();
    }).on('blur', function() {
        this.type = 'password';
    });
    $('#alerts-copy-url-btn').on('click', function() {
        var $input = $('#alerts-browser-source-url');
        // Temporarily flip to text so the copy/select path works reliably
        var wasMasked = $input.attr('type') === 'password';
        if (wasMasked) $input.attr('type', 'text');
        $input[0].select();
        $input[0].setSelectionRange(0, 99999);
        try {
            navigator.clipboard.writeText($input.val()).then(function() {
                var $btn = $('#alerts-copy-url-btn');
                var orig = $btn.html();
                $btn.html('<i class="fas fa-check"></i> ' + escapeHtml(i18n.copied));
                setTimeout(function() { $btn.html(orig); }, 1500);
            });
        } catch (_) {
            document.execCommand('copy');
        }
        if (wasMasked) setTimeout(function() { $input.attr('type', 'password'); }, 200);
    });
    $('#alerts-edit-multiple-link').on('click', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'info', title: i18n.editMultipleTitle,
            text: i18n.editMultipleText
        });
    });
    var libraryMode = null; // 'image' | 'sound'
    function openLibrary(mode) {
        libraryMode = mode;
        var files = mode === 'image' ? libraryImages : librarySounds;
        $('#alerts-library-modal-title').text(mode === 'image' ? i18n.chooseImage : i18n.chooseSound);
        renderLibrary(files, '');
        $('#alerts-library-search').val('');
        $('#alerts-library-modal').css('display', 'flex');
    }
    function closeLibrary() {
        $('#alerts-library-modal').hide();
        libraryMode = null;
    }
    function renderLibrary(files, search) {
        var grid = $('#alerts-library-grid');
        var filtered = files.filter(function(f) {
            return !search || f.toLowerCase().indexOf(search.toLowerCase()) !== -1;
        });
        if (filtered.length === 0) {
            grid.empty();
            $('#alerts-library-empty').show();
            return;
        }
        $('#alerts-library-empty').hide();
        var html = filtered.map(function(f) {
            var ext = f.split('.').pop().toLowerCase();
            var url = mediaBase + f;
            var thumb = '';
            if (libraryMode === 'image') {
                thumb = ext === 'webm'
                    ? '<video src="' + url + '" muted autoplay loop playsinline></video>'
                    : '<img src="' + url + '" alt="">';
            } else {
                thumb = '<div class="alerts-library-sound-thumb"><i class="fas fa-music"></i></div>';
            }
            return '<button type="button" class="alerts-library-item" data-file="' + escapeHtml(f) + '">'
                +    '<div class="alerts-library-thumb">' + thumb + '</div>'
                +    '<div class="alerts-library-name">' + escapeHtml(f) + '</div>'
                +  '</button>';
        }).join('');
        grid.html(html);
    }
    $('#image-library-btn').on('click', function() {
        if (!currentAlertId) return;
        openLibrary('image');
    });
    $('#sound-library-btn').on('click', function() {
        if (!currentAlertId) return;
        openLibrary('sound');
    });
    $('#alerts-library-modal-close').on('click', closeLibrary);
    $('#alerts-library-modal').on('click', function(e) {
        if (e.target === this) closeLibrary();
    });
    $('#alerts-library-search').on('input', function() {
        var files = libraryMode === 'image' ? libraryImages : librarySounds;
        renderLibrary(files, this.value);
    });
    $(document).on('click', '.alerts-library-item', function() {
        if (!currentAlertId || !libraryMode) return;
        var file = $(this).data('file');
        if (libraryMode === 'image') {
            alertsData[currentAlertId].alert_image = file;
            updateMediaPreview('image', file);
            updatePreview();
        } else {
            alertsData[currentAlertId].alert_sound = file;
            updateMediaPreview('sound', file);
        }
        markDirty();
        closeLibrary();
    });
    // Warn on page leave with unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // Auto-open first category and select first variant on load
    var $firstCategory = $('.alerts-category:first');
    if ($firstCategory.length) {
        $firstCategory.addClass('open');
        var $firstVariant = $firstCategory.find('.alerts-variant-item:first');
        if ($firstVariant.length) $firstVariant.click();
    }
});
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>