<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_dashboard_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";

// Handle bot control actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($connection) {
            if ($action == 'start') {
                SSHConnectionManager::executeCommand($connection, 'sudo systemctl start discordbot');
            } elseif ($action == 'stop') {
                SSHConnectionManager::executeCommand($connection, 'sudo systemctl stop discordbot');
            } elseif ($action == 'restart') {
                SSHConnectionManager::executeCommand($connection, 'sudo systemctl restart discordbot');
            }
        }
    } catch (Exception $e) {
        // Log error if needed
    }
    // Redirect to refresh the page
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch bot service status
$bot_status = 'Unknown';
$bot_pid = 'N/A';
try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    if ($connection) {
        $output = SSHConnectionManager::executeCommand($connection, 'systemctl status discordbot');
        if ($output) {
            if (preg_match('/Active:\s*active\s*\(running\)/', $output)) {
                $bot_status = 'Running';
            } elseif (preg_match('/Active:\s*inactive/', $output)) {
                $bot_status = 'Stopped';
            }
            if (preg_match('/Main PID:\s*(\d+)/', $output, $matches)) {
                $bot_pid = $matches[1];
            }
        }
    }
} catch (Exception $e) {
    $bot_status = 'Error';
    $bot_pid = 'N/A';
}

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
    <div class="columns">
        <div class="column is-two-thirds">
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
            </div>
        </div>
        <div class="column is-one-third">
            <h2 class="title is-4"><span class="icon"><i class="fab fa-discord"></i></span> Discord Bot Service Status</h2>
            <p>Current Status: <span id="bot-status" class="tag <?php echo $bot_status == 'Running' ? 'is-success' : ($bot_status == 'Stopped' ? 'is-danger' : 'is-warning'); ?>"><?php echo $bot_status; ?></span></p>
            <p>PID: <span id="bot-pid"><?php echo $bot_pid; ?></span></p>
            <form method="post" style="display: inline;">
                <button type="submit" name="action" value="start" class="button is-small is-success" <?php echo $bot_status == 'Running' ? 'disabled' : ''; ?>>Start</button>
                <button type="submit" name="action" value="stop" class="button is-small is-danger" <?php echo $bot_status == 'Stopped' ? 'disabled' : ''; ?>>Stop</button>
                <button type="submit" name="action" value="restart" class="button is-small is-warning">Restart</button>
            </form>
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