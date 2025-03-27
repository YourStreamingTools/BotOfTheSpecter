<?php
session_start();
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}
require_once "/var/www/config/db_connect.php";
include "/var/www/config/ssh.php";
include 'userdata.php';

// Validate and get parameters
if (!isset($_GET['server']) || !isset($_GET['file'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}
$selected_server = $_GET['server'];
$oldFilename = $_GET['file'];
if (strpos($oldFilename, '/') !== false || strpos($oldFilename, '\\') !== false || in_array($oldFilename, ['.', '..'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'Invalid filename']);
    exit();
}
// Extract file extension
$extension = pathinfo($oldFilename, PATHINFO_EXTENSION);

// Set server details based on selection
switch ($selected_server) {
    case 'au-east-1':
        $server_host = $storage_server_au_east_1_host;
        $server_username = $storage_server_au_east_1_username;
        $server_password = $storage_server_au_east_1_password;
        break;
    case 'au-syd-1':
        $server_host = $storage_server_au_syd1_host;
        $server_username = $storage_server_au_syd1_username;
        $server_password = $storage_server_au_syd1_password;
        break;
    default:
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Invalid server selection']);
        exit();
}
if (!function_exists('ssh2_connect')) {
    echo json_encode(['success' => false, 'message' => 'SSH2 extension not installed.']);
    exit();
}
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    echo json_encode(['success' => false, 'message' => 'Cannot connect to server.']);
    exit();
}
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    echo json_encode(['success' => false, 'message' => 'Authentication failed.']);
    exit();
}
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    echo json_encode(['success' => false, 'message' => 'SFTP initialization failed.']);
    exit();
}
$oldPath = "/root/{$username}/{$oldFilename}";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_title'])) {
    $newTitleRaw = trim($_POST['new_title']);
    // Replace spaces with underscores
    $newTitle = str_replace(' ', '_', $newTitleRaw);
    $newFilename = $newTitle . '.' . $extension;
    $newPath = "/root/{$username}/{$newFilename}";
    // Attempt rename
    if (@ssh2_sftp_rename($sftp, $oldPath, $newPath)) {
        $_SESSION['edit_status'] = ['success' => true, 'message' => 'File renamed successfully'];
        echo json_encode(['success' => true, 'message' => 'File renamed successfully', 'newFilename' => $newFilename]);
    } else {
        $_SESSION['edit_status'] = ['success' => false, 'message' => 'Failed to rename file'];
        echo json_encode(['success' => false, 'message' => 'Failed to rename file']);
    }
    exit();
} else {
    $currentTitle = pathinfo($oldFilename, PATHINFO_FILENAME);
    echo json_encode(['success' => true, 'currentTitle' => $currentTitle]);
    exit();
}
?>
