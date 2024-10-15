<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Walkons";

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

$walkon_path = "/var/www/walkons/" . $username;
$status = '';
$max_storage_size = 2 * 1024 * 1024; // 2MB in bytes

// Create the user's directory if it doesn't exist
if (!is_dir($walkon_path)) {
    if (!mkdir($walkon_path, 0755, true)) {
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

$current_storage_used = calculateStorageUsed($walkon_path);
$storage_percentage = ($current_storage_used / $max_storage_size) * 100;

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    $file_to_delete = $walkon_path . '/' . basename($_POST['delete_file']);
    if (is_file($file_to_delete) && unlink($file_to_delete)) {
        $status .= "The file " . htmlspecialchars(basename($_POST['delete_file'])) . " has been deleted.<br>";
        $current_storage_used = calculateStorageUsed($walkon_path); // Recalculate storage after deletion
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100; // Update percentage after deletion
    } else {
        $status .= "Failed to delete " . htmlspecialchars(basename($_POST['delete_file'])) . ".<br>";
    }
}

$walkon_files = array_diff(scandir($walkon_path), array('.', '..'));

function formatFileName($fileName) {
    return basename($fileName, '.mp3');
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
    <h1 class="title is-4">Upload Walkons</h1>
    <div class="notification is-danger">Before uploading, ensure the file is an MP3 and the filename is the user's lowercase username. (V4.6+)</div>
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
    <?php if (!empty($walkon_files)) : ?>
    <div class="container">
        <h1 class="title is-4">Users with Walkons</h1>
        <form action="" method="POST" id="deleteForm">
            <table class="table is-striped" style="width: 100%; max-width: 500px; text-align: center;">
                <thead>
                    <tr>
                        <th style="text-align: center;">Select</th>
                        <th style="text-align: center;">File Name</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($walkon_files as $file): ?>
                    <tr>
                        <td style="text-align: center; vertical-align: middle;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                        <td style="text-align: center; vertical-align: middle;"><?php echo htmlspecialchars(formatFileName($file)); ?></td>
                        <td><button type="button" class="delete-single button is-danger" data-file="<?php echo htmlspecialchars($file); ?>">Delete</button></td>
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

    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        $('<input>').attr({
            type: 'hidden',
            name: 'delete_files[]',
            value: fileName
        }).appendTo('#deleteForm');
        $('#deleteForm').submit();
    });
});
</script>
</body>
</html>