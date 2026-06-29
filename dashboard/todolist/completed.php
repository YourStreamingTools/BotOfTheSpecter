<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('todolist_completed_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../includes/userdata.php';
include '../includes/bot_control.php';
include "../includes/mod_access.php";
include '../includes/user_db.php';
include '../includes/storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

require_once __DIR__ . '/category_filter.php';

$categoryFilter = parse_todo_category_filter();

// Mark task as completed
if (isset($_POST['task_id'])) {
  $task_id = intval($_POST['task_id']);
  $sql = "UPDATE todos SET completed = 'Yes' WHERE id = ?";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $task_id);
  $stmt->execute();

  $qs = todo_category_filter_query_string($categoryFilter);
  header('Location: completed.php' . ($qs ? '?' . $qs : ''));
  exit();
}

$categorySql = "SELECT * FROM categories ORDER BY id ASC";
$categoryStmt = $db->query($categorySql);
$categories = $categoryStmt->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id";
$whereParts = ["t.completed = 'No'"];
$bindTypes = '';
$bindParams = [];

$categorySqlFilter = todo_category_sql_filter($categoryFilter);
if ($categorySqlFilter !== null) {
  $whereParts[] = $categorySqlFilter['sql'];
  $bindTypes .= $categorySqlFilter['types'];
  $bindParams = array_merge($bindParams, $categorySqlFilter['params']);
}

$sql .= ' WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY t.id ASC';
$stmt = $db->prepare($sql);
if ($bindTypes !== '') {
  $stmt->bind_param($bindTypes, ...$bindParams);
}
$stmt->execute();
$incompleteTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($incompleteTasks);
$categoryFilterQs = todo_category_filter_query_string($categoryFilter);

ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-check-double"></i> <?= t('todo_completed_card_title') ?></div>
  </div>
  <div class="sp-card-body">
    <?php if ($num_rows < 1): ?>
      <div class="sp-alert sp-alert-info" style="display:flex; align-items:center; gap:0.75rem;">
        <i class="fas fa-tasks fa-2x" style="color:var(--blue); flex-shrink:0;"></i>
        <div>
          <strong><?= t('todo_completed_empty_heading') ?></strong>
          <p style="margin-bottom:0;"><?= t('todo_completed_empty_text') ?></p>
        </div>
      </div>
    <?php else: ?>
      <div style="display:flex; align-items:flex-end; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
        <div style="flex:1; min-width:200px;">
          <label for="searchInput" class="sp-label"><?= t('todo_completed_search_label') ?></label>
          <input class="sp-input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="<?= htmlspecialchars(t('todo_completed_search_placeholder')) ?>">
        </div>
        <?php render_todo_category_filter($categories, $categoryFilter, 'completed.php', 'todo_completed_filter_label', 'todo_completed_filter_all'); ?>
      </div>
      <p style="margin-bottom:1rem; color:var(--text-secondary);"><?= t('todo_completed_total_count') ?> <?php echo $num_rows; ?></p>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem;" id="taskCardList">
        <?php foreach ($incompleteTasks as $row): ?>
          <div class="sp-card" style="margin-bottom:0;">
            <div class="sp-card-body" style="display:flex; flex-direction:column; gap:0.75rem; height:100%;">
              <div style="flex:1;">
                <p style="font-weight:600; margin-bottom:0.3rem;"><?php echo htmlspecialchars($row['objective']); ?></p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0; display:flex; align-items:center; gap:0.3rem;">
                  <i class="fas fa-folder"></i>
                  <?php echo htmlspecialchars($row['category_name'] ?? t('todo_completed_uncategorized')); ?>
                </p>
              </div>
              <div>
                <form method="post" action="completed.php<?php echo $categoryFilterQs ? '?' . htmlspecialchars($categoryFilterQs) : ''; ?>" style="margin-bottom:0;" class="mark-completed-form">
                  <input type="hidden" name="task_id" value="<?php echo $row['id']; ?>">
                  <button type="button" class="sp-btn sp-btn-success sp-btn-sm mark-completed-btn" style="width:100%;">
                    <i class="fas fa-check"></i> <?= t('todo_completed_mark_button') ?>
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
document.querySelectorAll('.mark-completed-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const form = btn.closest('form');
    Swal.fire({
      title: <?php echo json_encode(t('todo_completed_confirm_title')); ?>,
      text: <?php echo json_encode(t('todo_completed_confirm_text')); ?>,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#38c172',
      cancelButtonColor: '#3085d6',
      confirmButtonText: <?php echo json_encode(t('todo_completed_confirm_button')); ?>
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>