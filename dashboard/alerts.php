<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

require_once '/var/www/lib/require_auth.php';

$pageTitle = 'Specter Alerts';

require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'user_db.php';
require_once __DIR__ . '/upload_helpers.php';
require_once __DIR__ . '/file_paths.php';
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
    'follow' => 1,
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
    ['kofi', 'Ko-fi tip', 0, null, "{username}\nsent a Ko-fi!"],
    ['patreon', 'New patron', 0, null, "{username}\nbecame a patron!"],
    ['fourthwall', 'Fourthwall order', 0, null, "{username}\nbought from the shop!"],
    ['subathon', 'Subathon time added', 0, null, "{added_minutes} minutes added to the subathon!"],
    ['stream_bingo', 'Game Started', 0, "bingo_event = 'STREAM_BINGO_STARTED'",      "Stream Bingo is starting!"],
    ['stream_bingo', 'Game Event',   1, "bingo_event = 'STREAM_BINGO_EVENT_CALLED'", "Event called:\n{bingo_event_name}"],
    ['stream_bingo', 'Game Winner',  2, "bingo_event = 'STREAM_BINGO_WINNER'",       "BINGO! {username}\ngot {rank_text}!"],
    ['stream_bingo', 'Game Ended',   3, "bingo_event = 'STREAM_BINGO_ENDED'",        "Stream Bingo has ended!"],
    ['watch_streak', 'Watch streak', 0, 'streak >= 7', "{username}\nis on a {streak}-stream watch streak!"],
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
            celebration_enabled = ?, celebration_effect = ?, celebration_intensity = ?, celebration_area = ?
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
        $stmt->bind_param('sisississsiiiissssissssississiisssi',
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
        include __DIR__ . '/storage_used.php';
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
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

include '/var/www/config/twitch.php';
include 'bot_control.php';
include "mod_access.php";
include 'storage_used.php';

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
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
        <span><strong>Specter Alerts uses the new Unified Media Library.</strong> Upload your files on the <a href="media.php">Media</a> page.</span>
    </div>
    <!-- Top header bar — title, counter, save/discard -->
    <header class="alerts-top-bar">
        <div class="alerts-top-bar-left">
            <h1 class="alerts-page-title">Alerts</h1>
            <span class="alerts-variant-counter">
                <strong id="alerts-variant-count"><?php echo $totalVariants; ?></strong> variants
            </span>
        </div>
        <div class="alerts-top-bar-right">
            <button class="sp-btn sp-btn-ghost" id="alerts-discard-btn" disabled>
                <i class="fas fa-rotate-left"></i> Discard
            </button>
            <button class="sp-btn sp-btn-primary" id="alerts-save-btn" disabled>
                <i class="fas fa-save"></i> Save changes
            </button>
        </div>
    </header>
    <!-- Three-column shell: variants | preview | settings -->
    <div class="alerts-shell">
        <!-- LEFT: variants sidebar -->
        <aside class="alerts-sidebar">
            <div class="alerts-sidebar-header">
                <span class="alerts-sidebar-label">Variants</span>
                <div class="alerts-sidebar-tools">
                    <a href="#" class="alerts-edit-multiple" id="alerts-edit-multiple-link">Edit multiple</a>
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
                        <span class="alerts-category-name"><?php echo htmlspecialchars($meta['label']); ?></span>
                        <span class="alerts-category-count"><?php echo count($variants); ?></span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-category-body">
                        <div class="alerts-category-controls"<?php echo $showControls ? '' : ' style="display:none;"'; ?>>
                            <?php if ($showRandomize): ?>
                            <label class="alerts-mini-toggle">
                                <input type="checkbox" class="alerts-randomize-toggle" data-category="<?php echo htmlspecialchars($category); ?>" <?php echo $randomize ? 'checked' : ''; ?>>
                                <span class="alerts-mini-toggle-slider"></span>
                                <span class="alerts-mini-toggle-text">Randomize</span>
                            </label>
                            <?php endif; ?>
                            <button type="button" class="alerts-new-variant-btn" data-category="<?php echo htmlspecialchars($category); ?>" title="Add a new variant"<?php echo $canAddVariant ? '' : ' style="display:none;"'; ?>>
                                <i class="fas fa-plus"></i> New variant
                            </button>
                        </div>
                        <ul class="alerts-variant-list">
                            <?php foreach ($variants as $variant): ?>
                            <li class="alerts-variant-item" data-id="<?php echo $variant['id']; ?>">
                                <span class="alerts-variant-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
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
                            <li class="alerts-variant-empty">No variants yet — add one above.</li>
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
                    <i class="fas fa-eye"></i> Replay preview
                </button>
                <button class="sp-btn sp-btn-primary" id="test-alert-btn" disabled>
                    <i class="fas fa-paper-plane"></i> Trigger live test
                </button>
            </div>
            <div class="alerts-preview-area" id="preview-area">
                <div class="alerts-no-selection" id="preview-placeholder">
                    Select a variant from the left to preview it here.
                </div>
                <div class="alerts-preview-box" id="preview-box" style="display:none;">
                    <div class="alerts-preview-content" id="preview-content">
                        <img src="" alt="" class="preview-image" id="preview-img" style="display:none;">
                        <div class="preview-text" id="preview-text"></div>
                    </div>
                </div>
            </div>
            <div class="alerts-preview-options">
                <span class="alerts-preview-options-label">Preview canvas</span>
                <label class="alerts-mini-toggle">
                    <input type="checkbox" id="preview-autoplay" checked>
                    <span class="alerts-mini-toggle-slider"></span>
                    <span class="alerts-mini-toggle-text">Autoplay</span>
                </label>
                <label>Width</label>
                <input type="number" class="sp-input" id="preview-width" value="800" min="200" max="1920">
                <label>Height</label>
                <input type="number" class="sp-input" id="preview-height" value="600" min="200" max="1080">
                <div class="alerts-bg-swatches">
                    <button type="button" class="alerts-bg-swatch active" data-bg="transparent" title="Transparent"></button>
                    <button type="button" class="alerts-bg-swatch" data-bg="dark" title="Dark"></button>
                    <button type="button" class="alerts-bg-swatch" data-bg="light" title="Light"></button>
                    <button type="button" class="alerts-bg-swatch" data-bg="red" title="Red"></button>
                </div>
            </div>
        </div>
        <!-- RIGHT: settings panel -->
        <div class="alerts-settings-panel" id="settings-panel">
            <div class="alerts-no-selection" id="settings-placeholder">
                Select a variant from the left to edit its settings.
            </div>
            <div id="settings-form" style="display:none;">
                <!-- General Settings -->
                <section class="alerts-settings-section open">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-sliders"></i>
                        <span>General</span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group" id="variant-name-group">
                            <label>Variant name</label>
                            <input type="text" class="sp-input" id="set-variant-name">
                        </div>
                        <div class="alerts-form-group" id="variant-reward-group" style="display:none;">
                            <label>Channel point reward</label>
                            <select class="sp-select" id="set-reward-id">
                                <option value="">Select a reward…</option>
                            </select>
                            <small class="alerts-help-text">The variant name and trigger condition come from the selected reward. Sync more in <a href="channel_rewards.php">Channel Rewards</a>.</small>
                        </div>
                        <div class="alerts-form-group" id="variant-bingo-group" style="display:none;">
                            <label>Bingo event</label>
                            <select class="sp-select" id="set-bingo-event">
                                <option value="">Select a bingo event…</option>
                                <?php foreach ($bingoSubtypes as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="alerts-help-text">Variants fire only when the selected bingo event happens. Each sub-event can have its own visual.</small>
                        </div>
                        <div class="alerts-form-group" id="variant-condition-group">
                            <label>Condition <span class="alerts-help">(advanced)</span></label>
                            <input type="text" class="sp-input" id="set-alert-condition" placeholder="e.g. bits >= 100, months = 1, gift_count >= 5">
                            <small class="alerts-help-text">Only fire this variant when the condition matches. Leave blank to always match.</small>
                        </div>
                        <div class="alerts-form-group">
                            <label>On-screen duration (seconds)</label>
                            <input type="number" class="sp-input" id="set-duration" min="1" max="99" value="8">
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>In animation</label>
                                <div class="alerts-inline-pair">
                                    <select class="sp-select" id="set-animation-in">
                                        <?php foreach ($animationsIn as $label => $val): ?>
                                        <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="sp-input alerts-inline-pair-mini" id="set-animation-in-duration" min="0.1" max="5" step="0.1" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Out animation</label>
                                <div class="alerts-inline-pair">
                                    <select class="sp-select" id="set-animation-out">
                                        <?php foreach ($animationsOut as $label => $val): ?>
                                        <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="sp-input alerts-inline-pair-mini" id="set-animation-out-duration" min="0.1" max="5" step="0.1" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label>Enabled</label>
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
                        <span>Layout</span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label>Image position</label>
                            <div class="layout-presets">
                                <button type="button" class="layout-preset-btn active" data-layout="above" title="Image above text">
                                    <svg viewBox="0 0 48 36"><rect x="16" y="2" width="16" height="12" rx="2" fill="currentColor"/><rect x="8" y="18" width="32" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="24" width="24" height="3" rx="1" fill="currentColor" opacity="0.4"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="right" title="Text left, image right">
                                    <svg viewBox="0 0 48 36"><rect x="30" y="6" width="14" height="24" rx="2" fill="currentColor"/><rect x="4" y="10" width="22" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="4" y="17" width="18" height="3" rx="1" fill="currentColor" opacity="0.4"/><rect x="4" y="24" width="20" height="3" rx="1" fill="currentColor" opacity="0.4"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="left" title="Image left, text right">
                                    <svg viewBox="0 0 48 36"><rect x="4" y="6" width="14" height="24" rx="2" fill="currentColor"/><rect x="22" y="10" width="22" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="22" y="17" width="18" height="3" rx="1" fill="currentColor" opacity="0.4"/><rect x="22" y="24" width="20" height="3" rx="1" fill="currentColor" opacity="0.4"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="below" title="Text above, image below">
                                    <svg viewBox="0 0 48 36"><rect x="8" y="2" width="32" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="8" width="24" height="3" rx="1" fill="currentColor" opacity="0.4"/><rect x="16" y="16" width="16" height="16" rx="2" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="behind" title="Image behind text">
                                    <svg viewBox="0 0 48 36"><rect x="2" y="2" width="44" height="32" rx="2" fill="currentColor" opacity="0.5"/><rect x="8" y="12" width="32" height="3" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="18" width="24" height="3" rx="1" fill="currentColor" opacity="0.6"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Background</label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-bg-color" value="#FFFFFF">
                                    <input type="text" class="sp-input" id="set-bg-color-text" value="#FFFFFF">
                                </div>
                            </div>
                            <div class="alerts-form-group">
                                <label>Opacity (%)</label>
                                <input type="number" class="sp-input" id="set-bg-opacity" min="0" max="100" value="0">
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Padding (px)</label>
                                <input type="number" class="sp-input" id="set-padding" min="0" max="100" value="16">
                            </div>
                            <div class="alerts-form-group">
                                <label>Gap (px)</label>
                                <input type="number" class="sp-input" id="set-gap" min="0" max="100" value="16">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label>Rounded corners</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-rounded-corners" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label>Drop shadow</label>
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
                        <span>Text &amp; Speech</span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label>Message template</label>
                            <textarea id="set-message-template" placeholder="{username}&#10;just followed!"></textarea>
                            <div class="variable-hints" id="variable-hints">
                                <span>Variables:</span>
                                <span id="variable-hints-list"></span>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Font</label>
                            <div class="alerts-inline-pair">
                                <select class="sp-select" id="set-font-family">
                                    <?php foreach ($fonts as $font): ?>
                                    <option value="<?php echo $font; ?>"><?php echo $font; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="sp-select alerts-inline-pair-weight" id="set-font-weight">
                                    <?php foreach ($fontWeights as $label => $val): ?>
                                    <option value="<?php echo $label; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="sp-input alerts-inline-pair-mini" id="set-font-size" min="8" max="120" value="24">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Alignment</label>
                            <div class="text-align-btns">
                                <button type="button" class="text-align-btn" data-align="left" title="Align left"><i class="fas fa-align-left"></i></button>
                                <button type="button" class="text-align-btn active" data-align="center" title="Align center"><i class="fas fa-align-center"></i></button>
                                <button type="button" class="text-align-btn" data-align="right" title="Align right"><i class="fas fa-align-right"></i></button>
                                <button type="button" class="text-align-btn" data-align="justify" title="Justify"><i class="fas fa-align-justify"></i></button>
                                <span class="text-align-divider"></span>
                                <button type="button" class="text-align-btn valign-btn" data-valign="top" title="Align top"><i class="fas fa-arrow-up"></i></button>
                                <button type="button" class="text-align-btn valign-btn active" data-valign="center" title="Align middle"><i class="fas fa-arrows-up-down"></i></button>
                                <button type="button" class="text-align-btn valign-btn" data-valign="bottom" title="Align bottom"><i class="fas fa-arrow-down"></i></button>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Text colour</label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-text-color" value="#FFFFFF">
                                    <input type="text" class="sp-input" id="set-text-color-text" value="#FFFFFF">
                                </div>
                            </div>
                            <div class="alerts-form-group">
                                <label>Accent colour</label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-accent-color" value="#7C5CBF">
                                    <input type="text" class="sp-input" id="set-accent-color-text" value="#7C5CBF">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label>Text drop shadow</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-text-drop-shadow" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group alerts-form-group-divider">
                            <div class="alerts-toggle-wrap">
                                <div>
                                    <label class="alerts-mini-section-label">Text-to-speech</label>
                                    <div class="alerts-help-text">Read the alert text aloud through the TTS overlay.</div>
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
                        <span>Visuals &amp; sound</span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label>Alert image</label>
                            <div class="alerts-media-upload" id="image-upload-zone">
                                <div class="alerts-media-preview" id="image-preview"></div>
                                <div class="alerts-media-filename" id="image-filename">No image selected</div>
                                <div class="alerts-media-actions">
                                    <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="image-library-btn">
                                        <i class="fas fa-folder-open"></i> Browse library
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="image-upload-btn">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="image-remove-btn" style="display:none;">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                                <input type="file" id="image-file-input" accept=".webm,.gif,.png,.jpg,.jpeg">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Image scale</label>
                            <div class="alerts-range-wrap">
                                <input type="range" id="set-image-scale" min="0" max="200" value="100">
                                <span class="alerts-range-value" id="image-scale-val">100%</span>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Image volume</label>
                            <div class="alerts-range-wrap">
                                <input type="range" id="set-image-volume" min="0" max="100" value="0">
                                <span class="alerts-range-value" id="image-volume-val">0%</span>
                            </div>
                        </div>
                        <div class="alerts-form-group alerts-form-group-divider">
                            <label>Alert sound</label>
                            <div class="alerts-media-upload" id="sound-upload-zone">
                                <div class="alerts-media-filename" id="sound-filename">No sound selected</div>
                                <div class="alerts-media-actions">
                                    <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="sound-library-btn">
                                        <i class="fas fa-folder-open"></i> Browse library
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="sound-upload-btn">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="sound-remove-btn" style="display:none;">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                                <input type="file" id="sound-file-input" accept=".mp3">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Sound volume</label>
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
                        <span>Celebration</span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <div>
                                    <label class="alerts-mini-section-label">Particle effect</label>
                                    <div class="alerts-help-text">Layer a full-screen effect over the alert while it plays.</div>
                                </div>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-celebration-enabled">
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Effect</label>
                            <select class="sp-select" id="set-celebration-effect">
                                <option value="fireworks">Fireworks</option>
                                <option value="confetti">Confetti</option>
                                <option value="bubbles">Bubbles</option>
                            </select>
                        </div>
                        <div class="alerts-form-group">
                            <label>Intensity</label>
                            <select class="sp-select" id="set-celebration-intensity">
                                <option value="light">Light</option>
                                <option value="medium">Medium</option>
                                <option value="heavy">Heavy</option>
                            </select>
                        </div>
                    </div>
                </section>
                <!-- Danger zone: delete variant -->
                <section class="alerts-settings-section alerts-settings-danger">
                    <header class="alerts-settings-section-header">
                        <i class="fas fa-trash"></i>
                        <span>Delete variant</span>
                        <i class="fas fa-chevron-down chevron"></i>
                    </header>
                    <div class="alerts-settings-section-body">
                        <p class="alerts-help-text">Removing this variant cannot be undone. Any conditions and uploaded media stay in your media library — only this variant's configuration is removed.</p>
                        <button type="button" class="sp-btn sp-btn-danger" id="delete-variant-btn">
                            <i class="fas fa-trash"></i> Delete this variant
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
            Variants fire in the order shown above. Drag the grip handle to reorder, or enable <strong>Randomize</strong> on a category to pick one at random when several match.
        </div>
        <div class="alerts-browser-source">
            <span class="alerts-browser-source-label">OBS browser source</span>
            <input type="password" class="sp-input alerts-browser-source-url" id="alerts-browser-source-url" readonly value="<?php echo htmlspecialchars($browserSourceUrl); ?>" title="Click to reveal">
            <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="alerts-copy-url-btn">
                <i class="fas fa-copy"></i> Copy
            </button>
        </div>
    </footer>
</div>
<!-- Media library picker — used by both image and sound "Browse library" buttons -->
<div class="sp-modal-backdrop" id="alerts-library-modal" style="display:none;">
    <div class="sp-modal alerts-library-modal-card" role="dialog" aria-modal="true" aria-labelledby="alerts-library-modal-title">
        <header class="sp-modal-head">
            <span class="sp-modal-title" id="alerts-library-modal-title">Choose from media library</span>
            <button type="button" class="sp-modal-close" id="alerts-library-modal-close" aria-label="Close">&times;</button>
        </header>
        <div class="sp-modal-body">
            <p class="alerts-help-text">Files uploaded in <a href="media.php">Media</a> appear here. Click a file to assign it to this variant.</p>
            <input type="search" class="sp-input alerts-library-search" id="alerts-library-search" placeholder="Search files…">
            <div class="alerts-library-grid" id="alerts-library-grid"></div>
            <div class="alerts-library-empty" id="alerts-library-empty" style="display:none;">
                No files of this type in your library yet. Upload one above or via <a href="media.php">Media</a>.
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
        kofi:              ['{username}', '{amount}'],
        patreon:           ['{username}'],
        fourthwall:        ['{username}', '{amount}'],
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
            sel.append('<option value="" disabled>No rewards synced yet</option>');
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
                sel.append('<option value="' + $('<div>').text(rid).html() + '" data-missing="1">Unknown reward (' + $('<div>').text(rid).html() + ')</option>');
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
        if (isDirty && !confirm('You have unsaved changes. Discard them and switch variant?')) return;
        $('.alerts-variant-item').removeClass('active');
        $(this).addClass('active');
        loadVariant(id);
    });
    function loadVariant(id) {
        currentAlertId = id;
        var a = alertsData[id];
        if (!a) return;
        loadingVariant = true;
        $('#settings-placeholder').hide();
        $('#settings-form').show();
        $('#preview-placeholder').hide();
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
        loadingVariant = false;
        clearDirty();
    }
    function updateMediaPreview(type, filename) {
        if (type === 'image') {
            if (filename) {
                var ext = filename.split('.').pop().toLowerCase();
                var url = mediaBase + filename;
                if (['webm'].includes(ext)) {
                    $('#image-preview').html('<video src="' + url + '" autoplay loop muted></video>');
                } else {
                    $('#image-preview').html('<img src="' + url + '" alt="Alert image">');
                }
                $('#image-filename').text(filename);
                $('#image-remove-btn').show();
            } else {
                $('#image-preview').empty();
                $('#image-filename').text('No image selected');
                $('#image-remove-btn').hide();
            }
        } else {
            if (filename) {
                $('#sound-filename').text(filename);
                $('#sound-remove-btn').show();
            } else {
                $('#sound-filename').text('No sound selected');
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
            .replace(/\{username\}/g, '<span class="preview-accent">PreviewUser</span>')
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
        if (id == currentAlertId) $('#set-enabled').prop('checked', this.checked);
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
                    Swal.fire({ icon: 'success', title: 'Saved', text: resp.message, timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to save. Please try again.' }); }
        });
    });
    $('#alerts-discard-btn').on('click', function() {
        if (!currentAlertId || !isDirty) return;
        Swal.fire({
            title: 'Discard changes?',
            text: 'Your unsaved changes to this variant will be lost.',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Yes, discard', cancelButtonText: 'Keep editing'
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
            celebration_area: 'full'
        };
    }
    $(document).on('click', '.alerts-new-variant-btn', function(e) {
        e.stopPropagation();
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var category = $btn.data('category');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-pulse"></i> Adding…');
        $.post('', { action: 'create_variant', category: category }, function(resp) {
            $btn.prop('disabled', false).html(origHtml);
            if (!resp.success) {
                Swal.fire({ icon: 'error', title: 'Cannot add variant', text: resp.message });
                return;
            }
            var v = resp.variant;
            alertsData[v.id] = v;
            var $cat = $('.alerts-category[data-category="' + category + '"]');
            $cat.find('.alerts-variant-empty').remove();
            var $list = $cat.find('.alerts-variant-list');
            var html = ''
                + '<li class="alerts-variant-item" data-id="' + v.id + '">'
                +   '<span class="alerts-variant-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>'
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
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to add variant. Please try again.' });
        });
    });
    $('#delete-variant-btn').on('click', function() {
        var $btn = $(this);
        if ($btn.prop('disabled') || !currentAlertId) return;
        var a = alertsData[currentAlertId];
        Swal.fire({
            title: 'Delete "' + a.variant_name + '"?',
            text: 'This variant will be permanently removed.',
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete', cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            var origHtml = $btn.html();
            var idToDelete = currentAlertId;
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-pulse"></i> Deleting…');
            $.post('', { action: 'delete_variant', id: idToDelete }, function(resp) {
                $btn.prop('disabled', false).html(origHtml);
                if (!resp.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
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
                    $cat.find('.alerts-variant-list').append('<li class="alerts-variant-empty">No variants yet — add one above.</li>');
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
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to delete variant. Please try again.' });
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
                    Swal.fire({ icon: 'error', title: 'Upload failed', text: resp.message });
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
                    Swal.fire({ icon: 'error', title: 'Upload failed', text: resp.message });
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
        // Stream bingo variants are tied to specific sub-events via their condition;
        // pick the right sub-event for the live test from the variant itself.
        if (!config && a.alert_category === 'stream_bingo') {
            var be = extractBingoEvent(a.alert_condition);
            if (!be) {
                Swal.fire({ icon: 'info', title: 'Pick a bingo event first', text: 'Choose a bingo event from the dropdown and save before testing.' });
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
            Swal.fire({ icon: 'info', title: 'Live test not available yet', text: 'Test events for this category aren\'t wired up here yet. Use the trigger in your bot to test live.' });
            return;
        }
        var params = Object.assign({ event: config.event, api_key: apiKey, channel_name: channelName }, config.params);
        $.post('notify_event.php', params, function(resp) {
            if (resp.success) {
                Swal.fire({ icon: 'success', title: 'Test sent', text: 'Check your OBS browser source.', timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to send test event.' });
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
                $btn.html('<i class="fas fa-check"></i> Copied');
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
            icon: 'info', title: 'Edit multiple — coming soon',
            text: 'Bulk-editing several variants at once is on the roadmap. For now, open each variant and use Save changes individually.'
        });
    });
    var libraryMode = null; // 'image' | 'sound'
    function openLibrary(mode) {
        libraryMode = mode;
        var files = mode === 'image' ? libraryImages : librarySounds;
        $('#alerts-library-modal-title').text(mode === 'image' ? 'Choose an alert image' : 'Choose an alert sound');
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