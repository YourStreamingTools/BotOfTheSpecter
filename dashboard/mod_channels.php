<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('mod_channels_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/bot_control.php';
include "mod_access.php";
include 'includes/user_db.php';
include 'includes/storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Fetch all channels the user can moderate for (moderator_access: moderator_id, broadcaster_id)
$modChannels = [];
if ($username === 'botofthespecter') {
    // Global bot user should see every channel
    $allChannelsSTMT = $conn->prepare("
    SELECT id, twitch_user_id, twitch_display_name, profile_image, username
    FROM users
    ORDER BY id ASC
");
    $allChannelsSTMT->execute();
    $allResult = $allChannelsSTMT->get_result();
    while ($row = $allResult->fetch_assoc()) {
        $modChannels[] = $row;
    }
    $allChannelsSTMT->close();
} else {
    $modSTMT = $conn->prepare("
    SELECT u.id, u.twitch_user_id, u.twitch_display_name, u.profile_image, u.username
    FROM users u
    INNER JOIN moderator_access ma ON u.twitch_user_id = ma.broadcaster_id
    WHERE ma.moderator_id = ?
    ORDER BY u.id ASC
");
$modSTMT->bind_param("s", $twitchUserId);
$modSTMT->execute();
$modResult = $modSTMT->get_result();
while ($row = $modResult->fetch_assoc()) {
    $modChannels[] = $row;
}
    $modSTMT->close();
}
$showSearch = count($modChannels) > 9;

// Start building the HTML content
ob_start();
?>
<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.9rem; font-weight:800; color:var(--text-primary); margin:0 0 0.25rem;"><?= t('mod_channels_heading') ?></h1>
    <p style="color:var(--text-secondary); margin:0;"><?= t('mod_channels_subtitle') ?></p>
</div>
<?php if (isset($_GET['act_as']) && $_GET['act_as'] === 'stopped'): ?>
    <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
        <?= t('mod_channels_act_as_stopped') ?>
    </div>
<?php elseif (isset($_GET['act_as']) && $_GET['act_as'] === 'denied'): ?>
    <div class="sp-alert sp-alert-danger" style="margin-bottom:1rem;">
        <?= t('mod_channels_act_as_denied') ?>
    </div>
<?php elseif (isset($_GET['act_as']) && $_GET['act_as'] === 'not_found'): ?>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
        <?= t('mod_channels_act_as_not_found') ?>
    </div>
<?php endif; ?>
<?php if ($showSearch): ?>
    <div class="sp-form-group">
        <label class="sp-label" for="mod-channel-search"><?= t('mod_channels_search_label') ?></label>
        <input id="mod-channel-search" class="sp-input" type="text" placeholder="<?= htmlspecialchars(t('mod_channels_search_placeholder')) ?>" autocomplete="off">
    </div>
<?php endif; ?>
<?php if (empty($modChannels)): ?>
    <div class="sp-alert sp-alert-info">
        <i class="fas fa-info-circle"></i> <?= t('mod_channels_none') ?>
    </div>
<?php else: ?>
    <div class="mod-channels-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:1rem;">
        <?php foreach ($modChannels as $channel): ?>
            <div class="sp-card mod-channel-card" data-search="<?php echo htmlspecialchars(strtolower($channel['twitch_display_name'] . ' ' . $channel['username']), ENT_QUOTES); ?>">
                <div class="sp-card-body">
                    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">
                        <img src="<?php echo htmlspecialchars($channel['profile_image']); ?>" alt="<?php echo htmlspecialchars($channel['twitch_display_name']); ?>" style="width:64px; height:64px; border-radius:50%; flex-shrink:0; object-fit:cover;">
                        <div style="min-width:0;">
                            <p style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0 0 0.15rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($channel['twitch_display_name']); ?></p>
                            <p style="font-size:0.85rem; color:var(--text-muted); margin:0;">@<?php echo htmlspecialchars($channel['username']); ?></p>
                        </div>
                    </div>
                    <a href="/api/switch_channel.php?user_id=<?php echo urlencode($channel['twitch_user_id']); ?>" class="sp-btn sp-btn-primary" style="width:100%; justify-content:center;">
                        <i class="fas fa-user-secret"></i>
                        <span><?= t('mod_channels_act_as_button') ?></span>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();

if ($showSearch): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('mod-channel-search');
        if (!searchInput) {
            return;
        }
        const cards = Array.from(document.querySelectorAll('.mod-channel-card'));
        searchInput.addEventListener('input', function () {
            const term = searchInput.value.trim().toLowerCase();
            cards.forEach(card => {
                const matches = !term || (card.dataset.search && card.dataset.search.includes(term));
                card.style.display = matches ? '' : 'none';
            });
        });
    });
</script>
<?php
endif;

$scripts = ob_get_clean();

// Include the layout template
include 'layout.php';
?>
