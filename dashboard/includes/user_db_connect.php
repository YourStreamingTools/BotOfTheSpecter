<?php
/**
 * Open the per-user MySQL database as $db without bulk-loading every table.
 *
 * Prefer this on FAST SHELL pages that only need a connection (or a couple of
 * targeted queries). The full includes/user_db.php still exists for legacy
 * pages that expect $commands / $watchTimeData / etc. pre-hydrated.
 */
require_once '/var/www/config/database.php';

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

$dbname = $_SESSION['username'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', (string) $dbname)) {
    die(t('user_db_error_connection_failed', ['Invalid session username']));
}

if (!isset($db) || !($db instanceof mysqli)) {
    $db = new mysqli($db_servername, $db_username, $db_password, $dbname);
    if ($db->connect_error) {
        die(t('user_db_error_connection_failed', [$db->connect_error]));
    }
    if (method_exists($db, 'set_charset')) {
        $db->set_charset('utf8mb4');
    }
}
