<?php
// Initialize the session
session_start();

// Include internationalization
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => t('streaming_authentication_required')]);
    exit();
}

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
$billing_conn = new mysqli($servername, $username, $password, "fossbilling");
include "/var/www/config/ssh.php";
include 'userdata.php';
include 'user_db.php';
$is_subscribed = false;

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
            $is_subscribed = ($row['status'] === 'active');
        }
    }
    $stmt->close();
}
$billing_conn->close();

// Function to get files from the storage server (same as in streaming.php)
function getStorageFiles($server_host, $server_username, $server_password, $user_dir, $api_key, $recording_dir) {
    $files = [];
    $recording_active = false;    // Check if SSH2 extension is available
    if (!function_exists('ssh2_connect')) {
        return ['error' => t('streaming_ssh2_not_installed')];
    }
    // Connect to the server
    $connection = @ssh2_connect($server_host, 22);
    if (!$connection) {
        return ['error' => t('streaming_connection_failed')];
    }
    // Authenticate
    if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
        return ['error' => t('streaming_authentication_failed')];
    }
    // Create SFTP session
    $sftp = @ssh2_sftp($connection);
    if (!$sftp) {
        return ['error' => t('streaming_sftp_init_failed')];
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
    // Check if the directory exists
    $dir_path = $user_dir . "/";
    $sftp_stream = @opendir("ssh2.sftp://" . intval($sftp) . $dir_path);
    if (!$sftp_stream) {
        // If we found a recording, return that even if user directory doesn't exist
        if ($recording_active) {
            return $files;
        }        return ['error' => t('streaming_no_files_found')];
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

// Get server parameter
$selected_server = isset($_GET['server']) ? $_GET['server'] : 'au-east-1';
$storage_files = [];
$storage_error = null;

// Get files based on selected server
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
        }    } else {
        $storage_error = t('streaming_server_not_configured');
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
        }    } else {
        $storage_error = t('streaming_server_not_configured');
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
        }    } else {
        $storage_error = t('streaming_server_not_configured');
    }
} else {
    $storage_error = t('streaming_invalid_server_selection');
}

// Generate HTML for the files table
ob_start();
if ($storage_error) {
    echo '<tr><td colspan="6" class="has-text-centered has-text-danger">' . htmlspecialchars($storage_error) . '</td></tr>';
} elseif (empty($storage_files)) {
    echo '<tr><td colspan="6" class="has-text-centered">' . t('streaming_no_recorded_streams') . '</td></tr>';
} else {
    foreach ($storage_files as $file) {
        echo '<tr>';        // Check if this is a recording in progress
        if (isset($file['is_recording']) && $file['is_recording']) {
            echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="has-text-weight-bold has-text-danger">' . t('streaming_recording_in_progress') . '</span></td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['duration']) . '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['created_at']) . '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['size']) . '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">N/A</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="has-text-grey">' . t('streaming_no_actions_available') . '</span></td>';
        } else {
            $title = pathinfo($file['name'], PATHINFO_FILENAME);
            echo '<td class="has-text-centered" style="vertical-align: middle;">';            echo htmlspecialchars($title);
            if (isset($file['recently_converted']) && $file['recently_converted']) {
                echo ' <span class="tag is-success is-light conversion-tag">' . t('streaming_recently_converted') . '</span>';
            }
            echo '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['duration']) . '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['created_at']) . '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">' . htmlspecialchars($file['size']) . '</td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;"><span class="countdown" data-deletion-timestamp="' . htmlspecialchars($file['deletion_timestamp']) . '">' . htmlspecialchars($file['deletion_countdown']) . '</span></td>';
            echo '<td class="has-text-centered" style="vertical-align: middle;">';            echo '<a href="#" class="play-video action-icon" data-video-url="play_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" title="' . t('streaming_action_watch_video') . '"><i class="fas fa-play"></i></a> ';
            echo '<a href="download_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="action-icon" title="' . t('streaming_action_download_video') . '"><i class="fas fa-download"></i></a> ';
            if ($is_subscribed) {echo '<a class="upload-to-s3 action-icon" data-server="' . $selected_server . '" data-file="' . urlencode($file['name']) . '" title="' . t('streaming_action_upload_persistent') . '"><i class="fas fa-cloud-upload-alt"></i></a> ';}
            echo '<a href="#" class="edit-video action-icon" data-file="' . htmlspecialchars($file['name']) . '" data-title="' . htmlspecialchars($title) . '" data-server="' . $selected_server . '" title="' . t('streaming_action_edit_title') . '"><i class="fas fa-edit"></i></a> ';
            echo '<a href="delete_stream.php?server=' . $selected_server . '&file=' . urlencode($file['name']) . '" class="action-icon" title="' . t('streaming_action_delete_video') . '" onclick="return confirm(\'' . t('streaming_confirm_delete_file') . '\');"><i class="fas fa-trash"></i></a>';
            echo '</td>';
        }
        echo '</tr>';
    }
}
$tableContent = ob_get_clean();

// Return the data as JSON
header('Content-Type: application/json');
echo json_encode([
    'html' => $tableContent,
    'files_count' => count($storage_files)
]);
?>