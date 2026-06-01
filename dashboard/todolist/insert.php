<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('todolist_add_objective_title');

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

// Initialize message variables
$message = ""; 
$messageType = ""; 

$objective = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  // Get form data
  $objective = isset($_POST['objective']) ? trim($_POST['objective']) : '';
  $category = isset($_POST['category']) ? intval($_POST['category']) : 1;
  $private = isset($_POST['private']) ? 1 : 0;
  // Basic validation
  if (empty($objective)) {
    $message = t('todo_insert_msg_enter_task');
    $messageType = "is-danger";
  } else {
    // Prepare and execute query
    $stmt = $db->prepare("INSERT INTO todos (objective, category, created_at, updated_at, completed, private) VALUES (?, ?, NOW(), NOW(), 'No', ?)");
    $stmt->bind_param("sii", $objective, $category, $private);
    if ($stmt->execute()) {
      $message = t('todo_insert_msg_added_success');
      $messageType = "is-success";
    } else {
      $message = t('todo_insert_msg_add_error');
      $messageType = "is-danger";
    }
  }
} 

ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-plus"></i> <?= t('todo_insert_card_title') ?></div>
  </div>
  <div class="sp-card-body">
    <?php if ($message): ?>
      <div class="sp-alert sp-alert-<?php echo preg_replace('/^is-/', '', $messageType); ?>" style="margin-bottom:1rem;">
        <?php if ($messageType === 'is-danger'): ?>
          <i class="fas fa-exclamation-triangle" style="margin-right:0.4rem;"></i>
        <?php elseif ($messageType === 'is-success'): ?>
          <i class="fas fa-check-circle" style="margin-right:0.4rem;"></i>
        <?php else: ?>
          <i class="fas fa-info-circle" style="margin-right:0.4rem;"></i>
        <?php endif; ?>
        <span><?php echo $message; ?></span>
      </div>
    <?php endif; ?>
    <form method="post">
      <div class="sp-form-group">
        <label class="sp-label" for="objective"><i class="fas fa-tasks" style="margin-right:0.3rem;"></i> <?= t('todo_insert_label_task') ?></label>
        <textarea id="objective" name="objective" class="sp-textarea" placeholder="<?= htmlspecialchars(t('todo_insert_placeholder_task')) ?>"><?php echo htmlspecialchars($objective); ?></textarea>
      </div>
      <div class="sp-form-group">
        <label class="sp-label" for="category"><?= t('todo_insert_label_category') ?></label>
        <select id="category" name="category" class="sp-select">
          <?php
          $stmt = $db->query("SELECT * FROM categories");
          $result = $stmt->fetch_all(MYSQLI_ASSOC);
          foreach ($result as $row) {
            echo '<option value="'.htmlspecialchars($row['id']).'">'.htmlspecialchars($row['category']).'</option>';
          }
          ?>
        </select>
      </div>
      <div class="sp-form-group" style="margin-top:1rem;">
        <label class="sp-label" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
          <input type="checkbox" name="private" id="private" value="1">
          <i class="fas fa-eye-slash" style="margin-right:0.2rem;"></i> <?= t('todo_insert_label_private') ?>
        </label>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem;">
        <button type="submit" class="sp-btn sp-btn-primary"><?= t('todo_insert_btn_add') ?></button>
        <a href="index.php" class="sp-btn sp-btn-secondary"><?= t('todo_insert_btn_cancel') ?></a>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>