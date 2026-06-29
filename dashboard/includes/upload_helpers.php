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
