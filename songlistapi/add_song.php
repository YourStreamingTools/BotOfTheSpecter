<?php
session_start();
include 'database.php';

function getYoutubeId($url) {
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    return $query['v'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $artist = $_POST['artist'];
    $youtube_url = $_POST['youtube_url'];
    $youtube_id = getYoutubeId($youtube_url);
    
    // Use the logged-in user's Twitch user ID
    if (isset($_SESSION['twitch_user_id'])) {
        $added_by = $_SESSION['twitch_user_id'];
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not authenticated']);
        exit;
    }

    // Insert song into the database
    $stmt = $conn->prepare("INSERT INTO songs (title, artist, youtube_id, added_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $artist, $youtube_id, $added_by]);

    // Notify WebSocket server (if applicable)
    $msg = json_encode(['action' => 'add_song', 'title' => $title, 'artist' => $artist, 'youtube_id' => $youtube_id, 'added_by' => $added_by]);
    $socket = fsockopen("localhost", 8080, $errno, $errstr, 30);
    if ($socket) {
        fwrite($socket, $msg);
        fclose($socket);
    }

    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>
