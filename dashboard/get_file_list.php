<?php
// Initialize the session
session_start();

// Include the database credentials
require_once "/var/www/config/database.php";

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    error_log("User not logged in when accessing get_file_list.php");
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$db_name = $username;

// Create database connection using mysqli with credentials from database.php
$db = new mysqli($db_servername, $db_username, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    die("Database connection failed. Please check the configuration.");
}

// Define the twitch sound alert path
$soundalert_path = "/var/www/soundalerts/" . $username;
$twitch_sound_alert_path = $soundalert_path . "/twitch";

// Fetch sound alert mappings for the current user
$getTwitchAlerts = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
$getTwitchAlerts->execute();
$result = $getTwitchAlerts->get_result();
$soundAlerts = [];
while ($row = $result->fetch_assoc()) { $soundAlerts[] = $row; }
$getTwitchAlerts->close();

// Create an associative array for easy lookup: sound_mapping => twitch_alert_id
$twitchSoundAlertMappings = [];
foreach ($soundAlerts as $alert) {
    $twitchSoundAlertMappings[$alert['sound_mapping']] = $alert['twitch_alert_id'];
}

// Get the sound files
$availableTwitchEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'HypeTrain Start', 'HypeTrain End'];
clearstatcache();
$walkon_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..'));

// HTML output
if (!empty($walkon_files)) : 
    echo '<h1 class="title is-4">Your Twitch Sound Alerts</h1>';
    echo '<form action="module_data_post.php" method="POST" id="deleteForm">';
    echo '<table class="table is-striped" style="width: 100%; text-align: center;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="width: 70px;">Select</th>';
    echo '<th>File Name</th>';
    echo '<th>Twitch Event</th>';
    echo '<th style="width: 100px;">Action</th>';
    echo '<th style="width: 100px;">Test Audio</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($walkon_files as $file) {
        echo '<tr>';
        echo '<td>';
        echo '<input type="checkbox" name="delete_files[]" value="' . htmlspecialchars($file) . '">';
        echo '</td>';
        echo '<td>';
        echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME));
        echo '</td>';
        echo '<td>';
        
        // Determine the current mapped event (if any)
        $current_event = isset($twitchSoundAlertMappings[$file]) ? $twitchSoundAlertMappings[$file] : null;
        $mappedEvents = [];
        foreach ($twitchSoundAlertMappings as $mappedFile => $mappedEvent) {
            if ($mappedFile !== $file && $mappedEvent) {
                $mappedEvents[] = $mappedEvent;
            }
        }
        
        if ($current_event) {
            echo '<em>' . htmlspecialchars($current_event) . '</em>';
        } else {
            echo '<em>Not Mapped</em>';
        }
        echo '<br>';
        
        // Get available events by filtering out already mapped events
        $availableEvents = array_diff($availableTwitchEvents, $mappedEvents);
        
        if (!empty($availableEvents) || $current_event) {
            echo '<form action="module_data_post.php" method="POST" class="mapping-form">';
            echo '<input type="hidden" name="sound_file" value="' . htmlspecialchars($file) . '">';
            echo '<select name="twitch_alert_id" class="mapping-select">';
            echo '<option value="">-- Select Event --</option>';
            
            foreach ($availableEvents as $evt) {
                if ($current_event !== $evt) {
                    echo '<option value="' . htmlspecialchars($evt) . '">' . htmlspecialchars($evt) . '</option>';
                }
            }
            
            echo '</select>';
            echo '</form>';
        } else {
            echo '<em>All events are mapped.</em>';
        }
        
        echo '</td>';
        echo '<td>';
        echo '<button type="button" class="delete-single button is-danger" data-file="' . htmlspecialchars($file) . '">Delete</button>';
        echo '</td>';
        echo '<td>';
        echo '<button type="button" class="test-sound button is-primary" data-file="' . htmlspecialchars($file) . '">Test</button>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<button type="button" id="delete-selected" class="button is-danger">Delete Selected</button>';
    echo '</form>';
else:
    echo '<div class="notification is-info">No sound alert files uploaded yet. Please upload MP3 files to create sound alerts.</div>';
endif;
?>