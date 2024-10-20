<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Sound Alerts";

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
include 'bot_control.php';
include 'sqlite.php';

// Fetch sound alert mappings for the current user
$getSoundAlerts = $db->prepare("SELECT sound_mapping, reward_id FROM sound_alerts");
$getSoundAlerts->execute();
$soundAlerts = $getSoundAlerts->fetchAll(PDO::FETCH_ASSOC);

// Create an associative array for easy lookup: sound_mapping => reward_id
$soundAlertMappings = [];
foreach ($soundAlerts as $alert) {
    $soundAlertMappings[$alert['sound_mapping']] = $alert['reward_id'];
}

// Create an associative array for reward_id => reward_title for easy lookup
$rewardIdToTitle = [];
foreach ($channelPointRewards as $reward) {
    $rewardIdToTitle[$reward['reward_id']] = $reward['reward_title'];
}

// Define sound alert path and storage limits
$soundalert_path = "/var/www/soundalerts/" . $username;
$status = '';
$max_storage_size = 2 * 1024 * 1024; // 2MB in bytes

// Create the user's directory if it doesn't exist
if (!is_dir($soundalert_path)) {
    if (!mkdir($soundalert_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

// Calculate total storage used by the user
function calculateStorageUsed($directory) {
    $size = 0;
    foreach (glob(rtrim($directory, '/').'/*', GLOB_NOSORT) as $file) {
        $size += is_file($file) ? filesize($file) : calculateStorageUsed($file);
    }
    return $size;
}

$current_storage_used = calculateStorageUsed($soundalert_path);
$storage_percentage = ($current_storage_used / $max_storage_size) * 100;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Storage limit exceeded.<br>";
            continue;
        }
        $targetFile = $soundalert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 files are allowed.<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= "The file " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . " has been uploaded.<br>";
        } else {
            $status .= "Sorry, there was an error uploading " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ".<br>";
        }
    }
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100; // Update percentage after upload
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $file_to_delete_path = $soundalert_path . '/' . basename($file_to_delete);
        if (is_file($file_to_delete_path) && unlink($file_to_delete_path)) {
            $status .= "The file " . htmlspecialchars(basename($file_to_delete)) . " has been deleted.<br>";
            // Also remove mapping from database
            $removeMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = :sound_mapping");
            $removeMapping->bindParam(':sound_mapping', basename($file_to_delete));
            $removeMapping->execute();
            $current_storage_used = calculateStorageUsed($soundalert_path); // Recalculate storage after deletion
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100; // Update percentage after deletion
        } else {
            $status .= "Failed to delete " . htmlspecialchars(basename($file_to_delete)) . ".<br>";
        }
    }
}

// Handle mapping submissions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['sound_file'])) {
    $sound_file = $_POST['sound_file'];
    $reward_id = $_POST['reward_id'] !== '' ? $_POST['reward_id'] : null;
    try {
        // Begin transaction
        $db->beginTransaction();
        if ($reward_id) {
            // Remove any existing mapping for this reward_id to enforce one sound per reward
            $removeExisting = $db->prepare("UPDATE sound_alerts SET reward_id = NULL WHERE reward_id = :reward_id AND sound_mapping != :sound_mapping");
            $removeExisting->bindParam(':reward_id', $reward_id);
            $removeExisting->bindParam(':sound_mapping', $sound_file);
            $removeExisting->execute();
        }
        if ($reward_id) {
            // Insert or update the mapping
            $checkMapping = $db->prepare("SELECT * FROM sound_alerts WHERE sound_mapping = :sound_mapping");
            $checkMapping->bindParam(':sound_mapping', $sound_file);
            $checkMapping->execute();
            $existing = $checkMapping->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                // Update existing mapping
                $updateMapping = $db->prepare("UPDATE sound_alerts SET reward_id = :reward_id WHERE sound_mapping = :sound_mapping");
                $updateMapping->bindParam(':reward_id', $reward_id);
                $updateMapping->bindParam(':sound_mapping', $sound_file);
                $updateMapping->execute();
            } else {
                // Insert new mapping
                $insertMapping = $db->prepare("INSERT INTO sound_alerts (sound_mapping, reward_id) VALUES (:sound_mapping, :reward_id)");
                $insertMapping->bindParam(':sound_mapping', $sound_file);
                $insertMapping->bindParam(':reward_id', $reward_id);
                $insertMapping->execute();
            }
        } else {
            // If no reward_id is selected, remove any existing mapping
            $removeMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = :sound_mapping");
            $removeMapping->bindParam(':sound_mapping', $sound_file);
            $removeMapping->execute();
        }
        // Commit transaction
        $db->commit();
        // Reload the page to reflect changes
        header('Location: sound-alerts.php');
        exit();
    } catch (PDOException $e) {
        // Rollback transaction on error
        $db->rollBack();
        $status = "<div class='message is-danger'>Error updating mapping: " . htmlspecialchars($e->getMessage()) . "</div>";
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
    <h1 class="title is-4">Upload Sound Alerts</h1>
    <div class="notification is-danger">Upload your audio file below and use the dropdown menu to pick which channel point should trigger the sound. (V4.8+)</div>
    <div class="upload-container" style="width: 100%; max-width: 500px;">
        <?php if (!empty($status)) : ?>
            <div class="message"><?php echo $status; ?></div>
        <?php endif; ?>
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <label for="filesToUpload" class="drag-area" id="drag-area">
                <span>Drag & Drop files here or</span>
                <span>Browse Files</span>
                <input type="file" name="filesToUpload[]" id="filesToUpload" multiple>
            </label>
            <br>
            <input type="submit" value="Upload MP3 Files" name="submit">
        </form>
        <br>
        <div class="progress-bar-container">
            <div class="progress-bar has-text-black-bis" style="width: <?php echo $storage_percentage; ?>%;"><?php echo round($storage_percentage, 2); ?>%</div>
        </div>
        <p><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB of 2MB used</p>
    </div>
    <?php $walkon_files = array_diff(scandir($soundalert_path), array('.', '..')); if (!empty($walkon_files)) : ?>
    <div class="container">
        <h1 class="title is-4">Sound Alerts Uploaded</h1>
        <form action="" method="POST" id="deleteForm">
            <table class="table is-striped" style="width: 100%; max-width: 800px; text-align: center;">
                <thead>
                    <tr>
                        <th>Select</th>
                        <th>File Name</th>
                        <th>Channel Point Reward</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($walkon_files as $file): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>">
                        </td>
                        <td>
                            <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>
                        </td>
                        <td>
                            <?php
                            // Determine the current mapped reward (if any)
                            $current_reward_id = isset($soundAlertMappings[$file]) ? $soundAlertMappings[$file] : null;
                            $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id]) : "Not Mapped";
                            ?>
                            <?php if ($current_reward_id): ?>
                                <em><?php echo $current_reward_title; ?></em>
                            <?php else: ?>
                                <em>Not Mapped</em>
                            <?php endif; ?>
                            <br>
                            <form action="" method="POST" class="mapping-form">
                                <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                <select name="reward_id" class="mapping-select" onchange="this.form.submit()">
                                    <option value="">-- Select Reward --</option>
                                    <?php 
                                    foreach ($channelPointRewards as $reward): 
                                        $isMapped = in_array($reward['reward_id'], $soundAlertMappings);
                                        $isCurrent = ($current_reward_id === $reward['reward_id']);
                                        // Skip rewards that are already mapped to other sounds, unless it's the current mapping
                                        if ($isMapped && !$isCurrent) continue; 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($reward['reward_id']); ?>" 
                                            <?php 
                                            if ($isCurrent) {
                                                echo 'selected';
                                            }
                                            ?>
                                        >
                                            <?php echo htmlspecialchars($reward['reward_title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <button type="button" class="delete-single button is-danger" data-file="<?php echo htmlspecialchars($file); ?>">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="submit" value="Delete Selected" class="button is-danger" name="submit_delete" style="margin-top: 10px;">
        </form>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
$(document).ready(function() {
    let dropArea = $('#drag-area');
    let fileInput = $('#filesToUpload');

    dropArea.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropArea.addClass('dragging');
    });

    dropArea.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropArea.removeClass('dragging');
    });

    dropArea.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropArea.removeClass('dragging');

        let files = e.originalEvent.dataTransfer.files;
        fileInput.prop('files', files);

        $('#uploadForm').submit();
    });

    dropArea.on('click', function() {
        fileInput.click();
    });

    fileInput.on('change', function() {
        $('#uploadForm').submit();
    });

    // Handle single file deletion with confirmation
    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        if(confirm('Are you sure you want to delete "' + fileName + '"?')) {
            $('<input>').attr({
                type: 'hidden',
                name: 'delete_files[]',
                value: fileName
            }).appendTo('#deleteForm');
            $('#deleteForm').submit();
        }
    });
});
</script>
</body>
</html>