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
$title = "YourListOnline - Update Objective";

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
    $new_objective = $_POST['objective'][$row_id];
    // Check if the objective has been updated
    if ($new_objective != $row['objective']) {
      $updateStmt = $db->prepare("UPDATE todos SET objective = :objective WHERE id = :id");
      $updateStmt->bindParam(':objective', $new_objective, PDO::PARAM_STR);
      $updateStmt->bindParam(':id', $row_id, PDO::PARAM_INT);
      $updateStmt->execute();
    }
  }
  header('Location: update_objective.php');
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
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <br>
  <h1 class="title"><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
  <br>
  <form method="POST">
    <?php if ($num_rows < 1) { echo '<h3 class="has-text-danger">There are no rows to edit</h3>'; } else { echo "<h2 class='subtitle'>Please pick which row to update on your list:</h2>"; ?>
    <?php if ($num_rows > 0) { echo '<button type="submit" name="submit" class="button is-primary">Update All</button>'; } ?>
    <table class="table is-striped is-fullwidth sortable">
      <thead>
        <tr>
          <th>Objective</th>
          <th>Category</th>
          <th>Update Objective</th>
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
            <input type="text" name="objective[<?php echo $row['id']; ?>]" class="input" value="<?php echo htmlspecialchars($row['objective']); ?>">
          </td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
    <?php } ?>
  </form>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/about.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/sorttable.js"></script>
<script>
  // JavaScript function to handle the category filter change
  function applyCategoryFilter() {
    var selectedCategoryId = document.getElementById("categoryFilter").value;
    // Redirect to the page with the selected category filter
    window.location.href = "update_objective.php?category=" + selectedCategoryId;
  }
</script>
</body>
</html>