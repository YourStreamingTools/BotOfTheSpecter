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
$pageTitle = t('todolist_completed_title');

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

// Check if a specific category is selected
$categoryFilter = isset($_GET['category']) ? intval($_GET['category']) : 'all';
if ($categoryFilter !== 'all') {
  $sql = "SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id WHERE t.category = ? AND t.completed = 'No'";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $categoryFilter);
} else {
  $sql = "SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id WHERE t.completed = 'No'";
  $stmt = $db->prepare($sql);
}

$stmt->execute();
$incompleteTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($incompleteTasks);

// Mark task as completed
if (isset($_POST['task_id'])) {
  $task_id = intval($_POST['task_id']);
  $sql = "UPDATE todos SET completed = 'Yes' WHERE id = ?";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $task_id);
  $stmt->execute();
  
  header('Location: completed.php');
  exit();
}

// Retrieve categories for the filter dropdown
$categorySql = "SELECT * FROM categories";
$categoryStmt = $db->query($categorySql);
$categories = $categoryStmt->fetch_all(MYSQLI_ASSOC);

// Check if a specific category is selected
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';

ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-check-double"></i> Mark Tasks as Completed</div>
  </div>
  <div class="sp-card-body">
    <?php if ($num_rows < 1): ?>
      <div class="sp-alert sp-alert-info" style="display:flex; align-items:center; gap:0.75rem;">
        <i class="fas fa-tasks fa-2x" style="color:var(--blue); flex-shrink:0;"></i>
        <div>
          <strong>Your to-do list is empty!</strong>
          <p style="margin-bottom:0;">Start adding tasks to get organized.</p>
        </div>
      </div>
    <?php else: ?>
      <div style="display:flex; align-items:flex-end; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
        <div style="flex:1; min-width:200px;">
          <label for="searchInput" class="sp-label">Search Tasks</label>
          <input class="sp-input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search objectives">
        </div>
        <div style="min-width:200px;">
          <label for="categoryFilter" class="sp-label">Filter by Category</label>
          <select id="categoryFilter" class="sp-select">
            <option value="all" <?php if ($categoryFilter === 'all') echo 'selected'; ?>>All</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?php echo $category['id']; ?>" <?php if ($categoryFilter == $category['id']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($category['category']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p style="margin-bottom:1rem; color:var(--text-secondary);">Number of total tasks in the category: <?php echo $num_rows; ?></p>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem;" id="taskCardList">
        <?php foreach ($incompleteTasks as $row): ?>
          <div class="sp-card" style="margin-bottom:0;">
            <div class="sp-card-body" style="display:flex; flex-direction:column; gap:0.75rem; height:100%;">
              <div style="flex:1;">
                <p style="font-weight:600; margin-bottom:0.3rem;"><?php echo htmlspecialchars($row['objective']); ?></p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0; display:flex; align-items:center; gap:0.3rem;">
                  <i class="fas fa-folder"></i>
                  <?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?>
                </p>
              </div>
              <div>
                <form method="post" action="completed.php" style="margin-bottom:0;" class="mark-completed-form">
                  <input type="hidden" name="task_id" value="<?php echo $row['id']; ?>">
                  <button type="button" class="sp-btn sp-btn-success sp-btn-sm mark-completed-btn" style="width:100%;">
                    <i class="fas fa-check"></i> Mark as completed
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
  document.getElementById("categoryFilter").addEventListener("change", function() {
    var selectedCategoryId = this.value;
    window.location.href = "completed.php?category=" + selectedCategoryId;
  });
</script>
<script>
document.querySelectorAll('.mark-completed-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const form = btn.closest('form');
    Swal.fire({
      title: 'Mark as completed?',
      text: "This will mark the task as completed.",
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#38c172',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, mark completed!'
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