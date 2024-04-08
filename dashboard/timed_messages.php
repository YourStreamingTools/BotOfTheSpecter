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
$title = "Create Timed Messages";

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
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['message']) && isset($_POST['interval'])) {
      $message = $_POST['message'];
      $interval = $_POST['interval'];
      
      // Insert new message into SQLite database
      try {
          $stmt = $db->prepare("INSERT INTO timed_messages (interval, message) VALUES (?, ?)");
          $stmt->execute([$interval, $message]);
      } catch (PDOException $e) {
          echo 'Error adding message: ' . $e->getMessage();
      }
  }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if the remove message form is submitted
    if (isset($_POST['remove_message'])) {
        $message_id = $_POST['remove_message'];
        
        // Remove the selected message from the database
        try {
            $stmt = $db->prepare("DELETE FROM timed_messages WHERE id = ?");
            $stmt->execute([$message_id]);
            // Optionally, you can check if the deletion was successful and provide feedback to the user
            $deleted = $stmt->rowCount() > 0; // Check if any rows were affected
            if ($deleted) {
                echo "Message removed successfully.";
            } else {
                echo "Failed to remove message.";
            }
        } catch (PDOException $e) {
            echo 'Error removing message: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Headder -->
    <?php include('header.php'); ?>
    <!-- /Headder -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="row column">
    <br>
    <h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <div class="small-12 medium-6 column">
        <p style='color: red;'>
            <strong>Message</strong>: What is the message that needs to be posted.
        </p>
    </div>
    <div class="small-12 medium-6 column">
        <p style='color: red;'>
            <strong>Interval</strong>: The time the message needs to wait before posting again, between 5 and 60 minutes.<br>
        </p>
    </div>
    <form method="post" action="">
        <div class="row">
            <div class="small-12 medium-6 column">
                <label for="message">Message:</label>
                <input type="text" name="message" id="message" required>
            </div>
            <div class="small-12 medium-6 column">
                <label for="interval">Interval:</label>
                <input type="number" name="interval" id="interval" min="5" max="60" required>
            </div>
        </div>
        <input type="submit" class="defult-button" value="Add Message">
    </form>
    <br>
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message']) && isset($_POST['interval'])): ?>
        <p style="color: green;">Timed Message: "<?php echo $_POST['message']; ?>" with the interval: <?php echo $_POST['interval']; ?> has been successfully added to the database.</p>
    <?php endif; ?>
    <br><br><br>
    <?php
    $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
    $timedMessagesData = $getTimedMessages->fetchAll(PDO::FETCH_ASSOC);
    
    $items_in_database = !empty($timedMessagesData);
    if ($items_in_database):
    ?>
        <form method="post" action="">
            <!-- Add form for removing messages -->
            <div class="row">
                <div class="small-6">
                    <label for="remove_message">Select Message to Remove:</label>
                    <select name="remove_message" id="remove_message">
                        <?php foreach ($timedMessagesData as $message): ?>
                            <option value="<?php echo $message['id']; ?>"><?php echo $message['message']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <input type="submit" class="defult-button" value="Remove Message">
        </form>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>