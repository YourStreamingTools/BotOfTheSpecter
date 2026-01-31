<?php
session_start();
require_once "/var/www/config/db_connect.php";

// Ensure the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

// Get username from session
$username = $_SESSION['username'];

// Database connection to user database
$db = new mysqli($db_servername, $db_username, $db_password, $username);
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Get parameters
$link = isset($_POST['link']) ? trim($_POST['link']) : '';
$checkList = isset($_POST['check_list']) ? $_POST['check_list'] : ''; // 'whitelist' or 'blacklist'

if (empty($link) || empty($checkList)) {
    echo json_encode(['exists' => false]);
    exit();
}

$exists = false;

// Check the appropriate list
if ($checkList === 'whitelist') {
    // Check if URL exists in whitelist
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM link_whitelist WHERE link = ?");
    $stmt->bind_param('s', $link);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $exists = ($row['count'] > 0);
    $stmt->close();
} elseif ($checkList === 'blacklist') {
    // Check if URL exists in blacklist
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM link_blacklisting WHERE link = ?");
    $stmt->bind_param('s', $link);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $exists = ($row['count'] > 0);
    $stmt->close();
}

$db->close();

echo json_encode(['exists' => $exists]);
?>
