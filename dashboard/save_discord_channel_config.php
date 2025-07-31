<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Include database connection
include '/var/www/config/database.php';

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

// Connect to specterdiscordbot database
$discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
if ($discord_conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

try {
    switch ($action) {
        case 'save_welcome_message':
            if (!isset($input['welcome_channel_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Welcome channel ID is required']);
                exit();
            }
            $welcome_channel_id = $input['welcome_channel_id'];
            $welcome_use_default = isset($input['welcome_message_configuration_default']) ? 1 : 0;
            if (empty($welcome_channel_id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Welcome channel ID cannot be empty']);
                exit();
            }
            // Check if record exists for this server
            $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
            $checkStmt->bind_param("s", $server_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result->num_rows > 0) {
                // Update existing record
                $updateStmt = $discord_conn->prepare("UPDATE server_management SET welcome_message_configuration_channel = ?, welcome_message_configuration_default = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                $updateStmt->bind_param("sis", $welcome_channel_id, $welcome_use_default, $server_id);
                $success = $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, welcome_message_configuration_channel, welcome_message_configuration_default) VALUES (?, ?, ?)");
                $insertStmt->bind_param("ssi", $server_id, $welcome_channel_id, $welcome_use_default);
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
            if (!$checkStmt) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database prepare failed']);
                exit();
            }
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
        case 'save_message_tracking':
            if (!isset($input['message_log_channel_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Message log channel ID is required']);
                exit();
            }
            $message_log_channel_id = $input['message_log_channel_id'];
            if (empty($message_log_channel_id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Message log channel ID cannot be empty']);
                exit();
            }
            // Check if record exists for this server
            $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
            $checkStmt->bind_param("s", $server_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result->num_rows > 0) {
                // Update existing record
                $updateStmt = $discord_conn->prepare("UPDATE server_management SET message_tracking_configuration_channel = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                $updateStmt->bind_param("ss", $message_log_channel_id, $server_id);
                $success = $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, message_tracking_configuration_channel) VALUES (?, ?)");
                $insertStmt->bind_param("ss", $server_id, $message_log_channel_id);
                $success = $insertStmt->execute();
                $insertStmt->close();
            }
            $checkStmt->close();
            if ($success) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Message tracking configuration saved successfully',
                    'channel_id' => $message_log_channel_id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
            }
            break;
        case 'save_role_tracking':
            if (!isset($input['role_log_channel_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Role log channel ID is required']);
                exit();
            }
            $role_log_channel_id = $input['role_log_channel_id'];
            if (empty($role_log_channel_id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Role log channel ID cannot be empty']);
                exit();
            }
            // Check if record exists for this server
            $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
            $checkStmt->bind_param("s", $server_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result->num_rows > 0) {
                // Update existing record
                $updateStmt = $discord_conn->prepare("UPDATE server_management SET role_tracking_configuration_channel = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                $updateStmt->bind_param("ss", $role_log_channel_id, $server_id);
                $success = $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, role_tracking_configuration_channel) VALUES (?, ?)");
                $insertStmt->bind_param("ss", $server_id, $role_log_channel_id);
                $success = $insertStmt->execute();
                $insertStmt->close();
            }
            $checkStmt->close();
            if ($success) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Role tracking configuration saved successfully',
                    'channel_id' => $role_log_channel_id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
            }
            break;
        case 'save_server_role_management':
            if (!isset($input['server_mgmt_log_channel_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Server management log channel ID is required']);
                exit();
            }
            $server_mgmt_log_channel_id = $input['server_mgmt_log_channel_id'];
            if (empty($server_mgmt_log_channel_id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Server management log channel ID cannot be empty']);
                exit();
            }
            // Check if record exists for this server
            $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
            $checkStmt->bind_param("s", $server_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result->num_rows > 0) {
                // Update existing record
                $updateStmt = $discord_conn->prepare("UPDATE server_management SET server_role_management_configuration_channel = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                $updateStmt->bind_param("ss", $server_mgmt_log_channel_id, $server_id);
                $success = $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, server_role_management_configuration_channel) VALUES (?, ?)");
                $insertStmt->bind_param("ss", $server_id, $server_mgmt_log_channel_id);
                $success = $insertStmt->execute();
                $insertStmt->close();
            }
            $checkStmt->close();
            if ($success) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Server role management configuration saved successfully',
                    'channel_id' => $server_mgmt_log_channel_id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
            }
            break;
        case 'save_user_tracking':
            if (!isset($input['user_log_channel_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User log channel ID is required']);
                exit();
            }
            $user_log_channel_id = $input['user_log_channel_id'];
            if (empty($user_log_channel_id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User log channel ID cannot be empty']);
                exit();
            }
            // Check if record exists for this server
            $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
            $checkStmt->bind_param("s", $server_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result->num_rows > 0) {
                // Update existing record
                $updateStmt = $discord_conn->prepare("UPDATE server_management SET user_tracking_configuration_channel = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                $updateStmt->bind_param("ss", $user_log_channel_id, $server_id);
                $success = $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, user_tracking_configuration_channel) VALUES (?, ?)");
                $insertStmt->bind_param("ss", $server_id, $user_log_channel_id);
                $success = $insertStmt->execute();
                $insertStmt->close();
            }
            $checkStmt->close();
            if ($success) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'User tracking configuration saved successfully',
                    'channel_id' => $user_log_channel_id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
            }
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
    // Close the Discord database connection
    if (isset($discord_conn)) { $discord_conn->close(); }
}
?>
