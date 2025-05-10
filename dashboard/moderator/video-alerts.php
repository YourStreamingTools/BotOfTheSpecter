<?php
// Initialize the session
session_start();
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$title = "Video Alerts";

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

// Fetch video alert mappings for the current user
$getVideoAlerts = $db->prepare("SELECT video_mapping, reward_id FROM video_alerts");
$getVideoAlerts->execute();
$videoAlerts = $getVideoAlerts->fetchAll(PDO::FETCH_ASSOC);

// Create an associative array for easy lookup: video_mapping => reward_id
$videoAlertMappings = [];
foreach ($videoAlerts as $alert) {
    $videoAlertMappings[$alert['video_mapping']] = $alert['reward_id'];
}

// NEW: Query sound alerts to exclude mapped rewards
$getSoundAlertsForMapping = $db->prepare("SELECT DISTINCT reward_id FROM sound_alerts");
$getSoundAlertsForMapping->execute();
$soundMappedRewards = $getSoundAlertsForMapping->fetchAll(PDO::FETCH_COLUMN, 0);

// Create an associative array for reward_id => reward_title for easy lookup
$rewardIdToTitle = [];
foreach ($channelPointRewards as $reward) {
    $rewardIdToTitle[$reward['reward_id']] = $reward['reward_title'];
}

// Define empty variables
$status = '';

// Handle channel point reward mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['video_file'], $_POST['reward_id'])) {
    $status = ""; // Initialize $status
    $videoFile = $_POST['video_file'];
    $rewardId = $_POST['reward_id'];
    $videoFile = htmlspecialchars($videoFile); 
    $db->beginTransaction();  
    // Check if a mapping already exists for this video file
    $checkExisting = $db->prepare("SELECT 1 FROM video_alerts WHERE video_mapping = :video_mapping");
    $checkExisting->bindParam(':video_mapping', $videoFile);
    $checkExisting->execute();
    if ($checkExisting->rowCount() > 0) {
        // Update existing mapping
        if ($rewardId) {
            $updateMapping = $db->prepare("UPDATE video_alerts SET reward_id = :reward_id WHERE video_mapping = :video_mapping");
            $updateMapping->bindParam(':reward_id', $rewardId);
            $updateMapping->bindParam(':video_mapping', $videoFile);
            if (!$updateMapping->execute()) {
                $status .= "Failed to update mapping for file '" . $videoFile . "'. Database error: " . print_r($updateMapping->errorInfo(), true) . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $videoFile . "' has been updated successfully.<br>";
            }
        } else {
            // Delete the mapping if no reward is selected (Remove Mapping option)
            $deleteMapping = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = :video_mapping");
            $deleteMapping->bindParam(':video_mapping', $videoFile);
            if (!$deleteMapping->execute()) {
                $status .= "Failed to remove mapping for file '" . $videoFile . "'. Database error: " . print_r($deleteMapping->errorInfo(), true) . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $videoFile . "' has been removed.<br>";
            }
        }
    } else {
        // Create a new mapping if it doesn't exist
        if ($rewardId) {
            $insertMapping = $db->prepare("INSERT INTO video_alerts (video_mapping, reward_id) VALUES (:video_mapping, :reward_id)");
            $insertMapping->bindParam(':video_mapping', $videoFile);
            $insertMapping->bindParam(':reward_id', $rewardId);
            if (!$insertMapping->execute()) {
                $status .= "Failed to create mapping for file '" . $videoFile . "'. Database error: " . print_r($insertMapping->errorInfo(), true) . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $videoFile . "' has been created successfully.<br>";
            }
        } 
    }
    // Commit transaction
    $db->commit();
    // Re-fetch updated video alert mappings after mapping changes
    $getVideoAlerts = $db->prepare("SELECT video_mapping, reward_id FROM video_alerts");
    $getVideoAlerts->execute();
    $videoAlerts = $getVideoAlerts->fetchAll(PDO::FETCH_ASSOC);
    $videoAlertMappings = [];
    foreach ($videoAlerts as $alert) {
        $videoAlertMappings[$alert['video_mapping']] = $alert['reward_id'];
    }
}

$remaining_storage = $max_storage_size - $current_storage_used;
$max_upload_size = $remaining_storage;
// ini_set('upload_max_filesize', $max_upload_size);
// ini_set('post_max_size', $max_upload_size);

function translateUploadError($code) {
    switch ($code) {
        case UPLOAD_ERR_OK:
            return 'No error';
        case UPLOAD_ERR_INI_SIZE:
            return 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.';
        case UPLOAD_ERR_PARTIAL:
            return 'The uploaded file was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing a temporary folder.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'File upload stopped by extension.';
        default:
            return 'Unknown upload error.';
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    $status = ""; // Reset status for this operation
    if (empty($_FILES["filesToUpload"]["tmp_name"]) || !$_FILES["filesToUpload"]["tmp_name"]) {
        $status .= "No file was received by the server.<br>";
    }
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $errorCode = $_FILES["filesToUpload"]["error"][$key];
        if ($errorCode !== UPLOAD_ERR_OK) {
            $status .= "Sorry, there was an error uploading " 
                . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) 
                . ": " . translateUploadError($errorCode) . "<br>";
            continue;
        }
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Storage limit exceeded.<br>";
            continue;
        }
        $targetFile = $videoalert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp4") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP4 files are allowed.<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= "The file " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . " has been uploaded.<br>";
        } else {
            $status .= "Sorry, there was an error uploading "
                . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
                . ".<br>";
        }
    }
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100; // Update percentage after upload
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    $status = ""; // Reset status for this operation
    $db->beginTransaction(); // Begin transaction for database operations
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $filename = basename($file_to_delete);
        $full_path = $videoalert_path . '/' . $filename;
        // First delete the physical file
        if (is_file($full_path) && unlink($full_path)) {
            $status .= "The file " . htmlspecialchars($filename) . " has been deleted.<br>";
            // Now delete any mapping for this file from the database
            $deleteMapping = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = :video_mapping");
            $deleteMapping->bindParam(':video_mapping', $filename);
            if ($deleteMapping->execute()) {
                if ($deleteMapping->rowCount() > 0) {
                    $status .= "The mapping for " . htmlspecialchars($filename) . " has also been removed.<br>";
                }
            } else {
                $status .= "Warning: Could not remove mapping for " . htmlspecialchars($filename) . ".<br>";
            }
        } else {
            $status .= "Failed to delete " . htmlspecialchars($filename) . ".<br>";
        }
    }
    $db->commit(); // Commit all database changes
    $current_storage_used = calculateStorageUsed([$walkon_path, $videoalert_path]);
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
}

$videoalert_files = array_diff(scandir($videoalert_path), array('.', '..'));
function formatFileName($fileName) { return basename($fileName, '.mp4'); }
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
        <div class="columns is-vcentered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-bell fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                <p><strong>Setting up Channel Point Video Alerts</strong></p>
                <ul>
                    <li><span class="icon"><i class="fas fa-upload"></i></span> Upload your video file.  Click 'Upload MP4 Files' and choose the channel point to trigger it.</li>
                    <li><span class="icon"><i class="fab fa-twitch"></i></span> Make sure your rewards are created on Twitch and synced with Specter to see them in the dropdown.</li>
                    <li><span class="icon"><i class="fas fa-play-circle"></i></span> Video alerts will play through Specter Overlays when the channel point is redeemed.</li>
                    <li><span class="icon"><i class="fas fa-headphones"></i></span> Start your streaming software and enable the overlay with video <span class="has-text-weight-bold">before</span> testing.</li> 
                </ul>
            </div>
        </div>
    </div>
    <div class="columns is-desktop is-multiline box-container is-centered" style="width: 100%;">
        <div class="column is-4" id="walkon-upload" style="position: relative;">
            <h1 class="title is-4">Upload MP4 Files:</h1>
            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                <label for="filesToUpload" class="drag-area" id="drag-area">
                    <span>Drag & Drop files here or</span>
                    <span>Browse Files</span>
                    <input type="file" name="filesToUpload[]" id="filesToUpload" multiple>
                </label>
                <br>
                <div id="file-list"></div>
                <br>
                <input type="submit" value="Upload MP4 Files" name="submit">
            </form>
            <br>
            <div class="progress-bar-container">
                <div class="progress-bar has-text-black-bis" style="width: <?php echo $storage_percentage; ?>%;"><?php echo round($storage_percentage, 2); ?>%</div>
            </div>
            <p><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB of <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB used</p>
            <?php if (!empty($status)) : ?>
                <div class="message"><?php echo $status; ?></div>
            <?php endif; ?>
        </div>
        <div class="column is-7 bot-box" id="walkon-upload" style="position: relative;">
            <?php 
            // Ensure the files are displayed in the table
            if (!empty($videoalert_files)) {
                echo '<h1 class="title is-4">Your Video Alerts</h1>';
                echo '<form action="" method="POST" id="deleteForm">';
                echo '<table class="table is-striped" style="width: 100%; text-align: center;">';
                echo '<thead><tr><th style="width: 70px;">Select</th><th>File Name</th><th>Channel Point Reward</th><th style="width: 100px;">Action</th></tr></thead>';
                echo '<tbody>';
                foreach ($videoalert_files as $file) {
                    $fileName = htmlspecialchars(pathinfo($file, PATHINFO_FILENAME));
                    $current_reward_id = isset($videoAlertMappings[$file]) ? $videoAlertMappings[$file] : null;
                    $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id]) : "Not Mapped";
                    echo '<tr>';
                    echo '<td><input type="checkbox" name="delete_files[]" value="' . htmlspecialchars($file) . '"></td>';
                    echo '<td>' . $fileName . '</td>';
                    echo '<td>';
                    echo $current_reward_id ? '<em>' . $current_reward_title . '</em>' : '<em>Not Mapped</em>';
                    echo '<form action="" method="POST" class="mapping-form">';
                    echo '<input type="hidden" name="video_file" value="' . htmlspecialchars($file) . '">';
                    echo '<select name="reward_id" class="mapping-select" onchange="this.form.submit()">';
                    if ($current_reward_id) {
                        echo '<option value="" class="has-text-danger">-- Remove Mapping --</option>';
                    }
                    echo '<option value="">-- Select Reward --</option>';
                    foreach ($channelPointRewards as $reward) {
                        $isMapped = (in_array($reward['reward_id'], $videoAlertMappings) || in_array($reward['reward_id'], $soundMappedRewards));
                        $isCurrent = ($current_reward_id === $reward['reward_id']);
                        if ($isMapped && !$isCurrent) continue;
                        echo '<option value="' . htmlspecialchars($reward['reward_id']) . '"' . ($isCurrent ? ' selected' : '') . '>' . htmlspecialchars($reward['reward_title']) . '</option>';
                    }
                    echo '</select></form></td>';
                    echo '<td><button type="button" class="delete-single button is-danger" data-file="' . htmlspecialchars($file) . '">Delete</button></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<input type="submit" value="Delete Selected" class="button is-danger" name="submit_delete" style="margin-top: 10px;">';
                echo '</form>';
            } else {
                echo '<h1 class="title is-4">No video alert files uploaded.</h1>';
            }
            ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
$(document).ready(function() {
    let dropArea = $('#drag-area');
    let fileInput = $('#filesToUpload');
    let fileList = $('#file-list');
    let uploadProgressBarContainer = $('#upload-progress-bar-container');
    let uploadProgressBar = $('#upload-progress-bar');

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
        fileList.empty();
        $.each(files, function(index, file) {
            fileList.append('<div>' + file.name + '</div>');
        });
    });
    dropArea.on('click', function() {
        fileInput.click();
    });
    fileInput.on('change', function() {
        let files = fileInput.prop('files');
        fileList.empty();
        $.each(files, function(index, file) {
            fileList.append('<div>' + file.name + '</div>');
        });
    });

    function uploadFiles(files) {
        let formData = new FormData();
        $.each(files, function(index, file) {
            formData.append('filesToUpload[]', file);
        });
        uploadProgressBarContainer.show();
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = (e.loaded / e.total) * 100;
                        uploadProgressBar.css('width', percentComplete + '%');
                        uploadProgressBar.text(Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                location.reload(); // Reload the page to update the file list and storage usage
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
            }
        });
    }

    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        if (confirm('Are you sure you want to delete "' + fileName + '"?')) {
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