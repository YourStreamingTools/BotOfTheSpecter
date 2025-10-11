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

if (!isset($input['setting']) || !isset($input['value']) || !isset($input['server_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$setting = $input['setting'];
$value = $input['value'] ? 1 : 0;
$server_id = $input['server_id'];

// Validate server_id is not empty
if (empty($server_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Server ID is required']);
    exit();
}

// Validate setting name
$allowed_settings = [
    'welcomeMessage',
    'autoRole', 
    'roleHistory',
    'messageTracking',
    'roleTracking',
    'serverRoleManagement',
    'userTracking',
    'reactionRoles'
];

if (!in_array($setting, $allowed_settings)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid setting']);
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
    // Check if record exists for this server
    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE server_id = ?");
    $checkStmt->bind_param("s", $server_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows > 0) {
        // Update existing record
        $updateSQL = "UPDATE server_management SET `" . $setting . "` = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ?";
        $updateStmt = $discord_conn->prepare($updateSQL);
        $updateStmt->bind_param("is", $value, $server_id);
        $success = $updateStmt->execute();
        $updateStmt->close();
    } else {
        // Insert new record with default values
        $insertSQL = "INSERT INTO server_management (server_id, `" . $setting . "`) VALUES (?, ?)";
        $insertStmt = $discord_conn->prepare($insertSQL);
        $insertStmt->bind_param("si", $server_id, $value);
        $success = $insertStmt->execute();
        $insertStmt->close();
    }
    $checkStmt->close();
    if ($success) {
        echo json_encode([
            'success' => true, 
            'message' => 'Setting updated successfully',
            'setting' => $setting,
            'value' => $value
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $discord_conn->error]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} finally {
    // Close the Discord database connection
    if (isset($discord_conn)) { $discord_conn->close(); }
}
?>
