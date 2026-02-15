<?php 
// Initialize the session
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = 'EventSub Notifications';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';

// Get access token from session
$accessToken = $_SESSION['access_token'];
$userId = $_SESSION['user_id'];

// Fetch all EventSub subscriptions (including stale/disabled)
$ch = curl_init('https://api.twitch.tv/helix/eventsub/subscriptions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Client-Id: ' . $clientID
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$subscriptions = [];
$error = null;
$totalCount = 0;
$maxTotal = 0;
$totalCost = 0;
$maxCost = 0;

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $subscriptions = $data['data'] ?? [];
    $totalCount = $data['total'] ?? 0;
    $maxTotal = $data['max_total_cost'] ?? 0;
    $totalCost = $data['total_cost'] ?? 0;
    $maxCost = $data['max_total_cost'] ?? 0;
} else {
    $error = "Failed to fetch subscriptions. HTTP Code: $httpCode";
}

// Group subscriptions by transport type, session, and status
$websocketSubs = [];
$websocketSubsEnabled = [];
$websocketSubsDisabled = [];
$webhookSubs = [];
$sessionGroups = [];
$sessionGroupsDisabled = [];

foreach ($subscriptions as $sub) {
    if ($sub['transport']['method'] === 'websocket') {
        $websocketSubs[] = $sub;
        $sessionId = $sub['transport']['session_id'] ?? 'unknown';
        $isEnabled = ($sub['status'] === 'enabled');
        if ($isEnabled) {
            $websocketSubsEnabled[] = $sub;
            if (!isset($sessionGroups[$sessionId])) {
                $sessionGroups[$sessionId] = [];
            }
            $sessionGroups[$sessionId][] = $sub;
        } else {
            $websocketSubsDisabled[] = $sub;
            if (!isset($sessionGroupsDisabled[$sessionId])) {
                $sessionGroupsDisabled[$sessionId] = [];
            }
            $sessionGroupsDisabled[$sessionId][] = $sub;
        }
    } else {
        $webhookSubs[] = $sub;
    }
}

// Query session names from the database
$sessionNames = [];
try {
    $stmt = $db->prepare("SELECT session_id, session_name FROM eventsub_sessions");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sessionNames[$row['session_id']] = $row['session_name'];
    }
    $stmt->close();
} catch (Exception $e) {
    // If there's an error, just continue with empty session names
    error_log("Failed to fetch session names: " . $e->getMessage());
}

// Start output buffering
ob_start();
?>
<div class="section">
    <div class="container">
        <h1 class="title is-2">
            <i class="fas fa-bell"></i> EventSub Notifications
        </h1>
        <p class="subtitle">Monitor and manage your Twitch EventSub subscriptions <span id="auto-refresh-indicator" style="font-size: 12px; color: #aaa;">(Auto-refreshing every 10s)</span></p>
        <div id="notification-messages">
            <?php if ($error): ?>
                <div class="error-box">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
        </div>
        <div id="subscription-content">
            <?php include 'notifications_content.php'; ?>
        </div>
    </div>
</div>
<script>
console.log('Initial PHP session names:', <?php echo json_encode($sessionNames); ?>);
console.log('Initial PHP session groups:', <?php echo json_encode(array_keys($sessionGroups)); ?>);

// Auto-refresh interval (10 seconds)
let autoRefreshInterval;
let isDeleting = false; // Flag to prevent refresh during deletion

// Show notification message
function showNotification(message, type = 'success') {
    const container = document.getElementById('notification-messages');
    const div = document.createElement('div');
    div.className = type === 'success' ? 'info-box' : 'error-box';
    div.innerHTML = `<strong>${type === 'success' ? 'Success' : 'Error'}:</strong> ${message}`;
    container.appendChild(div);
    // Auto-remove after 5 seconds
    setTimeout(() => {
        div.style.opacity = '0';
        div.style.transition = 'opacity 0.5s';
        setTimeout(() => div.remove(), 500);
    }, 5000);
}

// Fetch and render subscriptions
async function refreshSubscriptions() {
    if (isDeleting) {
        console.log('Skipping refresh during deletion');
        return;
    }
    // If triggered by a button click, show loading state
    let button = null;
    let originalHTML = null;
    if (typeof event !== 'undefined' && event && event.target) {
        button = event.target.closest('button');
        if (button && button.classList.contains('refresh-btn')) {
            originalHTML = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Refreshing...';
        }
    }
    try {
        const response = await fetch('notifications_api.php?action=fetch_subscriptions');
        if (!response.ok) {
            throw new Error('Failed to fetch subscriptions');
        }
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Unknown error');
        }
        // Render the content using the API data
        renderSubscriptions(result.data);
        // Update indicator
        const indicator = document.getElementById('auto-refresh-indicator');
        if (indicator) {
            indicator.textContent = `(Last updated: ${new Date().toLocaleTimeString()})`;
        }
    } catch (error) {
        console.error('Refresh error:', error);
        showNotification('Failed to refresh subscriptions: ' + error.message, 'error');
    } finally {
        // Restore button state if it was manually triggered
        if (button && originalHTML) {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }
}

// Render subscriptions content
function renderSubscriptions(data) {
    const container = document.getElementById('subscription-content');
    if (!container) return;
    // Debug: Log session names to console
    console.log('Session names from API:', data.sessionNames);
    console.log('Session IDs from sessionGroups:', Object.keys(data.sessionGroups));
    // Build HTML content
    let html = '';
    // Stats Grid
    html += buildStatsGrid(data);
    // Active WebSocket Sessions
    if (Object.keys(data.sessionGroups).length > 0) {
        html += buildActiveSessionsSection(data);
    }
    // Disabled/Stale WebSocket Sessions
    if (Object.keys(data.sessionGroupsDisabled).length > 0) {
        html += buildDisabledSessionsSection(data);
    }
    // Webhook Subscriptions
    if (data.webhookSubs.length > 0) {
        html += buildWebhookSection(data);
    }
    // Empty state
    if (Object.keys(data.sessionGroups).length === 0 && data.webhookSubs.length === 0) {
        html += `
            <div class="box has-text-centered">
                <p class="subtitle">
                    <i class="fas fa-inbox"></i><br>
                    No EventSub subscriptions found.
                </p>
            </div>
        `;
    }
    container.innerHTML = html;
}

// Build stats grid HTML
function buildStatsGrid(data) {
    const connectionCounts = {};
    for (const [sessionId, subs] of Object.entries(data.sessionGroups)) {
        connectionCounts[sessionId] = subs.length;
    }
    const subCount = data.websocketSubsEnabled.length;
    const sessionCount = Object.keys(data.sessionGroups).length;
    let sessionColorClass = '';
    if (sessionCount >= 3) sessionColorClass = 'danger-card';
    else if (sessionCount >= 2) sessionColorClass = 'warning-card';
    let connectionDetails = '';
    let connectionNumber = 0;
    for (const [sessionId, count] of Object.entries(connectionCounts)) {
        connectionNumber++;
        let textColor = '#e6e6e6';
        if (count >= 250) textColor = '#e74c3c';
        else if (count >= 150) textColor = '#f39c12';
        connectionDetails += `<div class="stat-secondary" style="color: ${textColor}; margin-top: 4px;">Connection ${connectionNumber}: ${count} subscriptions</div>`;
    }
    if (data.websocketSubsDisabled.length > 0) {
        connectionDetails += `<div class="stat-secondary" style="color: #e74c3c; margin-top: 4px;">${data.websocketSubsDisabled.length} disabled/stale</div>`;
    }
    return `
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Subscriptions</div>
                <div class="stat-value">${data.totalCount}</div>
                <div class="stat-secondary">across all transports</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active WebSocket Subscriptions</div>
                <div class="stat-value">${subCount}</div>
                <div class="stat-secondary">limit: 300 per connection</div>
                ${connectionDetails}
            </div>
            <div class="stat-card ${sessionColorClass}">
                <div class="stat-label">Active Connections</div>
                <div class="stat-value">${sessionCount}</div>
                <div class="stat-secondary">limit: 3 connections</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Webhook Subscriptions</div>
                <div class="stat-value">${data.webhookSubs.length}</div>
                <div class="stat-secondary">callback-based</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Cost Usage</div>
                <div class="stat-value">${data.totalCost}</div>
                <div class="stat-secondary">of ${data.maxCost} max</div>
            </div>
        </div>
    `;
}

// Build active sessions section
function buildActiveSessionsSection(data) {
    let html = `
        <div class="box">
            <h2 class="title is-4">
                <i class="fas fa-network-wired"></i> Active WebSocket Sessions
                <button onclick="refreshSubscriptions()" class="refresh-btn" style="margin-left: auto; float: right; border: none; background: none; cursor: pointer; color: inherit;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button onclick="cleanDatabaseSessions()" class="custom-btn" style="margin-right: 8px; float: right;">
                    <i class="fas fa-broom"></i> Clean stale DB
                </button>
            </h2>
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Tip:</strong> Twitch limits you to 3 WebSocket connections. 
                Each session below counts toward that limit. Your bot and YourChat each need their own session. 
                If you hit the limit, delete old/unused sessions.
            </div>
    `;
    let sessionNumber = 0;
    for (const [sessionId, subs] of Object.entries(data.sessionGroups)) {
        sessionNumber++;
        const sessionName = data.sessionNames[sessionId] || `WebSocket Session ${sessionNumber}`;
        html += buildSessionGroup(sessionId, subs, sessionName, data.userId, false);
    }
    html += '</div>';
    return html;
}

// Build disabled sessions section
function buildDisabledSessionsSection(data) {
    let html = `
        <div class="box" style="border-left: 3px solid #e74c3c;">
            <h2 class="title is-4">
                <i class="fas fa-exclamation-triangle"></i> Disabled / Stale WebSocket Sessions
            </h2>
            <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: rgba(231, 76, 60, 0.3);">
                <strong><i class="fas fa-info-circle"></i> Note:</strong> These subscriptions are no longer active and can be safely deleted. 
                They do not count toward your connection or subscription limits.
            </div>
    `;
    let sessionNumber = 0;
    for (const [sessionId, subs] of Object.entries(data.sessionGroupsDisabled)) {
        sessionNumber++;
        const sessionName = data.sessionNames[sessionId] || `WebSocket Session ${sessionNumber}`;
        html += buildSessionGroup(sessionId, subs, sessionName, data.userId, true);
    }
    html += '</div>';
    return html;
}

// Build session group HTML
function buildSessionGroup(sessionId, subs, sessionName, userId, isDisabled) {
    const deleteAllButton = isDisabled ? 
        `<button class="custom-btn" onclick="deleteAllInSession('${escapeHtml(sessionId)}', ${subs.length}, '${escapeHtml(sessionName)}')" style="margin-left: 10px;">
            <i class="fas fa-trash-alt"></i> Delete All in Session
        </button>` : '';
    let html = `
        <div class="session-group">
            <div class="session-header">
                <div>
                    <strong>Session Name:</strong> <span class="session-name">${escapeHtml(sessionName)}</span>
                    <br>
                    <strong>Session ID:</strong> <span class="session-id">${escapeHtml(sessionId)}</span>
                </div>
                <div class="sub-count">
                    ${subs.length} subscriptions
                    ${deleteAllButton}
                </div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Version</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
    `;
    for (const sub of subs) {
        const conditions = [];
        for (const [key, value] of Object.entries(sub.condition)) {
            if (value === userId) {
                conditions.push(`${key}: <strong style='color: #00ff00;'>YOU</strong>`);
            } else {
                conditions.push(`${key}: ${escapeHtml(value.substring(0, 12))}`);
            }
        }
        const created = new Date(sub.created_at);
        const createdStr = created.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ', ' + 
                          created.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        const statusClass = 'status-' + sub.status.toLowerCase();
        const rowStyle = isDisabled ? 'opacity: 0.7;' : '';
        html += `
            <tr style="${rowStyle}">
                <td><span class="sub-type">${escapeHtml(sub.type)}</span></td>
                <td><span class="sub-version">v${escapeHtml(sub.version)}</span></td>
                <td style="font-size: 12px; color: #aaa;">${conditions.join('<br>')}</td>
                <td><span class="status-badge ${statusClass}">${escapeHtml(sub.status)}</span></td>
                <td style="font-size: 12px; color: #aaa;">${createdStr}</td>
                <td>
                    <button onclick="deleteSingleSubscription('${escapeHtml(sub.id)}')" class="delete-btn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </td>
            </tr>
        `;
    }
    html += `
                </tbody>
            </table>
        </div>
    `;
    return html;
}

// Build webhook section
function buildWebhookSection(data) {
    let html = `
        <div class="box">
            <h2 class="title is-4">
                <i class="fas fa-link"></i> Webhook Subscriptions
            </h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Version</th>
                        <th>Callback URL</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
    `;
    for (const sub of data.webhookSubs) {
        const statusClass = 'status-' + sub.status.toLowerCase();
        const callback = sub.transport.callback || 'N/A';
        html += `
            <tr>
                <td><span class="sub-type">${escapeHtml(sub.type)}</span></td>
                <td><span class="sub-version">v${escapeHtml(sub.version)}</span></td>
                <td style="font-size: 11px; color: #aaa; word-break: break-all;">${escapeHtml(callback)}</td>
                <td><span class="status-badge ${statusClass}">${escapeHtml(sub.status)}</span></td>
                <td>
                    <button onclick="deleteSingleSubscription('${escapeHtml(sub.id)}')" class="delete-btn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </td>
            </tr>
        `;
    }
    html += `
                </tbody>
            </table>
        </div>
    `;
    return html;
}

// Delete single subscription
async function deleteSingleSubscription(subscriptionId) {
    if (!confirm('Are you sure you want to delete this subscription?')) {
        return;
    }
    isDeleting = true;
    // Get the button that was clicked
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    try {
        const formData = new FormData();
        formData.append('action', 'delete_subscription');
        formData.append('subscription_id', subscriptionId);
        const response = await fetch('notifications_api.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showNotification(result.message || 'Successfully deleted subscription', 'success');
            await refreshSubscriptions();
        } else {
            throw new Error(result.error || 'Failed to delete subscription');
        }
    } catch (error) {
        console.error('Delete error:', error);
        showNotification('Error: ' + error.message, 'error');
        button.disabled = false;
        button.innerHTML = originalText;
    } finally {
        isDeleting = false;
    }
}

// Delete all subscriptions in a session
async function deleteAllInSession(sessionId, count, sessionName) {
    if (!confirm(`Are you sure you want to delete all ${count} subscriptions from "${sessionName}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    isDeleting = true;
    // Get the button that was clicked
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    // Collect all subscription IDs from this session
    const subscriptionIds = [];
    const tables = document.querySelectorAll('.session-group');
    tables.forEach(table => {
        const sessionIdElement = table.querySelector('.session-id');
        if (sessionIdElement && sessionIdElement.textContent === sessionId) {
            const buttons = table.querySelectorAll('button[onclick^="deleteSingleSubscription"]');
            buttons.forEach(button => {
                const match = button.getAttribute('onclick').match(/deleteSingleSubscription\('([^']+)'\)/);
                if (match && match[1]) {
                    subscriptionIds.push(match[1]);
                }
            });
        }
    });
    if (subscriptionIds.length === 0) {
        showNotification('No subscriptions found to delete', 'error');
        button.disabled = false;
        button.innerHTML = originalText;
        isDeleting = false;
        return;
    }
    try {
        const formData = new FormData();
        formData.append('action', 'delete_session');
        formData.append('subscription_ids', JSON.stringify(subscriptionIds));
        const response = await fetch('notifications_api.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showNotification(result.message || 'Successfully deleted subscriptions', 'success');
            await refreshSubscriptions();
        } else {
            throw new Error(result.error || 'Failed to delete subscriptions');
        }
    } catch (error) {
        console.error('Delete session error:', error);
        showNotification('Error: ' + error.message, 'error');
        button.disabled = false;
        button.innerHTML = originalText;
    } finally {
        isDeleting = false;
    }
}

// Clean up stale session entries from user's DB
async function cleanDatabaseSessions() {
    if (!confirm('Remove session entries from your DB that are not present in Twitch subscriptions?')) return;
    isDeleting = true;
    const button = (typeof event !== 'undefined' && event && event.target) ? event.target.closest('button') : null;
    const originalHTML = button ? button.innerHTML : null;
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning...';
    }
    try {
        const formData = new FormData();
        formData.append('action', 'cleanup_sessions');
        const response = await fetch('notifications_api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showNotification((result.deleted || 0) + ' stale session(s) removed', 'success');
            await refreshSubscriptions();
        } else {
            throw new Error(result.error || 'Cleanup failed');
        }
    } catch (err) {
        console.error('Cleanup error:', err);
        showNotification('Error: ' + err.message, 'error');
    } finally {
        if (button && originalHTML) {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
        isDeleting = false;
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize auto-refresh on page load
document.addEventListener('DOMContentLoaded', function() {
    // Start auto-refresh every 10 seconds
    autoRefreshInterval = setInterval(refreshSubscriptions, 10000);
    console.log('Auto-refresh initialized (10 seconds)');
});

// Clean up interval on page unload
window.addEventListener('beforeunload', function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
});
</script>
<?php
$content = ob_get_clean();
include "layout.php";
?>