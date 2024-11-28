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
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($rows);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  foreach ($rows as $row) {
    $row_id = $row['id'];
    $new_objective = $_POST['objective'][$row_id];
    // Check if the objective has been updated
    if ($new_objective != $row['objective']) {
      $updateStmt = $db->prepare("UPDATE todos SET objective = ? WHERE id = ?");
      $updateStmt->bind_param('si', $new_objective, $row_id);
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
  <?php if ($num_rows < 1): ?>
    <div class="notification is-info">
      <div class="columns is-vcentered">
        <div class="column is-narrow">
          <span class="icon is-large">
            <i class="fas fa-tasks fa-2x"></i> 
          </span>
        </div>
        <div class="column">
          <p><strong>Your to-do list is empty!</strong></p>
          <p>You can't update any tasks because there aren't any yet.</p> 
        </div>
      </div>
    </div>
  <?php else: ?> 
    <form method="POST">
      <h2 class='subtitle'>Please pick which row to update on your list:</h2>
      <button type="submit" name="submit" class="button is-primary">Update All</button>
      <table class="table is-striped is-fullwidth sortable">
        <thead>
          <tr>
            <th>Objective</th>
            <th>Category</th>
            <th>Update Objective</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['objective']); ?></td>
            <td>
              <?php
                $category_id = $row['category'];
                $category_stmt = $db->prepare("SELECT category FROM categories WHERE id = ?");
                $category_stmt->bind_param('i', $category_id);
                $category_stmt->execute();
                $category_row = $category_stmt->get_result()->fetch_assoc();
                echo htmlspecialchars($category_row['category']);
              ?>
            </td>
            <td>
              <input type="text" name="objective[<?php echo $row['id']; ?>]" class="input" value="<?php echo htmlspecialchars($row['objective']); ?>">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
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