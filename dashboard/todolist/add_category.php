<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "YourListOnline - Add Category";

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

// Initialize variables
$category = "";
$category_err = "";

// Process form submission when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Validate category
  if (empty(trim($_POST["category"]))) {
    $category_err = "Please enter a category name.";
  } else {
    // Prepare a select statement
    $sql = "SELECT id FROM categories WHERE category = ?";
    if ($stmt = $conn->prepare($sql)) {
      // Bind variables to the prepared statement as parameters
      $stmt->bind_param("s", $param_category);
      // Set parameters
      $param_category = trim($_POST["category"]);
      // Attempt to execute the prepared statement
      if ($stmt->execute()) {
        // Store result
        $stmt->store_result();
        if ($stmt->num_rows == 1) {
          $category_err = "This category name already exists.";
        } else {
          $category = trim($_POST["category"]);
        }
      } else {
        echo "Oops! Something went wrong. Please try again later.";
      }
    }
    // Close statement
    $stmt->close();
  }

// Check input errors before inserting into the database
if (empty($category_err)) {
  // Check if the 'Public' checkbox is checked
  $is_public = isset($_POST['public']) ? 1 : 0;
  // If the category is public, set user_id to NULL; otherwise, use the user's ID
  $param_user_id = $is_public ? NULL : $user_id;
  // Prepare an insert statement
  $sql = "INSERT INTO categories (category, user_id) VALUES (?, ?)";
  if ($stmt = $conn->prepare($sql)) {
    // Bind variables to the prepared statement as parameters
    $stmt->bind_param("si", $param_category, $param_user_id);
    // Set parameters
    $param_category = $category;
    // Attempt to execute the prepared statement
    if ($stmt->execute()) {
      // Redirect to categories page
      header("location: categories.php");
      exit();
    } else {
      echo "Oops! Something went wrong. Please try again later.";
    }
  }
  // Close statement
  $stmt->close();
}
  // Close connection
  $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title></title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://yourlistonline.yourcdnonline.com/css/custom.css">
    <script src="https://yourlistonline.yourcdnonline.com/js/about.js"></script>
    <link rel="icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png" type="image/png" />
    <link rel="apple-touch-icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png">
  </head>
<body>
<!-- Navigation -->
<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
  <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
  <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
  <div class="top-bar-left">
    <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
      <li class="menu-text menu-text-black">YourListOnline</li>
      <li><a href="dashboard.php">Dashboard</a></li>
      <li><a href="insert.php">Add</a></li>
      <li><a href="remove.php">Remove</a></li>
      <li>
        <a>Update</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="update_objective.php">Update Objective</a></li>
          <li><a href="update_category.php">Update Objective Category</a></li>
        </ul>
      </li>
      <li><a href="completed.php">Completed</a></li>
      <li>
        <a>Categories</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="categories.php">View Categories</a></li>
          <li class="is-active"><a href="add_category.php">Add Category</a></li>
        </ul>
      </li>
      <li>
        <a>Profile</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="obs_options.php">OBS Viewing Options</a></li>
          <li><a href="logout.php">Logout</a></li>
        </ul>
      </li>
      <?php if ($is_admin) { ?>
        <li>
        <a>Admins</a>
        <ul class="vertical menu" data-dropdown-menu>
					<li><a href="../admins/dashboard.php" target="_self">Admin Dashboard</a></li>
        </ul>
      </li>
      <?php } ?>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><button id="dark-mode-toggle"><i class="icon-toggle-dark-mode"></i></button></li>
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023 YourListOnline. All rights reserved.</a></li>
    </ul>
  </div>
</nav>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
<br>
<form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
  <h3>Type in what your new category will be:</h3>
  <div class="medium-5 large-4 cell <?php echo (!empty($category_err)) ? 'has-error' : ''; ?>">
    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($category); ?>">
    <span class="help-block"><?php echo $category_err; ?></span>
  </div>
  <div class="medium-5 large-4 cell">
    <input type="checkbox" name="public" id="publicCheckbox" value="1">
    <label for="publicCheckbox">Public Category</label>
  </div>
  <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
  <div class="medium-5 large-4 cell">
    <input type="submit" class="save-button" value="Submit">
    <a href="categories.php">Cancel</a>
  </div>
</form>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/darkmode.js"></script>
<script>$(document).foundation();</script>
<script>
  // JavaScript function to handle the category filter change
  document.getElementById("categoryFilter").addEventListener("change", function() {
    var selectedCategoryId = this.value;
    // Redirect to the page with the selected category filter
    window.location.href = "dashboard.php?category=" + selectedCategoryId;
  });
</script>
</body>
</html>