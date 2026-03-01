<?php
$use_backup = false;
if ($use_backup) {
    $clientID = "";
    $clientSecret = "";
} else {
    $clientID = "";
    $clientSecret = "";
}
$redirectURI = 'https://dashboard.botofthespecter.com/login.php';
$betaRedirectURI = 'https://beta.dashboard.botofthespecter.com/login.php';
$oauth = "";

if (!function_exists('botofthespecter_twitch_apply_db_override')) {
    function botofthespecter_twitch_apply_db_override($conn, &$clientID, &$clientSecret, &$oauth)
    {
        if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
            return;
        }
        $res = $conn->query("SELECT * FROM website LIMIT 1");
        if (!$res) {
            return;
        }
        $row = $res->fetch_assoc();
        if (!is_array($row)) {
            return;
        }
        foreach (['twitch_client_id', 'client_id', 'clientID'] as $clientIdKey) {
            if (array_key_exists($clientIdKey, $row) && !empty($row[$clientIdKey])) {
                $clientID = trim((string)$row[$clientIdKey]);
                break;
            }
        }
        foreach (['twitch_client_secret', 'client_secret', 'clientSecret'] as $clientSecretKey) {
            if (array_key_exists($clientSecretKey, $row) && !empty($row[$clientSecretKey])) {
                $clientSecret = trim((string)$row[$clientSecretKey]);
                break;
            }
        }
        foreach (['twitch_oauth_api_token', 'oauth', 'chat_oauth_token', 'twitch_oauth_token', 'twitch_access_token', 'bot_oauth_token'] as $oauthKey) {
            if (array_key_exists($oauthKey, $row) && !empty($row[$oauthKey])) {
                $oauth = trim((string)$row[$oauthKey]);
                break;
            }
        }
    }
}

if (!isset($conn)) {
    include_once '/var/www/config/db_connect.php';
}

if (isset($conn)) {
    botofthespecter_twitch_apply_db_override($conn, $clientID, $clientSecret, $oauth);
}

$GLOBALS['oauth'] = $oauth;
?>