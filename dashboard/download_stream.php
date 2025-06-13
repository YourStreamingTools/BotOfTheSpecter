<?php
// Initialize the session
session_start();

// Include internationalization
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

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
    echo t('streaming_missing_parameters');
    exit();
}

$selected_server = $_GET['server'];
$filename = $_GET['file'];

// Validate filename to prevent directory traversal
if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || $filename === '.' || $filename === '..') {
    header('HTTP/1.1 400 Bad Request');
    echo t('streaming_invalid_filename');
    exit();
}

// Set server details based on selection
switch ($selected_server) {    case 'au-east-1':
        $server_host = $stream_au_east_1_host;
        $server_username = $stream_au_east_1_username;
        $server_password = $stream_au_east_1_password;
        $user_dir = "/mnt/s3/bots-stream/$username";
        break;    case 'us-west-1':
        $server_host = $stream_us_west_1_host;
        $server_username = $stream_us_west_1_username;
        $server_password = $stream_us_west_1_password;
        $user_dir = "/mnt/s3/bots-stream/$username";
        break;
    case 'us-east-1':
        $server_host = $stream_us_east_1_host;
        $server_username = $stream_us_east_1_username;
        $server_password = $stream_us_east_1_password;
        $user_dir = "/mnt/s3/bots-stream/$username";
        break;
    // Add more server locations as needed
    default:
        header('HTTP/1.1 400 Bad Request');
        echo t('streaming_invalid_server_selection');
        exit();
}

// Check if SSH2 extension is available
if (!function_exists('ssh2_connect')) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_ssh2_not_installed');
    exit();
}

// Connect to the server
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_connection_failed');
    exit();
}

// Authenticate
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_authentication_failed');
    exit();
}

// Create SFTP session
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_sftp_init_failed');
    exit();
}

// Build file path
$file_path = "{$user_dir}/{$filename}";
$sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;

// Check if file exists
if (!file_exists($sftp_path)) {
    header('HTTP/1.1 404 Not Found');
    echo t('streaming_file_not_found');
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
    echo t('streaming_file_open_failed');
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
