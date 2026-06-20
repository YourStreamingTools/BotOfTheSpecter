<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../includes/userdata.php';
session_write_close();
$pageTitle = t('admin_feedback_page_title');
ob_start();

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
        echo json_encode(['success' => true, 'msg' => t('admin_feedback_msg_deleted')]);
    } else {
        echo json_encode(['success' => false, 'msg' => t('admin_feedback_msg_delete_failed')]);
    }
    $stmt->close();
    exit;
}
?>

<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-comments"></i> <?php echo t('admin_feedback_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
    <p style="color:var(--text-secondary);margin-bottom:1.25rem;"><?php echo t('admin_feedback_intro'); ?></p>
    <?php if (empty($feedback)): ?>
        <div class="sp-alert sp-alert-info">
            <p><?php echo t('admin_feedback_empty_state'); ?></p>
        </div>
    <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_feedback_th_id'); ?></th>
                        <th><?php echo t('admin_feedback_th_type'); ?></th>
                        <th><?php echo t('admin_feedback_th_display_name'); ?></th>
                        <th><?php echo t('admin_feedback_th_message'); ?></th>
                        <th><?php echo t('admin_feedback_th_details'); ?></th>
                        <th><?php echo t('admin_feedback_th_submitted_at'); ?></th>
                        <th><?php echo t('admin_feedback_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedback as $item): ?>
                        <tr<?php if ($item['is_bug_report']) echo ' style="background:var(--red-bg);"'; ?>>
                            <td><?php echo htmlspecialchars($item['id']); ?></td>
                            <td>
                                <?php if ($item['is_bug_report']): ?>
                                    <span class="sp-badge sp-badge-red">
                                        <i class="fas fa-bug"></i> <?php echo t('admin_feedback_badge_bug'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="sp-badge sp-badge-blue">
                                        <i class="fas fa-comment"></i> <?php echo t('admin_feedback_badge_feedback'); ?>
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
                                        <span><?php echo t('admin_feedback_btn_view_details'); ?></span>
                                    </button>
                                <?php else: ?>
                                    <span class="sp-text-muted"><?php echo t('admin_feedback_na'); ?></span>
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
                                    <span><?php echo t('admin_feedback_btn_delete'); ?></span>
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
const FEEDBACK_I18N = {
    na: <?php echo json_encode(t('admin_feedback_na')); ?>,
    labelCategory: <?php echo json_encode(t('admin_feedback_label_category')); ?>,
    labelSeverity: <?php echo json_encode(t('admin_feedback_label_severity')); ?>,
    labelSteps: <?php echo json_encode(t('admin_feedback_label_steps')); ?>,
    labelExpected: <?php echo json_encode(t('admin_feedback_label_expected')); ?>,
    labelActual: <?php echo json_encode(t('admin_feedback_label_actual')); ?>,
    labelError: <?php echo json_encode(t('admin_feedback_label_error')); ?>,
    labelBrowser: <?php echo json_encode(t('admin_feedback_label_browser')); ?>,
    detailsTitle: <?php echo json_encode(t('admin_feedback_details_title')); ?>,
    closeBtn: <?php echo json_encode(t('admin_feedback_btn_close')); ?>,
    deleteTitle: <?php echo json_encode(t('admin_feedback_delete_title')); ?>,
    deleteConfirmText: <?php echo json_encode(t('admin_feedback_delete_confirm_text')); ?>,
    deleteConfirmBtn: <?php echo json_encode(t('admin_feedback_delete_confirm_btn')); ?>,
    deletedTitle: <?php echo json_encode(t('admin_feedback_deleted_title')); ?>,
    deletedText: <?php echo json_encode(t('admin_feedback_deleted_text')); ?>,
    errorTitle: <?php echo json_encode(t('admin_feedback_error_title')); ?>,
    deleteFailedText: <?php echo json_encode(t('admin_feedback_delete_failed_text')); ?>
};
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
                        <label class="sp-label">${FEEDBACK_I18N.labelCategory}</label>
                        <span class="sp-badge sp-badge-blue">${category || FEEDBACK_I18N.na}</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">${FEEDBACK_I18N.labelSeverity}</label>
                        <span class="sp-badge ${severityClass}">${severity || FEEDBACK_I18N.na}</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">${FEEDBACK_I18N.labelSteps}</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;">${steps || FEEDBACK_I18N.na}</div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">${FEEDBACK_I18N.labelExpected}</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;">${expected || FEEDBACK_I18N.na}</div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">${FEEDBACK_I18N.labelActual}</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;">${actual || FEEDBACK_I18N.na}</div>
                    </div>
                    ${error ? `<div class="sp-form-group">
                        <label class="sp-label">${FEEDBACK_I18N.labelError}</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;white-space:pre-wrap;font-family:monospace;font-size:0.85em;">${error}</div>
                    </div>` : ''}
                    ${category === 'dashboard' && browser ? `<div class="sp-form-group">
                        <label class="sp-label">${FEEDBACK_I18N.labelBrowser}</label>
                        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:0.65rem;font-size:0.85em;word-break:break-all;">${browser}</div>
                    </div>` : ''}
                </div>
            `;
            Swal.fire({
                title: FEEDBACK_I18N.detailsTitle,
                html: htmlContent,
                width: '800px',
                confirmButtonText: FEEDBACK_I18N.closeBtn
            });
        });
    });
    // Handle delete feedback buttons
    document.querySelectorAll('.delete-feedback-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const feedbackId = this.getAttribute('data-id');
            const displayName = this.getAttribute('data-display-name');
            Swal.fire({
                title: FEEDBACK_I18N.deleteTitle,
                text: FEEDBACK_I18N.deleteConfirmText.replace('%s', displayName),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: FEEDBACK_I18N.deleteConfirmBtn
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        delete_feedback_id: feedbackId
                    }, function(resp) {
                        let data = {};
                        try { data = JSON.parse(resp); } catch {}
                        if (data.success) {
                            Swal.fire(FEEDBACK_I18N.deletedTitle, FEEDBACK_I18N.deletedText, 'success').then(() => location.reload());
                        } else {
                            Swal.fire(FEEDBACK_I18N.errorTitle, data.msg || FEEDBACK_I18N.deleteFailedText, 'error');
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