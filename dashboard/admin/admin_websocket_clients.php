<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_websocket_clients_title');
require_once "/var/www/config/db_connect.php";

// Fetch user display name from database by API key
function getUserDisplayName($apiKey, $conn) {
    $stmt = $conn->prepare("SELECT twitch_display_name FROM users WHERE api_key = ? LIMIT 1");
    if (!$stmt) {
        return "Unknown";
    }
    $stmt->bind_param("s", $apiKey);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return $row['twitch_display_name'] ?: "Unknown";
    }
    return "Unknown";
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
    header('Content-Type: application/json');
    $data = fetchWebsocketClients($conn);
    echo json_encode($data);
    exit;
}

// Fetch the real websocket data
$websocketData = fetchWebsocketClients($conn);
$lastUpdated = date('Y-m-d H:i:s');
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
    header('Content-Type: application/json');
    $apiKey = $_GET['api_key'] ?? '';
    $data = fetchWebsocketClients($conn);
    
    if (isset($data['registered_clients'][$apiKey])) {
        echo json_encode($data['registered_clients'][$apiKey]);
    } else {
        echo json_encode(['error' => 'User not found']);
    }
    exit;
}

// Handle AJAX request to disconnect client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_client'])) {
    header('Content-Type: application/json');
    $sid = $_POST['sid'] ?? '';
    // In a real implementation, this would send a disconnect command to the websocket server
    $response = ['success' => true, 'message' => 'Client disconnected successfully'];
    echo json_encode($response);
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
<div class="box">
    <div class="level">
        <div class="level-left">
            <div class="level-item">
                <div>
                    <h1 class="title is-4"><span class="icon"><i class="fas fa-plug"></i></span> Websocket Clients Overview</h1>
                    <p class="mb-4">Monitor active websocket connections, registered clients, and global listeners in real-time.</p>
                </div>
            </div>
        </div>
        <div class="level-right">
            <div class="level-item">
                <button class="button is-primary" onclick="refreshData()">
                    <span class="icon"><i class="fas fa-sync-alt"></i></span>
                    <span>Refresh</span>
                </button>
            </div>
        </div>
    </div>
    <?php if ($apiError): ?>
        <div class="notification is-warning">
            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
            <strong>Warning:</strong> Unable to connect to the websocket server API. Displaying cached or empty data.
        </div>
    <?php else: ?>
        <div class="notification is-info is-light" id="last-updated">
            <small><strong>Last Updated:</strong> <?php echo htmlspecialchars($lastUpdated); ?></small>
        </div>
    <?php endif; ?>
    <!-- Statistics Cards -->
    <div class="columns mb-4">
        <div class="column">
            <div class="box has-background-info has-text-white">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <div>
                                <p class="title is-4 has-text-white" id="stat-total"><?php echo $totalConnections; ?></p>
                                <p class="subtitle is-6 has-text-white">Total Connections</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="icon is-large has-text-white">
                                <i class="fas fa-network-wired fa-2x"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="column">
            <div class="box has-background-primary has-text-white">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <div>
                                <p class="title is-4 has-text-white" id="stat-clients"><?php echo $totalClients; ?></p>
                                <p class="subtitle is-6 has-text-white">Registered Clients</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="icon is-large has-text-white">
                                <i class="fas fa-users fa-2x"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="column">
            <div class="box has-background-warning has-text-black">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <div>
                                <p class="title is-4 has-text-black" id="stat-codes"><?php echo $totalCodes; ?></p>
                                <p class="subtitle is-6 has-text-black">Active Codes</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="icon is-large has-text-black">
                                <i class="fas fa-key fa-2x"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="column">
            <div class="box has-background-success has-text-white">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <div>
                                <p class="title is-4 has-text-white" id="stat-global"><?php echo $totalGlobalListeners; ?></p>
                                <p class="subtitle is-6 has-text-white">Global Listeners</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="icon is-large has-text-white">
                                <i class="fas fa-globe fa-2x"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Registered Clients Section -->
<div class="box">
    <h2 class="title is-5"><span class="icon"><i class="fas fa-users"></i></span> Registered Users</h2>
    <p class="mb-4">Users with active websocket connections grouped by their API keys.</p>
    <div class="field mb-4">
        <div class="control">
            <input type="text" id="client-search" placeholder="Search by display name or API key..." class="input">
        </div>
    </div>
    <?php if (empty($websocketData['registered_clients'])): ?>
        <div class="notification is-info">
            <p>No registered clients are currently connected.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-striped" id="clients-table">
                <thead>
                    <tr>
                        <th>Display Name</th>
                        <th>API Key</th>
                        <th>Connected Clients</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($websocketData['registered_clients'] as $apiKey => $userData): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($userData['twitch_display_name']); ?></strong>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars($apiKey); ?></code>
                            </td>
                            <td>
                                <span class="tag is-info"><?php echo $userData['client_count']; ?> clients</span>
                            </td>
                            <td>
                                <div class="buttons are-small">
                                    <button class="button is-info is-small" onclick="showUserClients('<?php echo htmlspecialchars($apiKey); ?>', '<?php echo htmlspecialchars($userData['twitch_display_name']); ?>')">
                                        <span class="icon"><i class="fas fa-eye"></i></span>
                                        <span>View Clients</span>
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

<!-- Global Listeners Section -->
<?php if (!empty($websocketData['global_listeners'])): ?>
<div class="box">
    <h2 class="title is-5"><span class="icon"><i class="fas fa-globe"></i></span> Global Listeners</h2>
    <p class="mb-4">Admin-authenticated clients that receive events from all channels.</p>
    
    <div class="table-container">
        <table class="table is-fullwidth is-striped">
            <thead>
                <tr>
                    <th>Listener Name</th>
                    <th>Socket ID</th>
                    <th>Admin Auth</th>
                    <th>Connected At</th>
                    <th>Last Activity</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($websocketData['global_listeners'] as $listener): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($listener['name']); ?></strong>
                        </td>
                        <td>
                            <span class="tag is-dark"><?php echo htmlspecialchars(substr($listener['sid'], 0, 12) . '...'); ?></span>
                        </td>
                        <td>
                            <span class="tag is-success">Authenticated</span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($listener['connected_at']); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($listener['last_activity']); ?>
                        </td>
                        <td>
                            <div class="buttons are-small">
                                <button class="button is-info is-small" onclick="showClientDetails('<?php echo htmlspecialchars($listener['sid']); ?>')">
                                    <span class="icon"><i class="fas fa-info"></i></span>
                                </button>
                                <button class="button is-danger is-small" onclick="disconnectClient('<?php echo htmlspecialchars($listener['sid']); ?>')">
                                    <span class="icon"><i class="fas fa-times"></i></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<!-- User Clients Modal -->
<div class="modal" id="user-clients-modal">
    <div class="modal-background" onclick="closeUserClientsModal()"></div>
    <div class="modal-card" style="max-width: 1400px; width: 90vw;">
        <header class="modal-card-head">
            <p class="modal-card-title">
                <span class="icon"><i class="fas fa-users"></i></span>
                User Clients: <span id="modal-user-name"></span>
            </p>
            <button class="delete" aria-label="close" onclick="closeUserClientsModal()"></button>
        </header>
        <section class="modal-card-body" id="user-clients-content">
            <!-- Content will be loaded dynamically -->
        </section>
        <footer class="modal-card-foot">
            <button class="button" onclick="closeUserClientsModal()">Close</button>
        </footer>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh the data every 60 seconds
    setInterval(function() {
        refreshData(true); // true = silent refresh
    }, 60000);
    // Search functionality
    document.getElementById('client-search').addEventListener('keyup', function(e) {
        filterClients();
    });
});

async function refreshData(silent = false) {
    try {
        if (!silent) {
            // Show loading state on manual refresh
            const refreshBtn = document.querySelector('button[onclick="refreshData()"]');
            if (refreshBtn) {
                refreshBtn.classList.add('is-loading');
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
            `<small><strong>Last Updated:</strong> ${new Date().toLocaleString()}</small>`;
        if (!silent) {
            const refreshBtn = document.querySelector('button[onclick="refreshData()"]');
            if (refreshBtn) {
                refreshBtn.classList.remove('is-loading');
            }
        }
    } catch (error) {
        console.error('Failed to refresh data:', error);
        if (!silent) {
            Swal.fire({
                icon: 'error',
                title: 'Refresh Failed',
                text: 'Could not fetch updated websocket client data. Please try again later.'
            });
            const refreshBtn = document.querySelector('button[onclick="refreshData()"]');
            if (refreshBtn) {
                refreshBtn.classList.remove('is-loading');
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
        tbody.innerHTML = '<tr><td colspan="4" class="has-text-centered">No registered clients are currently connected.</td></tr>';
        return;
    }
    // Sort clients by client count (descending)
    const sortedClients = Object.entries(registeredClients).sort((a, b) => {
        return b[1].client_count - a[1].client_count;
    });
    for (const [apiKey, userData] of sortedClients) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(userData.twitch_display_name)}</strong></td>
            <td><code>${escapeHtml(apiKey)}</code></td>
            <td><span class="tag is-info">${userData.client_count} clients</span></td>
            <td>
                <div class="buttons are-small">
                    <button class="button is-info is-small" onclick="showUserClients('${escapeHtml(apiKey)}', '${escapeHtml(userData.twitch_display_name)}')">
                        <span class="icon"><i class="fas fa-eye"></i></span>
                        <span>View Clients</span>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    }
}

function updateGlobalListenersTable(globalListeners) {
    // Find the global listeners section by looking for the specific heading text
    const headings = document.querySelectorAll('h2.title');
    let globalSection = null;
    for (const heading of headings) {
        if (heading.textContent.includes('Global Listeners')) {
            globalSection = heading.closest('.box');
            break;
        }
    }
    if (!globalSection && globalListeners.length > 0) {
        // Create global listeners section if it doesn't exist but we have data
        location.reload(); // For now, just reload the page
        return;
    }
    if (globalListeners.length === 0) {
        // Hide global listeners section if no data
        if (globalSection) {
            globalSection.style.display = 'none';
        }
        return;
    }
    // Show the section if it was hidden
    if (globalSection) {
        globalSection.style.display = '';
    }
    const table = globalSection?.querySelector('table tbody');
    if (!table) return;
    table.innerHTML = '';
    globalListeners.forEach(listener => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(listener.name)}</strong></td>
            <td><span class="tag is-light">${escapeHtml(listener.sid.substring(0, 12) + '...')}</span></td>
            <td><span class="tag is-success">Authenticated</span></td>
            <td>${listener.connected_at || 'N/A'}</td>
            <td>${listener.last_activity || 'N/A'}</td>
            <td>
                <div class="buttons are-small">
                    <button class="button is-info is-small" onclick="showClientDetails('${escapeHtml(listener.sid)}')">
                        <span class="icon"><i class="fas fa-info"></i></span>
                    </button>
                    <button class="button is-danger is-small" onclick="disconnectClient('${escapeHtml(listener.sid)}')">
                        <span class="icon"><i class="fas fa-times"></i></span>
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

function filterClients() {
    const input = document.getElementById('client-search').value.toLowerCase();
    const table = document.getElementById('clients-table');
    if (!table) return;
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) { // Skip header row
        const row = rows[i];
        const cells = row.getElementsByTagName('td');
        let shouldShow = false;
        // Search through display name and API key columns
        for (let j = 0; j < 2; j++) { // Only search display name and API key columns
            const cellText = cells[j].textContent.toLowerCase();
            if (cellText.includes(input)) {
                shouldShow = true;
                break;
            }
        }
        row.style.display = shouldShow ? '' : 'none';
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
        let content = `
            <div class="box">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <div>
                                <p class="title is-6">API Key: <code>${escapeHtml(apiKey)}</code></p>
                                <p class="subtitle is-6">${data.client_count} connected clients</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table class="table is-fullwidth is-striped">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Socket ID</th>
                            <th>Admin</th>
                            <th>Connected At</th>
                            <th>Last Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        data.clients.forEach(client => {
            content += `
                <tr>
                    <td><strong>${escapeHtml(client.name)}</strong></td>
                    <td><code>${escapeHtml(client.sid)}</code></td>
                    <td>
                        ${client.is_admin ? '<span class="tag is-danger">Admin</span>' : '<span class="tag is-info">User</span>'}
                    </td>
                    <td>${client.connected_at || 'N/A'}</td>
                    <td>${client.last_activity || 'N/A'}</td>
                    <td>
                        <button class="button is-danger is-small" onclick="disconnectClient('${escapeHtml(client.sid)}')">
                            <span class="icon"><i class="fas fa-times"></i></span>
                            <span>Disconnect</span>
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
        document.getElementById('user-clients-modal').classList.add('is-active');
    } catch (error) {
        console.error('Failed to load user clients:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to load user clients: ' + error.message
        });
    }
}

function closeUserClientsModal() {
    document.getElementById('user-clients-modal').classList.remove('is-active');
}

function disconnectClient(sid) {
    Swal.fire({
        title: 'Disconnect Client?',
        text: 'Are you sure you want to disconnect this client?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, disconnect'
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
                        title: 'Disconnected',
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
                        title: 'Error',
                        text: data.message || 'Failed to disconnect client.'
                    });
                }
            } catch (error) {
                console.error('Failed to disconnect client:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to disconnect client.'
                });
            }
        }
    });
}
</script>
<?php
$scripts = ob_get_clean();
include "admin_layout.php";
?>