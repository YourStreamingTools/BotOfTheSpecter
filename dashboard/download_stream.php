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
    header('HTTP/1.1 400 Bad Request');
    echo "Missing required parameters";
    exit();
}

$selected_server = $_GET['server'];
$filename = $_GET['file'];

// Validate filename to prevent directory traversal
if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || $filename === '.' || $filename === '..') {
    header('HTTP/1.1 400 Bad Request');
    echo "Invalid filename";
    exit();
}

// Set server details based on selection
switch ($selected_server) {
    case 'au-east-1':
        $server_host = $storage_server_au_east_1_host;
        $server_username = $storage_server_au_east_1_username;
        $server_password = $storage_server_au_east_1_password;
        break;
    // Add more server locations as needed
    default:
        header('HTTP/1.1 400 Bad Request');
        echo "Invalid server selection";
        exit();
}

// Check if SSH2 extension is available
if (!function_exists('ssh2_connect')) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "SSH2 extension not installed on the server";
    exit();
}

// Connect to the server
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Could not connect to the storage server";
    exit();
}

// Authenticate
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Authentication failed";
    exit();
}

// Create SFTP session
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Could not initialize SFTP subsystem";
    exit();
}

// Build file path
$file_path = "/root/{$username}/{$filename}";
$sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;

// Check if file exists
if (!file_exists($sftp_path)) {
    header('HTTP/1.1 404 Not Found');
    echo "File not found";
    exit();
}

// Get file size
$filesize = filesize($sftp_path);

// Set appropriate headers for streaming
header('Content-Description: File Transfer');
header('Content-Type: video/mp4');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $filesize);

// Open the file for reading
$handle = @fopen($sftp_path, 'r');
if (!$handle) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Could not open file for reading";
    exit();
}

// Stream the file in chunks to avoid memory issues with large files
$buffer_size = 8192; // 8KB chunks
while (!feof($handle)) {
    echo fread($handle, $buffer_size);
    // Flush the output buffer to send data to client immediately
    ob_flush();
    flush();
}

// Close the file handle
fclose($handle);
exit();
?>
