<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('navbar_streamlabs') ?? 'StreamLabs Integration';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include "/var/www/config/streamlabs.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load

$twitchUserId = $_SESSION['twitchUserId'] ?? null;
$isLinked = false;
$linkingMessage = '';
$linkingMessageType = '';
$isActAsUser = isset($isActAs) && $isActAs === true;
$access_token = null;
$refresh_token = null;
$expires_in = 3600;
$token_created_at = null;

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

// List endpoint first so the browser can paint skeletons, then fetch StreamLabs data.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    session_write_close();
    header('Content-Type: application/json');
    $payload = [
        'success' => true,
        'linked' => $isLinked,
        'donations' => [],
        'donations_ok' => true,
        'socket_token' => null,
        'user' => null,
    ];
    if ($isLinked && !empty($access_token)) {
        $timezone = 'UTC';
        $tzStmt = $db->prepare("SELECT timezone FROM profile");
        if ($tzStmt) {
            $tzStmt->execute();
            $tzRow = $tzStmt->get_result()->fetch_assoc();
            $tzStmt->close();
            $timezone = $tzRow['timezone'] ?? 'UTC';
        }
        date_default_timezone_set($timezone);

        $donations_url = "https://streamlabs.com/api/v2.0/donations?limit=100&currency=USD";
        $ch = curl_init($donations_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
                $recentDonations = array_slice($donations_data['data'], 0, 20);
                foreach ($recentDonations as $donation) {
                    $createdAtFormatted = '';
                    if (isset($donation['created_at'])) {
                        try {
                            $dt = new DateTime();
                            $dt->setTimestamp((int)$donation['created_at']);
                            $createdAtFormatted = $dt->format('M j, Y');
                        } catch (Exception $e) {
                            $createdAtFormatted = (string)$donation['created_at'];
                        }
                    }
                    $payload['donations'][] = [
                        'name' => $donation['name'] ?? null,
                        'currency' => $donation['currency'] ?? '$',
                        'amount' => $donation['amount'] ?? 0,
                        'message' => $donation['message'] ?? null,
                        'created_at' => $donation['created_at'] ?? null,
                        'created_at_formatted' => $createdAtFormatted,
                    ];
                }
            }
        } else {
            $payload['donations_ok'] = false;
        }

        $socket_token_url = "https://streamlabs.com/api/v2.0/socket/token";
        $ch = curl_init($socket_token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
                $payload['socket_token'] = $socketToken;
                if (!empty($socketToken) && isset($twitchUserId)) {
                    $colCheckSql = "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'streamlabs_tokens' AND COLUMN_NAME = 'socket_token'";
                    if ($colRes = $conn->query($colCheckSql)) {
                        $colRow = $colRes->fetch_assoc();
                        $colExists = (isset($colRow['cnt']) && (int)$colRow['cnt'] > 0);
                        $colRes->free();
                    } else {
                        $colExists = false;
                    }
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

        $user_url = "https://streamlabs.com/api/v2.0/user";
        $ch = curl_init($user_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
                $payload['user'] = $user_data;
            }
        }
    }
    echo json_encode($payload);
    exit();
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
            $linkingMessage = t('streamlabs_msg_unlinked');
            $linkingMessageType = "is-success";
            $isLinked = false;
            unset($access_token);
        } else {
            $linkingMessage = t('streamlabs_msg_unlink_failed');
            $linkingMessageType = "is-danger";
        }
        $stmt->close();
    }
}

// Handle user denial (error=true in query string)
if ($isActAsUser && isset($_GET['code'])) {
    $linkingMessage = t('streamlabs_msg_actas_link_disabled');
    $linkingMessageType = "is-warning";
} elseif (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = t('streamlabs_msg_auth_denied');
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code']) && !$isActAsUser) {
    // Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamlabs_oauth_state']) || $_GET['state'] !== $_SESSION['streamlabs_oauth_state']) {
        $linkingMessage = t('streamlabs_msg_invalid_state');
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
                        $linkingMessage = t('streamlabs_msg_linked');
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
                        $linkingMessage = t('streamlabs_msg_save_tokens_failed') . " " . $stmt->error;
                        $linkingMessageType = "is-warning";
                    }
                    $stmt->close();
                } else {
                    $linkingMessage = t('streamlabs_msg_prepare_failed') . " " . $conn->error;
                    $linkingMessageType = "is-warning";
                }
            } else {
                $linkingMessage = t('streamlabs_msg_missing_ids');
                $linkingMessageType = "is-warning";
            }
        } else {
            $linkingMessage = t('streamlabs_msg_link_failed');
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

session_write_close();
ob_start();
?>
<div class="sp-alert sp-alert-success" style="margin-bottom: 1.5rem;">
    <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
        <i class="fas fa-bell" style="margin-top: 0.15rem; flex-shrink: 0;"></i>
        <div>
            <strong><?= t('streamlabs_api_enabled_title') ?></strong>
            <p style="margin: 0.35rem 0 0; font-size: 0.9rem;">
                <?= t('streamlabs_api_enabled_desc') ?>
            </p>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fas fa-gift"></i>
            <?= t('streamlabs_integration_title') ?>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <?php if ($isLinked): ?>
                <span class="sp-badge sp-badge-green">
                    <i class="fas fa-check-circle"></i>
                    <?= t('streamlabs_badge_linked') ?>
                </span>
                <button id="unlinkHeaderBtn" class="sp-btn sp-btn-danger sp-btn-sm" title="<?= htmlspecialchars(t('streamlabs_unlink_account_title')) ?>">
                    <i class="fas fa-unlink"></i>
                    <?= t('streamlabs_unlink') ?>
                </button>
            <?php else: ?>
                <span class="sp-badge sp-badge-red">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= t('streamlabs_badge_not_linked') ?>
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
                <?= t('streamlabs_linked_intro') ?>
            </p>
            <!-- Tokens section -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                <!-- Access Token -->
                <?php if ($access_token): ?>
                    <div class="sp-card" style="margin-bottom: 0;">
                        <div class="sp-card-header">
                            <span style="font-size: 0.88rem; font-weight: 600; color: var(--text-primary);"><?= t('streamlabs_access_token_label') ?></span>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="sp-btn sp-btn-info sp-btn-sm" id="copyAccessTokenBtn" title="<?= htmlspecialchars(t('streamlabs_copy_access_token_title')) ?>">
                                    <i class="fas fa-copy" id="copyAccessTokenIcon"></i>
                                </button>
                                <button class="sp-btn sp-btn-warning sp-btn-sm" id="showAccessTokenBtn" title="<?= htmlspecialchars(t('streamlabs_show_access_token_title')) ?>">
                                    <i class="fas fa-eye" id="accessTokenEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="sp-card-body">
                            <input type="text" id="accessTokenDisplay" class="sp-input" value="<?php echo str_repeat('•', strlen($access_token)); ?>" readonly style="font-family: 'Courier New', monospace; font-size: 0.85rem; letter-spacing: 0.05em;">
                        </div>
                    </div>
                <?php endif; ?>
                <!-- Socket Token -->
                <div id="socketTokenHost" aria-busy="true">
                    <div id="socketTokenSkeleton" class="sp-card" style="margin-bottom: 0;">
                        <div class="sp-card-header">
                            <span style="font-size: 0.88rem; font-weight: 600; color: var(--text-primary);"><?= t('streamlabs_socket_token_label') ?></span>
                            <span class="sp-skeleton-badge"></span>
                        </div>
                        <div class="sp-card-body">
                            <span class="sp-skeleton-line w-90"></span>
                        </div>
                    </div>
                    <div id="socketTokenCard" class="sp-card" style="margin-bottom: 0; display: none;">
                        <div class="sp-card-header">
                            <span style="font-size: 0.88rem; font-weight: 600; color: var(--text-primary);"><?= t('streamlabs_socket_token_label') ?></span>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="sp-btn sp-btn-info sp-btn-sm" id="copySocketTokenBtn" title="<?= htmlspecialchars(t('streamlabs_copy_socket_token_title')) ?>">
                                    <i class="fas fa-copy" id="copySocketTokenIcon"></i>
                                </button>
                                <button class="sp-btn sp-btn-info sp-btn-sm" id="showSocketTokenBtn" title="<?= htmlspecialchars(t('streamlabs_show_socket_token_title')) ?>">
                                    <i class="fas fa-eye" id="socketTokenEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="sp-card-body">
                            <input type="text" id="socketTokenDisplay" class="sp-input" value="" readonly style="font-family: 'Courier New', monospace; font-size: 0.85rem; letter-spacing: 0.05em;">
                        </div>
                    </div>
                </div>
            </div>
            <!-- Recent Donations section -->
            <div id="donationsCard" class="sp-card" style="margin-bottom: 1.5rem;" aria-busy="true">
                <div class="sp-card-header">
                    <span class="sp-card-title"><?= t('streamlabs_recent_donations_title') ?></span>
                    <span id="donationsCount" style="font-size: 0.8rem; color: var(--text-muted);"><span class="sp-skeleton-line w-40"></span></span>
                </div>
                <div class="sp-table-wrap" style="border: none; border-radius: 0;">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th><?= t('streamlabs_th_donor') ?></th>
                                <th style="text-align: right;"><?= t('streamlabs_th_amount') ?></th>
                                <th><?= t('streamlabs_th_message') ?></th>
                                <th><?= t('streamlabs_th_date') ?></th>
                            </tr>
                        </thead>
                        <tbody id="donationsTableBody">
                            <?php for ($sk = 0; $sk < 5; $sk++): ?>
                            <tr aria-hidden="true">
                                <td><span class="sp-skeleton-line w-50"></span></td>
                                <td style="text-align: right;"><span class="sp-skeleton-line w-40"></span></td>
                                <td><span class="sp-skeleton-line w-80"></span></td>
                                <td><span class="sp-skeleton-line w-60"></span></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Empty donations message (shown only after a successful empty load) -->
            <div id="donationsEmpty" style="display: none; text-align: center; padding: 3rem 1.5rem; border: 1px dashed var(--border); border-radius: var(--radius-lg);">
                <i class="fas fa-inbox" style="font-size: 2rem; color: var(--text-muted); display: block; margin-bottom: 0.75rem;"></i>
                <p style="font-weight: 600; color: var(--text-secondary); margin-bottom: 0.35rem;"><?= t('streamlabs_no_donations_yet') ?></p>
                <p style="font-size: 0.82rem; color: var(--text-muted);"><?= t('streamlabs_no_donations_hint') ?></p>
            </div>
        <?php else: ?>
            <!-- Not linked display -->
            <div style="text-align: center; padding: 1rem 0;">
                <p style="font-size: 1rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    <?= t('streamlabs_connect_intro') ?>
                </p>
                <div style="max-width: 480px; margin: 0 auto 1.5rem;">
                    <div class="sp-card" style="margin-bottom: 0;">
                        <div class="sp-card-body" style="text-align: center;">
                            <i class="fas fa-link" style="font-size: 2.5rem; color: var(--text-muted); display: block; margin-bottom: 0.75rem;"></i>
                            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                                <?= t('streamlabs_link_card_desc') ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php if ($authURL): ?>
                    <a href="<?php echo $authURL; ?>" class="sp-btn sp-btn-info" style="font-size: 1rem; padding: 0.65rem 1.5rem;">
                        <i class="fas fa-link"></i>
                        <?= t('streamlabs_link_account_btn') ?>
                    </a>
                <?php elseif ($isActAsUser): ?>
                    <div class="sp-alert sp-alert-warning" style="max-width: 700px; margin: 0 auto;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= t('streamlabs_actas_disabled') ?>
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
                title: <?php echo json_encode(t('streamlabs_swal_unlink_title')); ?>,
                text: <?php echo json_encode(t('streamlabs_swal_unlink_text')); ?>,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: <?php echo json_encode(t('streamlabs_unlink')); ?>,
                cancelButtonText: <?php echo json_encode(t('streamlabs_cancel')); ?>,
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
let socketToken = '';
let socketTokenDotCount = 0;
let socketTokenVisible = false;
const SL_I18N = {
    anonymous: <?php echo json_encode(t('streamlabs_anonymous')); ?>,
    noMessage: <?php echo json_encode(t('streamlabs_no_message')); ?>,
    latest: <?php echo json_encode(t('streamlabs_recent_donations_latest')); ?>,
    loadError: <?php echo json_encode(t('dashboard_js_load_error')); ?>,
    hideSocketTitle: <?php echo json_encode(t('streamlabs_hide_socket_token_title')); ?>,
    showSocketTitle: <?php echo json_encode(t('streamlabs_show_socket_token_title')); ?>,
    copyFailedTitle: <?php echo json_encode(t('streamlabs_swal_copy_failed_title')); ?>,
    copyFailedText: <?php echo json_encode(t('streamlabs_swal_copy_failed_text')); ?>,
    revealSocketTitle: <?php echo json_encode(t('streamlabs_swal_reveal_socket_title')); ?>,
    revealSocketText: <?php echo json_encode(t('streamlabs_swal_reveal_socket_text')); ?>,
    swalShow: <?php echo json_encode(t('streamlabs_swal_show')); ?>,
    cancel: <?php echo json_encode(t('streamlabs_cancel')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function formatDonationAmount(donation) {
    var amount = Number(donation.amount);
    if (isNaN(amount)) amount = 0;
    return amount.toFixed(2);
}

function renderDonationsError() {
    var card = document.getElementById('donationsCard');
    var tbody = document.getElementById('donationsTableBody');
    var empty = document.getElementById('donationsEmpty');
    var count = document.getElementById('donationsCount');
    if (empty) empty.style.display = 'none';
    if (card) {
        card.style.display = '';
        card.setAttribute('aria-busy', 'false');
    }
    if (count) count.textContent = '';
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">' + escapeHtml(SL_I18N.loadError) + '</td></tr>';
    }
}

function renderDonations(donations) {
    var card = document.getElementById('donationsCard');
    var tbody = document.getElementById('donationsTableBody');
    var empty = document.getElementById('donationsEmpty');
    var count = document.getElementById('donationsCount');
    if (card) card.setAttribute('aria-busy', 'false');
    if (!donations.length) {
        if (card) card.style.display = 'none';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (card) card.style.display = '';
    if (count) count.textContent = SL_I18N.latest + ' ' + donations.length;
    if (!tbody) return;
    tbody.innerHTML = donations.map(function(donation) {
        var name = donation.name || SL_I18N.anonymous;
        var currency = donation.currency || '$';
        var message = donation.message || SL_I18N.noMessage;
        var dateLabel = donation.created_at_formatted || '';
        return '<tr>'
            + '<td><strong>' + escapeHtml(name) + '</strong></td>'
            + '<td style="text-align: right; color: var(--green); font-weight: 600;">'
            + escapeHtml(currency) + escapeHtml(formatDonationAmount(donation))
            + '</td>'
            + '<td style="color: var(--text-secondary); max-width: 250px; word-break: break-word;">'
            + escapeHtml(message)
            + '</td>'
            + '<td style="color: var(--text-muted); white-space: nowrap; font-size: 0.875rem;">'
            + escapeHtml(dateLabel)
            + '</td></tr>';
    }).join('');
}

function hideSocketTokenCard() {
    var host = document.getElementById('socketTokenHost');
    var skeleton = document.getElementById('socketTokenSkeleton');
    var card = document.getElementById('socketTokenCard');
    if (skeleton) skeleton.style.display = 'none';
    if (card) card.style.display = 'none';
    if (host) {
        host.style.display = 'none';
        host.setAttribute('aria-busy', 'false');
    }
}

function showSocketTokenCard(token) {
    socketToken = String(token || '');
    socketTokenDotCount = socketToken.length;
    socketTokenVisible = false;
    var host = document.getElementById('socketTokenHost');
    var skeleton = document.getElementById('socketTokenSkeleton');
    var card = document.getElementById('socketTokenCard');
    var display = document.getElementById('socketTokenDisplay');
    if (skeleton) skeleton.style.display = 'none';
    if (card) card.style.display = '';
    if (host) host.setAttribute('aria-busy', 'false');
    if (display) display.value = '•'.repeat(socketTokenDotCount);
    bindSocketTokenControls();
}

function bindSocketTokenControls() {
    const socketBtn = document.getElementById('showSocketTokenBtn');
    const socketEye = document.getElementById('socketTokenEye');
    const socketDisplay = document.getElementById('socketTokenDisplay');
    const copySocketBtn = document.getElementById('copySocketTokenBtn');
    const copySocketIcon = document.getElementById('copySocketTokenIcon');
    if (copySocketBtn && !copySocketBtn.dataset.bound) {
        copySocketBtn.dataset.bound = '1';
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
                    title: SL_I18N.copyFailedTitle,
                    text: SL_I18N.copyFailedText
                });
            });
        });
    }
    if (socketBtn && socketEye && socketDisplay && !socketBtn.dataset.bound) {
        socketBtn.dataset.bound = '1';
        socketBtn.addEventListener('click', function() {
            if (!socketTokenVisible) {
                Swal.fire({
                    title: SL_I18N.revealSocketTitle,
                    text: SL_I18N.revealSocketText,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: SL_I18N.swalShow,
                    cancelButtonText: SL_I18N.cancel,
                    confirmButtonColor: '#3273dc',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        socketDisplay.value = socketToken;
                        socketEye.classList.remove('fa-eye');
                        socketEye.classList.add('fa-eye-slash');
                        socketBtn.title = SL_I18N.hideSocketTitle;
                        socketBtn.classList.remove('sp-btn-info');
                        socketBtn.classList.add('sp-btn-danger');
                        socketTokenVisible = true;
                    }
                });
            } else {
                socketDisplay.value = '•'.repeat(socketTokenDotCount);
                socketEye.classList.remove('fa-eye-slash');
                socketEye.classList.add('fa-eye');
                socketBtn.title = SL_I18N.showSocketTitle;
                socketBtn.classList.remove('sp-btn-danger');
                socketBtn.classList.add('sp-btn-info');
                socketTokenVisible = false;
            }
        });
    }
}

function loadStreamlabsList() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success || data.donations_ok === false) {
                renderDonationsError();
            } else {
                renderDonations(Array.isArray(data.donations) ? data.donations : []);
            }
            if (data && data.socket_token) {
                showSocketTokenCard(data.socket_token);
            } else {
                hideSocketTokenCard();
            }
        })
        .catch(function() {
            renderDonationsError();
            hideSocketTokenCard();
        });
}

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
                    title: <?php echo json_encode(t('streamlabs_swal_copy_failed_title')); ?>,
                    text: <?php echo json_encode(t('streamlabs_swal_copy_failed_text')); ?>
                });
            });
        });
    }
    if (accessBtn && accessEye && accessDisplay) {
        accessBtn.addEventListener('click', function() {
            if (!accessTokenVisible) {
                Swal.fire({
                    title: <?php echo json_encode(t('streamlabs_swal_reveal_access_title')); ?>,
                    text: <?php echo json_encode(t('streamlabs_swal_reveal_access_text')); ?>,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: <?php echo json_encode(t('streamlabs_swal_show')); ?>,
                    cancelButtonText: <?php echo json_encode(t('streamlabs_cancel')); ?>,
                    confirmButtonColor: '#f39c12',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        accessDisplay.value = accessToken;
                        accessEye.classList.remove('fa-eye');
                        accessEye.classList.add('fa-eye-slash');
                        accessBtn.title = <?php echo json_encode(t('streamlabs_hide_access_token_title')); ?>;
                        accessBtn.classList.remove('sp-btn-warning');
                        accessBtn.classList.add('sp-btn-danger');
                        accessTokenVisible = true;
                    }
                });
            } else {
                accessDisplay.value = '•'.repeat(accessTokenDotCount);
                accessEye.classList.remove('fa-eye-slash');
                accessEye.classList.add('fa-eye');
                accessBtn.title = <?php echo json_encode(t('streamlabs_show_access_token_title')); ?>;
                accessBtn.classList.remove('sp-btn-danger');
                accessBtn.classList.add('sp-btn-warning');
                accessTokenVisible = false;
            }
        });
    }
    loadStreamlabsList();
});
</script>
<?php
$scripts = ob_get_clean();
endif;

include 'layout.php';
?>
