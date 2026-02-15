<?php 
// Initialize the session
session_start();
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

// Handle subscription deletion
if (isset($_POST['delete_subscription']) && isset($_POST['subscription_id'])) {
    $subId = $_POST['subscription_id'];
    $ch = curl_init("https://api.twitch.tv/helix/eventsub/subscriptions?id=" . urlencode($subId));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-Id: ' . $clientID
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 204) {
        $deleteSuccess = "Successfully deleted subscription: " . htmlspecialchars($subId);
    } else {
        $deleteError = "Failed to delete subscription. HTTP Code: " . $httpCode;
    }
    // Redirect to prevent form resubmission
    header("Location: notifications.php");
    exit;
}

// Fetch all EventSub subscriptions
$ch = curl_init('https://api.twitch.tv/helix/eventsub/subscriptions?status=enabled');
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

// Group subscriptions by transport type and session
$websocketSubs = [];
$webhookSubs = [];
$sessionGroups = [];

foreach ($subscriptions as $sub) {
    if ($sub['transport']['method'] === 'websocket') {
        $websocketSubs[] = $sub;
        $sessionId = $sub['transport']['session_id'] ?? 'unknown';
        if (!isset($sessionGroups[$sessionId])) {
            $sessionGroups[$sessionId] = [];
        }
        $sessionGroups[$sessionId][] = $sub;
    } else {
        $webhookSubs[] = $sub;
    }
}

// Start output buffering
ob_start();
?>
<div class="section">
    <div class="container">
        <h1 class="title is-2">
            <i class="fas fa-bell"></i> EventSub Notifications
        </h1>
        <p class="subtitle">Monitor and manage your Twitch EventSub subscriptions</p>
        <?php if ($error): ?>
            <div class="error-box">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($deleteSuccess)): ?>
            <div class="info-box">
                <strong>Success:</strong> <?php echo $deleteSuccess; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($deleteError)): ?>
            <div class="error-box">
                <strong>Error:</strong> <?php echo $deleteError; ?>
            </div>
        <?php endif; ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Subscriptions</div>
                <div class="stat-value"><?php echo $totalCount; ?></div>
                <div class="stat-secondary">across all transports</div>
            </div>
            <div class="stat-card <?php echo count($websocketSubs) > 10 ? 'warning-card' : ''; ?>">
                <div class="stat-label">WebSocket Subscriptions</div>
                <div class="stat-value"><?php echo count($websocketSubs); ?></div>
                <div class="stat-secondary"><?php echo count($sessionGroups); ?> active sessions (limit: 3)</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Webhook Subscriptions</div>
                <div class="stat-value"><?php echo count($webhookSubs); ?></div>
                <div class="stat-secondary">callback-based</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Cost Usage</div>
                <div class="stat-value"><?php echo $totalCost; ?></div>
                <div class="stat-secondary">of <?php echo $maxCost; ?> max</div>
            </div>
        </div>
        <?php if (count($sessionGroups) > 0): ?>
            <div class="box">
                <h2 class="title is-4">
                    <i class="fas fa-network-wired"></i> WebSocket Sessions
                    <a href="notifications.php" class="refresh-btn" style="margin-left: auto; float: right;">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </a>
                </h2>
                <div class="info-box">
                    <strong><i class="fas fa-info-circle"></i> Tip:</strong> Twitch limits you to 3 WebSocket connections. 
                    Each session below counts toward that limit. Your bot and YourChat each need their own session. 
                    If you hit the limit, delete old/unused sessions.
                </div>
                <?php 
                $sessionNumber = 0;
                foreach ($sessionGroups as $sessionId => $subs): 
                    $sessionNumber++;
                    $sessionName = "WebSocket Session " . $sessionNumber;
                ?>
                    <div class="session-group">
                        <div class="session-header">
                            <div>
                                <strong>Session Name:</strong> <span class="session-name"><?php echo htmlspecialchars($sessionName); ?></span>
                                <br>
                                <strong>Session ID:</strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                            </div>
                            <div class="sub-count"><?php echo count($subs); ?> subscriptions</div>
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
                                <?php foreach ($subs as $sub): ?>
                                    <tr>
                                        <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                                        <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                                        <td style="font-size: 12px; color: #aaa;">
                                            <?php 
                                            $conditions = [];
                                            foreach ($sub['condition'] as $key => $value) {
                                                if ($value === $userId) {
                                                    $conditions[] = "$key: <strong style='color: #00ff00;'>YOU</strong>";
                                                } else {
                                                    $conditions[] = "$key: " . htmlspecialchars(substr($value, 0, 12));
                                                }
                                            }
                                            echo implode('<br>', $conditions);
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-enabled"><?php echo htmlspecialchars($sub['status']); ?></span>
                                        </td>
                                        <td style="font-size: 12px; color: #aaa;">
                                            <?php 
                                            $created = new DateTime($sub['created_at']);
                                            echo $created->format('M d, H:i:s');
                                            ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subscription?');">
                                                <input type="hidden" name="subscription_id" value="<?php echo htmlspecialchars($sub['id']); ?>">
                                                <button type="submit" name="delete_subscription" class="delete-btn">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (count($webhookSubs) > 0): ?>
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
                        <?php foreach ($webhookSubs as $sub): ?>
                            <tr>
                                <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                                <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                                <td style="font-size: 11px; color: #aaa; word-break: break-all;">
                                    <?php echo htmlspecialchars($sub['transport']['callback'] ?? 'N/A'); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-enabled"><?php echo htmlspecialchars($sub['status']); ?></span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subscription?');">
                                        <input type="hidden" name="subscription_id" value="<?php echo htmlspecialchars($sub['id']); ?>">
                                        <button type="submit" name="delete_subscription" class="delete-btn">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (count($sessionGroups) === 0 && count($webhookSubs) === 0): ?>
            <div class="box has-text-centered">
                <p class="subtitle">
                    <i class="fas fa-inbox"></i><br>
                    No EventSub subscriptions found.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include "layout.php";
?>