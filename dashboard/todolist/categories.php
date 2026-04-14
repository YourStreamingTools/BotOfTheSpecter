<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ../login.php');
    exit();
}

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
    $message = 'Cannot remove the default category.';
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
      $message = 'Category not found.';
    }
  }
}
ob_start();
?>
<div class="sp-alert sp-alert-info" style="display:flex; gap:1.25rem; align-items:flex-start; margin-bottom:1.5rem;">
  <span style="font-size:1.75rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-list-ul"></i></span>
  <div>
    <p style="font-weight:700; margin-bottom:0.25rem;">Manage Your Categories</p>
    <p style="margin-bottom:0;">Here's the list of categories you've created. Each category helps you organize your tasks into separate lists.</p>
  </div>
</div>
<?php if (!empty($message)): ?>
  <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:1rem;">
  <?php foreach ($result as $row): ?>
    <div class="sp-card" style="margin-bottom:0;">
      <div class="sp-card-body">
        <div style="display:flex; align-items:center; gap:1rem;">
          <span style="font-size:1.5rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-folder"></i></span>
          <div style="flex:1; min-width:0;">
            <p style="font-weight:700; margin-bottom:0.25rem; word-break:break-word;"><?php echo htmlspecialchars($row['category']); ?></p>
            <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0;">ID: <?php echo htmlspecialchars($row['id']); ?></p>
          </div>
          <div style="flex-shrink:0;">
            <form method="post" style="margin-bottom:0;" class="remove-category-form">
              <input type="hidden" name="remove_category_id" value="<?php echo $row['id']; ?>">
              <button type="button" class="sp-btn sp-btn-danger sp-btn-sm remove-category-btn">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
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