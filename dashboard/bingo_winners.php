<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once "/var/www/config/db_connect.php";

// Get game_id from query parameter
$game_id = $_GET['game_id'] ?? '';

if (empty($game_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Game ID is required']);
    exit();
}

// Fetch winners for the game
$stmt = $db->prepare("SELECT player_name, player_id, rank, timestamp FROM bingo_winners WHERE game_id = ? ORDER BY rank ASC");
$stmt->bind_param("s", $game_id);
$stmt->execute();
$result = $stmt->get_result();

$winners = [];
while ($row = $result->fetch_assoc()) {
    $winners[] = $row;
}

$stmt->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'winners' => $winners
]);
?>