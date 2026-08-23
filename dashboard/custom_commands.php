<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('navbar_edit_custom_commands');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        $stmt = $db->prepare("SELECT * FROM custom_commands");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'commands' => $rows]);
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$jsonText = file_get_contents(__DIR__ . '/../api/builtin_commands.json');
$builtinCommands = json_decode($jsonText, true);
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);
$status = "";
$notification_status = "";

// Ensure cooldown_bucket exists before add/edit saves (layout migrates at render time, after POST)
$colCheck = $db->query("SHOW COLUMNS FROM custom_commands LIKE 'cooldown_bucket'");
if ($colCheck && $colCheck->num_rows === 0) {
    $db->query("ALTER TABLE custom_commands ADD cooldown_bucket VARCHAR(255) DEFAULT 'default'");
}
if ($colCheck) {
    $colCheck->close();
}

// Permission mapping (display to db)
$permissionsMap = [
    "Everyone" => "everyone",
    "VIPs" => "vip",
    "All Subscribers" => "all-subs",
    "Tier 1 Subscriber" => "t1-sub",
    "Tier 2 Subscriber" => "t2-sub",
    "Tier 3 Subscriber" => "t3-sub",
    "Mods" => "mod",
    "Broadcaster" => "broadcaster"
];

// Reverse mapping (db to display)
$permissionsMapReverse = array_flip($permissionsMap);

// Cooldown bucket values (match builtin_commands / bot resolve_cooldown_bucket_key)
$cooldownBucketOptions = [
    'default' => 'custom_commands_cooldown_bucket_default',
    'user' => 'custom_commands_cooldown_bucket_user',
    'mod' => 'custom_commands_cooldown_bucket_mod',
];

function sanitize_command_name($command)
{
    $command = strtolower(str_replace(' ', '', (string)$command));
    return preg_replace('/[^a-z0-9]/', '', $command);
}

function sanitize_cooldown_bucket($bucket)
{
    $bucket = strtolower(trim((string)$bucket));
    if ($bucket === 'mods') {
        $bucket = 'mod';
    }
    $allowed = ['default', 'user', 'mod'];
    return in_array($bucket, $allowed, true) ? $bucket : 'default';
}

function normalize_import_permission($value)
{
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return 'everyone';
    }
    $value = preg_replace('/[\s_]+/', '-', $value);
    $value = preg_replace('/-+/', '-', $value);
    $map = [
        'everyone' => 'everyone',
        'all' => 'everyone',
        'any' => 'everyone',
        'public' => 'everyone',
        'vip' => 'vip',
        'vips' => 'vip',
        'all-subs' => 'all-subs',
        'all-subscribers' => 'all-subs',
        'all-subscriber' => 'all-subs',
        'subscribers' => 'all-subs',
        'subscriber' => 'all-subs',
        'subs' => 'all-subs',
        't1-sub' => 't1-sub',
        't1' => 't1-sub',
        'tier-1' => 't1-sub',
        'tier-1-sub' => 't1-sub',
        'tier-1-subscriber' => 't1-sub',
        't2-sub' => 't2-sub',
        't2' => 't2-sub',
        'tier-2' => 't2-sub',
        'tier-2-sub' => 't2-sub',
        'tier-2-subscriber' => 't2-sub',
        't3-sub' => 't3-sub',
        't3' => 't3-sub',
        'tier-3' => 't3-sub',
        'tier-3-sub' => 't3-sub',
        'tier-3-subscriber' => 't3-sub',
        'mod' => 'mod',
        'mods' => 'mod',
        'moderator' => 'mod',
        'moderators' => 'mod',
        'broadcaster' => 'broadcaster',
        'streamer' => 'broadcaster',
        'owner' => 'broadcaster',
    ];
    return $map[$value] ?? null;
}

function sanitize_import_status($status)
{
    $status = strtolower(trim((string)$status));
    if ($status === '') {
        return 'Enabled';
    }
    if (in_array($status, ['enabled', 'enable', 'on', 'true', '1', 'yes', 'active'], true)) {
        return 'Enabled';
    }
    if (in_array($status, ['disabled', 'disable', 'off', 'false', '0', 'no', 'inactive'], true)) {
        return 'Disabled';
    }
    return null;
}

function custom_commands_detect_csv_delimiter($firstLine)
{
    $comma = substr_count((string)$firstLine, ',');
    $semi = substr_count((string)$firstLine, ';');
    return $semi > $comma ? ';' : ',';
}

function custom_commands_builtin_tokens($builtinCommands)
{
    $tokens = [];
    if (!isset($builtinCommands['commands']) || !is_array($builtinCommands['commands'])) {
        return $tokens;
    }
    foreach ($builtinCommands['commands'] as $name => $meta) {
        $name = strtolower(trim((string)$name));
        if ($name !== '') {
            $tokens[$name] = true;
        }
        if (!is_array($meta)) {
            continue;
        }
        $aliases = $meta['aliases'] ?? [];
        if (!is_array($aliases)) {
            $aliases = [$aliases];
        }
        foreach ($aliases as $alias) {
            $alias = strtolower(trim((string)$alias));
            if ($alias !== '') {
                $tokens[$alias] = true;
            }
        }
    }
    return $tokens;
}

function custom_commands_import_template_row_blocked(array $row, array $builtinTokens)
{
    $command = strtolower(trim((string)($row[0] ?? '')));
    if ($command === '' || isset($builtinTokens[$command])) {
        return true;
    }
    $aliasesRaw = (string)($row[5] ?? '');
    foreach (explode(',', $aliasesRaw) as $alias) {
        $alias = strtolower(trim($alias));
        if ($alias !== '' && isset($builtinTokens[$alias])) {
            return true;
        }
    }
    return false;
}

function custom_commands_import_template_csv($builtinTokens = [])
{
    $fh = fopen('php://temp', 'r+');
    $rows = [
        ['command', 'response', 'cooldown', 'cooldown_bucket', 'permission', 'aliases', 'status'],
    ];
    $candidates = [
        ['welcome', 'Hello (user)! Welcome to the stream.', '15', 'default', 'everyone', 'hi', 'Enabled'],
        ['hydrate', 'Drink some water, (user)!', '15', 'user', 'everyone', '', 'Enabled'],
        ['merch', 'Merch is linked in the Twitch panels.', '30', 'default', 'everyone', '', 'Enabled'],
    ];
    foreach ($candidates as $row) {
        if (custom_commands_import_template_row_blocked($row, $builtinTokens)) {
            continue;
        }
        $rows[] = $row;
    }
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '\\');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv === false ? '' : $csv;
}

function custom_commands_csv_header_map($headerRow)
{
    $aliases = [
        'command' => 'command',
        'cmd' => 'command',
        'name' => 'command',
        'response' => 'response',
        'message' => 'response',
        'reply' => 'response',
        'cooldown' => 'cooldown',
        'cd' => 'cooldown',
        'cooldown_bucket' => 'cooldown_bucket',
        'cooldownbucket' => 'cooldown_bucket',
        'bucket' => 'cooldown_bucket',
        'permission' => 'permission',
        'perm' => 'permission',
        'level' => 'permission',
        'aliases' => 'aliases',
        'alias' => 'aliases',
        'status' => 'status',
    ];
    $map = [];
    foreach ((array)$headerRow as $index => $raw) {
        $key = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$raw)));
        $key = str_replace([' ', '-'], ['_', '_'], $key);
        if (isset($aliases[$key]) && !isset($map[$aliases[$key]])) {
            $map[$aliases[$key]] = (int)$index;
        }
    }
    return $map;
}

function load_custom_command_taken_tokens($db)
{
    $taken = [];
    $res = $db->query('SELECT command, aliases FROM custom_commands');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $name = strtolower(trim((string)($row['command'] ?? '')));
            if ($name !== '') {
                $taken[$name] = true;
            }
            if (!empty($row['aliases'])) {
                foreach (explode(',', (string)$row['aliases']) as $other) {
                    $other = strtolower(trim($other));
                    if ($other !== '') {
                        $taken[$other] = true;
                    }
                }
            }
        }
        $res->close();
    }
    return $taken;
}

function custom_commands_csv_cell($row, $map, $field)
{
    if (!isset($map[$field])) {
        return '';
    }
    $index = $map[$field];
    return isset($row[$index]) ? trim((string)$row[$index]) : '';
}

function custom_commands_read_import_rows($tmpPath)
{
    $raw = file_get_contents($tmpPath);
    if ($raw === false || trim($raw) === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $firstLine = strtok($raw, "\r\n");
    if ($firstLine === false || trim($firstLine) === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    $delimiter = custom_commands_detect_csv_delimiter($firstLine);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter, '"', '\\');
    if (!is_array($header) || empty($header)) {
        fclose($fh);
        return ['ok' => false, 'error' => 'headers'];
    }
    $map = custom_commands_csv_header_map($header);
    if (!isset($map['command'], $map['response'])) {
        fclose($fh);
        return ['ok' => false, 'error' => 'headers'];
    }
    $rows = [];
    $rowNumber = 1;
    while (($data = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $rowNumber++;
        if ($data === [null] || $data === false) {
            continue;
        }
        $empty = true;
        foreach ($data as $cell) {
            if (trim((string)$cell) !== '') {
                $empty = false;
                break;
            }
        }
        if ($empty) {
            continue;
        }
        $rows[] = ['number' => $rowNumber, 'data' => $data];
    }
    fclose($fh);
    return ['ok' => true, 'map' => $map, 'rows' => $rows];
}

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'import_template') {
    ob_clean();
    $csv = custom_commands_import_template_csv(custom_commands_builtin_tokens($builtinCommands ?? []));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="specter-custom-commands-template.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . $csv;
    exit();
}

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'export') {
    ob_clean();
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['command', 'response', 'cooldown', 'cooldown_bucket', 'permission', 'aliases', 'status'], ',', '"', '\\');
    $exportStmt = $db->prepare("SELECT command, response, cooldown, cooldown_bucket, permission, aliases, status FROM custom_commands ORDER BY command ASC");
    if ($exportStmt) {
        $exportStmt->execute();
        $exportResult = $exportStmt->get_result();
        while ($exportRow = $exportResult->fetch_assoc()) {
            $statusValue = sanitize_import_status($exportRow['status'] ?? 'Enabled');
            fputcsv($fh, [
                (string)($exportRow['command'] ?? ''),
                (string)($exportRow['response'] ?? ''),
                (string)((int)($exportRow['cooldown'] ?? 15)),
                sanitize_cooldown_bucket($exportRow['cooldown_bucket'] ?? 'default'),
                (string)($exportRow['permission'] ?? 'everyone'),
                (string)($exportRow['aliases'] ?? ''),
                $statusValue !== null ? $statusValue : 'Enabled',
            ], ',', '"', '\\');
        }
        $exportStmt->close();
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    $safeUser = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)$username));
    $filename = 'specter-custom-commands' . ($safeUser !== '' ? '-' . $safeUser : '') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . ($csv === false ? '' : $csv);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    if ($_POST['action'] === 'get_random_pick_options') {
        $commandName = sanitize_command_name($_POST['command_name'] ?? '');
        if ($commandName === '') {
            echo json_encode(['success' => false, 'message' => t('custom_commands_msg_command_name_required')]);
            exit;
        }
        $stmt = $db->prepare("SELECT many_options_enabled, options FROM custom_command_random_pick_options WHERE command = ?");
        $stmt->bind_param('s', $commandName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $manyOptionsEnabled = false;
        $options = [];
        if ($row) {
            $manyOptionsEnabled = ((int)($row['many_options_enabled'] ?? 0) === 1);
            $decoded = json_decode($row['options'] ?? '[]', true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }
                    $value = trim((string)$item);
                    if ($value !== '') {
                        $options[] = $value;
                    }
                }
            }
        }
        echo json_encode([
            'success' => true,
            'many_options_enabled' => $manyOptionsEnabled,
            'options' => $options,
        ]);
        exit;
    }
    if ($_POST['action'] === 'save_random_pick_options') {
        $commandName = sanitize_command_name($_POST['command_name'] ?? '');
        if ($commandName === '') {
            echo json_encode(['success' => false, 'message' => t('custom_commands_msg_command_name_required')]);
            exit;
        }
        $decoded = json_decode($_POST['options'] ?? '[]', true);
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_msg_options_invalid')]);
            exit;
        }
        $cleanOptions = [];
        foreach ($decoded as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $value = trim((string)$item);
            if ($value !== '') {
                $cleanOptions[] = $value;
            }
        }
        $manyOptionsEnabled = 0;
        if (isset($_POST['many_options_enabled'])) {
            $rawEnabled = strtolower((string)$_POST['many_options_enabled']);
            $manyOptionsEnabled = ($rawEnabled === '1' || $rawEnabled === 'true') ? 1 : 0;
        }
        $optionsJson = json_encode(array_values($cleanOptions), JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO custom_command_random_pick_options (command, many_options_enabled, options) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE many_options_enabled = VALUES(many_options_enabled), options = VALUES(options)");
        $stmt->bind_param('sis', $commandName, $manyOptionsEnabled, $optionsJson);
        $success = $stmt->execute();
        $stmt->close();
        if (!$success) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_msg_save_options_failed')]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'saved_count' => count($cleanOptions),
            'many_options_enabled' => ($manyOptionsEnabled === 1),
        ]);
        exit;
    }
    if ($_POST['action'] === 'import_commands') {
        $maxBytes = 1048576;
        $maxRows = 1000;
        $maxResponse = 500;
        if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_no_file')]);
            exit;
        }
        $file = $_FILES['import_file'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_no_file')]);
            exit;
        }
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE || ((int)($file['size'] ?? 0) > $maxBytes)) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_too_large')]);
            exit;
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_generic')]);
            exit;
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_generic')]);
            exit;
        }
        $originalName = (string)($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_type')]);
            exit;
        }
        $parsed = custom_commands_read_import_rows($file['tmp_name']);
        if (!$parsed['ok']) {
            $errorKey = $parsed['error'] === 'empty' ? 'custom_commands_import_err_empty' : 'custom_commands_import_err_headers';
            echo json_encode(['success' => false, 'message' => t($errorKey)]);
            exit;
        }
        $builtinLookup = custom_commands_builtin_tokens($builtinCommands ?? []);
        $taken = load_custom_command_taken_tokens($db);
        $imported = 0;
        $skippedExisting = [];
        $skippedBuiltin = [];
        $skippedInvalid = [];
        $aliasWarnings = [];
        $truncated = false;
        $dataRows = $parsed['rows'];
        if (count($dataRows) > $maxRows) {
            $dataRows = array_slice($dataRows, 0, $maxRows);
            $truncated = true;
        }
        $insertSTMT = $db->prepare("INSERT INTO custom_commands (command, response, status, cooldown, cooldown_bucket, permission, aliases) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$insertSTMT) {
            echo json_encode(['success' => false, 'message' => t('custom_commands_import_err_generic')]);
            exit;
        }
        foreach ($dataRows as $entry) {
            $rowNumber = (int)$entry['number'];
            $row = $entry['data'];
            $map = $parsed['map'];
            $commandName = sanitize_command_name(custom_commands_csv_cell($row, $map, 'command'));
            $response = custom_commands_csv_cell($row, $map, 'response');
            if ($commandName === '') {
                $skippedInvalid[] = t('custom_commands_import_err_row_command', [$rowNumber]);
                continue;
            }
            if ($response === '') {
                $skippedInvalid[] = t('custom_commands_import_err_row_response', [$rowNumber, $commandName]);
                continue;
            }
            $responseLength = function_exists('mb_strlen') ? mb_strlen($response) : strlen($response);
            if ($responseLength > $maxResponse) {
                $skippedInvalid[] = t('custom_commands_import_err_row_response_long', [$rowNumber, $commandName]);
                continue;
            }
            $cooldownRaw = custom_commands_csv_cell($row, $map, 'cooldown');
            if ($cooldownRaw === '') {
                $cooldown = 15;
            } elseif (!is_numeric($cooldownRaw)) {
                $skippedInvalid[] = t('custom_commands_import_err_row_cooldown', [$rowNumber, $commandName]);
                continue;
            } else {
                $cooldown = (int)$cooldownRaw;
                if ($cooldown < 0) {
                    $cooldown = 0;
                }
                if ($cooldown > 86400) {
                    $cooldown = 86400;
                }
            }
            $permissionRaw = custom_commands_csv_cell($row, $map, 'permission');
            $permission = normalize_import_permission($permissionRaw);
            if ($permission === null) {
                $skippedInvalid[] = t('custom_commands_import_err_row_permission', [$rowNumber, $commandName]);
                continue;
            }
            $statusValue = sanitize_import_status(custom_commands_csv_cell($row, $map, 'status'));
            if ($statusValue === null) {
                $skippedInvalid[] = t('custom_commands_import_err_row_status', [$rowNumber, $commandName]);
                continue;
            }
            $cooldownBucket = sanitize_cooldown_bucket(custom_commands_csv_cell($row, $map, 'cooldown_bucket'));
            if (isset($builtinLookup[$commandName])) {
                $skippedBuiltin[] = $commandName;
                continue;
            }
            if (isset($taken[$commandName])) {
                $skippedExisting[] = $commandName;
                continue;
            }
            $normalizedAliases = [];
            $aliasConflicts = [];
            $aliasesRaw = custom_commands_csv_cell($row, $map, 'aliases');
            foreach (explode(',', $aliasesRaw) as $aliasTok) {
                $aliasTok = sanitize_command_name($aliasTok);
                if ($aliasTok === '' || $aliasTok === $commandName) {
                    continue;
                }
                if (in_array($aliasTok, $normalizedAliases, true)) {
                    continue;
                }
                if (isset($builtinLookup[$aliasTok]) || isset($taken[$aliasTok])) {
                    $aliasConflicts[] = $aliasTok;
                    continue;
                }
                $normalizedAliases[] = $aliasTok;
            }
            $aliasesValue = implode(',', $normalizedAliases);
            $insertSTMT->bind_param('ssissss', $commandName, $response, $statusValue, $cooldown, $cooldownBucket, $permission, $aliasesValue);
            if (!$insertSTMT->execute()) {
                $skippedInvalid[] = t('custom_commands_import_err_row_save', [$rowNumber, $commandName]);
                continue;
            }
            $taken[$commandName] = true;
            foreach ($normalizedAliases as $aliasTok) {
                $taken[$aliasTok] = true;
            }
            $imported++;
            if (!empty($aliasConflicts)) {
                $aliasWarnings[] = t('custom_commands_alias_conflict_warning', [implode(', ', $aliasConflicts)]) . ' (!' . $commandName . ')';
            }
        }
        $insertSTMT->close();
        echo json_encode([
            'success' => true,
            'imported' => $imported,
            'skipped_existing' => array_values(array_unique($skippedExisting)),
            'skipped_builtin' => array_values(array_unique($skippedBuiltin)),
            'skipped_invalid' => $skippedInvalid,
            'alias_warnings' => $aliasWarnings,
            'truncated' => $truncated,
            'max_rows' => $maxRows,
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => t('custom_commands_msg_unsupported_action')]);
    exit;
}

// Check if form data has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Editing a Custom Command
    if (
        isset($_POST['command_to_edit']) && 
        isset($_POST['command_response']) && 
        isset($_POST['cooldown_response']) &&
        isset($_POST['new_command_name'])
    ) {
        $command_to_edit = $_POST['command_to_edit'];
        $command_response = $_POST['command_response'];
        $cooldown = $_POST['cooldown_response'];
        $cooldown_bucket = sanitize_cooldown_bucket($_POST['cooldown_bucket_response'] ?? 'default');
        $permission = isset($_POST['permission_response']) ? $_POST['permission_response'] : 'Everyone';
        // Remove all non-alphanumeric characters
        $new_command_name = sanitize_command_name($_POST['new_command_name']);
        // Check if new command name is built-in
        if (array_key_exists($new_command_name, $builtinCommands['commands'])) {
            $status = t('custom_commands_msg_update_builtin_conflict');
            $notification_status = "sp-alert-danger";
        } else {
            try {
                // If the command name is changed, update it as well
                $dbPermission = $permissionsMap[$permission];
                // Normalize aliases (BETA): lowercase, sanitized, deduped, comma-separated
                $aliases_raw = isset($_POST['aliases']) ? $_POST['aliases'] : '';
                $self_lower = sanitize_command_name($new_command_name);
                $normalized_aliases = [];
                foreach (explode(',', $aliases_raw) as $alias_tok) {
                    $alias_tok = sanitize_command_name($alias_tok);
                    if ($alias_tok === '' || $alias_tok === $self_lower) continue;
                    if (in_array($alias_tok, $normalized_aliases, true)) continue;
                    $normalized_aliases[] = $alias_tok;
                }
                // Drop aliases already taken by another command's name or aliases
                $alias_conflicts = [];
                if (!empty($normalized_aliases)) {
                    $taken = [];
                    $aliasCheck = $db->prepare("SELECT command, aliases FROM custom_commands WHERE command != ?");
                    $aliasCheck->bind_param('s', $command_to_edit);
                    $aliasCheck->execute();
                    $aliasCheckRes = $aliasCheck->get_result();
                    while ($row = $aliasCheckRes->fetch_assoc()) {
                        $taken[strtolower(trim($row['command']))] = true;
                        if (!empty($row['aliases'])) {
                            foreach (explode(',', $row['aliases']) as $other) {
                                $other = strtolower(trim($other));
                                if ($other !== '') $taken[$other] = true;
                            }
                        }
                    }
                    $aliasCheck->close();
                    $kept = [];
                    foreach ($normalized_aliases as $alias_tok) {
                        if (isset($taken[$alias_tok])) { $alias_conflicts[] = $alias_tok; }
                        else { $kept[] = $alias_tok; }
                    }
                    $normalized_aliases = $kept;
                }
                $aliases_value = implode(',', $normalized_aliases);
                $updateSTMT = $db->prepare("UPDATE custom_commands SET command = ?, response = ?, cooldown = ?, cooldown_bucket = ?, permission = ?, aliases = ? WHERE command = ?");
                $updateSTMT->bind_param("ssissss", $new_command_name, $command_response, $cooldown, $cooldown_bucket, $dbPermission, $aliases_value, $command_to_edit);
                $updateSTMT->execute();
                if ($new_command_name !== $command_to_edit) {
                    $renameOptionsSTMT = $db->prepare("UPDATE custom_command_random_pick_options SET command = ? WHERE command = ?");
                    $renameOptionsSTMT->bind_param("ss", $new_command_name, $command_to_edit);
                    $renameOptionsSTMT->execute();
                    $renameOptionsSTMT->close();
                }
                if ($updateSTMT->affected_rows > 0) {
                    $status = t('custom_commands_msg_update_success', [$command_to_edit]);
                    $notification_status = "sp-alert-success";
                    if (!empty($alias_conflicts)) {
                        $status .= ' ' . t('custom_commands_alias_conflict_warning', [implode(', ', $alias_conflicts)]);
                    }
                } else {
                    $status = t('custom_commands_msg_update_not_found', [$command_to_edit]);
                    $notification_status = "sp-alert-danger";
                }
                $updateSTMT->close();
            } catch (Exception $e) {
                $status = t('custom_commands_msg_update_error', [$command_to_edit]) . " " . $e->getMessage();
                $notification_status = "sp-alert-danger";
            }
        }
    }
    // Adding a new custom command
    if (isset($_POST['command']) && isset($_POST['response']) && isset($_POST['cooldown'])) {
        $newCommand = sanitize_command_name($_POST['command']);
        $newResponse = $_POST['response'];
        $cooldown = $_POST['cooldown'];
        $cooldown_bucket = sanitize_cooldown_bucket($_POST['cooldown_bucket'] ?? 'default');
        $permission = isset($_POST['permission']) ? $_POST['permission'] : 'Everyone';
        // Check if command is built-in
        if (array_key_exists($newCommand, $builtinCommands['commands'])) {
            $status = t('custom_commands_msg_add_builtin_conflict');
            $notification_status = "sp-alert-danger";
        } else {
            // Check if command already exists
            $checkSTMT = $db->prepare("SELECT command FROM custom_commands WHERE command = ?");
            $checkSTMT->bind_param("s", $newCommand);
            $checkSTMT->execute();
            $checkSTMT->store_result();
            $alreadyExists = $checkSTMT->num_rows > 0;
            $checkSTMT->close();
            if ($alreadyExists) {
                $status = t('custom_commands_msg_add_already_exists', [$newCommand]);
                $notification_status = "sp-alert-danger";
            } else {
                // Insert new command into MySQL database
                try {
                    $dbPermission = $permissionsMap[$permission];
                    // Normalize aliases (BETA): lowercase, sanitized, deduped, comma-separated
                    $aliases_raw = isset($_POST['aliases']) ? $_POST['aliases'] : '';
                    $normalized_aliases = [];
                    foreach (explode(',', $aliases_raw) as $alias_tok) {
                        $alias_tok = sanitize_command_name($alias_tok);
                        if ($alias_tok === '' || $alias_tok === $newCommand) continue;
                        if (in_array($alias_tok, $normalized_aliases, true)) continue;
                        $normalized_aliases[] = $alias_tok;
                    }
                    // Drop aliases already taken by an existing command's name or aliases
                    $alias_conflicts = [];
                    if (!empty($normalized_aliases)) {
                        $taken = [];
                        $aliasCheckRes = $db->query("SELECT command, aliases FROM custom_commands");
                        if ($aliasCheckRes) {
                            while ($row = $aliasCheckRes->fetch_assoc()) {
                                $taken[strtolower(trim($row['command']))] = true;
                                if (!empty($row['aliases'])) {
                                    foreach (explode(',', $row['aliases']) as $other) {
                                        $other = strtolower(trim($other));
                                        if ($other !== '') $taken[$other] = true;
                                    }
                                }
                            }
                        }
                        $kept = [];
                        foreach ($normalized_aliases as $alias_tok) {
                            if (isset($taken[$alias_tok])) { $alias_conflicts[] = $alias_tok; }
                            else { $kept[] = $alias_tok; }
                        }
                        $normalized_aliases = $kept;
                    }
                    $aliases_value = implode(',', $normalized_aliases);
                    $insertSTMT = $db->prepare("INSERT INTO custom_commands (command, response, status, cooldown, cooldown_bucket, permission, aliases) VALUES (?, ?, 'Enabled', ?, ?, ?, ?)");
                    $insertSTMT->bind_param("ssisss", $newCommand, $newResponse, $cooldown, $cooldown_bucket, $dbPermission, $aliases_value);
                    $insertSTMT->execute();
                    $insertSTMT->close();
                } catch (Exception $e) {
                    $status = t('custom_commands_error_generic');
                    $notification_status = "sp-alert-danger";
                }
            }
        }
    }
    // Handle status toggle and remove from commands.php
    $dataUpdated = false;
    if (isset($_POST['command']) && isset($_POST['status'])) {
        $dbcommand = $_POST['command'];
        $status_val = $_POST['status'];
        $updateQuery = $db->prepare("UPDATE custom_commands SET status = ? WHERE command = ?");
        if (!$updateQuery) { error_log("MySQL prepare failed: " . $db->error); }
        $updateQuery->bind_param('ss', $status_val, $dbcommand);
        $result = $updateQuery->execute();
        if (!$result) { error_log("MySQL execute failed: " . $updateQuery->error); }
        $affected_rows = $updateQuery->affected_rows;
        $updateQuery->close();
        $dataUpdated = $result;
        // For AJAX requests, return JSON response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            ob_clean();
            header('Content-Type: application/json');
            if ($result && $affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => t('custom_commands_msg_status_updated'),
                    'affected_rows' => $affected_rows,
                    'database' => $db->host_info,
                    'database_name' => $_SESSION['username'] ?? 'unknown',
                    'command' => $dbcommand,
                    'new_status' => $status_val
                ]);
            }else {
                echo json_encode(['success' => false, 'message' => t('custom_commands_msg_no_rows_updated'), 'affected_rows' => $affected_rows]);
            }
            exit;
        }
    }
    if (isset($_POST['remove_command'])) {
        $commandToRemove = $_POST['remove_command'];
        $deleteStmt = $db->prepare("DELETE FROM custom_commands WHERE command = ?");
        $deleteStmt->bind_param('s', $commandToRemove);
        try {
            $deleteStmt->execute();
            $deleteStmt->close();
            $deleteOptionsStmt = $db->prepare("DELETE FROM custom_command_random_pick_options WHERE command = ?");
            $deleteOptionsStmt->bind_param('s', $commandToRemove);
            $deleteOptionsStmt->execute();
            $deleteOptionsStmt->close();
            $dataUpdated = true;
            $status = t('custom_commands_msg_remove_success');
        } catch (mysqli_sql_exception $e) {
            $status = t('custom_commands_msg_remove_error') . " " . $e->getMessage();
        }
    }
}

$twitchUsername = $username;

// Start output buffering for layout
ob_start();
?>
<div class="sp-alert sp-alert-info cc-print-hide" style="display:flex; gap:1rem; align-items:flex-start; margin-bottom:1.5rem;">
    <span style="font-size:1.5rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-info-circle"></i></span>
    <div>
        <p style="font-weight:700; margin-bottom:0.4rem;"><?php echo t('navbar_edit_custom_commands'); ?></p>
        <ol style="margin-left:1.25rem; margin-bottom:0.75rem;">
            <li><?php echo t('custom_commands_skip_exclamation'); ?></li>
            <li>
                <?php echo t('custom_commands_add_in_chat'); ?> <code>!addcommand [command] [message]</code>
                <div style="margin-left:1rem; margin-top:0.25rem;"><code>!addcommand mycommand <?php echo t('custom_commands_example_message'); ?></code></div>
            </li>
        </ol>
        <p style="margin-bottom:0.25rem;"><strong><?php echo t('custom_commands_level_up'); ?></strong></p>
        <p style="margin-bottom:0.25rem;"><?php echo t('custom_commands_explore_variables'); ?></p>
        <p style="margin-bottom:0.5rem;"><strong><?php echo t('custom_commands_note'); ?></strong> <?php echo t('custom_commands_note_detail'); ?></p>
        <a href="https://support.botofthespecter.com/index.php#variables" target="_blank" rel="noopener" class="sp-btn sp-btn-primary sp-btn-sm">
            <i class="fas fa-code"></i>
            <span><?php echo t('custom_commands_view_variables'); ?></span>
        </a>
        <div class="sp-betabot-toggle">
            <input type="checkbox" class="switch" id="betaBotToggle" onchange="applyBetaBotCharLimit(this.checked)">
            <label for="betaBotToggle"><?= t('custom_commands_beta_bot_toggle') ?></label>
        </div>
    </div>
</div>
<?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <?php if (isset($_POST['command']) && isset($_POST['response']) && empty($status)): ?>
        <div class="sp-alert sp-alert-success cc-print-hide" style="margin-bottom:1rem;">
            <i class="fas fa-check-circle"></i>
            <span>
                <?php
                $commandAdded = strtolower(str_replace(' ', '', $_POST['command']));
                printf(
                    t('custom_commands_added_success'),
                    htmlspecialchars($commandAdded)
                );
                ?>
                <?php if (!empty($alias_conflicts)): ?>
                    <br><?php echo t('custom_commands_alias_conflict_warning', [implode(', ', $alias_conflicts)]); ?>
                <?php endif; ?>
            </span>
        </div>
    <?php else: ?>
        <div class="sp-alert cc-print-hide <?php echo $notification_status; ?>" style="margin-bottom:1rem;">
            <?php echo $status; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<h4 class="cc-print-hide" style="font-size:1.15rem; font-weight:700; text-align:center; color:var(--text-primary); margin-bottom:1.5rem;"><?php echo t('navbar_edit_custom_commands'); ?></h4>
<div class="cc-form-grid cc-print-hide">
    <div class="sp-card">
        <form method="post" action="" style="display:flex; flex-direction:column; height:100%;">
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-plus-circle" style="color:var(--accent);"></i>
                    <?php echo t('custom_commands_add_title'); ?>
                </div>
                <button class="sp-btn sp-btn-primary" type="submit">
                    <i class="fas fa-plus"></i>
                    <span><?php echo t('custom_commands_add_btn'); ?></span>
                </button>
            </div>
            <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
                <div class="sp-form-group">
                    <label class="sp-label" for="command"><?php echo t('custom_commands_command_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-terminal sp-input-icon"></i>
                        <input class="sp-input" type="text" name="command" id="command" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="response"><?php echo t('custom_commands_response_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-message sp-input-icon"></i>
                        <input class="sp-input" type="text" name="response" id="response" required oninput="updateCharCount('response', 'responseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                    </div>
                    <small id="responseCharCount" class="sp-help">0/255 <?php echo t('custom_commands_characters'); ?></small>
                    <button id="addManyOptionsBtn" class="sp-btn sp-btn-secondary sp-btn-sm" style="display:none; margin-top:0.5rem;" type="button" onclick="handleManyOptionsPrompt('response', 'command', true)">
                        <i class="fas fa-list"></i>
                        <span><?= t('custom_commands_manage_options_btn') ?></span>
                    </button>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="cooldown"><?php echo t('custom_commands_cooldown_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-clock sp-input-icon"></i>
                        <input class="sp-input" type="number" min="1" name="cooldown" id="cooldown" value="15" required>
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="cooldown_bucket"><?php echo t('custom_commands_cooldown_bucket_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-layer-group sp-input-icon"></i>
                        <select id="cooldown_bucket" name="cooldown_bucket" class="sp-select" required>
                            <?php foreach ($cooldownBucketOptions as $bucketValue => $bucketLabelKey): ?>
                                <option value="<?php echo htmlspecialchars($bucketValue); ?>" <?php echo $bucketValue === 'default' ? 'selected' : ''; ?>>
                                    <?php echo t($bucketLabelKey); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <small class="sp-help"><?php echo t('custom_commands_cooldown_bucket_help'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="permission"><?= t('custom_commands_permission_level') ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-users sp-input-icon"></i>
                        <select id="permission" name="permission" class="sp-select" required>
                            <?php foreach ($permissionsMap as $displayName => $dbValue): ?>
                                <option value="<?php echo htmlspecialchars($displayName); ?>" <?php echo $displayName === 'Everyone' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($displayName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="add_aliases">
                        <?php echo t('custom_commands_aliases_label'); ?>
                        <span class="sp-badge sp-badge-amber" style="margin-left:0.4rem;">BETA</span>
                    </label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-tags sp-input-icon"></i>
                        <input class="sp-input" type="text" name="aliases" id="add_aliases" value="" placeholder="<?php echo t('custom_commands_aliases_placeholder'); ?>">
                    </div>
                    <small class="sp-help"><?php echo t('custom_commands_aliases_help'); ?></small>
                </div>
            </div>
        </form>
    </div>
    <div class="sp-card" id="editFormHost" aria-busy="true">
        <div class="sp-card-header">
            <div class="sp-card-title">
                <i class="fas fa-edit" style="color:var(--blue);"></i>
                <?php echo t('custom_commands_edit_title'); ?>
            </div>
            <button type="submit" form="editCommandForm" class="sp-btn sp-btn-primary" id="editSubmitBtn" style="display:none;">
                <i class="fas fa-save"></i>
                <span><?php echo t('custom_commands_update_btn'); ?></span>
            </button>
        </div>
        <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
            <div id="editFormSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                <span class="sp-skeleton-line w-40"></span>
                <span class="sp-skeleton-line w-90"></span>
                <span class="sp-skeleton-line w-50"></span>
                <span class="sp-skeleton-line w-70"></span>
                <span class="sp-skeleton-line w-80"></span>
                <span class="sp-skeleton-line w-45"></span>
            </div>
            <form id="editCommandForm" method="post" action="" style="display:none; flex:1; flex-direction:column;">
                    <div class="sp-form-group">
                        <label class="sp-label" for="command_to_edit_search"><?php echo t('custom_commands_edit_select_label'); ?></label>
                        <div class="cc-combobox" id="commandToEditCombobox">
                            <input type="hidden" name="command_to_edit" id="command_to_edit" value="">
                            <div class="sp-input-wrap">
                                <i class="fas fa-search sp-input-icon"></i>
                                <input class="sp-input cc-combobox-input" type="text" id="command_to_edit_search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="command_to_edit_list" aria-autocomplete="list" placeholder="<?php echo t('custom_commands_edit_select_placeholder'); ?>">
                                <i class="fas fa-chevron-down cc-combobox-caret"></i>
                            </div>
                            <ul class="cc-combobox-list" id="command_to_edit_list" role="listbox"></ul>
                        </div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="new_command_name"><?php echo t('custom_commands_edit_new_name_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-terminal sp-input-icon"></i>
                            <input class="sp-input" type="text" name="new_command_name" id="new_command_name" value="" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                        </div>
                        <small class="sp-help"><?php echo t('custom_commands_skip_exclamation'); ?></small>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="command_response"><?php echo t('custom_commands_response_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-message sp-input-icon"></i>
                            <input class="sp-input" type="text" name="command_response" id="command_response" value="" required oninput="updateCharCount('command_response', 'editResponseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                        </div>
                        <small id="editResponseCharCount" class="sp-help">0/255 <?php echo t('custom_commands_characters'); ?></small>
                        <button id="editManyOptionsBtn" class="sp-btn sp-btn-secondary sp-btn-sm" style="display:none; margin-top:0.5rem;" type="button" onclick="handleManyOptionsPrompt('command_response', 'new_command_name', true)">
                            <i class="fas fa-list"></i>
                            <span><?= t('custom_commands_manage_options_btn') ?></span>
                        </button>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="cooldown_response"><?php echo t('custom_commands_cooldown_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-clock sp-input-icon"></i>
                            <input class="sp-input" type="number" min="1" name="cooldown_response" id="cooldown_response" value="" required>
                        </div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="cooldown_bucket_response"><?php echo t('custom_commands_cooldown_bucket_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-layer-group sp-input-icon"></i>
                            <select id="cooldown_bucket_response" name="cooldown_bucket_response" class="sp-select" required>
                                <?php foreach ($cooldownBucketOptions as $bucketValue => $bucketLabelKey): ?>
                                    <option value="<?php echo htmlspecialchars($bucketValue); ?>">
                                        <?php echo t($bucketLabelKey); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="sp-help"><?php echo t('custom_commands_cooldown_bucket_help'); ?></small>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="permission_response"><?= t('custom_commands_permission_level') ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-users sp-input-icon"></i>
                            <select id="permission_response" name="permission_response" class="sp-select" required>
                                <?php foreach ($permissionsMap as $displayName => $dbValue): ?>
                                    <option value="<?php echo htmlspecialchars($displayName); ?>">
                                        <?php echo htmlspecialchars($displayName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_aliases">
                            <?php echo t('custom_commands_aliases_label'); ?>
                            <span class="sp-badge sp-badge-amber" style="margin-left:0.4rem;">BETA</span>
                        </label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-tags sp-input-icon"></i>
                            <input class="sp-input" type="text" name="aliases" id="edit_aliases" value="" placeholder="<?php echo t('custom_commands_aliases_placeholder'); ?>">
                        </div>
                        <small class="sp-help"><?php echo t('custom_commands_aliases_help'); ?></small>
                    </div>
            </form>
            <p id="editEmptyState" style="color:var(--text-muted); display:none;"><?php echo t('custom_commands_no_commands'); ?></p>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fas fa-terminal"></i>
            <?php echo t('custom_commands_header'); ?>
        </div>
        <div class="cc-commands-toolbar">
            <div class="cc-commands-actions">
                <button type="button" class="sp-btn sp-btn-secondary" id="importCommandsBtn">
                    <i class="fas fa-file-import"></i>
                    <span><?php echo t('custom_commands_import_btn'); ?></span>
                </button>
                <a class="sp-btn sp-btn-secondary" href="?ajax_action=export" id="exportCommandsBtn">
                    <i class="fas fa-file-export"></i>
                    <span><?php echo t('custom_commands_export_btn'); ?></span>
                </a>
                <button type="button" class="sp-btn sp-btn-secondary" id="printCommandsBtn">
                    <i class="fas fa-print"></i>
                    <span><?php echo t('custom_commands_print_btn'); ?></span>
                </button>
            </div>
            <div class="sp-input-wrap cc-commands-search" id="searchInputWrap">
                <i class="fas fa-search sp-input-icon"></i>
                <input class="sp-input" type="text" id="searchInput" placeholder="<?php echo t('builtin_commands_search_placeholder'); ?>">
            </div>
        </div>
    </div>
    <div class="sp-card-body">
        <p class="cc-print-banner"><?php echo htmlspecialchars(t('custom_commands_print_banner', [$twitchUsername, date('Y-m-d H:i T')])); ?></p>
        <div class="sp-table-wrap">
            <table class="sp-table" id="commandsTable">
                <thead>
                    <tr>
                        <th><?php echo t('builtin_commands_table_command'); ?></th>
                        <th><?php echo t('custom_commands_response_label'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_usage_level'); ?></th>
                        <th style="text-align:center;"><?php echo t('custom_commands_cooldown_label'); ?></th>
                        <th style="text-align:center;"><?php echo t('custom_commands_cooldown_bucket_label'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_status'); ?></th>
                        <th class="cc-print-col-hide" style="text-align:center;"><?php echo t('builtin_commands_table_action'); ?></th>
                        <th class="cc-print-col-hide" style="text-align:center;"><?php echo t('custom_commands_remove'); ?></th>
                    </tr>
                </thead>
                <tbody id="commandsTableBody" aria-busy="true">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                        <td><span class="sp-skeleton-line w-50"></span></td>
                        <td><span class="sp-skeleton-line w-80"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-40"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-40"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-50"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-40"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-30"></span></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<input type="hidden" id="yourlinks_username" value="<?php echo htmlspecialchars($twitchUsername); ?>">
<div class="cc-modal-backdrop cc-print-hide" id="importCommandsModal">
    <div class="cc-modal cc-import-modal" role="dialog" aria-modal="true" aria-labelledby="importCommandsTitle">
        <div class="cc-modal-head">
            <span class="cc-modal-title" id="importCommandsTitle">
                <i class="fas fa-file-import"></i>
                <?php echo t('custom_commands_import_title'); ?>
            </span>
            <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="closeImportCommandsModal" aria-label="<?php echo htmlspecialchars(t('custom_commands_import_close')); ?>">&times;</button>
        </div>
        <div class="cc-modal-body">
            <p class="cc-import-intro"><?php echo t('custom_commands_import_intro'); ?></p>
            <h5 class="cc-import-section-title"><?php echo t('custom_commands_import_how_title'); ?></h5>
            <ol class="cc-import-steps">
                <li><?php echo t('custom_commands_import_how_1'); ?></li>
                <li><?php echo t('custom_commands_import_how_2'); ?></li>
                <li><?php echo t('custom_commands_import_how_3'); ?></li>
                <li><?php echo t('custom_commands_import_how_4'); ?></li>
            </ol>
            <h5 class="cc-import-section-title"><?php echo t('custom_commands_import_format_title'); ?></h5>
            <div class="sp-table-wrap cc-import-format-wrap">
                <table class="sp-table">
                    <thead>
                        <tr>
                            <th><?php echo t('custom_commands_import_col_column'); ?></th>
                            <th><?php echo t('custom_commands_import_col_required'); ?></th>
                            <th><?php echo t('custom_commands_import_col_notes'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>command</code></td>
                            <td><?php echo t('custom_commands_import_required_yes'); ?></td>
                            <td><?php echo t('custom_commands_import_col_command_notes'); ?></td>
                        </tr>
                        <tr>
                            <td><code>response</code></td>
                            <td><?php echo t('custom_commands_import_required_yes'); ?></td>
                            <td><?php echo t('custom_commands_import_col_response_notes'); ?></td>
                        </tr>
                        <tr>
                            <td><code>cooldown</code></td>
                            <td><?php echo t('custom_commands_import_required_no'); ?></td>
                            <td><?php echo t('custom_commands_import_col_cooldown_notes'); ?></td>
                        </tr>
                        <tr>
                            <td><code>cooldown_bucket</code></td>
                            <td><?php echo t('custom_commands_import_required_no'); ?></td>
                            <td><?php echo t('custom_commands_import_col_bucket_notes'); ?></td>
                        </tr>
                        <tr>
                            <td><code>permission</code></td>
                            <td><?php echo t('custom_commands_import_required_no'); ?></td>
                            <td><?php echo t('custom_commands_import_col_permission_notes'); ?></td>
                        </tr>
                        <tr>
                            <td><code>aliases</code></td>
                            <td><?php echo t('custom_commands_import_required_no'); ?></td>
                            <td><?php echo t('custom_commands_import_col_aliases_notes'); ?></td>
                        </tr>
                        <tr>
                            <td><code>status</code></td>
                            <td><?php echo t('custom_commands_import_required_no'); ?></td>
                            <td><?php echo t('custom_commands_import_col_status_notes'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <h5 class="cc-import-section-title"><?php echo t('custom_commands_import_notes_title'); ?></h5>
            <ul class="cc-import-notes">
                <li><?php echo t('custom_commands_import_note_twitch'); ?></li>
                <li><?php echo t('custom_commands_import_note_skip_bang'); ?></li>
                <li><?php echo t('custom_commands_import_note_skip_existing'); ?></li>
                <li><?php echo t('custom_commands_import_note_quotes'); ?></li>
                <li><?php echo t('custom_commands_import_note_utf8'); ?></li>
            </ul>
            <div class="cc-import-template-row">
                <a class="sp-btn sp-btn-secondary" href="?ajax_action=import_template" download="specter-custom-commands-template.csv">
                    <i class="fas fa-download"></i>
                    <span><?php echo t('custom_commands_import_template_btn'); ?></span>
                </a>
            </div>
            <label class="cc-import-drop" id="importDropZone" for="importFileInput">
                <i class="fas fa-cloud-arrow-up cc-import-drop-icon"></i>
                <span class="cc-import-drop-title"><?php echo t('custom_commands_import_drop_title'); ?></span>
                <span class="cc-import-drop-hint"><?php echo t('custom_commands_import_drop_hint'); ?></span>
                <span class="cc-import-file-name" id="importFileName"><?php echo t('custom_commands_import_no_file'); ?></span>
            </label>
            <input type="file" id="importFileInput" class="cc-import-file-input" name="import_file" accept=".csv,text/csv">
            <div class="cc-import-results" id="importResults"></div>
        </div>
        <div class="cc-modal-foot">
            <button type="button" class="sp-btn sp-btn-secondary" id="cancelImportCommandsBtn"><?php echo t('custom_commands_import_close'); ?></button>
            <button type="button" class="sp-btn sp-btn-primary" id="submitImportCommandsBtn" disabled>
                <i class="fas fa-file-import"></i>
                <span><?php echo t('custom_commands_import_submit'); ?></span>
            </button>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/yourlinks-shortener.js?v=<?php echo filemtime(__DIR__ . '/js/yourlinks-shortener.js'); ?>"></script>
<script>
var commands = [];
var commandComboboxInitialized = false;
var importCommandsFile = null;
var permissionsMap = <?php echo json_encode(array_flip($permissionsMap)); ?>;
var permissionsMapReverse = <?php echo json_encode($permissionsMapReverse); ?>;
var cooldownBucketLabels = {
    default: <?php echo json_encode(t('custom_commands_cooldown_bucket_default')); ?>,
    user: <?php echo json_encode(t('custom_commands_cooldown_bucket_user')); ?>,
    mod: <?php echo json_encode(t('custom_commands_cooldown_bucket_mod')); ?>
};
var CC_I18N = {
    noCommands: <?php echo json_encode(t('builtin_commands_no_commands')); ?>,
    loadError: <?php echo json_encode(t('custom_commands_error_generic')); ?>,
    statusEnabled: <?php echo json_encode(t('builtin_commands_status_enabled')); ?>,
    statusDisabled: <?php echo json_encode(t('builtin_commands_status_disabled')); ?>,
    cooldownSeconds: <?php echo json_encode(t('custom_commands_cooldown_seconds')); ?>,
    removeTitle: <?php echo json_encode(t('custom_commands_remove')); ?>,
    importNoFile: <?php echo json_encode(t('custom_commands_import_no_file')); ?>,
    importType: <?php echo json_encode(t('custom_commands_import_err_type')); ?>,
    importWorking: <?php echo json_encode(t('custom_commands_import_working')); ?>,
    importGeneric: <?php echo json_encode(t('custom_commands_import_err_generic')); ?>,
    importImported: <?php echo json_encode(t('custom_commands_import_result_imported')); ?>,
    importNone: <?php echo json_encode(t('custom_commands_import_nothing')); ?>,
    importSkippedExisting: <?php echo json_encode(t('custom_commands_import_result_skipped_existing')); ?>,
    importSkippedBuiltin: <?php echo json_encode(t('custom_commands_import_result_skipped_builtin')); ?>,
    importSkippedInvalid: <?php echo json_encode(t('custom_commands_import_result_skipped_invalid')); ?>,
    importAliasWarnings: <?php echo json_encode(t('custom_commands_import_result_alias_warnings')); ?>,
    importTruncated: <?php echo json_encode(t('custom_commands_import_truncated')); ?>,
    importSuccessTitle: <?php echo json_encode(t('custom_commands_import_swal_success_title')); ?>,
    importPartialTitle: <?php echo json_encode(t('custom_commands_import_swal_partial_title')); ?>,
    importFailedTitle: <?php echo json_encode(t('custom_commands_import_swal_failed_title')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function escapeAttr(str) {
    return escapeHtml(str).replace(/`/g, '&#96;');
}

function populateEditCombobox() {
    var list = document.getElementById('command_to_edit_list');
    if (!list) return;
    list.innerHTML = '';
    commands.forEach(function(command) {
        var li = document.createElement('li');
        li.className = 'cc-combobox-option';
        li.setAttribute('role', 'option');
        li.setAttribute('data-value', command.command);
        li.textContent = '!' + command.command;
        list.appendChild(li);
    });
}

function populateEditForm() {
    var hasCommands = commands.length > 0;
    var skeleton = document.getElementById('editFormSkeleton');
    var form = document.getElementById('editCommandForm');
    var empty = document.getElementById('editEmptyState');
    var host = document.getElementById('editFormHost');
    var submitBtn = document.getElementById('editSubmitBtn');
    if (skeleton) skeleton.style.display = 'none';
    if (host) host.setAttribute('aria-busy', 'false');
    if (form) form.style.display = hasCommands ? 'flex' : 'none';
    if (empty) empty.style.display = hasCommands ? 'none' : '';
    if (submitBtn) submitBtn.style.display = hasCommands ? '' : 'none';
    populateEditCombobox();
    if (!commandComboboxInitialized) {
        initCommandToEditCombobox();
        commandComboboxInitialized = true;
    }
}

function renderCustomCommandsTable() {
    var tbody = document.getElementById('commandsTableBody');
    var searchWrap = document.getElementById('searchInputWrap');
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    if (searchWrap) {
        if (commands.length) {
            searchWrap.classList.add('is-visible');
        } else {
            searchWrap.classList.remove('is-visible');
        }
    }
    if (!commands.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">' + escapeHtml(CC_I18N.noCommands) + '</td></tr>';
        return;
    }
    tbody.innerHTML = commands.map(function(command) {
        var name = String(command.command || '');
        var enabled = command.status === 'Enabled';
        var aliasesHtml = '';
        if (command.aliases) {
            var aliasList = String(command.aliases).split(',').map(function(a) { return a.trim(); }).filter(Boolean);
            if (aliasList.length) {
                aliasesHtml = '<div class="sp-help" style="margin-top:0.15rem;"><i class="fas fa-tags" style="margin-right:0.25rem;"></i>' +
                    escapeHtml(aliasList.map(function(a) { return '!' + a; }).join(', ')) + '</div>';
            }
        }
        var bucket = String(command.cooldown_bucket || 'default').toLowerCase();
        if (bucket === 'mods') bucket = 'mod';
        if (['default', 'user', 'mod'].indexOf(bucket) === -1) bucket = 'default';
        var permissionLabel = permissionsMapReverse[command.permission] || 'Everyone';
        return '<tr>' +
            '<td>!' + escapeHtml(name) + aliasesHtml + '</td>' +
            '<td>' + escapeHtml(command.response) + '</td>' +
            '<td style="text-align:center;">' + escapeHtml(permissionLabel) + '</td>' +
            '<td style="text-align:center;">' + escapeHtml(parseInt(command.cooldown, 10) || 0) + escapeHtml(CC_I18N.cooldownSeconds) + '</td>' +
            '<td style="text-align:center;">' + escapeHtml(cooldownBucketLabels[bucket] || cooldownBucketLabels.default) + '</td>' +
            '<td style="text-align:center;"><span class="sp-badge ' + (enabled ? 'sp-badge-green' : 'sp-badge-red') + '">' +
                escapeHtml(enabled ? CC_I18N.statusEnabled : CC_I18N.statusDisabled) + '</span></td>' +
            '<td class="cc-print-col-hide" style="text-align:center;"><label style="cursor:pointer;">' +
                '<input type="checkbox" class="toggle-checkbox"' + (enabled ? ' checked' : '') +
                ' onchange="toggleStatus(\'' + escapeAttr(name) + '\', this.checked, this)" style="display:none;">' +
                '<span onclick="event.preventDefault(); event.stopPropagation(); this.previousElementSibling.click();" style="font-size:1.3rem; color:' +
                (enabled ? 'var(--green)' : 'var(--text-muted)') + ';">' +
                '<i class="fa-solid ' + (enabled ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span></label></td>' +
            '<td class="cc-print-col-hide" style="text-align:center;"><form method="POST" style="display:inline;" class="remove-command-form">' +
                '<input type="hidden" name="remove_command" value="' + escapeAttr(name) + '">' +
                '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm remove-command-btn" title="' + escapeAttr(CC_I18N.removeTitle) + '">' +
                '<i class="fas fa-trash-alt"></i></button></form></td>' +
            '</tr>';
    }).join('');
    setupRemoveButtons();
}

function renderCustomCommandsError() {
    commands = [];
    populateEditForm();
    var tbody = document.getElementById('commandsTableBody');
    if (tbody) {
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">' + escapeHtml(CC_I18N.loadError) + '</td></tr>';
    }
}

function loadCustomCommands() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderCustomCommandsError();
                return;
            }
            commands = Array.isArray(data.commands) ? data.commands : [];
            populateEditForm();
            renderCustomCommandsTable();
            if (typeof searchFunction === 'function') {
                searchFunction();
            }
        })
        .catch(function() {
            renderCustomCommandsError();
        });
}

document.addEventListener("DOMContentLoaded", function() {
    var searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.value = localStorage.getItem("searchTerm") || "";
        searchInput.addEventListener("input", function() {
            localStorage.setItem("searchTerm", this.value);
            searchFunction();
        });
    }
    yourLinksShortener.setSuppressPromptsAfterDecline(true);
    yourLinksShortener.initializeField('response');
    yourLinksShortener.initializeField('command_response');
    initializeRandomPickWatcher('response', 'command');
    initializeRandomPickWatcher('command_response', 'new_command_name');
    initImportCommands();
    var printBtn = document.getElementById('printCommandsBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }
    loadCustomCommands();
});

function formatImportCount(template, count) {
    return String(template).replace('%d', String(count));
}

function renderImportWorking() {
    var box = document.getElementById('importResults');
    if (!box) return;
    box.innerHTML = '';
    box.classList.add('is-visible');
    var info = document.createElement('div');
    info.className = 'sp-alert sp-alert-info';
    info.textContent = CC_I18N.importWorking;
    box.appendChild(info);
}

function importIssueLists(data) {
    return {
        existing: ((data && data.skipped_existing) || []).map(function(name) { return '!' + name; }),
        builtin: ((data && data.skipped_builtin) || []).map(function(name) { return '!' + name; }),
        invalid: (data && data.skipped_invalid) || [],
        aliases: (data && data.alias_warnings) || [],
        truncated: !!(data && data.truncated),
        truncatedText: data && data.truncated
            ? formatImportCount(CC_I18N.importTruncated, parseInt(data.max_rows, 10) || 1000)
            : ''
    };
}

function appendImportIssueLists(parent, issues, headingTag) {
    function addList(items, title, className) {
        if (!items || !items.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'sp-alert ' + className;
        var heading = document.createElement(headingTag || 'p');
        heading.className = 'cc-import-result-title';
        heading.textContent = title;
        wrap.appendChild(heading);
        var list = document.createElement('ul');
        list.className = 'cc-import-result-list';
        items.forEach(function(item) {
            var li = document.createElement('li');
            li.textContent = item;
            list.appendChild(li);
        });
        wrap.appendChild(list);
        parent.appendChild(wrap);
    }
    addList(issues.existing, CC_I18N.importSkippedExisting, 'sp-alert-warning');
    addList(issues.builtin, CC_I18N.importSkippedBuiltin, 'sp-alert-warning');
    addList(issues.invalid, CC_I18N.importSkippedInvalid, 'sp-alert-danger');
    addList(issues.aliases, CC_I18N.importAliasWarnings, 'sp-alert-warning');
    if (issues.truncated) {
        var trunc = document.createElement('div');
        trunc.className = 'sp-alert sp-alert-warning';
        trunc.textContent = issues.truncatedText;
        parent.appendChild(trunc);
    }
}

function notifyImportOutcome(data) {
    var imported = data && data.success ? (parseInt(data.imported, 10) || 0) : 0;
    var issues = importIssueLists(data);
    var hasIssues = issues.existing.length || issues.builtin.length || issues.invalid.length || issues.aliases.length || issues.truncated;
    if (typeof Swal === 'undefined') {
        return;
    }
    if (!data || !data.success) {
        Swal.fire({
            icon: 'error',
            title: CC_I18N.importFailedTitle,
            text: (data && data.message) ? data.message : CC_I18N.importGeneric
        });
        return;
    }
    var holder = document.createElement('div');
    holder.className = 'cc-import-swal-body';
    var summary = document.createElement('p');
    summary.textContent = imported > 0 ? formatImportCount(CC_I18N.importImported, imported) : CC_I18N.importNone;
    holder.appendChild(summary);
    appendImportIssueLists(holder, issues);
    Swal.fire({
        icon: hasIssues ? (imported > 0 ? 'warning' : 'error') : 'success',
        title: hasIssues ? CC_I18N.importPartialTitle : CC_I18N.importSuccessTitle,
        html: holder,
        width: 640
    });
}

function renderImportResults(data, notify) {
    var box = document.getElementById('importResults');
    if (!box) return;
    box.innerHTML = '';
    box.classList.add('is-visible');
    if (!data || !data.success) {
        var err = document.createElement('div');
        err.className = 'sp-alert sp-alert-danger';
        err.textContent = (data && data.message) ? data.message : CC_I18N.importGeneric;
        box.appendChild(err);
        if (notify !== false) {
            notifyImportOutcome(data);
        }
        if (typeof box.scrollIntoView === 'function') {
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        return;
    }
    var imported = parseInt(data.imported, 10) || 0;
    var summary = document.createElement('div');
    summary.className = imported > 0 ? 'sp-alert sp-alert-success' : 'sp-alert sp-alert-warning';
    summary.textContent = imported > 0 ? formatImportCount(CC_I18N.importImported, imported) : CC_I18N.importNone;
    box.appendChild(summary);
    appendImportIssueLists(box, importIssueLists(data));
    if (typeof box.scrollIntoView === 'function') {
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    if (notify !== false) {
        notifyImportOutcome(data);
    }
}

function resetImportResults() {
    var box = document.getElementById('importResults');
    if (!box) return;
    box.innerHTML = '';
    box.classList.remove('is-visible');
}

function setImportFile(file) {
    var nameEl = document.getElementById('importFileName');
    var submitBtn = document.getElementById('submitImportCommandsBtn');
    var drop = document.getElementById('importDropZone');
    var input = document.getElementById('importFileInput');
    if (!file) {
        if (nameEl) nameEl.textContent = CC_I18N.importNoFile;
        if (submitBtn) submitBtn.disabled = true;
        if (drop) drop.classList.remove('has-file');
        if (input) input.value = '';
        importCommandsFile = null;
        return;
    }
    var fileName = String(file.name || '').toLowerCase();
    if (fileName.slice(-4) !== '.csv') {
        importCommandsFile = null;
        if (nameEl) nameEl.textContent = CC_I18N.importNoFile;
        if (submitBtn) submitBtn.disabled = true;
        if (drop) drop.classList.remove('has-file');
        if (input) input.value = '';
        renderImportResults({ success: false, message: CC_I18N.importType });
        return;
    }
    importCommandsFile = file;
    if (nameEl) nameEl.textContent = file.name;
    if (submitBtn) submitBtn.disabled = false;
    if (drop) drop.classList.add('has-file');
}

function openImportCommandsModal() {
    var modal = document.getElementById('importCommandsModal');
    if (!modal) return;
    resetImportResults();
    setImportFile(null);
    modal.classList.add('is-active');
}

function closeImportCommandsModal() {
    var modal = document.getElementById('importCommandsModal');
    if (!modal) return;
    modal.classList.remove('is-active');
    setImportFile(null);
    resetImportResults();
}

function submitImportCommands() {
    var file = importCommandsFile;
    var submitBtn = document.getElementById('submitImportCommandsBtn');
    if (!file) {
        renderImportResults({ success: false, message: CC_I18N.importNoFile });
        return;
    }
    var formData = new FormData();
    formData.append('action', 'import_commands');
    formData.append('import_file', file, file.name);
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('sp-btn-loading');
    }
    renderImportWorking();
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) {
        return r.text().then(function(text) {
            var data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = null;
            }
            if (!data) {
                return { success: false, message: CC_I18N.importGeneric };
            }
            if (!r.ok && data.success === undefined) {
                return { success: false, message: data.message || CC_I18N.importGeneric };
            }
            return data;
        });
    }).then(function(data) {
        if (submitBtn) submitBtn.classList.remove('sp-btn-loading');
        if (submitBtn) submitBtn.disabled = !importCommandsFile;
        renderImportResults(data);
        if (data && data.success && (parseInt(data.imported, 10) || 0) > 0) {
            loadCustomCommands();
        }
    }).catch(function() {
        if (submitBtn) {
            submitBtn.classList.remove('sp-btn-loading');
            submitBtn.disabled = !importCommandsFile;
        }
        renderImportResults({ success: false, message: CC_I18N.importGeneric });
    });
}

function initImportCommands() {
    var openBtn = document.getElementById('importCommandsBtn');
    var closeBtn = document.getElementById('closeImportCommandsModal');
    var cancelBtn = document.getElementById('cancelImportCommandsBtn');
    var submitBtn = document.getElementById('submitImportCommandsBtn');
    var modal = document.getElementById('importCommandsModal');
    var drop = document.getElementById('importDropZone');
    var input = document.getElementById('importFileInput');
    if (openBtn) openBtn.addEventListener('click', openImportCommandsModal);
    if (closeBtn) closeBtn.addEventListener('click', closeImportCommandsModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeImportCommandsModal);
    if (submitBtn) submitBtn.addEventListener('click', submitImportCommands);
    if (modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) closeImportCommandsModal();
        });
    }
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal && modal.classList.contains('is-active')) {
            closeImportCommandsModal();
        }
    });
    if (input) {
        input.addEventListener('change', function() {
            setImportFile(this.files && this.files[0] ? this.files[0] : null);
            resetImportResults();
        });
    }
    if (drop) {
        ['dragenter', 'dragover'].forEach(function(type) {
            drop.addEventListener(type, function(event) {
                event.preventDefault();
                event.stopPropagation();
                drop.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function(type) {
            drop.addEventListener(type, function(event) {
                event.preventDefault();
                event.stopPropagation();
                drop.classList.remove('is-dragover');
            });
        });
        drop.addEventListener('drop', function(event) {
            var files = event.dataTransfer && event.dataTransfer.files;
            if (files && files[0]) {
                setImportFile(files[0]);
                resetImportResults();
            }
        });
    }
}

function sanitizeCommandName(commandName) {
    return String(commandName || '')
        .toLowerCase()
        .replace(/\s+/g, '')
        .replace(/[^a-z0-9]/g, '');
}

var charLimit = 255;
function applyBetaBotCharLimit(enabled) {
    charLimit = enabled ? 500 : 255;
    localStorage.setItem('betaBotMode', enabled ? '1' : '0');
    ['response', 'command_response'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.setAttribute('maxlength', charLimit);
    });
    updateCharCount('response', 'responseCharCount');
    updateCharCount('command_response', 'editResponseCharCount');
}

var randomPickOptionsCache = {};

function sendActionRequest(params, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== XMLHttpRequest.DONE) {
            return;
        }
        if (xhr.status !== 200) {
            callback(new Error('HTTP Error: ' + xhr.status));
            return;
        }
        try {
            callback(null, JSON.parse(xhr.responseText));
        } catch (error) {
            callback(error);
        }
    };
    var encoded = Object.keys(params)
        .map(function(key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        })
        .join('&');
    xhr.send(encoded);
}

function fetchRandomPickOptions(commandName, callback, forceRefresh) {
    if (!forceRefresh && randomPickOptionsCache[commandName]) {
        callback(null, randomPickOptionsCache[commandName]);
        return;
    }
    sendActionRequest(
        {
            action: 'get_random_pick_options',
            command_name: commandName
        },
        function(err, data) {
            if (!err && data && data.success) {
                randomPickOptionsCache[commandName] = data;
            }
            callback(err, data);
        }
    );
}

function prefetchRandomPickOptions(commandName) {
    var normalizedCommand = sanitizeCommandName(commandName);
    if (!normalizedCommand) {
        return;
    }
    fetchRandomPickOptions(normalizedCommand, function() {}, false);
}

function initializeRandomPickWatcher(responseInputId, commandInputId) {
    var responseInput = document.getElementById(responseInputId);
    if (!responseInput) {
        return;
    }
    var buttonId = responseInputId === 'response' ? 'addManyOptionsBtn' : 'editManyOptionsBtn';
    updateManyOptionsButtonVisibility(responseInputId, buttonId);
    responseInput.addEventListener('input', function() {
        updateManyOptionsButtonVisibility(responseInputId, buttonId);
    });
    responseInput.addEventListener('blur', function() {
        handleManyOptionsPrompt(responseInputId, commandInputId, false);
    });
}

function hasManyOptionsToken(responseValue) {
    var value = String(responseValue || '');
    var randomPickPattern = /\(random\.(?:pick|piack)\)/i;
    return randomPickPattern.test(value);
}

function updateManyOptionsButtonVisibility(responseInputId, buttonId) {
    var responseInput = document.getElementById(responseInputId);
    var button = document.getElementById(buttonId);
    if (!responseInput || !button) {
        return;
    }
    button.style.display = hasManyOptionsToken(responseInput.value) ? 'inline-flex' : 'none';
}

function handleManyOptionsPrompt(responseInputId, commandInputId, forceOpen) {
    var responseInput = document.getElementById(responseInputId);
    var commandInput = document.getElementById(commandInputId);
    if (!responseInput || !commandInput) {
        return;
    }
    var responseValue = responseInput.value || '';
    var hasNewSyntax = hasManyOptionsToken(responseValue);
    if (!hasNewSyntax && !forceOpen) {
        return;
    }
    var normalizedCommand = sanitizeCommandName(commandInput.value);
    if (!normalizedCommand) {
        Swal.fire({
            icon: 'info',
            title: <?php echo json_encode(t('custom_commands_js_name_required_title')); ?>,
            text: <?php echo json_encode(t('custom_commands_js_name_required_text')); ?>
        });
        return;
    }
    var cachedConfig = randomPickOptionsCache[normalizedCommand];
    var alreadyConfigured = cachedConfig && cachedConfig.many_options_enabled;
    var configuredForCommand = responseInput.dataset.randomPickConfiguredCommand === normalizedCommand;
    if (!forceOpen && (alreadyConfigured || configuredForCommand)) {
        return;
    }
    if (forceOpen) {
        openManyOptionsModal(normalizedCommand, responseInputId, false);
        return;
    }
    var promptSignature = normalizedCommand + '|' + responseValue;
    if (responseInput.dataset.randomPickPrompted === promptSignature) {
        return;
    }
    responseInput.dataset.randomPickPrompted = promptSignature;
    Swal.fire({
        icon: 'question',
        title: <?php echo json_encode(t('custom_commands_js_many_options_prompt_title')); ?>,
        text: <?php echo json_encode(t('custom_commands_js_many_options_prompt_text')); ?>,
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('custom_commands_js_many_options_confirm')); ?>,
        cancelButtonText: <?php echo json_encode(t('custom_commands_js_many_options_cancel')); ?>
    }).then(function(result) {
        if (result.isConfirmed) {
            openManyOptionsModal(normalizedCommand, responseInputId, true);
        }
    });
}

function extractLegacyInlineRandomPickOptions(responseValue) {
    var value = String(responseValue || '');
    var match = value.match(/\(random\.(?:pick|piack)\.([^\)]+)\)/i);
    if (!match || !match[1]) {
        return [];
    }
    return match[1]
        .split('.')
        .map(function(item) { return item.trim(); })
        .filter(function(item) { return item.length > 0; });
}

function normalizeResponseToManyOptionsToken(responseValue) {
    return String(responseValue || '')
        .replace(/\(random\.(?:pick|piack)\.[^\)]*\)/gi, '(random.pick)')
        .replace(/\(random\.piack\)/gi, '(random.pick)');
}

function openManyOptionsModal(commandName, responseInputId, autoEnable) {
    var responseInput = document.getElementById(responseInputId);
    var inlineOptions = extractLegacyInlineRandomPickOptions(responseInput ? responseInput.value : '');
    if (!randomPickOptionsCache[commandName]) {
        Swal.fire({
            title: <?php echo json_encode(t('custom_commands_js_loading_options')); ?>,
            allowOutsideClick: false,
            didOpen: function() {
                Swal.showLoading();
            }
        });
    }
    fetchRandomPickOptions(commandName, function(err, data) {
            if (Swal.isVisible()) {
                Swal.close();
            }
            if (err || !data || !data.success) {
                Swal.fire({
                    icon: 'error',
                    title: <?php echo json_encode(t('custom_commands_js_load_failed_title')); ?>,
                    text: (data && data.message) ? data.message : <?php echo json_encode(t('custom_commands_js_try_again')); ?>
                });
                return;
            }
            var dbOptions = Array.isArray(data.options) ? data.options : [];
            var effectiveOptions = dbOptions.length > 0 ? dbOptions : inlineOptions;
            var initialOptions = effectiveOptions.join('\n');
            var isEnabled = !!autoEnable || data.many_options_enabled || inlineOptions.length > 0;
            var checked = isEnabled ? 'checked' : '';
            Swal.fire({
                title: <?php echo json_encode(t('custom_commands_js_modal_title')); ?> + '!' + commandName,
                html:
                    '<div class="field has-text-left">' +
                        '<label class="checkbox">' +
                            '<input type="checkbox" id="manyOptionsEnabled" ' + checked + '> ' + <?php echo json_encode(t('custom_commands_js_enable_many_options')); ?> +
                        '</label>' +
                    '</div>' +
                    '<div class="field has-text-left mt-3">' +
                        '<label class="label has-text-black">' + <?php echo json_encode(t('custom_commands_js_options_one_per_line')); ?> + '</label>' +
                        '<textarea id="manyOptionsList" class="textarea" rows="10" placeholder="' + <?php echo json_encode(t('custom_commands_js_options_placeholder')); ?> + '">' + initialOptions + '</textarea>' +
                        '<p class="help has-text-black">' + <?php echo json_encode(t('custom_commands_js_options_help')); ?> + '</p>' +
                    '</div>',
                width: 700,
                showCancelButton: true,
                confirmButtonText: <?php echo json_encode(t('custom_commands_js_save_options')); ?>,
                preConfirm: function() {
                    var enabled = document.getElementById('manyOptionsEnabled').checked;
                    var raw = document.getElementById('manyOptionsList').value || '';
                    var options = raw
                        .split(/\r?\n/)
                        .map(function(item) { return item.trim(); })
                        .filter(function(item) { return item.length > 0; });
                    return {
                        enabled: enabled,
                        options: options
                    };
                }
            }).then(function(result) {
                if (!result.isConfirmed) {
                    return;
                }
                sendActionRequest(
                    {
                        action: 'save_random_pick_options',
                        command_name: commandName,
                        many_options_enabled: result.value.enabled ? '1' : '0',
                        options: JSON.stringify(result.value.options)
                    },
                    function(saveErr, saveData) {
                        if (saveErr || !saveData || !saveData.success) {
                            Swal.fire({
                                icon: 'error',
                                title: <?php echo json_encode(t('custom_commands_js_save_failed_title')); ?>,
                                text: (saveData && saveData.message) ? saveData.message : <?php echo json_encode(t('custom_commands_js_try_again')); ?>
                            });
                            return;
                        }
                        randomPickOptionsCache[commandName] = {
                            success: true,
                            many_options_enabled: !!result.value.enabled,
                            options: result.value.options
                        };
                        if (responseInput) {
                            responseInput.dataset.randomPickConfiguredCommand = commandName;
                        }
                        if (result.value.enabled && responseInput) {
                            responseInput.value = normalizeResponseToManyOptionsToken(responseInput.value);
                            if (responseInput.id === 'response') {
                                updateCharCount('response', 'responseCharCount');
                            }
                            if (responseInput.id === 'command_response') {
                                updateCharCount('command_response', 'editResponseCharCount');
                            }
                            var buttonId = responseInput.id === 'response' ? 'addManyOptionsBtn' : 'editManyOptionsBtn';
                            updateManyOptionsButtonVisibility(responseInput.id, buttonId);
                        }
                        if (responseInput && responseInput.id === 'command_response') {
                            clearEditCustomCommandForm();
                        }
                        Swal.fire({
                            icon: 'success',
                            title: <?php echo json_encode(t('custom_commands_js_saved_title')); ?>,
                            text: <?php echo json_encode(t('custom_commands_js_saved_text_prefix')); ?> + '!' + commandName + '.'
                        });
                    }
                );
            });
        }, false);
}

function setupRemoveButtons() {
    document.querySelectorAll('.remove-command-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = this.closest('form');
            Swal.fire({
                title: '<?php echo t('custom_commands_remove_confirm_title'); ?>',
                text: "<?php echo t('custom_commands_remove_confirm_text'); ?>",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: '<?php echo t('custom_commands_remove_confirm_btn'); ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
}

function toggleStatus(command, isChecked, elem) {
    // Prevent multiple rapid calls
    if (elem.dataset.processing === 'true') {
        return;
    }
    elem.dataset.processing = 'true';
    var icon = elem.parentElement.querySelector('i');
    var statusSpan = elem.closest('tr').querySelector('.sp-badge');
    icon.className = "fa-solid fa-spinner fa-spin";
    var status = isChecked ? 'Enabled' : 'Disabled';
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    console.log('Server response:', response);
                    if (response.success) {
                        // Update the toggle icon
                        icon.className = isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                        // Update the status tag
                        if (statusSpan) {
                            statusSpan.className = "sp-badge " + (isChecked ? "sp-badge-green" : "sp-badge-red");
                            statusSpan.textContent = isChecked ? "<?php echo t('builtin_commands_status_enabled'); ?>" : "<?php echo t('builtin_commands_status_disabled'); ?>";
                        }
                        if (response.affected_rows === 0) {
                            console.warn('No rows were affected by the update');
                            alert(<?php echo json_encode(t('custom_commands_js_alert_command_missing')); ?>);
                        }
                    } else {
                        // On error, revert the checkbox
                        elem.checked = !isChecked;
                        icon.className = !isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                        alert(<?php echo json_encode(t('custom_commands_js_alert_error_prefix')); ?> + ' ' + response.message);
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                    console.log('Raw response:', xhr.responseText);
                    // On error, revert the checkbox
                    elem.checked = !isChecked;
                    icon.className = !isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                    alert(<?php echo json_encode(t('custom_commands_js_alert_parse_error')); ?>);
                }            } else {
                // On error, revert the checkbox
                elem.checked = !isChecked;
                icon.className = !isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                alert(<?php echo json_encode(t('custom_commands_js_alert_http_error')); ?> + ' ' + xhr.status);
            }
            // Reset processing flag in all cases
            elem.dataset.processing = 'false';
        }
    };
    xhr.send("command=" + encodeURIComponent(command) + "&status=" + status);
}

function searchFunction() {
    var input = document.getElementById("searchInput");
    var filter = input.value.toLowerCase();
    var table = document.getElementById("commandsTable");
    var trs = table.getElementsByTagName("tr");
    for (var i = 1; i < trs.length; i++) {
        var tds = trs[i].getElementsByTagName("td");
        var found = false;
        for (var j = 0; j < tds.length; j++) {
            if (tds[j].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        trs[i].style.display = found ? "" : "none";
    }
}



function initCommandToEditCombobox() {
    var combobox = document.getElementById('commandToEditCombobox');
    if (!combobox) {
        return;
    }
    var hidden = document.getElementById('command_to_edit');
    var search = document.getElementById('command_to_edit_search');
    var list = document.getElementById('command_to_edit_list');
    var activeIndex = -1;
    function options() {
        return Array.prototype.slice.call(list.querySelectorAll('.cc-combobox-option'));
    }

    function openList() {
        combobox.classList.add('is-open');
        search.setAttribute('aria-expanded', 'true');
    }
    function closeList() {
        combobox.classList.remove('is-open');
        search.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
        options().forEach(function(opt) { opt.classList.remove('is-active'); });
    }
    function visibleOptions() {
        return options().filter(function(opt) { return opt.style.display !== 'none'; });
    }
    function filterList() {
        var query = search.value.trim().toLowerCase().replace(/^!/, '');
        var anyVisible = false;
        options().forEach(function(opt) {
            var match = opt.getAttribute('data-value').toLowerCase().indexOf(query) > -1;
            opt.style.display = match ? '' : 'none';
            if (match) { anyVisible = true; }
        });
        var empty = list.querySelector('.cc-combobox-empty');
        if (!anyVisible) {
            if (!empty) {
                empty = document.createElement('li');
                empty.className = 'cc-combobox-empty';
                empty.textContent = <?php echo json_encode(t('builtin_commands_no_commands')); ?>;
                list.appendChild(empty);
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
        activeIndex = -1;
        options().forEach(function(opt) { opt.classList.remove('is-active'); });
    }
    function setActive(index) {
        var vis = visibleOptions();
        if (!vis.length) {
            return;
        }
        if (index < 0) { index = vis.length - 1; }
        if (index >= vis.length) { index = 0; }
        activeIndex = index;
        options().forEach(function(opt) { opt.classList.remove('is-active'); });
        vis[index].classList.add('is-active');
        vis[index].scrollIntoView({ block: 'nearest' });
    }
    function selectOption(opt) {
        if (!opt) {
            return;
        }
        var value = opt.getAttribute('data-value');
        hidden.value = value;
        search.value = '!' + value;
        options().forEach(function(item) { item.classList.remove('is-selected'); });
        opt.classList.add('is-selected');
        closeList();
        showResponse();
        hidden.dispatchEvent(new Event('change'));
    }

    search.addEventListener('focus', function() { filterList(); openList(); });
    search.addEventListener('input', function() { openList(); filterList(); });
    search.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!combobox.classList.contains('is-open')) { filterList(); openList(); }
            setActive(activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(activeIndex - 1);
        } else if (e.key === 'Enter') {
            if (combobox.classList.contains('is-open')) {
                var vis = visibleOptions();
                var choice = (activeIndex > -1 && vis[activeIndex]) ? vis[activeIndex] : (vis.length === 1 ? vis[0] : null);
                if (choice) {
                    e.preventDefault();
                    selectOption(choice);
                }
            }
        } else if (e.key === 'Escape') {
            closeList();
        }
    });
    list.addEventListener('mousedown', function(e) {
        var opt = e.target.closest('.cc-combobox-option');
        if (opt) {
            e.preventDefault();
            selectOption(opt);
        }
    });
    document.addEventListener('click', function(e) {
        if (!combobox.contains(e.target)) {
            closeList();
        }
    });
    var form = combobox.closest('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hidden.value) {
                e.preventDefault();
                filterList();
                openList();
                search.focus();
                Swal.fire({
                    icon: 'info',
                    title: <?php echo json_encode(t('custom_commands_edit_select_placeholder')); ?>
                });
            }
        });
    }
}

function showResponse() {
    var command = document.getElementById('command_to_edit').value;
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown_response');
    var cooldownBucketInput = document.getElementById('cooldown_bucket_response');
    var newCommandInput = document.getElementById('new_command_name');
    var permissionInput = document.getElementById('permission_response');
    // Find the response for the selected command and display it in the text box
    var commandData = commands.find(c => c.command === command);
    responseInput.value = commandData ? commandData.response : '';
    cooldownInput.value = commandData ? commandData.cooldown : 15;
    var bucket = commandData && commandData.cooldown_bucket ? String(commandData.cooldown_bucket).toLowerCase() : 'default';
    if (bucket === 'mods') bucket = 'mod';
    if (['default', 'user', 'mod'].indexOf(bucket) === -1) bucket = 'default';
    if (cooldownBucketInput) {
        cooldownBucketInput.value = bucket;
    }
    newCommandInput.value = commandData ? commandData.command : '';
    // Set permission dropdown
    if (commandData && commandData.permission) {
        var displayPermission = permissionsMap[commandData.permission] || 'Everyone';
        permissionInput.value = displayPermission;
    } else {
        permissionInput.value = 'Everyone';
    }
    document.getElementById('edit_aliases').value = (commandData ? (commandData.aliases || '') : '');
    // Update character count for the edit response field
    updateCharCount('command_response', 'editResponseCharCount');
    updateManyOptionsButtonVisibility('command_response', 'editManyOptionsBtn');
    if (commandData && commandData.command) {
        prefetchRandomPickOptions(commandData.command);
    }
}

function clearEditCustomCommandForm() {
    var commandSelect = document.getElementById('command_to_edit');
    var commandSearch = document.getElementById('command_to_edit_search');
    var newCommandInput = document.getElementById('new_command_name');
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown_response');
    var cooldownBucketInput = document.getElementById('cooldown_bucket_response');
    var permissionInput = document.getElementById('permission_response');

    if (commandSelect) {
        commandSelect.value = '';
    }
    if (commandSearch) {
        commandSearch.value = '';
    }
    document.querySelectorAll('#command_to_edit_list .cc-combobox-option.is-selected').forEach(function(opt) {
        opt.classList.remove('is-selected');
    });
    if (newCommandInput) {
        newCommandInput.value = '';
    }
    if (responseInput) {
        responseInput.value = '';
        responseInput.dataset.randomPickPrompted = '';
        responseInput.dataset.randomPickConfiguredCommand = '';
    }
    if (cooldownInput) {
        cooldownInput.value = '';
    }
    if (cooldownBucketInput) {
        cooldownBucketInput.value = 'default';
    }
    if (permissionInput) {
        permissionInput.value = 'Everyone';
    }

    updateCharCount('command_response', 'editResponseCharCount');
    updateManyOptionsButtonVisibility('command_response', 'editManyOptionsBtn');
}

// Function to update character counts
function updateCharCount(inputId, counterId) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    const maxLength = charLimit;
    const currentLength = input.value.length;
    // Update the counter text
    counter.textContent = currentLength + '/' + maxLength + ' characters';
    // Update styling based on character count
    if (currentLength > maxLength) {
        counter.className = 'sp-help sp-help-danger';
        input.classList.add('sp-input-error');
        // Trim the input to maxLength characters
        input.value = input.value.substring(0, maxLength);
    } else if (currentLength > maxLength * 0.8) {
        counter.className = 'sp-help sp-help-warning';
        input.classList.remove('sp-input-error');
    } else {
        counter.className = 'sp-help';
        input.classList.remove('sp-input-error');
    }
}

// Validate form before submission
function validateForm(form) {
    const maxLength = charLimit;
    let valid = true;
    // Check all text inputs with maxlength attribute
    const textInputs = form.querySelectorAll('input[type="text"][maxlength]');
    textInputs.forEach(input => {
        if (input.value.length > maxLength) {
            input.classList.add('sp-input-error');
            valid = false;
            // Find associated help text and update
            const helpId = input.id + 'CharCount';
            const helpText = document.getElementById(helpId);
            if (helpText) {
                helpText.textContent = input.value.length + '/' + maxLength + ' characters - Exceeds limit!';
                helpText.className = 'sp-help sp-help-danger';
            }
        }
    });
    return valid;
}

// Initialize character counters when page loads
window.onload = function() {
    var betaEnabled = localStorage.getItem('betaBotMode') === '1';
    var toggle = document.getElementById('betaBotToggle');
    if (toggle) toggle.checked = betaEnabled;
    applyBetaBotCharLimit(betaEnabled);
    updateCharCount('response', 'responseCharCount');
    // Always initialize the edit response character counter, even when empty
    updateCharCount('command_response', 'editResponseCharCount');
    // Add event listener to command dropdown to update character count when a command is selected
    document.getElementById('command_to_edit').addEventListener('change', function() {
        updateCharCount('command_response', 'editResponseCharCount');
    });
    // Add form validation to both forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(this)) {
                event.preventDefault();
                alert('<?php echo t('custom_commands_char_limit_alert'); ?>');
            }
        });
    });
}
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>