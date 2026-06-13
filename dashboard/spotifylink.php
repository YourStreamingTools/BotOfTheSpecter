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
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/bot_control.php';
include "includes/mod_access.php";
include 'includes/user_db.php';
include 'includes/storage_used.php';
session_write_close();
$isActAsUser = isset($isActAs) && $isActAs === true;
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
$spotifyUserInfo = [];

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
        if ($exists) {
            // Update existing tokens for the user
            $updateStmt = $conn->prepare("UPDATE spotify_tokens SET access_token = ?, refresh_token = ?, auth = 1 WHERE user_id = ?");
            $updateStmt->bind_param("ssi", $access_token, $refresh_token, $user_id);
            $updateStmt->execute();
        } else {
            // Insert new tokens if none exist for this user
            $insertStmt = $conn->prepare("INSERT INTO spotify_tokens (user_id, access_token, refresh_token, auth) VALUES (?, ?, ?, 1)");
            $insertStmt->bind_param("iss", $user_id, $access_token, $refresh_token);
            $insertStmt->execute();
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
        $own_client = 1;
    } elseif (isset($_POST['save_credentials'])) {
        $client_id_input = $_POST['client_id'] ?? '';
        $client_secret_input = $_POST['client_secret'] ?? '';
        $updateStmt = $conn->prepare("UPDATE spotify_tokens SET client_id = ?, client_secret = ? WHERE user_id = ?");
        $updateStmt->bind_param("ssi", $client_id_input, $client_secret_input, $user_id);
        $updateStmt->execute();
        $user_client_id = $client_id_input;
        $user_client_secret = $client_secret_input;
        // Update effective credentials after saving
        if ($own_client == 1 && !empty($user_client_id) && !empty($user_client_secret)) {
            $effective_client_id = $user_client_id;
            $effective_client_secret = $user_client_secret;
        }
    }
}

// Re-fetch Spotify Profile Information for display
$spotifySTMT = $conn->prepare("SELECT access_token, has_access, own_client, client_id, client_secret FROM spotify_tokens WHERE user_id = ?");
$spotifySTMT->bind_param("i", $user_id);
$spotifySTMT->execute();
$spotifyResult = $spotifySTMT->get_result();
$connectionStatus = 'not-connected'; // default

if ($spotifyResult->num_rows > 0) {
    // User has a token entry
    $spotifyRow = $spotifyResult->fetch_assoc();
    $spotifyAccessToken = $spotifyRow['access_token'];
    $hasAccess = $spotifyRow['has_access'];
    // Update these from the latest fetch
    $own_client = $spotifyRow['own_client'];
    $user_client_id = $spotifyRow['client_id'] ?? '';
    $user_client_secret = $spotifyRow['client_secret'] ?? '';
    if ($hasAccess == 1 || $own_client == 1) {
        // Has access or using own client, try to fetch profile
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
            // Allow reconnect if using own client OR if they already had a linked slot (has_access = 1)
            if ($own_client == 1 || $hasAccess == 1) {
                $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
                $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$effective_client_id&scope=$scopes&redirect_uri=$redirect_uri";
            }
            $connectionStatus = 'error';
        }
    } else {
        // Pending approval
        $message = t('spotifylink_msg_pending_approval');
        $messageType = "is-warning";
        $connectionStatus = 'pending';
    }
} else {
    // User not linked - only allow linking via own client (dev account is full)
    if (!$isActAsUser && $own_client == 1) {
        $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
        $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$client_id&scope=$scopes&redirect_uri=$redirect_uri";
    }
}

// Update auth URL if needed to use effective client ID
if ($authURL && strpos($authURL, 'client_id=') !== false) {
    $authURL = str_replace("client_id=$client_id", "client_id=$effective_client_id", $authURL);
}

// Fetch the number of linked accounts with access
$linkedAccountsStmt = $conn->prepare("SELECT COUNT(*) as count FROM spotify_tokens WHERE has_access = 1");
$linkedAccountsStmt->execute();
$linkedAccountsResult = $linkedAccountsStmt->get_result();
$linkedAccountsCount = $linkedAccountsResult->fetch_assoc()['count'];
$maxAccounts = 5;

// Start output buffering for layout
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fab fa-spotify"></i>
            <?php echo t('spotify_link_page_title'); ?>
        </div>
        <?php if ($connectionStatus === 'connected'): ?>
            <span class="sp-badge sp-badge-green">
                <i class="fas fa-check-circle"></i>
                <?php echo t('spotify_connected_title'); ?>
            </span>
        <?php elseif ($connectionStatus === 'pending'): ?>
            <span class="sp-badge sp-badge-amber">
                <i class="fas fa-clock"></i>
                <?php echo t('spotifylink_badge_pending'); ?>
            </span>
        <?php else: ?>
            <span class="sp-badge sp-badge-red">
                <i class="fas fa-times-circle"></i>
                <?php echo t('spotifylink_badge_not_connected'); ?>
            </span>
        <?php endif; ?>
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
        <?php if ($connectionStatus === 'connected'): ?>
            <div class="sp-card">
                <div class="sp-card-header">
                    <div class="sp-card-title">
                        <i class="fab fa-spotify" style="color: var(--green);"></i>
                        <?php echo t('spotifylink_connected_account_info'); ?>
                    </div>
                </div>
                <div class="sp-card-body">
                    <div class="sp-alert sp-alert-warning" style="margin-bottom: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo t('spotify_connected_restart_bot'); ?></strong>
                    </div>
                    <div class="sp-alert sp-alert-info" style="margin-bottom: 1.5rem;">
                        <i class="fas fa-link"></i>
                        <strong><?php echo t('spotify_connected_check_link'); ?></strong>
                    </div>
                    <div class="sp-card" style="margin-bottom: 1rem;">
                        <div class="sp-card-header">
                            <div class="sp-card-title">
                                <i class="fas fa-music" style="color: var(--green);"></i>
                                <?php echo t('spotifylink_available_features'); ?>
                            </div>
                        </div>
                        <div class="sp-card-body">
                            <ul style="color: var(--text-secondary); list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem;">
                                <li><?php echo t('spotify_feature_current_song'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!song</code></li>
                                <li><?php echo t('spotify_feature_song_request'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!songrequest [song title] [artist]</code> (<?php echo t('spotify_feature_or'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!sr</code>)</li>
                                <li><?php echo t('spotify_feature_example'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!songrequest Stick Season Noah Kahan</code></li>
                            </ul>
                        </div>
                    </div>
                    <p style="color: var(--text-secondary);">
                        <strong><?php
                            $accountsLinkedText = t('spotify_accounts_linked');
                            $accountsLinkedText = str_replace([':count', ':max'], [$linkedAccountsCount, $maxAccounts], $accountsLinkedText);
                            echo $accountsLinkedText;
                        ?></strong>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center;">
                <div style="max-width: 700px; margin: 0 auto 1.5rem;">
                    <div class="sp-card" style="max-width: 600px; margin: 0 auto 1rem;">
                        <div class="sp-card-header">
                            <div class="sp-card-title">
                                <i class="fas fa-music" style="color: var(--green);"></i>
                                <?php echo t('spotifylink_available_features'); ?>
                            </div>
                        </div>
                        <div class="sp-card-body">
                            <ul style="color: var(--text-secondary); list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; text-align: left;">
                                <li><?php echo t('spotify_feature_current_song'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!song</code></li>
                                <li><?php echo t('spotify_feature_song_request'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!songrequest [song title] [artist]</code> (<?php echo t('spotify_feature_or'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!sr</code>)</li>
                                <li><?php echo t('spotify_feature_example'); ?> <code style="background: var(--bg-input); color: var(--text-primary); padding: 2px 6px; border-radius: var(--radius-sm);">!songrequest Stick Season Noah Kahan</code></li>
                            </ul>
                        </div>
                    </div>
                    <p style="color: var(--text-secondary);">
                        <strong><?php
                            $accountsLinkedText = t('spotify_accounts_linked');
                            $accountsLinkedText = str_replace([':count', ':max'], [$linkedAccountsCount, $maxAccounts], $accountsLinkedText);
                            echo $accountsLinkedText;
                        ?></strong>
                    </p>
                </div>
                <?php if ($authURL && $connectionStatus !== 'pending'): ?>
                    <a href="<?php echo $authURL; ?>" class="sp-btn sp-btn-success" style="font-size: 1rem; padding: 0.75rem 1.75rem;">
                        <i class="fab fa-spotify"></i>
                        <?php echo t('spotify_link_button'); ?>
                    </a>
                <?php elseif ($isActAsUser): ?>
                    <div class="sp-alert sp-alert-warning" style="max-width: 700px; margin: 0 auto;">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo t('spotifylink_actas_disabled'); ?>
                    </div>
                <?php elseif (!$authURL && $connectionStatus === 'not-connected' && $own_client == 0): ?>
                    <div class="sp-alert sp-alert-danger" style="max-width: 700px; margin: 0 auto;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo str_replace([':count', ':max'], [$linkedAccountsCount, $maxAccounts], t('spotifylink_capacity_full')); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include "layout.php";
?>