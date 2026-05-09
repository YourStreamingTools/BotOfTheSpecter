<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$pageTitle = t('modules_title');
$current_blacklist = [];

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

// Get active tab from URL parameter or default to first tab
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : ($cookieConsent && isset($_COOKIE['preferred_tab']) ? $_COOKIE['preferred_tab'] : 'joke-blacklist');

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Helper: resolve a Twitch username to its user ID via Helix API (for custom module bot)
function resolveModuleBotTwitchUserId($username) {
    global $clientID, $authToken;
    $username = trim($username);
    if ($username === '') return [false, 'Bot username cannot be empty.'];
    $url = 'https://api.twitch.tv/helix/users?login=' . urlencode($username);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $clientID,
        'Authorization: Bearer ' . $authToken,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        return [false, 'Twitch API error: ' . ($err ?: "HTTP {$code}")];
    }
    $data = json_decode($resp, true);
    if (!isset($data['data'][0]['id'])) {
        return [false, 'Twitch user not found.'];
    }
    return [$data['data'][0]['id'], null];
}

// AJAX: resolve module bot Twitch username to ID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_module_bot_id') {
    ob_clean();
    header('Content-Type: application/json');
    $botName = trim($_POST['bot_username'] ?? '');
    if ($botName === '') {
        echo json_encode(['success' => false, 'error' => 'Bot username cannot be empty.']);
        exit();
    }
    [$resolvedId, $resolveErr] = resolveModuleBotTwitchUserId($botName);
    if ($resolvedId === false) {
        echo json_encode(['success' => false, 'error' => $resolveErr]);
        exit();
    }
    echo json_encode(['success' => true, 'bot_id' => $resolvedId]);
    exit();
}

// Handle add a new module bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_module_bot') {
    session_start(); // Reopen session for flash messages
    $botName = trim($_POST['bot_username'] ?? '');
    $botId   = trim($_POST['bot_channel_id'] ?? '');
    if ($botName === '') {
        $_SESSION['update_message'] = 'Please provide a bot username.';
        header("Location: ?tab=custom-module-bot");
        exit();
    }
    // Auto-resolve ID if not provided
    if ($botId === '') {
        [$resolvedId, $resolveErr] = resolveModuleBotTwitchUserId($botName);
        if ($resolvedId === false) {
            $_SESSION['update_message'] = $resolveErr;
            header("Location: ?tab=custom-module-bot");
            exit();
        }
        $botId = $resolvedId;
    }
    // Prevent duplicate bot username for this channel
    $dupStmt = $conn->prepare("SELECT id FROM custom_module_bots WHERE channel_id = ? AND bot_username = ? LIMIT 1");
    $isDupe = false;
    if ($dupStmt) {
        $dupStmt->bind_param('is', $user_id, $botName);
        $dupStmt->execute();
        $dupStmt->store_result();
        $isDupe = $dupStmt->num_rows > 0;
        $dupStmt->close();
    }
    if ($isDupe) {
        $_SESSION['update_message'] = 'That bot is already linked to your channel.';
        header("Location: ?tab=custom-module-bot");
        exit();
    }
    $stmt = $conn->prepare("INSERT INTO custom_module_bots (channel_id, bot_username, bot_channel_id, is_verified, access_token, token_expires, refresh_token) VALUES (?, ?, ?, 0, '', NULL, NULL)");
    if ($stmt) {
        $stmt->bind_param('iss', $user_id, $botName, $botId);
        $stmt->execute();
    }
    $_SESSION['update_message'] = 'Module bot added. Please verify it using the link below.';
    header("Location: ?tab=custom-module-bot");
    exit();
}

// Handle remove a module bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_module_bot') {
    session_start(); // Reopen session for flash messages
    $recordId = intval($_POST['record_id'] ?? 0);
    if ($recordId > 0) {
        $stmt = $conn->prepare("DELETE FROM custom_module_bots WHERE id = ? AND channel_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $recordId, $user_id);
            $stmt->execute();
        }
        $_SESSION['update_message'] = 'Module bot removed.';
    }
    header("Location: ?tab=custom-module-bot");
    exit();
}

// Load all module bots for this channel
$moduleBots = [];
$mbStmt = $conn->prepare("SELECT id, bot_username, bot_channel_id, is_verified FROM custom_module_bots WHERE channel_id = ? ORDER BY bot_username ASC");
if ($mbStmt) {
    $mbStmt->bind_param('i', $user_id);
    $mbStmt->execute();
    $mbRes = $mbStmt->get_result();
    while ($row = $mbRes->fetch_assoc()) {
        $moduleBots[] = $row;
    }
    $mbStmt->close();
}

// Always load the current blacklist from the database before rendering the form
if (!isset($current_blacklist) || !is_array($current_blacklist) || empty($current_blacklist)) {
    $stmt = $db->prepare("SELECT blacklist FROM joke_settings");
    $stmt->execute();
    $stmt->bind_result($blacklist_str);
    if ($stmt->fetch() && $blacklist_str) {
        $current_blacklist = json_decode($blacklist_str, true);
        if (!is_array($current_blacklist))
            $current_blacklist = [];
    } else {
        $current_blacklist = [];
    }
    $stmt->close();
}

// Load joke command status from builtin_commands table
$joke_command_status = 'Enabled'; // Default value
$stmt = $db->prepare("SELECT status FROM builtin_commands WHERE command = 'joke'");
$stmt->execute();
$stmt->bind_result($joke_status);
if ($stmt->fetch()) {
    $joke_command_status = $joke_status;
}
$stmt->close();

// Handle joke command status update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_joke_command'])) {
    session_start(); // Reopen session for flash messages
    $new_status = $_POST['joke_command_status'];
    $stmt = $db->prepare("UPDATE builtin_commands SET status = ? WHERE command = 'joke'");
    $stmt->bind_param('s', $new_status);
    $stmt->execute();
    $stmt->close();
    $_SESSION['update_message'] = t('modules_joke_command_status_updated');
    header("Location: ?tab=joke-blacklist");
    exit();
}

// Load welcome message settings from the database before rendering the form
// Use explicit column selection and correct order for binding
$stmt = $db->prepare("SELECT 
    new_default_welcome_message,
    default_welcome_message,
    new_default_vip_welcome_message,
    default_vip_welcome_message,
    new_default_mod_welcome_message,
    default_mod_welcome_message,
    send_welcome_messages
    FROM streamer_preferences
    LIMIT 1");
$stmt->execute();
$stmt->bind_result(
    $new_default_welcome_message,
    $default_welcome_message,
    $new_default_vip_welcome_message,
    $default_vip_welcome_message,
    $new_default_mod_welcome_message,
    $default_mod_welcome_message,
    $send_welcome_messages
);
$stmt->fetch();
$stmt->close();

// Load ad notice settings from the database before rendering the form
// Default values in case the fetch fails or columns don't exist yet
$ad_upcoming_message = '';
$ad_1min_message = '';
$ad_start_message = '';
$ad_end_message = '';
$ad_snoozed_message = '';
$enable_ad_notice = 0;
$enable_upcoming_ad_message = 1;
$enable_1min_ad_message = 0;
$enable_start_ad_message = 1;
$enable_end_ad_message = 1;
$enable_snoozed_ad_message = 1;
$enable_ai_ad_breaks = 0;

$stmt = $db->prepare("SELECT ad_upcoming_message, ad_1min_message, ad_start_message, ad_end_message, ad_snoozed_message, enable_ad_notice, enable_upcoming_ad_message, enable_1min_ad_message, enable_start_ad_message, enable_end_ad_message, enable_snoozed_ad_message, enable_ai_ad_breaks FROM ad_notice_settings LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result(
        $fetched_upcoming,
        $fetched_1min,
        $fetched_start,
        $fetched_end,
        $fetched_snoozed,
        $fetched_enable_global,
        $fetched_enable_upcoming,
        $fetched_enable_1min,
        $fetched_enable_start,
        $fetched_enable_end,
        $fetched_enable_snoozed,
        $fetched_enable_ai
    );
    if ($stmt->fetch()) {
        $ad_upcoming_message = $fetched_upcoming;
        $ad_1min_message = $fetched_1min;
        $ad_start_message = $fetched_start;
        $ad_end_message = $fetched_end;
        $ad_snoozed_message = $fetched_snoozed;
        $enable_ad_notice = $fetched_enable_global;
        $enable_upcoming_ad_message = $fetched_enable_upcoming;
        $enable_1min_ad_message = $fetched_enable_1min;
        $enable_start_ad_message = $fetched_enable_start;
        $enable_end_ad_message = $fetched_enable_end;
        $enable_snoozed_ad_message = $fetched_enable_snoozed;
        $enable_ai_ad_breaks = $fetched_enable_ai;
    }
    $stmt->close();
}

// Load Twitch audio alerts settings from the database before rendering the form
$twitchSoundAlertMappings = [];
// Use the correct upload path for Twitch sound alerts
$twitch_sound_alert_path = "/var/www/soundalerts/$username/twitch";
// Load mappings: file => event
$stmt = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
$stmt->execute();
$stmt->bind_result($file_name, $twitch_event);
while ($stmt->fetch()) {
    $twitchSoundAlertMappings[$file_name] = $twitch_event;
}
$stmt->close();

// Load Twitch chat alerts settings from the database before rendering the form
$default_chat_alerts = [
    'follower_alert' => 'Thank you (user) for following! Welcome to the channel!',
    'cheer_alert' => 'Thank you (user) for (bits) bits! You\'ve given a total of (total-bits) bits.',
    'raid_alert' => 'Incredible! (user) and (viewers) viewers have joined the party! Let\'s give them a warm welcome!',
    'subscription_alert' => 'Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!',
    'gift_subscription_alert' => 'Thank you (user) for gifting a (tier) subscription to (count) members! You have gifted a total of (total-gifted) to the community!',
    'hype_train_start' => 'The Hype Train has started! Starting at level: (level)',
    'hype_train_end' => 'The Hype Train has ended at level (level)!',
    'gift_paid_upgrade' => 'Thank you (user) for upgrading from a Gifted Sub to a paid (tier) subscription!',
    'prime_paid_upgrade' => 'Thank you (user) for upgrading from Prime Gaming to a paid (tier) subscription!',
    'pay_it_forward' => 'Thank you (user) for paying it forward! They received a (tier) gift from (gifter) and gifted a (tier) subscription in return!'
];
$chat_alerts = [];
$stmt = $db->prepare("SELECT alert_type, alert_message FROM twitch_chat_alerts");
$stmt->execute();
$stmt->bind_result($alert_type, $alert_message);
while ($stmt->fetch()) {
    $chat_alerts[$alert_type] = $alert_message;
}
$stmt->close();

// Merge with defaults - if database value is empty or not set, use default
foreach ($default_chat_alerts as $type => $default_message) {
    if (!isset($chat_alerts[$type]) || trim($chat_alerts[$type]) === '') {
        $chat_alerts[$type] = $default_message;
    }
}

// Load ignored games from the database before rendering the form
$ignored_games = [];
$stmt = $db->prepare("SELECT game_name FROM game_deaths_settings");
$stmt->execute();
$stmt->bind_result($game_name);
while ($stmt->fetch()) {
    $ignored_games[] = $game_name;
}
$stmt->close();

// Load automated shoutout settings from the database
$automated_shoutout_cooldown = 60; // Default
$stmt = $db->prepare("SELECT cooldown_minutes FROM automated_shoutout_settings LIMIT 1");
$stmt->execute();
$stmt->bind_result($automated_shoutout_cooldown);
$stmt->fetch();
$stmt->close();

// Load automated shoutout tracking from the database
$automated_shoutout_tracking = [];
$stmt = $db->prepare("SELECT user_id, user_name, shoutout_time FROM automated_shoutout_tracking ORDER BY shoutout_time DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $automated_shoutout_tracking[] = $row;
}
$stmt->close();

// Load TTS settings from the database
$tts_voice = 'Alloy'; // Default voice
$tts_language = 'en'; // Default language
$stmt = $db->prepare("SELECT voice, language FROM tts_settings LIMIT 1");
$stmt->execute();
$stmt->bind_result($tts_voice_db, $tts_language_db);
if ($stmt->fetch()) {
    if (!empty($tts_voice_db))
        $tts_voice = $tts_voice_db;
    if (!empty($tts_language_db))
        $tts_language = $tts_language_db;
}
$stmt->close();

// Load protection settings
$currentSettings = 'False';
$termBlockingSettings = 'False';
$blockFirstMessageCommands = 'False';
$blockFirstMessageCommandMode = 'all';
$blockFirstMessageSelectedCommands = [];
$availableBlockFirstMessageCommands = [];
$getProtection = $db->query("SELECT url_blocking, term_blocking, block_first_message_commands, block_first_message_command_mode, block_first_message_selected_commands FROM protection LIMIT 1");
if ($getProtection) {
    $settings = $getProtection->fetch_assoc();
    $currentSettings = isset($settings['url_blocking']) ? $settings['url_blocking'] : 'False';
    $termBlockingSettings = isset($settings['term_blocking']) ? $settings['term_blocking'] : 'False';
    $blockFirstMessageCommands = isset($settings['block_first_message_commands']) ? $settings['block_first_message_commands'] : 'False';
    $blockFirstMessageCommandMode = isset($settings['block_first_message_command_mode']) && $settings['block_first_message_command_mode'] === 'selected' ? 'selected' : 'all';
    $selectedCommandsRaw = isset($settings['block_first_message_selected_commands']) ? $settings['block_first_message_selected_commands'] : '[]';
    $decodedSelectedCommands = json_decode($selectedCommandsRaw, true);
    if (is_array($decodedSelectedCommands)) {
        foreach ($decodedSelectedCommands as $cmd) {
            $normalizedCmd = ltrim(strtolower(trim((string) $cmd)), '!');
            if ($normalizedCmd !== '') {
                $blockFirstMessageSelectedCommands[$normalizedCmd] = true;
            }
        }
    }
    $getProtection->free();
}

$commandOptionsResult = $db->query("SELECT command FROM builtin_commands UNION SELECT command FROM custom_commands UNION SELECT command FROM custom_user_commands");
if ($commandOptionsResult) {
    while ($row = $commandOptionsResult->fetch_assoc()) {
        $cmd = ltrim(strtolower(trim((string) ($row['command'] ?? ''))), '!');
        if ($cmd !== '') {
            $availableBlockFirstMessageCommands[$cmd] = $cmd;
        }
    }
    $commandOptionsResult->free();
    ksort($availableBlockFirstMessageCommands, SORT_NATURAL | SORT_FLAG_CASE);
}

// Fetch whitelist and blacklist links
$whitelistLinks = [];
$blacklistLinks = [];
$getWhitelist = $db->query("SELECT link FROM link_whitelist");
if ($getWhitelist) {
    while ($row = $getWhitelist->fetch_assoc()) {
        $whitelistLinks[] = $row;
    }
    $getWhitelist->free();
}

$getBlacklist = $db->query("SELECT link FROM link_blacklisting");
if ($getBlacklist) {
    while ($row = $getBlacklist->fetch_assoc()) {
        $blacklistLinks[] = $row;
    }
    $getBlacklist->free();
}

// Fetch blocked terms
$blockedTerms = [];
$getBlockedTerms = $db->query("SELECT term FROM blocked_terms");
if ($getBlockedTerms) {
    while ($row = $getBlockedTerms->fetch_assoc()) {
        $blockedTerms[] = $row;
    }
    $getBlockedTerms->free();
}

// Start output buffering for layout
ob_start();
?>
<!-- Module Variables Notification -->
<div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;">
    <div style="display:flex; align-items:flex-start; gap:1rem;">
        <i class="fas fa-code fa-2x" style="flex-shrink:0; margin-top:0.2rem;"></i>
        <div>
            <p style="font-weight:700; margin-bottom:0.5rem;">Variables for Modules</p>
            <p style="margin-bottom:0.5rem;">Use variables in your Welcome Messages, Ad Notices, and Twitch Chat Alerts to create
                dynamic, personalized messages for your community.</p>
            <p style="margin-bottom:0.5rem;"><strong>What are Module Variables?</strong>
                <br>Variables are placeholders that get replaced with real information when the message is sent.
                <br>For example, <code>(user)</code> becomes the viewer's username, and <code>(bits)</code> shows the
                number of bits cheered.
            </p>
            <p style="margin-bottom:0.5rem;"><strong>Available Variables:</strong>
                <br>Each module has specific variables you can use - from usernames and viewer counts to subscription
                tiers and hype train levels.
            </p>
            <a href="https://help.botofthespecter.com/specter_module_variables.php" target="_blank"
                class="sp-btn sp-btn-primary sp-btn-sm">
                <i class="fas fa-code"></i>
                <span>View All Module Variables</span>
            </a>
        </div>
    </div>
</div>
<!-- Tabs Navigation -->
<ul class="sp-tabs-nav" style="flex-wrap:wrap; margin-bottom:1.25rem;">
    <li class="is-active" data-tab="joke-blacklist">
        <a><i class="fas fa-ban"></i><span><?php echo t('modules_tab_joke_blacklist'); ?></span></a>
    </li>
    <li data-tab="welcome-messages">
        <a><i class="fas fa-hand-sparkles"></i><span><?php echo t('modules_tab_welcome_messages'); ?></span></a>
    </li>
    <li data-tab="chat-protection">
        <a><i class="fas fa-shield-alt"></i><span><?php echo t('modules_tab_chat_protection'); ?></span></a>
    </li>
    <li data-tab="game-deaths">
        <a><i class="fas fa-skull-crossbones"></i><span>Game Deaths</span></a>
    </li>
    <li data-tab="ad-notices">
        <a><i class="fas fa-bullhorn"></i><span><?php echo t('modules_tab_ad_notices'); ?></span></a>
    </li>
    <li data-tab="twitch-audio-alerts">
        <a><i class="fas fa-volume-up"></i><span><?php echo t('modules_tab_twitch_event_alerts'); ?></span></a>
    </li>
    <li data-tab="twitch-chat-alerts">
        <a><i class="fas fa-comment-dots"></i><span><?php echo t('modules_tab_twitch_chat_alerts'); ?></span></a>
    </li>
    <li data-tab="automated-shoutouts">
        <a><i class="fas fa-bullhorn"></i><span>Automated Shoutouts</span></a>
    </li>
    <li data-tab="tts-settings">
        <a><i class="fas fa-microphone"></i><span>TTS Settings</span></a>
    </li>
    <li data-tab="custom-module-bot">
        <a><i class="fas fa-robot"></i><span>Custom Module Bots</span></a>
    </li>
</ul>
<div class="sp-card">
    <header class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-cogs"></i>
            <?php echo t('modules_title'); ?>
        </span>
    </header>
    <div class="sp-card-body">
                <?php if (!empty($_SESSION['update_message'])): ?>
                    <div class="sp-alert sp-alert-success" style="margin-bottom:1rem;">
                        <?php echo $_SESSION['update_message'];
                        unset($_SESSION['update_message']); ?>
                    </div>
                <?php endif; ?>
                <!-- Tab Contents -->
                <div>
                    <div class="tab-content" id="joke-blacklist">
                        <div class="module-container">
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1rem;">
                                <div>
                                    <h2 style="font-size:1.25rem; font-weight:700; color:var(--text-primary); margin-bottom:0.5rem;">
                                        <i class="fas fa-ban" style="color:var(--red);"></i>
                                        <?php echo t('modules_joke_blacklist_title'); ?>
                                    </h2>
                                    <p style="color:var(--red); font-size:0.9rem;">
                                        <?php echo t('modules_joke_blacklist_subtitle'); ?>
                                    </p>
                                </div>
                                <div>
                                    <!-- Joke Command Status Control -->
                                    <div class="sp-card" style="padding:0.75rem; min-width:420px;">
                                        <div style="display:flex; align-items:center; justify-content:center; gap:0.75rem; flex-wrap:wrap;">
                                            <span class="sp-badge sp-badge-grey">
                                                <i class="fas fa-terminal"></i>
                                                Joke Command
                                            </span>
                                            <span class="sp-badge <?php echo ($joke_command_status == 'Enabled') ? 'sp-badge-green' : 'sp-badge-red'; ?>">
                                                <?php echo ($joke_command_status == 'Enabled') ? t('builtin_commands_status_enabled') : t('builtin_commands_status_disabled'); ?>
                                            </span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="toggle_joke_command" value="1">
                                                <input type="hidden" name="joke_command_status"
                                                    value="<?php echo ($joke_command_status == 'Enabled') ? 'Disabled' : 'Enabled'; ?>">
                                                <button type="submit"
                                                    class="sp-btn sp-btn-sm <?php echo ($joke_command_status == 'Enabled') ? 'sp-btn-danger' : 'sp-btn-success'; ?>">
                                                    <i class="fas <?php echo ($joke_command_status == 'Enabled') ? 'fa-times' : 'fa-check'; ?>"></i>
                                                    <span><?php echo ($joke_command_status == 'Enabled') ? t('builtin_commands_disable') : t('builtin_commands_enable'); ?></span>
                                                </button>
                                            </form>
                                        </div>
                                        <p style="color:var(--text-muted); font-size:0.78rem; text-align:center; margin-top:0.5rem; margin-bottom:0;">
                                            <?php echo t('modules_joke_command_control_description'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:0.5rem; margin-bottom:1rem;">
                                    <!-- All joke categories -->
                                    <?php
                                    $joke_categories = [
                                        "Miscellaneous" => "modules_joke_miscellaneous",
                                        "Coding" => "modules_joke_coding",
                                        "Development" => "modules_joke_development",
                                        "Halloween" => "modules_joke_halloween",
                                        "Pun" => "modules_joke_pun",
                                        "nsfw" => "modules_joke_nsfw",
                                        "religious" => "modules_joke_religious",
                                        "political" => "modules_joke_political",
                                        "racist" => "modules_joke_racist",
                                        "sexist" => "modules_joke_sexist",
                                        "dark" => "modules_joke_dark",
                                        "explicit" => "modules_joke_explicit",
                                    ];
                                    foreach ($joke_categories as $cat_value => $cat_label_key):
                                        ?>
                                        <div>
                                            <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; color:var(--text-primary);">
                                                <input type="checkbox" name="blacklist[]"
                                                    value="<?php echo $cat_value; ?>" <?php echo (is_array($current_blacklist) && in_array($cat_value, $current_blacklist)) ? " checked" : ""; ?>>
                                                <?php echo t($cat_label_key); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="sp-btn sp-btn-primary" type="submit"><?php echo t('modules_save_blacklist_settings'); ?></button>
                            </form>
                        </div>
                    </div>
                    <div class="tab-content" id="welcome-messages">
                        <div class="module-container">
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1rem;">
                                <div>
                                    <h2 style="font-size:1.25rem; font-weight:700; color:var(--text-primary); margin-bottom:0.5rem;">
                                        <i class="fas fa-cog"></i>
                                        Welcome Message Configuration
                                    </h2>
                                </div>
                                <div>
                                    <!-- Welcome Messages Status Control -->
                                    <div class="sp-card" style="padding:0.75rem; min-width:420px;">
                                        <div style="display:flex; align-items:center; justify-content:center; gap:0.75rem; flex-wrap:wrap;">
                                            <span class="sp-badge sp-badge-grey">
                                                <i class="fas fa-comment"></i>
                                                Welcome Messages
                                            </span>
                                            <span class="sp-badge <?php echo ($send_welcome_messages) ? 'sp-badge-green' : 'sp-badge-red'; ?>">
                                                <?php echo ($send_welcome_messages) ? 'Enabled' : 'Disabled'; ?>
                                            </span>
                                            <form method="POST" action="module_data_post.php" style="display:inline;">
                                                <input type="hidden" name="toggle_welcome_messages" value="1">
                                                <input type="hidden" name="welcome_messages_status" value="<?php echo ($send_welcome_messages) ? '0' : '1'; ?>">
                                                <button type="submit" class="sp-btn sp-btn-sm <?php echo ($send_welcome_messages) ? 'sp-btn-danger' : 'sp-btn-success'; ?>">
                                                    <i class="fas <?php echo ($send_welcome_messages) ? 'fa-times' : 'fa-check'; ?>"></i>
                                                    <span><?php echo ($send_welcome_messages) ? 'Disable' : 'Enable'; ?></span>
                                                </button>
                                            </form>
                                        </div>
                                        <p style="color:var(--text-muted); font-size:0.78rem; text-align:center; margin-top:0.5rem; margin-bottom:0;">
                                            Toggle automatic welcome messages for viewers
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                    <!-- Regular Members Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-users"></i>
                                                Regular Members
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="regular-members">
                                                <i class="fas fa-save"></i>
                                                <span>Save Regular Members</span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-plus"></i>
                                                <?php echo t('modules_welcome_new_member_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="new_default_welcome_message" maxlength="255"
                                                value="<?php echo htmlspecialchars($new_default_welcome_message !== '' ? $new_default_welcome_message : t('modules_welcome_new_member_default')); ?>">
                                            <p class="field-help"><span class="char-count" data-field="new_default_welcome_message">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo t('modules_welcome_returning_member_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="default_welcome_message" maxlength="255"
                                                value="<?php echo htmlspecialchars($default_welcome_message !== '' ? $default_welcome_message : t('modules_welcome_returning_member_default')); ?>">
                                            <p class="field-help"><span class="char-count" data-field="default_welcome_message">0</span>/255 characters</p>
                                        </div>
                                    </div>
                                    <!-- VIP Members Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-gem"></i>
                                                VIP Members
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="vip-members">
                                                <i class="fas fa-save"></i>
                                                <span>Save VIP Members</span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-plus"></i>
                                                <?php echo t('modules_welcome_new_vip_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="new_default_vip_welcome_message" maxlength="255"
                                                value="<?php echo htmlspecialchars($new_default_vip_welcome_message !== '' ? $new_default_vip_welcome_message : t('modules_welcome_new_vip_default')); ?>">
                                            <p class="field-help"><span class="char-count" data-field="new_default_vip_welcome_message">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo t('modules_welcome_returning_vip_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="default_vip_welcome_message" maxlength="255"
                                                value="<?php echo htmlspecialchars($default_vip_welcome_message !== '' ? $default_vip_welcome_message : t('modules_welcome_returning_vip_default')); ?>">
                                            <p class="field-help"><span class="char-count" data-field="default_vip_welcome_message">0</span>/255 characters</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Moderators -->
                                <div>
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                        <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                            <i class="fas fa-shield-alt"></i>
                                            Moderators
                                        </h5>
                                        <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="moderators">
                                            <i class="fas fa-save"></i>
                                            <span>Save Moderators</span>
                                        </button>
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-plus"></i>
                                                <?php echo t('modules_welcome_new_mod_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="new_default_mod_welcome_message" maxlength="255"
                                                value="<?php echo htmlspecialchars($new_default_mod_welcome_message !== '' ? $new_default_mod_welcome_message : t('modules_welcome_new_mod_default')); ?>">
                                            <p class="field-help"><span class="char-count" data-field="new_default_mod_welcome_message">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo t('modules_welcome_returning_mod_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="default_mod_welcome_message" maxlength="255"
                                                value="<?php echo htmlspecialchars($default_mod_welcome_message !== '' ? $default_mod_welcome_message : t('modules_welcome_returning_mod_default')); ?>">
                                            <p class="field-help"><span class="char-count" data-field="default_mod_welcome_message">0</span>/255 characters</p>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="tab-content" id="chat-protection">
                        <div class="module-container">
                            <!-- Chat Protection Configuration -->
                            <h2 style="font-size:1.4rem; font-weight:700; color:var(--text-primary); margin-bottom:1rem;">
                                <i class="fas fa-shield-alt" style="color:var(--blue);"></i>
                                <?php echo t('protection_title'); ?>
                            </h2>
                            <!-- URL Blocking System Information (Version 5.8) -->
                            <div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;">
                                <h4 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>URL Blocking System Overview (Version 5.8)</strong>
                                </h4>
                                <p><strong>How URL Blocking Works:</strong></p>
                                <ul>
                                    <li>
                                        <strong style="color:var(--red);"><i class="fas fa-ban"></i> Blacklist (Always Active):</strong>
                                        URLs in the blacklist are <strong>ALWAYS blocked</strong> regardless of URL Blocking setting.
                                        Triggers a "Code Red" alert to moderators when detected.
                                    </li>
                                    <li>
                                        <strong style="color:var(--accent);"><i class="fas fa-toggle-on"></i> URL Blocking Enabled:</strong>
                                        Removes all links from chat <strong>except</strong>:
                                        <ul>
                                            <li>URLs matching whitelist regex patterns</li>
                                            <li>Twitch.tv and clips.twitch.tv links</li>
                                            <li>Messages from mods/streamers (bypass)</li>
                                        </ul>
                                    </li>
                                    <li>
                                        <strong style="color: #00947e;"><i class="fas fa-toggle-off"></i> URL Blocking Disabled:</strong>
                                        Allows all URLs in chat <strong>except</strong> blacklisted ones.
                                    </li>
                                    <li>
                                        <strong style="color: #00947e;"><i class="fas fa-check-circle"></i> Whitelist Supports Regex:</strong>
                                        Use regular expressions for flexible pattern matching (e.g., <code>.*\.youtube\.com</code> for all YouTube subdomains).
                                    </li>
                                </ul>
                                <p style="margin-top:0.75rem; margin-bottom:0;">
                                    <i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>
                                    <strong>Important:</strong> Moderators and streamers can post URLs even when URL Blocking is enabled.
                                </p>
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                <!-- URL Blocking Settings -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-link-slash" style="color:var(--accent);"></i>
                                            <?php echo t('protection_enable_url_blocking'); ?>
                                        </h3>
                                        <form action="module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <select class="sp-select" name="url_blocking" id="url_blocking">
                                                    <option value="True"<?php echo $currentSettings == 'True' ? ' selected' :'';?>><?php echo t('yes'); ?></option>
                                                    <option value="False"<?php echo $currentSettings == 'False' ? ' selected' :'';?>><?php echo t('no'); ?></option>
                                                </select>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-primary" style="width:100%;">
                                                    <i class="fas fa-save"></i>
                                                    <span><?php echo t('protection_update_btn'); ?></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <!-- Block first-message commands Settings -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-user-lock" style="color:var(--accent);"></i>
                                            <?php echo t('protection_block_first_message_commands'); ?>
                                            <span class="sp-badge sp-badge-amber" style="margin-left:0.5rem;">BETA 5.8</span>
                                        </h3>
                                        <form action="module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <select class="sp-select" name="block_first_message_commands" id="block_first_message_commands">
                                                    <option value="True"<?php echo $blockFirstMessageCommands == 'True' ? ' selected' :'';?>><?php echo t('yes'); ?></option>
                                                    <option value="False"<?php echo $blockFirstMessageCommands == 'False' ? ' selected' :'';?>><?php echo t('no'); ?></option>
                                                </select>
                                            </div>
                                            <div class="sp-form-group" style="margin-top:1rem;">
                                                <label class="sp-label">Blocking Mode</label>
                                                <select class="sp-select" name="block_first_message_command_mode" id="block_first_message_command_mode">
                                                    <option value="all"<?php echo $blockFirstMessageCommandMode === 'all' ? ' selected' : ''; ?>>Allow for all commands</option>
                                                    <option value="selected"<?php echo $blockFirstMessageCommandMode === 'selected' ? ' selected' : ''; ?>>Allow for selected commands only</option>
                                                </select>
                                            </div>
                                            <div class="sp-form-group" id="block-first-message-selected-wrapper" style="margin-top:1rem;<?php echo ($blockFirstMessageCommands === 'True' && $blockFirstMessageCommandMode === 'selected') ? '' : 'display:none;'; ?>">
                                                <label class="sp-label">Commands to block until user has chatted</label>
                                                <select class="sp-select" name="block_first_message_selected_commands[]" id="block_first_message_selected_commands" multiple size="10">
                                                    <?php foreach ($availableBlockFirstMessageCommands as $cmd): ?>
                                                        <option value="<?php echo htmlspecialchars($cmd); ?>"<?php echo isset($blockFirstMessageSelectedCommands[$cmd]) ? ' selected' : ''; ?>><?php echo htmlspecialchars('!' . $cmd); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <p class="field-help">Includes built-in and custom commands. Hold Ctrl (Windows) or Cmd (Mac) to select multiple commands.</p>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-primary" style="width:100%;">
                                                    <i class="fas fa-save"></i>
                                                    <span><?php echo t('protection_update_btn'); ?></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <!-- Whitelist Link Form -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-check-circle" style="color:var(--green);"></i>
                                            <?php echo t('protection_enter_link_whitelist'); ?>
                                        </h3>
                                        <form action="module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <input class="sp-input" type="text" name="whitelist_link" id="whitelist_link" placeholder="<?php echo t('protection_enter_url_placeholder'); ?>" required>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-info" style="width:100%;">
                                                    <i class="fas fa-plus-circle"></i>
                                                    <span><?php echo t('protection_add_to_whitelist'); ?></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <!-- Blacklist Link Form -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-ban" style="color:var(--red);"></i>
                                            <?php echo t('protection_enter_link_blacklist'); ?>
                                        </h3>
                                        <form action="module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <input class="sp-input" type="text" name="blacklist_link" id="blacklist_link" placeholder="<?php echo t('protection_enter_url_placeholder'); ?>" required>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-danger" style="width:100%;">
                                                    <i class="fas fa-minus-circle"></i>
                                                    <span><?php echo t('protection_add_to_blacklist'); ?></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <!-- Whitelist and Blacklist Tables -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">
                                            <i class="fas fa-list-ul" style="color:var(--green);"></i>
                                            <?php echo t('protection_whitelist_links'); ?>
                                        </h3>
                                        <div class="sp-table-wrap">
                                            <table class="sp-table">
                                                <tbody>
                                                    <?php if (empty($whitelistLinks)): ?>
                                                        <tr>
                                                            <td colspan="2" style="text-align:center; color:var(--text-muted);">
                                                                <i class="fas fa-info-circle"></i>
                                                                No whitelisted links configured
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($whitelistLinks as $link): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($link['link']); ?></td>
                                                                <td style="text-align:right;">
                                                                    <form action="module_data_post.php" method="post" style="display:inline;">
                                                                        <input type="hidden" name="remove_whitelist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                                                        <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm">
                                                                            <i class="fas fa-trash-alt"></i>
                                                                            <span><?php echo t('protection_remove'); ?></span>
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">
                                            <i class="fas fa-list-ul" style="color:var(--red);"></i>
                                            <?php echo t('protection_blacklist_links'); ?>
                                        </h3>
                                        <div class="sp-table-wrap">
                                            <table class="sp-table">
                                                <tbody>
                                                    <?php if (empty($blacklistLinks)): ?>
                                                        <tr>
                                                            <td colspan="2" style="text-align:center; color:var(--text-muted);">
                                                                <i class="fas fa-info-circle"></i>
                                                                No blacklisted links configured
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($blacklistLinks as $link): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($link['link']); ?></td>
                                                                <td style="text-align:right;">
                                                                    <form action="module_data_post.php" method="post" style="display:inline;">
                                                                        <input type="hidden" name="remove_blacklist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                                                        <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm">
                                                                            <i class="fas fa-trash-alt"></i>
                                                                            <span><?php echo t('protection_remove'); ?></span>
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Term Blocking Section (Beta) -->
                        <div class="module-container" style="margin-top:2rem;">
                            <h2 style="font-size:1.4rem; font-weight:700; color:var(--text-primary); margin-bottom:1rem;">
                                <i class="fas fa-comment-slash" style="color:var(--amber);"></i>
                                Text Term Blocking
                                <span class="sp-badge sp-badge-amber" style="margin-left:0.75rem;">Beta - Version 5.8</span>
                            </h2>
                            <!-- Term Blocking Information -->
                            <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
                                <h4 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">
                                    <i class="fas fa-flask"></i>
                                    <strong>Term Blocking System (Beta Feature)</strong>
                                </h4>
                                <p><strong>How Term Blocking Works:</strong></p>
                                <ul>
                                    <li>
                                        <strong style="color:var(--red);"><i class="fas fa-ban"></i> Blocked Terms:</strong>
                                        Messages containing blocked terms will be automatically deleted from chat.
                                    </li>
                                    <li>
                                        <strong style="color:var(--accent);"><i class="fas fa-toggle-on"></i> When Enabled:</strong>
                                        Bot will scan all chat messages for blocked terms and remove matching messages instantly.
                                    </li>
                                    <li>
                                        <strong style="color: #00947e;"><i class="fas fa-shield-alt"></i> Case-Insensitive:</strong>
                                        Term matching is case-insensitive (e.g., "badword", "BADWORD", "BadWord" all match).
                                    </li>
                                </ul>
                                <p style="margin-top:0.75rem; margin-bottom:0;">
                                    <i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>
                                    <strong>Note:</strong> This feature is in beta testing. Report any issues via feedback.
                                </p>
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                <!-- Term Blocking Settings -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-comment-slash" style="color:var(--amber);"></i>
                                            Enable Term Blocking
                                        </h3>
                                        <form action="module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <select class="sp-select" name="term_blocking" id="term_blocking">
                                                    <option value="True"<?php echo $termBlockingSettings == 'True' ? ' selected' : ''; ?>><?php echo t('yes'); ?></option>
                                                    <option value="False"<?php echo $termBlockingSettings == 'False' ? ' selected' : ''; ?>><?php echo t('no'); ?></option>
                                                </select>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-primary" style="width:100%;">
                                                    <i class="fas fa-save"></i>
                                                    <span><?php echo t('protection_update_btn'); ?></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <!-- Add Blocked Term Form -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-ban" style="color:var(--red);"></i>
                                            Add Blocked Term
                                        </h3>
                                        <form action="module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <input class="sp-input" type="text" name="blocked_term" id="blocked_term" placeholder="Enter term to block..." required>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-danger" style="width:100%;">
                                                    <i class="fas fa-minus-circle"></i>
                                                    <span>Add to Blocked Terms</span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <!-- Blocked Terms Table (full-width) -->
                                <div class="sp-card" style="grid-column: 1 / -1;">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">
                                            <i class="fas fa-list-ul" style="color:var(--red);"></i>
                                            Blocked Terms List
                                        </h3>
                                        <div class="sp-table-wrap">
                                            <table class="sp-table">
                                                <tbody>
                                                    <?php if (empty($blockedTerms)): ?>
                                                        <tr>
                                                            <td colspan="2" style="text-align:center; color:var(--text-muted);">
                                                                <i class="fas fa-info-circle"></i>
                                                                No blocked terms configured
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($blockedTerms as $term): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($term['term']); ?></td>
                                                                <td style="text-align:right;">
                                                                    <form action="module_data_post.php" method="post" style="display:inline;">
                                                                        <input type="hidden" name="remove_blocked_term" value="<?php echo htmlspecialchars($term['term']); ?>">
                                                                        <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm">
                                                                            <i class="fas fa-trash-alt"></i>
                                                                            <span>Remove</span>
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <br>
                    </div>
                    <div class="tab-content" id="game-deaths">
                        <div class="module-container">
                            <div style="display:flex; align-items:center; margin-bottom:1rem;">
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0;">
                                    <i class="fas fa-skull-crossbones"></i>
                                    Game Deaths Configuration
                                </h2>
                            </div>
                            <!-- Configuration Note -->
                            <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Game Deaths Configuration:</strong><br>
                                Configure games to ignore when counting deaths.<br>
                                Deaths in these games will not be added to the total death counter
                                for the !deathadd command.
                            </div>
                            <!-- Add Game Form -->
                            <form method="POST" action="module_data_post.php" style="margin-bottom:1rem;">
                                <div style="display:flex; gap:0.5rem;">
                                    <input class="sp-input" type="text" name="ignore_game_name"
                                        placeholder="Enter game name to ignore (e.g., Minecraft, Fortnite)"
                                        maxlength="100" required style="flex:1;">
                                    <button class="sp-btn sp-btn-primary" type="submit" name="add_ignored_game">
                                        <i class="fas fa-plus"></i>
                                        <span>Add Game</span>
                                    </button>
                                </div>
                            </form>
                            <!-- Current Ignored Games -->
                            <h4 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
                                <i class="fas fa-list"></i>
                                Currently Ignored Games
                            </h4>
                            <div>
                                <?php
                                if (!empty($ignored_games)) {
                                    echo '<div style="display:flex; flex-wrap:wrap; gap:0.5rem;">';
                                    foreach ($ignored_games as $game) {
                                        echo '<span class="sp-badge sp-badge-red" style="font-size:0.9rem; padding:0.3rem 0.6rem;">';
                                        echo htmlspecialchars($game);
                                        echo '<button type="button" onclick="removeIgnoredGame(\'' . htmlspecialchars(addslashes($game)) . '\')" style="background:none; border:none; cursor:pointer; color:inherit; margin-left:0.4rem; font-size:0.85rem;">&times;</button>';
                                        echo '</span>';
                                    }
                                    echo '</div>';
                                } else {
                                    echo '<p style="color:var(--text-muted);">No games are currently being ignored.</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <!-- Ad Notices -->
                    <div class="tab-content" id="ad-notices">
                        <div class="module-container">
                            <div style="display:flex; align-items:center; margin-bottom:1rem;">
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0;">
                                    <i class="fas fa-cog"></i>
                                    Ad Notice Messages
                                </h2>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                    <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                        <i class="fas fa-bullhorn"></i>
                                        Advertisement Messages
                                    </h5>
                                </div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                    <div class="sp-form-group">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                            <label class="sp-label" style="margin:0;">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <?php echo t('modules_ad_upcoming_message'); ?>
                                            </label>
                                            <label for="enable_upcoming_ad_message" style="cursor: pointer;">
                                                <input id="enable_upcoming_ad_message" type="checkbox"
                                                    name="enable_upcoming_ad_message" value="1"
                                                    <?php echo (!empty($enable_upcoming_ad_message) ? 'checked' : ''); ?>
                                                    style="display: none;">
                                                <i class="fas fa-toggle-<?php echo (!empty($enable_upcoming_ad_message) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_upcoming_ad_message) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_upcoming_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_upcoming_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_upcoming_message ?? ''); ?></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_upcoming_message">0</span>/255 characters
                                        </p>
                                    </div>
                                    <div class="sp-form-group">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                            <label class="sp-label" style="margin:0;">
                                                <i class="fas fa-hourglass-half"></i>
                                                <?php echo t('modules_ad_1min_message'); ?>
                                            </label>
                                            <label for="enable_1min_ad_message" style="cursor: pointer;">
                                                <input id="enable_1min_ad_message" type="checkbox"
                                                    name="enable_1min_ad_message" value="1"
                                                    <?php echo (!empty($enable_1min_ad_message) ? 'checked' : ''); ?>
                                                    style="display: none;">
                                                <i class="fas fa-toggle-<?php echo (!empty($enable_1min_ad_message) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_1min_ad_message) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_1min_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_1min_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_1min_message ?? ''); ?></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_1min_message">0</span>/255 characters
                                        </p>
                                    </div>
                                    <div class="sp-form-group">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                            <label class="sp-label" style="margin:0;">
                                                <i class="fas fa-play"></i>
                                                <?php echo t('modules_ad_start_message'); ?>
                                            </label>
                                            <label for="enable_start_ad_message" style="cursor: pointer;">
                                                <input id="enable_start_ad_message" type="checkbox"
                                                    name="enable_start_ad_message" value="1"
                                                    <?php echo (!empty($enable_start_ad_message) ? 'checked' : ''); ?>
                                                    style="display: none;">
                                                <i class="fas fa-toggle-<?php echo (!empty($enable_start_ad_message) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_start_ad_message) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_start_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_start_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_start_message ?? ''); ?></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_start_message">0</span>/255 characters
                                        </p>
                                    </div>
                                    <div class="sp-form-group">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                            <label class="sp-label" style="margin:0;">
                                                <i class="fas fa-stop"></i>
                                                <?php echo t('modules_ad_end_message'); ?>
                                            </label>
                                            <label for="enable_end_ad_message" style="cursor: pointer;">
                                                <input id="enable_end_ad_message" type="checkbox"
                                                    name="enable_end_ad_message" value="1"
                                                    <?php echo (!empty($enable_end_ad_message) ? 'checked' : ''); ?>
                                                    style="display: none;">
                                                <i class="fas fa-toggle-<?php echo (!empty($enable_end_ad_message) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_end_ad_message) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_end_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_end_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_end_message ?? ''); ?></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_end_message">0</span>/255 characters
                                        </p>
                                    </div>
                                </div>
                                <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                    <p><strong>Note:</strong> The Ad Snoozed Message may be delayed in chat due to polling intervals.
                                        Additionally, there is a known issue where an extra warning message after the ad is snoozed does not
                                        post. This will be fixed in the next release.</p>
                                </div>
                                <div class="sp-form-group" style="margin-bottom:1rem;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                        <label class="sp-label" style="margin:0;">
                                            <i class="fas fa-clock"></i>
                                            <?php echo t('modules_ad_snoozed_message'); ?>
                                        </label>
                                        <label for="enable_snoozed_ad_message" style="cursor: pointer;">
                                            <input id="enable_snoozed_ad_message" type="checkbox"
                                                name="enable_snoozed_ad_message" value="1"
                                                <?php echo (!empty($enable_snoozed_ad_message) ? 'checked' : ''); ?>
                                                style="display: none;">
                                            <i class="fas fa-toggle-<?php echo (!empty($enable_snoozed_ad_message) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_snoozed_ad_message) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                        </label>
                                    </div>
                                    <textarea class="sp-textarea ad-notice-input" name="ad_snoozed_message"
                                        maxlength="255" placeholder="<?php echo t('modules_ad_snoozed_message_placeholder'); ?>"
                                        rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_snoozed_message ?? ''); ?></textarea>
                                    <p class="field-help">
                                        <span class="char-count" data-field="ad_snoozed_message">0</span>/255 characters
                                    </p>
                                </div>
                                <!-- Enable/Disable Toggle -->
                                <div class="sp-form-group" style="margin-top:1rem;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div>
                                            <label class="sp-label">
                                                <i class="fas fa-toggle-on"></i>
                                                <?php echo t('modules_enable_ad_notice'); ?>
                                            </label>
                                            <p class="field-help">
                                                Toggle this switch to enable or disable advertisement notices in your stream chat.
                                            </p>
                                        </div>
                                        <label for="enable_ad_notice" style="cursor: pointer;">
                                            <input id="enable_ad_notice" type="checkbox" name="enable_ad_notice"
                                                value="1" <?php echo (!empty($enable_ad_notice) ? 'checked' : ''); ?>
                                                style="display: none;">
                                            <i class="fas fa-toggle-<?php echo (!empty($enable_ad_notice) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_ad_notice) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                        </label>
                                    </div>
                                </div>
                                <!-- AI Ad Breaks Toggle -->
                                <div class="sp-form-group" style="margin-top:1rem;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div>
                                            <label class="sp-label">
                                                <i class="fas fa-robot"></i>
                                                Enable AI-Powered Ad Break Messages
                                            </label>
                                            <p class="field-help">
                                                <span class="sp-badge sp-badge-amber" style="margin-right:0.5rem;">Premium Feature</span>
                                                When enabled, the bot will use AI to generate dynamic, context-aware ad break
                                                messages based on recent chat activity. Requires Tier 2 subscription or higher.
                                            </p>
                                        </div>
                                        <label for="enable_ai_ad_breaks" style="cursor: pointer;">
                                            <input id="enable_ai_ad_breaks" type="checkbox" name="enable_ai_ad_breaks"
                                                value="1" <?php echo (!empty($enable_ai_ad_breaks) ? 'checked' : ''); ?>
                                                style="display: none;">
                                            <i class="fas fa-toggle-<?php echo (!empty($enable_ai_ad_breaks) ? 'on' : 'off'); ?> fa-2x" style="color:<?php echo (!empty($enable_ai_ad_breaks) ? 'var(--green)' : 'var(--text-muted)'); ?>;"></i>
                                        </label>
                                    </div>
                                </div>
                                <!-- Save Button -->
                                <div style="margin-top:1.5rem;">
                                    <button class="sp-btn sp-btn-primary" type="submit">
                                        <i class="fas fa-save"></i>
                                        <span><?php echo t('modules_save_ad_notice_settings'); ?></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Twitch Event Alerts -->
                    <div class="tab-content" id="twitch-audio-alerts">
                        <div class="module-container">
                            <!-- Upload Card -->
                            <div class="sp-card" style="margin-bottom:1.5rem;">
                                <div class="sp-card-header">
                                    <span class="sp-card-title">
                                        <i class="fas fa-upload"></i>
                                        <?php echo t('modules_upload_mp3_files'); ?>
                                    </span>
                                </div>
                                <div class="sp-card-body">
                                    <!-- Storage Usage Info -->
                                    <div class="sp-card" style="margin-bottom:1rem; background:var(--bg-card-hover);">
                                        <div class="sp-card-body" style="padding:0.75rem;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                                <span><i class="fas fa-database"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                                                <span><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)</span>
                                            </div>
                                            <progress style="width:100%; height:0.75rem;" value="<?php echo $storage_percentage; ?>" max="100"></progress>
                                        </div>
                                    </div>
                                    <?php if (!empty($status)): ?>
                                        <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                            <?php echo $status; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form action="module_data_post.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                                        <!-- Custom drag/drop file input -->
                                        <div id="drag-area" style="border:2px dashed var(--border); border-radius:var(--radius); padding:2rem; text-align:center; cursor:pointer; background:var(--bg-card-hover); margin-bottom:1rem; transition:border-color 0.2s;">
                                            <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:var(--text-muted); display:block; margin-bottom:0.5rem;"></i>
                                            <span style="color:var(--text-secondary);"><?php echo t('modules_choose_mp3_files'); ?></span>
                                            <input class="sp-input" type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3" style="display:none;">
                                            <div id="file-list" style="margin-top:0.5rem; color:var(--text-muted); font-size:0.85rem;"><?php echo t('modules_no_files_selected'); ?></div>
                                        </div>
                                        <!-- Upload Status Container -->
                                        <div id="uploadStatusContainer" style="display: none; margin-bottom:1rem;">
                                            <div class="sp-alert sp-alert-info">
                                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                                    <strong id="uploadStatusText">Preparing upload...</strong>
                                                    <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                                                </div>
                                                <progress id="uploadProgress" value="0" max="100" style="width:100%; height:1.5rem; border-radius:0.75rem;">0%</progress>
                                            </div>
                                        </div>
                                        <button class="sp-btn sp-btn-primary" type="submit" name="submit" id="uploadBtn" style="width:100%; font-weight:600; font-size:1.1rem;">
                                            <i class="fas fa-upload"></i>
                                            <span id="uploadBtnText"><?php echo t('modules_upload_mp3_files'); ?></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="sp-card">
                                <div class="sp-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <span class="sp-card-title">
                                        <i class="fas fa-volume-up"></i>
                                        <?php echo t('modules_your_twitch_sound_alerts'); ?>
                                    </span>
                                    <div>
                                        <button class="sp-btn sp-btn-danger" id="deleteSelectedBtn" disabled>
                                            <i class="fas fa-trash"></i>
                                            <span><?php echo t('modules_delete_selected'); ?></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="sp-card-body">
                                    <?php $walkon_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..'));
                                    if (!empty($walkon_files)): ?>
                                        <form action="module_data_post.php" method="POST" id="deleteForm">
                                            <div class="sp-table-wrap">
                                                <table class="sp-table" id="twitchAlertsTable">
                                                    <thead>
                                                        <tr>
                                                            <th style="width:70px; text-align:center;"><?php echo t('modules_select'); ?></th>
                                                            <th style="text-align:center;"><?php echo t('modules_file_name'); ?></th>
                                                            <th style="text-align:center;"><?php echo t('modules_twitch_event'); ?></th>
                                                            <th style="width:80px; text-align:center;"><?php echo t('modules_action'); ?></th>
                                                            <th style="width:120px; text-align:center;"><?php echo t('modules_test_audio'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($walkon_files as $file): ?>
                                                            <tr>
                                                                <td style="text-align:center;"><input
                                                                        type="checkbox"
                                                                        name="delete_files[]"
                                                                        value="<?php echo htmlspecialchars($file); ?>">
                                                                </td>
                                                                <td>
                                                                    <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>
                                                                </td>
                                                                <td style="text-align:center;">
                                                                    <?php
                                                                    $current_mapped = isset($twitchSoundAlertMappings[$file]) ? $twitchSoundAlertMappings[$file] : null;
                                                                    $mappedEvents = [];
                                                                    foreach ($twitchSoundAlertMappings as $mappedFile => $mappedEvent) {
                                                                        if ($mappedFile !== $file && $mappedEvent) {
                                                                            $mappedEvents[] = $mappedEvent;
                                                                        }
                                                                    }
                                                                    $allEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'Hype Train Start', 'Hype Train End'];
                                                                    $availableEvents = array_diff($allEvents, $mappedEvents);
                                                                    ?>
                                                                    <?php if ($current_mapped): ?>
                                                                        <em><?php echo t('modules_event_' . strtolower(str_replace(' ', '_', $current_mapped))); ?></em>
                                                                    <?php else: ?>
                                                                        <em><?php echo t('modules_not_mapped'); ?></em>
                                                                    <?php endif; ?>
                                                                    <form action="module_data_post.php" method="POST" class="mapping-form" style="margin-top:0.5rem;">
                                                                        <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                                                        <select name="twitch_alert_id" class="sp-select mapping-select">
                                                                            <?php if ($current_mapped): ?>
                                                                                <option value=""><?php echo t('modules_remove_mapping'); ?></option>
                                                                            <?php endif; ?>
                                                                            <option value=""><?php echo t('modules_select_event'); ?></option>
                                                                            <?php
                                                                            foreach ($allEvents as $evt) {
                                                                                $isMapped = in_array($evt, $mappedEvents);
                                                                                $isCurrent = ($current_mapped === $evt);
                                                                                if ($isMapped && !$isCurrent) continue;
                                                                                echo '<option value="' . htmlspecialchars($evt) . '"';
                                                                                if ($isCurrent) echo ' selected';
                                                                                echo '>' . t('modules_event_' . strtolower(str_replace(' ', '_', $evt))) . '</option>';
                                                                            }
                                                                            ?>
                                                                        </select>
                                                                    </form>
                                                                </td>
                                                                <td style="text-align:center;">
                                                                    <button type="button"
                                                                        class="delete-single sp-btn sp-btn-danger sp-btn-sm"
                                                                        data-file="<?php echo htmlspecialchars($file); ?>">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </td>
                                                                <td style="text-align:center;">
                                                                    <button type="button"
                                                                        class="test-sound sp-btn sp-btn-primary sp-btn-sm"
                                                                        data-file="twitch/<?php echo htmlspecialchars($file); ?>">
                                                                        <i class="fas fa-play"></i>
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <button type="submit" value="Delete Selected"
                                                class="sp-btn sp-btn-danger" name="submit_delete" style="margin-top:0.75rem; display:none;">
                                                <i class="fas fa-trash"></i>
                                                <span><?php echo t('modules_delete_selected'); ?></span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div style="text-align:center; padding:2.5rem 1rem;">
                                            <h2 style="font-size:1rem; color:var(--text-muted);">
                                                <?php echo t('modules_no_sound_alert_files_uploaded'); ?>
                                            </h2>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Twitch Chat Alerts -->
                    <div class="tab-content" id="twitch-chat-alerts">
                        <div class="module-container">
                            <div style="display:flex; align-items:center; margin-bottom:1rem;">
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0;">
                                    <i class="fas fa-cog"></i>
                                    Chat Alert Messages
                                </h2>
                            </div>
                            <form action="module_data_post.php" method="POST" id="chatAlertsForm">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                    <!-- General Events Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-users"></i>
                                                General Events
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="general">
                                                <i class="fas fa-save"></i>
                                                <span>Save General Events</span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-heart"></i>
                                                <?php echo t('modules_follower_alert'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="follower_alert" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['follower_alert']) ? $chat_alerts['follower_alert'] : $default_chat_alerts['follower_alert']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="follower_alert">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-gem"></i>
                                                <?php echo t('modules_cheer_alert'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="cheer_alert" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['cheer_alert']) ? $chat_alerts['cheer_alert'] : $default_chat_alerts['cheer_alert']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="cheer_alert">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-friends"></i>
                                                <?php echo t('modules_raid_alert'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="raid_alert" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['raid_alert']) ? $chat_alerts['raid_alert'] : $default_chat_alerts['raid_alert']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="raid_alert">0</span>/255 characters</p>
                                        </div>
                                    </div>
                                    <!-- Subscription Events Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-star"></i>
                                                Subscription Events
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="subscription">
                                                <i class="fas fa-save"></i>
                                                <span>Save Subscription Events</span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-star"></i>
                                                <?php echo t('modules_subscription_alert'); ?>
                                                <span class="sp-badge sp-badge-red" style="font-size:0.7rem;">*</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="subscription_alert" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['subscription_alert']) ? $chat_alerts['subscription_alert'] : $default_chat_alerts['subscription_alert']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="subscription_alert">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-gift"></i>
                                                <?php echo t('modules_gift_subscription_alert'); ?>
                                                <span class="sp-badge sp-badge-red" style="font-size:0.7rem;">*</span>
                                                <i class="fas fa-info-circle" style="color:var(--amber);" title="This message uses the user variable to thank the person who sent the gift, not the recipients."></i>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="gift_subscription_alert" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['gift_subscription_alert']) ? $chat_alerts['gift_subscription_alert'] : $default_chat_alerts['gift_subscription_alert']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="gift_subscription_alert">0</span>/255 characters</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hype Train Events (Full Width) -->
                                <div style="margin-bottom:1.5rem;">
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                        <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                            <i class="fas fa-train"></i>
                                            Hype Train Events
                                        </h5>
                                        <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="hype-train">
                                            <i class="fas fa-save"></i>
                                            <span>Save Hype Train Events</span>
                                        </button>
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-play"></i>
                                                <?php echo t('modules_hype_train_start'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="hype_train_start" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['hype_train_start']) ? $chat_alerts['hype_train_start'] : $default_chat_alerts['hype_train_start']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="hype_train_start">0</span>/255 characters</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-stop"></i>
                                                <?php echo t('modules_hype_train_end'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="hype_train_end" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['hype_train_end']) ? $chat_alerts['hype_train_end'] : $default_chat_alerts['hype_train_end']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="hype_train_end">0</span>/255 characters</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- BETA Features Section -->
                                <div>
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                        <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                            <i class="fas fa-flask"></i>
                                            BETA Features
                                        </h5>
                                        <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="beta">
                                            <i class="fas fa-save"></i>
                                            <span>Save BETA Features</span>
                                        </button>
                                    </div>
                                    <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
                                        <i class="fas fa-flask"></i>
                                        <strong>BETA Features:</strong> These subscription upgrade alerts are currently in beta testing. They use the new Twitch EventSub "Chat Notification" system.
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-arrow-up"></i>
                                                Gift Paid Upgrade <span class="sp-badge sp-badge-amber" style="font-size:0.7rem;">BETA</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="gift_paid_upgrade" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['gift_paid_upgrade']) ? $chat_alerts['gift_paid_upgrade'] : $default_chat_alerts['gift_paid_upgrade']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="gift_paid_upgrade">0</span>/255 characters. Placeholders: (user), (tier)</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-arrow-up"></i>
                                                Prime Paid Upgrade <span class="sp-badge sp-badge-amber" style="font-size:0.7rem;">BETA</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="prime_paid_upgrade" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['prime_paid_upgrade']) ? $chat_alerts['prime_paid_upgrade'] : $default_chat_alerts['prime_paid_upgrade']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="prime_paid_upgrade">0</span>/255 characters. Placeholders: (user), (tier)</p>
                                        </div>
                                        <div class="sp-form-group" style="grid-column: 1 / -1;">
                                            <label class="sp-label">
                                                <i class="fas fa-gift"></i>
                                                Pay It Forward <span class="sp-badge sp-badge-amber" style="font-size:0.7rem;">BETA</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="pay_it_forward" maxlength="255"
                                                value="<?php echo htmlspecialchars(isset($chat_alerts['pay_it_forward']) ? $chat_alerts['pay_it_forward'] : $default_chat_alerts['pay_it_forward']); ?>">
                                            <p class="field-help"><span class="char-count" data-field="pay_it_forward">0</span>/255 characters. Placeholders: (user), (tier), (gifter)</p>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Automated Shoutouts -->
                    <div class="tab-content" id="automated-shoutouts">
                        <div class="module-container">
                            <div style="display:flex; align-items:center; margin-bottom:1rem;">
                                <div>
                                    <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0 0 0.25rem;">
                                        <i class="fas fa-bullhorn" style="color:var(--blue);"></i>
                                        Automated Shoutouts
                                    </h2>
                                    <p style="color:var(--text-muted); margin:0;">Manage cooldown settings for automated shoutouts triggered by raids, follows, cheers, subs, and welcome messages.</p>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div class="sp-card" style="margin-bottom:1.5rem;">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                            <i class="fas fa-clock" style="color:var(--amber);"></i>
                                            Cooldown Settings
                                        </h3>
                                        <div class="sp-form-group">
                                            <label class="sp-label">Automated Shoutout Cooldown (minutes)</label>
                                            <input class="sp-input" type="number" name="cooldown_minutes"
                                                value="<?php echo htmlspecialchars($automated_shoutout_cooldown); ?>"
                                                min="60" required>
                                            <p class="field-help">Minimum 60 minutes due to Twitch API rate limits. This prevents the same user from receiving multiple automated shoutouts within the cooldown period.</p>
                                        </div>
                                        <button type="submit" class="sp-btn sp-btn-success">
                                            <i class="fas fa-save"></i>
                                            <span>Save Cooldown Settings</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <!-- Active Cooldowns -->
                            <div class="sp-card">
                                <div class="sp-card-body">
                                    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 0.5rem;">
                                        <i class="fas fa-hourglass-half" style="color:var(--red);"></i>
                                        Users on Cooldown
                                    </h3>
                                    <p style="color:var(--text-muted); margin:0 0 1rem;">These users recently received an automated shoutout and are currently in the cooldown period. The cooldown resets when the stream goes offline or the timer above is reached.</p>
                                    <div class="sp-alert sp-alert-info"<?php echo !empty($automated_shoutout_tracking) ? ' style="display:none;"' : ''; ?>>
                                        <i class="fas fa-info-circle"></i>
                                        No users are currently on automated shoutout cooldown.
                                    </div>
                                    <div class="sp-table-wrap"<?php echo empty($automated_shoutout_tracking) ? ' style="display:none;"' : ''; ?>>
                                        <table class="sp-table">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Last Shoutout</th>
                                                    <th>Cooldown Remaining</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($automated_shoutout_tracking as $tracking):
                                                    $shoutout_time = new DateTime($tracking['shoutout_time'], new DateTimeZone($timezone));
                                                    $now = new DateTime('now', new DateTimeZone($timezone));
                                                    $diff = $now->getTimestamp() - $shoutout_time->getTimestamp();
                                                    $cooldown_seconds = $automated_shoutout_cooldown * 60;
                                                    $remaining_seconds = max(0, $cooldown_seconds - $diff);
                                                    $remaining_minutes = ceil($remaining_seconds / 60);
                                                    $is_expired = $remaining_seconds <= 0;
                                                    ?>
                                                    <tr<?php echo $is_expired ? ' style="color:var(--text-muted);"' : ''; ?>>
                                                        <td><?php echo htmlspecialchars($tracking['user_name']); ?></td>
                                                        <td><?php echo $shoutout_time->format('Y-m-d H:i:s'); ?></td>
                                                        <td>
                                                            <?php if ($is_expired): ?>
                                                                <span class="sp-badge sp-badge-green">Ready</span>
                                                            <?php else: ?>
                                                                <span class="sp-badge sp-badge-amber"><?php echo $remaining_minutes; ?> min</span>
                                                            <?php endif; ?>
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
                </div>
                <!-- TTS Settings -->
                <div class="tab-content" id="tts-settings">
                    <div class="module-container">
                        <div style="display:flex; align-items:center; margin-bottom:1rem;">
                            <div>
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0 0 0.25rem;">
                                    <i class="fas fa-microphone" style="color:var(--blue);"></i>
                                    Text-to-Speech (TTS) Settings
                                </h2>
                                <p style="color:var(--text-muted); margin:0;">Configure the voice and language for TTS messages in your channel.</p>
                            </div>
                        </div>
                        <form method="POST" action="module_data_post.php">
                            <div class="sp-card" style="margin-bottom:1.5rem;">
                                <div class="sp-card-body">
                                    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                        <i class="fas fa-cog"></i>
                                        Voice Configuration
                                    </h3>
                                    <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>Need help choosing a voice?</strong><br>
                                        Visit our <a href="https://help.botofthespecter.com/tts_setup.php" target="_blank" style="color:var(--accent);"><strong>TTS Setup Guide</strong></a> to hear voice samples and learn more about each option.
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-volume-up"></i>
                                                TTS Voice
                                            </label>
                                            <select class="sp-select" name="tts_voice" required>
                                                <option value="Alloy" <?php echo ($tts_voice === 'Alloy') ? 'selected' : ''; ?>>Alloy [default]</option>
                                                <option value="Ash" <?php echo ($tts_voice === 'Ash') ? 'selected' : ''; ?>>Ash</option>
                                                <option value="Ballad" <?php echo ($tts_voice === 'Ballad') ? 'selected' : ''; ?>>Ballad</option>
                                                <option value="Coral" <?php echo ($tts_voice === 'Coral') ? 'selected' : ''; ?>>Coral</option>
                                                <option value="Echo" <?php echo ($tts_voice === 'Echo') ? 'selected' : ''; ?>>Echo</option>
                                                <option value="Fable" <?php echo ($tts_voice === 'Fable') ? 'selected' : ''; ?>>Fable</option>
                                                <option value="Nova" <?php echo ($tts_voice === 'Nova') ? 'selected' : ''; ?>>Nova</option>
                                                <option value="Onyx" <?php echo ($tts_voice === 'Onyx') ? 'selected' : ''; ?>>Onyx</option>
                                                <option value="Sage" <?php echo ($tts_voice === 'Sage') ? 'selected' : ''; ?>>Sage</option>
                                                <option value="Shimmer" <?php echo ($tts_voice === 'Shimmer') ? 'selected' : ''; ?>>Shimmer</option>
                                                <option value="Verse" <?php echo ($tts_voice === 'Verse') ? 'selected' : ''; ?>>Verse</option>
                                            </select>
                                            <p class="field-help">Select the voice that will read TTS messages in your channel.</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-globe"></i>
                                                Language
                                            </label>
                                            <select class="sp-select" name="tts_language" required>
                                                <option value="en" <?php echo ($tts_language === 'en') ? 'selected' : ''; ?>>English</option>
                                            </select>
                                            <p class="field-help">Select the language for TTS messages.</p>
                                        </div>
                                    </div>
                                    <div style="margin-top:1rem;">
                                        <button type="submit" class="sp-btn sp-btn-success">
                                            <i class="fas fa-save"></i>
                                            <span>Save TTS Settings</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Custom Module Bots -->
                <div class="tab-content" id="custom-module-bot">
                    <div class="module-container">
                        <div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
                            <div style="display:flex; align-items:flex-start; gap:0.75rem;">
                                <i class="fas fa-info-circle" style="margin-top:0.15rem; flex-shrink:0;"></i>
                                <div>
                                    <strong>What is this feature?</strong>
                                    <p style="margin:0.25rem 0 0;">This feature is intended for channels that have had a <strong>custom module built specifically for their channel</strong> by BotOfTheSpecter and wish to use a separate bot account to send messages for those modules. If you have not had a custom module built for your channel, you do not need to configure anything here. <em>This is entirely optional.</em></p>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; margin-bottom:1rem;">
                            <div>
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0 0 0.25rem;">
                                    <i class="fas fa-robot" style="color:var(--amber);"></i>
                                    Custom Module Bots
                                </h2>
                                <p style="color:var(--text-muted); margin:0;">Link one or more custom bot accounts to your channel. Each bot can be used to send messages for different custom modules.</p>
                            </div>
                        </div>
                        <!-- Add new bot form -->
                        <div class="sp-card" style="margin-bottom:1.5rem;">
                            <div class="sp-card-body">
                                <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                    <i class="fas fa-plus-circle" style="color:var(--green);"></i>
                                    Add a Custom Module Bot
                                </h3>
                                <form method="post" id="custom-module-bot-form">
                                    <input type="hidden" name="action" value="add_module_bot">
                                    <div style="display:grid; grid-template-columns:5fr 7fr; gap:1.5rem; margin-bottom:1rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">Bot Username</label>
                                            <input class="sp-input" type="text" name="bot_username" id="module-bot-username" placeholder="Enter bot Twitch username (without @)" autocomplete="off">
                                            <span id="module-bot-lookup-status" style="display:none;"></span>
                                            <p class="field-help">The Twitch username of the bot account (without @).</p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">Bot ID <span style="color:var(--text-muted); font-weight:normal;">(auto-resolved)</span></label>
                                            <div style="display:flex; gap:0.5rem; margin-bottom:0.3rem;">
                                                <input class="sp-input" style="flex:1;" type="text" name="bot_channel_id" id="module-bot-id" readonly placeholder="Click &quot;Resolve ID&quot; to look up">
                                                <button type="button" class="sp-btn sp-btn-info" id="resolve-module-bot-btn">
                                                    <i class="fas fa-search"></i>
                                                    <span>Resolve ID</span>
                                                </button>
                                            </div>
                                            <p class="field-help">Resolved automatically from the username above.</p>
                                        </div>
                                    </div>
                                    <button type="submit" class="sp-btn sp-btn-success">
                                        <i class="fas fa-plus"></i>
                                        <span>Add Bot</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <!-- Verification instructions -->
                        <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Verification required:</strong> After adding a bot, it must be verified before it can send messages.
                            Open <a href="https://mybot.specterbot.systems/custombot.php" target="_blank" rel="noopener" style="color:var(--accent);"><strong>mybot.specterbot.systems/custombot.php</strong></a>
                            in an <strong>incognito/private window</strong> and sign in with the bot account to complete verification.
                        </div>
                        <!-- Linked bots list -->
                        <div class="sp-card">
                            <div class="sp-card-body">
                                <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                    <i class="fas fa-list" style="color:var(--blue);"></i>
                                    Linked Bots
                                </h3>
                                <?php if (empty($moduleBots)): ?>
                                    <div class="sp-alert sp-alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        No custom module bots linked yet. Use the form above to add one.
                                    </div>
                                <?php else: ?>
                                    <div class="sp-table-wrap">
                                        <table class="sp-table">
                                            <thead>
                                                <tr>
                                                    <th>Bot Username</th>
                                                    <th>Bot ID</th>
                                                    <th>Status</th>
                                                    <th style="text-align:right;">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($moduleBots as $mb): ?>
                                                <tr>
                                                    <td>
                                                        <i class="fas fa-robot"></i>
                                                        <?php echo htmlspecialchars($mb['bot_username']); ?>
                                                    </td>
                                                    <td><code><?php echo htmlspecialchars($mb['bot_channel_id']); ?></code></td>
                                                    <td>
                                                        <?php if (intval($mb['is_verified']) === 1): ?>
                                                            <span class="sp-badge sp-badge-green"><i class="fas fa-check-circle"></i> Verified</span>
                                                        <?php else: ?>
                                                            <span class="sp-badge sp-badge-amber"><i class="fas fa-clock"></i> Pending Verification</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:right;">
                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($mb['bot_username'])); ?>?');">
                                                            <input type="hidden" name="action" value="remove_module_bot">
                                                            <input type="hidden" name="record_id" value="<?php echo intval($mb['id']); ?>">
                                                            <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm">
                                                                <i class="fas fa-trash-alt"></i>
                                                                <span>Remove</span>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // File upload handling
        let dropArea = document.getElementById('drag-area');
        let fileInput = document.getElementById('filesToUpload');
        let fileList = document.getElementById('file-list');
        if (dropArea) {
            dropArea.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('dragging');
            });
            dropArea.addEventListener('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragging');
            });
            dropArea.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragging');
                let dt = e.dataTransfer;
                let files = dt.files;
                fileInput.files = files;
                updateFileList(files);
            });
            dropArea.addEventListener('click', function () {
                fileInput.click();
            });
        }
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                updateFileList(this.files);
                if (this.files.length > 0) {
                    uploadFiles(this.files);
                }
            });
        }
        function updateFileList(files) {
            if (!fileList) return;
            fileList.innerHTML = '';
            for (let i = 0; i < files.length; i++) {
                let fileItem = document.createElement('div');
                fileItem.textContent = files[i].name;
                fileList.appendChild(fileItem);
            }
        }
        function uploadFiles(files) {
            let formData = new FormData();
            for (let i = 0; i < files.length; i++) {
                formData.append('filesToUpload[]', files[i]);
            }
            // Show upload status indicator
            $('#file-list').append('<div class="sp-alert sp-alert-info">Uploading files, please wait...</div>');
            $.ajax({
                url: 'module_data_post.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                xhr: function () {
                    let xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            let percentComplete = (e.loaded / e.total) * 100;
                        }
                    }, false);
                    return xhr;
                },
                success: function (response) {
                    // Check if response is JSON
                    let result;
                    try {
                        if (typeof response === 'string') {
                            result = JSON.parse(response);
                        } else {
                            result = response;
                        }
                        if (result.success) {
                            // Update the progress bar with new storage values
                            if (result.storage_percentage) {
                                $('#uploadProgressBar').css('width', result.storage_percentage + '%');
                                $('#uploadProgressBar').text(Math.round(result.storage_percentage * 100) / 100 + '%');
                            }
                            // Show success message
                            $('#file-list').html('<div class="sp-alert sp-alert-success">Upload completed successfully!</div>');
                            // Reload the page after a short delay
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            $('#file-list').html('<div class="sp-alert sp-alert-danger">Upload failed: ' + (result.status || 'Unknown error') + '</div>');
                        }
                    } catch (e) {
                        console.error("Error parsing response:", e);
                        $('#file-list').html('<div class="sp-alert sp-alert-danger">Error processing server response</div>');
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                    console.error('Response:', jqXHR.responseText);
                    $('#file-list').html('<div class="sp-alert sp-alert-danger">Upload failed: ' + textStatus + '<br>Please check file size limits and try again.</div>');
                }
            });
        }
        // Test sound buttons
        document.querySelectorAll('.test-sound').forEach(function (button) {
            button.addEventListener('click', function () {
                const fileName = this.getAttribute('data-file');
                sendStreamEvent('SOUND_ALERT', fileName);
            });
        });
        // Delete single file buttons
        document.querySelectorAll('.delete-single').forEach(function (button) {
            button.addEventListener('click', function () {
                const fileName = this.getAttribute('data-file');
                if (confirm('Are you sure you want to delete "' + fileName + '"?')) {
                    let form = document.getElementById('deleteForm');
                    let input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'delete_files[]';
                    input.value = fileName;
                    form.appendChild(input);
                    form.submit();
                }
            });
        });
        // Handle delete selected button for Twitch audio alerts
        $('#deleteSelectedBtn').on('click', function () {
            var checkedBoxes = $('input[name="delete_files[]"]:checked');
            if (checkedBoxes.length > 0) {
                if (confirm('Are you sure you want to delete the selected ' + checkedBoxes.length + ' file(s)?')) {
                    $('#deleteForm').submit();
                }
            }
        });
        // Monitor checkbox changes to enable/disable delete button for Twitch audio alerts
        $(document).on('change', 'input[name="delete_files[]"]', function () {
            var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
            $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
        });
        // Update file name display for the file input
        $('#filesToUpload').on('change', function () {
            let files = this.files;
            let fileNames = [];
            for (let i = 0; i < files.length; i++) {
                fileNames.push(files[i].name);
            }
            $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('modules_no_files_selected'); ?>');
        });
        // AJAX upload with progress bar
        $('#uploadForm').on('submit', function (e) {
            e.preventDefault();
            var files = $('#filesToUpload')[0].files;
            if (files.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Files Selected',
                    text: 'Please select at least one file to upload.',
                    confirmButtonColor: '#3273dc'
                });
                return;
            }
            let formData = new FormData(this);
            // Show upload status and update UI
            $('#uploadStatusContainer').show();
            $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> Uploading ' + files.length + ' file(s)...');
            $('#uploadProgressPercent').text('0%');
            $('#uploadProgress').val(0);
            // Update button state
            $('#uploadBtn').prop('disabled', true).removeClass('sp-btn-primary').addClass('sp-btn-loading');
            $('#uploadBtnText').text('Uploading...');
            $.ajax({
                url: 'module_data_post.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                xhr: function () {
                    let xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            let percentComplete = Math.round((e.loaded / e.total) * 100);
                            $('#uploadProgress').val(percentComplete);
                            $('#uploadProgressPercent').text(percentComplete + '%');
                            if (percentComplete < 100) {
                                $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> Uploading... (' + percentComplete + '%)');
                            } else {
                                $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> Processing files on server...');
                            }
                        }
                    }, false);
                    return xhr;
                },
                success: function (response) {
                    $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> Upload completed successfully!');
                    $('#uploadProgressPercent').text('100%');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                    $('#uploadStatusContainer').hide();
                    $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading').addClass('sp-btn-primary');
                    $('#uploadBtnText').text('<?php echo t("modules_upload_mp3_files"); ?>');
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        text: 'An error occurred during upload. Please try again.',
                        confirmButtonColor: '#3273dc'
                    });
                }
            });
        });
        // Add event listener for mapping select boxes
        $('.mapping-select').on('change', function () {
            $(this).closest('form').submit();
        });
        // Character counter for chat alert inputs
        function updateCharCount(input) {
            const currentLength = input.value.length;
            const fieldName = input.getAttribute('name');
            const counter = document.querySelector(`.char-count[data-field="${fieldName}"]`);
            const helpText = counter ? counter.closest('.field-help') : null;
            if (counter && helpText) {
                counter.textContent = currentLength;
                // Calculate percentage of 255 character limit
                const percentage = (currentLength / 255) * 100;
                // Remove existing color classes from help text
                helpText.classList.remove('text-success', 'text-warning', 'text-danger');
                // Apply color based on percentage thresholds to entire help text
                if (percentage >= 91) {
                    helpText.classList.add('text-danger'); // Red for 91-100%
                } else if (percentage >= 81) {
                    helpText.classList.add('text-warning'); // Yellow for 81-90%
                } else {
                    helpText.classList.add('text-success'); // Green for 0-80%
                }
            }
        }
        // Initialize character counters and add event listeners
        document.querySelectorAll('.chat-alert-input').forEach(function (input) {
            // Update counter on page load
            updateCharCount(input);
            // Update counter on input
            input.addEventListener('input', function () {
                updateCharCount(this);
            });
            // Prevent typing beyond 255 characters
            input.addEventListener('keydown', function (e) {
                if (this.value.length >= 255 && e.key !== 'Backspace' && e.key !== 'Delete' && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                }
            });
        });
        // Initialize character counters for ad notice inputs
        document.querySelectorAll('.ad-notice-input').forEach(function (input) {
            // Update counter on page load
            updateCharCount(input);
            // Update counter on input
            input.addEventListener('input', function () {
                updateCharCount(this);
            });
            // Prevent typing beyond 255 characters
            input.addEventListener('keydown', function (e) {
                if (this.value.length >= 255 && e.key !== 'Backspace' && e.key !== 'Delete' && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                }
            });
        });
        // Initialize character counters for welcome message inputs
        document.querySelectorAll('.welcome-message-input').forEach(function (input) {
            updateCharCount(input);
            input.addEventListener('input', function () {
                updateCharCount(this);
            });
            input.addEventListener('keydown', function (e) {
                if (this.value.length >= 255 && e.key !== 'Backspace' && e.key !== 'Delete' && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                }
            });
        });
        // Set initial character counts after DOM is fully loaded
        setTimeout(function () {
            document.querySelectorAll('.chat-alert-input, .ad-notice-input, .welcome-message-input').forEach(function (input) {
                updateCharCount(input);
            });
        }, 100);
        // Save All button feedback
        const saveAllBtn = document.getElementById('save-all-btn');
        if (saveAllBtn) {
            saveAllBtn.addEventListener('click', function (e) {
                // Change button to loading state
                this.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Saving...</span>';
                this.disabled = true;
                // Form will submit naturally since this is type="submit"
            });
        }
        // Add event listener for section save buttons
        document.querySelectorAll('.section-save-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                const section = this.getAttribute('data-section');
                const originalText = this.innerHTML;
                // Change button to loading state
                this.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Saving...</span>';
                this.disabled = true;
                // Collect form data for the specific section
                const formData = new FormData();
                if (section === 'general') {
                    const followerAlert = document.querySelector('input[name="follower_alert"]');
                    const cheerAlert = document.querySelector('input[name="cheer_alert"]');
                    const raidAlert = document.querySelector('input[name="raid_alert"]');
                    if (followerAlert) formData.append('follower_alert', followerAlert.value);
                    if (cheerAlert) formData.append('cheer_alert', cheerAlert.value);
                    if (raidAlert) formData.append('raid_alert', raidAlert.value);
                } else if (section === 'subscription') {
                    const subscriptionAlert = document.querySelector('input[name="subscription_alert"]');
                    const giftSubscriptionAlert = document.querySelector('input[name="gift_subscription_alert"]');
                    if (subscriptionAlert) formData.append('subscription_alert', subscriptionAlert.value);
                    if (giftSubscriptionAlert) formData.append('gift_subscription_alert', giftSubscriptionAlert.value);
                } else if (section === 'hype-train') {
                    const hypeTrainStart = document.querySelector('input[name="hype_train_start"]');
                    const hypeTrainEnd = document.querySelector('input[name="hype_train_end"]');
                    if (hypeTrainStart) formData.append('hype_train_start', hypeTrainStart.value);
                    if (hypeTrainEnd) formData.append('hype_train_end', hypeTrainEnd.value);
                } else if (section === 'regular-members') {
                    const newWelcomeMessage = document.querySelector('input[name="new_default_welcome_message"]');
                    const defaultWelcomeMessage = document.querySelector('input[name="default_welcome_message"]');
                    if (newWelcomeMessage) formData.append('new_default_welcome_message', newWelcomeMessage.value);
                    if (defaultWelcomeMessage) formData.append('default_welcome_message', defaultWelcomeMessage.value);
                } else if (section === 'vip-members') {
                    const newVipWelcomeMessage = document.querySelector('input[name="new_default_vip_welcome_message"]');
                    const defaultVipWelcomeMessage = document.querySelector('input[name="default_vip_welcome_message"]');
                    if (newVipWelcomeMessage) formData.append('new_default_vip_welcome_message', newVipWelcomeMessage.value);
                    if (defaultVipWelcomeMessage) formData.append('default_vip_welcome_message', defaultVipWelcomeMessage.value);
                } else if (section === 'moderators') {
                    const newModWelcomeMessage = document.querySelector('input[name="new_default_mod_welcome_message"]');
                    const defaultModWelcomeMessage = document.querySelector('input[name="default_mod_welcome_message"]');
                    const sendWelcomeMessages = document.querySelector('input[name="send_welcome_messages"]');
                    if (newModWelcomeMessage) formData.append('new_default_mod_welcome_message', newModWelcomeMessage.value);
                    if (defaultModWelcomeMessage) formData.append('default_mod_welcome_message', defaultModWelcomeMessage.value);
                    if (sendWelcomeMessages) formData.append('send_welcome_messages', sendWelcomeMessages.checked ? '1' : '0');
                }
                // Add section identifier
                formData.append('section_save', section);
                // Send AJAX request
                fetch('module_data_post.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.text())
                    .then(data => {
                        // Reset button state
                        this.innerHTML = originalText;
                        this.disabled = false;
                        // Show success feedback
                        this.innerHTML = '<span class="icon"><i class="fas fa-check"></i></span><span>Saved!</span>';
                        this.classList.remove('sp-btn-success');
                        this.classList.add('sp-btn-info');
                        // Reset button after 2 seconds
                        setTimeout(() => {
                            this.innerHTML = originalText;
                            this.classList.remove('sp-btn-info');
                            this.classList.add('sp-btn-success');
                        }, 2000);
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        // Reset button state on error
                        this.innerHTML = originalText;
                        this.disabled = false;
                        // Show error feedback
                        this.innerHTML = '<span class="icon"><i class="fas fa-times"></i></span><span>Error</span>';
                        this.classList.remove('sp-btn-success');
                        this.classList.add('sp-btn-danger');
                        // Reset button after 2 seconds
                        setTimeout(() => {
                            this.innerHTML = originalText;
                            this.classList.remove('sp-btn-danger');
                            this.classList.add('sp-btn-success');
                        }, 2000);
                    });
            });
        });

        const blockFirstMessageCommandsSelect = document.getElementById('block_first_message_commands');
        const blockFirstMessageModeSelect = document.getElementById('block_first_message_command_mode');
        const blockFirstMessageSelectedWrapper = document.getElementById('block-first-message-selected-wrapper');
        const blockFirstMessageSelectedCommands = document.getElementById('block_first_message_selected_commands');

        function toggleFirstMessageCommandSelection() {
            if (!blockFirstMessageCommandsSelect || !blockFirstMessageModeSelect || !blockFirstMessageSelectedWrapper) {
                return;
            }

            const shouldShow = blockFirstMessageCommandsSelect.value === 'True' && blockFirstMessageModeSelect.value === 'selected';
            blockFirstMessageSelectedWrapper.style.display = shouldShow ? '' : 'none';
            if (blockFirstMessageSelectedCommands) {
                blockFirstMessageSelectedCommands.disabled = !shouldShow;
            }
        }

        if (blockFirstMessageCommandsSelect && blockFirstMessageModeSelect) {
            blockFirstMessageCommandsSelect.addEventListener('change', toggleFirstMessageCommandSelection);
            blockFirstMessageModeSelect.addEventListener('change', toggleFirstMessageCommandSelection);
            toggleFirstMessageCommandSelection();
        }
    });
    
    // Font Awesome toggle icon functionality
    document.querySelectorAll('label[for^="enable_"]').forEach(function(label) {
        label.addEventListener('click', function(e) {
            e.preventDefault();
            const checkbox = document.getElementById(this.getAttribute('for'));
            const icon = this.querySelector('i');
            
            if (checkbox && icon) {
                // Toggle checkbox state
                checkbox.checked = !checkbox.checked;
                
                // Update icon
                if (checkbox.checked) {
                    icon.classList.remove('fa-toggle-off');
                    icon.classList.add('fa-toggle-on');
                    icon.style.color = 'var(--green)';
                } else {
                    icon.classList.remove('fa-toggle-on');
                    icon.classList.add('fa-toggle-off');
                    icon.style.color = 'var(--text-muted)';
                }
            }
        });
    });
    
    // Function to send a stream event
    function sendStreamEvent(eventType, fileName) {
        const xhr = new XMLHttpRequest();
        const url = "notify_event.php";
        const params = `event=${eventType}&sound=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
        xhr.open("POST", url, true);
        xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            console.log(`${eventType} event for ${fileName} sent successfully.`);
                        } else {
                            console.error(`Error sending ${eventType} event: ${response.message}`);
                        }
                    } catch (e) {
                        console.error("Error parsing JSON response:", e);
                        console.error("Response:", xhr.responseText);
                    }
                } else {
                    console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
                }
            }
        };
        xhr.send(params);
    }
    // Function to remove an ignored game
    function removeIgnoredGame(gameName) {
        if (confirm('Are you sure you want to remove "' + gameName + '" from the ignored games list?')) {
            // Create a form to submit the removal request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'module_data_post.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'remove_ignored_game';
            input.value = gameName;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    // Function to set a cookie
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        const expires = "expires=" + d.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/";
    }
    // Function to load a tab
    function loadTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(function (tab) {
            tab.style.display = 'none';
        });
        // Show the selected tab
        const activeTab = document.getElementById(tabName);
        if (activeTab) {
            activeTab.style.display = 'block';
        }
        // Update sp-tabs-nav active state
        document.querySelectorAll('.sp-tabs-nav li').forEach(function (li) {
            if (li.getAttribute('data-tab') === tabName) {
                li.classList.add('is-active');
            } else {
                li.classList.remove('is-active');
            }
        });
        // Update URL with tab parameter
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.pushState({}, '', url);
        // Set cookie if consent given
        if (<?php echo $cookieConsent ? 'true' : 'false'; ?>) {
            setCookie('preferred_tab', tabName, 30);
        }
    }
    // Initialize tabs on page load
    document.addEventListener('DOMContentLoaded', function () {
        // Wire up tab nav click handlers
        document.querySelectorAll('.sp-tabs-nav li').forEach(function (li) {
            li.addEventListener('click', function () {
                const tabName = this.getAttribute('data-tab');
                if (tabName) loadTab(tabName);
            });
        });
        // Set initial active tab
        const initialTab = '<?php echo $activeTab; ?>';
        loadTab(initialTab);
        // ... existing code ...
    });
    // Auto-refresh automated shoutout cooldowns every 15 seconds
    let shoutoutRefreshInterval = null;
    function refreshShoutoutCooldowns() {
        fetch('get_shoutout_cooldowns.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.tracking) {
                    updateShoutoutCooldownTable(data);
                }
            })
            .catch(error => {
                console.error('Error refreshing shoutout cooldowns:', error);
            });
    }
    function updateShoutoutCooldownTable(data) {
        const tableBody = document.querySelector('#automated-shoutouts tbody');
        const noDataNotification = document.querySelector('#automated-shoutouts .sp-alert.sp-alert-info');
        if (!tableBody) return;
        if (data.tracking.length === 0) {
            // Show "no data" notification if exists, hide table
            if (noDataNotification) {
                noDataNotification.style.display = 'block';
            }
            const tableContainer = tableBody.closest('.sp-table-wrap');
            if (tableContainer) {
                tableContainer.style.display = 'none';
            }
        } else {
            // Hide notification, show table
            if (noDataNotification) {
                noDataNotification.style.display = 'none';
            }
            const tableContainer = tableBody.closest('.sp-table-wrap');
            if (tableContainer) {
                tableContainer.style.display = 'block';
            }
            // Update table rows
            let html = '';
            data.tracking.forEach(tracking => {
                const isExpired = tracking.is_expired;
                const rowClass = isExpired ? ' style="color:var(--text-muted);"' : '';
                const statusTag = isExpired 
                    ? '<span class="sp-badge sp-badge-green">Ready</span>'
                    : `<span class="sp-badge sp-badge-amber">${tracking.remaining_minutes} min</span>`;
                html += `
                    <tr${rowClass}>
                        <td>${escapeHtml(tracking.user_name)}</td>
                        <td>${tracking.shoutout_time}</td>
                        <td>${statusTag}</td>
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
        }
    }
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    // Start/stop refresh based on active tab
    function handleTabChange() {
        const automatedShoutoutsTab = document.getElementById('automated-shoutouts');
        if (automatedShoutoutsTab && automatedShoutoutsTab.style.display !== 'none') {
            // Start refreshing when on automated shoutouts tab
            if (!shoutoutRefreshInterval) {
                refreshShoutoutCooldowns(); // Refresh immediately
                shoutoutRefreshInterval = setInterval(refreshShoutoutCooldowns, 15000); // Then every 15 seconds
            }
        } else {
            // Stop refreshing when not on the tab
            if (shoutoutRefreshInterval) {
                clearInterval(shoutoutRefreshInterval);
                shoutoutRefreshInterval = null;
            }
        }
    }
    // Override the loadTab function to handle refresh
    const originalLoadTab = loadTab;
    loadTab = function(tabName) {
        originalLoadTab(tabName);
        handleTabChange();
    };
    // Start refresh if we're initially on the automated shoutouts tab
    document.addEventListener('DOMContentLoaded', function() {
        handleTabChange();
    });
    // Whitelist link validation against spam patterns
    const whitelistInput = document.getElementById('whitelist_link');
    const whitelistForm = whitelistInput ? whitelistInput.closest('form') : null;
    const whitelistButton = whitelistForm ? whitelistForm.querySelector('button[type="submit"]') : null;
    let whitelistCheckTimeout = null;
    if (whitelistInput && whitelistButton && whitelistForm) {
        // Create error message element
        const whitelistErrorMessage = document.createElement('p');
        whitelistErrorMessage.className = 'field-help text-danger';
        whitelistErrorMessage.style.display = 'none';
        whitelistErrorMessage.textContent = "Can't whitelist a globally blocked term";
        whitelistInput.parentElement.parentElement.appendChild(whitelistErrorMessage);
        whitelistInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(whitelistCheckTimeout);
            // Check for spaces FIRST using raw value - immediate validation
            if (whitelistInput.value.includes(' ')) {
                whitelistInput.classList.add('input-error');
                whitelistErrorMessage.textContent = 'No spaces allowed in URLs.';
                whitelistErrorMessage.style.display = 'block';
                whitelistButton.disabled = true;
                return;
            }
            // Reset state only if no spaces
            whitelistInput.classList.remove('input-error');
            whitelistErrorMessage.style.display = 'none';
            whitelistButton.disabled = false;
            const linkValue = whitelistInput.value.trim();
            if (linkValue.length === 0) {
                return;
            }
            // Debounce: wait 500ms after user stops typing
            whitelistCheckTimeout = setTimeout(function() {
                // Check against spam patterns
                const formData = new FormData();
                formData.append('link', linkValue);
                fetch('check_spam_pattern.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.matches === true) {
                        // Show error state
                        whitelistInput.classList.add('input-error');
                        whitelistErrorMessage.textContent = "Can't whitelist a globally blocked term";
                        whitelistErrorMessage.style.display = 'block';
                        whitelistButton.disabled = true;
                        return;
                    }
                    // If spam check passes, check if URL is in blacklist
                    const conflictData = new FormData();
                    conflictData.append('link', linkValue);
                    conflictData.append('check_list', 'blacklist');
                    return fetch('check_url_conflict.php', {
                        method: 'POST',
                        body: conflictData
                    });
                })
                .then(response => response ? response.json() : null)
                .then(data => {
                    if (data && data.exists === true) {
                        // URL already in blacklist
                        whitelistInput.classList.add('input-error');
                        whitelistErrorMessage.textContent = 'This URL is already in your blacklist.';
                        whitelistErrorMessage.style.display = 'block';
                        whitelistButton.disabled = true;
                    }
                })
                .catch(error => {
                    console.error('Error checking spam pattern:', error);
                });
            }, 500);
        });
        // Also validate on form submit as a safety check
        whitelistForm.addEventListener('submit', function(e) {
            if (whitelistInput.classList.contains('input-error')) {
                e.preventDefault();
                return false;
            }
        });
    }
    // Blacklist link validation against spam patterns
    const blacklistInput = document.getElementById('blacklist_link');
    const blacklistForm = blacklistInput ? blacklistInput.closest('form') : null;
    const blacklistButton = blacklistForm ? blacklistForm.querySelector('button[type="submit"]') : null;
    let blacklistCheckTimeout = null;
    if (blacklistInput && blacklistButton && blacklistForm) {
        // Create error message element
        const blacklistErrorMessage = document.createElement('p');
        blacklistErrorMessage.className = 'field-help text-danger';
        blacklistErrorMessage.style.display = 'none';
        blacklistErrorMessage.textContent = 'Globally Blocked, unable to add to personal block list';
        blacklistInput.parentElement.parentElement.appendChild(blacklistErrorMessage);
        blacklistInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(blacklistCheckTimeout);
            // Check for spaces FIRST using raw value - immediate validation
            if (blacklistInput.value.includes(' ')) {
                blacklistInput.classList.add('input-error');
                blacklistErrorMessage.textContent = 'No spaces allowed in URLs.';
                blacklistErrorMessage.style.display = 'block';
                blacklistButton.disabled = true;
                return;
            }
            // Reset state only if no spaces
            blacklistInput.classList.remove('input-error');
            blacklistErrorMessage.style.display = 'none';
            blacklistButton.disabled = false;
            const linkValue = blacklistInput.value.trim();
            if (linkValue.length === 0) {
                return;
            }
            // Debounce: wait 500ms after user stops typing
            blacklistCheckTimeout = setTimeout(function() {
                // Check against spam patterns
                const formData = new FormData();
                formData.append('link', linkValue);
                fetch('check_spam_pattern.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.matches === true) {
                        // Show error state
                        blacklistInput.classList.add('input-error');
                        blacklistErrorMessage.textContent = 'Globally Blocked, unable to add to personal block list';
                        blacklistErrorMessage.style.display = 'block';
                        blacklistButton.disabled = true;
                        return;
                    }
                    // If spam check passes, check if URL is in whitelist
                    const conflictData = new FormData();
                    conflictData.append('link', linkValue);
                    conflictData.append('check_list', 'whitelist');
                    return fetch('check_url_conflict.php', {
                        method: 'POST',
                        body: conflictData
                    });
                })
                .then(response => response ? response.json() : null)
                .then(data => {
                    if (data && data.exists === true) {
                        // URL already in whitelist
                        blacklistInput.classList.add('input-error');
                        blacklistErrorMessage.textContent = 'This URL is already in your whitelist.';
                        blacklistErrorMessage.style.display = 'block';
                        blacklistButton.disabled = true;
                    }
                })
                .catch(error => {
                    console.error('Error checking spam pattern:', error);
                });
            }, 500);
        });
        // Also validate on form submit as a safety check
        blacklistForm.addEventListener('submit', function(e) {
            if (blacklistInput.classList.contains('input-error')) {
                e.preventDefault();
                return false;
            }
        });
    }
    // Blocked term validation against spam patterns, whitelist, and blacklist
    const blockedTermInput = document.getElementById('blocked_term');
    const blockedTermForm = blockedTermInput ? blockedTermInput.closest('form') : null;
    const blockedTermButton = blockedTermForm ? blockedTermForm.querySelector('button[type="submit"]') : null;
    let blockedTermCheckTimeout = null;
    if (blockedTermInput && blockedTermButton && blockedTermForm) {
        // Create error message element
        const blockedTermErrorMessage = document.createElement('p');
        blockedTermErrorMessage.className = 'field-help text-danger';
        blockedTermErrorMessage.style.display = 'none';
        blockedTermErrorMessage.textContent = '';
        blockedTermInput.parentElement.parentElement.appendChild(blockedTermErrorMessage);
        blockedTermInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(blockedTermCheckTimeout);
            // Check for spaces FIRST using raw value (before trim) - immediate validation
            if (blockedTermInput.value.includes(' ')) {
                blockedTermInput.classList.add('input-error');
                blockedTermErrorMessage.textContent = 'Only one word per entry allowed. No spaces permitted.';
                blockedTermErrorMessage.style.display = 'block';
                blockedTermButton.disabled = true;
                return;
            }
            // Reset state only if no spaces
            blockedTermInput.classList.remove('input-error');
            blockedTermErrorMessage.style.display = 'none';
            blockedTermButton.disabled = false;
            const termValue = blockedTermInput.value.trim();
            if (termValue.length === 0) {
                return;
            }
            // Debounce: wait 500ms after user stops typing
            blockedTermCheckTimeout = setTimeout(function() {
                // Check against spam patterns, whitelist, and blacklist
                const formData = new FormData();
                formData.append('term', termValue);
                fetch('check_blocked_term.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.valid === false) {
                        // Show error state with appropriate message
                        blockedTermInput.classList.add('input-error');
                        blockedTermErrorMessage.textContent = data.message;
                        blockedTermErrorMessage.style.display = 'block';
                        blockedTermButton.disabled = true;
                    }
                })
                .catch(error => {
                    console.error('Error checking blocked term:', error);
                });
            }, 500);
        });
        // Also validate on form submit as a safety check
        blockedTermForm.addEventListener('submit', function(e) {
            if (blockedTermInput.classList.contains('input-error')) {
                e.preventDefault();
                return false;
            }
        });
    }

    // Custom Module Bots: resolve Twitch username to ID
    (function() {
        const resolveBtn   = document.getElementById('resolve-module-bot-btn');
        const usernameInput = document.getElementById('module-bot-username');
        const idField      = document.getElementById('module-bot-id');
        const statusIcon   = document.getElementById('module-bot-lookup-status');
        const addBtn       = document.querySelector('#custom-module-bot-form button[type="submit"]');
        function setStatus(html, isOk) {
            if (!statusIcon) return;
            statusIcon.style.display = html ? '' : 'none';
            statusIcon.innerHTML = html || '';
            if (addBtn) addBtn.disabled = !isOk;
        }
        // Disable Add button until ID is resolved
        if (addBtn) addBtn.disabled = true;
        // Re-disable when username changes
        if (usernameInput) {
            usernameInput.addEventListener('input', function() {
                if (idField) idField.value = '';
                setStatus('', false);
            });
        }
        if (resolveBtn) {
            resolveBtn.addEventListener('click', function() {
                const name = usernameInput ? usernameInput.value.trim() : '';
                if (!name) {
                    setStatus('<i class="fas fa-exclamation-triangle" style="color:var(--red);"></i>', false);
                    return;
                }
                setStatus('<i class="fas fa-spinner fa-spin"></i>', false);
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'resolve_module_bot_id', bot_username: name})
                })
                .then(r => r.json())
                .then(j => {
                    if (j && j.success) {
                        if (idField) idField.value = j.bot_id || '';
                        setStatus('<i class="fas fa-check" style="color:var(--green);"></i>', true);
                    } else {
                        setStatus('<i class="fas fa-times" style="color:var(--red);"></i>', false);
                        alert(j.error || 'Unable to resolve bot ID');
                    }
                })
                .catch(function(err) {
                    setStatus('<i class="fas fa-times" style="color:var(--red);"></i>', false);
                    console.error(err);
                    alert('Error resolving bot ID');
                });
            });
        }
    })();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>