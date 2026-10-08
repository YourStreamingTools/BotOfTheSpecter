<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = 'Discord Bot Configuration Overview';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

// Connect to Discord bot database
$discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
if ($discord_conn->connect_error) {
    die('Discord Database Connection failed: ' . $discord_conn->connect_error);
}

// Names for a user's Discord IDs: their login token reads account and server, the bot token reads channels and roles (cached 10 min)
function discord_overview_api(string $path, string $authHeader): array
{
    $ch = curl_init('https://discord.com/api/v10/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [$authHeader, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($data) ? $data : null];
}

function discord_overview_names(mysqli $conn, int $userId): array
{
    $cacheDir = '/var/www/cache/discord_names';
    $cacheFile = $cacheDir . '/' . $userId . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 600) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $out = ['account' => null, 'token' => 'none', 'guild' => null, 'channels' => [], 'roles' => []];
    $stmt = $conn->prepare("SELECT discord_id, access_token, guild_id FROM discord_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return $out;
    }
    $discordId = preg_match('/^[0-9]{5,25}$/', (string) $row['discord_id']) ? (string) $row['discord_id'] : '';
    $guildId = preg_match('/^[0-9]{5,25}$/', (string) $row['guild_id']) ? (string) $row['guild_id'] : '';
    $token = trim((string) $row['access_token']);
    $bot_token = '';
    include '/var/www/config/discord.php';
    $botAuth = $bot_token !== '' ? 'Authorization: Bot ' . $bot_token : '';

    // The user's own login: their account name and their server list.
    if ($token !== '') {
        $userAuth = 'Authorization: Bearer ' . $token;
        [$status, $me] = discord_overview_api('oauth2/@me', $userAuth);
        if ($status === 200 && isset($me['user'])) {
            $out['token'] = 'valid';
            $out['account'] = (string) (($me['user']['global_name'] ?? '') ?: ($me['user']['username'] ?? ''));
            if ($guildId !== '') {
                [$status, $guilds] = discord_overview_api('users/@me/guilds', $userAuth);
                foreach (($status === 200 ? $guilds : []) ?: [] as $guild) {
                    if ((string) ($guild['id'] ?? '') === $guildId) {
                        $out['guild'] = (string) ($guild['name'] ?? '');
                        break;
                    }
                }
            }
        } else {
            $out['token'] = 'expired';
        }
    }
    // The bot fills in what the login token can't read.
    if ($botAuth !== '') {
        if ($out['account'] === null && $discordId !== '') {
            [$status, $user] = discord_overview_api('users/' . $discordId, $botAuth);
            if ($status === 200) {
                $out['account'] = (string) (($user['global_name'] ?? '') ?: ($user['username'] ?? ''));
            }
        }
        if ($guildId !== '') {
            if ($out['guild'] === null) {
                [$status, $guild] = discord_overview_api('guilds/' . $guildId, $botAuth);
                if ($status === 200) {
                    $out['guild'] = (string) ($guild['name'] ?? '');
                }
            }
            [$status, $channels] = discord_overview_api('guilds/' . $guildId . '/channels', $botAuth);
            foreach (($status === 200 ? $channels : []) ?: [] as $channel) {
                $out['channels'][(string) $channel['id']] = ['name' => (string) ($channel['name'] ?? ''), 'type' => (int) ($channel['type'] ?? 0)];
            }
            [$status, $roles] = discord_overview_api('guilds/' . $guildId . '/roles', $botAuth);
            foreach (($status === 200 ? $roles : []) ?: [] as $role) {
                $out['roles'][(string) $role['id']] = (string) ($role['name'] ?? '');
            }
        }
    }
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 02775, true);
    }
    @file_put_contents($cacheFile, json_encode($out), LOCK_EX);
    return $out;
}

if (isset($_GET['names'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(discord_overview_names($conn, (int) $_GET['names']), JSON_UNESCAPED_UNICODE);
    exit();
}

// A Discord ID shown on the page: the script swaps it for its name once loaded (ID stays on hover).
function discord_ref_html(string $kind, $id): string
{
    $id = (string) $id;
    if (!preg_match('/^[0-9]{5,25}$/', $id)) {
        return htmlspecialchars($id);
    }
    return '<span class="discord-ref" data-kind="' . htmlspecialchars($kind) . '" data-id="' . $id . '">' . $id . '</span>';
}

// Fetch all users
$users = [];
$result = $conn->query("SELECT id as user_id, username FROM users ORDER BY username ASC");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Initialize data array
$discordConfigData = [];

foreach ($users as $userRow) {
    $user_id = $userRow['user_id'];
    $username = $userRow['username'];
    $userDbName = $username;
    // Fetch Discord configuration from discord_users table
    $discordStmt = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
    $discordStmt->bind_param("i", $user_id);
    $discordStmt->execute();
    $discordResult = $discordStmt->get_result();
    if ($discordResult->num_rows > 0) {
        $discordData = $discordResult->fetch_assoc();
        // Initialize configuration array
        $userConfig = [
            'user_id' => $user_id,
            'username' => $username,
            'is_linked' => !empty($discordData['access_token']),
            'discord_username' => $discordData['discord_username'] ?? '',
            'discord_avatar' => $discordData['discord_avatar'] ?? '',
            'expires_at' => $discordData['expires_at'] ?? '',
            'guild_id' => $discordData['guild_id'] ?? '',
            'manual_ids' => $discordData['manual_ids'] ?? 0,
            'live_channel_id' => $discordData['live_channel_id'] ?? '',
            'time_now_channel_id' => $discordData['time_now_channel_id'] ?? '',
            'online_text' => $discordData['online_text'] ?? '',
            'offline_text' => $discordData['offline_text'] ?? '',
            'stream_alert_channel_id' => $discordData['stream_alert_channel_id'] ?? '',
            'stream_alert_everyone' => $discordData['stream_alert_everyone'] ?? 0,
            'stream_alert_custom_role' => $discordData['stream_alert_custom_role'] ?? '',
            'moderation_channel_id' => $discordData['moderation_channel_id'] ?? '',
            'alert_channel_id' => $discordData['alert_channel_id'] ?? '',
            'member_streams_id' => $discordData['member_streams_id'] ?? '',
            'tracked_streams' => [],
            'server_management_settings' => []
        ];
        // Fetch tracked streams from user's database if exists
        try {
            $userConn = new mysqli($db_servername, $db_username, $db_password, $userDbName);
            if (!$userConn->connect_error) {
                // Check if member_streams table exists
                $tableCheck = $userConn->query("SHOW TABLES LIKE 'member_streams'");
                if ($tableCheck->num_rows > 0) {
                    $streams = [];
                    $stmt = $userConn->prepare("SELECT username, stream_url FROM member_streams ORDER BY username ASC");
                    if ($stmt) {
                        $stmt->execute();
                        $resultStreams = $stmt->get_result();
                        while ($row = $resultStreams->fetch_assoc()) {
                            $streams[] = $row;
                        }
                        $stmt->close();
                    }
                    if (!empty($streams)) {
                        // Remove duplicates based on username
                        $uniqueStreams = [];
                        foreach ($streams as $stream) {
                            $uniqueStreams[$stream['username']] = $stream;
                        }
                        $userConfig['tracked_streams'] = array_values($uniqueStreams);
                    }
                }
                $userConn->close();
            }
        } catch (mysqli_sql_exception $e) {
            // User database doesn't exist, skip streams
        }
        // Fetch server management settings if guild_id is set
        if (!empty($userConfig['guild_id'])) {
            $mgmtStmt = $discord_conn->prepare("SELECT * FROM server_management WHERE server_id = ? OR id = ?");
            $mgmtStmt->bind_param("ss", $userConfig['guild_id'], $userConfig['guild_id']);
            $mgmtStmt->execute();
            $mgmtResult = $mgmtStmt->get_result();
            if ($mgmtResult->num_rows > 0) {
                $userConfig['server_management_settings'] = $mgmtResult->fetch_assoc();
            }
            $mgmtStmt->close();
        }
        $discordConfigData[$username] = $userConfig;
    }
    $discordStmt->close();
}

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><span class="icon"><i class="fab fa-discord"></i></span> <?php echo t('admin_discord_overview_title'); ?></h1>
        <div class="sp-btn-group">
            <input id="user-search" class="sp-input" type="text" placeholder="<?php echo htmlspecialchars(t('admin_discord_overview_search_placeholder'), ENT_QUOTES); ?>">
            <a id="clear-search" class="sp-btn" title="<?php echo htmlspecialchars(t('admin_discord_overview_clear_title'), ENT_QUOTES); ?>"><?php echo t('admin_discord_overview_clear'); ?></a>
        </div>
    </div>
    <!-- Modal for detailed user configuration -->
    <div id="user-config-modal" class="db-modal-backdrop hidden">
        <div class="db-modal">
            <header class="db-modal-head">
                <h2 id="config-modal-title" class="db-modal-title"><?php echo t('admin_discord_overview_modal_title'); ?></h2>
                <button class="db-modal-close" aria-label="<?php echo htmlspecialchars(t('admin_discord_overview_close'), ENT_QUOTES); ?>" id="config-modal-close"><i class="fas fa-times"></i></button>
            </header>
            <div class="db-modal-body" id="config-modal-body" style="max-height: 70vh; overflow-y: auto;">
                <!-- populated by JS -->
            </div>
            <footer class="db-modal-foot">
                <button class="sp-btn sp-btn-secondary" id="config-modal-close-btn"><?php echo t('admin_discord_overview_close'); ?></button>
            </footer>
        </div>
    </div>
    <div class="sp-card-body">
    <p style="margin-bottom:1rem;"><?php echo t('admin_discord_overview_intro'); ?></p>
    <?php if (empty($discordConfigData)): ?>
        <div class="sp-alert sp-alert-info">
            <p><?php echo t('admin_discord_overview_empty_state'); ?></p>
        </div>
    <?php else: ?>
        <div class="admin-discord-grid" id="config-cards">
            <?php foreach ($discordConfigData as $username => $config): ?>
                <?php 
                    $safeUser = htmlspecialchars($username);
                    $isLinked = $config['is_linked'];
                    $hasGuild = !empty($config['guild_id']);
                    $trackedCount = count($config['tracked_streams']);
                    $statusClass = $isLinked ? 'sp-badge-green' : 'sp-badge-amber';
                    $statusText = $isLinked ? t('admin_discord_overview_status_linked') : t('admin_discord_overview_status_not_linked');
                ?>
                <div class="discord-config-card config-card" data-username="<?php echo strtolower($safeUser); ?>" data-user-id="<?php echo (int) $config['user_id']; ?>" data-linked="<?php echo $isLinked ? '1' : '0'; ?>">
                    <div class="discord-config-card-head">
                        <span style="font-weight:600;"><?php echo $safeUser; ?></span>
                        <span class="sp-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                    </div>
                    <div class="discord-config-card-body">
                                <p><strong><?php echo t('admin_discord_overview_card_discord_user'); ?></strong> <span class="discord-ref" data-kind="account">…</span></p>
                                <p><strong><?php echo t('admin_discord_overview_card_guild_id'); ?></strong> <?php echo !empty($config['guild_id']) ? discord_ref_html('guild', $config['guild_id']) : '<em>' . t('admin_discord_overview_not_set') . '</em>'; ?></p>
                                <p><strong><?php echo t('admin_discord_overview_card_live_channel'); ?></strong> <?php echo !empty($config['live_channel_id']) ? discord_ref_html('channel', $config['live_channel_id']) : '<em>' . t('admin_discord_overview_not_set') . '</em>'; ?></p>
                                <p><strong><?php echo t('admin_discord_overview_card_time_now_channel'); ?></strong> <?php echo !empty($config['time_now_channel_id']) ? discord_ref_html('channel', $config['time_now_channel_id']) : '<em>' . t('admin_discord_overview_not_set') . '</em>'; ?></p>
                                <?php if ($trackedCount > 0): ?>
                                    <p><strong><?php echo t('admin_discord_overview_card_tracked_streams'); ?></strong> <span class="sp-badge sp-badge-blue"><?php echo $trackedCount; ?></span></p>
                                <?php endif; ?>
                                <?php 
                                    $enabledFeatures = [];
                                    if (!empty($config['stream_alert_channel_id'])) $enabledFeatures[] = t('admin_discord_overview_feature_stream_alerts');
                                    if (!empty($config['time_now_channel_id'])) $enabledFeatures[] = t('admin_discord_overview_feature_time_now');
                                    if (!empty($config['moderation_channel_id'])) $enabledFeatures[] = t('admin_discord_overview_feature_moderation');
                                    if (!empty($config['alert_channel_id'])) $enabledFeatures[] = t('admin_discord_overview_feature_alerts');
                                    if (!empty($config['member_streams_id'])) $enabledFeatures[] = t('admin_discord_overview_feature_stream_monitoring');
                                    if (!empty($config['server_management_settings'])):
                                        $mgmt = $config['server_management_settings'];
                                        if (!empty($mgmt['welcome_message_configuration_channel'])) $enabledFeatures[] = t('admin_discord_overview_feature_welcome_message');
                                        if (!empty($mgmt['auto_role_assignment_configuration_role_id'])) $enabledFeatures[] = t('admin_discord_overview_feature_auto_role');
                                        if (!empty($mgmt['message_tracking_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_message_tracking');
                                        if (!empty($mgmt['role_tracking_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_role_tracking');
                                        if (!empty($mgmt['role_history_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_role_history');
                                        if (!empty($mgmt['user_tracking_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_user_tracking');
                                        if (!empty($mgmt['server_role_management_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_server_role_mgmt');
                                        if (!empty($mgmt['reaction_roles_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_reaction_roles');
                                        if (!empty($mgmt['rules_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_rules');
                                        if (!empty($mgmt['stream_schedule_configuration'])) $enabledFeatures[] = t('admin_discord_overview_feature_stream_schedule');
                                    endif;
                                ?>
                                <?php if (!empty($enabledFeatures)): ?>
                                    <div class="mt-3">
                                        <p><strong><?php echo t('admin_discord_overview_card_enabled_features'); ?></strong></p>
                                        <div style="display:flex;flex-wrap:wrap;gap:0.3rem;">
                                            <?php foreach ($enabledFeatures as $feature): ?>
                                                <span class="sp-badge sp-badge-grey"><?php echo htmlspecialchars($feature); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                    </div><!-- /discord-config-card-body -->
                    <div class="discord-config-card-footer">
                        <a href="#" class="discord-config-card-footer-item view-config-btn" data-username="<?php echo htmlspecialchars($username); ?>"><?php echo t('admin_discord_overview_view_full_config'); ?></a>
                    </div>
                </div><!-- /discord-config-card -->
            <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-top:1rem;">
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                <p><strong><?php echo t('admin_discord_overview_stat_total_users'); ?></strong> <?php echo count($discordConfigData); ?></p>
                <p><strong><?php echo t('admin_discord_overview_stat_linked_users'); ?></strong> <?php echo count(array_filter($discordConfigData, fn($c) => $c['is_linked'])); ?></p>
                <p><strong><?php echo t('admin_discord_overview_stat_with_guild'); ?></strong> <?php echo count(array_filter($discordConfigData, fn($c) => !empty($c['guild_id']))); ?></p>
            </div>
            <p><strong><?php echo t('admin_discord_overview_stat_total_tracked_streams'); ?></strong> <?php echo array_sum(array_map(fn($c) => count($c['tracked_streams']), $discordConfigData)); ?></p>
        </div>
    <?php endif; ?>
    </div><!-- /sp-card-body -->
</div><!-- /sp-card -->
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('user-search');
    const clearBtn = document.getElementById('clear-search');
    const cardsContainer = document.getElementById('config-cards');
    const configModal = document.getElementById('user-config-modal');
    const configModalTitle = document.getElementById('config-modal-title');
    const configModalBody = document.getElementById('config-modal-body');
    const configModalClose = document.getElementById('config-modal-close');
    const configModalCloseBtn = document.getElementById('config-modal-close-btn');
    // Config data embedded from PHP
    const allConfigData = <?php echo json_encode($discordConfigData); ?>;
    // Localized strings injected from PHP
    const L = <?php echo json_encode([
        'modal_title_suffix' => t('admin_discord_overview_js_modal_title_suffix'),
        'sec_basic_info' => t('admin_discord_overview_js_sec_basic_info'),
        'sec_server_config' => t('admin_discord_overview_js_sec_server_config'),
        'sec_stream_config' => t('admin_discord_overview_js_sec_stream_config'),
        'sec_other_channels' => t('admin_discord_overview_js_sec_other_channels'),
        'sec_tracked_streams' => t('admin_discord_overview_js_sec_tracked_streams'),
        'sec_server_mgmt' => t('admin_discord_overview_js_sec_server_mgmt'),
        'sub_welcome_message' => t('admin_discord_overview_js_sub_welcome_message'),
        'sub_auto_role' => t('admin_discord_overview_js_sub_auto_role'),
        'sub_message_tracking' => t('admin_discord_overview_js_sub_message_tracking'),
        'sub_role_tracking' => t('admin_discord_overview_js_sub_role_tracking'),
        'sub_role_history' => t('admin_discord_overview_js_sub_role_history'),
        'sub_server_role_mgmt' => t('admin_discord_overview_js_sub_server_role_mgmt'),
        'sub_user_tracking' => t('admin_discord_overview_js_sub_user_tracking'),
        'sub_reaction_roles' => t('admin_discord_overview_js_sub_reaction_roles'),
        'sub_rules_config' => t('admin_discord_overview_js_sub_rules_config'),
        'sub_stream_schedule' => t('admin_discord_overview_js_sub_stream_schedule'),
        'lbl_twitch_username' => t('admin_discord_overview_js_lbl_twitch_username'),
        'lbl_discord_linked' => t('admin_discord_overview_js_lbl_discord_linked'),
        'lbl_discord_username' => t('admin_discord_overview_js_lbl_discord_username'),
        'lbl_guild_id' => t('admin_discord_overview_js_lbl_guild_id'),
        'lbl_manual_ids_mode' => t('admin_discord_overview_js_lbl_manual_ids_mode'),
        'lbl_live_channel_id' => t('admin_discord_overview_js_lbl_live_channel_id'),
        'lbl_time_now_channel_id' => t('admin_discord_overview_js_lbl_time_now_channel_id'),
        'lbl_online_text' => t('admin_discord_overview_js_lbl_online_text'),
        'lbl_offline_text' => t('admin_discord_overview_js_lbl_offline_text'),
        'lbl_stream_alert_channel' => t('admin_discord_overview_js_lbl_stream_alert_channel'),
        'lbl_alert_everyone' => t('admin_discord_overview_js_lbl_alert_everyone'),
        'lbl_custom_role_alerts' => t('admin_discord_overview_js_lbl_custom_role_alerts'),
        'lbl_moderation_channel' => t('admin_discord_overview_js_lbl_moderation_channel'),
        'lbl_alert_channel' => t('admin_discord_overview_js_lbl_alert_channel'),
        'lbl_stream_monitoring_channel' => t('admin_discord_overview_js_lbl_stream_monitoring_channel'),
        'lbl_channel' => t('admin_discord_overview_js_lbl_channel'),
        'lbl_use_default' => t('admin_discord_overview_js_lbl_use_default'),
        'lbl_use_embed' => t('admin_discord_overview_js_lbl_use_embed'),
        'lbl_custom_message' => t('admin_discord_overview_js_lbl_custom_message'),
        'lbl_color' => t('admin_discord_overview_js_lbl_color'),
        'lbl_role_id' => t('admin_discord_overview_js_lbl_role_id'),
        'lbl_enabled' => t('admin_discord_overview_js_lbl_enabled'),
        'lbl_log_channel' => t('admin_discord_overview_js_lbl_log_channel'),
        'lbl_track_edits' => t('admin_discord_overview_js_lbl_track_edits'),
        'lbl_track_deletes' => t('admin_discord_overview_js_lbl_track_deletes'),
        'lbl_track_additions' => t('admin_discord_overview_js_lbl_track_additions'),
        'lbl_track_removals' => t('admin_discord_overview_js_lbl_track_removals'),
        'lbl_retention_days' => t('admin_discord_overview_js_lbl_retention_days'),
        'lbl_track_creation' => t('admin_discord_overview_js_lbl_track_creation'),
        'lbl_track_deletion' => t('admin_discord_overview_js_lbl_track_deletion'),
        'lbl_track_joins' => t('admin_discord_overview_js_lbl_track_joins'),
        'lbl_track_leaves' => t('admin_discord_overview_js_lbl_track_leaves'),
        'lbl_track_nickname_changes' => t('admin_discord_overview_js_lbl_track_nickname_changes'),
        'lbl_track_username_changes' => t('admin_discord_overview_js_lbl_track_username_changes'),
        'lbl_track_avatar_changes' => t('admin_discord_overview_js_lbl_track_avatar_changes'),
        'lbl_track_status_changes' => t('admin_discord_overview_js_lbl_track_status_changes'),
        'lbl_channel_id' => t('admin_discord_overview_js_lbl_channel_id'),
        'lbl_message_id' => t('admin_discord_overview_js_lbl_message_id'),
        'lbl_allow_multiple' => t('admin_discord_overview_js_lbl_allow_multiple'),
        'lbl_mappings_configured' => t('admin_discord_overview_js_lbl_mappings_configured'),
        'lbl_title' => t('admin_discord_overview_js_lbl_title'),
        'lbl_accept_role' => t('admin_discord_overview_js_lbl_accept_role'),
        'lbl_timezone' => t('admin_discord_overview_js_lbl_timezone'),
        'lbl_status' => t('admin_discord_overview_js_lbl_status'),
        'th_username' => t('admin_discord_overview_js_th_username'),
        'th_stream_url' => t('admin_discord_overview_js_th_stream_url'),
        'val_yes' => t('admin_discord_overview_js_val_yes'),
        'val_no' => t('admin_discord_overview_js_val_no'),
        'val_enabled' => t('admin_discord_overview_js_val_enabled'),
        'val_disabled' => t('admin_discord_overview_js_val_disabled'),
        'val_not_set' => t('admin_discord_overview_js_val_not_set'),
        'val_not_configured' => t('admin_discord_overview_js_val_not_configured'),
        'val_configured' => t('admin_discord_overview_js_val_configured'),
        'val_default' => t('admin_discord_overview_js_val_default'),
        'val_no_url' => t('admin_discord_overview_js_val_no_url'),
        'name_not_found' => t('admin_discord_overview_name_not_found'),
        'token_expired' => t('admin_discord_overview_token_expired'),
    ]); ?>;
    // Discord IDs -> names. Each user's names load once (a few at a time) and are reused by the modal.
    const namesByUser = {};
    function ref(kind, id) {
        const value = String(id == null ? '' : id);
        if (!/^[0-9]{5,25}$/.test(value)) return escapeHtml(value);
        return '<span class="discord-ref" data-kind="' + kind + '" data-id="' + value + '">' + value + '</span>';
    }
    function loadNames(userId, username) {
        if (!namesByUser[username]) {
            namesByUser[username] = fetch('?names=' + encodeURIComponent(userId), { credentials: 'same-origin' })
                .then(r => r.json())
                .catch(() => null);
        }
        return namesByUser[username];
    }
    function applyNames(root, names) {
        if (!names) return;
        root.querySelectorAll('.discord-ref').forEach(el => {
            const kind = el.getAttribute('data-kind');
            const id = el.getAttribute('data-id') || '';
            let text = null;
            let voice = false;
            if (kind === 'account') {
                text = names.account || '—';
            } else if (kind === 'guild') {
                text = names.guild || null;
            } else if (kind === 'channel' && names.channels && names.channels[id]) {
                const channel = names.channels[id];
                voice = channel.type === 2 || channel.type === 13;
                text = (voice ? '' : '#') + channel.name;
            } else if (kind === 'role' && names.roles && names.roles[id]) {
                text = '@' + names.roles[id];
            }
            if (text === null) {
                // Channel or role no longer in the server (or the bot can't see it): keep the ID, say so.
                if ((kind === 'channel' && names.channels && Object.keys(names.channels).length) ||
                    (kind === 'role' && names.roles && Object.keys(names.roles).length)) {
                    el.textContent = id + ' (' + L.name_not_found + ')';
                }
                return;
            }
            el.textContent = '';
            if (voice) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-volume-up';
                el.appendChild(icon);
                el.appendChild(document.createTextNode(' '));
            }
            el.appendChild(document.createTextNode(text));
            if (id) el.title = id;
            el.classList.add('is-resolved');
        });
        const card = root.classList && root.classList.contains('config-card') ? root : null;
        if (card && names.token === 'expired' && !card.querySelector('.discord-token-expired')) {
            const badge = document.createElement('span');
            badge.className = 'sp-badge sp-badge-red discord-token-expired';
            badge.textContent = L.token_expired;
            card.querySelector('.discord-config-card-head').appendChild(badge);
        }
    }
    (function loadAllNames() {
        const cards = Array.from(document.querySelectorAll('.config-card[data-user-id]'));
        let next = 0;
        function worker() {
            if (next >= cards.length) return Promise.resolve();
            const card = cards[next++];
            const username = Object.keys(allConfigData).find(name => String(allConfigData[name].user_id) === card.getAttribute('data-user-id')) || card.getAttribute('data-username');
            return loadNames(card.getAttribute('data-user-id'), username).then(names => applyNames(card, names)).then(worker);
        }
        for (let i = 0; i < 3; i++) worker();
    })();
    // Debounce helper
    function debounce(fn, delay) {
        let t;
        return function(...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), delay);
        };
    }
    function filterCards() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const cards = cardsContainer.querySelectorAll('.config-card');
        if (!q) {
            cards.forEach(c => c.style.display = '');
            return;
        }
        cards.forEach(card => {
            const user = card.getAttribute('data-username') || '';
            card.style.display = user.includes(q) ? '' : 'none';
        });
    }
    const debouncedFilter = debounce(filterCards, 220);
    searchInput.addEventListener('input', debouncedFilter);
    clearBtn.addEventListener('click', function(e) { e.preventDefault(); searchInput.value = ''; debouncedFilter(); });
    // Open config modal
    function openConfigModal(username) {
        const config = allConfigData[username];
        if (!config) return;
        configModalTitle.textContent = username + L.modal_title_suffix;
        configModalBody.innerHTML = '';
        // Build detailed config HTML
        let html = '<div class="content">';
        // Basic Info
        html += `<h4 style="font-size:1rem;font-weight:700;margin:0.5rem 0;">${escapeHtml(L.sec_basic_info)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_twitch_username)}</strong></td><td>${escapeHtml(config.username)}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_discord_linked)}</strong></td><td><span class="sp-badge ${config.is_linked ? 'sp-badge-green' : 'sp-badge-amber'}">${config.is_linked ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</span></td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_discord_username)}</strong></td><td><span class="discord-ref" data-kind="account">…</span></td></tr>`;
        html += '</tbody></table>';
        // Server Configuration
        html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_server_config)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_guild_id)}</strong></td><td>${config.guild_id ? ref('guild', config.guild_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_manual_ids_mode)}</strong></td><td>${config.manual_ids ? escapeHtml(L.val_enabled) : escapeHtml(L.val_disabled)}</td></tr>`;
        html += '</tbody></table>';
        // Stream Configuration
        html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_stream_config)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_live_channel_id)}</strong></td><td>${config.live_channel_id ? ref('channel', config.live_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_time_now_channel_id)}</strong></td><td>${config.time_now_channel_id ? ref('channel', config.time_now_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_online_text)}</strong></td><td>${config.online_text ? escapeHtml(config.online_text) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_offline_text)}</strong></td><td>${config.offline_text ? escapeHtml(config.offline_text) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += '<tr><td colspan="2"><hr></td></tr>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_stream_alert_channel)}</strong></td><td>${config.stream_alert_channel_id ? ref('channel', config.stream_alert_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_alert_everyone)}</strong></td><td>${config.stream_alert_everyone ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_custom_role_alerts)}</strong></td><td>${config.stream_alert_custom_role ? ref('role', config.stream_alert_custom_role) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += '</tbody></table>';
        // Other Channels
        html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_other_channels)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_moderation_channel)}</strong></td><td>${config.moderation_channel_id ? ref('channel', config.moderation_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_alert_channel)}</strong></td><td>${config.alert_channel_id ? ref('channel', config.alert_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_stream_monitoring_channel)}</strong></td><td>${config.member_streams_id ? ref('channel', config.member_streams_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += '</tbody></table>';
        // Tracked Streams
        if (config.tracked_streams && config.tracked_streams.length > 0) {
            html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_tracked_streams)}</h4>`;
            html += `<table class="sp-table"><thead><tr><th>${escapeHtml(L.th_username)}</th><th>${escapeHtml(L.th_stream_url)}</th></tr></thead><tbody>`;
            config.tracked_streams.forEach(stream => {
                html += `<tr><td>${escapeHtml(stream.username)}</td><td>`;
                if (stream.stream_url) {
                    html += `<a href="${escapeHtml(stream.stream_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(stream.stream_url)}</a>`;
                } else {
                    html += '<em>' + escapeHtml(L.val_no_url) + '</em>';
                }
                html += '</td></tr>';
            });
            html += '</tbody></table>';
        }
        // Server Management Settings
        if (config.server_management_settings && Object.keys(config.server_management_settings).length > 0) {
            const mgmt = config.server_management_settings;
            html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_server_mgmt)}</h4>`;
            html += '<table class="sp-table"><tbody>';
            // Welcome Message Settings
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_welcome_message)}</strong></td></tr>`;
            html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${mgmt.welcome_message_configuration_channel ? ref('channel', mgmt.welcome_message_configuration_channel) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
            if (mgmt.welcome_message_configuration_message || mgmt.welcome_message_configuration_default || mgmt.welcome_message_configuration_embed) {
                html += `<tr><td><strong>${escapeHtml(L.lbl_use_default)}</strong></td><td>${mgmt.welcome_message_configuration_default ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_use_embed)}</strong></td><td>${mgmt.welcome_message_configuration_embed ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_custom_message)}</strong></td><td>${mgmt.welcome_message_configuration_message ? '<em>' + escapeHtml(L.val_configured) + '</em>' : escapeHtml(L.val_not_set)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${mgmt.welcome_message_configuration_colour ? escapeHtml(mgmt.welcome_message_configuration_colour) : escapeHtml(L.val_default)}</td></tr>`;
            }
            // Auto Role Assignment
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_auto_role)}</strong></td></tr>`;
            html += `<tr><td><strong>${escapeHtml(L.lbl_role_id)}</strong></td><td>${mgmt.auto_role_assignment_configuration_role_id ? ref('role', mgmt.auto_role_assignment_configuration_role_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
            // Message Tracking
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_message_tracking)}</strong></td></tr>`;
            if (mgmt.message_tracking_configuration) {
                try {
                    const msgConfig = typeof mgmt.message_tracking_configuration === 'string' ? JSON.parse(mgmt.message_tracking_configuration) : mgmt.message_tracking_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${msgConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${msgConfig.log_channel_id ? ref('channel', msgConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_edits)}</strong></td><td>${msgConfig.track_edits ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_deletes)}</strong></td><td>${msgConfig.track_deletes ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td>${escapeHtml(L.val_enabled)}</td></tr>`;
                }
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Role Tracking
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_role_tracking)}</strong></td></tr>`;
            if (mgmt.role_tracking_configuration) {
                try {
                    const roleConfig = typeof mgmt.role_tracking_configuration === 'string' ? JSON.parse(mgmt.role_tracking_configuration) : mgmt.role_tracking_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${roleConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${roleConfig.log_channel_id ? ref('channel', roleConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_additions)}</strong></td><td>${roleConfig.track_additions ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_removals)}</strong></td><td>${roleConfig.track_removals ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td>${escapeHtml(L.val_enabled)}</td></tr>`;
                }
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Role History
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_role_history)}</strong></td></tr>`;
            if (mgmt.role_history_configuration) {
                try {
                    const histConfig = typeof mgmt.role_history_configuration === 'string' ? JSON.parse(mgmt.role_history_configuration) : mgmt.role_history_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${histConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_retention_days)}</strong></td><td>${histConfig.retention_days ? histConfig.retention_days : '30'}</td></tr>`;
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td>${escapeHtml(L.val_configured)}</td></tr>`;
                }
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Server Role Management
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_server_role_mgmt)}</strong></td></tr>`;
            if (mgmt.server_role_management_configuration) {
                try {
                    const srvRoleConfig = typeof mgmt.server_role_management_configuration === 'string' ? JSON.parse(mgmt.server_role_management_configuration) : mgmt.server_role_management_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${srvRoleConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${srvRoleConfig.log_channel_id ? ref('channel', srvRoleConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_creation)}</strong></td><td>${srvRoleConfig.track_creation ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_deletion)}</strong></td><td>${srvRoleConfig.track_deletion ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_edits)}</strong></td><td>${srvRoleConfig.track_edits ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td>${escapeHtml(L.val_configured)}</td></tr>`;
                }
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // User Tracking
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_user_tracking)}</strong></td></tr>`;
            if (mgmt.user_tracking_configuration) {
                try {
                    const userConfig = typeof mgmt.user_tracking_configuration === 'string' ? JSON.parse(mgmt.user_tracking_configuration) : mgmt.user_tracking_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${userConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${userConfig.log_channel_id ? ref('channel', userConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_joins)}</strong></td><td>${userConfig.track_joins ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_leaves)}</strong></td><td>${userConfig.track_leaves ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_nickname_changes)}</strong></td><td>${userConfig.track_nickname_changes ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_username_changes)}</strong></td><td>${userConfig.track_username_changes ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_avatar_changes)}</strong></td><td>${userConfig.track_avatar_changes ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_track_status_changes)}</strong></td><td>${userConfig.track_status_changes ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td>${escapeHtml(L.val_configured)}</td></tr>`;
                }
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Reaction Roles
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_reaction_roles)}</strong></td></tr>`;
            if (mgmt.reaction_roles_configuration) {
                try {
                    const reactionConfig = typeof mgmt.reaction_roles_configuration === 'string' ? JSON.parse(mgmt.reaction_roles_configuration) : mgmt.reaction_roles_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${reactionConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_channel_id)}</strong></td><td>${reactionConfig.channel_id ? ref('channel', reactionConfig.channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_message_id)}</strong></td><td>${reactionConfig.message_id ? escapeHtml(reactionConfig.message_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_allow_multiple)}</strong></td><td>${reactionConfig.allow_multiple ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_mappings_configured)}</strong></td><td>${reactionConfig.mappings ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td>${escapeHtml(L.val_configured)}</td></tr>`;
                }
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Rules Configuration
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_rules_config)}</strong></td></tr>`;
            if (mgmt.rules_configuration) {
                try {
                    const rulesConfig = typeof mgmt.rules_configuration === 'string' ? JSON.parse(mgmt.rules_configuration) : mgmt.rules_configuration;
                    if (rulesConfig.channel_id) {
                        html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${ref('channel', rulesConfig.channel_id)}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_title)}</strong></td><td>${rulesConfig.title ? escapeHtml(rulesConfig.title) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${rulesConfig.color ? escapeHtml(rulesConfig.color) : escapeHtml(L.val_default)}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_accept_role)}</strong></td><td>${rulesConfig.accept_role_id ? ref('role', rulesConfig.accept_role_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    } else {
                        html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
                    }
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
                }
            } else if (mgmt.rules_configuration_channel_id) {
                html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${ref('channel', mgmt.rules_configuration_channel_id)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_title)}</strong></td><td>${mgmt.rules_configuration_title ? escapeHtml(mgmt.rules_configuration_title) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${mgmt.rules_configuration_colour ? escapeHtml(mgmt.rules_configuration_colour) : escapeHtml(L.val_default)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_accept_role)}</strong></td><td>${mgmt.rules_configuration_accept_role_id ? ref('role', mgmt.rules_configuration_accept_role_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Stream Schedule
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_stream_schedule)}</strong></td></tr>`;
            if (mgmt.stream_schedule_configuration) {
                try {
                    const scheduleConfig = typeof mgmt.stream_schedule_configuration === 'string' ? JSON.parse(mgmt.stream_schedule_configuration) : mgmt.stream_schedule_configuration;
                    if (scheduleConfig.channel_id) {
                        html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${ref('channel', scheduleConfig.channel_id)}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_title)}</strong></td><td>${scheduleConfig.title ? escapeHtml(scheduleConfig.title) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${scheduleConfig.color ? escapeHtml(scheduleConfig.color) : escapeHtml(L.val_default)}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_timezone)}</strong></td><td>${scheduleConfig.timezone ? escapeHtml(scheduleConfig.timezone) : 'UTC'}</td></tr>`;
                    } else {
                        html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
                    }
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
                }
            } else if (mgmt.stream_schedule_configuration_channel_id) {
                html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${ref('channel', mgmt.stream_schedule_configuration_channel_id)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_title)}</strong></td><td>${mgmt.stream_schedule_configuration_title ? escapeHtml(mgmt.stream_schedule_configuration_title) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${mgmt.stream_schedule_configuration_colour ? escapeHtml(mgmt.stream_schedule_configuration_colour) : escapeHtml(L.val_default)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_timezone)}</strong></td><td>${mgmt.stream_schedule_configuration_timezone ? escapeHtml(mgmt.stream_schedule_configuration_timezone) : 'UTC'}</td></tr>`;
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            html += '</tbody></table>';
        }
        html += '</div>';
        configModalBody.innerHTML = html;
        configModal.classList.remove('hidden');
        configModalCloseBtn.focus();
        loadNames(config.user_id, username).then(names => applyNames(configModalBody, names));
    }
    function closeConfigModal() {
        configModal.classList.add('hidden');
        configModalBody.innerHTML = '';
    }
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    // View config buttons
    document.querySelectorAll('.view-config-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const username = this.getAttribute('data-username');
            openConfigModal(username);
        });
    });
    // Modal close events
    configModalClose.addEventListener('click', closeConfigModal);
    configModalCloseBtn.addEventListener('click', closeConfigModal);
    configModal.addEventListener('click', function(e) {
        if (e.target === configModal) closeConfigModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !configModal.classList.contains('hidden')) closeConfigModal();
    });
});
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>