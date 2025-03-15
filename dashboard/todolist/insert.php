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
$title = "YourListOnline - Add Objective";

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

// Initialize message variables
$message = ""; 
$messageType = ""; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  // Get form data
  $objective = $_POST['objective'];
  $category = $_POST['category'];
  // Basic validation
  if (empty($objective)) {
    $message = "Please enter a task.";
    $messageType = "is-danger"; 
  } else {
    // Prepare and execute query
    $stmt = $db->prepare("INSERT INTO todos (objective, category, created_at, updated_at, completed) VALUES (?, ?, NOW(), NOW(), 'No')");
    $stmt->bind_param("si", $objective, $category);
    if ($stmt->execute()) {
      $message = "Task added successfully!";
      $messageType = "is-success"; 
    } else {
      $message = "Error adding task. Please try again.";
      $messageType = "is-danger"; 
    }
  }
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
  <?php if ($message): ?>
    <div class="notification <?php echo $messageType; ?>"> 
      <div class="columns is-vcentered">
        <div class="column is-narrow">
          <span class="icon is-large"> 
            <?php if ($messageType === 'is-danger'): ?>
              <i class="fas fa-exclamation-triangle fa-2x"></i>
            <?php elseif ($messageType === 'is-warning'): ?>
              <i class="fas fa-exclamation-circle fa-2x"></i>
            <?php elseif ($messageType === 'is-success'): ?>
              <i class="fas fa-check-circle fa-2x"></i>
            <?php else: ?>
              <i class="fas fa-info-circle fa-2x"></i>
            <?php endif; ?>
          </span>
        </div>
        <div class="column">
          <p><?php echo $message; ?></p> 
        </div>
      </div>
    </div>
  <?php endif; ?>
  <form method="post">
    <h3 class="title is-3">Please enter your task to add it to your list:</h3>
    <div class="field">
      <div class="control">
        <textarea id="objective" name="objective" class="textarea" placeholder="Your task"></textarea>
      </div>
    </div>
    <div class="field">
      <label for="category">Category:</label>
      <div class="control">
        <div class="select">
          <select id="category" name="category">
            <?php
            $stmt = $db->query("SELECT * FROM categories");
            $result = $stmt->fetch_all(MYSQLI_ASSOC);
            foreach ($result as $row) { echo '<option value="'.htmlspecialchars($row['id']).'">'.htmlspecialchars($row['category']).'</option>';
            }
            ?>
          </select>
        </div>
      </div>
    </div>
    <div class="field">
      <div class="control">
        <button type="submit" class="button is-primary">Add</button>
        <a href="dashboard.php" class="button is-light">Cancel</a>
      </div>
    </div>
  </form>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
</body>
</html>