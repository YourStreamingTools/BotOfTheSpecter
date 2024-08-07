<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: login.php');
  exit();
}

// Page Title
$title = "YourListOnline - Completed";

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

// Check if a specific category is selected
if (isset($_GET['category'])) {
  $category_id = $_GET['category'];
  $sql = "SELECT * FROM todos WHERE category = ? AND completed = 'No'";
  $stmt = $db->prepare($sql);
  $stmt->bindParam(1, $category_id, PDO::PARAM_INT);
} else {
  $sql = "SELECT * FROM todos WHERE completed = 'No'";
  $stmt = $db->prepare($sql);
}

$stmt->execute();
$incompleteTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
$num_rows = count($incompleteTasks);

// Mark task as completed
if (isset($_POST['task_id'])) {
  $task_id = $_POST['task_id'];
  $sql = "UPDATE todos SET completed = 'Yes' WHERE id = ?";
  $stmt = $db->prepare($sql);
  $stmt->bindParam(1, $task_id, PDO::PARAM_INT);
  $stmt->execute();
  
  header('Location: completed.php');
  exit();
}

// Retrieve categories for the filter dropdown
$categorySql = "SELECT * FROM categories";
$categoryStmt = $db->query($categorySql);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <link rel="icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png" type="image/png" />
    <link rel="apple-touch-icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png">
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <br>
  <h1 class="title"><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
  <br>
  <?php if ($num_rows < 1) {} else { ?>
  <!-- Category Filter Dropdown & Search Bar -->
  <div class="field is-grouped">
    <p class="control is-expanded">
      <input type="text" name="search" placeholder="Search todos" class="input">
    </p>
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
  <!-- /Category Filter Dropdown & Search Bar -->
  <?php } ?>
  <?php if ($num_rows < 1) { echo '<h4 style="color: red;">There are no tasks to show.</h4>'; } else { echo "<h3>Completed Tasks:</h3><br><h4>Number of total tasks in the category: " . $num_rows; echo "</h4>"; ?>
  <table class="table is-striped is-fullwidth sortable">
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
          $category_stmt->bindParam(1, $category_id, PDO::PARAM_INT);
          $category_stmt->execute();
          $category_row = $category_stmt->fetch(PDO::FETCH_ASSOC);
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
  <?php } ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/about.js"></script>
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