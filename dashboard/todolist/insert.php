<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$pageTitle = t('todolist_add_objective_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
include '../bot_control.php';
include "../mod_access.php";
include '../user_db.php';
include '../storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

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

ob_start();
?>
<div class="columns is-centered">
  <div class="column">
    <div class="card" style="border-radius: 18px;">
      <header class="card-header">
        <p class="card-header-title is-size-4">
          <span class="icon"><i class="fas fa-plus"></i></span>
          <span class="ml-2">Add a New Task</span>
        </p>
      </header>
      <div class="card-content" style="padding: 2.5rem;">
        <?php if ($message): ?>
          <div class="notification <?php echo $messageType; ?> is-light mb-4">
            <span class="icon is-medium">
              <?php if ($messageType === 'is-danger'): ?>
                <i class="fas fa-exclamation-triangle"></i>
              <?php elseif ($messageType === 'is-success'): ?>
                <i class="fas fa-check-circle"></i>
              <?php else: ?>
                <i class="fas fa-info-circle"></i>
              <?php endif; ?>
            </span>
            <span><?php echo $message; ?></span>
          </div>
        <?php endif; ?>
        <form method="post">
          <div class="field mb-5">
            <label class="label" for="objective">
              <span class="icon is-left"><i class="fas fa-tasks"></i></span>
              Task
            </label>
            <div class="control has-icons-left">
              <textarea id="objective" name="objective" class="textarea is-medium is-rounded" placeholder="Describe your task..."></textarea>
            </div>
          </div>
          <div class="field mb-5">
            <label class="label" for="category">Category</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth is-rounded">
                <select id="category" name="category">
                  <?php
                  $stmt = $db->query("SELECT * FROM categories");
                  $result = $stmt->fetch_all(MYSQLI_ASSOC);
                  foreach ($result as $row) {
                    echo '<option value="'.htmlspecialchars($row['id']).'">'.htmlspecialchars($row['category']).'</option>';
                  }
                  ?>
                </select>
              </div>
              <span class="icon is-left">
                <i class="fas fa-folder"></i>
              </span>
            </div>
          </div>
          <div class="field is-grouped is-grouped-right mt-6">
            <div class="control">
              <button type="submit" class="button is-primary is-medium is-rounded px-5">Add</button>
            </div>
            <div class="control">
              <a href="dashboard.php" class="button is-light is-medium is-rounded px-5">Cancel</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include 'layout_todolist.php';
?>