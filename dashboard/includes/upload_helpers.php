<?php
// Shared upload validation helpers. Used by media.php and alerts.php.
// Goals:
//   - Refuse a file when its actual MIME doesn't match its extension.
//   - Strip the user-supplied filename to safe characters.
//   - On name collision in the target directory, append a numeric suffix
//     instead of silently overwriting an existing file (which would
//     silently swap content under any DB row that referenced it).

if (!function_exists('upload_extension_mime_map')) {
    function upload_extension_mime_map() {
        return [
            'mp3'  => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg-3', 'audio/mpeg3'],
            'mp4'  => ['video/mp4', 'audio/mp4', 'application/mp4'],
            'webm' => ['video/webm', 'audio/webm'],
            'gif'  => ['image/gif'],
            'png'  => ['image/png'],
            'webp' => ['image/webp'],
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
        ];
    }
}

if (!function_exists('upload_detect_mime')) {
    function upload_detect_mime($tmpPath) {
        if (!is_file($tmpPath)) {
            return null;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($tmpPath);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
        return null;
    }
}

if (!function_exists('upload_validate_extension_and_mime')) {
    /**
     * Returns true when the file's detected MIME type is in the allowed
     * MIME list for the given extension. Treat $allowedExts as the
     * user-facing whitelist; this function is the second gate.
     */
    function upload_validate_extension_and_mime($tmpPath, $ext, array $allowedExts) {
        $ext = strtolower((string)$ext);
        if ($ext === '' || !in_array($ext, $allowedExts, true)) {
            return false;
        }
        $map = upload_extension_mime_map();
        if (!isset($map[$ext])) {
            return false;
        }
        $detected = upload_detect_mime($tmpPath);
        if ($detected === null) {
            return false;
        }
        return in_array($detected, $map[$ext], true);
    }
}

if (!function_exists('upload_sanitize_filename')) {
    /**
     * Strip the supplied filename to a safe base name. Keeps letters,
     * digits, underscore, hyphen, and dot; collapses runs of unsafe
     * characters into a single hyphen. Forces lowercase extension.
     */
    function upload_sanitize_filename($rawName, $ext) {
        $base = pathinfo((string)$rawName, PATHINFO_FILENAME);
        $base = (string)$base;
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $base = trim((string)$base, '-._');
        if ($base === '') {
            $base = 'file-' . bin2hex(random_bytes(4));
        }
        if (strlen($base) > 80) {
            $base = substr($base, 0, 80);
        }
        return $base . '.' . strtolower((string)$ext);
    }
}

if (!function_exists('upload_reencode_image')) {
    /**
     * Re-encode PNG/WebP to strip metadata. Returns true on success.
     * Falls back to copy when GD is unavailable.
     */
    function upload_reencode_image($srcPath, $destPath, $ext, $maxDim = 4096, $minDim = 1) {
        $ext = strtolower((string) $ext);
        if (!in_array($ext, ['png', 'webp'], true)) {
            return false;
        }
        if (!function_exists('imagecreatefromstring')) {
            return @copy($srcPath, $destPath);
        }
        $img = false;
        if ($ext === 'png' && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($srcPath);
        }
        if ($img === false) {
            $raw = @file_get_contents($srcPath);
            if ($raw === false) {
                return false;
            }
            $img = @imagecreatefromstring($raw);
        }
        if ($img === false) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0 || $w < $minDim || $h < $minDim || $w > $maxDim || $h > $maxDim) {
            imagedestroy($img);
            return false;
        }
        // GD defaults composite alpha onto black unless blending is disabled before save.
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $ok = false;
        if ($ext === 'png') {
            $ok = imagepng($img, $destPath, 6);
        } elseif ($ext === 'webp' && function_exists('imagewebp')) {
            $ok = imagewebp($img, $destPath, 85);
        }
        imagedestroy($img);
        return (bool) $ok;
    }
}

if (!function_exists('upload_unique_target')) {
    /**
     * Return a path inside $dir that does not yet exist. If $filename
     * already exists, append "-1", "-2", ... before the extension.
     */
    function upload_unique_target($dir, $filename) {
        $dir = rtrim((string)$dir, '/\\');
        $candidate = $dir . '/' . $filename;
        if (!file_exists($candidate)) {
            return ['path' => $candidate, 'name' => $filename];
        }
        $info = pathinfo($filename);
        $base = isset($info['filename']) ? $info['filename'] : 'file';
        $ext = isset($info['extension']) ? $info['extension'] : '';
        for ($i = 1; $i < 1000; $i++) {
            $newName = $base . '-' . $i . ($ext !== '' ? ('.' . $ext) : '');
            $candidate = $dir . '/' . $newName;
            if (!file_exists($candidate)) {
                return ['path' => $candidate, 'name' => $newName];
            }
        }
        $newName = $base . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? ('.' . $ext) : '');
        return ['path' => $dir . '/' . $newName, 'name' => $newName];
    }
}

if (!function_exists('upload_rename_is_ajax')) {
    function upload_rename_is_ajax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('upload_rename_error_message')) {
    function upload_rename_error_message($error) {
        switch ((string) $error) {
            case 'exists':
                return t('upload_rename_exists');
            case 'invalid':
                return t('upload_rename_invalid');
            case 'missing':
                return t('upload_rename_missing');
            case 'same':
                return t('upload_rename_same');
            default:
                return t('upload_rename_failed');
        }
    }
}

if (!function_exists('upload_rename_json')) {
    /**
     * Emit a JSON rename response and exit.
     *
     * @param array{ok?:bool, old?:string, new?:string, error?:string} $result
     */
    function upload_rename_json(array $result) {
        header('Content-Type: application/json');
        $ok = !empty($result['ok']);
        $new = isset($result['new']) ? (string) $result['new'] : '';
        echo json_encode([
            'success' => $ok,
            'old' => isset($result['old']) ? (string) $result['old'] : '',
            'new' => $new,
            'error' => $ok ? null : (isset($result['error']) ? (string) $result['error'] : 'failed'),
            'message' => $ok
                ? t('upload_rename_success', [basename($new)])
                : upload_rename_error_message($result['error'] ?? 'failed'),
        ]);
        exit;
    }
}

if (!function_exists('upload_rename_file')) {
    /**
     * Rename a file inside $dir. $oldName may be a basename or one subdirectory
     * (e.g. avatar/foo.png). The original extension is always kept.
     *
     * $mode:
     *   safe  — letters, digits, dot, underscore, hyphen (media / alerts)
     *   title — also keeps spaces (user music titles)
     *   login — lowercase Twitch login charset (legacy walk-ons)
     *
     * @return array{ok:bool, old?:string, new?:string, old_base?:string, new_base?:string, error?:string}
     */
    function upload_rename_file($dir, $oldName, $newName, $mode = 'safe') {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        $oldRel = ltrim(str_replace('\\', '/', (string) $oldName), '/');
        if ($dir === '' || $oldRel === '' || strpos($oldRel, '..') !== false) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $parts = explode('/', $oldRel);
        if (count($parts) > 2) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return ['ok' => false, 'error' => 'invalid'];
            }
        }
        $oldBase = basename($oldRel);
        $subdir = (count($parts) === 2) ? $parts[0] : '';
        $oldPath = $dir . '/' . $oldRel;
        if (!is_file($oldPath)) {
            return ['ok' => false, 'error' => 'missing'];
        }
        $ext = strtolower((string) pathinfo($oldBase, PATHINFO_EXTENSION));
        if ($ext === '') {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $newRaw = trim((string) $newName);
        $newRaw = str_replace(['\\', '/'], '', $newRaw);
        if ($newRaw === '') {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $typedExt = strtolower((string) pathinfo($newRaw, PATHINFO_EXTENSION));
        if ($typedExt === $ext) {
            $newRaw = (string) pathinfo($newRaw, PATHINFO_FILENAME);
        }

        if ($mode === 'title') {
            $base = preg_replace('/[^A-Za-z0-9._\- ]+/', '-', $newRaw);
            $base = preg_replace('/[ ]{2,}/', ' ', (string) $base);
            $base = trim((string) $base, " \t.-_");
        } elseif ($mode === 'login') {
            $base = strtolower(preg_replace('/[^a-z0-9_]+/i', '', $newRaw));
            $base = trim((string) $base, '_');
        } else {
            $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $newRaw);
            $base = trim((string) $base, '-._');
        }
        if ($base === '') {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if (strlen($base) > 80) {
            $base = substr($base, 0, 80);
        }

        $newBase = $base . '.' . $ext;
        $newRel = $subdir !== '' ? ($subdir . '/' . $newBase) : $newBase;
        if ($newRel === $oldRel) {
            return ['ok' => false, 'error' => 'same'];
        }
        $newPath = $dir . '/' . $newRel;
        if (file_exists($newPath)) {
            return ['ok' => false, 'error' => 'exists'];
        }
        if (!@rename($oldPath, $newPath)) {
            return ['ok' => false, 'error' => 'failed'];
        }
        return [
            'ok' => true,
            'old' => $oldRel,
            'new' => $newRel,
            'old_base' => $oldBase,
            'new_base' => $newBase,
        ];
    }
}

if (!function_exists('upload_rename_update_refs')) {
    /**
     * Rewrite filename-as-identity columns in the per-user DB.
     * Table/column names are hardcoded from the known schema.
     */
    function upload_rename_update_refs($db, $oldValue, $newValue) {
        if (!$db || !is_object($db) || $oldValue === '' || $newValue === '' || $oldValue === $newValue) {
            return 0;
        }
        $pairs = [
            ['sound_alerts', 'sound_mapping'],
            ['video_alerts', 'video_mapping'],
            ['twitch_sound_alerts', 'sound_mapping'],
            ['walkons', 'media_file'],
            ['maker_project_images', 'media_file'],
            ['twitch_alerts', 'alert_image'],
            ['twitch_alerts', 'alert_sound'],
            ['avatar_settings', 'closed_image'],
            ['avatar_settings', 'open_image'],
            ['avatar_settings', 'closed_blink_image'],
            ['avatar_settings', 'open_blink_image'],
            ['avatar_settings', 'blink_image'],
        ];
        $updated = 0;
        foreach ($pairs as $pair) {
            $stmt = $db->prepare("UPDATE `{$pair[0]}` SET `{$pair[1]}` = ? WHERE `{$pair[1]}` = ?");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('ss', $newValue, $oldValue);
            if ($stmt->execute()) {
                $updated += (int) $stmt->affected_rows;
            }
            $stmt->close();
        }
        return $updated;
    }
}

if (!function_exists('upload_rename_music_public')) {
    function upload_rename_music_public($privateDir, $publicDir, $oldBase, $newBase) {
        $publicDir = rtrim((string) $publicDir, '/\\');
        $privateDir = rtrim((string) $privateDir, '/\\');
        $oldBase = basename((string) $oldBase);
        $newBase = basename((string) $newBase);
        if ($publicDir === '' || $newBase === '') {
            return;
        }
        $oldPub = $publicDir . '/' . $oldBase;
        if (is_link($oldPub) || is_file($oldPub)) {
            @unlink($oldPub);
        }
        $newPriv = $privateDir . '/' . $newBase;
        $newPub = $publicDir . '/' . $newBase;
        if (is_link($newPub) || is_file($newPub)) {
            @unlink($newPub);
        }
        if (!is_file($newPriv)) {
            return;
        }
        if (!@symlink($newPriv, $newPub)) {
            @copy($newPriv, $newPub);
        }
        @chmod($newPub, 0644);
    }
}

if (!function_exists('upload_rename_music_filter')) {
    function upload_rename_music_filter($db, $oldFile, $newFile) {
        if (!$db || !is_object($db) || $oldFile === '' || $newFile === '' || $oldFile === $newFile) {
            return false;
        }
        $oldKey = 'USER:' . $oldFile;
        $newKey = 'USER:' . $newFile;
        $res = @$db->query("SELECT music_playlist_filter FROM streamer_preferences WHERE id = 1");
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        $res->free();
        $filter = [];
        if (!empty($row['music_playlist_filter'])) {
            $decoded = json_decode($row['music_playlist_filter'], true);
            if (is_array($decoded)) {
                $filter = $decoded;
            }
        }
        $changed = false;
        foreach ($filter as $i => $key) {
            if ($key === $oldKey) {
                $filter[$i] = $newKey;
                $changed = true;
            } elseif ($key === $oldFile) {
                $filter[$i] = $newFile;
                $changed = true;
            }
        }
        if (!$changed) {
            return true;
        }
        $json = json_encode(array_values($filter));
        $stmt = $db->prepare("UPDATE streamer_preferences SET music_playlist_filter = ? WHERE id = 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $json);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}
