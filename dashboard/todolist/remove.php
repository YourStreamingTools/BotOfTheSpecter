<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$title = t('todolist_remove_objective_title');
$pageTitle = $title;
ob_start();

// Include necessary files
require_once "/var/www/config/db_connect.php";
include '../includes/userdata.php';
session_write_close();
include '../includes/bot_control.php';
include "../includes/mod_access.php";
include '../includes/user_db.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

require_once '/var/www/config/database.php';
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Get the selected category filter, default to "all" if not provided
$categoryFilter = isset($_GET['category']) ? intval($_GET['category']) : 'all';

// Build the SQL query based on the category filter (use join to include category name)
if ($categoryFilter === 'all') {
  $stmt = $db->prepare("SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id ORDER BY t.id ASC");
} else {
  $stmt = $db->prepare("SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id WHERE t.category = ? ORDER BY t.id ASC");
  $stmt->bind_param('i', $categoryFilter);
}

$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($result);

// Handle remove item form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $todo_id = intval($_POST['todo_id']);
  // Delete item from database
  $stmt = $db->prepare("DELETE FROM todos WHERE id = ?");
  $stmt->bind_param('i', $todo_id);
  $stmt->execute();
  // Redirect back to remove page
  header('Location: remove.php');
  exit();
}
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-trash"></i> <?= t('todo_remove_card_title') ?></div>
  </div>
  <div class="sp-card-body">
    <?php if ($num_rows < 1): ?>
      <div class="sp-alert sp-alert-info" style="display:flex; align-items:center; gap:0.75rem;">
        <i class="fas fa-tasks fa-2x" style="color:var(--blue); flex-shrink:0;"></i>
        <div>
          <strong><?= t('todo_remove_empty_heading') ?></strong>
          <p style="margin-bottom:0;"><?= t('todo_remove_empty_text') ?></p>
        </div>
      </div>
    <?php else: ?>
      <div style="display:flex; align-items:flex-end; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
        <div style="flex:1; min-width:200px;">
          <label for="searchInput" class="sp-label"><?= t('todo_remove_search_label') ?></label>
          <input type="text" name="search" id="searchInput" placeholder="<?= htmlspecialchars(t('todo_remove_search_placeholder')) ?>" class="sp-input" onkeyup="searchFunction()">
        </div>
        <div style="min-width:200px;">
          <label for="categoryFilter" class="sp-label"><?= t('todo_remove_filter_label') ?></label>
          <select id="categoryFilter" class="sp-select" onchange="applyCategoryFilter()">
            <option value="all" <?php if ($categoryFilter === 'all') echo 'selected'; ?>><?= t('todo_remove_filter_all') ?></option>
            <?php
            $categories_sql = "SELECT * FROM categories";
            $categories_stmt = $db->prepare($categories_sql);
            $categories_stmt->execute();
            $categories_result = $categories_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($categories_result as $category_row) {
              $categoryId = $category_row['id'];
              $categoryName = htmlspecialchars($category_row['category']);
              $selected = ($categoryFilter == $categoryId) ? 'selected' : '';
              echo "<option value=\"$categoryId\" $selected>$categoryName</option>";
            }
            ?>
          </select>
        </div>
      </div>
      <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem;"><?= t('todo_remove_pick_heading') ?></h2>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem;" id="taskCardList">
        <?php foreach ($result as $row): ?>
          <div class="sp-card" style="margin-bottom:0;">
            <div class="sp-card-body" style="display:flex; flex-direction:column; gap:0.75rem; height:100%;">
              <div style="flex:1;">
                <p style="font-weight:600; margin-bottom:0.3rem;"><?= htmlspecialchars($row['objective']) ?></p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.3rem; display:flex; align-items:center; gap:0.3rem;">
                  <i class="fas fa-folder"></i>
                  <?php echo htmlspecialchars($row['category_name'] ?? t('todo_remove_uncategorized')); ?>
                </p>
                <?= ($row['completed'] === 'Yes')
                  ? '<span class="sp-badge sp-badge-green">' . t('todo_remove_badge_completed') . '</span>'
                  : '<span class="sp-badge sp-badge-amber">' . t('todo_remove_badge_not_completed') . '</span>' ?>
              </div>
              <div>
                <form method="POST" style="margin-bottom:0;" class="remove-task-form">
                  <input type="hidden" name="todo_id" value="<?= $row['id'] ?>">
                  <button type="button" class="sp-btn sp-btn-danger sp-btn-sm remove-task-btn" style="width:100%;">
                    <i class="fas fa-trash"></i> <?= t('todo_remove_btn_remove') ?>
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
function applyCategoryFilter() {
  var selectedCategoryId = document.getElementById("categoryFilter").value;
  window.location.href = "remove.php?category=" + selectedCategoryId;
}

document.querySelectorAll('.remove-task-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const form = btn.closest('form');
    Swal.fire({
      title: 'Are you sure?',
      text: "This will remove the task.",
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