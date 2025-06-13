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
include "/var/www/config/object_storage.php";
include "/var/www/config/ssh.php";
include 'userdata.php';

$from_persistent = isset($_GET['persistent']) && $_GET['persistent'] === 'true';

if ($from_persistent) {
    $filename = $_GET['file'];
    $file_url = "https://{$username}.{$bucket_url}/{$filename}";
    header("Location: $file_url");
    exit();
}

// Validate and get parameters
if (!isset($_GET['server']) || !isset($_GET['file'])) {
    header('Location: streaming.php');
    exit();
}

$selected_server = $_GET['server'];
$filename = $_GET['file'];
if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || in_array($filename, ['.', '..'])) {
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
    default:
        header('Location: streaming.php');
        exit();
}
if (!function_exists('ssh2_connect')) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_ssh2_not_installed');
    exit();
}
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_connection_failed');
    exit();
}
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_authentication_failed');
    exit();
}
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_sftp_init_failed');
    exit();
}
$file_path = "{$user_dir}/{$filename}";
$sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;
if (!file_exists($sftp_path)) {
    header('HTTP/1.1 404 Not Found');
    echo t('streaming_file_not_found');
    exit();
}
$filesize = filesize($sftp_path);
header('Content-Type: video/mp4');
header('Content-Disposition: inline; filename="' . basename($filename) . '"');
header('Content-Length: ' . $filesize);
$handle = @fopen($sftp_path, 'r');
if (!$handle) {
    header('HTTP/1.1 500 Internal Server Error');
    echo t('streaming_file_open_failed');
    exit();
}
$buffer_size = 8192;
while (!feof($handle)) {
    echo fread($handle, $buffer_size);
    ob_flush();
    flush();
}
fclose($handle);
exit();
?>
