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
$pageTitle = t('todolist_dashboard_title');

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

// Get the selected category filter, default to "all" if not provided
$categoryFilter = isset($_GET['category']) ? intval($_GET['category']) : 'all';

// Build the SQL query based on the category filter (use join to include category name)
if ($categoryFilter === 'all') {
  $sql = "SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id ORDER BY t.id ASC";
  $stmt = $db->prepare($sql);
} else {
  $sql = "SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id WHERE t.category = ? ORDER BY t.id ASC";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $categoryFilter);
}
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($result);

ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-list-check"></i> Your Tasks</div>
  </div>
  <div class="sp-card-body">
    <div style="display:flex; align-items:flex-end; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
      <div style="flex:1; min-width:200px;">
        <label for="searchInput" class="sp-label">Search Objectives</label>
        <input class="sp-input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search...">
      </div>
      <div style="min-width:200px;">
        <label for="categoryFilter" class="sp-label">Filter by Category</label>
        <select id="categoryFilter" class="sp-select" onchange="applyCategoryFilter()">
          <option value="all" <?php if ($categoryFilter === 'all') echo 'selected'; ?>>All</option>
          <?php
            $categories_sql = "SELECT * FROM categories";
            $categories_stmt = $db->prepare($categories_sql);
            $categories_stmt->execute();
            $categories_result = $categories_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($categories_result as $category_row) {
              $categoryId = htmlspecialchars($category_row['id']);
              $categoryName = htmlspecialchars($category_row['category']);
              $selected = ($categoryFilter == $categoryId) ? 'selected' : '';
              echo "<option value=\"$categoryId\" $selected>$categoryName</option>";
            }
          ?>
        </select>
      </div>
    </div>
    <?php if ($num_rows < 1): ?>
      <div class="sp-alert sp-alert-info">
        <i class="fas fa-tasks" style="margin-right:0.5rem;"></i>
        <strong>Your to-do list is empty!</strong> Start adding tasks to get organized.
      </div>
    <?php else: ?>
      <p style="margin-bottom:1rem; color:var(--text-secondary);">Number of total tasks in the category: <?php echo $num_rows; ?></p>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem;" id="taskCardList">
        <?php foreach ($result as $row): ?>
          <div class="sp-card" style="margin-bottom:0;">
            <div class="sp-card-body">
              <p style="font-weight:600; margin-bottom:0.4rem;">
                <?php
                  $objective = htmlspecialchars($row['objective']);
                  echo ($row['completed'] == 'Yes') ? '<s>' . $objective . '</s>' : $objective;
                ?>
              </p>
              <p style="font-size:0.8rem; margin-bottom:0.4rem; color:var(--text-secondary); display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                <i class="fas fa-folder"></i>
                <?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?>
                <?php echo ($row['completed'] === 'Yes')
                  ? '<span class="sp-badge sp-badge-green">Completed</span>'
                  : '<span class="sp-badge sp-badge-amber">Not completed</span>'; ?>
              </p>
              <p style="font-size:0.8rem; display:flex; align-items:center; gap:0.3rem; color:var(--text-secondary); margin-bottom:0.2rem;">
                <i class="fas fa-calendar-plus"></i>
                Created: <span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['created_at']); ?>"><?php echo htmlspecialchars($row['created_at']); ?></span>
              </p>
              <p style="font-size:0.8rem; display:flex; align-items:center; gap:0.3rem; color:var(--text-secondary); margin-bottom:0;">
                <i class="fas fa-calendar-pen"></i>
                Updated: <span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['updated_at']); ?>"><?php echo htmlspecialchars($row['updated_at']); ?></span>
              </p>
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
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="../js/search.js"></script>
<script>
  function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    if (diff < 60) return diff + ' second' + (diff !== 1 ? 's' : '') + ' ago';
    const minutes = Math.floor(diff / 60);
    if (minutes < 60) return minutes + ' minute' + (minutes !== 1 ? 's' : '') + ' ago';
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + ' hour' + (hours !== 1 ? 's' : '') + ' ago';
    const days = Math.floor(hours / 24);
    if (days < 30) return days + ' day' + (days !== 1 ? 's' : '') + ' ago';
    const months = Math.floor(days / 30);
    if (months < 12) return months + ' month' + (months !== 1 ? 's' : '') + ' ago';
    const years = Math.floor(days / 365);
    return years + ' year' + (years !== 1 ? 's' : '') + ' ago';
  }
  function updateTimestamps() {
    const elements = document.querySelectorAll('.timestamp');
    elements.forEach(el => {
      const timestamp = el.getAttribute('data-timestamp');
      el.textContent = formatTimestamp(timestamp);
    });
  }
  setInterval(updateTimestamps, 1000);
  document.addEventListener('DOMContentLoaded', updateTimestamps);
  function applyCategoryFilter() {
    var selectedCategoryId = document.getElementById("categoryFilter").value;
    window.location.href = "index.php?category=" + selectedCategoryId;
  }
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>