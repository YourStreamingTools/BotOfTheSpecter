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
function getStorageFiles($server_host, $server_username, $server_password, $user_dir, $api_key) {
    $files = [];
    $recording_active = false;
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
    // Check for active recording files using API key
    if (!empty($api_key)) {
        $root_files = @scandir("ssh2.sftp://" . intval($sftp) . "/root/");
        if ($root_files) {
            foreach ($root_files as $root_file) {
                if (strpos($root_file, $api_key) !== false) {
                    $recording_active = true;
                    $recording_file = "/root/" . $root_file;
                    $files[] = [
                        'name' => 'Live Recording',
                        'date' => date('d-m-Y'),
                        'created_at' => date('d-m-Y H:i:s'),
                        'deletion_countdown' => 'N/A',
                        'size' => 'Recording...',
                        'duration' => 'Live',
                        'path' => $recording_file,
                        'deletion_timestamp' => 0,
                        'is_recording' => true
                    ];
                }
            }
        }
    }
    // Check if the directory exists
    $dir_path = "/root/$user_dir/";
    $sftp_stream = @opendir("ssh2.sftp://" . intval($sftp) . $dir_path);
    if (!$sftp_stream) {
        // If we found a recording, return that even if user directory doesn't exist
        if ($recording_active) {
            return $files;
        }
        return ['error' => "No files found."];
    }
    // List files
    while (($file = readdir($sftp_stream)) !== false) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'mp4') {
            // Get file stats
            $stat = ssh2_sftp_stat($sftp, $dir_path . $file);
            if (!$stat) {
                continue; // Skip if stat retrieval fails
            }
            // Format file size
            $size_bytes = $stat['size'];
            $size = $size_bytes < 1024*1024 ? round($size_bytes/1024, 2).' KB' : 
                ($size_bytes < 1024*1024*1024 ? round($size_bytes/(1024*1024), 2).' MB' : 
                round($size_bytes/(1024*1024*1024), 2).' GB');
            // Format creation date and deletion countdown
            $created_at = date('d-m-Y H:i:s', $stat['mtime']);
            $date = date('d-m-Y', $stat['mtime']);
            $deletionTime = $stat['mtime'] + 86400; // 24 hours later
            $remaining = $deletionTime - time();
            $countdown = $remaining > 0 ? sprintf('%02d:%02d:%02d', floor($remaining/3600), floor(($remaining % 3600) / 60), $remaining % 60) : 'Expired';
            // Get video duration using ffprobe
            $duration = "N/A";
            $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($dir_path . $file);
            $stream = ssh2_exec($connection, $command);
            if ($stream) {
                stream_set_blocking($stream, true);
                $output = stream_get_contents($stream);
                fclose($stream);
                if (is_numeric(trim($output))) {
                    $seconds = (int)trim($output);
                    $duration = sprintf('%02d:%02d:%02d', ($seconds / 3600), ($seconds / 60 % 60), $seconds % 60);
                }
            }
            $files[] = [
                'name' => $file,
                'date' => $date,
                'created_at' => $created_at,
                'deletion_countdown' => $countdown,
                'size' => $size,
                'duration' => $duration,
                'path' => $dir_path . $file,
                'deletion_timestamp' => $deletionTime,
                'is_recording' => false
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
$selected_server = isset($_GET['server']) ? $_GET['server'] : 'au-east-1';
$server_info = [
    'au-east-1' => [
        'name' => 'AU-EAST-1',
        'rtmps_url' => 'rtmps://au-east-1.stream.botofthespecter.com:1935'
    ]
];
$server_rtmps_url = $server_info[$selected_server]['rtmps_url'] ?? 'Unknown';

if ($selected_server == 'au-east-1') {
    // Only try to fetch files if the credentials are set
    if (!empty($storage_server_au_east_1_host) && !empty($storage_server_au_east_1_username) && !empty($storage_server_au_east_1_password)) {
        $result = getStorageFiles(
            $storage_server_au_east_1_host, 
            $storage_server_au_east_1_username, 
            $storage_server_au_east_1_password, 
            $username,
            $api_key
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
        <p class="has-text-black">We’re excited to offer this streaming feature as a complimentary service for all Specter users. You have several options to make the most of this feature:</p>
        <ul style="list-style-type: disc; padding-left: 20px;">
            <li class="has-text-black">Record your streams and simultaneously forward them to Twitch.</li>
            <li class="has-text-black">Use our service as a secondary output for your streams via multi-streaming, specifically for recording only.</li>
        </ul>
        <p class="has-text-black">You can choose the option that best fits your streaming needs and enhance your experience.</p>
        <hr>
        <p class="has-text-weight-bold has-text-black">Important Storage Information:</p>
        <ul style="list-style-type: disc; padding-left: 20px;">
            <li class="has-text-black">Currently, we offer a server location in Sydney, Australia. Additional servers will be available soon!</li>
            <li class="has-text-black">Recorded streams are stored for 24 hours after the stream ends.</li>
            <li class="has-text-black">After 24 hours, recorded files will be automatically removed due to storage limitations.</li>
        </ul>
        <hr>
        <p class="has-text-weight-bold has-text-black">Coming soon:</p>
        <p class="has-text-black">We’re introducing a new option to store your recorded streams for a longer period, with billing based on the storage space used.</p>
        <p class="has-text-black">The cost will be $7 USD per month for each terabyte of storage, with a minimum of 1TB per month.</p>
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
                <span class="has-text-weight-bold">Note: Your API Key (found on your profile) serves as the stream key for our servers.</span>
                <span class="has-text-weight-bold">RTMPS URL for Selected Server:</span>
                <br>
                <code><?php echo htmlspecialchars($server_rtmps_url); ?></code>
            </div>
        </div>
        <div class="column is-5 bot-box" style="position: relative;">
            <form method="post" action="">
                <div class="field">
                    <label class="has-text-white has-text-left" for="twitch_key">Twitch Stream Key</label>
                    <div class="control">
                        <input type="password" class="input" id="twitch_key" name="twitch_key" value="<?php echo htmlspecialchars($twitch_key); ?>" <?php echo !empty($twitch_key) ? 'readonly' : ''; ?> required>
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
                        <button type="submit" class="button is-primary" id="save-settings" <?php echo !empty($twitch_key) ? 'disabled' : ''; ?>>Save Settings</button>
                        <?php if (!empty($twitch_key)): ?>
                        <button type="button" id="toggle-twitch_btn" class="button is-info is-outlined is-rounded" style="margin-left: 10px;">
                            <span class="icon"><i class="fas fa-eye"></i></span>
                            <span>Show Key</span>
                        </button>
                        <?php endif; ?>
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
                            <form method="get" id="server-selection-form">
                                <div class="select">
                                    <select id="server-location" name="server" onchange="document.getElementById('server-selection-form').submit();">
                                        <option value="au-east-1" <?php echo $selected_server == 'au-east-1' ? 'selected' : ''; ?>>AU-EAST-1</option>
                                        <!-- Additional server options can be added in the future -->
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-container">
                <table class="table is-fullwidth">
                    <thead>
                        <tr>
                            <th class="has-text-centered" style="width: 120px;">Title</th>
                            <th class="has-text-centered" style="width: 90px;">Duration</th>
                            <th class="has-text-centered">Archive Creation Time</th>
                            <th class="has-text-centered">File Size</th>
                            <th class="has-text-centered">Deletion Countdown</th>
                            <th class="has-text-centered">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="filesTableBody">
                        <?php
                        if ($storage_error) {
                            echo '<tr><td colspan="6" class="has-text-centered has-text-danger">' . htmlspecialchars($storage_error) . '</td></tr>';
                        } elseif (empty($storage_files)) {
                            echo '<tr><td colspan="6" class="has-text-centered">No recorded streams available.</td></tr>';
                        } else {
                            foreach ($storage_files as $file) {
                                echo '<tr>';
                                // Check if this is a recording in progress
                                if (isset($file['is_recording']) && $file['is_recording']) {
                                    echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="has-text-weight-bold has-text-danger">RECORDING IN PROGRESS</span></td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['duration']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['created_at']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['size']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">N/A</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="has-text-grey">No actions available</span></td>';
                                } else {
                                    $title = pathinfo($file['name'], PATHINFO_FILENAME);
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($title) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['duration']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['created_at']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['size']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="countdown" data-deletion-timestamp="' . htmlspecialchars($file['deletion_timestamp']) . '">' . htmlspecialchars($file['deletion_countdown']) . '</span></td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">';
                                    echo '<a href="#" class="play-video action-icon" data-video-url="play_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" title="Watch the video"><i class="fas fa-play"></i></a> ';
                                    echo '<a href="download_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="action-icon" title="Download the video file"><i class="fas fa-download"></i></a> ';
                                    //echo '<a href="#" class="edit-video action-icon" data-file="' . htmlspecialchars($file['name']) . '" data-title="' . htmlspecialchars($title) . '" data-server="' . $selected_server . '" title="Edit the title"><i class="fas fa-edit"></i></a> ';
                                    echo '<a href="delete_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="action-icon" title="Delete the video file" onclick="return confirm(\'Are you sure you want to delete this file?\');"><i class="fas fa-trash"></i></a>';
                                    echo '</td>';
                                }
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<style>
    .action-icon {
        margin: 0 8px;
        display: inline-block;
    }
    .action-icon:first-child {
        margin-left: 0;
    }
    .action-icon:last-child {
        margin-right: 0;
    }
</style>

<div id="videoModal" class="modal">
    <div class="modal-background"></div>
    <button class="modal-close is-large" aria-label="close"></button>
    <div class="modal-content" style="width:1280px; height:720px;">
        <iframe id="videoFrame" style="width:100%; height:100%;" frameborder="0" allowfullscreen></iframe>
    </div>
</div>

<!-- Add Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title">Rename Video</p>
            <button class="delete" aria-label="close"></button>
        </header>
        <section class="modal-card-body">
            <div class="field">
                <label class="label">New Title</label>
                <div class="control">
                    <input class="input" type="text" id="edit-title-input">
                    <input type="hidden" id="edit-file-input">
                    <input type="hidden" id="edit-server-input">
                </div>
            </div>
        </section>
        <footer class="modal-card-foot">
            <button class="button is-success" id="save-edit-btn">Rename</button>
            <button class="button" id="cancel-edit-btn">Cancel</button>
        </footer>
    </div>
</div>
<br><br><br>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Existing countdown code
    function updateCountdown(element) {
        var deadline = parseInt(element.getAttribute('data-deletion-timestamp')) * 1000;
        var now = Date.now();
        var remaining = Math.floor((deadline - now) / 1000);
        if (remaining > 0) {
            var hours = Math.floor(remaining / 3600);
            var minutes = Math.floor((remaining % 3600) / 60);
            var seconds = remaining % 60;
            element.textContent =
                ("0" + hours).slice(-2) + ":" +
                ("0" + minutes).slice(-2) + ":" +
                ("0" + seconds).slice(-2);
        } else {
            element.textContent = 'Expired';
        }
    }
    function updateAllCountdowns() {
        document.querySelectorAll('.countdown').forEach(function(el) {
            updateCountdown(el);
        });
    }
    updateAllCountdowns();
    setInterval(updateAllCountdowns, 1000);
    
    // Video modal functionality
    var videoModal = document.getElementById('videoModal');
    var videoFrame = document.getElementById('videoFrame');
    document.querySelectorAll('.play-video').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('data-video-url');
            videoFrame.src = url;
            videoModal.classList.add('is-active');
        });
    });
    document.querySelector('#videoModal .modal-background').addEventListener('click', function() {
        videoModal.classList.remove('is-active');
        videoFrame.src = '';
    });
    document.querySelector('#videoModal .modal-close').addEventListener('click', function() {
        videoModal.classList.remove('is-active');
        videoFrame.src = '';
    });
    
    // Edit modal functionality
    var editModal = document.getElementById('editModal');
    var titleInput = document.getElementById('edit-title-input');
    var fileInput = document.getElementById('edit-file-input');
    var serverInput = document.getElementById('edit-server-input');
    
    // Open edit modal when clicking edit icon
    document.querySelectorAll('.edit-video').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            titleInput.value = this.getAttribute('data-title');
            fileInput.value = this.getAttribute('data-file');
            serverInput.value = this.getAttribute('data-server');
            editModal.classList.add('is-active');
        });
    });
    
    // Close edit modal
    function closeEditModal() {
        editModal.classList.remove('is-active');
    }
    
    document.querySelector('#editModal .delete').addEventListener('click', closeEditModal);
    document.getElementById('cancel-edit-btn').addEventListener('click', closeEditModal);
    document.querySelector('#editModal .modal-background').addEventListener('click', closeEditModal);
    
    // Handle rename form submission
    document.getElementById('save-edit-btn').addEventListener('click', function() {
        var newTitle = titleInput.value.trim();
        var oldFile = fileInput.value;
        var server = serverInput.value;
        
        if (newTitle === '') {
            alert('Please enter a valid title');
            return;
        }
        
        // AJAX request to rename the file
        fetch('edit_stream.php?server=' + encodeURIComponent(server) + '&file=' + encodeURIComponent(oldFile), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'new_title=' + encodeURIComponent(newTitle)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('File renamed successfully!');
                location.reload(); // Reload the page to see changes
            } else {
                alert('Error: ' + data.message);
            }
            closeEditModal();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while renaming the file.');
            closeEditModal();
        });
    });
});

// Function to fetch updated table content and refresh
function refreshTable() {
    // Define the URL to get the updated data
    var url = 'get_stream_files.php?server=' + encodeURIComponent('<?= $selected_server ?>');
    // Fetch updated data using AJAX
    fetch(url)
        .then(response => response.json())
        .then(data => {
            // Check if the response has HTML content
            if (data.html) {
                // Update the table body with the new HTML
                document.getElementById('filesTableBody').innerHTML = data.html;
            }
        })
        .catch(error => {
            console.error('Error fetching updated stream data:', error);
        });
}
// Set an interval to refresh the table every 60 seconds (60000 ms)
setInterval(refreshTable, 60000);

function togglePasswordVisibility(el) {
    var input = document.getElementById("twitch_key");
    if (input.type === "password") {
        input.type = "text";
        el.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        input.type = "password";
        el.innerHTML = '<i class="fas fa-eye"></i>';
    }
}

// Attach event listener to the toggle button after DOM load
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('toggle-twitch_btn');
    var twitchInput = document.getElementById('twitch_key');
    var saveBtn = document.getElementById('save-settings');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (twitchInput.type === "password") {
                Swal.fire({
                    title: 'Are you sure?',
                    text: 'Warning: Revealing your Twitch Stream Key can be a security risk. Do you want to proceed?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, show it',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if(result.isConfirmed){
                        twitchInput.type = "text";
                        twitchInput.removeAttribute("readonly");
                        toggleBtn.innerHTML = '<span class="icon"><i class="fas fa-eye-slash"></i></span><span>Hide Key</span>';
                        saveBtn.disabled = false;
                    }
                });
            } else {
                twitchInput.type = "password";
                twitchInput.setAttribute("readonly", "readonly");
                toggleBtn.innerHTML = '<span class="icon"><i class="fas fa-eye"></i></span><span>Show Key</span>';
                saveBtn.disabled = true;
            }
        });
    }
});
</script>
</body>
</html>
