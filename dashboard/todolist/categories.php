<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('todolist_categories_title');

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

// Get categories from the secondary database
$query = "SELECT * FROM categories";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!$result) {
  die("Error retrieving categories: " . $db->error);
}

// Handle remove category form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_category_id'])) {
  $remove_id = intval($_POST['remove_category_id']);
  // Prevent deleting default category (id 1)
  if ($remove_id === 1) {
    $message = t('todo_categories_msg_cannot_remove_default');
  } else {
    // Check if category exists
    $stmt = $db->prepare("SELECT id FROM categories WHERE id = ?");
    $stmt->bind_param("i", $remove_id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    if ($exists) {
      // Ensure default category exists (id 1); if not, create it
      $stmt = $db->prepare("SELECT id FROM categories WHERE id = 1");
      $stmt->execute();
      $default_exists = $stmt->get_result()->fetch_assoc();
      if (!$default_exists) {
        $stmt = $db->prepare("INSERT INTO categories (id, category) VALUES (1, 'Default')");
        $stmt->execute();
      }
      // Reassign any todos in this category to default (1)
      $stmt = $db->prepare("UPDATE todos SET category = 1 WHERE category = ?");
      $stmt->bind_param("i", $remove_id);
      $stmt->execute();
      // Now delete the category
      $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
      $stmt->bind_param("i", $remove_id);
      $stmt->execute();
      header("Location: categories.php");
      exit();
    } else {
      $message = t('todo_categories_msg_not_found');
    }
  }
}
ob_start();
?>
<div class="sp-alert sp-alert-info todo-cat-intro">
  <span class="todo-cat-intro-icon"><i class="fas fa-list-ul"></i></span>
  <div>
    <p style="font-weight:700;"><?= t('todo_categories_intro_heading') ?></p>
    <p><?= t('todo_categories_intro_text') ?></p>
  </div>
</div>
<?php if (!empty($message)): ?>
  <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<div class="todo-cat-grid">
  <?php foreach ($result as $row): ?>
    <div class="sp-card todo-cat-card">
      <div class="sp-card-body todo-cat-body">
        <span class="todo-cat-icon"><i class="fas fa-folder"></i></span>
        <p class="todo-cat-name"><?php echo htmlspecialchars($row['category']); ?></p>
        <p class="todo-cat-id"><?= t('todo_categories_id_label') ?> <?php echo htmlspecialchars($row['id']); ?></p>
      </div>
      <form method="post" class="remove-category-form todo-cat-remove-form">
        <input type="hidden" name="remove_category_id" value="<?php echo $row['id']; ?>">
        <button type="button" class="sp-btn sp-btn-danger sp-btn-sm remove-category-btn">
          <i class="fas fa-trash"></i>
        </button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.remove-category-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const form = btn.closest('form');
    Swal.fire({
      title: 'Are you sure?',
      text: "This will remove the category.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, remove it!'
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