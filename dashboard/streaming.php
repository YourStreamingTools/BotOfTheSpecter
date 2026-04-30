<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

$pageTitle = t('streaming_settings_title');

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
session_write_close();

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Gate: streaming service is limited testing only
if (!$is_admin && !$betaAccess && !in_array('streaming', $betaPrograms)) {
    ob_start();
    ?>
    <div class="columns is-centered">
        <div class="column is-8-tablet is-6-desktop">
            <div class="card mt-6">
                <header class="card-header">
                    <p class="card-header-title">
                        <span class="icon mr-2"><i class="fas fa-broadcast-tower"></i></span>
                        <?= t('streaming_settings_title') ?>
                    </p>
                </header>
                <div class="card-content has-text-centered">
                    <span class="icon is-large has-text-info mb-4" style="font-size:3rem;">
                        <i class="fas fa-flask"></i>
                    </span>
                    <h2 class="title is-5 mt-4"><?= t('streaming_beta_title') ?></h2>
                    <p class="mb-4"><?= t('streaming_beta_description') ?></p>
                    <p class="mb-5"><?= t('streaming_beta_request_access') ?></p>
                    <a href="https://support.botofthespecter.com" target="_blank" rel="noopener" class="button is-info is-medium">
                        <span class="icon"><i class="fas fa-headset"></i></span>
                        <span><?= t('streaming_beta_support_link') ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    include 'layout.php';
    exit();
}

ob_start();
?>
<div class="columns is-centered">
    <div class="column is-10-tablet is-8-desktop">
        <!-- Access confirmed banner -->
        <div class="notification is-success is-light mb-5">
            <span class="icon mr-2"><i class="fas fa-circle-check"></i></span>
            <strong>Beta Access Confirmed</strong> — You have been granted access to the Streaming beta program.
        </div>
        <!-- Coming soon card -->
        <div class="card mb-5">
            <header class="card-header">
                <p class="card-header-title is-size-5">
                    <span class="icon mr-2"><i class="fas fa-tower-broadcast"></i></span>
                    BotOfTheSpecter Streaming
                </p>
            </header>
            <div class="card-content has-text-centered" style="padding:3rem 2rem;">
                <span class="icon is-large mb-5" style="font-size:4rem;color:#7c3aed;">
                    <i class="fas fa-satellite-dish"></i>
                </span>
                <h2 class="title is-4 mt-2">Coming Soon</h2>
                <p class="subtitle is-6 mt-3" style="max-width:560px;margin:0 auto;">
                    This is a complete rewrite of the old streaming system, rebuilt from the ground up to deliver
                    better performance, reliability, and features. As a beta tester, you have early access —
                    this page will be updated with streaming controls, server information, and your recordings
                    as features become available.
                </p>
                <p class="mt-4" style="color:#6b7280;font-size:0.9rem;">
                    Thank you for being part of the testing program. Keep an eye on this page for updates.
                </p>
            </div>
        </div>
        <!-- What's coming section -->
        <div class="card">
            <header class="card-header">
                <p class="card-header-title">
                    <span class="icon mr-2"><i class="fas fa-list-check"></i></span>
                    What's Being Built
                </p>
            </header>
            <div class="card-content">
                <div class="columns is-multiline">
                    <div class="column is-6">
                        <div class="media">
                            <div class="media-left">
                                <span class="icon has-text-info"><i class="fas fa-key"></i></span>
                            </div>
                            <div class="media-content">
                                <p class="has-text-weight-semibold">Stream Key Management</p>
                                <p class="is-size-7" style="color:#6b7280;">Set your Twitch stream key and configure forwarding.</p>
                            </div>
                        </div>
                    </div>
                    <div class="column is-6">
                        <div class="media">
                            <div class="media-left">
                                <span class="icon has-text-info"><i class="fas fa-video"></i></span>
                            </div>
                            <div class="media-content">
                                <p class="has-text-weight-semibold">Auto Record from Twitch</p>
                                <p class="is-size-7" style="color:#6b7280;">Automatically record your streams as they happen.</p>
                            </div>
                        </div>
                    </div>
                    <div class="column is-6">
                        <div class="media">
                            <div class="media-left">
                                <span class="icon has-text-info"><i class="fas fa-server"></i></span>
                            </div>
                            <div class="media-content">
                                <p class="has-text-weight-semibold">Multi-Region Servers</p>
                                <p class="is-size-7" style="color:#6b7280;">AU-EAST-1, US-WEST-1, and US-EAST-1 ingest points.</p>
                            </div>
                        </div>
                    </div>
                    <div class="column is-6">
                        <div class="media">
                            <div class="media-left">
                                <span class="icon has-text-info"><i class="fas fa-folder-open"></i></span>
                            </div>
                            <div class="media-content">
                                <p class="has-text-weight-semibold">Recording Library</p>
                                <p class="is-size-7" style="color:#6b7280;">Browse, play, download, and manage your recorded streams.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include 'layout.php';
?>