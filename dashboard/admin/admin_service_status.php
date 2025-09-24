<?php
session_start();
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";

// Function to check if user is admin
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

// Check if user is authenticated and is admin
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit();
}

header('Content-Type: application/json');

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

// Get the requested service
$service = $_GET['service'] ?? '';

// Define service mappings
$serviceMap = [
    'discordbot' => [
        'service_name' => 'discordbot',
        'ssh_host' => $bots_ssh_host,
        'ssh_username' => $bots_ssh_username,
        'ssh_password' => $bots_ssh_password
    ],
    'fastapi' => [
        'service_name' => 'fastapi.service',
        'ssh_host' => $api_server_host,
        'ssh_username' => $api_server_username,
        'ssh_password' => $api_server_password
    ],
    'websocket' => [
        'service_name' => 'websocket.service',
        'ssh_host' => $websocket_server_host,
        'ssh_username' => $websocket_server_username,
        'ssh_password' => $websocket_server_password
    ]
];

if (!isset($serviceMap[$service])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid service']);
    exit();
}

$config = $serviceMap[$service];
$result = getServiceStatus(
    $config['service_name'], 
    $config['ssh_host'], 
    $config['ssh_username'], 
    $config['ssh_password']
);

echo json_encode($result);
?>