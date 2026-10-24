<?php
// Storage breakdown for the beta Profile card. Read-only: same folders and
// counting rules as dashboard/includes/storage_used.php (files plus one level
// of subfolders, the twitch subfolder counted on its own), split by type.
require __DIR__ . '/../_session.php';
beta_session_start();
if (empty($_SESSION['access_token']) || empty($_SESSION['username'])) beta_json(401, ['success' => false, 'session_expired' => true]);
$username = basename((string) $_SESSION['username']);
session_write_close();
if ($username === '' || $username === '.' || $username === '..') beta_json(400, ['success' => false]);

function beta_storage_size(string $directory): int
{
    $total = 0;
    if (!is_dir($directory)) return 0;
    foreach (scandir($directory) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $directory . '/' . $file;
        if (is_file($path)) {
            $total += filesize($path);
        } elseif (is_dir($path) && $file !== 'twitch') {
            foreach (scandir($path) ?: [] as $sub) {
                if ($sub !== '.' && $sub !== '..' && is_file($path . '/' . $sub)) $total += filesize($path . '/' . $sub);
            }
        }
    }
    return $total;
}

$media = "/var/www/media/$username";
$sound = "/var/www/soundalerts/$username";
$parts = [['key' => 'walkons', 'label' => 'Walk-ons', 'bytes' => beta_storage_size("/var/www/walkons/$username")]];
if (is_dir($media)) {
    $parts[] = ['key' => 'media', 'label' => 'Media library', 'bytes' => beta_storage_size($media)];
} else {
    $parts[] = ['key' => 'sounds', 'label' => 'Sound alerts', 'bytes' => beta_storage_size($sound) + beta_storage_size("$sound/twitch")];
}
$parts[] = ['key' => 'videos', 'label' => 'Video alerts', 'bytes' => beta_storage_size("/var/www/videoalerts/$username")];
$parts[] = ['key' => 'music', 'label' => 'Music', 'bytes' => beta_storage_size("/var/www/private/music_user/$username")];
beta_json(200, ['success' => true, 'parts' => $parts]);
