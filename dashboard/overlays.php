<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

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
session_write_close();
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
        <p style="font-weight:700; margin-bottom:0.4rem;"><?= t('overlays_upcoming_update_title') ?></p>
        <p style="margin-bottom:0.5rem;"><?= t('overlays_upcoming_update_body') ?></p>
        <p style="margin-bottom:0.4rem;"><?= t('overlays_root_url_note') ?></p>
        <div class="info-box" style="font-family:monospace; margin-bottom:0;">
            https://overlay.botofthespecter.com/?code=API_KEY_HERE
        </div>
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
    <!-All Overlays (full width) -->
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
    <!-- Makers & Crafting Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="sp-card-title"><i class="fas fa-palette"></i> <?= t('overlays_makers_crafting') ?></div>
            <a href="makers.php" class="sp-btn sp-btn-sm sp-btn-secondary" title="<?= htmlspecialchars(t('overlays_manage_projects')) ?>"><i class="fas fa-cog"></i></a>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_makers_crafting_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/maker.php?code=API_KEY_HERE
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
                            <span><?= t('overlays_credits_slow') ?></span>
                            <span id="creditsScrollSpeedVal"><?= intval($creditsSettings['scroll_speed']) ?></span>
                            <span><?= t('overlays_credits_fast') ?></span>
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
    <!-- Media Player (Song Requests) Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-play-circle"></i> <?= t('overlays_media_player_overlay') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_media_player_overlay_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/mediaplayer.php?code=API_KEY_HERE
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
                <?= t('overlays_chat_overlay_note') ?></p>
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
                <?= t('overlays_music_overlay_note') ?></p>
        </div>
    </div>
    <!-- Spotify Now Playing Overlay -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fab fa-spotify"></i> <?= t('overlays_spotify') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_spotify_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0.5rem;">
                https://overlay.botofthespecter.com/spotify.php?code=API_KEY_HERE
            </div>
            <p style="font-size:0.8rem; margin-top:0.5rem; color:var(--text-secondary); margin-bottom:0;">
                <?= t('overlays_spotify_themes_note') ?></p>
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
    <!-- Counters -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title">
                <i class="fas fa-hashtag"></i> <?= t('overlays_counter_display') ?>
            </div>
        </div>
        <div class="sp-card-body">
            <p style="margin-bottom:0.5rem;"><?= t('overlays_counter_display_desc') ?></p>
            <p style="font-size:0.85rem; margin-bottom:0.75rem; color:var(--text-secondary);"><?= t('overlays_counter_builtin_note') ?></p>
            <div style="display:flex; gap:0.75rem; margin-bottom:0.75rem; flex-wrap:wrap;">
                <div style="flex:1; min-width:160px;">
                    <label class="sp-label" style="font-size:0.8rem;"><?= t('overlays_counter_name_label') ?></label>
                    <input type="text" class="sp-input" id="counter-builder-name" placeholder="<?= htmlspecialchars(t('overlays_counter_name_placeholder')) ?>">
                </div>
                <div style="flex:1; min-width:140px;">
                    <label class="sp-label" style="font-size:0.8rem;"><?= t('overlays_counter_text_colour_label') ?></label>
                    <input type="text" class="sp-input" id="counter-builder-color" placeholder="<?= htmlspecialchars(t('overlays_counter_colour_placeholder')) ?>">
                </div>
                <div style="flex:1; min-width:140px;">
                    <label class="sp-label" style="font-size:0.8rem;"><?= t('overlays_counter_background_label') ?></label>
                    <input type="text" class="sp-input" id="counter-builder-bg" placeholder="<?= htmlspecialchars(t('overlays_counter_bg_placeholder')) ?>">
                </div>
            </div>
            <div style="display:flex; gap:0.5rem; align-items:stretch;">
                <input type="password" class="sp-input" id="counter-builder-url" readonly placeholder="<?= htmlspecialchars(t('overlays_counter_url_placeholder')) ?>" style="flex:1; font-family:monospace;" title="<?= htmlspecialchars(t('overlays_counter_url_title')) ?>">
                <button class="sp-btn sp-btn-primary" id="counter-builder-copy" type="button" disabled>
                    <i class="fas fa-copy"></i> <?= t('overlays_counter_copy') ?>
                </button>
            </div>
            <details style="margin-top:0.75rem;">
                <summary style="cursor:pointer; font-size:0.85rem; color:var(--text-secondary);"><?= t('overlays_counter_raw_summary') ?></summary>
                <p style="font-size:0.85rem; margin:0.5rem 0 0.25rem 0;"><?= t('overlays_counter_raw_append') ?></p>
                <ul style="margin: 0 0 0 1.25rem; list-style:disc; font-size:0.85rem;">
                    <li><code>text</code> &rarr; <em>frog: 5</em></li>
                    <li><code>number</code> &rarr; <em>5</em></li>
                    <li><code>name</code> &rarr; <em>frog</em></li>
                    <li><code>json</code> &rarr; <em>{"counter":"frog","count":5}</em></li>
                </ul>
            </details>
        </div>
    </div>
    <script>
    (function () {
        var apiKey = <?php echo json_encode($api_key); ?>;
        var copiedLabel = <?php echo json_encode(t('overlays_counter_copied')); ?>;
        var $name  = document.getElementById('counter-builder-name');
        var $color = document.getElementById('counter-builder-color');
        var $bg    = document.getElementById('counter-builder-bg');
        var $url   = document.getElementById('counter-builder-url');
        var $copy  = document.getElementById('counter-builder-copy');
        if (!$name || !$url || !$copy) return;
        function update() {
            var name = $name.value.trim();
            if (!name) { $url.value = ''; $copy.disabled = true; return; }
            var params = new URLSearchParams({ code: apiKey, counter: name });
            if ($color.value.trim()) params.set('color', $color.value.trim());
            if ($bg.value.trim())    params.set('bg',    $bg.value.trim());
            $url.value = 'https://overlay.botofthespecter.com/counters.php?' + params.toString();
            $copy.disabled = false;
        }
        $name.addEventListener('input', update);
        $color.addEventListener('input', update);
        $bg.addEventListener('input', update);
        $url.addEventListener('focus', function () { this.type = 'text'; this.select(); });
        $url.addEventListener('click', function () { if (this.value) { this.type = 'text'; this.select(); } });
        $url.addEventListener('blur',  function () { this.type = 'password'; });
        $copy.addEventListener('click', function () {
            if (!$url.value) return;
            var wasMasked = $url.type === 'password';
            if (wasMasked) $url.type = 'text';
            $url.select(); $url.setSelectionRange(0, 99999);
            navigator.clipboard.writeText($url.value).then(function () {
                var orig = $copy.innerHTML;
                $copy.innerHTML = '<i class="fas fa-check"></i> ' + copiedLabel;
                setTimeout(function () { $copy.innerHTML = orig; }, 1500);
            }).catch(function () { document.execCommand('copy'); });
            if (wasMasked) setTimeout(function () { $url.type = 'password'; }, 200);
        });
    })();
    </script>
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