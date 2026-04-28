<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
ob_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Connect directly to the user's channel database (minimal — no heavy user_db.php queries)
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

// Fetch winners for the game
$stmt = $db->prepare("SELECT player_name, player_id, `rank`, timestamp FROM bingo_winners WHERE game_id = ? ORDER BY `rank` ASC");
$stmt->bind_param("s", $game_id);
$stmt->execute();
$result = $stmt->get_result();

$winners = [];
while ($row = $result->fetch_assoc()) {
    $winners[] = $row;
}

$stmt->close();
$db->close();

// Discard any stray output and return clean JSON
ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'winners' => $winners
]);
?>