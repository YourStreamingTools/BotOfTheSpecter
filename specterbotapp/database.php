<?php
/**
 * SpecterBotApp DB bootstrap.
 *
 * Preferred path: tenant-scoped SQL data API (no MySQL credentials on this host).
 *   - Key file: {keys_dir}/{username}.key  (streamer's website.users.api_key)
 *   - Config:   /var/www/config/sql_api.php
 *   - Helpers:  sql_api_select / sql_api_query / sql_api_insert / sql_api_update /
 *               sql_api_delete / sql_api_me / sql_api_ensure_modules
 *
 * Isolation: Host only chooses which key file to load. The SQL API maps the key
 * to a single username and opens only that tenant's schemas.
 *
 * Legacy mysqli ($conn / $conn_module) is available only when
 * config allow_legacy_mysql => true. Turn that off after modules are migrated.
 * Do not ship production with shared MySQL passwords on this host long-term.
 */
declare(strict_types=1);

require_once __DIR__ . '/sql_api_client.php';

/** @var string */
$username = '';
/** @var bool */
$sql_api_ok = false;
/** @var string|null */
$sql_api_error = null;
/** @var bool */
$sql_api_mode = false;
/** @var mysqli|null */
$conn = null;
/** @var mysqli|null */
$conn_module = null;
/** @var string */
$connection = '';

$cfg = sql_api_config();
$allowLegacy = !empty($cfg['allow_legacy_mysql']);

$boot = sql_api_bootstrap();
$username = (string)($boot['username'] ?? '');
$sql_api_ok = !empty($boot['ok']);
$sql_api_error = $boot['error'] ?? null;

if ($sql_api_ok) {
    $sql_api_mode = true;
    $connection = 'SQL API mode for tenant: ' . $username;
    // $conn stays null — use sql_api_* helpers
} elseif ($allowLegacy && $username !== '' && $username !== 'website') {
    // Legacy path: shared MySQL credentials (migration only)
    $dbCfgPath = '/var/www/config/database.php';
    if (!is_file($dbCfgPath)) {
        $dbCfgPath = dirname(__DIR__) . '/config/database.php';
    }
    if (is_file($dbCfgPath)) {
        require_once $dbCfgPath;
        $servername = $db_servername ?? '';
        $dbUser = $db_username ?? '';
        $dbPass = $db_password ?? '';
        if ($servername !== '' && $dbUser !== '') {
            $conn = @new mysqli($servername, $dbUser, $dbPass, $username);
            if ($conn->connect_error) {
                $sql_api_error = 'Legacy MySQL connect failed: ' . $conn->connect_error;
                $conn = null;
            } else {
                $connection = 'Legacy MySQL connected: ' . $username;
                $module_db = $username . '_custom_modules';
                $escaped = $conn->real_escape_string($module_db);
                $result = $conn->query(
                    "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$escaped}'"
                );
                if ($result && $result->num_rows > 0) {
                    $conn_module = @new mysqli($servername, $dbUser, $dbPass, $module_db);
                    if ($conn_module && $conn_module->connect_error) {
                        $conn_module = null;
                    }
                }
            }
        } else {
            $sql_api_error = ($sql_api_error ? $sql_api_error . '; ' : '')
                . 'Legacy MySQL enabled but database.php credentials empty';
        }
    } else {
        $sql_api_error = ($sql_api_error ? $sql_api_error . '; ' : '')
            . 'Legacy MySQL enabled but config/database.php missing';
    }
} elseif ($username === '' && $allowLegacy) {
    // Apex / no subdomain: optional website DB for home portal only (migration)
    $dbCfgPath = '/var/www/config/database.php';
    if (!is_file($dbCfgPath)) {
        $dbCfgPath = dirname(__DIR__) . '/config/database.php';
    }
    if (is_file($dbCfgPath)) {
        require_once $dbCfgPath;
        $servername = $db_servername ?? '';
        $dbUser = $db_username ?? '';
        $dbPass = $db_password ?? '';
        if ($servername !== '' && $dbUser !== '') {
            $conn = @new mysqli($servername, $dbUser, $dbPass, 'website');
            if ($conn->connect_error) {
                $sql_api_error = 'Legacy website connect failed: ' . $conn->connect_error;
                $conn = null;
            } else {
                $username = 'website';
                $connection = 'Legacy MySQL connected: website';
            }
        }
    }
}

if (!$sql_api_mode && $conn === null && $sql_api_error === null) {
    $sql_api_error = 'No SQL API key and legacy MySQL disabled — set keys_dir/{user}.key or allow_legacy_mysql';
}
