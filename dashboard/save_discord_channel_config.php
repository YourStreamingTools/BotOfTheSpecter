<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Include database connection
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

// Get user_id from session (simplified approach)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User session not found']);
    exit();
}
$user_id = $_SESSION['user_id'];

// Check if request is POST and has the required data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action']) || !isset($input['server_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$action = $input['action'];
$server_id = $input['server_id'];

// Validate server_id is not empty
if (empty($server_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Server ID is required']);
    exit();
}

try {
    switch ($action) {
        case 'save_welcome_message':
        case 'save_auto_role':
        case 'save_message_tracking':
        case 'save_role_tracking':
        case 'save_server_role_management':
        case 'save_user_tracking':
            // These are server management features that go to the Discord bot database
            // Connect to specterdiscordbot database
            $discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
            if ($discord_conn->connect_error) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Discord database connection failed']);
                exit();
            }
            // Handle server management settings (existing logic)
            switch ($action) {
                case 'save_welcome_message':
                    if (!isset($input['welcome_channel_id'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Welcome channel ID is required']);
                        exit();
                    }
                    $welcome_channel_id = $input['welcome_channel_id'];
                    $welcome_message = isset($input['welcome_message']) ? trim($input['welcome_message']) : '';
                    $welcome_use_default = isset($input['welcome_message_configuration_default']) ? 1 : 0;
                    if (empty($welcome_channel_id)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Welcome channel ID cannot be empty']);
                        exit();
                    }
                    // If not using default message, require custom welcome message text
                    if (!$welcome_use_default && empty($welcome_message)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Please enter a welcome message or enable "Use default welcome message"']);
                        exit();
                    }
                    // Check if record exists for this server
                    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
                    $checkStmt->bind_param("s", $server_id);
                    $checkStmt->execute();
                    $result = $checkStmt->get_result();
                    if ($result->num_rows > 0) {
                        // Update existing record
                        $updateStmt = $discord_conn->prepare("UPDATE server_management SET welcome_message_configuration_channel = ?, welcome_message_configuration_message = ?, welcome_message_configuration_default = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                        $updateStmt->bind_param("ssis", $welcome_channel_id, $welcome_message, $welcome_use_default, $server_id);
                        $success = $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        // Insert new record
                        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, welcome_message_configuration_channel, welcome_message_configuration_message, welcome_message_configuration_default) VALUES (?, ?, ?, ?)");
                        $insertStmt->bind_param("sssi", $server_id, $welcome_channel_id, $welcome_message, $welcome_use_default);
                        $success = $insertStmt->execute();
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Welcome message configuration saved successfully',
                            'channel_id' => $welcome_channel_id,
                            'use_default' => $welcome_use_default
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
                    }
                    break;
                case 'save_auto_role':
                    if (!isset($input['auto_role_id'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Auto role ID is required']);
                        exit();
                    }
                    $auto_role_id = $input['auto_role_id'];
                    if (empty($auto_role_id)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Auto role ID cannot be empty']);
                        exit();
                    }
                    // Check if record exists for this server
                    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
                    $checkStmt->bind_param("s", $server_id);
                    $checkStmt->execute();
                    $result = $checkStmt->get_result();
                    if ($result->num_rows > 0) {
                        // Update existing record
                        $updateStmt = $discord_conn->prepare("UPDATE server_management SET auto_role_assignment_configuration_role_id = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                        $updateStmt->bind_param("ss", $auto_role_id, $server_id);
                        $success = $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        // Insert new record
                        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, auto_role_assignment_configuration_role_id) VALUES (?, ?)");
                        $insertStmt->bind_param("ss", $server_id, $auto_role_id);
                        $success = $insertStmt->execute();
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Auto role configuration saved successfully',
                            'role_id' => $auto_role_id
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
                    }
                    break;
                // Add other server management cases here if needed
            }
            $discord_conn->close();
            break;
        case 'save_discord_channels':
            // This saves the main Discord configuration to the website database discord_users table
            if (!isset($input['guild_id']) || !isset($input['live_channel_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Guild ID and Live Channel ID are required']);
                exit();
            }
            $guild_id = $input['guild_id'];
            $live_channel_id = $input['live_channel_id'];
            $online_text = isset($input['online_text']) ? trim($input['online_text']) : '';
            $offline_text = isset($input['offline_text']) ? trim($input['offline_text']) : '';
            $stream_alert_channel_id = isset($input['stream_alert_channel_id']) ? $input['stream_alert_channel_id'] : null;
            $moderation_channel_id = isset($input['moderation_channel_id']) ? $input['moderation_channel_id'] : null;
            $alert_channel_id = isset($input['alert_channel_id']) ? $input['alert_channel_id'] : null;
            $member_streams_id = isset($input['member_streams_id']) ? $input['member_streams_id'] : null;
            // Validate character limits for online/offline text
            if (strlen($online_text) > 20) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Online text cannot exceed 20 characters']);
                exit();
            }
            if (strlen($offline_text) > 20) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Offline text cannot exceed 20 characters']);
                exit();
            }
            // Update the discord_users table in the website database
            $updateStmt = $conn->prepare("UPDATE discord_users SET guild_id = ?, live_channel_id = ?, online_text = ?, offline_text = ?, stream_alert_channel_id = ?, moderation_channel_id = ?, alert_channel_id = ?, member_streams_id = ? WHERE user_id = ?");
            $updateStmt->bind_param("ssssssssi", $guild_id, $live_channel_id, $online_text, $offline_text, $stream_alert_channel_id, $moderation_channel_id, $alert_channel_id, $member_streams_id, $user_id);
            if ($updateStmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Discord configuration saved successfully',
                    'guild_id' => $guild_id,
                    'live_channel_id' => $live_channel_id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $conn->error]);
            }
            $updateStmt->close();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} finally {
    // Close database connections if they exist
    if (isset($discord_conn)) { $discord_conn->close(); }
    if (isset($conn)) { $conn->close(); }
}
?>
