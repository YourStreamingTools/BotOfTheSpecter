<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Page Title and Header
$pageTitle = "Tanggle Integration";
$pageDescription = "Configure Tanggle puzzle integration settings";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'storage_used.php';

require_once '/var/www/config/database.php';
$dbname = $username;
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Handle POST request to save credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['api_token']) || isset($_POST['community_uuid']))) {
    $api_token = trim($_POST['api_token'] ?? '');
    $community_uuid = trim($_POST['community_uuid'] ?? '');
    if (!empty($api_token) && !empty($community_uuid)) {
        // Ensure a profile row exists; if not insert, otherwise update
        $checkStmt = mysqli_prepare($db, "SELECT COUNT(*) as cnt FROM profile");
        if ($checkStmt) {
            mysqli_stmt_execute($checkStmt);
            $checkRes = mysqli_stmt_get_result($checkStmt);
            $row = mysqli_fetch_assoc($checkRes);
            mysqli_stmt_close($checkStmt);
        } else {
            $row = ['cnt' => 0];
        }
        if (!isset($row['cnt']) || $row['cnt'] == 0) {
            $stmt = mysqli_prepare($db, "INSERT INTO profile (tanggle_api_token, tanggle_community_uuid) VALUES (?, ?)");
        } else {
            $stmt = mysqli_prepare($db, "UPDATE profile SET tanggle_api_token = ?, tanggle_community_uuid = ?");
        }
        if ($stmt === false) {
            $message = "Database error: " . mysqli_error($db);
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $api_token, $community_uuid);
            if (mysqli_stmt_execute($stmt)) {
                $message = "Tanggle credentials saved successfully!";
            } else {
                $message = "Failed to save Tanggle credentials: " . mysqli_error($db);
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $message = "Please enter both API Token and Community UUID.";
    }
}

// Get current credentials
$current_api_token = '';
$current_community_uuid = '';
$result = $db->query("SELECT tanggle_api_token, tanggle_community_uuid FROM profile LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $current_api_token = $row['tanggle_api_token'] ?? '';
    $current_community_uuid = $row['tanggle_community_uuid'] ?? '';
}
$credentials_exist = !empty($current_api_token) && !empty($current_community_uuid);

// Fetch active puzzle room if credentials exist
$active_room = null;
$api_error = null;
$puzzle_stats = [
    'completed_count' => 0,
    'last_completed_at' => null,
    'last_completed_room_uuid' => null
];
$recent_completions = [];
if ($credentials_exist) {
    $tanggle_base_url = 'https://api.tanggle.io';
    $rooms_url = "$tanggle_base_url/communities/$current_community_uuid/rooms";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rooms_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $current_api_token,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code === 200 && $response) {
        $rooms_data = json_decode($response, true);
        if (isset($rooms_data['items']) && count($rooms_data['items']) > 0) {
            $first_room_uuid = $rooms_data['items'][0]['uuid'];
            $room_url = "$tanggle_base_url/communities/$current_community_uuid/rooms/$first_room_uuid";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $room_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $current_api_token,
                'Content-Type: application/json'
            ]);
            $room_response = curl_exec($ch);
            $room_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($room_http_code === 200 && $room_response) {
                $room_data = json_decode($room_response, true);
                if (isset($room_data['success']) && $room_data['success'] && isset($room_data['room'])) {
                    $active_room = $room_data['room'];
                }
            }
        }
    } else {
        $api_error = "Failed to fetch room data from Tanggle API";
    }

    // Fetch queue
    $queue_items = [];
    $queue_url = "$tanggle_base_url/communities/$current_community_uuid/queue";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $queue_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $current_api_token,
        'Content-Type: application/json'
    ]);
    $queue_response = curl_exec($ch);
    $queue_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($queue_http_code === 200 && $queue_response) {
        $queue_data = json_decode($queue_response, true);
        if (is_array($queue_data)) {
            $queue_items = $queue_data;
        }
    }

    // Fetch completion stats and recent completions from local DB
    $stats_result = $db->query("SELECT completed_count, last_completed_at, last_completed_room_uuid FROM tanggle_puzzle_stats WHERE id = 1 LIMIT 1");
    if ($stats_result && $stats_row = $stats_result->fetch_assoc()) {
        $puzzle_stats['completed_count'] = (int)($stats_row['completed_count'] ?? 0);
        $puzzle_stats['last_completed_at'] = $stats_row['last_completed_at'] ?? null;
        $puzzle_stats['last_completed_room_uuid'] = $stats_row['last_completed_room_uuid'] ?? null;
    }

    $recent_result = $db->query("SELECT room_uuid, redirect_url, room_title, piece_count, piece_completed, winner_username, winner_twitch_username, completed_at, recorded_at FROM tanggle_room_completions ORDER BY COALESCE(completed_at, recorded_at) DESC LIMIT 10");
    if ($recent_result) {
        while ($completion_row = $recent_result->fetch_assoc()) {
            $recent_completions[] = $completion_row;
        }
    }
}

ob_start();
?>
<?php if (isset($message)): ?>
    <div class="sp-alert <?php echo strpos($message, 'successfully') !== false ? 'sp-alert-success' : 'sp-alert-danger'; ?>" style="margin-bottom:1.5rem;">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if (!$credentials_exist): ?>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
        <strong>Configuration Required:</strong> Please enter your Tanggle API credentials below to enable puzzle integration.
    </div>
<?php endif; ?>
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <i class="fas fa-puzzle-piece" style="margin-right:0.5rem;"></i>
            Tanggle Integration
        </p>
    </header>
    <div class="sp-card-body">
        <div class="sp-card" style="margin-bottom:1.5rem;">
            <header class="sp-card-header">
                <p class="sp-card-title">
                    <i class="fas fa-wrench" style="margin-right:0.5rem;"></i>
                    Tanggle Configuration
                </p>
            </header>
            <div class="sp-card-body">
                <p style="color:var(--text-secondary);margin-bottom:1.25rem;">Enter your Tanggle API credentials below. These will be used to integrate with Tanggle puzzles.</p>
                <form method="post" action="">
                    <div class="sp-form-group">
                        <label class="sp-label">API Access Token</label>
                        <div style="position:relative;">
                            <input class="sp-input" type="password" name="api_token"
                                value="<?php echo htmlspecialchars($current_api_token); ?>"
                                placeholder="Enter your API Access Token" required id="api-token-field"
                                style="padding-right:2.5rem;">
                            <span id="api-token-visibility-icon"
                                style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--text-muted);">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.35rem;">Click the eye icon to show/hide the API token</p>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Community UUID</label>
                        <input class="sp-input" type="text" name="community_uuid"
                            value="<?php echo htmlspecialchars($current_community_uuid); ?>"
                            placeholder="Enter your Community UUID" required id="community-uuid-field">
                        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.35rem;">Example: 12345678-1234-1234-1234-123456789012</p>
                    </div>
                    <button class="sp-btn sp-btn-primary" type="submit">Save Credentials</button>
                </form>
            </div>
        </div>
        <?php if (!$credentials_exist): ?>
            <div class="sp-card">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-question-circle" style="margin-right:0.5rem;"></i>
                        How to Get Your Tanggle Credentials
                    </p>
                </header>
                <div class="sp-card-body">
                    <p style="font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">API Access Token:</p>
                    <ol style="color:var(--text-secondary);padding-left:1.5rem;margin-bottom:1.25rem;">
                        <li><strong>Navigate to your Tanggle community:</strong> Go to <a href="https://tanggle.io" target="_blank">https://tanggle.io</a></li>
                        <li><strong>Access Administration:</strong> Click on your community settings or administration panel</li>
                        <li><strong>Go to Integrations:</strong> Find and click on the "Integrations" section</li>
                        <li><strong>Open Custom Tab:</strong> Click on the "Custom" tab within Integrations</li>
                        <li><strong>Copy API Token:</strong> Click the "Copy token" button to copy your API access token</li>
                        <li><strong>Paste Here:</strong> Paste the token in the "API Access Token" field above</li>
                    </ol>
                    <p style="font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">Community UUID:</p>
                    <ol style="color:var(--text-secondary);padding-left:1.5rem;">
                        <li><strong>View Your Community Page:</strong> Navigate to your community on Tanggle</li>
                        <li><strong>Check the URL:</strong> Look at your browser's address bar</li>
                        <li><strong>Find the UUID:</strong> The URL will look like <code>tanggle.io/community/12345678-1234-1234-1234-123456789012</code></li>
                        <li><strong>Copy the UUID:</strong> Copy the long string after <code>/community/</code> (e.g., <code>12345678-1234-1234-1234-123456789012</code>)</li>
                        <li><strong>Paste Here:</strong> Paste the UUID in the "Community UUID" field above</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($credentials_exist): ?>
            <?php if ($api_error): ?>
                <div class="sp-alert sp-alert-danger" style="margin-bottom:1.5rem;">
                    <strong>API Error:</strong> <?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php endif; ?>
            <div class="sp-card" style="margin-bottom:1.5rem;">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-chart-line" style="margin-right:0.5rem;"></i>
                        Puzzle Completion Stats
                    </p>
                </header>
                <div class="sp-card-body">
                    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <p style="color:var(--text-secondary);">
                                <strong style="color:var(--text-primary);">Total Completed:</strong>
                                <span class="sp-badge sp-badge-green" style="margin-left:0.5rem;"><?php echo (int)$puzzle_stats['completed_count']; ?></span>
                            </p>
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <p style="color:var(--text-secondary);">
                                <strong style="color:var(--text-primary);">Last Completed:</strong>
                                <?php
                                $last_completed_display = 'N/A';
                                if (!empty($puzzle_stats['last_completed_at'])) {
                                    $last_completed_ts = strtotime($puzzle_stats['last_completed_at']);
                                    if ($last_completed_ts !== false) {
                                        $last_completed_display = date('M j, Y g:i A', $last_completed_ts);
                                    }
                                }
                                echo htmlspecialchars($last_completed_display);
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($active_room): ?>
                <div class="sp-card" style="margin-bottom:1.5rem;">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-puzzle-piece" style="margin-right:0.5rem;"></i>
                            Active Puzzle
                        </p>
                    </header>
                    <div class="sp-card-body">
                        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
                            <div style="flex:0 0 240px;max-width:240px;">
                                <img src="<?php echo htmlspecialchars($active_room['image']['sources']['preview'][3]['url'] ?? ''); ?>"
                                    alt="Puzzle Preview" style="width:100%;border-radius:var(--radius);display:block;">
                            </div>
                            <div style="flex:1;min-width:200px;">
                                <p style="font-weight:700;font-size:1rem;color:var(--text-primary);margin-bottom:0.75rem;">
                                    <?php echo htmlspecialchars($active_room['image']['attribution']['title'] ?? 'Untitled Puzzle'); ?>
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);">Status:</strong>
                                    <?php if ($active_room['isCompleted']): ?>
                                        <span class="sp-badge sp-badge-green" style="margin-left:0.35rem;">Completed</span>
                                    <?php else: ?>
                                        <span class="sp-badge sp-badge-blue" style="margin-left:0.35rem;">In Progress</span>
                                    <?php endif; ?>
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);">Pieces:</strong>
                                    <?php echo $active_room['pieces']['completed']; ?> /
                                    <?php echo $active_room['pieces']['count']; ?>
                                    (<?php echo round($active_room['pieces']['completedRate'] * 100, 1); ?>%)
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);">Grid:</strong>
                                    <?php echo $active_room['pieces']['x']; ?>x<?php echo $active_room['pieces']['y']; ?>
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);">Players:</strong>
                                    <?php echo $active_room['playerCount']; ?> /
                                    <?php echo $active_room['playerLimit']; ?>
                                </p>
                                <?php if (isset($active_room['image']['attribution']['creator']['name'])): ?>
                                    <p style="color:var(--text-secondary);margin-bottom:0.75rem;">
                                        <strong style="color:var(--text-primary);">Creator:</strong>
                                        <?php echo htmlspecialchars($active_room['image']['attribution']['creator']['name']); ?>
                                    </p>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars($active_room['redirectUrl']); ?>"
                                    target="_blank" class="sp-btn sp-btn-primary sp-btn-sm">
                                    <i class="fas fa-external-link-alt"></i>
                                    Open Puzzle
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif (!$api_error): ?>
                <div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;">
                    <strong>No Active Puzzle:</strong> There are currently no active puzzle rooms in your community.
                </div>
            <?php endif; ?>
            <?php if (!empty($queue_items)): ?>
                <div class="sp-card" style="margin-bottom:1.5rem;">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-list" style="margin-right:0.5rem;"></i>
                            Puzzle Queue (<?php echo count($queue_items); ?> puzzles)
                        </p>
                    </header>
                    <div class="sp-card-body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;">
                            <?php foreach ($queue_items as $queue_item): ?>
                                <div class="sp-card" style="margin-bottom:0;">
                                    <img src="<?php echo htmlspecialchars($queue_item['image']['sources']['preview'][3]['url'] ?? ''); ?>"
                                        alt="<?php echo htmlspecialchars($queue_item['image']['attribution']['title'] ?? 'Puzzle'); ?>"
                                        style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block;">
                                    <div style="padding:0.75rem;">
                                        <p style="font-weight:700;color:var(--text-primary);margin-bottom:0.3rem;font-size:0.88rem;">
                                            <?php echo htmlspecialchars($queue_item['image']['attribution']['title'] ?? 'Untitled'); ?>
                                        </p>
                                        <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0.3rem;">
                                            Position: #<?php echo $queue_item['position']; ?>
                                        </p>
                                        <p style="font-size:0.8rem;color:var(--text-secondary);">
                                            <strong>Grid:</strong>
                                            <?php echo $queue_item['body']['pieces']['x']; ?>x<?php echo $queue_item['body']['pieces']['y']; ?>
                                            (<?php echo ($queue_item['body']['pieces']['x'] * $queue_item['body']['pieces']['y']); ?> pieces)
                                        </p>
                                        <?php if (isset($queue_item['image']['attribution']['creator']['name'])): ?>
                                            <p style="font-size:0.8rem;color:var(--text-secondary);">
                                                <strong>By:</strong>
                                                <?php echo htmlspecialchars($queue_item['image']['attribution']['creator']['name']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="sp-card">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-history" style="margin-right:0.5rem;"></i>
                        Recent Completed Puzzles
                    </p>
                </header>
                <div class="sp-card-body">
                    <?php if (!empty($recent_completions)): ?>
                        <div class="sp-table-wrap">
                            <table class="sp-table">
                                <thead>
                                    <tr>
                                        <th>Puzzle</th>
                                        <th>Winner</th>
                                        <th>Pieces</th>
                                        <th>Completed</th>
                                        <th>Link</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_completions as $completion): ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($completion['room_title'] ?: 'Untitled Puzzle'); ?><br>
                                                <span style="font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars($completion['room_uuid'] ?? ''); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $winner = $completion['winner_twitch_username'] ?: $completion['winner_username'];
                                                echo htmlspecialchars($winner ?: 'Unknown');
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $piece_completed = isset($completion['piece_completed']) ? (int)$completion['piece_completed'] : 0;
                                                $piece_count = isset($completion['piece_count']) ? (int)$completion['piece_count'] : 0;
                                                echo $piece_completed . ' / ' . $piece_count;
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $completed_source = $completion['completed_at'] ?: $completion['recorded_at'];
                                                $completed_display = 'N/A';
                                                if (!empty($completed_source)) {
                                                    $completed_ts = strtotime($completed_source);
                                                    if ($completed_ts !== false) {
                                                        $completed_display = date('M j, Y g:i A', $completed_ts);
                                                    }
                                                }
                                                echo htmlspecialchars($completed_display);
                                                ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($completion['redirect_url'])): ?>
                                                    <a href="<?php echo htmlspecialchars($completion['redirect_url']); ?>" target="_blank" class="sp-btn sp-btn-info sp-btn-sm">Open</a>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="sp-alert sp-alert-info">
                            <strong>No Completion History Yet:</strong> Completed puzzles will appear here after the bot records a room completion event.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const apiTokenField = document.getElementById('api-token-field');
        const visibilityIcon = document.getElementById('api-token-visibility-icon');
        if (apiTokenField && visibilityIcon) {
            visibilityIcon.addEventListener('click', function () {
                if (apiTokenField.type === 'password') {
                    apiTokenField.type = 'text';
                    visibilityIcon.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    apiTokenField.type = 'password';
                    visibilityIcon.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
        }
    });
</script>
<?php
$content = ob_get_clean();
include "layout.php";
?>