<?php
session_start();

// Include internationalization
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

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
    echo json_encode(['success' => false, 'message' => t('streaming_missing_parameters')]);
    exit();
}
$selected_server = $_GET['server'];
$oldFilename = $_GET['file'];
if (strpos($oldFilename, '/') !== false || strpos($oldFilename, '\\') !== false || in_array($oldFilename, ['.', '..'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => t('streaming_invalid_filename')]);
    exit();
}
// Extract file extension
$extension = pathinfo($oldFilename, PATHINFO_EXTENSION);

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
    default:
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => t('streaming_invalid_server_selection')]);
        exit();
}
if (!function_exists('ssh2_connect')) {
    echo json_encode(['success' => false, 'message' => t('streaming_ssh2_not_installed')]);
    exit();
}
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    echo json_encode(['success' => false, 'message' => t('streaming_connection_failed')]);
    exit();
}
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    echo json_encode(['success' => false, 'message' => t('streaming_authentication_failed')]);
    exit();
}
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    echo json_encode(['success' => false, 'message' => t('streaming_sftp_init_failed')]);
    exit();
}
$oldPath = "{$user_dir}/{$oldFilename}";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_title'])) {
    $newTitleRaw = trim($_POST['new_title']);    // Replace spaces with underscores
    $newTitle = str_replace(' ', '_', $newTitleRaw);
    $newFilename = $newTitle . '.' . $extension;
    $newPath = "{$user_dir}/{$newFilename}";
    // Attempt rename
    if (@ssh2_sftp_rename($sftp, $oldPath, $newPath)) {
        $_SESSION['edit_status'] = ['success' => true, 'message' => t('streaming_file_renamed_success')];
        echo json_encode(['success' => true, 'message' => t('streaming_file_renamed_success'), 'newFilename' => $newFilename]);
    } else {
        $_SESSION['edit_status'] = ['success' => false, 'message' => t('streaming_file_rename_failed')];
        echo json_encode(['success' => false, 'message' => t('streaming_file_rename_failed')]);
    }
    exit();
} else {
    $currentTitle = pathinfo($oldFilename, PATHINFO_FILENAME);
    echo json_encode(['success' => true, 'currentTitle' => $currentTitle, 'filename' => $oldFilename]);
    exit();
}
?>
