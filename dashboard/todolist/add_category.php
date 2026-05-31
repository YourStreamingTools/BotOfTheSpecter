<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('todolist_add_category_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
include '../bot_control.php';
include "../mod_access.php";
include '../user_db.php';
include '../storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Initialize variables
$category = "";
$category_err = "";
$message = "";
$messageType = "";

// Process form submission when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Validate category
  if (empty(trim($_POST["category"]))) {
    $category_err = t('todo_add_category_err_empty');
  } else {
    // Prepare a select statement
    $sql = "SELECT id FROM categories WHERE category = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $param_category);
    $param_category = trim($_POST["category"]);
    // Attempt to execute the prepared statement
    $stmt->execute();
    $stmt->store_result(); // To check row count
    if ($stmt->num_rows == 1) {
      $category_err = t('todo_add_category_err_exists');
    } else {
      $category = trim($_POST["category"]);
    }
  }
  // Check input errors before inserting into the database
  if (empty($category_err)) {
    // Prepare an insert statement
    $sql = "INSERT INTO categories (category) VALUES (?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $category); // Using the final category value
    // Attempt to execute the prepared statement
    if ($stmt->execute()) {
      // Redirect to categories page
      $message = t('todo_add_category_msg_success');
      $messageType = "is-success";
    } else {
      $message = t('todo_add_category_msg_error');
      $messageType = "is-danger";
    }
  }
}

ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-folder-plus"></i> <?= t('todo_add_category_card_title') ?></div>
  </div>
  <div class="sp-card-body">
    <?php if ($message): ?>
      <div class="sp-alert sp-alert-<?php echo preg_replace('/^is-/', '', $messageType); ?>" style="margin-bottom:1rem;">
        <?php if ($messageType === 'is-danger'): ?>
          <i class="fas fa-exclamation-triangle" style="margin-right:0.4rem;"></i>
        <?php elseif ($messageType === 'is-warning'): ?>
          <i class="fas fa-exclamation-circle" style="margin-right:0.4rem;"></i>
        <?php elseif ($messageType === 'is-success'): ?>
          <i class="fas fa-check-circle" style="margin-right:0.4rem;"></i>
        <?php else: ?>
          <i class="fas fa-info-circle" style="margin-right:0.4rem;"></i>
        <?php endif; ?>
        <span><?php echo $message; ?></span>
      </div>
    <?php endif; ?>
    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
      <h3 style="font-size:1.05rem; font-weight:700; margin-bottom:1rem;"><?= t('todo_add_category_heading') ?></h3>
      <div class="sp-form-group <?php echo (!empty($category_err)) ? 'has-error' : ''; ?>">
        <label class="sp-label" for="category"><?= t('todo_add_category_label_name') ?></label>
        <input type="text" name="category" id="category" class="sp-input" value="<?php echo htmlspecialchars($category); ?>" placeholder="<?= htmlspecialchars(t('todo_add_category_placeholder_name')) ?>">
        <?php if (!empty($category_err)): ?>
          <p style="color:var(--red); font-size:0.8rem; margin-top:0.25rem;"><?php echo $category_err; ?></p>
        <?php endif; ?>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem;">
        <input type="submit" class="sp-btn sp-btn-primary" value="<?= htmlspecialchars(t('todo_add_category_btn_submit')) ?>">
        <a href="categories.php" class="sp-btn sp-btn-secondary"><?= t('todo_add_category_btn_cancel') ?></a>
      </div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>