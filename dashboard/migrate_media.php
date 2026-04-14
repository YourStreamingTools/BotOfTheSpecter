<?php
session_start();
session_write_close();
header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit();
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

include '/var/www/config/database.php';
$username = $_SESSION['username'] ?? '';
if (empty($username)) {
    echo json_encode(['success' => false, 'message' => 'No username in session.']);
    exit();
}

// Source dirs (old system)
$soundalert_path     = "/var/www/soundalerts/" . $username;
$twitch_sound_path   = $soundalert_path . "/twitch";
// Destination (new unified library)
$media_path          = "/var/www/media/" . $username;
$media_base          = "/var/www/media";

// Ensure base and user dirs exist
if (!is_dir($media_base) && !mkdir($media_base, 0755, true)) {
    echo json_encode(['success' => false, 'message' => 'Could not create media base directory.']);
    exit();
}
if (!is_dir($media_path) && !mkdir($media_path, 0755, true)) {
    echo json_encode(['success' => false, 'message' => 'Could not create user media directory.']);
    exit();
}
chmod($media_path, 0755);

$copied = 0;
$skipped = 0;
$errors = [];

// Copy MP3 files from soundalerts and twitch subdirs
$sourceDirs = [$soundalert_path, $twitch_sound_path];
foreach ($sourceDirs as $dir) {
    if (!is_dir($dir)) continue;
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = $dir . '/' . $file;
        if (!is_file($fullPath)) continue;
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'mp3') continue;
        $dest = $media_path . '/' . $file;
        if (file_exists($dest)) {
            $skipped++;
            continue;
        }
        if (copy($fullPath, $dest)) {
            $copied++;
        } else {
            $errors[] = "Failed to copy: " . htmlspecialchars($file);
        }
    }
}

// If any hard errors stop before updating the flag
if (count($errors) > 0 && $copied === 0 && $skipped === 0) {
    echo json_encode(['success' => false, 'message' => 'Migration failed: ' . implode(', ', $errors)]);
    exit();
}

// Mark user as migrated in their profile table
$db = new mysqli($db_servername, $db_username, $db_password, $username);
if ($db->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $db->connect_error]);
    exit();
}

// Update or insert profile row
$db->query("UPDATE profile SET media_migrated = 1");
if ($db->affected_rows === 0) {
    $db->query("INSERT INTO profile (media_migrated) VALUES (1)");
}
$db->close();

$summary  = "Migration complete. {$copied} file(s) copied, {$skipped} already present.";
if (!empty($errors)) {
    $summary .= " Warnings: " . implode(', ', $errors);
}

echo json_encode([
    'success'  => true,
    'copied'   => $copied,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'message'  => $summary,
]);
?>
