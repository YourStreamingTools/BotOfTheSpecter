<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

require_once '/var/www/config/db_connect.php';
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/bot_control.php';
include 'includes/mod_access.php';
include 'includes/user_db.php';
include 'includes/storage_used.php';
include 'includes/file_paths.php';
require_once __DIR__ . '/includes/upload_helpers.php';
session_write_close();

$pageTitle = t('avatar_page_title');

$overlayLink = 'https://overlay.botofthespecter.com/avatar.php';
$overlayLinkWithCode = $overlayLink . '?code=' . rawurlencode($api_key);
$overlayLinkMasked = $overlayLink . '?code=' . str_repeat('•', 24);

$allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'custom'];
$avatarImageExts = ['png', 'webp'];
$avatarMediaDir = rtrim($media_path, '/\\') . '/avatar';
$avatarMediaUrl = 'https://media.botofthespecter.com/' . rawurlencode($username) . '/avatar/';
const AVATAR_IMAGE_MIN_PX = 128;
const AVATAR_IMAGE_MAX_PX = 4096;
const AVATAR_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

$avatarUploadSlots = [
    'idle_open' => 'closed_image',
    'idle_blink' => 'closed_blink_image',
    'talk_open' => 'open_image',
    'talk_blink' => 'open_blink_image',
];

$av = [
    'enabled' => 0,
    'closed_image' => null,
    'open_image' => null,
    'closed_blink_image' => null,
    'open_blink_image' => null,
    'position' => 'bottom-right',
    'pos_x' => 0,
    'pos_y' => 0,
    'scale' => 1.00,
    'flip' => 0,
    'mic_threshold' => 0.080,
    'attack_ms' => 40,
    'release_ms' => 180,
    'blink_enabled' => 1,
    'blink_interval_min' => 3,
    'blink_interval_max' => 6,
    'bounce_enabled' => 1,
    'bounce_intensity' => 5,
];

$avStmt = $db->prepare(
    'SELECT enabled, closed_image, open_image, closed_blink_image, open_blink_image, position, pos_x, pos_y, scale, flip, '
    . 'mic_threshold, attack_ms, release_ms, blink_enabled, blink_interval_min, blink_interval_max, '
    . 'bounce_enabled, bounce_intensity FROM avatar_settings WHERE id = 1'
);
if (!$avStmt) {
    $avStmt = $db->prepare(
        'SELECT enabled, closed_image, open_image, position, pos_x, pos_y, scale, flip, '
        . 'mic_threshold, attack_ms, release_ms, blink_enabled, blink_interval_min, blink_interval_max, '
        . 'bounce_enabled, bounce_intensity FROM avatar_settings WHERE id = 1'
    );
}
if ($avStmt) {
    $avStmt->execute();
    $avResult = $avStmt->get_result();
    if ($avResult->num_rows > 0) {
        $av = array_merge($av, $avResult->fetch_assoc());
    }
    $avStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_upload'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $slot = $_POST['slot'] ?? '';
    if (!isset($avatarUploadSlots[$slot])) {
        echo json_encode(['success' => false, 'error' => 'invalid_slot']);
        exit;
    }
    if (!isset($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error', ['no file'])]);
        exit;
    }
    $tmp = $_FILES['image']['tmp_name'];
    $orig = $_FILES['image']['name'] ?? 'image.png';
    $size = (int) ($_FILES['image']['size'] ?? 0);
    if ($size <= 0 || $size > AVATAR_IMAGE_MAX_BYTES) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error_file_size', [(int) (AVATAR_IMAGE_MAX_BYTES / (1024 * 1024))])]);
        exit;
    }
    $dims = @getimagesize($tmp);
    if (!$dims || empty($dims[0]) || empty($dims[1])) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error_invalid')]);
        exit;
    }
    $imgW = (int) $dims[0];
    $imgH = (int) $dims[1];
    if ($imgW < AVATAR_IMAGE_MIN_PX || $imgH < AVATAR_IMAGE_MIN_PX) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error_too_small', [AVATAR_IMAGE_MIN_PX, $imgW, $imgH])]);
        exit;
    }
    if ($imgW > AVATAR_IMAGE_MAX_PX || $imgH > AVATAR_IMAGE_MAX_PX) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error_too_large', [AVATAR_IMAGE_MAX_PX, $imgW, $imgH])]);
        exit;
    }
    if (!is_uploaded_file($tmp)) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error', ['invalid upload'])]);
        exit;
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $avatarImageExts, true) || !upload_validate_extension_and_mime($tmp, $ext, $avatarImageExts)) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error', ['invalid type'])]);
        exit;
    }
    if (!is_dir($avatarMediaDir)) {
        mkdir($avatarMediaDir, 0755, true);
    }
    $col = $avatarUploadSlots[$slot];
    $oldFilename = !empty($av[$col]) ? basename((string) $av[$col]) : '';
    $oldPath = $oldFilename !== '' ? $avatarMediaDir . '/' . $oldFilename : '';
    $oldSize = ($oldPath !== '' && is_file($oldPath)) ? filesize($oldPath) : 0;
    $safeName = upload_sanitize_filename($orig, $ext);
    $target = upload_unique_target($avatarMediaDir, $safeName);
    if (!upload_reencode_image($tmp, $target['path'], $ext, AVATAR_IMAGE_MAX_PX, AVATAR_IMAGE_MIN_PX)) {
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error_invalid')]);
        exit;
    }
    $savedSize = filesize($target['path']);
    $projectedUsed = $current_storage_used - $oldSize + $savedSize;
    if ($projectedUsed > $max_storage_size) {
        @unlink($target['path']);
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error_storage')]);
        exit;
    }
    $saveUp = $db->prepare("INSERT INTO avatar_settings (id, {$col}) VALUES (1, ?) ON DUPLICATE KEY UPDATE {$col} = VALUES({$col})");
    $saveUp->bind_param('s', $target['name']);
    $ok = $saveUp->execute();
    $saveUp->close();
    if (!$ok) {
        @unlink($target['path']);
        echo json_encode(['success' => false, 'error' => t('avatar_upload_error', ['db'])]);
        exit;
    }
    if ($oldFilename !== '' && $oldFilename !== $target['name'] && is_file($oldPath)) {
        $stillReferenced = false;
        foreach ($avatarUploadSlots as $otherCol) {
            if ($otherCol === $col) {
                continue;
            }
            if (!empty($av[$otherCol]) && basename((string) $av[$otherCol]) === $oldFilename) {
                $stillReferenced = true;
                break;
            }
        }
        if (!$stillReferenced) {
            @unlink($oldPath);
        }
    }
    $current_storage_used = $projectedUsed;
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
    echo json_encode([
        'success' => true,
        'filename' => $target['name'],
        'url' => $avatarMediaUrl . rawurlencode($target['name']),
        'message' => t('avatar_upload_success', [$target['name']]),
        'storage_used' => $current_storage_used,
        'max_storage' => $max_storage_size,
        'storage_percentage' => $storage_percentage,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_save'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $position = in_array($_POST['position'] ?? '', $allowedPositions, true) ? $_POST['position'] : 'bottom-right';
    $posX = max(0, min(4000, (int) ($_POST['pos_x'] ?? 0)));
    $posY = max(0, min(4000, (int) ($_POST['pos_y'] ?? 0)));
    $scale = max(0.1, min(5.0, (float) ($_POST['scale'] ?? 1)));
    $flip = !empty($_POST['flip']) ? 1 : 0;
    $threshold = max(0.001, min(1.0, (float) ($_POST['mic_threshold'] ?? 0.08)));
    $attackMs = max(0, min(2000, (int) ($_POST['attack_ms'] ?? 40)));
    $releaseMs = max(0, min(5000, (int) ($_POST['release_ms'] ?? 180)));
    $blinkEnabled = !empty($_POST['blink_enabled']) ? 1 : 0;
    $blinkMin = max(1, min(60, (int) ($_POST['blink_interval_min'] ?? 3)));
    $blinkMax = max($blinkMin, min(120, (int) ($_POST['blink_interval_max'] ?? 6)));
    $bounceEnabled = !empty($_POST['bounce_enabled']) ? 1 : 0;
    $bounceIntensity = max(0, min(10, (int) ($_POST['bounce_intensity'] ?? 5)));

    $saveStmt = $db->prepare(
        'INSERT INTO avatar_settings (id, enabled, position, pos_x, pos_y, scale, flip, mic_threshold, attack_ms, release_ms, '
        . 'blink_enabled, blink_interval_min, blink_interval_max, bounce_enabled, bounce_intensity) '
        . 'VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), position = VALUES(position), pos_x = VALUES(pos_x), '
        . 'pos_y = VALUES(pos_y), scale = VALUES(scale), flip = VALUES(flip), mic_threshold = VALUES(mic_threshold), '
        . 'attack_ms = VALUES(attack_ms), release_ms = VALUES(release_ms), blink_enabled = VALUES(blink_enabled), '
        . 'blink_interval_min = VALUES(blink_interval_min), blink_interval_max = VALUES(blink_interval_max), '
        . 'bounce_enabled = VALUES(bounce_enabled), bounce_intensity = VALUES(bounce_intensity)'
    );
    if (!$saveStmt) {
        echo json_encode(['success' => false, 'error' => $db->error]);
        exit;
    }
    $saveStmt->bind_param(
        'isiiididiiiiii',
        $enabled,
        $position,
        $posX,
        $posY,
        $scale,
        $flip,
        $threshold,
        $attackMs,
        $releaseMs,
        $blinkEnabled,
        $blinkMin,
        $blinkMax,
        $bounceEnabled,
        $bounceIntensity
    );
    if ($saveStmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $saveStmt->error]);
    }
    $saveStmt->close();
    exit;
}

while (ob_get_level()) { ob_end_clean(); }

$avatarFrameUrl = function ($filename) use ($avatarMediaUrl) {
    if (!$filename) {
        return '';
    }
    return $avatarMediaUrl . rawurlencode(basename((string) $filename));
};
$frameUrls = [
    'idle_open' => $avatarFrameUrl($av['closed_image'] ?? null),
    'idle_blink' => $avatarFrameUrl($av['closed_blink_image'] ?? null),
    'talk_open' => $avatarFrameUrl($av['open_image'] ?? null),
    'talk_blink' => $avatarFrameUrl($av['open_blink_image'] ?? null),
];

ob_start();
?>
<div class="sp-alert sp-alert-info media-storage-bar" id="avStorageBar">
    <div class="media-storage-header">
        <span><i class="fas fa-database"></i> <strong><?= t('alerts_storage_usage') ?>:</strong></span>
        <span id="avStorageText"><?= round($current_storage_used / 1024 / 1024, 2) ?>MB / <?= round($max_storage_size / 1024 / 1024, 2) ?>MB (<?= round($storage_percentage, 2) ?>%)</span>
    </div>
    <progress class="progress" id="avStorageProgress" value="<?= $storage_percentage ?>" max="100"></progress>
    <p class="av-help-text av-storage-note"><?= t('avatar_storage_note') ?></p>
</div>

<div class="sp-page-header">
    <h1><i class="fas fa-user-astronaut"></i> <?= t('avatar_page_title') ?></h1>
    <p><?= t('avatar_intro_description') ?></p>
</div>

<div class="sp-card av-url-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-link"></i> <?= t('avatar_overlay_url_title') ?></div>
    </div>
    <div class="sp-card-body">
        <p class="av-help-text"><?= t('avatar_overlay_url_desc') ?></p>
        <div class="av-url-row">
            <code class="info-box av-url-box" id="avOverlayUrl"><?= htmlspecialchars($overlayLinkMasked) ?></code>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" id="avUrlReveal" aria-pressed="false"><i class="fas fa-eye"></i> <span class="av-url-reveal-label"><?= t('avatar_overlay_url_show') ?></span></button>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-primary" id="avUrlCopy"><i class="fas fa-copy"></i> <span class="av-url-copy-label"><?= t('avatar_overlay_url_copy') ?></span></button>
        </div>
    </div>
</div>

<div class="sp-alert sp-alert-info av-browser-note">
    <span class="av-browser-note-icon"><i class="fas fa-circle-info"></i></span>
    <div>
        <p class="av-browser-note-title"><?= t('avatar_browser_note_title') ?></p>
        <p class="av-browser-note-body"><?= t('avatar_browser_note_body') ?></p>
    </div>
</div>

<div class="av-layout">
    <div style="display:flex;flex-direction:column;gap:1.5rem;">
        <div class="sp-card">
            <div class="sp-card-header">
                <div class="sp-card-title"><i class="fas fa-microphone"></i> <?= t('avatar_mic_title') ?></div>
                <span class="status-indicator offline" id="avMicStatus"><?= t('avatar_status_idle') ?></span>
            </div>
            <div class="sp-card-body">
                <p class="av-help-text"><?= t('avatar_mic_desc') ?></p>
                <div class="av-control-row">
                    <button type="button" id="avStartBtn" class="sp-btn sp-btn-success sp-btn-block"><i class="fas fa-play"></i> <?= t('avatar_start_mic') ?></button>
                    <button type="button" id="avStopBtn" class="sp-btn sp-btn-danger sp-btn-block" disabled><i class="fas fa-stop"></i> <?= t('avatar_stop_mic') ?></button>
                </div>
                <div class="av-preview-wrap">
                    <div class="av-preview-label"><?= t('avatar_live_preview') ?></div>
                    <div class="av-preview-stage" id="avPreview">
                        <img class="av-preview-img" id="avPreviewImg" alt="" <?php if ($frameUrls['idle_open']): ?>src="<?= htmlspecialchars($frameUrls['idle_open']) ?>"<?php endif; ?>>
                        <span class="av-preview-placeholder" id="avPreviewPlaceholder"><?= t('avatar_preview_placeholder') ?></span>
                    </div>
                </div>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="avTestBtn" style="margin-top:0.75rem;"><i class="fas fa-comment-dots"></i> <?= t('avatar_test_btn') ?></button>
            </div>
        </div>
    </div>

    <form id="avSettingsForm" class="sp-card" enctype="multipart/form-data">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-palette"></i> <?= t('avatar_appearance_title') ?></div>
        </div>
        <div class="sp-card-body">
            <label class="sp-checkbox-label">
                <input type="checkbox" id="avEnabled" name="enabled" value="1" <?= !empty($av['enabled']) ? 'checked' : '' ?>>
                <?= t('avatar_enabled_label') ?>
            </label>
            <p class="av-help-text"><?= t('avatar_enabled_help') ?></p>

            <p class="av-help-text" style="margin-top:0;"><strong><?= t('avatar_images_title') ?></strong> — <?= t('avatar_images_help') ?></p>
            <div class="av-form-grid av-frame-grid">
                <?php
                $frameUploadFields = [
                    'idle_open' => 'avatar_idle_open_label',
                    'idle_blink' => 'avatar_idle_blink_label',
                    'talk_open' => 'avatar_talk_open_label',
                    'talk_blink' => 'avatar_talk_blink_label',
                ];
                foreach ($frameUploadFields as $slotKey => $labelKey):
                    $col = $avatarUploadSlots[$slotKey];
                    $fileName = $av[$col] ?? null;
                ?>
                <div>
                    <label><?= t($labelKey) ?></label>
                    <input type="file" class="sp-input av-frame-upload" data-slot="<?= htmlspecialchars($slotKey) ?>" accept="image/png,image/webp">
                    <?php if ($fileName): ?><small class="av-file-name"><?= htmlspecialchars(basename((string) $fileName)) ?></small><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="av-help-text"><?= t('avatar_upload_help', [AVATAR_IMAGE_MIN_PX, AVATAR_IMAGE_MAX_PX, (int) (AVATAR_IMAGE_MAX_BYTES / (1024 * 1024))]) ?></p>

            <div class="av-form-grid">
                <div>
                    <label for="avPosition"><?= t('avatar_position_label') ?></label>
                    <select id="avPosition" name="position" class="sp-input">
                        <?php foreach ($allowedPositions as $pos): ?>
                            <option value="<?= htmlspecialchars($pos) ?>" <?= $av['position'] === $pos ? 'selected' : '' ?>>
                                <?= t('avatar_pos_' . str_replace('-', '_', $pos)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="avScale"><?= t('avatar_scale_label') ?> (<span id="avScaleVal"><?= htmlspecialchars((string) $av['scale']) ?></span>)</label>
                    <input type="range" id="avScale" name="scale" min="0.2" max="3" step="0.05" value="<?= htmlspecialchars((string) $av['scale']) ?>" class="sp-input">
                </div>
                <div class="av-custom-pos">
                    <label for="avPosX"><?= t('avatar_pos_x_label') ?></label>
                    <input type="number" id="avPosX" name="pos_x" min="0" max="4000" value="<?= (int) $av['pos_x'] ?>" class="sp-input">
                </div>
                <div class="av-custom-pos">
                    <label for="avPosY"><?= t('avatar_pos_y_label') ?></label>
                    <input type="number" id="avPosY" name="pos_y" min="0" max="4000" value="<?= (int) $av['pos_y'] ?>" class="sp-input">
                </div>
            </div>
            <label class="sp-checkbox-label">
                <input type="checkbox" id="avFlip" name="flip" value="1" <?= !empty($av['flip']) ? 'checked' : '' ?>>
                <?= t('avatar_flip_label') ?>
            </label>

            <hr style="border-color:var(--border);margin:1.25rem 0;">

            <div class="av-form-grid">
                <div>
                    <label for="avThreshold"><?= t('avatar_mic_threshold_label') ?> (<span id="avThresholdVal"><?= htmlspecialchars((string) $av['mic_threshold']) ?></span>)</label>
                    <input type="range" id="avThreshold" name="mic_threshold" min="0.01" max="0.5" step="0.005" value="<?= htmlspecialchars((string) $av['mic_threshold']) ?>" class="sp-input">
                    <small class="av-help-text"><?= t('avatar_mic_threshold_help') ?></small>
                </div>
                <div>
                    <label for="avAttack"><?= t('avatar_attack_ms_label') ?></label>
                    <input type="number" id="avAttack" name="attack_ms" min="0" max="2000" value="<?= (int) $av['attack_ms'] ?>" class="sp-input">
                </div>
                <div>
                    <label for="avRelease"><?= t('avatar_release_ms_label') ?></label>
                    <input type="number" id="avRelease" name="release_ms" min="0" max="5000" value="<?= (int) $av['release_ms'] ?>" class="sp-input">
                </div>
            </div>

            <div class="av-form-grid">
                <div>
                    <label class="sp-checkbox-label">
                        <input type="checkbox" id="avBlink" name="blink_enabled" value="1" <?= !empty($av['blink_enabled']) ? 'checked' : '' ?>>
                        <?= t('avatar_blink_enabled_label') ?>
                    </label>
                </div>
                <div>
                    <label for="avBlinkMin"><?= t('avatar_blink_min_label') ?></label>
                    <input type="number" id="avBlinkMin" name="blink_interval_min" min="1" max="60" value="<?= (int) $av['blink_interval_min'] ?>" class="sp-input">
                </div>
                <div>
                    <label for="avBlinkMax"><?= t('avatar_blink_max_label') ?></label>
                    <input type="number" id="avBlinkMax" name="blink_interval_max" min="1" max="120" value="<?= (int) $av['blink_interval_max'] ?>" class="sp-input">
                </div>
                <div>
                    <label class="sp-checkbox-label">
                        <input type="checkbox" id="avBounce" name="bounce_enabled" value="1" <?= !empty($av['bounce_enabled']) ? 'checked' : '' ?>>
                        <?= t('avatar_bounce_enabled_label') ?>
                    </label>
                </div>
                <div>
                    <label for="avBounceIntensity"><?= t('avatar_bounce_intensity_label') ?> (<span id="avBounceVal"><?= (int) $av['bounce_intensity'] ?></span>)</label>
                    <input type="range" id="avBounceIntensity" name="bounce_intensity" min="0" max="10" step="1" value="<?= (int) $av['bounce_intensity'] ?>" class="sp-input">
                </div>
            </div>

            <div class="av-save-row">
                <span class="av-save-status" id="avSaveStatus"></span>
                <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-save"></i> <?= t('avatar_save') ?></button>
            </div>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
<script>
(function () {
    const apiKey = <?php echo json_encode($api_key); ?>;
    const avLang = {
        idle: <?php echo json_encode(t('avatar_status_idle')); ?>,
        listening: <?php echo json_encode(t('avatar_status_listening')); ?>,
        starting: <?php echo json_encode(t('avatar_status_starting')); ?>,
        micDenied: <?php echo json_encode(t('avatar_error_mic_denied')); ?>,
        saved: <?php echo json_encode(t('avatar_saved')); ?>,
        saveError: <?php echo json_encode(t('avatar_save_error')); ?>,
        urlShow: <?php echo json_encode(t('avatar_overlay_url_show')); ?>,
        urlHide: <?php echo json_encode(t('avatar_overlay_url_hide')); ?>,
        urlCopied: <?php echo json_encode(t('avatar_overlay_url_copied')); ?>,
    };
    const avUrlReal = <?php echo json_encode($overlayLinkWithCode); ?>;
    const avUrlMasked = <?php echo json_encode($overlayLinkMasked); ?>;
    const frameUrls = <?php echo json_encode($frameUrls); ?>;

    let vadConfig = {
        threshold: <?php echo json_encode((float) $av['mic_threshold']); ?>,
        attackMs: <?php echo json_encode((int) $av['attack_ms']); ?>,
        releaseMs: <?php echo json_encode((int) $av['release_ms']); ?>,
    };
    let micRunning = false;
    let mouthState = 'idle';
    let isBlinking = false;
    let blinkPreviewTimer = null;
    let audioContext = null;
    let mediaStream = null;
    let analyser = null;
    let sourceNode = null;
    let muteNode = null;
    let rafId = null;
    let releaseTimer = null;
    let stateHeartbeat = null;

    const socketUrl = 'wss://websocket.botofthespecter.com';
    let socket = null;
    let socketReady = false;
    let attempts = 0;

    const scheduleReconnect = () => {
        attempts += 1;
        const delay = Math.min(5000 * attempts, 30000);
        if (socket) { socket.removeAllListeners(); socket = null; }
        setTimeout(connectSocket, delay);
    };
    function connectSocket() {
        socket = io(socketUrl, { reconnection: false });
        socketReady = false;
        socket.on('connect', () => {
            attempts = 0;
            socketReady = true;
            socket.emit('REGISTER', { code: apiKey, channel: 'Dashboard', name: 'Avatar Dashboard' });
            if (micRunning) {
                pushAvatarState(mouthState, true);
            }
        });
        socket.on('disconnect', () => { socketReady = false; scheduleReconnect(); });
        socket.on('connect_error', () => { socketReady = false; scheduleReconnect(); });
        socket.on('AVATAR_STATE_REQUEST', () => {
            if (micRunning) {
                pushAvatarState(mouthState, true);
            }
        });
    }
    connectSocket();

    const pushAvatarState = (state, force) => {
        if (!socket || !socketReady || !socket.connected) return false;
        socket.emit('AVATAR_STATE', { code: apiKey, state: state, expression: 'default' });
        return true;
    };

    const emitAvatarState = (state, force) => {
        if (!force && mouthState === state) return;
        mouthState = state;
        pushAvatarState(state, true);
        updatePreviewFrame();
    };

    const startStateHeartbeat = () => {
        stopStateHeartbeat();
        stateHeartbeat = setInterval(() => {
            if (!micRunning) return;
            pushAvatarState(mouthState, true);
        }, 1500);
    };

    const stopStateHeartbeat = () => {
        if (stateHeartbeat) {
            clearInterval(stateHeartbeat);
            stateHeartbeat = null;
        }
    };

    const micStatus = document.getElementById('avMicStatus');
    const startBtn = document.getElementById('avStartBtn');
    const stopBtn = document.getElementById('avStopBtn');
    const previewImg = document.getElementById('avPreviewImg');
    const previewPlaceholder = document.getElementById('avPreviewPlaceholder');

    const pickFrameUrl = () => {
        const talking = mouthState === 'talking';
        if (talking) {
            if (isBlinking && frameUrls.talk_blink) return frameUrls.talk_blink;
            return frameUrls.talk_open || frameUrls.talk_blink || '';
        }
        if (isBlinking && frameUrls.idle_blink) return frameUrls.idle_blink;
        return frameUrls.idle_open || frameUrls.idle_blink || '';
    };

    const setStatus = (text, state) => {
        if (!micStatus) return;
        micStatus.textContent = text;
        micStatus.className = 'status-indicator ' + state;
    };

    const updatePreviewFrame = () => {
        const url = pickFrameUrl();
        if (previewImg) {
            if (url) {
                previewImg.src = url;
                previewImg.classList.remove('av-hidden');
            } else {
                previewImg.removeAttribute('src');
                previewImg.classList.add('av-hidden');
            }
        }
        if (previewPlaceholder) {
            previewPlaceholder.style.display = url ? 'none' : '';
        }
    };

    const schedulePreviewBlink = () => {
        if (blinkPreviewTimer) clearTimeout(blinkPreviewTimer);
        const blinkOn = document.getElementById('avBlink');
        if (!blinkOn || !blinkOn.checked) return;
        const min = parseInt(document.getElementById('avBlinkMin')?.value || '3', 10);
        const max = parseInt(document.getElementById('avBlinkMax')?.value || '6', 10);
        const lo = Math.max(1, min);
        const hi = Math.max(lo, max);
        const delay = (lo + Math.random() * (hi - lo)) * 1000;
        blinkPreviewTimer = setTimeout(() => {
            isBlinking = true;
            updatePreviewFrame();
            setTimeout(() => {
                isBlinking = false;
                updatePreviewFrame();
                schedulePreviewBlink();
            }, 120);
        }, delay);
    };

    const computeRms = () => {
        if (!analyser) return 0;
        const buf = new Uint8Array(analyser.fftSize);
        analyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) {
            const v = (buf[i] - 128) / 128;
            sum += v * v;
        }
        return Math.sqrt(sum / buf.length);
    };

    const tick = () => {
        if (!micRunning) return;
        const rms = computeRms();
        if (rms >= vadConfig.threshold) {
            if (releaseTimer) { clearTimeout(releaseTimer); releaseTimer = null; }
            emitAvatarState('talking');
        } else if (mouthState === 'talking' && !releaseTimer) {
            releaseTimer = setTimeout(() => {
                releaseTimer = null;
                emitAvatarState('idle');
            }, vadConfig.releaseMs);
        }
        rafId = requestAnimationFrame(tick);
    };

    const stopMic = () => {
        micRunning = false;
        stopStateHeartbeat();
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        if (releaseTimer) { clearTimeout(releaseTimer); releaseTimer = null; }
        if (sourceNode) { try { sourceNode.disconnect(); } catch (e) {} sourceNode = null; }
        if (analyser) { try { analyser.disconnect(); } catch (e) {} analyser = null; }
        if (muteNode) { try { muteNode.disconnect(); } catch (e) {} muteNode = null; }
        if (mediaStream) { mediaStream.getTracks().forEach(t => t.stop()); mediaStream = null; }
        if (audioContext) { try { audioContext.close(); } catch (e) {} audioContext = null; }
        emitAvatarState('idle');
        setStatus(avLang.idle, 'offline');
        if (startBtn) startBtn.disabled = false;
        if (stopBtn) stopBtn.disabled = true;
    };

    const startMic = async () => {
        setStatus(avLang.starting, 'warn');
        try {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: { channelCount: 1, echoCancellation: false, noiseSuppression: false, autoGainControl: false }
            });
            if (audioContext.state === 'suspended') await audioContext.resume();
            sourceNode = audioContext.createMediaStreamSource(mediaStream);
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 2048;
            muteNode = audioContext.createGain();
            muteNode.gain.value = 0;
            sourceNode.connect(analyser);
            analyser.connect(muteNode);
            muteNode.connect(audioContext.destination);
            micRunning = true;
            if (startBtn) startBtn.disabled = true;
            if (stopBtn) stopBtn.disabled = false;
            setStatus(avLang.listening, 'online');
            emitAvatarState('idle', true);
            startStateHeartbeat();
            rafId = requestAnimationFrame(tick);
        } catch (e) {
            setStatus(avLang.micDenied, 'offline');
            stopMic();
        }
    };

    if (startBtn) startBtn.addEventListener('click', startMic);
    if (stopBtn) stopBtn.addEventListener('click', stopMic);
    window.addEventListener('beforeunload', () => { if (micRunning) stopMic(); });

    const testBtn = document.getElementById('avTestBtn');
    if (testBtn) {
        testBtn.addEventListener('click', () => {
            if (!socket || !socketReady) return;
            socket.emit('AVATAR_STATE', { code: apiKey, state: 'talking', expression: 'default' });
            setTimeout(() => {
                if (socket && socketReady) socket.emit('AVATAR_STATE', { code: apiKey, state: 'idle', expression: 'default' });
            }, 1000);
            mouthState = 'talking';
            updatePreviewFrame();
            setTimeout(() => {
                mouthState = 'idle';
                updatePreviewFrame();
            }, 1000);
        });
    }

    const uploadImage = (slot, file) => {
        const fd = new FormData();
        fd.append('avatar_upload', '1');
        fd.append('slot', slot);
        fd.append('image', file);
        return fetch(window.location.pathname, { method: 'POST', body: fd }).then(r => r.json());
    };

    document.querySelectorAll('.av-frame-upload').forEach((input) => {
        input.addEventListener('change', () => {
            const slot = input.getAttribute('data-slot');
            const f = input.files && input.files[0];
            if (!slot || !f) return;
            uploadImage(slot, f).then((data) => {
                if (data.success) {
                    frameUrls[slot] = data.url;
                    const nameEl = input.parentElement && input.parentElement.querySelector('.av-file-name');
                    if (nameEl) nameEl.textContent = data.filename;
                    updatePreviewFrame();
                    if (typeof data.storage_used === 'number' && typeof data.max_storage === 'number') {
                        const usedMb = (data.storage_used / 1024 / 1024).toFixed(2);
                        const maxMb = (data.max_storage / 1024 / 1024).toFixed(2);
                        const pct = typeof data.storage_percentage === 'number'
                            ? data.storage_percentage.toFixed(2)
                            : ((data.storage_used / data.max_storage) * 100).toFixed(2);
                        const textEl = document.getElementById('avStorageText');
                        const progEl = document.getElementById('avStorageProgress');
                        if (textEl) textEl.textContent = usedMb + 'MB / ' + maxMb + 'MB (' + pct + '%)';
                        if (progEl) progEl.value = pct;
                    }
                } else if (data && data.error) {
                    alert(data.error);
                }
            });
        });
    });

    const positionEl = document.getElementById('avPosition');
    const toggleCustomPos = () => {
        const custom = positionEl && positionEl.value === 'custom';
        document.querySelectorAll('.av-custom-pos').forEach(el => {
            el.style.display = custom ? '' : 'none';
        });
    };
    if (positionEl) { positionEl.addEventListener('change', toggleCustomPos); toggleCustomPos(); }

    const scaleEl = document.getElementById('avScale');
    const scaleVal = document.getElementById('avScaleVal');
    if (scaleEl && scaleVal) scaleEl.addEventListener('input', () => { scaleVal.textContent = scaleEl.value; });
    const threshEl = document.getElementById('avThreshold');
    const threshVal = document.getElementById('avThresholdVal');
    if (threshEl && threshVal) threshEl.addEventListener('input', () => {
        threshVal.textContent = threshEl.value;
        vadConfig.threshold = parseFloat(threshEl.value);
    });
    const bounceEl = document.getElementById('avBounceIntensity');
    const bounceVal = document.getElementById('avBounceVal');
    if (bounceEl && bounceVal) bounceEl.addEventListener('input', () => { bounceVal.textContent = bounceEl.value; });

    const form = document.getElementById('avSettingsForm');
    const saveStatus = document.getElementById('avSaveStatus');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            fd.append('avatar_save', '1');
            fd.set('enabled', document.getElementById('avEnabled').checked ? '1' : '0');
            fd.set('flip', document.getElementById('avFlip').checked ? '1' : '0');
            fd.set('blink_enabled', document.getElementById('avBlink').checked ? '1' : '0');
            fd.set('bounce_enabled', document.getElementById('avBounce').checked ? '1' : '0');
            vadConfig.threshold = parseFloat(document.getElementById('avThreshold').value);
            vadConfig.attackMs = parseInt(document.getElementById('avAttack').value, 10);
            vadConfig.releaseMs = parseInt(document.getElementById('avRelease').value, 10);
            if (saveStatus) { saveStatus.textContent = ''; saveStatus.className = 'av-save-status'; }
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success && socket && socketReady) {
                        socket.emit('AVATAR_SETTINGS_UPDATE', { code: apiKey });
                    }
                    if (!saveStatus) return;
                    if (data && data.success) {
                        saveStatus.textContent = avLang.saved;
                        saveStatus.className = 'av-save-status is-success';
                    } else {
                        saveStatus.textContent = avLang.saveError;
                        saveStatus.className = 'av-save-status is-error';
                    }
                })
                .catch(() => {
                    if (saveStatus) {
                        saveStatus.textContent = avLang.saveError;
                        saveStatus.className = 'av-save-status is-error';
                    }
                });
        });
    }

    const avUrlBox = document.getElementById('avOverlayUrl');
    const avUrlReveal = document.getElementById('avUrlReveal');
    const avUrlCopy = document.getElementById('avUrlCopy');
    let urlRevealed = false;
    if (avUrlReveal && avUrlBox) {
        avUrlReveal.addEventListener('click', () => {
            urlRevealed = !urlRevealed;
            avUrlBox.textContent = urlRevealed ? avUrlReal : avUrlMasked;
            avUrlReveal.setAttribute('aria-pressed', urlRevealed ? 'true' : 'false');
            const lbl = avUrlReveal.querySelector('.av-url-reveal-label');
            if (lbl) lbl.textContent = urlRevealed ? avLang.urlHide : avLang.urlShow;
        });
    }
    if (avUrlCopy) {
        avUrlCopy.addEventListener('click', () => {
            navigator.clipboard.writeText(avUrlReal).then(() => {
                const lbl = avUrlCopy.querySelector('.av-url-copy-label');
                if (!lbl) return;
                const orig = lbl.textContent;
                lbl.textContent = avLang.urlCopied;
                setTimeout(() => { lbl.textContent = orig; }, 1500);
            }).catch(() => {});
        });
    }

    updatePreviewFrame();
    schedulePreviewBlink();
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>