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

// Debug logging
error_log('save_discord_channel_config received: ' . json_encode($input));

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
        case 'save_reaction_roles':
        case 'send_reaction_roles_message':
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
                case 'save_reaction_roles':
                    error_log('Processing save_reaction_roles with input: ' . json_encode($input));
                    if (!isset($input['reaction_roles_channel_id'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID is required']);
                        exit();
                    }
                    $reaction_roles_channel_id = trim($input['reaction_roles_channel_id']);
                    $reaction_roles_message = isset($input['reaction_roles_message']) ? trim($input['reaction_roles_message']) : '';
                    $reaction_roles_mappings = isset($input['reaction_roles_mappings']) ? trim($input['reaction_roles_mappings']) : '';
                    $allow_multiple_reactions = isset($input['allow_multiple_reactions']) ? (bool)$input['allow_multiple_reactions'] : false;
                    if (empty($reaction_roles_channel_id)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID cannot be empty']);
                        exit();
                    }
                    // Check if required columns exist and create them if they don't
                    $required_columns = [
                        'reaction_roles_configuration_channel' => 'VARCHAR(255) DEFAULT NULL',
                        'reaction_roles_configuration_message' => 'TEXT DEFAULT NULL',
                        'reaction_roles_configuration_mappings' => 'TEXT DEFAULT NULL',
                        'reaction_roles_configuration_allow_multiple' => 'TINYINT(1) DEFAULT 0'
                    ];
                    foreach ($required_columns as $column_name => $column_definition) {
                        $column_check = $discord_conn->prepare("SHOW COLUMNS FROM server_management LIKE ?");
                        $column_check->bind_param("s", $column_name);
                        $column_check->execute();
                        $column_result = $column_check->get_result();
                        
                        if ($column_result->num_rows == 0) {
                            // Column doesn't exist, create it
                            $alter_sql = "ALTER TABLE server_management ADD COLUMN `$column_name` $column_definition";
                            if (!$discord_conn->query($alter_sql)) {
                                error_log("Failed to add column $column_name: " . $discord_conn->error);
                                http_response_code(500);
                                echo json_encode(['success' => false, 'message' => 'Database schema error: Failed to create required columns']);
                                exit();
                            }
                        }
                        $column_check->close();
                    }
                    // Check if record exists for this server
                    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
                    if (!$checkStmt) {
                        error_log('Failed to prepare record check statement: ' . $discord_conn->error);
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database error: Failed to check record existence']);
                        exit();
                    }
                    $checkStmt->bind_param("s", $server_id);
                    if (!$checkStmt->execute()) {
                        error_log('Failed to execute record check: ' . $checkStmt->error);
                        $checkStmt->close();
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database error: Failed to check record']);
                        exit();
                    }
                    $result = $checkStmt->get_result();
                    if ($result->num_rows > 0) {
                        // Update existing record using separate columns
                        $updateStmt = $discord_conn->prepare("UPDATE server_management SET reaction_roles_configuration_channel = ?, reaction_roles_configuration_message = ?, reaction_roles_configuration_mappings = ?, reaction_roles_configuration_allow_multiple = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                        if (!$updateStmt) {
                            error_log('Failed to prepare update statement: ' . $discord_conn->error);
                            $checkStmt->close();
                            http_response_code(500);
                            echo json_encode(['success' => false, 'message' => 'Database error: Failed to prepare update']);
                            exit();
                        }
                        $updateStmt->bind_param("sssds", $reaction_roles_channel_id, $reaction_roles_message, $reaction_roles_mappings, $allow_multiple_reactions, $server_id);
                        $success = $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        // Insert new record using separate columns
                        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, reaction_roles_configuration_channel, reaction_roles_configuration_message, reaction_roles_configuration_mappings, reaction_roles_configuration_allow_multiple) VALUES (?, ?, ?, ?, ?)");
                        if (!$insertStmt) {
                            error_log('Failed to prepare insert statement: ' . $discord_conn->error);
                            $checkStmt->close();
                            http_response_code(500);
                            echo json_encode(['success' => false, 'message' => 'Database error: Failed to prepare insert']);
                            exit();
                        }
                        $insertStmt->bind_param("sssds", $server_id, $reaction_roles_channel_id, $reaction_roles_message, $reaction_roles_mappings, $allow_multiple_reactions);
                        $success = $insertStmt->execute();
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        // Send websocket notification to update the bot
                        $websocket_url = 'https://websocket.botofthespecter.com/notify'; // Production websocket server
                        $params = [
                            'action' => 'update_reaction_roles',
                            'server_id' => $server_id,
                            'user_id' => $user_id,
                            'reaction_roles_config' => json_encode([
                                'channel_id' => $reaction_roles_channel_id,
                                'message' => $reaction_roles_message,
                                'mappings' => $reaction_roles_mappings,
                                'allow_multiple' => $allow_multiple_reactions
                            ])
                        ];
                        // Build query string
                        $query_string = http_build_query($params);
                        $full_url = $websocket_url . '?' . $query_string;
                        // Send HTTP GET request to websocket server
                        $ch = curl_init($full_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        // Log websocket notification result (optional)
                        if ($http_code !== 200) {
                            error_log("Failed to send websocket notification for reaction roles: HTTP $http_code, Response: $response");
                        } else {
                            error_log("Successfully sent websocket notification for reaction roles");
                        }
                        echo json_encode([
                            'success' => true,
                            'message' => 'Reaction roles configuration saved successfully',
                            'channel_id' => $reaction_roles_channel_id,
                            'allow_multiple' => $allow_multiple_reactions
                        ]);
                    } else {
                        error_log('Database operation failed: ' . $discord_conn->error);
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
                    }
                    break;
                case 'send_reaction_roles_message':
                    if (!isset($input['reaction_roles_channel_id'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID is required']);
                        exit();
                    }
                    $reaction_roles_channel_id = trim($input['reaction_roles_channel_id']);
                    $reaction_roles_message = isset($input['reaction_roles_message']) ? trim($input['reaction_roles_message']) : '';
                    $reaction_roles_mappings = isset($input['reaction_roles_mappings']) ? trim($input['reaction_roles_mappings']) : '';
                    $allow_multiple_reactions = isset($input['allow_multiple_reactions']) ? (bool)$input['allow_multiple_reactions'] : false;
                    if (empty($reaction_roles_channel_id)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID cannot be empty']);
                        exit();
                    }
                    if (empty($reaction_roles_message)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles message cannot be empty']);
                        exit();
                    }
                    if (empty($reaction_roles_mappings)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles mappings cannot be empty']);
                        exit();
                    }
                    // Send websocket notification to post the message to Discord channel
                    $websocket_url = 'https://websocket.botofthespecter.com/notify'; // Production websocket server
                    $params = [
                        'code' => $_SESSION['api_key'],
                        'event' => 'post_reaction_roles_message',
                        'server_id' => $server_id,
                        'channel_id' => $reaction_roles_channel_id,
                        'message' => $reaction_roles_message,
                        'mappings' => $reaction_roles_mappings,
                        'allow_multiple' => $allow_multiple_reactions ? 'true' : 'false'
                    ];
                    // Build query string
                    $query_string = http_build_query($params);
                    $full_url = $websocket_url . '?' . $query_string;
                    // Send HTTP GET request to websocket server
                    $ch = curl_init($full_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    // Check if websocket notification was successful
                    if ($http_code !== 200) {
                        error_log("Failed to send websocket notification for reaction roles: HTTP $http_code, Response: $response");
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Failed to send message to Discord channel']);
                        exit();
                    } else {
                        error_log("Successfully sent websocket notification for reaction roles");
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Reaction roles message sent to Discord channel successfully',
                            'channel_id' => $reaction_roles_channel_id,
                            'allow_multiple' => $allow_multiple_reactions
                        ]);
                    }
                    break;
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
