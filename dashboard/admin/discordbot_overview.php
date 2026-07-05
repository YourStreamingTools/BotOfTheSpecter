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
                <div class="discord-config-card config-card" data-username="<?php echo strtolower($safeUser); ?>" data-linked="<?php echo $isLinked ? '1' : '0'; ?>">
                    <div class="discord-config-card-head">
                        <span style="font-weight:600;"><?php echo $safeUser; ?></span>
                        <span class="sp-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                    </div>
                    <div class="discord-config-card-body">
                                <?php if ($isLinked): ?>
                                    <p><strong><?php echo t('admin_discord_overview_card_discord_user'); ?></strong> <?php echo htmlspecialchars($config['discord_username']); ?></p>
                                <?php endif; ?>
                                <p><strong><?php echo t('admin_discord_overview_card_guild_id'); ?></strong> <?php echo !empty($config['guild_id']) ? htmlspecialchars($config['guild_id']) : '<em>' . t('admin_discord_overview_not_set') . '</em>'; ?></p>
                                <p><strong><?php echo t('admin_discord_overview_card_live_channel'); ?></strong> <?php echo !empty($config['live_channel_id']) ? htmlspecialchars($config['live_channel_id']) : '<em>' . t('admin_discord_overview_not_set') . '</em>'; ?></p>
                                <p><strong><?php echo t('admin_discord_overview_card_time_now_channel'); ?></strong> <?php echo !empty($config['time_now_channel_id']) ? htmlspecialchars($config['time_now_channel_id']) : '<em>' . t('admin_discord_overview_not_set') . '</em>'; ?></p>
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
    ]); ?>;
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
        if (config.discord_username) {
            html += `<tr><td><strong>${escapeHtml(L.lbl_discord_username)}</strong></td><td>${escapeHtml(config.discord_username)}</td></tr>`;
        }
        html += '</tbody></table>';
        // Server Configuration
        html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_server_config)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_guild_id)}</strong></td><td>${config.guild_id ? escapeHtml(config.guild_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_manual_ids_mode)}</strong></td><td>${config.manual_ids ? escapeHtml(L.val_enabled) : escapeHtml(L.val_disabled)}</td></tr>`;
        html += '</tbody></table>';
        // Stream Configuration
        html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_stream_config)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_live_channel_id)}</strong></td><td>${config.live_channel_id ? escapeHtml(config.live_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_time_now_channel_id)}</strong></td><td>${config.time_now_channel_id ? escapeHtml(config.time_now_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_online_text)}</strong></td><td>${config.online_text ? escapeHtml(config.online_text) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_offline_text)}</strong></td><td>${config.offline_text ? escapeHtml(config.offline_text) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += '<tr><td colspan="2"><hr></td></tr>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_stream_alert_channel)}</strong></td><td>${config.stream_alert_channel_id ? escapeHtml(config.stream_alert_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_alert_everyone)}</strong></td><td>${config.stream_alert_everyone ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_custom_role_alerts)}</strong></td><td>${config.stream_alert_custom_role ? escapeHtml(config.stream_alert_custom_role) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += '</tbody></table>';
        // Other Channels
        html += `<h4 style="font-size:1rem;font-weight:700;margin:1rem 0 0.5rem;">${escapeHtml(L.sec_other_channels)}</h4>`;
        html += '<table class="sp-table"><tbody>';
        html += `<tr><td><strong>${escapeHtml(L.lbl_moderation_channel)}</strong></td><td>${config.moderation_channel_id ? escapeHtml(config.moderation_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_alert_channel)}</strong></td><td>${config.alert_channel_id ? escapeHtml(config.alert_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
        html += `<tr><td><strong>${escapeHtml(L.lbl_stream_monitoring_channel)}</strong></td><td>${config.member_streams_id ? escapeHtml(config.member_streams_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
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
            html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${mgmt.welcome_message_configuration_channel ? escapeHtml(mgmt.welcome_message_configuration_channel) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
            if (mgmt.welcome_message_configuration_message || mgmt.welcome_message_configuration_default || mgmt.welcome_message_configuration_embed) {
                html += `<tr><td><strong>${escapeHtml(L.lbl_use_default)}</strong></td><td>${mgmt.welcome_message_configuration_default ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_use_embed)}</strong></td><td>${mgmt.welcome_message_configuration_embed ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_custom_message)}</strong></td><td>${mgmt.welcome_message_configuration_message ? '<em>' + escapeHtml(L.val_configured) + '</em>' : escapeHtml(L.val_not_set)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${mgmt.welcome_message_configuration_colour ? escapeHtml(mgmt.welcome_message_configuration_colour) : escapeHtml(L.val_default)}</td></tr>`;
            }
            // Auto Role Assignment
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_auto_role)}</strong></td></tr>`;
            html += `<tr><td><strong>${escapeHtml(L.lbl_role_id)}</strong></td><td>${mgmt.auto_role_assignment_configuration_role_id ? escapeHtml(mgmt.auto_role_assignment_configuration_role_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
            // Message Tracking
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_message_tracking)}</strong></td></tr>`;
            if (mgmt.message_tracking_configuration) {
                try {
                    const msgConfig = typeof mgmt.message_tracking_configuration === 'string' ? JSON.parse(mgmt.message_tracking_configuration) : mgmt.message_tracking_configuration;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_enabled)}</strong></td><td>${msgConfig.enabled ? escapeHtml(L.val_yes) : escapeHtml(L.val_no)}</td></tr>`;
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${msgConfig.log_channel_id ? escapeHtml(msgConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
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
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${roleConfig.log_channel_id ? escapeHtml(roleConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
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
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${srvRoleConfig.log_channel_id ? escapeHtml(srvRoleConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
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
                    html += `<tr><td><strong>${escapeHtml(L.lbl_log_channel)}</strong></td><td>${userConfig.log_channel_id ? escapeHtml(userConfig.log_channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
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
                    html += `<tr><td><strong>${escapeHtml(L.lbl_channel_id)}</strong></td><td>${reactionConfig.channel_id ? escapeHtml(reactionConfig.channel_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
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
                        html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${escapeHtml(rulesConfig.channel_id)}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_title)}</strong></td><td>${rulesConfig.title ? escapeHtml(rulesConfig.title) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${rulesConfig.color ? escapeHtml(rulesConfig.color) : escapeHtml(L.val_default)}</td></tr>`;
                        html += `<tr><td><strong>${escapeHtml(L.lbl_accept_role)}</strong></td><td>${rulesConfig.accept_role_id ? escapeHtml(rulesConfig.accept_role_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                    } else {
                        html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
                    }
                } catch (e) {
                    html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
                }
            } else if (mgmt.rules_configuration_channel_id) {
                html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${escapeHtml(mgmt.rules_configuration_channel_id)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_title)}</strong></td><td>${mgmt.rules_configuration_title ? escapeHtml(mgmt.rules_configuration_title) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_color)}</strong></td><td>${mgmt.rules_configuration_colour ? escapeHtml(mgmt.rules_configuration_colour) : escapeHtml(L.val_default)}</td></tr>`;
                html += `<tr><td><strong>${escapeHtml(L.lbl_accept_role)}</strong></td><td>${mgmt.rules_configuration_accept_role_id ? escapeHtml(mgmt.rules_configuration_accept_role_id) : '<em>' + escapeHtml(L.val_not_set) + '</em>'}</td></tr>`;
            } else {
                html += `<tr><td><strong>${escapeHtml(L.lbl_status)}</strong></td><td><em>${escapeHtml(L.val_not_configured)}</em></td></tr>`;
            }
            // Stream Schedule
            html += `<tr><td colspan="2"><strong class="sp-text-accent">${escapeHtml(L.sub_stream_schedule)}</strong></td></tr>`;
            if (mgmt.stream_schedule_configuration) {
                try {
                    const scheduleConfig = typeof mgmt.stream_schedule_configuration === 'string' ? JSON.parse(mgmt.stream_schedule_configuration) : mgmt.stream_schedule_configuration;
                    if (scheduleConfig.channel_id) {
                        html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${escapeHtml(scheduleConfig.channel_id)}</td></tr>`;
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
                html += `<tr><td><strong>${escapeHtml(L.lbl_channel)}</strong></td><td>${escapeHtml(mgmt.stream_schedule_configuration_channel_id)}</td></tr>`;
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