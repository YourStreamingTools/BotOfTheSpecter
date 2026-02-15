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

// Handle bulk session deletion
if (isset($_POST['delete_session']) && isset($_POST['subscription_ids'])) {
    $subIds = json_decode($_POST['subscription_ids'], true);
    if (is_array($subIds) && count($subIds) > 0) {
        $deletedCount = 0;
        $failedCount = 0;
        foreach ($subIds as $subId) {
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
                $deletedCount++;
            } else {
                $failedCount++;
            }
            // Small delay to avoid rate limiting
            usleep(100000); // 0.1 seconds
        }
        if ($deletedCount > 0 && $failedCount === 0) {
            $deleteSuccess = "Successfully deleted {$deletedCount} subscription(s) from session.";
        } elseif ($deletedCount > 0 && $failedCount > 0) {
            $deleteSuccess = "Deleted {$deletedCount} subscription(s), but {$failedCount} failed.";
        } else {
            $deleteError = "Failed to delete subscriptions from session.";
        }
    }
    // Redirect to prevent form resubmission
    header("Location: notifications.php");
    exit;
}

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
            <?php
            // Determine color class for Active WebSocket Subscriptions
            $subCount = count($websocketSubsEnabled);
            $subColorClass = '';
            if ($subCount >= 251) {
                $subColorClass = 'danger-card';
            } elseif ($subCount >= 151) {
                $subColorClass = 'warning-card';
            }
            ?>
            <div class="stat-card <?php echo $subColorClass; ?>">
                <div class="stat-label">Active WebSocket Subscriptions</div>
                <div class="stat-value"><?php echo $subCount; ?></div>
                <div class="stat-secondary">limit: 300 per connection</div>
                <?php if (count($websocketSubsDisabled) > 0): ?>
                    <div class="stat-secondary" style="color: #e74c3c; margin-top: 4px;">
                        <?php echo count($websocketSubsDisabled); ?> disabled/stale
                    </div>
                <?php endif; ?>
            </div>
            <?php
            // Determine color class for Active Sessions
            $sessionCount = count($sessionGroups);
            $sessionColorClass = '';
            if ($sessionCount >= 3) {
                $sessionColorClass = 'danger-card';
            } elseif ($sessionCount >= 2) {
                $sessionColorClass = 'warning-card';
            }
            ?>
            <div class="stat-card <?php echo $sessionColorClass; ?>">
                <div class="stat-label">Active Connections</div>
                <div class="stat-value"><?php echo $sessionCount; ?></div>
                <div class="stat-secondary">limit: 3 connections</div>
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
                    <i class="fas fa-network-wired"></i> Active WebSocket Sessions
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
                    // Check if we have a name for this session in the database
                    if (isset($sessionNames[$sessionId]) && !empty($sessionNames[$sessionId])) {
                        $sessionName = $sessionNames[$sessionId];
                    } else {
                        // Fall back to numbered format if no name found
                        $sessionName = "WebSocket Session " . $sessionNumber;
                    }
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
                                            <?php 
                                            $status = $sub['status'];
                                            $statusClass = 'status-' . strtolower($status);
                                            ?>
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
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
        <?php if (count($sessionGroupsDisabled) > 0): ?>
            <div class="box" style="border-left: 3px solid #e74c3c;">
                <h2 class="title is-4">
                    <i class="fas fa-exclamation-triangle"></i> Disabled / Stale WebSocket Sessions
                </h2>
                <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: rgba(231, 76, 60, 0.3);">
                    <strong><i class="fas fa-info-circle"></i> Note:</strong> These subscriptions are no longer active and can be safely deleted. 
                    They do not count toward your connection or subscription limits.
                </div>
                <?php 
                $sessionNumber = 0;
                foreach ($sessionGroupsDisabled as $sessionId => $subs): 
                    $sessionNumber++;
                    // Check if we have a name for this session in the database
                    if (isset($sessionNames[$sessionId]) && !empty($sessionNames[$sessionId])) {
                        $sessionName = $sessionNames[$sessionId];
                    } else {
                        // Fall back to numbered format if no name found
                        $sessionName = "WebSocket Session " . $sessionNumber;
                    }
                ?>
                    <div class="session-group">
                        <div class="session-header">
                            <div>
                                <strong>Session Name:</strong> <span class="session-name"><?php echo htmlspecialchars($sessionName); ?></span>
                                <br>
                                <strong>Session ID:</strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                            </div>
                            <div class="sub-count">
                                <?php echo count($subs); ?> subscriptions
                                <button class="custom-btn" onclick="deleteAllInSession('<?php echo htmlspecialchars($sessionId, ENT_QUOTES, 'UTF-8'); ?>', <?php echo count($subs); ?>, '<?php echo htmlspecialchars($sessionName, ENT_QUOTES, 'UTF-8'); ?>')" style="margin-left: 10px;">
                                    <i class="fas fa-trash-alt"></i> Delete All in Session
                                </button>
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
                                <?php foreach ($subs as $sub): ?>
                                    <tr style="opacity: 0.7;">
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
                                            <?php 
                                            $status = $sub['status'];
                                            $statusClass = 'status-' . strtolower($status);
                                            ?>
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
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
                                    <?php 
                                    $status = $sub['status'];
                                    $statusClass = 'status-' . strtolower($status);
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
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

<script>
function deleteAllInSession(sessionId, count, sessionName) {
    if (!confirm(`Are you sure you want to delete all ${count} subscriptions from "${sessionName}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    // Collect all subscription IDs from this session
    const subscriptionIds = [];
    const tables = document.querySelectorAll('.session-group');
    
    tables.forEach(table => {
        const sessionIdElement = table.querySelector('.session-id');
        if (sessionIdElement && sessionIdElement.textContent === sessionId) {
            const forms = table.querySelectorAll('form[method="POST"]');
            forms.forEach(form => {
                const subIdInput = form.querySelector('input[name="subscription_id"]');
                if (subIdInput) {
                    subscriptionIds.push(subIdInput.value);
                }
            });
        }
    });
    
    if (subscriptionIds.length === 0) {
        alert('No subscriptions found to delete.');
        return;
    }
    
    // Show loading state
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    
    // Send POST request
    const formData = new FormData();
    formData.append('delete_session', '1');
    formData.append('subscription_ids', JSON.stringify(subscriptionIds));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            window.location.reload();
        } else {
            throw new Error('Failed to delete subscriptions');
        }
    })
    .catch(error => {
        alert('Error deleting subscriptions: ' + error.message);
        button.disabled = false;
        button.innerHTML = originalText;
    });
}
</script>

<?php
$content = ob_get_clean();
include "layout.php";
?>