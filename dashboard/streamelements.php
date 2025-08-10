<?php
session_start();
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
        curl_close($ch);
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
                if ($days > 0) $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
                if ($hours > 0) $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
                if ($minutes > 0 && count($parts) < 2) $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
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
            curl_close($ch);
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
            curl_close($ch);
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
if (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = "Authorization was denied. Please allow access to link your StreamElements account.";
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code'])) {
    // Optional: Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamelements_oauth_state']) || $_GET['state'] !== $_SESSION['streamelements_oauth_state']) {
        $linkingMessage = "Invalid state parameter. Please try again.";
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
        curl_close($ch);
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
            curl_close($ch);
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
                curl_close($ch);
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
                            $linkingMessage = "StreamElements account successfully linked!";
                            $linkingMessageType = "is-success";
                            $isLinked = true;
                            // Redirect to refresh page and show linked status
                            header("Location: streamelements.php");
                            exit();
                        } else { 
                            $linkingMessage = "Linked, but failed to save tokens.";
                            $linkingMessageType = "is-warning";
                        }
                        $stmt->close();
                    } else { 
                        $linkingMessage = "Linked, but failed to prepare statement.";
                        $linkingMessageType = "is-warning";
                    }
                } else { 
                    $linkingMessage = "Linked, but missing Twitch user ID or refresh token.";
                    $linkingMessageType = "is-warning";
                }
            } else {
                $linkingMessage = "Token validation failed.";
                $linkingMessageType = "is-danger";
                if (isset($validate_data['message'])) { 
                    $linkingMessage .= " Error: " . htmlspecialchars($validate_data['message']);
                }
            }
        } else {
            $linkingMessage = "Failed to link StreamElements account.";
            $linkingMessageType = "is-danger";
            if (isset($token_data['error'])) { 
                $linkingMessage .= " Error: " . htmlspecialchars($token_data['error']);
            }
            if (isset($token_data['error_description'])) { 
                $linkingMessage .= " Description: " . htmlspecialchars($token_data['error_description']);
            }
        }
    }
}

// Generate auth URL for manual linking
$authURL = '';
if (!$isLinked) {
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
        curl_close($ch);
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
        $tips_url = "https://api.streamelements.com/kappa/v2/tips/{$channelId}";
        $ch = curl_init($tips_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json; charset=utf-8",
            "Authorization: Bearer {$stored_jwt_token}"
        ]);
        $tips_response = curl_exec($ch);
        $tips_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($tips_code === 200) {
            $tips_data = json_decode($tips_response, true);
            if (isset($tips_data['docs']) && is_array($tips_data['docs'])) {
                $recentTips = array_slice($tips_data['docs'], 0, 10); // Get last 10 tips
            }
        }
    }
}

ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <div style="display: flex; align-items: center; flex: 1; min-width: 0;">
                    <span class="card-header-title is-size-4 has-text-white" style="font-weight:700; flex-shrink: 0;">
                        <span class="icon mr-2"><i class="fas fa-bolt"></i></span>
                        StreamElements Integration
                    </span>
                    <div class="se-badges" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-left: 1rem;">
                        <?php if ($isLinked): ?>
                            <?php if ($inactive !== null): ?>
                                <span class="tag <?php echo $inactive ? 'is-danger' : 'is-success'; ?> is-light" title="Account Status">
                                    <i class="fa-regular fa-user" style="margin-right:0.3em;"></i>
                                    <?php echo $inactive ? 'Inactive' : 'Active'; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($createdAtFormatted): ?>
                                <span class="tag is-info is-light" title="Account Created">
                                    <i class="fa-regular fa-calendar" style="margin-right:0.3em;"></i>
                                    <?php echo $createdAtFormatted; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($isPartner !== null): ?>
                                <span class="tag <?php echo $isPartner ? 'is-primary' : 'is-light'; ?>" style="<?php echo $isPartner ? 'background-color:#8e44ad;border-color:#8e44ad;color:#fff;' : ''; ?>" title="StreamElements Partner">
                                    <i class="fa-solid fa-star" style="margin-right:0.3em;"></i>
                                    SE Partner
                                </span>
                            <?php endif; ?>
                            <?php if ($suspended !== null): ?>
                                <span class="tag <?php echo $suspended ? 'is-danger' : 'is-success'; ?> is-light" title="Suspended">
                                    <i class="fa-solid fa-ban" style="margin-right:0.3em;"></i>
                                    <?php echo $suspended ? 'Suspended' : 'Active'; ?>
                                </span>
                            <?php endif; ?>
                            <span class="tag is-success is-medium" style="border-radius: 6px; font-weight: 600;">
                                <span class="icon mr-1"><i class="fas fa-check-circle"></i></span>
                                Connected
                            </span>
                        <?php else: ?>
                            <span class="tag is-danger is-medium" style="border-radius: 6px; font-weight: 600;">
                                <span class="icon mr-1"><i class="fas fa-times-circle"></i></span>
                                Not Connected
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            <div class="card-content">
                <?php if ($linkingMessage): ?>
                    <div class="notification <?php echo $linkingMessageType === 'is-success' ? 'is-success' : ($linkingMessageType === 'is-danger' ? 'is-danger' : 'is-warning'); ?> is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
                        <span class="icon">
                            <?php if ($linkingMessageType === 'is-danger'): ?>
                                <i class="fas fa-exclamation-triangle"></i>
                            <?php elseif ($linkingMessageType === 'is-success'): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="fas fa-info-circle"></i>
                            <?php endif; ?>
                        </span>
                        <?php echo $linkingMessage; ?>
                    </div>
                <?php endif; ?>
                <?php if ($isLinked): ?>
                    <!-- Account status text -->
                    <div class="has-text-centered mb-5" style="padding: 1rem 2rem 2rem;">
                        <p class="subtitle is-6 has-text-grey-light mb-4" style="max-width: 600px; margin: 0 auto;">
                            Your StreamElements account is successfully linked to your profile.
                        </p>
                        <?php if ($expires_str): ?>
                            <div class="notification is-info is-light" style="border-radius: 8px; max-width: 600px; margin: 0 auto;">
                                <p><strong>Token Status:</strong> Your active token will auto-renew in about <strong><?php echo htmlspecialchars($expires_str) ?></strong>.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Tokens section - Side by side layout -->
                    <?php if ($apiToken || $stored_jwt_token): ?>
                        <div class="columns is-variable is-4" style="margin: 0 auto; max-width: 2000px;">
                            <!-- API Token column -->
                            <?php if ($apiToken): ?>
                                <div class="column is-6">
                                    <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                        <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                                            <p class="card-header-title has-text-white" style="font-weight: 600;">
                                                <span class="icon mr-2 has-text-warning"><i class="fas fa-key"></i></span>
                                                API Token
                                            </p>
                                        </header>
                                        <div class="card-content" style="padding: 1.5rem;">
                                            <div class="field">
                                                <label class="label has-text-white mb-3" style="font-weight: 500;">StreamElements API Token</label>
                                                <div class="field has-addons">
                                                    <div class="control is-expanded">
                                                        <input class="input" type="text" id="apiTokenDisplay" value="<?php echo str_repeat('•', strlen($apiToken)); ?>" readonly style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px 0 0 6px; font-family: monospace; font-size: 1rem; letter-spacing: 1.5px; min-width: 300px;">
                                                    </div>
                                                    <div class="control">
                                                        <button id="showApiTokenBtn" class="button is-warning" style="border-radius: 0 6px 6px 0; font-weight: 600;" title="Show API Token">
                                                            <span class="icon">
                                                                <i id="apiTokenEye" class="fa-solid fa-eye"></i>
                                                            </span>
                                                        </button>
                                                    </div>
                                                </div>
                                                <p class="help has-text-grey-light mt-3">
                                                    <i class="fas fa-exclamation-triangle has-text-warning mr-1"></i>
                                                    Keep this token secure and never share it publicly.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- JWT Token column -->
                            <?php if ($stored_jwt_token): ?>
                                <div class="column <?php echo $apiToken ? 'is-6' : 'is-6 is-offset-3'; ?>">
                                    <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; height: 100%;">
                                        <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                                            <p class="card-header-title has-text-white" style="font-weight: 600;">
                                                <span class="icon mr-2 has-text-info"><i class="fas fa-shield-alt"></i></span>
                                                JWT Token
                                            </p>
                                        </header>
                                        <div class="card-content" style="padding: 1.5rem;">
                                            <div class="field">
                                                <label class="label has-text-white mb-3" style="font-weight: 500;">StreamElements JWT Token</label>
                                                <div class="field has-addons">
                                                    <div class="control is-expanded">
                                                        <input class="input" type="text" id="jwtTokenDisplay" value="<?php echo str_repeat('•', strlen($stored_jwt_token)); ?>" readonly style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px 0 0 6px; font-family: monospace; font-size: 1rem; letter-spacing: 1.5px; min-width: 300px;">
                                                    </div>
                                                    <div class="control">
                                                        <button id="showJwtTokenBtn" class="button is-info" style="border-radius: 0 6px 6px 0; font-weight: 600;" title="Show JWT Token">
                                                            <span class="icon">
                                                                <i id="jwtTokenEye" class="fa-solid fa-eye"></i>
                                                            </span>
                                                        </button>
                                                    </div>
                                                </div>
                                                <p class="help has-text-grey-light mt-3">
                                                    <i class="fas fa-exclamation-triangle has-text-warning mr-1"></i>
                                                    This JWT token is used for WebSocket connections and real-time features.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <!-- Recent Tips section -->
                    <?php if (!empty($recentTips)): ?>
                        <div style="margin: 2rem auto 0; max-width: 2000px;">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                                    <p class="card-header-title has-text-white" style="font-weight: 600;">
                                        <span class="icon mr-2 has-text-success"><i class="fas fa-dollar-sign"></i></span>
                                        Recent Tips (<?php echo count($recentTips); ?>)
                                    </p>
                                </header>
                            <div class="card-content" style="padding: 1.5rem;">
                                <div class="table-container">
                                    <table class="table is-fullwidth is-hoverable has-background-grey-darker" style="background-color: #363636;">
                                        <thead>
                                            <tr style="background-color: #2c2c2c;">
                                                <th class="has-text-white" style="border-color: #5a5a5a;">Tipper</th>
                                                <th class="has-text-white" style="border-color: #5a5a5a;">Amount</th>
                                                <th class="has-text-white" style="border-color: #5a5a5a;">Message</th>
                                                <th class="has-text-white" style="border-color: #5a5a5a;">Provider</th>
                                                <th class="has-text-white" style="border-color: #5a5a5a;">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentTips as $tip): ?>
                                                <tr style="background-color: #363636;">
                                                    <td class="has-text-white" style="border-color: #5a5a5a;">
                                                        <?php echo htmlspecialchars($tip['donation']['user']['username'] ?? 'Anonymous'); ?>
                                                    </td>
                                                    <td class="has-text-white" style="border-color: #5a5a5a;">
                                                        <span class="tag is-success is-light">
                                                            <?php 
                                                            $amount = $tip['donation']['amount'] ?? 0;
                                                            $currency = $tip['donation']['currency'] ?? 'USD';
                                                            echo htmlspecialchars($currency . ' ' . number_format($amount / 100, 2)); 
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td class="has-text-white" style="border-color: #5a5a5a; max-width: 200px; word-wrap: break-word;">
                                                        <?php 
                                                        $message = $tip['donation']['message'] ?? '';
                                                        echo htmlspecialchars(strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message); 
                                                        ?>
                                                    </td>
                                                    <td class="has-text-white" style="border-color: #5a5a5a;">
                                                        <span class="tag is-info is-light">
                                                            <?php echo htmlspecialchars(ucfirst($tip['provider'] ?? 'Unknown')); ?>
                                                        </span>
                                                    </td>
                                                    <td class="has-text-white" style="border-color: #5a5a5a;">
                                                        <?php 
                                                        if (isset($tip['createdAt'])) {
                                                            try {
                                                                $dt = new DateTime($tip['createdAt']);
                                                                echo $dt->format('M j, Y g:i A');
                                                            } catch (Exception $e) {
                                                                echo htmlspecialchars($tip['createdAt']);
                                                            }
                                                        } else {
                                                            echo 'Unknown';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="help has-text-grey-light mt-3">
                                    <i class="fas fa-info-circle has-text-info mr-1"></i>
                                    Showing the last 10 tips received through StreamElements.
                                </p>
                            </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- Debug section for tips -->
                    <?php if ($isLinked && isset($access_token)): ?>
                        <?php if (empty($recentTips)): ?>
                            <div style="margin: 2rem auto 0; max-width: 1600px;">
                                <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                    <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                                        <p class="card-header-title has-text-white" style="font-weight: 600;">
                                            <span class="icon mr-2 has-text-info"><i class="fas fa-info-circle"></i></span>
                                            Tips Status
                                        </p>
                                    </header>
                                    <div class="card-content" style="padding: 1.5rem;">
                                        <div class="notification is-info is-light">
                                            <p><strong>No recent tips found.</strong></p>
                                            <?php if (isset($_SESSION['streamelements_channel_id'])): ?>
                                                <p><strong>Channel ID:</strong> <?php echo htmlspecialchars($_SESSION['streamelements_channel_id']); ?></p>
                                                <p>Channel ID is available and we're checking for tips.</p>
                                                <?php if (isset($tips_code)): ?>
                                                    <p><strong>Tips API Response Code:</strong> <?php echo $tips_code; ?></p>
                                                    <?php if ($tips_code !== 200): ?>
                                                        <p class="has-text-danger">Tips API call failed (HTTP <?php echo $tips_code; ?>)</p>
                                                    <?php else: ?>
                                                        <p>Tips API call successful, but no tips found.</p>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="has-text-warning">Channel ID not available - this might be why tips aren't loading.</p>
                                                <p>The current user API call may have failed or returned no channels.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Not linked display -->
                    <div class="has-text-centered">
                        <div class="content has-text-white mb-5" style="margin: 0 auto;">
                            <p>Connect your StreamElements account to enable tip tracking and integration features.</p>
                            <div class="box has-background-grey-darker has-text-centered" style="max-width: 600px; margin: 0 auto; border-radius: 8px; border: 1px solid #363636;">
                                <h4 class="title is-6 has-text-white mb-3">
                                    <span class="icon mr-2 has-text-info"><i class="fas fa-bolt"></i></span>
                                    Available Features:
                                </h4>
                                <ul class="has-text-left has-text-white">
                                    <li class="mb-2">Access to your recent tips and donation data</li>
                                    <li class="mb-2">Integration with bot commands for tip information</li>
                                    <li>Enhanced stream engagement through tip-based features</li>
                                </ul>
                            </div>
                        </div>
                        <?php if ($authURL): ?>
                            <a href="<?php echo $authURL; ?>" class="button is-info is-large" style="border-radius: 8px; font-weight: 600;">
                                <span class="icon"><i class="fas fa-bolt"></i></span>
                                <span>Link StreamElements Account</span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

if ($isLinked && (isset($apiToken) || isset($stored_jwt_token))):
ob_start();
?>
<script>
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
                    title: 'Reveal API Token?',
                    text: 'Are you sure you want to show your API Token? Keep it secret!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Show',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#f39c12',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        apiDisplay.value = apiToken;
                        apiEye.classList.remove('fa-eye');
                        apiEye.classList.add('fa-eye-slash');
                        apiBtn.title = "Hide API Token";
                        apiBtn.classList.remove('is-warning');
                        apiBtn.classList.add('is-danger');
                        apiTokenVisible = true;
                    }
                });
            } else {
                apiDisplay.value = '•'.repeat(apiTokenDotCount);
                apiEye.classList.remove('fa-eye-slash');
                apiEye.classList.add('fa-eye');
                apiBtn.title = "Show API Token";
                apiBtn.classList.remove('is-danger');
                apiBtn.classList.add('is-warning');
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
                    title: 'Reveal JWT Token?',
                    text: 'Are you sure you want to show your JWT Token? Keep it secret!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Show',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#3273dc',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        jwtDisplay.value = jwtToken;
                        jwtEye.classList.remove('fa-eye');
                        jwtEye.classList.add('fa-eye-slash');
                        jwtBtn.title = "Hide JWT Token";
                        jwtBtn.classList.remove('is-info');
                        jwtBtn.classList.add('is-danger');
                        jwtTokenVisible = true;
                    }
                });
            } else {
                jwtDisplay.value = '•'.repeat(jwtTokenDotCount);
                jwtEye.classList.remove('fa-eye-slash');
                jwtEye.classList.add('fa-eye');
                jwtBtn.title = "Show JWT Token";
                jwtBtn.classList.remove('is-danger');
                jwtBtn.classList.add('is-info');
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