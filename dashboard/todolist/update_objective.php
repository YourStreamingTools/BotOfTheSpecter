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
$title = t('todolist_update_objective_title');
$pageTitle = $title;
ob_start();

// Include necessary files
require_once "/var/www/config/db_connect.php";
include '../userdata.php';
include '../bot_control.php';
include "../mod_access.php";
include '../user_db.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

require_once '/var/www/config/database.php';
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Get user's to-do list
$stmt = $db->prepare("SELECT * FROM todos ORDER BY id DESC");
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($rows);

// Fetch all categories once for the dropdowns
$categories_stmt = $db->prepare("SELECT * FROM categories");
$categories_stmt->execute();
$categories = $categories_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  foreach ($rows as $row) {
    $row_id = $row['id'];
    $new_objective = $_POST['objective'][$row_id];
    $new_category = $_POST['category'][$row_id];
    if ($new_objective != $row['objective'] || $new_category != $row['category']) {
      $updateStmt = $db->prepare("UPDATE todos SET objective = ?, category = ? WHERE id = ?");
      $updateStmt->bind_param('sii', $new_objective, $new_category, $row_id);
      $updateStmt->execute();
    }
  }
  header('Location: update_objective.php');
  exit();
}
?>
<div class="columns is-centered">
  <div class="column">
    <div class="card" style="border-radius: 18px;">
      <header class="card-header">
        <p class="card-header-title is-size-4">
          <span class="icon"><i class="fas fa-edit"></i></span>
          <span class="ml-2">Update Task Objective</span>
        </p>
      </header>
      <div class="card-content" style="padding: 2.5rem;">
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
            <h2 class="title is-4 mb-4">Edit your task objectives and categories below and click "Update All" to save changes:</h2>
            <div class="columns is-multiline">
              <?php foreach ($rows as $row): ?>
                <div class="column is-6-tablet is-4-desktop">
                  <div class="box" style="border-radius: 12px;">
                    <div class="media is-align-items-center">
                      <div class="media-content">
                        <p class="mb-2 is-flex is-align-items-center">
                          <span class="icon is-align-self-center"><i class="fas fa-tasks"></i></span>
                          <span class="ml-1"><strong>Current:</strong> <?= htmlspecialchars($row['objective']) ?></span>
                        </p>
                        <div class="field mb-3">
                          <label class="label is-small" for="objective_<?php echo $row['id']; ?>">Update Objective</label>
                          <div class="control has-icons-left">
                            <input type="text" name="objective[<?php echo $row['id']; ?>]" id="objective_<?php echo $row['id']; ?>" class="input is-rounded" value="<?php echo htmlspecialchars($row['objective']); ?>">
                            <span class="icon is-left"><i class="fas fa-pen"></i></span>
                          </div>
                        </div>
                        <div class="field">
                          <label class="label is-small" for="category_<?php echo $row['id']; ?>">Update Category</label>
                          <div class="control has-icons-left">
                            <div class="select is-fullwidth is-rounded">
                              <select name="category[<?php echo $row['id']; ?>]" id="category_<?php echo $row['id']; ?>">
                                <?php foreach ($categories as $cat): ?>
                                  <option value="<?= $cat['id'] ?>" <?php if ($cat['id'] == $row['category']) echo 'selected'; ?>>
                                    <?= htmlspecialchars($cat['category']) ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <span class="icon is-left"><i class="fas fa-folder"></i></span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="field is-grouped is-grouped-right mt-5">
              <div class="control">
                <button type="submit" name="submit" class="button is-primary is-medium is-rounded px-5">Update All</button>
              </div>
              <div class="control">
                <a href="dashboard.php" class="button is-light is-medium is-rounded px-5">Cancel</a>
              </div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include 'layout_todolist.php';
?>