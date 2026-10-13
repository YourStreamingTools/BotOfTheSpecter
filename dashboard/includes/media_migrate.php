<?php
// Copy a channel's old sound, video, and walkon files into the unified media library
// and mark website.users.new_media. Called by the admin migration page.
if (!isset($db_servername)) {
    include '/var/www/config/database.php';
}
if (!isset($clientID)) {
    include '/var/www/config/twitch.php';
}

function migrate_user_media_library($username) {
    global $db_servername, $db_username, $db_password, $clientID, $oauth;
    $username = trim((string) $username);
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $username)) {
        return ['success' => false, 'message' => 'Invalid username.'];
    }

    $soundalert_path   = "/var/www/soundalerts/" . $username;
    $twitch_sound_path = $soundalert_path . "/twitch";
    $videoalert_path   = "/var/www/videoalerts/" . $username;
    $walkon_path       = "/var/www/walkons/" . $username;
    $media_path        = "/var/www/media/" . $username;
    $media_base        = "/var/www/media";

    if (!is_dir($media_base) && !mkdir($media_base, 0755, true)) {
        return ['success' => false, 'message' => 'Could not create media base directory.'];
    }
    if (!is_dir($media_path) && !mkdir($media_path, 0755, true)) {
        return ['success' => false, 'message' => 'Could not create user media directory.'];
    }
    chmod($media_path, 0755);

    $copied = 0;
    $skipped = 0;
    $errors = [];
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
                $errors[] = "Failed to copy: " . $file;
            }
        }
    }

    if (count($errors) > 0 && $copied === 0 && $skipped === 0) {
        return ['success' => false, 'message' => 'Migration failed: ' . implode(', ', $errors)];
    }

    $db = new mysqli($db_servername, $db_username, $db_password, $username);
    if ($db->connect_error) {
        return ['success' => false, 'message' => 'Database connection failed: ' . $db->connect_error];
    }

    $walkonsLinked = 0;
    $walkonsHelixLookups = 0;
    $walkonsFailed = 0;
    if (is_dir($walkon_path)) {
        $botClientId = trim((string) ($clientID ?? ''));
        $botOauth = trim((string) ($oauth ?? ''));
        $resolveLoginToUserId = function ($login) use ($botClientId, $botOauth, &$walkonsHelixLookups) {
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
                'user_id' => $data['data'][0]['id'],
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
                $errors[] = "Could not resolve walkon login: " . $login;
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

    $db->query("UPDATE profile SET media_migrated = 1");
    if ($db->affected_rows === 0) {
        $db->query("INSERT INTO profile (media_migrated) VALUES (1)");
    }
    $db->close();

    $website = new mysqli($db_servername, $db_username, $db_password, 'website');
    if (!$website->connect_error) {
        if ($flagStmt = $website->prepare("UPDATE users SET new_media = 1 WHERE username = ?")) {
            $flagStmt->bind_param('s', $username);
            $flagStmt->execute();
            $flagStmt->close();
        }
        $website->close();
    }

    $summary = "Migration complete. {$copied} file(s) copied, {$skipped} already present.";
    if ($walkonsLinked > 0 || $walkonsFailed > 0) {
        $summary .= " {$walkonsLinked} walkon(s) auto-linked";
        if ($walkonsFailed > 0) {
            $summary .= ", {$walkonsFailed} could not be linked";
        }
        $summary .= ".";
    }
    if (!empty($errors)) {
        $summary .= " Warnings: " . implode(', ', $errors);
    }

    return [
        'success' => true,
        'copied' => $copied,
        'skipped' => $skipped,
        'walkons_linked' => $walkonsLinked,
        'walkons_failed' => $walkonsFailed,
        'helix_lookups' => $walkonsHelixLookups,
        'errors' => $errors,
        'message' => $summary,
    ];
}
