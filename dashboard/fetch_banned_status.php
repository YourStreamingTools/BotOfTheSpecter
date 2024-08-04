<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['access_token'])) {
    exit();
}

$access_token = $_SESSION['access_token'];
$broadcasterID = $_SESSION['twitch_user_id'];

function getTwitchUserId($username, $accessToken) {
    $url = "https://api.twitch.tv/helix/users?login=$username";
    $headers = [
        "Authorization: Bearer $accessToken",
        "Client-Id: " . getenv('TWITCH_CLIENT_ID')
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('CURL error: ' . curl_error($ch));
    }
    curl_close($ch);
    $data = json_decode($response, true);

    error_log("Fetching Twitch user ID for $username: " . json_encode($data));
    return $data['data'][0]['id'] ?? null;
}

function isUserBanned($userId, $accessToken, $broadcasterID) {
    $url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=$broadcasterID&user_id=$userId";
    $headers = [
        "Authorization: Bearer $accessToken",
        "Client-Id: " . getenv('TWITCH_CLIENT_ID')
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('CURL error: ' . curl_error($ch));
    }
    curl_close($ch);
    $data = json_decode($response, true);

    error_log("Checking if user ID $userId is banned: " . json_encode($data));
    return !empty($data['data']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['usernameToCheck'])) {
    $username = $_POST['usernameToCheck'];
    error_log("Received request to check banned status for $username");
    $userId = getTwitchUserId($username, $access_token);

    if ($userId) {
        $banned = isUserBanned($userId, $access_token, $broadcasterID);
        error_log("$username (ID: $userId) banned status: " . ($banned ? "banned" : "not banned"));
        echo json_encode(['banned' => $banned]);
    } else {
        error_log("Failed to fetch user ID for $username");
        echo json_encode(['banned' => false]);
    }
} else {
    http_response_code(400);
    error_log("Bad request");
    echo json_encode(['error' => 'Bad request']);
}
?>