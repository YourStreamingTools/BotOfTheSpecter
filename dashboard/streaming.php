<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('streaming_settings_title');

require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/stream.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php';
session_write_close();

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

if (!$is_admin && !$betaAccess && !in_array('streaming', $betaPrograms)) {
    ob_start();
    ?>
    <div class="sp-card">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-broadcast-tower"></i> <?php echo t('streaming_settings_title'); ?></div>
        </div>
        <div class="sp-card-body">
            <h2><?php echo t('streaming_beta_title'); ?></h2>
            <p><?php echo t('streaming_beta_description'); ?></p>
            <p><?php echo t('streaming_beta_request_access'); ?></p>
            <a href="https://support.botofthespecter.com" target="_blank" rel="noopener noreferrer" class="sp-btn sp-btn-info">
                <span class="icon"><i class="fas fa-headset"></i></span>
                <span><?php echo t('streaming_beta_support_link'); ?></span>
            </a>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    include 'layout.php';
    exit();
}

$saveStatus = null;
$twitchKey = '';
$forwardToTwitch = 0;
$settingsId = null;

$loadStmt = $db->prepare("SELECT id, twitch_key, forward_to_twitch FROM streaming_settings ORDER BY id ASC LIMIT 1");
if ($loadStmt) {
    $loadStmt->execute();
    $loadResult = $loadStmt->get_result();
    if ($loadResult && $row = $loadResult->fetch_assoc()) {
        $settingsId = (int) ($row['id'] ?? 0);
        $twitchKey = (string) ($row['twitch_key'] ?? '');
        $forwardToTwitch = (int) ($row['forward_to_twitch'] ?? 0);
    }
    $loadStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_streaming_settings'])) {
    $twitchKey = trim((string) ($_POST['twitch_key'] ?? ''));
    $forwardToTwitch = isset($_POST['forward_to_twitch']) ? 1 : 0;
    $ok = false;
    if ($settingsId) {
        $saveStmt = $db->prepare("UPDATE streaming_settings SET twitch_key = ?, forward_to_twitch = ? WHERE id = ?");
        if ($saveStmt) {
            $saveStmt->bind_param("sii", $twitchKey, $forwardToTwitch, $settingsId);
            $ok = $saveStmt->execute();
            $saveStmt->close();
        } else {
            $ok = false;
        }
    } else {
        $saveStmt = $db->prepare("INSERT INTO streaming_settings (twitch_key, forward_to_twitch) VALUES (?, ?)");
        if ($saveStmt) {
            $saveStmt->bind_param("si", $twitchKey, $forwardToTwitch);
            $ok = $saveStmt->execute();
            if ($ok) {
                $settingsId = (int) $db->insert_id;
            }
            $saveStmt->close();
        } else {
            $ok = false;
        }
    }
    $saveStatus = $ok
        ? ['success' => true, 'message' => t('streaming_settings_saved_success')]
        : ['success' => false, 'message' => t('streaming_settings_save_failed')];
}

$hasSlot = false;
$quotaBytes = 0;
$slotUserId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));
if ($slotUserId > 0) {
    $slotStmt = $conn->prepare("SELECT quota_bytes, bonus_bytes FROM stream_storage_slots WHERE user_id = ? LIMIT 1");
    if ($slotStmt) {
        $slotStmt->bind_param("i", $slotUserId);
        $slotStmt->execute();
        $slotResult = $slotStmt->get_result();
        if ($slotResult && $slotRow = $slotResult->fetch_assoc()) {
            $hasSlot = true;
            $rawQuota = $slotRow['quota_bytes'];
            $quotaBytes = ($rawQuota === null) ? (100 * 1024 * 1024 * 1024) : (int) $rawQuota;
            $quotaBytes = $quotaBytes === 0 ? 0 : $quotaBytes + (int) ($slotRow['bonus_bytes'] ?? 0);
        }
        $slotStmt->close();
    }
}

$rtmpsUrl = rtrim((string) ($stream_rtmps_url ?? 'rtmps://syd1.stream.botofthespecter.com:1935/app'), '/');
$specterKey = (string) ($api_key ?? ($_SESSION['api_key'] ?? ''));
$unlimitedStorage = $hasSlot && $quotaBytes === 0;

$streamApiBase = rtrim((string) ($stream_api_base ?? ''), '/');
$streamApiTimeout = (int) ($stream_api_timeout ?? 30);
require_once __DIR__ . '/includes/stream_api_client.php';
$storageSummary = streamFetchStorage($streamApiBase, $specterKey, $streamApiTimeout);
$storageUsedBytes = $storageSummary['used_bytes'];
$storageQuotaBytes = $storageSummary['quota_bytes'];
$storageUnlimited = $storageSummary['unlimited'] || $unlimitedStorage;

ob_start();
?>
<?php if ($saveStatus): ?>
    <div class="sp-alert <?= $saveStatus['success'] ? 'sp-alert-success' : 'sp-alert-danger' ?> mb-4">
        <?= htmlspecialchars($saveStatus['message']) ?>
    </div>
<?php endif; ?>
<div class="sp-alert sp-alert-success mb-4">
    <?php echo t('streaming_access_confirmed_banner'); ?>
</div>
<?php if ($hasSlot): ?>
    <div class="sp-alert sp-alert-info mb-4">
        <?php echo $unlimitedStorage ? t('streaming_slot_unlimited') : t('streaming_slot_ok'); ?>
    </div>
<?php else: ?>
    <div class="sp-alert sp-alert-warning mb-4">
        <?php echo t('streaming_slot_none'); ?>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/includes/stream_storage_bar.php'; ?>
<div class="sp-card mb-4">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-satellite-dish"></i> <?php echo t('streaming_ingest_heading'); ?></div>
    </div>
    <div class="sp-card-body">
        <p><?php echo t('streaming_ingest_help'); ?></p>
        <div class="sp-form-group">
            <label class="sp-label" for="rtmps-server"><?php echo t('streaming_rtmps_server_label'); ?></label>
            <div class="sp-field-row">
                <input class="sp-input w-100" id="rtmps-server" type="text" value="<?php echo htmlspecialchars($rtmpsUrl); ?>" readonly>
                <button type="button" class="sp-btn sp-btn-secondary" data-copy-target="rtmps-server"><?php echo t('streaming_copy'); ?></button>
            </div>
            <span class="sp-help"><?php echo t('streaming_obs_server_help'); ?></span>
        </div>
        <div class="sp-form-group">
            <label class="sp-label" for="specter-stream-key"><?php echo t('streaming_specter_key_label'); ?></label>
            <div class="sp-field-row">
                <input class="sp-input w-100" id="specter-stream-key" type="password" value="<?php echo htmlspecialchars($specterKey); ?>" readonly autocomplete="off">
                <button type="button" class="sp-btn sp-btn-secondary" id="toggle-specter-key" title="<?php echo htmlspecialchars(t('streaming_show_hide_twitch_key')); ?>"><i class="fas fa-eye"></i></button>
                <button type="button" class="sp-btn sp-btn-secondary" data-copy-target="specter-stream-key"><?php echo t('streaming_copy'); ?></button>
            </div>
            <span class="sp-help"><?php echo t('streaming_api_key_note'); ?></span>
        </div>
        <p class="sp-help"><?php echo t('streaming_sydney_only'); ?></p>
        <p class="sp-help"><?php echo t('streaming_session_limit'); ?></p>
        <p><a class="sp-btn sp-btn-info sp-btn-sm" href="recording.php"><i class="fas fa-video"></i> <?php echo t('streaming_recordings_link'); ?></a></p>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-key"></i> <?php echo t('streaming_feature_stream_key_title'); ?></div>
    </div>
    <div class="sp-card-body">
        <p><?php echo t('streaming_service_option_record_and_forward'); ?></p>
        <form method="post" action="">
            <div class="sp-form-group">
                <label class="sp-label" for="twitch_key"><?php echo t('streaming_twitch_key_label'); ?></label>
                <div class="sp-field-row">
                    <input class="sp-input w-100" id="twitch_key" name="twitch_key" type="password" value="<?php echo htmlspecialchars($twitchKey); ?>" autocomplete="off">
                    <button type="button" class="sp-btn sp-btn-secondary" id="toggle-twitch-key" title="<?php echo htmlspecialchars(t('streaming_show_hide_twitch_key')); ?>"><i class="fas fa-eye"></i></button>
                </div>
                <span class="sp-help"><?php echo t('streaming_show_twitch_key_warning'); ?></span>
            </div>
            <div class="sp-form-group">
                <label>
                    <input type="checkbox" name="forward_to_twitch" value="1" <?php echo $forwardToTwitch ? 'checked' : ''; ?>>
                    <?php echo t('streaming_forward_to_twitch_label'); ?>
                </label>
            </div>
            <button type="submit" name="save_streaming_settings" class="sp-btn sp-btn-primary">
                <span class="icon"><i class="fas fa-save"></i></span>
                <span><?php echo t('streaming_save_settings_btn'); ?></span>
            </button>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function bindToggle(btnId, inputId) {
        var btn = document.getElementById(btnId);
        var input = document.getElementById(inputId);
        if (!btn || !input) return;
        btn.addEventListener('click', function () {
            var hide = input.type === 'text';
            input.type = hide ? 'password' : 'text';
            var icon = btn.querySelector('i');
            if (icon) icon.className = hide ? 'fas fa-eye' : 'fas fa-eye-slash';
        });
    }
    bindToggle('toggle-specter-key', 'specter-stream-key');
    bindToggle('toggle-twitch-key', 'twitch_key');
    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-copy-target'));
            if (!el) return;
            var value = el.value || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value);
            }
        });
    });
});
</script>
<?php
$content = ob_get_clean();
include 'layout.php';
