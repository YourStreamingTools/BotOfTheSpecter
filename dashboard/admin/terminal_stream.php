<?php
session_start();
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '../userdata.php';

@set_time_limit(0);
ignore_user_abort(true);

$streamTerminated = false;

function sse_send($data, $event = 'message') {
    if ($data === null) {
        return;
    }
    if (is_array($data) || is_object($data)) {
        $data = json_encode($data);
    }
    $data = str_replace("\r", '', trim($data, "\r\n"));
    $lines = $data === '' ? [''] : explode("\n", $data);
    echo "event: $event\n";
    foreach ($lines as $line) {
        if ($line === '') continue;
        echo "data: $line\n";
    }
    echo "\n";
    ob_flush();
    flush();
}

function sendDoneEvent(array $payload = []) {
    global $streamTerminated;
    if ($streamTerminated) {
        return;
    }
    $streamTerminated = true;
    sse_send($payload, 'done');
}

register_shutdown_function(function() {
    global $streamTerminated;
    if ($streamTerminated) {
        return;
    }
    $error = error_get_last();
    if ($error) {
        sse_send('Unhandled error: ' . $error['message'], 'error');
        sendDoneEvent(['error' => $error['message']]);
    } else {
        sendDoneEvent([]);
    }
});

// Database query to check if user is admin
function isAdmin() {
    global $conn;
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($is_admin);
    $result = $stmt->fetch();
    $stmt->close();
    return $result && $is_admin == 1;
}

// Check admin access
if (!isAdmin()) {
    http_response_code(403);
    exit('Access denied');
}

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

// Get parameters
$server = $_GET['server'] ?? '';
$command = $_GET['command'] ?? '';

if (empty($server) || empty($command)) {
    sse_send('Error: Missing server or command parameter', 'error');
    sendDoneEvent(['error' => 'Missing parameters']);
    exit;
}

// Map server names to SSH credentials
$ssh_configs = [
    'bots' => [
        'host' => $bots_ssh_host,
        'username' => $bots_ssh_username,
        'password' => $bots_ssh_password,
        'name' => 'Bot Server'
    ],
    'api' => [
        'host' => $api_server_host,
        'username' => $api_server_username,
        'password' => $api_server_password,
        'name' => 'API Server'
    ],
    'web' => [
        'host' => 'localhost',
        'username' => $server_username,
        'password' => $server_password,
        'name' => 'Web Server'
    ],
    'websocket' => [
        'host' => $websocket_server_host,
        'username' => $websocket_server_username,
        'password' => $websocket_server_password,
        'name' => 'WebSocket Server'
    ],
    'sql' => [
        'host' => $sql_server_host,
        'username' => $sql_server_username,
        'password' => $sql_server_password,
        'name' => 'SQL Server'
    ]
];

if (!isset($ssh_configs[$server])) {
    sse_send('Error: Invalid server specified', 'error');
    sendDoneEvent(['error' => 'Invalid server']);
    exit;
}

$config = $ssh_configs[$server];

try {
    // Get SSH connection
    $connection = SSHConnectionManager::getConnection($config['host'], $config['username'], $config['password']);
    if (!$connection) {
        sse_send("Error: Could not connect to {$config['name']}", 'error');
        sendDoneEvent(['error' => 'SSH connection failed']);
        exit;
    }
    sse_send("Executing on {$config['name']}: {$command}");
    // Execute command with streaming output
    $stream = SSHConnectionManager::executeCommandStream($connection, $command);
    if (!$stream) {
        sse_send('Error: Could not execute command', 'error');
        sendDoneEvent(['error' => 'Command execution failed']);
        exit;
    }
    if (is_array($stream)) {
        $stdout = $stream['stdout'] ?? null;
        $stderr = $stream['stderr'] ?? null;
    } else {
        $stdout = $stream;
        $stderr = null;
    }
    while (($stdout && !feof($stdout)) || ($stderr && !feof($stderr))) {
        $dataRead = false;
        if ($stdout && !feof($stdout)) {
            $data = @fread($stdout, 1024);
            if ($data !== false && $data !== '') {
                sse_send($data);
                $dataRead = true;
            }
        }
        if ($stderr && !feof($stderr)) {
            $edata = @fread($stderr, 1024);
            if ($edata !== false && $edata !== '') {
                sse_send('[stderr] ' . $edata);
                $dataRead = true;
            }
        }
        if (!$dataRead) {
            usleep(10000);
        }
    }
    if ($stdout && is_resource($stdout)) { fclose($stdout); }
    if ($stderr && is_resource($stderr)) { fclose($stderr); }
    sendDoneEvent(['success' => true, 'exit_code' => 0]);
} catch (Exception $e) {
    sse_send("Error: " . $e->getMessage(), 'error');
    sendDoneEvent(['error' => $e->getMessage()]);
}
?>