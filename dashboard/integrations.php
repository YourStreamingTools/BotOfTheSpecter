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
$pageTitle = t('integrations_page_title'); 

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
<div style="text-align:center; margin-bottom:1.75rem;">
    <i class="fas fa-plug fa-2x" style="color:var(--blue); margin-bottom:0.75rem; display:block;"></i>
    <h1 style="font-size:1.9rem; font-weight:800; color:var(--text-primary); margin:0 0 0.5rem;"><?= t('integrations_page_title') ?></h1>
    <p style="font-size:1rem; color:var(--text-secondary); margin:0;">
        <?= t('integrations_page_intro') ?><br>
        <?= t('integrations_page_services') ?><br>
        <?= t('integrations_page_quick_steps') ?>
    </p>
</div>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:1.5rem; align-items:start;">
    <!-- Fourthwall -->
    <div class="sp-card integration-card" id="fourthwall">
        <header class="sp-card-header">
            <p class="sp-card-title">
                <i class="fas fa-store" style="color:var(--amber);"></i>
                <?= t('integrations_fourthwall_title') ?>
            </p>
        </header>
        <div class="sp-card-body">
            <p><?= t('integrations_fourthwall_intro') ?></p>
            <ol style="padding-left:1.25rem; margin-bottom:0.75rem;">
                <li><?= t('integrations_fourthwall_step1') ?></li>
                <li><?= t('integrations_fourthwall_step2') ?></li>
                <li><?= t('integrations_fourthwall_step3') ?></li>
                <li><?= t('integrations_fourthwall_step4') ?></li>
                <li><?= t('integrations_fourthwall_step5') ?><br>
                    <code>https://api.botofthespecter.com/fourthwall?api_key=</code><br>
                    <?= t('integrations_append_api_key') ?>
                </li>
                <li><?= t('integrations_fourthwall_step6') ?>
                    <ul style="list-style:disc; padding-left:1.25rem; margin-top:0.25rem;">
                        <li><?= t('integrations_fourthwall_event_order') ?></li>
                        <li><?= t('integrations_fourthwall_event_gift') ?></li>
                        <li><?= t('integrations_fourthwall_event_donation') ?></li>
                        <li><?= t('integrations_fourthwall_event_subscription') ?></li>
                    </ul>
                </li>
            </ol>
            <p style="color:var(--green);"><strong><?= t('integrations_done') ?></strong> <?= t('integrations_fourthwall_success') ?></p>
        </div>
    </div>
    <!-- Ko-fi -->
    <div class="sp-card integration-card" id="kofi">
        <header class="sp-card-header">
            <p class="sp-card-title">
                <i class="fas fa-coffee" style="color:var(--red);"></i>
                <?= t('integrations_kofi_title') ?>
            </p>
        </header>
        <div class="sp-card-body">
            <p><?= t('integrations_kofi_intro') ?></p>
            <ol style="padding-left:1.25rem; margin-bottom:0.75rem;">
                <li><?= t('integrations_kofi_step1') ?></li>
                <li><?= t('integrations_kofi_step2') ?></li>
                <li><?= t('integrations_kofi_step3') ?></li>
                <li><?= t('integrations_kofi_step4') ?><br>
                    <code>https://api.botofthespecter.com/kofi?api_key=</code><br>
                    <?= t('integrations_append_api_key') ?>
                </li>
                <li><?= t('integrations_kofi_step5') ?></li>
            </ol>
            <p style="color:var(--green);"><strong><?= t('integrations_done') ?></strong> <?= t('integrations_kofi_success') ?></p>
        </div>
    </div>
    <!-- Patreon -->
    <div class="sp-card integration-card" id="patreon">
        <header class="sp-card-header">
            <p class="sp-card-title">
                <i class="fab fa-patreon" style="color:var(--red);"></i>
                <?= t('integrations_patreon_title') ?>
            </p>
        </header>
        <div class="sp-card-body">
            <p style="font-weight:700; color:var(--blue); margin-bottom:0.5rem;"><?= t('integrations_patreon_note') ?></p>
            <p><?= t('integrations_patreon_intro') ?></p>
            <ol style="padding-left:1.25rem; margin-bottom:0.75rem;">
                <li>
                    <?= t('integrations_patreon_step1') ?><br>
                    <a href="https://www.patreon.com/portal/registration/register-webhooks" target="_blank">https://www.patreon.com/portal/registration/register-webhooks</a>
                </li>
                <li>
                    <?= t('integrations_patreon_step2') ?><br>
                    <code>https://api.botofthespecter.com/patreon?api_key=</code><br>
                    <?= t('integrations_append_api_key') ?>
                </li>
                <li>
                    <?= t('integrations_patreon_step3') ?>
                    <ul style="list-style:disc; padding-left:1.25rem; margin-top:0.25rem;">
                        <li><?= t('integrations_patreon_event_create') ?></li>
                        <li><?= t('integrations_patreon_event_delete') ?></li>
                        <li><?= t('integrations_patreon_event_update') ?></li>
                    </ul>
                    <?= t('integrations_patreon_enable_members') ?>
                </li>
            </ol>
            <p style="color:var(--green);"><strong><?= t('integrations_done') ?></strong> <?= t('integrations_patreon_success') ?></p>
        </div>
    </div>
</div>
<?php
// End buffering and assign to $content
$content = ob_get_clean();
include 'layout.php';
?>