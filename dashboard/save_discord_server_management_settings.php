<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Include database connection and userdata
include '/var/www/config/database.php';
include 'userdata.php';

// Connect to specterdiscordbot database
$discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
if ($discord_conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Check if request is POST and has the required data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['setting']) || !isset($input['value'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$setting = $input['setting'];
$value = $input['value'] ? 1 : 0;

// Validate setting name
$allowed_settings = [
    'welcomeMessage',
    'autoRole', 
    'roleHistory',
    'messageTracking',
    'roleTracking',
    'serverRoleManagement',
    'userTracking'
];

if (!in_array($setting, $allowed_settings)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid setting']);
    exit();
}

try {
    // Check if record exists for this user
    $checkStmt = $discord_conn->prepare("SELECT id FROM server_management WHERE user_id = ?");
    $checkStmt->bind_param("i", $user_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing record
        $updateStmt = $discord_conn->prepare("UPDATE server_management SET `$setting` = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
        $updateStmt->bind_param("ii", $value, $user_id);
        $success = $updateStmt->execute();
        $updateStmt->close();
    } else {
        // Insert new record with default values
        $insertStmt = $discord_conn->prepare("INSERT INTO server_management (user_id, `$setting`) VALUES (?, ?)");
        $insertStmt->bind_param("ii", $user_id, $value);
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
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} finally {
    // Close the Discord database connection
    if (isset($discord_conn)) {
        $discord_conn->close();
    }
}
?>
