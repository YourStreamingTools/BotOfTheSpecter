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
<div class="has-text-centered mb-6">
  <span class="icon is-large has-text-info mb-2">
    <i class="fas fa-plug fa-2x"></i>
  </span>
  <h1 class="title is-3 mb-2"><?= t('integrations_page_title') ?></h1>
  <p class="subtitle is-5 has-text-white">
    <?= t('integrations_page_intro') ?><br>
    <?= t('integrations_page_services') ?><br>
    <?= t('integrations_page_quick_steps') ?>
  </p>
</div>
<div class="columns is-variable is-6 is-centered integration-columns">
  <div class="column is-12-mobile is-6-tablet is-4-desktop">
    <div class="card integration-card" id="fourthwall">
      <header class="card-header">
        <p class="card-header-title">
          <span class="icon has-text-warning mr-2"><i class="fas fa-store"></i></span>
          <?= t('integrations_fourthwall_title') ?>
        </p>
      </header>
      <div class="card-content has-text-wrap">
        <div class="content">
          <p><?= t('integrations_fourthwall_intro') ?></p>
          <ol class="mb-3">
            <li><?= t('integrations_fourthwall_step1') ?></li>
            <li><?= t('integrations_fourthwall_step2') ?></li>
            <li><?= t('integrations_fourthwall_step3') ?></li>
            <li><?= t('integrations_fourthwall_step4') ?></li>
            <li><?= t('integrations_fourthwall_step5') ?><br>
              <code>https://api.botofthespecter.com/fourthwall?api_key=</code><br>
              <?= t('integrations_append_api_key') ?>
            </li>
            <li><?= t('integrations_fourthwall_step6') ?>
              <ul>
                <li><?= t('integrations_fourthwall_event_order') ?></li>
                <li><?= t('integrations_fourthwall_event_gift') ?></li>
                <li><?= t('integrations_fourthwall_event_donation') ?></li>
                <li><?= t('integrations_fourthwall_event_subscription') ?></li>
              </ul>
            </li>
          </ol>
          <p class="has-text-success"><strong><?= t('integrations_done') ?></strong> <?= t('integrations_fourthwall_success') ?></p>
        </div>
      </div>
    </div>
  </div>
  <div class="column is-12-mobile is-6-tablet is-4-desktop">
    <div class="card integration-card" id="kofi">
      <header class="card-header">
        <p class="card-header-title">
          <span class="icon has-text-danger mr-2"><i class="fas fa-coffee"></i></span>
          <?= t('integrations_kofi_title') ?>
        </p>
      </header>
      <div class="card-content has-text-wrap">
        <div class="content">
          <p><?= t('integrations_kofi_intro') ?></p>
          <ol class="mb-3">
            <li><?= t('integrations_kofi_step1') ?></li>
            <li><?= t('integrations_kofi_step2') ?></li>
            <li><?= t('integrations_kofi_step3') ?></li>
            <li><?= t('integrations_kofi_step4') ?><br>
              <code>https://api.botofthespecter.com/kofi?api_key=</code><br>
              <?= t('integrations_append_api_key') ?>
            </li>
            <li><?= t('integrations_kofi_step5') ?></li>
          </ol>
          <p class="has-text-success"><strong><?= t('integrations_done') ?></strong> <?= t('integrations_kofi_success') ?></p>
        </div>
      </div>
    </div>
  </div>
  <div class="column is-12-mobile is-6-tablet is-4-desktop">
    <div class="card integration-card" id="patreon">
      <header class="card-header">
        <p class="card-header-title">
          <span class="icon has-text-danger mr-2"><i class="fab fa-patreon"></i></span>
          <?= t('integrations_patreon_title') ?>
        </p>
      </header>
      <div class="card-content has-text-wrap">
        <div class="content">
          <p class="has-text-weight-bold has-text-info mb-2"><?= t('integrations_patreon_note') ?></p>
          <p><?= t('integrations_patreon_intro') ?></p>
          <ol class="mb-3">
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
              <ul>
                <li><?= t('integrations_patreon_event_create') ?></li>
                <li><?= t('integrations_patreon_event_delete') ?></li>
                <li><?= t('integrations_patreon_event_update') ?></li>
              </ul>
              <?= t('integrations_patreon_enable_members') ?>
            </li>
          </ol>
          <p class="has-text-success"><strong><?= t('integrations_done') ?></strong> <?= t('integrations_patreon_success') ?></p>
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