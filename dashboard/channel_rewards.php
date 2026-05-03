<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Provide fallback for translation if it doesn't exist
if (!function_exists('t') || t('channel_rewards_manage') === 'channel_rewards_manage') {
    function t_fallback($key)
    {
        $translations = [
            'channel_rewards_manage' => 'Manage'
        ];
        return $translations[$key] ?? $key;
    }
    if (!function_exists('t')) {
        function t($key)
        {
            return t_fallback($key);
        }
    }
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
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);
$syncMessage = "";

// By default assume channel-points are available; check broadcaster_type to be sure.
$showNoChannelPoints = false;
$channelDisplayName = 'This channel';
$isOwner = false;
$channelTwitchId = '';
// Try to fetch user info to determine broadcaster_type
$usersUrl = "https://api.twitch.tv/helix/users?id={$broadcasterID}";
$curl = curl_init($usersUrl);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . ($_SESSION['access_token'] ?? ''),
    'Client-ID: ' . ($clientID ?? '')
]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$usersResponse = curl_exec($curl);
if ($usersResponse !== false) {
    $usersHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($usersHttpCode === 200) {
        $usersData = json_decode($usersResponse, true);
        $channelDisplayName = $usersData['data'][0]['display_name'] ?? ($usersData['data'][0]['login'] ?? $channelDisplayName);
        $channelTwitchId = $usersData['data'][0]['id'] ?? '';
        $broadcasterType = $usersData['data'][0]['broadcaster_type'] ?? '';
        $isOwner = (isset($twitchUserId) && $twitchUserId === $channelTwitchId);
        $showNoChannelPoints = ($broadcasterType === '');
    }
}
curl_close($curl);

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
    session_start(); // Reopen session for flash messages
    $deleteRewardId = $_POST['deleteRewardId'];
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';
    // Check if reward is marked as managed by Specter
    $check = $db->prepare("SELECT managed_by FROM channel_point_rewards WHERE reward_id = ?");
    $check->bind_param('s', $deleteRewardId);
    $check->execute();
    $res = $check->get_result();
    $row = $res->fetch_assoc();
    $check->close();
    $managedBy = $row['managed_by'] ?? null;
    if ($managedBy === 'specter') {
        // Validate OAuth token and scopes before attempting delete
        $valCh = curl_init();
        curl_setopt($valCh, CURLOPT_URL, "https://id.twitch.tv/oauth2/validate");
        curl_setopt($valCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($valCh, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$_SESSION['access_token']}"]);
        $valResp = curl_exec($valCh);
        $valCode = curl_getinfo($valCh, CURLINFO_HTTP_CODE);
        curl_close($valCh);
        if ($valCode !== 200) {
            $errorMsg = 'OAuth token validation failed. HTTP ' . $valCode . ': ' . $valResp;
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $errorMsg, 'http_code' => $valCode]);
                exit();
            } else {
                $_SESSION['sync_output'] = $errorMsg;
                header('Location: channel_rewards.php');
                exit();
            }
        }
        $valData = json_decode($valResp, true);
        $tokenClientId = $valData['client_id'] ?? null;
        $tokenScopes = $valData['scopes'] ?? [];
        // Check client id matches configured client id
        if ($tokenClientId !== $clientID) {
            $errorMsg = 'Client ID in token (' . ($tokenClientId ?? 'none') . ') does not match configured client ID (' . ($clientID ?? 'none') . '). Twitch will only allow the app that created the reward to delete it.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                exit();
            } else {
                $_SESSION['sync_output'] = $errorMsg;
                header('Location: channel_rewards.php');
                exit();
            }
        }
        // Check required scope
        if (!in_array('channel:manage:redemptions', $tokenScopes, true)) {
            $errorMsg = 'OAuth token missing required scope: channel:manage:redemptions. Please re-authenticate and grant that scope.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                exit();
            } else {
                $_SESSION['sync_output'] = $errorMsg;
                header('Location: channel_rewards.php');
                exit();
            }
        }
        // Proceed to delete on Twitch
        $deleteUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$_SESSION['twitchUserId']}&id={$deleteRewardId}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $deleteUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$_SESSION['access_token']}",
            "Client-Id: {$clientID}"
        ]);
        $tResp = curl_exec($ch);
        $tCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Try to parse response body if JSON to give clear errors
        $tRespDecoded = null;
        $tmp = json_decode($tResp, true);
        if (is_array($tmp)) {
            $tRespDecoded = $tmp;
        }
        if ($tCode === 204 || $tCode === 200 || $tCode === 404) {
            // Delete local records and related tables
            $del = $db->prepare("DELETE FROM channel_point_rewards WHERE reward_id = ?");
            $del->bind_param('s', $deleteRewardId);
            $del->execute();
            $del->close();
            $related = ['reward_counts', 'reward_streaks', 'sound_alerts', 'video_alerts'];
            foreach ($related as $table) {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE reward_id = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $deleteRewardId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Deleted on Twitch and removed from Specter sync.', 'twitch_status' => $tCode]);
                exit();
            } else {
                $_SESSION['sync_output'] = 'Deleted on Twitch and removed from Specter sync.';
                header('Location: channel_rewards.php');
                exit();
            }
        } else {
            // Failed to delete on Twitch - include parsed message if available
            $errorExtra = '';
            if (is_array($tRespDecoded)) {
                if (isset($tRespDecoded['message'])) $errorExtra = $tRespDecoded['message'];
                elseif (isset($tRespDecoded['error'])) $errorExtra = $tRespDecoded['error'];
            }
            $errorMsg = 'Failed to delete on Twitch. HTTP ' . $tCode . ': ' . ($errorExtra ?: $tResp);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $errorMsg, 'http_code' => $tCode, 'twitch_response' => $tRespDecoded ?: $tResp]);
                exit();
            } else {
                $_SESSION['sync_output'] = $errorMsg;
                header('Location: channel_rewards.php');
                exit();
            }
        }
    } else {
        // Not managed by Specter - just remove from local sync
        $deleteQuery = $db->prepare("DELETE FROM channel_point_rewards WHERE reward_id = ?");
        $deleteQuery->bind_param('s', $deleteRewardId);
        $deleteQuery->execute();
        $deleteQuery->close();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Removed from Specter sync (reward remains on Twitch).']);
            exit();
        } else {
            header('Location: channel_rewards.php');
            exit();
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['syncRewards'])) {
    session_start(); // Reopen session for flash messages
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
        echo json_encode(['output' => nl2br(htmlspecialchars($output ?? '', ENT_QUOTES, 'UTF-8'))]);
        exit();
    } else {
        $_SESSION['sync_output'] = nl2br(htmlspecialchars($output ?? '', ENT_QUOTES, 'UTF-8'));
        header('Location: channel_rewards.php');
        exit();
    }
}

// On-load: ensure Twitch 'manageable' rewards are present in the DB so they show up immediately
$syncErrors = [];
if (!$showNoChannelPoints && isset($_SESSION['access_token']) && !empty($clientID)) {
    $ch = curl_init();
    $after = null;
    do {
        $url = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$_SESSION['twitchUserId']}&only_manageable_rewards=true&first=50";
        if ($after) $url .= '&after=' . urlencode($after);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$_SESSION['access_token']}",
            "Client-Id: {$clientID}"
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300) {
            $data = json_decode($resp, true);
            if (!empty($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $r) {
                    $rewardId = $r['id'];
                    $rewardTitle = $r['title'];
                    $rewardCost = isset($r['cost']) ? (int)$r['cost'] : 0;
                    $customMsg = null;
                    // Upsert into per-user DB (no is_enabled column in schema)
                    $upsertSql = "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost, custom_message, managed_by) VALUES (?, ?, ?, ?, 'specter') ON DUPLICATE KEY UPDATE reward_title=VALUES(reward_title), reward_cost=VALUES(reward_cost), custom_message=IF(custom_message IS NULL OR custom_message = '', VALUES(custom_message), custom_message), managed_by=VALUES(managed_by)";
                    $stmtUp = $db->prepare($upsertSql);
                    if ($stmtUp) {
                        $stmtUp->bind_param('ssis', $rewardId, $rewardTitle, $rewardCost, $customMsg);
                        if (!$stmtUp->execute()) {
                            $syncErrors[] = 'DB execute failed for ' . $rewardId . ': ' . $stmtUp->error;
                        }
                        $stmtUp->close();
                    } else {
                        $syncErrors[] = 'DB prepare failed: ' . $db->error;
                    }
                }
            }
            $after = $data['pagination']['cursor'] ?? null;
        } else {
            $syncErrors[] = 'Twitch API Error: ' . $resp;
            break;
        }
    } while ($after);
    curl_close($ch);
}

// Fetch channel point rewards
$rewardsQuery = $db->prepare("SELECT reward_id, reward_title, custom_message, reward_cost, managed_by FROM channel_point_rewards ORDER BY CAST(reward_cost AS UNSIGNED) ASC");
$rewardsQuery->execute();
$result = $rewardsQuery->get_result();
$channelPointRewards = $result->fetch_all(MYSQLI_ASSOC);
$rewardsQuery->close();

if (!empty($syncErrors)) {
    $syncMessage = implode("\n", $syncErrors);
}

// Start output buffering for layout template
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-gift" style="margin-right:0.5rem;"></i>
            <?php echo t('channel_rewards_title'); ?>
        </span>
    </div>
    <div class="sp-card-body">
        <div class="sp-alert sp-alert-info" id="sync-result" style="display: none;">
            <strong>Sync Result:</strong><br>
            <pre id="sync-output"
                style="white-space: pre-wrap; max-height: 220px; overflow:auto; font-family: monospace; margin:0;"></pre>
        </div>
            <?php if ($showNoChannelPoints) : ?>
                <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                    <div>
                        <?php if (!empty($isOwner)) : ?>
                            <?php echo t('channel_rewards_no_channel_points_owner'); ?>
                        <?php else : ?>
                            <?php echo t('channel_rewards_no_channel_points_other', ['channel' => htmlspecialchars($channelDisplayName)]); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
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
                        <button class="sp-btn sp-btn-primary" id="sync-btn" style="margin-top: 0;"
                            <?php echo $showNoChannelPoints ? 'disabled title="' . t('channel_rewards_sync_disabled') . '"' : 'onclick="syncRewards()"'; ?>>
                            <span id="sync-btn-spinner" style="display:none;">
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                            <span id="sync-btn-text"><i class="fas fa-sync-alt"></i>
                                <?php echo t('channel_rewards_sync_btn'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
            <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
                <p style="font-weight:700; margin-bottom:0.25rem;">
                    <span class="icon"><i class="fas fa-exclamation-circle"></i></span>
                    Important Change: Fortune, Lotto, and TTS Variables
                </p>
                <p>
                    The <span style="font-weight:700;">(fortune)</span>, <span
                        style="font-weight:700;">(lotto)</span>, and <span
                        style="font-weight:700;">(tts)</span> are now text variables that can be used anywhere
                    in your custom message instead of reward name-based triggers. This allows you to use any reward
                    name you want without conflicts with other rewards.
                </p>
            </div>
            <div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
                <p style="font-weight:700; margin-bottom:0.25rem;">
                    <span class="icon"><i class="fas fa-code"></i></span>
                    <?php echo t('channel_rewards_custom_vars_title'); ?>
                </p>
                <p><?php echo t('channel_rewards_custom_vars_desc'); ?></p>
                <ul>
                    <li><span style="font-weight:700;">(user)</span>:
                        <?php echo t('channel_rewards_var_user'); ?>
                    </li>
                    <li><span style="font-weight:700;">(usercount)</span>:
                        <?php echo t('channel_rewards_var_usercount'); ?>
                    </li>
                    <li><span style="font-weight:700;">(userstreak)</span>:
                        <?php echo t('channel_rewards_var_userstreak'); ?>
                    </li>
                    <li><span style="font-weight:700;">(track)</span>: Tracks the internal usage count
                        of the reward.</li>
                    <li><span style="font-weight:700;">(fortune)</span>: Replaces with a random fortune
                        message.</li>
                    <li><span style="font-weight:700;">(lotto)</span>: Replaces with randomly generated
                        lotto numbers.</li>
                    <li><span style="font-weight:700;">(tts)</span>: Sends the user input to
                        text-to-speech (removes from message).</li>
                    <li><span style="font-weight:700;">(tts.message)</span>: Sends the final complete
                        message to both chat and text-to-speech.</li>
                    <li><span style="font-weight:700;">(customapi.URL)</span>: <?php echo t('channel_rewards_var_customapi'); ?></li>
                </ul>
                <div class="sp-betabot-toggle" style="margin-top:0.75rem;">
                    <input type="checkbox" class="switch" id="betaBotToggle" onchange="applyBetaBotCharLimit(this.checked)">
                    <label for="betaBotToggle">Using Beta Bot? <strong>Enables 500 character limit.</strong></label>
                </div>
            </div>
            <!-- Tabs -->
            <ul class="sp-tabs-nav" style="margin-bottom:1.25rem;">
                <li class="is-active" data-tab="tab-rewards">
                    <a>
                        <i class="fas fa-list"></i>
                        <span><?php echo t('channel_rewards_tab_rewards'); ?></span>
                    </a>
                </li>
                <li data-tab="tab-redemptions">
                    <a>
                        <i class="fas fa-history"></i>
                        <span><?php echo t('channel_rewards_tab_redemptions'); ?></span>
                    </a>
                </li>
                <li data-tab="tab-create">
                    <a>
                        <i class="fas fa-plus-circle"></i>
                        <span><?php echo t('channel_rewards_tab_create'); ?></span>
                    </a>
                </li>
            </ul>
            <!-- Tab Content: Rewards -->
            <div id="tab-content-rewards" class="tab-content" style="display: block;">
                <div class="sp-table-wrap">
                    <table class="sp-table" id="commandsTable">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Image</th>
                                <th><?php echo t('channel_rewards_reward_name'); ?></th>
                                <th><?php echo t('channel_rewards_custom_message'); ?></th>
                                <th style="width: 150px; text-align:center;">
                                    <?php echo t('channel_rewards_reward_cost'); ?>
                                </th>
                                <th style="width: 100px; text-align:center;">
                                    <?php echo t('channel_rewards_managed'); ?>
                                </th>
                                <th style="width: 100px; text-align:center;">
                                    <?php echo t('channel_rewards_editing'); ?>
                                </th>
                                <th style="width: 100px; text-align:center;">
                                    <?php echo t('channel_rewards_deleting'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 1. Fetch DB Rewards
                            $rewardsQuery = $db->prepare("SELECT reward_id, reward_title, custom_message, reward_cost, managed_by FROM channel_point_rewards");
                            $rewardsQuery->execute();
                            $result = $rewardsQuery->get_result();
                            $dbRewardsList = $result->fetch_all(MYSQLI_ASSOC);
                            $rewardsQuery->close();
                            $dbRewardsMap = [];
                            foreach ($dbRewardsList as $r) {
                                $dbRewardsMap[$r['reward_id']] = $r;
                            }
                            // 2. Fetch Twitch Rewards (skip for normal broadcasters)
                            $twitchRewards = [];
                            $twitchError = false;
                            if (!$showNoChannelPoints) {
                                $tToken = $_SESSION['access_token'];
                                $tBroadcasterId = $_SESSION['twitchUserId'];
                                $tClientId = isset($clientID) ? $clientID : '';
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
                                        foreach ($dbRewardsList as $dbItem) {
                                            $twitchRewards[] = [
                                                'id' => $dbItem['reward_id'],
                                                'title' => $dbItem['reward_title'],
                                                'cost' => $dbItem['reward_cost'],
                                                'managed_by' => $dbItem['managed_by'] ?? 'twitch',
                                            ];
                                        }
                                    }
                                }
                            }
                            // 3. Display Loop
                            if ($showNoChannelPoints) {
                                echo '<tr><td colspan="7" style="text-align:center;">' . t('channel_rewards_no_channel_points') . '</td></tr>';
                            } elseif (empty($twitchRewards)) {
                                echo '<tr><td colspan="7" style="text-align:center;">' . t('channel_rewards_no_rewards') . '</td></tr>';
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
                                    $syncIcon = $isSynced
                                        ? '<span style="color:var(--green);" title="Synced"><i class="fas fa-check-circle"></i></span>'
                                        : '<span style="color:var(--grey);" title="Not Synced"><i class="far fa-circle"></i></span>';
                                    ?>
                                    <tr <?php echo !$isSynced ? 'style="opacity:0.6;"' : ''; ?>>
                                        <td style="text-align:center; vertical-align:middle;">
                                            <?php
                                            // Try to get image URL - check multiple possible fields
                                            $imageUrl = '';
                                            if (isset($reward['image']['url_4x'])) {
                                                $imageUrl = $reward['image']['url_4x'];
                                            } elseif (isset($reward['default_image']['url_4x'])) {
                                                $imageUrl = $reward['default_image']['url_4x'];
                                            } elseif (isset($reward['image']['url_2x'])) {
                                                $imageUrl = $reward['image']['url_2x'];
                                            } elseif (isset($reward['default_image']['url_2x'])) {
                                                $imageUrl = $reward['default_image']['url_2x'];
                                            }
                                            if (!empty($imageUrl)): ?>
                                                <img src="<?php echo htmlspecialchars($imageUrl ?? '', ENT_QUOTES, 'UTF-8'); ?>" alt="Reward Icon"
                                                    style="width: 56px; height: 56px; border-radius: 4px;">
                                            <?php else: ?>
                                                <span style="color:var(--grey);">
                                                    <i class="fas fa-image fa-2x"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($rTitle ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($isSynced): ?>
                                                <div id="<?php echo $rId; ?>">
                                                    <?php echo htmlspecialchars($customMessage ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                                <div class="edit-box" id="edit-box-<?php echo $rId; ?>" style="display: none;">
                                                    <textarea class="sp-input custom-message"
                                                        data-reward-id="<?php echo $rId; ?>"
                                                        maxlength="255" style="height:auto; min-height:4rem;"><?php echo htmlspecialchars($customMessage ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                    <div class="character-count" id="count-<?php echo $rId; ?>"
                                                        style="margin-top: 5px; font-size: 0.8em;">0 / 255 characters
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-style:italic; color:var(--text-muted);">Not Synced</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center; vertical-align:middle;">
                                            <?php echo htmlspecialchars((string)($rCost ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td style="text-align:center; vertical-align:middle;">
                                            <?php
                                            $managedBy = $dbRewardsMap[$rId]['managed_by'] ?? 'twitch';
                                            if ($isSynced && $managedBy === 'specter'): ?>
                                                <span style="color:var(--green);" title="Managed by Specter">
                                                    <i class="fas fa-check-circle fa-lg"></i>
                                                </span>
                                            <?php elseif ($isSynced): ?>
                                                <button class="sp-btn sp-btn-warning sp-btn-sm manage-btn"
                                                    data-reward-id="<?php echo $rId; ?>"
                                                    title="Convert to Specter-managed reward">
                                                    <i class="fas fa-cog"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="sp-btn sp-btn-sm" disabled
                                                    title="Sync to enable managing" style="opacity:0.45;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center; vertical-align:middle;">
                                            <?php if ($isSynced): ?>
                                                <div class="edit-controls" id="controls-<?php echo $rId; ?>"
                                                    style="display: flex; justify-content: center; align-items: center;">
                                                    <button class="sp-btn sp-btn-info sp-btn-sm edit-btn"
                                                        data-reward-id="<?php echo $rId; ?>"><i
                                                            class="fas fa-pencil-alt"></i></button>
                                                    <div class="save-cancel" id="save-cancel-<?php echo $rId; ?>"
                                                        style="display: none;">
                                                        <button class="sp-btn sp-btn-success sp-btn-sm save-btn"
                                                            data-reward-id="<?php echo $rId; ?>"><i
                                                                class="fas fa-check"></i></button>
                                                        <button class="sp-btn sp-btn-danger sp-btn-sm cancel-btn"
                                                            data-reward-id="<?php echo $rId; ?>"><i
                                                                class="fas fa-times"></i></button>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <button class="sp-btn sp-btn-sm" disabled title="Sync to enable editing" style="opacity:0.45;">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center; vertical-align:middle;">
                                            <?php if ($isSynced): ?>
                                                <button class="sp-btn sp-btn-danger sp-btn-sm delete-btn"
                                                    data-reward-id="<?php echo $rId; ?>"
                                                    data-managed-by="<?php echo htmlspecialchars($managedBy ?? 'twitch', ENT_QUOTES, 'UTF-8'); ?>"><i
                                                        class="fas fa-trash-alt"></i></button>
                                            <?php else: ?>
                                                <button class="sp-btn sp-btn-sm" disabled style="opacity:0.45;">
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
                <!-- End Table Container -->
            </div>
        <!-- Tab Content: Redemptions -->
        <div id="tab-content-redemptions" class="tab-content" style="display: none;">
            <div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
                <p style="font-weight:700; margin-bottom:0.25rem;">
                    <span class="icon"><i class="fas fa-history"></i></span>
                    <?php echo t('channel_rewards_recent_redemptions'); ?>
                </p>
                <p>
                    <?php echo t('channel_rewards_recent_redemptions_desc'); ?>
                </p>
                <p style="margin-top:0.5rem;">
                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                    <em>Note: Redemption history is only available for rewards managed by the
                        Specter system. You can convert any reward to be managed by our system using the
                        Manage
                        button in the Rewards tab.</em>
                </p>
            </div>
            <div class="sp-table-wrap">
                <table class="sp-table">
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
                            <th style="text-align:center;">
                                <?php echo t('channel_rewards_cost'); ?>
                            </th>
                            <th style="text-align:center;">
                                <?php echo t('channel_rewards_status'); ?>
                            </th>
                            <th style="text-align:center;">
                                Actions
                            </th>
                            <th style="text-align:center;">
                                <?php echo t('channel_rewards_time'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="redemptions-table-body">
                        <tr>
                            <td colspan="6" style="text-align:center;"><i class="fas fa-spinner fa-spin"></i>
                                Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Tab Content: Create -->
        <div id="tab-content-create" class="tab-content" style="display: none;">
            <div class="sp-card" style="margin-bottom:0;">
                <div class="sp-card-body">
                <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:1rem;">
                    <?php echo t('channel_rewards_create_title'); ?>
                </h2>
                <form id="create-reward-form" enctype="multipart/form-data">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('channel_rewards_reward_name'); ?> *</label>
                            <input class="sp-input" type="text" name="title"
                                required placeholder="e.g. Hydrate!">
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('channel_rewards_reward_cost'); ?> *</label>
                            <input class="sp-input" type="number"
                                name="cost" required min="1" value="100">
                        </div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label"><?php echo t('channel_rewards_prompt'); ?></label>
                        <textarea class="sp-input" name="prompt" rows="2"
                            placeholder="Describe the reward..." style="height:auto; min-height:4rem;"></textarea>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label"><?php echo t('channel_rewards_bg_color'); ?></label>
                        <input class="sp-input" type="color"
                            name="background_color" value="#00E5CB" style="height: 40px; padding: 2px;">
                    </div>
                    <hr style="border-color:var(--border); margin:1rem 0;">
                    <h4 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
                        <?php echo t('channel_rewards_limits_header'); ?>
                    </h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem;">
                        <div class="sp-form-group">
                            <label style="cursor:pointer; color:var(--text-secondary);">
                                <input type="checkbox" name="is_max_per_stream_enabled" id="toggle-max-stream">
                                <?php echo t('channel_rewards_max_per_stream'); ?>
                            </label>
                            <div class="sp-form-group" id="field-max-stream" style="display:none; margin-top:0.5rem;">
                                <input class="sp-input" type="number"
                                    name="max_per_stream" min="1" placeholder="Max">
                            </div>
                        </div>
                        <div class="sp-form-group">
                            <label style="cursor:pointer; color:var(--text-secondary);">
                                <input type="checkbox" name="is_max_per_user_per_stream_enabled"
                                    id="toggle-max-user">
                                <?php echo t('channel_rewards_max_per_user'); ?>
                            </label>
                            <div class="sp-form-group" id="field-max-user" style="display:none; margin-top:0.5rem;">
                                <input class="sp-input" type="number"
                                    name="max_per_user_per_stream" min="1" placeholder="Max">
                            </div>
                        </div>
                        <div class="sp-form-group">
                            <label style="cursor:pointer; color:var(--text-secondary);">
                                <input type="checkbox" name="is_global_cooldown_enabled" id="toggle-cooldown">
                                <?php echo t('channel_rewards_cooldown'); ?>
                            </label>
                            <div class="sp-form-group" id="field-cooldown" style="display:none; margin-top:0.5rem;">
                                <input class="sp-input" type="number"
                                    name="global_cooldown_seconds" min="1" placeholder="Seconds">
                            </div>
                        </div>
                    </div>
                    <div style="margin-bottom:0.75rem;">
                        <label style="cursor:pointer; color:var(--text-secondary);">
                            <input type="checkbox" name="should_redemptions_skip_request_queue">
                            <?php echo t('channel_rewards_skip_queue'); ?>
                        </label>
                    </div>
                    <div style="margin-bottom:0.75rem;">
                        <label style="cursor:pointer; color:var(--text-secondary);">
                            <input type="checkbox" name="is_user_input_required">
                            <?php echo t('channel_rewards_req_user_input'); ?>
                        </label>
                    </div>
                    <hr style="border-color:var(--border); margin:1rem 0;">
                    <h4 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">Images</h4>
                    <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
                        <span class="icon"><i class="fas fa-info-circle"></i></span>
                        <strong>Note:</strong> Twitch does not allow uploading images during reward creation via
                        API.
                        Please create the reward first, then go to your <a
                            href="https://dashboard.twitch.tv/viewer-rewards/channel-points/rewards"
                            target="_blank">Twitch Dashboard</a> to upload custom icons.
                    </div>
                    <div style="display:flex; justify-content:flex-end; margin-top:1.25rem;">
                        <button class="sp-btn sp-btn-primary" id="create_reward_submit" <?php echo $showNoChannelPoints ? 'disabled title="' . t('channel_rewards_create_disabled') . '"' : ''; ?>>
                            <?php echo t('channel_rewards_create_btn'); ?>
                        </button>
                    </div>
                </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
// Prepare reward IDs for JS
$rewardMap = [];
if ($showNoChannelPoints) {
    // Prevent JS from attempting to fetch redemptions for channels without channel-points
    $channelPointRewards = [];
}
if (!empty($channelPointRewards)) {
    foreach ($channelPointRewards as $r) {
        // Only fetch redemptions for managed rewards to avoid 403s
        if (isset($r['managed_by']) && $r['managed_by'] === 'specter') {
            $rewardMap[$r['reward_id']] = $r['reward_title'];
        }
    }
}
?>
<script>
    const rewardMap = <?php echo json_encode($rewardMap); ?>;
    const allRewardIds = Object.keys(rewardMap);
    let allRedemptions = [];
    var charLimit = 255;
    function applyBetaBotCharLimit(enabled) {
        charLimit = enabled ? 500 : 255;
        localStorage.setItem('betaBotMode', enabled ? '1' : '0');
        document.querySelectorAll('.custom-message').forEach(function(el) {
            el.setAttribute('maxlength', charLimit);
            var rewardid = el.getAttribute('data-reward-id');
            if (rewardid) updateCharCount(rewardid);
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var betaEnabled = localStorage.getItem('betaBotMode') === '1';
        var toggle = document.getElementById('betaBotToggle');
        if (toggle) toggle.checked = betaEnabled;
        applyBetaBotCharLimit(betaEnabled);
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
            document.getElementById('redemptions-table-body').innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--red);">Failed to load redemptions.</td></tr>';
        }
    }
    function renderRedemptions(redemptions) {
        const tbody = document.getElementById('redemptions-table-body');
        tbody.innerHTML = '';
        if (redemptions.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;"><?php echo t('channel_rewards_no_redemptions'); ?></td></tr>`;
            return;
        }
        redemptions.forEach(r => {
            const rewardName = r.reward.title || rewardMap[r.reward.id] || r.reward.id;
            const input = r.user_input || '<span style="color:var(--text-muted); font-style:italic;">No input</span>';
            const cost = r.reward.cost;
            let statusColor = 'sp-badge sp-badge-amber';
            let actions = '';
            if (r.status === 'FULFILLED') {
                statusColor = 'sp-badge sp-badge-green';
                actions = '<span style="color:var(--green);"><i class="fas fa-check"></i></span>';
            } else if (r.status === 'CANCELED') {
                statusColor = 'sp-badge sp-badge-red';
                actions = '<span style="color:var(--red);"><i class="fas fa-times"></i></span>';
            } else {
                // UNFULFILLED
                actions = `
                    <div style="display:flex; gap:0.25rem; justify-content:center;">
                        <button class="sp-btn sp-btn-success sp-btn-sm" onclick="updateRedemptionStatus('${r.id}', '${r.reward.id}', 'FULFILLED', this)" title="Approve Redemption">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="sp-btn sp-btn-danger sp-btn-sm" onclick="updateRedemptionStatus('${r.id}', '${r.reward.id}', 'CANCELED', this)" title="Reject (Refund)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            }
            const date = new Date(r.redeemed_at).toLocaleString();
            const row = `
    <tr>
        <td>${escapeHtml(r.user_name)}</td>
        <td>${escapeHtml(rewardName)}</td>
        <td>${r.user_input ? escapeHtml(r.user_input) : input}</td>
        <td style="text-align:center;">${cost}</td>
        <td style="text-align:center;"><span class="${statusColor}">${escapeHtml(r.status)}</span></td>
        <td style="text-align:center;">${actions}</td>
        <td style="text-align:center;">${date}</td>
    </tr>
`;
            tbody.innerHTML += row;
        });
    }
    function updateRedemptionStatus(redemptionId, rewardId, status, btn) {
        // Disable buttons in the group
        const parentDiv = btn.closest('div');
        const buttons = parentDiv.querySelectorAll('button');
        buttons.forEach(b => b.disabled = true);
        const originalContent = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        const formData = new FormData();
        formData.append('redemption_id', redemptionId);
        formData.append('reward_id', rewardId);
        formData.append('status', status);
        fetch('manage_redemption.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Determine new visual state
                    let newHtml = '';
                    if (status === 'FULFILLED') {
                        newHtml = '<span style="color:var(--green);"><i class="fas fa-check"></i></span>';
                        // Update tag status locally for instant feedback
                        const row = btn.closest('tr');
                        const statusTag = row.querySelector('.sp-badge');
                        statusTag.className = 'sp-badge sp-badge-green';
                        statusTag.textContent = 'FULFILLED';
                    } else {
                        newHtml = '<span style="color:var(--red);"><i class="fas fa-times"></i></span>';
                        const row = btn.closest('tr');
                        const statusTag = row.querySelector('.sp-badge');
                        statusTag.className = 'sp-badge sp-badge-red';
                        statusTag.textContent = 'CANCELED';
                    }
                    // Replace the button group with the icon
                    parentDiv.parentNode.innerHTML = newHtml;
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: data.error || 'Failed to update status',
                        icon: 'error',
                        background: '#333',
                        color: '#fff'
                    });
                    // Revert button state
                    buttons.forEach(b => b.disabled = false);
                    btn.innerHTML = originalContent;
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    title: 'Error',
                    text: 'Network error occurred',
                    icon: 'error',
                    background: '#333',
                    color: '#fff'
                });
                buttons.forEach(b => b.disabled = false);
                btn.innerHTML = originalContent;
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
    function deleteReward(rewardid, managedBy) {
        let msg = '';
        if (managedBy === 'specter') {
            msg = <?php echo json_encode(t('channel_rewards_delete_specter_msg')); ?>;
        } else {
            msg = <?php echo json_encode(t('channel_rewards_delete_twitch_msg')); ?>;
        }
        Swal.fire({
            title: <?php echo json_encode(t('channel_rewards_delete_confirm')); ?>,
            text: msg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: <?php echo json_encode(t('channel_rewards_yes')); ?>,
            cancelButtonText: <?php echo json_encode(t('channel_rewards_cancel')); ?>,
            background: '#333',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                // Use fetch + JSON response handling
                fetch('', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'deleteRewardId=' + encodeURIComponent(rewardid)
                })
                    .then(res => {
                        // Try to parse JSON on success, otherwise throw with text
                        const contentType = res.headers.get('content-type') || '';
                        if (!res.ok) {
                            return res.text().then(txt => { throw new Error(txt || ('HTTP ' + res.status)); });
                        }
                        if (contentType.indexOf('application/json') === -1) {
                            return res.text().then(txt => { throw new Error(txt || 'Unexpected response'); });
                        }
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted', text: data.message || '' }).then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Failed to delete reward.' });
                        }
                    })
                    .catch(err => {
                        Swal.fire({ icon: 'error', title: 'Error', text: err.message || 'Request failed' });
                    });
            }
        });
    }
    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest('.delete-btn')) {
            const btn = e.target.closest('.delete-btn');
            // Prevent double clicks
            if (btn.disabled) return;
            const rewardId = btn.getAttribute('data-reward-id');
            const managedBy = btn.getAttribute('data-managed-by');
            if (rewardId) {
                deleteReward(rewardId, managedBy);
            }
        }
    });
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
        syncResultContainer.classList.remove('sp-alert-info', 'sp-alert-success', 'sp-alert-danger');
        syncResultContainer.classList.add('sp-alert-' + (variant === 'info' ? 'info' : variant === 'success' ? 'success' : 'danger'));
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
    function manageReward(rewardId, button) {
        Swal.fire({
            title: <?php echo json_encode(t('channel_rewards_managed')); ?>,
            text: <?php echo json_encode(t('channel_rewards_managed_confirm_desc')); ?>,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel',
            background: '#333',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('reward_id', rewardId);
                fetch('manage_reward.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: <?php echo json_encode(t('channel_rewards_managed_success')); ?>,
                                icon: 'success',
                                background: '#333',
                                color: '#fff'
                            }).then(() => {
                                location.reload();
                            });
                        } else if (data.error === 'manual_delete_required') {
                            // MANUAL DELETE FLOW
                            let msg = <?php echo json_encode(t('channel_rewards_manual_delete_msg')); ?>;
                            msg = msg.replace('{reward}', data.title || 'the reward');
                            Swal.fire({
                                title: <?php echo json_encode(t('channel_rewards_manual_delete_title')); ?>,
                                html: msg,
                                icon: 'warning',
                                background: '#333',
                                color: '#fff',
                                showCancelButton: true,
                                confirmButtonColor: '#48c774',
                                cancelButtonColor: '#d33',
                                confirmButtonText: <?php echo json_encode(t('channel_rewards_manual_confirm_btn')); ?>,
                                cancelButtonText: <?php echo json_encode(t('channel_rewards_cancel')); ?>,
                                allowOutsideClick: false
                            }).then((res) => {
                                if (res.isConfirmed) {
                                    // Step 2: Complete Manual
                                    const step2Data = new FormData();
                                    step2Data.append('action', 'complete_manual');
                                    step2Data.append('reward_id', rewardId);
                                    // Show loading again
                                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                                    fetch('manage_reward.php', {
                                        method: 'POST',
                                        body: step2Data
                                    })
                                        .then(r2 => r2.json())
                                        .then(d2 => {
                                            if (d2.success) {
                                                Swal.fire({
                                                    title: 'Success!',
                                                    text: <?php echo json_encode(t('channel_rewards_managed_success')); ?>,
                                                    icon: 'success',
                                                    background: '#333',
                                                    color: '#fff'
                                                }).then(() => {
                                                    location.reload();
                                                });
                                            } else {
                                                Swal.fire({
                                                    title: 'Error!',
                                                    text: 'Error: ' + (d2.error || 'Unknown error'),
                                                    icon: 'error',
                                                    background: '#333',
                                                    color: '#fff'
                                                });
                                                button.disabled = false;
                                                button.innerHTML = '<i class="fas fa-cog"></i>';
                                            }
                                        })
                                        .catch(err2 => {
                                            console.error('Error Step 2:', err2);
                                            Swal.fire({
                                                title: 'Error!',
                                                text: 'An error occurred during creation.',
                                                icon: 'error',
                                                background: '#333',
                                                color: '#fff'
                                            });
                                            button.disabled = false;
                                            button.innerHTML = '<i class="fas fa-cog"></i>';
                                        });
                                } else {
                                    button.disabled = false;
                                    button.innerHTML = '<i class="fas fa-cog"></i>';
                                }
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: 'Error: ' + (data.error || 'Unknown error') + (data.http_code ? ' (Code: ' + data.http_code + ')' : ''),
                                icon: 'error',
                                background: '#333',
                                color: '#fff'
                            });
                            button.disabled = false;
                            button.innerHTML = '<i class="fas fa-cog"></i>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error!',
                            text: 'An error occurred while processing your request.',
                            icon: 'error',
                            background: '#333',
                            color: '#fff'
                        });
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-cog"></i>';
                    });
            } else {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-cog"></i>';
            }
        });
    }
    // Event delegation for dynamically added elements or just simplicity
    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest('.manage-btn')) {
            const btn = e.target.closest('.manage-btn');
            // Prevent double clicks
            if (btn.disabled) return;
            const rewardId = btn.getAttribute('data-reward-id');
            if (rewardId) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                manageReward(rewardId, btn);
            }
        }
    });
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
        countDiv.textContent = length + ' / ' + charLimit + ' characters';
        if (length > charLimit) {
            countDiv.style.color = 'red';
        } else {
            countDiv.style.color = '';
        }
    }
    // Tabs Logic and Form Handling
    document.addEventListener('DOMContentLoaded', () => {
        // Tab Switching
        const tabs = document.querySelectorAll('.sp-tabs-nav li');
        const contents = document.querySelectorAll('.tab-content');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Deactivate all
                tabs.forEach(t => t.classList.remove('is-active'));
                contents.forEach(c => c.style.display = 'none');
                // Activate selected
                tab.classList.add('is-active');
                const targetId = tab.getAttribute('data-tab');
                const targetContent = document.getElementById('tab-content-' + targetId.replace('tab-', ''));
                if (targetContent) {
                    targetContent.style.display = 'block';
                }
            });
        });
        const form = document.getElementById('create-reward-form');
        const submitBtn = document.getElementById('create_reward_submit');
        // Toggles
        const toggleIds = ['toggle-max-stream', 'toggle-max-user', 'toggle-cooldown'];
        toggleIds.forEach(id => {
            const toggle = document.getElementById(id);
            if (toggle) {
                toggle.addEventListener('change', () => {
                    const fieldId = 'field-' + id.replace('toggle-', '');
                    const field = document.getElementById(fieldId);
                    if (field) field.style.display = toggle.checked ? 'block' : 'none';
                });
            }
        });
        // File Inputs Name Display
        const fileInputs = document.querySelectorAll('.file-input');
        fileInputs.forEach(input => {
            input.addEventListener('change', () => {
                if (input.files.length > 0) {
                    const fileName = input.parentNode.querySelector('.file-label');
                    if (fileName) fileName.textContent = input.files[0].name;
                }
            });
        });
        if (submitBtn) {
            submitBtn.addEventListener('click', (e) => {
                e.preventDefault();
                // Validate required
                const title = form.querySelector('[name="title"]').value;
                const cost = form.querySelector('[name="cost"]').value;
                if (!title || !cost) {
                    Swal.fire({
                        title: 'Error',
                        text: 'Please fill in Title and Cost.',
                        icon: 'error',
                        background: '#333',
                        color: '#fff'
                    });
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.7';
                submitBtn.textContent = 'Creating...';
                const formData = new FormData(form);
                fetch('create_reward.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '';
                        submitBtn.textContent = <?php echo json_encode(t('channel_rewards_create_btn')); ?>;
                        if (data.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: <?php echo json_encode(t('channel_rewards_created_success')); ?>,
                                icon: 'success',
                                background: '#333',
                                color: '#fff'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: data.error || 'Unknown error occurred',
                                icon: 'error',
                                background: '#333',
                                color: '#fff'
                            });
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '';
                        submitBtn.textContent = <?php echo json_encode(t('channel_rewards_create_btn')); ?>;
                        Swal.fire({
                            title: 'Error!',
                            text: 'A network error occurred.',
                            icon: 'error',
                            background: '#333',
                            color: '#fff'
                        });
                    });
            });
        }
    });
</script>
<?php
// Get the buffered content
$content = ob_get_clean();
// Use the dashboard layout
include 'layout.php';
?>