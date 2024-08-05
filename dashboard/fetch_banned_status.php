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
$cacheUsername = $_SESSION['username'];
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

function getTwitchUserId($username, $accessToken, $clientID) {
    $url = "https://api.twitch.tv/helix/users?login=$username";
    $headers = [
        "Authorization: Bearer $accessToken",
        "Client-Id: $clientID"
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

function isUserBanned($userId, $accessToken, $broadcasterID, $clientID) {
    $url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=$broadcasterID&user_id=$userId";
    $headers = [
        "Authorization: Bearer $accessToken",
        "Client-Id: $clientID"
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

    $cacheDirectory = "cache/$cacheUsername";
    $cacheFile = "$cacheDirectory/bannedUsers.json";
    $cacheExpiration = 600; // Cache expires after 10 minutes

    $bannedUsersCache = [];
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
        $cacheContent = file_get_contents($cacheFile);
        if ($cacheContent) {
            $bannedUsersCache = json_decode($cacheContent, true);
            error_log("Loaded cache content: $cacheContent");
        } else {
            error_log("Cache file is empty for user $cacheUsername");
        }
    } else {
        error_log("Cache file does not exist or is expired for user $cacheUsername");
    }

    if (isset($bannedUsersCache[$username])) {
        error_log("Using cached banned status for $username");
        $banned = $bannedUsersCache[$username];
    } else {
        $userId = getTwitchUserId($username, $access_token, $clientID);
        if ($userId) {
            $banned = isUserBanned($userId, $access_token, $broadcasterID, $clientID);
            error_log("$username (ID: $userId) banned status: " . ($banned ? "banned" : "not banned"));
            $bannedUsersCache[$username] = $banned;

            if (!is_dir($cacheDirectory)) {
                mkdir($cacheDirectory, 0755, true);
            }
            file_put_contents($cacheFile, json_encode($bannedUsersCache));
            error_log("Updated cache file for $cacheUsername: " . json_encode($bannedUsersCache));
        } else {
            error_log("Failed to fetch user ID for $username");
            $banned = false;
        }
    }

    echo json_encode(['banned' => $banned]);
} else {
    http_response_code(400);
    error_log("Bad request");
    echo json_encode(['error' => 'Bad request']);
}
?>