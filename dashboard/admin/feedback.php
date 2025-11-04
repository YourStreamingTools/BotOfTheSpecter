<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
$pageTitle = 'Feedback Management';
ob_start();

// Ensure feedback table exists
$create_sql = "CREATE TABLE IF NOT EXISTS feedback (id INT AUTO_INCREMENT PRIMARY KEY,twitch_user_id VARCHAR(64),display_name VARCHAR(255),message TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_sql);

// Fetch feedback from database
$feedback = [];
$stmt = $conn->prepare("SELECT id, twitch_user_id, display_name, message, created_at FROM feedback ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $feedback[] = $row;
}
$stmt->close();

// Handle delete feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_feedback_id'])) {
    $feedback_id = intval($_POST['delete_feedback_id']);
    $stmt = $conn->prepare("DELETE FROM feedback WHERE id = ?");
    $stmt->bind_param("i", $feedback_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'msg' => 'Feedback deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Failed to delete feedback.']);
    }
    $stmt->close();
    exit;
}
?>

<div class="container">
    <h1 class="title">Feedback Management</h1>
    <p class="subtitle">View and manage user feedback submissions</p>
    <?php if (empty($feedback)): ?>
        <div class="notification is-info">
            <p>No feedback submissions found.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-striped is-hoverable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Twitch User ID</th>
                        <th>Display Name</th>
                        <th>Message</th>
                        <th>Submitted At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedback as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['id']); ?></td>
                            <td><?php echo htmlspecialchars($item['twitch_user_id']); ?></td>
                            <td><?php echo htmlspecialchars($item['display_name']); ?></td>
                            <td>
                                <div style="max-width: 400px; word-wrap: break-word;">
                                    <?php echo nl2br(htmlspecialchars($item['message'])); ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($item['created_at']); ?></td>
                            <td>
                                <button class="button is-small is-danger delete-feedback-btn"
                                        data-id="<?php echo $item['id']; ?>"
                                        data-display-name="<?php echo htmlspecialchars($item['display_name']); ?>">
                                    <span class="icon">
                                        <i class="fas fa-trash"></i>
                                    </span>
                                    <span>Delete</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle delete feedback buttons
    document.querySelectorAll('.delete-feedback-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const feedbackId = this.getAttribute('data-id');
            const displayName = this.getAttribute('data-display-name');
            Swal.fire({
                title: 'Delete Feedback',
                text: `Are you sure you want to delete feedback from ${displayName}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        delete_feedback_id: feedbackId
                    }, function(resp) {
                        let data = {};
                        try { data = JSON.parse(resp); } catch {}
                        if (data.success) {
                            Swal.fire('Deleted!', 'Feedback has been deleted.', 'success').then(() => location.reload());
                        } else {
                            Swal.fire('Error', data.msg || 'Could not delete feedback.', 'error');
                        }
                    });
                }
            });
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include "admin_layout.php";
?>