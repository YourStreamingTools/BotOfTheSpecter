<?php 
// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: login.php');
  exit();
}

// Page Title and Initial Variables
$title = "Streaming Settings";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include "/var/www/config/ssh.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $twitch_key = $_POST['twitch_key'];
    $forward_to_twitch = isset($_POST['forward_to_twitch']) ? 1 : 0;

    // Update the database with the new settings
    $stmt = $db->prepare("INSERT INTO streaming_settings (id, twitch_key, forward_to_twitch) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE twitch_key = VALUES(twitch_key), forward_to_twitch = VALUES(forward_to_twitch)");
    if ($stmt === false) {
        die('Prepare failed: ' . htmlspecialchars($db->error));
    }
    $stmt->bindValue(1, $twitch_key, PDO::PARAM_STR);
    $stmt->bindValue(2, $forward_to_twitch, PDO::PARAM_INT);
    if ($stmt->execute() === false) {
        die('Execute failed: ' . htmlspecialchars($stmt->errorInfo()[2]));
    }
    $stmt->closeCursor();
    // Set session variable to indicate success
    $_SESSION['settings_saved'] = true;
    header('Location: streaming.php');
    exit();
}

// Fetch current settings
$result = $db->query("SELECT twitch_key, forward_to_twitch FROM streaming_settings WHERE id = 1");
if ($result === false) {
    die('Query failed: ' . htmlspecialchars($db->error));
}
$current_settings = $result->fetch(PDO::FETCH_ASSOC);
$twitch_key = $current_settings['twitch_key'] ?? '';
$forward_to_twitch = $current_settings['forward_to_twitch'] ?? 1;

// Function to get files from the storage server
function getStorageFiles($server_host, $server_username, $server_password, $user_dir) {
    $files = [];
    // Check if SSH2 extension is available
    if (!function_exists('ssh2_connect')) {
        return ['error' => 'SSH2 extension not installed on the server.'];
    }
    // Connect to the server
    $connection = @ssh2_connect($server_host, 22);
    if (!$connection) {
        return ['error' => 'Could not connect to the storage server.'];
    }
    // Authenticate
    if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
        return ['error' => 'Authentication failed.'];
    }
    // Create SFTP session
    $sftp = @ssh2_sftp($connection);
    if (!$sftp) {
        return ['error' => 'Could not initialize SFTP subsystem.'];
    }
    // Check if the directory exists
    $dir_path = "/root/$user_dir/";
    $sftp_stream = @opendir("ssh2.sftp://" . intval($sftp) . $dir_path);
    if (!$sftp_stream) {
        return ['error' => "Directory $dir_path not found or not accessible."];
    }
    // List files
    while (($file = readdir($sftp_stream)) !== false) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'mp4') {
            // Get file stats
            $stat = ssh2_sftp_stat($sftp, $dir_path . $file);
            // Format file size
            $size_bytes = $stat['size'];
            $size = $size_bytes < 1024*1024 ? round($size_bytes/1024, 2).' KB' : 
                   ($size_bytes < 1024*1024*1024 ? round($size_bytes/(1024*1024), 2).' MB' : 
                   round($size_bytes/(1024*1024*1024), 2).' GB');
            // Format date
            $date = date('d-m-Y', $stat['mtime']);
            // We can't easily get video duration via SSH, so leaving as placeholder
            $duration = "N/A"; // Would require specific tools on the server
            $files[] = [
                'name' => $file,
                'date' => $date,
                'size' => $size,
                'duration' => $duration,
                'path' => $dir_path . $file
            ];
        }
    }
    closedir($sftp_stream);
    return $files;
}

// Get files when the server is selected
$storage_files = [];
$storage_error = null;

// Server selection handling (default to AU SYD 1)
$selected_server = isset($_GET['server']) ? $_GET['server'] : 'au-syd-1';

if ($selected_server == 'au-syd-1') {
    // Only try to fetch files if the credentials are set
    if (!empty($storage_server_au_syd1_host) && !empty($storage_server_au_syd1_username) && !empty($storage_server_au_syd1_password)) {
        $result = getStorageFiles(
            $storage_server_au_syd1_host, 
            $storage_server_au_syd1_username, 
            $storage_server_au_syd1_password, 
            $username
        );
        if (isset($result['error'])) {
            $storage_error = $result['error'];
        } else {
            $storage_files = $result;
        }
    } else {
        $storage_error = "Server connection information not configured.";
    }
}
?>
<!doctype html>
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
    <div class="notification is-primary">
        <p class="has-text-weight-bold has-text-black">Complementary Streaming Service</p>
        <p class="has-text-black">This streaming feature is provided as a complementary service for all Specter users. You have multiple options:</p>
        <ul>
            <li class="has-text-black">Record your streams and simultaneously forward them to Twitch</li>
            <li class="has-text-black">Use our service as a secondary output for your streams via multi-streaming</li>
        </ul>
        <p class="has-text-black">The choice is yours on how to utilize this feature to enhance your streaming experience.</p>
    </div>
    <h1 class="title">Streaming Settings</h1>
    <?php if (isset($_SESSION['settings_saved'])): ?>
        <?php unset($_SESSION['settings_saved']); ?>
        <div class="notification is-success">
            Settings have been successfully saved.
        </div>
    <?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-5" style="position: relative;">
            <div class="notification is-info">
                <span class="has-text-weight-bold">Streaming Instructions:</span>
                <ul>
                    <li>Retrieve your Twitch Stream Key from your account settings.</li>
                    <li>Enter the key below and choose whether to forward it to Twitch.</li>
                    <li>Click "Save Settings".</li>
                </ul>
                <br>
                <span class="has-text-weight-bold">Server Information:</span>
                <p>Stream securely via RTMPS at <code>rtmps://stream.botofthespecter.com:1935</code>.</p>
                <p>Your API key (found on your profile) serves as the stream key.</p>
            </div>
        </div>
        <div class="column is-5 bot-box" style="position: relative;">
            <form method="post" action="">
                <div class="field">
                    <label class="has-text-white has-text-left" for="twitch_key">Twitch Stream Key</label>
                    <div class="control">
                        <input type="text" class="input" id="twitch_key" name="twitch_key" value="<?php echo htmlspecialchars($twitch_key); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <label class="checkbox" for="forward_to_twitch">
                            <input type="checkbox" id="forward_to_twitch" name="forward_to_twitch" <?php echo $forward_to_twitch ? 'checked' : ''; ?>>
                            Forward to Twitch
                        </label>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button type="submit" class="button is-primary">Save Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Downloads section -->
    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-10 bot-box">
            <h2 class="subtitle has-text-white">Your Recorded Streams</h2>
            <!-- Server selection form -->
            <div class="field is-horizontal mb-4">
                <div class="field-label is-normal">
                    <label class="label has-text-white">Server Location:</label>
                </div>
                <div class="field-body">
                    <div class="field">
                        <div class="control">
                            <div class="select">
                                <select id="server-location" name="server-location">
                                    <option value="au-syd-1" selected>AU SYD 1</option>
                                    <!-- Additional server options can be added in the future -->
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <form method="get">
                                <input type="hidden" name="server" id="server-input" value="au-syd-1">
                                <button type="submit" class="button is-info">Load Files</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-container">
                <table class="table is-fullwidth">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Date Recorded</th>
                            <th>Size</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($storage_error) {
                            echo '<tr><td colspan="4" class="has-text-centered has-text-danger">' . htmlspecialchars($storage_error) . '</td></tr>';
                        } elseif (empty($storage_files)) {
                            echo '<tr><td colspan="4" class="has-text-centered">No recorded streams available.</td></tr>';
                        } else {
                            foreach ($storage_files as $file) {
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($file['name']) . '</td>';
                                echo '<td>' . htmlspecialchars($file['date']) . '</td>';
                                echo '<td>' . htmlspecialchars($file['size']) . '</td>';
                                echo '<td>
                                        <a href="download_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="button is-small is-primary">Download</a>
                                        <a href="delete_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="button is-small is-danger" onclick="return confirm(\'Are you sure you want to delete this file?\');">Delete</a>
                                      </td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
            <script>
                // Update hidden input when dropdown changes
                document.getElementById('server-location').addEventListener('change', function() {
                    document.getElementById('server-input').value = this.value;
                });
            </script>
        </div>
    </div>
</div>
</body>
</html>
