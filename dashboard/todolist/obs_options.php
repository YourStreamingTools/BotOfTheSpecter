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
$stmt = $db->prepare("SELECT * FROM showobs WHERE user_id = ?");
$stmt->bindParam(1, $user_id, PDO::PARAM_INT);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

$font = isset($result['font']) && $result['font'] !== '' ? $result['font'] : 'Not set';
$color = isset($result['color']) && $result['color'] !== '' ? $result['color'] : 'Not set';
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
        $stmt = $db->prepare("UPDATE showobs SET font = ?, color = ?, list = ?, shadow = ?, bold = ?, font_size = ? WHERE user_id = ?");
        $stmt->bindParam(1, $selectedFont, PDO::PARAM_STR);
        $stmt->bindParam(2, $selectedColor, PDO::PARAM_STR);
        $stmt->bindParam(3, $selectedList, PDO::PARAM_STR);
        $stmt->bindParam(4, $selectedShadow, PDO::PARAM_INT);
        $stmt->bindParam(5, $selectedBold, PDO::PARAM_INT);
        $stmt->bindParam(6, $selectedFontSize, PDO::PARAM_STR);
        $stmt->bindParam(7, $user_id, PDO::PARAM_INT);
        if ($stmt->execute()) {
            // Update successful
            header("Location: obs_options.php");
        } else {
            // Display error message
            $errorInfo = $stmt->errorInfo();
            echo "Error updating settings: " . $errorInfo[2];
        }
    } else {
        // Insert new settings for the user
        $stmt = $db->prepare("INSERT INTO showobs (user_id, font, color, list, shadow, bold, font_size) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $selectedFont, PDO::PARAM_STR);
        $stmt->bindParam(3, $selectedColor, PDO::PARAM_STR);
        $stmt->bindParam(4, $selectedList, PDO::PARAM_STR);
        $stmt->bindParam(5, $selectedShadow, PDO::PARAM_INT);
        $stmt->bindParam(6, $selectedBold, PDO::PARAM_INT);
        $stmt->bindParam(7, $selectedFontSize, PDO::PARAM_STR);
        if ($stmt->execute()) {
            // Insertion successful
            header("Location: obs_options.php");
        } else {
            // Display error message
            $errorInfo = $stmt->errorInfo();
            echo "Error inserting settings: " . $errorInfo[2];
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
        <link rel="icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png" type="image/png" />
        <link rel="apple-touch-icon" href="https://yourlistonline.yourcdnonline.com/img/logo.png">
    </head>
<body>
<!-- Navigation -->
<nav class="navbar is-spaced" role="navigation" aria-label="main navigation">
    <div class="navbar-brand">
        <a class="navbar-item" href="dashboard.php">
            YourListOnline
        </a>
        <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasicExample">
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </a>
    </div>
    <div id="navbarBasicExample" class="navbar-menu">
        <div class="navbar-start">
            <a class="navbar-item" href="dashboard.php">Dashboard</a>
            <a class="navbar-item" href="insert.php">Add</a>
            <a class="navbar-item" href="remove.php">Remove</a>
            <div class="navbar-item has-dropdown is-hoverable">
                <a class="navbar-link">Update</a>
                <div class="navbar-dropdown">
                    <a class="navbar-item" href="update_objective.php">Update Objective</a>
                    <a class="navbar-item" href="update_category.php">Update Objective Category</a>
                </div>
            </div>
            <a class="navbar-item" href="completed.php">Completed</a>
            <div class="navbar-item has-dropdown is-hoverable">
                <a class="navbar-link">Categories</a>
                <div class="navbar-dropdown">
                    <a class="navbar-item" href="categories.php">View Categories</a>
                    <a class="navbar-item" href="add_category.php">Add Category</a>
                </div>
            </div>
            <div class="navbar-item has-dropdown is-hoverable">
                <a class="navbar-link">Profile</a>
                <div class="navbar-dropdown">
                    <a class="navbar-item is-active" href="obs_options.php">OBS Viewing Options</a>
                </div>
            </div>
        </div>
        <div class="navbar-end">
            <div class="navbar-item">
                <button id="dark-mode-toggle" class="button is-dark"><i class="icon-toggle-dark-mode"></i></button>
            </div>
            <div class="navbar-item">
                <a class="popup-link" onclick="showPopup()">&copy; 2023 YourListOnline. All rights reserved.</a>
            </div>
        </div>
    </div>
</nav>
<!-- /Navigation -->

<div class="container">
<br>
<h1 class="title"><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
<br>
<button type="button" class="button is-link" onclick="showOBSInfo()">HOW TO PUT ON YOUR STREAM</button>
<br>
<h3 class="title is-3">Font & Color Settings:</h3>
<?php if ($font !== 'Not set' || $color !== 'Not set') { ?>
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
                        <label class="label" for="font">Font:</label>
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
                        <label class="label" for="color">Color:</label>
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
                        <label class="label" for="custom_color">Custom Color:</label>
                        <div class="control">
                            <input type="text" name="custom_color" id="custom-color-input" class="input">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="list">List Type:</label>
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
                        <label class="label" for="font_size">Font Size:</label>
                        <div class="control">
                            <input type="text" name="font_size" value="<?php echo htmlspecialchars($font_size); ?>" class="input">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="shadow">Text Shadow:</label>
                        <div class="control">
                            <input type="checkbox" name="shadow" value="1" <?php if ($shadow) echo 'checked'; ?>>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="bold">Text Bold:</label>
                        <div class="control">
                            <input type="checkbox" name="bold" value="1" <?php if ($bold) echo 'checked'; ?>>
                        </div>
                    </div>
                    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
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
            <td><?php echo htmlspecialchars($color); ?></td>
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
<?php } else { echo '<p>No font and color settings have been set.</p>'; } ?>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/profile.js"></script>
<script src="https://yourlistonline.yourcdnonline.com/js/about.js" defer></script>
<script src="https://yourlistonline.yourcdnonline.com/js/obsbutton.js" defer></script>
</body>
</html>