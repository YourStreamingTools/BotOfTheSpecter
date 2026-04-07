<?php
ob_start();
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('overlays_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Load credits overlay settings
$creditsSettings = ['scroll_speed' => 50, 'text_color' => '#FFFFFF', 'font_family' => 'Arial', 'looping' => 1];
$creditsStmt = $db->prepare("SELECT scroll_speed, text_color, font_family, looping FROM credits_overlay_settings WHERE id = 1");
if ($creditsStmt) {
    $creditsStmt->execute();
    $creditsResult = $creditsStmt->get_result();
    if ($creditsResult->num_rows > 0) {
        $creditsSettings = $creditsResult->fetch_assoc();
    }
    $creditsStmt->close();
}

// Handle credits overlay settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['credits_overlay_save'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $scrollSpeed = max(10, min(200, intval($_POST['scroll_speed'] ?? 50)));
    $textColor = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['text_color'] ?? '') ? $_POST['text_color'] : '#FFFFFF';
    $fontFamily = htmlspecialchars(trim($_POST['font_family'] ?? 'Arial'), ENT_QUOTES, 'UTF-8');
    $looping = intval(!empty($_POST['looping']));
    $saveStmt = $db->prepare("INSERT INTO credits_overlay_settings (id, scroll_speed, text_color, font_family, looping) VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE scroll_speed = VALUES(scroll_speed), text_color = VALUES(text_color), font_family = VALUES(font_family), looping = VALUES(looping)");
    $saveStmt->bind_param("issi", $scrollSpeed, $textColor, $fontFamily, $looping);
    if ($saveStmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $saveStmt->error]);
    }
    $saveStmt->close();
    exit();
}

// Start output buffering
ob_start();
?>
<div class="sp-alert sp-alert-warning" style="display:flex; gap:1.25rem; align-items:flex-start; margin-bottom:1.5rem; background-color:rgba(255, 193, 7, 0.1); border-left:4px solid var(--amber);">
    <span style="font-size:1.75rem; color:var(--amber); flex-shrink:0;"><i class="fas fa-bell"></i></span>
    <div>
        <p style="font-weight:700; margin-bottom:0.4rem;">📢 Upcoming Overlay System Update</p>
        <p style="margin-bottom:0;">We're working on improvements to our overlay system. The main overlay is temporarily showing a notice page. Use the <strong>"All Overlays"</strong> option below with <code>all.php</code> for the complete overlay experience. Check back soon for updates!</p>
    </div>
</div>
<div class="sp-alert sp-alert-info" style="display:flex; gap:1.25rem; align-items:flex-start; margin-bottom:1.5rem;">
    <span style="font-size:1.75rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-broadcast-tower"></i></span>
    <div>
        <p style="font-weight:700; margin-bottom:0.4rem;"><?= t('overlays_seamless_streaming') ?></p>
        <p style="margin-bottom:0.4rem;"><?= t('overlays_works_with') ?></p>
        <ul style="margin: 0 0 0.4rem 1.25rem; list-style:disc;">
            <li><i class="fas fa-link" style="margin-right:0.4rem;"></i> <?= t('overlays_add_as_browser_source') ?></li>
            <li><i class="fas fa-key" style="margin-right:0.4rem;"></i> <?= t('overlays_replace_api_key') ?></li>
        </ul>
        <p style="font-size:0.8rem; margin-bottom:0;"><i class="fas fa-user-secret" style="margin-right:0.4rem;"></i> <?= t('overlays_keep_api_key_safe') ?></p>
    </div>
</div>
<div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:1.5rem;">
    <!-- All Overlays (full width) -->
    <div style="grid-column:1/-1;">
        <div class="sp-card" style="margin-bottom:0;">
            <div class="sp-card-header">
                <div class="sp-card-title"><i class="fas fa-layer-group"></i> <?= t('overlays_all_overlays') ?></div>
            </div>
            <div class="sp-card-body">
                <p style="margin-bottom:0.5rem;"><?= t('overlays_all_overlays_desc') ?><br>
                <span><?= t('overlays_exceptions') ?>:</span></p>
                <ul style="margin: 0 0 0.75rem 1.25rem; list-style:disc; color:var(--amber);">
                    <li><?= t('overlays_stream_ending_credits') ?></li>
                    <li><?= t('overlays_todo_list') ?></li>
                    <li><?= t('overlays_video_alerts') ?></li>
                    <li><?= t('overlays_dmca_free_music') ?></li>
                    <li><?= t('overlays_external_services') ?></li>
                </ul>
                <p style="font-size:0.8rem; margin-bottom:0.5rem;"><?= t('overlays_add_once_auto') ?></p>
                <div class="info-box" style="font-family:monospace; margin-bottom:0;">
                    https://overlay.botofthespecter.com/all.php?code=API_KEY_HERE
                </div>
            </div>
        </div>
    </div>
    <!-- Stream Ending Credits -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="sp-card-title"><i class="fas fa-scroll"></i> <?= t('overlays_stream_ending_credits') ?></div>
            <button id="creditsSettingsBtn" class="sp-btn sp-btn-sm sp-btn-secondary" title="<?= t('overlays_credits_settings_title') ?>">
                <i class="fas fa-cog"></i>
            </button>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_stream_ending_credits_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/credits.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Credits Overlay Settings Modal -->
    <div class="sp-modal-backdrop" id="creditsSettingsModal">
        <div class="sp-modal" style="max-width:500px;">
            <header class="sp-modal-head">
                <p class="sp-modal-title"><?= t('overlays_credits_settings_title') ?></p>
                <button class="sp-modal-close" aria-label="close" id="closeCreditsSettingsModal">&times;</button>
            </header>
            <section class="sp-modal-body">
                <form id="creditsSettingsForm">
                    <div style="margin-bottom:1rem;">
                        <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('overlays_credits_scroll_speed') ?></label>
                        <input type="range" id="creditsScrollSpeed" name="scroll_speed" min="10" max="200" value="<?= intval($creditsSettings['scroll_speed']) ?>" style="width:100%;">
                        <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-secondary);">
                            <span>Slow</span>
                            <span id="creditsScrollSpeedVal"><?= intval($creditsSettings['scroll_speed']) ?></span>
                            <span>Fast</span>
                        </div>
                        <small style="color:var(--text-secondary);"><?= t('overlays_credits_scroll_speed_help') ?></small>
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('overlays_credits_text_color') ?></label>
                        <input type="color" id="creditsTextColor" name="text_color" value="<?= htmlspecialchars($creditsSettings['text_color']) ?>" style="width:60px; height:36px; border:none; cursor:pointer;">
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('overlays_credits_font_family') ?></label>
                        <select id="creditsFontFamily" name="font_family" class="sp-input" style="width:100%;">
                            <?php
                            $fonts = ['Arial', 'Verdana', 'Helvetica', 'Tahoma', 'Trebuchet MS', 'Georgia', 'Times New Roman', 'Courier New', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Oswald', 'Raleway', 'Ubuntu', 'Nunito', 'Inter'];
                            foreach ($fonts as $font):
                            ?>
                                <option value="<?= $font ?>" <?= ($creditsSettings['font_family'] === $font) ? 'selected' : '' ?> style="font-family:'<?= $font ?>';"><?= $font ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600; cursor:pointer;">
                            <input type="checkbox" id="creditsLooping" name="looping" value="1" <?= $creditsSettings['looping'] ? 'checked' : '' ?>>
                            <?= t('overlays_credits_looping') ?>
                        </label>
                        <small style="color:var(--text-secondary);"><?= t('overlays_credits_looping_help') ?></small>
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                        <span id="creditsSaveStatus" style="align-self:center; font-size:0.85rem;"></span>
                        <button type="submit" class="sp-btn sp-btn-primary"><?= t('overlays_credits_save') ?></button>
                    </div>
                </form>
            </section>
        </div>
    </div>
    <!-- To Do List -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-list-check"></i> <?= t('overlays_todo_list') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_todo_list_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Death Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-skull-crossbones"></i> <?= t('overlays_death_overlay') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_death_overlay_desc') ?><br>
            <small><?= t('overlays_best_size_death') ?></small>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/deaths.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Weather Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-cloud-sun"></i> <?= t('overlays_weather_overlay') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_weather_overlay_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/weather.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Discord Join Notifications -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fab fa-discord"></i> <?= t('overlays_discord_join_notifications') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_discord_join_notifications_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/discord.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Subathon -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-hourglass-half"></i> <?= t('overlays_subathon') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_subathon_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/subathon.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- All Audio -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-volume-up"></i> <?= t('overlays_all_audio') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_all_audio_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/alert.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Text To Speech (TTS) Only -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-comment-dots"></i> <?= t('overlays_tts_only') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_tts_only_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/tts.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Walkons Only -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-door-open"></i> <?= t('overlays_walkons_only') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_walkons_only_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/walkons.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Sound Alerts Only -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-bell"></i> <?= t('overlays_sound_alerts_only') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_sound_alerts_only_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/sound-alert.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Video Alerts -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-film"></i> <?= t('overlays_video_alerts') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_video_alerts_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/video-alert.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Chat Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title">
                <i class="fas fa-comments"></i> <?= t('overlays_chat_overlay') ?>
                <span class="sp-badge sp-badge-red" style="font-size:0.72rem;">Beta 5.8</span>
            </div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_chat_overlay_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0.5rem;">
                https://overlay.botofthespecter.com/chat.php?code=API_KEY_HERE
            </div>
            <p style="font-size:0.8rem; margin-top:0.5rem; color:var(--text-secondary); margin-bottom:0;">
                Customize the message cap with <code>&amp;max=30</code> (default: 20).<br>
                Use <code>&amp;count=2</code> (default: 1) to run multiple Chat Overlay sources at the same time across different OBS scenes.</p>
        </div>
    </div>
    <!-- Music Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-music"></i> <?= t('overlays_music_overlay') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_music_overlay_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0.5rem;">
                https://overlay.botofthespecter.com/music.php?code=API_KEY_HERE
            </div>
            <p style="font-size:0.8rem; margin-top:0.5rem; color:var(--text-secondary); margin-bottom:0;">
                Add <code>&amp;nowplaying</code> to the URL to display now playing text.<br>
                Example: <code>music.php?code=API_KEY_HERE&amp;nowplaying&amp;color=white</code>.<br>
                Customize color with <code>&amp;color=white</code>.</p>
        </div>
    </div>
    <!-- Fourthwall -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title">
                <i class="fas fa-store"></i> <?= t('overlays_fourthwall') ?>
                <span class="sp-badge sp-badge-green" style="font-size:0.72rem;"><?= t('overlays_external_service') ?></span>
                <span class="sp-badge sp-badge-red" style="font-size:0.72rem;"><?= t('overlays_coming_soon') ?></span>
            </div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_fourthwall_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/fourthwall.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Ko-Fi -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title">
                <i class="fas fa-coffee"></i> <?= t('overlays_kofi') ?>
                <span class="sp-badge sp-badge-green" style="font-size:0.72rem;"><?= t('overlays_external_service') ?></span>
                <span class="sp-badge sp-badge-red" style="font-size:0.72rem;"><?= t('overlays_coming_soon') ?></span>
            </div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_kofi_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/kofi.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
    <!-- Patreon -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title">
                <i class="fab fa-patreon"></i> <?= t('overlays_patreon') ?>
                <span class="sp-badge sp-badge-green" style="font-size:0.72rem;"><?= t('overlays_external_service') ?></span>
                <span class="sp-badge sp-badge-red" style="font-size:0.72rem;"><?= t('overlays_coming_soon') ?></span>
            </div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_patreon_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/patreon.php?code=API_KEY_HERE
            </div>
        </div>
    </div>
</div>
<?php
// End buffering and assign to $content
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var openBtn = document.getElementById('creditsSettingsBtn');
    var modal = document.getElementById('creditsSettingsModal');
    var closeBtn = document.getElementById('closeCreditsSettingsModal');
    var form = document.getElementById('creditsSettingsForm');
    var speedSlider = document.getElementById('creditsScrollSpeed');
    var speedVal = document.getElementById('creditsScrollSpeedVal');
    var statusEl = document.getElementById('creditsSaveStatus');
    if (!openBtn || !modal) return;
    speedSlider.addEventListener('input', function () {
        speedVal.textContent = this.value;
    });
    openBtn.addEventListener('click', function () {
        modal.classList.add('is-active');
    });
    closeBtn.addEventListener('click', function () {
        modal.classList.remove('is-active');
    });
    modal.addEventListener('click', function (e) {
        if (e.target === modal) modal.classList.remove('is-active');
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var formData = new FormData(form);
        formData.append('credits_overlay_save', '1');
        statusEl.textContent = '';
        statusEl.style.color = '';
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                statusEl.style.color = 'var(--green)';
                statusEl.textContent = <?= json_encode(t('overlays_credits_saved')) ?>;
            } else {
                statusEl.style.color = 'var(--red)';
                statusEl.textContent = <?= json_encode(t('overlays_credits_save_error')) ?>;
            }
        })
        .catch(function () {
            statusEl.style.color = 'var(--red)';
            statusEl.textContent = <?= json_encode(t('overlays_credits_save_error')) ?>;
        });
    });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>