<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
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
require_once '/var/www/config/database.php';
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) { die('Connection failed: ' . $db->connect_error); }
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Fetch the total number of users in the seen_users table
$totalUsersSTMT = $db->prepare("SELECT COUNT(*) as total_users FROM seen_users");
$totalUsersSTMT->execute();
$totalUsersResult = $totalUsersSTMT->get_result()->fetch_assoc();
$totalUsers = $totalUsersResult['total_users'];
$totalUsersSTMT->close();

// Cache for banned users
$cacheExpiration = 86400; // 24 hours
$loggedInUsername = $_SESSION['username'];
$cacheBaseDir = "/var/www/cache/known_users";
$cacheFile = "$cacheBaseDir/$loggedInUsername.json";
$cacheWarningMessage = null; // Initialize warning message

if (!is_dir($cacheBaseDir)) {
  if (!mkdir($cacheBaseDir, 0755, true) && !is_dir($cacheBaseDir)) {
    $cacheWarningMessage = "Error: Could not create cache directory: $cacheBaseDir. Please check server permissions.";
    error_log($cacheWarningMessage . " User: " . $loggedInUsername);
  }
}
$bannedUsersCache = [];
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
    $cacheContent = file_get_contents($cacheFile);
    if ($cacheContent) {
        $bannedUsersCache = json_decode($cacheContent, true);
    }
} else {
    // Clear the cache if it is expired
    $bannedUsersCache = [];
}

// Handle POST requests for updates
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['username']) && isset($_POST['status'])) {
    $dbusername = $_POST['username'];
    $status = $_POST['status'];
    $updateQuery = $db->prepare("UPDATE seen_users SET status = ? WHERE username = ?");
    $updateQuery->bind_param('ss', $status, $dbusername);
    $updateQuery->execute();
    $updateQuery->close();
  }
  if (isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
    $userId = $_POST['userId'];
    $newWelcomeMessage = $_POST['newWelcomeMessage'];
    $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = ? WHERE id = ?");
    $messageQuery->bind_param('si', $newWelcomeMessage, $userId);
    $messageQuery->execute();
    $messageQuery->close();
    header("Location: known_users.php");
    exit();
  }
  if (isset($_POST['deleteUserId'])) {
    $deleteUserId = $_POST['deleteUserId'];
    $deleteQuery = $db->prepare("DELETE FROM seen_users WHERE id = ?");
    $deleteQuery->bind_param('i', $deleteUserId);
    $deleteQuery->execute();
    $deleteQuery->close();
    header("Location: known_users.php");
    exit();
  }
}

// Start output buffering for layout
ob_start();
?>
<script>
// Mobile device detection
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth < 768;
}

if (isMobileDevice()) {
    document.addEventListener('DOMContentLoaded', function() {
        // Hide all page content
        const pageContent = document.getElementById('knownUsersPageContent');
        if (pageContent) pageContent.style.display = 'none';
        // Create and insert mobile warning message
        const mobileWarning = document.createElement('div');
        mobileWarning.className = 'columns is-centered';
        mobileWarning.innerHTML = `
            <div class="column is-10-tablet is-8-desktop">
                <div class="notification is-warning" style="margin-top: 2rem;">
                    <h1 class="title has-text-centered">Mobile Access Unavailable</h1>
                    <div class="content has-text-centered">
                        <p><strong>We apologize for the inconvenience.</strong></p>
                        <p>The Welcome Messages page is currently unavailable on mobile devices due to the complexity of the table interface.</p>
                        <p>We are actively working to provide a mobile-friendly version in future releases and updates.</p>
                        <p>Please access this page from a desktop or tablet device for the best experience.</p>
                    </div>
                </div>
            </div>
        `;
        // Insert the warning at the beginning of the page
        if (pageContent && pageContent.parentNode) {
            pageContent.parentNode.insertBefore(mobileWarning, pageContent);
        }
    });
}
</script>
<div id="knownUsersPageContent">
  <div id="loadingNoticeBox" class="notification <?php echo $totalUsers > 0 ? 'has-background-warning has-text-warning-dark' : 'has-background-info-light has-text-info-dark'; ?>">
    <p id="loadingNotice">
      <?php 
      if ($totalUsers > 0) {
          echo t('known_users_loading', ['loaded' => 0, 'total' => $totalUsers]);
      } else {
          echo t('known_users_no_users');
      }
      ?>
    </p>
  </div>
  <?php if ($cacheWarningMessage): ?>
  <div class="notification is-danger">
      <?php echo htmlspecialchars($cacheWarningMessage); ?>
  </div>
  <?php endif; ?>
  <div id="content" style="display: <?php echo $totalUsers > 0 ? 'none' : 'block'; ?>;">
    <div class="columns is-centered">
      <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
          <header class="card-header" style="border-bottom: 1px solid #23272f;">
            <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
              <span class="icon mr-2"><i class="fas fa-users"></i></span>
              <?php echo t('known_users_title'); ?>
            </span>
          </header>
          <div class="card-content">
            <div class="notification is-info mb-5">
              <p class="has-text-weight-bold">
                <span class="icon"><i class="fas fa-code"></i></span>
                Custom Variables for Welcome Messages
              </p>
              <p>You can use the following variables in welcome messages:</p>
              <ul>
                <li><span class="has-text-weight-bold">(shoutout)</span>: Automatically sends a shoutout to the user.</li>
              </ul>
            </div>
            <div class="notification is-warning has-text-dark mb-5">
              <p class="has-text-weight-bold">
                <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                Important: How to Use Variables
              </p>
              <p>
                <strong>If you only enter a variable</strong> (like <code>(shoutout)</code>) in the welcome message text area, <strong>the bot will NOT post anything to chat</strong>.
              </p>
              <p class="mt-3">
                To send a welcome message, you must include <strong>text along with the variable</strong>. For example:
              </p>
              <ul class="mt-2">
                <li><strong style="color: #0a6e0a;">✓ Will Send Message:</strong> <code>Welcome back, BotOfTheSpecter! (shoutout)</code></li>
                <li><strong style="color: #0a6e0a;">✓ Will Send Message:</strong> <code>Great to see you again, BotOfTheSpecter! (shoutout)</code></li>
                <li><strong style="color: #c70000;">✗ No Message Sent:</strong> <code>(shoutout)</code> <em>(only the variable, no text)</em></li>
              </ul>
              <p class="mt-3">
                <strong style="color: #d83838;">⚠️ Note:</strong> Entering any welcome message or variable for a user will <strong>override the default welcome message</strong> set in your bot settings.
              </p>
            </div>
            <div class="notification has-background-danger has-text-black has-text-weight-bold"><?php echo t('known_users_edit_notice'); ?></div>
            <!-- Search Bar -->
            <input type="text" id="searchInput" class="input" placeholder="<?php echo t('known_users_search_placeholder'); ?>" onkeyup="searchFunction()">
            <br><br>
            <div class="table-container">
              <table class="table is-fullwidth" id="commandsTable">
                <thead>
                  <tr>
                    <th class="has-text-white"><?php echo t('counters_username_column'); ?></th>
                    <th class="has-text-white"><?php echo t('known_users_welcome_message_column'); ?></th>
                    <th class="has-text-white has-text-centered"><?php echo t('known_users_status_column'); ?></th>
                    <th class="has-text-white has-text-centered"><?php echo t('known_users_action_column'); ?></th>
                    <th class="has-text-white has-text-centered"><?php echo t('known_users_editing_column'); ?></th>
                    <th class="has-text-white has-text-centered">Test</th>
                    <th class="has-text-white has-text-centered"><?php echo t('known_users_removing_column'); ?></th>
                  </tr>
                </thead>
                <tbody id="user-table">
                  <?php foreach ($seenUsersData as $userData): ?>
                    <tr class="is-vcentered has-text-white">
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
                          <textarea class="textarea welcome-message" data-user-id="<?php echo $userData['id']; ?>" maxlength="255"><?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?></textarea>
                          <div class="character-counter" id="counter-<?php echo $userData['id']; ?>" style="font-size: 0.8em; margin-top: 0.25em; text-align: right; color: #ccc;">
                            <span class="current-count"><?php echo strlen($userData['welcome_message'] ?? ''); ?></span>/255 characters
                          </div>
                        </div>
                      </td>
                      <td class="has-text-centered" style="vertical-align: middle;">
                        <span style="color: <?php echo $userData['status'] == 'True' ? 'green' : 'red'; ?>">
                          <?php echo isset($userData['status']) ? t($userData['status'] == 'True' ? 'known_users_status_true' : 'known_users_status_false') : ''; ?>
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
                        <button class="button is-info is-small test-welcome-btn" 
                                data-username="<?php echo htmlspecialchars($userData['username']); ?>" 
                                data-message="<?php echo htmlspecialchars($userData['welcome_message']); ?>"
                                <?php echo $userData['status'] != 'True' ? 'disabled title="User is inactive"' : ''; ?>>
                          <i class="fas fa-paper-plane"></i>
                        </button>
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
          </div>
        </div>
      </div>
    </div>
  </div>
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
  // Load Toastify library dynamically
  function loadToastify() {
    return new Promise(function(resolve) {
      if (window.Toastify) return resolve();
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css';
      document.head.appendChild(css);
      var script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/toastify-js';
      script.onload = function() { resolve(); };
      script.onerror = function() { resolve(); };
      document.body.appendChild(script);
    });
  }
  function showToast(message, success) {
    if (window.Toastify) {
      Toastify({
        text: message,
        duration: 3500,
        close: true,
        gravity: 'top',
        position: 'right',
        style: { background: success ? '#48c774' : '#f14668' }
      }).showToast();
    } else {
      alert(message);
    }
  }
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
      // Show loading state
      const originalIcon = this.innerHTML;
      this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      this.disabled = true;
      updateWelcomeMessage(userId, newWelcomeMessage, this, originalIcon, editBtn, cancelBtn);
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
        title: '<?php echo t('known_users_delete_confirm_title'); ?>',
        text: "<?php echo t('known_users_delete_confirm_text'); ?>",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?php echo t('known_users_delete_confirm_btn'); ?>',
        cancelButtonText: '<?php echo t('cancel'); ?>'
      }).then((result) => {
        if (result.isConfirmed) {
          form.submit();
        }
      });
    });
  });
  // Test welcome message button functionality
  document.querySelectorAll('.test-welcome-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const username = this.getAttribute('data-username');
      const message = this.getAttribute('data-message');
      const button = this;
      // Show loading state
      const originalIcon = button.innerHTML;
      button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      button.disabled = true;
      // Make AJAX request
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'send_welcome_message.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
          button.innerHTML = originalIcon;
          button.disabled = false;
          if (xhr.status === 200) {
            try {
              const response = JSON.parse(xhr.responseText);
              if (response.success) {
                loadToastify().then(function() {
                  showToast('✓ Test Sent: ' + response.message, true);
                });
              } else {
                loadToastify().then(function() {
                  showToast('✗ Error: ' + (response.message || 'Failed to send test message'), false);
                });
              }
            } catch (e) {
              loadToastify().then(function() {
                showToast('✗ Error: Invalid response from server', false);
              });
            }
          } else {
            loadToastify().then(function() {
              showToast('✗ Error: Failed to send test message. Status: ' + xhr.status, false);
            });
          }
        }
      };
      xhr.send('username=' + encodeURIComponent(username) + '&message=' + encodeURIComponent(message));
    });
  });
  // Character counter functionality
  document.querySelectorAll('.welcome-message').forEach(textarea => {
    textarea.addEventListener('input', function() {
      const userId = this.getAttribute('data-user-id');
      const counter = document.getElementById('counter-' + userId);
      const currentCount = this.value.length;
      const currentCountSpan = counter.querySelector('.current-count');
      currentCountSpan.textContent = currentCount;
      // Change color based on character count
      if (currentCount >= 240) {
        counter.style.color = '#ff3860'; // Red for near limit
      } else if (currentCount >= 200) {
        counter.style.color = '#ff9f43'; // Orange for warning
      } else {
        counter.style.color = '#ccc'; // Default gray
      }
    });
  });
  // Fetch the banned status for each user asynchronously
  fetchBannedStatuses();
});

function fetchBannedStatuses() {
  const usernamesElements = document.querySelectorAll('.username');
  const loadingNoticeBox = document.getElementById('loadingNoticeBox');
  const contentElement = document.getElementById('content');
  if (totalUsers === 0) {
    return;
  }
  if (usernamesElements.length === 0 && totalUsers > 0) {
    handleAllUsersProcessed(false);
    return;
  }
  const uncachedUsers = [];
  const cachedUsers = [];
  usernamesElements.forEach(usernameElement => {
    const username = usernameElement.dataset.username;
    if (!(username in bannedUsersCache)) {
      uncachedUsers.push({username, element: usernameElement});
    } else {
      cachedUsers.push({username, element: usernameElement});
    }
  });
  cachedUsers.forEach(({username, element}) => {
    const bannedStatusElement = element.nextElementSibling;
    if (bannedUsersCache[username]) {
      bannedStatusElement.innerHTML = " <em style='color:red'>(<?php echo t('known_users_banned_label'); ?>)</em>";
    } else {
      bannedStatusElement.innerHTML = "";
    }
    loadedUsers++;
    updateLoadingNotice();
  });
  if (uncachedUsers.length === 0) {
    handleAllUsersProcessed(false);
    return;
  }
  const batchSize = 10;
  const batches = [];
  for (let i = 0; i < uncachedUsers.length; i += batchSize) {
    batches.push(uncachedUsers.slice(i, i + batchSize));
  }
  let completedBatches = 0;
  let newCacheEntriesMade = false;
  batches.forEach(batch => {
    fetchBannedStatusBatch(batch, (batchHadNewEntries) => {
      if (batchHadNewEntries) {
        newCacheEntriesMade = true;
      }
      completedBatches++;
      if (completedBatches === batches.length) {
        handleAllUsersProcessed(newCacheEntriesMade);
      }
    });
  });
}

function fetchBannedStatusBatch(userBatch, callback) {
  const usernames = userBatch.map(user => user.username);
  console.log(`Fetching banned status for batch of ${usernames.length} users:`, usernames);
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "fetch_banned_status.php", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      let batchHadNewEntries = false;
      console.log(`Response received for batch of ${usernames.length} users`);
      if (xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          console.log(`Batch response:`, response);
          userBatch.forEach(({username, element}) => {
            const bannedStatusElement = element.nextElementSibling;
            const isBanned = response.bannedUsers && response.bannedUsers[username] === true;
            if (isBanned) {
              bannedStatusElement.innerHTML = " <em style='color:red'>(<?php echo t('known_users_banned_label'); ?>)</em>";
            } else {
              bannedStatusElement.innerHTML = "";
            }
            bannedUsersCache[username] = isBanned;
            batchHadNewEntries = true;
            loadedUsers++;
            updateLoadingNotice();
          });
          if (batchHadNewEntries) {
            const cacheUpdate = {};
            userBatch.forEach(({username}) => {
              cacheUpdate[username] = bannedUsersCache[username];
            });
            updateCacheOnServer(cacheUpdate);
          }
          
        } catch (e) {
          console.error(`Error parsing JSON for batch:`, e, xhr.responseText);
          userBatch.forEach(() => {
            loadedUsers++;
            updateLoadingNotice();
          });
        }
      } else {
        console.log(`Error fetching banned status for batch: ${xhr.status}`);
        userBatch.forEach(() => {
          loadedUsers++;
          updateLoadingNotice();
        });
      }
      if (callback) callback(batchHadNewEntries);
    }
  };
  xhr.send("usernames=" + encodeURIComponent(JSON.stringify(usernames)));
}

function updateCacheOnServer(cacheUpdate) {
  fetch('update_banned_users_cache.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(cacheUpdate)
  }).then(res => {
    if (!res.ok) {
      return res.text().then(text => { throw new Error(`HTTP error! status: ${res.status}, body: ${text}`); });
    }
    return res.json();
  }).then(data => {
    console.log(`Cache updated on server for batch:`, data);
  }).catch(error => {
    console.error(`Error updating cache on server for batch:`, error);
  });
}
function handleAllUsersProcessed(cacheWasModified) {
  const loadingNoticeBox = document.getElementById('loadingNoticeBox');
  const loadingNotice = document.getElementById('loadingNotice');
  const contentElement = document.getElementById('content');
  if (!loadingNoticeBox || !loadingNotice || !contentElement) {
      console.error('Required UI elements for loading notice not found.');
      return;
  }
  loadingNotice.innerText = '<?php echo t('known_users_loading_done'); ?>';
  loadingNoticeBox.classList.remove('has-background-warning', 'has-text-warning-dark');
  loadingNoticeBox.classList.remove('has-background-info-light', 'has-text-info-dark');
  loadingNoticeBox.classList.add('has-background-success-light', 'has-text-success-dark');
  setTimeout(() => {
    loadingNoticeBox.style.display = 'none';
    contentElement.style.display = 'block';
  }, 2000);
}
function updateLoadingNotice() {
  const loadingNotice = document.getElementById('loadingNotice');
  if (loadingNotice) {
    loadingNotice.innerText = '<?php echo t('known_users_loading_js'); ?>'.replace('{loaded}', loadedUsers).replace('{total}', totalUsers);
  }
}
function updateWelcomeMessage(userId, newWelcomeMessage, button, originalIcon, editBtn, cancelBtn) {
  console.log(`Updating welcome message for user ID ${userId} to "${newWelcomeMessage}"`);
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      // Restore button state
      button.innerHTML = originalIcon;
      button.disabled = false;
      // Hide save/cancel, show edit
      button.style.display = 'none';
      if (cancelBtn) cancelBtn.style.display = 'none';
      if (editBtn) editBtn.style.display = '';
      console.log(`Response received for updating welcome message of user ID ${userId}`);
      location.reload();
    }
  };
  xhr.send("userId=" + encodeURIComponent(userId) + "&newWelcomeMessage=" + encodeURIComponent(newWelcomeMessage));
}

function toggleStatus(username, isChecked) {
  console.log(`Toggling status for ${username} to ${isChecked ? 'True' : 'False'}`);
  var status = isChecked ? 'True' : 'False';
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      console.log(`Response received for toggling status of ${username}`);
      console.log(xhr.responseText);
      location.reload();
    }
  };
  xhr.send("username=" + encodeURIComponent(username) + "&status=" + status);
}
</script>
<?php
$scripts = ob_get_clean();

// Use the layout
include 'layout.php';
?>