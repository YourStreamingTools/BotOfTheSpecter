<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Known Users";

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$betaStatusOutput = 'Bot Status: Unknown';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
include 'bot_control.php';
include 'sqlite.php';

// Fetch the total number of users in the seen_users table
$totalUsersSTMT = $db->prepare("SELECT COUNT(*) as total_users FROM seen_users");
$totalUsersSTMT->execute();
$totalUsersResult = $totalUsersSTMT->fetch(PDO::FETCH_ASSOC);
$totalUsers = $totalUsersResult['total_users'];

// Cache for banned users
$cacheUsername = $_SESSION['username'];
$cacheExpiration = 600; // Cache expires after 10 minutes
$cacheDirectory = "cache/$cacheUsername";
$cacheFile = "$cacheDirectory/bannedUsers.json";

if (!is_dir($cacheDirectory)) {
    mkdir($cacheDirectory, 0755, true);
}
$bannedUsersCache = [];
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
    $cacheContent = file_get_contents($cacheFile);
    if ($cacheContent) {
        $bannedUsersCache = json_decode($cacheContent, true);
    }
}

// Handle POST requests for updates
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['username']) && isset($_POST['status'])) {
    $dbusername = $_POST['username'];
    $status = $_POST['status'];
    $updateQuery = $db->prepare("UPDATE seen_users SET status = :status WHERE username = :username");
    $updateQuery->bindParam(':status', $status);
    $updateQuery->bindParam(':username', $dbusername);
    $updateQuery->execute();
  }

  if (isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
    $userId = $_POST['userId'];
    $newWelcomeMessage = $_POST['newWelcomeMessage'];
    $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = :welcome_message WHERE id = :user_id");
    $messageQuery->bindParam(':welcome_message', $newWelcomeMessage);
    $messageQuery->bindParam(':user_id', $userId);
    $messageQuery->execute();
    header("Location: known_users.php");
    exit();
  }

  if (isset($_POST['deleteUserId'])) {
    $deleteUserId = $_POST['deleteUserId'];
    $deleteQuery = $db->prepare("DELETE FROM seen_users WHERE id = :user_id");
    $deleteQuery->bindParam(':user_id', $deleteUserId);
    $deleteQuery->execute();
    header("Location: known_users.php");
    exit();
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
  <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <div id="loadingNoticeBox" class="notification is-warning">
    <p id="loadingNotice">Please wait while we load the users and their status... (0/<?php echo $totalUsers; ?>)</p>
  </div>
  <div id="content" style="display: none;">
    <h2 class="title is-4">Known Users & Welcome Messages</h2>
    <p class="has-text-danger">Click the Edit Button within the users table, edit the welcome message in the text box, when done, click the edit button again to save.</p>
    
    <!-- Search Bar -->
    <input type="text" id="searchInput" class="input" placeholder="Search users..." onkeyup="searchFunction()">
    <br><br>
    
    <table class="table is-fullwidth" id="commandsTable">
      <thead>
        <tr>
          <th>Username</th>
          <th>Welcome Message</th>
          <th>Status</th>
          <th>Action</th>
          <th>Edit</th>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody id="user-table">
        <?php foreach ($seenUsersData as $userData): ?>
          <tr>
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
            <td>
              <span style="color: <?php echo $userData['status'] == 'True' ? 'green' : 'red'; ?>">
                <?php echo isset($userData['status']) ? htmlspecialchars($userData['status']) : ''; ?>
              </span>
            </td>
            <td>
              <label class="checkbox">
                <input type="checkbox" class="toggle-checkbox" <?php echo $userData['status'] == 'True' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $userData['username']; ?>', this.checked)">
                <i class="fa-solid <?php echo $userData['status'] == 'True' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
              </label>
            </td>
            <td>
              <button class="button is-small is-primary edit-btn" data-user-id="<?php echo $userData['id']; ?>"><i class="fas fa-pencil-alt"></i></button>
            </td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="deleteUserId" value="<?php echo $userData['id']; ?>">
                <button type="submit" class="button is-small is-danger"><i class="fas fa-trash-alt"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <br><br><br><br>
</div>

<script>
const totalUsers = <?php echo $totalUsers; ?>;
let loadedUsers = 0;

document.addEventListener('DOMContentLoaded', function() {
  // Initialize the editing functionality
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const userId = this.getAttribute('data-user-id');
      const editBox = document.getElementById('edit-box-' + userId);
      const welcomeMessage = document.getElementById('welcome-message-' + userId);
      if (editBox.style.display === 'none') {
        console.log(`Editing welcome message for user ID ${userId}`);
        editBox.style.display = 'block';
        welcomeMessage.style.display = 'none';
        this.classList.add('is-warning');
      } else {
        const newWelcomeMessage = editBox.querySelector('.welcome-message').value;
        updateWelcomeMessage(userId, newWelcomeMessage, this);
      }
    });
  });
  // Fetch the banned status for each user asynchronously
  fetchBannedStatuses();
});

function fetchBannedStatuses() {
  const usernames = document.querySelectorAll('.username');
  let remainingRequests = usernames.length;
  usernames.forEach(usernameElement => {
    const username = usernameElement.dataset.username;
    fetchBannedStatus(username, usernameElement, () => {
      remainingRequests--;
      loadedUsers++;
      updateLoadingNotice();
      if (remainingRequests === 0) {
        const loadingNoticeBox = document.getElementById('loadingNoticeBox');
        const loadingNotice = document.getElementById('loadingNotice');
        loadingNotice.innerText = 'Loading completed, you can start editing';
        loadingNoticeBox.classList.remove('is-warning');
        loadingNoticeBox.classList.add('is-success');
        setTimeout(() => {
          loadingNoticeBox.style.display = 'none';
          document.getElementById('content').style.display = 'block';
        }, 2000); // Show the success message for 2 seconds before hiding it
      }
    });
  });
}

function updateLoadingNotice() {
  const loadingNotice = document.getElementById('loadingNotice');
  loadingNotice.innerText = `Please wait while we load the users and their status... (${loadedUsers}/${totalUsers})`;
}

function fetchBannedStatus(username, usernameElement, callback) {
  console.log(`Fetching banned status for ${username}`);
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "fetch_banned_status.php", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      console.log(`Response received for banned status of ${username}`);
      if (xhr.status === 200) {
        console.log(`XHR response: ${xhr.responseText}`);
        const response = JSON.parse(xhr.responseText);
        const bannedStatusElement = usernameElement.nextElementSibling;
        if (response.banned) {
          console.log(`${username} is banned`);
          bannedStatusElement.innerHTML = " <em style='color:red'>(banned)</em>";
        } else {
          console.log(`${username} is not banned`);
        }
        // Update the cache
        const bannedUsersCache = <?php echo json_encode($bannedUsersCache); ?>;
        bannedUsersCache[username] = response.banned;
        if (Object.keys(bannedUsersCache).length > 0) {
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
        } else {
            console.error('Error: Cache update attempt with empty data.');
        }
      } else {
        console.log(`Error fetching banned status for ${username}: ${xhr.status}`);
      }
      callback();
    }
  };
  xhr.send("usernameToCheck=" + encodeURIComponent(username));
}

function updateWelcomeMessage(userId, newWelcomeMessage, button) {
  console.log(`Updating welcome message for user ID ${userId} to "${newWelcomeMessage}"`);
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>
<script src="/js/search.js"></script>
</body>
</html>