<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$title = "Bot Points Management";

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
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $point_name = $_POST['point_name'];
    $point_amount_chat = $_POST['point_amount_chat'];
    $point_amount_follower = $_POST['point_amount_follower'];
    $point_amount_subscriber = $_POST['point_amount_subscriber'];
    $point_amount_cheer = $_POST['point_amount_cheer'];
    $point_amount_raid = $_POST['point_amount_raid'];
    $subscriber_multiplier = $_POST['subscriber_multiplier'];
    $excluded_users = $_POST['excluded_users'];

    $updateStmt = $db->prepare("UPDATE bot_settings SET 
        point_name = ?, 
        point_amount_chat = ?, 
        point_amount_follower = ?, 
        point_amount_subscriber = ?, 
        point_amount_cheer = ?, 
        point_amount_raid = ?, 
        subscriber_multiplier = ?, 
        excluded_users = ?
    WHERE id = 1");

    $updateStmt->execute([
        $point_name, 
        $point_amount_chat, 
        $point_amount_follower, 
        $point_amount_subscriber, 
        $point_amount_cheer, 
        $point_amount_raid, 
        $subscriber_multiplier,
        $excluded_users
    ]);
    $status = "Settings updated successfully!";
}

$settingsStmt = $db->prepare("SELECT * FROM bot_settings WHERE id = 1");
$settingsStmt->execute();
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$pointsName = htmlspecialchars($settings['point_name']);
$excludedUsers = htmlspecialchars($settings['excluded_users']);

// Fetch users and their points from bot_points table
$pointsStmt = $db->prepare("SELECT user_name, points FROM bot_points ORDER BY points DESC");
$pointsStmt->execute();
$pointsData = $pointsStmt->fetchAll(PDO::FETCH_ASSOC);

// If requested via AJAX, return the JSON data
if (isset($_GET['action']) && $_GET['action'] == 'get_points_data') {
    echo json_encode($pointsData);
    exit();
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
    <!-- Settings Button -->
    <?php if ($status): ?>
        <div class="notification is-success" style="background-color: #4CAF50; color: #ffffff;"><?php echo $status; ?></div>
    <?php endif; ?>
    <button class="button is-primary" id="settingsButton">Settings</button>
    <!-- Points Table -->
    <h2 class="subtitle">User Points</h2>
    <p id="updateInfo">Data last updated: <span id="secondsAgo">0</span> seconds ago.</p>
    <table class="table is-fullwidth is-striped">
        <thead>
            <tr>
                <th>Username</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody id="pointsTableBody">
            <?php foreach ($pointsData as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['points']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div class="modal" id="settingsModal">
    <div class="modal-background"></div>
    <div class="modal-card" style="background-color: #2b2b2b;">
        <header class="modal-card-head" style="background-color: #1a1a1a; color: #ffffff;">
            <p class="modal-card-title" style="color: #ffffff;">Points System Settings</p>
            <button class="delete" aria-label="close" id="closeModal" style="color: #ffffff;"></button>
        </header>
        <section class="modal-card-body" style="background-color: #2b2b2b; color: #ffffff;">
            <form method="POST" action="">
                <div class="field">
                    <label for="point_name" style="color: #ffffff;">Points Name</label>
                    <div class="control">
                        <input class="input" type="text" name="point_name" value="<?php echo $pointsName; ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                </div>
                <div class="field">
                    <label for="point_amount_chat" style="color: #ffffff;"><?php echo $pointsName; ?> Earned per Chat Message</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_chat" value="<?php echo htmlspecialchars($settings['point_amount_chat']); ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                </div>
                <div class="field">
                    <label for="point_amount_follower" style="color: #ffffff;"><?php echo $pointsName; ?> Earned for Following</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_follower" value="<?php echo htmlspecialchars($settings['point_amount_follower']); ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                </div>
                <div class="field">
                    <label for="point_amount_subscriber" style="color: #ffffff;"><?php echo $pointsName; ?> Earned for Subscribing</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_subscriber" value="<?php echo htmlspecialchars($settings['point_amount_subscriber']); ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                </div>
                <div class="field">
                    <label for="point_amount_cheer" style="color: #ffffff;"><?php echo $pointsName; ?> Earned Per Cheer</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_cheer" value="<?php echo htmlspecialchars($settings['point_amount_cheer']); ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                </div>
                <div class="field">
                    <label for="point_amount_raid" style="color: #ffffff;"><?php echo $pointsName; ?> Earned Per Raid Viewer</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_raid" value="<?php echo htmlspecialchars($settings['point_amount_raid']); ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                </div>
                <div class="field">
                    <label for="subscriber_multiplier" style="color: #ffffff;">Subscriber Multiplier</label>
                    <div class="control">
                        <div class="select is-fullwidth" style="background-color: #3a3a3a; color: #ffffff; border: none;">
                            <select name="subscriber_multiplier" style="background-color: #3a3a3a; color: #ffffff; border: none;">
                                <option value="0" <?php echo $settings['subscriber_multiplier'] == 0 ? 'selected' : ''; ?>>None</option>
                                <option value="2" <?php echo $settings['subscriber_multiplier'] == 2 ? 'selected' : ''; ?>>2x</option>
                                <option value="3" <?php echo $settings['subscriber_multiplier'] == 3 ? 'selected' : ''; ?>>3x</option>
                                <option value="4" <?php echo $settings['subscriber_multiplier'] == 4 ? 'selected' : ''; ?>>4x</option>
                                <option value="5" <?php echo $settings['subscriber_multiplier'] == 5 ? 'selected' : ''; ?>>5x</option>
                                <option value="6" <?php echo $settings['subscriber_multiplier'] == 6 ? 'selected' : ''; ?>>6x</option>
                                <option value="7" <?php echo $settings['subscriber_multiplier'] == 7 ? 'selected' : ''; ?>>7x</option>
                                <option value="8" <?php echo $settings['subscriber_multiplier'] == 8 ? 'selected' : ''; ?>>8x</option>
                                <option value="9" <?php echo $settings['subscriber_multiplier'] == 9 ? 'selected' : ''; ?>>9x</option>
                                <option value="10" <?php echo $settings['subscriber_multiplier'] == 10 ? 'selected' : ''; ?>>10x</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="excluded_users" style="color: #ffffff;">Excluded Users (comma-separated)</label>
                    <div class="control">
                        <input class="input" type="text" name="excluded_users" value="<?php echo $excludedUsers; ?>" required style="background-color: #3a3a3a; color: #ffffff; border: none;">
                    </div>
                    <p class="help" style="color: #ffffff;">By default, both the bot and yourself are excluded.</p>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Update Settings</button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
    let secondsAgo = 0;

    // Function to update the table with new data
    function updatePointsTable() {
        $.ajax({
            url: '?action=get_points_data',
            method: 'GET',
            success: function(data) {
                const pointsData = JSON.parse(data);
                let tableBody = '';
                pointsData.forEach(function(row) {
                    tableBody += `<tr><td>${row.user_name}</td><td>${row.points}</td></tr>`;
                });
                $('#pointsTableBody').html(tableBody);
                secondsAgo = 0; // Reset the seconds counter after each update
            }
        });
    }

    // Update the seconds ago counter
    function updateSecondsAgo() {
        secondsAgo++;
        $('#secondsAgo').text(secondsAgo);
    }
    // Initial table update
    updatePointsTable();
    setInterval(updatePointsTable, 30000); // 30000 ms = 30 seconds
    setInterval(updateSecondsAgo, 1000); // 1000 ms = 1 second

    // Modal Script
    document.getElementById('settingsButton').addEventListener('click', function() {
        document.getElementById('settingsModal').classList.add('is-active');
    });
    document.getElementById('closeModal').addEventListener('click', function() {
        document.getElementById('settingsModal').classList.remove('is-active');
    });
    document.querySelector('.modal-background').addEventListener('click', function() {
        document.getElementById('settingsModal').classList.remove('is-active');
    });
</script>
</body>
</html>