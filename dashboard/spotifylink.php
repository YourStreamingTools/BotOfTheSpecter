<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('spotify_link_page_title'); 

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/spotify.php";
include '/var/www/config/twitch.php';
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
if (isset($_GET['code'])) {
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
        die("Failed to contact Spotify. Please check your API credentials and network connection.");
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
        $message = "Your Spotify account has been successfully linked!";
        $messageType = "is-success";
    } else {
        $message = "Failed to retrieve tokens from Spotify. Please try again.";
        $messageType = "is-danger";
    }
}

// Handle POST requests for own client settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['use_own_client'])) {
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
            $message = "Please follow the linking instructions above this error panel. (Error: User is not authorized.)";
            $messageType = "is-danger";
            // Set authorization URL to trigger reauthorization
            $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
            $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$effective_client_id&scope=$scopes&redirect_uri=$redirect_uri";
            $connectionStatus = 'error';
        }
    } else {
        // Pending approval
        $message = "Your Spotify link is pending approval.";
        $messageType = "is-warning";
        $connectionStatus = 'pending';
    }
} else {
    // User not linked, set authorization URL
    $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
    $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$client_id&scope=$scopes&redirect_uri=$redirect_uri";
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
$maxAccounts = 25;

// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fab fa-spotify"></i></span>
                    <?php echo t('spotify_link_page_title'); ?>
                </span>
                <?php if ($connectionStatus === 'connected'): ?>
                    <div class="card-header-icon">
                        <span class="tag is-success is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon mr-1"><i class="fas fa-check-circle"></i></span>
                            <?php echo t('spotify_connected_title'); ?>
                        </span>
                    </div>
                <?php elseif ($connectionStatus === 'pending'): ?>
                    <div class="card-header-icon">
                        <span class="tag is-warning is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon mr-1"><i class="fas fa-clock"></i></span>
                            Pending Link
                        </span>
                    </div>
                <?php else: ?>
                    <div class="card-header-icon">
                        <span class="tag is-danger is-medium" style="border-radius: 6px; font-weight: 600;">
                            <span class="icon mr-1"><i class="fas fa-times-circle"></i></span>
                            Not Connected
                        </span>
                    </div>
                <?php endif; ?>
            </header>
            <div class="card-content">
                <?php if ($message): ?>
                    <div class="notification <?php echo $messageType === 'is-success' ? 'is-success' : ($messageType === 'is-danger' ? 'is-danger' : 'is-info'); ?> is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
                        <span class="icon">
                            <?php if ($messageType === 'is-danger'): ?>
                                <i class="fas fa-exclamation-triangle"></i>
                            <?php elseif ($messageType === 'is-success'): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="fas fa-info-circle"></i>
                            <?php endif; ?>
                        </span>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>
                <div class="box has-background-grey-darker" style="border-radius: 8px; border: 1px solid #363636; margin-bottom: 1.5rem;">
                    <h4 class="title is-6 has-text-white mb-3">
                        <span class="icon mr-2 has-text-info"><i class="fas fa-cogs"></i></span>
                        Use Your Own Spotify Client
                    </h4>
                    <p class="has-text-grey-light mb-3">If you prefer to use your own Spotify application instead of waiting for authorization, enable this option. You'll need to create your own Spotify app and enter the credentials below.</p>
                    <a href="https://help.botofthespecter.com/spotify_setup.php" target="_blank" class="button is-info is-small" style="margin-bottom: 1rem;">
                        <span class="icon"><i class="fas fa-external-link-alt"></i></span>
                        <span>Get Setup Instructions</span>
                    </a>
                    <form method="post">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox has-text-white">
                                    <input type="checkbox" name="use_own_client" <?php echo $own_client == 1 ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    Enable Own Client
                                </label>
                            </div>
                        </div>
                        <?php if ($own_client == 1): ?>
                            <div class="field">
                                <label class="label has-text-white">Client ID</label>
                                <div class="control">
                                    <input class="input" type="text" name="client_id" value="<?php echo htmlspecialchars($user_client_id); ?>" placeholder="Your Spotify Client ID">
                                </div>
                            </div>
                            <div class="field">
                                <label class="label has-text-white">Client Secret</label>
                                <div class="control">
                                    <input class="input" type="password" name="client_secret" value="<?php echo htmlspecialchars($user_client_secret); ?>" placeholder="Your Spotify Client Secret">
                                </div>
                            </div>
                            <div class="field">
                                <div class="control">
                                    <button class="button is-success" type="submit" name="save_credentials">Save Credentials</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                <?php if ($connectionStatus === 'connected'): ?>
                    <div class="columns is-multiline is-variable is-6">
                        <div class="column is-12">
                            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
                                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                                    <p class="card-header-title has-text-white" style="font-weight: 600;">
                                        <span class="icon mr-2 has-text-success"><i class="fab fa-spotify"></i></span>
                                        Connected Account Information
                                    </p>
                                </header>
                                <div class="card-content">
                                    <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
                                        <span class="icon"><i class="fas fa-info-circle"></i></span>
                                        <strong><?php echo t('spotify_connected_restart_bot'); ?></strong>
                                    </div>
                                    <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
                                        <span class="icon"><i class="fas fa-link"></i></span>
                                        <strong><?php echo t('spotify_connected_check_link'); ?></strong>
                                    </div>
                                    <div class="box has-background-grey-darker" style="border-radius: 8px; border: 1px solid #363636;">
                                        <h4 class="title is-6 has-text-white mb-3">
                                            <span class="icon mr-2 has-text-success"><i class="fas fa-music"></i></span>
                                            Available Features:
                                        </h4>
                                        <ul class="has-text-grey-light has-text-white">
                                            <li class="mb-2"><?php echo t('spotify_feature_current_song'); ?> <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!song</code></li>
                                            <li class="mb-2"><?php echo t('spotify_feature_song_request'); ?> <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!songrequest [song title] [artist]</code> (or <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!sr</code>)</li>
                                            <li><?php echo t('spotify_feature_example'); ?> <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!songrequest Stick Season Noah Kahan</code></li>
                                        </ul>
                                    </div>
                                    <div class="mt-4">
                                        <p class="has-text-grey-light">
                                            <strong><?php
                                                $accountsLinkedText = t('spotify_accounts_linked');
                                                $accountsLinkedText = str_replace([':count', ':max'], [$linkedAccountsCount, $maxAccounts], $accountsLinkedText);
                                                echo $accountsLinkedText;
                                            ?></strong>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="has-text-centered">
                        <div class="content has-text-white mb-5" style="margin: 0 auto;">
                            <p><?php echo t('spotify_connect_instructions'); ?><br><?php echo t('spotify_connect_after_request'); ?></p>
                            <div class="box has-background-grey-darker has-text-centered" style="max-width: 600px; margin: 0 auto; border-radius: 8px; border: 1px solid #363636;">
                                <h4 class="title is-6 has-text-white mb-3">
                                    <span class="icon mr-2 has-text-success"><i class="fas fa-music"></i></span>
                                    Available Features:
                                </h4>
                                <ul class="has-text-left has-text-white">
                                    <li class="mb-2"><?php echo t('spotify_feature_current_song'); ?> <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!song</code></li>
                                    <li class="mb-2"><?php echo t('spotify_feature_song_request'); ?> <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!songrequest [song title] [artist]</code> (or <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!sr</code>)</li>
                                    <li><?php echo t('spotify_feature_example'); ?> <code class="has-background-grey-dark has-text-white" style="padding: 2px 6px; border-radius: 4px;">!songrequest Stick Season Noah Kahan</code></li>
                                </ul>
                            </div>
                            <p class="mt-4">
                                <strong><?php
                                    $accountsLinkedText = t('spotify_accounts_linked');
                                    $accountsLinkedText = str_replace([':count', ':max'], [$linkedAccountsCount, $maxAccounts], $accountsLinkedText);
                                    echo $accountsLinkedText;
                                ?></strong>
                            </p>
                        </div>
                        <?php if ($authURL && $connectionStatus !== 'pending'): ?>
                            <a href="<?php echo $authURL; ?>" class="button is-success is-large" style="border-radius: 8px; font-weight: 600;">
                                <span class="icon"><i class="fab fa-spotify"></i></span>
                                <span><?php echo t('spotify_link_button'); ?></span>
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
include "layout.php";
?>