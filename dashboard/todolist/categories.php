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
$title = "YourListOnline - View Categories";

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

// Get categories from the secondary database
$query = "SELECT * FROM categories";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!$result) {
  die("Error retrieving categories: " . $db->error);
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
  <div class="notification is-info">
    <div class="columns is-vcentered">
      <div class="column is-narrow">
        <span class="icon is-large">
          <i class="fas fa-list-ul fa-2x"></i> 
        </span>
      </div>
      <div class="column">
        <p><strong>Manage Your Categories</strong></p>
        <p>Here's the list of categories you've created. Each category helps you organize your tasks into separate lists.</p> 
      </div>
    </div>
  </div>
  <table class="table is-striped is-fullwidth sortable">
    <thead>
      <tr>
        <th width="50">ID</th>
        <th width="100%">Category</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($result as $row): ?>
      <tr>
        <td><?php echo htmlspecialchars($row['id']); ?></td>
        <td><?php echo htmlspecialchars($row['category']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
<script src="https://yourlistonline.yourcdnonline.com/js/sorttable.js"></script>
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