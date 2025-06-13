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
$pageTitle = t('todolist_completed_title');

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

// Check if a specific category is selected
if (isset($_GET['category'])) {
  $category_id = $_GET['category'];
  $sql = "SELECT * FROM todos WHERE category = ? AND completed = 'No'";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $category_id);
} else {
  $sql = "SELECT * FROM todos WHERE completed = 'No'";
  $stmt = $db->prepare($sql);
}

$stmt->execute();
$incompleteTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($incompleteTasks);

// Mark task as completed
if (isset($_POST['task_id'])) {
  $task_id = $_POST['task_id'];
  $sql = "UPDATE todos SET completed = 'Yes' WHERE id = ?";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("i", $task_id);
  $stmt->execute();
  
  header('Location: completed.php');
  exit();
}

// Retrieve categories for the filter dropdown
$categorySql = "SELECT * FROM categories";
$categoryStmt = $db->query($categorySql);
$categories = $categoryStmt->fetch_all(MYSQLI_ASSOC);

// Check if a specific category is selected
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';

ob_start();
?>
<div class="columns is-centered">
  <div class="column">
    <div class="card" style="border-radius: 18px;">
      <header class="card-header">
        <p class="card-header-title is-size-4">
          <span class="icon"><i class="fas fa-check-double"></i></span>
          <span class="ml-2">Mark Tasks as Completed</span>
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
                <p>Start adding tasks to get organized.</p>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="mb-5">
            <div class="columns is-vcentered">
              <div class="column is-9">
                <label for="searchInput" class="label mb-1">Search Tasks</label>
                <div class="control has-icons-left">
                  <input class="input is-rounded" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search objectives">
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
                      <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" <?php if ($categoryFilter == $category['id']) echo 'selected'; ?>>
                          <?php echo htmlspecialchars($category['category']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <span class="icon is-left">
                    <i class="fas fa-filter"></i>
                  </span>
                </div>
              </div>
            </div>
          </div>
          <h4 class="mb-4">Number of total tasks in the category: <?php echo $num_rows; ?></h4>
          <div class="columns is-multiline" id="taskCardList">
            <?php foreach ($incompleteTasks as $row): ?>
              <div class="column is-6-tablet is-4-desktop">
                <div class="box" style="border-radius: 12px;">
                  <div class="media is-align-items-center">
                    <div class="media-content">
                      <p class="title is-6 mb-1 is-flex is-align-items-center"><?php echo htmlspecialchars($row['objective']); ?></p>
                      <p class="subtitle is-7 has-text-grey is-flex is-align-items-center">
                        <span class="icon is-align-self-center"><i class="fas fa-folder"></i></span>
                        <span class="ml-1">
                          <?php
                            $category_id = $row['category'];
                            $category_sql = "SELECT category FROM categories WHERE id = ?";
                            $category_stmt = $db->prepare($category_sql);
                            $category_stmt->bind_param("i", $category_id);
                            $category_stmt->execute();
                            $category_row = $category_stmt->get_result()->fetch_assoc();
                            echo htmlspecialchars($category_row['category']);
                          ?>
                        </span>
                      </p>
                    </div>
                    <div class="media-right">
                      <form method="post" action="completed.php" style="margin-bottom:0;" class="mark-completed-form">
                        <input type="hidden" name="task_id" value="<?php echo $row['id']; ?>">
                        <button type="button" class="button is-success is-rounded is-small mark-completed-btn">
                          <span class="icon"><i class="fas fa-check"></i></span>
                          <span>Mark as completed</span>
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
  document.getElementById("categoryFilter").addEventListener("change", function() {
    var selectedCategoryId = this.value;
    window.location.href = "completed.php?category=" + selectedCategoryId;
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.mark-completed-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const form = btn.closest('form');
    Swal.fire({
      title: 'Mark as completed?',
      text: "This will mark the task as completed.",
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#38c172',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, mark completed!'
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