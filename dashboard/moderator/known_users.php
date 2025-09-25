<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: ../login.php');
  exit();
}

// Page Title
$pageTitle = t('known_users_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Fetch the total number of users in the seen_users table (MySQLi)
$totalUsers = 0;
$totalUsersSTMT = $db->query("SELECT COUNT(*) as total_users FROM seen_users");
if ($totalUsersSTMT && $row = $totalUsersSTMT->fetch_assoc()) {
  $totalUsers = $row['total_users'];
}

// Cache for banned users
$cacheUsername = $_SESSION['editing_username'];
$cacheExpiration = 86400; // Cache expires after 24 hours
$cacheDirectory = "cache/$cacheUsername";
$cacheFile = "$cacheDirectory/bannedUsers.json";
$bannedUsersCache = [];

if (!is_dir($cacheDirectory)) { mkdir($cacheDirectory, 0755, true); }
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
  $cacheContent = file_get_contents($cacheFile);
  if ($cacheContent) { $bannedUsersCache = json_decode($cacheContent, true); }
} else {
  // Clear the cache if it is expired
  $bannedUsersCache = [];
}

// Handle POST requests for updates
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_GET['ajax'])) {
  header('Content-Type: application/json');
  if (isset($_POST['username']) && isset($_POST['status'])) {
    $dbusername = $_POST['username'];
    $status = $_POST['status'];
    $updateQuery = $db->prepare("UPDATE seen_users SET status = ? WHERE username = ?");
    $updateQuery->bind_param('ss', $status, $dbusername);
    if ($updateQuery->execute()) {
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'error' => $updateQuery->error]);
    }
    $updateQuery->close();
    exit();
  }

  if (isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
    $userId = $_POST['userId'];
    $newWelcomeMessage = $_POST['newWelcomeMessage'];
    $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = ? WHERE id = ?");
    $messageQuery->bind_param('si', $newWelcomeMessage, $userId);
    if ($messageQuery->execute()) {
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'error' => $messageQuery->error]);
    }
    $messageQuery->close();
    exit();
  }

  if (isset($_POST['deleteUserId'])) {
    $deleteUserId = $_POST['deleteUserId'];
    $deleteQuery = $db->prepare("DELETE FROM seen_users WHERE id = ?");
    $deleteQuery->bind_param('i', $deleteUserId);
    if ($deleteQuery->execute()) {
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'error' => $deleteQuery->error]);
    }
    $deleteQuery->close();
    exit();
  }
}

// Start output buffering for layout
ob_start();
?>
<div id="loadingNoticeBox" class="notification <?php echo $totalUsers > 0 ? 'has-background-warning has-text-warning-dark' : 'has-background-info-light has-text-info-dark'; ?>">
  <p id="loadingNotice">
    <?php 
    if ($totalUsers > 0) {
        echo "Please wait while we load the users and their status... (0/$totalUsers)";
    } else {
        echo "There are no users to display.";
    }
    ?>
  </p>
</div>
<div id="content" style="display: <?php echo $totalUsers > 0 ? 'none' : 'block'; ?>;">
  <h2 class="title is-4">Known Users & Welcome Messages</h2>
  <div class="notification has-background-danger has-text-black has-text-weight-bold">Click the Edit Button within the users table, edit the welcome message in the text box, when done, click the edit button again to save.</div>
  <!-- Search Bar -->
  <input type="text" id="searchInput" class="input" placeholder="Search users..." onkeyup="searchFunction()">
  <br><br>
  <table class="table is-fullwidth" id="commandsTable">
    <thead>
      <tr>
        <th style="width: 50%;">Username</th>
        <th style="width: 50%;">Welcome Message</th>
        <th style="width: 100px;">Status</th>
        <th style="width: 100px;">Action</th>
        <th style="width: 100px;">Editing</th>
        <th style="width: 100px;">Removing</th>
      </tr>
    </thead>
    <tbody id="user-table">
      <?php foreach ($seenUsersData as $userData): ?>
        <tr class="is-vcentered">
          <td>
            <span class="username" data-username="<?php echo htmlspecialchars($userData['username']); ?>">
              <?php echo isset($userData['username']) ? htmlspecialchars($userData['username']) : ''; ?>
            </span>
            <span class="banned-status"></span>
          </td>
          <td>
            <div id="welcome-message-<?php echo $userData['id']; ?>">
              <?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?>
            </div>
            <div class="edit-box" id="edit-box-<?php echo $userData['id']; ?>" style="display: none;">
              <textarea class="textarea welcome-message" data-user-id="<?php echo $userData['id']; ?>"><?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?></textarea>
            </div>
          </td>
          <td class="has-text-centered" style="vertical-align: middle;">
            <span style="color: <?php echo $userData['status'] == 'True' ? 'green' : 'red'; ?>">
              <?php echo isset($userData['status']) ? htmlspecialchars($userData['status']) : ''; ?>
            </span>
          </td>
          <td class="has-text-centered" style="vertical-align: middle;">
            <label class="checkbox" style="cursor:pointer;">
              <input type="checkbox" class="toggle-checkbox" <?php echo $userData['status'] == 'True' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $userData['username']; ?>', this.checked)" style="display:none;">
              <span class="icon is-medium" onclick="this.previousElementSibling.click();">
                <i class="fa-solid <?php echo $userData['status'] == 'True' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
              </span>
            </label>
          </td>
          <td class="has-text-centered" style="vertical-align: middle;">
            <div class="edit-action-group" style="display: flex; flex-direction: column; align-items: center;">
              <button class="button is-primary is-small edit-btn" data-user-id="<?php echo $userData['id']; ?>">
                <i class="fas fa-pencil-alt"></i>
              </button>
              <button class="button is-small is-success save-edit-btn" data-user-id="<?php echo $userData['id']; ?>" style="display:none; margin-top: 0.25em;">
                <span class="icon is-medium">
                  <i class="fas fa-floppy-disk"></i>
                </span>
              </button>
              <button class="button is-small is-danger cancel-edit-btn" data-user-id="<?php echo $userData['id']; ?>" style="display:none; margin-top: 0.25em;">
                <span class="icon is-medium">
                  <i class="fas fa-xmark"></i>
                </span>
              </button>
            </div>
          </td>
          <td class="has-text-centered" style="vertical-align: middle;">
            <form method="POST" style="display:inline;" class="delete-user-form">
              <input type="hidden" name="deleteUserId" value="<?php echo $userData['id']; ?>">
              <button type="button" class="button is-danger is-small delete-user-btn"><i class="fas fa-trash-alt"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
const totalUsers = <?php echo $totalUsers; ?>;
let loadedUsers = 0;
const bannedUsersCache = <?php echo json_encode($bannedUsersCache); ?>;

document.addEventListener('DOMContentLoaded', function() {
  // Editing functionality
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const userId = this.getAttribute('data-user-id');
      const editBox = document.getElementById('edit-box-' + userId);
      const welcomeMessage = document.getElementById('welcome-message-' + userId);
      const editActionGroup = this.parentElement;
      const saveBtn = editActionGroup.querySelector('.save-edit-btn');
      const cancelBtn = editActionGroup.querySelector('.cancel-edit-btn');
      // Switch to editing mode
      editBox.style.display = 'block';
      welcomeMessage.style.display = 'none';
      this.style.display = 'none';
      if (saveBtn) saveBtn.style.display = '';
      if (cancelBtn) cancelBtn.style.display = '';
    });
  });

  // Save edit functionality
  document.querySelectorAll('.save-edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const userId = this.getAttribute('data-user-id');
      const editBox = document.getElementById('edit-box-' + userId);
      const newWelcomeMessage = editBox.querySelector('.welcome-message').value;
      const editActionGroup = this.parentElement;
      const editBtn = editActionGroup.querySelector('.edit-btn');
      const cancelBtn = editActionGroup.querySelector('.cancel-edit-btn');
      // Hide save/cancel, show edit
      this.style.display = 'none';
      if (cancelBtn) cancelBtn.style.display = 'none';
      if (editBtn) editBtn.style.display = '';
      updateWelcomeMessage(userId, newWelcomeMessage, editBtn);
    });
  });

  // Cancel edit functionality
  document.querySelectorAll('.cancel-edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const userId = this.getAttribute('data-user-id');
      const editActionGroup = this.parentElement;
      const editBtn = editActionGroup.querySelector('.edit-btn');
      const saveBtn = editActionGroup.querySelector('.save-edit-btn');
      const editBox = document.getElementById('edit-box-' + userId);
      const welcomeMessage = document.getElementById('welcome-message-' + userId);
      // Revert UI to non-editing state
      editBox.style.display = 'none';
      welcomeMessage.style.display = '';
      if (editBtn) editBtn.style.display = '';
      if (saveBtn) saveBtn.style.display = 'none';
      this.style.display = 'none';
    });
  });
  // SweetAlert2 for delete confirmation
  document.querySelectorAll('.delete-user-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      const form = this.closest('form');
      Swal.fire({
        title: 'Are you sure?',
        text: "This user will be removed. This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete user'
      }).then((result) => {
        if (result.isConfirmed) {
          const formData = new FormData(form);
          const xhr = new XMLHttpRequest();
          xhr.open("POST", "?ajax=1", true);
          xhr.onreadystatechange = function() {
            if (xhr.readyState === XMLHttpRequest.DONE) {
              if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                  location.reload();
                } else {
                  console.log('Error deleting user:', response.error);
                  alert('Error deleting user: ' + response.error);
                }
              } else {
                console.log('HTTP Error:', xhr.status);
              }
            }
          };
          xhr.send(formData);
        }
      });
    });
  });
  // Fetch the banned status for each user asynchronously
  fetchBannedStatuses();
});

function fetchBannedStatuses() {
  const usernameElements = document.querySelectorAll('.username');
  const usernames = Array.from(usernameElements).map(el => el.dataset.username);
  const batchSize = 10;
  let index = 0;

  function sendBatch() {
    const batch = usernames.slice(index, index + batchSize);
    if (batch.length === 0) {
      // All batches sent, show success
      const loadingNoticeBox = document.getElementById('loadingNoticeBox');
      const loadingNotice = document.getElementById('loadingNotice');
      loadingNotice.innerText = 'Loading completed, you can start editing';
      loadingNoticeBox.classList.remove('has-background-warning', 'has-text-warning-dark');
      loadingNoticeBox.classList.add('has-background-success-light', 'has-text-success-dark');
      setTimeout(() => {
        loadingNoticeBox.style.display = 'none';
        document.getElementById('content').style.display = 'block';
      }, 2000);
      return;
    }

    const xhr = new XMLHttpRequest();
    xhr.open("POST", "fetch_banned_status.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
      if (xhr.readyState === XMLHttpRequest.DONE) {
        if (xhr.status === 200) {
          const response = JSON.parse(xhr.responseText);
          batch.forEach(username => {
            const usernameElement = document.querySelector(`.username[data-username="${username}"]`);
            if (usernameElement) {
              const banned = response[username];
              const bannedStatusElement = usernameElement.nextElementSibling;
              if (banned) {
                bannedStatusElement.innerHTML = " <em style='color:red'>(banned)</em>";
              }
              // Update cache
              bannedUsersCache[username] = banned;
              loadedUsers++;
              updateLoadingNotice();
            }
          });
          // Update cache on server
          fetch('update_banned_users_cache.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(bannedUsersCache)
          }).then(res => res.json()).then(data => {
            console.log('Cache updated', data);
          }).catch(error => {
            console.error('Error updating cache', error);
          });
          // Send next batch
          index += batchSize;
          sendBatch();
        } else {
          console.log(`Error fetching banned statuses: ${xhr.status}`);
        }
      }
    };
    const data = 'usernames=' + encodeURIComponent(JSON.stringify(batch));
    xhr.send(data);
  }

  sendBatch();
}function updateLoadingNotice() {
  const loadingNotice = document.getElementById('loadingNotice');
  loadingNotice.innerText = `Please wait while we load the users and their status... (${loadedUsers}/${totalUsers})`;
}

function updateWelcomeMessage(userId, newWelcomeMessage, button) {
  console.log(`Updating welcome message for user ID ${userId} to "${newWelcomeMessage}"`);
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "?ajax=1", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          location.reload();
        } else {
          console.log('Error updating welcome message:', response.error);
          alert('Error updating welcome message: ' + response.error);
        }
      } else {
        console.log('HTTP Error:', xhr.status);
      }
    }
  };
  xhr.send("userId=" + encodeURIComponent(userId) + "&newWelcomeMessage=" + encodeURIComponent(newWelcomeMessage));
}

function toggleStatus(username, isChecked) {
  console.log(`Toggling status for ${username} to ${isChecked ? 'True' : 'False'}`);
  var status = isChecked ? 'True' : 'False';
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "?ajax=1", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          location.reload();
        } else {
          console.log('Error updating status:', response.error);
          alert('Error updating status: ' + response.error);
        }
      } else {
        console.log('HTTP Error:', xhr.status);
      }
    }
  };
  xhr.send("username=" + encodeURIComponent(username) + "&status=" + status);
}
</script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>
<script src="/js/search.js"></script>
<?php
$scripts = ob_get_clean();
include 'mod_layout.php';
?>