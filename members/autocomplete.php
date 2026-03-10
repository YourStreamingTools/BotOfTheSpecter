<?php
session_start();

// Must be logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
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
        throw new Exception("Connection failed");
    }
    $like = '%' . $conn->real_escape_string($q) . '%';
    $stmt = $conn->prepare(
        "SELECT u.username, u.twitch_display_name, u.profile_image
         FROM users u
         LEFT JOIN restricted_users r ON u.username = r.username
         WHERE (u.username LIKE ? OR u.twitch_display_name LIKE ?)
           AND u.is_deceased = 0
           AND r.username IS NULL
         ORDER BY
           CASE WHEN u.username LIKE ? THEN 0 ELSE 1 END,
           u.username ASC
         LIMIT 10"
    );
    $likeStart = $q . '%';
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
