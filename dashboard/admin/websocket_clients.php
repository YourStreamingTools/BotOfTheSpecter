<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_websocket_clients_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/admin_actions.php";

// Fetch user display name from database by API key
function getUserDisplayName($apiKey, $conn) {
    $stmt = $conn->prepare("SELECT twitch_display_name FROM users WHERE api_key = ? LIMIT 1");
    if (!$stmt) {
        return t('admin_websocket_clients_unknown_user');
    }
    $stmt->bind_param("s", $apiKey);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return $row['twitch_display_name'] ?: t('admin_websocket_clients_unknown_user');
    }
    return t('admin_websocket_clients_unknown_user');
}

// Clean listener name by removing common prefixes
function cleanListenerName($name) {
    // Remove "Global - " prefix
    if (stripos($name, 'Global - ') === 0) {
        $name = substr($name, strlen('Global - '));
    }
}

// Fetch real websocket client data from the websocket server
function fetchWebsocketClients($conn) {
    $url = 'https://websocket.botofthespecter.com/clients';
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'method' => 'GET',
            'header' => [
                'User-Agent: BotOfTheSpecter Admin Panel'
            ]
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        // Return empty data if API call fails
        return [
            'registered_clients' => [],
            'global_listeners' => []
        ];
    }
    $data = json_decode($response, true);
    if (!$data || !isset($data['clients'])) {
        return [
            'registered_clients' => [],
            'global_listeners' => []
        ];
    }
    // Transform the data structure and fetch user display names
    $registered_clients = [];
    if (isset($data['clients']) && is_array($data['clients'])) {
        foreach ($data['clients'] as $apiKey => $clients) {
            $displayName = getUserDisplayName($apiKey, $conn);
            $registered_clients[$apiKey] = [
                'twitch_display_name' => $displayName,
                'api_key' => $apiKey,
                'client_count' => count($clients),
                'clients' => []
            ];
            foreach ($clients as $client) {
                // Add timestamps (not available from API, so we'll use current time as placeholder)
                $client['connected_at'] = date('Y-m-d H:i:s');
                $client['last_activity'] = date('Y-m-d H:i:s');
                $registered_clients[$apiKey]['clients'][] = $client;
            }
        }
    }
    $global_listeners = [];
    if (isset($data['global_listeners']) && is_array($data['global_listeners'])) {
        foreach ($data['global_listeners'] as $listener) {
            // Add timestamps (not available from API, so we'll use current time as placeholder)
            $listener['connected_at'] = date('Y-m-d H:i:s');
            $listener['last_activity'] = date('Y-m-d H:i:s');
            $global_listeners[] = $listener;
        }
    }
    // Sort registered clients by client count (descending)
    uasort($registered_clients, function($a, $b) {
        return $b['client_count'] - $a['client_count'];
    });
    
    return [
        'registered_clients' => $registered_clients,
        'global_listeners' => $global_listeners
    ];
}

// Handle AJAX request for refreshing data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh_data'])) {
    ob_clean();
    header('Content-Type: application/json');
    $data = fetchWebsocketClients($conn);
    echo json_encode($data);
    exit;
}

// Fetch the real websocket data
$websocketData = fetchWebsocketClients($conn);
$lastUpdated = date('Y-m-d H:i:s');
$lastUpdatedIso = date('c'); // ISO 8601 timestamp so client can render in local timezone
$apiError = false;

// Check if API returned empty data (might indicate an error)
if (empty($websocketData['registered_clients']) && empty($websocketData['global_listeners'])) {
    // Try to check if the API is actually down by making a quick test
    $headers = @get_headers('https://websocket.botofthespecter.com/clients', 1);
    if (!$headers || strpos($headers[0], '200') === false) {
        $apiError = true;
    }
}

// Handle AJAX request to get user clients
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_user_clients'])) {
    ob_clean();
    header('Content-Type: application/json');
    $apiKey = $_GET['api_key'] ?? '';
    $data = fetchWebsocketClients($conn);
    
    if (isset($data['registered_clients'][$apiKey])) {
        echo json_encode($data['registered_clients'][$apiKey]);
    } else {
        echo json_encode(['error' => t('admin_websocket_clients_user_not_found')]);
    }
    exit;
}

// Handle AJAX request to disconnect client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_client'])) {
    ob_clean();
    header('Content-Type: application/json');
    $sid = $_POST['sid'] ?? '';
    if (empty($sid)) {
        echo json_encode(['success' => false, 'message' => t('admin_websocket_clients_msg_sid_required')]);
        exit;
    }
    // Send disconnect command to the websocket server
    $disconnectUrl = 'https://websocket.botofthespecter.com/admin/disconnect?admin_key=' . urlencode($admin_key);
    $postData = json_encode(['sid' => $sid]);
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'User-Agent: BotOfTheSpecter Admin Panel'
            ],
            'content' => $postData
        ]
    ]);
    $response = @file_get_contents($disconnectUrl, false, $context);
    if ($response === false) {
        echo json_encode(['success' => false, 'message' => t('admin_websocket_clients_msg_connect_failed')]);
        exit;
    }
    $result = json_decode($response, true);
    if ($result && isset($result['success']) && $result['success']) {
        echo json_encode(['success' => true, 'message' => t('admin_websocket_clients_msg_disconnect_success')]);
    } else {
        $errorMsg = $result['message'] ?? t('admin_websocket_clients_msg_unknown_error');
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
    exit;
}

// Calculate statistics
$totalClients = 0;
$totalCodes = count($websocketData['registered_clients']);
foreach ($websocketData['registered_clients'] as $userCode => $userData) {
    $totalClients += $userData['client_count'];
}
$totalGlobalListeners = count($websocketData['global_listeners']);
$totalConnections = $totalClients + $totalGlobalListeners;

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <div>
            <h1 class="sp-card-title"><span class="icon"><i class="fas fa-plug"></i></span> <?php echo t('admin_websocket_clients_title'); ?></h1>
            <p style="margin-bottom:1rem;"><?php echo t('admin_websocket_clients_subtitle'); ?></p>
        </div>
        <button class="sp-btn sp-btn-primary" onclick="refreshData()">
            <span class="icon"><i class="fas fa-sync-alt"></i></span>
            <span><?php echo t('admin_websocket_clients_refresh'); ?></span>
        </button>
    </div>
    <div class="sp-card-body">
    <?php if ($apiError): ?>
        <div class="sp-alert sp-alert-warning">
            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
            <?php echo t('admin_websocket_clients_api_warning'); ?>
        </div>
    <?php else: ?>
        <div class="sp-alert sp-alert-info" id="last-updated" data-last-updated="<?php echo htmlspecialchars($lastUpdatedIso); ?>">
            <small><?php echo t('admin_websocket_clients_last_updated', [htmlspecialchars($lastUpdated)]); ?></small>
        </div>
    <?php endif; ?>
    <!-- Statistics Cards -->
    <div class="sp-stat-row" style="grid-template-columns:repeat(4,1fr);">
        <div class="sp-stat" style="border-color:var(--blue);background:var(--blue-bg);">
            <span class="sp-stat-label"><?php echo t('admin_websocket_clients_stat_total'); ?></span>
            <span class="sp-stat-value" style="color:var(--blue);" id="stat-total"><?php echo $totalConnections; ?></span>
        </div>
        <div class="sp-stat" style="border-color:var(--accent);background:var(--accent-light);">
            <span class="sp-stat-label"><?php echo t('admin_websocket_clients_stat_registered'); ?></span>
            <span class="sp-stat-value" style="color:var(--accent);" id="stat-clients"><?php echo $totalClients; ?></span>
        </div>
        <div class="sp-stat" style="border-color:var(--amber);background:var(--amber-bg);">
            <span class="sp-stat-label"><?php echo t('admin_websocket_clients_stat_codes'); ?></span>
            <span class="sp-stat-value" style="color:var(--amber);" id="stat-codes"><?php echo $totalCodes; ?></span>
        </div>
        <div class="sp-stat" style="border-color:var(--green);background:var(--green-bg);">
            <span class="sp-stat-label"><?php echo t('admin_websocket_clients_stat_listeners'); ?></span>
            <span class="sp-stat-value" style="color:var(--green);" id="stat-global"><?php echo $totalGlobalListeners; ?></span>
        </div>
    </div>
    </div>
</div>
<!-- Registered Clients Section -->
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><i class="fas fa-users" style="margin-right:0.5rem;"></i><?php echo t('admin_websocket_clients_registered_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <p style="margin-bottom:1rem;"><?php echo t('admin_websocket_clients_registered_desc'); ?></p>
    <div style="margin-bottom:1rem;">
        <div class="search-wrapper">
            <span class="search-icon"><i class="fas fa-search"></i></span>
            <input type="text" id="client-search" placeholder="<?php echo htmlspecialchars(t('admin_websocket_clients_search_placeholder')); ?>" class="search-input" aria-label="<?php echo htmlspecialchars(t('admin_websocket_clients_search_aria')); ?>">
            <button type="button" id="client-search-clear" class="sp-btn sp-btn-sm search-clear" aria-label="<?php echo htmlspecialchars(t('admin_websocket_clients_search_clear_aria')); ?>" title="<?php echo htmlspecialchars(t('admin_websocket_clients_search_clear_aria')); ?>" style="display:none;">
                <span class="icon"><i class="fas fa-times"></i></span>
            </button>
            <span id="client-search-count" class="search-count" aria-live="polite"><?php echo t('admin_websocket_clients_results', [0]); ?></span>
        </div>
    </div>
    <?php if (empty($websocketData['registered_clients'])): ?>
        <div class="sp-alert sp-alert-info">
            <p><?php echo t('admin_websocket_clients_none_connected'); ?></p>
        </div>
    <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table" id="clients-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_websocket_clients_th_display_name'); ?></th>
                        <th><?php echo t('admin_websocket_clients_th_api_key'); ?></th>
                        <th><?php echo t('admin_websocket_clients_th_connected_clients'); ?></th>
                        <th><?php echo t('admin_websocket_clients_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($websocketData['registered_clients'] as $apiKey => $userData): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($userData['twitch_display_name']); ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:0.5rem;">
                                    <?php $maskLen = min(strlen($apiKey), 32); $masked = str_repeat('•', $maskLen); ?>
                                    <code class="masked-api-key"><?php echo htmlspecialchars($masked); ?></code>
                                    <code class="full-api-key" style="display:none;"><?php echo htmlspecialchars($apiKey); ?></code>
                                    <button class="sp-btn sp-btn-sm" aria-label="<?php echo htmlspecialchars(t('admin_websocket_clients_toggle_api_key_aria')); ?>" onclick="toggleApiKey(this)">
                                        <span class="icon"><i class="fas fa-eye"></i></span>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <span class="sp-badge sp-badge-blue"><?php echo t('admin_websocket_clients_client_count', [$userData['client_count']]); ?></span>
                            </td>
                            <td>
                                <div class="sp-btn-group">
                                    <button class="sp-btn sp-btn-info sp-btn-sm" onclick="showUserClients('<?php echo htmlspecialchars($apiKey); ?>', '<?php echo htmlspecialchars($userData['twitch_display_name']); ?>')">
                                        <span class="icon"><i class="fas fa-eye"></i></span>
                                        <span><?php echo t('admin_websocket_clients_view_clients'); ?></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- Listeners Section -->
<?php if (!empty($websocketData['global_listeners'])): ?>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><i class="fas fa-globe" style="margin-right:0.5rem;"></i><?php echo t('admin_websocket_clients_global_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <p style="margin-bottom:1rem;"><?php echo t('admin_websocket_clients_global_desc'); ?></p>
    <div class="sp-table-wrap">
        <table class="sp-table" id="global-listeners-table">
            <thead>
                <tr>
                    <th><?php echo t('admin_websocket_clients_th_listener'); ?></th>
                    <th><?php echo t('admin_websocket_clients_th_status'); ?></th>
                    <th><?php echo t('admin_websocket_clients_th_socket_id'); ?></th>
                    <th><?php echo t('admin_websocket_clients_th_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($websocketData['global_listeners'] as $listener): ?>
                    <tr>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:0.4rem;">
                                <span class="icon sp-text-success"><i class="fas fa-broadcast-tower"></i></span>
                                <span style="font-weight:600;"><?php echo htmlspecialchars(cleanListenerName($listener['name'])); ?></span>
                            </span>
                        </td>
                        <td>
                            <span class="sp-badge sp-badge-green">
                                <span class="icon"><i class="fas fa-circle" style="font-size: 0.5rem;"></i></span>
                                <span><?php echo t('admin_websocket_clients_status_active'); ?></span>
                            </span>
                        </td>
                        <td>
                            <code style="font-size:0.75rem;"><?php echo htmlspecialchars($listener['sid']); ?></code>
                        </td>
                        <td>
                            <div class="sp-btn-group">
                                <button class="sp-btn sp-btn-info sp-btn-sm" onclick="showListenerDetails('<?php echo htmlspecialchars($listener['sid']); ?>', '<?php echo htmlspecialchars($listener['name']); ?>')">
                                    <span class="icon"><i class="fas fa-info"></i></span>
                                    <span><?php echo t('admin_websocket_clients_details'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-danger sp-btn-sm" onclick="disconnectClient('<?php echo htmlspecialchars($listener['sid']); ?>')">
                                    <span class="icon"><i class="fas fa-times"></i></span>
                                    <span><?php echo t('admin_websocket_clients_disconnect'); ?></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>
<?php endif; ?>
<!-- User Clients Modal -->
<div class="sp-modal-backdrop" id="user-clients-modal" onclick="closeUserClientsModal()">
    <div class="sp-modal" style="max-width:1400px;width:90vw;" onclick="event.stopPropagation()">
        <div class="sp-modal-head">
            <h2 class="sp-modal-title">
                <span class="icon"><i class="fas fa-users"></i></span>
                <?php echo t('admin_websocket_clients_modal_title'); ?> <span id="modal-user-name"></span>
            </h2>
            <button class="sp-modal-close" aria-label="<?php echo htmlspecialchars(t('admin_websocket_clients_modal_close_aria')); ?>" onclick="closeUserClientsModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="sp-modal-body" id="user-clients-content">
            <!-- Content will be loaded dynamically -->
        </div>
        <div style="padding:1rem;display:flex;justify-content:flex-end;border-top:1px solid var(--border);">
            <button class="sp-btn sp-btn-secondary" onclick="closeUserClientsModal()"><?php echo t('admin_websocket_clients_close'); ?></button>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const i18n = {
    lastUpdated: <?php echo json_encode(t('admin_websocket_clients_last_updated', ['%s'])); ?>,
    refreshFailedTitle: <?php echo json_encode(t('admin_websocket_clients_swal_refresh_failed_title')); ?>,
    refreshFailedText: <?php echo json_encode(t('admin_websocket_clients_swal_refresh_failed_text')); ?>,
    noneConnected: <?php echo json_encode(t('admin_websocket_clients_none_connected')); ?>,
    clientCountSuffix: <?php echo json_encode(t('admin_websocket_clients_client_count_suffix')); ?>,
    viewClients: <?php echo json_encode(t('admin_websocket_clients_view_clients')); ?>,
    toggleApiKeyAria: <?php echo json_encode(t('admin_websocket_clients_toggle_api_key_aria')); ?>,
    statusActive: <?php echo json_encode(t('admin_websocket_clients_status_active')); ?>,
    details: <?php echo json_encode(t('admin_websocket_clients_details')); ?>,
    disconnect: <?php echo json_encode(t('admin_websocket_clients_disconnect')); ?>,
    resultSingular: <?php echo json_encode(t('admin_websocket_clients_result_singular')); ?>,
    resultPlural: <?php echo json_encode(t('admin_websocket_clients_result_plural')); ?>,
    apiKeyLabel: <?php echo json_encode(t('admin_websocket_clients_modal_api_key_label')); ?>,
    connectedClientsSuffix: <?php echo json_encode(t('admin_websocket_clients_modal_connected_clients_suffix')); ?>,
    thClientName: <?php echo json_encode(t('admin_websocket_clients_th_client_name')); ?>,
    thSocketId: <?php echo json_encode(t('admin_websocket_clients_th_socket_id')); ?>,
    thAdmin: <?php echo json_encode(t('admin_websocket_clients_th_admin')); ?>,
    thConnectedAt: <?php echo json_encode(t('admin_websocket_clients_th_connected_at')); ?>,
    thLastActivity: <?php echo json_encode(t('admin_websocket_clients_th_last_activity')); ?>,
    thActions: <?php echo json_encode(t('admin_websocket_clients_th_actions')); ?>,
    badgeAdmin: <?php echo json_encode(t('admin_websocket_clients_badge_admin')); ?>,
    badgeUser: <?php echo json_encode(t('admin_websocket_clients_badge_user')); ?>,
    notAvailable: <?php echo json_encode(t('admin_websocket_clients_not_available')); ?>,
    errorTitle: <?php echo json_encode(t('admin_websocket_clients_swal_error_title')); ?>,
    loadClientsFailed: <?php echo json_encode(t('admin_websocket_clients_swal_load_failed')); ?>,
    listenerDetailsTitle: <?php echo json_encode(t('admin_websocket_clients_swal_listener_title')); ?>,
    listenerName: <?php echo json_encode(t('admin_websocket_clients_swal_listener_name')); ?>,
    socketIdLabel: <?php echo json_encode(t('admin_websocket_clients_swal_socket_id')); ?>,
    typeLabel: <?php echo json_encode(t('admin_websocket_clients_swal_type')); ?>,
    typeValue: <?php echo json_encode(t('admin_websocket_clients_swal_type_value')); ?>,
    permissionsLabel: <?php echo json_encode(t('admin_websocket_clients_swal_permissions')); ?>,
    permissionsValue: <?php echo json_encode(t('admin_websocket_clients_swal_permissions_value')); ?>,
    closeBtn: <?php echo json_encode(t('admin_websocket_clients_close')); ?>,
    disconnectTitle: <?php echo json_encode(t('admin_websocket_clients_swal_disconnect_title')); ?>,
    disconnectText: <?php echo json_encode(t('admin_websocket_clients_swal_disconnect_text')); ?>,
    disconnectConfirm: <?php echo json_encode(t('admin_websocket_clients_swal_disconnect_confirm')); ?>,
    disconnectedTitle: <?php echo json_encode(t('admin_websocket_clients_swal_disconnected_title')); ?>,
    disconnectFailed: <?php echo json_encode(t('admin_websocket_clients_swal_disconnect_failed')); ?>
};
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh the data every 60 seconds
    setInterval(function() {
        refreshData(true); // true = silent refresh
    }, 60000);
    // Search functionality: debounce input, show clear button, and update visible result count
    const clientSearchInput = document.getElementById('client-search');
    const clientSearchClear = document.getElementById('client-search-clear');
    const clientSearchCount = document.getElementById('client-search-count');
    let searchDebounceTimer = null;
    if (clientSearchInput) {
        clientSearchInput.addEventListener('input', function(e) {
            // Toggle clear button visibility
            if (clientSearchInput.value && clientSearchInput.value.length > 0) {
                if (clientSearchClear) clientSearchClear.style.display = '';
            } else {
                if (clientSearchClear) clientSearchClear.style.display = 'none';
            }
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => filterClients(), 180);
        });
    }
    if (clientSearchClear) {
        clientSearchClear.addEventListener('click', function() {
            clientSearchInput.value = '';
            clientSearchClear.style.display = 'none';
            filterClients();
            clientSearchInput.focus();
        });
    }
    // Render initial server-provided last-updated timestamp using client's locale so it matches refresh formatting
    try {
        const lastUpdatedEl = document.getElementById('last-updated');
        if (lastUpdatedEl) {
            const iso = lastUpdatedEl.getAttribute('data-last-updated');
            if (iso) {
                const dt = new Date(iso);
                if (!isNaN(dt.getTime())) {
                    lastUpdatedEl.innerHTML = `<small>${i18n.lastUpdated.replace('%s', dt.toLocaleString())}</small>`;
                }
            }
        }
        // Initialize search UI state and result count on page load
        try {
            const clientSearchInput = document.getElementById('client-search');
            const clientSearchClear = document.getElementById('client-search-clear');
            if (clientSearchInput) {
                if (clientSearchClear) {
                    clientSearchClear.style.display = clientSearchInput.value && clientSearchInput.value.length > 0 ? '' : 'none';
                }
            }
            // Run an initial filter to set the result count correctly
            if (typeof filterClients === 'function') filterClients();
        } catch (e) {
            // ignore
        }
    } catch (e) {
        // fail silently if anything goes wrong
        console.error('Failed to render initial last-updated timestamp', e);
    }
});

async function refreshData(silent = false) {
    try {
        if (!silent) {
            // Show loading state on manual refresh
            const refreshBtn = document.querySelector('button[onclick="refreshData()"]');
            if (refreshBtn) {
                refreshBtn.classList.add('sp-btn-loading');
            }
        }
        const response = await fetch('?refresh_data=1');
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const data = await response.json();
        // Update statistics
        updateStatistics(data);
        // Update tables
        updateClientsTable(data.registered_clients);
        updateGlobalListenersTable(data.global_listeners);
        // Update last updated timestamp
        document.getElementById('last-updated').innerHTML =
            `<small>${i18n.lastUpdated.replace('%s', new Date().toLocaleString())}</small>`;
        if (!silent) {
            const refreshBtn = document.querySelector('button[onclick="refreshData()"]');
            if (refreshBtn) {
                refreshBtn.classList.remove('sp-btn-loading');
            }
        }
    } catch (error) {
        console.error('Failed to refresh data:', error);
        if (!silent) {
            Swal.fire({
                icon: 'error',
                title: i18n.refreshFailedTitle,
                text: i18n.refreshFailedText
            });
            const refreshBtn = document.querySelector('button[onclick="refreshData()"]');
            if (refreshBtn) {
                refreshBtn.classList.remove('sp-btn-loading');
            }
        }
    }
}

function updateStatistics(data) {
    let totalClients = 0;
    let totalCodes = Object.keys(data.registered_clients).length;
    for (const apiKey in data.registered_clients) {
        totalClients += data.registered_clients[apiKey].client_count;
    }
    let totalGlobalListeners = data.global_listeners.length;
    let totalConnections = totalClients + totalGlobalListeners;
    // Update the statistics cards using specific IDs
    document.getElementById('stat-total').textContent = totalConnections;
    document.getElementById('stat-clients').textContent = totalClients;
    document.getElementById('stat-codes').textContent = totalCodes;
    document.getElementById('stat-global').textContent = totalGlobalListeners;
}

function updateClientsTable(registeredClients) {
    const table = document.getElementById('clients-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    tbody.innerHTML = '';
    if (Object.keys(registeredClients).length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">${escapeHtml(i18n.noneConnected)}</td></tr>`;
        return;
    }
    // Sort clients by client count (descending)
    const sortedClients = Object.entries(registeredClients).sort((a, b) => {
        return b[1].client_count - a[1].client_count;
    });
    for (const [apiKey, userData] of sortedClients) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(userData.twitch_display_name)}</td>
            <td>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <code class="masked-api-key">${'•'.repeat(Math.min(apiKey.length, 32))}</code>
                    <code class="full-api-key" style="display:none;">${escapeHtml(apiKey)}</code>
                    <button class="sp-btn sp-btn-sm" aria-label="${escapeHtml(i18n.toggleApiKeyAria)}" onclick="toggleApiKey(this)">
                        <span class="icon"><i class="fas fa-eye"></i></span>
                    </button>
                </div>
            </td>
            <td><span class="sp-badge sp-badge-blue">${userData.client_count} ${escapeHtml(i18n.clientCountSuffix)}</span></td>
            <td>
                <div class="sp-btn-group">
                    <button class="sp-btn sp-btn-info sp-btn-sm" onclick="showUserClients('${escapeHtml(apiKey)}', '${escapeHtml(userData.twitch_display_name)}')">
                        <span class="icon"><i class="fas fa-eye"></i></span>
                        <span>${escapeHtml(i18n.viewClients)}</span>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    }
    // Re-run the filter to update the visible count and clear button state
    try {
        if (typeof filterClients === 'function') filterClients();
    } catch (e) {
        console.error('filterClients() failed after updating clients table', e);
    }
}

function updateGlobalListenersTable(globalListeners) {
    // Find the listeners section by looking for the specific heading text
    // Find the global listeners section by ID
    const globalSectionCard = document.querySelector('.sp-card:has(#global-listeners-table)');
    let globalSection = globalSectionCard || null;
    if (!globalSection && globalListeners.length > 0) {
        // Create listeners section if it doesn't exist but we have data
        location.reload(); // For now, just reload the page
        return;
    }
    if (globalListeners.length === 0) {
        // Hide listeners section if no data
        if (globalSection) globalSection.style.display = 'none';
        return;
    }
    if (globalSection) globalSection.style.display = '';
    const table = globalSection?.querySelector('table tbody');
    if (!table) return;
    table.innerHTML = '';
    globalListeners.forEach(listener => {
        const row = document.createElement('tr');
        const cleanedName = cleanListenerName(listener.name);
        row.innerHTML = `
            <td>
                <span class="icon-text">
                    <span class="icon sp-text-success">
                        <i class="fas fa-broadcast-tower"></i>
                    </span>
                    <span style="font-weight:600;">${escapeHtml(cleanedName)}</span>
                </span>
            </td>
            <td>
                <span class="sp-badge sp-badge-green">
                    <span class="icon">
                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                    </span>
                    <span>${escapeHtml(i18n.statusActive)}</span>
                </span>
            </td>
            <td>
                <code style="font-size:0.75rem;">${escapeHtml(listener.sid)}</code>
            </td>
            <td>
                <div class="sp-btn-group">
                    <button class="sp-btn sp-btn-info sp-btn-sm" onclick="showListenerDetails('${escapeHtml(listener.sid)}', '${escapeHtml(listener.name)}')">
                        <span class="icon"><i class="fas fa-info"></i></span>
                        <span>${escapeHtml(i18n.details)}</span>
                    </button>
                    <button class="sp-btn sp-btn-danger sp-btn-sm" onclick="disconnectClient('${escapeHtml(listener.sid)}')">
                        <span class="icon"><i class="fas fa-times"></i></span>
                        <span>${escapeHtml(i18n.disconnect)}</span>
                    </button>
                </div>
            </td>
        `;
        table.appendChild(row);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Clean listener name by removing common prefixes
function cleanListenerName(name) {
    // Remove "Global - " prefix (case insensitive)
    if (name.toLowerCase().startsWith('global - ')) {
        name = name.substring(9);
    }
}

// Toggle API key visibility (button should be inside the same container as .masked-api-key and .full-api-key)
function toggleApiKey(button) {
    try {
        // Button might be inside a <p> or div; find nearest container with masked/full elements
        let container = button.parentElement;
        // If the button was rendered inside the <p> that also contains text nodes, parentElement is fine
        // Keep walking up if necessary to find the masked/full elements
        while (container && !container.querySelector('.masked-api-key')) {
            container = container.parentElement;
        }
        if (!container) return;
        const masked = container.querySelector('.masked-api-key');
        const full = container.querySelector('.full-api-key');
        const icon = button.querySelector('i');
        if (!masked || !full || !icon) return;
        const showing = full.style.display !== 'none';
        if (showing) {
            full.style.display = 'none';
            masked.style.display = '';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        } else {
            full.style.display = '';
            masked.style.display = 'none';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } catch (e) {
        console.error('toggleApiKey error', e);
    }
}

function filterClients() {
    const inputEl = document.getElementById('client-search');
    const input = inputEl ? (inputEl.value || '').toLowerCase() : '';
    const table = document.getElementById('clients-table');
    if (!table) return;
    const rows = table.getElementsByTagName('tr');
    let visibleCount = 0;
    for (let i = 1; i < rows.length; i++) { // Skip header row
        const row = rows[i];
        const cells = row.getElementsByTagName('td');
        let shouldShow = false;
        // Search through display name and API key columns
        for (let j = 0; j < 2; j++) { // Only search display name and API key columns
            const cell = cells[j];
            if (!cell) continue;
            const cellText = (cell.textContent || cell.innerText || '').toLowerCase();
            if (cellText.indexOf(input) !== -1) {
                shouldShow = true;
                break;
            }
        }
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
    }
    // Update result count
    const countEl = document.getElementById('client-search-count');
    if (countEl) {
        countEl.textContent = (visibleCount === 1 ? i18n.resultSingular : i18n.resultPlural).replace('%s', visibleCount);
    }
}

async function showUserClients(apiKey, displayName) {
    try {
        const response = await fetch(`?get_user_clients=1&api_key=${encodeURIComponent(apiKey)}`);
        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }
        document.getElementById('modal-user-name').textContent = displayName;
        const maskedKey = '•'.repeat(Math.min(apiKey.length, 32));
        let content = `
            <div class="sp-card" style="margin-bottom:1rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <div>
                        <p style="font-size:1rem;font-weight:700;margin:0 0 0.25rem;">${escapeHtml(i18n.apiKeyLabel)} <code class="masked-api-key">${maskedKey}</code><code class="full-api-key" style="display:none;">${escapeHtml(apiKey)}</code>
                            <button class="sp-btn sp-btn-sm" aria-label="${escapeHtml(i18n.toggleApiKeyAria)}" onclick="toggleApiKey(this)">
                                <span class="icon"><i class="fas fa-eye"></i></span>
                            </button>
                        </p>
                        <p style="color:var(--text-muted);font-size:0.85rem;">${data.client_count} ${escapeHtml(i18n.connectedClientsSuffix)}</p>
                    </div>
                </div>
            </div>
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <thead>
                        <tr>
                            <th>${escapeHtml(i18n.thClientName)}</th>
                            <th>${escapeHtml(i18n.thSocketId)}</th>
                            <th>${escapeHtml(i18n.thAdmin)}</th>
                            <th>${escapeHtml(i18n.thConnectedAt)}</th>
                            <th>${escapeHtml(i18n.thLastActivity)}</th>
                            <th>${escapeHtml(i18n.thActions)}</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        data.clients.forEach(client => {
            content += `
                <tr>
                    <td>${escapeHtml(client.name)}</td>
                    <td><code>${escapeHtml(client.sid)}</code></td>
                    <td>
                        ${client.is_admin ? `<span class="sp-badge sp-badge-red">${escapeHtml(i18n.badgeAdmin)}</span>` : `<span class="sp-badge sp-badge-blue">${escapeHtml(i18n.badgeUser)}</span>`}
                    </td>
                    <td>${client.connected_at || i18n.notAvailable}</td>
                    <td>${client.last_activity || i18n.notAvailable}</td>
                    <td>
                        <button class="sp-btn sp-btn-danger sp-btn-sm" onclick="disconnectClient('${escapeHtml(client.sid)}')">
                            <span class="icon"><i class="fas fa-times"></i></span>
                            <span>${escapeHtml(i18n.disconnect)}</span>
                        </button>
                    </td>
                </tr>
            `;
        });
        content += `
                    </tbody>
                </table>
            </div>
        `;
        document.getElementById('user-clients-content').innerHTML = content;
        const modal = document.getElementById('user-clients-modal');
        modal.classList.add('is-active');
    } catch (error) {
        console.error('Failed to load user clients:', error);
        Swal.fire({
            icon: 'error',
            title: i18n.errorTitle,
            text: i18n.loadClientsFailed.replace('%s', error.message)
        });
    }
}

function closeUserClientsModal() {
    const modal = document.getElementById('user-clients-modal');
    modal.classList.remove('is-active');
}

function showListenerDetails(sid, name) {
    Swal.fire({
        title: i18n.listenerDetailsTitle,
        html: `
            <div class="content">
                <p><span class="has-text-weight-bold">${escapeHtml(i18n.listenerName)}</span> ${escapeHtml(name)}</p>
                <p><span class="has-text-weight-bold">${escapeHtml(i18n.socketIdLabel)}</span> <code>${escapeHtml(sid)}</code></p>
                <p><span class="has-text-weight-bold">${escapeHtml(i18n.typeLabel)}</span> ${escapeHtml(i18n.typeValue)}</p>
                <p><span class="has-text-weight-bold">${escapeHtml(i18n.permissionsLabel)}</span> ${escapeHtml(i18n.permissionsValue)}</p>
            </div>
        `,
        icon: 'info',
        confirmButtonText: i18n.closeBtn,
        width: 600,
        customClass: {
            htmlContainer: 'text-left'
        }
    });
}

function disconnectClient(sid) {
    Swal.fire({
        title: i18n.disconnectTitle,
        text: i18n.disconnectText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: i18n.disconnectConfirm
    }).then(async (result) => {
        if (result.isConfirmed) {
            try {
                const formData = new FormData();
                formData.append('disconnect_client', '1');
                formData.append('sid', sid);
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: i18n.disconnectedTitle,
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        // Refresh the page to update the client list
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: i18n.errorTitle,
                        text: data.message || i18n.disconnectFailed
                    });
                }
            } catch (error) {
                console.error('Failed to disconnect client:', error);
                Swal.fire({
                    icon: 'error',
                    title: i18n.errorTitle,
                    text: i18n.disconnectFailed
                });
            }
        }
    });
}
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>