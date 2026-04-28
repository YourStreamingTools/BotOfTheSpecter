<?php
ob_start();
session_start();
if (!isset($_SESSION['access_token'])) {
    ob_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
session_write_close();

ob_clean();
header('Content-Type: application/json');

// Both values are set in the session by userdata.php on every page load.
$twitchUserId = $_SESSION['twitchUserId'] ?? '';
$userApiKey   = $_SESSION['api_key'] ?? '';

if (empty($twitchUserId) || empty($userApiKey)) {
    echo json_encode(['success' => false, 'error' => 'Session data missing — please reload the page']);
    exit;
}

// Callback URL uses the Twitch user ID (public info) — API key stays server-side only.
$CALLBACK_URL       = 'https://api.botofthespecter.com/twitch/extension/bits?twitch_user_id=' . urlencode($twitchUserId);
$BINGO_EXTENSION_ID = '2xfbxuwg9rwty88d1qlmbn3j3g5x84';

function getAppAccessToken($clientID, $clientSecret) {
    if (empty($clientID) || empty($clientSecret)) return null;
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
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-Id: ' . $clientID,
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true)];
}

try {
    if (empty($clientID) || empty($clientSecret)) {
        echo json_encode(['success' => false, 'error' => 'Server Twitch credentials not configured']);
        exit;
    }

    $appToken = getAppAccessToken($clientID, $clientSecret);
    if (!$appToken) {
        echo json_encode(['success' => false, 'error' => 'Failed to obtain Twitch app access token']);
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
        $body = [
            'type'      => 'extension.bits_transaction.create',
            'version'   => '1',
            'condition' => ['extension_client_id' => $BINGO_EXTENSION_ID],
            'transport' => [
                'method'   => 'webhook',
                'callback' => $CALLBACK_URL,
                'secret'   => $userApiKey,
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

} catch (Throwable $e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
