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
                $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `message`, `status`) VALUES (?, ?, ?)');
                // Assuming the default status is 'True' (Enabled)
                $stmt->execute([$interval, $message, 'True']);
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
    // Check if the form was submitted for editing the message, interval, or status
    elseif (isset($_POST['edit_message']) && isset($_POST['edit_interval']) && isset($_POST['edit_status'])) {
        $edit_message_id = $_POST['edit_message'];
        $edit_interval = filter_input(INPUT_POST, 'edit_interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5, "max_range" => 60)));
        $edit_message_content = $_POST['edit_message_content'];
        $edit_status = $_POST['edit_status'];
        // Check if the edit_message_id exists in the timed_messages table
        $stmt = $db->prepare("SELECT COUNT(*) FROM timed_messages WHERE id = ?");
        $stmt->execute([$edit_message_id]);
        $message_exists = $stmt->fetchColumn();
        if ($message_exists && $edit_interval !== false) {
            // Update the message, interval, and status for the selected message in the database
            try {
                $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `message` = ?, `status` = ? WHERE id = ?');
                $stmt->execute([$edit_interval, $edit_message_content, $edit_status, $edit_message_id]);
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
    <br>
    <div class="notification is-danger">
        Important Notice: This feature is currently under heavy development and testing. As such, it may not work as expected at this time.<br>
        Any changes made to a timed message will require a bot reboot for the update to take effect.<br>
        Please ensure the bot is restarted after editing or adding messages.
    </div>
    <br>
    <?php if ($displayMessages): ?><div class="notification is-primary"><?php echo $displayMessages; ?></div><br><?php endif; ?>
    <div class="columns is-desktop is-multiline box-container">
        <div class="column is-3 bot-box">
            <h4 class="title is-5">Add a timed message:</h4>
            <form id="addMessageForm" method="post" action="">
                <div class="field">
                    <label for="message">Message:</label>
                    <div class="control">
                        <input class="input" type="text" name="message" id="message" required>
                        <span id="messageError" class="help is-danger" style="display: none;">Message is required</span>
                    </div>
                </div>
                <div class="field">
                    <label for="interval">Interval: (Minutes, Between 5-60)</label>
                    <div class="control">
                        <input class="input" type="number" name="interval" id="interval" min="5" max="60" required>
                        <span id="intervalError" class="help is-danger" style="display: none;">Please pick a time between 5 and 60 minutes</span>
                    </div>
                </div>
                <div class="control"><button type="submit" class="button is-primary">Add Message</button></div>
            </form>
        </div>
        <?php if (!empty($timedMessagesData)): ?>
            <div class="column is-4 bot-box">
                <h4 class="title is-5">Edit a timed message:</h4>
                <form method="post" action="">
                    <div class="field">
                        <label for="edit_message">Select Message to Edit:</label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="edit_message" id="edit_message" onchange="showResponse()">
                                    <option value="">PICK A MESSAGE TO EDIT</option>
                                    <?php
                                    usort($timedMessagesData, function($a, $b) {
                                        return $a['id'] - $b['id'];
                                    });
                                    foreach ($timedMessagesData as $message): ?>
                                        <option value="<?php echo $message['id']; ?>" <?php echo $message['status'] == 'True' ? 'selected' : ''; ?>>
                                            (<?php echo $message['id']; ?>) <?php echo $message['message']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="edit_interval">Interval:</label>
                        <div class="control">
                            <input class="input" type="number" name="edit_interval" id="edit_interval" min="5" max="60" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="edit_message_content">Message:</label>
                        <div class="control">
                            <input class="input" type="text" name="edit_message_content" id="edit_message_content" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="edit_status">Status:</label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="edit_status" id="edit_status">
                                    <option value="True" <?php echo isset($status) && $status == 'True' ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="False" <?php echo isset($status) && $status == 'False' ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="control"><button type="submit" class="button is-primary">Save Changes</button></div>
                </form>
            </div>
            <div class="column is-4 bot-box">
                <h4 class="title is-5">Remove a timed message:</h4>
                <form method="post" action="">
                    <div class="field">
                        <label for="remove_message">Select Message to Remove:</label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="remove_message" id="remove_message">
                                    <option value="">PICK A MESSAGE TO REMOVE</option>
                                    <?php foreach ($timedMessagesData as $message): ?>
                                        <option value="<?php echo $message['id']; ?>">
                                            (<?php echo $message['id']; ?>) <?php echo $message['message']; ?>
                                        </option>
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