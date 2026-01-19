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
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

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
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';
    $escapedUsername = escapeshellarg($username);
    $escapedTwitchUserId = escapeshellarg($twitchUserId);
    $escapedAuthToken = escapeshellarg($authToken);
    $output = shell_exec("python3 /home/botofthespecter/sync-channel-rewards.py -channel $escapedUsername -channelid $escapedTwitchUserId -token $escapedAuthToken 2>&1");
    if ($output === false) {
        $output = "Error: Script execution failed.";
    } elseif (empty($output)) {
        $output = "Script ran but produced no output.";
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['output' => nl2br(htmlspecialchars($output))]);
        exit();
    } else {
        $_SESSION['sync_output'] = nl2br(htmlspecialchars($output));
        header('Location: channel_rewards.php');
        exit();
    }
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
                <div class="notification is-info mb-4" id="sync-result" style="display: none;">
                    <strong>Sync Result:</strong><br>
                    <pre id="sync-output" class="mb-0"
                        style="white-space: pre-wrap; max-height: 220px; overflow:auto; font-family: monospace;"></pre>
                </div>
                <div class="notification is-info mb-4">
                    <form method="POST" id="sync-form"
                        style="display: flex; align-items: flex-start; justify-content: space-between;">
                        <div style="flex: 1;">
                            <span class="icon"><i class="fas fa-sync-alt"></i></span>
                            <strong><?php echo t('channel_rewards_sync_title'); ?></strong><br>
                            <?php echo t('channel_rewards_sync_desc'); ?><br />
                            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                            <strong><?php echo t('channel_rewards_sync_important'); ?></strong>
                            <?php echo t('channel_rewards_sync_important_desc'); ?>
                        </div>
                        <div style="margin-left: 24px;">
                            <button class="button is-primary" id="sync-btn" style="margin-top: 0;"
                                onclick="syncRewards()">
                                <span id="sync-btn-spinner" class="icon is-small" style="display:none;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </span>
                                <span id="sync-btn-text"><i class="fas fa-sync-alt"></i>
                                    <?php echo t('channel_rewards_sync_btn'); ?></span>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="notification is-warning mb-4">
                    <p class="has-text-weight-bold">
                        <span class="icon"><i class="fas fa-exclamation-circle"></i></span>
                        Important Change: Fortune, Lotto, and TTS Variables
                    </p>
                    <p>
                        The <span class="has-text-weight-bold">(fortune)</span>, <span
                            class="has-text-weight-bold">(lotto)</span>, and <span
                            class="has-text-weight-bold">(tts)</span> are now text variables that can be used anywhere
                        in your custom message instead of reward name-based triggers. This allows you to use any reward
                        name you want without conflicts with other rewards.
                    </p>
                </div>
                <div class="notification is-info mb-5">
                    <div class="columns is-multiline">
                        <div class="column is-12">
                            <p class="has-text-weight-bold">
                                <span class="icon"><i class="fas fa-code"></i></span>
                                <?php echo t('channel_rewards_custom_vars_title'); ?>
                            </p>
                            <p><?php echo t('channel_rewards_custom_vars_desc'); ?></p>
                            <ul>
                                <li><span class="has-text-weight-bold">(user)</span>:
                                    <?php echo t('channel_rewards_var_user'); ?>
                                </li>
                                <li><span class="has-text-weight-bold">(usercount)</span>:
                                    <?php echo t('channel_rewards_var_usercount'); ?>
                                </li>
                                <li><span class="has-text-weight-bold">(userstreak)</span>:
                                    <?php echo t('channel_rewards_var_userstreak'); ?>
                                </li>
                                <li><span class="has-text-weight-bold">(track)</span>: Tracks the internal usage count
                                    of the reward.</li>
                                <li><span class="has-text-weight-bold">(fortune)</span>: Replaces with a random fortune
                                    message.</li>
                                <li><span class="has-text-weight-bold">(lotto)</span>: Replaces with randomly generated
                                    lotto numbers.</li>
                                <li><span class="has-text-weight-bold">(tts)</span>: Sends the user input to
                                    text-to-speech (removes from message).</li>
                                <li><span class="has-text-weight-bold">(tts.message)</span>: Sends the final complete
                                    message to both chat and text-to-speech.</li>
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
                                <th style="width: 150px;" class="has-text-centered">
                                    <?php echo t('channel_rewards_reward_cost'); ?>
                                </th>
                                <th style="width: 100px;" class="has-text-centered">Synced</th>
                                <th style="width: 100px;" class="has-text-centered">
                                    <?php echo t('channel_rewards_editing'); ?>
                                </th>
                                <th style="width: 100px;" class="has-text-centered">
                                    <?php echo t('channel_rewards_deleting'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 1. Fetch DB Rewards
                            $rewardsQuery = $db->prepare("SELECT reward_id, reward_title, custom_message, reward_cost FROM channel_point_rewards");
                            $rewardsQuery->execute();
                            $result = $rewardsQuery->get_result();
                            $dbRewardsList = $result->fetch_all(MYSQLI_ASSOC);
                            $rewardsQuery->close();

                            $dbRewardsMap = [];
                            foreach ($dbRewardsList as $r) {
                                $dbRewardsMap[$r['reward_id']] = $r;
                            }

                            // 2. Fetch Twitch Rewards
                            $twitchRewards = [];
                            $twitchError = false;

                            $tToken = $_SESSION['access_token'];
                            $tBroadcasterId = $_SESSION['twitchUserId'];

                            // Try to get Client ID from config/env
                            $tClientId = '';
                            if (file_exists('/home/botofthespecter/.env')) {
                                $env = parse_ini_file('/home/botofthespecter/.env');
                                $tClientId = $env['CLIENT_ID'] ?? '';
                            }
                            if (empty($tClientId) && file_exists('/var/www/.env')) {
                                $lines = file('/var/www/.env');
                                foreach ($lines as $line) {
                                    if (strpos(trim($line), 'CLIENT_ID') === 0) {
                                        $tClientId = trim(explode('=', $line)[1]);
                                        break;
                                    }
                                }
                            }

                            if (!empty($tToken) && !empty($tBroadcasterId)) {
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id=" . $tBroadcasterId);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                    "Authorization: Bearer $tToken",
                                    "Client-Id: $tClientId"
                                ]);
                                $resp = curl_exec($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                curl_close($ch);

                                if ($httpCode == 200) {
                                    $json = json_decode($resp, true);
                                    $twitchRewards = $json['data'] ?? [];
                                } else {
                                    $twitchError = true;
                                    // Fallback: If API fails, just show what we have in DB?
                                    // Or maybe list DB items as "Unknown Sync Status"?
                                    // For now, let's behave as if we only have DB items if API fails, 
                                    // but we need to format them like Twitch items.
                                    foreach ($dbRewardsList as $dbItem) {
                                        $twitchRewards[] = [
                                            'id' => $dbItem['reward_id'],
                                            'title' => $dbItem['reward_title'],
                                            'cost' => $dbItem['reward_cost'],
                                            // 'fallback' => true
                                        ];
                                    }
                                }
                            }

                            // 3. Display Loop
                            if (empty($twitchRewards)) {
                                echo '<tr><td colspan="6" class="has-text-centered">' . t('channel_rewards_no_rewards') . '</td></tr>';
                            } else {
                                // Sort by cost
                                usort($twitchRewards, function ($a, $b) {
                                    return $a['cost'] - $b['cost'];
                                });

                                foreach ($twitchRewards as $reward):
                                    $rId = $reward['id'];
                                    $rTitle = $reward['title'];
                                    $rCost = $reward['cost'];

                                    $isSynced = isset($dbRewardsMap[$rId]);
                                    $customMessage = $isSynced ? $dbRewardsMap[$rId]['custom_message'] : '';

                                    // Visuals
                                    $rowClass = $isSynced ? '' : 'has-background-grey-darker'; // slight dim for unsynced?
                                    $syncIcon = $isSynced
                                        ? '<span class="icon has-text-success" title="Synced"><i class="fas fa-check-circle"></i></span>'
                                        : '<span class="icon has-text-grey" title="Not Synced"><i class="far fa-circle"></i></span>';

                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td><?php echo htmlspecialchars($rTitle); ?></td>
                                        <td>
                                            <?php if ($isSynced): ?>
                                                <div id="<?php echo $rId; ?>">
                                                    <?php echo htmlspecialchars($customMessage); ?>
                                                </div>
                                                <div class="edit-box" id="edit-box-<?php echo $rId; ?>" style="display: none;">
                                                    <textarea class="textarea custom-message" data-reward-id="<?php echo $rId; ?>"
                                                        maxlength="255"><?php echo htmlspecialchars($customMessage); ?></textarea>
                                                    <div class="character-count" id="count-<?php echo $rId; ?>"
                                                        style="margin-top: 5px; font-size: 0.8em;">0 / 255 characters</div>
                                                </div>
                                            <?php else: ?>
                                                <span class="is-italic has-text-grey-light">Not Synced</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <?php echo htmlspecialchars($rCost); ?>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <?php echo $syncIcon; ?>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <?php if ($isSynced): ?>
                                                <div class="edit-controls" id="controls-<?php echo $rId; ?>"
                                                    style="display: flex; justify-content: center; align-items: center;">
                                                    <button class="button is-small is-info edit-btn"
                                                        data-reward-id="<?php echo $rId; ?>"><i
                                                            class="fas fa-pencil-alt"></i></button>
                                                    <div class="save-cancel" id="save-cancel-<?php echo $rId; ?>"
                                                        style="display: none;">
                                                        <button class="button is-small is-success save-btn"
                                                            data-reward-id="<?php echo $rId; ?>"><i
                                                                class="fas fa-check"></i></button>
                                                        <button class="button is-small is-danger cancel-btn"
                                                            data-reward-id="<?php echo $rId; ?>"><i
                                                                class="fas fa-times"></i></button>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <button class="button is-small is-dark" disabled title="Sync to enable editing">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <?php if ($isSynced): ?>
                                                <button class="button is-small is-danger delete-btn"
                                                    data-reward-id="<?php echo $rId; ?>"><i class="fas fa-trash-alt"></i></button>
                                            <?php else: ?>
                                                <button class="button is-small is-dark" disabled>
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="notification is-info mb-5">
                    <p class="has-text-weight-bold">
                        <span class="icon"><i class="fas fa-history"></i></span>
                        <?php echo t('channel_rewards_recent_redemptions'); ?>
                    </p>
                    <p>
                        <?php echo t('channel_rewards_recent_redemptions_desc'); ?>
                    </p>
                </div>
                <div class="table-container">
                    <table class="table is-fullwidth has-background-dark" style="border-radius: 8px;">
                        <thead>
                            <tr>
                                <th>
                                    <?php echo t('channel_rewards_user'); ?>
                                </th>
                                <th>
                                    <?php echo t('channel_rewards_reward'); ?>
                                </th>
                                <th>
                                    <?php echo t('channel_rewards_input'); ?>
                                </th>
                                <th class="has-text-centered">
                                    <?php echo t('channel_rewards_cost'); ?>
                                </th>
                                <th class="has-text-centered">
                                    <?php echo t('channel_rewards_status'); ?>
                                </th>
                                <th class="has-text-centered">
                                    <?php echo t('channel_rewards_time'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="redemptions-table-body">
                            <tr>
                                <td colspan="6" class="has-text-centered"><i class="fas fa-spinner fa-spin"></i>
                                    Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
// Prepare reward IDs for JS
$rewardMap = [];
if (!empty($channelPointRewards)) {
    foreach ($channelPointRewards as $r) {
        $rewardMap[$r['reward_id']] = $r['reward_title'];
    }
}
?>
<script>
    const rewardMap = <?php echo json_encode($rewardMap); ?>;
    const allRewardIds = Object.keys(rewardMap);
    let allRedemptions = [];

    document.addEventListener('DOMContentLoaded', function () {
        if (allRewardIds.length > 0) {
            fetchAllRedemptions();
        } else {
            renderRedemptions([]);
        }
    });

    async function fetchAllRedemptions() {
        const promises = allRewardIds.map(id => fetch('get_redemptions.php?reward_id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.data) return data.data;
                return [];
            })
            .catch(err => [])
        );

        try {
            const results = await Promise.all(promises);
            allRedemptions = results.flat();

            // Sort by redeemed_at desc
            allRedemptions.sort((a, b) => new Date(b.redeemed_at) - new Date(a.redeemed_at));

            // Limit to 50
            allRedemptions = allRedemptions.slice(0, 50);

            renderRedemptions(allRedemptions);
        } catch (error) {
            console.error('Error fetching redemptions:', error);
            document.getElementById('redemptions-table-body').innerHTML = '<tr><td colspan="6" class="has-text-centered has-text-danger">Failed to load redemptions.</td></tr>';
        }
    }

    function renderRedemptions(redemptions) {
        const tbody = document.getElementById('redemptions-table-body');
        tbody.innerHTML = '';

        if (redemptions.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="has-text-centered"><?php echo t('channel_rewards_no_redemptions'); ?></td></tr>`;
            return;
        }

        redemptions.forEach(r => {
            const rewardName = r.reward.title || rewardMap[r.reward.id] || r.reward.id;
            const input = r.user_input || '<span class="has-text-grey-light is-italic">No input</span>';
            const cost = r.reward.cost;
            let statusColor = 'is-warning';
            if (r.status === 'FULFILLED') statusColor = 'is-success';
            if (r.status === 'CANCELED') statusColor = 'is-danger';

            const date = new Date(r.redeemed_at).toLocaleString();

            const row = `
                <tr>
                    <td>${escapeHtml(r.user_name)}</td>
                    <td>${escapeHtml(rewardName)}</td>
                    <td>${r.user_input ? escapeHtml(r.user_input) : input}</td>
                    <td class="has-text-centered">${cost}</td>
                    <td class="has-text-centered"><span class="tag ${statusColor}">${escapeHtml(r.status)}</span></td>
                    <td class="has-text-centered">${date}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }


    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
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
        btn.addEventListener('click', function () {
            const rewardid = this.getAttribute('data-reward-id');
            if (confirm('<?php echo t('channel_rewards_delete_confirm'); ?>')) {
                deleteReward(rewardid);
            }
        });
    });

    function updateCustomMessage(rewardid, newCustomMessage, button, originalIcon) {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function () {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                // Restore button state
                button.innerHTML = originalIcon;
                button.disabled = false;
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
        xhr.onreadystatechange = function () {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                location.reload();
            }
        };
        xhr.send("deleteRewardId=" + encodeURIComponent(rewardid));
    }

    document.getElementById('sync-form').addEventListener('submit', function (e) {
        e.preventDefault();
    });

    const syncResultContainer = document.getElementById('sync-result');
    const syncOutputElement = document.getElementById('sync-output');
    let syncEventSource = null;
    let syncResultHideTimer = null;

    function appendSyncOutputLine(message) {
        if (!syncOutputElement) return;
        syncOutputElement.textContent += message + '\n';
        syncOutputElement.scrollTop = syncOutputElement.scrollHeight;
    }

    function setSyncResultVariant(variant) {
        if (!syncResultContainer) return;
        syncResultContainer.classList.remove('is-info', 'is-success', 'is-danger');
        syncResultContainer.classList.add('is-' + variant);
    }

    function showSyncResult() {
        if (!syncResultContainer) return;
        syncResultContainer.style.display = 'block';
        setSyncResultVariant('info');
    }

    function scheduleSyncResultHide() {
        if (!syncResultContainer) return;
        if (syncResultHideTimer) {
            clearTimeout(syncResultHideTimer);
        }
        syncResultHideTimer = setTimeout(function () {
            syncResultContainer.style.display = 'none';
        }, 60000);
    }

    function finalizeSync(success, button, spinner, text) {
        if (spinner) {
            spinner.style.display = 'none';
        }
        if (button) {
            button.disabled = false;
        }
        if (text && button && button.dataset.syncOriginalHtml) {
            text.innerHTML = button.dataset.syncOriginalHtml;
        }
        setSyncResultVariant(success ? 'success' : 'danger');
        scheduleSyncResultHide();
        if (syncEventSource) {
            syncEventSource.close();
            syncEventSource = null;
        }
    }

    function syncRewards() {
        var btn = document.getElementById('sync-btn');
        var spinner = document.getElementById('sync-btn-spinner');
        var text = document.getElementById('sync-btn-text');
        if (!btn || !spinner || !text || !syncOutputElement) {
            return;
        }

        if (!btn.dataset.syncOriginalHtml) {
            btn.dataset.syncOriginalHtml = text.innerHTML;
        }
        btn.disabled = true;
        spinner.style.display = '';
        text.textContent = <?php echo json_encode(t('channel_rewards_syncing')); ?>;

        if (syncEventSource) {
            syncEventSource.close();
        }
        syncOutputElement.textContent = '';
        showSyncResult();
        appendSyncOutputLine('Connecting to the sync service...');

        syncEventSource = new EventSource('channel_rewards_stream.php');
        syncEventSource.onmessage = function (e) {
            appendSyncOutputLine(e.data || '');
        };
        syncEventSource.addEventListener('done', function (e) {
            let info = {};
            try {
                info = JSON.parse(e.data || '{}');
            } catch (err) {
                appendSyncOutputLine('[ERROR] Unable to read completion details.');
            }
            appendSyncOutputLine('');
            appendSyncOutputLine('[PROCESS DONE] ' + (info.success ? 'Success' : 'Failed') + ' (exit code: ' + (typeof info.exit_code !== 'undefined' ? info.exit_code : 'unknown') + ')');
            finalizeSync(Boolean(info.success), btn, spinner, text);
        });
        syncEventSource.onerror = function () {
            appendSyncOutputLine('[ERROR] Connection interrupted; waiting for the script to finish.');
        };
    }

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', function () {
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
        btn.addEventListener('click', function () {
            const rewardid = this.getAttribute('data-reward-id');
            const newCustomMessage = document.querySelector(`.custom-message[data-reward-id="${rewardid}"]`).value;
            // Show loading state
            const originalIcon = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            this.disabled = true;
            updateCustomMessage(rewardid, newCustomMessage, this, originalIcon);
        });
    });

    document.querySelectorAll('.custom-message').forEach(textarea => {
        textarea.addEventListener('input', function () {
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