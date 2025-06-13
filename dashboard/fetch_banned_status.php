<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

$access_token = $_SESSION['access_token'];
$broadcasterID = $_SESSION['twitch_user_id'];
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

function getTwitchUserIds($usernames, $accessToken, $clientID) {
    $loginParams = array_map(function($username) { return 'login=' . urlencode($username); }, $usernames);
    $url = "https://api.twitch.tv/helix/users?" . implode('&', $loginParams);
    $headers = [ "Authorization: Bearer $accessToken", "Client-Id: $clientID" ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    $userMap = [];
    if (isset($data['data'])) { foreach ($data['data'] as $user) { $userMap[$user['login']] = $user['id']; } }
    return $userMap;
}

function getBannedUsers($userIds, $accessToken, $broadcasterID, $clientID) {
    if (empty($userIds)) { return []; }
    $userIdParams = array_map(function($userId) { return 'user_id=' . urlencode($userId); }, $userIds);
    $url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=$broadcasterID&" . implode('&', $userIdParams);
    $headers = [ "Authorization: Bearer $accessToken", "Client-Id: $clientID" ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    $bannedUserIds = [];
    if (isset($data['data'])) { foreach ($data['data'] as $bannedUser) { $bannedUserIds[] = $bannedUser['user_id']; } }
    return $bannedUserIds;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['usernames'])) {
    $usernames = json_decode($_POST['usernames'], true);
    if (!is_array($usernames)) { http_response_code(400); echo json_encode(['error' => 'Invalid usernames format']); exit(); }
    $userMap = getTwitchUserIds($usernames, $access_token, $clientID);
    $userIds = array_values($userMap);
    $bannedUserIds = getBannedUsers($userIds, $access_token, $broadcasterID, $clientID);
    $result = ['bannedUsers' => []];
    foreach ($usernames as $username) {
        $userId = $userMap[$username] ?? null;
        $isBanned = $userId && in_array($userId, $bannedUserIds);
        $result['bannedUsers'][$username] = $isBanned;
    }
    echo json_encode($result);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
}
?>