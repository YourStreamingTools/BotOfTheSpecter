<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('spotify_link_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/spotify.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

$isActAsUser = isset($isActAs) && $isActAs === true;

function spotifylink_build_list_payload(mysqli $conn, $user_id, $isActAsUser, $client_id, $redirect_uri): array
{
    $own_client = 0;
    $user_client_id = '';
    $user_client_secret = '';
    $connectionStatus = 'not-connected';
    $authURL = '';
    $message = '';
    $messageType = '';
    $effective_client_id = $client_id;

    $spotifySTMT = $conn->prepare("SELECT access_token, has_access, own_client, client_id, client_secret FROM spotify_tokens WHERE user_id = ?");
    $spotifySTMT->bind_param("i", $user_id);
    $spotifySTMT->execute();
    $spotifyResult = $spotifySTMT->get_result();

    if ($spotifyResult->num_rows > 0) {
        $spotifyRow = $spotifyResult->fetch_assoc();
        $spotifyAccessToken = $spotifyRow['access_token'];
        $hasAccess = $spotifyRow['has_access'];
        $own_client = $spotifyRow['own_client'];
        $user_client_id = $spotifyRow['client_id'] ?? '';
        $user_client_secret = $spotifyRow['client_secret'] ?? '';
        if ($own_client == 1 && !empty($user_client_id) && !empty($user_client_secret)) {
            $effective_client_id = $user_client_id;
        }
        if ($hasAccess == 1 || $own_client == 1) {
            $profileUrl = 'https://api.spotify.com/v1/me';
            $profileOptions = [
                'http' => [
                    'method' => 'GET',
                    'header' => "Authorization: Bearer $spotifyAccessToken",
                    'ignore_errors' => true
                ]
            ];
            $profileResponse = file_get_contents($profileUrl, false, stream_context_create($profileOptions));
            $spotifyUserInfo = json_decode($profileResponse, true);
            if (isset($spotifyUserInfo['id'])) {
                $connectionStatus = 'connected';
            } else {
                $message = t('spotifylink_msg_not_authorized');
                $messageType = "is-danger";
                if ($own_client == 1 || $hasAccess == 1) {
                    $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
                    $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$effective_client_id&scope=$scopes&redirect_uri=$redirect_uri";
                }
                $connectionStatus = 'error';
            }
        } else {
            $message = t('spotifylink_msg_pending_approval');
            $messageType = "is-warning";
            $connectionStatus = 'pending';
        }
    } else {
        if (!$isActAsUser && $own_client == 1) {
            $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
            $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$client_id&scope=$scopes&redirect_uri=$redirect_uri";
        }
    }
    $spotifySTMT->close();

    if ($authURL && strpos($authURL, 'client_id=') !== false) {
        $authURL = str_replace("client_id=$client_id", "client_id=$effective_client_id", $authURL);
    }

    $linkedAccountsCount = 0;
    $linkedAccountsStmt = $conn->prepare("SELECT COUNT(*) as count FROM spotify_tokens WHERE has_access = 1");
    $linkedAccountsStmt->execute();
    $linkedAccountsResult = $linkedAccountsStmt->get_result();
    $linkedAccountsCount = (int)($linkedAccountsResult->fetch_assoc()['count'] ?? 0);
    $linkedAccountsStmt->close();

    return [
        'success' => true,
        'connection_status' => $connectionStatus,
        'own_client' => (int)$own_client,
        'auth_url' => $authURL,
        'linked_accounts_count' => $linkedAccountsCount,
        'max_accounts' => 5,
        'is_act_as' => (bool)$isActAsUser,
        'message' => $message,
        'message_type' => $messageType,
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch Spotify status.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        echo json_encode(spotifylink_build_list_payload($conn, $user_id, $isActAsUser, $client_id, $redirect_uri));
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Set variables
$authURL = '';
$message = '';
$messageType = '';

// Fetch user's Spotify settings first to determine which credentials to use
$spotifySTMT = $conn->prepare("SELECT access_token, has_access, own_client, client_id, client_secret FROM spotify_tokens WHERE user_id = ?");
$spotifySTMT->bind_param("i", $user_id);
$spotifySTMT->execute();
$spotifyResult = $spotifySTMT->get_result();
$own_client = 0;
$user_client_id = '';
$user_client_secret = '';

if ($spotifyResult->num_rows > 0) {
    $spotifyRow = $spotifyResult->fetch_assoc();
    $own_client = $spotifyRow['own_client'];
    $user_client_id = $spotifyRow['client_id'] ?? '';
    $user_client_secret = $spotifyRow['client_secret'] ?? '';
}
$spotifySTMT->close();

// Determine effective client credentials
$effective_client_id = $client_id;
$effective_client_secret = $client_secret;
if ($own_client == 1 && !empty($user_client_id) && !empty($user_client_secret)) {
    $effective_client_id = $user_client_id;
    $effective_client_secret = $user_client_secret;
}

// Check if we received a code from Spotify (callback handling)
if ($isActAsUser && isset($_GET['code'])) {
    $message = t('spotifylink_msg_actas_link_disabled');
    $messageType = "is-warning";
} elseif (isset($_GET['code'])) {
    $auth_code = $_GET['code'];
    // Exchange the authorization code for an access token and refresh token
    $token_url = 'https://accounts.spotify.com/api/token';
    $data = [
        'grant_type' => 'authorization_code',
        'code' => $auth_code,
        'redirect_uri' => $redirect_uri,
        'client_id' => $effective_client_id,
        'client_secret' => $effective_client_secret
    ];
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($data),
            'ignore_errors' => true
        ]
    ];
    $response = file_get_contents($token_url, false, stream_context_create($options));
    if ($response === FALSE) {
        die(t('spotifylink_msg_contact_failed'));
    }
    $tokens = json_decode($response, true);
    if (isset($tokens['access_token'], $tokens['refresh_token'])) {
        $access_token = $tokens['access_token'];
        $refresh_token = $tokens['refresh_token'];
        // Check if the spotify_tokens entry exists for this user
        $checkStmt = $conn->prepare("SELECT 1 FROM spotify_tokens WHERE user_id = ?");
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        if ($exists) {
            // Update existing tokens for the user
            $updateStmt = $conn->prepare("UPDATE spotify_tokens SET access_token = ?, refresh_token = ?, auth = 1 WHERE user_id = ?");
            $updateStmt->bind_param("ssi", $access_token, $refresh_token, $user_id);
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            // Insert new tokens if none exist for this user
            $insertStmt = $conn->prepare("INSERT INTO spotify_tokens (user_id, access_token, refresh_token, auth) VALUES (?, ?, ?, 1)");
            $insertStmt->bind_param("iss", $user_id, $access_token, $refresh_token);
            $insertStmt->execute();
            $insertStmt->close();
        }
        $message = t('spotifylink_msg_link_success');
        $messageType = "is-success";
    } else {
        $message = t('spotifylink_msg_token_failed');
        $messageType = "is-danger";
    }
}

// Handle POST requests for own client settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isActAsUser && (isset($_POST['use_own_client']) || isset($_POST['save_credentials']))) {
        $message = t('spotifylink_msg_actas_settings_disabled');
        $messageType = "is-warning";
    } elseif (isset($_POST['use_own_client'])) {
        // Enable own client and reset auth
        $updateStmt = $conn->prepare("UPDATE spotify_tokens SET own_client = 1, auth = 0 WHERE user_id = ?");
        $updateStmt->bind_param("i", $user_id);
        $updateStmt->execute();
        $updateStmt->close();
        $own_client = 1;
    } elseif (isset($_POST['save_credentials'])) {
        $client_id_input = $_POST['client_id'] ?? '';
        $client_secret_input = $_POST['client_secret'] ?? '';
        $updateStmt = $conn->prepare("UPDATE spotify_tokens SET client_id = ?, client_secret = ? WHERE user_id = ?");
        $updateStmt->bind_param("ssi", $client_id_input, $client_secret_input, $user_id);
        $updateStmt->execute();
        $updateStmt->close();
        $user_client_id = $client_id_input;
        $user_client_secret = $client_secret_input;
        // Update effective credentials after saving
        if ($own_client == 1 && !empty($user_client_id) && !empty($user_client_secret)) {
            $effective_client_id = $user_client_id;
            $effective_client_secret = $user_client_secret;
        }
    }
}

// Start output buffering for layout
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fab fa-spotify"></i>
            <?php echo t('spotify_link_page_title'); ?>
        </div>
        <span id="spotifyStatusBadge" aria-busy="true">
            <span class="sp-skeleton-badge" aria-hidden="true"></span>
        </span>
    </div>
    <div class="sp-card-body">
        <div class="sp-alert sp-alert-warning" style="margin-bottom: 1.5rem;">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo t('spotifylink_policy_notice'); ?>
        </div>
        <?php if ($message): ?>
            <?php
                if ($messageType === 'is-success') $alertClass = 'sp-alert-success';
                elseif ($messageType === 'is-danger') $alertClass = 'sp-alert-danger';
                elseif ($messageType === 'is-warning') $alertClass = 'sp-alert-warning';
                else $alertClass = 'sp-alert-info';
            ?>
            <div class="sp-alert <?php echo $alertClass; ?>" style="margin-bottom: 1.5rem;">
                <?php if ($messageType === 'is-danger'): ?>
                    <i class="fas fa-exclamation-triangle"></i>
                <?php elseif ($messageType === 'is-success'): ?>
                    <i class="fas fa-check"></i>
                <?php elseif ($messageType === 'is-warning'): ?>
                    <i class="fas fa-exclamation-circle"></i>
                <?php else: ?>
                    <i class="fas fa-info-circle"></i>
                <?php endif; ?>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <div id="spotifyAjaxMessage"></div>
        <div class="sp-card" style="margin-bottom: 1.5rem;">
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-cogs" style="color: var(--blue);"></i>
                    <?php echo t('spotifylink_own_client_title'); ?>
                </div>
            </div>
            <div class="sp-card-body">
                <p style="color: var(--text-secondary); margin-bottom: 1rem;"><?php echo t('spotifylink_own_client_desc'); ?></p>
                <a href="https://help.botofthespecter.com/spotify_setup.php" target="_blank" class="sp-btn sp-btn-info sp-btn-sm" style="margin-bottom: 1rem;">
                    <i class="fas fa-external-link-alt"></i>
                    <?php echo t('spotifylink_setup_instructions'); ?>
                </a>
                <form method="post">
                    <div class="sp-form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-primary); cursor: pointer;">
                            <input type="checkbox" name="use_own_client" <?php echo $own_client == 1 ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <?php echo t('spotifylink_enable_own_client'); ?>
                        </label>
                    </div>
                    <?php if ($own_client == 1): ?>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('spotifylink_client_id_label'); ?></label>
                            <input class="sp-input" type="text" name="client_id" value="<?php echo htmlspecialchars($user_client_id); ?>" placeholder="<?php echo htmlspecialchars(t('spotifylink_client_id_placeholder')); ?>">
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('spotifylink_client_secret_label'); ?></label>
                            <input class="sp-input" type="password" name="client_secret" value="<?php echo htmlspecialchars($user_client_secret); ?>" placeholder="<?php echo htmlspecialchars(t('spotifylink_client_secret_placeholder')); ?>">
                        </div>
                        <div class="sp-form-group">
                            <button class="sp-btn sp-btn-success" type="submit" name="save_credentials"><?php echo t('spotifylink_save_credentials'); ?></button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div id="spotifyStatusHost" aria-busy="true">
            <div class="sp-skeleton-stack" aria-hidden="true">
                <span class="sp-skeleton-line w-80"></span>
                <span class="sp-skeleton-line w-60"></span>
                <span class="sp-skeleton-line w-70"></span>
                <span class="sp-skeleton-line w-50"></span>
                <span class="sp-skeleton-line w-90"></span>
                <span class="sp-skeleton-line w-40"></span>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const SP_I18N = {
    connectedTitle: <?php echo json_encode(t('spotify_connected_title')); ?>,
    badgePending: <?php echo json_encode(t('spotifylink_badge_pending')); ?>,
    badgeNotConnected: <?php echo json_encode(t('spotifylink_badge_not_connected')); ?>,
    connectedAccountInfo: <?php echo json_encode(t('spotifylink_connected_account_info')); ?>,
    restartBot: <?php echo json_encode(t('spotify_connected_restart_bot')); ?>,
    checkLink: <?php echo json_encode(t('spotify_connected_check_link')); ?>,
    availableFeatures: <?php echo json_encode(t('spotifylink_available_features')); ?>,
    featureCurrentSong: <?php echo json_encode(t('spotify_feature_current_song')); ?>,
    featureSongRequest: <?php echo json_encode(t('spotify_feature_song_request')); ?>,
    featureOr: <?php echo json_encode(t('spotify_feature_or')); ?>,
    featureExample: <?php echo json_encode(t('spotify_feature_example')); ?>,
    accountsLinked: <?php echo json_encode(t('spotify_accounts_linked')); ?>,
    linkButton: <?php echo json_encode(t('spotify_link_button')); ?>,
    actasDisabled: <?php echo json_encode(t('spotifylink_actas_disabled')); ?>,
    capacityFull: <?php echo json_encode(t('spotifylink_capacity_full')); ?>,
    contactFailed: <?php echo json_encode(t('spotifylink_msg_contact_failed')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function replaceCountMax(template, count, max) {
    return String(template == null ? '' : template)
        .replace(/:count/g, String(count))
        .replace(/:max/g, String(max));
}

function codeChip(text) {
    return '<code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">' + escapeHtml(text) + '</code>';
}

function featuresListHtml(leftAlign) {
    var align = leftAlign ? ' text-align: left;' : '';
    return '<ul style="color: var(--text-secondary); list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem;' + align + '">' +
        '<li>' + escapeHtml(SP_I18N.featureCurrentSong) + ' ' + codeChip('!song') + '</li>' +
        '<li>' + escapeHtml(SP_I18N.featureSongRequest) + ' ' + codeChip('!songrequest [song title] [artist]') + ' (' + escapeHtml(SP_I18N.featureOr) + ' ' + codeChip('!sr') + ')</li>' +
        '<li>' + escapeHtml(SP_I18N.featureExample) + ' ' + codeChip('!songrequest Stick Season Noah Kahan') + '</li>' +
        '</ul>';
}

function featuresCardHtml(opts) {
    opts = opts || {};
    var cardStyle = opts.cardStyle ? ' style="' + opts.cardStyle + '"' : '';
    var listAlign = !!opts.leftAlign;
    return '<div class="sp-card"' + cardStyle + '>' +
        '<div class="sp-card-header"><div class="sp-card-title">' +
        '<i class="fas fa-music" style="color: var(--green);"></i> ' + escapeHtml(SP_I18N.availableFeatures) +
        '</div></div>' +
        '<div class="sp-card-body">' + featuresListHtml(listAlign) + '</div>' +
        '</div>';
}

function accountsLinkedHtml(count, max) {
    return '<p style="color: var(--text-secondary);"><strong>' +
        replaceCountMax(SP_I18N.accountsLinked, count, max) +
        '</strong></p>';
}

function alertIcon(type) {
    if (type === 'is-danger') return 'fa-exclamation-triangle';
    if (type === 'is-success') return 'fa-check';
    if (type === 'is-warning') return 'fa-exclamation-circle';
    return 'fa-info-circle';
}

function alertClass(type) {
    if (type === 'is-success') return 'sp-alert-success';
    if (type === 'is-danger') return 'sp-alert-danger';
    if (type === 'is-warning') return 'sp-alert-warning';
    return 'sp-alert-info';
}

function showAjaxMessage(message, type) {
    var box = document.getElementById('spotifyAjaxMessage');
    if (!box) return;
    if (!message) {
        box.innerHTML = '';
        return;
    }
    box.innerHTML = '<div class="sp-alert ' + alertClass(type) + '" style="margin-bottom: 1.5rem;">' +
        '<i class="fas ' + alertIcon(type) + '"></i> ' + message +
        '</div>';
}

function renderSpotifyBadge(status) {
    var badge = document.getElementById('spotifyStatusBadge');
    if (!badge) return;
    var html;
    if (status === 'connected') {
        html = '<span class="sp-badge sp-badge-green"><i class="fas fa-check-circle"></i> ' + escapeHtml(SP_I18N.connectedTitle) + '</span>';
    } else if (status === 'pending') {
        html = '<span class="sp-badge sp-badge-amber"><i class="fas fa-clock"></i> ' + escapeHtml(SP_I18N.badgePending) + '</span>';
    } else {
        html = '<span class="sp-badge sp-badge-red"><i class="fas fa-times-circle"></i> ' + escapeHtml(SP_I18N.badgeNotConnected) + '</span>';
    }
    badge.innerHTML = html;
    badge.setAttribute('aria-busy', 'false');
}

function renderSpotifyStatus(data) {
    var host = document.getElementById('spotifyStatusHost');
    if (!host) return;
    var status = data.connection_status || 'not-connected';
    var count = data.linked_accounts_count || 0;
    var max = data.max_accounts || 5;
    var html = '';
    if (status === 'connected') {
        html = '<div class="sp-card">' +
            '<div class="sp-card-header"><div class="sp-card-title">' +
            '<i class="fab fa-spotify" style="color: var(--green);"></i> ' + escapeHtml(SP_I18N.connectedAccountInfo) +
            '</div></div>' +
            '<div class="sp-card-body">' +
            '<div class="sp-alert sp-alert-warning" style="margin-bottom: 1rem;">' +
            '<i class="fas fa-info-circle"></i> <strong>' + escapeHtml(SP_I18N.restartBot) + '</strong></div>' +
            '<div class="sp-alert sp-alert-info" style="margin-bottom: 1.5rem;">' +
            '<i class="fas fa-link"></i> <strong>' + SP_I18N.checkLink + '</strong></div>' +
            featuresCardHtml({ cardStyle: 'margin-bottom: 1rem;' }) +
            accountsLinkedHtml(count, max) +
            '</div></div>';
    } else {
        html = '<div style="text-align: center;">' +
            '<div style="max-width: 700px; margin: 0 auto 1.5rem;">' +
            featuresCardHtml({ cardStyle: 'max-width: 600px; margin: 0 auto 1rem;', leftAlign: true }) +
            accountsLinkedHtml(count, max) +
            '</div>';
        if (data.auth_url && status !== 'pending') {
            html += '<a href="' + escapeHtml(data.auth_url) + '" class="sp-btn sp-btn-success" style="font-size: 1rem; padding: 0.75rem 1.75rem;">' +
                '<i class="fab fa-spotify"></i> ' + escapeHtml(SP_I18N.linkButton) +
                '</a>';
        } else if (data.is_act_as) {
            html += '<div class="sp-alert sp-alert-warning" style="max-width: 700px; margin: 0 auto;">' +
                '<i class="fas fa-exclamation-circle"></i> ' + escapeHtml(SP_I18N.actasDisabled) +
                '</div>';
        } else if (!data.auth_url && status === 'not-connected' && Number(data.own_client) === 0) {
            html += '<div class="sp-alert sp-alert-danger" style="max-width: 700px; margin: 0 auto;">' +
                '<i class="fas fa-exclamation-triangle"></i> ' + replaceCountMax(SP_I18N.capacityFull, count, max) +
                '</div>';
        }
        html += '</div>';
    }
    host.innerHTML = html;
    host.setAttribute('aria-busy', 'false');
}

function renderSpotifyError() {
    renderSpotifyBadge('error');
    var host = document.getElementById('spotifyStatusHost');
    if (host) {
        host.innerHTML = '';
        host.setAttribute('aria-busy', 'false');
    }
    showAjaxMessage(escapeHtml(SP_I18N.contactFailed), 'is-danger');
}

function loadSpotifyStatus() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderSpotifyError();
                return;
            }
            renderSpotifyBadge(data.connection_status);
            showAjaxMessage(data.message || '', data.message_type || '');
            renderSpotifyStatus(data);
        })
        .catch(function() {
            renderSpotifyError();
        });
}

document.addEventListener('DOMContentLoaded', loadSpotifyStatus);
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>
