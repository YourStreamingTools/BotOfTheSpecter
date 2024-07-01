<?php
include 'database.php';

if (isset($_GET['username'])) {
    $username = $_GET['username'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['status' => 'success', 'user' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Streamer not found.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Username not provided.']);
}
?>
