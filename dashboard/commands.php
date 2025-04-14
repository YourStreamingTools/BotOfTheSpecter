<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Custom Commands";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Check if the update request is sent via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['command']) && isset($_POST['status'])) {
    // Process the update here
    $dbcommand = $_POST['command'];
    $status = $_POST['status'];
    // Update the status in the database
    $updateQuery = $db->prepare("UPDATE custom_commands SET status = :status WHERE command = :command");
    $updateQuery->bindParam(':status', $status);
    $updateQuery->bindParam(':command', $dbcommand);
    $updateQuery->execute();
  }
  if (isset($_POST['remove_command'])) {
    $commandToRemove = $_POST['remove_command'];
    // Prepare a delete statement
    $deleteStmt = $db->prepare("DELETE FROM custom_commands WHERE command = ?");
    $deleteStmt->bindParam(1, $commandToRemove, PDO::PARAM_STR);
    // Execute the delete statement
    try {
        $deleteStmt->execute();
        // Success message 
        $status = "Command removed successfully";
        // Reload the page after 1 seconds
        header("Refresh: 1; url={$_SERVER['PHP_SELF']}");
        exit();
    } catch (PDOException $e) {
        // Handle potential errors here
        $status = "Error removing command: " . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
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
  <div class="columns">
    <div class="column">
      <h4 class="title is-4">Bot Commands</h4>
      <?php if (empty($commands)): ?>
        <p>No commands found.</p>
      <?php else: ?>
        <div class="field">
          <div class="control">
            <input class="input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search for commands...">
          </div>
        </div>
        <table class="table is-striped is-fullwidth" id="commandsTable">
          <thead>
            <tr>
              <th style="width: 200px;" class="has-text-centered">Command</th>
              <th class="has-text-centered">Response</th>
              <th style="width: 100px;" class="has-text-centered">Cooldown</th>
              <th style="width: 100px;" class="has-text-centered">Status</th>
              <th style="width: 100px;" class="has-text-centered">Action</th>
              <th style="width: 100px;" class="has-text-centered">Remove</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($commands as $command): ?>
              <tr>
                <td style="vertical-align: middle;">!<?php echo $command['command']; ?></td>
                <td style="vertical-align: middle;"><?php echo $command['response']; ?></td>
                <td class="has-text-centered" style="vertical-align: middle;"><?php echo $command['cooldown']; ?>s</td>
                <td class="has-text-centered" style="vertical-align: middle; color: <?php echo ($command['status'] == 'Enabled') ? 'green' : 'red'; ?>;">
                  <?php echo $command['status']; ?>
                </td>
                <td class="has-text-centered" style="vertical-align: middle;">
                  <label class="checkbox">
                    <input type="checkbox" class="toggle-checkbox" <?php echo $command['status'] == 'Enabled' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $command['command']; ?>', this.checked, this)">
                    <span class="icon is-small">
                      <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                    </span>
                  </label>
                </td>
                <td class="has-text-centered" style="vertical-align: middle;">
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="remove_command" value="<?php echo $command['command']; ?>">
                    <button type="submit" class="button is-small is-danger"><i class="fas fa-trash-alt"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function toggleStatus(command, isChecked, elem) {
  // Update the icon to show a spinner so the user knows something is happening
  var icon = elem.parentElement.querySelector('i');
  icon.className = "fa-solid fa-spinner fa-spin";
  var status = isChecked ? 'Enabled' : 'Disabled';
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      // Reload the page after the AJAX request is completed
      location.reload();
    }
  };
  xhr.send("command=" + encodeURIComponent(command) + "&status=" + status);
}

// Remember previously entered search term
document.addEventListener("DOMContentLoaded", function() {
  var searchInput = document.getElementById("searchInput");
  if (searchInput) {
    // Set saved search value if exists
    searchInput.value = localStorage.getItem("searchTerm") || "";
    // Listen for changes to remember the search term and trigger a search
    searchInput.addEventListener("input", function() {
      localStorage.setItem("searchTerm", this.value);
      searchFunction();
    });
  }
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/search.js"></script>
</body>
</html>