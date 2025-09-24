<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_dashboard_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";

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
    // Redirect to refresh the page
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
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

// Fetch service statuses
$discord_status = getServiceStatus('discordbot', $bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
$api_status = getServiceStatus('fastapi.service', $api_server_host, $api_server_username, $api_server_password);
$websocket_status = getServiceStatus('websocket.service', $websocket_server_host, $websocket_server_username, $websocket_server_password);

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
                                <p class="title is-6 <?php echo $discord_status['status'] == 'Running' ? 'has-text-success' : ($discord_status['status'] == 'Stopped' ? 'has-text-danger' : ($discord_status['status'] == 'Failed' ? 'has-text-danger' : 'has-text-warning')); ?>"><?php echo $discord_status['status']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="tag is-light has-text-black">PID: <?php echo $discord_status['pid']; ?></span>
                        </div>
                    </div>
                </div>
                <div class="buttons are-small">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="discordbot">
                        <button type="submit" name="action" value="start" class="button is-success" <?php echo $discord_status['status'] == 'Running' ? 'disabled' : ''; ?>>
                            <span class="icon"><i class="fas fa-play"></i></span>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="discordbot">
                        <button type="submit" name="action" value="stop" class="button is-danger" <?php echo ($discord_status['status'] == 'Stopped' || $discord_status['status'] == 'Failed') ? 'disabled' : ''; ?>>
                            <span class="icon"><i class="fas fa-stop"></i></span>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="discordbot">
                        <button type="submit" name="action" value="restart" class="button is-warning">
                            <span class="icon"><i class="fas fa-redo"></i></span>
                        </button>
                    </form>
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
                                <p class="title is-6 <?php echo $api_status['status'] == 'Running' ? 'has-text-success' : ($api_status['status'] == 'Stopped' ? 'has-text-danger' : ($api_status['status'] == 'Failed' ? 'has-text-danger' : 'has-text-warning')); ?>"><?php echo $api_status['status']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="tag is-light has-text-black">PID: <?php echo $api_status['pid']; ?></span>
                        </div>
                    </div>
                </div>
                <div class="buttons are-small">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="fastapi.service">
                        <button type="submit" name="action" value="start" class="button is-success" <?php echo $api_status['status'] == 'Running' ? 'disabled' : ''; ?>>
                            <span class="icon"><i class="fas fa-play"></i></span>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="fastapi.service">
                        <button type="submit" name="action" value="stop" class="button is-danger" <?php echo ($api_status['status'] == 'Stopped' || $api_status['status'] == 'Failed') ? 'disabled' : ''; ?>>
                            <span class="icon"><i class="fas fa-stop"></i></span>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="fastapi.service">
                        <button type="submit" name="action" value="restart" class="button is-warning">
                            <span class="icon"><i class="fas fa-redo"></i></span>
                        </button>
                    </form>
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
                                <p class="title is-6 <?php echo $websocket_status['status'] == 'Running' ? 'has-text-success' : ($websocket_status['status'] == 'Stopped' ? 'has-text-danger' : ($websocket_status['status'] == 'Failed' ? 'has-text-danger' : 'has-text-warning')); ?>"><?php echo $websocket_status['status']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="level-right">
                        <div class="level-item">
                            <span class="tag is-light has-text-black">PID: <?php echo $websocket_status['pid']; ?></span>
                        </div>
                    </div>
                </div>
                <div class="buttons are-small">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="websocket.service">
                        <button type="submit" name="action" value="start" class="button is-success" <?php echo $websocket_status['status'] == 'Running' ? 'disabled' : ''; ?>>
                            <span class="icon"><i class="fas fa-play"></i></span>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="websocket.service">
                        <button type="submit" name="action" value="stop" class="button is-danger" <?php echo ($websocket_status['status'] == 'Stopped' || $websocket_status['status'] == 'Failed') ? 'disabled' : ''; ?>>
                            <span class="icon"><i class="fas fa-stop"></i></span>
                        </button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="service" value="websocket.service">
                        <button type="submit" name="action" value="restart" class="button is-warning">
                            <span class="icon"><i class="fas fa-redo"></i></span>
                        </button>
                    </form>
                </div>
            </div>
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
<?php
$content = ob_get_clean();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>
<?php
include "admin_layout.php";
?>