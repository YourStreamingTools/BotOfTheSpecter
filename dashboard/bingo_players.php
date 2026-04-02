<?php
session_start();
ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Connect directly to the user's channel database
include '/var/www/config/database.php';
$dbname = $_SESSION['username'] ?? '';
if (empty($dbname)) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No session username']);
    exit();
}
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Get game_id from query parameter
$game_id = $_GET['game_id'] ?? '';

if (empty($game_id)) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Game ID is required']);
    exit();
}

// Fetch players for the game
$stmt = $db->prepare("SELECT player_name, player_id, joined_at FROM bingo_players WHERE game_id = ? ORDER BY joined_at ASC");
$stmt->bind_param("s", $game_id);
$stmt->execute();
$result = $stmt->get_result();

$players = [];
while ($row = $result->fetch_assoc()) {
    $players[] = $row;
}

$stmt->close();
$db->close();

ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'players' => $players
]);
?>
