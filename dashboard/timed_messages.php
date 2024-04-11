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
$title = "Timed Messages";

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

// Initialize variables for messages or errors
$successMessage = "";
$errorMessage = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if the form was submitted for adding a new message
    if (isset($_POST['message']) && isset($_POST['interval'])) {
        $message = $_POST['message'];
        $interval = filter_input(INPUT_POST, 'interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5, "max_range" => 60)));

        // Validate input data
        if ($interval === false) {
            $errorMessage = "Interval must be a valid integer between 5 and 60.";
        } else {
            // Insert new message into SQLite database
            try {
                $stmt = $db->prepare("INSERT INTO timed_messages (interval, message) VALUES (?, ?)");
                $stmt->execute([$interval, $message]);
                $successMessage = '<p style="color: green;">Timed Message: "' . $_POST['message'] . '" with the interval: ' . $_POST['interval'] . ' has been successfully added to the database.</p>';
            } catch (PDOException $e) {
                $errorMessage = "Error adding message: " . $e->getMessage();
            }
        }
    }    

    // Check if the form was submitted for removing a message
    elseif (isset($_POST['remove_message'])) {
        $message_id = $_POST['remove_message'];

        // Remove the selected message from the database
        try {
            $stmt = $db->prepare("DELETE FROM timed_messages WHERE id = ?");
            $stmt->execute([$message_id]);
            // Optionally, you can check if the deletion was successful and provide feedback to the user
            $deleted = $stmt->rowCount() > 0; // Check if any rows were affected
            if ($deleted) {
                $successMessage = "Message removed successfully.";
            } else {
                $errorMessage = "Failed to remove message.";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error removing message: " . $e->getMessage();
        }
    }

    // Check if the form was submitted for editing the message or the interval of a message
    elseif (isset($_POST['edit_message']) && isset($_POST['edit_interval'])) {
        $edit_message_id = $_POST['edit_message'];
        $edit_interval = filter_input(INPUT_POST, 'edit_interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5, "max_range" => 60)));
        $edit_message_content = $_POST['edit_message_content'];

        // Check if the edit_message_id exists in the timed_messages table
        $stmt = $db->prepare("SELECT COUNT(*) FROM timed_messages WHERE id = ?");
        $stmt->execute([$edit_message_id]);
        $message_exists = $stmt->fetchColumn();

        if ($message_exists && $edit_interval !== false) {
            // Update the message and/or interval for the selected message in the database
            try {
                $stmt = $db->prepare("UPDATE timed_messages SET interval = ?, message = ? WHERE id = ?");
                $stmt->execute([$edit_interval, $edit_message_content, $edit_message_id]);
                // Optionally, you can check if the update was successful and provide feedback to the user
                $updated = $stmt->rowCount() > 0; // Check if any rows were affected
                if ($updated) {
                    $successMessage = '<p style="color: green;">Message with ID ' . $edit_message_id . ' updated successfully.</p>';
                } else {
                    $errorMessage = "Failed to update message.";
                }
            } catch (PDOException $e) {
                $errorMessage = "Error updating message: " . $e->getMessage();
            }
        } else {
            $errorMessage = "Invalid input data.";
        }
    }
}
$displayMessages = !empty($successMessage) || !empty($errorMessage);
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
    <?php if ($displayMessages): ?>
    <div class="messages">
        <?php
        // Display success message
        if (!empty($successMessage)) {
            echo "<p style='color: green;'>$successMessage</p>";
        }

        // Display error message
        if (!empty($errorMessage)) {
            echo "<p style='color: red;'>$errorMessage</p>";
        }
        ?>
    </div>
    <br>
    <?php endif; ?>
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
    <br><?php
    $items_in_database = !empty($timedMessagesData);
    if ($items_in_database): ?>
    <div class="row">
        <div class="small-12 medium-6 column">
            <h4>Edit a timed message:</h4>
        <form method="post" action="">
            <div class="row">
                <div class="small-12 medium-6 column">
                    <label for="edit_message">Select Message to Edit:</label>
                    <select name="edit_message" id="edit_message" onchange="showResponse()">
                        <option value="">PICK A MESSAGE TO EDIT</option>
                        <?php usort($timedMessagesData, function($a, $b) { return $a['id'] - $b['id']; }); foreach ($timedMessagesData as $message): ?>
                            <option value="<?php echo $message['id']; ?>">
                                (<?php echo $message['id']; ?>) <?php echo $message['message']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="small-12 medium-6 column">
                    <label for="edit_interval">New Interval:</label>
                    <input type="number" name="edit_interval" id="edit_interval" min="5" max="60" required value="<?php echo $message['interval']; ?>">
                </div>
                <div class="small-12 medium-12 column">
                    <label for="edit_message_content">New Message:</label>
                    <input type="text" name="edit_message_content" id="edit_message_content" required value="<?php echo $message['message']; ?>">
                </div>
            </div>
            <input type="submit" class="defult-button" value="Edit Message">
        </form>
        </div>
        <div class="small-12 medium-6 column">
                <h4>Remove a timed message:</h4>
            <form method="post" action="">
                <label for="remove_message">Select Message to Remove:</label>
                <select name="remove_message" id="remove_message">
                    <?php foreach ($timedMessagesData as $message): ?>
                        <option value="<?php echo $message['id']; ?>"><?php echo $message['message']; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="defult-button" value="Remove Message">
            </form>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
    // Define the function to show response
    function showResponse() {
        var editMessage = document.getElementById('edit_message').value;
        var timedMessagesData = <?php echo json_encode($timedMessagesData); ?>;
        var editMessageContent = document.getElementById('edit_message_content');
        var editIntervalInput = document.getElementById('edit_interval');

        // Find the message content and interval for the selected message and update the corresponding input fields
        var messageData = timedMessagesData.find(m => m.id == editMessage);
        if (messageData) {
            editMessageContent.value = messageData.message;
            editIntervalInput.value = messageData.interval;
        } else {
            editMessageContent.value = '';
            editIntervalInput.value = '';
        }
    }

    // Call the function initially to pre-fill the fields if a default message is selected
    window.onload = showResponse;
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>