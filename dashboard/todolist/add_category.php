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
$title = "YourListOnline - Add Category";

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
    $stmt = $db->prepare($sql);
    $stmt->bindParam(1, $param_category, PDO::PARAM_STR);
    $param_category = trim($_POST["category"]);
    // Attempt to execute the prepared statement
    $stmt->execute();
    if ($stmt->rowCount() == 1) {
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
    $stmt->bindParam(1, $param_category, PDO::PARAM_STR);
    // Attempt to execute the prepared statement
    if ($stmt->execute()) {
      // Redirect to categories page
      header("location: categories.php");
      exit();
    } else {
      echo "Oops! Something went wrong. Please try again later.";
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
<script src="https://yourlistonline.yourcdnonline.com/js/about.js"></script>
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