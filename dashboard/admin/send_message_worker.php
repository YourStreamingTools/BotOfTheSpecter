<?php
// Check if this is being called via command line or web request
if (php_sapi_name() !== 'cli') {
    // This is a web request - require admin authentication
    session_start();
    // Check if user is logged in and is an admin
    if (!isset($_SESSION['access_token']) || !isset($_SESSION['username'])) {
        http_response_code(401);
        exit('Unauthorized - Please log in');
    }
    // Include necessary files to get user data
    require_once '/var/www/config/db_connect.php';
    include '../userdata.php';
    include '/var/www/config/twitch.php';
    // Check if user is admin
    if (!isset($is_admin) || !$is_admin) {
        http_response_code(403);
        exit('Unauthorized - Admin access required');
    }
    // Handle POST request with payload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payload'])) {
        $raw = $_POST['payload'];
    } else {
        exit(0);
    }
} else {
    if ($argc < 2) {
        // Nothing to do
        exit(0);
    }
    $raw = $argv[1];
}

$payload = json_decode($raw, true);
if (!$payload) {
    if (php_sapi_name() !== 'cli') {
        http_response_code(400);
        exit('Invalid payload');
    }
    exit(0);
}

$broadcaster_id = $payload['broadcaster_id'] ?? '';
$sender_id = $payload['sender_id'] ?? '';
$message = $payload['message'] ?? '';
$chat_token = $oauth;
$clientID = $payload['clientID'] ?? '';

if (empty($broadcaster_id) || empty($message) || empty($chat_token) || empty($clientID)) {
    exit(0);
}

$url = "https://api.twitch.tv/helix/chat/messages";
$headers = [
    "Authorization: Bearer " . $chat_token,
    "Client-Id: " . $clientID,
    "Content-Type: application/json"
];
$data = [
    "broadcaster_id" => $broadcaster_id,
    "sender_id" => $sender_id,
    "message" => $message
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
// Set a reasonable timeout for the worker
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

// Log minimal info to a file for debugging if needed
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/send_message_worker.log';
$entry = date('c') . "\t" . ($http_code ?: '0') . "\t" . ($curl_errno ? $curl_error : 'OK') . "\t" . substr($message, 0, 200) . "\n";
@file_put_contents($logFile, $entry, FILE_APPEND);

// Output result as JSON for the calling process
$result = [
    'success' => $http_code === 204,
    'http_code' => $http_code,
    'error' => $curl_errno ? $curl_error : null,
    'response' => $response
];
echo json_encode($result);

exit(0);
