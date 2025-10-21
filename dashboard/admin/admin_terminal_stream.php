<?php
session_start();
require_once "/var/www/config/ssh.php";
include '../userdata.php';

// Check admin access
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    exit('Access denied');
}

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

// Helper function to send SSE data
function sse_send($data, $event = 'message') {
    echo "event: $event\n";
    echo "data: $data\n\n";
    ob_flush();
    flush();
}

// Get parameters
$server = $_GET['server'] ?? '';
$command = $_GET['command'] ?? '';

if (empty($server) || empty($command)) {
    sse_send('Error: Missing server or command parameter', 'error');
    sse_send(json_encode(['error' => 'Missing parameters']), 'done');
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
        'host' => $api_ssh_host,
        'username' => $api_ssh_username,
        'password' => $api_server_password,
        'name' => 'API Server'
    ],
    'websocket' => [
        'host' => $websocket_ssh_host,
        'username' => $websocket_ssh_username,
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
    sse_send(json_encode(['error' => 'Invalid server']), 'done');
    exit;
}

$config = $ssh_configs[$server];

try {
    // Get SSH connection
    $connection = SSHConnectionManager::getConnection($config['host'], $config['username'], $config['password']);
    
    if (!$connection) {
        sse_send("Error: Could not connect to {$config['name']}", 'error');
        sse_send(json_encode(['error' => 'SSH connection failed']), 'done');
        exit;
    }
    
    sse_send("Executing on {$config['name']}: {$command}");
    
    // Execute command with streaming output
    $stream = SSHConnectionManager::executeCommandStream($connection, $command);
    
    if (!$stream) {
        sse_send('Error: Could not execute command', 'error');
        sse_send(json_encode(['error' => 'Command execution failed']), 'done');
        exit;
    }
    
    // Stream output line by line
    $buffer = '';
    while (!feof($stream)) {
        $data = fread($stream, 1024);
        if ($data === false) break;
        
        $buffer .= $data;
        
        // Process complete lines
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            
            if (!empty(trim($line))) {
                sse_send($line);
            }
        }
        
        // Small delay to prevent overwhelming the browser
        usleep(10000); // 10ms
    }
    
    // Send any remaining buffer content
    if (!empty(trim($buffer))) {
        sse_send($buffer);
    }
    
    fclose($stream);
    
    sse_send(json_encode(['success' => true, 'exit_code' => 0]), 'done');
    
} catch (Exception $e) {
    sse_send("Error: " . $e->getMessage(), 'error');
    sse_send(json_encode(['error' => $e->getMessage()]), 'done');
}
?>