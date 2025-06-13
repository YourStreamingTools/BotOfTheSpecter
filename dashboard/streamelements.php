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
    $stmt = $conn->prepare("SELECT access_token FROM streamelements_tokens WHERE twitch_user_id = ?");
    $stmt->bind_param("s", $twitchUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $access_token = $row['access_token'];
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
                if (isset($_SESSION['twitchUserId']) && $refresh_token) {
                    $twitchUserId = $_SESSION['twitchUserId'];
                    $query = "INSERT INTO streamelements_tokens (twitch_user_id, access_token, refresh_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token)";
                    if ($stmt = $conn->prepare($query)) {
                        $stmt->bind_param('sss', $twitchUserId, $access_token, $refresh_token);
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
                    <!-- API Token section -->
                    <?php if ($apiToken): ?>
                        <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; min-width: 650px; margin: 0 auto;">
                            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                                <p class="card-header-title has-text-white" style="font-weight: 600;">
                                    <span class="icon mr-2 has-text-warning"><i class="fas fa-key"></i></span>
                                    API Token
                                </p>
                            </header>
                            <div class="card-content" style="padding: 2rem 4rem;">
                                <div class="field">
                                    <label class="label has-text-white mb-3" style="font-weight: 500;">StreamElements API Token</label>
                                    <div class="field has-addons">
                                        <div class="control is-expanded">
                                            <input class="input" type="text" id="apiTokenDisplay" value="<?php echo str_repeat('•', strlen($apiToken)); ?>" readonly style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px 0 0 6px; font-family: monospace; font-size: 0.9rem; letter-spacing: 1px;">
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

if ($isLinked && isset($apiToken)):
ob_start();
?>
<script>
const apiToken = "<?php echo addslashes($apiToken) ?>";
const apiTokenDotCount = <?php echo (int)strlen($apiToken); ?>;
let apiTokenVisible = false;
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>
<?php
$scripts = ob_get_clean();
endif;

include 'layout.php';
?>