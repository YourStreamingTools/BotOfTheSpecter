<?php
// Initialize the session
session_start();

// Set up error handler to catch errors and return as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $errstr . ' in ' . basename($errfile) . ' line ' . $errline
    ]);
    exit();
});

header('Content-Type: application/json');

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';

// Get access token from session
$accessToken = $_SESSION['access_token'];
$userId = $_SESSION['user_id'];

// Handle AJAX requests
$action = $_POST['action'] ?? $_GET['action'] ?? null;

try {
    switch ($action) {
        case 'fetch_subscriptions':
            fetchSubscriptions($accessToken, $clientID, $userId, $db);
            break;
        
        case 'delete_subscription':
            if (!isset($_POST['subscription_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing subscription_id']);
                exit();
            }
            deleteSubscription($_POST['subscription_id'], $accessToken, $clientID);
            break;
        
        case 'delete_session':
            if (!isset($_POST['subscription_ids'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing subscription_ids']);
                exit();
            }
            deleteSession($_POST['subscription_ids'], $accessToken, $clientID);
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage()
    ]);
}

function fetchSubscriptions($accessToken, $clientID, $userId, $db) {
    // Fetch all EventSub subscriptions
    $ch = curl_init('https://api.twitch.tv/helix/eventsub/subscriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-Id: ' . $clientID
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "cURL error: $curlError"]);
        exit();
    }
    
    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(['success' => false, 'error' => "Failed to fetch subscriptions. HTTP Code: $httpCode"]);
        exit();
    }
    
    $data = json_decode($response, true);
    $subscriptions = $data['data'] ?? [];
    
    // Group subscriptions by transport type, session, and status
    $websocketSubsEnabled = [];
    $websocketSubsDisabled = [];
    $webhookSubs = [];
    $sessionGroups = [];
    $sessionGroupsDisabled = [];
    
    foreach ($subscriptions as $sub) {
        if ($sub['transport']['method'] === 'websocket') {
            $sessionId = $sub['transport']['session_id'] ?? 'unknown';
            $isEnabled = ($sub['status'] === 'enabled');
            
            if ($isEnabled) {
                $websocketSubsEnabled[] = $sub;
                if (!isset($sessionGroups[$sessionId])) {
                    $sessionGroups[$sessionId] = [];
                }
                $sessionGroups[$sessionId][] = $sub;
            } else {
                $websocketSubsDisabled[] = $sub;
                if (!isset($sessionGroupsDisabled[$sessionId])) {
                    $sessionGroupsDisabled[$sessionId] = [];
                }
                $sessionGroupsDisabled[$sessionId][] = $sub;
            }
        } else {
            $webhookSubs[] = $sub;
        }
    }
    
    // Query session names from the database
    $sessionNames = [];
    try {
        $stmt = $db->prepare("SELECT session_id, session_name FROM eventsub_sessions");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $sessionNames[$row['session_id']] = $row['session_name'];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Failed to fetch session names: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'totalCount' => $data['total'] ?? 0,
            'totalCost' => $data['total_cost'] ?? 0,
            'maxCost' => $data['max_total_cost'] ?? 0,
            'websocketSubsEnabled' => $websocketSubsEnabled,
            'websocketSubsDisabled' => $websocketSubsDisabled,
            'webhookSubs' => $webhookSubs,
            'sessionGroups' => $sessionGroups,
            'sessionGroupsDisabled' => $sessionGroupsDisabled,
            'sessionNames' => $sessionNames,
            'userId' => $userId
        ]
    ]);
}

function deleteSubscription($subId, $accessToken, $clientID) {
    $ch = curl_init("https://api.twitch.tv/helix/eventsub/subscriptions?id=" . urlencode($subId));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-Id: ' . $clientID
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 204) {
        echo json_encode([
            'success' => true,
            'message' => 'Successfully deleted subscription'
        ]);
    } else {
        http_response_code($httpCode);
        echo json_encode([
            'success' => false,
            'error' => "Failed to delete subscription. HTTP Code: $httpCode"
        ]);
    }
}

function deleteSession($subscriptionIdsJson, $accessToken, $clientID) {
    $subIds = json_decode($subscriptionIdsJson, true);
    
    if (!is_array($subIds) || count($subIds) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid subscription_ids']);
        exit();
    }
    
    $deletedCount = 0;
    $failedCount = 0;
    
    foreach ($subIds as $subId) {
        $ch = curl_init("https://api.twitch.tv/helix/eventsub/subscriptions?id=" . urlencode($subId));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Client-Id: ' . $clientID
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 204) {
            $deletedCount++;
        } else {
            $failedCount++;
        }
        
        // Small delay to avoid rate limiting
        usleep(100000); // 0.1 seconds
    }
    
    if ($deletedCount > 0 && $failedCount === 0) {
        echo json_encode([
            'success' => true,
            'message' => "Successfully deleted {$deletedCount} subscription(s) from session."
        ]);
    } elseif ($deletedCount > 0 && $failedCount > 0) {
        echo json_encode([
            'success' => true,
            'message' => "Deleted {$deletedCount} subscription(s), but {$failedCount} failed.",
            'partial' => true
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => "Failed to delete subscriptions from session."
        ]);
    }
}
