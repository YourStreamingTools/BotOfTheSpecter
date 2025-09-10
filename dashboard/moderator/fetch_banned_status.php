<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

$access_token = $_SESSION['access_token'];
$broadcasterID = $_SESSION['editing_user'];
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

function getTwitchUserId($username, $accessToken, $clientID) {
    $url = "https://api.twitch.tv/helix/users?login=$username";
    $headers = ["Authorization: Bearer $accessToken","Client-Id: $clientID"];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['data'][0]['id'] ?? null;
}

// Ensure the banned status check is scoped to the broadcaster
function isUserBanned($userId, $accessToken, $broadcasterID, $clientID) {
    $url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=$broadcasterID&user_id=$userId";
    $headers = ["Authorization: Bearer $accessToken","Client-Id: $clientID"];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return !empty($data['data']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $cacheDirectory = "cache/$broadcasterID";
    $cacheFile = "$cacheDirectory/bannedUsers.json";
    $tempCacheFile = "$cacheFile.tmp";
    $cacheExpiration = 600; // Cache expires after 10 minutes
    $bannedUsersCache = [];
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
        $cacheContent = file_get_contents($cacheFile);
        if ($cacheContent) { $bannedUsersCache = json_decode($cacheContent, true); }
    }
    $result = [];
    if (isset($_POST['usernames'])) {
        // Batch processing
        $usernames = json_decode($_POST['usernames'], true);
        if (!is_array($usernames)) {
            $usernames = [$usernames];
        }
        $usernamesToCheck = [];
        foreach ($usernames as $username) {
            if (isset($bannedUsersCache[$username])) {
                $result[$username] = $bannedUsersCache[$username];
            } else {
                $usernamesToCheck[] = $username;
            }
        }
        if (!empty($usernamesToCheck)) {
            // Batch fetch user IDs
            $query = implode('&', array_map(function($u) { return 'login=' . urlencode($u); }, $usernamesToCheck));
            $url = "https://api.twitch.tv/helix/users?$query";
            $headers = ["Authorization: Bearer $access_token","Client-Id: $clientID"];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($response, true);
            $userIds = [];
            $usernameToId = [];
            if (isset($data['data'])) {
                foreach ($data['data'] as $user) {
                    $userIds[] = $user['id'];
                    $usernameToId[$user['login']] = $user['id'];
                }
            }
            // Batch check banned
            if (!empty($userIds)) {
                $query = 'broadcaster_id=' . urlencode($broadcasterID) . '&' . implode('&', array_map(function($id) { return 'user_id=' . urlencode($id); }, $userIds));
                $url = "https://api.twitch.tv/helix/moderation/banned?$query";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $response = curl_exec($ch);
                curl_close($ch);
                $bannedData = json_decode($response, true);
                $bannedUserIds = [];
                if (isset($bannedData['data'])) {
                    foreach ($bannedData['data'] as $banned) {
                        $bannedUserIds[] = $banned['user_id'];
                    }
                }
                foreach ($usernamesToCheck as $username) {
                    $userId = $usernameToId[$username] ?? null;
                    $banned = $userId && in_array($userId, $bannedUserIds);
                    $result[$username] = $banned;
                    $bannedUsersCache[$username] = $banned;
                }
            } else {
                foreach ($usernamesToCheck as $username) {
                    $result[$username] = false;
                    $bannedUsersCache[$username] = false;
                }
            }
        }
        // Update cache
        if (!is_dir($cacheDirectory)) { mkdir($cacheDirectory, 0755, true); }
        if (!empty($bannedUsersCache) && $tempFileHandle = fopen($tempCacheFile, 'w')) {
            if (flock($tempFileHandle, LOCK_EX)) {
                fwrite($tempFileHandle, json_encode($bannedUsersCache));
                fflush($tempFileHandle);
                flock($tempFileHandle, LOCK_UN);
            }
            fclose($tempFileHandle);
            rename($tempCacheFile, $cacheFile);
        }
        echo json_encode($result);
    } elseif (isset($_POST['usernameToCheck'])) {
        // Single user processing (backward compatibility)
        $username = $_POST['usernameToCheck'];
        if (isset($bannedUsersCache[$username])) {
            $banned = $bannedUsersCache[$username];
        } else {
            $userId = getTwitchUserId($username, $access_token, $clientID);
            if ($userId) {
                $banned = isUserBanned($userId, $access_token, $broadcasterID, $clientID);
                $bannedUsersCache[$username] = $banned;
                if (!is_dir($cacheDirectory)) {
                    mkdir($cacheDirectory, 0755, true);
                }
                if (!empty($bannedUsersCache) && $tempFileHandle = fopen($tempCacheFile, 'w')) {
                    if (flock($tempFileHandle, LOCK_EX)) {
                        fwrite($tempFileHandle, json_encode($bannedUsersCache));
                        fflush($tempFileHandle);
                        flock($tempFileHandle, LOCK_UN);
                    }
                    fclose($tempFileHandle);
                    rename($tempCacheFile, $cacheFile);
                }
            } else {
                $banned = false;
            }
        }
        echo json_encode(['banned' => $banned]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request']);
    }
}
?>