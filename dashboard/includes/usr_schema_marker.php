<?php
// Per-user "schema checked" marker for includes/usr_database.php.
// Kept outside the session: the check runs after layout.php has sent the page,
// and PHP refuses to reopen a session once headers are out, so a session flag never saved.
// The fingerprint changes whenever usr_database.php changes, so a deploy re-runs the check.

if (!defined('USR_SCHEMA_MARKER_DIR')) {
    define('USR_SCHEMA_MARKER_DIR', '/var/www/cache/usr_schema');
}

if (!function_exists('usr_schema_marker_fingerprint')) {
    function usr_schema_marker_fingerprint()
    {
        static $fingerprint = null;
        if ($fingerprint === null) {
            $fingerprint = (string) @md5_file(__DIR__ . '/usr_database.php');
        }
        return $fingerprint;
    }
}

if (!function_exists('usr_schema_marker_path')) {
    function usr_schema_marker_path($username)
    {
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $username)) {
            return null;
        }
        return USR_SCHEMA_MARKER_DIR . '/' . $username . '.json';
    }
}

if (!function_exists('usr_schema_marker_read')) {
    function usr_schema_marker_read($username)
    {
        $path = usr_schema_marker_path($username);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('usr_schema_marker_is_current')) {
    // True when the last check for this user succeeded against the current usr_database.php.
    function usr_schema_marker_is_current($username)
    {
        $marker = usr_schema_marker_read($username);
        return $marker !== null
            && !empty($marker['ok'])
            && ($marker['fingerprint'] ?? '') === usr_schema_marker_fingerprint();
    }
}

if (!function_exists('usr_schema_marker_write')) {
    function usr_schema_marker_write($username, $ok, array $logs)
    {
        $path = usr_schema_marker_path($username);
        if ($path === null) {
            return false;
        }
        if (!is_dir(USR_SCHEMA_MARKER_DIR) && !@mkdir(USR_SCHEMA_MARKER_DIR, 0775, true) && !is_dir(USR_SCHEMA_MARKER_DIR)) {
            error_log('usr_schema_marker: cannot create ' . USR_SCHEMA_MARKER_DIR);
            return false;
        }
        $json = json_encode([
            'ok' => (bool) $ok,
            'fingerprint' => usr_schema_marker_fingerprint(),
            'checked_at' => time(),
            'logs' => array_values($logs),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Write then rename so a concurrent reader never sees a half-written file.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            error_log('usr_schema_marker: cannot write ' . $tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            error_log('usr_schema_marker: cannot rename ' . $tmp);
            return false;
        }
        return true;
    }
}
