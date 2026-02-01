<?php
session_start();
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
include "userdata.php";

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get the posted data
$target_username = trim($_POST['username'] ?? '');
$welcome_message = trim($_POST['message'] ?? '');

if (empty($target_username) || empty($welcome_message)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Username and message are required']);
    exit();
}

// Get the channel ID (broadcaster ID) from the session user
$channel_id = $_SESSION['twitchUserId'];

// Check for the (shoutout) variable in the welcome message
$has_shoutout = stripos($welcome_message, '(shoutout)') !== false;

// Remove the (shoutout) variable from the message to send
$message_to_send = str_ireplace('(shoutout)', '', $welcome_message);
$message_to_send = trim($message_to_send);

// If the message is empty after removing (shoutout), don't send anything
if (empty($message_to_send) && !$has_shoutout) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Welcome message is empty']);
    exit();
}

$messages_sent = [];
$errors = [];

// Send the welcome message if it has content
if (!empty($message_to_send)) {
    $url = "https://api.twitch.tv/helix/chat/messages";
    $headers = [
        "Authorization: Bearer " . $oauth,
        "Client-Id: " . $clientID,
        "Content-Type: application/json"
    ];
    $data = [
        "broadcaster_id" => $channel_id,
        "sender_id" => "971436498", // Bot's user ID
        "message" => $message_to_send
    ];
    // Log what we're about to send
    file_put_contents('/var/www/logs/test_welcome_debug.log', sprintf(
        "[%s] SENDING: broadcaster_id=%s sender_id=%s message='%s'\n",
        date('Y-m-d H:i:s'),
        $channel_id,
        "971436498",
        substr($message_to_send, 0, 100)
    ), FILE_APPEND);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error) {
        $errors[] = "Welcome message failed: " . $curl_error;
    } elseif ($http_code === 200) {
        $response_data = json_decode($response, true);
        if ($response_data && isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data']) > 0) {
            $msg_data = $response_data['data'][0];
            $is_sent = $msg_data['is_sent'] ?? false;
            $drop_reason = $msg_data['drop_reason'] ?? null;
            // Log the actual response for debugging
            file_put_contents('/var/www/logs/test_welcome_debug.log', sprintf(
                "[%s] Welcome msg response: is_sent=%s drop_reason=%s\n",
                date('Y-m-d H:i:s'),
                $is_sent ? 'true' : 'false',
                $drop_reason ?? 'none'
            ), FILE_APPEND);
            if ($is_sent) {
                $messages_sent[] = "Welcome message";
            } else {
                $error_msg = "Welcome message not sent";
                if ($drop_reason) {
                    $error_msg .= " (Drop reason: " . $drop_reason . ")";
                }
                $errors[] = $error_msg;
            }
        } else {
            $errors[] = "Invalid response from Twitch API for welcome message";
        }
    } else {
        $error_msg = "Failed to send welcome message. HTTP $http_code";
        if ($response) {
            $response_data = json_decode($response, true);
            if ($response_data && isset($response_data['message'])) {
                $error_msg .= ": " . $response_data['message'];
            }
        }
        $errors[] = $error_msg;
    }
}

// If (shoutout) was found, get the last game and send a shoutout message
if ($has_shoutout) {
    // Get the target user's ID from Twitch API
    $user_url = "https://api.twitch.tv/helix/users?login=" . urlencode($target_username);
    $headers = [
        "Authorization: Bearer " . $oauth,
        "Client-Id: " . $clientID
    ];
    $ch = curl_init($user_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $user_response = curl_exec($ch);
    $user_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($user_http_code === 200) {
        $user_data = json_decode($user_response, true);
        if ($user_data && isset($user_data['data']) && count($user_data['data']) > 0) {
            $target_user_id = $user_data['data'][0]['id'];
            // Check if the user is currently streaming
            $stream_url = "https://api.twitch.tv/helix/streams?user_id=" . urlencode($target_user_id);
            $ch = curl_init($stream_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $stream_response = curl_exec($ch);
            $stream_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $last_game = null;
            $is_online = false;
            if ($stream_http_code === 200) {
                $stream_data = json_decode($stream_response, true);
                if ($stream_data && isset($stream_data['data']) && count($stream_data['data']) > 0) {
                    // User is online, get current game
                    $last_game = $stream_data['data'][0]['game_name'] ?? null;
                    $is_online = true;
                }
            }
            // If user is offline, get their channel info for last game
            if (!$is_online) {
                $channel_url = "https://api.twitch.tv/helix/channels?broadcaster_id=" . urlencode($target_user_id);
                $ch = curl_init($channel_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $channel_response = curl_exec($ch);
                $channel_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($channel_http_code === 200) {
                    $channel_data = json_decode($channel_response, true);
                    if ($channel_data && isset($channel_data['data']) && count($channel_data['data']) > 0) {
                        $last_game = $channel_data['data'][0]['game_name'] ?? null;
                    }
                }
            }
            // Send shoutout message with last game info
            if ($last_game) {
                $shoutout_message = "Check out {$target_username}! They were last playing {$last_game}. https://twitch.tv/{$target_username}";
            } else {
                $shoutout_message = "Check out {$target_username}! https://twitch.tv/{$target_username}";
            }
            $url = "https://api.twitch.tv/helix/chat/messages";
            $headers = [
                "Authorization: Bearer " . $oauth,
                "Client-Id: " . $clientID,
                "Content-Type: application/json"
            ];
            $data = [
                "broadcaster_id" => $channel_id,
                "sender_id" => "971436498", // Bot's user ID
                "message" => $shoutout_message
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            if ($curl_error) {
                $errors[] = "Shoutout message failed: " . $curl_error;
            } elseif ($http_code === 200) {
                $response_data = json_decode($response, true);
                if ($response_data && isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data']) > 0) {
                    $msg_data = $response_data['data'][0];
                    $is_sent = $msg_data['is_sent'] ?? false;
                    $drop_reason = $msg_data['drop_reason'] ?? null;
                    // Log the actual response for debugging
                    file_put_contents('/var/www/logs/test_welcome_debug.log', sprintf(
                        "[%s] Shoutout msg response: is_sent=%s drop_reason=%s\n",
                        date('Y-m-d H:i:s'),
                        $is_sent ? 'true' : 'false',
                        $drop_reason ?? 'none'
                    ), FILE_APPEND);
                    if ($is_sent) {
                        $messages_sent[] = "Shoutout message" . ($last_game ? " (with last game: {$last_game})" : "");
                    } else {
                        $error_msg = "Shoutout message not sent";
                        if ($drop_reason) {
                            $error_msg .= " (Drop reason: " . $drop_reason . ")";
                        }
                        $errors[] = $error_msg;
                    }
                } else {
                    $errors[] = "Invalid response from Twitch API for shoutout";
                }
            } else {
                $error_msg = "Failed to send shoutout. HTTP $http_code";
                if ($response) {
                    $response_data = json_decode($response, true);
                    if ($response_data && isset($response_data['message'])) {
                        $error_msg .= ": " . $response_data['message'];
                    }
                }
                $errors[] = $error_msg;
            }
        } else {
            $errors[] = "Could not find user: {$target_username}";
        }
    } else {
        $errors[] = "Failed to get user info for shoutout. HTTP {$user_http_code}";
    }
}

// Return the result
header('Content-Type: application/json');
if (count($messages_sent) > 0 && count($errors) === 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Successfully sent: ' . implode(', ', $messages_sent)
    ]);
} elseif (count($messages_sent) > 0 && count($errors) > 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Partially sent: ' . implode(', ', $messages_sent) . '. Errors: ' . implode(', ', $errors)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send messages. Errors: ' . implode(', ', $errors)
    ]);
}
exit();
?>