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
    curl_close($ch);
    $data = json_decode($response, true);

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
    curl_close($ch);
    $data = json_decode($response, true);

    return !empty($data['data']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username'])) {
    $username = $_POST['username'];
    $userId = getTwitchUserId($username, $access_token);

    if ($userId) {
        $banned = isUserBanned($userId, $access_token, $broadcasterID);
        echo json_encode(['banned' => $banned]);
    } else {
        echo json_encode(['banned' => false]);
    }
}
?>