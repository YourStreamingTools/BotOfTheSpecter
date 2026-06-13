<?php 
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = 'EventSub Notifications';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/bot_control.php';
include "includes/mod_access.php";
include 'includes/user_db.php';
include 'includes/storage_used.php';
session_write_close();

// Get access token from session
$accessToken = $_SESSION['access_token'];
$userId = $_SESSION['user_id'];

// Webhook subs require an app access token.
// $clientID and $clientSecret are already set by twitch.php (sourced from the database).
$_appToken = null;
if (!empty($clientID) && !empty($clientSecret)) {
    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $clientID, 'client_secret' => $clientSecret, 'grant_type' => 'client_credentials'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $_tr = curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $_td = json_decode($_tr, true);
        $_appToken = $_td['access_token'] ?? null;
    }
}

// Fetch WebSocket subscriptions with user access token
$ch = curl_init('https://api.twitch.tv/helix/eventsub/subscriptions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Client-Id: ' . $clientID]);
$wsResponse = curl_exec($ch);
$wsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
// Fetch webhook subscriptions with app access token
$_webhookSubs = [];
if ($_appToken) {
    $ch = curl_init('https://api.twitch.tv/helix/eventsub/subscriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $_appToken, 'Client-Id: ' . $clientID]);
    $_whResp = curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $_whData = json_decode($_whResp, true);
        foreach ($_whData['data'] ?? [] as $_s) {
            if (($_s['transport']['method'] ?? '') === 'webhook') $_webhookSubs[] = $_s;
        }
    }
}

$subscriptions = [];
$error = null;
$totalCount = 0;
$maxTotal = 0;
$totalCost = 0;
$maxCost = 0;

if ($wsHttpCode === 200) {
    $data = json_decode($wsResponse, true);
    $_wsSubs = array_values(array_filter($data['data'] ?? [], fn($s) => ($s['transport']['method'] ?? '') === 'websocket'));
    $subscriptions = array_merge($_wsSubs, $_webhookSubs);
    $totalCount = ($data['total'] ?? 0) + count($_webhookSubs);
    $maxTotal = $data['max_total_cost'] ?? 0;
    $totalCost = ($data['total_cost'] ?? 0) + array_sum(array_column($_webhookSubs, 'cost'));
    $maxCost = $data['max_total_cost'] ?? 0;
} else {
    $error = t('notifications_error_fetch_failed', [$wsHttpCode]);
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
<h1 style="font-size:1.75rem; font-weight:700; color:var(--text-primary); margin-bottom:0.5rem;">
    <i class="fas fa-bell"></i> <?= t('notifications_page_title') ?>
</h1>
<p style="color:var(--text-secondary); margin-bottom:1rem;"><?= t('notifications_page_subtitle') ?> <span id="auto-refresh-indicator" style="font-size:12px; color:var(--text-muted);"><?= t('notifications_auto_refresh_indicator') ?></span></p>
<div style="margin-bottom:0.75rem;">
    <button id="refresh-my-ws-btn" class="sp-btn sp-btn-sm" onclick="refreshInternalWebsocket(this)">
        <i class="fas fa-plug"></i>&nbsp; <?= t('notifications_refresh_internal_websocket_btn') ?>
    </button>
</div>
<div id="notification-messages">
    <?php if ($error): ?>
        <div class="error-box">
            <strong><?= t('notifications_error_label') ?></strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
</div>
<div id="subscription-content">
    <?php include 'includes/notifications_content.php'; ?>
</div>
<div class="sp-card" id="internal-ws-box" style="margin-top:1rem;">
    <div class="sp-card-body">
    <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.5rem;"><i class="fas fa-plug"></i> <?= t('notifications_internal_ws_heading') ?></h2>
    <p style="margin-bottom:0.75rem;"><?= t('notifications_internal_ws_description') ?></p>
    <div id="internal-ws-summary" class="info-box"><?= t('notifications_internal_ws_loading_status') ?></div>
    <div class="sp-table-wrap" style="margin-top:0.625rem;">
        <table class="data-table sp-table" id="internal-ws-table">
            <thead>
                <tr>
                    <th><?= t('notifications_th_client_name') ?></th>
                    <th><?= t('notifications_th_socket_id') ?></th>
                    <th><?= t('notifications_th_admin') ?></th>
                    <th><?= t('notifications_th_actions') ?></th>
                </tr>
            </thead>
            <tbody id="internal-ws-tbody">
                <tr><td colspan="4" style="text-align:center;"><?= t('notifications_loading') ?></td></tr>
            </tbody>
        </table>
    </div>
    </div>
</div>
<script>
const NOTIF_I18N = {
    success: <?php echo json_encode(t('notifications_js_success')); ?>,
    error: <?php echo json_encode(t('notifications_js_error')); ?>,
    refreshing: <?php echo json_encode(t('notifications_js_refreshing')); ?>,
    lastUpdated: <?php echo json_encode(t('notifications_js_last_updated')); ?>,
    failedRefresh: <?php echo json_encode(t('notifications_js_failed_refresh')); ?>,
    failedFetchSubscriptions: <?php echo json_encode(t('notifications_js_failed_fetch_subscriptions')); ?>,
    unknownError: <?php echo json_encode(t('notifications_js_unknown_error')); ?>,
    emptyNoSubscriptions: <?php echo json_encode(t('notifications_content_empty_no_subscriptions')); ?>,
    connectionLine: <?php echo json_encode(t('notifications_js_connection_line')); ?>,
    disabledStaleLine: <?php echo json_encode(t('notifications_js_disabled_stale_line')); ?>,
    statTotalSubscriptions: <?php echo json_encode(t('notifications_content_stat_total_subscriptions')); ?>,
    statAcrossAllTransports: <?php echo json_encode(t('notifications_content_stat_across_all_transports')); ?>,
    statActiveWebsocketSubs: <?php echo json_encode(t('notifications_content_stat_active_websocket_subs')); ?>,
    statLimit300: <?php echo json_encode(t('notifications_content_stat_limit_300_per_connection')); ?>,
    statActiveConnections: <?php echo json_encode(t('notifications_content_stat_active_connections')); ?>,
    statLimit3: <?php echo json_encode(t('notifications_content_stat_limit_3_connections')); ?>,
    statWebhookSubscriptions: <?php echo json_encode(t('notifications_content_stat_webhook_subscriptions')); ?>,
    statCallbackBased: <?php echo json_encode(t('notifications_content_stat_callback_based')); ?>,
    statCostUsage: <?php echo json_encode(t('notifications_content_stat_cost_usage')); ?>,
    statOfMax: <?php echo json_encode(t('notifications_js_stat_of_max')); ?>,
    headingActiveSessions: <?php echo json_encode(t('notifications_content_heading_active_sessions')); ?>,
    infoboxActiveSessions: <?php echo json_encode(t('notifications_content_infobox_active_sessions')); ?>,
    headingDisabledSessions: <?php echo json_encode(t('notifications_content_heading_disabled_sessions')); ?>,
    infoboxDisabledSessions: <?php echo json_encode(t('notifications_content_infobox_disabled_sessions')); ?>,
    websocketSessionLabel: <?php echo json_encode(t('notifications_js_websocket_session_label')); ?>,
    btnDeleteAllInSession: <?php echo json_encode(t('notifications_content_btn_delete_all_in_session')); ?>,
    labelSessionName: <?php echo json_encode(t('notifications_content_label_session_name')); ?>,
    labelSessionId: <?php echo json_encode(t('notifications_content_label_session_id')); ?>,
    subscriptionsCount: <?php echo json_encode(t('notifications_js_subscriptions_count')); ?>,
    thType: <?php echo json_encode(t('notifications_content_th_type')); ?>,
    thVersion: <?php echo json_encode(t('notifications_content_th_version')); ?>,
    thCondition: <?php echo json_encode(t('notifications_content_th_condition')); ?>,
    thStatus: <?php echo json_encode(t('notifications_content_th_status')); ?>,
    thCreated: <?php echo json_encode(t('notifications_content_th_created')); ?>,
    thAction: <?php echo json_encode(t('notifications_content_th_action')); ?>,
    thCallbackUrl: <?php echo json_encode(t('notifications_content_th_callback_url')); ?>,
    conditionYou: <?php echo json_encode(t('notifications_content_condition_you')); ?>,
    btnDelete: <?php echo json_encode(t('notifications_content_btn_delete')); ?>,
    headingWebhookSubscriptions: <?php echo json_encode(t('notifications_content_heading_webhook_subscriptions')); ?>,
    notAvailable: <?php echo json_encode(t('notifications_js_not_available')); ?>,
    confirmDeleteSingle: <?php echo json_encode(t('notifications_js_confirm_delete_single')); ?>,
    deleting: <?php echo json_encode(t('notifications_js_deleting')); ?>,
    deletedSingle: <?php echo json_encode(t('notifications_js_deleted_single')); ?>,
    failedDeleteSingle: <?php echo json_encode(t('notifications_js_failed_delete_single')); ?>,
    confirmDeleteAll: <?php echo json_encode(t('notifications_js_confirm_delete_all')); ?>,
    noSubscriptionsToDelete: <?php echo json_encode(t('notifications_js_no_subscriptions_to_delete')); ?>,
    deletedMultiple: <?php echo json_encode(t('notifications_js_deleted_multiple')); ?>,
    failedDeleteMultiple: <?php echo json_encode(t('notifications_js_failed_delete_multiple')); ?>,
    staleSessionsRemoved: <?php echo json_encode(t('notifications_js_stale_sessions_removed')); ?>,
    internalWsSummary: <?php echo json_encode(t('notifications_js_internal_ws_summary')); ?>,
    noActiveClients: <?php echo json_encode(t('notifications_js_no_active_clients')); ?>,
    unknownClient: <?php echo json_encode(t('notifications_js_unknown_client')); ?>,
    badgeAdmin: <?php echo json_encode(t('notifications_js_badge_admin')); ?>,
    badgeUser: <?php echo json_encode(t('notifications_js_badge_user')); ?>,
    btnDisconnect: <?php echo json_encode(t('notifications_js_btn_disconnect')); ?>,
    failedLoadInternalWs: <?php echo json_encode(t('notifications_js_failed_load_internal_ws')); ?>,
    unableToLoadData: <?php echo json_encode(t('notifications_js_unable_to_load_data')); ?>,
    confirmDisconnect: <?php echo json_encode(t('notifications_js_confirm_disconnect')); ?>,
    disconnecting: <?php echo json_encode(t('notifications_js_disconnecting')); ?>,
    failedDisconnect: <?php echo json_encode(t('notifications_js_failed_disconnect')); ?>
};

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
    div.innerHTML = `<strong>${type === 'success' ? NOTIF_I18N.success : NOTIF_I18N.error}:</strong> ${message}`;
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
            button.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> ' + NOTIF_I18N.refreshing;
        }
    }
    try {
        const response = await fetch('/api/notifications_api.php?action=fetch_subscriptions');
        if (!response.ok) {
            throw new Error(NOTIF_I18N.failedFetchSubscriptions);
        }
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || NOTIF_I18N.unknownError);
        }
        // Render the content using the API data
        renderSubscriptions(result.data);
        await refreshInternalWebsocket();
        // Update indicator
        const indicator = document.getElementById('auto-refresh-indicator');
        if (indicator) {
            indicator.textContent = NOTIF_I18N.lastUpdated.replace(':time', new Date().toLocaleTimeString());
        }
    } catch (error) {
        console.error('Refresh error:', error);
        showNotification(NOTIF_I18N.failedRefresh + error.message, 'error');
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
            <div class="sp-card" style="text-align:center; padding:2rem;">
                <p style="color:var(--text-secondary); font-size:1rem;">
                    <i class="fas fa-inbox"></i><br>
                    ${NOTIF_I18N.emptyNoSubscriptions}
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
        let textColor = 'var(--text-primary)';
        if (count >= 250) textColor = '#e74c3c';
        else if (count >= 150) textColor = '#f39c12';
        connectionDetails += `<div class="stat-secondary" style="color: ${textColor}; margin-top: 4px;">${NOTIF_I18N.connectionLine.replace(':number', connectionNumber).replace(':count', count)}</div>`;
    }
    if (data.websocketSubsDisabled.length > 0) {
        connectionDetails += `<div class="stat-secondary" style="color: #e74c3c; margin-top: 4px;">${NOTIF_I18N.disabledStaleLine.replace(':count', data.websocketSubsDisabled.length)}</div>`;
    }
    return `
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">${NOTIF_I18N.statTotalSubscriptions}</div>
                <div class="stat-value">${data.totalCount}</div>
                <div class="stat-secondary">${NOTIF_I18N.statAcrossAllTransports}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">${NOTIF_I18N.statActiveWebsocketSubs}</div>
                <div class="stat-value">${subCount}</div>
                <div class="stat-secondary">${NOTIF_I18N.statLimit300}</div>
                ${connectionDetails}
            </div>
            <div class="stat-card ${sessionColorClass}">
                <div class="stat-label">${NOTIF_I18N.statActiveConnections}</div>
                <div class="stat-value">${sessionCount}</div>
                <div class="stat-secondary">${NOTIF_I18N.statLimit3}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">${NOTIF_I18N.statWebhookSubscriptions}</div>
                <div class="stat-value">${data.webhookSubs.length}</div>
                <div class="stat-secondary">${NOTIF_I18N.statCallbackBased}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">${NOTIF_I18N.statCostUsage}</div>
                <div class="stat-value">${data.totalCost}</div>
                <div class="stat-secondary">${NOTIF_I18N.statOfMax.replace(':max', data.maxCost)}</div>
            </div>
        </div>
    `;
}

// Build active sessions section
function buildActiveSessionsSection(data) {
    let html = `
        <div class="sp-card"><div class="sp-card-body">
            <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
                <i class="fas fa-network-wired"></i> ${NOTIF_I18N.headingActiveSessions}
            </h2>
            <div class="info-box">
                ${NOTIF_I18N.infoboxActiveSessions}
            </div>
    `;
    let sessionNumber = 0;
    for (const [sessionId, subs] of Object.entries(data.sessionGroups)) {
        sessionNumber++;
        const sessionName = data.sessionNames[sessionId] || NOTIF_I18N.websocketSessionLabel.replace(':number', sessionNumber);
        html += buildSessionGroup(sessionId, subs, sessionName, data.userId, false);
    }
    html += '</div></div>';
    return html;
}

// Build disabled sessions section
function buildDisabledSessionsSection(data) {
    let html = `
        <div class="sp-card" style="border-left:3px solid var(--red);"><div class="sp-card-body">
            <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
                <i class="fas fa-exclamation-triangle"></i> ${NOTIF_I18N.headingDisabledSessions}
            </h2>
            <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: rgba(231, 76, 60, 0.3);">
                ${NOTIF_I18N.infoboxDisabledSessions}
            </div>
    `;
    let sessionNumber = 0;
    for (const [sessionId, subs] of Object.entries(data.sessionGroupsDisabled)) {
        sessionNumber++;
        const sessionName = data.sessionNames[sessionId] || NOTIF_I18N.websocketSessionLabel.replace(':number', sessionNumber);
        html += buildSessionGroup(sessionId, subs, sessionName, data.userId, true);
    }
    html += '</div></div>';
    return html;
}

// Build session group HTML
function buildSessionGroup(sessionId, subs, sessionName, userId, isDisabled) {
    const deleteAllButton = isDisabled ? 
        `<button class="custom-btn" onclick="deleteAllInSession('${escapeHtml(sessionId)}', ${subs.length}, '${escapeHtml(sessionName)}')" style="margin-left: 10px;">
            <i class="fas fa-trash-alt"></i> ${NOTIF_I18N.btnDeleteAllInSession}
        </button>` : '';
    let html = `
        <div class="session-group">
            <div class="session-header">
                <div>
                    <strong>${NOTIF_I18N.labelSessionName}</strong> <span class="session-name">${escapeHtml(sessionName)}</span>
                    <br>
                    <strong>${NOTIF_I18N.labelSessionId}</strong> <span class="session-id">${escapeHtml(sessionId)}</span>
                </div>
                <div class="sub-count">
                    ${NOTIF_I18N.subscriptionsCount.replace(':count', subs.length)}
                    ${deleteAllButton}
                </div>
            </div>
            <table class="data-table sp-table">
                <thead>
                    <tr>
                        <th>${NOTIF_I18N.thType}</th>
                        <th>${NOTIF_I18N.thVersion}</th>
                        <th>${NOTIF_I18N.thCondition}</th>
                        <th>${NOTIF_I18N.thStatus}</th>
                        <th>${NOTIF_I18N.thCreated}</th>
                        <th>${NOTIF_I18N.thAction}</th>
                    </tr>
                </thead>
                <tbody>
    `;
    for (const sub of subs) {
        const conditions = [];
        for (const [key, value] of Object.entries(sub.condition)) {
            if (value === userId) {
                conditions.push(`${key}: <strong style='color: #00ff00;'>${NOTIF_I18N.conditionYou}</strong>`);
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
                <td style="font-size: 12px; color: var(--text-muted);">${conditions.join('<br>')}</td>
                <td><span class="status-badge ${statusClass}">${escapeHtml(sub.status)}</span></td>
                <td style="font-size: 12px; color: var(--text-muted);">${createdStr}</td>
                <td>
                    <button onclick="deleteSingleSubscription('${escapeHtml(sub.id)}')" class="delete-btn">
                        <i class="fas fa-trash"></i> ${NOTIF_I18N.btnDelete}
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
        <div class="sp-card"><div class="sp-card-body">
            <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
                <i class="fas fa-link"></i> ${NOTIF_I18N.headingWebhookSubscriptions}
            </h2>
            <table class="data-table sp-table">
                <thead>
                    <tr>
                        <th>${NOTIF_I18N.thType}</th>
                        <th>${NOTIF_I18N.thVersion}</th>
                        <th>${NOTIF_I18N.thCallbackUrl}</th>
                        <th>${NOTIF_I18N.thStatus}</th>
                        <th>${NOTIF_I18N.thAction}</th>
                    </tr>
                </thead>
                <tbody>
    `;
    for (const sub of data.webhookSubs) {
        const statusClass = 'status-' + sub.status.toLowerCase();
        const callback = sub.transport.callback || NOTIF_I18N.notAvailable;
        html += `
            <tr>
                <td><span class="sub-type">${escapeHtml(sub.type)}</span></td>
                <td><span class="sub-version">v${escapeHtml(sub.version)}</span></td>
                <td style="font-size: 11px; color: var(--text-muted); word-break: break-all;">${escapeHtml(callback)}</td>
                <td><span class="status-badge ${statusClass}">${escapeHtml(sub.status)}</span></td>
                <td>
                    <button onclick="deleteSingleSubscription('${escapeHtml(sub.id)}', 'webhook')" class="delete-btn">
                        <i class="fas fa-trash"></i> ${NOTIF_I18N.btnDelete}
                    </button>
                </td>
            </tr>
        `;
    }
    html += `
                </tbody>
            </table>
        </div></div>
    `;
    return html;
}

// Delete single subscription
async function deleteSingleSubscription(subscriptionId, transport = 'websocket') {
    if (!confirm(NOTIF_I18N.confirmDeleteSingle)) {
        return;
    }
    isDeleting = true;
    // Get the button that was clicked
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + NOTIF_I18N.deleting;
    try {
        const formData = new FormData();
        formData.append('action', 'delete_subscription');
        formData.append('subscription_id', subscriptionId);
        formData.append('transport', transport);
        const response = await fetch('/api/notifications_api.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showNotification(result.message || NOTIF_I18N.deletedSingle, 'success');
            await refreshSubscriptions();
        } else {
            throw new Error(result.error || NOTIF_I18N.failedDeleteSingle);
        }
    } catch (error) {
        console.error('Delete error:', error);
        showNotification(NOTIF_I18N.error + ': ' + error.message, 'error');
        button.disabled = false;
        button.innerHTML = originalText;
    } finally {
        isDeleting = false;
    }
}

// Delete all subscriptions in a session
async function deleteAllInSession(sessionId, count, sessionName) {
    if (!confirm(NOTIF_I18N.confirmDeleteAll.replace(':count', count).replace(':name', sessionName))) {
        return;
    }
    isDeleting = true;
    // Get the button that was clicked
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + NOTIF_I18N.deleting;
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
        showNotification(NOTIF_I18N.noSubscriptionsToDelete, 'error');
        button.disabled = false;
        button.innerHTML = originalText;
        isDeleting = false;
        return;
    }
    try {
        const formData = new FormData();
        formData.append('action', 'delete_session');
        formData.append('subscription_ids', JSON.stringify(subscriptionIds));
        const response = await fetch('/api/notifications_api.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showNotification(result.message || NOTIF_I18N.deletedMultiple, 'success');
            await refreshSubscriptions();
        } else {
            throw new Error(result.error || NOTIF_I18N.failedDeleteMultiple);
        }
    } catch (error) {
        console.error('Delete session error:', error);
        showNotification(NOTIF_I18N.error + ': ' + error.message, 'error');
        button.disabled = false;
        button.innerHTML = originalText;
    } finally {
        isDeleting = false;
    }
}

// Background cleanup of stale session entries from user's DB (no UI confirmation)
async function autoCleanupSessions() {
    if (isDeleting) {
        console.log('Skipping cleanup while another operation is running');
        return;
    }
    isDeleting = true;

    try {
        const formData = new FormData();
        formData.append('action', 'cleanup_sessions');
        const response = await fetch('/api/notifications_api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            if (result.deleted && result.deleted > 0) {
                console.log('Auto-cleanup removed', result.deleted, 'stale sessions');
                showNotification(NOTIF_I18N.staleSessionsRemoved.replace(':count', (result.deleted || 0)), 'success');
                await refreshSubscriptions();
            } else {
                console.log('Auto-cleanup: no stale sessions to remove');
            }
        } else {
            console.warn('Auto-cleanup failed:', result.error || 'unknown');
        }
    } catch (err) {
        console.error('Auto-cleanup error:', err);
    } finally {
        isDeleting = false;
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function refreshInternalWebsocket(button = null) {
    const summary = document.getElementById('internal-ws-summary');
    const tbody = document.getElementById('internal-ws-tbody');
    if (!summary || !tbody) return;

    let originalButtonHtml = null;
    if (button) {
        originalButtonHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> ' + NOTIF_I18N.refreshing;
    }

    try {
        const res = await fetch('/api/notifications_api.php?action=fetch_internal_websocket', { credentials: 'same-origin' });
        if (!res.ok) throw new Error('Failed to fetch');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'No data');
        const clientsRaw = (data.data && data.data.clients) ? data.data.clients : [];
        const clients = Array.isArray(clientsRaw) ? clientsRaw : Object.values(clientsRaw);
        const clientCount = (data.data && typeof data.data.clientCount === 'number') ? data.data.clientCount : clients.length;

        summary.innerHTML = NOTIF_I18N.internalWsSummary.replace(':count', `<strong>${clientCount}</strong>`);

        if (clients.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">' + escapeHtml(NOTIF_I18N.noActiveClients) + '</td></tr>';
            return;
        }

        let html = '';
        clients.forEach(c => {
            const sidRaw = c.sid || c.id || c.connectionId || '';
            const name = escapeHtml(c.name || c.client_name || c.clientName || NOTIF_I18N.unknownClient);
            const sid = escapeHtml(sidRaw || NOTIF_I18N.notAvailable);
            const isAdmin = !!(c.is_admin || c.isAdmin || c.admin);
            const adminBadge = isAdmin
                ? `<span class="sp-badge sp-badge-red">${escapeHtml(NOTIF_I18N.badgeAdmin)}</span>`
                : `<span class="sp-badge sp-badge-blue">${escapeHtml(NOTIF_I18N.badgeUser)}</span>`;

            html += `<tr><td>${name}</td><td><code>${sid}</code></td><td>${adminBadge}</td><td><button class="sp-btn sp-btn-danger sp-btn-sm" onclick='disconnectWs(${JSON.stringify(sidRaw)}, this)'><i class="fas fa-times"></i> ${escapeHtml(NOTIF_I18N.btnDisconnect)}</button></td></tr>`;
        });
        tbody.innerHTML = html;
    } catch (err) {
        console.error('refreshInternalWebsocket error', err);
        summary.innerHTML = `<span style="color:#e74c3c;">${escapeHtml(NOTIF_I18N.failedLoadInternalWs)} ${escapeHtml(err.message || String(err))}</span>`;
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">' + escapeHtml(NOTIF_I18N.unableToLoadData) + '</td></tr>';
    } finally {
        if (button) {
            button.disabled = false;
            button.innerHTML = originalButtonHtml;
        }
    }
}

async function disconnectWs(sid, btn) {
    if (!confirm(NOTIF_I18N.confirmDisconnect)) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + NOTIF_I18N.disconnecting; }
    try {
        const form = new FormData();
        form.append('disconnect_client', '1');
        form.append('sid', sid);
        form.append('action', 'disconnect_internal_websocket');
        const res = await fetch('/api/notifications_api.php', { method: 'POST', body: form, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        // refresh list
        await refreshInternalWebsocket();
    } catch (err) {
        console.error('disconnectWs error', err);
        alert(NOTIF_I18N.failedDisconnect + (err.message || err));
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-times"></i> ' + NOTIF_I18N.btnDisconnect; }
    }
}

// Initialize auto-refresh on page load
document.addEventListener('DOMContentLoaded', function() {
    // Start auto-refresh every 10 seconds
    autoRefreshInterval = setInterval(refreshSubscriptions, 10000);
    console.log('Auto-refresh initialized (10 seconds)');

    // Load internal websocket status immediately
    refreshInternalWebsocket();

    // Run an immediate cleanup shortly after load, then every 5 minutes
    setTimeout(() => {
        autoCleanupSessions();
    }, 2000);
    setInterval(autoCleanupSessions, 5 * 60 * 1000); // 5 minutes
    console.log('Auto-cleanup scheduled (every 5 minutes)');
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
