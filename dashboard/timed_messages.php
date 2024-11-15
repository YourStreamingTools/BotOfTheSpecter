<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Timed Messages";

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$greeting = 'Hello';

// Initialize variables for messages or errors
$successMessage = "";
$errorMessage = "";
$displayMessages = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if the form was submitted for adding a new message
    if (isset($_POST['message']) && isset($_POST['interval'])) {
        $message = $_POST['message'];
        $interval = filter_input(INPUT_POST, 'interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5, "max_range" => 60)));

        // Validate input data
        if ($interval === false) {
            $errorMessage = "Interval must be a valid integer between 5 and 60.";
        } else {
            try {
                $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `message`) VALUES (?, ?)');
                $stmt->execute([$interval, $message]);
                $successMessage = 'Timed Message: "' . $_POST['message'] . '" with the interval: ' . $_POST['interval'] . ' has been successfully added to the database.';
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
                $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `message` = ? WHERE id = ?');
                $stmt->execute([$edit_interval, $edit_message_content, $edit_message_id]);
                // Optionally, you can check if the update was successful and provide feedback to the user
                $updated = $stmt->rowCount() > 0; // Check if any rows were affected
                if ($updated) {
                    $successMessage = 'Message with ID ' . $edit_message_id . ' updated successfully.';
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
    
    // Redirect with message
    if (!empty($successMessage)) {
        header("Location: {$_SERVER['PHP_SELF']}?successMessage=" . urlencode($successMessage));
        exit();
    } elseif (!empty($errorMessage)) {
        header("Location: {$_SERVER['PHP_SELF']}?errorMessage=" . urlencode($errorMessage));
        exit();
    }
}
$displayMessageData = !empty($_GET['successMessage']) || !empty($_GET['errorMessage']);

if ($displayMessageData) {
    if (!empty($_GET['successMessage'])) {
        $errorMessage = isset($_GET['successMessage']) ? $_GET['successMessage'] : '';
        $displayMessages = "<p class='has-text-success'>" . htmlspecialchars($_GET['successMessage']) . "</p>";
    } elseif (!empty($_GET['errorMessage'])) {
        $errorMessage = isset($_GET['errorMessage']) ? $_GET['errorMessage'] : '';
        $displayMessages = "<p class='has-text-danger'>". htmlspecialchars($errorMessage) . "</p>";
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
    <div class="notification is-danger">
        Important Notice: This feature is currently under heavy development and testing. As such, it may not work as expected at this time.<br>
        Any changes made to a timed message will require a bot reboot for the update to take effect. Please ensure the bot is restarted after editing or adding messages.
    </div>
    <br>
    <?php if ($displayMessages): ?><div class="notification is-primary"><?php echo $displayMessages; ?></div><br><?php endif; ?>
    <div class="columns">
        <div class="column is-one-third">
            <h4 class="title is-5">Add a timed message:</h4>
            <form id="addMessageForm" method="post" action="">
                <div class="field">
                    <label class="label" for="message">Message:</label>
                    <div class="control">
                        <input class="input" type="text" name="message" id="message" required>
                        <span id="messageError" class="help is-danger" style="display: none;">Message is required</span>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="interval">Interval: (Minutes, Between 5-60)</label>
                    <div class="control">
                        <input class="input" type="number" name="interval" id="interval" min="5" max="60" required>
                        <span id="intervalError" class="help is-danger" style="display: none;">Please pick a time between 5 and 60 minutes</span>
                    </div>
                </div>
                <div class="control"><button type="submit" class="button is-primary">Add Message</button></div>
            </form>
        </div>
        <?php if (!empty($timedMessagesData)): ?>
            <div class="column is-one-third">
                <h4 class="title is-5">Edit a timed message:</h4>
                <form method="post" action="">
                    <div class="field">
                        <label class="label" for="edit_message">Select Message to Edit:</label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="edit_message" id="edit_message">
                                    <option value="">PICK A MESSAGE TO EDIT</option>
                                    <?php
                                    usort($timedMessagesData, function($a, $b) {
                                        return $a['id'] - $b['id'];
                                    });
                                    foreach ($timedMessagesData as $message): ?>
                                        <option value="<?php echo $message['id']; ?>">
                                            (<?php echo $message['id']; ?>) <?php echo $message['message']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="edit_interval">New Interval:</label>
                        <div class="control"><input class="input" type="number" name="edit_interval" id="edit_interval" min="5" max="60" required></div>
                    </div>
                    <div class="field">
                        <label class="label" for="edit_message_content">New Message:</label>
                        <div class="control">
                            <input class="input" type="text" name="edit_message_content" id="edit_message_content" required>
                        </div>
                    </div>
                    <div class="control"><button type="submit" class="button is-primary">Edit Message</button></div>
                </form>
            </div>
            <div class="column is-one-third">
                <h4 class="title is-5">Remove a timed message:</h4>
                <form method="post" action="">
                    <div class="field">
                        <label class="label" for="remove_message">Select Message to Remove:</label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="remove_message" id="remove_message">
                                    <?php foreach ($timedMessagesData as $message): ?>
                                        <option value="<?php echo $message['id']; ?>"><?php echo $message['message']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="control"><button type="submit" class="button is-danger">Remove Message</button></div>
                </form>
            </div>
        <?php endif; ?>
    </div>
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
        editIntervalInput.value = messageData.interval_count;
    } else {
        editMessageContent.value = '';
        editIntervalInput.value = '';
    }
}

// Call the function initially to pre-fill the fields if a default message is selected
window.onload = showResponse;
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>