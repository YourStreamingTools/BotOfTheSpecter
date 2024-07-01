<?php
include 'database.php';

if (isset($_GET['username'])) {
    $username = $_GET['username'];

    // Check if the username exists in the database
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Fetch songs added by this user
        $stmt = $conn->prepare("SELECT * FROM songs WHERE added_by = ?");
        $stmt->execute([$user['id']]);
        $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($songs);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Username not provided.']);
}
?>
