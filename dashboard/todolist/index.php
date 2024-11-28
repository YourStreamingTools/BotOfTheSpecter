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

// Connect to the primary database
require_once "db_connect.php";

// Fetch the user's data from the primary database based on the access_token
$access_token = $_SESSION['access_token'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

// Include the secondary database connection
include 'database.php';

// Get the selected category filter, default to "all" if not provided
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';

// Build the SQL query based on the category filter
if ($categoryFilter === 'all') {
  $sql = "SELECT * FROM todos ORDER BY id ASC";
  $stmt = $db->prepare($sql);
} else {
  $sql = "SELECT * FROM todos WHERE category = ? ORDER BY id ASC";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $categoryFilter);
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
            $categories_stmt = $db->query($categories_sql);
            $categories_result = $categories_stmt->fetch_all(MYSQLI_ASSOC);
            foreach ($categories_result as $category_row) {
              $categoryId = $category_row['id'];
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
    <table class="table is-striped is-fullwidth sortable" id="commandsTable">
      <thead>
        <tr>
          <th width="700">Objective</th>
          <th width="300">Category</th>
          <th width="300">Created</th>
          <th width="300">Last Updated</th>
          <th width="150">Completed</th>
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
            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
            <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
            <td><?php echo htmlspecialchars($row['completed']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?> 
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
<script src="../js/search.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/sorttable.js"></script>
<script>
  // JavaScript function to handle the category filter change
  function applyCategoryFilter() {
    var selectedCategoryId = document.getElementById("categoryFilter").value;
    // Redirect to the page with the selected category filter
    window.location.href = "index.php?category=" + selectedCategoryId;
  }
</script>
</body>
</html>