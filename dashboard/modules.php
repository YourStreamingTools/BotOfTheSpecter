<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
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
$ad_start_message = '';
$ad_end_message = '';
$ad_snoozed_message = '';
$enable_ad_notice = 0;
$enable_upcoming_ad_message = 1;
$enable_start_ad_message = 1;
$enable_end_ad_message = 1;
$enable_snoozed_ad_message = 1;
$enable_ai_ad_breaks = 0;

$stmt = $db->prepare("SELECT ad_upcoming_message, ad_start_message, ad_end_message, ad_snoozed_message, enable_ad_notice, enable_upcoming_ad_message, enable_start_ad_message, enable_end_ad_message, enable_snoozed_ad_message, enable_ai_ad_breaks FROM ad_notice_settings LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result(
        $fetched_upcoming,
        $fetched_start,
        $fetched_end,
        $fetched_snoozed,
        $fetched_enable_global,
        $fetched_enable_upcoming,
        $fetched_enable_start,
        $fetched_enable_end,
        $fetched_enable_snoozed,
        $fetched_enable_ai
    );
    if ($stmt->fetch()) {
        $ad_upcoming_message = $fetched_upcoming;
        $ad_start_message = $fetched_start;
        $ad_end_message = $fetched_end;
        $ad_snoozed_message = $fetched_snoozed;
        $enable_ad_notice = $fetched_enable_global;
        $enable_upcoming_ad_message = $fetched_enable_upcoming;
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
$getProtection = $db->query("SELECT url_blocking, term_blocking FROM protection LIMIT 1");
if ($getProtection) {
    $settings = $getProtection->fetch_assoc();
    $currentSettings = isset($settings['url_blocking']) ? $settings['url_blocking'] : 'False';
    $termBlockingSettings = isset($settings['term_blocking']) ? $settings['term_blocking'] : 'False';
    $getProtection->free();
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
<div class="notification is-info mb-5">
    <div class="columns is-vcentered">
        <div class="column is-narrow">
            <span class="icon is-large"><i class="fas fa-code fa-2x"></i></span>
        </div>
        <div class="column">
            <p class="title is-6 mb-2">Variables for Modules</p>
            <p class="mb-2">Use variables in your Welcome Messages, Ad Notices, and Twitch Chat Alerts to create
                dynamic, personalized messages for your community.</p>
            <p class="mb-2"><strong>What are Module Variables?</strong>
                <br>Variables are placeholders that get replaced with real information when the message is sent.
                <br>For example, <code>(user)</code> becomes the viewer's username, and <code>(bits)</code> shows the
                number of bits cheered.
            </p>
            <p class="mb-2"><strong>Available Variables:</strong>
                <br>Each module has specific variables you can use - from usernames and viewer counts to subscription
                tiers and hype train levels.
            </p>
            <a href="https://help.botofthespecter.com/specter_module_variables.php" target="_blank"
                class="button is-primary is-small">
                <span class="icon"><i class="fas fa-code"></i></span>
                <span>View All Module Variables</span>
            </a>
        </div>
    </div>
</div>
<!-- Tabs Navigation -->
<div class="tabs-container">
    <div class="tabs-scroll-wrapper">
        <div class="data-tabs">
            <div class="tab-item active" onclick="loadTab('joke-blacklist')">
                <i class="fas fa-ban"></i>
                <span><?php echo t('modules_tab_joke_blacklist'); ?></span>
            </div>
            <div class="tab-item" onclick="loadTab('welcome-messages')">
                <i class="fas fa-hand-sparkles"></i>
                <span><?php echo t('modules_tab_welcome_messages'); ?></span>
            </div>
            <div class="tab-item" onclick="loadTab('chat-protection')">
                <i class="fas fa-shield-alt"></i>
                <span><?php echo t('modules_tab_chat_protection'); ?></span>
            </div>
            <div class="tab-item" onclick="loadTab('game-deaths')">
                <i class="fas fa-skull-crossbones"></i>
                <span>Game Deaths</span>
            </div>
            <div class="tab-item" onclick="loadTab('ad-notices')">
                <i class="fas fa-bullhorn"></i>
                <span><?php echo t('modules_tab_ad_notices'); ?></span>
            </div>
            <div class="tab-item" onclick="loadTab('twitch-audio-alerts')">
                <i class="fas fa-volume-up"></i>
                <span><?php echo t('modules_tab_twitch_event_alerts'); ?></span>
            </div>
            <div class="tab-item" onclick="loadTab('twitch-chat-alerts')">
                <i class="fas fa-comment-dots"></i>
                <span><?php echo t('modules_tab_twitch_chat_alerts'); ?></span>
            </div>
            <div class="tab-item" onclick="loadTab('automated-shoutouts')">
                <i class="fas fa-bullhorn"></i>
                <span>Automated Shoutouts</span>
            </div>
            <div class="tab-item" onclick="loadTab('tts-settings')">
                <i class="fas fa-microphone"></i>
                <span>TTS Settings</span>
            </div>
        </div>
    </div>
</div>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5"
            style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-cogs"></i></span>
                    <?php echo t('modules_title'); ?>
                </span>
            </header>
            <div class="card-content">
                <?php if (isset($_SESSION['update_message'])): ?>
                    <div class="notification is-success">
                        <?php echo $_SESSION['update_message'];
                        unset($_SESSION['update_message']); ?>
                    </div>
                <?php endif; ?>
                <!-- Tab Contents -->
                <div class="content">
                    <div class="tab-content" id="joke-blacklist">
                        <div class="module-container">
                            <div class="columns is-vcentered mb-4">
                                <div class="column">
                                    <h2 class="title is-4 has-text-white mb-2">
                                        <span class="icon has-text-danger"><i class="fas fa-ban"></i></span>
                                        <?php echo t('modules_joke_blacklist_title'); ?>
                                    </h2>
                                    <p class="subtitle is-6 has-text-danger">
                                        <?php echo t('modules_joke_blacklist_subtitle'); ?>
                                    </p>
                                </div>
                                <div class="column is-narrow">
                                    <!-- Joke Command Status Control -->
                                    <div class="box has-background-grey-darker p-3" style="min-width: 420px;">
                                        <div class="field">
                                            <div class="field is-grouped is-grouped-centered">
                                                <div class="control">
                                                    <div class="tags">
                                                        <span class="tag is-dark is-medium">
                                                            <span class="icon is-small mr-1"><i
                                                                    class="fas fa-terminal"></i></span>
                                                            Joke Command
                                                        </span>
                                                        <span
                                                            class="tag is-medium <?php echo ($joke_command_status == 'Enabled') ? 'is-success' : 'is-danger'; ?>">
                                                            <?php echo ($joke_command_status == 'Enabled') ? t('builtin_commands_status_enabled') : t('builtin_commands_status_disabled'); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="control">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="toggle_joke_command" value="1">
                                                        <input type="hidden" name="joke_command_status"
                                                            value="<?php echo ($joke_command_status == 'Enabled') ? 'Disabled' : 'Enabled'; ?>">
                                                        <button type="submit"
                                                            class="button is-small <?php echo ($joke_command_status == 'Enabled') ? 'is-danger' : 'is-success'; ?>">
                                                            <span class="icon is-small">
                                                                <i
                                                                    class="fas <?php echo ($joke_command_status == 'Enabled') ? 'fa-times' : 'fa-check'; ?>"></i>
                                                            </span>
                                                            <span><?php echo ($joke_command_status == 'Enabled') ? t('builtin_commands_disable') : t('builtin_commands_enable'); ?></span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                            <p class="has-text-grey-light is-size-7 has-text-centered mb-0">
                                                <?php echo t('modules_joke_command_control_description'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div class="columns is-multiline">
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
                                        <div class="column is-3">
                                            <div class="field">
                                                <label class="checkbox">
                                                    <input type="checkbox" name="blacklist[]"
                                                        value="<?php echo $cat_value; ?>" <?php echo (is_array($current_blacklist) && in_array($cat_value, $current_blacklist)) ? " checked" : ""; ?>>
                                                    <?php echo t($cat_label_key); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="field">
                                    <div class="control">
                                        <button class="button is-primary"
                                            type="submit"><?php echo t('modules_save_blacklist_settings'); ?></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="tab-content" id="welcome-messages">
                        <div class="module-container">
                            <div class="columns is-vcentered mb-4">
                                <div class="column">
                                    <h2 class="title is-4 has-text-white mb-2">
                                        <span class="icon mr-2"><i class="fas fa-cog"></i></span>
                                        Welcome Message Configuration
                                    </h2>
                                </div>
                                <div class="column is-narrow">
                                    <!-- Welcome Messages Status Control -->
                                    <div class="box has-background-grey-darker p-3" style="min-width: 420px;">
                                        <div class="field">
                                            <div class="field is-grouped is-grouped-centered">
                                                <div class="control">
                                                    <div class="tags">
                                                        <span class="tag is-dark is-medium">
                                                            <span class="icon is-small mr-1"><i class="fas fa-comment"></i></span>
                                                            Welcome Messages
                                                        </span>
                                                        <span class="tag is-medium <?php echo ($send_welcome_messages) ? 'is-success' : 'is-danger'; ?>">
                                                            <?php echo ($send_welcome_messages) ? 'Enabled' : 'Disabled'; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="control">
                                                    <form method="POST" action="module_data_post.php" style="display: inline;">
                                                        <input type="hidden" name="toggle_welcome_messages" value="1">
                                                        <input type="hidden" name="welcome_messages_status" value="<?php echo ($send_welcome_messages) ? '0' : '1'; ?>">
                                                        <button type="submit" class="button is-small <?php echo ($send_welcome_messages) ? 'is-danger' : 'is-success'; ?>">
                                                            <span class="icon is-small">
                                                                <i class="fas <?php echo ($send_welcome_messages) ? 'fa-times' : 'fa-check'; ?>"></i>
                                                            </span>
                                                            <span><?php echo ($send_welcome_messages) ? 'Disable' : 'Enable'; ?></span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                            <p class="has-text-grey-light is-size-7 has-text-centered mb-0">
                                                Toggle automatic welcome messages for viewers
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div class="columns is-multiline">
                                    <!-- Regular Members Column -->
                                    <div class="column is-6">
                                        <div class="level mb-4">
                                            <div class="level-left">
                                                <h5 class="title is-5 has-text-white mb-0">
                                                    <span class="icon mr-2"><i class="fas fa-users"></i></span>
                                                    Regular Members
                                                </h5>
                                            </div>
                                            <div class="level-right">
                                                <button type="button"
                                                    class="section-save-btn button is-success is-small"
                                                    data-section="regular-members">
                                                    <span class="icon"><i class="fas fa-save"></i></span>
                                                    <span>Save Regular Members</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i class="fas fa-user-plus"></i></span>
                                                <?php echo t('modules_welcome_new_member_label'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input welcome-message-input" type="text"
                                                    name="new_default_welcome_message" maxlength="255"
                                                    value="<?php echo htmlspecialchars($new_default_welcome_message !== '' ? $new_default_welcome_message : t('modules_welcome_new_member_default')); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="new_default_welcome_message">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i class="fas fa-user-check"></i></span>
                                                <?php echo t('modules_welcome_returning_member_label'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input welcome-message-input" type="text"
                                                    name="default_welcome_message" maxlength="255"
                                                    value="<?php echo htmlspecialchars($default_welcome_message !== '' ? $default_welcome_message : t('modules_welcome_returning_member_default')); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="default_welcome_message">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                    </div>
                                    <!-- VIP Members Column -->
                                    <div class="column is-6">
                                        <div class="level mb-4">
                                            <div class="level-left">
                                                <h5 class="title is-5 has-text-white mb-0">
                                                    <span class="icon mr-2"><i class="fas fa-gem"></i></span>
                                                    VIP Members
                                                </h5>
                                            </div>
                                            <div class="level-right">
                                                <button type="button"
                                                    class="section-save-btn button is-success is-small"
                                                    data-section="vip-members">
                                                    <span class="icon"><i class="fas fa-save"></i></span>
                                                    <span>Save VIP Members</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i class="fas fa-user-plus"></i></span>
                                                <?php echo t('modules_welcome_new_vip_label'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input welcome-message-input" type="text"
                                                    name="new_default_vip_welcome_message" maxlength="255"
                                                    value="<?php echo htmlspecialchars($new_default_vip_welcome_message !== '' ? $new_default_vip_welcome_message : t('modules_welcome_new_vip_default')); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="new_default_vip_welcome_message">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i class="fas fa-user-check"></i></span>
                                                <?php echo t('modules_welcome_returning_vip_label'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input welcome-message-input" type="text"
                                                    name="default_vip_welcome_message" maxlength="255"
                                                    value="<?php echo htmlspecialchars($default_vip_welcome_message !== '' ? $default_vip_welcome_message : t('modules_welcome_returning_vip_default')); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="default_vip_welcome_message">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                    </div>
                                    <!-- Moderators -->
                                    <div class="column is-12">
                                        <div class="level mb-4">
                                            <div class="level-left">
                                                <h5 class="title is-5 has-text-white mb-0">
                                                    <span class="icon mr-2"><i class="fas fa-shield-alt"></i></span>
                                                    Moderators
                                                </h5>
                                            </div>
                                            <div class="level-right">
                                                <button type="button"
                                                    class="section-save-btn button is-success is-small"
                                                    data-section="moderators">
                                                    <span class="icon"><i class="fas fa-save"></i></span>
                                                    <span>Save Moderators</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="columns">
                                            <div class="column is-6">
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-plus"></i></span>
                                                        <?php echo t('modules_welcome_new_mod_label'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input welcome-message-input" type="text"
                                                            name="new_default_mod_welcome_message" maxlength="255"
                                                            value="<?php echo htmlspecialchars($new_default_mod_welcome_message !== '' ? $new_default_mod_welcome_message : t('modules_welcome_new_mod_default')); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="new_default_mod_welcome_message">0</span>/255
                                                        characters
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="column is-6">
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-check"></i></span>
                                                        <?php echo t('modules_welcome_returning_mod_label'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input welcome-message-input" type="text"
                                                            name="default_mod_welcome_message" maxlength="255"
                                                            value="<?php echo htmlspecialchars($default_mod_welcome_message !== '' ? $default_mod_welcome_message : t('modules_welcome_returning_mod_default')); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="default_mod_welcome_message">0</span>/255
                                                        characters
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="tab-content" id="chat-protection">
                        <div class="module-container">
                            <!-- Chat Protection Configuration -->
                            <h1 class="title is-3 mb-4">
                                <span class="icon has-text-info"><i class="fas fa-shield-alt"></i></span>
                                <?php echo t('protection_title'); ?>
                            </h1>
                            <!-- URL Blocking System Information (Version 5.8) -->
                            <div class="notification is-info is-light mb-5">
                                <h4 class="title is-5 has-text-dark mb-3">
                                    <span class="icon mr-2"><i class="fas fa-info-circle"></i></span>
                                    <strong>URL Blocking System Overview (Version 5.8)</strong>
                                </h4>
                                <div class="content has-text-dark">
                                    <p><strong>How URL Blocking Works:</strong></p>
                                    <ul>
                                        <li>
                                            <strong class="has-text-danger"><i class="fas fa-ban mr-1"></i> Blacklist (Always Active):</strong>
                                            URLs in the blacklist are <strong>ALWAYS blocked</strong> regardless of URL Blocking setting.
                                            Triggers a "Code Red" alert to moderators when detected.
                                        </li>
                                        <li>
                                            <strong class="has-text-link"><i class="fas fa-toggle-on mr-1"></i> URL Blocking Enabled:</strong>
                                            Removes all links from chat <strong>except</strong>:
                                            <ul>
                                                <li>URLs matching whitelist regex patterns</li>
                                                <li>Twitch.tv and clips.twitch.tv links</li>
                                                <li>Messages from mods/streamers (bypass)</li>
                                            </ul>
                                        </li>
                                        <li>
                                            <strong style="color: #00947e;"><i class="fas fa-toggle-off mr-1"></i> URL Blocking Disabled:</strong>
                                            Allows all URLs in chat <strong>except</strong> blacklisted ones.
                                        </li>
                                        <li>
                                            <strong style="color: #00947e;"><i class="fas fa-check-circle mr-1"></i> Whitelist Supports Regex:</strong>
                                            Use regular expressions for flexible pattern matching (e.g., <code>.*\.youtube\.com</code> for all YouTube subdomains).
                                        </li>
                                    </ul>
                                    <p class="mt-3 mb-0">
                                        <span class="icon-text">
                                            <span class="icon has-text-warning"><i class="fas fa-exclamation-triangle"></i></span>
                                            <span><strong>Important:</strong> Moderators and streamers can post URLs even when URL Blocking is enabled.</span>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="columns is-multiline is-variable is-5 is-centered">
                                <!-- URL Blocking Settings -->
                                <div class="column is-4">
                                    <div class="card" style="height: 100%;">
                                        <div class="card-content">
                                            <div class="has-text-centered mb-4">
                                                <h3 class="title is-5">
                                                    <span class="icon has-text-link"><i class="fas fa-link-slash"></i></span>
                                                    <?php echo t('protection_enable_url_blocking'); ?>
                                                </h3>
                                            </div>
                                            <form action="module_data_post.php" method="post">
                                                <div class="field">
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select name="url_blocking" id="url_blocking">
                                                                <option value="True"<?php echo $currentSettings == 'True' ? ' selected' :'';?>><?php echo t('yes'); ?></option>
                                                                <option value="False"<?php echo $currentSettings == 'False' ? ' selected' :'';?>><?php echo t('no'); ?></option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="field mt-4">
                                                    <button type="submit" name="submit" class="button is-primary is-fullwidth">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span><?php echo t('protection_update_btn'); ?></span>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <!-- Whitelist Link Form -->
                                <div class="column is-4">
                                    <div class="card" style="height: 100%;">
                                        <div class="card-content">
                                            <div class="has-text-centered mb-4">
                                                <h3 class="title is-5">
                                                    <span class="icon has-text-success"><i class="fas fa-check-circle"></i></span>
                                                    <?php echo t('protection_enter_link_whitelist'); ?>
                                                </h3>
                                            </div>
                                            <form action="module_data_post.php" method="post">
                                                <div class="field">
                                                    <div class="control has-icons-left">
                                                        <input class="input" type="text" name="whitelist_link" id="whitelist_link" placeholder="<?php echo t('protection_enter_url_placeholder'); ?>" required>
                                                        <span class="icon is-small is-left"><i class="fas fa-link"></i></span>
                                                    </div>
                                                </div>
                                                <div class="field mt-4">
                                                    <button type="submit" name="submit" class="button is-link is-fullwidth">
                                                        <span class="icon"><i class="fas fa-plus-circle"></i></span>
                                                        <span><?php echo t('protection_add_to_whitelist'); ?></span>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <!-- Blacklist Link Form -->
                                <div class="column is-4">
                                    <div class="card" style="height: 100%;">
                                        <div class="card-content">
                                            <div class="has-text-centered mb-4">
                                                <h3 class="title is-5">
                                                    <span class="icon has-text-danger"><i class="fas fa-ban"></i></span>
                                                    <?php echo t('protection_enter_link_blacklist'); ?>
                                                </h3>
                                            </div>
                                            <form action="module_data_post.php" method="post">
                                                <div class="field">
                                                    <div class="control has-icons-left">
                                                        <input class="input" type="text" name="blacklist_link" id="blacklist_link" placeholder="<?php echo t('protection_enter_url_placeholder'); ?>" required>
                                                        <span class="icon is-small is-left"><i class="fas fa-link"></i></span>
                                                    </div>
                                                </div>
                                                <div class="field mt-4">
                                                    <button type="submit" name="submit" class="button is-danger is-fullwidth">
                                                        <span class="icon"><i class="fas fa-minus-circle"></i></span>
                                                        <span><?php echo t('protection_add_to_blacklist'); ?></span>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <!-- Whitelist and Blacklist Tables -->
                                <div class="column is-6">
                                    <div class="box">
                                        <h2 class="subtitle is-5 mb-3">
                                            <span class="icon has-text-success"><i class="fas fa-list-ul"></i></span>
                                            <?php echo t('protection_whitelist_links'); ?>
                                        </h2>
                                        <table class="table is-fullwidth is-bordered is-striped is-hoverable">
                                            <tbody>
                                                <?php if (empty($whitelistLinks)): ?>
                                                    <tr>
                                                        <td class="has-text-centered has-text-grey-light" colspan="2">
                                                            <span class="icon-text">
                                                                <span class="icon"><i class="fas fa-info-circle"></i></span>
                                                                <span>No whitelisted links configured</span>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($whitelistLinks as $link): ?>
                                                        <tr>
                                                            <td class="is-size-6"><?php echo htmlspecialchars($link['link']); ?></td>
                                                            <td class="has-text-right">
                                                                <form action="module_data_post.php" method="post" style="display:inline;">
                                                                    <input type="hidden" name="remove_whitelist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                                                    <button type="submit" class="button is-danger is-small is-rounded">
                                                                        <span class="icon"><i class="fas fa-trash-alt"></i></span>
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
                                <div class="column is-6">
                                    <div class="box">
                                        <h2 class="subtitle is-5 mb-3">
                                            <span class="icon has-text-danger"><i class="fas fa-list-ul"></i></span>
                                            <?php echo t('protection_blacklist_links'); ?>
                                        </h2>
                                        <table class="table is-fullwidth is-bordered is-striped is-hoverable">
                                            <tbody>
                                                <?php if (empty($blacklistLinks)): ?>
                                                    <tr>
                                                        <td class="has-text-centered has-text-grey-light" colspan="2">
                                                            <span class="icon-text">
                                                                <span class="icon"><i class="fas fa-info-circle"></i></span>
                                                                <span>No blacklisted links configured</span>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($blacklistLinks as $link): ?>
                                                        <tr>
                                                            <td class="is-size-6"><?php echo htmlspecialchars($link['link']); ?></td>
                                                            <td class="has-text-right">
                                                                <form action="module_data_post.php" method="post" style="display:inline;">
                                                                    <input type="hidden" name="remove_blacklist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                                                    <button type="submit" class="button is-danger is-small is-rounded">
                                                                        <span class="icon"><i class="fas fa-trash-alt"></i></span>
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
                        <!-- Term Blocking Section (Beta) -->
                        <div class="module-container mt-6">
                            <h1 class="title is-3 mb-4">
                                <span class="icon has-text-warning"><i class="fas fa-comment-slash"></i></span>
                                Text Term Blocking
                                <span class="tag is-warning is-light ml-3">Beta - Version 5.8</span>
                            </h1>
                            <!-- Term Blocking Information -->
                            <div class="notification is-warning is-light mb-5">
                                <h4 class="title is-5 has-text-dark mb-3">
                                    <span class="icon mr-2"><i class="fas fa-flask"></i></span>
                                    <strong>Term Blocking System (Beta Feature)</strong>
                                </h4>
                                <div class="content has-text-dark">
                                    <p><strong>How Term Blocking Works:</strong></p>
                                    <ul>
                                        <li>
                                            <strong class="has-text-danger"><i class="fas fa-ban mr-1"></i> Blocked Terms:</strong>
                                            Messages containing blocked terms will be automatically deleted from chat.
                                        </li>
                                        <li>
                                            <strong class="has-text-link"><i class="fas fa-toggle-on mr-1"></i> When Enabled:</strong>
                                            Bot will scan all chat messages for blocked terms and remove matching messages instantly.
                                        </li>
                                        <li>
                                            <strong style="color: #00947e;"><i class="fas fa-shield-alt mr-1"></i> Case-Insensitive:</strong>
                                            Term matching is case-insensitive (e.g., "badword", "BADWORD", "BadWord" all match).
                                        </li>
                                    </ul>
                                    <p class="mt-3 mb-0">
                                        <span class="icon-text">
                                            <span class="icon has-text-warning"><i class="fas fa-exclamation-triangle"></i></span>
                                            <span><strong>Note:</strong> This feature is in beta testing. Report any issues via feedback.</span>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="columns is-multiline is-variable is-5 is-centered">
                                <!-- Term Blocking Settings -->
                                <div class="column is-4">
                                    <div class="card" style="height: 100%;">
                                        <div class="card-content">
                                            <div class="has-text-centered mb-4">
                                                <h3 class="title is-5">
                                                    <span class="icon has-text-warning"><i class="fas fa-comment-slash"></i></span>
                                                    Enable Term Blocking
                                                </h3>
                                            </div>
                                            <form action="module_data_post.php" method="post">
                                                <div class="field">
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select name="term_blocking" id="term_blocking">
                                                                <option value="True"<?php echo $termBlockingSettings == 'True' ? ' selected' : ''; ?>><?php echo t('yes'); ?></option>
                                                                <option value="False"<?php echo $termBlockingSettings == 'False' ? ' selected' : ''; ?>><?php echo t('no'); ?></option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="field mt-4">
                                                    <button type="submit" name="submit" class="button is-primary is-fullwidth">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span><?php echo t('protection_update_btn'); ?></span>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add Blocked Term Form -->
                                <div class="column is-4">
                                    <div class="card" style="height: 100%;">
                                        <div class="card-content">
                                            <div class="has-text-centered mb-4">
                                                <h3 class="title is-5">
                                                    <span class="icon has-text-danger"><i class="fas fa-ban"></i></span>
                                                    Add Blocked Term
                                                </h3>
                                            </div>
                                            <form action="module_data_post.php" method="post">
                                                <div class="field">
                                                    <div class="control has-icons-left">
                                                        <input class="input" type="text" name="blocked_term" id="blocked_term" placeholder="Enter term to block..." required>
                                                        <span class="icon is-small is-left"><i class="fas fa-comment-slash"></i></span>
                                                    </div>
                                                </div>
                                                <div class="field mt-4">
                                                    <button type="submit" name="submit" class="button is-danger is-fullwidth">
                                                        <span class="icon"><i class="fas fa-minus-circle"></i></span>
                                                        <span>Add to Blocked Terms</span>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <!-- Blocked Terms Table -->
                                <div class="column is-8">
                                    <div class="box">
                                        <h2 class="subtitle is-5 mb-3">
                                            <span class="icon has-text-danger"><i class="fas fa-list-ul"></i></span>
                                            Blocked Terms List
                                        </h2>
                                        <table class="table is-fullwidth is-bordered is-striped is-hoverable">
                                            <tbody>
                                                <?php if (empty($blockedTerms)): ?>
                                                    <tr>
                                                        <td class="has-text-centered has-text-grey-light" colspan="2">
                                                            <span class="icon-text">
                                                                <span class="icon"><i class="fas fa-info-circle"></i></span>
                                                                <span>No blocked terms configured</span>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($blockedTerms as $term): ?>
                                                        <tr>
                                                            <td class="is-size-6"><?php echo htmlspecialchars($term['term']); ?></td>
                                                            <td class="has-text-right">
                                                                <form action="module_data_post.php" method="post" style="display:inline;">
                                                                    <input type="hidden" name="remove_blocked_term" value="<?php echo htmlspecialchars($term['term']); ?>">
                                                                    <button type="submit" class="button is-danger is-small is-rounded">
                                                                        <span class="icon"><i class="fas fa-trash-alt"></i></span>
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
                        <br>
                    </div>
                    <div class="tab-content" id="game-deaths">
                        <div class="module-container">
                            <div class="columns is-vcentered mb-4">
                                <div class="column">
                                    <h2 class="title is-4 has-text-white mb-2">
                                        <span class="icon mr-2"><i class="fas fa-skull-crossbones"></i></span>
                                        Game Deaths Configuration
                                    </h2>
                                </div>
                            </div>
                            <!-- Configuration Note -->
                            <div class="notification is-info mb-4">
                                <p class="has-text-dark">
                                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                                    <strong>Game Deaths Configuration:</strong><br>
                                    Configure games to ignore when counting deaths.<br>
                                    Deaths in these games will not be added to the total death counter
                                    for the !deathadd command.
                                </p>
                            </div>
                            <!-- Add Game Form -->
                            <form method="POST" action="module_data_post.php" class="mb-4">
                                <div class="field has-addons">
                                    <div class="control is-expanded">
                                        <input class="input" type="text" name="ignore_game_name"
                                            placeholder="Enter game name to ignore (e.g., Minecraft, Fortnite)"
                                            maxlength="100" required>
                                    </div>
                                    <div class="control">
                                        <button class="button is-primary" type="submit" name="add_ignored_game">
                                            <span class="icon"><i class="fas fa-plus"></i></span>
                                            <span>Add Game</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <!-- Current Ignored Games -->
                            <h4 class="title is-5 has-text-white mb-3">
                                <span class="icon mr-2"><i class="fas fa-list"></i></span>
                                Currently Ignored Games
                            </h4>
                            <div class="content">
                                <?php
                                if (!empty($ignored_games)) {
                                    echo '<div class="tags">';
                                    foreach ($ignored_games as $game) {
                                        echo '<span class="tag is-danger is-medium">';
                                        echo htmlspecialchars($game);
                                        echo '<button class="delete is-small ml-1" onclick="removeIgnoredGame(\'' . htmlspecialchars(addslashes($game)) . '\')"></button>';
                                        echo '</span>';
                                    }
                                    echo '</div>';
                                } else {
                                    echo '<p class="has-text-grey-light">No games are currently being ignored.</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <!-- Ad Notices -->
                    <div class="tab-content" id="ad-notices">
                        <div class="module-container">
                            <div class="columns is-vcentered mb-4">
                                <div class="column">
                                    <h2 class="title is-4 has-text-white mb-2">
                                        <span class="icon mr-2"><i class="fas fa-cog"></i></span>
                                        Ad Notice Messages
                                    </h2>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <div class="level mb-4">
                                    <div class="level-left">
                                        <h5 class="title is-5 has-text-white mb-0">
                                            <span class="icon mr-2"><i class="fas fa-bullhorn"></i></span>
                                            Advertisement Messages
                                        </h5>
                                    </div>
                                </div>
                                <div class="columns">
                                    <div class="column is-4">
                                        <div class="field">
                                            <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                                <label class="label has-text-white mb-0">
                                                    <span class="icon mr-1"><i class="fas fa-exclamation-triangle"></i></span>
                                                    <?php echo t('modules_ad_upcoming_message'); ?>
                                                </label>
                                                <label for="enable_upcoming_ad_message" style="cursor: pointer;">
                                                    <input id="enable_upcoming_ad_message" type="checkbox"
                                                        name="enable_upcoming_ad_message" value="1"
                                                        <?php echo (!empty($enable_upcoming_ad_message) ? 'checked' : ''); ?>
                                                        style="display: none;">
                                                    <i class="fas fa-toggle-<?php echo (!empty($enable_upcoming_ad_message) ? 'on has-text-success' : 'off has-text-grey'); ?> fa-2x"></i>
                                                </label>
                                            </div>
                                            <div class="control">
                                                <textarea class="textarea ad-notice-input" name="ad_upcoming_message"
                                                    maxlength="255" placeholder="<?php echo t('modules_ad_upcoming_message_placeholder'); ?>"
                                                    rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_upcoming_message ?? ''); ?></textarea>
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="ad_upcoming_message">0</span>/255 characters
                                            </p>
                                        </div>
                                    </div>
                                    <div class="column is-4">
                                        <div class="field">
                                            <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                                <label class="label has-text-white mb-0">
                                                    <span class="icon mr-1"><i class="fas fa-play"></i></span>
                                                    <?php echo t('modules_ad_start_message'); ?>
                                                </label>
                                                <label for="enable_start_ad_message" style="cursor: pointer;">
                                                    <input id="enable_start_ad_message" type="checkbox"
                                                        name="enable_start_ad_message" value="1"
                                                        <?php echo (!empty($enable_start_ad_message) ? 'checked' : ''); ?>
                                                        style="display: none;">
                                                    <i class="fas fa-toggle-<?php echo (!empty($enable_start_ad_message) ? 'on has-text-success' : 'off has-text-grey'); ?> fa-2x"></i>
                                                </label>
                                            </div>
                                            <div class="control">
                                                <textarea class="textarea ad-notice-input" name="ad_start_message"
                                                    maxlength="255" placeholder="<?php echo t('modules_ad_start_message_placeholder'); ?>"
                                                    rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_start_message ?? ''); ?></textarea>
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="ad_start_message">0</span>/255 characters
                                            </p>
                                        </div>
                                    </div>
                                    <div class="column is-4">
                                        <div class="field">
                                            <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                                <label class="label has-text-white mb-0">
                                                    <span class="icon mr-1"><i class="fas fa-stop"></i></span>
                                                    <?php echo t('modules_ad_end_message'); ?>
                                                </label>
                                                <label for="enable_end_ad_message" style="cursor: pointer;">
                                                    <input id="enable_end_ad_message" type="checkbox"
                                                        name="enable_end_ad_message" value="1"
                                                        <?php echo (!empty($enable_end_ad_message) ? 'checked' : ''); ?>
                                                        style="display: none;">
                                                    <i class="fas fa-toggle-<?php echo (!empty($enable_end_ad_message) ? 'on has-text-success' : 'off has-text-grey'); ?> fa-2x"></i>
                                                </label>
                                            </div>
                                            <div class="control">
                                                <textarea class="textarea ad-notice-input" name="ad_end_message"
                                                    maxlength="255" placeholder="<?php echo t('modules_ad_end_message_placeholder'); ?>"
                                                    rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_end_message ?? ''); ?></textarea>
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="ad_end_message">0</span>/255 characters
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="notification is-info mb-4">
                                    <p><strong>Note:</strong> The Ad Snoozed Message may be delayed in chat due to polling intervals.
                                        Additionally, there is a known issue where an extra warning message after the ad is snoozed does not
                                        post. This will be fixed in the next release.</p>
                                </div>
                                <div class="columns is-multiline">
                                    <div class="column is-12">
                                        <div class="field">
                                            <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                                <label class="label has-text-white mb-0">
                                                    <span class="icon mr-1"><i class="fas fa-clock"></i></span>
                                                    <?php echo t('modules_ad_snoozed_message'); ?>
                                                </label>
                                                <label for="enable_snoozed_ad_message" style="cursor: pointer;">
                                                    <input id="enable_snoozed_ad_message" type="checkbox"
                                                        name="enable_snoozed_ad_message" value="1"
                                                        <?php echo (!empty($enable_snoozed_ad_message) ? 'checked' : ''); ?>
                                                        style="display: none;">
                                                    <i class="fas fa-toggle-<?php echo (!empty($enable_snoozed_ad_message) ? 'on has-text-success' : 'off has-text-grey'); ?> fa-2x"></i>
                                                </label>
                                            </div>
                                            <div class="control">
                                                <textarea class="textarea ad-notice-input" name="ad_snoozed_message"
                                                    maxlength="255" placeholder="<?php echo t('modules_ad_snoozed_message_placeholder'); ?>"
                                                    rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_snoozed_message ?? ''); ?></textarea>
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count" data-field="ad_snoozed_message">0</span>/255 characters
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Enable/Disable Toggle -->
                                <div class="field mt-4">
                                    <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                        <div>
                                            <label class="label has-text-white mb-0">
                                                <span class="icon mr-2"><i class="fas fa-toggle-on"></i></span>
                                                <?php echo t('modules_enable_ad_notice'); ?>
                                            </label>
                                            <p class="help has-text-grey-light mt-2">
                                                Toggle this switch to enable or disable advertisement notices in your stream chat.
                                            </p>
                                        </div>
                                        <label for="enable_ad_notice" style="cursor: pointer;">
                                            <input id="enable_ad_notice" type="checkbox" name="enable_ad_notice"
                                                value="1" <?php echo (!empty($enable_ad_notice) ? 'checked' : ''); ?>
                                                style="display: none;">
                                            <i class="fas fa-toggle-<?php echo (!empty($enable_ad_notice) ? 'on has-text-success' : 'off has-text-grey'); ?> fa-2x"></i>
                                        </label>
                                    </div>
                                </div>
                                <!-- AI Ad Breaks Toggle -->
                                <div class="field mt-4">
                                    <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                        <div>
                                            <label class="label has-text-white mb-0">
                                                <span class="icon mr-2"><i class="fas fa-robot"></i></span>
                                                Enable AI-Powered Ad Break Messages
                                            </label>
                                            <p class="help has-text-grey-light mt-2">
                                                <span class="tag is-warning mr-2">Premium Feature</span>
                                                When enabled, the bot will use AI to generate dynamic, context-aware ad break
                                                messages based on recent chat activity. Requires Tier 2 subscription or higher.
                                            </p>
                                        </div>
                                        <label for="enable_ai_ad_breaks" style="cursor: pointer;">
                                            <input id="enable_ai_ad_breaks" type="checkbox" name="enable_ai_ad_breaks"
                                                value="1" <?php echo (!empty($enable_ai_ad_breaks) ? 'checked' : ''); ?>
                                                style="display: none;">
                                            <i class="fas fa-toggle-<?php echo (!empty($enable_ai_ad_breaks) ? 'on has-text-success' : 'off has-text-grey'); ?> fa-2x"></i>
                                        </label>
                                    </div>
                                </div>
                                <!-- Save Button -->
                                <div class="field mt-4">
                                    <div class="control">
                                        <button class="button is-primary" type="submit">
                                            <span class="icon mr-2"><i class="fas fa-save"></i></span>
                                            <span><?php echo t('modules_save_ad_notice_settings'); ?></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Twitch Event Alerts -->
                    <div class="tab-content" id="twitch-audio-alerts">
                        <div class="module-container">
                            <!-- Upload Card -->
                            <div class="columns is-desktop is-multiline is-centered">
                                <div class="column is-fullwidth" style="max-width: 1200px;">
                                    <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                                        <header class="card-header" style="border-bottom: 1px solid #23272f;">
                                            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                                <span class="icon mr-2"><i class="fas fa-upload"></i></span>
                                                <?php echo t('modules_upload_mp3_files'); ?>
                                            </span>
                                        </header>
                                        <div class="card-content">
                                            <!-- Storage Usage Info -->
                                            <div class="notification is-dark mb-4" style="background-color: #2b2f3a; border: 1px solid #4a4a4a;">
                                                <div class="level is-mobile">
                                                    <div class="level-left">
                                                        <div class="level-item">
                                                            <span class="icon mr-2"><i class="fas fa-database"></i></span>
                                                            <strong><?php echo t('alerts_storage_usage'); ?>:</strong>
                                                        </div>
                                                    </div>
                                                    <div class="level-right">
                                                        <div class="level-item">
                                                            <?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)
                                                        </div>
                                                    </div>
                                                </div>
                                                <progress class="progress is-success" value="<?php echo $storage_percentage; ?>" max="100" style="height: 0.75rem;"></progress>
                                            </div>
                                            <?php if (!empty($status)): ?>
                                                <article class="message is-info mb-4">
                                                    <div class="message-body has-text-white">
                                                        <?php echo $status; ?>
                                                    </div>
                                                </article>
                                            <?php endif; ?>
                                            <form action="module_data_post.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                                                <div class="file has-name is-fullwidth is-boxed mb-3">
                                                    <label class="file-label" style="width: 100%;">
                                                        <input class="file-input" type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3">
                                                        <span class="file-cta" style="background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                                                            <span class="file-label" style="display: flex; align-items: center; justify-content: center; font-size: 1.15em;">
                                                                <?php echo t('modules_choose_mp3_files'); ?>
                                                            </span>
                                                        </span>
                                                        <span class="file-name" id="file-list" style="text-align: center; background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                                                            <?php echo t('modules_no_files_selected'); ?>
                                                        </span>
                                                    </label>
                                                </div>
                                                <!-- Upload Status Container -->
                                                <div id="uploadStatusContainer" style="display: none;" class="mb-4">
                                                    <div class="notification is-info" style="background-color: #2b2f3a; border: 1px solid #4a8ef5;">
                                                        <div class="level is-mobile mb-2">
                                                            <div class="level-left">
                                                                <div class="level-item">
                                                                    <span class="icon mr-2 has-text-white"><i class="fas fa-spinner fa-pulse"></i></span>
                                                                    <strong id="uploadStatusText" class="has-text-white">Preparing upload...</strong>
                                                                </div>
                                                            </div>
                                                            <div class="level-right">
                                                                <div class="level-item">
                                                                    <span id="uploadProgressPercent" class="has-text-white" style="font-weight: 600;">0%</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <progress class="progress is-primary" id="uploadProgress" value="0" max="100" style="height: 1.5rem; border-radius: 0.75rem;">0%</progress>
                                                    </div>
                                                </div>
                                                <button class="button is-primary is-fullwidth" type="submit" name="submit" id="uploadBtn" style="font-weight: 600; font-size: 1.1rem;">
                                                    <span class="icon"><i class="fas fa-upload"></i></span>
                                                    <span id="uploadBtnText"><?php echo t('modules_upload_mp3_files'); ?></span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="columns is-desktop is-multiline is-centered">
                                <div class="column is-fullwidth" style="max-width: 1200px;">
                                    <div class="card has-background-dark has-text-white"
                                        style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                                        <header class="card-header"
                                            style="border-bottom: 1px solid #23272f; display: flex; justify-content: space-between; align-items: center;">
                                            <span class="card-header-title is-size-4 has-text-white"
                                                style="font-weight:700;">
                                                <span class="icon mr-2"><i class="fas fa-volume-up"></i></span>
                                                <?php echo t('modules_your_twitch_sound_alerts'); ?>
                                            </span>
                                            <div class="buttons">
                                                <button class="button is-danger" id="deleteSelectedBtn" disabled>
                                                    <span class="icon"><i class="fas fa-trash"></i></span>
                                                    <span><?php echo t('modules_delete_selected'); ?></span>
                                                </button>
                                            </div>
                                        </header>
                                        <div class="card-content">
                                            <?php $walkon_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..'));
                                            if (!empty($walkon_files)): ?>
                                                <form action="module_data_post.php" method="POST" id="deleteForm">
                                                    <div class="table-container">
                                                        <table class="table is-fullwidth has-background-dark"
                                                            id="twitchAlertsTable">
                                                            <thead>
                                                                <tr>
                                                                    <th style="width: 70px;" class="has-text-centered">
                                                                        <?php echo t('modules_select'); ?>
                                                                    </th>
                                                                    <th class="has-text-centered">
                                                                        <?php echo t('modules_file_name'); ?>
                                                                    </th>
                                                                    <th class="has-text-centered">
                                                                        <?php echo t('modules_twitch_event'); ?>
                                                                    </th>
                                                                    <th style="width: 80px;" class="has-text-centered">
                                                                        <?php echo t('modules_action'); ?>
                                                                    </th>
                                                                    <th style="width: 120px;" class="has-text-centered">
                                                                        <?php echo t('modules_test_audio'); ?>
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($walkon_files as $file): ?>
                                                                    <tr>
                                                                        <td class="has-text-centered is-vcentered"><input
                                                                                type="checkbox" class="is-checkradio"
                                                                                name="delete_files[]"
                                                                                value="<?php echo htmlspecialchars($file); ?>">
                                                                        </td>
                                                                        <td class="is-vcentered">
                                                                            <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>
                                                                        </td>
                                                                        <td class="has-text-centered is-vcentered">
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
                                                                            <form action="module_data_post.php" method="POST"
                                                                                class="mapping-form mt-2">
                                                                                <input type="hidden" name="sound_file"
                                                                                    value="<?php echo htmlspecialchars($file); ?>">
                                                                                <div class="select is-small is-fullwidth">
                                                                                    <select name="twitch_alert_id"
                                                                                        class="mapping-select"
                                                                                        style="background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                                                                                        <?php if ($current_mapped): ?>
                                                                                            <option value=""
                                                                                                class="has-text-danger">
                                                                                                <?php echo t('modules_remove_mapping'); ?>
                                                                                            </option>
                                                                                        <?php endif; ?>
                                                                                        <option value="">
                                                                                            <?php echo t('modules_select_event'); ?>
                                                                                        </option>
                                                                                        <?php
                                                                                        foreach ($allEvents as $evt) {
                                                                                            $isMapped = in_array($evt, $mappedEvents);
                                                                                            $isCurrent = ($current_mapped === $evt);
                                                                                            if ($isMapped && !$isCurrent)
                                                                                                continue;
                                                                                            echo '<option value="' . htmlspecialchars($evt) . '"';
                                                                                            if ($isCurrent)
                                                                                                echo ' selected';
                                                                                            echo '>' . t('modules_event_' . strtolower(str_replace(' ', '_', $evt))) . '</option>';
                                                                                        }
                                                                                        ?>
                                                                                    </select>
                                                                                </div>
                                                                            </form>
                                                                        </td>
                                                                        <td class="has-text-centered is-vcentered">
                                                                            <button type="button"
                                                                                class="delete-single button is-danger is-small"
                                                                                data-file="<?php echo htmlspecialchars($file); ?>">
                                                                                <span class="icon"><i
                                                                                        class="fas fa-trash"></i></span>
                                                                            </button>
                                                                        </td>
                                                                        <td class="has-text-centered is-vcentered">
                                                                            <button type="button"
                                                                                class="test-sound button is-primary is-small"
                                                                                data-file="twitch/<?php echo htmlspecialchars($file); ?>">
                                                                                <span class="icon"><i
                                                                                        class="fas fa-play"></i></span>
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <button type="submit" value="Delete Selected"
                                                        class="button is-danger mt-3" name="submit_delete"
                                                        style="display: none;">
                                                        <span class="icon"><i class="fas fa-trash"></i></span>
                                                        <span><?php echo t('modules_delete_selected'); ?></span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <div class="has-text-centered py-6">
                                                    <h2 class="title is-5 has-text-grey-light">
                                                        <?php echo t('modules_no_sound_alert_files_uploaded'); ?>
                                                    </h2>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Twitch Chat Alerts -->
                    <div class="tab-content" id="twitch-chat-alerts">
                        <div class="module-container">
                            <div class="columns is-vcentered mb-4">
                                <div class="column">
                                    <h2 class="title is-4 has-text-white mb-2">
                                        <span class="icon mr-2"><i class="fas fa-cog"></i></span>
                                        Chat Alert Messages
                                    </h2>
                                </div>
                            </div>
                            <form action="module_data_post.php" method="POST" id="chatAlertsForm">
                                <div class="columns is-multiline">
                                    <!-- General Events Column -->
                                    <div class="column is-6">
                                        <div class="level mb-4">
                                            <div class="level-left">
                                                <h5 class="title is-5 has-text-white mb-0">
                                                    <span class="icon mr-2"><i class="fas fa-users"></i></span>
                                                    General Events
                                                </h5>
                                            </div>
                                            <div class="level-right">
                                                <button type="button"
                                                    class="section-save-btn button is-success is-small"
                                                    data-section="general">
                                                    <span class="icon"><i class="fas fa-save"></i></span>
                                                    <span>Save General Events</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i
                                                        class="fas fa-heart"></i></span>
                                                <?php echo t('modules_follower_alert'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input chat-alert-input"
                                                    type="text" name="follower_alert"
                                                    maxlength="255"
                                                    value="<?php echo htmlspecialchars(isset($chat_alerts['follower_alert']) ? $chat_alerts['follower_alert'] : $default_chat_alerts['follower_alert']); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count"
                                                    data-field="follower_alert">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i
                                                        class="fas fa-gem"></i></span>
                                                <?php echo t('modules_cheer_alert'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input chat-alert-input"
                                                    type="text" name="cheer_alert"
                                                    maxlength="255"
                                                    value="<?php echo htmlspecialchars(isset($chat_alerts['cheer_alert']) ? $chat_alerts['cheer_alert'] : $default_chat_alerts['cheer_alert']); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count"
                                                    data-field="cheer_alert">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i
                                                        class="fas fa-user-friends"></i></span>
                                                <?php echo t('modules_raid_alert'); ?>
                                            </label>
                                            <div class="control">
                                                <input class="input chat-alert-input"
                                                    type="text" name="raid_alert"
                                                    maxlength="255"
                                                    value="<?php echo htmlspecialchars(isset($chat_alerts['raid_alert']) ? $chat_alerts['raid_alert'] : $default_chat_alerts['raid_alert']); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count"
                                                    data-field="raid_alert">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                    </div>
                                    <!-- Subscription Events Column -->
                                    <div class="column is-6">
                                        <div class="level mb-4">
                                            <div class="level-left">
                                                <h5 class="title is-5 has-text-white mb-0">
                                                    <span class="icon mr-2"><i class="fas fa-star"></i></span>
                                                    Subscription Events
                                                </h5>
                                            </div>
                                            <div class="level-right">
                                                <button type="button"
                                                    class="section-save-btn button is-success is-small"
                                                    data-section="subscription">
                                                    <span class="icon"><i class="fas fa-save"></i></span>
                                                    <span>Save Subscription Events</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i
                                                        class="fas fa-star"></i></span>
                                                <?php echo t('modules_subscription_alert'); ?>
                                                <span class="tag is-danger is-small">*</span>
                                            </label>
                                            <div class="control">
                                                <input class="input chat-alert-input"
                                                    type="text" name="subscription_alert"
                                                    maxlength="255"
                                                    value="<?php echo htmlspecialchars(isset($chat_alerts['subscription_alert']) ? $chat_alerts['subscription_alert'] : $default_chat_alerts['subscription_alert']); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count"
                                                    data-field="subscription_alert">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i
                                                        class="fas fa-gift"></i></span>
                                                <?php echo t('modules_gift_subscription_alert'); ?>
                                                <span class="tag is-danger is-small">*</span>
                                                <span class="icon has-text-warning"
                                                    title="This message uses the user variable to thank the person who sent the gift, not the recipients."><i
                                                        class="fas fa-info-circle"></i></span>
                                            </label>
                                            <div class="control">
                                                <input class="input chat-alert-input"
                                                    type="text" name="gift_subscription_alert"
                                                    maxlength="255"
                                                    value="<?php echo htmlspecialchars(isset($chat_alerts['gift_subscription_alert']) ? $chat_alerts['gift_subscription_alert'] : $default_chat_alerts['gift_subscription_alert']); ?>">
                                            </div>
                                            <p class="help has-text-grey-light">
                                                <span class="char-count"
                                                    data-field="gift_subscription_alert">0</span>/255
                                                characters
                                            </p>
                                        </div>
                                    </div>
                                    <!-- Hype Train Events (Full Width) -->
                                    <div class="column is-12">
                                        <div class="level mb-4">
                                            <div class="level-left">
                                                <h5 class="title is-5 has-text-white mb-0">
                                                    <span class="icon mr-2"><i class="fas fa-train"></i></span>
                                                    Hype Train Events
                                                </h5>
                                            </div>
                                            <div class="level-right">
                                                <button type="button"
                                                    class="section-save-btn button is-success is-small"
                                                    data-section="hype-train">
                                                    <span class="icon"><i class="fas fa-save"></i></span>
                                                    <span>Save Hype Train Events</span>
                                                </button>
                                            </div>
                                        </div>
                                            <div class="columns">
                                                <div class="column is-6">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i
                                                                    class="fas fa-play"></i></span>
                                                            <?php echo t('modules_hype_train_start'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input"
                                                                type="text" name="hype_train_start"
                                                                maxlength="255"
                                                                value="<?php echo htmlspecialchars(isset($chat_alerts['hype_train_start']) ? $chat_alerts['hype_train_start'] : $default_chat_alerts['hype_train_start']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count"
                                                                data-field="hype_train_start">0</span>/255
                                                            characters
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="column is-6">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i
                                                                    class="fas fa-stop"></i></span>
                                                            <?php echo t('modules_hype_train_end'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input"
                                                                type="text" name="hype_train_end"
                                                                maxlength="255"
                                                                value="<?php echo htmlspecialchars(isset($chat_alerts['hype_train_end']) ? $chat_alerts['hype_train_end'] : $default_chat_alerts['hype_train_end']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count"
                                                                data-field="hype_train_end">0</span>/255
                                                            characters
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- BETA Features Section -->
                                        <div class="column is-12">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-flask"></i></span>
                                                        BETA Features
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button"
                                                        class="section-save-btn button is-success is-small"
                                                        data-section="beta">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save BETA Features</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="notification is-warning is-light mb-4">
                                                <span class="icon mr-2"><i class="fas fa-flask"></i></span>
                                                <strong>BETA Features:</strong> These subscription upgrade alerts are currently in beta testing. They use the new Twitch EventSub "Chat Notification" system.
                                            </div>
                                            <div class="columns is-multiline">
                                                <div class="column is-6">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i
                                                                    class="fas fa-arrow-up"></i></span>
                                                            Gift Paid Upgrade <span
                                                                class="tag is-warning ml-2">BETA</span>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input"
                                                                type="text" name="gift_paid_upgrade"
                                                                maxlength="255"
                                                                value="<?php echo htmlspecialchars(isset($chat_alerts['gift_paid_upgrade']) ? $chat_alerts['gift_paid_upgrade'] : $default_chat_alerts['gift_paid_upgrade']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count"
                                                                data-field="gift_paid_upgrade">0</span>/255
                                                            characters. Placeholders: (user), (tier)
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="column is-6">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i
                                                                    class="fas fa-arrow-up"></i></span>
                                                            Prime Paid Upgrade <span
                                                                class="tag is-warning ml-2">BETA</span>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input"
                                                                type="text"
                                                                name="prime_paid_upgrade"
                                                                maxlength="255"
                                                                value="<?php echo htmlspecialchars(isset($chat_alerts['prime_paid_upgrade']) ? $chat_alerts['prime_paid_upgrade'] : $default_chat_alerts['prime_paid_upgrade']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count"
                                                                data-field="prime_paid_upgrade">0</span>/255
                                                            characters. Placeholders: (user), (tier)
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="column is-12">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i
                                                                    class="fas fa-gift"></i></span>
                                                            Pay It Forward <span
                                                                class="tag is-warning ml-2">BETA</span>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input"
                                                                type="text" name="pay_it_forward"
                                                                maxlength="255"
                                                                value="<?php echo htmlspecialchars(isset($chat_alerts['pay_it_forward']) ? $chat_alerts['pay_it_forward'] : $default_chat_alerts['pay_it_forward']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count"
                                                                data-field="pay_it_forward">0</span>/255
                                                            characters. Placeholders: (user),
                                                            (tier), (gifter)
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <!-- Automated Shoutouts -->
                    <div class="tab-content" id="automated-shoutouts">
                        <div class="module-container">
                            <div class="columns is-vcentered mb-4">
                                <div class="column">
                                    <h2 class="title is-4 has-text-white mb-2">
                                        <span class="icon has-text-info"><i class="fas fa-bullhorn"></i></span>
                                        Automated Shoutouts
                                    </h2>
                                    <p class="subtitle is-6 has-text-grey-light">Manage cooldown settings for automated
                                        shoutouts triggered by raids, follows, cheers, subs, and welcome messages.</p>
                                </div>
                            </div>
                            <form method="POST" action="module_data_post.php">
                                <!-- Cooldown Settings -->
                                <div class="box has-background-grey-dark has-text-white mb-4">
                                    <h3 class="title is-5 has-text-white mb-4">
                                        <span class="icon has-text-warning"><i class="fas fa-clock"></i></span>
                                        Cooldown Settings
                                    </h3>
                                    <div class="field">
                                        <label class="label has-text-white">Automated Shoutout Cooldown
                                            (minutes)</label>
                                        <div class="control">
                                            <input class="input" type="number" name="cooldown_minutes"
                                                value="<?php echo htmlspecialchars($automated_shoutout_cooldown); ?>"
                                                min="60" required>
                                        </div>
                                        <p class="help has-text-grey-light">Minimum 60 minutes due to Twitch API rate
                                            limits. This prevents the same user from receiving multiple automated
                                            shoutouts within the cooldown period.</p>
                                    </div>
                                    <div class="field">
                                        <div class="control">
                                            <button type="submit" class="button is-success">
                                                <span class="icon"><i class="fas fa-save"></i></span>
                                                <span>Save Cooldown Settings</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            <!-- Active Cooldowns -->
                            <div class="box has-background-grey-dark has-text-white">
                                <h3 class="title is-5 has-text-white mb-4">
                                    <span class="icon has-text-danger"><i class="fas fa-hourglass-half"></i></span>
                                    Users on Cooldown
                                </h3>
                                <p class="subtitle is-6 has-text-grey-light mb-4">These users recently received an
                                    automated shoutout and are currently in the cooldown period. The cooldown resets
                                    when the stream goes offline or the timer above is reached.</p>
                                <?php if (empty($automated_shoutout_tracking)): ?>
                                    <div class="notification is-info">
                                        <span class="icon"><i class="fas fa-info-circle"></i></span>
                                        No users are currently on automated shoutout cooldown.
                                    </div>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table
                                            class="table is-fullwidth is-hoverable has-background-grey-dark has-text-white">
                                            <thead>
                                                <tr>
                                                    <th class="has-text-white">User</th>
                                                    <th class="has-text-white">Last Shoutout</th>
                                                    <th class="has-text-white">Cooldown Remaining</th>
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
                                                    <tr<?php echo $is_expired ? ' class="has-text-grey"' : ''; ?>>
                                                        <td><?php echo htmlspecialchars($tracking['user_name']); ?></td>
                                                        <td><?php echo $shoutout_time->format('Y-m-d H:i:s'); ?></td>
                                                        <td>
                                                            <?php if ($is_expired): ?>
                                                                <span class="tag is-success">Ready</span>
                                                            <?php else: ?>
                                                                <span class="tag is-warning"><?php echo $remaining_minutes; ?>
                                                                    min</span>
                                                            <?php endif; ?>
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
                <!-- TTS Settings -->
                <div class="tab-content" id="tts-settings">
                    <div class="module-container">
                        <div class="columns is-vcentered mb-4">
                            <div class="column">
                                <h2 class="title is-4 has-text-white mb-2">
                                    <span class="icon has-text-info"><i class="fas fa-microphone"></i></span>
                                    Text-to-Speech (TTS) Settings
                                </h2>
                                <p class="subtitle is-6 has-text-grey-light">Configure the voice and language for TTS
                                    messages in your channel.</p>
                            </div>
                        </div>
                        <form method="POST" action="module_data_post.php">
                            <!-- TTS Configuration -->
                            <div class="box has-background-grey-dark has-text-white mb-4">
                                <h3 class="title is-5 has-text-white mb-4">
                                    <span class="icon has-text-primary"><i class="fas fa-cog"></i></span>
                                    Voice Configuration
                                </h3>
                                <div class="notification is-info is-light mb-4">
                                    <p class="has-text-dark">
                                        <span class="icon"><i class="fas fa-info-circle"></i></span>
                                        <strong>Need help choosing a voice?</strong><br>
                                        Visit our <a href="https://help.botofthespecter.com/tts_setup.php"
                                            target="_blank" class="has-text-link"><strong>TTS Setup Guide</strong></a>
                                        to hear voice samples and learn more about each option.
                                    </p>
                                </div>
                                <div class="columns">
                                    <div class="column is-6">
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i class="fas fa-volume-up"></i></span>
                                                TTS Voice
                                            </label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="tts_voice" required>
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
                                                </div>
                                            </div>
                                            <p class="help has-text-grey-light">Select the voice that will read TTS
                                                messages in your channel.</p>
                                        </div>
                                    </div>
                                    <div class="column is-6">
                                        <div class="field">
                                            <label class="label has-text-white">
                                                <span class="icon mr-1"><i class="fas fa-globe"></i></span>
                                                Language
                                            </label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="tts_language" required>
                                                        <option value="en" <?php echo ($tts_language === 'en') ? 'selected' : ''; ?>>English</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p class="help has-text-grey-light">Select the language for TTS messages.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="field mt-4">
                                    <div class="control">
                                        <button type="submit" class="button is-success">
                                            <span class="icon"><i class="fas fa-save"></i></span>
                                            <span>Save TTS Settings</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
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
            $('#file-list').append('<div class="notification is-info">Uploading files, please wait...</div>');
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
                            $('#file-list').html('<div class="notification is-success">Upload completed successfully!</div>');
                            // Reload the page after a short delay
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            $('#file-list').html('<div class="notification is-danger">Upload failed: ' + (result.status || 'Unknown error') + '</div>');
                        }
                    } catch (e) {
                        console.error("Error parsing response:", e);
                        $('#file-list').html('<div class="notification is-danger">Error processing server response</div>');
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                    console.error('Response:', jqXHR.responseText);
                    $('#file-list').html('<div class="notification is-danger">Upload failed: ' + textStatus + '<br>Please check file size limits and try again.</div>');
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
        // Update file name display for Bulma file input
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
            $('#uploadBtn').prop('disabled', true).removeClass('is-primary').addClass('is-loading');
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
                    $('#uploadBtn').prop('disabled', false).removeClass('is-loading').addClass('is-primary');
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
            const helpText = counter ? counter.closest('.help') : null;
            if (counter && helpText) {
                counter.textContent = currentLength;
                // Calculate percentage of 255 character limit
                const percentage = (currentLength / 255) * 100;
                // Remove existing color classes from help text
                helpText.classList.remove('has-text-success', 'has-text-warning', 'has-text-danger', 'has-text-grey-light');
                // Apply color based on percentage thresholds to entire help text
                if (percentage >= 91) {
                    helpText.classList.add('has-text-danger'); // Red for 91-100%
                } else if (percentage >= 81) {
                    helpText.classList.add('has-text-warning'); // Yellow for 81-90%
                } else {
                    helpText.classList.add('has-text-success'); // Green for 0-80%
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
                        this.classList.remove('is-success');
                        this.classList.add('is-info');
                        // Reset button after 2 seconds
                        setTimeout(() => {
                            this.innerHTML = originalText;
                            this.classList.remove('is-info');
                            this.classList.add('is-success');
                        }, 2000);
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        // Reset button state on error
                        this.innerHTML = originalText;
                        this.disabled = false;
                        // Show error feedback
                        this.innerHTML = '<span class="icon"><i class="fas fa-times"></i></span><span>Error</span>';
                        this.classList.remove('is-success');
                        this.classList.add('is-danger');
                        // Reset button after 2 seconds
                        setTimeout(() => {
                            this.innerHTML = originalText;
                            this.classList.remove('is-danger');
                            this.classList.add('is-success');
                        }, 2000);
                    });
            });
        });
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
                    icon.classList.remove('fa-toggle-off', 'has-text-grey');
                    icon.classList.add('fa-toggle-on', 'has-text-success');
                } else {
                    icon.classList.remove('fa-toggle-on', 'has-text-success');
                    icon.classList.add('fa-toggle-off', 'has-text-grey');
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
        // Update tab states (new tab-item structure)
        document.querySelectorAll('.tab-item').forEach(function (tab) {
            if (tab.getAttribute('onclick') === "loadTab('" + tabName + "')") {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
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
        const noDataNotification = document.querySelector('#automated-shoutouts .notification.is-info');
        if (!tableBody) return;
        if (data.tracking.length === 0) {
            // Show "no data" notification if exists, hide table
            if (noDataNotification) {
                noDataNotification.style.display = 'block';
            }
            const tableContainer = tableBody.closest('.table-container');
            if (tableContainer) {
                tableContainer.style.display = 'none';
            }
        } else {
            // Hide notification, show table
            if (noDataNotification) {
                noDataNotification.style.display = 'none';
            }
            const tableContainer = tableBody.closest('.table-container');
            if (tableContainer) {
                tableContainer.style.display = 'block';
            }
            // Update table rows
            let html = '';
            data.tracking.forEach(tracking => {
                const isExpired = tracking.is_expired;
                const rowClass = isExpired ? ' class="has-text-grey"' : '';
                const statusTag = isExpired 
                    ? '<span class="tag is-success">Ready</span>'
                    : `<span class="tag is-warning">${tracking.remaining_minutes} min</span>`;
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
        whitelistErrorMessage.className = 'help is-danger mt-2';
        whitelistErrorMessage.style.display = 'none';
        whitelistErrorMessage.textContent = "Can't whitelist a globally blocked term";
        whitelistInput.parentElement.parentElement.appendChild(whitelistErrorMessage);
        whitelistInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(whitelistCheckTimeout);
            // Check for spaces FIRST using raw value - immediate validation
            if (whitelistInput.value.includes(' ')) {
                whitelistInput.classList.add('is-danger');
                whitelistErrorMessage.textContent = 'No spaces allowed in URLs.';
                whitelistErrorMessage.style.display = 'block';
                whitelistButton.disabled = true;
                return;
            }
            // Reset state only if no spaces
            whitelistInput.classList.remove('is-danger');
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
                        whitelistInput.classList.add('is-danger');
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
                        whitelistInput.classList.add('is-danger');
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
            if (whitelistInput.classList.contains('is-danger')) {
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
        blacklistErrorMessage.className = 'help is-danger mt-2';
        blacklistErrorMessage.style.display = 'none';
        blacklistErrorMessage.textContent = 'Globally Blocked, unable to add to personal block list';
        blacklistInput.parentElement.parentElement.appendChild(blacklistErrorMessage);
        blacklistInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(blacklistCheckTimeout);
            // Check for spaces FIRST using raw value - immediate validation
            if (blacklistInput.value.includes(' ')) {
                blacklistInput.classList.add('is-danger');
                blacklistErrorMessage.textContent = 'No spaces allowed in URLs.';
                blacklistErrorMessage.style.display = 'block';
                blacklistButton.disabled = true;
                return;
            }
            // Reset state only if no spaces
            blacklistInput.classList.remove('is-danger');
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
                        blacklistInput.classList.add('is-danger');
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
                        blacklistInput.classList.add('is-danger');
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
            if (blacklistInput.classList.contains('is-danger')) {
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
        blockedTermErrorMessage.className = 'help is-danger mt-2';
        blockedTermErrorMessage.style.display = 'none';
        blockedTermErrorMessage.textContent = '';
        blockedTermInput.parentElement.parentElement.appendChild(blockedTermErrorMessage);
        blockedTermInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(blockedTermCheckTimeout);
            // Check for spaces FIRST using raw value (before trim) - immediate validation
            if (blockedTermInput.value.includes(' ')) {
                blockedTermInput.classList.add('is-danger');
                blockedTermErrorMessage.textContent = 'Only one word per entry allowed. No spaces permitted.';
                blockedTermErrorMessage.style.display = 'block';
                blockedTermButton.disabled = true;
                return;
            }
            // Reset state only if no spaces
            blockedTermInput.classList.remove('is-danger');
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
                        blockedTermInput.classList.add('is-danger');
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
            if (blockedTermInput.classList.contains('is-danger')) {
                e.preventDefault();
                return false;
            }
        });
    }
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>