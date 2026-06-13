<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/lib/require_auth.php';
include "/var/www/config/streamelements.php";
include "/var/www/config/db_connect.php";
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$pageTitle = t('navbar_streamelements');
// Check if user is logged in and has Twitch user ID
$twitchUserId = $_SESSION['twitchUserId'] ?? null;
$isLinked = false;
$linkingMessage = '';
$linkingMessageType = '';

// Include files for database and user data
include 'includes/userdata.php';
include 'includes/bot_control.php';
include "includes/mod_access.php";
include 'includes/user_db.php';
include 'includes/storage_used.php';
$isActAsUser = isset($isActAs) && $isActAs === true;
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

if ($twitchUserId) {
    // Check if StreamElements is already linked for this user and fetch token
    $stmt = $conn->prepare("SELECT access_token, jwt_token FROM streamelements_tokens WHERE twitch_user_id = ?");
    $stmt->bind_param("s", $twitchUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $access_token = $row['access_token'];
        $stored_jwt_token = $row['jwt_token'] ?? null;
        // Validate the token
        $validate_url = "https://api.streamelements.com/oauth2/validate";
        $ch = curl_init($validate_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: oAuth {$access_token}"]);
        $validate_response = curl_exec($ch);
        $validate_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$validate_data = json_decode($validate_response, true);
        if ($validate_code === 200 && isset($validate_data['channel_id'])) {
            $isLinked = true;
            // Calculate time until token expires (can be days/weeks)
            $expires_in = isset($validate_data['expires_in']) ? (int)$validate_data['expires_in'] : null;
            $expires_str = '';
            if ($expires_in !== null) {
                $days = floor($expires_in / 86400);
                $hours = floor(($expires_in % 86400) / 3600);
                $minutes = floor(($expires_in % 3600) / 60);
                $parts = [];
                if ($days > 0) $parts[] = ($days > 1 ? t('streamelements_duration_days', [$days]) : t('streamelements_duration_day', [$days]));
                if ($hours > 0) $parts[] = ($hours > 1 ? t('streamelements_duration_hours', [$hours]) : t('streamelements_duration_hour', [$hours]));
                if ($minutes > 0 && count($parts) < 2) $parts[] = ($minutes > 1 ? t('streamelements_duration_minutes', [$minutes]) : t('streamelements_duration_minute', [$minutes]));
                $expires_str = implode(', ', $parts);
            }
            // Fetch StreamElements user profile
            $profile_url = "https://api.streamelements.com/kappa/v2/channels/me";
            $ch = curl_init($profile_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Accept: application/json",
                "Authorization: oAuth {$access_token}"
            ]);
            $profile_response = curl_exec($ch);
$profile_data = json_decode($profile_response, true);
            $apiToken = $profile_data['apiToken'] ?? null;
            // Fetch StreamElements current user to get JWT token and channel ID
            // Use JWT token if available, otherwise skip this call
            if ($stored_jwt_token) {
                $current_user_url = "https://api.streamelements.com/kappa/v2/users/current";
                $ch = curl_init($current_user_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Accept: application/json; charset=utf-8",
                    "Authorization: Bearer {$stored_jwt_token}"
                ]);
            }
            $current_user_response = curl_exec($ch);
            $current_user_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$jwtToken = null;
            $channelId = null;
            if ($current_user_code === 200) {
                $current_user_data = json_decode($current_user_response, true);
                if (isset($current_user_data['channels']) && is_array($current_user_data['channels'])) {
                    // Find the primary channel or first channel
                    foreach ($current_user_data['channels'] as $channel) {
                        // Get channel ID
                        if (isset($channel['_id'])) {
                            $channelId = $channel['_id'];
                        }
                        // Get JWT token if available
                        if (!$stored_jwt_token && isset($channel['lastJWTToken']) && !empty($channel['lastJWTToken'])) {
                            $jwtToken = $channel['lastJWTToken'];
                        }
                        // Break after first channel (usually the primary one)
                        if ($channelId) {
                            break;
                        }
                    }
                }
                // Store channel ID in session for other API calls
                if ($channelId) {$_SESSION['streamelements_channel_id'] = $channelId;}
                // Update the database with the JWT token if found and not already stored
                if ($jwtToken && !$stored_jwt_token) {
                    $update_jwt_stmt = $conn->prepare("UPDATE streamelements_tokens SET jwt_token = ? WHERE twitch_user_id = ?");
                    $update_jwt_stmt->bind_param("ss", $jwtToken, $twitchUserId);
                    $update_jwt_stmt->execute();
                    $update_jwt_stmt->close();
                    $stored_jwt_token = $jwtToken;
                }
            }
            // Get createdAt and format as readable date
            $createdAt = $profile_data['createdAt'] ?? null;
            $createdAtFormatted = '';
            if ($createdAt) { try { $dt = new DateTime($createdAt); $createdAtFormatted = $dt->format('F j, Y'); } catch (Exception $e) {
                    $createdAtFormatted = htmlspecialchars($createdAt); } }
            $inactive = isset($profile_data['inactive']) ? (bool)$profile_data['inactive'] : null;
            $isPartner = isset($profile_data['isPartner']) ? (bool)$profile_data['isPartner'] : null;
            $suspended = isset($profile_data['suspended']) ? (bool)$profile_data['suspended'] : null;
        }
    }
    $stmt->close();
}

// Set up StreamElements OAuth2 parameters
$client_id = $streamelements_client_id;
$client_secret = $streamelements_client_secret;
$redirect_uri = 'https://dashboard.botofthespecter.com/streamelements.php';
$scope = 'channel:read tips:read';

// Handle user denial (error=true in query string)
if ($isActAsUser && isset($_GET['code'])) {
    $linkingMessage = t('streamelements_msg_actas_disabled');
    $linkingMessageType = "is-warning";
} elseif (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = t('streamelements_msg_auth_denied');
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code']) && !$isActAsUser) {
    // Optional: Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamelements_oauth_state']) || $_GET['state'] !== $_SESSION['streamelements_oauth_state']) {
        $linkingMessage = t('streamelements_msg_invalid_state');
        $linkingMessageType = "is-danger";
    } else {
        unset($_SESSION['streamelements_oauth_state']);
        $code = $_GET['code'];
        $token_url = "https://api.streamelements.com/oauth2/token";
        $post_fields = http_build_query([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri
        ]);
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$token_data = json_decode($response, true);
        if ($httpcode === 200 && isset($token_data['access_token'])) {
            $access_token = $token_data['access_token'];
            $refresh_token = $token_data['refresh_token'];
            // Store refresh_token and expires_in if present
            if (isset($token_data['refresh_token'])) { $_SESSION['streamelements_refresh_token'] = $token_data['refresh_token']; }
            if (isset($token_data['expires_in'])) { $_SESSION['streamelements_expires_in'] = $token_data['expires_in']; }
            // Validate the token
            $validate_url = "https://api.streamelements.com/oauth2/validate";
            $ch = curl_init($validate_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: oAuth {$access_token}"]);
            $validate_response = curl_exec($ch);
            $validate_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$validate_data = json_decode($validate_response, true);
            if ($validate_code === 200 && isset($validate_data['channel_id'])) {
                $_SESSION['streamelements_token'] = $access_token;
                // Fetch StreamElements current user to get JWT token
                $current_user_url = "https://api.streamelements.com/kappa/v2/users/current";
                $ch = curl_init($current_user_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Accept: application/json; charset=utf-8",
                    "Authorization: Bearer {$access_token}"
                ]);
                $current_user_response = curl_exec($ch);
                $current_user_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$jwtToken = null;
                $channelId = null;
                if ($current_user_code === 200) {
                    $current_user_data = json_decode($current_user_response, true);
                    if (isset($current_user_data['channels']) && is_array($current_user_data['channels'])) {
                        // Find the primary channel or first channel
                        foreach ($current_user_data['channels'] as $channel) {
                            // Get channel ID
                            if (isset($channel['_id'])) {
                                $channelId = $channel['_id'];
                            }
                            // Get JWT token if available
                            if (isset($channel['lastJWTToken']) && !empty($channel['lastJWTToken'])) {
                                $jwtToken = $channel['lastJWTToken'];
                            }
                            // Break after first channel (usually the primary one)
                            if ($channelId) {
                                break;
                            }
                        }
                    }
                    // Store channel ID in session for other API calls
                    if ($channelId) {
                        $_SESSION['streamelements_channel_id'] = $channelId;
                    }
                }
                if (isset($_SESSION['twitchUserId']) && $refresh_token) {
                    $twitchUserId = $_SESSION['twitchUserId'];
                    // Prepare the query with JWT token support
                    if ($jwtToken) {
                        $query = "INSERT INTO streamelements_tokens (twitch_user_id, access_token, refresh_token, jwt_token) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), jwt_token = VALUES(jwt_token)";
                        if ($stmt = $conn->prepare($query)) {
                            $stmt->bind_param('ssss', $twitchUserId, $access_token, $refresh_token, $jwtToken);
                        }
                    } else {
                        $query = "INSERT INTO streamelements_tokens (twitch_user_id, access_token, refresh_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token)";
                        if ($stmt = $conn->prepare($query)) {
                            $stmt->bind_param('sss', $twitchUserId, $access_token, $refresh_token);
                        }
                    }
                    if ($stmt) {
                        if ($stmt->execute()) {
                            $linkingMessage = t('streamelements_msg_link_success');
                            $linkingMessageType = "is-success";
                            $isLinked = true;
                            // Redirect to refresh page and show linked status
                            header("Location: streamelements.php");
                            exit();
                        } else {
                            $linkingMessage = t('streamelements_msg_link_save_failed');
                            $linkingMessageType = "is-warning";
                        }
                        $stmt->close();
                    } else {
                        $linkingMessage = t('streamelements_msg_link_prepare_failed');
                        $linkingMessageType = "is-warning";
                    }
                } else {
                    $linkingMessage = t('streamelements_msg_link_missing_ids');
                    $linkingMessageType = "is-warning";
                }
            } else {
                $linkingMessage = t('streamelements_msg_token_validation_failed');
                $linkingMessageType = "is-danger";
                if (isset($validate_data['message'])) {
                    $linkingMessage .= " " . t('streamelements_msg_error_prefix') . " " . htmlspecialchars($validate_data['message']);
                }
            }
        } else {
            $linkingMessage = t('streamelements_msg_link_failed');
            $linkingMessageType = "is-danger";
            if (isset($token_data['error'])) {
                $linkingMessage .= " " . t('streamelements_msg_error_prefix') . " " . htmlspecialchars($token_data['error']);
            }
            if (isset($token_data['error_description'])) {
                $linkingMessage .= " " . t('streamelements_msg_description_prefix') . " " . htmlspecialchars($token_data['error_description']);
            }
        }
    }
}

// Generate auth URL for manual linking
$authURL = '';
if (!$isLinked && !$isActAsUser) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['streamelements_oauth_state'] = $state;
    $authURL = "https://api.streamelements.com/oauth2/authorize"
        . "?client_id={$client_id}"
        . "&response_type=code"
        . "&scope=" . urlencode($scope)
        . "&state={$state}"
        . "&redirect_uri=" . $redirect_uri;
}

// Fetch recent tips if user is linked and we have JWT token
$recentTips = [];
$tips_code = null; // Initialize to track the API response code
if ($isLinked && isset($stored_jwt_token) && !empty($stored_jwt_token)) {
    $channelId = $_SESSION['streamelements_channel_id'] ?? null;
    // If we don't have channel ID in session, fetch it
    if (!$channelId) {
        $current_user_url = "https://api.streamelements.com/kappa/v2/users/current";
        $ch = curl_init($current_user_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json; charset=utf-8",
            "Authorization: Bearer {$stored_jwt_token}"
        ]);
        $current_user_response = curl_exec($ch);
        $current_user_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($current_user_code === 200) {
            $current_user_data = json_decode($current_user_response, true);
            if (isset($current_user_data['channels']) && is_array($current_user_data['channels'])) {
                foreach ($current_user_data['channels'] as $channel) {
                    if (isset($channel['_id'])) {
                        $channelId = $channel['_id'];
                        $_SESSION['streamelements_channel_id'] = $channelId;
                        break;
                    }
                }
            }
        }
    }
    // Fetch tips if we have channel ID
    if ($channelId) {
        $tips_url = "https://api.streamelements.com/kappa/v2/tips/{$channelId}?limit=100";
        $ch = curl_init($tips_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json; charset=utf-8",
            "Authorization: Bearer {$stored_jwt_token}"
        ]);
        $tips_response = curl_exec($ch);
        $tips_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($tips_code === 200) {
            $tips_data = json_decode($tips_response, true);
            if (isset($tips_data['docs']) && is_array($tips_data['docs'])) {
                $recentTips = $tips_data['docs']; // Get all tips from API response
            }
        }
    }
}

session_write_close();
ob_start();
?>
<div class="sp-card">
    <header class="sp-card-header">
        <div style="display:flex;align-items:center;flex:1;min-width:0;flex-wrap:wrap;gap:0.5rem;">
            <p class="sp-card-title" style="flex-shrink:0;">
                <i class="fas fa-bolt" style="color:var(--accent-hover);margin-right:0.4em;"></i>
                <?= t('streamelements_integration_title') ?>
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;margin-left:0.75rem;">
                <?php if ($isLinked): ?>
                    <?php if ($inactive !== null): ?>
                        <span class="sp-badge <?php echo $inactive ? 'sp-badge-red' : 'sp-badge-green'; ?>" title="<?= htmlspecialchars(t('streamelements_badge_account_status')) ?>">
                            <i class="fa-regular fa-user"></i>
                            <?php echo $inactive ? t('streamelements_status_inactive') : t('streamelements_status_active'); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($createdAtFormatted): ?>
                        <span class="sp-badge sp-badge-blue" title="<?= htmlspecialchars(t('streamelements_badge_account_created')) ?>">
                            <i class="fa-regular fa-calendar"></i>
                            <?php echo $createdAtFormatted; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($isPartner !== null): ?>
                        <span class="sp-badge <?php echo $isPartner ? 'sp-badge-accent' : 'sp-badge-grey'; ?>" title="<?= htmlspecialchars(t('streamelements_badge_partner')) ?>">
                            <i class="fa-solid fa-star"></i>
                            <?= t('streamelements_badge_se_partner') ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($suspended !== null): ?>
                        <span class="sp-badge <?php echo $suspended ? 'sp-badge-red' : 'sp-badge-green'; ?>" title="<?= htmlspecialchars(t('streamelements_badge_suspended')) ?>">
                            <i class="fa-solid fa-ban"></i>
                            <?php echo $suspended ? t('streamelements_status_suspended') : t('streamelements_status_active'); ?>
                        </span>
                    <?php endif; ?>
                    <span class="sp-badge sp-badge-green">
                        <i class="fas fa-check-circle"></i>
                        <?= t('streamelements_status_connected') ?>
                    </span>
                <?php else: ?>
                    <span class="sp-badge sp-badge-red">
                        <i class="fas fa-times-circle"></i>
                        <?= t('streamelements_status_not_connected') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <div class="sp-card-body">
        <?php if ($linkingMessage): ?>
            <div class="sp-alert <?php echo $linkingMessageType === 'is-success' ? 'sp-alert-success' : ($linkingMessageType === 'is-danger' ? 'sp-alert-danger' : 'sp-alert-warning'); ?>" style="margin-bottom:1.5rem;">
                <?php if ($linkingMessageType === 'is-danger'): ?>
                    <i class="fas fa-exclamation-triangle"></i>
                <?php elseif ($linkingMessageType === 'is-success'): ?>
                    <i class="fas fa-check"></i>
                <?php else: ?>
                    <i class="fas fa-info-circle"></i>
                <?php endif; ?>
                <?php echo $linkingMessage; ?>
            </div>
        <?php endif; ?>
        <?php if ($isLinked): ?>
            <!-- Account status text -->
            <div style="text-align:center;padding:1rem 2rem 2rem;">
                <p style="color:var(--text-secondary);max-width:600px;margin:0 auto 1rem;">
                    <?= t('streamelements_linked_success_message') ?>
                </p>
                <?php if ($expires_str): ?>
                    <div class="sp-alert sp-alert-info" style="max-width:600px;margin:0 auto;">
                        <i class="fas fa-clock"></i>
                        <?= t('streamelements_token_status_autorenew', ['expires' => '<strong>' . htmlspecialchars($expires_str) . '</strong>']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Tokens section -->
            <?php if ($apiToken || $stored_jwt_token): ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:1.5rem;margin-bottom:1.5rem;">
                    <?php if ($apiToken): ?>
                        <div class="sp-card" style="margin-bottom:0;">
                            <header class="sp-card-header">
                                <p class="sp-card-title">
                                    <i class="fas fa-key" style="color:var(--amber);margin-right:0.4em;"></i>
                                    <?= t('streamelements_api_token_title') ?>
                                </p>
                            </header>
                            <div class="sp-card-body">
                                <div class="sp-form-group">
                                    <label class="sp-label"><?= t('streamelements_api_token_label') ?></label>
                                    <div style="display:flex;">
                                        <input class="sp-input" type="text" id="apiTokenDisplay" value="<?php echo str_repeat('•', strlen($apiToken)); ?>" readonly style="border-radius:var(--radius) 0 0 var(--radius);font-family:monospace;letter-spacing:1.5px;">
                                        <button id="showApiTokenBtn" class="sp-btn sp-btn-warning" style="border-radius:0 var(--radius) var(--radius) 0;border-left:none;" title="<?= htmlspecialchars(t('streamelements_show_api_token')) ?>">
                                            <i id="apiTokenEye" class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem;">
                                        <i class="fas fa-exclamation-triangle" style="color:var(--amber);margin-right:0.25em;"></i>
                                        <?= t('streamelements_api_token_warning') ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($stored_jwt_token): ?>
                        <div class="sp-card" style="margin-bottom:0;">
                            <header class="sp-card-header">
                                <p class="sp-card-title">
                                    <i class="fas fa-shield-alt" style="color:var(--blue);margin-right:0.4em;"></i>
                                    <?= t('streamelements_jwt_token_title') ?>
                                </p>
                            </header>
                            <div class="sp-card-body">
                                <div class="sp-form-group">
                                    <label class="sp-label"><?= t('streamelements_jwt_token_label') ?></label>
                                    <div style="display:flex;">
                                        <input class="sp-input" type="text" id="jwtTokenDisplay" value="<?php echo str_repeat('•', strlen($stored_jwt_token)); ?>" readonly style="border-radius:var(--radius) 0 0 var(--radius);font-family:monospace;letter-spacing:1.5px;">
                                        <button id="showJwtTokenBtn" class="sp-btn sp-btn-info" style="border-radius:0 var(--radius) var(--radius) 0;border-left:none;" title="<?= htmlspecialchars(t('streamelements_show_jwt_token')) ?>">
                                            <i id="jwtTokenEye" class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem;">
                                        <i class="fas fa-exclamation-triangle" style="color:var(--amber);margin-right:0.25em;"></i>
                                        <?= t('streamelements_jwt_token_warning') ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <!-- Recent Tips section -->
            <?php if (!empty($recentTips)): ?>
                <div class="sp-card" style="margin-top:1.5rem;">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-dollar-sign" style="color:var(--green);margin-right:0.4em;"></i>
                            <?= t('streamelements_recent_tips_title') ?> (<?php echo count($recentTips); ?>)
                        </p>
                    </header>
                    <div class="sp-card-body" style="padding:0;">
                        <div class="sp-table-wrap" style="border:none;border-radius:0;">
                            <table class="sp-table">
                                <thead>
                                    <tr>
                                        <th><?= t('streamelements_table_tipper') ?></th>
                                        <th><?= t('streamelements_table_amount') ?></th>
                                        <th><?= t('streamelements_table_message') ?></th>
                                        <th><?= t('streamelements_table_provider') ?></th>
                                        <th><?= t('streamelements_table_date') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentTips as $tip): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($tip['donation']['user']['username'] ?? t('streamelements_tipper_anonymous')); ?></td>
                                            <td>
                                                <span class="sp-badge sp-badge-green">
                                                    <?php
                                                    $amount = $tip['donation']['amount'] ?? 0;
                                                    $currency = $tip['donation']['currency'] ?? 'USD';
                                                    // Format amount (appears to already be in dollar format, not cents)
                                                    $formatted_amount = number_format((float)$amount, 2);
                                                    // Display currency symbol instead of code when possible
                                                    $currency_symbol = match($currency) {
                                                        'USD' => '$',
                                                        'EUR' => '€',
                                                        'GBP' => '£',
                                                        'CAD' => 'C$',
                                                        'AUD' => 'A$',
                                                        default => $currency . ' '
                                                    };
                                                    echo htmlspecialchars($currency_symbol . $formatted_amount);
                                                    ?>
                                                </span>
                                            </td>
                                            <td style="max-width:200px;word-break:break-word;">
                                                <?php
                                                $message = $tip['donation']['message'] ?? '';
                                                echo htmlspecialchars(strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message);
                                                ?>
                                            </td>
                                            <td>
                                                <span class="sp-badge sp-badge-blue">
                                                    <?php echo htmlspecialchars(ucfirst($tip['provider'] ?? t('streamelements_provider_unknown'))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                if (isset($tip['createdAt'])) {
                                                    try {
                                                        $dt = new DateTime($tip['createdAt']);
                                                        echo $dt->format('M j, Y g:i A');
                                                    } catch (Exception $e) {
                                                        echo htmlspecialchars($tip['createdAt']);
                                                    }
                                                } else {
                                                    echo t('streamelements_date_unknown');
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Tips status section -->
            <?php if ($isLinked && isset($access_token)): ?>
                <?php if (empty($recentTips)): ?>
                    <div class="sp-card" style="margin-top:1.5rem;">
                        <header class="sp-card-header">
                            <p class="sp-card-title">
                                <i class="fas fa-info-circle" style="color:var(--blue);margin-right:0.4em;"></i>
                                <?= t('streamelements_tips_status_title') ?>
                            </p>
                        </header>
                        <div class="sp-card-body">
                            <div class="sp-alert sp-alert-info">
                                <p><strong><?= t('streamelements_no_recent_tips') ?></strong></p>
                                <?php if (isset($_SESSION['streamelements_channel_id'])): ?>
                                    <p><strong><?= t('streamelements_channel_id_label') ?></strong> <?php echo htmlspecialchars($_SESSION['streamelements_channel_id']); ?></p>
                                    <p><?= t('streamelements_channel_id_checking') ?></p>
                                    <?php if (isset($tips_code)): ?>
                                        <p><strong><?= t('streamelements_tips_api_response_code') ?></strong> <?php echo $tips_code; ?></p>
                                        <?php if ($tips_code !== 200): ?>
                                            <p style="color:var(--red);"><?= t('streamelements_tips_api_failed', ['code' => $tips_code]) ?></p>
                                        <?php else: ?>
                                            <p><?= t('streamelements_tips_api_success_empty') ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p style="color:var(--amber);"><?= t('streamelements_channel_id_unavailable') ?></p>
                                    <p><?= t('streamelements_current_user_call_failed') ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <!-- Not linked display -->
            <div style="text-align:center;padding:1.5rem 0;">
                <p style="color:var(--text-secondary);margin-bottom:1.5rem;"><?= t('streamelements_connect_prompt') ?></p>
                <div class="sp-card" style="max-width:600px;margin:0 auto 1.5rem;text-align:left;">
                    <div class="sp-card-body">
                        <p style="font-weight:700;margin-bottom:0.75rem;">
                            <i class="fas fa-bolt" style="color:var(--blue);margin-right:0.4em;"></i>
                            <?= t('streamelements_available_features') ?>
                        </p>
                        <ul style="list-style:disc;padding-left:1.25rem;color:var(--text-secondary);">
                            <li style="margin-bottom:0.4rem;"><?= t('streamelements_feature_tips_data') ?></li>
                            <li style="margin-bottom:0.4rem;"><?= t('streamelements_feature_bot_commands') ?></li>
                            <li><?= t('streamelements_feature_engagement') ?></li>
                        </ul>
                    </div>
                </div>
                <?php if ($authURL): ?>
                    <a href="<?php echo $authURL; ?>" class="sp-btn sp-btn-info" style="padding:0.75rem 1.5rem;font-size:1rem;">
                        <i class="fas fa-bolt"></i>
                        <span><?= t('streamelements_link_account_button') ?></span>
                    </a>
                <?php elseif ($isActAsUser): ?>
                    <div class="sp-alert sp-alert-warning" style="max-width:700px;margin:0 auto;">
                        <i class="fas fa-info-circle"></i>
                        <?= t('streamelements_act_as_disabled') ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

if ($isLinked && (isset($apiToken) || isset($stored_jwt_token))):
ob_start();
?>
<script>
const seRevealApiTitle = <?php echo json_encode(t('streamelements_js_reveal_api_title')); ?>;
const seRevealApiText = <?php echo json_encode(t('streamelements_js_reveal_api_text')); ?>;
const seRevealJwtTitle = <?php echo json_encode(t('streamelements_js_reveal_jwt_title')); ?>;
const seRevealJwtText = <?php echo json_encode(t('streamelements_js_reveal_jwt_text')); ?>;
const seConfirmShow = <?php echo json_encode(t('streamelements_js_confirm_show')); ?>;
const seConfirmCancel = <?php echo json_encode(t('streamelements_js_confirm_cancel')); ?>;
const seHideApiTitle = <?php echo json_encode(t('streamelements_show_api_token')); ?>;
const seHideApiToken = <?php echo json_encode(t('streamelements_js_hide_api_token')); ?>;
const seHideJwtTitle = <?php echo json_encode(t('streamelements_show_jwt_token')); ?>;
const seHideJwtToken = <?php echo json_encode(t('streamelements_js_hide_jwt_token')); ?>;
<?php if (isset($apiToken)): ?>
const apiToken = "<?php echo addslashes($apiToken) ?>";
const apiTokenDotCount = <?php echo (int)strlen($apiToken); ?>;
let apiTokenVisible = false;
<?php endif; ?>

<?php if (isset($stored_jwt_token)): ?>
const jwtToken = "<?php echo addslashes($stored_jwt_token) ?>";
const jwtTokenDotCount = <?php echo (int)strlen($stored_jwt_token); ?>;
let jwtTokenVisible = false;
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($apiToken)): ?>
    const apiBtn = document.getElementById('showApiTokenBtn');
    const apiEye = document.getElementById('apiTokenEye');
    const apiDisplay = document.getElementById('apiTokenDisplay');
    if (apiBtn && apiEye && apiDisplay) {
        apiBtn.addEventListener('click', function() {
            if (!apiTokenVisible) {
                Swal.fire({
                    title: seRevealApiTitle,
                    text: seRevealApiText,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: seConfirmShow,
                    cancelButtonText: seConfirmCancel,
                    confirmButtonColor: '#f39c12',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        apiDisplay.value = apiToken;
                        apiEye.classList.remove('fa-eye');
                        apiEye.classList.add('fa-eye-slash');
                        apiBtn.title = seHideApiToken;
                        apiBtn.classList.remove('sp-btn-warning');
                        apiBtn.classList.add('sp-btn-danger');
                        apiTokenVisible = true;
                    }
                });
            } else {
                apiDisplay.value = '•'.repeat(apiTokenDotCount);
                apiEye.classList.remove('fa-eye-slash');
                apiEye.classList.add('fa-eye');
                apiBtn.title = seHideApiTitle;
                apiBtn.classList.remove('sp-btn-danger');
                apiBtn.classList.add('sp-btn-warning');
                apiTokenVisible = false;
            }
        });
    }
    <?php endif; ?>
    
    <?php if (isset($stored_jwt_token)): ?>
    const jwtBtn = document.getElementById('showJwtTokenBtn');
    const jwtEye = document.getElementById('jwtTokenEye');
    const jwtDisplay = document.getElementById('jwtTokenDisplay');
    if (jwtBtn && jwtEye && jwtDisplay) {
        jwtBtn.addEventListener('click', function() {
            if (!jwtTokenVisible) {
                Swal.fire({
                    title: seRevealJwtTitle,
                    text: seRevealJwtText,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: seConfirmShow,
                    cancelButtonText: seConfirmCancel,
                    confirmButtonColor: '#3273dc',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        jwtDisplay.value = jwtToken;
                        jwtEye.classList.remove('fa-eye');
                        jwtEye.classList.add('fa-eye-slash');
                        jwtBtn.title = seHideJwtToken;
                        jwtBtn.classList.remove('sp-btn-info');
                        jwtBtn.classList.add('sp-btn-danger');
                        jwtTokenVisible = true;
                    }
                });
            } else {
                jwtDisplay.value = '•'.repeat(jwtTokenDotCount);
                jwtEye.classList.remove('fa-eye-slash');
                jwtEye.classList.add('fa-eye');
                jwtBtn.title = seHideJwtTitle;
                jwtBtn.classList.remove('sp-btn-danger');
                jwtBtn.classList.add('sp-btn-info');
                jwtTokenVisible = false;
            }
        });
    }
    <?php endif; ?>
});
</script>
<?php
$scripts = ob_get_clean();
endif;

include 'layout.php';
?>
