<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Header
$pageTitle = t('channel_rewards_title');

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
$syncMessage = "";

require_once '/var/www/config/database.php';
$dbname = $_SESSION['username'];
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) { die('Connection failed: ' . $db->connect_error); }

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rewardid']) && isset($_POST['newCustomMessage'])) {
    $rewardid = $_POST['rewardid'];
    $newCustomMessage = $_POST['newCustomMessage'];
    $messageQuery = $db->prepare("UPDATE channel_point_rewards SET custom_message = ? WHERE reward_id = ?");
    $messageQuery->bind_param('ss', $newCustomMessage, $rewardid);
    $messageQuery->execute();
    $messageQuery->close();
    header('Location: channel_rewards.php');
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['deleteRewardId'])) {
    $deleteRewardId = $_POST['deleteRewardId'];
    $deleteQuery = $db->prepare("DELETE FROM channel_point_rewards WHERE reward_id = ?");
    $deleteQuery->bind_param('s', $deleteRewardId);
    $deleteQuery->execute();
    $deleteQuery->close();
    header('Location: channel_rewards.php');
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['syncRewards'])) {
    $escapedUsername = escapeshellarg($username);
    $escapedTwitchUserId = escapeshellarg($twitchUserId);
    $escapedAuthToken = escapeshellarg($authToken);
    shell_exec("python3 /home/botofthespecter/sync-channel-rewards.py -channel $escapedUsername -channelid $escapedTwitchUserId -token $escapedAuthToken 2>&1");
    $syncMessage = "<p>" . t('channel_rewards_syncing') . "</p>";
    sleep(3);
    header('Location: channel_rewards.php');
    exit();
}

// Fetch channel point rewards
$rewardsQuery = $db->prepare("SELECT reward_id, reward_title, custom_message, reward_cost FROM channel_point_rewards ORDER BY CAST(reward_cost AS UNSIGNED) ASC");
$rewardsQuery->execute();
$result = $rewardsQuery->get_result();
$channelPointRewards = $result->fetch_all(MYSQLI_ASSOC);
$rewardsQuery->close();

// Start output buffering for layout template
ob_start();
?>
<div class="columns is-centered">
    <div class="column is-12">
        <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-gift"></i></span>
                    <?php echo t('channel_rewards_title'); ?>
                </span>
            </header>
            <div class="card-content">
                <div class="notification is-info mb-4">
                    <form method="POST" id="sync-form" style="display: flex; align-items: flex-start; justify-content: space-between;">
                        <div style="flex: 1;">
                            <span class="icon"><i class="fas fa-sync-alt"></i></span>
                            <strong><?php echo t('channel_rewards_sync_title'); ?></strong><br>
                            <?php echo t('channel_rewards_sync_desc'); ?><br />
                            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                            <strong><?php echo t('channel_rewards_sync_important'); ?></strong> <?php echo t('channel_rewards_sync_important_desc'); ?>
                        </div>
                        <div style="margin-left: 24px;">
                            <button class="button is-primary" name="syncRewards" type="submit" id="sync-btn" style="margin-top: 0;" disabled>
                                <span id="sync-btn-spinner" class="icon is-small" style="display:none;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </span>
                                <span id="sync-btn-text"><i class="fas fa-sync-alt"></i> <?php echo t('channel_rewards_sync_btn'); ?></span>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="notification is-info mb-5">
                    <div class="columns is-multiline">
                        <div class="column is-6">
                            <p class="has-text-weight-bold">
                                <span class="icon"><i class="fas fa-tags"></i></span>
                                <?php echo t('channel_rewards_builtin_tags_title'); ?>
                            </p>
                            <p>
                                <?php echo t('channel_rewards_builtin_tags_desc'); ?>
                            </p>
                            <ul>
                                <li>
                                    <span class="icon"><i class="fas fa-comment-dots"></i></span>
                                    <span class="has-text-weight-bold"><?php echo t('channel_rewards_fortunes'); ?></span>: <?php echo t('channel_rewards_fortunes_desc'); ?>
                                </li>
                                <li>
                                    <span class="icon"><i class="fas fa-ticket-alt"></i></span>
                                    <span class="has-text-weight-bold"><?php echo t('channel_rewards_lotto'); ?></span>: <?php echo t('channel_rewards_lotto_desc'); ?>
                                </li>
                                <li>
                                    <span class="icon"><i class="fas fa-volume-up"></i></span>
                                    <span class="has-text-weight-bold"><?php echo t('channel_rewards_tts'); ?></span>: <?php echo t('channel_rewards_tts_desc'); ?><br>
                                    <?php echo t('channel_rewards_tts_overlay'); ?>
                                </li>
                            </ul>
                        </div>
                        <div class="column is-6">
                            <p class="has-text-weight-bold">
                                <span class="icon"><i class="fas fa-code"></i></span>
                                <?php echo t('channel_rewards_custom_vars_title'); ?>
                            </p>
                            <p><?php echo t('channel_rewards_custom_vars_desc'); ?></p>
                            <ul>
                                <li><span class="has-text-weight-bold">(user)</span>: <?php echo t('channel_rewards_var_user'); ?></li>
                                <li><span class="has-text-weight-bold">(usercount)</span>: <?php echo t('channel_rewards_var_usercount'); ?></li>
                                <li><span class="has-text-weight-bold">(userstreak)</span>: <?php echo t('channel_rewards_var_userstreak'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table is-fullwidth has-background-dark" style="border-radius: 8px;">
                        <thead>
                            <tr>
                                <th><?php echo t('channel_rewards_reward_name'); ?></th>
                                <th><?php echo t('channel_rewards_custom_message'); ?></th>
                                <th style="width: 150px;" class="has-text-centered" ><?php echo t('channel_rewards_reward_cost'); ?></th>
                                <th style="width: 100px;" class="has-text-centered" ><?php echo t('channel_rewards_editing'); ?></th>
                                <th style="width: 100px;" class="has-text-centered" ><?php echo t('channel_rewards_deleting'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($channelPointRewards)): ?>
                                <tr>
                                    <td colspan="5" class="has-text-centered"><?php echo t('channel_rewards_no_rewards'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($channelPointRewards as $reward): ?>
                                    <tr>
                                        <td><?php echo isset($reward['reward_title']) ? htmlspecialchars($reward['reward_title']) : ''; ?></td>
                                        <td>
                                            <div id="<?php echo $reward['reward_id']; ?>">
                                                <?php 
                                                $message = isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : '';
                                                echo $message;
                                                ?>
                                            </div>
                                            <div class="edit-box" id="edit-box-<?php echo $reward['reward_id']; ?>" style="display: none;">
                                                <textarea class="textarea custom-message" data-reward-id="<?php echo $reward['reward_id']; ?>" maxlength="255"><?php echo isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : ''; ?></textarea>
                                                <div class="character-count" id="count-<?php echo $reward['reward_id']; ?>" style="margin-top: 5px; font-size: 0.8em;">0 / 255 characters</div>
                                            </div>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;"><?php echo isset($reward['reward_cost']) ? htmlspecialchars($reward['reward_cost']) : ''; ?></td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <div class="edit-controls" id="controls-<?php echo $reward['reward_id']; ?>" style="display: flex; justify-content: center; align-items: center;">
                                                <button class="button is-small is-info edit-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-pencil-alt"></i></button>
                                                <div class="save-cancel" id="save-cancel-<?php echo $reward['reward_id']; ?>" style="display: none;">
                                                    <button class="button is-small is-success save-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-check"></i></button>
                                                    <button class="button is-small is-danger cancel-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-times"></i></button>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <button class="button is-small is-danger delete-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-trash-alt"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function escapeHtml(text) {
    var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const rewardid = this.getAttribute('data-reward-id');
        const editBox = document.getElementById('edit-box-' + rewardid);
        const customMessage = document.getElementById(rewardid);
        const controls = document.getElementById('controls-' + rewardid);
        // Enter edit mode
        editBox.style.display = 'block';
        customMessage.style.display = 'none';
        controls.querySelector('.edit-btn').style.display = 'none';
        const saveCancel = controls.querySelector('.save-cancel');
        saveCancel.style.display = 'flex';
        saveCancel.style.justifyContent = 'center';
        saveCancel.style.alignItems = 'center';
        saveCancel.style.gap = '5px';
        updateCharCount(rewardid);
    });
});

document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const rewardid = this.getAttribute('data-reward-id');
        if (confirm('<?php echo t('channel_rewards_delete_confirm'); ?>')) {
            deleteReward(rewardid);
        }
    });
});

function updateCustomMessage(rewardid, newCustomMessage) {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                // Update the display
                const customMessage = document.getElementById(rewardid);
                const escapedMessage = escapeHtml(newCustomMessage);
                customMessage.innerHTML = escapedMessage;
                // Hide edit mode
                const editBox = document.getElementById('edit-box-' + rewardid);
                const controls = document.getElementById('controls-' + rewardid);
                editBox.style.display = 'none';
                customMessage.style.display = 'block';
                controls.querySelector('.edit-btn').style.display = 'block';
                controls.querySelector('.save-cancel').style.display = 'none';
            } else {
                alert('Error saving message');
            }
        }
    };
    xhr.send("rewardid=" + encodeURIComponent(rewardid) + "&newCustomMessage=" + encodeURIComponent(newCustomMessage));
}

function deleteReward(rewardid) {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            location.reload();
        }
    };
    xhr.send("deleteRewardId=" + encodeURIComponent(rewardid));
}

document.getElementById('sync-form').addEventListener('submit', function(e) {
    var btn = document.getElementById('sync-btn');
    var spinner = document.getElementById('sync-btn-spinner');
    var text = document.getElementById('sync-btn-text');
    btn.disabled = true;
    spinner.style.display = '';
    text.textContent = <?php echo json_encode(t('channel_rewards_syncing')); ?>;
});

document.querySelectorAll('.cancel-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const rewardid = this.getAttribute('data-reward-id');
        const editBox = document.getElementById('edit-box-' + rewardid);
        const customMessage = document.getElementById(rewardid);
        const controls = document.getElementById('controls-' + rewardid);
        editBox.style.display = 'none';
        customMessage.style.display = 'block';
        controls.querySelector('.edit-btn').style.display = 'block';
        controls.querySelector('.save-cancel').style.display = 'none';
        // Reset textarea
        const textarea = editBox.querySelector('.custom-message');
        textarea.value = customMessage.textContent.trim();
        updateCharCount(rewardid);
    });
});

document.querySelectorAll('.save-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const rewardid = this.getAttribute('data-reward-id');
        const newCustomMessage = document.querySelector(`.custom-message[data-reward-id="${rewardid}"]`).value;
        updateCustomMessage(rewardid, newCustomMessage);
    });
});

document.querySelectorAll('.custom-message').forEach(textarea => {
    textarea.addEventListener('input', function() {
        const rewardid = this.getAttribute('data-reward-id');
        updateCharCount(rewardid);
    });
});

function updateCharCount(rewardid) {
    const textarea = document.querySelector(`.custom-message[data-reward-id="${rewardid}"]`);
    const countDiv = document.getElementById('count-' + rewardid);
    const length = textarea.value.length;
    countDiv.textContent = length + ' / 255 characters';
    if (length > 255) {
        countDiv.style.color = 'red';
    } else {
        countDiv.style.color = '';
    }
}
</script>
<?php
// Get the buffered content
$content = ob_get_clean();

// Use the dashboard layout
include 'layout.php';
?>
