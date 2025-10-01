<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_dashboard_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once "/var/www/config/admin_actions.php";
require_once "/var/www/config/twitch.php";

// Handle service control actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['service'])) {
    $action = $_POST['action'];
    $service = $_POST['service'];
    // Define allowed services
    $allowedServices = ['discordbot.service', 'fastapi.service', 'websocket.service'];
    if (in_array($service, $allowedServices)) {
        try {
            // Determine which server credentials to use based on service
            $ssh_host = $bots_ssh_host;
            $ssh_username = $bots_ssh_username;
            $ssh_password = $bots_ssh_password;
            if ($service == 'fastapi.service') {
                $ssh_host = $api_server_host;
                $ssh_username = $api_server_username;
                $ssh_password = $api_server_password;
            } elseif ($service == 'websocket.service') {
                $ssh_host = $websocket_server_host;
                $ssh_username = $websocket_server_username;
                $ssh_password = $websocket_server_password;
            }
            $connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password);
            if ($connection) {
                if ($action == 'start') {
                    SSHConnectionManager::executeCommand($connection, "sudo systemctl start $service");
                } elseif ($action == 'stop') {
                    SSHConnectionManager::executeCommand($connection, "sudo systemctl stop $service");
                } elseif ($action == 'restart') {
                    SSHConnectionManager::executeCommand($connection, "sudo systemctl restart $service");
                }
            }
        } catch (Exception $e) {}
    }
    // Return JSON response instead of redirect
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle send message action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $message = trim($_POST['message']);
    $channel_id = $_POST['channel_id'];
    if (!empty($message) && !empty($channel_id)) {
        // Send message using Twitch API
        $url = "https://api.twitch.tv/helix/chat/messages";
        $headers = [
            "Authorization: Bearer " . $twitch_bot_oauth,
            "Client-Id: " . $clientID,
            "Content-Type: application/json"
        ];
        $data = [
            "broadcaster_id" => $channel_id,
            "sender_id" => "971436498",
            "message" => $message
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code == 200) {
            $response_data = json_decode($response, true);
            if (isset($response_data['data'][0]['is_sent']) && $response_data['data'][0]['is_sent']) {
                $success_message = "Message sent successfully.";
            } else {
                $error_message = "Message not sent: " . ($response_data['data'][0]['drop_reason'] ?? 'Unknown reason');
            }
        } else {
            $error_message = "Failed to send message: HTTP $http_code - $response";
        }
    } else {
        $error_message = "Message and channel are required.";
    }
}

// Function to check if a channel is online
function isOnline($user_id, $client_id, $bearer) {
    $url = "https://api.twitch.tv/helix/streams?user_id=" . urlencode($user_id);
    $headers = [
        "Authorization: Bearer " . $bearer,
        "Client-Id: " . $client_id
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return isset($data['data']) && !empty($data['data']);
}

// Fetch online channels
$online_channels = [];
if ($conn && isset($_SESSION['access_token'])) {
    $result = $conn->query("SELECT id, twitch_user_id, twitch_display_name FROM users");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isOnline($row['twitch_user_id'], $clientID, $_SESSION['access_token'])) {
                $online_channels[] = $row;
            }
        }
    }
}

// Function to get service status
function getServiceStatus($service_name, $ssh_host, $ssh_username, $ssh_password) {
    $status = 'Unknown';
    $pid = 'N/A';
    try {
        $connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password);
        if ($connection) {
            $output = SSHConnectionManager::executeCommand($connection, "systemctl status $service_name");
            if ($output) {
                if (preg_match('/Active:\s*active\s*\(running\)/', $output)) {
                    $status = 'Running';
                } elseif (preg_match('/Active:\s*inactive/', $output)) {
                    $status = 'Stopped';
                } elseif (preg_match('/Active:\s*failed/', $output)) {
                    $status = 'Failed';
                }
                if (preg_match('/Main PID:\s*(\d+)/', $output, $matches)) {
                    $pid = $matches[1];
                }
            }
        }
    } catch (Exception $e) {
        $status = 'Error';
        $pid = 'N/A';
    }
    return ['status' => $status, 'pid' => $pid];
}

// Function to get bot status
function getBotStatus($bots_ssh_host, $bots_ssh_username, $bots_ssh_password) {
    $output = '';
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($connection) {
            $output = SSHConnectionManager::executeCommand($connection, "python3 /home/botofthespecter/running_bots.py 2>&1");
        }
    } catch (Exception $e) {
        $output = "Error fetching bot status: " . $e->getMessage();
    }
    return $output;
}

// Service statuses will be loaded asynchronously via JavaScript
$discord_status = ['status' => 'Loading...', 'pid' => '...'];
$api_status = ['status' => 'Loading...', 'pid' => '...'];
$websocket_status = ['status' => 'Loading...', 'pid' => '...'];

// Fetch user statistics for pie chart
$total_users = 0;
$admin_count = 0;
$beta_count = 0;
$premium_count = 0;
$regular_count = 0;

if ($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $total_users = $result->fetch_assoc()['count'];
    }
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1");
    if ($result) {
        $admin_count = $result->fetch_assoc()['count'];
    }
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE beta_access = 1");
    if ($result) {
        $beta_count = $result->fetch_assoc()['count'];
    }
    // Assuming premium is based on beta_access for now, adjust if needed
    $premium_count = $beta_count;
    $regular_count = $total_users - $admin_count - $beta_count;
}

$bot_output = getBotStatus($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
$stable_bots = [];
$beta_bots = [];
$lines = explode("\n", $bot_output);
$section = '';
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'Stable bots running:') === 0) {
        $section = 'stable';
    } elseif (strpos($line, 'Beta bots running:') === 0) {
        $section = 'beta';
    } elseif (preg_match('/- Channel: (\S+), PID: (\d+)/', $line, $matches)) {
        $bot = ['channel' => $matches[1], 'pid' => $matches[2]];
        if ($section == 'stable') {
            $stable_bots[] = $bot;
        } elseif ($section == 'beta') {
            $beta_bots[] = $bot;
        }
    }
}

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<div class="box">
    <h1 class="title is-3"><span class="icon"><i class="fas fa-shield-alt"></i></span> Administrator Dashboard</h1>
    <p class="mb-4">This is the admin dashboard. Use the links below to manage users, view logs, and perform other administrative tasks.</p>
    <div class="buttons">
        <a href="admin_logs.php" class="button is-info is-light">
            <span class="icon"><i class="fas fa-clipboard-list"></i></span>
            <span>Log Management</span>
        </a>
        <a href="admin_twitch_tokens.php" class="button is-primary is-light">
            <span class="icon"><i class="fab fa-twitch"></i></span>
            <span>Twitch Tokens</span>
        </a>
        <a href="admin_discord_tracking.php" class="button is-link is-light">
            <span class="icon"><i class="fab fa-discord"></i></span>
            <span>Discord Tracking</span>
        </a>
        <a href="admin_websocket_clients.php" class="button is-success is-light">
            <span class="icon"><i class="fas fa-plug"></i></span>
            <span>Websocket Clients</span>
        </a>
    </div>
</div>

<div class="box">
    <h2 class="title is-4"><span class="icon"><i class="fas fa-server"></i></span> Server Overview</h2>
    <div class="columns is-multiline">
        <!-- Discord Bot Service -->
        <div class="column is-one-third">
            <div class="box">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <span class="icon has-text-link">
                                <i class="fab fa-discord fa-lg"></i>
                            </span>
                        </div>
                        <div class="level-item">
                            <div>
                                <p class="heading">Discord Bot</p>
                                <p class="title is-6 has-text-info" id="discord-status">Loading...</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="tag is-light has-text-black" id="discord-pid">PID: ...</span>
                        </div>
                    </div>
                </div>
                <div class="buttons are-small" id="discord-buttons">
                    <button type="button" class="button is-success" onclick="controlService('discordbot.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('discordbot.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('discordbot.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
        <!-- API Server Service -->
        <div class="column is-one-third">
            <div class="box">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <span class="icon has-text-primary">
                                <i class="fas fa-code fa-lg"></i>
                            </span>
                        </div>
                        <div class="level-item">
                            <div>
                                <p class="heading">API Server</p>
                                <p class="title is-6 has-text-info" id="api-status">Loading...</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="tag is-light has-text-black" id="api-pid">PID: ...</span>
                        </div>
                    </div>
                </div>
                <div class="buttons are-small" id="api-buttons">
                    <button type="button" class="button is-success" onclick="controlService('fastapi.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('fastapi.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('fastapi.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
        <!-- WebSocket Server Service -->
        <div class="column is-one-third">
            <div class="box">
                <div class="level">
                    <div class="level-left">
                        <div class="level-item">
                            <span class="icon has-text-success">
                                <i class="fas fa-plug fa-lg"></i>
                            </span>
                        </div>
                        <div class="level-item">
                            <div>
                                <p class="heading">WebSocket Server</p>
                                <p class="title is-6 has-text-info" id="websocket-status">Loading...</p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="tag is-light has-text-black" id="websocket-pid">PID: ...</span>
                        </div>
                    </div>
                </div>
                <div class="buttons are-small" id="websocket-buttons">
                    <button type="button" class="button is-success" onclick="controlService('websocket.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('websocket.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('websocket.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="box">
    <h2 class="title is-4"><span class="icon"><i class="fas fa-robot"></i></span> Bot Overview</h2>
    <div class="columns">
        <div class="column">
            <h3 class="title is-5">Stable Bots</h3>
            <?php if (!empty($stable_bots)): ?>
                <ul>
                    <?php foreach ($stable_bots as $bot): ?>
                        <li><?php echo htmlspecialchars($bot['channel']); ?> (PID: <?php echo $bot['pid']; ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <p><strong>Total:</strong> <?php echo count($stable_bots); ?></p>
            <?php else: ?>
                <p><?php echo htmlspecialchars($bot_output ?: 'None'); ?></p>
            <?php endif; ?>
        </div>
        <div class="column">
            <h3 class="title is-5">Beta Bots</h3>
            <?php if (!empty($beta_bots)): ?>
                <ul>
                    <?php foreach ($beta_bots as $bot): ?>
                        <li><?php echo htmlspecialchars($bot['channel']); ?> (PID: <?php echo $bot['pid']; ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <p><strong>Total:</strong> <?php echo count($beta_bots); ?></p>
            <?php else: ?>
                <p><?php echo htmlspecialchars($bot_output ?: 'None'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="box">
    <h2 class="title is-4"><span class="icon"><i class="fas fa-chart-pie"></i></span> User Overview</h2>
    <div class="columns">
        <div class="column is-half">
            <div style="max-width: 300px; margin: 0 auto;">
                <canvas id="userChart" width="300" height="300"></canvas>
            </div>
        </div>
        <div class="column is-half">
            <p class="mb-4">Quick stats on user distribution:</p>
            <ul>
                <li><strong>Total Users:</strong> <?php echo $total_users; ?></li>
                <li><strong>Admins:</strong> <?php echo $admin_count; ?></li>
                <li><strong>Beta Users:</strong> <?php echo $beta_count; ?></li>
                <li><strong>Premium Users:</strong> <?php echo $premium_count; ?></li>
                <li><strong>Regular Users:</strong> <?php echo $regular_count; ?></li>
            </ul>
            <a href="admin_users.php" class="button is-link is-light mt-4">
                <span class="icon"><i class="fas fa-users-cog"></i></span>
                <span>Manage Users</span>
            </a>
        </div>
    </div>
</div>
<div class="box">
    <h2 class="title is-4"><span class="icon"><i class="fas fa-paper-plane"></i></span> Send Bot Message</h2>
    <?php if (isset($success_message)): ?>
        <div class="notification is-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
        <div class="notification is-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="field">
            <label class="label">Select Channel (Online Only)</label>
            <div class="control">
                <div class="select">
                    <select name="channel_id" required>
                        <option value="">Choose a channel...</option>
                        <?php foreach ($online_channels as $channel): ?>
                            <option value="<?php echo htmlspecialchars($channel['twitch_user_id']); ?>"><?php echo htmlspecialchars($channel['twitch_display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="field">
            <label class="label">Message</label>
            <div class="control">
                <textarea class="textarea" name="message" id="message" placeholder="Enter your message..." required></textarea>
            </div>
        </div>
        <div class="field">
            <div class="control">
                <button class="button is-primary" type="submit" name="send_message" id="send" disabled>Send Message</button>
            </div>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize user chart
    const ctx = document.getElementById('userChart');
    if (ctx) {
        const chartCtx = ctx.getContext('2d');
        const data = [<?php echo $admin_count; ?>, <?php echo $beta_count; ?>, <?php echo $premium_count; ?>, <?php echo $regular_count; ?>];
        if (data.some(val => val > 0)) {
            const userChart = new Chart(chartCtx, {
                type: 'pie',
                data: {
                    labels: ['Admins', 'Beta Users', 'Premium Users', 'Regular Users'],
                    datasets: [{
                        data: data,
                        backgroundColor: [
                            '#ff6384',
                            '#36a2eb',
                            '#cc65fe',
                            '#ffce56'
                        ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        }
                    }
                }
            });
        } else {
            ctx.style.display = 'none';
            const noDataMsg = document.createElement('p');
            noDataMsg.textContent = 'No user data available to display.';
            ctx.parentNode.appendChild(noDataMsg);
        }
    }
    // Function to control service
    window.controlService = function(service, action) {
        const buttonsElementId = service === 'discordbot.service' ? 'discord-buttons' : service === 'fastapi.service' ? 'api-buttons' : 'websocket-buttons';
        const buttonsElement = document.getElementById(buttonsElementId);
        const buttons = buttonsElement.querySelectorAll('button');
        buttons.forEach(btn => btn.disabled = true);
        const formData = new FormData();
        formData.append('service', service);
        formData.append('action', action);
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update status after a short delay to allow service to change state
                setTimeout(() => {
                    const statusService = service === 'fastapi.service' ? 'fastapi' : service === 'websocket.service' ? 'websocket' : 'discordbot';
                    const statusElementId = service === 'fastapi.service' ? 'api-status' : service === 'websocket.service' ? 'websocket-status' : 'discord-status';
                    const pidElementId = service === 'fastapi.service' ? 'api-pid' : service === 'websocket.service' ? 'websocket-pid' : 'discord-pid';
                    const buttonsElementId = service === 'fastapi.service' ? 'api-buttons' : service === 'websocket.service' ? 'websocket-buttons' : 'discord-buttons';
                    updateServiceStatus(statusService, statusElementId, pidElementId, buttonsElementId);
                }, 2000);
            } else {
                buttons.forEach(btn => btn.disabled = false);
            }
        })
        .catch(error => {
            console.error('Error controlling service:', error);
            buttons.forEach(btn => btn.disabled = false);
        });
    };
    // Function to update service status
    function updateServiceStatus(service, statusElementId, pidElementId, buttonsElementId) {
        fetch(`admin_service_status.php?service=${service}`)
            .then(response => response.json())
            .then(data => {
                const statusElement = document.getElementById(statusElementId);
                const pidElement = document.getElementById(pidElementId);
                const buttonsElement = document.getElementById(buttonsElementId);
                // Update status with appropriate color
                statusElement.textContent = data.status;
                statusElement.className = 'title is-6';
                if (data.status === 'Running') {
                    statusElement.classList.add('has-text-success');
                } else if (data.status === 'Stopped' || data.status === 'Failed') {
                    statusElement.classList.add('has-text-danger');
                } else {
                    statusElement.classList.add('has-text-warning');
                }
                // Update PID
                pidElement.textContent = `PID: ${data.pid}`;
                // Enable/disable buttons based on status
                const startBtn = buttonsElement.querySelector('button[onclick*="start"]');
                const stopBtn = buttonsElement.querySelector('button[onclick*="stop"]');
                const restartBtn = buttonsElement.querySelector('button[onclick*="restart"]');
                if (data.status === 'Running') {
                    startBtn.disabled = true;
                    stopBtn.disabled = false;
                    restartBtn.disabled = false;
                } else if (data.status === 'Stopped' || data.status === 'Failed') {
                    startBtn.disabled = false;
                    stopBtn.disabled = true;
                    restartBtn.disabled = false;
                } else {
                    startBtn.disabled = false;
                    stopBtn.disabled = false;
                    restartBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error(`Error fetching ${service} status:`, error);
                const statusElement = document.getElementById(statusElementId);
                statusElement.textContent = 'Error';
                statusElement.className = 'title is-6 has-text-danger';
            });
    }
    // Load service statuses after page load
    setTimeout(() => {
        updateServiceStatus('discordbot', 'discord-status', 'discord-pid', 'discord-buttons');
        updateServiceStatus('fastapi', 'api-status', 'api-pid', 'api-buttons');
        updateServiceStatus('websocket', 'websocket-status', 'websocket-pid', 'websocket-buttons');
    }, 100);
    // Enable send button when message is typed
    const messageTextarea = document.getElementById('message');
    const sendButton = document.getElementById('send');
    if (messageTextarea && sendButton) {
        messageTextarea.addEventListener('input', function() {
            sendButton.disabled = this.value.trim() === '';
        });
    }
});
</script>
<?php
include "admin_layout.php";
?>