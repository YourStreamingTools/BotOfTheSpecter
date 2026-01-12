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
$create_sql = "CREATE TABLE IF NOT EXISTS feedback (id INT AUTO_INCREMENT PRIMARY KEY,twitch_user_id VARCHAR(64),display_name VARCHAR(255),message TEXT,is_bug_report TINYINT(1) DEFAULT 0,bug_category VARCHAR(100),severity VARCHAR(50),steps_to_reproduce TEXT,expected_behavior TEXT,actual_behavior TEXT,browser_info VARCHAR(500),error_message TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_sql);

// Fetch feedback from database
$feedback = [];
$stmt = $conn->prepare("SELECT id, twitch_user_id, display_name, message, is_bug_report, bug_category, severity, steps_to_reproduce, expected_behavior, actual_behavior, browser_info, error_message, created_at FROM feedback ORDER BY created_at DESC");
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
                        <th>Type</th>
                        <th>Display Name</th>
                        <th>Message/Summary</th>
                        <th>Details</th>
                        <th>Submitted At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedback as $item): ?>
                        <tr class="<?php echo $item['is_bug_report'] ? 'has-background-danger-light' : ''; ?>">
                            <td><?php echo htmlspecialchars($item['id']); ?></td>
                            <td>
                                <?php if ($item['is_bug_report']): ?>
                                    <span class="tag is-danger">
                                        <span class="icon"><i class="fas fa-bug"></i></span>
                                        <span>Bug</span>
                                    </span>
                                <?php else: ?>
                                    <span class="tag is-info">
                                        <span class="icon"><i class="fas fa-comment"></i></span>
                                        <span>Feedback</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['display_name']); ?></td>
                            <td>
                                <div style="max-width: 300px; word-wrap: break-word;">
                                    <?php echo nl2br(htmlspecialchars($item['message'])); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($item['is_bug_report']): ?>
                                    <button class="button is-small is-info view-bug-details" 
                                            data-id="<?php echo $item['id']; ?>"
                                            data-category="<?php echo htmlspecialchars($item['bug_category']); ?>"
                                            data-severity="<?php echo htmlspecialchars($item['severity']); ?>"
                                            data-steps="<?php echo htmlspecialchars($item['steps_to_reproduce']); ?>"
                                            data-expected="<?php echo htmlspecialchars($item['expected_behavior']); ?>"
                                            data-actual="<?php echo htmlspecialchars($item['actual_behavior']); ?>"
                                            data-browser="<?php echo htmlspecialchars($item['browser_info']); ?>"
                                            data-error="<?php echo htmlspecialchars($item['error_message']); ?>">
                                        <span class="icon"><i class="fas fa-info-circle"></i></span>
                                        <span>View Details</span>
                                    </button>
                                <?php else: ?>
                                    <span class="has-text-grey">N/A</span>
                                <?php endif; ?>
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
    // Handle view bug details buttons
    document.querySelectorAll('.view-bug-details').forEach(btn => {
        btn.addEventListener('click', function() {
            const category = this.getAttribute('data-category');
            const severity = this.getAttribute('data-severity');
            const steps = this.getAttribute('data-steps');
            const expected = this.getAttribute('data-expected');
            const actual = this.getAttribute('data-actual');
            const browser = this.getAttribute('data-browser');
            const error = this.getAttribute('data-error');
            let severityClass = 'is-info';
            if (severity === 'critical') severityClass = 'is-danger';
            else if (severity === 'high') severityClass = 'is-warning';
            else if (severity === 'medium') severityClass = 'is-warning';
            const htmlContent = `
                <div class="content has-text-left">
                    <div class="field">
                        <label class="label">Category:</label>
                        <span class="tag is-info">${category || 'N/A'}</span>
                    </div>
                    <div class="field">
                        <label class="label">Severity:</label>
                        <span class="tag ${severityClass}">${severity || 'N/A'}</span>
                    </div>
                    <div class="field">
                        <label class="label">Steps to Reproduce:</label>
                        <div class="box" style="white-space: pre-wrap;">${steps || 'N/A'}</div>
                    </div>
                    <div class="field">
                        <label class="label">Expected Behavior:</label>
                        <div class="box" style="white-space: pre-wrap;">${expected || 'N/A'}</div>
                    </div>
                    <div class="field">
                        <label class="label">Actual Behavior:</label>
                        <div class="box" style="white-space: pre-wrap;">${actual || 'N/A'}</div>
                    </div>
                    ${error ? `<div class="field">
                        <label class="label">Error Message:</label>
                        <div class="box" style="white-space: pre-wrap; font-family: monospace; font-size: 0.85em;">${error}</div>
                    </div>` : ''}
                    ${category === 'dashboard' && browser ? `<div class="field">
                        <label class="label">Browser Info:</label>
                        <div class="box" style="font-size: 0.85em; word-break: break-all;">${browser}</div>
                    </div>` : ''}
                </div>
            `;
            Swal.fire({
                title: 'Bug Report Details',
                html: htmlContent,
                width: '800px',
                confirmButtonText: 'Close'
            });
        });
    });
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