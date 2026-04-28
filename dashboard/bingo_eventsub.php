<?php
session_start();
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
session_write_close();

header('Content-Type: application/json');

// $clientID and $clientSecret are provided by twitch.php (sourced from the database).
// Read the webhook secret from bot_chat_token — same table twitch.php uses.
$webhookSecret = '';
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $res = $conn->query("SELECT twitch_extension_bits_secret FROM bot_chat_token ORDER BY id ASC LIMIT 1");
    if ($res) {
        $row = $res->fetch_assoc();
        $webhookSecret = trim((string)($row['twitch_extension_bits_secret'] ?? ''));
    }
}

$CALLBACK_URL        = 'https://api.botofthespecter.com/twitch/extension/bits';
$BINGO_EXTENSION_ID  = '2xfbxuwg9rwty88d1qlmbn3j3g5x84';

// Get an App Access Token via client_credentials
function getAppAccessToken($clientID, $clientSecret) {
    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $clientID,
        'client_secret' => $clientSecret,
        'grant_type'    => 'client_credentials',
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }
    return null;
}

function twitchRequest($url, $method, $clientID, $accessToken, $body = null) {
    $ch = curl_init($url);
    $headers = [
        'Client-Id: ' . $clientID,
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true)];
}

if (empty($clientID) || empty($clientSecret)) {
    echo json_encode(['success' => false, 'error' => 'Server credentials not configured']);
    exit;
}

$appToken = getAppAccessToken($clientID, $clientSecret);
if (!$appToken) {
    echo json_encode(['success' => false, 'error' => 'Failed to obtain app access token']);
    exit;
}

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    $result = twitchRequest(
        'https://api.twitch.tv/helix/eventsub/subscriptions?type=extension.bits_transaction.create',
        'GET', $clientID, $appToken
    );
    if ($result['code'] === 200) {
        $subs = $result['data']['data'] ?? [];
        $found = null;
        foreach ($subs as $sub) {
            if (
                ($sub['transport']['callback'] ?? '') === $CALLBACK_URL &&
                ($sub['condition']['extension_client_id'] ?? '') === $BINGO_EXTENSION_ID
            ) {
                $found = $sub;
                break;
            }
        }
        echo json_encode(['success' => true, 'subscription' => $found]);
    } else {
        $msg = $result['data']['message'] ?? 'Unknown error';
        echo json_encode(['success' => false, 'error' => $msg, 'code' => $result['code']]);
    }

} elseif ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($webhookSecret)) {
        echo json_encode(['success' => false, 'error' => 'TWITCH_EXTENSION_BITS_SECRET not set in server config']);
        exit;
    }
    $body = [
        'type'      => 'extension.bits_transaction.create',
        'version'   => '1',
        'condition' => ['extension_client_id' => $BINGO_EXTENSION_ID],
        'transport' => [
            'method'   => 'webhook',
            'callback' => $CALLBACK_URL,
            'secret'   => $webhookSecret,
        ],
    ];
    $result = twitchRequest(
        'https://api.twitch.tv/helix/eventsub/subscriptions',
        'POST', $clientID, $appToken, $body
    );
    if ($result['code'] === 202) {
        echo json_encode(['success' => true, 'subscription' => $result['data']['data'][0] ?? null]);
    } else {
        $msg = $result['data']['message'] ?? 'Unknown error';
        echo json_encode(['success' => false, 'error' => $msg, 'code' => $result['code']]);
    }

} elseif ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $subId = $_POST['subscription_id'] ?? '';
    if (empty($subId)) {
        echo json_encode(['success' => false, 'error' => 'Missing subscription_id']);
        exit;
    }
    $result = twitchRequest(
        'https://api.twitch.tv/helix/eventsub/subscriptions?id=' . urlencode($subId),
        'DELETE', $clientID, $appToken
    );
    if ($result['code'] === 204) {
        echo json_encode(['success' => true]);
    } else {
        $msg = $result['data']['message'] ?? 'Unknown error';
        echo json_encode(['success' => false, 'error' => $msg, 'code' => $result['code']]);
    }

} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
