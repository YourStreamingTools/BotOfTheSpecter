<?php
session_start();

// Must be logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) < 1) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

include '/var/www/config/database.php';

$results = [];
try {
    $conn = new mysqli($db_servername, $db_username, $db_password, 'website');
    if ($conn->connect_error) {
        throw new Exception('Connection failed');
    }
    $like = '%' . $conn->real_escape_string($q) . '%';
    $likeStart = $conn->real_escape_string($q) . '%';
    $stmt = $conn->prepare(
        'SELECT username, twitch_display_name, profile_image
         FROM users
         WHERE username LIKE ? OR twitch_display_name LIKE ?
         ORDER BY
           CASE WHEN username LIKE ? THEN 0 ELSE 1 END,
           username ASC
         LIMIT 10'
    );
    $stmt->bind_param('sss', $like, $like, $likeStart);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = [
            'username'     => $row['username'],
            'display_name' => $row['twitch_display_name'] ?: $row['username'],
            'avatar'       => $row['profile_image'] ?: '',
        ];
    }
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    // Return empty on error
}

header('Content-Type: application/json');
echo json_encode($results);
