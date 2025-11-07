<?php
session_start();
include "/var/www/config/streamlabs.php";
include "/var/www/config/db_connect.php";
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$pageTitle = t('navbar_streamlabs') ?? 'StreamLabs Integration';
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
    // Check if StreamLabs is already linked for this user and fetch token
    $stmt = $conn->prepare("SELECT access_token, refresh_token, expires_in, created_at FROM streamlabs_tokens WHERE twitch_user_id = ?");
    $stmt->bind_param("s", $twitchUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $access_token = $row['access_token'];
        $refresh_token = $row['refresh_token'];
        $expires_in = (int)$row['expires_in'] ?? 3600;
        $token_created_at = (int)$row['created_at'];
        $isLinked = true;
    }
    $stmt->close();
}

// Set up StreamLabs OAuth2 parameters
$client_id = $streamlabs_client_id;
$client_secret = $streamlabs_client_secret;
$redirect_uri = 'https://dashboard.botofthespecter.com/streamlabs.php';
$scope = 'donations.read socket.token';

// Handle user denial (error=true in query string)
if (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = "Authorization was denied. Please allow access to link your StreamLabs account.";
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code'])) {
    // Optional: Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamlabs_oauth_state']) || $_GET['state'] !== $_SESSION['streamlabs_oauth_state']) {
        $linkingMessage = "Invalid state parameter. Please try again.";
        $linkingMessageType = "is-danger";
    } else {
        unset($_SESSION['streamlabs_oauth_state']);
        $code = $_GET['code'];
        $token_url = "https://streamlabs.com/api/v1.0/token";
        $post_fields = http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'code' => $code
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
            $new_access_token = $token_data['access_token'];
            $new_refresh_token = $token_data['refresh_token'] ?? null;
            $new_expires_in = (int)($token_data['expires_in'] ?? 3600);
            $created_at_timestamp = time();
            if (isset($_SESSION['twitchUserId']) && $new_refresh_token) {
                $twitchUserId = $_SESSION['twitchUserId'];
                $query = "INSERT INTO streamlabs_tokens (twitch_user_id, access_token, refresh_token, expires_in, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_in = VALUES(expires_in), created_at = VALUES(created_at)";
                if ($stmt = $conn->prepare($query)) {
                    $stmt->bind_param('sssii', $twitchUserId, $new_access_token, $new_refresh_token, $new_expires_in, $created_at_timestamp);
                    if ($stmt->execute()) {
                        $linkingMessage = "StreamLabs account successfully linked!";
                        $linkingMessageType = "is-success";
                        $isLinked = true;
                        $access_token = $new_access_token;
                        $refresh_token = $new_refresh_token;
                        $expires_in = $new_expires_in;
                        $token_created_at = $created_at_timestamp;
                        // Redirect to refresh page and show linked status
                        header("Location: streamlabs.php");
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
            $linkingMessage = "Failed to link StreamLabs account.";
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
    $_SESSION['streamlabs_oauth_state'] = $state;
    $authURL = "https://streamlabs.com/api/v1.0/authorize"
        . "?response_type=code"
        . "&client_id={$client_id}"
        . "&redirect_uri=" . urlencode($redirect_uri)
        . "&scope=" . urlencode($scope)
        . "&state={$state}";
}

// Function to refresh StreamLabs access token
function refreshStreamLabsToken($refresh_token, $client_id, $client_secret, $redirect_uri) {
    $token_url = "https://streamlabs.com/api/v1.0/token";
    $post_fields = http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'refresh_token' => $refresh_token
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
    if ($httpcode === 200) {
        $token_data = json_decode($response, true);
        if (isset($token_data['access_token'])) {
            return $token_data;
        }
    }
    return null;
}

// Function to check and refresh token if expired before making API calls
function ensureValidToken(&$access_token, &$refresh_token, &$expires_in, &$token_created_at, $client_id, $client_secret, $redirect_uri, $conn, $twitchUserId) {
    $now = time();
    // Check if token is expired: created_at + expires_in < now()
    if (($token_created_at + $expires_in) < $now) {
        // Token is expired, refresh it
        $refreshed_token = refreshStreamLabsToken($refresh_token, $client_id, $client_secret, $redirect_uri);
        if ($refreshed_token && isset($refreshed_token['access_token'])) {
            // Update variables with new token data
            $access_token = $refreshed_token['access_token'];
            $refresh_token = $refreshed_token['refresh_token'] ?? $refresh_token;
            $expires_in = (int)($refreshed_token['expires_in'] ?? 3600);
            $token_created_at = time();
            
            // Update database with new tokens
            $update_stmt = $conn->prepare("UPDATE streamlabs_tokens SET access_token = ?, refresh_token = ?, expires_in = ?, created_at = ? WHERE twitch_user_id = ?");
            if ($update_stmt) {
                $update_stmt->bind_param('sssii', $access_token, $refresh_token, $expires_in, $token_created_at, $twitchUserId);
                $update_stmt->execute();
                $update_stmt->close();
            }
            return true;
        }
        return false;
    }
    return true; // Token is still valid
}

// Fetch recent donations if user is linked
$recentDonations = [];
$donations_code = null;
if ($isLinked && isset($access_token) && !empty($access_token)) {
    // Check and refresh token if expired before making API call
    ensureValidToken($access_token, $refresh_token, $expires_in, $token_created_at, $client_id, $client_secret, $redirect_uri, $conn, $twitchUserId);
    $donations_url = "https://streamlabs.com/api/v1.0/donations?access_token=" . urlencode($access_token) . "&limit=100&currency=USD";
    $ch = curl_init($donations_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json"
    ]);
    $donations_response = curl_exec($ch);
    $donations_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($donations_code === 200) {
        $donations_data = json_decode($donations_response, true);
        if (isset($donations_data['data']) && is_array($donations_data['data'])) {
            $recentDonations = $donations_data['data'];
        }
    }
}

// Fetch socket token for real-time events
$socketToken = null;
$socketTokenCode = null;
if ($isLinked && isset($access_token) && !empty($access_token)) {
    // Check and refresh token if expired before making API call
    ensureValidToken($access_token, $refresh_token, $expires_in, $token_created_at, $client_id, $client_secret, $redirect_uri, $conn, $twitchUserId);
    $socket_token_url = "https://streamlabs.com/api/v1.0/socket/token?access_token=" . urlencode($access_token);
    $ch = curl_init($socket_token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json"
    ]);
    $socket_token_response = curl_exec($ch);
    $socketTokenCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($socketTokenCode === 200) {
        $socket_token_data = json_decode($socket_token_response, true);
        if (isset($socket_token_data['socket_token'])) {
            $socketToken = $socket_token_data['socket_token'];
        }
    }
}

// Fetch user information
$userData = null;
$userDataCode = null;
if ($isLinked && isset($access_token) && !empty($access_token)) {
    // Check and refresh token if expired before making API call
    ensureValidToken($access_token, $refresh_token, $expires_in, $token_created_at, $client_id, $client_secret, $redirect_uri, $conn, $twitchUserId);
    $user_url = "https://streamlabs.com/api/v1.0/user?access_token=" . urlencode($access_token);
    $ch = curl_init($user_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json"
    ]);
    $user_response = curl_exec($ch);
    $userDataCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($userDataCode === 200) {
        $user_data = json_decode($user_response, true);
        if (is_array($user_data)) {
            $userData = $user_data;
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
                        <span class="icon mr-2"><i class="fas fa-gift"></i></span>
                        StreamLabs Integration
                    </span>
                    <div class="se-badges" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-left: 1rem;">
                        <?php if ($isLinked): ?>
                            <span class="tag is-success is-medium" style="border-radius: 6px; font-weight: 600;">
                                <span class="icon is-small"><i class="fas fa-check-circle"></i></span>
                                <span>Linked</span>
                            </span>
                        <?php else: ?>
                            <span class="tag is-danger is-medium" style="border-radius: 6px; font-weight: 600;">
                                <span class="icon is-small"><i class="fas fa-exclamation-circle"></i></span>
                                <span>Not Linked</span>
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
                            Your StreamLabs account is successfully linked to your profile.
                        </p>
                    </div>
                    <!-- User Information section -->
                    <?php if ($userData): ?>
                        <div style="margin: 0 auto 2rem; max-width: 600px;">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <p class="subtitle is-6 has-text-white mb-3">StreamLabs Account Information</p>
                                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                        <?php if (isset($userData['twitch'])): ?>
                                            <div style="display: flex; align-items: center; gap: 1rem;">
                                                <span style="color: #8b93a1; min-width: 120px;">Twitch ID:</span>
                                                <span style="color: #e2e8f0; font-weight: 500;"><?php echo htmlspecialchars($userData['twitch']['id'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 1rem;">
                                                <span style="color: #8b93a1; min-width: 120px;">Display Name:</span>
                                                <span style="color: #e2e8f0; font-weight: 500;"><?php echo htmlspecialchars($userData['twitch']['display_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 1rem;">
                                                <span style="color: #8b93a1; min-width: 120px;">Username:</span>
                                                <span style="color: #e2e8f0; font-weight: 500;"><?php echo htmlspecialchars($userData['twitch']['name'] ?? 'N/A'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- Access Token section -->
                    <?php if ($access_token): ?>
                        <div style="margin: 0 auto; max-width: 600px;">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
                                        <p class="subtitle is-6 has-text-white" style="margin: 0;">Access Token</p>
                                        <button class="button is-warning is-small" id="showAccessTokenBtn" title="Show Access Token" style="border-radius: 6px;">
                                            <span class="icon is-small">
                                                <i class="fas fa-eye" id="accessTokenEye"></i>
                                            </span>
                                        </button>
                                    </div>
                                    <input type="text" id="accessTokenDisplay" class="input" value="<?php echo str_repeat('•', strlen($access_token)); ?>" readonly style="border-radius: 6px; background-color: #2a2a2a; border-color: #363636; color: #00d1b2; font-family: monospace; font-size: 0.85rem;">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- Socket Token section -->
                    <?php if ($socketToken): ?>
                        <div style="margin: 1rem auto 0; max-width: 600px;">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
                                        <p class="subtitle is-6 has-text-white" style="margin: 0;">Socket Token (Real-time Events)</p>
                                        <button class="button is-info is-small" id="showSocketTokenBtn" title="Show Socket Token" style="border-radius: 6px;">
                                            <span class="icon is-small">
                                                <i class="fas fa-eye" id="socketTokenEye"></i>
                                            </span>
                                        </button>
                                    </div>
                                    <input type="text" id="socketTokenDisplay" class="input" value="<?php echo str_repeat('•', strlen($socketToken)); ?>" readonly style="border-radius: 6px; background-color: #2a2a2a; border-color: #363636; color: #3273dc; font-family: monospace; font-size: 0.85rem;">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- Recent Donations section -->
                    <?php if (!empty($recentDonations)): ?>
                        <div style="margin: 2rem auto 0; width: 100%;">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                <div class="card-content" style="padding: 1.5rem;">
                                    <p class="subtitle is-6 has-text-white mb-4">Recent Donations (Latest 100)</p>
                                    <div style="overflow-x: auto;">
                                        <table class="table is-fullwidth" style="background-color: transparent; border-collapse: collapse;">
                                            <thead>
                                                <tr style="border-bottom: 2px solid #363636;">
                                                    <th style="color: #b5bdc4; padding: 0.75rem; text-align: left;">Donor</th>
                                                    <th style="color: #b5bdc4; padding: 0.75rem; text-align: right;">Amount</th>
                                                    <th style="color: #b5bdc4; padding: 0.75rem; text-align: left;">Message</th>
                                                    <th style="color: #b5bdc4; padding: 0.75rem; text-align: left;">Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($recentDonations, 0, 20) as $donation): ?>
                                                    <tr style="border-bottom: 1px solid #2a2a2a; transition: background-color 0.2s;">
                                                        <td style="color: #e2e8f0; padding: 0.75rem; text-align: left;">
                                                            <?php echo htmlspecialchars($donation['name'] ?? 'Anonymous'); ?>
                                                        </td>
                                                        <td style="color: #00d1b2; padding: 0.75rem; text-align: right; font-weight: 600;">
                                                            <?php echo htmlspecialchars($donation['currency'] ?? '$'); ?><?php echo htmlspecialchars(number_format($donation['amount'] ?? 0, 2)); ?>
                                                        </td>
                                                        <td style="color: #b5bdc4; padding: 0.75rem; text-align: left; max-width: 300px; word-break: break-word;">
                                                            <?php echo htmlspecialchars($donation['message'] ?? 'No message'); ?>
                                                        </td>
                                                        <td style="color: #8b93a1; padding: 0.75rem; text-align: left; white-space: nowrap;">
                                                            <?php 
                                                            if (isset($donation['created_at'])) {
                                                                try {
                                                                    // created_at is a Unix timestamp
                                                                    $timestamp = (int)$donation['created_at'];
                                                                    $dt = new DateTime();
                                                                    $dt->setTimestamp($timestamp);
                                                                    echo htmlspecialchars($dt->format('M j, Y H:i'));
                                                                } catch (Exception $e) {
                                                                    echo htmlspecialchars($donation['created_at']);
                                                                }
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
                        </div>
                    <?php endif; ?>
                    <!-- Debug section for donations -->
                    <?php if ($isLinked && isset($access_token)): ?>
                        <?php if (empty($recentDonations)): ?>
                            <div style="margin: 2rem auto 0; max-width: 1600px;">
                                <div class="notification is-warning is-light" style="border-radius: 8px;">
                                    <p class="has-text-weight-semibold">No donations found</p>
                                    <p style="margin-top: 0.5rem;">There are no recent donations to display, or the API request encountered an issue.</p>
                                    <?php if ($donations_code !== 200 && $donations_code !== null): ?>
                                        <p style="margin-top: 0.5rem; font-size: 0.85rem; color: #7a6521;">API Response Code: <?php echo $donations_code; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Not linked display -->
                    <div class="has-text-centered">
                        <div class="content has-text-white mb-5" style="margin: 0 auto;">
                            <p>Connect your StreamLabs account to enable donation tracking and integration features.</p>
                            <div class="box has-background-grey-darker has-text-centered" style="max-width: 600px; margin: 0 auto; border-radius: 8px; border: 1px solid #363636;">
                                <p class="is-size-6 has-text-grey-light">
                                    StreamLabs allows you to track donations and integrate them with your bot for real-time updates.
                                </p>
                            </div>
                        </div>
                        <?php if ($authURL): ?>
                            <a href="<?php echo $authURL; ?>" class="button is-info is-large" style="border-radius: 8px; font-weight: 600;">
                                <span class="icon mr-2">
                                    <i class="fas fa-link"></i>
                                </span>
                                <span>Link StreamLabs Account</span>
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

if ($isLinked && isset($access_token)):
ob_start();
?>
<script>
const accessToken = "<?php echo addslashes($access_token) ?>";
const accessTokenDotCount = <?php echo (int)strlen($access_token); ?>;
let accessTokenVisible = false;

<?php if ($socketToken): ?>
const socketToken = "<?php echo addslashes($socketToken) ?>";
const socketTokenDotCount = <?php echo (int)strlen($socketToken); ?>;
let socketTokenVisible = false;
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const accessBtn = document.getElementById('showAccessTokenBtn');
    const accessEye = document.getElementById('accessTokenEye');
    const accessDisplay = document.getElementById('accessTokenDisplay');
    if (accessBtn && accessEye && accessDisplay) {
        accessBtn.addEventListener('click', function() {
            if (!accessTokenVisible) {
                Swal.fire({
                    title: 'Reveal Access Token?',
                    text: 'Are you sure you want to show your Access Token? Keep it secret!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Show',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#f39c12',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        accessDisplay.value = accessToken;
                        accessEye.classList.remove('fa-eye');
                        accessEye.classList.add('fa-eye-slash');
                        accessBtn.title = "Hide Access Token";
                        accessBtn.classList.remove('is-warning');
                        accessBtn.classList.add('is-danger');
                        accessTokenVisible = true;
                    }
                });
            } else {
                accessDisplay.value = '•'.repeat(accessTokenDotCount);
                accessEye.classList.remove('fa-eye-slash');
                accessEye.classList.add('fa-eye');
                accessBtn.title = "Show Access Token";
                accessBtn.classList.remove('is-danger');
                accessBtn.classList.add('is-warning');
                accessTokenVisible = false;
            }
        });
    }

    <?php if ($socketToken): ?>
    const socketBtn = document.getElementById('showSocketTokenBtn');
    const socketEye = document.getElementById('socketTokenEye');
    const socketDisplay = document.getElementById('socketTokenDisplay');
    if (socketBtn && socketEye && socketDisplay) {
        socketBtn.addEventListener('click', function() {
            if (!socketTokenVisible) {
                Swal.fire({
                    title: 'Reveal Socket Token?',
                    text: 'Are you sure you want to show your Socket Token? Keep it secret!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Show',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#3273dc',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        socketDisplay.value = socketToken;
                        socketEye.classList.remove('fa-eye');
                        socketEye.classList.add('fa-eye-slash');
                        socketBtn.title = "Hide Socket Token";
                        socketBtn.classList.remove('is-info');
                        socketBtn.classList.add('is-danger');
                        socketTokenVisible = true;
                    }
                });
            } else {
                socketDisplay.value = '•'.repeat(socketTokenDotCount);
                socketEye.classList.remove('fa-eye-slash');
                socketEye.classList.add('fa-eye');
                socketBtn.title = "Show Socket Token";
                socketBtn.classList.remove('is-danger');
                socketBtn.classList.add('is-info');
                socketTokenVisible = false;
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
