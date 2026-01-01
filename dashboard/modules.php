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
        if (!is_array($current_blacklist)) $current_blacklist = [];
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
$stmt = $db->prepare("SELECT ad_upcoming_message, ad_start_message, ad_end_message, ad_snoozed_message, enable_ad_notice FROM ad_notice_settings LIMIT 1");
$stmt->execute();
$stmt->bind_result(
    $ad_upcoming_message,
    $ad_start_message,
    $ad_end_message,
    $ad_snoozed_message,
    $enable_ad_notice
);
$stmt->fetch();
$stmt->close();

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
    'hype_train_end' => 'The Hype Train has ended at level (level)!'
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
            <p class="mb-2">Use variables in your Welcome Messages, Ad Notices, and Twitch Chat Alerts to create dynamic, personalized messages for your community.</p>
            <p class="mb-2"><strong>What are Module Variables?</strong>
                            <br>Variables are placeholders that get replaced with real information when the message is sent.
                            <br>For example, <code>(user)</code> becomes the viewer's username, and <code>(bits)</code> shows the number of bits cheered.
            </p>
            <p class="mb-2"><strong>Available Variables:</strong>
                            <br>Each module has specific variables you can use - from usernames and viewer counts to subscription tiers and hype train levels.
            </p>
            <a href="https://help.botofthespecter.com/specter_module_variables.php" target="_blank" class="button is-primary is-small">
                <span class="icon"><i class="fas fa-code"></i></span>
                <span>View All Module Variables</span>
            </a>
        </div>
    </div>
</div>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-cogs"></i></span>
                    <?php echo t('modules_title'); ?>
                </span>
            </header>
            <div class="card-content">
                <?php if (isset($_SESSION['update_message'])): ?>
                    <div class="notification is-success"><?php echo $_SESSION['update_message']; unset($_SESSION['update_message']);?></div>
                <?php endif; ?>
                <!-- Tabs Navigation -->
                <div class="buttons is-centered mb-4 pb-4">
                    <button class="button is-info" onclick="loadTab('joke-blacklist')">
                        <span class="icon"><i class="fas fa-ban"></i></span>
                        <span><?php echo t('modules_tab_joke_blacklist'); ?></span>
                    </button>
                    <button class="button is-info" onclick="loadTab('welcome-messages')">
                        <span class="icon"><i class="fas fa-hand-sparkles"></i></span>
                        <span><?php echo t('modules_tab_welcome_messages'); ?></span>
                    </button>
                    <button class="button is-info" onclick="loadTab('chat-protection')">
                        <span class="icon"><i class="fas fa-shield-alt"></i></span>
                        <span><?php echo t('modules_tab_chat_protection'); ?></span>
                    </button>
                    <button class="button is-info" onclick="loadTab('game-deaths')">
                        <span class="icon"><i class="fas fa-skull-crossbones"></i></span>
                        <span>Game Deaths</span>
                    </button>
                    <button class="button is-info" onclick="loadTab('ad-notices')">
                        <span class="icon"><i class="fas fa-bullhorn"></i></span>
                        <span><?php echo t('modules_tab_ad_notices'); ?></span>
                    </button>
                    <button class="button is-info" onclick="loadTab('twitch-audio-alerts')">
                        <span class="icon"><i class="fas fa-volume-up"></i></span>
                        <span><?php echo t('modules_tab_twitch_event_alerts'); ?></span>
                    </button>
                    <button class="button is-info" onclick="loadTab('twitch-chat-alerts')">
                        <span class="icon"><i class="fas fa-comment-dots"></i></span>
                        <span><?php echo t('modules_tab_twitch_chat_alerts'); ?></span>
                    </button>
                </div>
                <style>.tab-content { display: none; }</style>
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
                                        <p class="subtitle is-6 has-text-danger"><?php echo t('modules_joke_blacklist_subtitle'); ?></p>
                                    </div>
                                    <div class="column is-narrow">
                                        <!-- Joke Command Status Control -->
                                        <div class="box has-background-grey-darker p-3" style="min-width: 420px;">
                                            <div class="field">
                                                <div class="field is-grouped is-grouped-centered">
                                                    <div class="control">
                                                        <div class="tags">
                                                            <span class="tag is-dark is-medium">
                                                                <span class="icon is-small mr-1"><i class="fas fa-terminal"></i></span>
                                                                Joke Command
                                                            </span>
                                                            <span class="tag is-medium <?php echo ($joke_command_status == 'Enabled') ? 'is-success' : 'is-danger'; ?>">
                                                                <?php echo ($joke_command_status == 'Enabled') ? t('builtin_commands_status_enabled') : t('builtin_commands_status_disabled'); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="control">
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="toggle_joke_command" value="1">
                                                            <input type="hidden" name="joke_command_status" value="<?php echo ($joke_command_status == 'Enabled') ? 'Disabled' : 'Enabled'; ?>">
                                                            <button type="submit" class="button is-small <?php echo ($joke_command_status == 'Enabled') ? 'is-danger' : 'is-success'; ?>">
                                                                <span class="icon is-small">
                                                                    <i class="fas <?php echo ($joke_command_status == 'Enabled') ? 'fa-times' : 'fa-check'; ?>"></i>
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
                                                    <input type="checkbox" name="blacklist[]" value="<?php echo $cat_value; ?>"<?php echo (is_array($current_blacklist) && in_array($cat_value, $current_blacklist)) ? " checked" : ""; ?>>
                                                    <?php echo t($cat_label_key); ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="field">
                                    <div class="control">
                                        <button class="button is-primary" type="submit"><?php echo t('modules_save_blacklist_settings'); ?></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
    <div class="tab-content" id="welcome-messages">
        <div class="module-container">
            <!-- Welcome Messages Configuration Form -->
            <div class="columns is-desktop is-multiline is-centered">
                <div class="column is-fullwidth" style="max-width: 1200px;">
                    <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                        <header class="card-header" style="border-bottom: 1px solid #23272f;">
                            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                <span class="icon mr-2"><i class="fas fa-cog"></i></span>
                                Welcome Message Configuration
                            </span>
                        </header>
                        <div class="card-content">
                            <form method="POST" action="module_data_post.php">
                                <div class="columns is-multiline">
                                    <!-- Regular Members Column -->
                                    <div class="column is-6">
                                        <div class="box has-background-grey-darker" style="height: 100%; min-height: 320px; display: flex; flex-direction: column;">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-users"></i></span>
                                                        Regular Members
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button" class="section-save-btn button is-success is-small" data-section="regular-members">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div style="flex-grow: 1;">
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-plus"></i></span>
                                                        <?php echo t('modules_welcome_new_member_label'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input welcome-message-input" type="text" name="new_default_welcome_message" maxlength="255" value="<?php echo htmlspecialchars($new_default_welcome_message !== '' ? $new_default_welcome_message : t('modules_welcome_new_member_default')); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="new_default_welcome_message">0</span>/255 characters
                                                    </p>
                                                </div>
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-check"></i></span>
                                                        <?php echo t('modules_welcome_returning_member_label'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input welcome-message-input" type="text" name="default_welcome_message" maxlength="255" value="<?php echo htmlspecialchars($default_welcome_message !== '' ? $default_welcome_message : t('modules_welcome_returning_member_default')); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="default_welcome_message">0</span>/255 characters
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- VIP Members Column -->
                                    <div class="column is-6">
                                        <div class="box has-background-grey-darker" style="height: 100%; min-height: 320px; display: flex; flex-direction: column;">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-gem"></i></span>
                                                        VIP Members
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button" class="section-save-btn button is-success is-small" data-section="vip-members">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div style="flex-grow: 1;">
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-plus"></i></span>
                                                        <?php echo t('modules_welcome_new_vip_label'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input welcome-message-input" type="text" name="new_default_vip_welcome_message" maxlength="255" value="<?php echo htmlspecialchars($new_default_vip_welcome_message !== '' ? $new_default_vip_welcome_message : t('modules_welcome_new_vip_default')); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="new_default_vip_welcome_message">0</span>/255 characters
                                                    </p>
                                                </div>
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-check"></i></span>
                                                        <?php echo t('modules_welcome_returning_vip_label'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input welcome-message-input" type="text" name="default_vip_welcome_message" maxlength="255" value="<?php echo htmlspecialchars($default_vip_welcome_message !== '' ? $default_vip_welcome_message : t('modules_welcome_returning_vip_default')); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="default_vip_welcome_message">0</span>/255 characters
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Moderator Members -->
                                    <div class="column is-12">
                                        <div class="box has-background-grey-darker">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-shield-alt"></i></span>
                                                        Moderators
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button" class="section-save-btn button is-success is-small" data-section="moderators">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save</span>
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
                                                            <input class="input welcome-message-input" type="text" name="new_default_mod_welcome_message" maxlength="255" value="<?php echo htmlspecialchars($new_default_mod_welcome_message !== '' ? $new_default_mod_welcome_message : t('modules_welcome_new_mod_default')); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="new_default_mod_welcome_message">0</span>/255 characters
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
                                                            <input class="input welcome-message-input" type="text" name="default_mod_welcome_message" maxlength="255" value="<?php echo htmlspecialchars($default_mod_welcome_message !== '' ? $default_mod_welcome_message : t('modules_welcome_returning_mod_default')); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="default_mod_welcome_message">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Enable/Disable Toggle -->
                                            <div class="field mt-4">
                                                <label class="checkbox">
                                                    <input type="checkbox" name="send_welcome_messages" value="1" <?php echo ($send_welcome_messages ? 'checked' : ''); ?>>
                                                    <?php echo t('modules_enable_welcome_messages'); ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Save Button -->
                                <div class="field mt-6">
                                    <div class="control has-text-centered">
                                        <button class="button is-primary" type="submit" style="padding: 0.75rem 2rem; font-size: 1rem;">
                                            <span class="icon mr-2">
                                                <i class="fas fa-save"></i>
                                            </span>
                                            <span><?php echo t('modules_save_welcome_settings'); ?></span>
                                        </button>
                                        <p class="help has-text-grey-light mt-2">
                                            Save your welcome message configuration and settings.
                                        </p>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-content" id="chat-protection">
        <div class="module-container">
            <!-- Chat Protection Configuration -->
            <div class="columns is-desktop is-multiline is-centered">
                <div class="column is-fullwidth" style="max-width: 1200px;">
                    <?php include 'protection.php'; ?>
                </div>
            </div>
        </div>
    <br>
    </div>
    <div class="tab-content" id="game-deaths">
        <div class="module-container">
            <!-- Game Deaths Configuration -->
            <div class="columns is-desktop is-multiline is-centered">
                <div class="column is-fullwidth" style="max-width: 1200px;">
                    <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                        <header class="card-header" style="border-bottom: 1px solid #23272f;">
                            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                <span class="icon mr-2"><i class="fas fa-skull-crossbones"></i></span>
                                Game Deaths Configuration
                            </span>
                        </header>
                        <div class="card-content">
                            <!-- Configuration Note -->
                            <div class="notification is-info mb-4">
                                <p class="has-text-dark">
                                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                                    <strong>Game Deaths Configuration:</strong><br>
                                    Configure games to ignore when counting deaths.<br>
                                    Deaths in these games will not be added to the total death counter for the !deathadd command.
                                </p>
                            </div>
                            <!-- Add Game Form -->
                            <form method="POST" action="module_data_post.php" class="mb-4">
                                <div class="field has-addons">
                                    <div class="control is-expanded">
                                        <input class="input" type="text" name="ignore_game_name" placeholder="Enter game name to ignore (e.g., Minecraft, Fortnite)" maxlength="100" required>
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
                            <div class="box has-background-grey-darker">
                                <h4 class="title is-5 has-text-white mb-3">
                                    <span class="icon mr-2"><i class="fas fa-list"></i></span>
                                    Currently Ignored Games
                                </h4>
                                <div class="content">
                                    <?php
                                    // Load ignored games from database (placeholder - will need backend implementation)
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
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Ad Notices -->
    <div class="tab-content" id="ad-notices">
        <div class="module-container">
            <!-- Ad Notices Configuration Form -->
            <div class="columns is-desktop is-multiline is-centered">
                <div class="column is-fullwidth" style="max-width: 1200px;">
                    <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                        <header class="card-header" style="border-bottom: 1px solid #23272f;">
                            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                <span class="icon mr-2"><i class="fas fa-cog"></i></span>
                                Ad Notice Messages
                            </span>
                        </header>
                        <div class="card-content">
                            <form method="POST" action="module_data_post.php">
                                <div class="columns is-multiline">
                                    <!-- Ad Messages Column -->
                                    <div class="column is-12">
                                        <div class="box has-background-grey-darker">
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
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i class="fas fa-exclamation-triangle"></i></span>
                                                            <?php echo t('modules_ad_upcoming_message'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <textarea class="textarea ad-notice-input" name="ad_upcoming_message" maxlength="255" placeholder="<?php echo t('modules_ad_upcoming_message_placeholder'); ?>" rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_upcoming_message ?? ''); ?></textarea>
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="ad_upcoming_message">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="column is-4">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i class="fas fa-play"></i></span>
                                                            <?php echo t('modules_ad_start_message'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <textarea class="textarea ad-notice-input" name="ad_start_message" maxlength="255" placeholder="<?php echo t('modules_ad_start_message_placeholder'); ?>" rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_start_message ?? ''); ?></textarea>
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="ad_start_message">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="column is-4">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i class="fas fa-stop"></i></span>
                                                            <?php echo t('modules_ad_end_message'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <textarea class="textarea ad-notice-input" name="ad_end_message" maxlength="255" placeholder="<?php echo t('modules_ad_end_message_placeholder'); ?>" rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_end_message ?? ''); ?></textarea>
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="ad_end_message">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="notification is-info mb-4">
                                                <p><strong>Note:</strong> The Ad Snoozed Message may be delayed in chat due to polling intervals. Additionally, there is a known issue where an extra warning message after the ad is snoozed does not post. This will be fixed in the next release.</p>
                                            </div>
                                            <div class="columns is-multiline">
                                                <div class="column is-12">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i class="fas fa-clock"></i></span>
                                                            <?php echo t('modules_ad_snoozed_message'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <textarea class="textarea ad-notice-input" name="ad_snoozed_message" maxlength="255" placeholder="<?php echo t('modules_ad_snoozed_message_placeholder'); ?>" rows="3" style="word-wrap: break-word; white-space: pre-wrap;"><?php echo htmlspecialchars($ad_snoozed_message ?? ''); ?></textarea>
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="ad_snoozed_message">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Enable/Disable Toggle -->
                                            <div class="field mt-4">
                                                <div class="columns is-vcentered">
                                                    <div class="column">
                                                        <label class="label has-text-white mb-0">
                                                            <span class="icon mr-2"><i class="fas fa-toggle-on"></i></span>
                                                            <?php echo t('modules_enable_ad_notice'); ?>
                                                        </label>
                                                        <p class="help has-text-grey-light mt-2">
                                                            Toggle this switch to enable or disable advertisement notices in your stream chat.
                                                        </p>
                                                    </div>
                                                    <div class="column is-narrow">
                                                        <label class="switch is-medium is-success" aria-label="<?php echo t('modules_enable_ad_notice'); ?>">
                                                            <input type="checkbox" name="enable_ad_notice" value="1" <?php echo (!empty($enable_ad_notice) ? 'checked' : ''); ?> style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;">
                                                            <span class="check"></span>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Save Button -->
                                <div class="field mt-6">
                                    <div class="control has-text-centered">
                                        <button class="button is-primary" type="submit" style="padding: 0.75rem 2rem; font-size: 1rem;">
                                            <span class="icon mr-2">
                                                <i class="fas fa-save"></i>
                                            </span>
                                            <span><?php echo t('modules_save_ad_notice_settings'); ?></span>
                                        </button>
                                        <p class="help has-text-grey-light mt-2">
                                            Save your ad notice configuration and messages.
                                        </p>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Twitch Event Alerts -->
    <div class="tab-content" id="twitch-audio-alerts">
        <div class="module-container">
            <div class="columns is-desktop is-multiline is-centered">
                <div class="column is-fullwidth" style="max-width: 1200px;">
                    <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                        <header class="card-header" style="border-bottom: 1px solid #23272f; display: flex; justify-content: space-between; align-items: center;">
                            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                <span class="icon mr-2"><i class="fas fa-volume-up"></i></span>
                                <?php echo t('modules_your_twitch_sound_alerts'); ?>
                            </span>
                            <div class="buttons">
                                <button class="button is-danger" id="deleteSelectedBtn" disabled>
                                    <span class="icon"><i class="fas fa-trash"></i></span>
                                    <span><?php echo t('modules_delete_selected'); ?></span>
                                </button>
                                <button class="button is-primary" id="openUploadModal">
                                    <span class="icon"><i class="fas fa-upload"></i></span>
                                    <span><?php echo t('modules_upload_mp3_files'); ?></span>
                                </button>
                            </div>
                        </header>
                        <div class="card-content">
                            <?php $walkon_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..')); if (!empty($walkon_files)) : ?>
                            <form action="module_data_post.php" method="POST" id="deleteForm">
                                <div class="table-container">
                                    <table class="table is-fullwidth has-background-dark" id="twitchAlertsTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 70px;" class="has-text-centered"><?php echo t('modules_select'); ?></th>
                                                <th class="has-text-centered"><?php echo t('modules_file_name'); ?></th>
                                                <th class="has-text-centered"><?php echo t('modules_twitch_event'); ?></th>
                                                <th style="width: 80px;" class="has-text-centered"><?php echo t('modules_action'); ?></th>
                                                <th style="width: 120px;" class="has-text-centered"><?php echo t('modules_test_audio'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($walkon_files as $file): ?>
                                            <tr>
                                                <td class="has-text-centered is-vcentered"><input type="checkbox" class="is-checkradio" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                                <td class="is-vcentered"><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
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
                                                    <form action="module_data_post.php" method="POST" class="mapping-form mt-2">
                                                        <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                                        <div class="select is-small is-fullwidth">
                                                            <select name="twitch_alert_id" class="mapping-select" style="background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                                                                <?php if ($current_mapped): ?>
                                                                    <option value="" class="has-text-danger"><?php echo t('modules_remove_mapping'); ?></option>
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
                                                        </div>
                                                    </form>
                                                </td>
                                                <td class="has-text-centered is-vcentered">
                                                    <button type="button" class="delete-single button is-danger is-small" data-file="<?php echo htmlspecialchars($file); ?>">
                                                        <span class="icon"><i class="fas fa-trash"></i></span>
                                                    </button>
                                                </td>
                                                <td class="has-text-centered is-vcentered">
                                                    <button type="button" class="test-sound button is-primary is-small" data-file="twitch/<?php echo htmlspecialchars($file); ?>">
                                                        <span class="icon"><i class="fas fa-play"></i></span>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="submit" value="Delete Selected" class="button is-danger mt-3" name="submit_delete" style="display: none;">
                                    <span class="icon"><i class="fas fa-trash"></i></span>
                                    <span><?php echo t('modules_delete_selected'); ?></span>
                                </button>
                            </form>
                            <?php else: ?>
                                <div class="has-text-centered py-6">
                                    <h2 class="title is-5 has-text-grey-light"><?php echo t('modules_no_sound_alert_files_uploaded'); ?></h2>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Upload Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head has-background-dark">
                <p class="modal-card-title has-text-white">
                    <span class="icon mr-2"><i class="fas fa-upload"></i></span>
                    <?php echo t('modules_upload_mp3_files'); ?>
                </p>
                <button class="delete" aria-label="close" id="closeUploadModal"></button>
            </header>
            <section class="modal-card-body has-background-dark has-text-white">
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
                    <div class="mt-4" style="position: relative;">
                        <progress class="progress is-success" value="<?php echo $storage_percentage; ?>" max="100" style="height: 1.25rem; border-radius: 0.75rem;"></progress>
                        <div class="has-text-centered" style="margin-top: -1.7rem; margin-bottom: 0.7rem; font-size: 0.98rem; font-weight: 500; color: #fff; width: 100%; position: relative; z-index: 2;">
                            <?php echo round($storage_percentage, 2); ?>% &mdash; <?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB <?php echo t('modules_of'); ?> <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB <?php echo t('modules_used'); ?>
                        </div>
                    </div>
                    <?php if (!empty($status)) : ?>
                        <article class="message is-info mt-4">
                            <div class="message-body">
                                <?php echo $status; ?>
                            </div>
                        </article>
                    <?php endif; ?>
                </form>
            </section>
            <footer class="modal-card-foot has-background-dark">
                <button class="button is-primary" type="submit" form="uploadForm" name="submit">
                    <span class="icon"><i class="fas fa-upload"></i></span>
                    <span><?php echo t('modules_upload_mp3_files'); ?></span>
                </button>
                <button class="button" id="cancelUploadModal"><?php echo t('cancel'); ?></button>
            </footer>
        </div>
    </div>
    <!-- Twitch Chat Alerts -->
    <div class="tab-content" id="twitch-chat-alerts">
        <div class="module-container">
            <!-- Chat Alerts Configuration Form -->
            <div class="columns is-desktop is-multiline is-centered">
                <div class="column is-fullwidth" style="max-width: 1200px;">
                    <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                        <header class="card-header" style="border-bottom: 1px solid #23272f;">
                            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                <span class="icon mr-2"><i class="fas fa-cog"></i></span>
                                Chat Alert Messages
                            </span>
                        </header>
                        <div class="card-content">
                            <form action="module_data_post.php" method="POST" id="chatAlertsForm">
                                <div class="columns is-multiline">
                                    <!-- General Events Column -->
                                    <div class="column is-6">
                                        <div class="box has-background-grey-darker" style="height: 100%; min-height: 420px; display: flex; flex-direction: column;">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-users"></i></span>
                                                        General Events
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button" class="section-save-btn button is-success is-small" data-section="general">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div style="flex-grow: 1;">
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-heart"></i></span>
                                                        <?php echo t('modules_follower_alert'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input chat-alert-input" type="text" name="follower_alert" maxlength="255"
                                                               value="<?php echo htmlspecialchars(isset($chat_alerts['follower_alert']) ? $chat_alerts['follower_alert'] : $default_chat_alerts['follower_alert']); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="follower_alert">0</span>/255 characters
                                                    </p>
                                                </div>
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-gem"></i></span>
                                                        <?php echo t('modules_cheer_alert'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input chat-alert-input" type="text" name="cheer_alert" maxlength="255"
                                                               value="<?php echo htmlspecialchars(isset($chat_alerts['cheer_alert']) ? $chat_alerts['cheer_alert'] : $default_chat_alerts['cheer_alert']); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="cheer_alert">0</span>/255 characters
                                                    </p>
                                                </div>
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-user-friends"></i></span>
                                                        <?php echo t('modules_raid_alert'); ?>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input chat-alert-input" type="text" name="raid_alert" maxlength="255"
                                                               value="<?php echo htmlspecialchars(isset($chat_alerts['raid_alert']) ? $chat_alerts['raid_alert'] : $default_chat_alerts['raid_alert']); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="raid_alert">0</span>/255 characters
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Subscription Events Column -->
                                    <div class="column is-6">
                                        <div class="box has-background-grey-darker" style="height: 100%; min-height: 420px; display: flex; flex-direction: column;">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-star"></i></span>
                                                        Subscription Events
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button" class="section-save-btn button is-success is-small" data-section="subscription">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div style="flex-grow: 1;">
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-star"></i></span>
                                                        <?php echo t('modules_subscription_alert'); ?> 
                                                        <span class="tag is-danger is-small">*</span>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input chat-alert-input" type="text" name="subscription_alert" maxlength="255"
                                                               value="<?php echo htmlspecialchars(isset($chat_alerts['subscription_alert']) ? $chat_alerts['subscription_alert'] : $default_chat_alerts['subscription_alert']); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="subscription_alert">0</span>/255 characters
                                                    </p>
                                                </div>
                                                <div class="field">
                                                    <label class="label has-text-white">
                                                        <span class="icon mr-1"><i class="fas fa-gift"></i></span>
                                                        <?php echo t('modules_gift_subscription_alert'); ?> 
                                                        <span class="tag is-danger is-small">*</span>
                                                        <span class="icon has-text-warning" title="This message uses the user variable to thank the person who sent the gift, not the recipients."><i class="fas fa-info-circle"></i></span>
                                                    </label>
                                                    <div class="control">
                                                        <input class="input chat-alert-input" type="text" name="gift_subscription_alert" maxlength="255"
                                                               value="<?php echo htmlspecialchars(isset($chat_alerts['gift_subscription_alert']) ? $chat_alerts['gift_subscription_alert'] : $default_chat_alerts['gift_subscription_alert']); ?>">
                                                    </div>
                                                    <p class="help has-text-grey-light">
                                                        <span class="char-count" data-field="gift_subscription_alert">0</span>/255 characters
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Hype Train Events (Full Width) -->
                                    <div class="column is-12">
                                        <div class="box has-background-grey-darker">
                                            <div class="level mb-4">
                                                <div class="level-left">
                                                    <h5 class="title is-5 has-text-white mb-0">
                                                        <span class="icon mr-2"><i class="fas fa-train"></i></span>
                                                        Hype Train Events
                                                    </h5>
                                                </div>
                                                <div class="level-right">
                                                    <button type="button" class="section-save-btn button is-success is-small" data-section="hype-train">
                                                        <span class="icon"><i class="fas fa-save"></i></span>
                                                        <span>Save</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="columns">
                                                <div class="column is-6">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i class="fas fa-play"></i></span>
                                                            <?php echo t('modules_hype_train_start'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input" type="text" name="hype_train_start" maxlength="255"
                                                                   value="<?php echo htmlspecialchars(isset($chat_alerts['hype_train_start']) ? $chat_alerts['hype_train_start'] : $default_chat_alerts['hype_train_start']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="hype_train_start">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="column is-6">
                                                    <div class="field">
                                                        <label class="label has-text-white">
                                                            <span class="icon mr-1"><i class="fas fa-stop"></i></span>
                                                            <?php echo t('modules_hype_train_end'); ?>
                                                        </label>
                                                        <div class="control">
                                                            <input class="input chat-alert-input" type="text" name="hype_train_end" maxlength="255"
                                                                   value="<?php echo htmlspecialchars(isset($chat_alerts['hype_train_end']) ? $chat_alerts['hype_train_end'] : $default_chat_alerts['hype_train_end']); ?>">
                                                        </div>
                                                        <p class="help has-text-grey-light">
                                                            <span class="char-count" data-field="hype_train_end">0</span>/255 characters
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Save All Button -->
                                <div class="field mt-6">
                                    <div class="control has-text-centered">
                                        <button id="save-all-btn" class="button is-success" type="submit" style="padding: 0.75rem 2rem; font-size: 1rem;">
                                            <span class="icon mr-2">
                                                <i class="fas fa-save"></i>
                                            </span>
                                            <span><?php echo t('modules_save_all_settings'); ?></span>
                                        </button>
                                        <p class="help has-text-grey-light mt-2">
                                            <?php echo t('modules_save_all_description'); ?>
                                        </p>
                                    </div>
                                </div>
                            </form>
                        </div>
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
document.addEventListener('DOMContentLoaded', function() {
    // File upload handling
    let dropArea = document.getElementById('drag-area');
    let fileInput = document.getElementById('filesToUpload');
    let fileList = document.getElementById('file-list');
    if (dropArea) {
        dropArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragging');
        });

        dropArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragging');
        });

        dropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragging');
            let dt = e.dataTransfer;
            let files = dt.files;
            fileInput.files = files;
            updateFileList(files);
        });

        dropArea.addEventListener('click', function() {
            fileInput.click();
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            updateFileList(this.files);
            if(this.files.length > 0) {
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
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = (e.loaded / e.total) * 100;
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
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
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $('#file-list').html('<div class="notification is-danger">Upload failed: ' + (result.status || 'Unknown error') + '</div>');
                    }
                } catch (e) {
                    console.error("Error parsing response:", e);
                    $('#file-list').html('<div class="notification is-danger">Error processing server response</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                console.error('Response:', jqXHR.responseText);
                $('#file-list').html('<div class="notification is-danger">Upload failed: ' + textStatus + '<br>Please check file size limits and try again.</div>');
            }
        });
    }

    // Test sound buttons
    document.querySelectorAll('.test-sound').forEach(function(button) {
        button.addEventListener('click', function() {
            const fileName = this.getAttribute('data-file');
            sendStreamEvent('SOUND_ALERT', fileName);
        });
    });

    // Delete single file buttons
    document.querySelectorAll('.delete-single').forEach(function(button) {
        button.addEventListener('click', function() {
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
    // Modal controls for Twitch audio alerts
    $('#openUploadModal').on('click', function() {
        $('#uploadModal').addClass('is-active');
    });
    $('#closeUploadModal, #cancelUploadModal, .modal-background').on('click', function() {
        $('#uploadModal').removeClass('is-active');
    });

    // Handle delete selected button for Twitch audio alerts
    $('#deleteSelectedBtn').on('click', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked');
        if (checkedBoxes.length > 0) {
            if (confirm('Are you sure you want to delete the selected ' + checkedBoxes.length + ' file(s)?')) {
                $('#deleteForm').submit();
            }
        }
    });

    // Monitor checkbox changes to enable/disable delete button for Twitch audio alerts
    $(document).on('change', 'input[name="delete_files[]"]', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
        $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
    });

    // Update file name display for Bulma file input
    $('#filesToUpload').on('change', function() {
        let files = this.files;
        let fileNames = [];
        for (let i = 0; i < files.length; i++) {
            fileNames.push(files[i].name);
        }
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('modules_no_files_selected'); ?>');
    });

    // AJAX upload with progress bar
    $('#uploadForm').on('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        $.ajax({
            url: 'module_data_post.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = (e.loaded / e.total) * 100;
                        $('.upload-progress-bar').val(percentComplete).text(Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                location.reload();
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
            }
        });
    });

    // Add event listener for mapping select boxes
    $('.mapping-select').on('change', function() {
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
    document.querySelectorAll('.chat-alert-input').forEach(function(input) {
        // Update counter on page load
        updateCharCount(input);
        
        // Update counter on input
        input.addEventListener('input', function() {
            updateCharCount(this);
        });
        
        // Prevent typing beyond 255 characters
        input.addEventListener('keydown', function(e) {
            if (this.value.length >= 255 && e.key !== 'Backspace' && e.key !== 'Delete' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
            }
        });
    });

    // Initialize character counters for ad notice inputs
    document.querySelectorAll('.ad-notice-input').forEach(function(input) {
        // Update counter on page load
        updateCharCount(input);
        
        // Update counter on input
        input.addEventListener('input', function() {
            updateCharCount(this);
        });
        
        // Prevent typing beyond 255 characters
        input.addEventListener('keydown', function(e) {
            if (this.value.length >= 255 && e.key !== 'Backspace' && e.key !== 'Delete' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
            }
        });
    });

    // Initialize character counters for welcome message inputs
    document.querySelectorAll('.welcome-message-input').forEach(function(input) {
        updateCharCount(input);
        input.addEventListener('input', function() {
            updateCharCount(this);
        });
        input.addEventListener('keydown', function(e) {
            if (this.value.length >= 255 && e.key !== 'Backspace' && e.key !== 'Delete' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
            }
        });
    });

    // Set initial character counts after DOM is fully loaded
    setTimeout(function() {
        document.querySelectorAll('.chat-alert-input, .ad-notice-input, .welcome-message-input').forEach(function(input) {
            updateCharCount(input);
        });
    }, 100);

    // Save All button feedback
    const saveAllBtn = document.getElementById('save-all-btn');
    if (saveAllBtn) {
        saveAllBtn.addEventListener('click', function(e) {
            // Change button to loading state
            this.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Saving...</span>';
            this.disabled = true;
            // Form will submit naturally since this is type="submit"
        });
    }

    // Add event listener for section save buttons
    document.querySelectorAll('.section-save-btn').forEach(function(button) {
        button.addEventListener('click', function() {
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

// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "notify_event.php";
    const params = `event=${eventType}&sound=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
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
    document.querySelectorAll('.tab-content').forEach(function(tab) {
        tab.style.display = 'none';
    });
    // Show the selected tab
    const activeTab = document.getElementById(tabName);
    if (activeTab) {
        activeTab.style.display = 'block';
    }
    // Update button states
    document.querySelectorAll('.buttons .button').forEach(function(button) {
        if (button.getAttribute('onclick') === "loadTab('" + tabName + "')") {
            button.classList.remove('is-info');
            button.classList.add('is-primary');
        } else {
            button.classList.remove('is-primary');
            button.classList.add('is-info');
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
document.addEventListener('DOMContentLoaded', function() {
    // Set initial active tab
    const initialTab = '<?php echo $activeTab; ?>';
    loadTab(initialTab);
    // ... existing code ...
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>