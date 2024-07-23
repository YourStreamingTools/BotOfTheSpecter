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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_dir($walkon_path)) {
        if (!mkdir($walkon_path, 0755, true)) {
            exit("Failed to create directory.");
        }
    }

    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $targetFile = $walkon_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        if ($fileType != "mp3") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 files are allowed.<br>";
            continue;
        }

        if (move_uploaded_file($tmp_name, $targetFile)) {
            $status .= "The file " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . " has been uploaded.<br>";
        } else {
            $status .= "Sorry, there was an error uploading " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ".<br>";
        }
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
</head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <h1 class="title is-4">Upload Walkons</h1>
    <div class="upload-container">
        <?php if (!empty($status)) : ?>
            <div class="message"><?php echo $status; ?></div>
        <?php endif; ?>
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="drag-area" id="drag-area">
                Drag & Drop files here or
                <label for="filesToUpload">Browse Files</label>
                <input type="file" name="filesToUpload[]" id="filesToUpload" multiple>
            </div>
            <input type="submit" value="Upload MP3 Files" name="submit">
        </form>
    </div>
    <div class="container">
        <h1 class="title is-4">Users with Walkons</h1>
        <?php foreach ($walkon_files as $file): ?><li><?php echo htmlspecialchars(formatFileName($file)); ?></li><?php endforeach; ?>
    </div>
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
});
</script>
</body>
</html>