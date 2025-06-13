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
$pageTitle = t('todolist_add_category_title');

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

ob_start();
?>
<div class="columns is-centered">
  <div class="column">
    <div class="card" style="border-radius: 18px;">
      <header class="card-header">
        <p class="card-header-title is-size-4">
          <span class="icon"><i class="fas fa-folder-plus"></i></span>
          <span class="ml-2">Add New Category</span>
        </p>
      </header>
      <div class="card-content" style="padding: 2.5rem;">
        <?php if ($message): ?>
          <div class="notification <?php echo $messageType; ?>">
            <span class="icon is-medium">
              <?php if ($messageType === 'is-danger'): ?>
                <i class="fas fa-exclamation-triangle"></i>
              <?php elseif ($messageType === 'is-warning'): ?>
                <i class="fas fa-exclamation-circle"></i>
              <?php elseif ($messageType === 'is-success'): ?>
                <i class="fas fa-check-circle"></i>
              <?php else: ?>
                <i class="fas fa-info-circle"></i>
              <?php endif; ?>
            </span>
            <span><?php echo $message; ?></span>
          </div>
        <?php endif; ?>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
          <h3 class="title is-4 mb-4">Type in what your new category will be:</h3>
          <div class="field <?php echo (!empty($category_err)) ? 'has-error' : ''; ?>">
            <label class="label" for="category">Category Name</label>
            <div class="control has-icons-left">
              <input type="text" name="category" id="category" class="input is-rounded is-medium" value="<?php echo htmlspecialchars($category); ?>" placeholder="e.g. Work, Personal, Shopping">
              <span class="icon is-left">
                <i class="fas fa-folder"></i>
              </span>
            </div>
            <?php if (!empty($category_err)): ?>
              <p class="help is-danger"><?php echo $category_err; ?></p>
            <?php endif; ?>
          </div>
          <div class="field is-grouped is-grouped-right mt-5">
            <div class="control">
              <input type="submit" class="button is-primary is-medium is-rounded px-5" value="Submit">
            </div>
            <div class="control">
              <a href="categories.php" class="button is-light is-medium is-rounded px-5">Cancel</a>
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