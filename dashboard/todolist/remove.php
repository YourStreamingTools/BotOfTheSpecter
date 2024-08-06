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
$title = "YourListOnline - Remove Objective";

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
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

// Get the selected category filter, default to "all" if not provided
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';

// Build the SQL query based on the category filter
if ($categoryFilter === 'all') {
  $sql = "SELECT * FROM todos WHERE user_id = ? ORDER BY id ASC";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $user_id);
} else {
  $categoryFilter = mysqli_real_escape_string($conn, $categoryFilter);
  $sql = "SELECT * FROM todos WHERE user_id = ? AND category = ? ORDER BY id ASC";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("is", $user_id, $categoryFilter);
}

$stmt->execute();
$result = $stmt->get_result();
$num_rows = $result->num_rows;

// Handle errors
if (!$result) {
  echo "Error: " . $conn->error;
  exit();
}

// Handle remove item form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo_id = $_POST['todo_id'];
    // Delete item from database
    $sql = "DELETE FROM todos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $todo_id);
    $stmt->execute();
    // Redirect back to remove page
    header('Location: remove.php');
    exit;
}
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
<nav class="navbar is-spaced" role="navigation" aria-label="main navigation">
  <div class="navbar-brand">
    <a class="navbar-item" href="dashboard.php">
      YourListOnline
    </a>
    <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasicExample">
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
    </a>
  </div>
  <div id="navbarBasicExample" class="navbar-menu">
    <div class="navbar-start">
      <a class="navbar-item" href="dashboard.php">Dashboard</a>
      <a class="navbar-item" href="insert.php">Add</a>
      <a class="navbar-item is-active" href="remove.php">Remove</a>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Update</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="update_objective.php">Update Objective</a>
          <a class="navbar-item" href="update_category.php">Update Objective Category</a>
        </div>
      </div>
      <a class="navbar-item" href="completed.php">Completed</a>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Categories</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="categories.php">View Categories</a>
          <a class="navbar-item" href="add_category.php">Add Category</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Profile</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="obs_options.php">OBS Viewing Options</a>
        </div>
      </div>
    </div>
    <div class="navbar-end">
      <div class="navbar-item">
        <button id="dark-mode-toggle" class="button is-dark"><i class="icon-toggle-dark-mode"></i></button>
      </div>
      <div class="navbar-item">
        <a class="popup-link" onclick="showPopup()">&copy; 2023 YourListOnline. All rights reserved.</a>
      </div>
    </div>
  </div>
</nav>
<!-- /Navigation -->

<div class="container">
<br>
<h1 class="title"><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
<br>
<?php if ($num_rows < 1) {} else { ?>
<!-- Category Filter Dropdown & Search Bar -->
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
          $categories_sql = "SELECT * FROM categories WHERE user_id = ? OR user_id IS NULL";
          $categories_stmt = $conn->prepare($categories_sql);
          $categories_stmt->bind_param("i", $user_id);
          $categories_stmt->execute();
          $categories_result = $categories_stmt->get_result();
          while ($category_row = $categories_result->fetch_assoc()) {
            $categoryId = $category_row['id'];
            $categoryName = htmlspecialchars($category_row['category']);
            $selected = ($categoryFilter == $categoryId) ? 'selected' : '';
            echo "<option value=\"$categoryId\" $selected>$categoryName</option>";
          }
          $categories_stmt->close();
        ?>
      </select>
    </div>
  </p>
</div>
<!-- /Category Filter Dropdown & Search Bar -->
<?php } ?>

<div class="container">
<?php if ($num_rows < 1) { echo '<h3 class="has-text-danger">There are no rows to edit</h3>'; } else { ?>
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
    <?php while ($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($row['objective']) ?></td>
        <td>
          <?php
            $category_id = $row['category'];
            $category_sql = "SELECT category FROM categories WHERE id = ?";
            $category_stmt = $conn->prepare($category_sql);
            $category_stmt->bind_param("i", $category_id);
            $category_stmt->execute();
            $category_result = $category_stmt->get_result();
            $category_row = $category_result->fetch_assoc();
            echo htmlspecialchars($category_row['category']);
            $category_stmt->close();
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
    <?php endwhile; ?>
  </tbody>
</table>
<?php } ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/about.js"></script>
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