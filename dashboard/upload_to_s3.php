<?php
// Initialize the session
session_start();

// Include internationalization
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_authentication_required')]);
    exit();
}

// Include necessary files
include 'userdata.php';
include_once "/var/www/config/ssh.php";

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_invalid_request_method')]);
    exit();
}

// Get POST parameters
$server = isset($_POST['server']) ? $_POST['server'] : '';
$file = isset($_POST['file']) ? $_POST['file'] : '';
$username_param = isset($_POST['username']) ? $_POST['username'] : '';

// Validate parameters
if (empty($server) || empty($file) || empty($username_param)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_missing_parameters')]);
    exit();
}

// Server connection details and region mapping
$server_details = [
    'au-east-1' => [
        'host' => $stream_au_east_1_host,
        'username' => $stream_au_east_1_username,
        'password' => $stream_au_east_1_password,
        'script_path' => '/home/botofthespecter/upload_to_persistent_storage.py',
        'region' => 'au'
    ],
    'us-west-1' => [
        'host' => $stream_us_west_1_host,
        'username' => $stream_us_west_1_username,
        'password' => $stream_us_west_1_password,
        'script_path' => '/home/botofthespecter/upload_to_persistent_storage.py',
        'region' => 'us'
    ],
    'us-east-1' => [
        'host' => $stream_us_east_1_host,
        'username' => $stream_us_east_1_username,
        'password' => $stream_us_east_1_password,
        'script_path' => '/home/botofthespecter/upload_to_persistent_storage.py',
        'region' => 'us'
    ]
];

if (!isset($server_details[$server])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_invalid_server_selection')]);
    exit();
}

$details = $server_details[$server];

// Check if SSH2 extension is available
if (!function_exists('ssh2_connect')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_ssh2_not_installed')]);
    exit();
}

// Connect to the server
$connection = @ssh2_connect($details['host'], 22);
if (!$connection) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_connection_failed')]);
    exit();
}

// Authenticate
if (!@ssh2_auth_password($connection, $details['username'], $details['password'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_authentication_failed')]);
    exit();
}

// Sanitize filename - remove URL encoding and get just the filename
$filename = urldecode($file);
$filename = basename($filename); // Get just the filename without path

// Build the Python command to run in background
// Format: python3 upload_to_persistent_storage.py username region filename
$safe_username = escapeshellarg($username_param);
$safe_region = escapeshellarg($details['region']);
$safe_filename = escapeshellarg($filename);

$command = "nohup python3 {$details['script_path']} $safe_username $safe_region $safe_filename > /dev/null 2>&1 &";

// Execute the command
$stream = @ssh2_exec($connection, $command);

if (!$stream) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('streaming_upload_script_failed')]);
    exit();
}

// Since we're running in background, we don't wait for output
// Just check if the command was sent successfully
fclose($stream);

// Log the upload attempt
error_log("Upload to persistent storage initiated for user: $username_param, region: {$details['region']}, file: $filename");

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => t('streaming_upload_initiated_success')
]);
?>
