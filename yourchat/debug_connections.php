<?php
require_once "/var/www/config/twitch.php";

session_start();

// Security: Only allow authenticated users
if (!isset($_SESSION['access_token']) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Unauthorized. Please log in to YourChat first.');
}

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
    header("Location: debug_connections.php");
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventSub Connections Debug - YourChat</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        header {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px 30px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 10px;
            color: #9147ff;
        }
        
        .subtitle {
            color: #aaa;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-label {
            color: #aaa;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #9147ff;
        }
        
        .stat-secondary {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .warning {
            background: rgba(255, 68, 68, 0.1);
            border: 1px solid rgba(255, 68, 68, 0.3);
            color: #ff4444;
        }
        
        .warning .stat-value {
            color: #ff4444;
        }
        
        .section {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #9147ff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .session-group {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #9147ff;
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .session-id {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #aaa;
            background: rgba(255, 255, 255, 0.05);
            padding: 5px 10px;
            border-radius: 4px;
        }
        
        .sub-count {
            background: #9147ff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        th {
            color: #9147ff;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        td {
            font-size: 14px;
        }
        
        .sub-type {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #00b4d8;
        }
        
        .sub-version {
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #aaa;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-enabled {
            background: rgba(0, 200, 0, 0.2);
            color: #00ff00;
        }
        
        .delete-btn {
            background: rgba(255, 68, 68, 0.2);
            color: #ff4444;
            border: 1px solid rgba(255, 68, 68, 0.3);
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        
        .delete-btn:hover {
            background: rgba(255, 68, 68, 0.4);
            transform: translateY(-1px);
        }
        
        .back-link {
            display: inline-block;
            background: rgba(145, 71, 255, 0.2);
            color: #9147ff;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid rgba(145, 71, 255, 0.3);
        }
        
        .back-link:hover {
            background: rgba(145, 71, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .error {
            background: rgba(255, 68, 68, 0.1);
            border: 1px solid rgba(255, 68, 68, 0.3);
            color: #ff4444;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .refresh-btn {
            background: rgba(145, 71, 255, 0.2);
            color: #9147ff;
            border: 1px solid rgba(145, 71, 255, 0.3);
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .refresh-btn:hover {
            background: rgba(145, 71, 255, 0.3);
        }
        
        .info-box {
            background: rgba(0, 180, 216, 0.1);
            border: 1px solid rgba(0, 180, 216, 0.3);
            color: #00b4d8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔍 EventSub Connections Debug</h1>
            <p class="subtitle">Debug page for viewing active Twitch EventSub subscriptions</p>
        </header>
        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($deleteSuccess)): ?>
            <div class="info-box">
                <strong>Success:</strong> <?php echo $deleteSuccess; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($deleteError)): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo $deleteError; ?>
            </div>
        <?php endif; ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Subscriptions</div>
                <div class="stat-value"><?php echo $totalCount; ?></div>
                <div class="stat-secondary">across all transports</div>
            </div>
            <div class="stat-card <?php echo count($websocketSubs) > 10 ? 'warning' : ''; ?>">
                <div class="stat-label">WebSocket Subscriptions</div>
                <div class="stat-value"><?php echo count($websocketSubs); ?></div>
                <div class="stat-secondary"><?php echo count($sessionGroups); ?> active sessions</div>
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
            <div class="section">
                <h2>
                    <span>🌐 WebSocket Sessions</span>
                    <a href="debug_connections.php" class="refresh-btn" style="margin-left: auto;">🔄 Refresh</a>
                </h2>
                <div class="info-box">
                    <strong>💡 Tip:</strong> Twitch limits WebSocket transports. Each session below counts toward your limit. 
                    Your bot and YourChat each need their own session. If you hit the limit, delete old/unused sessions.
                </div>
                <?php foreach ($sessionGroups as $sessionId => $subs): ?>
                    <div class="session-group">
                        <div class="session-header">
                            <div>
                                <strong>Session:</strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                            </div>
                            <div class="sub-count"><?php echo count($subs); ?> subscriptions</div>
                        </div>
                        <table>
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
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this subscription?');">
                                                <input type="hidden" name="subscription_id" value="<?php echo htmlspecialchars($sub['id']); ?>">
                                                <button type="submit" name="delete_subscription" class="delete-btn">🗑️ Delete</button>
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
            <div class="section">
                <h2>🔗 Webhook Subscriptions</h2>
                
                <table>
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
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this subscription?');">
                                        <input type="hidden" name="subscription_id" value="<?php echo htmlspecialchars($sub['id']); ?>">
                                        <button type="submit" name="delete_subscription" class="delete-btn">🗑️ Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div style="text-align: center; margin-top: 30px;">
            <a href="index.php" class="back-link">← Back to YourChat</a>
        </div>
    </div>
</body>
</html>