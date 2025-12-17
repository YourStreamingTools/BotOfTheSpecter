<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Include database connection
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

// Get user_id from session (simplified approach)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'User session not found']);
    exit();
}
$user_id = $_SESSION['user_id'];

// Debug log collection for browser console
$debug_logs = [];
function debug_log($message) {
    global $debug_logs;
    $debug_logs[] = $message;
    error_log($message); // Also log to server
}

// Get API key from session or fetch from database
$api_key = null;
if (isset($_SESSION['api_key'])) {
    $api_key = $_SESSION['api_key'];
} else {
    // Fetch api_key from database if not in session
    $userSTMT = $conn->prepare("SELECT api_key FROM users WHERE id = ?");
    $userSTMT->bind_param("i", $user_id);
    if ($userSTMT->execute()) {
        $userResult = $userSTMT->get_result();
        if ($userRow = $userResult->fetch_assoc()) {
            $api_key = $userRow['api_key'];
            $_SESSION['api_key'] = $api_key; // Cache it in session
        }
    }
    $userSTMT->close();
}

// Check if request is POST and has the required data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Debug logging
debug_log('save_discord_channel_config received: ' . json_encode($input));

if (!isset($input['action']) || !isset($input['server_id'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$action = $input['action'];
$server_id = $input['server_id'];

// Validate server_id is not empty
if (empty($server_id)) {
    http_response_code(400);
    header('Content-Type: application/json');
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
        case 'save_rules':
        case 'send_rules_message':
        case 'save_stream_schedule':
        case 'send_stream_schedule_message':
        case 'clear_reaction_roles':
        case 'clear_rules':
        case 'clear_stream_schedule':
        case 'clear_welcome_message':
        case 'clear_auto_role':
        case 'clear_role_history':
        case 'clear_message_tracking':
        case 'clear_role_tracking':
        case 'clear_server_role_management':
        case 'clear_user_tracking':
            // These are server management features that go to the Discord bot database
            // Connect to specterdiscordbot database
            $discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
            if ($discord_conn->connect_error) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Discord database connection failed']);
                exit();
            }
            // Handle server management settings (existing logic)
            switch ($action) {
                case 'save_welcome_message':
                    if (!isset($input['welcome_channel_id'])) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Welcome channel ID is required']);
                        exit();
                    }
                    $welcome_channel_id = $input['welcome_channel_id'];
                    $welcome_message = isset($input['welcome_message']) ? trim($input['welcome_message']) : '';
                    $welcome_use_default = isset($input['welcome_message_configuration_default']) ? 1 : 0;
                    $welcome_enable_embed = isset($input['welcome_message_configuration_embed']) ? 1 : 0;
                    $welcome_colour = isset($input['welcome_message_configuration_colour']) ? trim($input['welcome_message_configuration_colour']) : '#00d1b2';
                    if (empty($welcome_channel_id)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Welcome channel ID cannot be empty']);
                        exit();
                    }
                    // If not using default message, require custom welcome message text
                    if (!$welcome_use_default && empty($welcome_message)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
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
                        $updateStmt = $discord_conn->prepare("UPDATE server_management SET welcome_message_configuration_channel = ?, welcome_message_configuration_message = ?, welcome_message_configuration_default = ?, welcome_message_configuration_embed = ?, welcome_message_configuration_colour = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                        $updateStmt->bind_param("ssiiss", $welcome_channel_id, $welcome_message, $welcome_use_default, $welcome_enable_embed, $welcome_colour, $server_id);
                        $success = $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        // Insert new record
                        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, welcome_message_configuration_channel, welcome_message_configuration_message, welcome_message_configuration_default, welcome_message_configuration_embed, welcome_message_configuration_colour) VALUES (?, ?, ?, ?, ?, ?)");
                        $insertStmt->bind_param("sssiis", $server_id, $welcome_channel_id, $welcome_message, $welcome_use_default, $welcome_enable_embed, $welcome_colour);
                        $success = $insertStmt->execute();
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Welcome message configuration saved successfully',
                            'channel_id' => $welcome_channel_id,
                            'use_default' => $welcome_use_default,
                            'enable_embed' => $welcome_enable_embed,
                            'colour' => $welcome_colour
                        ]);
                        exit(); // Add exit to prevent further execution
                    } else {
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
                        exit(); // Add exit to prevent further execution
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
                    debug_log('Processing save_reaction_roles with input: ' . json_encode($input));
                    if (!isset($input['reaction_roles_channel_id'])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID is required', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $reaction_roles_channel_id = trim($input['reaction_roles_channel_id']);
                    $reaction_roles_message = isset($input['reaction_roles_message']) ? trim($input['reaction_roles_message']) : '';
                    $reaction_roles_mappings = isset($input['reaction_roles_mappings']) ? trim($input['reaction_roles_mappings']) : '';
                    $allow_multiple_reactions = isset($input['allow_multiple_reactions']) ? (bool)$input['allow_multiple_reactions'] : false;
                    if (empty($reaction_roles_channel_id)) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Build the JSON configuration object
                    $reaction_roles_config = [
                        'channel_id' => $reaction_roles_channel_id,
                        'message' => $reaction_roles_message,
                        'mappings' => $reaction_roles_mappings,
                        'allow_multiple' => $allow_multiple_reactions
                    ];
                    $reaction_roles_json = json_encode($reaction_roles_config);
                    if ($reaction_roles_json === false) {
                        debug_log('Failed to encode reaction roles JSON: ' . json_last_error_msg());
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Failed to encode configuration data', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Check if record exists for this server
                    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
                    if (!$checkStmt) {
                        debug_log('Failed to prepare record check statement: ' . $discord_conn->error);
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database error: Failed to check record existence', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $checkStmt->bind_param("s", $server_id);
                    if (!$checkStmt->execute()) {
                        debug_log('Failed to execute record check: ' . $checkStmt->error);
                        $checkStmt->close();
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database error: Failed to check record', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $result = $checkStmt->get_result();
                    if ($result->num_rows > 0) {
                        // Update existing record with JSON configuration
                        $updateStmt = $discord_conn->prepare("UPDATE server_management SET reaction_roles_configuration = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                        if (!$updateStmt) {
                            debug_log('Failed to prepare update statement: ' . $discord_conn->error);
                            $checkStmt->close();
                            http_response_code(500);
                            echo json_encode(['success' => false, 'message' => 'Database error: Failed to prepare update', 'debug_logs' => $debug_logs]);
                            exit();
                        }
                        $updateStmt->bind_param("ss", $reaction_roles_json, $server_id);
                        $success = $updateStmt->execute();
                        if (!$success) {
                            debug_log('Update failed: ' . $updateStmt->error);
                        }
                        $updateStmt->close();
                    } else {
                        // Insert new record with JSON configuration
                        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, reaction_roles_configuration) VALUES (?, ?)");
                        if (!$insertStmt) {
                            debug_log('Failed to prepare insert statement: ' . $discord_conn->error);
                            $checkStmt->close();
                            http_response_code(500);
                            echo json_encode(['success' => false, 'message' => 'Database error: Failed to prepare insert', 'debug_logs' => $debug_logs]);
                            exit();
                        }
                        $insertStmt->bind_param("ss", $server_id, $reaction_roles_json);
                        $success = $insertStmt->execute();
                        if (!$success) {
                            debug_log('Insert failed: ' . $insertStmt->error);
                        }
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        debug_log('Successfully saved reaction roles configuration: ' . $reaction_roles_json);
                        // Send success response immediately before websocket notification
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'message' => 'Reaction roles configuration saved successfully',
                            'channel_id' => $reaction_roles_channel_id,
                            'allow_multiple' => $allow_multiple_reactions,
                            'debug_logs' => $debug_logs
                        ]);
                        // Flush the output to send the response to the client
                        if (function_exists('fastcgi_finish_request')) {
                            fastcgi_finish_request();
                        } else {
                            ob_end_flush();
                            flush();
                        }
                        // Now send websocket notification in the background
                        $websocket_url = 'https://websocket.botofthespecter.com/notify'; // Production websocket server
                        $params = [
                            'action' => 'update_reaction_roles',
                            'server_id' => $server_id,
                            'user_id' => $user_id,
                            'reaction_roles_config' => $reaction_roles_json
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
                        // Log websocket notification result
                        if ($http_code !== 200) {
                            debug_log("Failed to send websocket notification for reaction roles: HTTP $http_code, Response: $response");
                        } else {
                            debug_log("Successfully sent websocket notification for reaction roles");
                        }
                        // Exit to prevent any further output
                        exit();
                    } else {
                        debug_log('Database operation failed: ' . $discord_conn->error);
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                    }
                    break;
                case 'send_reaction_roles_message':
                    debug_log('send_reaction_roles_message case entered with input: ' . json_encode($input));
                    if (!isset($input['reaction_roles_channel_id'])) {
                        debug_log('Missing reaction_roles_channel_id in input');
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID is required']);
                        exit();
                    }
                    $reaction_roles_channel_id = trim($input['reaction_roles_channel_id']);
                    $reaction_roles_message = isset($input['reaction_roles_message']) ? trim($input['reaction_roles_message']) : '';
                    $reaction_roles_mappings = isset($input['reaction_roles_mappings']) ? trim($input['reaction_roles_mappings']) : '';
                    $allow_multiple_reactions = isset($input['allow_multiple_reactions']) ? (bool)$input['allow_multiple_reactions'] : false;
                    debug_log('Extracted values - channel_id: ' . $reaction_roles_channel_id . ', message: ' . $reaction_roles_message . ', mappings: ' . $reaction_roles_mappings);
                    if (empty($reaction_roles_channel_id)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Reaction roles channel ID cannot be empty']);
                        exit();
                    }
                    if (empty($reaction_roles_message)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Reaction roles message cannot be empty']);
                        exit();
                    }
                    if (empty($reaction_roles_mappings)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Reaction roles mappings cannot be empty']);
                        exit();
                    }
                    // Send websocket notification to post the message to Discord channel
                    $websocket_url = 'https://websocket.botofthespecter.com/notify'; // Production websocket server
                    // Check if api_key exists
                    if (empty($api_key)) {
                        debug_log('api_key not found for user_id: ' . $user_id);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'API key not found. Please refresh the page and try again.', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    debug_log('Building websocket request with api_key: ' . substr($api_key, 0, 10) . '...');
                    $params = [
                        'code' => $api_key,
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
                    debug_log('Sending websocket request to: ' . $full_url);
                    // Send HTTP GET request to websocket server
                    $ch = curl_init($full_url);
                    if ($ch === false) {
                        debug_log('Failed to initialize cURL');
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to initialize HTTP request']);
                        exit();
                    }
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
                    $response = curl_exec($ch);
                    if ($response === false) {
                        $curl_error = curl_error($ch);
                        debug_log('cURL error: ' . $curl_error);
                        curl_close($ch);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'HTTP request failed: ' . $curl_error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    debug_log('Websocket response: HTTP ' . $http_code . ', Body: ' . $response);
                    // Check if websocket notification was successful
                    if ($http_code !== 200) {
                        debug_log("Failed to send websocket notification for reaction roles: HTTP $http_code, Response: $response");
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to send message to Discord channel', 'debug_logs' => $debug_logs]);
                        exit();
                    } else {
                        debug_log("Successfully sent websocket notification for reaction roles");
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Reaction roles message sent to Discord channel successfully',
                            'channel_id' => $reaction_roles_channel_id,
                            'allow_multiple' => $allow_multiple_reactions,
                            'debug_logs' => $debug_logs
                        ]);
                        exit();
                    }
                    break;
                case 'save_rules':
                    debug_log('Processing save_rules with input: ' . json_encode($input));
                    if (!isset($input['rules_channel_id'])) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules channel ID is required', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $rules_channel_id = trim($input['rules_channel_id']);
                    $rules_title = isset($input['rules_title']) ? trim($input['rules_title']) : '';
                    $rules_content = isset($input['rules_content']) ? trim($input['rules_content']) : '';
                    $rules_color = isset($input['rules_color']) ? trim($input['rules_color']) : '#5865f2';
                    $rules_accept_role_id = isset($input['rules_accept_role_id']) ? trim($input['rules_accept_role_id']) : '';
                    if (empty($rules_channel_id)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules channel ID cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($rules_title)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules title cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($rules_content)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules content cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Build the JSON configuration object
                    $rules_config = [
                        'channel_id' => $rules_channel_id,
                        'title' => $rules_title,
                        'rules' => $rules_content,
                        'color' => $rules_color,
                        'accept_role_id' => $rules_accept_role_id
                    ];
                    $rules_json = json_encode($rules_config);
                    if ($rules_json === false) {
                        debug_log('Failed to encode rules configuration as JSON');
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to encode rules configuration', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Check if record exists for this server
                    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
                    if (!$checkStmt) {
                        debug_log('Failed to prepare SELECT statement: ' . $discord_conn->error);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $checkStmt->bind_param("s", $server_id);
                    if (!$checkStmt->execute()) {
                        debug_log('Failed to execute SELECT statement: ' . $checkStmt->error);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database query failed: ' . $checkStmt->error, 'debug_logs' => $debug_logs]);
                        $checkStmt->close();
                        exit();
                    }
                    $result = $checkStmt->get_result();
                    if ($result->num_rows > 0) {
                        // Update existing record
                        $updateStmt = $discord_conn->prepare("UPDATE server_management SET rules_configuration = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?");
                        if (!$updateStmt) {
                            debug_log('Failed to prepare UPDATE statement: ' . $discord_conn->error);
                            http_response_code(500);
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                            $checkStmt->close();
                            exit();
                        }
                        $updateStmt->bind_param("ss", $rules_json, $server_id);
                        $success = $updateStmt->execute();
                        if (!$success) {
                            debug_log('Failed to execute UPDATE statement: ' . $updateStmt->error);
                        }
                        $updateStmt->close();
                    } else {
                        // Insert new record
                        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (server_id, rules_configuration) VALUES (?, ?)");
                        if (!$insertStmt) {
                            debug_log('Failed to prepare INSERT statement: ' . $discord_conn->error);
                            http_response_code(500);
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                            $checkStmt->close();
                            exit();
                        }
                        $insertStmt->bind_param("ss", $server_id, $rules_json);
                        $success = $insertStmt->execute();
                        if (!$success) {
                            debug_log('Failed to execute INSERT statement: ' . $insertStmt->error);
                        }
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        debug_log('Successfully saved rules configuration');
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Rules configuration saved successfully',
                            'channel_id' => $rules_channel_id,
                            'title' => $rules_title,
                            'color' => $rules_color,
                            'debug_logs' => $debug_logs
                        ]);
                        exit();
                    } else {
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    break;
                case 'send_rules_message':
                    debug_log('send_rules_message case entered with input: ' . json_encode($input));
                    if (!isset($input['rules_channel_id'])) {
                        debug_log('Missing rules_channel_id in input');
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules channel ID is required', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $rules_channel_id = trim($input['rules_channel_id']);
                    $rules_title = isset($input['rules_title']) ? trim($input['rules_title']) : '';
                    $rules_content = isset($input['rules_content']) ? trim($input['rules_content']) : '';
                    $rules_color = isset($input['rules_color']) ? trim($input['rules_color']) : '#5865f2';
                    $rules_accept_role_id = isset($input['rules_accept_role_id']) ? trim($input['rules_accept_role_id']) : '';
                    debug_log('Extracted values - channel_id: ' . $rules_channel_id . ', title: ' . $rules_title . ', color: ' . $rules_color . ', accept_role_id: ' . $rules_accept_role_id);
                    if (empty($rules_channel_id)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules channel ID cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($rules_title)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules title cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($rules_content)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Rules content cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Send websocket notification to post the rules message to Discord channel
                    $websocket_url = 'https://websocket.botofthespecter.com/notify'; // Production websocket server
                    // Check if api_key exists
                    if (empty($api_key)) {
                        debug_log('api_key not found for user_id: ' . $user_id);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'API key not found. Please refresh the page and try again.', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    debug_log('Building websocket request with api_key: ' . substr($api_key, 0, 10) . '...');
                    $params = [
                        'code' => $api_key,
                        'event' => 'post_rules_message',
                        'server_id' => $server_id,
                        'channel_id' => $rules_channel_id,
                        'title' => $rules_title,
                        'rules' => $rules_content,
                        'color' => $rules_color,
                        'accept_role_id' => $rules_accept_role_id
                    ];
                    // Build query string
                    $query_string = http_build_query($params);
                    $full_url = $websocket_url . '?' . $query_string;
                    debug_log('Sending websocket request to: ' . $full_url);
                    // Send HTTP GET request to websocket server
                    $ch = curl_init($full_url);
                    if ($ch === false) {
                        debug_log('Failed to initialize cURL');
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to initialize HTTP request', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
                    $response = curl_exec($ch);
                    if ($response === false) {
                        $curl_error = curl_error($ch);
                        debug_log('cURL error: ' . $curl_error);
                        curl_close($ch);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'HTTP request failed: ' . $curl_error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    debug_log('Websocket response: HTTP ' . $http_code . ', Body: ' . $response);
                    // Check if websocket notification was successful
                    if ($http_code !== 200) {
                        debug_log("Failed to send websocket notification for rules message: HTTP $http_code, Response: $response");
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to send rules message to Discord channel', 'debug_logs' => $debug_logs]);
                        exit();
                    } else {
                        debug_log("Successfully sent websocket notification for rules message");
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Rules message sent to Discord channel successfully',
                            'channel_id' => $rules_channel_id,
                            'title' => $rules_title,
                            'color' => $rules_color,
                            'debug_logs' => $debug_logs
                        ]);
                        exit();
                    }
                    break;
                case 'save_stream_schedule':
                    debug_log('Processing save_stream_schedule with input: ' . json_encode($input));
                    if (!isset($input['stream_schedule_channel_id'])) {
                        debug_log('Missing stream_schedule_channel_id in input');
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule channel ID is required', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $schedule_channel_id = trim($input['stream_schedule_channel_id']);
                    $schedule_title = isset($input['stream_schedule_title']) ? trim($input['stream_schedule_title']) : '';
                    $schedule_content = isset($input['stream_schedule_content']) ? trim($input['stream_schedule_content']) : '';
                    $schedule_color = isset($input['stream_schedule_color']) ? trim($input['stream_schedule_color']) : '#9146ff';
                    $schedule_timezone = isset($input['stream_schedule_timezone']) ? trim($input['stream_schedule_timezone']) : '';
                    if (empty($schedule_channel_id)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule channel ID cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($schedule_title)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule title cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($schedule_content)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule content cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Build the JSON configuration object
                    $schedule_config = [
                        'channel_id' => $schedule_channel_id,
                        'title' => $schedule_title,
                        'schedule' => $schedule_content,
                        'color' => $schedule_color,
                        'timezone' => $schedule_timezone
                    ];
                    $schedule_json = json_encode($schedule_config);
                    if ($schedule_json === false) {
                        debug_log('Failed to encode stream schedule configuration to JSON');
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to encode stream schedule configuration', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Check if record exists for this server
                    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
                    if (!$checkStmt) {
                        debug_log('Failed to prepare check statement: ' . $discord_conn->error);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $checkStmt->bind_param("s", $server_id);
                    if (!$checkStmt->execute()) {
                        debug_log('Failed to execute check statement: ' . $checkStmt->error);
                        $checkStmt->close();
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database error: ' . $checkStmt->error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $result = $checkStmt->get_result();
                    if ($result->num_rows > 0) {
                        debug_log('Updating existing stream schedule configuration for server_id: ' . $server_id);
                        $updateSQL = "UPDATE server_management SET stream_schedule_configuration = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?";
                        $updateStmt = $discord_conn->prepare($updateSQL);
                        if (!$updateStmt) {
                            debug_log('Failed to prepare update statement: ' . $discord_conn->error);
                            $checkStmt->close();
                            http_response_code(500);
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                            exit();
                        }
                        $updateStmt->bind_param("ss", $schedule_json, $server_id);
                        $success = $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        debug_log('Creating new stream schedule configuration record for server_id: ' . $server_id);
                        $insertSQL = "INSERT INTO server_management (server_id, stream_schedule_configuration, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                        $insertStmt = $discord_conn->prepare($insertSQL);
                        if (!$insertStmt) {
                            debug_log('Failed to prepare insert statement: ' . $discord_conn->error);
                            $checkStmt->close();
                            http_response_code(500);
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                            exit();
                        }
                        $insertStmt->bind_param("ss", $server_id, $schedule_json);
                        $success = $insertStmt->execute();
                        $insertStmt->close();
                    }
                    $checkStmt->close();
                    if ($success) {
                        debug_log('Successfully saved stream schedule configuration');
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'message' => 'Stream schedule configuration saved successfully',
                            'debug_logs' => $debug_logs
                        ]);
                        exit();
                    } else {
                        debug_log('Failed to save stream schedule configuration: ' . $discord_conn->error);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    break;
                case 'send_stream_schedule_message':
                    debug_log('send_stream_schedule_message case entered with input: ' . json_encode($input));
                    if (!isset($input['stream_schedule_channel_id'])) {
                        debug_log('Missing stream_schedule_channel_id in input');
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule channel ID is required', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $schedule_channel_id = trim($input['stream_schedule_channel_id']);
                    $schedule_title = isset($input['stream_schedule_title']) ? trim($input['stream_schedule_title']) : '';
                    $schedule_content = isset($input['stream_schedule_content']) ? trim($input['stream_schedule_content']) : '';
                    $schedule_color = isset($input['stream_schedule_color']) ? trim($input['stream_schedule_color']) : '#9146ff';
                    $schedule_timezone = isset($input['stream_schedule_timezone']) ? trim($input['stream_schedule_timezone']) : '';
                    debug_log('Extracted values - channel_id: ' . $schedule_channel_id . ', title: ' . $schedule_title . ', color: ' . $schedule_color . ', timezone: ' . $schedule_timezone);
                    if (empty($schedule_channel_id)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule channel ID cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($schedule_title)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule title cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    if (empty($schedule_content)) {
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Stream schedule content cannot be empty', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    // Send websocket notification to post the stream schedule message to Discord channel
                    $websocket_url = 'https://websocket.botofthespecter.com/notify'; // Production websocket server
                    // Check if api_key exists
                    if (empty($api_key)) {
                        debug_log('api_key not found for user_id: ' . $user_id);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'API key not found. Please refresh the page and try again.', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    debug_log('Building websocket request with api_key: ' . substr($api_key, 0, 10) . '...');
                    $params = [
                        'code' => $api_key,
                        'event' => 'post_stream_schedule_message',
                        'server_id' => $server_id,
                        'channel_id' => $schedule_channel_id,
                        'title' => $schedule_title,
                        'schedule' => $schedule_content,
                        'color' => $schedule_color,
                        'timezone' => $schedule_timezone
                    ];
                    // Build query string
                    $query_string = http_build_query($params);
                    $full_url = $websocket_url . '?' . $query_string;
                    debug_log('Sending websocket request to: ' . $full_url);
                    // Send HTTP GET request to websocket server
                    $ch = curl_init($full_url);
                    if ($ch === false) {
                        debug_log('Failed to initialize cURL');
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to initialize HTTP request', 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
                    $response = curl_exec($ch);
                    if ($response === false) {
                        $curl_error = curl_error($ch);
                        debug_log('cURL error: ' . $curl_error);
                        curl_close($ch);
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'HTTP request failed: ' . $curl_error, 'debug_logs' => $debug_logs]);
                        exit();
                    }
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    debug_log('Websocket response: HTTP ' . $http_code . ', Body: ' . $response);
                    // Check if websocket notification was successful
                    if ($http_code !== 200) {
                        debug_log("Failed to send websocket notification for stream schedule message: HTTP $http_code, Response: $response");
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to send stream schedule message to Discord channel', 'debug_logs' => $debug_logs]);
                        exit();
                    } else {
                        debug_log("Successfully sent websocket notification for stream schedule message");
                        http_response_code(200);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Stream schedule message sent to Discord channel successfully',
                            'channel_id' => $schedule_channel_id,
                            'title' => $schedule_title,
                            'color' => $schedule_color,
                            'timezone' => $schedule_timezone,
                            'debug_logs' => $debug_logs
                        ]);
                        exit();
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
        case 'clear_reaction_roles':
            debug_log('Processing clear_reaction_roles for server_id: ' . $server_id);
            // Clear reaction roles data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET reaction_roles_channel_id = NULL, reaction_roles_message = NULL, reaction_roles_mappings = NULL, allow_multiple_reactions = 0 WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Reaction roles data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Reaction roles data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear reaction roles data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear reaction roles data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_rules':
            debug_log('Processing clear_rules for server_id: ' . $server_id);
            // Clear rules data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET rules_channel_id = NULL, rules_title = NULL, rules_content = NULL, rules_color = NULL, rules_accept_role_id = NULL WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Rules data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Rules data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear rules data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear rules data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_stream_schedule':
            debug_log('Processing clear_stream_schedule for server_id: ' . $server_id);
            // Clear stream schedule data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET stream_schedule_channel_id = NULL, stream_schedule_title = NULL, stream_schedule_content = NULL, stream_schedule_color = NULL, stream_schedule_timezone = NULL WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Stream schedule data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Stream schedule data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear stream schedule data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear stream schedule data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_welcome_message':
            debug_log('Processing clear_welcome_message for server_id: ' . $server_id);
            // Clear welcome message data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET welcome_message_configuration_channel = NULL, welcome_message_configuration_message = NULL, welcome_message_configuration_default = 0, welcome_message_configuration_embed = 0, welcome_message_configuration_colour = NULL WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Welcome message data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Welcome message data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear welcome message data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear welcome message data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_auto_role':
            debug_log('Processing clear_auto_role for server_id: ' . $server_id);
            // Clear auto role data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET auto_role_id = NULL WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Auto role data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Auto role data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear auto role data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear auto role data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_role_history':
            debug_log('Processing clear_role_history for server_id: ' . $server_id);
            // Clear role history data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET role_history_enabled = 0, role_history_retention = NULL WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Role history data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Role history data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear role history data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear role history data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_message_tracking':
            debug_log('Processing clear_message_tracking for server_id: ' . $server_id);
            // Clear message tracking data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET message_tracking_log_channel_id = NULL, message_tracking_edits = 0, message_tracking_deletes = 0 WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Message tracking data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Message tracking data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear message tracking data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear message tracking data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_role_tracking':
            debug_log('Processing clear_role_tracking for server_id: ' . $server_id);
            // Clear role tracking data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET role_tracking_log_channel_id = NULL, role_tracking_additions = 0, role_tracking_removals = 0 WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Role tracking data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Role tracking data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear role tracking data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear role tracking data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_server_role_management':
            debug_log('Processing clear_server_role_management for server_id: ' . $server_id);
            // Clear server role management data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET server_mgmt_log_channel_id = NULL, role_creation_tracking = 0, role_deletion_tracking = 0, role_edit_tracking = 0 WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('Server role management data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'Server role management data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear server role management data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear server role management data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        case 'clear_user_tracking':
            debug_log('Processing clear_user_tracking for server_id: ' . $server_id);
            // Clear user tracking data from server_management table
            $clearStmt = $discord_conn->prepare("UPDATE server_management SET user_tracking_log_channel_id = NULL, user_join_tracking = 0, user_leave_tracking = 0, user_nickname_tracking = 0, user_username_tracking = 0, user_avatar_tracking = 0, user_status_tracking = 0 WHERE server_id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $server_id);
                if ($clearStmt->execute()) {
                    debug_log('User tracking data cleared successfully');
                    echo json_encode(['success' => true, 'message' => 'User tracking data cleared successfully', 'debug_logs' => $debug_logs]);
                } else {
                    debug_log('Failed to clear user tracking data: ' . $clearStmt->error);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to clear user tracking data', 'debug_logs' => $debug_logs]);
                }
                $clearStmt->close();
            } else {
                debug_log('Failed to prepare clear statement: ' . $discord_conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error', 'debug_logs' => $debug_logs]);
            }
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    debug_log('Exception caught in save_discord_channel_config.php: ' . $e->getMessage());
    debug_log('Exception trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'debug_logs' => $debug_logs]);
} finally {
    // Close database connections if they exist
    if (isset($discord_conn)) { $discord_conn->close(); }
    if (isset($conn)) { $conn->close(); }
}
?>
