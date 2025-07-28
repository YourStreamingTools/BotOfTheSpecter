<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$pageTitle = t('streaming_settings_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
$billing_conn = new mysqli($servername, $username, $password, "fossbilling");
include_once "/var/www/config/ssh.php";
include "/var/www/config/object_storage.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check connection
if ($billing_conn->connect_error) {
    die("Billing connection failed: " . $billing_conn->connect_error);
}
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Check subscription status
$is_subscribed = false;
$subscription_status = 'Inactive';

// Get user email from the profile data
if (isset($email)) {
    // First, get the client ID from the client table
    $stmt = $billing_conn->prepare("SELECT id FROM client WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $client_id = $row['id'];
        // Check if the client has an active Persistent Storage Membership
        $stmt = $billing_conn->prepare("
            SELECT co.status FROM client_order co
            WHERE co.client_id = ? 
            AND co.title LIKE '%Persistent Storage%'
            ORDER BY co.id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $subscription_status = ucfirst($row['status']);
            $is_subscribed = ($row['status'] === 'active');
        }
    }
    $stmt->close();
}
$billing_conn->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if this is the auto-record form submission
    if (isset($_POST['auto_record']) || (isset($_POST['server']) && !isset($_POST['twitch_key']))) {
        // Handle auto-record form submission only
        $auto_record = isset($_POST['auto_record']) ? 1 : 0;
        $selected_server = isset($_POST['server']) ? $_POST['server'] : (isset($_GET['server']) ? $_GET['server'] : ($cookieConsent && isset($_COOKIE['selectedStreamServer']) ? $_COOKIE['selectedStreamServer'] : 'au-east-1'));
        // Use REPLACE INTO to ensure only one row per user
        $stmt = $db->prepare("REPLACE INTO auto_record_settings (id, server_location, enabled) VALUES (1, ?, ?)");
        if ($stmt === false) {
            die('Prepare failed: ' . htmlspecialchars($db->error));
        }
        $stmt->bind_param("si", $selected_server, $auto_record);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . htmlspecialchars($stmt->error));
        }
        $stmt->close();
        // Set session variable to indicate success
        $_SESSION['settings_saved'] = true;
        header('Location: streaming.php?server=' . urlencode($selected_server));
        exit();
    } else {
        // Handle streaming settings form submission (twitch key + forward settings)
        $twitch_key = $_POST['twitch_key'];
        $forward_to_twitch = isset($_POST['forward_to_twitch']) ? 1 : 0;
        // Save twitch_key and forward_to_twitch
        $stmt = $db->prepare("INSERT INTO streaming_settings (id, twitch_key, forward_to_twitch) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE twitch_key = VALUES(twitch_key), forward_to_twitch = VALUES(forward_to_twitch)");
        if ($stmt === false) {
            die('Prepare failed: ' . htmlspecialchars($db->error));
        }
        $stmt->bind_param("si", $twitch_key, $forward_to_twitch);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . htmlspecialchars($stmt->error));
        }
        $stmt->close();
        // Get the selected server for redirect
        $selected_server = isset($_POST['server']) ? $_POST['server'] : (isset($_GET['server']) ? $_GET['server'] : ($cookieConsent && isset($_COOKIE['selectedStreamServer']) ? $_COOKIE['selectedStreamServer'] : 'au-east-1'));
        // Set session variable to indicate success
        $_SESSION['settings_saved'] = true;
        header('Location: streaming.php?server=' . urlencode($selected_server));
        exit();
    }
}

// Fetch current settings using MySQLi
$stmt = $db->prepare("SELECT twitch_key, forward_to_twitch FROM streaming_settings WHERE id = 1");
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($db->error));
}
$stmt->execute();
$stmt->bind_result($twitch_key_db, $forward_to_twitch_db);
if ($stmt->fetch()) {
    $twitch_key = $twitch_key_db ?? '';
    $forward_to_twitch = $forward_to_twitch_db ?? 1;
} else {
    $twitch_key = '';
    $forward_to_twitch = 1;
}
$stmt->close();

// Load auto_record setting from new table (only one record per user)
$auto_record = 0;
$stmt = $db->prepare("SELECT server_location, enabled FROM auto_record_settings WHERE id = 1");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($server_location_db, $auto_record_db);
    if ($stmt->fetch()) {
        $selected_server = $server_location_db ?? $selected_server;
        $auto_record = $auto_record_db ?? 0;
    }
    $stmt->close();
}
// Function to get files from the storage server
function getStorageFiles($server_host, $server_username, $server_password, $user_dir, $api_key, $recording_dir) {
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
    // Check for active recording files using API key in the specified recording directory
    if (!empty($api_key)) {
        $root_files = @scandir("ssh2.sftp://" . intval($sftp) . $recording_dir);
        if ($root_files) {
            foreach ($root_files as $root_file) {
                if (strpos($root_file, $api_key) !== false) {
                    $recording_active = true;
                    $recording_file = $recording_dir . $root_file;
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
    // Check if the directory exists, and create it if it does not
    $dir_path = $user_dir . "/";
    $sftp_dir = "ssh2.sftp://" . intval($sftp) . $dir_path;
    $sftp_stream = @opendir($sftp_dir);
    if (!$sftp_stream) {
        // Try to create the directory if it does not exist
        if (!@ssh2_sftp_mkdir($sftp, $dir_path, 0775, true)) {
            // If we found a recording, return that even if user directory doesn't exist
            if ($recording_active) { return $files; }
            return ['error' => "User directory could not be created or accessed."];
        }
        // Try to open again after creating
        $sftp_stream = @opendir($sftp_dir);
        if (!$sftp_stream) {
            if ($recording_active) { return $files; }
            return ['error' => "User directory could not be accessed after creation."];
        }
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
            // Check if this file was recently converted
            $recently_converted = (time() - $stat['mtime']) < 600;
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
                'is_recording' => false,
                'recently_converted' => $recently_converted
            ];
        }
    }
    closedir($sftp_stream);
    return $files;
}

// Include S3-compatible API library
require_once '/var/www/vendor/aws-autoloader.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

// Get files when the server is selected
$storage_files = [];
$storage_error = null;

// Server selection handling (default to AU SYD 1)
$selected_server = isset($_GET['server']) ? $_GET['server'] : ($cookieConsent && isset($_COOKIE['selectedStreamServer']) ? $_COOKIE['selectedStreamServer'] : 'au-east-1');

// Set the cookie if the server is selected from the dropdown
if (isset($_GET['server']) && $cookieConsent) {
    setcookie('selectedStreamServer', $_GET['server'], time() + (86400 * 30), "/"); // Cookie for 30 days
}

// Initialize S3 client based on selected server region
if ($selected_server == 'au-east-1') {
    $s3Client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => "https://" . $au_s3_bucket_url,
        'credentials' => [
            'key' => $au_s3_access_key,
            'secret' => $au_s3_secret_key
        ]
    ]);
} else {
    // US servers (us-west-1 and us-east-1)
    $s3Client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => "https://" . $us_s3_bucket_url,
        'credentials' => [
            'key' => $us_s3_access_key,
            'secret' => $us_s3_secret_key
        ]
    ]);
}

$server_info = [
    'au-east-1' => [
        'name' => 'AU-EAST-1',
        'rtmps_url' => 'rtmps://au-east-1.botofthespecter.video:1935'
    ],
    'us-west-1' => [
        'name' => 'US-WEST-1',
        'rtmps_url' => 'rtmps://us-west-1.botofthespecter.video:1935'
    ],
    'us-east-1' => [
        'name' => 'US-EAST-1',
        'rtmps_url' => 'rtmps://us-east-1.botofthespecter.video:1935'
    ]
];
$server_rtmps_url = $server_info[$selected_server]['rtmps_url'] ?? 'Unknown';

if ($selected_server == 'au-east-1') {
    $recording_dir = "/mnt/s3/bots-stream/"; // Base directory for AU-EAST-1
    $user_dir = "/mnt/s3/bots-stream/$username";  // User-specific directory for AU-EAST-1
    if (!empty($stream_au_east_1_host) && !empty($stream_au_east_1_username) && !empty($stream_au_east_1_password)) {
        $result = getStorageFiles(
            $stream_au_east_1_host, 
            $stream_au_east_1_username, 
            $stream_au_east_1_password, 
            $user_dir,
            $api_key,
            $recording_dir
        );
        if (isset($result['error'])) {
            $storage_error = $result['error'];
        } else {
            $storage_files = $result;
        }
    } else {
        $storage_error = "AU-EAST-1 server connection information not configured.";
    }
} elseif ($selected_server == 'us-west-1') {
    $recording_dir = "/mnt/s3/bots-stream/"; // Base directory for US-WEST-1
    $user_dir = "/mnt/s3/bots-stream/$username";  // User-specific directory for US-WEST-1
    if (!empty($stream_us_west_1_host) && !empty($stream_us_west_1_username) && !empty($stream_us_west_1_password)) {
        $result = getStorageFiles(
            $stream_us_west_1_host, 
            $stream_us_west_1_username, 
            $stream_us_west_1_password, 
            $user_dir,
            $api_key,
            $recording_dir
        );
        if (isset($result['error'])) {
            $storage_error = $result['error'];
        } else {
            $storage_files = $result;
        }
    } else {
        $storage_error = "US-WEST-1 server connection information not configured.";
    }
} elseif ($selected_server == 'us-east-1') {
    $recording_dir = "/mnt/s3/bots-stream/"; // Base directory for US-EAST-1
    $user_dir = "/mnt/s3/bots-stream/$username";  // User-specific directory for US-EAST-1
    if (!empty($stream_us_east_1_host) && !empty($stream_us_east_1_username) && !empty($stream_us_east_1_password)) {
        $result = getStorageFiles(
            $stream_us_east_1_host, 
            $stream_us_east_1_username, 
            $stream_us_east_1_password, 
            $user_dir,
            $api_key,
            $recording_dir
        );
        if (isset($result['error'])) {
            $storage_error = $result['error'];
        } else {
            $storage_files = $result;
        }
    } else {
        $storage_error = "US-EAST-1 server connection information not configured.";
    }
} else {
    $storage_error = "Invalid server selected.";
}

// Start output buffering for layout
ob_start();
?>
<?php if (isset($_SESSION['settings_saved'])): ?>
    <?php unset($_SESSION['settings_saved']); ?>
    <div class="notification is-success is-light mb-4">
        <?= t('streaming_settings_saved_success') ?>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['delete_status'])): ?>
    <?php $delete_status = $_SESSION['delete_status']; unset($_SESSION['delete_status']); ?>
    <div class="notification <?= $delete_status['success'] ? 'is-success' : 'is-danger' ?> is-light mb-4">
        <?= htmlspecialchars($delete_status['message']) ?>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['edit_status'])): ?>
    <?php $edit_status = $_SESSION['edit_status']; unset($_SESSION['edit_status']); ?>
    <div class="notification <?= $edit_status['success'] ? 'is-success' : 'is-danger' ?> is-light mb-4">
        <?= htmlspecialchars($edit_status['message']) ?>
    </div>
<?php endif; ?>
<div class="columns is-variable is-8 is-multiline">
    <div class="column is-12">
        <div class="card mb-5">
            <header class="card-header">
                <p class="card-header-title is-size-5">
                    <span class="icon mr-2"><i class="fas fa-info-circle"></i></span>
                    <?= t('streaming_service_overview_title') ?>
                </p>
            </header>
            <div class="card-content" style="position:relative;">
                <div class="columns is-variable is-6 is-multiline">
                    <div class="column is-7-tablet is-8-desktop">
                        <div class="content">
                            <details open>
                                <summary class="is-size-6 has-text-weight-semibold" style="cursor:pointer;outline:none;">Overview</summary>
                                <p class="mt-2 mb-2">
                                    <?= t('streaming_service_intro') ?>
                                </p>
                                <ul class="mb-2">
                                    <li><?= t('streaming_service_option_record_and_forward') ?></li>
                                    <li><?= t('streaming_service_option_multistream_record') ?></li>
                                </ul>
                            </details>
                            <details class="mt-4 mb-2" open>
                                <summary class="is-size-6 has-text-weight-semibold" style="cursor:pointer;outline:none;">Auto Record from Twitch</summary>
                                <div class="mt-2">
                                    <span class="has-text-weight-semibold"><?= t('streaming_auto_record_feature_title') ?></span>
                                    <p><?= t('streaming_auto_record_feature_desc') ?></p>
                                    <ul>
                                        <li><?= t('streaming_auto_record_content_notice') ?></li>
                                    </ul>
                                    <form method="post" action="" style="margin-top:1em;" id="auto-record-form">
                                        <input type="hidden" name="server" value="<?php echo htmlspecialchars($selected_server); ?>">
                                        <div class="field">
                                            <div class="control">
                                                <label class="checkbox" for="auto_record">
                                                    <input type="checkbox" id="auto_record" name="auto_record" <?php echo $auto_record ? 'checked' : ''; ?> onchange="document.getElementById('auto-record-form').submit();">
                                                    <span><?= t('streaming_auto_record_label') ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </details>
                            <details class="mt-3" open>
                                <summary class="is-size-6 has-text-weight-semibold" style="cursor:pointer;outline:none;">Storage Info</summary>
                                <ul class="mt-2">
                                    <li><?= t('streaming_storage_info_retention') ?></li>
                                    <li><?= t('streaming_storage_info_deletion') ?></li>
                                    <li><?= t('streaming_auto_record_vod_speed') ?></li>
                                </ul>
                            </details>
                            <div class="mt-3">
                                <span class="has-text-weight-semibold"><?= t('streaming_server_locations_title') ?></span>
                                <ul>
                                    <li><?= t('streaming_server_locations_current') ?></li>
                                    <li><?= t('streaming_server_locations_coming_soon') ?></li>
                                </ul>
                                <div class="mt-2">
                                    <span class="has-text-weight-semibold"><?= t('streaming_rtmps_url_label') ?></span>
                                    <code class="ml-2"><?php echo htmlspecialchars($server_rtmps_url); ?></code>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-5-tablet is-4-desktop">
                        <div class="content">
                            <h2 class="is-size-5 mb-3"><span class="icon mr-2"><i class="fas fa-cog"></i></span><?= t('streaming_settings_title') ?></h2>
                            <form method="post" action="">
                                <input type="hidden" name="server" value="<?php echo htmlspecialchars($selected_server); ?>">
                                <div class="field">
                                    <label class="label" for="twitch_key"><?= t('streaming_twitch_key_label') ?></label>
                                    <div class="field is-grouped" style="align-items:stretch;">
                                        <div class="control is-expanded" style="position:relative;">
                                            <input
                                                type="password"
                                                class="input"
                                                id="twitch_key"
                                                name="twitch_key"
                                                value="<?php echo htmlspecialchars($twitch_key); ?>"
                                                <?php echo !empty($twitch_key) ? 'readonly' : ''; ?>
                                                required
                                                style="padding-right:2.75em;"
                                            >
                                            <?php if (!empty($twitch_key)): ?>
                                            <button
                                                type="button"
                                                id="toggle-twitch_btn"
                                                class="button is-white"
                                                style="position:absolute;top:0;right:0;height:100%;border:none;padding:0 0.75em;display:flex;align-items:center;justify-content:center;box-shadow:none;"
                                                tabindex="0"
                                                aria-label="<?= t('streaming_show_hide_twitch_key') ?>"
                                            >
                                                <span class="icon" id="toggle-twitch_icon"><i class="fas fa-eye"></i></span>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="field">
                                    <div class="control">
                                        <label class="checkbox" for="forward_to_twitch">
                                            <input type="checkbox" id="forward_to_twitch" name="forward_to_twitch" <?php echo $forward_to_twitch ? 'checked' : ''; ?>>
                                            <?= t('streaming_forward_to_twitch_label') ?>
                                        </label>
                                    </div>
                                </div>
                                <div class="field is-grouped is-grouped-right">
                                    <div class="control">
                                        <button type="submit" class="button is-primary" id="save-settings" <?php echo !empty($twitch_key) ? 'disabled' : ''; ?>><?= t('streaming_save_settings_btn') ?></button>
                                    </div>
                                </div>
                            </form>
                            <div class="mt-4">
                                <span class="has-text-weight-semibold"><?= t('streaming_note_label') ?></span>
                                <?= t('streaming_api_key_note') ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Server selection absolutely positioned at bottom right of the card -->
                <div style="position:absolute; right:1.5rem; bottom:1.5rem; z-index:2;">
                    <form method="get" id="server-selection-form">
                        <div class="field is-grouped is-align-items-center mb-0">
                            <label class="label mr-2 mb-0"><?= t('streaming_server_label') ?></label>
                            <div class="control">
                                <div class="select">
                                    <select id="server-location" name="server" onchange="document.getElementById('server-selection-form').submit();">
                                        <option value="au-east-1" <?php echo $selected_server == 'au-east-1' ? 'selected' : ''; ?>>AU-EAST-1</option>
                                        <option value="us-west-1" <?php echo $selected_server == 'us-west-1' ? 'selected' : ''; ?>>US-WEST-1</option>
                                        <option value="us-east-1" <?php echo $selected_server == 'us-east-1' ? 'selected' : ''; ?>>US-EAST-1</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="block mb-5">
    <div class="card">
        <header class="card-header">
            <p class="card-header-title is-size-5">
                <span class="icon mr-2"><i class="fas fa-video"></i></span>
                <?= t('streaming_recorded_streams_title') ?>
            </p>
        </header>
        <div class="card-content">
            <div class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped">
                    <thead>
                        <tr>
                            <th class="has-text-centered" style="width: 150px;"><?= t('streaming_table_title') ?></th>
                            <th class="has-text-centered" style="width: 90px;"><?= t('streaming_table_duration') ?></th>
                            <th class="has-text-centered"><?= t('streaming_table_creation_time') ?></th>
                            <th class="has-text-centered"><?= t('streaming_table_file_size') ?></th>
                            <th class="has-text-centered"><?= t('streaming_table_deletion_countdown') ?></th>
                            <th class="has-text-centered"><?= t('streaming_table_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody id="filesTableBody">
                        <?php
                        if ($storage_error) {
                            echo '<tr><td colspan="6" class="has-text-centered has-text-danger">' . htmlspecialchars($storage_error) . '</td></tr>';
                        } elseif (empty($storage_files)) {
                            echo '<tr><td colspan="6" class="has-text-centered">' . t('streaming_no_recorded_streams') . '</td></tr>';
                        } else {
                            foreach ($storage_files as $file) {
                                echo '<tr>';
                                if (isset($file['is_recording']) && $file['is_recording']) {
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">';
                                    echo '<span class="has-text-weight-bold has-text-danger">' . t('streaming_recording_in_progress') . '</span>';
                                    echo '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['duration']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['created_at']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['size']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">N/A</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="has-text-grey">' . t('streaming_no_actions_available') . '</span></td>';
                                } else {
                                    $title = pathinfo($file['name'], PATHINFO_FILENAME);
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">';
                                    echo htmlspecialchars($title);
                                    if (isset($file['recently_converted']) && $file['recently_converted']) {
                                        echo ' <span class="tag is-success is-light conversion-tag">' . t('streaming_recently_converted') . '</span>';
                                    }
                                    echo '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['duration']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['created_at']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['size']) . '</td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="countdown" data-deletion-timestamp="' . htmlspecialchars($file['deletion_timestamp']) . '">' . htmlspecialchars($file['deletion_countdown']) . '</span></td>';
                                    echo '<td class="has-text-centered" style="vertical-align: middle;">';
                                    echo '<a href="#" class="play-video action-icon" data-video-url="play_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" title="' . t('streaming_action_watch_video') . '"><i class="fas fa-play"></i></a> ';
                                    echo '<a href="download_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="action-icon" title="' . t('streaming_action_download_video') . '"><i class="fas fa-download"></i></a> ';
                                    if ($is_subscribed): ?>
                                        <a class="upload-to-s3 action-icon" data-server="<?php echo $selected_server; ?>" data-file="<?php echo urlencode($file['name']); ?>" title="<?= t('streaming_action_upload_persistent') ?>"><i class="fas fa-cloud-upload-alt"></i></a>
                                    <?php endif;
                                    echo '<a href="#" class="edit-video action-icon" data-file="' . htmlspecialchars($file['name']) . '" data-title="' . htmlspecialchars($title) . '" data-server="' . $selected_server . '" title="' . t('streaming_action_edit_title') . '"><i class="fas fa-edit"></i></a> ';
                                    echo '<a href="delete_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="action-icon" title="' . t('streaming_action_delete_video') . '" onclick="return confirm(\'' . t('streaming_confirm_delete_file') . '\');"><i class="fas fa-trash"></i></a>';
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
<div class="block mt-5">
    <div class="notification is-info is-light is-flex is-align-items-center is-justify-content-space-between">
        <div>
            <span class="icon mr-2"><i class="fas fa-archive"></i></span>
            <span class="has-text-weight-semibold"><?= t('streaming_need_long_term_storage') ?></span>
            <span class="ml-2"><?= t('streaming_access_persistent_storage') ?></span>
        </div>
        <a href="persistent_storage.php" class="button is-primary is-light ml-4">
            <span class="icon"><i class="fas fa-archive"></i></span>
            <span><?= t('streaming_go_to_persistent_storage') ?></span>
        </a>
    </div>
</div>

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
            <p class="modal-card-title"><?= t('streaming_rename_video_title') ?></p>
            <button class="delete" aria-label="close"></button>
        </header>
        <section class="modal-card-body">
            <div class="field">
                <label class="label"><?= t('streaming_new_title_label') ?></label>
                <div class="control">
                    <input class="input" type="text" id="edit-title-input">
                    <input type="hidden" id="edit-file-input">
                    <input type="hidden" id="edit-server-input">
                </div>
            </div>
        </section>
        <footer class="modal-card-foot">
            <button class="button is-success" id="save-edit-btn"><?= t('streaming_rename_btn') ?></button>
            <button class="button" id="cancel-edit-btn"><?= t('streaming_cancel_btn') ?></button>
        </footer>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
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
            alert('<?= t('streaming_enter_valid_title') ?>');
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
                alert('<?= t('streaming_file_renamed_success') ?>');
                location.reload(); // Reload the page to see changes
            } else {
                alert('Error: ' + data.message);
            }
            closeEditModal();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('<?= t('streaming_file_rename_error') ?>');
            closeEditModal();
        });
    });
    // Store references to modal elements outside of event handlers
    window.videoModal = document.getElementById('videoModal');
    window.videoFrame = document.getElementById('videoFrame');
    window.editModal = document.getElementById('editModal');
    window.titleInput = document.getElementById('edit-title-input');
    window.fileInput = document.getElementById('edit-file-input');
    window.serverInput = document.getElementById('edit-server-input');
    // Initialize event handlers for the first time
    reattachEventHandlers();

    // Handle upload to S3
    document.querySelectorAll('.upload-to-s3').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var server = this.getAttribute('data-server');
            var file = this.getAttribute('data-file');
            Swal.fire({
                title: '<?= t('streaming_upload_to_persistent_title') ?>',
                text: '<?= t('streaming_upload_to_persistent_text') ?>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<?= t('streaming_upload_to_persistent_confirm') ?>',
                cancelButtonText: '<?= t('streaming_cancel_btn') ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('upload_to_s3.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'server=' + encodeURIComponent(server) + '&file=' + encodeURIComponent(file) + '&username=' + encodeURIComponent('<?php echo $username; ?>')
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('<?= t('streaming_success_title') ?>', '<?= t('streaming_file_uploaded_success') ?>', 'success');
                            refreshTable(); // Refresh the table to reflect changes
                        } else {
                            Swal.fire('<?= t('streaming_error_title') ?>', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire('<?= t('streaming_error_title') ?>', '<?= t('streaming_file_upload_error') ?>', 'error');
                    });
                }
            });
        });
    });
});

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
                // Re-initialize event handlers for the newly added elements
                reattachEventHandlers();
                // Update conversion tags
                updateConversionTags();
                // Update countdowns
                updateAllCountdowns();
            }
        })
        .catch(error => {
            console.error('<?= t('streaming_error_fetching_updated_data') ?>', error);
        });
}

// Function to reattach event handlers after table refresh
function reattachEventHandlers() {
    // Re-attach play video modal handlers
    document.querySelectorAll('.play-video').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('data-video-url');
            videoFrame.src = url;
            videoModal.classList.add('is-active');
        });
    });
    // Re-attach edit video handlers (if uncommented/enabled)
    document.querySelectorAll('.edit-video').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            titleInput.value = this.getAttribute('data-title');
            fileInput.value = this.getAttribute('data-file');
            serverInput.value = this.getAttribute('data-server');
            editModal.classList.add('is-active');
        });
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
    if (toggleBtn && twitchInput) {
        toggleBtn.onclick = function(e) {
            e.preventDefault();
            if (twitchInput.type === "password") {
                // Only prompt when revealing
                Swal.fire({
                    title: '<?= t('streaming_show_twitch_key_title') ?>',
                    text: '<?= t('streaming_show_twitch_key_warning') ?>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: '<?= t('streaming_show_twitch_key_confirm') ?>',
                    cancelButtonText: '<?= t('streaming_cancel_btn') ?>'
                }).then((result) => {
                    if(result.isConfirmed){
                        twitchInput.type = "text";
                        twitchInput.removeAttribute("readonly");
                        toggleBtn.querySelector('i').classList.remove('fa-eye');
                        toggleBtn.querySelector('i').classList.add('fa-eye-slash');
                        saveBtn.disabled = false;
                    }
                });
            } else {
                // Hide immediately, no prompt
                twitchInput.type = "password";
                twitchInput.setAttribute("readonly", "readonly");
                toggleBtn.querySelector('i').classList.remove('fa-eye-slash');
                toggleBtn.querySelector('i').classList.add('fa-eye');
                saveBtn.disabled = true;
            }
        };
    }
});

</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>