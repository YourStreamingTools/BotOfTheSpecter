<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include "/var/www/config/ssh.php";
include 'userdata.php';
include 'user_db.php';

// Function to get files from the storage server (same as in streaming.php)
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
    // Check if the directory exists
    $dir_path = $user_dir . "/";
    $sftp_stream = @opendir("ssh2.sftp://" . intval($sftp) . $dir_path);
    if (!$sftp_stream) {
        // If we found a recording, return that even if user directory doesn't exist
        if ($recording_active) {
            return $files;
        }
        return ['error' => "Directory not found or not accessible at this time."];
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
    $recording_dir = "/root/"; // Base directory for AU-EAST-1
    $user_dir = "/root/$username";  // User-specific directory for AU-EAST-1
    if (!empty($storage_server_au_east_1_host) && !empty($storage_server_au_east_1_username) && !empty($storage_server_au_east_1_password)) {
        $result = getStorageFiles(
            $storage_server_au_east_1_host, 
            $storage_server_au_east_1_username, 
            $storage_server_au_east_1_password, 
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
        $storage_error = "Server connection information not configured.";
    }
} elseif ($selected_server == 'us-west-1') {
    $recording_dir = "/mnt/stream-us-west-1/"; // Base directory for US-WEST-1
    $user_dir = "/mnt/stream-us-west-1/$username";  // User-specific directory for US-WEST-1
    if (!empty($storage_server_us_west_1_host) && !empty($storage_server_us_west_1_username) && !empty($storage_server_us_west_1_password)) {
        $result = getStorageFiles(
            $storage_server_us_west_1_host, 
            $storage_server_us_west_1_username, 
            $storage_server_us_west_1_password, 
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
        $storage_error = "Server connection information not configured.";
    }
} elseif ($selected_server == 'us-east-1') {
    $recording_dir = "/home/specter/"; // Base directory for US-EAST-1
    $user_dir = "/home/specter/$username";  // User-specific directory for US-EAST-1
    if (!empty($storage_server_us_east_1_host) && !empty($storage_server_us_east_1_username) && !empty($storage_server_us_east_1_password)) {
        $result = getStorageFiles(
            $storage_server_us_east_1_host, 
            $storage_server_us_east_1_username, 
            $storage_server_us_east_1_password, 
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
        $storage_error = "Server connection information not configured.";
    }
} else {
    $storage_error = "Invalid server selected.";
}

// Generate HTML for the files table
ob_start();
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
            echo '<td class="has-text-centered" style="vertical-align: middle;">';
            echo htmlspecialchars($title);
            if (isset($file['recently_converted']) && $file['recently_converted']) {
                echo ' <span class="tag is-success is-light conversion-tag">Recently Converted</span>';
            }
            echo '</td>';
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
$tableContent = ob_get_clean();

// Return the data as JSON
header('Content-Type: application/json');
echo json_encode([
    'html' => $tableContent,
    'files_count' => count($storage_files)
]);
?>