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
$title = "YourListOnline - Remove Objective";

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
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';

// Build the SQL query based on the category filter
if ($categoryFilter === 'all') {
  $stmt = $db->prepare("SELECT * FROM todos ORDER BY id ASC");
} else {
  $stmt = $db->prepare("SELECT * FROM todos WHERE category = ? ORDER BY id ASC");
  $stmt->bind_param('s', $categoryFilter);
}

$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($result);

// Handle remove item form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $todo_id = $_POST['todo_id'];
  // Delete item from database
  $stmt = $db->prepare("DELETE FROM todos WHERE id = ?");
  $stmt->bind_param('i', $todo_id);
  $stmt->execute();
  // Redirect back to remove page
  header('Location: remove.php');
  exit();
}
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
          <p>You can't remove any tasks because there aren't any yet.</p> 
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="field is-grouped">
      <p class="control is-expanded">
        <form method="GET" action="">
          <input type="text" name="search" placeholder="Search todos" class="input">
        </form>
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
    <h1 class="title">Please pick which task to remove from your list:</h1>
    <table class="table is-fullwidth is-striped">
      <thead>
        <tr>
          <th>Objective</th>
          <th>Category</th>
          <th>Completed</th>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['objective']) ?></td>
          <td>
            <?php
            $category_id = $row['category'];
            $category_stmt = $db->prepare("SELECT category FROM categories WHERE id = ?");
            $category_stmt->bind_param('i', $category_id);
            $category_stmt->execute();
            $category_row = $category_stmt->get_result()->fetch_assoc();
            echo htmlspecialchars($category_row['category']);
            ?>
          </td>
          <td><?= htmlspecialchars($row['completed']) ?></td>
          <td>
            <form method="POST">
              <input type="hidden" name="todo_id" value="<?= $row['id'] ?>">
              <button type="submit" class="button is-danger">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
<script>
  // JavaScript function to handle the category filter change
  function applyCategoryFilter() {
    var selectedCategoryId = document.getElementById("categoryFilter").value;
    // Redirect to the page with the selected category filter
    window.location.href = "remove.php?category=" + selectedCategoryId;
  }
</script>
</body>
</html>