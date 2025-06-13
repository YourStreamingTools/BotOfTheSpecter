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
$title = t('todolist_remove_objective_title');
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

// Get the selected category filter, default to "all" if not provided
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';

// Build the SQL query based on the category filter
if ($categoryFilter === 'all') {
  $stmt = $db->prepare("SELECT * FROM todos ORDER BY id ASC");
} else {
  $stmt = $db->prepare("SELECT * FROM todos WHERE category = ? ORDER BY id ASC");
  $stmt->bind_param('s', $categoryFilter);
}

$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($result);

// Handle remove item form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $todo_id = $_POST['todo_id'];
  // Delete item from database
  $stmt = $db->prepare("DELETE FROM todos WHERE id = ?");
  $stmt->bind_param('i', $todo_id);
  $stmt->execute();
  // Redirect back to remove page
  header('Location: remove.php');
  exit();
}
?>
<div class="columns is-centered">
  <div class="column">
    <div class="card" style="border-radius: 18px;">
      <header class="card-header">
        <p class="card-header-title is-size-4">
          <span class="icon"><i class="fas fa-trash"></i></span>
          <span class="ml-2">Remove a Task</span>
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
                <p>You can't remove any tasks because there aren't any yet.</p> 
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="mb-5">
            <div class="columns is-vcentered">
              <div class="column is-9">
                <label for="searchInput" class="label mb-1">Search Tasks</label>
                <div class="control has-icons-left">
                  <input type="text" name="search" id="searchInput" placeholder="Search todos" class="input is-rounded" onkeyup="searchFunction()">
                  <span class="icon is-left">
                    <i class="fas fa-search"></i>
                  </span>
                </div>
              </div>
              <div class="column is-3 has-text-right">
                <label for="categoryFilter" class="label mb-1 has-text-left" style="display:block;">Filter by Category</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth is-rounded">
                    <select id="categoryFilter" onchange="applyCategoryFilter()">
                      <option value="all" <?php if ($categoryFilter === 'all') echo 'selected'; ?>>All</option>
                      <?php
                      $categories_sql = "SELECT * FROM categories";
                      $categories_stmt = $db->prepare($categories_sql);
                      $categories_stmt->execute();
                      $categories_result = $categories_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                      foreach ($categories_result as $category_row) {
                        $categoryId = $category_row['id'];
                        $categoryName = htmlspecialchars($category_row['category']);
                        $selected = ($categoryFilter == $categoryId) ? 'selected' : '';
                        echo "<option value=\"$categoryId\" $selected>$categoryName</option>";
                      }
                      ?>
                    </select>
                  </div>
                  <span class="icon is-left">
                    <i class="fas fa-filter"></i>
                  </span>
                </div>
              </div>
            </div>
          </div>
          <h2 class="title is-4 mb-4">Please pick which task to remove from your list:</h2>
          <div class="columns is-multiline" id="taskCardList">
            <?php foreach ($result as $row): ?>
              <div class="column is-6-tablet is-4-desktop">
                <div class="box" style="border-radius: 12px;">
                  <div class="media is-align-items-center">
                    <div class="media-content">
                      <p class="title is-6 mb-1 is-flex is-align-items-center"><?= htmlspecialchars($row['objective']) ?></p>
                      <p class="subtitle is-7 has-text-grey is-flex is-align-items-center">
                        <span class="icon is-align-self-center"><i class="fas fa-folder"></i></span>
                        <span class="ml-1">
                          <?php
                          $category_id = $row['category'];
                          $category_stmt = $db->prepare("SELECT category FROM categories WHERE id = ?");
                          $category_stmt->bind_param('i', $category_id);
                          $category_stmt->execute();
                          $category_row = $category_stmt->get_result()->fetch_assoc();
                          echo htmlspecialchars($category_row['category']);
                          ?>
                        </span>
                        <span class="ml-2">
                          <?= ($row['completed'] === 'Yes') ? '<span class="tag is-success is-light">Completed</span>' : '<span class="tag is-warning is-light">Not completed</span>' ?>
                        </span>
                      </p>
                    </div>
                    <div class="media-right">
                      <form method="POST" style="margin-bottom:0;" class="remove-task-form">
                        <input type="hidden" name="todo_id" value="<?= $row['id'] ?>">
                        <button type="button" class="button is-danger is-rounded is-small remove-task-btn">
                          <span class="icon"><i class="fas fa-trash"></i></span>
                          <span>Remove</span>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
function applyCategoryFilter() {
  var selectedCategoryId = document.getElementById("categoryFilter").value;
  window.location.href = "remove.php?category=" + selectedCategoryId;
}

document.querySelectorAll('.remove-task-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const form = btn.closest('form');
    Swal.fire({
      title: 'Are you sure?',
      text: "This will remove the task.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, remove it!'
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout_todolist.php';
?>