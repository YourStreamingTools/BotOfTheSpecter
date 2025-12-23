<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define the user's directories
$walkon_path = "/var/www/walkons/" . $_SESSION['editing_username'];;
$soundalert_path = "/var/www/soundalerts/" . $_SESSION['editing_username'];;
$videoalert_path = "/var/www/videoalerts/" . $_SESSION['editing_username'];;
$twitch_sound_alert_path = $soundalert_path . "/twitch";

// Define user-specific storage limits
$base_storage_size = 20 * 1024 * 1024; // 20MB in bytes (FREE)

// Prefer authoritative user data from the database when available so the
// moderator page uses the same detection logic as the admin/user page.
$tier = null;
$is_beta_flag = false;
$twitch_user_id = null;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$editing_username = $_SESSION['editing_username'] ?? null;
if ($editing_username) {
    // Try include DB connection; suppress warnings if not present.
    if (file_exists('/var/www/config/db_connect.php')) {
        @require_once '/var/www/config/db_connect.php';
        if (!empty($conn)) {
            // Select the full row to avoid errors if columns are missing in some schemas.
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $editing_username);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res) {
                        $row = $res->fetch_assoc();
                        if ($row) {
                            if (isset($row['tier'])) $tier = $row['tier'];
                            if (isset($row['beta'])) $is_beta_flag = (bool)$row['beta'];
                            if (isset($row['is_beta'])) $is_beta_flag = $is_beta_flag || (bool)$row['is_beta'];
                            if (isset($row['twitch_user_id'])) $twitch_user_id = $row['twitch_user_id'];
                        }
                    }
                }
                $stmt->close();
            }
        }
    }
}

// Fall back to session tier/beta values if DB didn't provide
if (empty($tier)) {
    $tier = $_SESSION['tier'] ?? null;
}
if (!$is_beta_flag) {
    $is_beta_flag = $_SESSION['beta'] ?? ($_SESSION['is_beta'] ?? false);
}

// Default to free tier
$max_storage_size = $base_storage_size;

// Apply known mappings
if ($is_beta_flag === true || (is_string($tier) && strtolower($tier) === 'beta') || strtolower((string)$is_beta_flag) === 'true') {
    $max_storage_size = 500 * 1024 * 1024; // 500MB for beta users
} else {
    switch ((string)$tier) {
        case "1000":
            $max_storage_size = 50 * 1024 * 1024; // 50MB
            break;
        case "2000":
            $max_storage_size = 100 * 1024 * 1024; // 100MB
            break;
        case "3000":
            $max_storage_size = 200 * 1024 * 1024; // 200MB
            break;
        case "4000":
            $max_storage_size = 500 * 1024 * 1024; // 500MB
            break;
        case "500":
        case "500mb":
            $max_storage_size = 500 * 1024 * 1024; // 500MB
            break;
        default:
            $max_storage_size = $base_storage_size; // 20MB (FREE)
            break;
    }
}

// If we still have only the base tier but the user has a twitch id and we
// have an access token, attempt to verify an active subscription via Helix
// and treat an active subscription as a 500MB tier (matches admin/users.php).
if ($max_storage_size === $base_storage_size && !empty($twitch_user_id) && !empty($_SESSION['access_token'])) {
    $clientID = $_SESSION['twitch_client_id'] ?? null;
    $accessToken = $_SESSION['access_token'];
    // Try to get broadcaster id from session or config file
    $broadcaster_id = $_SESSION['broadcaster_id'] ?? null;
    if (empty($broadcaster_id) && file_exists('/var/www/config/twitch.php')) {
        @include '/var/www/config/twitch.php';
        // some configs may set $broadcaster_id or $clientID; prefer session values
    }
    if (!empty($clientID) && !empty($broadcaster_id)) {
        $url = "https://api.twitch.tv/helix/subscriptions?broadcaster_id={$broadcaster_id}&user_id={$twitch_user_id}";
        $headers = [ "Client-ID: $clientID", "Authorization: Bearer $accessToken" ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
                $max_storage_size = 500 * 1024 * 1024;
            }
        }
        curl_close($ch);
    }
}

// Helper function to ensure directory is writable
function ensureDirectoryWritable($path) {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            error_log("Failed to create directory: $path");
            return false;
        }
    }
    // Fix permissions if directory exists but isn't writable
    if (!is_writable($path)) {
        if (!chmod($path, 0755)) {
            error_log("Failed to chmod directory: $path");
            return false;
        }
    }
    return true;
}

// Create and fix permissions for user directories
ensureDirectoryWritable($walkon_path);
ensureDirectoryWritable($soundalert_path);
ensureDirectoryWritable($videoalert_path);
ensureDirectoryWritable($twitch_sound_alert_path);

// Calculate total storage used by the user across both directories
function calculateStorageUsed($directories) {
    $size = 0;
    foreach ($directories as $directory) {
        foreach (glob(rtrim($directory, '/').'/*', GLOB_NOSORT) as $file) {
            $size += is_file($file) ? filesize($file) : calculateStorageUsed([$file]);
        }
    }
    return $size;
}

$current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path, $videoalert_path]);
$storage_percentage = ($max_storage_size > 0) ? ($current_storage_used / $max_storage_size) * 100 : 0;
// No database access here, so just keep as is for MySQLi context
?>