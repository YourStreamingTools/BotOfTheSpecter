<?php
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/admin/database.php';
roadmap_session_start();

$itemId = (int) ($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    json_out(['ok' => false, 'error' => 'Item ID required'], 400);
}

$conn = getRoadmapConnection();
$stmt = $conn->prepare('SELECT id, username, comment, created_at FROM roadmap_comments WHERE item_id = ? ORDER BY created_at ASC');
$stmt->bind_param('i', $itemId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$creator = null;
$createdAt = null;
$itemStmt = $conn->prepare('SELECT created_by, created_at FROM roadmap_items WHERE id = ?');
$itemStmt->bind_param('i', $itemId);
$itemStmt->execute();
$itemRow = $itemStmt->get_result()->fetch_assoc();
$itemStmt->close();
if ($itemRow) {
    $creator = $itemRow['created_by'];
    $createdAt = $itemRow['created_at'];
}

$comments = [];
$wdb = website_db();
foreach ($rows as $row) {
    $profileImage = null;
    $ustmt = $wdb->prepare('SELECT profile_image FROM users WHERE username = ? LIMIT 1');
    if ($ustmt) {
        $ustmt->bind_param('s', $row['username']);
        $ustmt->execute();
        $ustmt->bind_result($profileImage);
        $ustmt->fetch();
        $ustmt->close();
    }
    $comments[] = [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'comment' => $row['comment'],
        'created_at' => $row['created_at'],
        'profile_image' => $profileImage,
    ];
}
$wdb->close();

json_out([
    'ok' => true,
    'comments' => $comments,
    'created_by' => $creator,
    'created_at' => $createdAt,
    'can_delete' => roadmap_is_admin(),
]);
