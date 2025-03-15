<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: ../login.php');
  exit();
}

// Page Title
$title = "YourListOnline - Dashboard";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '../userdata.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Include the secondary database connection
include 'database.php';

// Get the selected category filter, default to "all" if not provided
$categoryFilter = isset($_GET['category']) ? htmlspecialchars($_GET['category']) : 'all';

// Build the SQL query based on the category filter
if ($categoryFilter === 'all') {
  $sql = "SELECT * FROM todos ORDER BY id ASC";
  $stmt = $db->prepare($sql);
} else {
  $sql = "SELECT * FROM todos WHERE category = ? ORDER BY id ASC";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("s", $categoryFilter);
}

$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $title; ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/css/bulma.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="../css/bulma-custom.css">
  <link rel="icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png" type="image/png" />
  <link rel="apple-touch-icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png">
</head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <br>
  <div class="field is-grouped">
    <p class="control is-expanded">
    <div class="field">
      <div class="control">
        <input class="input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search objectives">
      </div>
    </div>
    </p>
    <p class="control">
      <div class="select">
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
    </p>
  </div>
  <?php if ($num_rows < 1): ?>
    <div class="notification is-info">
      <div class="columns is-vcentered">
        <div class="column is-narrow">
          <span class="icon is-large">
            <i class="fas fa-tasks fa-2x"></i> 
          </span>
        </div>
        <div class="column">
          <p><strong>Your to-do list is empty!</strong></p>
          <p>Start adding tasks to get organized.</p> 
        </div>
      </div>
    </div>
  <?php else: ?>
    <h4>Number of total tasks in the category: <?php echo $num_rows; ?></h4> 
    <div class="table-container">
      <table class="table is-fullwidth is-narrow sortable" id="commandsTable">
        <thead>
          <tr>
            <th>Objective</th>
            <th>Category</th>
            <th>Created</th>
            <th>Last Updated</th>
            <th>Completed</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result as $row): ?>
            <tr>
              <td><?php echo ($row['completed'] == 'Yes') ? '<s>' . htmlspecialchars($row['objective']) . '</s>' : htmlspecialchars($row['objective']); ?></td>
              <td>
                <?php
                  $category_id = $row['category'];
                  $category_sql = "SELECT category FROM categories WHERE id = ?";
                  $category_stmt = $db->prepare($category_sql);
                  $category_stmt->bind_param("i", $category_id);
                  $category_stmt->execute();
                  $category_row = $category_stmt->get_result()->fetch_assoc();
                  echo htmlspecialchars($category_row['category']);
                ?>
              </td>
              <td><span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['created_at']); ?>"><?php echo htmlspecialchars($row['created_at']); ?></span></td>
              <td><span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['updated_at']); ?>"><?php echo htmlspecialchars($row['updated_at']); ?></span></td>
              <td><?php echo htmlspecialchars($row['completed']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?> 
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
<script src="../js/search.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/sorttable.js"></script>
<script>
  function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;

    const seconds = Math.floor(diff / 1000) % 60;
    const minutes = Math.floor(diff / (1000 * 60)) % 60;
    const hours = Math.floor(diff / (1000 * 60 * 60)) % 24;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24)) % 30;
    const months = Math.floor(diff / (1000 * 60 * 60 * 24 * 30)) % 12;
    const years = Math.floor(diff / (1000 * 60 * 60 * 24 * 365));
    let result = '';
    if (years > 0) result += `${years} year${years > 1 ? 's' : ''}, `;
    if (months > 0) result += `${months} month${months > 1 ? 's' : ''}, `;
    if (days > 0) result += `${days} day${days > 1 ? 's' : ''}, `;
    if (hours > 0) result += `${hours} hour${hours > 1 ? 's' : ''}, `;
    if (minutes > 0) result += `${minutes} minute${minutes > 1 ? 's' : ''}, `;
    result += `${seconds} second${seconds > 1 ? 's' : ''} ago`;
    return result;
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
  // JavaScript function to handle the category filter change
  function applyCategoryFilter() {
    var selectedCategoryId = document.getElementById("categoryFilter").value;
    // Redirect to the page with the selected category filter
    window.location.href = "index.php?category=" + selectedCategoryId;
  }
</script>
</body>
</html>