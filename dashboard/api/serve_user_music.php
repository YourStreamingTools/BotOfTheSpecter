<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();

// Load translations so user-facing error responses are localized.
if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    $i18nPath = __DIR__ . '/../lang/i18n.php';
    if (file_exists($i18nPath)) {
        include_once $i18nPath;
    }
    if (!function_exists('t')) {
        function t($key, $replacements = [])
        {
            return $key;
        }
    }
}

// Serve user-uploaded music files to the owner only
if (!isset($_SESSION['access_token']) || empty($_SESSION['username'])) {
    http_response_code(403);
    exit(t('serve_user_music_error_forbidden'));
}
$username = $_SESSION['username'];
if (empty($_GET['file'])) {
    http_response_code(400);
    exit(t('serve_user_music_error_missing_file'));
}
$filename = basename($_GET['file']);
// basic validation
if (!preg_match('/^[A-Za-z0-9_\-\. ]+\.mp3$/i', $filename)) {
    http_response_code(400);
    exit(t('serve_user_music_error_invalid_file'));
}
$userMusicPath = "/var/www/private/music_user/" . $username;
$fullPath = $userMusicPath . '/' . $filename;
if (!is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit(t('serve_user_music_error_not_found'));
}
$size   = filesize($fullPath);
$fm     = @fopen($fullPath, 'rb');
if (!$fm) {
    http_response_code(500);
    exit(t('serve_user_music_error_open_failed'));
}
$begin  = 0;
$end    = $size - 1;
// Support for HTTP Range header (partial content)
if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $begin = intval($matches[1]);
        if ($matches[2] !== '') {
            $end = intval($matches[2]);
        }
        if ($begin > $end || $end >= $size) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes */$size");
            exit;
        }
        header('HTTP/1.1 206 Partial Content');
    }
}
$length = $end - $begin + 1;
header('Content-Type: audio/mpeg');
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header("Content-Range: bytes $begin-$end/$size");
// Output the requested range
fseek($fm, $begin);
$bufferSize = 8192;
while (!feof($fm) && ($p = ftell($fm)) <= $end) {
    $bytesToRead = $bufferSize;
    if ($p + $bytesToRead > $end) {
        $bytesToRead = $end - $p + 1;
    }
    $data = fread($fm, $bytesToRead);
    echo $data;
    flush();
}
fclose($fm);
exit;
