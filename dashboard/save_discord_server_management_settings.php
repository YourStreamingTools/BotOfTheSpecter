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
include 'userdata.php';

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
    $checkStmt = $conn->prepare("SELECT id FROM discord_settings WHERE user_id = ?");
    $checkStmt->bind_param("i", $user_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows > 0) {
        // Update existing record
        $updateStmt = $conn->prepare("UPDATE discord_settings SET `$setting` = ? WHERE user_id = ?");
        $updateStmt->bind_param("ii", $value, $user_id);
        $success = $updateStmt->execute();
        $updateStmt->close();
    } else {
        // Insert new record with default values
        $insertStmt = $conn->prepare("INSERT INTO discord_settings (user_id, `$setting`) VALUES (?, ?)");
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
}
?>
