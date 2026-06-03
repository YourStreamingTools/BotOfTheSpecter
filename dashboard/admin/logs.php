<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_user_bot_logs_title');
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';

// Always use SSH config and log reading function for log retrieval
include_once "/var/www/config/ssh.php";
function read_bot_log_over_ssh($remote_path) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!function_exists('ssh2_connect')) { return ['error' => 'SSH2 extension not installed']; }
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) return ['error' => 'Could not connect to SSH server'];
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) { return ['error' => 'SSH authentication failed']; }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") { return ['error' => 'not_found']; }
    // Read entire file
    $cmd = "cat " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    if (trim($logContent) === '') { return ['logContent' => '','empty' => true]; }
    return ['logContent' => $logContent];
}

// Function to read Apache2 logs via SSH to localhost using server credentials
function read_apache2_log_over_ssh($remote_path) {
    global $server_username, $server_password;
    if (!function_exists('ssh2_connect')) { return ['error' => 'SSH2 extension not installed']; }
    $connection = ssh2_connect('localhost', 22);
    if (!$connection) return ['error' => 'Could not connect to SSH server'];
    if (!ssh2_auth_password($connection, $server_username, $server_password)) { return ['error' => 'SSH authentication failed']; }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") { return ['error' => 'not_found']; }
    // Read entire file
    $cmd = "cat " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    if (trim($logContent) === '') { return ['logContent' => '','empty' => true]; }
    return ['logContent' => $logContent];
}

// Function to read API server logs via SSH
function read_api_log_over_ssh($remote_path) {
    global $api_server_host, $api_server_username, $api_server_password;
    if (!function_exists('ssh2_connect')) { return ['error' => 'SSH2 extension not installed']; }
    $connection = ssh2_connect($api_server_host, 22);
    if (!$connection) return ['error' => 'Could not connect to API server'];
    if (!ssh2_auth_password($connection, $api_server_username, $api_server_password)) { return ['error' => 'SSH authentication failed']; }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") { return ['error' => 'not_found']; }
    // Read entire file
    $cmd = "cat " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    if (trim($logContent) === '') { return ['logContent' => '','empty' => true]; }
    return ['logContent' => $logContent];
}

// Function to read WebSocket server logs via SSH
function read_websocket_log_over_ssh($remote_path) {
    global $websocket_server_host, $websocket_server_username, $websocket_server_password;
    if (!function_exists('ssh2_connect')) { return ['error' => 'SSH2 extension not installed']; }
    $connection = ssh2_connect($websocket_server_host, 22);
    if (!$connection) return ['error' => 'Could not connect to WebSocket server'];
    if (!ssh2_auth_password($connection, $websocket_server_username, $websocket_server_password)) { return ['error' => 'SSH authentication failed']; }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") { return ['error' => 'not_found']; }
    // Read entire file
    $cmd = "cat " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    if (trim($logContent) === '') { return ['logContent' => '','empty' => true]; }
    return ['logContent' => $logContent];
}

// Function to read recorder server logs via SSH
function read_recorder_log_over_ssh($remote_path) {
    global $recorder_ssh_host, $recorder_ssh_username, $recorder_ssh_password;
    if (!function_exists('ssh2_connect')) { return ['error' => 'SSH2 extension not installed']; }
    $connection = ssh2_connect($recorder_ssh_host, 22);
    if (!$connection) return ['error' => 'Could not connect to recorder server'];
    if (!ssh2_auth_password($connection, $recorder_ssh_username, $recorder_ssh_password)) { return ['error' => 'SSH authentication failed']; }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") { return ['error' => 'not_found']; }
    // Read entire file
    $cmd = "cat " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    if (trim($logContent) === '') { return ['logContent' => '','empty' => true]; }
    return ['logContent' => $logContent];
}

// Function to read MySQL server logs via SSH to database server
function read_mysql_log_over_ssh($remote_path) {
    global $sql_server_host, $sql_server_username, $sql_server_password;
    if (!function_exists('ssh2_connect')) { return ['error' => 'SSH2 extension not installed']; }
    $connection = ssh2_connect($sql_server_host, 22);
    if (!$connection) return ['error' => 'Could not connect to database server'];
    if (!ssh2_auth_password($connection, $sql_server_username, $sql_server_password)) { return ['error' => 'SSH authentication failed']; }
    // Check if file exists
    $cmd_exists = "test -f " . escapeshellarg($remote_path) . " && echo 1 || echo 0";
    $stream = ssh2_exec($connection, $cmd_exists);
    stream_set_blocking($stream, true);
    $exists = trim(stream_get_contents($stream));
    fclose($stream);
    if ($exists !== "1") { return ['error' => 'not_found']; }
    // Read entire file
    $cmd = "cat " . escapeshellarg($remote_path);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking($stream, true);
    $logContent = stream_get_contents($stream);
    fclose($stream);
    if (trim($logContent) === '') { return ['logContent' => '','empty' => true]; }
    return ['logContent' => $logContent];
}

function read_log_over_ssh($remote_path, $lines = 200, $startLine = null) {
    // This function is not implemented - return an error
    return ['error' => 'not_implemented'];
}
// Helper function to highlight log dates in a string and add <br> at end of each line
function highlight_log_dates($text) {
    $dateStyle = 'style="color: #e67e22; font-weight: bold;"';
    $infoStyle = 'style="color: #3498db; font-weight: bold;"';
    $errorStyle = 'style="color: #e74c3c; font-weight: bold;"';
    $warningStyle = 'style="color: #f39c12; font-weight: bold;"';
    $debugStyle = 'style="color: #95a5a6; font-weight: bold;"';
    $escaped = htmlspecialchars($text);
    $lines = explode("\n", $escaped);
    foreach ($lines as &$line) {
        // Highlight date
        $line = preg_replace(
            '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
            '<span ' . $dateStyle . '>$1</span>',
            $line
        );
        // Highlight log levels
        $line = preg_replace(
            '/ - (info) - /',
            ' - <span ' . $infoStyle . '>$1</span> - ',
            $line
        );
        $line = preg_replace(
            '/ - (error) - /',
            ' - <span ' . $errorStyle . '>$1</span> - ',
            $line
        );
        $line = preg_replace(
            '/ - (warning) - /',
            ' - <span ' . $warningStyle . '>$1</span> - ',
            $line
        );
        $line = preg_replace(
            '/ - (debug) - /',
            ' - <span ' . $debugStyle . '>$1</span> - ',
            $line
        );
    }
    return implode("<br>", $lines);
}

// Helper function to highlight MySQL logs with proper formatting
function highlight_mysql_logs($text) {
    $escaped = htmlspecialchars($text);
    $lines = explode("\n", $escaped);
    // Define styles
    $dateStyle = 'style="color: #e67e22; font-weight: bold;"';
    $warningStyle = 'style="color: #f39c12; font-weight: bold;"';
    $systemStyle = 'style="color: #3498db; font-weight: bold;"';
    $errorStyle = 'style="color: #e74c3c; font-weight: bold;"';
    $noteStyle = 'style="color: #27ae60; font-weight: bold;"';
    foreach ($lines as &$line) {
        // Highlight MySQL timestamps (2025-09-24T08:44:00.275216Z) and convert to readable format
        $line = preg_replace_callback(
            '/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.\d{6}Z/',
            function($matches) use ($dateStyle) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = intval($matches[4]);
                $minute = $matches[5];
                $second = $matches[6];
                // Convert to 12-hour format with AM/PM
                $ampm = ($hour >= 12) ? 'PM' : 'AM';
                $displayHour = ($hour == 0) ? 12 : (($hour > 12) ? $hour - 12 : $hour);
                // Format as dd/mm/yyyy h:mm:ss AM/PM
                $readableTime = sprintf('%s/%s/%s %d:%s:%s %s', $day, $month, $year, $displayHour, $minute, $second, $ampm);
                return '<span ' . $dateStyle . '>' . $readableTime . '</span>';
            },
            $line
        );
        // Highlight [Warning] tags
        $line = preg_replace(
            '/(\[Warning\])/i',
            '<span ' . $warningStyle . '>$1</span>',
            $line
        );
        // Highlight [System] tags
        $line = preg_replace(
            '/(\[System\])/i',
            '<span ' . $systemStyle . '>$1</span>',
            $line
        );
        // Highlight [Error] tags
        $line = preg_replace(
            '/(\[Error\])/i',
            '<span ' . $errorStyle . '>$1</span>',
            $line
        );
        // Highlight [Note] tags
        $line = preg_replace(
            '/(\[Note\])/i',
            '<span ' . $noteStyle . '>$1</span>',
            $line
        );
    }
    return implode("<br>", $lines);
}

// Helper function to highlight Apache2 logs with proper formatting
function highlight_apache2_logs($text, $logType) {
    $escaped = htmlspecialchars($text);
    $lines = explode("\n", $escaped);
    // Define styles
    $dateStyle = 'style="color: #e67e22; font-weight: bold;"';
    $ipStyle = 'style="color: #3498db; font-weight: bold;"';
    $localhostStyle = 'style="color: #9b59b6; font-weight: bold;"';
    $errorStyle = 'style="color: #e74c3c; font-weight: bold;"';
    foreach ($lines as &$line) {
        if (strpos($logType, 'access') !== false) {
            // Apache2 Access Log Format: IP - - [date] ...
            // Handle IPv6 localhost (::1)
            $line = preg_replace(
                '/^(::1)/',
                '<span ' . $localhostStyle . '>$1 (localhost IPv6)</span>',
                $line
            );
            // Handle IPv4 localhost (127.0.0.1)
            $line = preg_replace(
                '/^(127\.0\.0\.1)/',
                '<span ' . $localhostStyle . '>$1 (localhost)</span>',
                $line
            );
            // Handle other IP addresses (IPv4)
            $line = preg_replace(
                '/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/',
                '<span ' . $ipStyle . '>$1</span>',
                $line
            );
            // Handle other IPv6 addresses
            $line = preg_replace(
                '/^([0-9a-fA-F:]+)(?!\s*\(localhost)/',
                '<span ' . $ipStyle . '>$1</span>',
                $line
            );
            // Highlight dates in access logs [15/Jun/2025:00:23:04 +1000]
            $line = preg_replace(
                '/\[(\d{2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s[+-]\d{4})\]/',
                '[<span ' . $dateStyle . '>$1</span>]',
                $line
            );
        } elseif (strpos($logType, 'error') !== false) {
            // Highlight dates in error logs [Sun Jun 15 00:00:03.003748 2025]
            $line = preg_replace(
                '/\[(\w{3}\s\w{3}\s\d{2}\s\d{2}:\d{2}:\d{2}\.\d+\s\d{4})\]/',
                '[<span ' . $dateStyle . '>$1</span>]',
                $line
            );
            // Highlight error levels
            $line = preg_replace(
                '/\[(error|warn|notice|info|debug|crit|alert|emerg)\]/',
                '[<span ' . $errorStyle . '>$1</span>]',
                $line
            );
        }
    }
    return implode("<br>", $lines);
}

function highlight_admin_audit_logs($rows) {
    if (!is_array($rows) || empty($rows)) {
        return t('admin_logs_audit_none');
    }
    $html = [];
    foreach ($rows as $row) {
        $createdAt = htmlspecialchars($row['created_at'] ?? '');
        $actor = htmlspecialchars($row['actor_username'] ?? 'unknown');
        $action = htmlspecialchars($row['action'] ?? 'unknown');
        $status = htmlspecialchars($row['status'] ?? 'info');
        $targetType = htmlspecialchars($row['target_type'] ?? '');
        $targetValue = htmlspecialchars($row['target_value'] ?? '');
        $requestMethod = htmlspecialchars($row['request_method'] ?? '');
        $requestPath = htmlspecialchars($row['request_path'] ?? '');
        $detailsRaw = $row['details_json'] ?? '';
        $detailsSummary = '';
        if (!empty($detailsRaw)) {
            $decoded = json_decode($detailsRaw, true);
            if (is_array($decoded)) {
                $flattened = [];
                foreach ($decoded as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    $flattened[] = $key . '=' . (string) $value;
                }
                $detailsSummary = implode(' | ', $flattened);
            } else {
                $detailsSummary = (string) $detailsRaw;
            }
        }
        if (strlen($detailsSummary) > 500) {
            $detailsSummary = substr($detailsSummary, 0, 500) . '...';
        }
        $line = '[' . $createdAt . '] '
            . '<span style="color:#9cdcfe;">actor=' . $actor . '</span> '
            . '<span style="color:#ce9178;">action=' . $action . '</span> '
            . '<span style="color:#b5cea8;">status=' . $status . '</span> '
            . '<span style="color:#dcdcaa;">request=' . $requestMethod . ' ' . $requestPath . '</span>';
        if ($targetType !== '' || $targetValue !== '') {
            $line .= ' <span style="color:#c586c0;">target=' . $targetType . ':' . $targetValue . '</span>';
        }
        if ($detailsSummary !== '') {
            $line .= '<br>&nbsp;&nbsp;<span style="color:#d4d4d4;">details=' . htmlspecialchars($detailsSummary) . '</span>';
        }
        $html[] = $line;
    }
    return implode('<br><br>', $html);
}

if (isset($_GET['admin_audit_log'])) {
    header('Content-Type: application/json');
    ob_clean();
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 300;
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 1000) {
        $limit = 1000;
    }
    $tableCheck = $conn->query("SHOW TABLES LIKE 'admin_audit_log'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        echo json_encode(['data' => t('admin_logs_audit_table_missing')]);
        exit();
    }
    $stmt = $conn->prepare("SELECT created_at, actor_username, action, status, target_type, target_value, details_json, request_method, request_path FROM admin_audit_log ORDER BY id DESC LIMIT ?");
    if (!$stmt) {
        echo json_encode(['error' => 'query_failed']);
        exit();
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    if (empty($rows)) {
        echo json_encode(['data' => t('admin_logs_audit_none'), 'empty' => true]);
        exit();
    }
    echo json_encode(['data' => highlight_admin_audit_logs($rows)]);
    exit();
}

// Handle AJAX log fetch for admin (always via SSH)
if (isset($_GET['admin_log_user']) && isset($_GET['admin_log_type'])) {
    header('Content-Type: application/json');
    ob_clean();
    $selectedUser = $_GET['admin_log_user'];
    $logType = $_GET['admin_log_type'];
    $logPath = ($logType === 'crash')
        ? "/home/botofthespecter/logs/{$selectedUser}_crash.log"
        : "/home/botofthespecter/logs/logs/$logType/$selectedUser.txt";
    $result = read_bot_log_over_ssh($logPath);
    if (isset($result['error'])) {
        // If file not found, return empty log message
        if ($result['error'] === 'not_found') {
            echo json_encode(['data' => t('admin_logs_user_file_empty')]);
        } else {
            echo json_encode(['error' => 'connection_failed']);
        }
        exit();
    }
    if (isset($result['empty']) && $result['empty']) {
        echo json_encode(['data' => '', 'empty' => true]);
        exit();
    }
    $logContent = $result['logContent'];
    $logContent = highlight_log_dates($logContent);
    echo json_encode(['data' => $logContent]);
    exit();
}

// Function to read local log files directly
function read_local_log($filePath, $lines = 200, $startLine = null) {
    if (!file_exists($filePath)) { return ['error' => 'not_found', 'path' => $filePath]; }
    if (!is_readable($filePath)) { return ['error' => 'permission_denied', 'path' => $filePath]; }
    // Count total lines
    $linesTotal = 0;
    $handle = fopen($filePath, 'r');
    if ($handle) {
        while (!feof($handle)) {
            fgets($handle);
            $linesTotal++;
        }
        fclose($handle);
    }
    if ($linesTotal === 0) { return ['linesTotal' => 0, 'logContent' => '', 'empty' => true]; }
    if ($startLine === null) { $startLine = max(0, $linesTotal - $lines); }
    // Read the specified lines
    $logLines = [];
    $handle = fopen($filePath, 'r');
    if ($handle) {
        $currentLine = 0;
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($currentLine >= $startLine && count($logLines) < $lines) {
                $logLines[] = rtrim($line, "\n\r");
            }
            $currentLine++;
            if (count($logLines) >= $lines) {
                break;
            }
        }
        fclose($handle);
    }
    return [
        'linesTotal' => $linesTotal,
        'logContent' => implode("\n", $logLines)
    ];
}

// Handle AJAX log fetch for system logs
if (isset($_GET['admin_system_log_type'])) {
    header('Content-Type: application/json');
    ob_clean();
    $logType = $_GET['admin_system_log_type'];
    // Determine log path and read method based on log type
    switch ($logType) {        // Standard Apache2 Logs
                case 'streamersconnect_ssl_access':
                    $logPath = "/var/log/apache2/streamersconnect_ssl_access.log";
                    $result = read_apache2_log_over_ssh($logPath);
                    break;
                case 'streamersconnect_ssl_error':
                    $logPath = "/var/log/apache2/streamersconnect_ssl_error.log";
                    $result = read_apache2_log_over_ssh($logPath);
                    break;
        case 'apache2-access':
            $logPath = "/var/log/apache2/access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'apache2-error':
            $logPath = "/var/log/apache2/error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'other_vhosts_access':
            $logPath = "/var/log/apache2/other_vhosts_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        // Apache2 Access Logs
        case 'beta.dashboard.botofthespecter.com_access':
            $logPath = "/var/log/apache2/beta.dashboard.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'botofthespecter.com_access':
            $logPath = "/var/log/apache2/botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'cdn.botofthespecter.com_access':
            $logPath = "/var/log/apache2/cdn.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'dashboard.botofthespecter.com_access':
            $logPath = "/var/log/apache2/dashboard.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'members.botofthespecter.com_access':
            $logPath = "/var/log/apache2/members.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'overlay.botofthespecter.com_access':
            $logPath = "/var/log/apache2/overlay.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'soundalerts.botofthespecter.com_access':
            $logPath = "/var/log/apache2/soundalerts.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'tts.botofthespecter.com_access':
            $logPath = "/var/log/apache2/tts.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'videoalerts.botofthespecter.com_access':
            $logPath = "/var/log/apache2/videoalerts.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'walkons.botofthespecter.com_access':
            $logPath = "/var/log/apache2/walkons.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        // Apache2 Error Logs
        case 'beta.dashboard.botofthespecter.com_error':
            $logPath = "/var/log/apache2/beta.dashboard.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'botofthespecter.com_error':
            $logPath = "/var/log/apache2/botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'cdn.botofthespecter.com_error':
            $logPath = "/var/log/apache2/cdn.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'dashboard.botofthespecter.com_error':
            $logPath = "/var/log/apache2/dashboard.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'members.botofthespecter.com_error':
            $logPath = "/var/log/apache2/members.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'overlay.botofthespecter.com_error':
            $logPath = "/var/log/apache2/overlay.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'soundalerts.botofthespecter.com_error':
            $logPath = "/var/log/apache2/soundalerts.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'tts.botofthespecter.com_error':
            $logPath = "/var/log/apache2/tts.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'videoalerts.botofthespecter.com_error':
            $logPath = "/var/log/apache2/videoalerts.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'walkons.botofthespecter.com_error':
            $logPath = "/var/log/apache2/walkons.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'specterbot.app_error':
            $logPath = "/var/log/apache2/specterbot.app_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'specterbot.app_access':
            $logPath = "/var/log/apache2/specterbot.app_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'ghostbot.au_access':
            $logPath = "/var/log/apache2/ghostbot_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'ghostbot.au_error':
            $logPath = "/var/log/apache2/ghostbot_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'roadmap.botofthespecter.com_access':
            $logPath = "/var/log/apache2/roadmap_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'roadmap.botofthespecter.com_error':
            $logPath = "/var/log/apache2/roadmap_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'extension.botofthespecter.com_access':
            $logPath = "/var/log/apache2/extension.botofthespecter.com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'extension.botofthespecter.com_error':
            $logPath = "/var/log/apache2/extension.botofthespecter.com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'help.botofthespecter.com_access':
            $logPath = "/var/log/apache2/help-botofthespecter-com_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'help.botofthespecter.com_error':
            $logPath = "/var/log/apache2/help-botofthespecter-com_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'mybot.specterbot.systems_access':
            $logPath = "/var/log/apache2/mybot.specterbot.systems-access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'mybot.specterbot.systems_error':
            $logPath = "/var/log/apache2/mybot.specterbot.systems-error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'specterbot.systems_access':
            $logPath = "/var/log/apache2/specterbot.systems-access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'specterbot.systems_error':
            $logPath = "/var/log/apache2/specterbot.systems-error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'yourchat.botofthespecter.com_access':
            $logPath = "/var/log/apache2/yourchat_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'yourchat.botofthespecter.com_error':
            $logPath = "/var/log/apache2/yourchat_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'yourlinks.click_access':
            $logPath = "/var/log/apache2/yourlinks.click_access.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'yourlinks.click_error':
            $logPath = "/var/log/apache2/yourlinks.click_error.log";
            $result = read_apache2_log_over_ssh($logPath);
            break;
        case 'discordbot':
            // Discord bot log in custom directory (use SSH)
            $logPath = "/home/botofthespecter/logs/specterdiscord/discordbot.txt";
            $result = read_bot_log_over_ssh($logPath);
            break;
        case 'api':
            // API server log (use API server SSH)
            $logPath = "/home/botofthespecter/log.txt";
            $result = read_api_log_over_ssh($logPath);
            break;
        case 'websocket':
            // WebSocket server log (use WebSocket server SSH)
            $logPath = "/home/botofthespecter/noti_server.log";
            $result = read_websocket_log_over_ssh($logPath);
            break;
        case 'mysql-error':
            // MySQL error log (use database server SSH)
            $logPath = "/var/log/mysql/error.log";
            $result = read_mysql_log_over_ssh($logPath);
            break;
        case 'streamersconnect-cleanup':
            // StreamersConnect cleanup tokens log (local file)
            $logPath = "/var/log/streamersconnect/cleanup_tokens.log";
            $result = read_local_log($logPath, 500);
            break;
        case 'twitch-recorder':
            // Twitch recorder uses a single fixed log file (rotated by size)
            $logPath = "/home/botofthespecter/logs/twitch-recorder.log";
            $result = read_recorder_log_over_ssh($logPath);
            break;
        default:
            // Other system logs in custom directory (use SSH)
            $logPath = "/home/botofthespecter/logs/system/$logType.txt";
            $result = read_log_over_ssh($logPath);
            break;
    }
    // Check if result is null or invalid
    if ($result === null || !is_array($result)) {
        echo json_encode(['error' => 'invalid_log_type']);
        exit();
    }
    if (isset($result['error'])) {
        if ($result['error'] === 'not_found') { 
            echo json_encode(['error' => 'not_found', 'path' => isset($result['path']) ? $result['path'] : $logPath]);
        } else if ($result['error'] === 'permission_denied') {
            echo json_encode(['error' => 'permission_denied', 'path' => isset($result['path']) ? $result['path'] : $logPath]);
        } else { 
            echo json_encode(['error' => 'connection_failed']); 
        }
        exit();
    }
    if (isset($result['empty']) && $result['empty']) {
        echo json_encode(['data' => '', 'empty' => true]);
        exit();
    }
    // Safely access array keys with default values
    $logContent = isset($result['logContent']) ? $result['logContent'] : '';
    // Apply appropriate highlighting based on log type
    if (strpos($logType, 'apache2-') === 0 || strpos($logType, '_access') !== false || strpos($logType, '_error') !== false || $logType === 'other_vhosts_access') {
        // This is an Apache2 log, use specialized highlighting
        $logContent = highlight_apache2_logs($logContent, $logType);
    } elseif ($logType === 'mysql-error') {
        // This is a MySQL log, use MySQL-specific highlighting
        $logContent = highlight_mysql_logs($logContent);
    } else { $logContent = highlight_log_dates($logContent); }
    echo json_encode(['data' => $logContent]);
    exit();
}

// Handle AJAX token log fetch (Token Logs category)
if (isset($_GET['admin_token_log_type'])) {
    header('Content-Type: application/json');
    ob_clean();
    $logType = $_GET['admin_token_log_type'];
    $map = [
        'spotify_refresh' => '/home/botofthespecter/logs/spotify_refresh.log',
        'refresh_streamelements_tokens' => '/home/botofthespecter/logs/refresh_streamelements_tokens.log',
        'refresh_discord_tokens' => '/home/botofthespecter/logs/refresh_discord_tokens.log',
        'custom_bot_token_refresh_cron' => '/home/botofthespecter/logs/custom_bot_token_refresh_cron.log',
        'custom_bot_token_refresh' => '/home/botofthespecter/logs/custom_bot_token_refresh.log',
    ];
    if (!isset($map[$logType])) {
        echo json_encode(['error' => 'invalid_log_type']);
        exit();
    }
    $logPath = $map[$logType];
    $result = read_bot_log_over_ssh($logPath);
    if ($result === null || !is_array($result)) { echo json_encode(['error' => 'invalid_log_type']); exit(); }
    if (isset($result['error'])) {
        if ($result['error'] === 'not_found') { echo json_encode(['error' => 'not_found']); }
        else if ($result['error'] === 'permission_denied') { echo json_encode(['error' => 'permission_denied']); }
        else { echo json_encode(['error' => 'connection_failed']); }
        exit();
    }
    if (isset($result['empty']) && $result['empty']) { echo json_encode(['data' => '', 'empty' => true]); exit(); }
    $logContent = isset($result['logContent']) ? $result['logContent'] : '';
    $logContent = highlight_log_dates($logContent);
    echo json_encode(['data' => $logContent]);
    exit();
}

// Define system log types in a single PHP array for both PHP and JS
$systemLogTypes = [
    [
        'label' => t('admin_logs_group_standard_apache'),
        'options' => [
            ['value' => 'apache2-access', 'label' => t('admin_logs_opt_apache2_access_combined')],
            ['value' => 'apache2-error', 'label' => t('admin_logs_opt_apache2_error_combined')],
            ['value' => 'other_vhosts_access', 'label' => t('admin_logs_opt_other_vhosts_access')],
        ]
    ],
    [
        'label' => t('admin_logs_group_apache_access'),
        'options' => [
            ['value' => 'beta.dashboard.botofthespecter.com_access', 'label' => t('admin_logs_opt_beta_dashboard_access')],
            ['value' => 'botofthespecter.com_access', 'label' => t('admin_logs_opt_main_site_access')],
            ['value' => 'cdn.botofthespecter.com_access', 'label' => t('admin_logs_opt_cdn_access')],
            ['value' => 'dashboard.botofthespecter.com_access', 'label' => t('admin_logs_opt_dashboard_access')],
            ['value' => 'extension.botofthespecter.com_access', 'label' => t('admin_logs_opt_extension_access')],
            ['value' => 'help.botofthespecter.com_access', 'label' => t('admin_logs_opt_help_access')],
            ['value' => 'members.botofthespecter.com_access', 'label' => t('admin_logs_opt_members_access')],
            ['value' => 'overlay.botofthespecter.com_access', 'label' => t('admin_logs_opt_overlay_access')],
            ['value' => 'roadmap.botofthespecter.com_access', 'label' => t('admin_logs_opt_roadmap_access')],
            ['value' => 'soundalerts.botofthespecter.com_access', 'label' => t('admin_logs_opt_soundalerts_access')],
            ['value' => 'tts.botofthespecter.com_access', 'label' => t('admin_logs_opt_tts_access')],
            ['value' => 'videoalerts.botofthespecter.com_access', 'label' => t('admin_logs_opt_videoalerts_access')],
            ['value' => 'walkons.botofthespecter.com_access', 'label' => t('admin_logs_opt_walkons_access')],
            ['value' => 'yourchat.botofthespecter.com_access', 'label' => t('admin_logs_opt_yourchat_access')],
            ['value' => 'specterbot.app_access', 'label' => t('admin_logs_opt_specterbot_app_access')],
            ['value' => 'mybot.specterbot.systems_access', 'label' => t('admin_logs_opt_mybot_systems_access')],
            ['value' => 'specterbot.systems_access', 'label' => t('admin_logs_opt_specterbot_systems_access')],
            ['value' => 'ghostbot.au_access', 'label' => t('admin_logs_opt_ghostbot_access')],
            ['value' => 'yourlinks.click_access', 'label' => t('admin_logs_opt_yourlinks_access')],
            ['value' => 'streamersconnect_ssl_access', 'label' => t('admin_logs_opt_streamersconnect_access')],
        ]
    ],
    [
        'label' => t('admin_logs_group_apache_error'),
        'options' => [
            ['value' => 'beta.dashboard.botofthespecter.com_error', 'label' => t('admin_logs_opt_beta_dashboard_error')],
            ['value' => 'botofthespecter.com_error', 'label' => t('admin_logs_opt_main_site_error')],
            ['value' => 'cdn.botofthespecter.com_error', 'label' => t('admin_logs_opt_cdn_error')],
            ['value' => 'dashboard.botofthespecter.com_error', 'label' => t('admin_logs_opt_dashboard_error')],
            ['value' => 'extension.botofthespecter.com_error', 'label' => t('admin_logs_opt_extension_error')],
            ['value' => 'help.botofthespecter.com_error', 'label' => t('admin_logs_opt_help_error')],
            ['value' => 'members.botofthespecter.com_error', 'label' => t('admin_logs_opt_members_error')],
            ['value' => 'overlay.botofthespecter.com_error', 'label' => t('admin_logs_opt_overlay_error')],
            ['value' => 'roadmap.botofthespecter.com_error', 'label' => t('admin_logs_opt_roadmap_error')],
            ['value' => 'soundalerts.botofthespecter.com_error', 'label' => t('admin_logs_opt_soundalerts_error')],
            ['value' => 'tts.botofthespecter.com_error', 'label' => t('admin_logs_opt_tts_error')],
            ['value' => 'videoalerts.botofthespecter.com_error', 'label' => t('admin_logs_opt_videoalerts_error')],
            ['value' => 'walkons.botofthespecter.com_error', 'label' => t('admin_logs_opt_walkons_error')],
            ['value' => 'yourchat.botofthespecter.com_error', 'label' => t('admin_logs_opt_yourchat_error')],
            ['value' => 'specterbot.app_error', 'label' => t('admin_logs_opt_specterbot_app_error')],
            ['value' => 'mybot.specterbot.systems_error', 'label' => t('admin_logs_opt_mybot_systems_error')],
            ['value' => 'specterbot.systems_error', 'label' => t('admin_logs_opt_specterbot_systems_error')],
            ['value' => 'ghostbot.au_error', 'label' => t('admin_logs_opt_ghostbot_error')],
            ['value' => 'yourlinks.click_error', 'label' => t('admin_logs_opt_yourlinks_error')],
            ['value' => 'streamersconnect_ssl_error', 'label' => t('admin_logs_opt_streamersconnect_error')],
        ]
    ],
    [
        'label' => t('admin_logs_group_other_system'),
        'options' => [
            ['value' => 'api', 'label' => t('admin_logs_opt_api_server')],
            ['value' => 'websocket', 'label' => t('admin_logs_opt_websocket_server')],
            ['value' => 'mysql-error', 'label' => t('admin_logs_opt_mysql_error')],
            ['value' => 'discordbot', 'label' => t('admin_logs_opt_discord_bot')],
            ['value' => 'streamersconnect-cleanup', 'label' => t('admin_logs_opt_streamersconnect_cleanup')],
            ['value' => 'twitch-recorder', 'label' => t('admin_logs_opt_twitch_recorder')],
        ]
    ],
];

// Fetch all users for dropdown
$users = [];
$res = $conn->query("SELECT username FROM users ORDER BY username ASC");
while ($row = $res->fetch_assoc()) { $users[] = $row['username']; }
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><span class="icon"><i class="fas fa-clipboard-list"></i></span> <?php echo t('admin_logs_heading'); ?></h1>
    </div>
    <div class="sp-card-body">
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin-bottom:1rem;">
        <select class="sp-select" id="admin-log-category-select">
                            <option value=""><?php echo t('admin_logs_cat_select'); ?></option>
                            <option value="audit"><?php echo t('admin_logs_cat_audit'); ?></option>
                            <option value="system"><?php echo t('admin_logs_cat_system'); ?></option>
                            <option value="user"><?php echo t('admin_logs_cat_user'); ?></option>
                            <option value="token"><?php echo t('admin_logs_cat_token'); ?></option>
                        </select>
        <div id="admin-log-user-control">
        <select class="sp-select" id="admin-log-user-select" disabled>
                            <option value=""><?php echo t('admin_logs_select_category_first'); ?></option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                            <?php endforeach; ?>
                        </select>
        </div><!-- /admin-log-user-control -->
        <div id="user-log-type-control" style="display: none;">
        <select class="sp-select" id="admin-log-type-select" disabled>
                            <option value=""><?php echo t('admin_logs_type_select'); ?></option>
                            <option value="bot"><?php echo t('admin_logs_type_bot'); ?></option>
                            <option value="chat"><?php echo t('admin_logs_type_chat'); ?></option>
                            <option value="twitch"><?php echo t('admin_logs_type_twitch'); ?></option>
                            <option value="api"><?php echo t('admin_logs_type_api'); ?></option>
                            <option value="chat_history"><?php echo t('admin_logs_type_chat_history'); ?></option>
                            <option value="event_log"><?php echo t('admin_logs_type_event'); ?></option>
                            <option value="websocket"><?php echo t('admin_logs_type_websocket'); ?></option>
                            <option value="system"><?php echo t('admin_logs_type_system'); ?></option>
                            <option value="integrations"><?php echo t('admin_logs_type_integrations'); ?></option>
                            <option value="crash"><?php echo t('admin_logs_type_crash'); ?></option>
                        </select>
        </div><!-- /user-log-type-control -->
        <div id="system-log-type-control" style="display: none;">
        <select class="sp-select" id="admin-system-log-type-select" disabled>
                            <option value=""><?php echo t('admin_logs_system_type_select'); ?></option>
                            <?php
                            foreach ($systemLogTypes as $group) {
                                echo '<optgroup label="' . htmlspecialchars($group['label']) . '">';
                                foreach ($group['options'] as $opt) {
                                    echo '<option value="' . htmlspecialchars($opt['value']) . '">' . htmlspecialchars($opt['label']) . '</option>';
                                }
                                echo '</optgroup>';
                            }
                            ?>
                        </select>
        </div><!-- /system-log-type-control -->
        <div id="token-log-type-control" style="display: none;">
        <select class="sp-select" id="admin-token-log-type-select" disabled>
                            <option value=""><?php echo t('admin_logs_token_select'); ?></option>
                            <option value="spotify_refresh"><?php echo t('admin_logs_token_spotify'); ?></option>
                            <option value="refresh_streamelements_tokens"><?php echo t('admin_logs_token_streamelements'); ?></option>
                            <option value="refresh_discord_tokens"><?php echo t('admin_logs_token_discord'); ?></option>
                            <option value="custom_bot_token_refresh_cron"><?php echo t('admin_logs_token_custom_bot_cron'); ?></option>
                            <option value="custom_bot_token_refresh"><?php echo t('admin_logs_token_custom_bot_manual'); ?></option>
                        </select>
        </div><!-- /token-log-type-control -->
        <button class="sp-btn sp-btn-info" id="admin-log-reload" disabled><?php echo t('admin_logs_btn_reload'); ?></button>
        <button class="sp-btn" id="admin-log-auto-refresh" disabled>
            <span class="icon">
                <i class="fas fa-play"></i>
            </span>
            <span><?php echo t('admin_logs_btn_auto_refresh'); ?></span>
        </button>
    </div><!-- /filter-row -->
    <div id="admin-log-textarea" class="admin-log-content" contenteditable="false" style="max-height: 400px; min-height: 200px; font-family: monospace; white-space: pre-wrap; word-break: break-all; background: #23272f; color: #f5f5f5; border: 1px solid #444; border-radius: 4px; padding: 1em; width: 100%; overflow-x: hidden; overflow-y: auto;"></div>
    </div><!-- /sp-card-body -->
</div><!-- /sp-card -->
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
const ADMIN_LOGS_I18N = {
    chooseUser: <?php echo json_encode(t('admin_logs_choose_user')); ?>,
    selectCategoryFirst: <?php echo json_encode(t('admin_logs_select_category_first')); ?>,
    systemTypeSelect: <?php echo json_encode(t('admin_logs_system_type_select')); ?>,
    autoRefresh: <?php echo json_encode(t('admin_logs_btn_auto_refresh')); ?>,
    stopAutoRefresh: <?php echo json_encode(t('admin_logs_btn_stop_auto_refresh')); ?>,
    pathLabel: <?php echo json_encode(t('admin_logs_js_path_label')); ?>,
    connectionFailed: <?php echo json_encode(t('admin_logs_js_connection_failed')); ?>,
    unknownError: <?php echo json_encode(t('admin_logs_js_unknown_error')); ?>,
    permissionDenied: <?php echo json_encode(t('admin_logs_js_permission_denied')); ?>,
    userNotFound: <?php echo json_encode(t('admin_logs_js_user_not_found')); ?>,
    userFileEmpty: <?php echo json_encode(t('admin_logs_js_user_file_empty')); ?>,
    userEmptyOrNotFound: <?php echo json_encode(t('admin_logs_js_user_empty_or_not_found')); ?>,
    auditLoadFailed: <?php echo json_encode(t('admin_logs_js_audit_load_failed')); ?>,
    auditNone: <?php echo json_encode(t('admin_logs_audit_none')); ?>,
    auditEmpty: <?php echo json_encode(t('admin_logs_js_audit_empty')); ?>,
    systemNotFound: <?php echo json_encode(t('admin_logs_js_system_not_found')); ?>,
    systemFileEmpty: <?php echo json_encode(t('admin_logs_js_system_file_empty')); ?>,
    systemEmptyOrNotFound: <?php echo json_encode(t('admin_logs_js_system_empty_or_not_found')); ?>,
    tokenNotFound: <?php echo json_encode(t('admin_logs_js_token_not_found')); ?>,
    tokenFileEmpty: <?php echo json_encode(t('admin_logs_js_token_file_empty')); ?>,
    tokenEmptyOrNotFound: <?php echo json_encode(t('admin_logs_js_token_empty_or_not_found')); ?>
};
document.addEventListener('DOMContentLoaded', function() {
    const userSelect = document.getElementById('admin-log-user-select');
    const userSelectControl = document.getElementById('admin-log-user-control');
    const userLogTypeControl = document.getElementById('user-log-type-control');
    const typeSelect = document.getElementById('admin-log-type-select');
    const systemLogTypeControl = document.getElementById('system-log-type-control');
    const systemTypeSelect = document.getElementById('admin-system-log-type-select');
    const reloadBtn = document.getElementById('admin-log-reload');
    const logTextarea = document.getElementById('admin-log-textarea');
    const categorySelect = document.getElementById('admin-log-category-select');
    const tokenLogTypeControl = document.getElementById('token-log-type-control');
    const tokenTypeSelect = document.getElementById('admin-token-log-type-select');
    const autoRefreshBtn = document.getElementById('admin-log-auto-refresh');
    // Function to scroll log container to bottom
    function scrollLogToBottom() {
        logTextarea.scrollTop = logTextarea.scrollHeight;
    }
    const systemLogTypes = <?php echo json_encode($systemLogTypes); ?>;
    let adminLogCategory = '';
    let adminLogUser = '';
    let adminLogType = '';
    let autoRefreshInterval = null;
    let isAutoRefreshActive = false;
    categorySelect.addEventListener('change', function() {
        adminLogCategory = this.value;
        resetLogContent();
        adminLogUser = '';
        adminLogType = '';
        userSelect.value = '';
        typeSelect.value = '';
        tokenTypeSelect.value = '';
        typeSelect.disabled = true;
        tokenTypeSelect.disabled = true;
        reloadBtn.disabled = true;
        autoRefreshBtn.disabled = true; // Reset auto refresh button
        if (adminLogCategory === 'audit') {
            userSelectControl.style.display = 'none';
            userLogTypeControl.style.display = 'none';
            systemLogTypeControl.style.display = 'none';
            tokenLogTypeControl.style.display = 'none';
            systemTypeSelect.disabled = true;
            fetchAuditLog();
            reloadBtn.disabled = false;
            autoRefreshBtn.disabled = false;
        } else if (adminLogCategory === 'user') {
            userSelectControl.style.display = '';
            userSelect.disabled = false;
            userLogTypeControl.style.display = 'block';
            systemLogTypeControl.style.display = 'none';
            tokenLogTypeControl.style.display = 'none';
            systemTypeSelect.disabled = true;
            userSelect.innerHTML = '<option value="">' + ADMIN_LOGS_I18N.chooseUser + '</option>';
            <?php foreach ($users as $u): ?>
            userSelect.innerHTML += '<option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>';
            <?php endforeach; ?>
        } else if (adminLogCategory === 'system') {
            userSelectControl.style.display = 'none';
            userLogTypeControl.style.display = 'none';
            systemLogTypeControl.style.display = 'block';
            tokenLogTypeControl.style.display = 'none';
            systemTypeSelect.disabled = false;
            let html = '<option value="">' + ADMIN_LOGS_I18N.systemTypeSelect + '</option>';
            for (const group of systemLogTypes) {
                html += `<optgroup label="${group.label}">`;
                for (const opt of group.options) {
                    html += `<option value="${opt.value}">${opt.label}</option>`;
                }
                html += '</optgroup>';
            }
            systemTypeSelect.innerHTML = html;
        } else if (adminLogCategory === 'token') {
            userSelectControl.style.display = 'none';
            userLogTypeControl.style.display = 'none';
            systemLogTypeControl.style.display = 'none';
            tokenLogTypeControl.style.display = 'block';
            tokenTypeSelect.disabled = false;
        } else {
            userSelectControl.style.display = '';
            userSelect.disabled = true;
            userLogTypeControl.style.display = 'none';
            systemLogTypeControl.style.display = 'none';
            tokenLogTypeControl.style.display = 'none';
            userSelect.innerHTML = '<option value="">' + ADMIN_LOGS_I18N.selectCategoryFirst + '</option>';
            systemTypeSelect.innerHTML = '<option value="">' + ADMIN_LOGS_I18N.selectCategoryFirst + '</option>';
            systemTypeSelect.disabled = true;
        }
    });
    userSelect.addEventListener('change', function() {
        adminLogUser = this.value;
        resetLogContent();
        if (adminLogCategory === 'user') {
            typeSelect.disabled = !adminLogUser;
            if (!adminLogUser) {
                typeSelect.value = '';
                reloadBtn.disabled = true;
                autoRefreshBtn.disabled = true; // Disable auto refresh when no user selected
            }
        }
    });
    systemTypeSelect.addEventListener('change', function() {
        adminLogType = this.value;
        resetLogContent();
        if (adminLogType) {
            fetchSystemLog();
            reloadBtn.disabled = false;
            autoRefreshBtn.disabled = false;
        } else {
            reloadBtn.disabled = true;
            autoRefreshBtn.disabled = true;
        }
    });
    tokenTypeSelect.addEventListener('change', function() {
        adminLogType = this.value;
        resetLogContent();
        if (adminLogType) {
            fetchTokenLog();
            reloadBtn.disabled = false;
            autoRefreshBtn.disabled = false;
        } else {
            reloadBtn.disabled = true;
            autoRefreshBtn.disabled = true;
        }
    });
    typeSelect.addEventListener('change', function() {
        adminLogType = this.value;
        if (adminLogUser && adminLogType) {
            fetchAdminLog();
            reloadBtn.disabled = false;
            autoRefreshBtn.disabled = false;
        } else {
            resetLogContent();
            reloadBtn.disabled = true;
            autoRefreshBtn.disabled = true;
        }
    });
    reloadBtn.addEventListener('click', function() {
        if (adminLogCategory === 'audit') {
            fetchAuditLog();
        } else if (adminLogCategory === 'user') {
            fetchAdminLog();
        } else if (adminLogCategory === 'system') {
            fetchSystemLog();
        } else if (adminLogCategory === 'token') {
            fetchTokenLog();
        }
    });
    autoRefreshBtn.addEventListener('click', function() {
        if (isAutoRefreshActive) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
        }
    });
    function resetLogContent() {
        logTextarea.innerHTML = '';
        stopAutoRefresh(); // Stop auto refresh when resetting content
    }
    function startAutoRefresh() {
        if (autoRefreshInterval) return; // Already running
        // Only start if we have a valid log selection
        if ((adminLogCategory === 'audit') ||
            (adminLogCategory === 'user' && adminLogUser && adminLogType) ||
            (adminLogCategory === 'system' && adminLogType) ||
            (adminLogCategory === 'token' && adminLogType)) {
            autoRefreshInterval = setInterval(function() {
                if (adminLogCategory === 'audit') {
                    fetchAuditLog();
                } else if (adminLogCategory === 'user') {
                    fetchAdminLog();
                } else if (adminLogCategory === 'system') {
                    fetchSystemLog();
                } else if (adminLogCategory === 'token') {
                    fetchTokenLog();
                }
            }, 10000); // 10 seconds
            // Update button state
            isAutoRefreshActive = true;
            updateAutoRefreshButton();
        }
    }
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
        // Update button state
        isAutoRefreshActive = false;
        updateAutoRefreshButton();
    }
    function updateAutoRefreshButton() {
        const icon = autoRefreshBtn.querySelector('i');
        const text = autoRefreshBtn.querySelector('span:last-child');
        if (isAutoRefreshActive) {
            // Active state - red stop button
            autoRefreshBtn.className = 'sp-btn sp-btn-danger';
            icon.className = 'fas fa-stop';
            text.textContent = ADMIN_LOGS_I18N.stopAutoRefresh;
        } else {
            // Inactive state - light play button
            autoRefreshBtn.className = 'sp-btn';
            icon.className = 'fas fa-play';
            text.textContent = ADMIN_LOGS_I18N.autoRefresh;
        }
    }
    async function fetchAdminLog() {
        if (!adminLogUser || !adminLogType) return;
        try {
            const resp = await fetch(`logs.php?admin_log_user=${encodeURIComponent(adminLogUser)}&admin_log_type=${encodeURIComponent(adminLogType)}`);
            const json = await resp.json();
            if (json.error) {
                if (json.error === "not_found") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.userNotFound;
                } else if (json.error === "connection_failed") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
                } else {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.unknownError;
                }
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.userFileEmpty;
            } else if (!json.data || json.data.trim() === "") {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.userEmptyOrNotFound;
            } else {
                logTextarea.innerHTML = json.data;
            }
            scrollLogToBottom();
        } catch (e) {
            logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
            console.error(e);
        }
    }
    async function fetchAuditLog() {
        try {
            const resp = await fetch('logs.php?admin_audit_log=1');
            const json = await resp.json();
            if (json.error) {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.auditLoadFailed;
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.auditNone;
            } else if (!json.data || json.data.trim() === '') {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.auditEmpty;
            } else {
                logTextarea.innerHTML = json.data;
            }
            logTextarea.scrollTop = 0;
        } catch (e) {
            logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
            console.error(e);
        }
    }
    async function fetchSystemLog() {
        if (!adminLogType) return;
        try {
            const resp = await fetch(`logs.php?admin_system_log_type=${encodeURIComponent(adminLogType)}`);
            const json = await resp.json();
            if (json.error) {
                if (json.error === "not_found") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.systemNotFound + (json.path ? " " + ADMIN_LOGS_I18N.pathLabel + " " + json.path : "");
                } else if (json.error === "permission_denied") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.permissionDenied + (json.path ? " " + ADMIN_LOGS_I18N.pathLabel + " " + json.path : "");
                } else if (json.error === "connection_failed") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
                } else {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.unknownError;
                }
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.systemFileEmpty;
            } else if (!json.data || json.data.trim() === "") {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.systemEmptyOrNotFound;
            } else {
                logTextarea.innerHTML = json.data;
            }
            scrollLogToBottom();
        } catch (e) {
            logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
            console.error(e);
        }
    }
    async function fetchTokenLog() {
        if (!adminLogType) return;
        try {
            const resp = await fetch(`logs.php?admin_token_log_type=${encodeURIComponent(adminLogType)}`);
            const json = await resp.json();
            if (json.error) {
                if (json.error === "not_found") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.tokenNotFound;
                } else if (json.error === "permission_denied") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.permissionDenied;
                } else if (json.error === "connection_failed") {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
                } else {
                    logTextarea.innerHTML = ADMIN_LOGS_I18N.unknownError;
                }
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.tokenFileEmpty;
            } else if (!json.data || json.data.trim() === "") {
                logTextarea.innerHTML = ADMIN_LOGS_I18N.tokenEmptyOrNotFound;
            } else {
                logTextarea.innerHTML = json.data;
            }
            scrollLogToBottom();
        } catch (e) {
            logTextarea.innerHTML = ADMIN_LOGS_I18N.connectionFailed;
            console.error(e);
        }
    }
});
</script>
<?php
$scripts = ob_get_clean();
$customStyles = "";
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
echo $customStyles;
?>