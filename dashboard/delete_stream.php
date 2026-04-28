<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';

// Include internationalization
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
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

// Release session lock before SSH connection (SSH can take seconds)
session_write_close();

$deleteStatus = null;

// Connect to the server
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    $deleteStatus = ['success' => false, 'message' => t('streaming_connection_failed')];
} elseif (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    $deleteStatus = ['success' => false, 'message' => t('streaming_authentication_failed')];
} else {
    // Create SFTP session
    $sftp = @ssh2_sftp($connection);
    if (!$sftp) {
        $deleteStatus = ['success' => false, 'message' => t('streaming_sftp_init_failed')];
    } else {
        // Build file path
        $file_path = "{$user_dir}/{$filename}";
        $sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;

        if (!file_exists($sftp_path)) {
            $deleteStatus = ['success' => false, 'message' => t('streaming_file_not_found')];
        } elseif (@ssh2_sftp_unlink($sftp, $file_path)) {
            $deleteStatus = ['success' => true, 'message' => t('streaming_file_deleted_success')];
        } else {
            $deleteStatus = ['success' => false, 'message' => t('streaming_file_delete_failed')];
        }
    }
}

// Reopen session only once to write the result
session_start();
$_SESSION['delete_status'] = $deleteStatus;
header('Location: streaming.php');
exit();
?>
