<?php
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

// Start output buffering
ob_start();
?>
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
                    https://overlay.botofthespecter.com/?code=API_KEY_HERE
                </div>
            </div>
        </div>
    </div>
    <!-- Stream Ending Credits -->
    <div class="sp-card" style="margin-bottom:0;">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-scroll"></i> <?= t('overlays_stream_ending_credits') ?></div>
        </div>
        <div class="sp-card-body">
            <?= t('overlays_stream_ending_credits_desc') ?>
            <div class="info-box" style="font-family:monospace; margin-top:1rem; margin-bottom:0;">
                https://overlay.botofthespecter.com/credits.php?code=API_KEY_HERE
            </div>
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
include 'layout.php';
?>