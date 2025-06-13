<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_user_bot_logs_title');
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';

// Always use SSH config and log reading function for log retrieval
include_once "/var/www/config/ssh.php";
function read_log_over_ssh($remote_path, $lines = 200, $startLine = null) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!function_exists('ssh2_connect')) {
      return ['error' => 'SSH2 extension not installed'];
    }
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) return ['error' => 'Could not connect to SSH server'];
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
      return ['error' => 'SSH authentication failed'];
    }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") {
      return ['error' => 'not_found'];
    }
    // Count total lines
    $cmd_count = "wc -l < " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd_count);
    stream_set_blocking($stream, true);
    $linesTotal = (int)trim(stream_get_contents($stream));
    fclose($stream);
    if ($linesTotal === 0) {
      return [
        'linesTotal' => 0,
        'logContent' => '',
        'empty' => true
      ];
    }
    if ($startLine === null) {
      $startLine = max(0, $linesTotal - $lines);
    }
    $cmd = "tail -n +" . ($startLine + 1) . " " . escapeshellarg($remote_path) . " | head -n $lines";
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    return [
      'linesTotal' => $linesTotal,
      'logContent' => $logContent
    ];
}

// Helper function to highlight log dates in a string, add <br> at end of each line, and reverse order
function highlight_log_dates($text) {
    $style = 'style="color: #e67e22; font-weight: bold;"';
    $escaped = htmlspecialchars($text);
    $lines = explode("\n", $escaped);
    $lines = array_reverse($lines);
    foreach ($lines as &$line) {
        $line = preg_replace(
            '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
            '<span ' . $style . '>$1</span>',
            $line
        );
    }
    return implode("<br>", $lines);
}

// Handle AJAX log fetch for admin (always via SSH)
if (isset($_GET['admin_log_user']) && isset($_GET['admin_log_type'])) {
    header('Content-Type: application/json');
    $selectedUser = $_GET['admin_log_user'];
    $logType = $_GET['admin_log_type'];
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $logPath = "/home/botofthespecter/logs/logs/$logType/$selectedUser.txt";
    $result = read_log_over_ssh($logPath, 200, $since);
    if (isset($result['error'])) {
        if ($result['error'] === 'not_found') {
            echo json_encode(['error' => 'not_found']);
        } else {
            echo json_encode(['error' => 'connection_failed']);
        }
        exit();
    }
    if (isset($result['empty']) && $result['empty']) {
        echo json_encode(['last_line' => 0, 'data' => '', 'empty' => true]);
        exit();
    }
    $logContent = $result['logContent'];
    $linesTotal = $result['linesTotal'];
    $logContent = highlight_log_dates($logContent);
    echo json_encode(['last_line' => $linesTotal, 'data' => $logContent]);
    exit();
}

// Fetch all users for dropdown
$users = [];
$res = $conn->query("SELECT username FROM users ORDER BY username ASC");
while ($row = $res->fetch_assoc()) {
    $users[] = $row['username'];
}
ob_start();
?>
<div class="box">
    <div class="level mb-4">
        <div class="level-left">
            <h1 class="title is-4 mb-0"><span class="icon"><i class="fas fa-clipboard-list"></i></span> User Bot Logs</h1>
        </div>
        <div class="level-right">
            <div class="field has-addons">
                <div class="control">
                    <div class="select">
                        <select id="admin-log-user-select">
                            <option value="">Select User</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="control">
                    <div class="select">
                        <select id="admin-log-type-select" disabled>
                            <option value="">Select Log Type</option>
                            <option value="bot">Bot Log</option>
                            <option value="chat">Chat Log</option>
                            <option value="twitch">Twitch Log</option>
                            <option value="api">API Log</option>
                            <option value="chat_history">Chat History</option>
                            <option value="event_log">Event Log</option>
                            <option value="websocket">Websocket Log</option>
                            <option value="discord">Discord Bot Log</option>
                        </select>
                    </div>
                </div>
                <div class="control">
                    <button class="button is-link" id="admin-log-reload" disabled>Reload</button>
                </div>
                <div class="control">
                    <button class="button is-primary" id="admin-log-load-more" disabled>Load More</button>
                </div>
            </div>
        </div>
    </div>
    <div class="table-container">
        <div id="admin-log-textarea"
             class="admin-log-content"
             contenteditable="false"
             style="max-height: 600px; min-height: 600px; font-family: monospace; white-space: pre-wrap; background: #23272f; color: #f5f5f5; border: 1px solid #444; border-radius: 4px; padding: 1em; width: 100%; overflow-x: auto; overflow-y: auto;"></div>
    </div>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
let adminLogLastLine = 0;
let adminLogUser = '';
let adminLogType = '';
const userSelect = document.getElementById('admin-log-user-select');
const typeSelect = document.getElementById('admin-log-type-select');
const reloadBtn = document.getElementById('admin-log-reload');
const loadMoreBtn = document.getElementById('admin-log-load-more');
const logTextarea = document.getElementById('admin-log-textarea');

// Enable log type select when user is chosen
userSelect.addEventListener('change', function() {
    adminLogUser = this.value;
    typeSelect.disabled = !adminLogUser;
    logTextarea.value = '';
    adminLogLastLine = 0;
    typeSelect.value = '';
    reloadBtn.disabled = true;
    loadMoreBtn.disabled = true;
});

// When log type is chosen, fetch log
typeSelect.addEventListener('change', function() {
    adminLogType = this.value;
    if (adminLogUser && adminLogType) {
        fetchAdminLog();
        reloadBtn.disabled = false;
        loadMoreBtn.disabled = false;
    } else {
        logTextarea.value = '';
        reloadBtn.disabled = true;
        loadMoreBtn.disabled = true;
    }
});

reloadBtn.addEventListener('click', function() {
    fetchAdminLog();
});
loadMoreBtn.addEventListener('click', function() {
    fetchAdminLog(true);
});

async function fetchAdminLog(loadMore = false) {
    if (!adminLogUser || !adminLogType) return;
    if (loadMore && adminLogLastLine <= 0) return;
    let since = loadMore ? Math.max(0, adminLogLastLine - 200) : 0;
    try {
        const resp = await fetch(`admin_logs.php?admin_log_user=${encodeURIComponent(adminLogUser)}&admin_log_type=${encodeURIComponent(adminLogType)}&since=${since}`);
        const json = await resp.json();
        if (json.error) {
            if (json.error === "not_found") {
                logTextarea.innerHTML = "Log file not found.";
            } else if (json.error === "connection_failed") {
                logTextarea.innerHTML = "Unable to connect to the logging system.";
            } else {
                logTextarea.innerHTML = "An unknown error occurred.";
            }
            return;
        }
        adminLogLastLine = json.last_line;
        if (json.empty) {
            logTextarea.innerHTML = "(log file is empty)";
        } else if (!json.data || json.data.trim() === "") {
            logTextarea.innerHTML = "(log is empty or not found)";
        } else if (loadMore) {
            logTextarea.innerHTML = json.data + logTextarea.innerHTML;
        } else {
            logTextarea.innerHTML = json.data;
        }
    } catch (e) {
        logTextarea.innerHTML = "Unable to connect to the logging system.";
        console.error(e);
    }
}
</script>
<?php
$scripts = ob_get_clean();
include "admin_layout.php";
?>