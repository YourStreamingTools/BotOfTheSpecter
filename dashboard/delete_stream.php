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
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_missing_parameters')];
    header('Location: streaming.php');
    exit();
}

$selected_server = $_GET['server'];
$filename = $_GET['file'];

// Validate filename to prevent directory traversal
if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || $filename === '.' || $filename === '..') {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_invalid_filename')];
    header('Location: streaming.php');
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
        $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_invalid_server_selection')];
        header('Location: streaming.php');
        exit();
}

// Check if SSH2 extension is available
if (!function_exists('ssh2_connect')) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_ssh2_not_installed')];
    header('Location: streaming.php');
    exit();
}

// Connect to the server
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_connection_failed')];
    header('Location: streaming.php');
    exit();
}

// Authenticate
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_authentication_failed')];
    header('Location: streaming.php');
    exit();
}

// Create SFTP session
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_sftp_init_failed')];
    header('Location: streaming.php');
    exit();
}

// Build file path
$file_path = "{$user_dir}/{$filename}";
$sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;

// Check if file exists
if (!file_exists($sftp_path)) {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_file_not_found')];
    header('Location: streaming.php');
    exit();
}

// Delete the file
if (@ssh2_sftp_unlink($sftp, $file_path)) {
    $_SESSION['delete_status'] = ['success' => true, 'message' => t('streaming_file_deleted_success')];
} else {
    $_SESSION['delete_status'] = ['success' => false, 'message' => t('streaming_file_delete_failed')];
}

// Redirect back to streaming page
header('Location: streaming.php');
exit();
?>
