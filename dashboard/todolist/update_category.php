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
$title = "YourListOnline - Update Objective Category";

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

// Get user's to-do list
$stmt = $db->prepare("SELECT * FROM todos ORDER BY id DESC");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$num_rows = count($rows);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  foreach ($rows as $row) {
    $row_id = $row['id'];
    $new_category = $_POST['category'][$row_id];
    // Check if the row has been updated
    if ($new_category != $row['category']) {
      $updateStmt = $db->prepare("UPDATE todos SET category = :category WHERE id = :id");
      $updateStmt->bindParam(':category', $new_category, PDO::PARAM_STR);
      $updateStmt->bindParam(':id', $row_id, PDO::PARAM_INT);
      $updateStmt->execute();
    }
  }
  header('Location: update_category.php');
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
      <a class="navbar-item" href="remove.php">Remove</a>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Update</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="update_objective.php">Update Objective</a>
          <a class="navbar-item is-active" href="update_category.php">Update Objective Category</a>
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
<?php if ($num_rows < 1) { echo '<h3 class="has-text-danger">There are no rows to edit</h3>'; } else { echo "<h2 class='subtitle'>Please pick which row to update on your list:</h2>"; ?>
<form method="POST">
<button type="submit" name="submit" class="button is-primary">Update All</button>
<table class="table is-fullwidth is-striped">
  <thead>
    <tr>
      <th>Objective</th>
      <th>Category</th>
      <th>Update Category</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $row) { ?>
      <tr>
        <td><?php echo htmlspecialchars($row['objective']); ?></td>
        <td>
          <?php
            $category_id = $row['category'];
            $category_stmt = $db->prepare("SELECT category FROM categories WHERE id = :category_id");
            $category_stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
            $category_stmt->execute();
            $category_row = $category_stmt->fetch(PDO::FETCH_ASSOC);
            echo htmlspecialchars($category_row['category']);
          ?>
        </td>
        <td>
          <div class="select">
            <select id="category" name="category[<?php echo $row['id']; ?>]" class="form-control">
              <?php
                $stmt = $db->prepare("SELECT * FROM categories WHERE user_id IS NULL");
                $stmt->execute();
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($categories as $category_row) {
                  $selected = ($category_row['id'] == $row['category']) ? 'selected' : '';
                  echo '<option value="'.$category_row['id'].'" '.$selected.'>'.htmlspecialchars($category_row['category']).'</option>';
                }
              ?>
            </select>
          </div>
        </td>
      </tr>
    <?php } ?>
  </tbody>
</table>
</form>
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
    window.location.href = "update_category.php?category=" + selectedCategoryId;
  }
</script>
</body>
</html>