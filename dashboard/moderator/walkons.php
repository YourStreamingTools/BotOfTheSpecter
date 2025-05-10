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
$title = "Walkons";

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

// Define empty variable for status
$status = '';

// Define user-specific storage limits
$remaining_storage = $max_storage_size - $current_storage_used;
$max_upload_size = $remaining_storage;
// ini_set('upload_max_filesize', $max_upload_size);
// ini_set('post_max_size', $max_upload_size);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Storage limit exceeded.<br>";
            continue;
        }
        $targetFile = $walkon_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3" && $fileType != "mp4") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 and MP4 files are allowed.<br>";
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
        $file_to_delete = $walkon_path . '/' . basename($file_to_delete);
        if (is_file($file_to_delete) && unlink($file_to_delete)) {
            $status .= "The file " . htmlspecialchars(basename($file_to_delete)) . " has been deleted.<br>";
        } else {
            $status .= "Failed to delete " . htmlspecialchars(basename($file_to_delete)) . ".<br>";
        }
    }
    $current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path]);
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
}
$walkon_files = array_diff(scandir($walkon_path), array('.', '..'));
function formatFileName($fileName) { return basename($fileName, '.mp3'); }
function formatFileNamewithEXT($fileName) {
    $fileInfo = pathinfo($fileName);
    $name = basename($fileName, '.' . $fileInfo['extension']);
    $extenstion = strtoupper($fileInfo['extension']);
    return $name . " (" . $extenstion . ")";
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
        <div class="columns is-vcentered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-volume-up fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                <p><strong>Setting up Audio Walkons</strong></p>
                <ul>
                    <li><span class="icon"><i class="fas fa-upload"></i></span> Upload your MP3 audio file. Make sure the filename matches the user's lowercase username.</li>
                    <li><span class="icon"><i class="fas fa-headphones"></i></span>  Start your streaming software and enable the overlay with audio <span class="has-text-weight-bold">before</span> testing.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="columns is-desktop is-multiline box-container is-centered">
        <div class="column is-4" id="walkon-upload" style="position: relative;">
            <h1 class="title is-4">Upload MP3 Files:</h1>
            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                <label for="filesToUpload" class="drag-area" id="drag-area">
                    <span>Drag & Drop files here or</span>
                    <span>Browse Files</span>
                    <input type="file" name="filesToUpload[]" id="filesToUpload" multiple>
                </label>
                <br>
                <div id="file-list"></div>
                <br>
                <input type="submit" value="Upload MP3 Files" name="submit">
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
        <div class="column is-7 bot-box" id="walkon-files" style="position: relative;">
            <?php if (!empty($walkon_files)) : ?>
            <h1 class="title is-4">Users with Walkons</h1>
            <form action="" method="POST" id="deleteForm">
                <table class="table is-striped" style="width: 100%; text-align: center;">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Select</th>
                            <th>File Name</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($walkon_files as $file): ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                            <td style="text-align: center; vertical-align: middle;"><?php echo htmlspecialchars(formatFileNamewithEXT($file)); ?></td>
                            <td><button type="button" class="delete-single button is-danger" data-file="<?php echo htmlspecialchars($file); ?>">Delete</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="submit" value="Delete Selected" class="button is-danger" name="submit_delete" style="margin-top: 10px;">
            </form>
            <?php else: ?>
                <h1 class="title is-4">No walkon files uploaded.</h1>
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
</script>
</body>
</html>