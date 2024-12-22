<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$title = "YourListOnline - OBS Viewing Options";

// Connect to the primary database
require_once "db_connect.php";

// Fetch the user's data from the primary database based on the access_token
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

// Include the secondary database connection
include 'database.php';

// Retrieve font, color, list, shadow, bold, and font_size data for the user from the showobs table
$stmt = $db->prepare("SELECT * FROM showobs LIMIT 1");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

$font = isset($result['font']) && $result['font'] !== '' ? $result['font'] : 'Not set';
$color = isset($result['color']) && $result['color'] !== '' ? $result['color'] : 'Not set';
if ($color !== 'Black' && $color !== 'White' && $color !== 'Red' && $color !== 'Blue' && $color !== 'Not set') {
    $color = 'Other';
}
$list = isset($result['list']) && $result['list'] !== '' ? $result['list'] : 'Bullet';
$shadow = isset($result['shadow']) && $result['shadow'] == 1 ? true : false;
$bold = isset($result['bold']) && $result['bold'] == 1 ? true : false;
$font_size = isset($result['font_size']) ? $result['font_size'] : '12px';

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
    if ($result) {
        // Update the font, color, list, shadow, bold, and font_size data in the database
        $stmt = $db->prepare("UPDATE showobs SET font = ?, color = ?, list = ?, shadow = ?, bold = ?, font_size = ? LIMIT 1");
        $stmt->bind_param("sssiss", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize);
        if ($stmt->execute()) {
            // Update successful
            header("Location: obs_options.php");
        } else {
            // Display error message
            echo "Error updating settings: " . $stmt->error;
        }
    } else {
        // Insert new settings for the user
        $stmt = $db->prepare("INSERT INTO showobs (font, color, list, shadow, bold, font_size) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiss", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize);
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
        <title><?php echo $title; ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/css/bulma.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <link rel="stylesheet" href="../css/bulma-custom.css">
        <link rel="icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png" type="image/png" />
        <link rel="apple-touch-icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png">
    </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <button type="button" class="button is-link" onclick="showOBSInfo()">HOW TO PUT ON YOUR STREAM</button>
    <br>
    <h3 class="title is-3">Font & Color Settings:</h3>
    <?php if ($font !== 'Not set' || $color !== 'Not set'): ?>
        <table class="table is-fullwidth is-striped">
            <thead>
                <tr>
                    <th>Setting</th>
                    <th>Value</th>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Font</td>
                    <td><?php echo htmlspecialchars($font); ?></td>
                    <td rowspan="6">
                        <form method="post">
                            <div class="field">
                                <label for="font">Font:</label>
                                <div class="control">
                                    <div class="select">
                                        <select name="font">
                                            <option value="Arial"<?php if ($font === 'Arial') echo ' selected'; ?>>Arial</option>
                                            <option value="Arial Narrow"<?php if ($font === 'Arial Narrow') echo ' selected'; ?>>Arial Narrow</option>
                                            <option value="Verdana"<?php if ($font === 'Verdana') echo ' selected'; ?>>Verdana</option>
                                            <option value="Times New Roman"<?php if ($font === 'Times New Roman') echo ' selected'; ?>>Times New Roman</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="color">Color:</label>
                                <div class="control">
                                    <div class="select">
                                        <select name="color" id="color-select">
                                            <option value="Black"<?php if ($color === 'Black') echo ' selected'; ?>>Black</option>
                                            <option value="White"<?php if ($color === 'White') echo ' selected'; ?>>White</option>
                                            <option value="Red"<?php if ($color === 'Red') echo ' selected'; ?>>Red</option>
                                            <option value="Blue"<?php if ($color === 'Blue') echo ' selected'; ?>>Blue</option>
                                            <option value="Other"<?php if ($color === 'Other') echo ' selected'; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="field" id="custom-color-group"<?php if ($color !== 'Other') echo ' style="display: none;"'; ?>>
                                <label for="custom_color">Custom Color:</label>
                                <div class="control">
                                    <input type="text" name="custom_color" id="custom-color-input" class="input" value="<?php echo htmlspecialchars($result['color']); ?>">
                                </div>
                            </div>
                            <div class="field">
                                <label for="list">List Type:</label>
                                <div class="control">
                                    <div class="select">
                                        <select name="list">
                                            <option value="Bullet"<?php if ($list === 'Bullet') echo ' selected'; ?>>Bullet List</option>
                                            <option value="Numbered"<?php if ($list === 'Numbered') echo ' selected'; ?>>Numbered List</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="font_size">Font Size:</label>
                                <div class="control">
                                    <input type="text" name="font_size" value="<?php echo htmlspecialchars($font_size); ?>" class="input">
                                </div>
                            </div>
                            <div class="field">
                                <label for="shadow">Text Shadow:</label>
                                <div class="control">
                                    <input type="checkbox" name="shadow" value="1" <?php if ($shadow) echo 'checked'; ?>>
                                </div>
                            </div>
                            <div class="field">
                                <label for="bold">Text Bold:</label>
                                <div class="control">
                                    <input type="checkbox" name="bold" value="1" <?php if ($bold) echo 'checked'; ?>>
                                </div>
                            </div>
                            <div class="field">
                                <div class="control">
                                    <button type="submit" class="button is-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </td>
                </tr>
                <tr>
                    <td>Color</td>
                    <td>
                        <span style="display:inline-block;width:18px;height:18px;background-color:<?php echo htmlspecialchars($result['color']); ?>;margin-right:3px; solid #000;vertical-align:middle;"></span>
                        <span style="vertical-align:middle;"><?php echo htmlspecialchars($result['color']); ?></span>
                    </td>
                </tr>
                <tr>
                    <td>List Type</td>
                    <td><?php echo htmlspecialchars($list); ?></td>
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
                    <td><?php echo htmlspecialchars($font_size); ?>px</td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <div class="notification is-info">
            <div class="columns is-vcentered">
                <div class="column is-narrow">
                    <span class="icon is-large">
                        <i class="fas fa-palette fa-2x"></i> 
                    </span>
                </div>
                <div class="column">
                    <p><strong>Customize your lists!</strong></p>
                    <p>No font and color settings have been set.  Use the controls below to personalize the look of your lists.</p> 
                </div>
            </div>
        </div>
    <?php endif; ?>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    var customColorInput = document.getElementById('custom-color-input');
    var colorGroup = document.getElementById('custom-color-group');

    customColorInput.addEventListener('input', function() {
        if (customColorInput.value && !customColorInput.value.startsWith('#')) {
            customColorInput.value = '#' + customColorInput.value;
        }
    });

    // Show custom color input if a custom color is selected
    if (customColorInput.value && customColorInput.value.startsWith('#')) {
        colorGroup.style.display = 'block';
    }
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="../js/about.js" defer></script>
<script src="../js/obsinfo.js" defer></script>
</body>
</html>