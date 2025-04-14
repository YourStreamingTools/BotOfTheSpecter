<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Counters";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$status = '';
$notification_status = '';

// Fetch usernames from the user_typos table
try {
    $stmt = $db->prepare("SELECT username FROM user_typos");
    $stmt->execute();
    $usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $status = "Error fetching usernames: " . $e->getMessage();
    $notification_status = "is-danger";
    $usernames = [];
}

// Fetch commands from the custom_counts table
try {
    $stmt = $db->prepare("SELECT command FROM custom_counts");
    $stmt->execute();
    $commands = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $status = "Error fetching commands: " . $e->getMessage();
    $notification_status = "is-danger";
    $commands = [];
}

// Handling form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'update': 
            $formUsername = $_POST['typo-username'] ?? '';
            $typoCount = $_POST['typo_count'] ?? '';
            $formCommand = $_POST['command'] ?? '';
            $commandCount = $_POST['command_count'] ?? '';
            // Update typo count
            if ($formUsername && is_numeric($typoCount)) {
                try {
                    $stmt = $db->prepare("UPDATE user_typos SET typo_count = :typo_count WHERE username = :username");
                    $stmt->bindParam(':username', $formUsername);
                    $stmt->bindParam(':typo_count', $typoCount, PDO::PARAM_INT);
                    $stmt->execute();
                    $status = "Typo count updated successfully for user {$formUsername}.";
                    $notification_status = "is-success";
                } catch (PDOException $e) {
                    $status = "Error: " . $e->getMessage();
                    $notification_status = "is-danger";
                }
            }
            // Update command count
            if ($formCommand && is_numeric($commandCount)) {
                try {
                    $stmt = $db->prepare("UPDATE custom_counts SET count = :command_count WHERE command = :command");
                    $stmt->bindParam(':command', $formCommand);
                    $stmt->bindParam(':command_count', $commandCount, PDO::PARAM_INT);
                    $stmt->execute();
                    $status = "Count updated successfully for the command {$formCommand}.";
                    $notification_status = "is-success";
                } catch (PDOException $e) {
                    $status = "Error: " . $e->getMessage();
                    $notification_status = "is-danger";
                }
            }
            break;
        case 'remove':
            $formUsername = $_POST['typo-username-remove'] ?? '';
            // Remove typo record
            if ($formUsername) {
                try {
                    $stmt = $db->prepare("DELETE FROM user_typos WHERE username = :username");
                    $stmt->bindParam(':username', $formUsername, PDO::PARAM_STR);
                    $stmt->execute();
                    $status = "Typo record for user '$formUsername' has been removed.";
                    $notification_status = "is-success";
                } catch (PDOException $e) {
                    $status = 'Error: ' . $e->getMessage();
                    $notification_status = "is-danger";
                }
            } else {
                $status = "Invalid input.";
                $notification_status = "is-danger";
            }
            break;
        default:
            $status = "Invalid action.";
            $notification_status = "is-danger";
            break;
    }
}

// Fetch usernames and their current typo counts
try {
    $stmt = $db->prepare("SELECT username, typo_count FROM user_typos");
    $stmt->execute();
    $typoData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status = "Error fetching typo data: " . $e->getMessage();
    $notification_status = "is-danger";
    $typoData = [];
}

// Fetch command counts
try {
    $stmt = $db->prepare("SELECT command, count FROM custom_counts");
    $stmt->execute();
    $commandData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status = "Error fetching data: " . $e->getMessage();
    $notification_status = "is-danger";
    $commandData = [];
}

// Check for AJAX requests
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'get_typo_count' && isset($_GET['username'])) {
        $requestedUsername = $_GET['username'];
        try {
            $stmt = $db->prepare("SELECT typo_count FROM user_typos WHERE username = :username");
            $stmt->bindParam(':username', $requestedUsername);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $result['typo_count'] ?? "0";
        } catch (PDOException $e) {
            echo "Error: " . $e->getMessage();
        }
    } elseif ($_GET['action'] == 'get_command_count' && isset($_GET['command'])) {
        $requestedCommand = $_GET['command'];
        try {
            $stmt = $db->prepare("SELECT count FROM custom_counts WHERE command = :command");
            $stmt->bindParam(':command', $requestedCommand);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $result['count'] ?? "0";
        } catch (PDOException $e) {
            echo "Error: " . $e->getMessage();
        }
    }
    exit;
}

// Prepare a JavaScript object with Typo Counts & Command Counts for each user
$commandCountsJs = json_encode(array_column($commandData, 'count', 'command'));
$typoCountsJs = json_encode(array_column($typoData, 'typo_count', 'username'));
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
    <h4 class="title is-4">Edit and Manage Bot Counters</h4>
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
        <div class="notification <?php echo $notification_status; ?>"><?php echo $status; ?></div>
    <?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-3 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-5">Edit User Typos</h2>
            <form action="" method="post">
                <input type="hidden" name="action" value="update">
                <div class="field">
                    <label for="typo-username">Username:</label>
                    <div class="control">
                        <div class="select">
                            <select id="typo-username" name="typo-username" required onchange="updateCurrentCount('typo', this.value)">
                                <option value="">Select a user</option>
                                <?php foreach ($usernames as $typo_name): ?>
                                    <option value="<?php echo htmlspecialchars($typo_name); ?>"><?php echo htmlspecialchars($typo_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="typo_count">New Typo Count:</label>
                    <div class="control">
                        <input class="input" type="number" id="typo_count" name="typo_count" value="" required min="0">
                    </div>
                </div>
                <div class="control"><button type="submit" class="button is-primary">Update Typo Count</button></div>
            </form>
        </div>
        <div class="column is-3 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-5">Remove User Typo Record</h2>
            <form action="" method="post">
                <input type="hidden" name="action" value="remove">
                <div class="field">
                    <label for="typo-username-remove">Username:</label>
                    <div class="control">
                        <div class="select">
                            <select id="typo-username-remove" name="typo-username-remove" required>
                                <option value="">Select a user</option>
                                <?php foreach ($usernames as $typo_name): ?>
                                    <option value="<?php echo htmlspecialchars($typo_name); ?>"><?php echo htmlspecialchars($typo_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="control"><button type="submit" class="button is-danger">Remove Typo Record</button></div>
            </form>
        </div>
        <div class="column is-3 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-5">Edit Custom Counter</h2>
            <form action="" method="post">
                <input type="hidden" name="action" value="update">
                <div class="field">
                    <label for="command">Command:</label>
                    <div class="control">
                        <div class="select">
                            <select id="command" name="command" required onchange="updateCurrentCount('command', this.value)">
                                <option value="">Select a command</option>
                                <?php foreach ($commands as $command): ?>
                                    <option value="<?php echo htmlspecialchars($command); ?>"><?php echo htmlspecialchars($command); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="command_count">New Command Count:</label>
                    <div class="control">
                        <input class="input" type="number" id="command_count" name="command_count" value="" min="0" required>
                    </div>
                </div>
                <div class="control"><button type="submit" class="button is-primary">Update Command Count</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
function fetchCurrentCount(type, value, inputId) {
    if (value) {
        const param = type === 'typo' ? 'username' : 'command';
        fetch(`?action=get_${type}_count&${param}=` + encodeURIComponent(value))
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.text();
            })
            .then(data => {
                const inputField = document.getElementById(inputId);
                if (inputField && data) {
                    inputField.value = data;
                } else {
                    console.error('No data returned from server for type:', type, 'value:', value);
                    inputField.value = 0;
                }
            })
            .catch(error => console.error('Error fetching count:', error));
    } else {
        document.getElementById(inputId).value = '';
    }
}

function updateCurrentCount(type, value) {
    const inputId = type === 'typo' ? 'typo_count' : 'command_count';
    fetchCurrentCount(type, value, inputId);
}
</script>
</body>
</html>