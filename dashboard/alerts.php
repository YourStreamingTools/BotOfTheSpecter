<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = 'Twitch Alerts';

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';

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

// Default alert variants to seed on first visit
$defaultAlerts = [
    ['follow', 'New Follow', 0, null, "{username}\njust followed!"],
    ['subscription', 'New Subscriber', 0, 'months = 1', "{username}\njust subscribed!"],
    ['subscription', 'New Subscriber', 1, 'months >= 2', "{username}\nsubscribed for {months} months!"],
    ['gift_subscription', 'New Subscriber Gift', 0, null, "{username}\ngifted a subscription!"],
    ['gift_subscription', 'New Subscriber Gift', 1, 'gift_count >= 1', "{username}\ngifted {amount} subs!"],
    ['bits', 'First Time Cheer', 0, 'is_first = 1', "{username}\njust cheered for the first time!"],
    ['bits', '100 Bits', 1, 'bits >= 100', "{username}\ncheered {amount} bits!"],
    ['bits', '1k Bits', 2, 'bits >= 1000', "{username}\ncheered {amount} bits!"],
    ['bits', '5K Bits', 3, 'bits >= 5000', "{username}\ncheered {amount} bits!"],
    ['bits', '10K Bits', 4, 'bits >= 10000', "{username}\ncheered {amount} bits!"],
    ['raid', 'New Raid', 0, 'viewers >= 1', "{username}\nis raiding with {viewers} viewers!"],
    ['charity', 'New Charity Donation', 0, null, "{username}\ndonated to charity!"],
    ['hype_train', 'Hype Train Started', 0, null, "Hype Train Started!"],
    ['hype_train', 'Hype Train New All Time High', 1, null, "New All Time High!"],
    ['channel_points', '1st in Chat', 0, null, "{username}\nis 1st in chat!"],
    ['goals', 'Goal Started', 0, null, "A new goal has started!"],
    ['goals', 'Goal Reached', 1, null, "Goal reached!"],
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
        $accent_color = $_POST['accent_color'] ?? '#A1C53A';
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
            echo json_encode(['success' => true, 'message' => 'Alert settings saved.']);
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

    if ($action === 'upload_alert_media') {
        if (!isset($_FILES['media_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
            exit;
        }
        $file = $_FILES['media_file'];
        $allowedExts = ['webm', 'gif', 'png', 'jpg', 'jpeg', 'mp3'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExts)]);
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
        $targetFile = $media_path . '/' . basename($file['name']);
        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            echo json_encode(['success' => true, 'filename' => basename($file['name']), 'message' => 'File uploaded.']);
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
        echo json_encode(['success' => true, 'message' => 'Media removed from alert.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Fetch all alerts grouped by category
$allAlerts = [];
$alertsByCategory = [];
$result = $db->query("SELECT * FROM twitch_alerts ORDER BY alert_category, variant_index");
while ($row = $result->fetch_assoc()) {
    $allAlerts[$row['id']] = $row;
    $alertsByCategory[$row['alert_category']][] = $row;
}
$alertsJson = json_encode($allAlerts);

// Category display metadata
$categoryMeta = [
    'follow' => ['icon' => 'fas fa-heart', 'label' => 'Follows'],
    'subscription' => ['icon' => 'fas fa-star', 'label' => 'Subscriptions'],
    'gift_subscription' => ['icon' => 'fas fa-gift', 'label' => 'Gifted Subscriptions'],
    'bits' => ['icon' => 'fas fa-gem', 'label' => 'Bits'],
    'raid' => ['icon' => 'fas fa-users', 'label' => 'Raids'],
    'charity' => ['icon' => 'fas fa-hand-holding-heart', 'label' => 'Charity'],
    'hype_train' => ['icon' => 'fas fa-train', 'label' => 'Hype Trains'],
    'channel_points' => ['icon' => 'fas fa-circle', 'label' => 'Channel Points'],
    'goals' => ['icon' => 'fas fa-bullseye', 'label' => 'Goals'],
];

// Animation options
$animationsIn = ['Fade-In' => 'fadeIn', 'Slide-Left' => 'slideInLeft', 'Slide-Right' => 'slideInRight', 'Slide-Up' => 'slideInUp', 'Slide-Down' => 'slideInDown', 'Bounce' => 'bounceIn', 'Zoom' => 'zoomIn'];
$animationsOut = ['Fade-Out' => 'fadeOut', 'Slide-Left' => 'slideOutLeft', 'Slide-Right' => 'slideOutRight', 'Slide-Up' => 'slideOutUp', 'Slide-Down' => 'slideOutDown', 'Bounce' => 'bounceOut', 'Zoom' => 'zoomOut'];

// Font options
$fonts = ['Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Oswald', 'Raleway', 'Poppins', 'Nunito', 'Ubuntu', 'Bebas Neue', 'Bangers', 'Permanent Marker', 'Press Start 2P', 'Creepster'];
$fontWeights = ['Light' => '300', 'Regular' => '400', 'Medium' => '500', 'Semi-Bold' => '600', 'Bold' => '700', 'Extra-Bold' => '800'];

// Media base URL for preview
$mediaBase = $media_migrated ? "https://media.botofthespecter.com/$username/" : "https://soundalerts.botofthespecter.com/$username/";

ob_start();
?>
<div class="sp-alert sp-alert-info">
    <i class="fas fa-info-circle"></i> <strong>Coming Soon</strong> &mdash; The Twitch Alerts configuration system is currently under development. Settings are view-only for now and will be fully functional in a future update.
</div>
<div class="sp-card" style="opacity:0.5;pointer-events:none;">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-bell"></i> Twitch Alerts</span>
        <div style="display:flex;gap:8px;">
            <button class="sp-btn sp-btn-primary" id="preview-alert-btn" disabled>
                <i class="fas fa-eye"></i> Preview Alert
            </button>
            <button class="sp-btn sp-btn-warning" id="test-alert-btn" disabled>
                <i class="fas fa-paper-plane"></i> Send Test Alert
            </button>
        </div>
    </header>
    <div class="sp-card-body alerts-layout">
        <!-- LEFT: Category Sidebar -->
        <div class="alerts-sidebar">
            <?php foreach ($categoryMeta as $category => $meta):
                $variants = $alertsByCategory[$category] ?? [];
                $isHidden = ($category === 'goals');
            ?>
            <div class="alerts-category" data-category="<?php echo $category; ?>"<?php if ($isHidden) echo ' style="display:none"'; ?>>
                <div class="alerts-category-header">
                    <i class="<?php echo $meta['icon']; ?>"></i>
                    <span><?php echo htmlspecialchars($meta['label']); ?></span>
                    <i class="fas fa-chevron-right chevron"></i>
                </div>
                <div class="alerts-category-variants">
                    <?php foreach ($variants as $variant): ?>
                    <div class="alerts-variant-item" data-id="<?php echo $variant['id']; ?>">
                        <div>
                            <div class="variant-name"><?php echo htmlspecialchars($variant['variant_name']); ?></div>
                            <?php if ($variant['alert_condition']): ?>
                            <div class="variant-condition"><?php echo htmlspecialchars($variant['alert_condition']); ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="alerts-toggle-badge <?php echo $variant['enabled'] ? 'enabled' : 'disabled'; ?>"></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- CENTER: Preview Panel -->
        <div class="alerts-preview-panel">
            <div class="alerts-preview-area" id="preview-area">
                <div class="alerts-no-selection" id="preview-placeholder">
                    Select an alert variant to preview
                </div>
                <div class="alerts-preview-box" id="preview-box" style="display:none;">
                    <div class="alerts-preview-content" id="preview-content">
                        <img src="" alt="" class="preview-image" id="preview-img" style="display:none;">
                        <div class="preview-text" id="preview-text"></div>
                    </div>
                </div>
            </div>
            <div class="alerts-preview-options">
                <label>Preview Options</label>
                <label>Width px</label>
                <input type="number" class="sp-input" id="preview-width" value="800" min="200" max="1920">
                <label>Height px</label>
                <input type="number" class="sp-input" id="preview-height" value="600" min="200" max="1080">
                <div style="display:flex;gap:4px;margin-left:auto;">
                    <div class="preview-bg-swatch active" data-bg="transparent" title="Transparent"></div>
                    <div class="preview-bg-swatch" data-bg="dark" title="Dark"></div>
                    <div class="preview-bg-swatch" data-bg="light" title="Light"></div>
                    <div class="preview-bg-swatch" data-bg="red" title="Red"></div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Settings Panel -->
        <div class="alerts-settings-panel" id="settings-panel">
            <div class="alerts-no-selection" id="settings-placeholder">
                Select an alert variant to edit
            </div>
            <div id="settings-form" style="display:none;">
                <!-- General Settings -->
                <div class="alerts-settings-section open">
                    <div class="alerts-settings-section-header">
                        <i class="fas fa-cog"></i>
                        <span>General Settings</span>
                        <i class="fas fa-chevron-right chevron"></i>
                    </div>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label>Variant Name</label>
                            <input type="text" class="sp-input" id="set-variant-name">
                        </div>
                        <div class="alerts-form-group">
                            <label>Alert Condition</label>
                            <input type="text" class="sp-input" id="set-alert-condition" placeholder="e.g. bits >= 100">
                        </div>
                        <div class="alerts-form-group">
                            <label>Duration (In seconds, 99 max)</label>
                            <input type="number" class="sp-input" id="set-duration" min="1" max="99" value="8">
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Animation In</label>
                                <div style="display:flex;gap:8px;">
                                    <select class="sp-select" id="set-animation-in" style="flex:1;">
                                        <?php foreach ($animationsIn as $label => $val): ?>
                                        <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="sp-input" id="set-animation-in-duration" min="0.1" max="5" step="0.1" value="1" style="width:60px;">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Animation Out</label>
                                <div style="display:flex;gap:8px;">
                                    <select class="sp-select" id="set-animation-out" style="flex:1;">
                                        <?php foreach ($animationsOut as $label => $val): ?>
                                        <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="sp-input" id="set-animation-out-duration" min="0.1" max="5" step="0.1" value="1" style="width:60px;">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label style="margin:0;">Enabled</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-enabled" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Layout -->
                <div class="alerts-settings-section">
                    <div class="alerts-settings-section-header">
                        <i class="fas fa-th-large"></i>
                        <span>Layout</span>
                        <i class="fas fa-chevron-right chevron"></i>
                    </div>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <div class="layout-presets">
                                <button type="button" class="layout-preset-btn active" data-layout="above" title="Image above text">
                                    <svg viewBox="0 0 48 36"><rect x="16" y="2" width="16" height="12" rx="2" fill="#888"/><rect x="8" y="18" width="32" height="3" rx="1" fill="#aaa"/><rect x="12" y="24" width="24" height="3" rx="1" fill="#666"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="right" title="Text left, image right">
                                    <svg viewBox="0 0 48 36"><rect x="30" y="6" width="14" height="24" rx="2" fill="#888"/><rect x="4" y="10" width="22" height="3" rx="1" fill="#aaa"/><rect x="4" y="17" width="18" height="3" rx="1" fill="#666"/><rect x="4" y="24" width="20" height="3" rx="1" fill="#666"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="left" title="Image left, text right">
                                    <svg viewBox="0 0 48 36"><rect x="4" y="6" width="14" height="24" rx="2" fill="#888"/><rect x="22" y="10" width="22" height="3" rx="1" fill="#aaa"/><rect x="22" y="17" width="18" height="3" rx="1" fill="#666"/><rect x="22" y="24" width="20" height="3" rx="1" fill="#666"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="below" title="Text above, image below">
                                    <svg viewBox="0 0 48 36"><rect x="8" y="2" width="32" height="3" rx="1" fill="#aaa"/><rect x="12" y="8" width="24" height="3" rx="1" fill="#666"/><rect x="16" y="16" width="16" height="16" rx="2" fill="#888"/></svg>
                                </button>
                                <button type="button" class="layout-preset-btn" data-layout="behind" title="Image behind text">
                                    <svg viewBox="0 0 48 36"><rect x="2" y="2" width="44" height="32" rx="2" fill="#555" opacity="0.5"/><rect x="8" y="12" width="32" height="3" rx="1" fill="#aaa"/><rect x="12" y="18" width="24" height="3" rx="1" fill="#aaa"/></svg>
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
                                <label>Opacity (percent)</label>
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
                                <label style="margin:0;">Rounded Corners</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-rounded-corners" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label style="margin:0;">Drop Shadow</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-drop-shadow" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Text & Speech -->
                <div class="alerts-settings-section">
                    <div class="alerts-settings-section-header">
                        <i class="fas fa-font"></i>
                        <span>Text & Speech</span>
                        <i class="fas fa-chevron-right chevron"></i>
                    </div>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label>Message</label>
                            <textarea id="set-message-template" placeholder="{username}&#10;just followed!"></textarea>
                            <div class="variable-hints">
                                Variables: <code>{username}</code> <code>{amount}</code> <code>{months}</code> <code>{viewers}</code> <code>{tier}</code>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Font</label>
                            <div style="display:flex;gap:8px;">
                                <select class="sp-select" id="set-font-family" style="flex:1;">
                                    <?php foreach ($fonts as $font): ?>
                                    <option value="<?php echo $font; ?>"><?php echo $font; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="sp-select" id="set-font-weight" style="width:110px;">
                                    <?php foreach ($fontWeights as $label => $val): ?>
                                    <option value="<?php echo $label; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="sp-input" id="set-font-size" min="8" max="120" value="24" style="width:60px;">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Text Layout</label>
                            <div class="text-align-btns">
                                <button type="button" class="text-align-btn" data-align="left" title="Left"><i class="fas fa-align-left"></i></button>
                                <button type="button" class="text-align-btn active" data-align="center" title="Center"><i class="fas fa-align-center"></i></button>
                                <button type="button" class="text-align-btn" data-align="right" title="Right"><i class="fas fa-align-right"></i></button>
                                <button type="button" class="text-align-btn" data-align="justify" title="Justify"><i class="fas fa-align-justify"></i></button>
                                <span style="width:8px;"></span>
                                <button type="button" class="text-align-btn valign-btn" data-valign="top" title="Top"><i class="fas fa-arrow-up"></i></button>
                                <button type="button" class="text-align-btn valign-btn active" data-valign="center" title="Middle"><i class="fas fa-arrows-alt-v"></i></button>
                                <button type="button" class="text-align-btn valign-btn" data-valign="bottom" title="Bottom"><i class="fas fa-arrow-down"></i></button>
                            </div>
                        </div>
                        <div class="alerts-form-row">
                            <div class="alerts-form-group">
                                <label>Text Color</label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-text-color" value="#FFFFFF">
                                    <input type="text" class="sp-input" id="set-text-color-text" value="#FFFFFF">
                                </div>
                            </div>
                            <div class="alerts-form-group">
                                <label>Accent Color</label>
                                <div class="alerts-color-input">
                                    <input type="color" id="set-accent-color" value="#A1C53A">
                                    <input type="text" class="sp-input" id="set-accent-color-text" value="#A1C53A">
                                </div>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <label style="margin:0;">Drop Shadow</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-text-drop-shadow" checked>
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group" style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.06);">
                            <div class="alerts-toggle-wrap">
                                <label style="margin:0;text-transform:uppercase;font-size:12px;font-weight:600;letter-spacing:0.5px;">Text-to-Speech</label>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-tts-enabled">
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                            <div style="font-size:11px;color:#888;margin-top:4px;">Say Alert Text</div>
                        </div>
                    </div>
                </div>

                <!-- Visuals & Sound -->
                <div class="alerts-settings-section">
                    <div class="alerts-settings-section-header">
                        <i class="fas fa-image"></i>
                        <span>Visuals & Sound</span>
                        <i class="fas fa-chevron-right chevron"></i>
                    </div>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <label>Alert Image</label>
                            <div class="alerts-media-upload" id="image-upload-zone">
                                <div class="alerts-media-preview" id="image-preview"></div>
                                <div class="alerts-media-filename" id="image-filename">No image selected</div>
                                <div class="alerts-media-actions">
                                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="image-upload-btn">
                                        <i class="fas fa-upload"></i> Upload File
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="image-remove-btn" style="display:none;">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                                <input type="file" id="image-file-input" accept=".webm,.gif,.png,.jpg,.jpeg">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Scale</label>
                            <div class="alerts-range-wrap">
                                <input type="range" id="set-image-scale" min="0" max="200" value="100">
                                <span class="alerts-range-value" id="image-scale-val">100%</span>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Image Volume</label>
                            <div class="alerts-range-wrap">
                                <input type="range" id="set-image-volume" min="0" max="100" value="0">
                                <span class="alerts-range-value" id="image-volume-val">0%</span>
                            </div>
                        </div>
                        <div class="alerts-form-group" style="margin-top:20px;">
                            <label>Alert Sound</label>
                            <div class="alerts-media-upload" id="sound-upload-zone">
                                <div class="alerts-media-filename" id="sound-filename">No sound selected</div>
                                <div class="alerts-media-actions">
                                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="sound-upload-btn">
                                        <i class="fas fa-upload"></i> Upload File
                                    </button>
                                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="sound-remove-btn" style="display:none;">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                                <input type="file" id="sound-file-input" accept=".mp3">
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Sound Volume</label>
                            <div class="alerts-range-wrap">
                                <span style="margin-right:4px;"><i class="fas fa-play" style="font-size:11px;color:#888;"></i></span>
                                <input type="range" id="set-sound-volume" min="0" max="100" value="50">
                                <span class="alerts-range-value" id="sound-volume-val">50%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Celebration (hidden) -->
                <div class="alerts-settings-section" style="display:none;">
                    <div class="alerts-settings-section-header">
                        <i class="fas fa-wand-magic-sparkles"></i>
                        <span>Celebration</span>
                        <i class="fas fa-chevron-right chevron"></i>
                    </div>
                    <div class="alerts-settings-section-body">
                        <div class="alerts-form-group">
                            <div class="alerts-toggle-wrap">
                                <div>
                                    <label style="margin:0;text-transform:uppercase;font-size:12px;font-weight:600;">Celebrations</label>
                                    <div style="font-size:11px;color:#888;">Add celebration animations to jazz up your Alerts.</div>
                                </div>
                                <label class="alerts-toggle">
                                    <input type="checkbox" id="set-celebration-enabled">
                                    <span class="alerts-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="alerts-form-group">
                            <label>Effects</label>
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
                </div>

                <!-- Save -->
                <div class="alerts-save-bar">
                    <button class="sp-btn sp-btn-primary" id="save-alert-btn" style="width:100%;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
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
    let currentAlertId = null;

    // Category accordion
    $(document).on('click', '.alerts-category-header', function() {
        $(this).closest('.alerts-category').toggleClass('open');
    });

    // Settings section accordion
    $(document).on('click', '.alerts-settings-section-header', function() {
        $(this).closest('.alerts-settings-section').toggleClass('open');
    });

    // Variant selection
    $(document).on('click', '.alerts-variant-item', function() {
        var id = $(this).data('id');
        $('.alerts-variant-item').removeClass('active');
        $(this).addClass('active');
        loadAlert(id);
    });

    function loadAlert(id) {
        currentAlertId = id;
        var a = alertsData[id];
        if (!a) return;

        $('#settings-placeholder').hide();
        $('#settings-form').show();
        $('#preview-placeholder').hide();
        $('#preview-box').show();
        $('#preview-alert-btn, #test-alert-btn').prop('disabled', false);

        // General
        $('#set-variant-name').val(a.variant_name);
        $('#set-alert-condition').val(a.alert_condition || '');
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
    }

    function updateMediaPreview(type, filename) {
        if (type === 'image') {
            if (filename) {
                var ext = filename.split('.').pop().toLowerCase();
                var url = mediaBase + filename;
                if (['webm'].includes(ext)) {
                    $('#image-preview').html('<video src="' + url + '" autoplay loop muted style="max-width:150px;max-height:100px;border-radius:4px;"></video>');
                } else {
                    $('#image-preview').html('<img src="' + url + '" alt="Alert Image" style="max-width:150px;max-height:100px;border-radius:4px;">');
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

        // Font weight mapping
        var weightMap = {'Light':'300','Regular':'400','Medium':'500','Semi-Bold':'600','Bold':'700','Extra-Bold':'800'};
        var cssWeight = weightMap[fontWeight] || '600';

        // Parse bg color to rgba
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

        // Image
        var imgFile = a.alert_image || $('#image-file-input').data('uploaded');
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

        // Text
        var displayMsg = msg.replace(/\{username\}/g, '<span class="preview-accent">maxart</span>')
            .replace(/\{amount\}/g, '<span class="preview-accent">100</span>')
            .replace(/\{months\}/g, '<span class="preview-accent">3</span>')
            .replace(/\{viewers\}/g, '<span class="preview-accent">42</span>')
            .replace(/\{tier\}/g, '<span class="preview-accent">1</span>')
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

        // Load Google Font
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

    // Live preview updates on any input change
    $(document).on('input change', '#settings-form input, #settings-form select, #settings-form textarea', function() {
        updatePreview();
    });

    // Layout preset buttons
    $(document).on('click', '.layout-preset-btn', function() {
        $('.layout-preset-btn').removeClass('active');
        $(this).addClass('active');
        updatePreview();
    });

    // Text alignment buttons
    $(document).on('click', '.text-align-btn[data-align]', function() {
        $('.text-align-btn[data-align]').removeClass('active');
        $(this).addClass('active');
        updatePreview();
    });
    $(document).on('click', '.valign-btn', function() {
        $('.valign-btn').removeClass('active');
        $(this).addClass('active');
        updatePreview();
    });

    // Color input sync
    $('#set-bg-color').on('input', function() { $('#set-bg-color-text').val(this.value); });
    $('#set-bg-color-text').on('change', function() { $('#set-bg-color').val(this.value); updatePreview(); });
    $('#set-text-color').on('input', function() { $('#set-text-color-text').val(this.value); });
    $('#set-text-color-text').on('change', function() { $('#set-text-color').val(this.value); updatePreview(); });
    $('#set-accent-color').on('input', function() { $('#set-accent-color-text').val(this.value); });
    $('#set-accent-color-text').on('change', function() { $('#set-accent-color').val(this.value); updatePreview(); });

    // Range slider display updates
    $('#set-image-scale').on('input', function() { $('#image-scale-val').text(this.value + '%'); });
    $('#set-image-volume').on('input', function() { $('#image-volume-val').text(this.value + '%'); });
    $('#set-sound-volume').on('input', function() { $('#sound-volume-val').text(this.value + '%'); });

    // Preview background swatches
    $(document).on('click', '.preview-bg-swatch', function() {
        $('.preview-bg-swatch').removeClass('active');
        $(this).addClass('active');
        var bg = $(this).data('bg');
        var area = $('#preview-area');
        switch(bg) {
            case 'transparent':
                area.css('background-color', 'transparent');
                area.css('background-image', 'repeating-conic-gradient(rgba(255,255,255,0.03) 0% 25%, transparent 0% 50%)');
                break;
            case 'dark':
                area.css({'background-color': '#18181b', 'background-image': 'none'});
                break;
            case 'light':
                area.css({'background-color': '#fff', 'background-image': 'none'});
                break;
            case 'red':
                area.css({'background-color': '#e74c3c', 'background-image': 'none'});
                break;
        }
    });

    // Enabled toggle - immediate save
    $('#set-enabled').on('change', function() {
        if (!currentAlertId) return;
        var enabled = this.checked ? 1 : 0;
        alertsData[currentAlertId].enabled = enabled;
        var $badge = $('.alerts-variant-item[data-id="' + currentAlertId + '"] .alerts-toggle-badge');
        $badge.toggleClass('enabled', this.checked).toggleClass('disabled', !this.checked);
        $.post('', { action: 'toggle_alert', id: currentAlertId, enabled: enabled }, null, 'json');
    });

    // Save alert
    $('#save-alert-btn').on('click', function() {
        if (!currentAlertId) return;
        var data = {
            action: 'save_alert',
            id: currentAlertId,
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
        $.ajax({
            url: '',
            type: 'POST',
            data: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    // Update local data
                    Object.assign(alertsData[currentAlertId], data);
                    // Update sidebar name
                    $('.alerts-variant-item[data-id="' + currentAlertId + '"] .variant-name').text(data.variant_name);
                    Swal.fire({ icon: 'success', title: 'Saved', text: resp.message, timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to save. Please try again.' });
            }
        });
    });

    // File upload - Image
    $('#image-upload-btn').on('click', function() { $('#image-file-input').click(); });
    $('#image-file-input').on('change', function() {
        if (!this.files[0] || !currentAlertId) return;
        var formData = new FormData();
        formData.append('action', 'upload_alert_media');
        formData.append('media_file', this.files[0]);
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alertsData[currentAlertId].alert_image = resp.filename;
                    updateMediaPreview('image', resp.filename);
                    updatePreview();
                } else {
                    Swal.fire({ icon: 'error', title: 'Upload Failed', text: resp.message });
                }
            }
        });
    });
    $('#image-remove-btn').on('click', function() {
        if (!currentAlertId) return;
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'remove_alert_media', id: currentAlertId, field: 'alert_image' },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alertsData[currentAlertId].alert_image = null;
                    updateMediaPreview('image', null);
                    updatePreview();
                }
            }
        });
    });

    // File upload - Sound
    $('#sound-upload-btn').on('click', function() { $('#sound-file-input').click(); });
    $('#sound-file-input').on('change', function() {
        if (!this.files[0] || !currentAlertId) return;
        var formData = new FormData();
        formData.append('action', 'upload_alert_media');
        formData.append('media_file', this.files[0]);
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alertsData[currentAlertId].alert_sound = resp.filename;
                    updateMediaPreview('sound', resp.filename);
                } else {
                    Swal.fire({ icon: 'error', title: 'Upload Failed', text: resp.message });
                }
            }
        });
    });
    $('#sound-remove-btn').on('click', function() {
        if (!currentAlertId) return;
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'remove_alert_media', id: currentAlertId, field: 'alert_sound' },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alertsData[currentAlertId].alert_sound = null;
                    updateMediaPreview('sound', null);
                }
            }
        });
    });

    // Preview Alert button - animate in preview area
    $('#preview-alert-btn').on('click', function() {
        if (!currentAlertId) return;
        var a = alertsData[currentAlertId];
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

    // Send Test Alert button
    $('#test-alert-btn').on('click', function() {
        if (!currentAlertId) return;
        var a = alertsData[currentAlertId];
        var eventMap = {
            'follow': { event: 'TWITCH_FOLLOW', params: { user: 'TestUser' } },
            'subscription': { event: 'TWITCH_SUB', params: { user: 'TestUser', sub_tier: '1', sub_months: '3' } },
            'gift_subscription': { event: 'TWITCH_SUB', params: { user: 'TestUser', sub_tier: '1', sub_months: '1' } },
            'bits': { event: 'TWITCH_CHEER', params: { user: 'TestUser', cheer_amount: '100' } },
            'raid': { event: 'TWITCH_RAID', params: { user: 'TestUser', raid_viewers: '42' } },
        };
        var config = eventMap[a.alert_category];
        if (!config) {
            Swal.fire({ icon: 'info', title: 'Test Not Available', text: 'Test events for this category are not yet supported.' });
            return;
        }
        var params = Object.assign({ event: config.event, api_key: apiKey }, config.params);
        $.post('notify_event.php', params, function(resp) {
            if (resp.success) {
                Swal.fire({ icon: 'success', title: 'Test Sent', text: 'Check your overlay!', timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to send test event.' });
            }
        }, 'json');
    });

    // Auto-open first category and select first variant
    var $firstCategory = $('.alerts-category:visible:first');
    if ($firstCategory.length) {
        $firstCategory.addClass('open');
        var $firstVariant = $firstCategory.find('.alerts-variant-item:first');
        if ($firstVariant.length) {
            $firstVariant.click();
        }
    }
});
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>
