<?php
// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$title = "Dashboard";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'modding_access.php';
include 'user_db.php';
$getProfile = $db->query("SELECT timezone FROM profile");
$profile = $getProfile->fetchAll(PDO::FETCH_ASSOC);
$timezone = $profile['timezone'];
date_default_timezone_set($timezone);
?>
<!doctype html>
<html lang="en">
  <head>
    <!-- Header -->
    <?php include('header.php'); ?>
    <!-- /Header -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <br>
  <div class="columns is-desktop is-multiline box-container">
    Editing User ID: <?php echo $_SESSION['editing_user']; ?><br>
    Editing User Name: <?php echo $_SESSION['editing_username']; ?><br>
    Editing User Display Name: <?php echo $_SESSION['editing_display_name']; ?><br>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>