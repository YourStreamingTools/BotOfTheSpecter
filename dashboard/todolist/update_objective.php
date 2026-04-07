<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
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
    $new_private = isset($_POST['private'][$row_id]) ? 1 : 0;
    if ($new_objective != $row['objective'] || $new_category != $row['category'] || $new_private != $row['private']) {
      $updateStmt = $db->prepare("UPDATE todos SET objective = ?, category = ?, private = ? WHERE id = ?");
      $updateStmt->bind_param('siii', $new_objective, $new_category, $new_private, $row_id);
      $updateStmt->execute();
    }
  }
  header('Location: update_objective.php');
  exit();
}
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-edit"></i> Update Task Objective</div>
  </div>
  <div class="sp-card-body">
    <?php if ($num_rows < 1): ?>
      <div class="sp-alert sp-alert-info" style="display:flex; align-items:center; gap:0.75rem;">
        <i class="fas fa-tasks fa-2x" style="color:var(--blue); flex-shrink:0;"></i>
        <div>
          <strong>Your to-do list is empty!</strong>
          <p style="margin-bottom:0;">You can't update any tasks because there aren't any yet.</p>
        </div>
      </div>
    <?php else: ?>
      <form method="POST">
        <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Edit your task objectives and categories below and click "Update All" to save changes:</h2>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem;">
          <?php foreach ($rows as $row): ?>
            <div class="sp-card" style="margin-bottom:0;">
              <div class="sp-card-body" style="display:flex; flex-direction:column; gap:0.75rem; height:100%;">
                <div>
                  <p style="font-weight:600; margin-bottom:0.2rem;"><?= htmlspecialchars($row['objective']) ?></p>
                  <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0; display:flex; align-items:center; gap:0.3rem;">
                    <i class="fas fa-folder"></i>
                    <?php
                      $catName = 'Uncategorized';
                      foreach ($categories as $cat) {
                        if ($cat['id'] == $row['category']) { $catName = $cat['category']; break; }
                      }
                      echo htmlspecialchars($catName);
                    ?>
                  </p>
                </div>
                <div class="sp-form-group" style="margin-bottom:0;">
                  <label class="sp-label" for="objective_<?php echo $row['id']; ?>">Objective</label>
                  <input type="text" name="objective[<?php echo $row['id']; ?>]" id="objective_<?php echo $row['id']; ?>" class="sp-input" value="<?php echo htmlspecialchars($row['objective']); ?>">
                </div>
                <div class="sp-form-group" style="margin-bottom:0;">
                  <label class="sp-label" for="category_<?php echo $row['id']; ?>">Category</label>
                  <select name="category[<?php echo $row['id']; ?>]" id="category_<?php echo $row['id']; ?>" class="sp-select">
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?= $cat['id'] ?>" <?php if ($cat['id'] == $row['category']) echo 'selected'; ?>>
                        <?= htmlspecialchars($cat['category']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div style="margin-top:auto;">
                  <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.85rem; color:var(--text-secondary);">
                    <input type="checkbox" name="private[<?php echo $row['id']; ?>]" value="1" <?php if (!empty($row['private']) && $row['private'] == 1) echo 'checked'; ?>>
                    <i class="fas fa-eye-slash"></i> Private (hide from OBS overlay)
                  </label>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem;">
          <button type="submit" name="submit" class="sp-btn sp-btn-primary">Update All</button>
          <a href="index.php" class="sp-btn sp-btn-secondary">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>