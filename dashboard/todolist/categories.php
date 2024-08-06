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
$title = "YourListOnline - View Categories";

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

// Get categories from the database for the logged-in user
$query = "SELECT * FROM categories WHERE user_id = '$user_id' OR user_id IS NULL";
$result = $conn->query($query);

if (!$result) {
    die("Error retrieving categories: " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title></title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourlistonline.com.au/css/custom.css">
    <script src="https://cdn.yourlistonline.com.au/js/about.js"></script>
    <script src="https://cdn.yourlistonline.com.au/js/sorttable.js"></script>
  	<link rel="icon" href="https://cdn.yourlistonline.com.au/img/logo.png" type="image/png" />
  	<link rel="apple-touch-icon" href="https://cdn.yourlistonline.com.au/img/logo.png">
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
          <li class="is-active"><a href="categories.php">View Categories</a></li>
          <li><a href="add_category.php">Add Category</a></li>
        </ul>
      </li>
      <li>
        <a>Profile</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="profile.php">View Profile</a></li>
          <li><a href="update_profile.php">Update Profile</a></li>
          <li><a href="obs_options.php">OBS Viewing Options</a></li>
          <li><a href="twitch_mods.php">Twitch Mods</a></li>
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
<p>Here is the current list of categories you can filter your lists in, each category is it's own list.<br>
   The categories in this list are either created by you or publicly accessible.</p>
<table class="sortable dark-mode-table">
  <thead>
    <tr>
      <th width="50">ID</th>
      <th width="100%">Category</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($row = $result->fetch_assoc()): ?>
    <tr>
      <td><?php echo htmlspecialchars($row['id']) ?></td>
      <td><?php echo htmlspecialchars($row['category']) ?></td>
    </tr>
    <?php endwhile ?>
  </tbody>
</table=>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script src="https://cdn.yourlistonline.com.au/js/darkmode.js"></script>
<script>$(document).foundation();</script>
<script>
  // JavaScript function to handle the category filter change
  document.getElementById("categoryFilter").addEventListener("change", function() {
    var selectedCategoryId = this.value;
    // Redirect to the page with the selected category filter
    window.location.href = "categories.php?category=" + selectedCategoryId;
  });
</script>
</body>
</html>