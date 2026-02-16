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
<div class="card" style="border-radius: 18px;">
  <header class="card-header">
    <p class="card-header-title is-size-4">
      <span class="icon"><i class="fas fa-list-check"></i></span>
      <span class="ml-2">Your Tasks</span>
    </p>
  </header>
  <div class="card-content">
    <div class="columns is-vcentered mb-4">
      <div class="column is-9">
        <label for="searchInput" class="label mb-1">Search Objectives</label>
        <div class="control has-icons-left">
          <input class="input is-rounded" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search...">
          <span class="icon is-left">
            <i class="fas fa-search"></i>
          </span>
        </div>
      </div>
      <div class="column is-3 has-text-right">
        <label for="categoryFilter" class="label mb-1 has-text-left" style="display:block;">Filter by Category</label>
        <div class="control has-icons-left">
          <div class="select is-fullwidth is-rounded">
            <select id="categoryFilter" onchange="applyCategoryFilter()">
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
          <span class="icon is-left">
            <i class="fas fa-filter"></i>
          </span>
        </div>
      </div>
    </div>
    <?php if ($num_rows < 1): ?>
      <div class="notification is-info is-light">
        <span class="icon"><i class="fas fa-tasks"></i></span>
        <span class="ml-2"><strong>Your to-do list is empty!</strong> Start adding tasks to get organized.</span>
      </div>
    <?php else: ?>
      <h4 class="mb-4">Number of total tasks in the category: <?php echo $num_rows; ?></h4>
      <div class="columns is-multiline" id="taskCardList">
        <?php foreach ($result as $row): ?>
          <div class="column is-6-tablet is-4-desktop">
            <div class="box" style="border-radius: 12px;">
              <div class="media is-align-items-center">
                <div class="media-content">
                  <p class="title is-6 mb-1 is-flex is-align-items-center" style="color:#fff;">
                    <?php
                      $objective = htmlspecialchars($row['objective']);
                      echo ($row['completed'] == 'Yes') ? '<s>' . $objective . '</s>' : $objective;
                    ?>
                  </p>
                  <p class="subtitle is-7 mb-2 is-flex is-align-items-center" style="color:#fff;">
                    <span class="icon is-align-self-center"><i class="fas fa-folder"></i></span>
                    <span class="ml-1">
                      <?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?>
                    </span>
                    <span class="ml-2">
                      <?php echo ($row['completed'] === 'Yes')
                        ? '<span class="tag is-success is-light">Completed</span>'
                        : '<span class="tag is-warning is-light">Not completed</span>'; ?>
                    </span>
                  </p>
                  <p class="is-size-7 is-flex is-align-items-center" style="color:#fff;">
                    <span class="icon"><i class="fas fa-calendar-plus"></i></span>
                    <span class="ml-1">
                      Created: <span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['created_at']); ?>"><?php echo htmlspecialchars($row['created_at']); ?></span>
                    </span>
                  </p>
                  <p class="is-size-7 is-flex is-align-items-center" style="color:#fff;">
                    <span class="icon"><i class="fas fa-calendar-pen"></i></span>
                    <span class="ml-1">
                      Updated: <span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['updated_at']); ?>"><?php echo htmlspecialchars($row['updated_at']); ?></span>
                    </span>
                  </p>
                </div>
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
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bulma@1.0.0/js/bulma.min.js"></script>
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