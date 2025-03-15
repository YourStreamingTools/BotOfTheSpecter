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
$title = "YourListOnline - Add Category";

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

// Initialize variables
$category = "";
$category_err = "";
$message = "";
$messageType = "";

// Process form submission when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Validate category
  if (empty(trim($_POST["category"]))) {
    $category_err = "Please enter a category name.";
  } else {
    // Prepare a select statement
    $sql = "SELECT id FROM categories WHERE category = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $param_category);
    $param_category = trim($_POST["category"]);
    // Attempt to execute the prepared statement
    $stmt->execute();
    $stmt->store_result(); // To check row count
    if ($stmt->num_rows == 1) {
      $category_err = "This category name already exists.";
    } else {
      $category = trim($_POST["category"]);
    }
  }
  // Check input errors before inserting into the database
  if (empty($category_err)) {
    // Prepare an insert statement
    $sql = "INSERT INTO categories (category) VALUES (?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $category); // Using the final category value
    // Attempt to execute the prepared statement
    if ($stmt->execute()) {
      // Redirect to categories page
      $message = "Category added successfully!";
      $messageType = "is-success";
    } else {
      $message = "Oops! Something went wrong. Please try again later.";
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
  <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
    <h3 class="title is-3">Type in what your new category will be:</h3>
    <div class="field <?php echo (!empty($category_err)) ? 'has-error' : ''; ?>">
      <div class="control">
        <input type="text" name="category" class="input" value="<?php echo htmlspecialchars($category); ?>">
      </div>
      <p class="help is-danger"><?php echo $category_err; ?></p> 
    </div>
    <div class="field">
      <div class="control">
        <input type="submit" class="button is-primary" value="Submit">
        <a href="categories.php" class="button is-light">Cancel</a>
      </div>
    </div>
  </form>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
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