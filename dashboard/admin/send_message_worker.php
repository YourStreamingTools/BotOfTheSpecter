<?php
// send_message_worker.php
// Usage: php send_message_worker.php '{"broadcaster_id":"...","sender_id":"...","message":"...","twitch_bot_oauth":"...","clientID":"..."}'
// This script performs a single HTTP request to Twitch to send a chat message.

if ($argc < 2) {
    // Nothing to do
    exit(0);
}

$raw = $argv[1];
$payload = json_decode($raw, true);
if (!$payload) {
    exit(0);
}

$broadcaster_id = $payload['broadcaster_id'] ?? '';
$sender_id = $payload['sender_id'] ?? '';
$message = $payload['message'] ?? '';
$twitch_bot_oauth = $payload['twitch_bot_oauth'] ?? '';
$clientID = $payload['clientID'] ?? '';

if (empty($broadcaster_id) || empty($message) || empty($twitch_bot_oauth) || empty($clientID)) {
    exit(0);
}

$url = "https://api.twitch.tv/helix/chat/messages";
$headers = [
    "Authorization: Bearer " . $twitch_bot_oauth,
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

exit(0);
