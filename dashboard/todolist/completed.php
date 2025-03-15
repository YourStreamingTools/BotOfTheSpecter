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
$title = "YourListOnline - Completed";

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

// Check if a specific category is selected
if (isset($_GET['category'])) {
  $category_id = $_GET['category'];
  $sql = "SELECT * FROM todos WHERE category = ? AND completed = 'No'";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $category_id);
} else {
  $sql = "SELECT * FROM todos WHERE completed = 'No'";
  $stmt = $db->prepare($sql);
}

$stmt->execute();
$incompleteTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($incompleteTasks);

// Mark task as completed
if (isset($_POST['task_id'])) {
  $task_id = $_POST['task_id'];
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
          <p>Start adding tasks to get organized.</p> 
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="field is-grouped">
      <input class="input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search objectives">
      <p class="control">
        <div class="select">
          <select id="categoryFilter" onchange="applyCategoryFilter()">
            <option value="all" <?php if ($categoryFilter === 'all') echo 'selected'; ?>>All</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?php echo $category['id']; ?>" <?php if ($categoryFilter == $category['id']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($category['category']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </p>
    </div>
    <h3>Completed Tasks:</h3>
    <br>
    <h4>Number of total tasks in the category: <?php echo $num_rows; ?></h4> 
    <table class="table is-striped is-fullwidth sortable" id="commandsTable">
      <thead>
        <tr>
          <th width="700">Objective</th>
          <th width="300">Category</th>
          <th width="200">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incompleteTasks as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['objective']); ?></td>
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
            <td>
              <form method="post" action="completed.php">
                <input type="hidden" name="task_id" value="<?php echo $row['id']; ?>">
                <button type="submit" class="button is-primary">Mark as completed</button>
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
<script src="../js/search.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/sorttable.js"></script>
<script>
  // JavaScript function to handle the category filter change
  document.getElementById("categoryFilter").addEventListener("change", function() {
    var selectedCategoryId = this.value;
    // Redirect to the page with the selected category filter
    window.location.href = "completed.php?category=" + selectedCategoryId;
  });
</script>
</body>
</html>