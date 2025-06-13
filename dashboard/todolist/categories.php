<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_category_id'])) {
  $remove_id = intval($_POST['remove_category_id']);
  // Remove the category
  $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
  $stmt->bind_param("i", $remove_id);
  $stmt->execute();
  // Optionally, you may want to handle orphaned todos here
  header("Location: categories.php");
  exit();
}
ob_start();
?>
<div class="notification is-info">
  <div class="columns is-vcentered">
    <div class="column is-narrow">
      <span class="icon is-large">
        <i class="fas fa-list-ul fa-2x"></i> 
      </span>
    </div>
    <div class="column">
      <p><strong>Manage Your Categories</strong></p>
      <p>Here's the list of categories you've created. Each category helps you organize your tasks into separate lists.</p> 
    </div>
  </div>
</div>
<div class="columns is-multiline">
  <?php foreach ($result as $row): ?>
    <div class="column is-4-tablet is-3-desktop">
      <div class="box" style="background: transparent; border-radius: 14px; border: 1px solid #d3d3d3; box-shadow: none; padding: 1.5rem 1.25rem;">
        <div class="media is-align-items-center">
          <div class="media-left is-flex is-align-items-center">
            <span class="icon has-text-info is-large">
              <i class="fas fa-folder fa-lg"></i>
            </span>
          </div>
          <div class="media-content">
            <p class="title is-5 mb-1" style="word-break: break-word;"><?php echo htmlspecialchars($row['category']); ?></p>
            <p class="subtitle is-7" style="font-size: 1rem; color: #fff;">ID: <?php echo htmlspecialchars($row['id']); ?></p>
          </div>
          <div class="media-right">
            <form method="post" style="margin-bottom:0;" class="remove-category-form">
              <input type="hidden" name="remove_category_id" value="<?php echo $row['id']; ?>">
              <button type="button" class="button is-danger is-rounded is-small remove-category-btn">
                <span class="icon"><i class="fas fa-trash"></i></span>
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
include 'layout_todolist.php';
?>