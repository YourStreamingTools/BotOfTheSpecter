<?php
// Get comments for a roadmap item
require_once "/var/www/config/database.php";
require_once "/var/www/roadmap/admin/database.php";

session_start();

$item_id = $_GET['item_id'] ?? 0;

if ($item_id <= 0) {
    echo '<p class="has-text-grey" style="text-align: center; padding: 1rem;">No comments yet</p>';
    exit;
}

$conn = getRoadmapConnection();

// Connect to website database to get profile images from users table
$users_conn = new mysqli($db_servername, $db_username, $db_password, "website");
if ($users_conn->connect_error) {
    $users_conn = null; // Fall back to no profile images if connection fails
}

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
    echo '<p class="has-text-grey" style="text-align: center; padding: 1rem;">No comments yet</p>';
    $stmt->close();
    $conn->close();
    exit;
}

while ($row = $result->fetch_assoc()) {
    $createdAt = new DateTime($row['created_at']);
    // Format timestamp - show relative time (e.g., "2 hours ago")
    $now = new DateTime();
    $interval = $now->diff($createdAt);
    if ($interval->days > 0) {
        $formattedDate = $createdAt->format('M d \a\t h:i A');
    } elseif ($interval->h > 0) {
        $formattedDate = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    } elseif ($interval->i > 0) {
        $formattedDate = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    } else {
        $formattedDate = 'Just now';
    }
    // Get user profile image from users database
    $profileImage = null;
    if ($users_conn) {
        $user_query = "SELECT profile_image FROM users WHERE username = ? LIMIT 1";
        $user_stmt = $users_conn->prepare($user_query);
        if ($user_stmt) {
            $user_stmt->bind_param("s", $row['username']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_result->num_rows > 0) {
                $user_row = $user_result->fetch_assoc();
                $profileImage = $user_row['profile_image'];
            }
            $user_stmt->close();
        }
    }
    // Get user initials for avatar fallback
    $userInitials = '';
    $nameParts = explode(' ', htmlspecialchars($row['username']));
    $userInitials = substr($nameParts[0], 0, 1);
    if (isset($nameParts[1])) {
        $userInitials .= substr($nameParts[1], 0, 1);
    }
    $userInitials = strtoupper($userInitials);
    // Get avatar color based on username hash (for fallback)
    $hash = crc32($row['username']) % 360;
    $avatarHue = $hash;
    ?>
    <div class="box comment-box">
        <!-- Header: Avatar + Username on left, Time on right -->
        <div class="comment-header">
            <div class="comment-user-info">
                <?php if ($profileImage): ?>
                    <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="<?php echo htmlspecialchars($row['username']); ?>" class="comment-avatar">
                <?php else: ?>
                    <div class="comment-avatar-fallback" style="--avatar-hue: <?php echo $avatarHue; ?>;">
                        <?php echo $userInitials; ?>
                    </div>
                <?php endif; ?>
                <strong class="comment-username"><?php echo htmlspecialchars($row['username']); ?></strong>
            </div>
            <div class="comment-time"><?php echo htmlspecialchars($formattedDate); ?></div>
        </div>
        <div class="comment-text"><?php echo htmlspecialchars($row['comment']); ?></div>
    </div>
    <?php
}

$stmt->close();
$conn->close();
if ($users_conn) {
    $users_conn->close();
}
?>
