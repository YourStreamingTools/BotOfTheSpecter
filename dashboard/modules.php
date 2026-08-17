<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
require_once '/var/www/lib/require_auth.php';

// Page Title and Initial Variables
$pageTitle = t('modules_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
$updateMessage = '';
if (!empty($_SESSION['update_message'])) {
    $updateMessage = $_SESSION['update_message'];
    unset($_SESSION['update_message']);
}
session_write_close();

function modules_default_chat_alerts()
{
    return [
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
}

function modules_query_all(mysqli $db, string $sql): array
{
    $rows = [];
    if ($result = $db->query($sql)) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    return $rows;
}

function modules_expressive_voices(): array
{
    $voices = [];
    $cfgPath = is_file('/var/www/config/elevenlabs.php')
        ? '/var/www/config/elevenlabs.php'
        : dirname(__DIR__) . '/config/elevenlabs.php';
    if (!is_file($cfgPath)) {
        return $voices;
    }
    include $cfgPath;
    $key = isset($elevenlabs_api_key) ? trim((string) $elevenlabs_api_key) : '';
    if ($key === '') {
        return $voices;
    }
    $ch = curl_init('https://api.elevenlabs.io/v1/voices');
    if ($ch === false) {
        return $voices;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . $key],
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw) || $raw === '') {
        return $voices;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['voices']) || !is_array($data['voices'])) {
        return $voices;
    }
    foreach ($data['voices'] as $voice) {
        if (!is_array($voice) || empty($voice['voice_id']) || empty($voice['name'])) {
            continue;
        }
        $voices[] = [
            'id' => (string) $voice['voice_id'],
            'name' => (string) $voice['name'],
        ];
    }
    return $voices;
}

function modules_default_expressive_voice_id(array $voices): string
{
    foreach ($voices as $voice) {
        if (strcasecmp((string) ($voice['name'] ?? ''), 'Callum') === 0) {
            return (string) $voice['id'];
        }
    }
    return '';
}

function modules_build_list_payload(mysqli $db, mysqli $conn, $user_id, $username): array
{
    $timezone = 'UTC';
    $stmt = $db->prepare("SELECT timezone FROM profile");
    if ($stmt) {
        $stmt->execute();
        $channelData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $timezone = $channelData['timezone'] ?? 'UTC';
    }
    date_default_timezone_set($timezone);

    $current_blacklist = [];
    $stmt = $db->prepare("SELECT blacklist FROM joke_settings");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($blacklist_str);
        if ($stmt->fetch() && $blacklist_str) {
            $decoded = json_decode($blacklist_str, true);
            if (is_array($decoded)) {
                $current_blacklist = $decoded;
            }
        }
        $stmt->close();
    }

    $joke_command_status = 'Enabled';
    $stmt = $db->prepare("SELECT status FROM builtin_commands WHERE command = 'joke'");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($joke_status);
        if ($stmt->fetch() && $joke_status) {
            $joke_command_status = $joke_status;
        }
        $stmt->close();
    }

    $welcome = [
        'new_default_welcome_message' => '',
        'default_welcome_message' => '',
        'new_default_vip_welcome_message' => '',
        'default_vip_welcome_message' => '',
        'new_default_mod_welcome_message' => '',
        'default_mod_welcome_message' => '',
        'send_welcome_messages' => 0,
    ];
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
    if ($stmt) {
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
        if ($stmt->fetch()) {
            $welcome = [
                'new_default_welcome_message' => $new_default_welcome_message !== '' ? $new_default_welcome_message : t('modules_welcome_new_member_default'),
                'default_welcome_message' => $default_welcome_message !== '' ? $default_welcome_message : t('modules_welcome_returning_member_default'),
                'new_default_vip_welcome_message' => $new_default_vip_welcome_message !== '' ? $new_default_vip_welcome_message : t('modules_welcome_new_vip_default'),
                'default_vip_welcome_message' => $default_vip_welcome_message !== '' ? $default_vip_welcome_message : t('modules_welcome_returning_vip_default'),
                'new_default_mod_welcome_message' => $new_default_mod_welcome_message !== '' ? $new_default_mod_welcome_message : t('modules_welcome_new_mod_default'),
                'default_mod_welcome_message' => $default_mod_welcome_message !== '' ? $default_mod_welcome_message : t('modules_welcome_returning_mod_default'),
                'send_welcome_messages' => (int) $send_welcome_messages,
            ];
        } else {
            $welcome['new_default_welcome_message'] = t('modules_welcome_new_member_default');
            $welcome['default_welcome_message'] = t('modules_welcome_returning_member_default');
            $welcome['new_default_vip_welcome_message'] = t('modules_welcome_new_vip_default');
            $welcome['default_vip_welcome_message'] = t('modules_welcome_returning_vip_default');
            $welcome['new_default_mod_welcome_message'] = t('modules_welcome_new_mod_default');
            $welcome['default_mod_welcome_message'] = t('modules_welcome_returning_mod_default');
        }
        $stmt->close();
    }

    $ad = [
        'ad_upcoming_message' => '',
        'ad_1min_message' => '',
        'ad_start_message' => '',
        'ad_end_message' => '',
        'ad_snoozed_message' => '',
        'enable_ad_notice' => 0,
        'enable_upcoming_ad_message' => 1,
        'enable_1min_ad_message' => 0,
        'enable_start_ad_message' => 1,
        'enable_end_ad_message' => 1,
        'enable_snoozed_ad_message' => 1,
        'enable_ai_ad_breaks' => 0,
        'enable_raid_ad_snooze' => 1,
        'raid_ad_snooze_window_minutes' => 10,
        'enable_raid_ad_snooze_message' => 1,
        'raid_ad_snooze_message' => 'Snoozed the next ad for the raid from (user).',
    ];
    $stmt = $db->prepare("SELECT ad_upcoming_message, ad_1min_message, ad_start_message, ad_end_message, ad_snoozed_message, enable_ad_notice, enable_upcoming_ad_message, enable_1min_ad_message, enable_start_ad_message, enable_end_ad_message, enable_snoozed_ad_message, enable_ai_ad_breaks, enable_raid_ad_snooze, raid_ad_snooze_window_minutes, enable_raid_ad_snooze_message, raid_ad_snooze_message FROM ad_notice_settings LIMIT 1");
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
            $fetched_enable_ai,
            $fetched_enable_raid_snooze,
            $fetched_raid_window,
            $fetched_enable_raid_msg,
            $fetched_raid_msg
        );
        if ($stmt->fetch()) {
            $raidWindow = (int) $fetched_raid_window;
            if ($raidWindow < 1 || $raidWindow > 30) {
                $raidWindow = 10;
            }
            $ad = [
                'ad_upcoming_message' => (string) $fetched_upcoming,
                'ad_1min_message' => (string) $fetched_1min,
                'ad_start_message' => (string) $fetched_start,
                'ad_end_message' => (string) $fetched_end,
                'ad_snoozed_message' => (string) $fetched_snoozed,
                'enable_ad_notice' => (int) $fetched_enable_global,
                'enable_upcoming_ad_message' => (int) $fetched_enable_upcoming,
                'enable_1min_ad_message' => (int) $fetched_enable_1min,
                'enable_start_ad_message' => (int) $fetched_enable_start,
                'enable_end_ad_message' => (int) $fetched_enable_end,
                'enable_snoozed_ad_message' => (int) $fetched_enable_snoozed,
                'enable_ai_ad_breaks' => (int) $fetched_enable_ai,
                'enable_raid_ad_snooze' => (int) $fetched_enable_raid_snooze,
                'raid_ad_snooze_window_minutes' => $raidWindow,
                'enable_raid_ad_snooze_message' => (int) $fetched_enable_raid_msg,
                'raid_ad_snooze_message' => ($fetched_raid_msg !== null && $fetched_raid_msg !== '') ? $fetched_raid_msg : $ad['raid_ad_snooze_message'],
            ];
        }
        $stmt->close();
    }

    $twitchSoundAlertMappings = [];
    $stmt = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($file_name, $twitch_event);
        while ($stmt->fetch()) {
            $twitchSoundAlertMappings[$file_name] = $twitch_event;
        }
        $stmt->close();
    }

    include __DIR__ . '/includes/storage_used.php';
    $soundPath = "/var/www/soundalerts/$username/twitch";
    $soundFiles = [];
    if (is_dir($soundPath)) {
        $soundFiles = array_values(array_diff(scandir($soundPath) ?: [], ['.', '..']));
    }

    $default_chat_alerts = modules_default_chat_alerts();
    $chat_alerts = [];
    $stmt = $db->prepare("SELECT alert_type, alert_message FROM twitch_chat_alerts");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($alert_type, $alert_message);
        while ($stmt->fetch()) {
            $chat_alerts[$alert_type] = $alert_message;
        }
        $stmt->close();
    }
    foreach ($default_chat_alerts as $type => $default_message) {
        if (!isset($chat_alerts[$type]) || trim((string) $chat_alerts[$type]) === '') {
            $chat_alerts[$type] = $default_message;
        }
    }

    $ignored_games = [];
    $stmt = $db->prepare("SELECT game_name FROM game_deaths_settings");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($game_name);
        while ($stmt->fetch()) {
            $ignored_games[] = $game_name;
        }
        $stmt->close();
    }

    $automated_shoutout_cooldown = 60;
    $stmt = $db->prepare("SELECT cooldown_minutes FROM automated_shoutout_settings LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($cooldown_minutes);
        if ($stmt->fetch() && $cooldown_minutes) {
            $automated_shoutout_cooldown = (int) $cooldown_minutes;
        }
        $stmt->close();
    }

    $automated_shoutout_tracking = [];
    $stmt = $db->prepare("SELECT user_id, user_name, shoutout_time FROM automated_shoutout_tracking ORDER BY shoutout_time DESC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $now = new DateTime('now', new DateTimeZone($timezone));
        $cooldown_seconds = $automated_shoutout_cooldown * 60;
        while ($row = $result->fetch_assoc()) {
            $shoutout_time = new DateTime($row['shoutout_time'], new DateTimeZone($timezone));
            $diff = $now->getTimestamp() - $shoutout_time->getTimestamp();
            $remaining_seconds = max(0, $cooldown_seconds - $diff);
            $automated_shoutout_tracking[] = [
                'user_id' => $row['user_id'],
                'user_name' => $row['user_name'],
                'shoutout_time' => $shoutout_time->format('Y-m-d H:i:s'),
                'remaining_minutes' => (int) ceil($remaining_seconds / 60),
                'is_expired' => $remaining_seconds <= 0,
            ];
        }
        $stmt->close();
    }

    $tts_voice = 'Alloy';
    $tts_language = 'en';
    $tts_style = 'normal';
    $tts_expressive_voice = '';
    $stmt = $db->prepare("SELECT voice, language, style, expressive_voice FROM tts_settings LIMIT 1");
    if (!$stmt) {
        $stmt = $db->prepare("SELECT voice, language FROM tts_settings LIMIT 1");
    }
    if ($stmt) {
        $stmt->execute();
        $meta = $stmt->result_metadata();
        $fieldCount = $meta ? $meta->field_count : 2;
        if ($fieldCount >= 4) {
            $stmt->bind_result($tts_voice_db, $tts_language_db, $tts_style_db, $tts_expressive_voice_db);
        } else {
            $tts_style_db = 'normal';
            $tts_expressive_voice_db = '';
            $stmt->bind_result($tts_voice_db, $tts_language_db);
        }
        if ($stmt->fetch()) {
            if (!empty($tts_voice_db)) {
                $tts_voice = $tts_voice_db;
            }
            if (!empty($tts_language_db)) {
                $tts_language = $tts_language_db;
            }
            if (!empty($tts_style_db) && in_array($tts_style_db, ['normal', 'expressive'], true)) {
                $tts_style = $tts_style_db;
            }
            if (!empty($tts_expressive_voice_db)) {
                $tts_expressive_voice = $tts_expressive_voice_db;
            }
        }
        $stmt->close();
    }

    $currentSettings = 'False';
    $termBlockingSettings = 'False';
    $blockFirstMessageCommands = 'False';
    $blockFirstMessageCommandMode = 'all';
    $blockFirstMessageSelectedCommands = [];
    $wordReplaceEnabled = 'False';
    $wordReplaceWord = 'fun';
    $wordReplaceFrequency = 30;
    $wordReplaceRate = 10;
    $wordReplaceCooldown = 30;
    $getProtection = $db->query("SELECT url_blocking, term_blocking, block_first_message_commands, block_first_message_command_mode, block_first_message_selected_commands, word_replace_enabled, word_replace_word, word_replace_frequency, word_replace_rate, word_replace_cooldown FROM protection LIMIT 1");
    if ($getProtection) {
        $settings = $getProtection->fetch_assoc();
        if ($settings) {
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
                        $blockFirstMessageSelectedCommands[] = $normalizedCmd;
                    }
                }
            }
            $wordReplaceEnabled = isset($settings['word_replace_enabled']) ? $settings['word_replace_enabled'] : 'False';
            $wordReplaceWord = isset($settings['word_replace_word']) && $settings['word_replace_word'] !== '' ? $settings['word_replace_word'] : 'fun';
            $wordReplaceFrequency = isset($settings['word_replace_frequency']) ? (int) $settings['word_replace_frequency'] : 30;
            $wordReplaceRate = isset($settings['word_replace_rate']) ? (int) $settings['word_replace_rate'] : 10;
            $wordReplaceCooldown = isset($settings['word_replace_cooldown']) ? (int) $settings['word_replace_cooldown'] : 30;
        }
        $getProtection->free();
    }

    $availableBlockFirstMessageCommands = [];
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

    $spotify_enabled = 1;
    $spotify_max_song_seconds = 600;
    $spotify_max_queue_length = 20;
    $spotify_per_viewer_limit = 2;
    $stmt = $db->prepare("SELECT enabled, max_song_seconds, max_queue_length, per_viewer_limit FROM media_request_settings WHERE id = 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($spotify_en, $spotify_mss, $spotify_mql, $spotify_pvl);
        if ($stmt->fetch()) {
            $spotify_enabled = (int) $spotify_en;
            $spotify_max_song_seconds = (int) $spotify_mss;
            $spotify_max_queue_length = (int) $spotify_mql;
            $spotify_per_viewer_limit = (int) $spotify_pvl;
        }
        $stmt->close();
    }

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

    $allEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'Hype Train Start', 'Hype Train End'];
    $eventLabels = [];
    foreach ($allEvents as $evt) {
        $eventLabels[$evt] = t('modules_event_' . strtolower(str_replace(' ', '_', $evt)));
    }

    return [
        'success' => true,
        'joke_command_status' => $joke_command_status,
        'current_blacklist' => $current_blacklist,
        'welcome' => $welcome,
        'ad' => $ad,
        'chat_alerts' => $chat_alerts,
        'protection' => [
            'url_blocking' => $currentSettings,
            'term_blocking' => $termBlockingSettings,
            'block_first_message_commands' => $blockFirstMessageCommands,
            'block_first_message_command_mode' => $blockFirstMessageCommandMode,
            'block_first_message_selected_commands' => $blockFirstMessageSelectedCommands,
            'available_commands' => array_values($availableBlockFirstMessageCommands),
        ],
        'whitelist_links' => array_column(modules_query_all($db, "SELECT link FROM link_whitelist"), 'link'),
        'blacklist_links' => array_column(modules_query_all($db, "SELECT link FROM link_blacklisting"), 'link'),
        'blocked_terms' => array_column(modules_query_all($db, "SELECT term FROM blocked_terms"), 'term'),
        'word_replace' => [
            'enabled' => $wordReplaceEnabled,
            'word' => $wordReplaceWord,
            'frequency' => $wordReplaceFrequency,
            'rate' => $wordReplaceRate,
            'cooldown' => $wordReplaceCooldown,
            'ignored_words' => array_column(modules_query_all($db, "SELECT word FROM word_replace_ignored_words ORDER BY word ASC"), 'word'),
            'ignored_users' => modules_query_all($db, "SELECT username, opted_out_at, source FROM word_replace_ignored_users ORDER BY opted_out_at DESC"),
        ],
        'ignored_games' => $ignored_games,
        'storage' => [
            'used_mb' => round(($current_storage_used ?? 0) / 1024 / 1024, 2),
            'max_mb' => round(($max_storage_size ?? 1) / 1024 / 1024, 2),
            'percentage' => round($storage_percentage ?? 0, 2),
        ],
        'sound_files' => $soundFiles,
        'sound_mappings' => $twitchSoundAlertMappings,
        'sound_events' => $allEvents,
        'sound_event_labels' => $eventLabels,
        'shoutouts' => [
            'cooldown_minutes' => $automated_shoutout_cooldown,
            'tracking' => $automated_shoutout_tracking,
        ],
        'tts' => [
            'voice' => $tts_voice,
            'language' => $tts_language,
            'style' => $tts_style,
            'expressive_voice' => $tts_expressive_voice,
        ],
        'module_bots' => $moduleBots,
        'spotify' => [
            'enabled' => $spotify_enabled,
            'max_song_seconds' => $spotify_max_song_seconds,
            'max_queue_length' => $spotify_max_queue_length,
            'per_viewer_limit' => $spotify_per_viewer_limit,
        ],
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        echo json_encode(modules_build_list_payload($db, $conn, $user_id, $username));
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

// Get active tab from URL parameter or default to first tab
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : ($cookieConsent && isset($_COOKIE['preferred_tab']) ? $_COOKIE['preferred_tab'] : 'joke-blacklist');

// Helper: resolve a Twitch username to its user ID via Helix API (for custom module bot)
function resolveModuleBotTwitchUserId($username) {
    global $clientID, $authToken;
    $username = trim($username);
    if ($username === '') return [false, t('modules_err_bot_username_empty')];
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
if ($resp === false || $code !== 200) {
        return [false, t('modules_err_twitch_api', [$err ?: "HTTP {$code}"])];
    }
    $data = json_decode($resp, true);
    if (!isset($data['data'][0]['id'])) {
        return [false, t('modules_err_twitch_user_not_found')];
    }
    return [$data['data'][0]['id'], null];
}

// AJAX: resolve module bot Twitch username to ID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_module_bot_id') {
    include '/var/www/config/twitch.php';
    ob_clean();
    header('Content-Type: application/json');
    $botName = trim($_POST['bot_username'] ?? '');
    if ($botName === '') {
        echo json_encode(['success' => false, 'error' => t('modules_err_bot_username_empty')]);
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
    include '/var/www/config/twitch.php';
    session_start(); // Reopen session for flash messages
    $botName = trim($_POST['bot_username'] ?? '');
    $botId   = trim($_POST['bot_channel_id'] ?? '');
    if ($botName === '') {
        $_SESSION['update_message'] = t('modules_err_provide_bot_username');
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
        $_SESSION['update_message'] = t('modules_err_bot_already_linked');
        header("Location: ?tab=custom-module-bot");
        exit();
    }
    $stmt = $conn->prepare("INSERT INTO custom_module_bots (channel_id, bot_username, bot_channel_id, is_verified, access_token, token_expires, refresh_token) VALUES (?, ?, ?, 0, '', NULL, NULL)");
    if ($stmt) {
        $stmt->bind_param('iss', $user_id, $botName, $botId);
        $stmt->execute();
    }
    $_SESSION['update_message'] = t('modules_msg_bot_added');
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
        $_SESSION['update_message'] = t('modules_msg_bot_removed');
    }
    header("Location: ?tab=custom-module-bot");
    exit();
}

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

// Handle Spotify song request settings update (saving to unified media_request_settings)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_spotify_settings'])) {
    session_start(); // Reopen session for flash messages
    $en = isset($_POST['enabled']) ? 1 : 0;
    $mss = max(30, intval($_POST['max_song_seconds'] ?? 600));
    $mql = max(1, intval($_POST['max_queue_length'] ?? 20));
    $pvl = max(1, intval($_POST['per_viewer_limit'] ?? 2));
    $stmt = $db->prepare("INSERT INTO media_request_settings (id, enabled, max_song_seconds, max_queue_length, per_viewer_limit) VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), max_song_seconds = VALUES(max_song_seconds), max_queue_length = VALUES(max_queue_length), per_viewer_limit = VALUES(per_viewer_limit)");
    if ($stmt) {
        $stmt->bind_param('iiii', $en, $mss, $mql, $pvl);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['update_message'] = t('modules_spotify_song_requests_success');
    header("Location: ?tab=spotify-song-requests");
    exit();
}

// Start output buffering for layout
ob_start();
?>
<!-- Module Variables Notification -->
<div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;">
    <div style="display:flex; align-items:flex-start; gap:1rem;">
        <i class="fas fa-code fa-2x" style="flex-shrink:0; margin-top:0.2rem;"></i>
        <div>
            <p style="font-weight:700; margin-bottom:0.5rem;"><?= t('modules_variables_notice_title') ?></p>
            <p style="margin-bottom:0.5rem;"><?= t('modules_variables_notice_intro') ?></p>
            <p style="margin-bottom:0.5rem;"><?= t('modules_variables_notice_what') ?></p>
            <p style="margin-bottom:0.5rem;"><?= t('modules_variables_notice_available') ?></p>
            <a href="https://support.botofthespecter.com/index.php#variables" target="_blank" rel="noopener"
                class="sp-btn sp-btn-primary sp-btn-sm">
                <i class="fas fa-code"></i>
                <span><?= t('modules_view_all_variables_btn') ?></span>
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
    <li data-tab="word-replacer">
        <a><i class="fas fa-random"></i><span><?= t('modules_tab_word_replacer') ?></span></a>
    </li>
    <li data-tab="game-deaths">
        <a><i class="fas fa-skull-crossbones"></i><span><?= t('modules_tab_game_deaths') ?></span></a>
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
        <a><i class="fas fa-bullhorn"></i><span><?= t('modules_tab_automated_shoutouts') ?></span></a>
    </li>
    <li data-tab="tts-settings">
        <a><i class="fas fa-microphone"></i><span><?= t('modules_tab_tts_settings') ?></span></a>
    </li>
    <li data-tab="custom-module-bot">
        <a><i class="fas fa-robot"></i><span><?= t('modules_tab_custom_module_bots') ?></span></a>
    </li>
    <li data-tab="spotify-song-requests">
        <a><i class="fab fa-spotify"></i><span><?php echo t('modules_tab_spotify_song_requests'); ?></span></a>
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
                <?php if ($updateMessage !== ''): ?>
                    <div class="sp-alert sp-alert-success" style="margin-bottom:1rem;">
                        <?php echo $updateMessage; ?>
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
                                        <div id="jokeCommandHost" style="display:flex; align-items:center; justify-content:center; gap:0.75rem; flex-wrap:wrap;" aria-busy="true">
                                            <span class="sp-badge sp-badge-grey">
                                                <i class="fas fa-terminal"></i>
                                                <?= t('modules_joke_command_badge') ?>
                                            </span>
                                            <span id="jokeCommandStatusBadge" class="sp-skeleton-badge" aria-hidden="true"></span>
                                            <form id="jokeCommandForm" method="POST" style="display:none;">
                                                <input type="hidden" name="toggle_joke_command" value="1">
                                                <input type="hidden" name="joke_command_status" id="jokeCommandStatusValue" value="Disabled">
                                                <button type="submit" id="jokeCommandToggleBtn" class="sp-btn sp-btn-sm">
                                                    <i class="fas fa-check"></i>
                                                    <span></span>
                                                </button>
                                            </form>
                                        </div>
                                        <p style="color:var(--text-muted); font-size:0.78rem; text-align:center; margin-top:0.5rem; margin-bottom:0;">
                                            <?php echo t('modules_joke_command_control_description'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" action="/api/module_data_post.php">
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
                                                    value="<?php echo $cat_value; ?>">
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
                                        <?= t('modules_welcome_config_title') ?>
                                    </h2>
                                </div>
                                <div>
                                    <!-- Welcome Messages Status Control -->
                                    <div class="sp-card" style="padding:0.75rem; min-width:420px;">
                                        <div id="welcomeStatusHost" style="display:flex; align-items:center; justify-content:center; gap:0.75rem; flex-wrap:wrap;" aria-busy="true">
                                            <span class="sp-badge sp-badge-grey">
                                                <i class="fas fa-comment"></i>
                                                <?= t('modules_welcome_messages_badge') ?>
                                            </span>
                                            <span id="welcomeStatusBadge" class="sp-skeleton-badge" aria-hidden="true"></span>
                                            <form id="welcomeStatusForm" method="POST" action="/api/module_data_post.php" style="display:none;">
                                                <input type="hidden" name="toggle_welcome_messages" value="1">
                                                <input type="hidden" name="welcome_messages_status" id="welcomeStatusValue" value="1">
                                                <button type="submit" id="welcomeStatusToggleBtn" class="sp-btn sp-btn-sm">
                                                    <i class="fas fa-check"></i>
                                                    <span></span>
                                                </button>
                                            </form>
                                        </div>
                                        <p style="color:var(--text-muted); font-size:0.78rem; text-align:center; margin-top:0.5rem; margin-bottom:0;">
                                            <?= t('modules_welcome_toggle_description') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" action="/api/module_data_post.php">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                    <!-- Regular Members Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-users"></i>
                                                <?= t('modules_welcome_regular_members') ?>
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="regular-members">
                                                <i class="fas fa-save"></i>
                                                <span><?= t('modules_welcome_save_regular_members') ?></span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-plus"></i>
                                                <?php echo t('modules_welcome_new_member_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="new_default_welcome_message" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="new_default_welcome_message">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo t('modules_welcome_returning_member_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="default_welcome_message" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="default_welcome_message">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                    </div>
                                    <!-- VIP Members Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-gem"></i>
                                                <?= t('modules_welcome_vip_members') ?>
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="vip-members">
                                                <i class="fas fa-save"></i>
                                                <span><?= t('modules_welcome_save_vip_members') ?></span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-plus"></i>
                                                <?php echo t('modules_welcome_new_vip_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="new_default_vip_welcome_message" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="new_default_vip_welcome_message">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo t('modules_welcome_returning_vip_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="default_vip_welcome_message" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="default_vip_welcome_message">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Moderators -->
                                <div>
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                        <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                            <i class="fas fa-shield-alt"></i>
                                            <?= t('modules_welcome_moderators') ?>
                                        </h5>
                                        <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="moderators">
                                            <i class="fas fa-save"></i>
                                            <span><?= t('modules_welcome_save_moderators') ?></span>
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
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="new_default_mod_welcome_message">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-check"></i>
                                                <?php echo t('modules_welcome_returning_mod_label'); ?>
                                            </label>
                                            <input class="sp-input welcome-message-input" type="text"
                                                name="default_mod_welcome_message" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="default_mod_welcome_message">0</span><?= t('modules_char_count_255') ?></p>
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
                                    <strong><?= t('modules_url_blocking_overview_title') ?></strong>
                                </h4>
                                <p><strong><?= t('modules_url_blocking_how_works') ?></strong></p>
                                <ul>
                                    <li>
                                        <strong style="color:var(--red);"><i class="fas fa-ban"></i> <?= t('modules_url_blocking_blacklist_label') ?></strong>
                                        <?= t('modules_url_blocking_blacklist_desc') ?>
                                    </li>
                                    <li>
                                        <strong style="color:var(--accent);"><i class="fas fa-toggle-on"></i> <?= t('modules_url_blocking_enabled_label') ?></strong>
                                        <?= t('modules_url_blocking_enabled_desc') ?>
                                        <ul>
                                            <li><?= t('modules_url_blocking_enabled_item1') ?></li>
                                            <li><?= t('modules_url_blocking_enabled_item2') ?></li>
                                            <li><?= t('modules_url_blocking_enabled_item3') ?></li>
                                        </ul>
                                    </li>
                                    <li>
                                        <strong style="color: #00947e;"><i class="fas fa-toggle-off"></i> <?= t('modules_url_blocking_disabled_label') ?></strong>
                                        <?= t('modules_url_blocking_disabled_desc') ?>
                                    </li>
                                    <li>
                                        <strong style="color: #00947e;"><i class="fas fa-check-circle"></i> <?= t('modules_url_blocking_regex_label') ?></strong>
                                        <?= t('modules_url_blocking_regex_desc') ?>
                                    </li>
                                </ul>
                                <p style="margin-top:0.75rem; margin-bottom:0;">
                                    <i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>
                                    <?= t('modules_url_blocking_important_note') ?>
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
                                        <form action="/api/module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <select class="sp-select" name="url_blocking" id="url_blocking">
                                                    <option value="True"><?php echo t('yes'); ?></option>
                                                    <option value="False"><?php echo t('no'); ?></option>
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
                                        <form action="/api/module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <select class="sp-select" name="block_first_message_commands" id="block_first_message_commands">
                                                    <option value="True"><?php echo t('yes'); ?></option>
                                                    <option value="False"><?php echo t('no'); ?></option>
                                                </select>
                                            </div>
                                            <div class="sp-form-group" style="margin-top:1rem;">
                                                <label class="sp-label"><?= t('modules_blocking_mode_label') ?></label>
                                                <select class="sp-select" name="block_first_message_command_mode" id="block_first_message_command_mode">
                                                    <option value="all"><?= t('modules_blocking_mode_all') ?></option>
                                                    <option value="selected"><?= t('modules_blocking_mode_selected') ?></option>
                                                </select>
                                            </div>
                                            <div class="sp-form-group" id="block-first-message-selected-wrapper" style="margin-top:1rem;display:none;">
                                                <label class="sp-label"><?= t('modules_commands_to_block_label') ?></label>
                                                <div id="blockCommandsHost" aria-busy="true">
                                                    <div class="sp-skeleton-stack" aria-hidden="true">
                                                        <span class="sp-skeleton-line w-80"></span>
                                                        <span class="sp-skeleton-line w-70"></span>
                                                        <span class="sp-skeleton-line w-90"></span>
                                                    </div>
                                                    <select class="sp-select" name="block_first_message_selected_commands[]" id="block_first_message_selected_commands" multiple size="10" style="display:none;"></select>
                                                </div>
                                                <p class="field-help"><?= t('modules_commands_to_block_help') ?></p>
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
                                        <form action="/api/module_data_post.php" method="post">
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
                                        <form action="/api/module_data_post.php" method="post">
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
                                                <tbody id="whitelistLinksBody" aria-busy="true">
                                                    <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                                    <tr aria-hidden="true">
                                                        <td><span class="sp-skeleton-line w-80"></span></td>
                                                        <td style="text-align:right;"><span class="sp-skeleton-badge"></span></td>
                                                    </tr>
                                                    <?php endfor; ?>
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
                                                <tbody id="blacklistLinksBody" aria-busy="true">
                                                    <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                                    <tr aria-hidden="true">
                                                        <td><span class="sp-skeleton-line w-80"></span></td>
                                                        <td style="text-align:right;"><span class="sp-skeleton-badge"></span></td>
                                                    </tr>
                                                    <?php endfor; ?>
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
                                <?= t('modules_term_blocking_title') ?>
                                <span class="sp-badge sp-badge-amber" style="margin-left:0.75rem;"><?= t('modules_term_blocking_beta_badge') ?></span>
                            </h2>
                            <!-- Term Blocking Information -->
                            <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
                                <h4 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">
                                    <i class="fas fa-flask"></i>
                                    <strong><?= t('modules_term_blocking_system_title') ?></strong>
                                </h4>
                                <p><strong><?= t('modules_term_blocking_how_works') ?></strong></p>
                                <ul>
                                    <li>
                                        <strong style="color:var(--red);"><i class="fas fa-ban"></i> <?= t('modules_term_blocking_blocked_label') ?></strong>
                                        <?= t('modules_term_blocking_blocked_desc') ?>
                                    </li>
                                    <li>
                                        <strong style="color:var(--accent);"><i class="fas fa-toggle-on"></i> <?= t('modules_term_blocking_enabled_label') ?></strong>
                                        <?= t('modules_term_blocking_enabled_desc') ?>
                                    </li>
                                    <li>
                                        <strong style="color: #00947e;"><i class="fas fa-shield-alt"></i> <?= t('modules_term_blocking_case_label') ?></strong>
                                        <?= t('modules_term_blocking_case_desc') ?>
                                    </li>
                                </ul>
                                <p style="margin-top:0.75rem; margin-bottom:0;">
                                    <i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>
                                    <?= t('modules_term_blocking_beta_note') ?>
                                </p>
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                <!-- Term Blocking Settings -->
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <i class="fas fa-comment-slash" style="color:var(--amber);"></i>
                                            <?= t('modules_enable_term_blocking') ?>
                                        </h3>
                                        <form action="/api/module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <select class="sp-select" name="term_blocking" id="term_blocking">
                                                    <option value="True"><?php echo t('yes'); ?></option>
                                                    <option value="False"><?php echo t('no'); ?></option>
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
                                            <?= t('modules_add_blocked_term_title') ?>
                                        </h3>
                                        <form action="/api/module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <input class="sp-input" type="text" name="blocked_term" id="blocked_term" placeholder="<?= htmlspecialchars(t('modules_blocked_term_placeholder')) ?>" required>
                                            </div>
                                            <div style="margin-top:1rem;">
                                                <button type="submit" name="submit" class="sp-btn sp-btn-danger" style="width:100%;">
                                                    <i class="fas fa-minus-circle"></i>
                                                    <span><?= t('modules_add_to_blocked_terms') ?></span>
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
                                            <?= t('modules_blocked_terms_list') ?>
                                        </h3>
                                        <div class="sp-table-wrap">
                                            <table class="sp-table">
                                                <tbody id="blockedTermsBody" aria-busy="true">
                                                    <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                                    <tr aria-hidden="true">
                                                        <td><span class="sp-skeleton-line w-70"></span></td>
                                                        <td style="text-align:right;"><span class="sp-skeleton-badge"></span></td>
                                                    </tr>
                                                    <?php endfor; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-content" id="word-replacer">
                        <!-- Word Replacer Section (Beta) -->
                        <div class="module-container">
                            <h2 style="font-size:1.4rem; font-weight:700; color:var(--text-primary); margin-bottom:1rem;">
                                <i class="fas fa-random" style="color:var(--accent);"></i>
                                <?= t('modules_word_replace_title') ?>
                                <span class="sp-badge sp-badge-amber" style="margin-left:0.75rem;"><?= t('modules_word_replace_beta_badge') ?></span>
                            </h2>
                            <div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;">
                                <p style="margin-bottom:0.75rem;"><strong><?= t('modules_word_replace_how_works') ?></strong></p>
                                <p style="margin-bottom:0.75rem;"><?= t('modules_word_replace_desc') ?></p>
                                <p style="margin-bottom:0;"><?= t('modules_word_replace_optout_note') ?></p>
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="text-align:center; font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <?= t('modules_word_replace_settings_title') ?>
                                        </h3>
                                        <form action="/api/module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <label class="sp-label"><?= t('modules_word_replace_enable') ?></label>
                                                <select class="sp-select" name="word_replace_enabled" id="word_replace_enabled">
                                                    <option value="True"><?= t('yes') ?></option>
                                                    <option value="False"><?= t('no') ?></option>
                                                </select>
                                            </div>
                                            <div class="sp-form-group">
                                                <label class="sp-label"><?= t('modules_word_replace_word_label') ?></label>
                                                <input class="sp-input" type="text" name="word_replace_word" value="" maxlength="32" pattern="[a-z0-9]+" required>
                                            </div>
                                            <div class="sp-form-group">
                                                <label class="sp-label"><?= t('modules_word_replace_frequency_label') ?></label>
                                                <input class="sp-input" type="number" name="word_replace_frequency" min="5" max="200" value="30" required>
                                            </div>
                                            <div class="sp-form-group">
                                                <label class="sp-label"><?= t('modules_word_replace_rate_label') ?></label>
                                                <input class="sp-input" type="number" name="word_replace_rate" min="2" max="50" value="10" required>
                                            </div>
                                            <div class="sp-form-group">
                                                <label class="sp-label"><?= t('modules_word_replace_cooldown_label') ?></label>
                                                <input class="sp-input" type="number" name="word_replace_cooldown" min="10" max="300" value="30" required>
                                            </div>
                                            <button type="submit" name="submit" class="sp-btn sp-btn-primary" style="width:100%;">
                                                <i class="fas fa-save"></i>
                                                <span><?= t('protection_update_btn') ?></span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div class="sp-card">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <?= t('modules_word_replace_add_ignored_word') ?>
                                        </h3>
                                        <form action="/api/module_data_post.php" method="post" style="margin-bottom:1.5rem;">
                                            <div class="sp-form-group">
                                                <input class="sp-input" type="text" name="word_replace_ignored_word" placeholder="<?= htmlspecialchars(t('modules_word_replace_ignored_word_placeholder')) ?>" required>
                                            </div>
                                            <button type="submit" class="sp-btn sp-btn-secondary" style="width:100%;">
                                                <i class="fas fa-plus"></i>
                                                <span><?= t('modules_word_replace_add_word_btn') ?></span>
                                            </button>
                                        </form>
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">
                                            <?= t('modules_word_replace_add_opted_out_user') ?>
                                        </h3>
                                        <form action="/api/module_data_post.php" method="post">
                                            <div class="sp-form-group">
                                                <input class="sp-input" type="text" name="word_replace_ignored_user" placeholder="<?= htmlspecialchars(t('modules_word_replace_opted_out_user_placeholder')) ?>" required>
                                            </div>
                                            <button type="submit" class="sp-btn sp-btn-secondary" style="width:100%;">
                                                <i class="fas fa-user-slash"></i>
                                                <span><?= t('modules_word_replace_add_user_btn') ?></span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div class="sp-card" style="grid-column:1 / -1;">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;"><?= t('modules_word_replace_ignored_words_list') ?></h3>
                                        <div class="sp-table-wrap" style="margin-bottom:1.5rem;">
                                            <table class="sp-table">
                                                <tbody id="wordReplaceIgnoredWordsBody" aria-busy="true">
                                                    <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                                    <tr aria-hidden="true">
                                                        <td><span class="sp-skeleton-line w-60"></span></td>
                                                        <td style="text-align:right;"><span class="sp-skeleton-badge"></span></td>
                                                    </tr>
                                                    <?php endfor; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;"><?= t('modules_word_replace_opted_out_users_list') ?></h3>
                                        <div class="sp-table-wrap">
                                            <table class="sp-table">
                                                <thead>
                                                    <tr>
                                                        <th><?= t('modules_word_replace_col_username') ?></th>
                                                        <th><?= t('modules_word_replace_col_opted_out') ?></th>
                                                        <th><?= t('modules_word_replace_col_source') ?></th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody id="wordReplaceIgnoredUsersBody" aria-busy="true">
                                                    <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                                    <tr aria-hidden="true">
                                                        <td><span class="sp-skeleton-line w-50"></span></td>
                                                        <td><span class="sp-skeleton-line w-60"></span></td>
                                                        <td><span class="sp-skeleton-badge"></span></td>
                                                        <td style="text-align:right;"><span class="sp-skeleton-badge"></span></td>
                                                    </tr>
                                                    <?php endfor; ?>
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
                                    <?= t('modules_game_deaths_config_title') ?>
                                </h2>
                            </div>
                            <!-- Configuration Note -->
                            <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                <i class="fas fa-info-circle"></i>
                                <?= t('modules_game_deaths_config_note') ?>
                            </div>
                            <!-- Add Game Form -->
                            <form method="POST" action="/api/module_data_post.php" style="margin-bottom:1rem;">
                                <div style="display:flex; gap:0.5rem;">
                                    <input class="sp-input" type="text" name="ignore_game_name"
                                        placeholder="<?= htmlspecialchars(t('modules_ignore_game_placeholder')) ?>"
                                        maxlength="100" required style="flex:1;">
                                    <button class="sp-btn sp-btn-primary" type="submit" name="add_ignored_game">
                                        <i class="fas fa-plus"></i>
                                        <span><?= t('modules_add_game') ?></span>
                                    </button>
                                </div>
                            </form>
                            <!-- Current Ignored Games -->
                            <h4 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
                                <i class="fas fa-list"></i>
                                <?= t('modules_currently_ignored_games') ?>
                            </h4>
                            <div id="ignoredGamesHost" aria-busy="true">
                                <div class="sp-skeleton-stack" aria-hidden="true">
                                    <span class="sp-skeleton-line w-70"></span>
                                    <span class="sp-skeleton-line w-50"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Ad Notices -->
                    <div class="tab-content" id="ad-notices">
                        <div class="module-container">
                            <div style="display:flex; align-items:center; margin-bottom:1rem;">
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0;">
                                    <i class="fas fa-cog"></i>
                                    <?= t('modules_ad_notice_messages_title') ?>
                                </h2>
                            </div>
                            <form method="POST" action="/api/module_data_post.php">
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                    <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                        <i class="fas fa-bullhorn"></i>
                                        <?= t('modules_advertisement_messages') ?>
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
                                                    style="display: none;">
                                                <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_upcoming_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_upcoming_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_upcoming_message">0</span><?= t('modules_char_count_255') ?>
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
                                                    style="display: none;">
                                                <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_1min_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_1min_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_1min_message">0</span><?= t('modules_char_count_255') ?>
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
                                                    style="display: none;">
                                                <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_start_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_start_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_start_message">0</span><?= t('modules_char_count_255') ?>
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
                                                    style="display: none;">
                                                <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                            </label>
                                        </div>
                                        <textarea class="sp-textarea ad-notice-input" name="ad_end_message"
                                            maxlength="255" placeholder="<?php echo t('modules_ad_end_message_placeholder'); ?>"
                                            rows="3" style="word-wrap: break-word; white-space: pre-wrap;"></textarea>
                                        <p class="field-help">
                                            <span class="char-count" data-field="ad_end_message">0</span><?= t('modules_char_count_255') ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                    <p><?= t('modules_ad_snoozed_note') ?></p>
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
                                                style="display: none;">
                                            <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                        </label>
                                    </div>
                                    <textarea class="sp-textarea ad-notice-input" name="ad_snoozed_message"
                                        maxlength="255" placeholder="<?php echo t('modules_ad_snoozed_message_placeholder'); ?>"
                                        rows="3" style="word-wrap: break-word; white-space: pre-wrap;"></textarea>
                                    <p class="field-help">
                                        <span class="char-count" data-field="ad_snoozed_message">0</span><?= t('modules_char_count_255') ?>
                                    </p>
                                </div>
                                <!-- Raid auto-snooze -->
                                <div style="display:flex; align-items:center; justify-content:space-between; margin:1.5rem 0 1rem;">
                                    <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                        <i class="fas fa-shield-alt"></i>
                                        <?= t('modules_raid_ad_snooze_title') ?>
                                    </h5>
                                </div>
                                <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                    <p><?= t('modules_raid_ad_snooze_note') ?></p>
                                </div>
                                <div class="sp-form-group" style="margin-bottom:1rem;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div>
                                            <label class="sp-label">
                                                <i class="fas fa-user-friends"></i>
                                                <?php echo t('modules_enable_raid_ad_snooze'); ?>
                                            </label>
                                            <p class="field-help">
                                                <?= t('modules_enable_raid_ad_snooze_help') ?>
                                            </p>
                                        </div>
                                        <label for="enable_raid_ad_snooze" style="cursor: pointer;">
                                            <input id="enable_raid_ad_snooze" type="checkbox" name="enable_raid_ad_snooze"
                                                value="1"
                                                style="display: none;">
                                            <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                        </label>
                                    </div>
                                </div>
                                <div class="sp-form-group" style="margin-bottom:1rem;">
                                    <label class="sp-label" for="raid_ad_snooze_window_minutes">
                                        <i class="fas fa-hourglass-start"></i>
                                        <?php echo t('modules_raid_ad_snooze_window'); ?>
                                    </label>
                                    <input class="sp-input" type="number" name="raid_ad_snooze_window_minutes"
                                        id="raid_ad_snooze_window_minutes" min="1" max="30" step="1"
                                        value="10"
                                        style="max-width:8rem;">
                                    <p class="field-help">
                                        <?= t('modules_raid_ad_snooze_window_help') ?>
                                    </p>
                                </div>
                                <div class="sp-form-group" style="margin-bottom:1rem;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                        <label class="sp-label" style="margin:0;">
                                            <i class="fas fa-comment-dots"></i>
                                            <?php echo t('modules_raid_ad_snooze_message'); ?>
                                        </label>
                                        <label for="enable_raid_ad_snooze_message" style="cursor: pointer;">
                                            <input id="enable_raid_ad_snooze_message" type="checkbox"
                                                name="enable_raid_ad_snooze_message" value="1"
                                                style="display: none;">
                                            <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                        </label>
                                    </div>
                                    <textarea class="sp-textarea ad-notice-input" name="raid_ad_snooze_message"
                                        maxlength="255" placeholder="<?php echo t('modules_raid_ad_snooze_message_placeholder'); ?>"
                                        rows="3" style="word-wrap: break-word; white-space: pre-wrap;"></textarea>
                                    <p class="field-help">
                                        <span class="char-count" data-field="raid_ad_snooze_message">0</span><?= t('modules_char_count_255') ?>
                                    </p>
                                    <p class="field-help">
                                        <?= t('modules_raid_ad_snooze_variables_note') ?>
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
                                                <?= t('modules_enable_ad_notice_help') ?>
                                            </p>
                                        </div>
                                        <label for="enable_ad_notice" style="cursor: pointer;">
                                            <input id="enable_ad_notice" type="checkbox" name="enable_ad_notice"
                                                value="1"
                                                style="display: none;">
                                            <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
                                        </label>
                                    </div>
                                </div>
                                <!-- AI Ad Breaks Toggle -->
                                <div class="sp-form-group" style="margin-top:1rem;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div>
                                            <label class="sp-label">
                                                <i class="fas fa-robot"></i>
                                                <?= t('modules_enable_ai_ad_breaks_label') ?>
                                            </label>
                                            <p class="field-help">
                                                <span class="sp-badge sp-badge-amber" style="margin-right:0.5rem;"><?= t('modules_premium_feature_badge') ?></span>
                                                <?= t('modules_enable_ai_ad_breaks_help') ?>
                                            </p>
                                        </div>
                                        <label for="enable_ai_ad_breaks" style="cursor: pointer;">
                                            <input id="enable_ai_ad_breaks" type="checkbox" name="enable_ai_ad_breaks"
                                                value="1"
                                                style="display: none;">
                                            <i class="fas fa-toggle-off fa-2x" style="color:var(--text-muted);"></i>
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
                                    <div class="sp-card" style="margin-bottom:1rem; background:var(--bg-card-hover);" id="storageUsageHost" aria-busy="true">
                                        <div class="sp-card-body" style="padding:0.75rem;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                                <span><i class="fas fa-database"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                                                <span id="storageUsageText"><span class="sp-skeleton-line w-50"></span></span>
                                            </div>
                                            <progress id="storageUsageBar" style="width:100%; height:0.75rem;" value="0" max="100"></progress>
                                        </div>
                                    </div>
                                    <form action="/api/module_data_post.php" method="POST" enctype="multipart/form-data" id="uploadForm">
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
                                                    <strong id="uploadStatusText"><?= t('modules_preparing_upload') ?></strong>
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
                                    <div id="soundAlertsEmpty" style="text-align:center; padding:2.5rem 1rem; display:none;">
                                        <h2 style="font-size:1rem; color:var(--text-muted);">
                                            <?php echo t('modules_no_sound_alert_files_uploaded'); ?>
                                        </h2>
                                    </div>
                                    <form action="/api/module_data_post.php" method="POST" id="deleteForm">
                                        <div class="sp-table-wrap">
                                            <table class="sp-table" id="twitchAlertsTable">
                                                <thead>
                                                    <tr>
                                                        <th style="width:70px; text-align:center;"><?php echo t('modules_select'); ?></th>
                                                        <th style="text-align:center;"><?php echo t('modules_file_name'); ?></th>
                                                        <th style="text-align:center;"><?php echo t('modules_twitch_event'); ?></th>
                                                        <th style="width:130px; text-align:center;"><?php echo t('modules_action'); ?></th>
                                                        <th style="width:120px; text-align:center;"><?php echo t('modules_test_audio'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody id="soundAlertsBody" aria-busy="true">
                                                    <?php for ($sk = 0; $sk < 4; $sk++): ?>
                                                    <tr aria-hidden="true">
                                                        <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                                                        <td><span class="sp-skeleton-line w-70"></span></td>
                                                        <td style="text-align:center;"><span class="sp-skeleton-line w-60"></span></td>
                                                        <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                                                        <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                                                    </tr>
                                                    <?php endfor; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <button type="submit" value="Delete Selected"
                                            class="sp-btn sp-btn-danger" name="submit_delete" style="margin-top:0.75rem; display:none;">
                                            <i class="fas fa-trash"></i>
                                            <span><?php echo t('modules_delete_selected'); ?></span>
                                        </button>
                                    </form>
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
                                    <?= t('modules_chat_alert_messages_title') ?>
                                </h2>
                            </div>
                            <form action="/api/module_data_post.php" method="POST" id="chatAlertsForm">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                                    <!-- General Events Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-users"></i>
                                                <?= t('modules_general_events') ?>
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="general">
                                                <i class="fas fa-save"></i>
                                                <span><?= t('modules_save_general_events') ?></span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-heart"></i>
                                                <?php echo t('modules_follower_alert'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="follower_alert" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="follower_alert">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-gem"></i>
                                                <?php echo t('modules_cheer_alert'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="cheer_alert" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="cheer_alert">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-user-friends"></i>
                                                <?php echo t('modules_raid_alert'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="raid_alert" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="raid_alert">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                    </div>
                                    <!-- Subscription Events Column -->
                                    <div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                            <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                                <i class="fas fa-star"></i>
                                                <?= t('modules_subscription_events') ?>
                                            </h5>
                                            <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="subscription">
                                                <i class="fas fa-save"></i>
                                                <span><?= t('modules_save_subscription_events') ?></span>
                                            </button>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-star"></i>
                                                <?php echo t('modules_subscription_alert'); ?>
                                                <span class="sp-badge sp-badge-red" style="font-size:0.7rem;">*</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="subscription_alert" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="subscription_alert">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-gift"></i>
                                                <?php echo t('modules_gift_subscription_alert'); ?>
                                                <span class="sp-badge sp-badge-red" style="font-size:0.7rem;">*</span>
                                                <i class="fas fa-info-circle" style="color:var(--amber);" title="<?= htmlspecialchars(t('modules_gift_sub_info_tooltip')) ?>"></i>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="gift_subscription_alert" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="gift_subscription_alert">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hype Train Events (Full Width) -->
                                <div style="margin-bottom:1.5rem;">
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                        <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                            <i class="fas fa-train"></i>
                                            <?= t('modules_hype_train_events') ?>
                                        </h5>
                                        <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="hype-train">
                                            <i class="fas fa-save"></i>
                                            <span><?= t('modules_save_hype_train_events') ?></span>
                                        </button>
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-play"></i>
                                                <?php echo t('modules_hype_train_start'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="hype_train_start" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="hype_train_start">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-stop"></i>
                                                <?php echo t('modules_hype_train_end'); ?>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="hype_train_end" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="hype_train_end">0</span><?= t('modules_char_count_255') ?></p>
                                        </div>
                                    </div>
                                </div>
                                <!-- BETA Features Section -->
                                <div>
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                                        <h5 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">
                                            <i class="fas fa-flask"></i>
                                            <?= t('modules_beta_features') ?>
                                        </h5>
                                        <button type="button" class="section-save-btn sp-btn sp-btn-success sp-btn-sm" data-section="beta">
                                            <i class="fas fa-save"></i>
                                            <span><?= t('modules_save_beta_features') ?></span>
                                        </button>
                                    </div>
                                    <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
                                        <i class="fas fa-flask"></i>
                                        <?= t('modules_beta_features_note') ?>
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-arrow-up"></i>
                                                <?= t('modules_gift_paid_upgrade_label') ?> <span class="sp-badge sp-badge-amber" style="font-size:0.7rem;">BETA</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="gift_paid_upgrade" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="gift_paid_upgrade">0</span><?= t('modules_char_count_255_placeholders_user_tier') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-arrow-up"></i>
                                                <?= t('modules_prime_paid_upgrade_label') ?> <span class="sp-badge sp-badge-amber" style="font-size:0.7rem;">BETA</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="prime_paid_upgrade" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="prime_paid_upgrade">0</span><?= t('modules_char_count_255_placeholders_user_tier') ?></p>
                                        </div>
                                        <div class="sp-form-group" style="grid-column: 1 / -1;">
                                            <label class="sp-label">
                                                <i class="fas fa-gift"></i>
                                                <?= t('modules_pay_it_forward_label') ?> <span class="sp-badge sp-badge-amber" style="font-size:0.7rem;">BETA</span>
                                            </label>
                                            <input class="sp-input chat-alert-input" type="text" name="pay_it_forward" maxlength="255"
                                                value="">
                                            <p class="field-help"><span class="char-count" data-field="pay_it_forward">0</span><?= t('modules_char_count_255_placeholders_user_tier_gifter') ?></p>
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
                                        <?= t('modules_automated_shoutouts_title') ?>
                                    </h2>
                                    <p style="color:var(--text-muted); margin:0;"><?= t('modules_automated_shoutouts_description') ?></p>
                                </div>
                            </div>
                            <form method="POST" action="/api/module_data_post.php">
                                <div class="sp-card" style="margin-bottom:1.5rem;">
                                    <div class="sp-card-body">
                                        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                            <i class="fas fa-clock" style="color:var(--amber);"></i>
                                            <?= t('modules_cooldown_settings') ?>
                                        </h3>
                                        <div class="sp-form-group">
                                            <label class="sp-label"><?= t('modules_automated_shoutout_cooldown_label') ?></label>
                                            <input class="sp-input" type="number" name="cooldown_minutes"
                                                value="60"
                                                min="60" required>
                                            <p class="field-help"><?= t('modules_automated_shoutout_cooldown_help') ?></p>
                                        </div>
                                        <button type="submit" class="sp-btn sp-btn-success">
                                            <i class="fas fa-save"></i>
                                            <span><?= t('modules_save_cooldown_settings') ?></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <!-- Active Cooldowns -->
                            <div class="sp-card">
                                <div class="sp-card-body">
                                    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 0.5rem;">
                                        <i class="fas fa-hourglass-half" style="color:var(--red);"></i>
                                        <?= t('modules_users_on_cooldown') ?>
                                    </h3>
                                    <p style="color:var(--text-muted); margin:0 0 1rem;"><?= t('modules_users_on_cooldown_description') ?></p>
                                    <div id="shoutoutEmpty" class="sp-alert sp-alert-info" style="display:none;">
                                        <i class="fas fa-info-circle"></i>
                                        <?= t('modules_no_users_on_cooldown') ?>
                                    </div>
                                    <div class="sp-table-wrap" id="shoutoutTableWrap">
                                        <table class="sp-table">
                                            <thead>
                                                <tr>
                                                    <th><?= t('modules_table_user') ?></th>
                                                    <th><?= t('modules_table_last_shoutout') ?></th>
                                                    <th><?= t('modules_table_cooldown_remaining') ?></th>
                                                </tr>
                                            </thead>
                                            <tbody id="shoutoutTrackingBody" aria-busy="true">
                                                <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                                <tr aria-hidden="true">
                                                    <td><span class="sp-skeleton-line w-50"></span></td>
                                                    <td><span class="sp-skeleton-line w-70"></span></td>
                                                    <td><span class="sp-skeleton-badge"></span></td>
                                                </tr>
                                                <?php endfor; ?>
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
                                    <?= t('modules_tts_settings_title') ?>
                                </h2>
                                <p style="color:var(--text-muted); margin:0;"><?= t('modules_tts_settings_description') ?></p>
                            </div>
                        </div>
                        <form method="POST" action="/api/module_data_post.php">
                            <div class="sp-card" style="margin-bottom:1.5rem;">
                                <div class="sp-card-body">
                                    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                        <i class="fas fa-cog"></i>
                                        <?= t('modules_voice_configuration') ?>
                                    </h3>
                                    <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                                        <i class="fas fa-info-circle"></i>
                                        <?= t('modules_tts_help_choosing_voice') ?>
                                    </div>
                                    <?php
                                    $expressiveVoices = modules_expressive_voices();
                                    $tts_style = isset($tts_style) ? $tts_style : 'normal';
                                    $tts_expressive_voice = isset($tts_expressive_voice) ? $tts_expressive_voice : '';
                                    $callumVoiceId = modules_default_expressive_voice_id($expressiveVoices);
                                    if ($tts_expressive_voice === '' && $callumVoiceId !== '') {
                                        $tts_expressive_voice = $callumVoiceId;
                                    }
                                    ?>
                                    <div class="sp-form-group">
                                        <label class="sp-label" for="tts_style">
                                            <i class="fas fa-sliders"></i>
                                            <?= t('modules_tts_style_label') ?>
                                        </label>
                                        <select class="sp-select" name="tts_style" id="tts_style">
                                            <option value="normal" <?= $tts_style === 'expressive' ? '' : 'selected' ?>><?= t('modules_tts_style_normal') ?></option>
                                            <option value="expressive" <?= $tts_style === 'expressive' ? 'selected' : '' ?>><?= t('modules_tts_style_expressive') ?></option>
                                        </select>
                                        <p class="field-help"><?= t('modules_tts_style_help') ?></p>
                                    </div>
                                    <div id="tts-normal-fields" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-volume-up"></i>
                                                <?= t('modules_tts_voice_label') ?>
                                            </label>
                                            <select class="sp-select" name="tts_voice" id="tts_voice">
                                                <option value="Alloy">Alloy <?= t('modules_tts_voice_default_suffix') ?></option>
                                                <option value="Ash">Ash</option>
                                                <option value="Ballad">Ballad</option>
                                                <option value="Coral">Coral</option>
                                                <option value="Echo">Echo</option>
                                                <option value="Fable">Fable</option>
                                                <option value="Nova">Nova</option>
                                                <option value="Onyx">Onyx</option>
                                                <option value="Sage">Sage</option>
                                                <option value="Shimmer">Shimmer</option>
                                                <option value="Verse">Verse</option>
                                            </select>
                                            <p class="field-help"><?= t('modules_tts_voice_help') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label">
                                                <i class="fas fa-globe"></i>
                                                <?= t('modules_tts_language_label') ?>
                                            </label>
                                            <select class="sp-select" name="tts_language" id="tts_language">
                                                <option value="en"><?= t('modules_tts_language_english') ?></option>
                                            </select>
                                            <p class="field-help"><?= t('modules_tts_language_help') ?></p>
                                        </div>
                                    </div>
                                    <div id="tts-expressive-fields" class="sp-form-group">
                                        <label class="sp-label" for="tts_expressive_voice">
                                            <i class="fas fa-volume-up"></i>
                                            <?= t('modules_tts_expressive_voice_label') ?>
                                        </label>
                                        <select class="sp-select" name="tts_expressive_voice" id="tts_expressive_voice">
                                            <option value=""><?= t('modules_tts_expressive_voice_placeholder') ?></option>
                                            <?php foreach ($expressiveVoices as $exVoice):
                                                $exLabel = $exVoice['name'];
                                                if (strcasecmp($exVoice['name'], 'Callum') === 0) {
                                                    $exLabel .= ' ' . t('modules_tts_voice_default_suffix');
                                                }
                                            ?>
                                                <option value="<?= htmlspecialchars($exVoice['id']) ?>" <?= $tts_expressive_voice === $exVoice['id'] ? 'selected' : '' ?>><?= htmlspecialchars($exLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="field-help">
                                            <?= $expressiveVoices ? t('modules_tts_expressive_voice_help') : t('modules_tts_expressive_voice_empty') ?>
                                        </p>
                                    </div>
                                    <div style="margin-top:1rem;">
                                        <button type="submit" class="sp-btn sp-btn-success">
                                            <i class="fas fa-save"></i>
                                            <span><?= t('modules_save_tts_settings') ?></span>
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
                                    <strong><?= t('modules_module_bot_what_title') ?></strong>
                                    <p style="margin:0.25rem 0 0;"><?= t('modules_module_bot_what_desc') ?></p>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; margin-bottom:1rem;">
                            <div>
                                <h2 style="font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0 0 0.25rem;">
                                    <i class="fas fa-robot" style="color:var(--amber);"></i>
                                    <?= t('modules_custom_module_bots_title') ?>
                                </h2>
                                <p style="color:var(--text-muted); margin:0;"><?= t('modules_custom_module_bots_description') ?></p>
                            </div>
                        </div>
                        <!-- Add new bot form -->
                        <div class="sp-card" style="margin-bottom:1.5rem;">
                            <div class="sp-card-body">
                                <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                    <i class="fas fa-plus-circle" style="color:var(--green);"></i>
                                    <?= t('modules_add_custom_module_bot') ?>
                                </h3>
                                <form method="post" id="custom-module-bot-form">
                                    <input type="hidden" name="action" value="add_module_bot">
                                    <div style="display:grid; grid-template-columns:5fr 7fr; gap:1.5rem; margin-bottom:1rem;">
                                        <div class="sp-form-group">
                                            <label class="sp-label"><?= t('modules_bot_username_label') ?></label>
                                            <input class="sp-input" type="text" name="bot_username" id="module-bot-username" placeholder="<?= htmlspecialchars(t('modules_bot_username_placeholder')) ?>" autocomplete="off">
                                            <span id="module-bot-lookup-status" style="display:none;"></span>
                                            <p class="field-help"><?= t('modules_bot_username_help') ?></p>
                                        </div>
                                        <div class="sp-form-group">
                                            <label class="sp-label"><?= t('modules_bot_id_label') ?> <span style="color:var(--text-muted); font-weight:normal;"><?= t('modules_bot_id_auto_resolved') ?></span></label>
                                            <div style="display:flex; gap:0.5rem; margin-bottom:0.3rem;">
                                                <input class="sp-input" style="flex:1;" type="text" name="bot_channel_id" id="module-bot-id" readonly placeholder="<?= htmlspecialchars(t('modules_bot_id_placeholder')) ?>">
                                                <button type="button" class="sp-btn sp-btn-info" id="resolve-module-bot-btn">
                                                    <i class="fas fa-search"></i>
                                                    <span><?= t('modules_resolve_id_btn') ?></span>
                                                </button>
                                            </div>
                                            <p class="field-help"><?= t('modules_bot_id_help') ?></p>
                                        </div>
                                    </div>
                                    <button type="submit" class="sp-btn sp-btn-success">
                                        <i class="fas fa-plus"></i>
                                        <span><?= t('modules_add_bot_btn') ?></span>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <!-- Verification instructions -->
                        <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?= t('modules_verification_required_note') ?>
                        </div>
                        <!-- Linked bots list -->
                        <div class="sp-card">
                            <div class="sp-card-body">
                                <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 1rem;">
                                    <i class="fas fa-list" style="color:var(--blue);"></i>
                                    <?= t('modules_linked_bots') ?>
                                </h3>
                                <div id="moduleBotsEmpty" class="sp-alert sp-alert-info" style="display:none;">
                                    <i class="fas fa-info-circle"></i>
                                    <?= t('modules_no_module_bots_linked') ?>
                                </div>
                                <div class="sp-table-wrap" id="moduleBotsTableWrap">
                                    <table class="sp-table">
                                        <thead>
                                            <tr>
                                                <th><?= t('modules_table_bot_username') ?></th>
                                                <th><?= t('modules_table_bot_id') ?></th>
                                                <th><?= t('modules_table_status') ?></th>
                                                <th style="text-align:right;"><?= t('modules_table_actions') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="moduleBotsBody" aria-busy="true">
                                            <?php for ($sk = 0; $sk < 3; $sk++): ?>
                                            <tr aria-hidden="true">
                                                <td><span class="sp-skeleton-line w-50"></span></td>
                                                <td><span class="sp-skeleton-line w-40"></span></td>
                                                <td><span class="sp-skeleton-badge"></span></td>
                                                <td style="text-align:right;"><span class="sp-skeleton-badge"></span></td>
                                            </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Spotify Song Requests Tab -->
                <div class="tab-content" id="spotify-song-requests">
                    <div class="module-container">
                        <div class="sp-card">
                            <header class="sp-card-header">
                                <span class="sp-card-title"><i class="fab fa-spotify" style="color: var(--green);"></i> <?php echo t('modules_spotify_song_requests_title'); ?></span>
                            </header>
                            <div class="sp-card-body">
                                <form method="post" style="max-width: 440px; display: flex; flex-direction: column; gap: 0.75rem;">
                                    <label class="sp-label">
                                        <input type="checkbox" name="enabled" id="spotify_enabled">
                                        <span><?php echo t('modules_spotify_song_requests_enabled'); ?></span>
                                    </label>
                                    <div class="sp-form-group">
                                        <label class="sp-label"><?php echo t('modules_spotify_song_requests_max_len'); ?></label>
                                        <input class="sp-input" type="number" name="max_song_seconds" min="30" value="600" required>
                                    </div>
                                    <div class="sp-form-group">
                                        <label class="sp-label"><?php echo t('modules_spotify_song_requests_max_queue'); ?></label>
                                        <input class="sp-input" type="number" name="max_queue_length" min="1" value="20" required>
                                    </div>
                                    <div class="sp-form-group">
                                        <label class="sp-label"><?php echo t('modules_spotify_song_requests_per_viewer_limit'); ?></label>
                                        <input class="sp-input" type="number" name="per_viewer_limit" min="1" value="2" required>
                                    </div>
                                    <button class="sp-btn sp-btn-primary" type="submit" name="save_spotify_settings" value="1">
                                        <i class="fas fa-save"></i> <?php echo t('modules_spotify_song_requests_save'); ?>
                                    </button>
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
    const MODULES_I18N = {
        uploadingWait: <?php echo json_encode(t('modules_js_uploading_wait')); ?>,
        uploadCompleted: <?php echo json_encode(t('modules_js_upload_completed')); ?>,
        uploadFailedPrefix: <?php echo json_encode(t('modules_js_upload_failed_prefix')); ?>,
        unknownError: <?php echo json_encode(t('modules_js_unknown_error')); ?>,
        errorProcessingResponse: <?php echo json_encode(t('modules_js_error_processing_response')); ?>,
        checkSizeNote: <?php echo json_encode(t('modules_js_check_size_note')); ?>,
        noFilesSelectedTitle: <?php echo json_encode(t('modules_js_no_files_selected_title')); ?>,
        noFilesSelectedText: <?php echo json_encode(t('modules_js_no_files_selected_text')); ?>,
        uploading: <?php echo json_encode(t('modules_js_uploading')); ?>,
        processingServer: <?php echo json_encode(t('modules_js_processing_server')); ?>,
        uploadFailedTitle: <?php echo json_encode(t('modules_js_upload_failed_title')); ?>,
        uploadErrorRetry: <?php echo json_encode(t('modules_js_upload_error_retry')); ?>,
        saving: <?php echo json_encode(t('modules_js_saving')); ?>,
        saved: <?php echo json_encode(t('modules_js_saved')); ?>,
        errorLabel: <?php echo json_encode(t('modules_js_error')); ?>,
        cooldownReady: <?php echo json_encode(t('modules_cooldown_ready')); ?>,
        cooldownMin: <?php echo json_encode(t('modules_cooldown_min')); ?>,
        cantWhitelistBlocked: <?php echo json_encode(t('modules_js_cant_whitelist_blocked')); ?>,
        noSpacesUrls: <?php echo json_encode(t('modules_js_no_spaces_urls')); ?>,
        urlAlreadyBlacklist: <?php echo json_encode(t('modules_js_url_already_blacklist')); ?>,
        urlAlreadyWhitelist: <?php echo json_encode(t('modules_js_url_already_whitelist')); ?>,
        globallyBlocked: <?php echo json_encode(t('modules_js_globally_blocked')); ?>,
        oneWordNoSpaces: <?php echo json_encode(t('modules_js_one_word_no_spaces')); ?>,
        unableResolveBot: <?php echo json_encode(t('modules_js_unable_resolve_bot')); ?>,
        errorResolvingBot: <?php echo json_encode(t('modules_js_error_resolving_bot')); ?>,
        noFilesSelectedFileList: <?php echo json_encode(t('modules_no_files_selected')); ?>,
        uploadMp3Files: <?php echo json_encode(t('modules_upload_mp3_files')); ?>,
        confirmDeleteFile: <?php echo json_encode(t('modules_js_confirm_delete_file')); ?>,
        confirmDeleteSelected: <?php echo json_encode(t('modules_js_confirm_delete_selected')); ?>,
        rename: <?php echo json_encode(t('upload_rename')); ?>,
        renameTitle: <?php echo json_encode(t('upload_rename_title')); ?>,
        renameHint: <?php echo json_encode(t('upload_rename_prompt')); ?>,
        renameConfirm: <?php echo json_encode(t('upload_rename_confirm')); ?>,
        renameCancel: <?php echo json_encode(t('upload_rename_cancel')); ?>,
        renameEmpty: <?php echo json_encode(t('upload_rename_empty')); ?>,
        success: <?php echo json_encode(t('upload_rename_success')); ?>,
        failed: <?php echo json_encode(t('upload_rename_failed')); ?>,
        exists: <?php echo json_encode(t('upload_rename_exists')); ?>,
        invalid: <?php echo json_encode(t('upload_rename_invalid')); ?>,
        missing: <?php echo json_encode(t('upload_rename_missing')); ?>,
        same: <?php echo json_encode(t('upload_rename_same')); ?>,
        confirmRemoveGame: <?php echo json_encode(t('modules_js_confirm_remove_game')); ?>,
        uploadingFiles: <?php echo json_encode(t('modules_js_uploading_files')); ?>,
        uploadingPercent: <?php echo json_encode(t('modules_js_uploading_percent')); ?>,
        statusEnabled: <?php echo json_encode(t('builtin_commands_status_enabled')); ?>,
        statusDisabled: <?php echo json_encode(t('builtin_commands_status_disabled')); ?>,
        enableLabel: <?php echo json_encode(t('builtin_commands_enable')); ?>,
        disableLabel: <?php echo json_encode(t('builtin_commands_disable')); ?>,
        protectionRemove: <?php echo json_encode(t('protection_remove')); ?>,
        noWhitelistedLinks: <?php echo json_encode(t('modules_no_whitelisted_links')); ?>,
        noBlacklistedLinks: <?php echo json_encode(t('modules_no_blacklisted_links')); ?>,
        noBlockedTerms: <?php echo json_encode(t('modules_no_blocked_terms')); ?>,
        noIgnoredWords: <?php echo json_encode(t('modules_word_replace_no_ignored_words')); ?>,
        noOptedOutUsers: <?php echo json_encode(t('modules_word_replace_no_opted_out_users')); ?>,
        noGamesIgnored: <?php echo json_encode(t('modules_no_games_ignored')); ?>,
        notMapped: <?php echo json_encode(t('modules_not_mapped')); ?>,
        removeMapping: <?php echo json_encode(t('modules_remove_mapping')); ?>,
        selectEvent: <?php echo json_encode(t('modules_select_event')); ?>,
        statusVerified: <?php echo json_encode(t('modules_status_verified')); ?>,
        statusPending: <?php echo json_encode(t('modules_status_pending_verification')); ?>,
        confirmRemoveBot: <?php echo json_encode(t('modules_js_confirm_remove_bot')); ?>,
        loadError: <?php echo json_encode(t('modules_js_unknown_error')); ?>
    };
    var modulesSoundEvents = [];
    var modulesSoundEventLabels = {};

    function modulesEscapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function modulesSetBusy(el, busy) {
        if (el) el.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function modulesSetSelectValue(selector, value) {
        var el = document.querySelector(selector);
        if (el) el.value = value == null ? '' : String(value);
    }

    function modulesDefaultExpressiveVoice() {
        var sel = document.getElementById('tts_expressive_voice');
        if (!sel || sel.value) return;
        for (var i = 0; i < sel.options.length; i++) {
            var label = (sel.options[i].text || '').replace(/\s*\[.*?\]\s*$/, '').trim().toLowerCase();
            if (label === 'callum') {
                sel.value = sel.options[i].value;
                return;
            }
        }
    }

    function modulesSyncTtsStyleFields() {
        var styleEl = document.getElementById('tts_style');
        var normalEl = document.getElementById('tts-normal-fields');
        var expressiveEl = document.getElementById('tts-expressive-fields');
        if (!styleEl || !normalEl || !expressiveEl) return;
        var expressive = styleEl.value === 'expressive';
        normalEl.style.display = expressive ? 'none' : 'grid';
        expressiveEl.style.display = expressive ? '' : 'none';
        if (expressive) modulesDefaultExpressiveVoice();
    }

    function modulesSetInputValue(selector, value) {
        var el = document.querySelector(selector);
        if (el) el.value = value == null ? '' : String(value);
    }

    function modulesSetToggle(id, on) {
        var checkbox = document.getElementById(id);
        if (!checkbox) return;
        checkbox.checked = !!on;
        var icon = document.querySelector('label[for="' + id + '"] i');
        if (icon) {
            icon.classList.toggle('fa-toggle-on', !!on);
            icon.classList.toggle('fa-toggle-off', !on);
            icon.style.color = on ? 'var(--green)' : 'var(--text-muted)';
        }
    }

    function modulesRenderStatusToggle(opts) {
        var enabled = !!opts.enabled;
        if (opts.badge) {
            opts.badge.className = 'sp-badge ' + (enabled ? 'sp-badge-green' : 'sp-badge-red');
            opts.badge.removeAttribute('aria-hidden');
            opts.badge.textContent = enabled ? MODULES_I18N.statusEnabled : MODULES_I18N.statusDisabled;
        }
        if (opts.valueInput) opts.valueInput.value = opts.offValue;
        if (opts.button) {
            opts.button.className = 'sp-btn sp-btn-sm ' + (enabled ? 'sp-btn-danger' : 'sp-btn-success');
            var icon = opts.button.querySelector('i');
            var label = opts.button.querySelector('span');
            if (icon) icon.className = 'fas ' + (enabled ? 'fa-times' : 'fa-check');
            if (label) label.textContent = enabled ? MODULES_I18N.disableLabel : MODULES_I18N.enableLabel;
        }
        if (opts.form) opts.form.style.display = 'inline';
        modulesSetBusy(opts.host, false);
    }

    function modulesRenderLinkRows(tbodyId, links, removeName, emptyText) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        modulesSetBusy(tbody, false);
        if (!links.length) {
            tbody.innerHTML = '<tr><td colspan="2" style="text-align:center; color:var(--text-muted);"><i class="fas fa-info-circle"></i> ' + modulesEscapeHtml(emptyText) + '</td></tr>';
            return;
        }
        tbody.innerHTML = links.map(function(link) {
            return '<tr><td>' + modulesEscapeHtml(link) + '</td><td style="text-align:right;"><form action="/api/module_data_post.php" method="post" style="display:inline;"><input type="hidden" name="' + removeName + '" value="' + modulesEscapeHtml(link) + '"><button type="submit" class="sp-btn sp-btn-danger sp-btn-sm"><i class="fas fa-trash-alt"></i><span>' + modulesEscapeHtml(MODULES_I18N.protectionRemove) + '</span></button></form></td></tr>';
        }).join('');
    }

    function modulesHydrate(data) {
        var jokeEnabled = data.joke_command_status === 'Enabled';
        modulesRenderStatusToggle({
            enabled: jokeEnabled,
            host: document.getElementById('jokeCommandHost'),
            badge: document.getElementById('jokeCommandStatusBadge'),
            form: document.getElementById('jokeCommandForm'),
            valueInput: document.getElementById('jokeCommandStatusValue'),
            button: document.getElementById('jokeCommandToggleBtn'),
            offValue: jokeEnabled ? 'Disabled' : 'Enabled'
        });
        var blacklist = Array.isArray(data.current_blacklist) ? data.current_blacklist : [];
        document.querySelectorAll('input[name="blacklist[]"]').forEach(function(box) {
            box.checked = blacklist.indexOf(box.value) !== -1;
        });

        var welcome = data.welcome || {};
        modulesRenderStatusToggle({
            enabled: !!welcome.send_welcome_messages,
            host: document.getElementById('welcomeStatusHost'),
            badge: document.getElementById('welcomeStatusBadge'),
            form: document.getElementById('welcomeStatusForm'),
            valueInput: document.getElementById('welcomeStatusValue'),
            button: document.getElementById('welcomeStatusToggleBtn'),
            offValue: welcome.send_welcome_messages ? '0' : '1'
        });
        ['new_default_welcome_message', 'default_welcome_message', 'new_default_vip_welcome_message', 'default_vip_welcome_message', 'new_default_mod_welcome_message', 'default_mod_welcome_message'].forEach(function(name) {
            modulesSetInputValue('input[name="' + name + '"]', welcome[name] || '');
        });

        var protection = data.protection || {};
        modulesSetSelectValue('#url_blocking', protection.url_blocking || 'False');
        modulesSetSelectValue('#term_blocking', protection.term_blocking || 'False');
        modulesSetSelectValue('#block_first_message_commands', protection.block_first_message_commands || 'False');
        modulesSetSelectValue('#block_first_message_command_mode', protection.block_first_message_command_mode || 'all');
        var cmdSelect = document.getElementById('block_first_message_selected_commands');
        var cmdHost = document.getElementById('blockCommandsHost');
        if (cmdSelect) {
            var selected = {};
            (protection.block_first_message_selected_commands || []).forEach(function(cmd) { selected[cmd] = true; });
            cmdSelect.innerHTML = (protection.available_commands || []).map(function(cmd) {
                return '<option value="' + modulesEscapeHtml(cmd) + '"' + (selected[cmd] ? ' selected' : '') + '>!' + modulesEscapeHtml(cmd) + '</option>';
            }).join('');
            cmdSelect.style.display = '';
        }
        if (cmdHost) {
            var skel = cmdHost.querySelector('.sp-skeleton-stack');
            if (skel) skel.remove();
            modulesSetBusy(cmdHost, false);
        }

        modulesRenderLinkRows('whitelistLinksBody', data.whitelist_links || [], 'remove_whitelist_link', MODULES_I18N.noWhitelistedLinks);
        modulesRenderLinkRows('blacklistLinksBody', data.blacklist_links || [], 'remove_blacklist_link', MODULES_I18N.noBlacklistedLinks);
        modulesRenderLinkRows('blockedTermsBody', data.blocked_terms || [], 'remove_blocked_term', MODULES_I18N.noBlockedTerms);

        var wr = data.word_replace || {};
        modulesSetSelectValue('#word_replace_enabled', wr.enabled || 'False');
        modulesSetInputValue('input[name="word_replace_word"]', wr.word || 'fun');
        modulesSetInputValue('input[name="word_replace_frequency"]', wr.frequency);
        modulesSetInputValue('input[name="word_replace_rate"]', wr.rate);
        modulesSetInputValue('input[name="word_replace_cooldown"]', wr.cooldown);
        var wrWordsBody = document.getElementById('wordReplaceIgnoredWordsBody');
        if (wrWordsBody) {
            modulesSetBusy(wrWordsBody, false);
            var wrWords = wr.ignored_words || [];
            wrWordsBody.innerHTML = wrWords.length
                ? wrWords.map(function(word) {
                    return '<tr><td>' + modulesEscapeHtml(word) + '</td><td style="text-align:right;"><form action="/api/module_data_post.php" method="post" style="display:inline;"><input type="hidden" name="remove_word_replace_ignored_word" value="' + modulesEscapeHtml(word) + '"><button type="submit" class="sp-btn sp-btn-danger sp-btn-sm"><i class="fas fa-trash-alt"></i></button></form></td></tr>';
                }).join('')
                : '<tr><td style="text-align:center;color:var(--text-muted);">' + modulesEscapeHtml(MODULES_I18N.noIgnoredWords) + '</td></tr>';
        }
        var wrUsersBody = document.getElementById('wordReplaceIgnoredUsersBody');
        if (wrUsersBody) {
            modulesSetBusy(wrUsersBody, false);
            var wrUsers = wr.ignored_users || [];
            wrUsersBody.innerHTML = wrUsers.length
                ? wrUsers.map(function(user) {
                    return '<tr><td>' + modulesEscapeHtml(user.username) + '</td><td>' + modulesEscapeHtml(user.opted_out_at || '') + '</td><td>' + modulesEscapeHtml(user.source || 'chat') + '</td><td style="text-align:right;"><form action="/api/module_data_post.php" method="post" style="display:inline;"><input type="hidden" name="remove_word_replace_ignored_user" value="' + modulesEscapeHtml(user.username) + '"><button type="submit" class="sp-btn sp-btn-danger sp-btn-sm"><i class="fas fa-trash-alt"></i></button></form></td></tr>';
                }).join('')
                : '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);">' + modulesEscapeHtml(MODULES_I18N.noOptedOutUsers) + '</td></tr>';
        }

        var gamesHost = document.getElementById('ignoredGamesHost');
        if (gamesHost) {
            var games = data.ignored_games || [];
            if (!games.length) {
                gamesHost.innerHTML = '<p style="color:var(--text-muted);">' + modulesEscapeHtml(MODULES_I18N.noGamesIgnored) + '</p>';
            } else {
                gamesHost.innerHTML = '<div style="display:flex; flex-wrap:wrap; gap:0.5rem;">' + games.map(function(game) {
                    return '<span class="sp-badge sp-badge-red" style="font-size:0.9rem; padding:0.3rem 0.6rem;">' + modulesEscapeHtml(game) + '<button type="button" onclick="removeIgnoredGame(\'' + String(game).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + '\')" style="background:none; border:none; cursor:pointer; color:inherit; margin-left:0.4rem; font-size:0.85rem;">&times;</button></span>';
                }).join('') + '</div>';
            }
            modulesSetBusy(gamesHost, false);
        }

        var ad = data.ad || {};
        ['enable_upcoming_ad_message', 'enable_1min_ad_message', 'enable_start_ad_message', 'enable_end_ad_message', 'enable_snoozed_ad_message', 'enable_raid_ad_snooze', 'enable_raid_ad_snooze_message', 'enable_ad_notice', 'enable_ai_ad_breaks'].forEach(function(id) {
            modulesSetToggle(id, !!ad[id]);
        });
        ['ad_upcoming_message', 'ad_1min_message', 'ad_start_message', 'ad_end_message', 'ad_snoozed_message', 'raid_ad_snooze_message'].forEach(function(name) {
            modulesSetInputValue('textarea[name="' + name + '"]', ad[name] || '');
        });
        modulesSetInputValue('#raid_ad_snooze_window_minutes', ad.raid_ad_snooze_window_minutes);

        var storage = data.storage || {};
        var storageText = document.getElementById('storageUsageText');
        var storageBar = document.getElementById('storageUsageBar');
        if (storageText) storageText.textContent = (storage.used_mb || 0) + 'MB / ' + (storage.max_mb || 0) + 'MB (' + (storage.percentage || 0) + '%)';
        if (storageBar) storageBar.value = storage.percentage || 0;
        modulesSetBusy(document.getElementById('storageUsageHost'), false);

        modulesSoundEvents = data.sound_events || [];
        modulesSoundEventLabels = data.sound_event_labels || {};
        var soundBody = document.getElementById('soundAlertsBody');
        var soundEmpty = document.getElementById('soundAlertsEmpty');
        var soundForm = document.getElementById('deleteForm');
        var files = data.sound_files || [];
        var mappings = data.sound_mappings || {};
        if (soundBody) {
            modulesSetBusy(soundBody, false);
            if (!files.length) {
                if (soundEmpty) soundEmpty.style.display = '';
                if (soundForm) soundForm.style.display = 'none';
                soundBody.innerHTML = '';
            } else {
                if (soundEmpty) soundEmpty.style.display = 'none';
                if (soundForm) soundForm.style.display = '';
                soundBody.innerHTML = files.map(function(file) {
                    var currentMapped = mappings[file] || null;
                    var mappedElsewhere = {};
                    Object.keys(mappings).forEach(function(mappedFile) {
                        if (mappedFile !== file && mappings[mappedFile]) mappedElsewhere[mappings[mappedFile]] = true;
                    });
                    var label = currentMapped ? (modulesSoundEventLabels[currentMapped] || currentMapped) : MODULES_I18N.notMapped;
                    var options = '';
                    if (currentMapped) options += '<option value="">' + modulesEscapeHtml(MODULES_I18N.removeMapping) + '</option>';
                    options += '<option value="">' + modulesEscapeHtml(MODULES_I18N.selectEvent) + '</option>';
                    modulesSoundEvents.forEach(function(evt) {
                        if (mappedElsewhere[evt] && currentMapped !== evt) return;
                        options += '<option value="' + modulesEscapeHtml(evt) + '"' + (currentMapped === evt ? ' selected' : '') + '>' + modulesEscapeHtml(modulesSoundEventLabels[evt] || evt) + '</option>';
                    });
                    var base = String(file).replace(/\.[^/.]+$/, '');
                    return '<tr><td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="' + modulesEscapeHtml(file) + '"></td><td>' + modulesEscapeHtml(base) + '</td><td style="text-align:center;"><em>' + modulesEscapeHtml(label) + '</em><form action="/api/module_data_post.php" method="POST" class="mapping-form" style="margin-top:0.5rem;"><input type="hidden" name="sound_file" value="' + modulesEscapeHtml(file) + '"><select name="twitch_alert_id" class="sp-select mapping-select">' + options + '</select></form></td><td style="text-align:center;"><span class="file-row-actions"><button type="button" class="rename-single sp-btn sp-btn-secondary sp-btn-sm" data-file="' + modulesEscapeHtml(file) + '" title="' + modulesEscapeHtml(MODULES_I18N.rename) + '"><i class="fas fa-pencil-alt"></i></button><button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="' + modulesEscapeHtml(file) + '"><i class="fas fa-trash"></i></button></span></td><td style="text-align:center;"><button type="button" class="test-sound sp-btn sp-btn-primary sp-btn-sm" data-file="twitch/' + modulesEscapeHtml(file) + '"><i class="fas fa-play"></i></button></td></tr>';
                }).join('');
            }
        }

        var chat = data.chat_alerts || {};
        Object.keys(chat).forEach(function(name) {
            modulesSetInputValue('input[name="' + name + '"]', chat[name]);
        });

        var shout = data.shoutouts || {};
        modulesSetInputValue('input[name="cooldown_minutes"]', shout.cooldown_minutes || 60);
        updateShoutoutCooldownTable({ tracking: shout.tracking || [] });
        modulesSetBusy(document.getElementById('shoutoutTrackingBody'), false);

        if (data.tts) {
            modulesSetSelectValue('#tts_voice', data.tts.voice || 'Alloy');
            modulesSetSelectValue('#tts_language', data.tts.language || 'en');
            modulesSetSelectValue('#tts_style', data.tts.style || 'normal');
            if (data.tts.expressive_voice) {
                modulesSetSelectValue('#tts_expressive_voice', data.tts.expressive_voice);
            }
            modulesSyncTtsStyleFields();
        }

        var botsBody = document.getElementById('moduleBotsBody');
        var botsEmpty = document.getElementById('moduleBotsEmpty');
        var botsWrap = document.getElementById('moduleBotsTableWrap');
        var bots = data.module_bots || [];
        if (botsBody) {
            modulesSetBusy(botsBody, false);
            if (!bots.length) {
                if (botsEmpty) botsEmpty.style.display = '';
                if (botsWrap) botsWrap.style.display = 'none';
                botsBody.innerHTML = '';
            } else {
                if (botsEmpty) botsEmpty.style.display = 'none';
                if (botsWrap) botsWrap.style.display = '';
                botsBody.innerHTML = bots.map(function(bot) {
                    var verified = parseInt(bot.is_verified, 10) === 1;
                    var badge = verified
                        ? '<span class="sp-badge sp-badge-green"><i class="fas fa-check-circle"></i> ' + modulesEscapeHtml(MODULES_I18N.statusVerified) + '</span>'
                        : '<span class="sp-badge sp-badge-amber"><i class="fas fa-clock"></i> ' + modulesEscapeHtml(MODULES_I18N.statusPending) + '</span>';
                    var confirmMsg = String(MODULES_I18N.confirmRemoveBot).replace(':name', bot.bot_username || '');
                    return '<tr><td><i class="fas fa-robot"></i> ' + modulesEscapeHtml(bot.bot_username) + '</td><td><code>' + modulesEscapeHtml(bot.bot_channel_id) + '</code></td><td>' + badge + '</td><td style="text-align:right;"><form method="post" style="display:inline;" onsubmit="return confirm(' + JSON.stringify(confirmMsg) + ');"><input type="hidden" name="action" value="remove_module_bot"><input type="hidden" name="record_id" value="' + modulesEscapeHtml(bot.id) + '"><button type="submit" class="sp-btn sp-btn-danger sp-btn-sm"><i class="fas fa-trash-alt"></i><span>' + modulesEscapeHtml(MODULES_I18N.protectionRemove) + '</span></button></form></td></tr>';
                }).join('');
            }
        }

        var spotify = data.spotify || {};
        var spotifyEnabled = document.getElementById('spotify_enabled');
        if (spotifyEnabled) spotifyEnabled.checked = !!spotify.enabled;
        modulesSetInputValue('input[name="max_song_seconds"]', spotify.max_song_seconds);
        modulesSetInputValue('input[name="max_queue_length"]', spotify.max_queue_length);
        modulesSetInputValue('input[name="per_viewer_limit"]', spotify.per_viewer_limit);

        document.querySelectorAll('.chat-alert-input, .ad-notice-input, .welcome-message-input').forEach(function(input) {
            var counter = document.querySelector('.char-count[data-field="' + input.getAttribute('name') + '"]');
            if (counter) counter.textContent = String(input.value || '').length;
        });
        var cmdSelectEl = document.getElementById('block_first_message_commands');
        var cmdModeEl = document.getElementById('block_first_message_command_mode');
        var cmdWrapper = document.getElementById('block-first-message-selected-wrapper');
        var cmdList = document.getElementById('block_first_message_selected_commands');
        if (cmdSelectEl && cmdModeEl && cmdWrapper) {
            var shouldShow = cmdSelectEl.value === 'True' && cmdModeEl.value === 'selected';
            cmdWrapper.style.display = shouldShow ? '' : 'none';
            if (cmdList) cmdList.disabled = !shouldShow;
        }
    }

    function loadModulesList() {
        var url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax_action', 'list');
        fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) throw new Error('bad');
                modulesHydrate(data);
            })
            .catch(function() {
                ['whitelistLinksBody', 'blacklistLinksBody', 'blockedTermsBody', 'wordReplaceIgnoredWordsBody', 'wordReplaceIgnoredUsersBody', 'soundAlertsBody', 'shoutoutTrackingBody', 'moduleBotsBody'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) {
                        modulesSetBusy(el, false);
                        el.innerHTML = '<tr><td colspan="5" style="text-align:center;">' + modulesEscapeHtml(MODULES_I18N.loadError) + '</td></tr>';
                    }
                });
                var gamesHost = document.getElementById('ignoredGamesHost');
                if (gamesHost) {
                    modulesSetBusy(gamesHost, false);
                    gamesHost.innerHTML = '<p style="color:var(--text-muted);">' + modulesEscapeHtml(MODULES_I18N.loadError) + '</p>';
                }
                modulesSetBusy(document.getElementById('jokeCommandHost'), false);
                modulesSetBusy(document.getElementById('welcomeStatusHost'), false);
                modulesSetBusy(document.getElementById('storageUsageHost'), false);
                modulesSetBusy(document.getElementById('blockCommandsHost'), false);
            });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var ttsStyle = document.getElementById('tts_style');
        if (ttsStyle) {
            ttsStyle.addEventListener('change', modulesSyncTtsStyleFields);
            modulesSyncTtsStyleFields();
        }
        loadModulesList();
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
            $('#file-list').append('<div class="sp-alert sp-alert-info">' + MODULES_I18N.uploadingWait + '</div>');
            $.ajax({
                url: '/api/module_data_post.php',
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
                            $('#file-list').html('<div class="sp-alert sp-alert-success">' + MODULES_I18N.uploadCompleted + '</div>');
                            // Reload the page after a short delay
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            $('#file-list').html('<div class="sp-alert sp-alert-danger">' + MODULES_I18N.uploadFailedPrefix + ' ' + (result.status || MODULES_I18N.unknownError) + '</div>');
                        }
                    } catch (e) {
                        console.error("Error parsing response:", e);
                        $('#file-list').html('<div class="sp-alert sp-alert-danger">' + MODULES_I18N.errorProcessingResponse + '</div>');
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                    console.error('Response:', jqXHR.responseText);
                    $('#file-list').html('<div class="sp-alert sp-alert-danger">' + MODULES_I18N.uploadFailedPrefix + ' ' + textStatus + '<br>' + MODULES_I18N.checkSizeNote + '</div>');
                }
            });
        }
        // Test sound / delete-single buttons are rendered after ajax hydrate
        document.addEventListener('click', function (e) {
            var testBtn = e.target.closest('.test-sound');
            if (testBtn) {
                sendStreamEvent('SOUND_ALERT', testBtn.getAttribute('data-file'));
                return;
            }
            var renameBtn = e.target.closest('.rename-single');
            if (renameBtn) {
                var renameFile = renameBtn.getAttribute('data-file');
                if (!renameFile || typeof specterPromptRename !== 'function') return;
                specterPromptRename({
                    currentName: renameFile,
                    title: MODULES_I18N.renameTitle,
                    hint: MODULES_I18N.renameHint,
                    confirmText: MODULES_I18N.renameConfirm,
                    cancelText: MODULES_I18N.renameCancel,
                    emptyError: MODULES_I18N.renameEmpty
                }).then(function (nextName) {
                    if (!nextName) return;
                    return specterPostRename('/api/module_data_post.php', {
                        rename_file: renameFile,
                        new_name: nextName
                    }).then(function (data) {
                        if (data && data.success) {
                            loadModulesList();
                        } else {
                            Swal.fire({ icon: 'error', title: MODULES_I18N.failed, text: specterRenameMessage(data, MODULES_I18N) });
                        }
                    });
                }).catch(function () {
                    Swal.fire({ icon: 'error', title: MODULES_I18N.failed, text: MODULES_I18N.failed });
                });
                return;
            }
            var deleteBtn = e.target.closest('.delete-single');
            if (deleteBtn) {
                const fileName = deleteBtn.getAttribute('data-file');
                if (confirm(MODULES_I18N.confirmDeleteFile.replace(':name', fileName))) {
                    let form = document.getElementById('deleteForm');
                    let input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'delete_files[]';
                    input.value = fileName;
                    form.appendChild(input);
                    form.submit();
                }
            }
        });
        // Handle delete selected button for Twitch audio alerts
        $('#deleteSelectedBtn').on('click', function () {
            var checkedBoxes = $('input[name="delete_files[]"]:checked');
            if (checkedBoxes.length > 0) {
                if (confirm(MODULES_I18N.confirmDeleteSelected.replace(':count', checkedBoxes.length))) {
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
            $('#file-list').text(fileNames.length ? fileNames.join(', ') : MODULES_I18N.noFilesSelectedFileList);
        });
        // AJAX upload with progress bar
        $('#uploadForm').on('submit', function (e) {
            e.preventDefault();
            var files = $('#filesToUpload')[0].files;
            if (files.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: MODULES_I18N.noFilesSelectedTitle,
                    text: MODULES_I18N.noFilesSelectedText,
                    confirmButtonColor: '#3273dc'
                });
                return;
            }
            let formData = new FormData(this);
            // Show upload status and update UI
            $('#uploadStatusContainer').show();
            $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + MODULES_I18N.uploadingFiles.replace(':count', files.length));
            $('#uploadProgressPercent').text('0%');
            $('#uploadProgress').val(0);
            // Update button state
            $('#uploadBtn').prop('disabled', true).removeClass('sp-btn-primary').addClass('sp-btn-loading');
            $('#uploadBtnText').text(MODULES_I18N.uploading);
            $.ajax({
                url: '/api/module_data_post.php',
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
                                $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + MODULES_I18N.uploadingPercent.replace(':percent', percentComplete));
                            } else {
                                $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + MODULES_I18N.processingServer);
                            }
                        }
                    }, false);
                    return xhr;
                },
                success: function (response) {
                    $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + MODULES_I18N.uploadCompleted);
                    $('#uploadProgressPercent').text('100%');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                    $('#uploadStatusContainer').hide();
                    $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading').addClass('sp-btn-primary');
                    $('#uploadBtnText').text(MODULES_I18N.uploadMp3Files);
                    Swal.fire({
                        icon: 'error',
                        title: MODULES_I18N.uploadFailedTitle,
                        text: MODULES_I18N.uploadErrorRetry,
                        confirmButtonColor: '#3273dc'
                    });
                }
            });
        });
        // Mapping selects are rendered after ajax hydrate
        $(document).on('change', '.mapping-select', function () {
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
                this.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + MODULES_I18N.saving + '</span>';
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
                this.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + MODULES_I18N.saving + '</span>';
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
                fetch('/api/module_data_post.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.text())
                    .then(data => {
                        // Reset button state
                        this.innerHTML = originalText;
                        this.disabled = false;
                        // Show success feedback
                        this.innerHTML = '<span class="icon"><i class="fas fa-check"></i></span><span>' + MODULES_I18N.saved + '</span>';
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
                        this.innerHTML = '<span class="icon"><i class="fas fa-times"></i></span><span>' + MODULES_I18N.errorLabel + '</span>';
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
        const url = "/api/notify_event.php";
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
        if (confirm(MODULES_I18N.confirmRemoveGame.replace(':name', gameName))) {
            // Create a form to submit the removal request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/api/module_data_post.php';
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
        fetch('/api/get_shoutout_cooldowns.php')
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
        const tableBody = document.getElementById('shoutoutTrackingBody') || document.querySelector('#automated-shoutouts tbody');
        const noDataNotification = document.getElementById('shoutoutEmpty') || document.querySelector('#automated-shoutouts .sp-alert.sp-alert-info');
        if (!tableBody) return;
        const tracking = (data && data.tracking) ? data.tracking : [];
        if (tracking.length === 0) {
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
            tracking.forEach(function(row) {
                const isExpired = row.is_expired;
                const rowClass = isExpired ? ' style="color:var(--text-muted);"' : '';
                const statusTag = isExpired
                    ? `<span class="sp-badge sp-badge-green">${MODULES_I18N.cooldownReady}</span>`
                    : `<span class="sp-badge sp-badge-amber">${row.remaining_minutes} ${MODULES_I18N.cooldownMin}</span>`;
                html += `
                    <tr${rowClass}>
                        <td>${escapeHtml(row.user_name)}</td>
                        <td>${row.shoutout_time}</td>
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
        whitelistErrorMessage.textContent = MODULES_I18N.cantWhitelistBlocked;
        whitelistInput.parentElement.parentElement.appendChild(whitelistErrorMessage);
        whitelistInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(whitelistCheckTimeout);
            // Check for spaces FIRST using raw value - immediate validation
            if (whitelistInput.value.includes(' ')) {
                whitelistInput.classList.add('input-error');
                whitelistErrorMessage.textContent = MODULES_I18N.noSpacesUrls;
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
                fetch('/api/check_spam_pattern.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.matches === true) {
                        // Show error state
                        whitelistInput.classList.add('input-error');
                        whitelistErrorMessage.textContent = MODULES_I18N.cantWhitelistBlocked;
                        whitelistErrorMessage.style.display = 'block';
                        whitelistButton.disabled = true;
                        return;
                    }
                    // If spam check passes, check if URL is in blacklist
                    const conflictData = new FormData();
                    conflictData.append('link', linkValue);
                    conflictData.append('check_list', 'blacklist');
                    return fetch('/api/check_url_conflict.php', {
                        method: 'POST',
                        body: conflictData
                    });
                })
                .then(response => response ? response.json() : null)
                .then(data => {
                    if (data && data.exists === true) {
                        // URL already in blacklist
                        whitelistInput.classList.add('input-error');
                        whitelistErrorMessage.textContent = MODULES_I18N.urlAlreadyBlacklist;
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
        blacklistErrorMessage.textContent = MODULES_I18N.globallyBlocked;
        blacklistInput.parentElement.parentElement.appendChild(blacklistErrorMessage);
        blacklistInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(blacklistCheckTimeout);
            // Check for spaces FIRST using raw value - immediate validation
            if (blacklistInput.value.includes(' ')) {
                blacklistInput.classList.add('input-error');
                blacklistErrorMessage.textContent = MODULES_I18N.noSpacesUrls;
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
                fetch('/api/check_spam_pattern.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.matches === true) {
                        // Show error state
                        blacklistInput.classList.add('input-error');
                        blacklistErrorMessage.textContent = MODULES_I18N.globallyBlocked;
                        blacklistErrorMessage.style.display = 'block';
                        blacklistButton.disabled = true;
                        return;
                    }
                    // If spam check passes, check if URL is in whitelist
                    const conflictData = new FormData();
                    conflictData.append('link', linkValue);
                    conflictData.append('check_list', 'whitelist');
                    return fetch('/api/check_url_conflict.php', {
                        method: 'POST',
                        body: conflictData
                    });
                })
                .then(response => response ? response.json() : null)
                .then(data => {
                    if (data && data.exists === true) {
                        // URL already in whitelist
                        blacklistInput.classList.add('input-error');
                        blacklistErrorMessage.textContent = MODULES_I18N.urlAlreadyWhitelist;
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
                blockedTermErrorMessage.textContent = MODULES_I18N.oneWordNoSpaces;
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
                fetch('/api/check_blocked_term.php', {
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
                        alert(j.error || MODULES_I18N.unableResolveBot);
                    }
                })
                .catch(function(err) {
                    setStatus('<i class="fas fa-times" style="color:var(--red);"></i>', false);
                    console.error(err);
                    alert(MODULES_I18N.errorResolvingBot);
                });
            });
        }
    })();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
