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
$isActAsUser = isset($isActAs) && $isActAs === true;
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
if ($isActAsUser && isset($_GET['code'])) {
    $linkingMessage = "Linking StreamLabs is disabled while using Act As mode.";
    $linkingMessageType = "is-warning";
} elseif (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = "Authorization was denied. Please allow access to link your StreamLabs account.";
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code']) && !$isActAsUser) {
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
if (!$isLinked && !$isActAsUser) {
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
            // Persist socket token to database for use by the bot
            if (!empty($socketToken) && isset($twitchUserId)) {
                // Attempt to detect if the socket_token column exists
                $colCheckSql = "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'streamlabs_tokens' AND COLUMN_NAME = 'socket_token'";
                if ($colRes = $conn->query($colCheckSql)) {
                    $colRow = $colRes->fetch_assoc();
                    $colExists = (isset($colRow['cnt']) && (int)$colRow['cnt'] > 0);
                    $colRes->free();
                } else {
                    $colExists = false;
                }
                // If column exists, update the row for this twitch user
                if ($colExists) {
                    if ($stmt = $conn->prepare("UPDATE streamlabs_tokens SET socket_token = ? WHERE twitch_user_id = ?")) {
                        $stmt->bind_param("ss", $socketToken, $twitchUserId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
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
<div class="sp-alert sp-alert-success" style="margin-bottom: 1.5rem;">
    <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
        <i class="fas fa-bell" style="margin-top: 0.15rem; flex-shrink: 0;"></i>
        <div>
            <strong>StreamLabs API Enabled</strong>
            <p style="margin: 0.35rem 0 0; font-size: 0.9rem;">
                We have been approved and the API is unlocked - you may connect away and enjoy real-time notices about donations. If you have any issues while this is still under review and testing, please log a support ticket on our Discord server.
            </p>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fas fa-gift"></i>
            StreamLabs Integration
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <?php if ($isLinked): ?>
                <span class="sp-badge sp-badge-green">
                    <i class="fas fa-check-circle"></i>
                    Linked
                </span>
                <button id="unlinkHeaderBtn" class="sp-btn sp-btn-danger sp-btn-sm" title="Unlink StreamLabs Account">
                    <i class="fas fa-unlink"></i>
                    Unlink
                </button>
            <?php else: ?>
                <span class="sp-badge sp-badge-red">
                    <i class="fas fa-exclamation-circle"></i>
                    Not Linked
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="sp-card-body">
        <?php if ($linkingMessage): ?>
            <div class="sp-alert <?php echo $linkingMessageType === 'is-success' ? 'sp-alert-success' : ($linkingMessageType === 'is-danger' ? 'sp-alert-danger' : 'sp-alert-warning'); ?>" style="margin-bottom: 1.5rem;">
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
            <p style="text-align: center; color: var(--text-secondary); margin-bottom: 1.5rem; font-size: 0.9rem;">
                Your StreamLabs account is successfully linked to your profile and ready to track donations.
            </p>
            <!-- Tokens section -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                <!-- Access Token -->
                <?php if ($access_token): ?>
                    <div class="sp-card" style="margin-bottom: 0;">
                        <div class="sp-card-header">
                            <span style="font-size: 0.88rem; font-weight: 600; color: var(--text-primary);">Access Token</span>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="sp-btn sp-btn-info sp-btn-sm" id="copyAccessTokenBtn" title="Copy Access Token">
                                    <i class="fas fa-copy" id="copyAccessTokenIcon"></i>
                                </button>
                                <button class="sp-btn sp-btn-warning sp-btn-sm" id="showAccessTokenBtn" title="Show Access Token">
                                    <i class="fas fa-eye" id="accessTokenEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="sp-card-body">
                            <input type="text" id="accessTokenDisplay" class="sp-input" value="<?php echo str_repeat('•', strlen($access_token)); ?>" readonly style="font-family: 'Courier New', monospace; font-size: 0.85rem; letter-spacing: 0.05em; color: var(--green);">
                        </div>
                    </div>
                <?php endif; ?>
                <!-- Socket Token -->
                <?php if ($socketToken): ?>
                    <div class="sp-card" style="margin-bottom: 0;">
                        <div class="sp-card-header">
                            <span style="font-size: 0.88rem; font-weight: 600; color: var(--text-primary);">Socket Token (Real-time Events)</span>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="sp-btn sp-btn-info sp-btn-sm" id="copySocketTokenBtn" title="Copy Socket Token">
                                    <i class="fas fa-copy" id="copySocketTokenIcon"></i>
                                </button>
                                <button class="sp-btn sp-btn-info sp-btn-sm" id="showSocketTokenBtn" title="Show Socket Token">
                                    <i class="fas fa-eye" id="socketTokenEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="sp-card-body">
                            <input type="text" id="socketTokenDisplay" class="sp-input" value="<?php echo str_repeat('•', strlen($socketToken)); ?>" readonly style="font-family: 'Courier New', monospace; font-size: 0.85rem; letter-spacing: 0.05em; color: var(--blue);">
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Recent Donations section -->
            <?php if (!empty($recentDonations)): ?>
                <div class="sp-card" style="margin-bottom: 1.5rem;">
                    <div class="sp-card-header">
                        <span class="sp-card-title">Recent Donations</span>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Latest <?php echo min(20, count($recentDonations)); ?></span>
                    </div>
                    <div class="sp-table-wrap" style="border: none; border-radius: 0;">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th>Donor</th>
                                    <th style="text-align: right;">Amount</th>
                                    <th>Message</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($recentDonations, 0, 20) as $donation): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($donation['name'] ?? 'Anonymous'); ?></strong></td>
                                        <td style="text-align: right; color: var(--green); font-weight: 600;">
                                            <?php echo htmlspecialchars($donation['currency'] ?? '$'); ?><?php echo htmlspecialchars(number_format($donation['amount'] ?? 0, 2)); ?>
                                        </td>
                                        <td style="color: var(--text-secondary); max-width: 250px; word-break: break-word;">
                                            <?php echo htmlspecialchars($donation['message'] ?? 'No message'); ?>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap; font-size: 0.875rem;">
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
            <?php endif; ?>
            <!-- Empty donations message -->
            <?php if (empty($recentDonations) && $isLinked && isset($access_token)): ?>
                <div style="text-align: center; padding: 3rem 1.5rem; border: 1px dashed var(--border); border-radius: var(--radius-lg);">
                    <i class="fas fa-inbox" style="font-size: 2rem; color: var(--text-muted); display: block; margin-bottom: 0.75rem;"></i>
                    <p style="font-weight: 600; color: var(--text-secondary); margin-bottom: 0.35rem;">No donations yet</p>
                    <p style="font-size: 0.82rem; color: var(--text-muted);">When you receive donations, they will appear here.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Not linked display -->
            <div style="text-align: center; padding: 1rem 0;">
                <p style="font-size: 1rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Connect your StreamLabs account to enable donation tracking and integration features.
                </p>
                <div style="max-width: 480px; margin: 0 auto 1.5rem;">
                    <div class="sp-card" style="margin-bottom: 0;">
                        <div class="sp-card-body" style="text-align: center;">
                            <i class="fas fa-link" style="font-size: 2.5rem; color: var(--text-muted); display: block; margin-bottom: 0.75rem;"></i>
                            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                                Link your StreamLabs account to automatically track and display donations on your dashboard.
                            </p>
                        </div>
                    </div>
                </div>
                <?php if ($authURL): ?>
                    <a href="<?php echo $authURL; ?>" class="sp-btn sp-btn-info" style="font-size: 1rem; padding: 0.65rem 1.5rem;">
                        <i class="fas fa-link"></i>
                        Link StreamLabs Account
                    </a>
                <?php elseif ($isActAsUser): ?>
                    <div class="sp-alert sp-alert-warning" style="max-width: 700px; margin: 0 auto;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Act As mode is active. Linking StreamLabs is disabled for acting users.
                    </div>
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
                copyAccessBtn.classList.add('sp-btn-success');
                copyAccessBtn.classList.remove('sp-btn-info');
                setTimeout(() => {
                    copyAccessIcon.classList.add('fa-copy');
                    copyAccessIcon.classList.remove('fa-check');
                    copyAccessBtn.classList.remove('sp-btn-success');
                    copyAccessBtn.classList.add('sp-btn-info');
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
                        accessBtn.classList.remove('sp-btn-warning');
                        accessBtn.classList.add('sp-btn-danger');
                        accessTokenVisible = true;
                    }
                });
            } else {
                accessDisplay.value = '•'.repeat(accessTokenDotCount);
                accessEye.classList.remove('fa-eye-slash');
                accessEye.classList.add('fa-eye');
                accessBtn.title = "Show Access Token";
                accessBtn.classList.remove('sp-btn-danger');
                accessBtn.classList.add('sp-btn-warning');
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
                copySocketBtn.classList.add('sp-btn-success');
                copySocketBtn.classList.remove('sp-btn-info');
                setTimeout(() => {
                    copySocketIcon.classList.add('fa-copy');
                    copySocketIcon.classList.remove('fa-check');
                    copySocketBtn.classList.remove('sp-btn-success');
                    copySocketBtn.classList.add('sp-btn-info');
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
                        socketBtn.classList.remove('sp-btn-info');
                        socketBtn.classList.add('sp-btn-danger');
                        socketTokenVisible = true;
                    }
                });
            } else {
                socketDisplay.value = '•'.repeat(socketTokenDotCount);
                socketEye.classList.remove('fa-eye-slash');
                socketEye.classList.add('fa-eye');
                socketBtn.title = "Show Socket Token";
                socketBtn.classList.remove('sp-btn-danger');
                socketBtn.classList.add('sp-btn-info');
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
