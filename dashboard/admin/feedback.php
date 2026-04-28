<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
session_write_close();
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

<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-comments"></i> Feedback Management</h1>
    </div>
    <div class="sp-card-body">
    <p style="color:var(--text-secondary);margin-bottom:1.25rem;">View and manage user feedback submissions</p>
    <?php if (empty($feedback)): ?>
        <div class="sp-alert sp-alert-info">
            <p>No feedback submissions found.</p>
        </div>
    <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
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
                        <tr<?php if ($item['is_bug_report']) echo ' style="background:var(--red-bg);"'; ?>>
                            <td><?php echo htmlspecialchars($item['id']); ?></td>
                            <td>
                                <?php if ($item['is_bug_report']): ?>
                                    <span class="sp-badge sp-badge-red">
                                        <i class="fas fa-bug"></i> Bug
                                    </span>
                                <?php else: ?>
                                    <span class="sp-badge sp-badge-blue">
                                        <i class="fas fa-comment"></i> Feedback
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
                                    <button class="sp-btn sp-btn-info sp-btn-sm view-bug-details" 
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
                                    <span class="sp-text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['created_at']); ?></td>
                            <td>
                                <button class="sp-btn sp-btn-danger sp-btn-sm delete-feedback-btn"
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
    </div><!-- /sp-card-body -->
</div><!-- /sp-card -->

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
            let severityClass = 'sp-badge-blue';
            if (severity === 'critical') severityClass = 'sp-badge-red';
            else if (severity === 'high') severityClass = 'sp-badge-amber';
            else if (severity === 'medium') severityClass = 'sp-badge-amber';
            const htmlContent = `
                <div style="text-align:left;">
                    <div class="sp-form-group">
                        <label class="sp-label">Category:</label>
                        <span class="sp-badge sp-badge-blue">${category || 'N/A'}</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Severity:</label>
                        <span class="sp-badge ${severityClass}">${severity || 'N/A'}</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Steps to Reproduce:</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;">${steps || 'N/A'}</div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Expected Behavior:</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;">${expected || 'N/A'}</div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Actual Behavior:</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;">${actual || 'N/A'}</div>
                    </div>
                    ${error ? `<div class="sp-form-group">
                        <label class="sp-label">Error Message:</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;font-family:monospace;font-size:0.85em;">${error}</div>
                    </div>` : ''}
                    ${category === 'dashboard' && browser ? `<div class="sp-form-group">
                        <label class="sp-label">Browser Info:</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;font-size:0.85em;word-break:break-all;">${browser}</div>
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
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>