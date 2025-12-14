<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('html_errors', '1');
error_reporting(E_ALL);

session_start();
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
            'guild_id' => $discordData['guild_id'] ?? '',
            'live_channel_id' => $discordData['live_channel_id'] ?? '',
            'online_text' => $discordData['online_text'] ?? '',
            'offline_text' => $discordData['offline_text'] ?? '',
            'stream_alert_channel_id' => $discordData['stream_alert_channel_id'] ?? '',
            'stream_alert_everyone' => $discordData['stream_alert_everyone'] ?? 0,
            'stream_alert_custom_role' => $discordData['stream_alert_custom_role'] ?? '',
            'moderation_channel_id' => $discordData['moderation_channel_id'] ?? '',
            'alert_channel_id' => $discordData['alert_channel_id'] ?? '',
            'member_streams_id' => $discordData['member_streams_id'] ?? '',
            'manual_ids' => $discordData['manual_ids'] ?? 0,
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
            $mgmtStmt = $discord_conn->prepare("SELECT * FROM server_management WHERE server_id = ?");
            $mgmtStmt->bind_param("s", $userConfig['guild_id']);
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
<div class="box">
    <div class="level">
        <div class="level-left">
            <h1 class="title is-4"><span class="icon"><i class="fab fa-discord"></i></span> Discord Bot Configuration Overview</h1>
        </div>
        <!-- Modal for detailed user configuration -->
        <div id="user-config-modal" class="modal">
            <div class="modal-background"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p id="config-modal-title" class="modal-card-title">Discord Configuration Details</p>
                    <button class="delete" aria-label="close" id="config-modal-close"></button>
                </header>
                <section class="modal-card-body" id="config-modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- populated by JS -->
                </section>
                <footer class="modal-card-foot">
                    <button class="button" id="config-modal-close-btn">Close</button>
                </footer>
            </div>
        </div>
        <div class="level-right">
            <div class="field has-addons">
                <div class="control">
                    <input id="user-search" class="input" type="text" placeholder="Search users...">
                </div>
                <div class="control">
                    <a id="clear-search" class="button is-light" title="Clear search">Clear</a>
                </div>
            </div>
        </div>
    </div>
    <p class="mb-4">Overview of all users with Discord bot configuration. Click a user card to view full details.</p>
    <?php if (empty($discordConfigData)): ?>
        <div class="notification is-info">
            <p>No users currently have Discord bot configuration set up.</p>
        </div>
    <?php else: ?>
        <div class="columns is-multiline" id="config-cards">
            <?php foreach ($discordConfigData as $username => $config): ?>
                <?php 
                    $safeUser = htmlspecialchars($username);
                    $isLinked = $config['is_linked'];
                    $hasGuild = !empty($config['guild_id']);
                    $trackedCount = count($config['tracked_streams']);
                    $statusClass = $isLinked ? 'is-success' : 'is-warning';
                    $statusText = $isLinked ? 'Linked' : 'Not Linked';
                ?>
                <div class="column is-6-tablet is-4-desktop config-card" data-username="<?php echo strtolower($safeUser); ?>" data-linked="<?php echo $isLinked ? '1' : '0'; ?>">
                    <div class="card">
                        <header class="card-header">
                            <p class="card-header-title">
                                <span class="has-text-weight-semibold"><?php echo $safeUser; ?></span>
                            </p>
                            <div class="card-header-icon">
                                <span class="tag <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </div>
                        </header>
                        <div class="card-content">
                            <div class="content">
                                <?php if ($isLinked): ?>
                                    <p><strong>Discord User:</strong> <?php echo htmlspecialchars($config['discord_username']); ?></p>
                                <?php endif; ?>
                                <p><strong>Guild ID:</strong> <?php echo !empty($config['guild_id']) ? htmlspecialchars($config['guild_id']) : '<em>Not set</em>'; ?></p>
                                <p><strong>Live Channel:</strong> <?php echo !empty($config['live_channel_id']) ? htmlspecialchars($config['live_channel_id']) : '<em>Not set</em>'; ?></p>
                                <?php if ($trackedCount > 0): ?>
                                    <p><strong>Tracked Streams:</strong> <span class="tag is-info"><?php echo $trackedCount; ?></span></p>
                                <?php endif; ?>
                                <?php 
                                    $enabledFeatures = [];
                                    if (!empty($config['stream_alert_channel_id'])) $enabledFeatures[] = 'Stream Alerts';
                                    if (!empty($config['moderation_channel_id'])) $enabledFeatures[] = 'Moderation';
                                    if (!empty($config['alert_channel_id'])) $enabledFeatures[] = 'Alerts';
                                    if (!empty($config['member_streams_id'])) $enabledFeatures[] = 'Stream Monitoring';
                                    if (!empty($config['server_management_settings'])): 
                                        $mgmt = $config['server_management_settings'];
                                        if (!empty($mgmt['welcome_message_channel_id'])) $enabledFeatures[] = 'Welcome';
                                        if (!empty($mgmt['auto_role_assignment_configuration_role_id'])) $enabledFeatures[] = 'Auto Role';
                                        if (!empty($mgmt['message_tracking_configuration'])) $enabledFeatures[] = 'Message Tracking';
                                    endif;
                                ?>
                                <?php if (!empty($enabledFeatures)): ?>
                                    <div class="mt-3">
                                        <p><strong>Enabled Features:</strong></p>
                                        <div class="tags">
                                            <?php foreach ($enabledFeatures as $feature): ?>
                                                <span class="tag is-light"><?php echo htmlspecialchars($feature); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <footer class="card-footer">
                            <a href="#" class="card-footer-item view-config-btn" data-username="<?php echo htmlspecialchars($username); ?>">View Full Config</a>
                        </footer>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="level mt-4">
            <div class="level-left">
                <div class="level-item">
                    <p><strong>Total Users:</strong> <?php echo count($discordConfigData); ?></p>
                </div>
                <div class="level-item">
                    <p><strong>Linked Users:</strong> <?php echo count(array_filter($discordConfigData, fn($c) => $c['is_linked'])); ?></p>
                </div>
                <div class="level-item">
                    <p><strong>With Guild:</strong> <?php echo count(array_filter($discordConfigData, fn($c) => !empty($c['guild_id']))); ?></p>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <p><strong>Total Tracked Streams:</strong> <?php echo array_sum(array_map(fn($c) => count($c['tracked_streams']), $discordConfigData)); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
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
        configModalTitle.textContent = username + ' — Discord Configuration';
        configModalBody.innerHTML = '';
        // Build detailed config HTML
        let html = '<div class="content">';
        // Basic Info
        html += '<h4 class="title is-5">Basic Information</h4>';
        html += '<table class="table is-fullwidth is-striped"><tbody>';
        html += `<tr><td><strong>Twitch Username</strong></td><td>${escapeHtml(config.username)}</td></tr>`;
        html += `<tr><td><strong>Discord Linked</strong></td><td><span class="tag ${config.is_linked ? 'is-success' : 'is-warning'}">${config.is_linked ? 'Yes' : 'No'}</span></td></tr>`;
        if (config.discord_username) {
            html += `<tr><td><strong>Discord Username</strong></td><td>${escapeHtml(config.discord_username)}</td></tr>`;
        }
        html += '</tbody></table>';
        // Server Configuration
        html += '<h4 class="title is-5 mt-4">Server Configuration</h4>';
        html += '<table class="table is-fullwidth is-striped"><tbody>';
        html += `<tr><td><strong>Guild ID</strong></td><td>${config.guild_id ? escapeHtml(config.guild_id) : '<em>Not set</em>'}</td></tr>`;
        html += `<tr><td><strong>Manual IDs Mode</strong></td><td>${config.manual_ids ? 'Enabled' : 'Disabled'}</td></tr>`;
        html += '</tbody></table>';
        // Stream Configuration
        html += '<h4 class="title is-5 mt-4">Stream Configuration</h4>';
        html += '<table class="table is-fullwidth is-striped"><tbody>';
        html += `<tr><td><strong>Live Channel ID</strong></td><td>${config.live_channel_id ? escapeHtml(config.live_channel_id) : '<em>Not set</em>'}</td></tr>`;
        html += `<tr><td><strong>Online Text</strong></td><td>${config.online_text ? escapeHtml(config.online_text) : '<em>Not set</em>'}</td></tr>`;
        html += `<tr><td><strong>Offline Text</strong></td><td>${config.offline_text ? escapeHtml(config.offline_text) : '<em>Not set</em>'}</td></tr>`;
        html += '<tr><td colspan="2"><hr></td></tr>';
        html += `<tr><td><strong>Stream Alert Channel</strong></td><td>${config.stream_alert_channel_id ? escapeHtml(config.stream_alert_channel_id) : '<em>Not set</em>'}</td></tr>`;
        html += `<tr><td><strong>Alert Everyone</strong></td><td>${config.stream_alert_everyone ? 'Yes' : 'No'}</td></tr>`;
        html += `<tr><td><strong>Custom Role for Alerts</strong></td><td>${config.stream_alert_custom_role ? escapeHtml(config.stream_alert_custom_role) : '<em>Not set</em>'}</td></tr>`;
        html += '</tbody></table>';
        // Other Channels
        html += '<h4 class="title is-5 mt-4">Other Channels</h4>';
        html += '<table class="table is-fullwidth is-striped"><tbody>';
        html += `<tr><td><strong>Moderation Channel</strong></td><td>${config.moderation_channel_id ? escapeHtml(config.moderation_channel_id) : '<em>Not set</em>'}</td></tr>`;
        html += `<tr><td><strong>Alert Channel</strong></td><td>${config.alert_channel_id ? escapeHtml(config.alert_channel_id) : '<em>Not set</em>'}</td></tr>`;
        html += `<tr><td><strong>Stream Monitoring Channel</strong></td><td>${config.member_streams_id ? escapeHtml(config.member_streams_id) : '<em>Not set</em>'}</td></tr>`;
        html += '</tbody></table>';
        // Tracked Streams
        if (config.tracked_streams && config.tracked_streams.length > 0) {
            html += '<h4 class="title is-5 mt-4">Tracked Streams</h4>';
            html += '<table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>Username</th><th>Stream URL</th></tr></thead><tbody>';
            config.tracked_streams.forEach(stream => {
                html += `<tr><td>${escapeHtml(stream.username)}</td><td>`;
                if (stream.stream_url) {
                    html += `<a href="${escapeHtml(stream.stream_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(stream.stream_url)}</a>`;
                } else {
                    html += '<em>No URL</em>';
                }
                html += '</td></tr>';
            });
            html += '</tbody></table>';
        }
        // Server Management Settings
        if (config.server_management_settings && Object.keys(config.server_management_settings).length > 0) {
            const mgmt = config.server_management_settings;
            html += '<h4 class="title is-5 mt-4">Server Management Settings</h4>';
            html += '<table class="table is-fullwidth is-striped"><tbody>';
            html += `<tr><td><strong>Welcome Channel</strong></td><td>${mgmt.welcome_message_channel_id ? escapeHtml(mgmt.welcome_message_channel_id) : '<em>Not set</em>'}</td></tr>`;
            html += `<tr><td><strong>Auto Role</strong></td><td>${mgmt.auto_role_assignment_configuration_role_id ? escapeHtml(mgmt.auto_role_assignment_configuration_role_id) : '<em>Not set</em>'}</td></tr>`;
            html += `<tr><td><strong>Message Tracking</strong></td><td>${mgmt.message_tracking_configuration ? 'Enabled' : 'Not set'}</td></tr>`;
            html += `<tr><td><strong>Role Tracking</strong></td><td>${mgmt.role_tracking_configuration ? 'Enabled' : 'Not set'}</td></tr>`;
            html += `<tr><td><strong>User Tracking</strong></td><td>${mgmt.user_tracking_configuration ? 'Enabled' : 'Not set'}</td></tr>`;
            html += `<tr><td><strong>Reaction Roles</strong></td><td>${mgmt.reaction_roles_configuration ? 'Enabled' : 'Not set'}</td></tr>`;
            html += `<tr><td><strong>Rules Channel</strong></td><td>${mgmt.rules_configuration_channel_id ? escapeHtml(mgmt.rules_configuration_channel_id) : '<em>Not set</em>'}</td></tr>`;
            html += `<tr><td><strong>Stream Schedule Channel</strong></td><td>${mgmt.stream_schedule_configuration_channel_id ? escapeHtml(mgmt.stream_schedule_configuration_channel_id) : '<em>Not set</em>'}</td></tr>`;
            html += '</tbody></table>';
        }
        html += '</div>';
        configModalBody.innerHTML = html;
        configModal.classList.add('is-active');
        configModalCloseBtn.focus();
    }
    function closeConfigModal() {
        configModal.classList.remove('is-active');
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
    configModal.querySelector('.modal-background').addEventListener('click', closeConfigModal);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeConfigModal();
    });
});
</script>
<?php
$scripts = ob_get_clean();
include "admin_layout.php";
?>