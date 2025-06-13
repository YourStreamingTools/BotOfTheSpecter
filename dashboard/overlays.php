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
<div class="box has-background-info mb-5 has-text-black">
    <div class="columns is-vcentered">
        <div class="column is-narrow">
            <span class="icon is-large">
                <i class="fas fa-broadcast-tower fa-2x"></i>
            </span>
        </div>
        <div class="column">
            <h1 class="title is-4 mb-2 has-text-black"><?= t('overlays_seamless_streaming') ?></h1>
            <p class="mb-1 has-text-black"><?= t('overlays_works_with') ?></p>
            <ul class="mb-1 has-text-black">
                <li><span class="icon"><i class="fas fa-link"></i></span> <?= t('overlays_add_as_browser_source') ?></li>
                <li><span class="icon"><i class="fas fa-key"></i></span> <?= t('overlays_replace_api_key') ?></li>
            </ul>
            <p class="is-size-7 has-text-black"><span class="icon"><i class="fas fa-user-secret"></i></span> <?= t('overlays_keep_api_key_safe') ?></p>
        </div>
    </div>
</div>
<div class="columns is-multiline">
    <div class="column is-12">
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-layer-group"></i></span>
                    <?= t('overlays_all_overlays') ?>
                </p>
            </header>
            <div class="card-content">
                <div class="content">
                    <p><?= t('overlays_all_overlays_desc') ?><br>
                    <span><?= t('overlays_exceptions') ?>:</span></p>
                    <ul class="ml-4 mb-2 has-text-warning">
                        <li><?= t('overlays_stream_ending_credits') ?></li>
                        <li><?= t('overlays_todo_list') ?></li>
                        <li><?= t('overlays_video_alerts') ?></li>
                        <li><?= t('overlays_dmca_free_music') ?></li>
                        <li><?= t('overlays_external_services') ?></li>
                    </ul>
                    <p class="is-size-7 mb-2"><?= t('overlays_add_once_auto') ?></p>
                    <div class="notification is-link is-light is-family-monospace mb-0 has-text-black">
                        https://overlay.botofthespecter.com/?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Stream Ending Credits -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-scroll"></i></span>
                    <?= t('overlays_stream_ending_credits') ?>
                    <span class="tag is-danger is-light ml-2 is-size-7"><?= t('overlays_coming_soon') ?></span>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_stream_ending_credits_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/credits.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- To Do List -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-list-check"></i></span>
                    <?= t('overlays_todo_list') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_todo_list_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Death Overlay -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-skull-crossbones"></i></span>
                    <?= t('overlays_death_overlay') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_death_overlay_desc') ?><br>
                    <span class="is-size-7"><?= t('overlays_best_size_death') ?></span>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/deaths.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Weather Overlay -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-cloud-sun"></i></span>
                    <?= t('overlays_weather_overlay') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_weather_overlay_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/weather.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Discord Join Notifications -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fab fa-discord"></i></span>
                    <?= t('overlays_discord_join_notifications') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_discord_join_notifications_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/discord.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Subathon -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-hourglass-half"></i></span>
                    <?= t('overlays_subathon') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_subathon_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/subathon.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- All Audio -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-volume-up"></i></span>
                    <?= t('overlays_all_audio') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_all_audio_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/alert.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Text To Speech (TTS) Only -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-comment-dots"></i></span>
                    <?= t('overlays_tts_only') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_tts_only_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/tts.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Walkons Only -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-door-open"></i></span>
                    <?= t('overlays_walkons_only') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_walkons_only_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/walkons.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Sound Alerts Only -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-bell"></i></span>
                    <?= t('overlays_sound_alerts_only') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_sound_alerts_only_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/sound-alert.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Video Alerts -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-film"></i></span>
                    <?= t('overlays_video_alerts') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_video_alerts_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/video-alert.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Music Overlay -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-music"></i></span>
                    <?= t('overlays_music_overlay') ?>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_music_overlay_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/music.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Fourthwall -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-store"></i></span>
                    <?= t('overlays_fourthwall') ?>
                    <span class="tag is-success is-light ml-2 is-size-7"><?= t('overlays_external_service') ?></span>
                    <span class="tag is-danger is-light ml-2 is-size-7"><?= t('overlays_coming_soon') ?></span>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_fourthwall_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/fourthwall.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Ko-Fi -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-coffee"></i></span>
                    <?= t('overlays_kofi') ?>
                    <span class="tag is-success is-light ml-2 is-size-7"><?= t('overlays_external_service') ?></span>
                    <span class="tag is-danger is-light ml-2 is-size-7"><?= t('overlays_coming_soon') ?></span>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_kofi_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/kofi.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <!-- Patreon -->
        <div class="card mb-4 has-background-dark has-text-white" style="height:100%;">
            <header class="card-header">
                <p class="card-header-title has-text-white">
                    <span class="icon mr-2"><i class="fab fa-patreon"></i></span>
                    <?= t('overlays_patreon') ?>
                    <span class="tag is-success is-light ml-2 is-size-7"><?= t('overlays_external_service') ?></span>
                    <span class="tag is-danger is-light ml-2 is-size-7"><?= t('overlays_coming_soon') ?></span>
                </p>
            </header>
            <div class="card-content" style="min-height: 200px;">
                <div class="content">
                    <?= t('overlays_patreon_desc') ?>
                    <div class="notification is-family-monospace is-link is-light mt-3 mb-0 has-text-black">
                        https://overlay.botofthespecter.com/patreon.php?code=API_KEY_HERE
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
// End buffering and assign to $content
$content = ob_get_clean();
include 'layout.php';
?>