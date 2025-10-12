<?php
header('Content-Type: application/json');
require_once "/var/www/config/database.php";
$dbname = "roadmap";

// Create connection without db first
$conn = new mysqli($db_servername, $db_username, $db_password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Select the database
$conn->select_db($dbname);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $board_id = $_GET['id'];

    // Get board
    $sql_board = "SELECT * FROM boards WHERE id = ?";
    $stmt_board = $conn->prepare($sql_board);
    $stmt_board->bind_param("i", $board_id);
    $stmt_board->execute();
    $board = $stmt_board->get_result()->fetch_assoc();
    $stmt_board->close();

    if (!$board) {
        echo json_encode(['error' => 'Board not found']);
        exit;
    }

    // Get lists for the board
    $sql_lists = "SELECT * FROM lists WHERE board_id = ? ORDER BY position";
    $stmt_lists = $conn->prepare($sql_lists);
    $stmt_lists->bind_param("i", $board_id);
    $stmt_lists->execute();
    $result_lists = $stmt_lists->get_result();
    $lists = [];
    while ($row = $result_lists->fetch_assoc()) {
        $list_id = $row['id'];
        // Get cards for each list
        $sql_cards = "SELECT * FROM cards WHERE list_id = ? ORDER BY position";
        $stmt_cards = $conn->prepare($sql_cards);
        $stmt_cards->bind_param("i", $list_id);
        $stmt_cards->execute();
        $result_cards = $stmt_cards->get_result();
        $cards = [];
        while ($card = $result_cards->fetch_assoc()) {
            $cards[] = $card;
        }
        $row['cards'] = $cards;
        $stmt_cards->close();
        $lists[] = $row;
    }
    $stmt_lists->close();

    $board['lists'] = $lists;
    echo json_encode($board);
} else {
    echo json_encode(['error' => 'Invalid request']);
}

$conn->close();
?>