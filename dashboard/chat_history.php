<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Chat History";

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
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$pid = '';
include 'bot_control.php';
include 'sqlite.php';
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
  <h2 class="title is-2">Chat History</h2>
  <div class="tabs is-boxed" id="chatTabs">
    <ul>
      <?php
      // Fetch chat history dates
      $dateFolder = "/var/www/logs/chat_history/$username/";
      $dates = scandir($dateFolder);

      // Display chat history dates as tabs
      foreach ($dates as $date) {
        if ($date !== '.' && $date !== '..') {
          $dateWithoutExtension = pathinfo($date, PATHINFO_FILENAME);
          echo "<li class='tab-item'><a href='?date=$dateWithoutExtension'>$dateWithoutExtension</a></li>";
        }
      }
      ?>
    </ul>
  </div>
  <div class="content">
    <?php
    // Display chat history content for the selected date
    if (isset($_GET['date'])) {
      $selectedDate = $_GET['date'];
      $filename = "/var/www/logs/chat_history/$username/$selectedDate.txt";
      if (file_exists($filename)) {
        $chatHistory = file_get_contents($filename);
        echo "<div class='box'>";
        echo "<h3 class='title is-3'>$selectedDate Chat</h3>";
        echo "<pre>$chatHistory</pre>";
        echo "</div>";
      } else {
        echo "<div class='box'>";
        echo "<h3 class='title is-3'>$selectedDate Chat</h3>";
        echo "Chat history not found for the selected date";
        echo "</div>";
      }
    }
    ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const tabs = document.querySelectorAll('#chatTabs li a');
  const tabContents = document.querySelectorAll('.tab-content');
  tabs.forEach(tab => {
    tab.addEventListener('click', (event) => {
      event.preventDefault();
      const target = tab.getAttribute('href').substring(1);
      tabs.forEach(item => item.parentElement.classList.remove('is-active'));
      tabContents.forEach(content => content.classList.remove('is-active'));
      tab.parentElement.classList.add('is-active');
      document.getElementById(target).classList.add('is-active');
    });
  });
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>