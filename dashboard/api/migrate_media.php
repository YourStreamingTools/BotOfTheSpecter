<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
header('Content-Type: application/json');

require_once '/var/www/lib/require_auth_ajax.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

include '/var/www/config/database.php';
include '/var/www/config/twitch.php';
$username = $_SESSION['username'] ?? '';
if (empty($username)) {
    echo json_encode(['success' => false, 'message' => 'No username in session.']);
    exit();
}

// Source dirs (old per-trigger layout) -> unified library
$soundalert_path     = "/var/www/soundalerts/" . $username;
$twitch_sound_path   = $soundalert_path . "/twitch";
$videoalert_path     = "/var/www/videoalerts/" . $username;
$walkon_path         = "/var/www/walkons/" . $username;
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

// Each source describes a directory and the file extensions to copy from it.
// Walkons retain their filename so the post-copy auto-link step can match
// them back to a Twitch login.
$sources = [
    ['path' => $soundalert_path,   'exts' => ['mp3']],
    ['path' => $twitch_sound_path, 'exts' => ['mp3']],
    ['path' => $videoalert_path,   'exts' => ['mp4']],
    ['path' => $walkon_path,       'exts' => ['mp3']],
];
foreach ($sources as $src) {
    if (!is_dir($src['path'])) continue;
    foreach (scandir($src['path']) as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = $src['path'] . '/' . $file;
        if (!is_file($fullPath)) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $src['exts'], true)) continue;
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

// Connect to user DB for the walkon auto-link step + the migrated flag
$db = new mysqli($db_servername, $db_username, $db_password, $username);
if ($db->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $db->connect_error]);
    exit();
}

$walkonsLinked = 0;
$walkonsHelixLookups = 0;
$walkonsFailed = 0;
if (is_dir($walkon_path)) {
    // Bot Helix credentials for unknown-login lookups
    $botClientId = '';
    $botOauth    = '';
    $bconn = new mysqli($db_servername, $db_username, $db_password, 'website');
    if (!$bconn->connect_error) {
        $bres = $bconn->query("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1");
        if ($bres && ($brow = $bres->fetch_assoc())) {
            foreach (['twitch_client_id', 'client_id', 'clientID'] as $k) {
                if (!empty($brow[$k])) { $botClientId = trim($brow[$k]); break; }
            }
            foreach (['twitch_oauth_api_token', 'oauth', 'chat_oauth_token', 'twitch_oauth_token', 'twitch_access_token', 'bot_oauth_token'] as $k) {
                if (!empty($brow[$k])) { $botOauth = trim($brow[$k]); break; }
            }
        }
        $bconn->close();
    }
    $resolveLoginToUserId = function ($login) use ($db, $botClientId, $botOauth, &$walkonsHelixLookups) {
        // seen_users cache first
        $seenStmt = $db->prepare("SELECT username FROM seen_users WHERE LOWER(username) = ? LIMIT 1");
        if ($seenStmt) {
            $loginLower = strtolower($login);
            $seenStmt->bind_param('s', $loginLower);
            $seenStmt->execute();
            $seenRes = $seenStmt->get_result();
            $seenStmt->close();
            // seen_users doesn't store user_id directly - we still need Helix for that
            // but the existence check tells us it's a known chatter
        }
        // Helix lookup
        if ($botClientId === '' || $botOauth === '') return null;
        $walkonsHelixLookups++;
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $botOauth\r\nClient-Id: $botClientId\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $url = 'https://api.twitch.tv/helix/users?login=' . urlencode($login);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (empty($data['data'][0]['id'])) return null;
        return [
            'user_id'   => $data['data'][0]['id'],
            'user_name' => $data['data'][0]['login'],
        ];
    };
    $upsertWalkon = $db->prepare(
        "INSERT INTO walkons (twitch_user_id, twitch_user_name, media_file) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE media_file = VALUES(media_file), twitch_user_name = VALUES(twitch_user_name)"
    );
    foreach (scandir($walkon_path) as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = $walkon_path . '/' . $file;
        if (!is_file($fullPath)) continue;
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'mp3') continue;
        $login = strtolower(pathinfo($file, PATHINFO_FILENAME));
        if ($login === '') continue;
        $resolved = $resolveLoginToUserId($login);
        if (!$resolved) {
            $walkonsFailed++;
            $errors[] = "Could not resolve walkon login: " . htmlspecialchars($login);
            continue;
        }
        if ($upsertWalkon) {
            $upsertWalkon->bind_param('sss', $resolved['user_id'], $resolved['user_name'], $file);
            if ($upsertWalkon->execute()) {
                $walkonsLinked++;
            } else {
                $walkonsFailed++;
            }
        }
    }
    if ($upsertWalkon) $upsertWalkon->close();
}

// Mark user as migrated in their profile table (per-user DB)
$db->query("UPDATE profile SET media_migrated = 1");
if ($db->affected_rows === 0) {
    $db->query("INSERT INTO profile (media_migrated) VALUES (1)");
}
$db->close();

// Also set the global flag on website.users.new_media so the bot, overlays,
// and API can route on it without hopping to the per-user DB.
$website = new mysqli($db_servername, $db_username, $db_password, 'website');
if (!$website->connect_error) {
    if ($flagStmt = $website->prepare("UPDATE users SET new_media = 1 WHERE username = ?")) {
        $flagStmt->bind_param('s', $username);
        $flagStmt->execute();
        $flagStmt->close();
    }
    $website->close();
}

$summary  = "Migration complete. {$copied} file(s) copied, {$skipped} already present.";
if ($walkonsLinked > 0 || $walkonsFailed > 0) {
    $summary .= " {$walkonsLinked} walkon(s) auto-linked";
    if ($walkonsFailed > 0) {
        $summary .= ", {$walkonsFailed} could not be linked (you can add them manually in the new UI)";
    }
    $summary .= ".";
}
if (!empty($errors)) {
    $summary .= " Warnings: " . implode(', ', $errors);
}

echo json_encode([
    'success'          => true,
    'copied'           => $copied,
    'skipped'          => $skipped,
    'walkons_linked'   => $walkonsLinked,
    'walkons_failed'   => $walkonsFailed,
    'helix_lookups'    => $walkonsHelixLookups,
    'errors'           => $errors,
    'message'          => $summary,
]);
?>
