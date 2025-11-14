<?php
// Get comments for a roadmap item
require_once "/var/www/config/database.php";
require_once "/var/www/roadmap/admin/database.php";

session_start();

$item_id = $_GET['item_id'] ?? 0;

if ($item_id <= 0) {
    echo '';
    exit;
}

$conn = getRoadmapConnection();

// Connect to website database to get profile images from users table
$users_conn = new mysqli($db_servername, $db_username, $db_password, "website");
if ($users_conn->connect_error) {
    $users_conn = null;
}

// Get item creator and creation info
$item_query = "SELECT created_by, created_at FROM roadmap_items WHERE id = ?";
$item_stmt = $conn->prepare($item_query);
if ($item_stmt) {
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    if ($item_result->num_rows > 0) {
        $item_row = $item_result->fetch_assoc();
        $creator = $item_row['created_by'];
        $createdAt = new DateTime($item_row['created_at']);
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
        // Get creator profile image
        $profileImage = null;
        if ($users_conn) {
            $user_query = "SELECT profile_image FROM users WHERE username = ? LIMIT 1";
            $user_stmt = $users_conn->prepare($user_query);
            if ($user_stmt) {
                $user_stmt->bind_param("s", $creator);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_result->num_rows > 0) {
                    $user_row = $user_result->fetch_assoc();
                    $profileImage = $user_row['profile_image'];
                }
                $user_stmt->close();
            }
        }
        // Get creator initials for avatar fallback
        $userInitials = '';
        $nameParts = explode(' ', htmlspecialchars($creator));
        $userInitials = substr($nameParts[0], 0, 1);
        if (isset($nameParts[1])) {
            $userInitials .= substr($nameParts[1], 0, 1);
        }
        $userInitials = strtoupper($userInitials);
        $hash = crc32($creator) % 360;
        $avatarHue = $hash;
        ?>
        <div style="border: 1px solid rgba(102, 126, 234, 0.3); border-radius: 6px; padding: 0.75rem; background-color: rgba(102, 126, 234, 0.15); margin-bottom: 0.75rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; gap: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                    <?php if ($profileImage): ?>
                        <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="<?php echo htmlspecialchars($creator); ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: hsl(<?php echo $avatarHue; ?>, 70%, 50%); color: white; font-weight: bold; font-size: 0.85rem; flex-shrink: 0;">
                            <?php echo $userInitials; ?>
                        </div>
                    <?php endif; ?>
                    <strong style="color: #667eea; font-size: 0.9rem; white-space: nowrap;"><?php echo htmlspecialchars($creator); ?></strong>
                    <span style="color: #888; font-size: 0.85rem;">created this item</span>
                </div>
                <small style="color: #888; white-space: nowrap; flex-shrink: 0;"><?php echo htmlspecialchars($formattedDate); ?></small>
            </div>
        </div>
        <?php
    }
    $item_stmt->close();
}

// Get all comments for this item
$query = "SELECT username, comment, created_at FROM roadmap_comments WHERE item_id = ? ORDER BY created_at ASC";
$stmt = $conn->prepare($query);
if (!$stmt) {
    echo '';
    exit;
}

$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<p style="text-align: center; color: #888; padding: 1rem;">No comments yet</p>';
    $stmt->close();
    $conn->close();
    exit;
}

while ($row = $result->fetch_assoc()) {
    $createdAt = new DateTime($row['created_at']);
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
    $hash = crc32($row['username']) % 360;
    $avatarHue = $hash;
    ?>
    <div style="border: 1px solid rgba(102, 126, 234, 0.2); border-radius: 6px; padding: 0.75rem; background-color: rgba(102, 126, 234, 0.08);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; gap: 0.5rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                <?php if ($profileImage): ?>
                    <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="<?php echo htmlspecialchars($row['username']); ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                <?php else: ?>
                    <div style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: hsl(<?php echo $avatarHue; ?>, 70%, 50%); color: white; font-weight: bold; font-size: 0.85rem; flex-shrink: 0;">
                        <?php echo $userInitials; ?>
                    </div>
                <?php endif; ?>
                <strong style="color: #667eea; font-size: 0.9rem; white-space: nowrap;"><?php echo htmlspecialchars($row['username']); ?></strong>
            </div>
            <small style="color: #888; white-space: nowrap; flex-shrink: 0;"><?php echo htmlspecialchars($formattedDate); ?></small>
        </div>
        <div style="color: #c0c0c0; line-height: 1.5; font-size: 0.9rem; word-wrap: break-word; white-space: pre-wrap; margin: 0;">
            <?php echo htmlspecialchars($row['comment']); ?>
        </div>
    </div>
    <?php
}

$stmt->close();
$conn->close();
if ($users_conn) {
    $users_conn->close();
}
?>
