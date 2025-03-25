<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Include SSH and user data files
require_once "/var/www/config/db_connect.php";
include "/var/www/config/ssh.php";
include 'userdata.php';

// Validate and get parameters
if (!isset($_GET['server']) || !isset($_GET['file'])) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'Missing required parameters'];
    header('Location: streaming.php');
    exit();
}

$selected_server = $_GET['server'];
$filename = $_GET['file'];

// Validate filename to prevent directory traversal
if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || $filename === '.' || $filename === '..') {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'Invalid filename'];
    header('Location: streaming.php');
    exit();
}

// Set server details based on selection
switch ($selected_server) {
    case 'au-syd-1':
        $server_host = $storage_server_au_syd1_host;
        $server_username = $storage_server_au_syd1_username;
        $server_password = $storage_server_au_syd1_password;
        break;
    // Add more server locations as needed
    default:
        $_SESSION['delete_status'] = ['success' => false, 'message' => 'Invalid server selection'];
        header('Location: streaming.php');
        exit();
}

// Check if SSH2 extension is available
if (!function_exists('ssh2_connect')) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'SSH2 extension not installed on the server'];
    header('Location: streaming.php');
    exit();
}

// Connect to the server
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'Could not connect to the storage server'];
    header('Location: streaming.php');
    exit();
}

// Authenticate
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'Authentication failed'];
    header('Location: streaming.php');
    exit();
}

// Create SFTP session
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'Could not initialize SFTP subsystem'];
    header('Location: streaming.php');
    exit();
}

// Build file path
$file_path = "/root/{$username}/{$filename}";
$sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;

// Check if file exists
if (!file_exists($sftp_path)) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'File not found'];
    header('Location: streaming.php');
    exit();
}

// Delete the file
if (@ssh2_sftp_unlink($sftp, $file_path)) {
    $_SESSION['delete_status'] = ['success' => true, 'message' => 'File deleted successfully'];
} else {
    $_SESSION['delete_status'] = ['success' => false, 'message' => 'Failed to delete file'];
}

// Redirect back to streaming page
header('Location: streaming.php');
exit();
?>
