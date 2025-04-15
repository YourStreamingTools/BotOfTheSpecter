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

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'modding_access.php';
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$channelData = $stmt->fetch(PDO::FETCH_ASSOC);
$timezone = $channelData['timezone'] ?? 'UTC';
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
        $chat_line_trigger = filter_input(INPUT_POST, 'chat_line_trigger', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5)));
        // Validate input data
        if ($interval === false) {
            $errorMessage = "Interval must be a valid integer between 5 and 60.";
        } elseif ($chat_line_trigger !== null && $chat_line_trigger === false) {
            $errorMessage = "Chat Line Trigger must be a valid integer greater than or equal to 5.";
        } else {
            try {
                $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `message`, `status`, `chat_line_trigger`) VALUES (?, ?, ?, ?)');
                // Default status is 'True' (Enabled)
                $stmt->execute([$interval, $message, 'True', $chat_line_trigger]);
                $successMessage = 'Timed Message: "' . $_POST['message'] . '" with the interval: ' . $_POST['interval'] . 
                                  ($chat_line_trigger ? ' and chat line trigger: ' . $chat_line_trigger : '') . ' has been successfully added to the database.';
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
            // Check if the deletion was successful and provide feedback to the user
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
        $edit_chat_line_trigger = filter_input(INPUT_POST, 'edit_chat_line_trigger', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5)));
        // Check if the edit_message_id exists in the timed_messages table
        $stmt = $db->prepare("SELECT COUNT(*) FROM timed_messages WHERE id = ?");
        $stmt->execute([$edit_message_id]);
        $message_exists = $stmt->fetchColumn();
        if ($message_exists && $edit_interval !== false) {
            // Update the message, interval, and status for the selected message in the database
            try {
                $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `message` = ?, `status` = ?, `chat_line_trigger` = ? WHERE id = ?');
                $stmt->execute([$edit_interval, $edit_message_content, $edit_status, $edit_chat_line_trigger, $edit_message_id]);
                // Check if the update was successful and provide feedback to the user
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
    <div class="notification is-info">
        <div class="columns is-vcentered is-centered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-clock fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                <p><strong>Timed Messages</strong></p> 
                <p>Timed messages function based on the following parameters:</p>
                <ul style="margin-left: 20px; list-style-type: disc;">
                    <li><strong>Message:</strong> Enter the text you want the bot to send to your chat.</li>
                    <li><strong>Interval:</strong> Set the time interval between each message in minutes (between 5 and 60 minutes).</li>
                    <li><strong>Chat Lines:</strong> Specify the minimum number of chat messages that must be sent in your chat before the timed message is triggered. The minimum is 5 chat messages.</li>
                </ul>
                <p><span class="icon"><i class="fas fa-power-off"></i></span> After making changes here for the streamer, please remind them to restart the bot from their dashboard to apply the new settings.</p>
            </div>
        </div>
    </div>
    <br>
    <?php if ($displayMessages): ?><div class="notification is-primary"><?php echo $displayMessages; ?></div><br><?php endif; ?>
    <div class="columns is-desktop is-multiline box-container">
        <div class="column is-3 bot-box">
            <h4 class="title is-5">Add a timed message:</h4>
            <form id="addMessageForm" method="post" action="" onsubmit="return validateForm()">
                <div class="field">
                    <label for="message">Message:</label>
                    <div class="control">
                        <input class="input" type="text" name="message" id="message" required maxlength="255" oninput="updateCharCount('message', 'charCount')">
                        <p id="charCount" class="help">0/255 characters</p>
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
                <div class="field">
                    <label for="chat_line_trigger">Chat Line Trigger: (Minimum 5)</label>
                    <div class="control">
                        <input class="input" type="number" name="chat_line_trigger" id="chat_line_trigger" min="5">
                        <span id="chatLineTriggerError" class="help is-danger" style="display: none;">Please enter a valid number of chat lines</span>
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
                                    <option value="" selected>PICK A MESSAGE TO EDIT</option>
                                    <?php
                                    usort($timedMessagesData, function($a, $b) {
                                        return $a['id'] - $b['id'];
                                    });
                                    foreach ($timedMessagesData as $message): ?>
                                        <option value="<?php echo $message['id']; ?>">
                                            (<?php echo "ID: " . $message['id']; ?>) <?php echo $message['message']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="edit_interval">Interval: (Minutes, Between 5-60)</label>
                        <div class="control">
                            <input class="input" type="number" name="edit_interval" id="edit_interval" min="5" max="60" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="edit_chat_line_trigger">Chat Line Trigger: (Minimum 5)</label>
                        <div class="control">
                            <input class="input" type="number" name="edit_chat_line_trigger" id="edit_chat_line_trigger" min="5">
                        </div>
                    </div>
                    <div class="field">
                        <label for="edit_message_content">Message:</label>
                        <div class="control">
                            <input class="input" type="text" name="edit_message_content" id="edit_message_content" required maxlength="255" oninput="updateCharCount('edit_message_content', 'editCharCount')">
                            <p id="editCharCount" class="help">0/255 characters</p>
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
                                <select name="remove_message" id="remove_message" onchange="showMessage()">
                                    <option value="">PICK A MESSAGE TO REMOVE</option>
                                    <?php foreach ($timedMessagesData as $message): ?>
                                        <option value="<?php echo $message['id']; ?>">
                                            Message ID: <?php echo $message['id']; ?> - 
                                            <?php echo htmlspecialchars(mb_strimwidth($message['message'], 0, 40, "")); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="remove_message_content">Message:</label>
                        <div class="control">
                            <textarea class="textarea" id="remove_message_content" disabled rows="7"></textarea>
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
    var editChatLineTriggerInput = document.getElementById('edit_chat_line_trigger');
    // Find the message content, interval, and chat line trigger for the selected message and update the corresponding input fields
    var messageData = timedMessagesData.find(m => m.id == editMessage);
    if (messageData) {
        editMessageContent.value = messageData.message;
        editIntervalInput.value = messageData.interval_count;
        editChatLineTriggerInput.value = messageData.chat_line_trigger || 5;
        // Update character count for the edit message field
        updateCharCount('edit_message_content', 'editCharCount');
    } else {
        editMessageContent.value = '';
        editIntervalInput.value = '';
        editChatLineTriggerInput.value = '';
        // Reset character count
        document.getElementById('editCharCount').textContent = '0/255 characters';
        document.getElementById('editCharCount').className = 'help';
    }
}

// Function to update character counts
function updateCharCount(inputId, counterId) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    const maxLength = 255;
    const currentLength = input.value.length;
    // Update the counter text
    counter.textContent = currentLength + '/' + maxLength + ' characters';
    // Update styling based on character count
    if (currentLength > maxLength) {
        counter.className = 'help is-danger';
    } else if (currentLength > maxLength * 0.8) {
        counter.className = 'help is-warning';
    } else {
        counter.className = 'help is-info';
    }
}

// Call the function initially to pre-fill the fields if a default message is selected
window.onload = function() {
    showResponse();
    // Initialize character count for the add message field
    updateCharCount('message', 'charCount');
}

function showMessage() {
    var select = document.getElementById('remove_message');
    var selectedMessage = select.options[select.selectedIndex].value;
    <?php foreach ($timedMessagesData as $message): ?>
        if (selectedMessage == '<?php echo $message['id']; ?>') {
            document.getElementById('remove_message_content').value = '<?php echo addslashes($message['message']); ?>';
        }
    <?php endforeach; ?>
}

// Function to validate the form before submission
function validateForm() {
    // Message length validation
    const messageInput = document.getElementById('message');
    if (messageInput.value.length > 255) {
        document.getElementById('messageError').textContent = 'Message exceeds 255 character limit';
        document.getElementById('messageError').style.display = 'block';
        return false;
    }
    // Interval validation
    const intervalInput = document.getElementById('interval');
    if (intervalInput.value < 5 || intervalInput.value > 60) {
        document.getElementById('intervalError').style.display = 'block';
        return false;
    }
    return true;
}

// Function to validate the edit form before submission
function validateEditForm() {
    const editMessageContent = document.getElementById('edit_message_content');
    if (editMessageContent.value.length > 255) {
        alert('Message exceeds 255 character limit');
        return false;
    }
    return true;
}

// Update the edit form to use validation
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.querySelector('form:nth-of-type(2)');
    if (editForm) {
        editForm.onsubmit = validateEditForm;
    }
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>