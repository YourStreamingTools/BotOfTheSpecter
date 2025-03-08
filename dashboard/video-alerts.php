<?php
// Initialize the session
session_start();
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Video Alerts";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include 'storage_used.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
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
            // Clear the mapping if no reward is selected
            $clearMapping = $db->prepare("UPDATE video_alerts SET reward_id = NULL WHERE video_mapping = :video_mapping");
            $clearMapping->bindParam(':video_mapping', $videoFile);
            if (!$clearMapping->execute()) {
                $status .= "Failed to clear mapping for file '" . $videoFile . "'. Database error: " . print_r($clearMapping->errorInfo(), true) . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $videoFile . "' has been cleared.<br>";
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
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $file_to_delete = $videoalert_path . '/' . basename($file_to_delete);
        if (is_file($file_to_delete) && unlink($file_to_delete)) {
            $status .= "The file " . htmlspecialchars(basename($file_to_delete)) . " has been deleted.<br>";
        } else {
            $status .= "Failed to delete " . htmlspecialchars(basename($file_to_delete)) . ".<br>";
        }
    }
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
    <div class="notification is-warning">
        <div class="columns is-vcentered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-exclamation-triangle fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                This feature is in V5.3 Beta.
            </div>
        </div>
    </div>
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
            <div class="progress-bar-container" id="upload-progress-bar-container" style="display: none;">
                <div class="progress-bar has-text-black-bis" id="upload-progress-bar" style="width: 0%;">0%</div>
            </div>
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
            <?php $walkon_files = array_diff(scandir($videoalert_path), array('.', '..')); if (!empty($walkon_files)) : ?>
            <h1 class="title is-4">Your Video Alerts</h1>
            <form action="" method="POST" id="deleteForm">
                <table class="table is-striped" style="width: 100%; text-align: center;">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Select</th>
                            <th>File Name</th>
                            <th>Channel Point Reward</th>
                            <th style="width: 100px;">Action</th>
                            <th style="width: 100px;">Test Video</th>
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
                                $current_reward_id = isset($videoAlertMappings[$file]) ? $videoAlertMappings[$file] : null;
                                $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id]) : "Not Mapped";
                                ?>
                                <?php if ($current_reward_id): ?>
                                    <em><?php echo $current_reward_title; ?></em>
                                <?php else: ?>
                                    <em>Not Mapped</em>
                                <?php endif; ?>
                                <br>
                                <form action="" method="POST" class="mapping-form">
                                    <input type="hidden" name="video_file" value="<?php echo htmlspecialchars($file); ?>">
                                    <select name="reward_id" class="mapping-select" onchange="this.form.submit()">
                                        <option value="">-- Select Reward --</option>
                                        <?php 
                                        foreach ($channelPointRewards as $reward): 
                                            $isMapped = in_array($reward['reward_id'], $videoAlertMappings);
                                            $isCurrent = ($current_reward_id === $reward['reward_id']);
                                            // Skip rewards that are already mapped to other videos, unless it's the current mapping
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
                            <td>
                                <button type="button" class="test-video button is-primary" data-file="<?php echo htmlspecialchars($file); ?>">Test</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="submit" value="Delete Selected" class="button is-danger" name="submit_delete" style="margin-top: 10px;">
            </form>
            <?php else: ?>
                <h1 class="title is-4">No video alert files uploaded.</h1>
            <?php endif; ?>
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
        uploadFiles(files);
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
        uploadFiles(files);
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

document.addEventListener("DOMContentLoaded", function () {
    // Attach click event listeners to all Test buttons
    document.querySelectorAll(".test-video").forEach(function (button) {
        button.addEventListener("click", function () {
            const fileName = this.getAttribute("data-file");
            sendStreamEvent("VIDEO_ALERT", fileName);
        });
    });
});

// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "notify_event.php";
    const params = `event=${eventType}&video=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    console.log(`${eventType} event for ${fileName} sent successfully.`);
                } else {
                    console.error(`Error sending ${eventType} event: ${response.message}`);
                }
            } catch (e) {
                console.error("Error parsing JSON response:", e);
                console.error("Response:", xhr.responseText);
            }
        } else if (xhr.readyState === 4) {
            console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
        }
    };
    xhr.send(params);
}
</script>
</body>
</html>