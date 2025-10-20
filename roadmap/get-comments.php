<?php
// Get comments for a roadmap item
require_once "/var/www/config/database.php";
require_once "/var/www/roadmap/admin/database.php";

$item_id = $_GET['item_id'] ?? 0;

if ($item_id <= 0) {
    echo '<p class="has-text-grey">No comments yet</p>';
    exit;
}

$conn = getRoadmapConnection();

// Get all comments for this item
$query = "SELECT username, comment, created_at FROM roadmap_comments WHERE item_id = ? ORDER BY created_at ASC";
$stmt = $conn->prepare($query);
if (!$stmt) {
    echo '<p class="has-text-danger">Error loading comments</p>';
    exit;
}

$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<p class="has-text-grey">No comments yet</p>';
    $stmt->close();
    $conn->close();
    exit;
}

while ($row = $result->fetch_assoc()) {
    $createdAt = new DateTime($row['created_at']);
    $formattedDate = $createdAt->format('M d, Y h:i A');
    ?>
    <div class="box" style="margin-bottom: 0.75rem; padding: 0.75rem;">
        <div class="level" style="margin-bottom: 0.5rem;">
            <div class="level-left">
                <div class="level-item">
                    <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <small class="has-text-grey"><?php echo htmlspecialchars($formattedDate); ?></small>
                </div>
            </div>
        </div>
        <p><?php echo htmlspecialchars($row['comment']); ?></p>
    </div>
    <?php
}

$stmt->close();
$conn->close();
?>
