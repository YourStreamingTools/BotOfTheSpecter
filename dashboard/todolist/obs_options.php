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
$title = "YourListOnline - OBS Viewing Options";

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

// Retrieve font, color, list, shadow, bold, and font_size data for the user from the showobs table
$stmt = $conn->prepare("SELECT * FROM showobs WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();
// Retrieve font, color, list, shadow, bold, and font_size data for the user from the showobs table
$font = isset($settings['font']) && $settings['font'] !== '' ? $settings['font'] : 'Not set';
$color = isset($settings['color']) && $settings['color'] !== '' ? $settings['color'] : 'Not set';
$list = isset($settings['list']) && $settings['list'] !== '' ? $settings['list'] : 'Bullet';
$shadow = isset($settings['shadow']) && $settings['shadow'] == 1 ? true : false;
$bold = isset($settings['bold']) && $settings['bold'] == 1 ? true : false;
$font_size = isset($settings['font_size']) ? $settings['font_size'] : '12px';

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate and sanitize the input
    $selectedFont = isset($_POST["font"]) ? $_POST["font"] : '';
    $selectedColor = isset($_POST["color"]) ? $_POST["color"] : '';
    $selectedList = isset($_POST["list"]) ? $_POST["list"] : 'Bullet';
    $selectedShadow = isset($_POST["shadow"]) ? 1 : 0;
    $selectedBold = isset($_POST["bold"]) ? 1 : 0;
    $selectedFontSize = isset($_POST["font_size"]) ? $_POST["font_size"] : '12px';

    // Check if the user has selected "Other" color option
    if ($selectedColor === 'Other') {
        $customColor = isset($_POST["custom_color"]) ? $_POST["custom_color"] : '';
        if (!empty($customColor)) {
            $selectedColor = $customColor;
        }
    }

    // Check if the user has existing settings
    if ($result->num_rows > 0) {
        // Update the font, color, list, shadow, bold, and font_size data in the database
        $stmt = $conn->prepare("UPDATE showobs SET font = ?, color = ?, list = ?, shadow = ?, bold = ?, font_size = ? WHERE user_id = ?");
        $stmt->bind_param("sssiiis", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize, $user_id);
        if ($stmt->execute()) {
            // Update successful
            header("Location: obs_options.php");
        } else {
            // Display error message
            echo "Error updating settings: " . $stmt->error;
        }
    } else {
        // Insert new settings for the user
        $stmt = $conn->prepare("INSERT INTO showobs (user_id, font, color, list, shadow, bold, font_size) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssiiis", $user_id, $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize);
        if ($stmt->execute()) {
            // Insertion successful
            header("Location: obs_options.php");
        } else {
            // Display error message
            echo "Error inserting settings: " . $stmt->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title></title>
        <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
        <link rel="stylesheet" href="https://yourlistonline.yourcdnonline.com/css/custom.css">
        <script src="https://yourlistonline.yourcdnonline.com/js/about.js"></script>
        <link rel="icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png" type="image/png" />
        <link rel="apple-touch-icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png">
    </head>
<body>
<!-- Navigation -->
<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
    <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
    <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
    <div class="top-bar-left">
        <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
            <li class="menu-text menu-text-black">YourListOnline</li>
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="insert.php">Add</a></li>
            <li><a href="remove.php">Remove</a></li>
            <li>
                <a>Update</a>
                <ul class="vertical menu" data-dropdown-menu>
                    <li><a href="update_objective.php">Update Objective</a></li>
                    <li><a href="update_category.php">Update Objective Category</a></li>
                </ul>
            </li>
            <li><a href="completed.php">Completed</a></li>
            <li>
                <a>Categories</a>
                <ul class="vertical menu" data-dropdown-menu>
                    <li><a href="categories.php">View Categories</a></li>
                    <li><a href="add_category.php">Add Category</a></li>
                </ul>
            </li>
            <li>
                <a>Profile</a>
                <ul class="vertical menu" data-dropdown-menu>
                    <li><a href="obs_options.php">OBS Viewing Options</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </li>
            <?php if ($is_admin) { ?>
                <li>
                <a>Admins</a>
                <ul class="vertical menu" data-dropdown-menu>
                    <li><a href="../admins/dashboard.php" target="_self">Admin Dashboard</a></li>
                </ul>
            </li>
            <?php } ?>
        </ul>
    </div>
    <div class="top-bar-right">
        <ul class="menu">
            <li><button id="dark-mode-toggle"><i class="icon-toggle-dark-mode"></i></button></li>
            <li><a class="popup-link" onclick="showPopup()">&copy; 2023 YourListOnline. All rights reserved.</a></li>
        </ul>
    </div>
</nav>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
<br>
<button type="button" class="defult-button" onclick="showOBSInfo()">HOW TO PUT ON YOUR STREAM</button>
<br>
<h3>Font & Color Settings:</h3>
<?php if ($font !== '' || $color !== '') { ?>
<table class="dark-mode-table">
    <tr>
        <th style="width: 15%; height: 20%;">Setting</th>
        <th style="width: 25%; height: 20%;">Value</th>
        <th style="width: 60%;">Update</th>
    </tr>
    <tr>
        <td>Font</td>
        <td><?php echo $font; ?></td>
        <td rowspan="6">
            <form method="post">
                <div class="form-group">
                    <label for="font">Font:</label>
                    <select name="font" class="form-control">
                        <!-- Font options -->
                        <option value="Arial"<?php if ($font === 'Arial') echo ' selected'; ?>>Arial</option>
                        <option value="Arial Narrow"<?php if ($font === 'Arial Narrow') echo ' selected'; ?>>Arial Narrow</option>
                        <option value="Verdana"<?php if ($font === 'Verdana') echo ' selected'; ?>>Verdana</option>
                        <option value="Times New Roman"<?php if ($font === 'Times New Roman') echo ' selected'; ?>>Times New Roman</option>
                    </select>
                    <?php if ($font === '') echo '<p class="text-danger">Please select a font.</p>'; ?>
                </div>
                <div class="form-group">
                    <label for="color">Color:</label>
                    <select name="color" id="color-select" class="form-control">
                        <!-- Color options -->
                        <option value="Black"<?php if ($color === 'Black') echo ' selected'; ?>>Black</option>
                        <option value="White"<?php if ($color === 'White') echo ' selected'; ?>>White</option>
                        <option value="Red"<?php if ($color === 'Red') echo ' selected'; ?>>Red</option>
                        <option value="Blue"<?php if ($color === 'Blue') echo ' selected'; ?>>Blue</option>
                        <option value="Other"<?php if ($color === 'Other') echo ' selected'; ?>>Other</option>
                    </select>
                    <?php if ($color === '') echo '<p class="text-danger">Please select a color.</p>'; ?>
                </div>
                <div class="form-group" id="custom-color-group"<?php if ($color !== 'Other') echo ' style="display: none;"'; ?>>
                    <label for="custom_color">Custom Color:</label>
                    <input type="text" name="custom_color" id="custom-color-input" class="form-control">
                </div>
                <div class="form-group">
                    <label for="list">List Type:</label>
                    <select name="list" class="form-control">
                        <!-- List type options -->
                        <option value="Bullet"<?php if ($list === 'Bullet') echo ' selected'; ?>>Bullet List</option>
                        <option value="Numbered"<?php if ($list === 'Numbered') echo ' selected'; ?>>Numbered List</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="font_size">Font Size:</label>
                    <input type="text" name="font_size" value="<?php echo $font_size; ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label for="shadow">Text Shadow:</label>
                    <input type="checkbox" name="shadow" value="1" <?php if ($shadow) echo 'checked'; ?>>
                </div>
                <div class="form-group">
                    <label for="bold">Text Bold:</label>
                    <input type="checkbox" name="bold" value="1" <?php if ($bold) echo 'checked'; ?>>
                </div>
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                <input type="submit" value="Save" class="save-button">
            </form>
        </td>
    </tr>
    <tr>
        <td>Color</td>
        <td><?php echo $color; ?></td>
    </tr>
    <tr>
        <td>List Type</td>
        <td><?php echo $list; ?></td>
    </tr>
    <tr>
        <td>Text Shadow</td>
        <td><?php echo $shadow ? 'Enabled' : 'Disabled'; ?></td>
    </tr>
    <tr>
        <td>Text Bold</td>
        <td><?php echo $bold ? 'Enabled' : 'Disabled'; ?></td>
    </tr>
    <tr>
        <td>Font Size</td>
        <td><?php echo $font_size; ?>px</td>
    </tr>
</table>
<?php } else { echo 'No font and color settings have been set.'; } ?>
</div>
<script>
    // Get references to the select element and custom color group
    var colorSelect = document.getElementById("color-select");
    var customColorGroup = document.getElementById("custom-color-group");

    // Add event listener to toggle custom color group visibility
    colorSelect.addEventListener("change", function() {
        if (colorSelect.value === "Other") {
            customColorGroup.style.display = "block";
        } else {
            customColorGroup.style.display = "none";
        }
    });
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/darkmode.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/profile.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/about.js" defer></script>
<script src="https://yourlistonline.yourcdnonline.com/js/obsbutton.js" defer></script>
<script>$(document).foundation();</script>
</body>
</html>