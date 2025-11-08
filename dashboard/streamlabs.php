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

// Handle unlinking
if (isset($_GET['action']) && $_GET['action'] === 'unlink') {
    if ($twitchUserId) {
        $stmt = $conn->prepare("DELETE FROM streamlabs_tokens WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitchUserId);
        if ($stmt->execute()) {
            $linkingMessage = "StreamLabs account successfully unlinked.";
            $linkingMessageType = "is-success";
            $isLinked = false;
            unset($access_token);
            unset($socketToken);
        } else {
            $linkingMessage = "Failed to unlink StreamLabs account.";
            $linkingMessageType = "is-danger";
        }
        $stmt->close();
    }
}

// Handle user denial (error=true in query string)
if (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = "Authorization was denied. Please allow access to link your StreamLabs account.";
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code'])) {
    // Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamlabs_oauth_state']) || $_GET['state'] !== $_SESSION['streamlabs_oauth_state']) {
        $linkingMessage = "Invalid state parameter. Please try again.";
        $linkingMessageType = "is-danger";
    } else {
        unset($_SESSION['streamlabs_oauth_state']);
        $code = $_GET['code'];
        $token_url = "https://streamlabs.com/api/v2.0/token";
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
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
                        $linkingMessage = "Linked, but failed to save tokens. Error: " . $stmt->error;
                        $linkingMessageType = "is-warning";
                    }
                    $stmt->close();
                } else { 
                    $linkingMessage = "Linked, but failed to prepare statement. Error: " . $conn->error;
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
    $authURL = "https://streamlabs.com/api/v2.0/authorize"
        . "?response_type=code"
        . "&client_id=" . urlencode($client_id)
        . "&redirect_uri=" . urlencode($redirect_uri)
        . "&scope=" . urlencode($scope)
        . "&state=" . urlencode($state);
}

// Fetch recent donations if user is linked
$recentDonations = [];
$donations_code = null;
if ($isLinked && isset($access_token) && !empty($access_token)) {
    $donations_url = "https://streamlabs.com/api/v2.0/donations?limit=100&currency=USD";
    $ch = curl_init($donations_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: Bearer " . $access_token
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
    $socket_token_url = "https://streamlabs.com/api/v2.0/socket/token";
    $ch = curl_init($socket_token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: Bearer " . $access_token
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
    $user_url = "https://streamlabs.com/api/v2.0/user";
    $ch = curl_init($user_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: Bearer " . $access_token
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
<div class="notification is-warning is-dark" style="border-radius: 8px; margin-bottom: 1.5rem;">
    <div style="display: flex; gap: 0.75rem;">
        <span class="icon" style="flex-shrink: 0; margin-top: 0.25rem;">
            <i class="fas fa-tools"></i>
        </span>
        <div style="flex: 1;">
            <p class="has-text-weight-bold" style="margin-bottom: 0.5rem;">StreamLabs Integration Coming Soon</p>
            <p style="margin: 0 0 0.5rem 0; font-size: 0.95rem;">
                We're actively building this feature and appreciate your patience. While the authorization button may redirect you to StreamLabs, our API is currently in the beta testing phase and requires manual whitelisting for security purposes.
            </p>
            <p style="margin: 0 0 0.5rem 0; font-size: 0.95rem;">
                We have submitted an application to StreamLabs for full API access and are working towards becoming a developer-partner with them. We'll be rolling out access gradually as development progresses, so stay tuned!
            </p>
        </div>
    </div>
</div>
<div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.5);">
    <header class="card-header" style="border-bottom: 1px solid #23272f;">
        <div class="level is-mobile" style="width: 100%;">
            <div class="level-left">
                <div class="level-item">
                    <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                        <span class="icon mr-2"><i class="fas fa-gift"></i></span>
                        StreamLabs Integration
                    </span>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item" style="display: flex; gap: 0.75rem; align-items: center;">
                    <?php if ($isLinked): ?>
                        <span class="tag is-success is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon is-small"><i class="fas fa-check-circle"></i></span>
                            <span>Linked</span>
                        </span>
                        <button id="unlinkHeaderBtn" class="button is-danger is-medium" style="border-radius: 6px; padding: 0.375rem 0.75rem; height: auto;" title="Unlink StreamLabs Account">
                            <span class="icon is-small">
                                <i class="fas fa-unlink"></i>
                            </span>
                            <span>Unlink</span>
                        </button>
                    <?php else: ?>
                        <span class="tag is-danger is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon is-small"><i class="fas fa-exclamation-circle"></i></span>
                            <span>Not Linked</span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    <div class="card-content">
        <?php if ($linkingMessage): ?>
            <div class="notification <?php echo $linkingMessageType === 'is-success' ? 'is-success' : ($linkingMessageType === 'is-danger' ? 'is-danger' : 'is-warning'); ?> is-light mb-5" style="border-radius: 8px;">
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
            <div class="has-text-centered mb-6">
                <p class="subtitle is-6 has-text-grey-light">
                    Your StreamLabs account is successfully linked to your profile and ready to track donations.
                </p>
            </div>
            <!-- Tokens section -->
            <div class="columns is-centered mb-6">
                <div class="column is-full">
                    <div class="columns is-multiline">
                        <!-- Access Token -->
                        <?php if ($access_token): ?>
                            <div class="column is-half-desktop is-full-tablet">
                                <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; box-shadow: 0 2px 8px rgba(0,0,0,0.3); height: 100%;">
                                    <div class="card-content">
                                        <div class="level is-mobile mb-3">
                                            <div class="level-left">
                                                <div class="level-item">
                                                    <p class="subtitle is-6 has-text-white" style="margin: 0;">Access Token</p>
                                                </div>
                                            </div>
                                            <div class="level-right">
                                                <div class="level-item">
                                                    <button class="button is-info is-small" id="copyAccessTokenBtn" title="Copy Access Token" style="border-radius: 6px; transition: all 0.2s ease; margin-right: 0.5rem;">
                                                        <span class="icon is-small">
                                                            <i class="fas fa-copy" id="copyAccessTokenIcon"></i>
                                                        </span>
                                                    </button>
                                                    <button class="button is-warning is-small" id="showAccessTokenBtn" title="Show Access Token" style="border-radius: 6px; transition: all 0.2s ease;">
                                                        <span class="icon is-small">
                                                            <i class="fas fa-eye" id="accessTokenEye"></i>
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="text" id="accessTokenDisplay" class="input" value="<?php echo str_repeat('•', strlen($access_token)); ?>" readonly style="border-radius: 6px; background-color: #1a1a1a; border-color: #363636; color: #00d1b2; font-family: 'Courier New', monospace; font-size: 0.85rem; letter-spacing: 0.05em;">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <!-- Socket Token -->
                        <?php if ($socketToken): ?>
                            <div class="column is-half-desktop is-full-tablet">
                                <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; box-shadow: 0 2px 8px rgba(0,0,0,0.3); height: 100%;">
                                    <div class="card-content">
                                        <div class="level is-mobile mb-3">
                                            <div class="level-left">
                                                <div class="level-item">
                                                    <p class="subtitle is-6 has-text-white" style="margin: 0;">Socket Token (Real-time Events)</p>
                                                </div>
                                            </div>
                                            <div class="level-right">
                                                <div class="level-item">
                                                    <button class="button is-info is-small" id="copySocketTokenBtn" title="Copy Socket Token" style="border-radius: 6px; transition: all 0.2s ease; margin-right: 0.5rem;">
                                                        <span class="icon is-small">
                                                            <i class="fas fa-copy" id="copySocketTokenIcon"></i>
                                                        </span>
                                                    </button>
                                                    <button class="button is-info is-small" id="showSocketTokenBtn" title="Show Socket Token" style="border-radius: 6px; transition: all 0.2s ease;">
                                                        <span class="icon is-small">
                                                            <i class="fas fa-eye" id="socketTokenEye"></i>
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="text" id="socketTokenDisplay" class="input" value="<?php echo str_repeat('•', strlen($socketToken)); ?>" readonly style="border-radius: 6px; background-color: #1a1a1a; border-color: #363636; color: #3273dc; font-family: 'Courier New', monospace; font-size: 0.85rem; letter-spacing: 0.05em;">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Recent Donations section -->
            <?php if (!empty($recentDonations)): ?>
                <div class="columns is-centered mb-6">
                    <div class="column is-full">
                        <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; box-shadow: 0 2px 8px rgba(0,0,0,0.3); overflow: hidden;">
                            <div class="card-content">
                                <p class="subtitle is-6 has-text-white mb-4">Recent Donations <span class="has-text-grey" style="font-size: 0.85rem;">(Latest <?php echo min(20, count($recentDonations)); ?>)</span></p>
                                <div style="overflow-x: auto; border-radius: 8px; background-color: rgba(0,0,0,0.2);">
                                    <table class="table is-fullwidth" style="background-color: transparent; border-collapse: collapse; margin: 0;">
                                        <thead>
                                            <tr style="border-bottom: 2px solid #363636; background-color: rgba(0,0,0,0.1);">
                                                <th class="has-text-grey-light" style="padding: 1rem 0.75rem; text-align: left; font-weight: 600; font-size: 0.9rem;">Donor</th>
                                                <th class="has-text-grey-light" style="padding: 1rem 0.75rem; text-align: right; font-weight: 600; font-size: 0.9rem;">Amount</th>
                                                <th class="has-text-grey-light" style="padding: 1rem 0.75rem; text-align: left; font-weight: 600; font-size: 0.9rem;">Message</th>
                                                <th class="has-text-grey-light" style="padding: 1rem 0.75rem; text-align: left; font-weight: 600; font-size: 0.9rem;">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($recentDonations, 0, 20) as $donation): ?>
                                                <tr style="border-bottom: 1px solid rgba(35, 39, 47, 0.5); transition: background-color 0.2s ease;">
                                                    <td style="color: #e2e8f0; padding: 0.95rem 0.75rem; text-align: left;">
                                                        <strong><?php echo htmlspecialchars($donation['name'] ?? 'Anonymous'); ?></strong>
                                                    </td>
                                                    <td style="color: #00d1b2; padding: 0.95rem 0.75rem; text-align: right; font-weight: 600;">
                                                        <?php echo htmlspecialchars($donation['currency'] ?? '$'); ?><?php echo htmlspecialchars(number_format($donation['amount'] ?? 0, 2)); ?>
                                                    </td>
                                                    <td style="color: #b5bdc4; padding: 0.95rem 0.75rem; text-align: left; max-width: 250px; word-break: break-word;">
                                                        <?php echo htmlspecialchars($donation['message'] ?? 'No message'); ?>
                                                    </td>
                                                    <td style="color: #8b93a1; padding: 0.95rem 0.75rem; text-align: left; white-space: nowrap; font-size: 0.9rem;">
                                                        <?php 
                                                        if (isset($donation['created_at'])) {
                                                            try {
                                                                $timestamp = (int)$donation['created_at'];
                                                                $dt = new DateTime();
                                                                $dt->setTimestamp($timestamp);
                                                                echo htmlspecialchars($dt->format('M j, Y'));
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
                </div>
            <?php endif; ?>
            <!-- Empty donations message -->
            <?php if (empty($recentDonations) && $isLinked && isset($access_token)): ?>
                <div class="columns is-centered">
                    <div class="column is-two-thirds">
                        <div class="card has-background-grey-darker has-text-centered" style="border-radius: 12px; border: 1px dashed #363636; padding: 3rem 1.5rem;">
                            <div class="has-text-grey">
                                <p class="icon mb-3" style="font-size: 2rem;">
                                    <i class="fas fa-inbox"></i>
                                </p>
                                <p class="has-text-weight-semibold has-text-grey-light mb-2">No donations yet</p>
                                <p class="is-size-7">When you receive donations, they will appear here.</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Not linked display -->
            <div class="has-text-centered">
                <div class="mb-6">
                    <p class="is-size-5 mb-5">Connect your StreamLabs account to enable donation tracking and integration features.</p>
                    <div class="columns is-centered mb-5">
                        <div class="column is-half-desktop is-three-quarters-tablet">
                            <div class="card has-background-grey-darker" style="border-radius: 8px; border: 1px solid #363636;">
                                <div class="card-content has-text-centered">
                                    <p class="icon mb-3" style="font-size: 2.5rem; color: #8b93a1;">
                                        <i class="fas fa-link"></i>
                                    </p>
                                    <p class="subtitle is-6 has-text-grey-light">
                                        Link your StreamLabs account to automatically track and display donations on your dashboard.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($authURL): ?>
                    <a href="<?php echo $authURL; ?>" class="button is-info is-large" style="border-radius: 8px; font-weight: 600; box-shadow: 0 4px 12px rgba(50, 115, 220, 0.3); transition: all 0.3s ease;">
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
<?php
$content = ob_get_clean();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const unlinkHeaderBtn = document.getElementById('unlinkHeaderBtn');
    
    if (unlinkHeaderBtn) {
        unlinkHeaderBtn.addEventListener('click', function() {
            Swal.fire({
                title: 'Unlink StreamLabs Account?',
                text: 'Are you sure you want to unlink your StreamLabs account? This will remove all associated tokens and data.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Unlink',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#f14668',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'streamlabs.php?action=unlink';
                }
            });
        });
    }
});
</script>
<?php

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
    const copyAccessBtn = document.getElementById('copyAccessTokenBtn');
    const copyAccessIcon = document.getElementById('copyAccessTokenIcon');
    
    if (copyAccessBtn) {
        copyAccessBtn.addEventListener('click', function() {
            navigator.clipboard.writeText(accessToken).then(() => {
                copyAccessIcon.classList.remove('fa-copy');
                copyAccessIcon.classList.add('fa-check');
                copyAccessBtn.classList.add('is-success');
                copyAccessBtn.classList.remove('is-info');
                setTimeout(() => {
                    copyAccessIcon.classList.add('fa-copy');
                    copyAccessIcon.classList.remove('fa-check');
                    copyAccessBtn.classList.remove('is-success');
                    copyAccessBtn.classList.add('is-info');
                }, 2000);
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Copy Failed',
                    text: 'Could not copy token to clipboard'
                });
            });
        });
    }
    
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
    const copySocketBtn = document.getElementById('copySocketTokenBtn');
    const copySocketIcon = document.getElementById('copySocketTokenIcon');
    
    if (copySocketBtn) {
        copySocketBtn.addEventListener('click', function() {
            navigator.clipboard.writeText(socketToken).then(() => {
                copySocketIcon.classList.remove('fa-copy');
                copySocketIcon.classList.add('fa-check');
                copySocketBtn.classList.add('is-success');
                copySocketBtn.classList.remove('is-info');
                setTimeout(() => {
                    copySocketIcon.classList.add('fa-copy');
                    copySocketIcon.classList.remove('fa-check');
                    copySocketBtn.classList.remove('is-success');
                    copySocketBtn.classList.add('is-info');
                }, 2000);
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Copy Failed',
                    text: 'Could not copy token to clipboard'
                });
            });
        }
    }
    
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
