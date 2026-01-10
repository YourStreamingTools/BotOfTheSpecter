<?php
session_start();
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
// Helper function to highlight log dates in a string, add <br> at end of each line, and reverse order
function highlight_log_dates($text) {
    $style = 'style="color: #e67e22; font-weight: bold;"';
    $escaped = htmlspecialchars($text);
    $lines = explode("\n", $escaped);
    $lines = array_reverse($lines);
    foreach ($lines as &$line) {
        $line = preg_replace(
            '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
            '<span ' . $style . '>$1</span>',
            $line
        );
    }
    return implode("<br>", $lines);
}

// Helper function to highlight MySQL logs with proper formatting
function highlight_mysql_logs($text) {
    $escaped = htmlspecialchars($text);
    $lines = explode("\n", $escaped);
    $lines = array_reverse($lines);
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
    $lines = array_reverse($lines);
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

// Handle AJAX log fetch for admin (always via SSH)
if (isset($_GET['admin_log_user']) && isset($_GET['admin_log_type'])) {
    header('Content-Type: application/json');
    $selectedUser = $_GET['admin_log_user'];
    $logType = $_GET['admin_log_type'];
    $logPath = "/home/botofthespecter/logs/logs/$logType/$selectedUser.txt";
    $result = read_bot_log_over_ssh($logPath);
    if (isset($result['error'])) {
        if ($result['error'] === 'not_found') { echo json_encode(['error' => 'not_found']); }
        else { echo json_encode(['error' => 'connection_failed']); }
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
    if (!file_exists($filePath)) { return ['error' => 'not_found']; }
    if (!is_readable($filePath)) { return ['error' => 'permission_denied']; }
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
    $logType = $_GET['admin_system_log_type'];
    // Determine log path and read method based on log type
    switch ($logType) {        // Standard Apache2 Logs
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
            echo json_encode(['error' => 'not_found']);
        } else if ($result['error'] === 'permission_denied') {
            echo json_encode(['error' => 'permission_denied']);
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
        'label' => 'Standard Apache2 Logs',
        'options' => [
            ['value' => 'apache2-access', 'label' => 'Apache2 Access Log (Combined)'],
            ['value' => 'apache2-error', 'label' => 'Apache2 Error Log (Combined)'],
            ['value' => 'other_vhosts_access', 'label' => 'Other Virtual Hosts Access'],
        ]
    ],
    [
        'label' => 'Apache2 Access Logs',
        'options' => [
            ['value' => 'beta.dashboard.botofthespecter.com_access', 'label' => 'Beta Dashboard Access'],
            ['value' => 'botofthespecter.com_access', 'label' => 'Main Site Access'],
            ['value' => 'cdn.botofthespecter.com_access', 'label' => 'CDN Access'],
            ['value' => 'dashboard.botofthespecter.com_access', 'label' => 'Dashboard Access'],
            ['value' => 'extension.botofthespecter.com_access', 'label' => 'Extension Access'],
            ['value' => 'help.botofthespecter.com_access', 'label' => 'Help Site Access'],
            ['value' => 'members.botofthespecter.com_access', 'label' => 'Members Access'],
            ['value' => 'overlay.botofthespecter.com_access', 'label' => 'Overlay Access'],
            ['value' => 'roadmap.botofthespecter.com_access', 'label' => 'Roadmap Access'],
            ['value' => 'soundalerts.botofthespecter.com_access', 'label' => 'Sound Alerts Access'],
            ['value' => 'tts.botofthespecter.com_access', 'label' => 'TTS Access'],
            ['value' => 'videoalerts.botofthespecter.com_access', 'label' => 'Video Alerts Access'],
            ['value' => 'walkons.botofthespecter.com_access', 'label' => 'Walkons Access'],
            ['value' => 'yourchat.botofthespecter.com_access', 'label' => 'YourChat Access'],
            ['value' => 'specterbot.app_access', 'label' => 'Specterbot App Access'],
            ['value' => 'mybot.specterbot.systems_access', 'label' => 'MyBot Specterbot Systems Access'],
            ['value' => 'specterbot.systems_access', 'label' => 'Specterbot Systems Access'],
            ['value' => 'ghostbot.au_access', 'label' => 'GhostBot AU Access'],
            ['value' => 'yourlinks.click_access', 'label' => 'YourLinks Click Access'],
        ]
    ],
    [
        'label' => 'Apache2 Error Logs',
        'options' => [
            ['value' => 'beta.dashboard.botofthespecter.com_error', 'label' => 'Beta Dashboard Errors'],
            ['value' => 'botofthespecter.com_error', 'label' => 'Main Site Errors'],
            ['value' => 'cdn.botofthespecter.com_error', 'label' => 'CDN Errors'],
            ['value' => 'dashboard.botofthespecter.com_error', 'label' => 'Dashboard Errors'],
            ['value' => 'extension.botofthespecter.com_error', 'label' => 'Extension Errors'],
            ['value' => 'help.botofthespecter.com_error', 'label' => 'Help Site Errors'],
            ['value' => 'members.botofthespecter.com_error', 'label' => 'Members Errors'],
            ['value' => 'overlay.botofthespecter.com_error', 'label' => 'Overlay Errors'],
            ['value' => 'roadmap.botofthespecter.com_error', 'label' => 'Roadmap Error'],
            ['value' => 'soundalerts.botofthespecter.com_error', 'label' => 'Sound Alerts Errors'],
            ['value' => 'tts.botofthespecter.com_error', 'label' => 'TTS Errors'],
            ['value' => 'videoalerts.botofthespecter.com_error', 'label' => 'Video Alerts Errors'],
            ['value' => 'walkons.botofthespecter.com_error', 'label' => 'Walkons Errors'],
            ['value' => 'yourchat.botofthespecter.com_error', 'label' => 'YourChat Errors'],
            ['value' => 'specterbot.app_error', 'label' => 'Specterbot App Error'],
            ['value' => 'mybot.specterbot.systems_error', 'label' => 'MyBot Specterbot Systems Error'],
            ['value' => 'specterbot.systems_error', 'label' => 'Specterbot Systems Error'],
            ['value' => 'ghostbot.au_error', 'label' => 'GhostBot AU Error'],
            ['value' => 'yourlinks.click_error', 'label' => 'YourLinks Click Error'],
        ]
    ],
    [
        'label' => 'Other System Logs',
        'options' => [
            ['value' => 'api', 'label' => 'API Server Log'],
            ['value' => 'websocket', 'label' => 'WebSocket Server Log'],
            ['value' => 'mysql-error', 'label' => 'MySQL Error Log'],
            ['value' => 'discordbot', 'label' => 'Discord Bot Log'],
        ]
    ],
];

// Fetch all users for dropdown
$users = [];
$res = $conn->query("SELECT username FROM users ORDER BY username ASC");
while ($row = $res->fetch_assoc()) { $users[] = $row['username']; }
ob_start();
?>
<div class="box">
    <div class="level mb-4">
        <div class="level-left">
            <h1 class="title is-4 mb-0"><span class="icon"><i class="fas fa-clipboard-list"></i></span> Admin Logs</h1>
        </div>
        <div class="level-right">
            <div class="field has-addons">
                <div class="control">
                    <div class="select">
                        <select id="admin-log-category-select">
                            <option value="">Select Log Category</option>
                            <option value="system">System Logs</option>
                            <option value="user">User Logs</option>
                            <option value="token">Token Logs</option>
                        </select>
                    </div>
                </div>                <div class="control">
                    <div class="select">
                        <select id="admin-log-user-select" disabled>
                            <option value="">Select Log Category First</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="control" id="user-log-type-control" style="display: none;">
                    <div class="select">
                        <select id="admin-log-type-select" disabled>
                            <option value="">Select Log Type</option>
                            <option value="bot">Bot Log</option>
                            <option value="chat">Chat Log</option>
                            <option value="twitch">Twitch Log</option>
                            <option value="api">API Log</option>
                            <option value="chat_history">Chat History</option>
                            <option value="event_log">Event Log</option>
                            <option value="websocket">Websocket Log</option>
                        </select>
                    </div>
                </div>
                <div class="control" id="system-log-type-control" style="display: none;">
                    <div class="select">
                        <select id="admin-system-log-type-select" disabled>
                            <option value="">Select System Log Type</option>
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
                    </div>
                </div>
                <div class="control" id="token-log-type-control" style="display: none; margin-left: 8px;">
                    <div class="select">
                        <select id="admin-token-log-type-select" disabled>
                            <option value="">Select Token Log</option>
                            <option value="spotify_refresh">Spotify Log</option>
                            <option value="refresh_streamelements_tokens">StreamElements Log</option>
                            <option value="refresh_discord_tokens">Discord Log</option>
                            <option value="custom_bot_token_refresh_cron">Custom Bot Logs (cron)</option>
                            <option value="custom_bot_token_refresh">Custom Bot Logs (manual)</option>
                        </select>
                    </div>
                </div>
                <div class="control">
                    <button class="button is-link" id="admin-log-reload" disabled>Reload</button>
                </div>
                <div class="control">
                    <button class="button is-light" id="admin-log-auto-refresh" disabled>
                        <span class="icon">
                            <i class="fas fa-play"></i>
                        </span>
                        <span>Auto Refresh</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="table-container">
        <div id="admin-log-textarea" class="admin-log-content" contenteditable="false" style="max-height: 400px; min-height: 200px; font-family: monospace; white-space: pre-wrap; word-break: break-all; background: #23272f; color: #f5f5f5; border: 1px solid #444; border-radius: 4px; padding: 1em; width: 100%; overflow-x: hidden; overflow-y: auto;"></div>
    </div>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const userSelect = document.getElementById('admin-log-user-select');
    const userSelectControl = userSelect.closest('.control');
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
        if (adminLogCategory === 'user') {
            userSelectControl.style.display = '';
            userSelect.disabled = false;
            userLogTypeControl.style.display = 'block';
            systemLogTypeControl.style.display = 'none';
            tokenLogTypeControl.style.display = 'none';
            systemTypeSelect.disabled = true;
            userSelect.innerHTML = '<option value="">Choose a User</option>';
            <?php foreach ($users as $u): ?>
            userSelect.innerHTML += '<option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>';
            <?php endforeach; ?>
        } else if (adminLogCategory === 'system') {
            userSelectControl.style.display = 'none';
            userLogTypeControl.style.display = 'none';
            systemLogTypeControl.style.display = 'block';
            tokenLogTypeControl.style.display = 'none';
            systemTypeSelect.disabled = false;
            let html = '<option value="">Select System Log Type</option>';
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
            userSelect.innerHTML = '<option value="">Select Log Category First</option>';
            systemTypeSelect.innerHTML = '<option value="">Select Log Category First</option>';
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
        if (adminLogCategory === 'user') {
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
        if ((adminLogCategory === 'user' && adminLogUser && adminLogType) || 
            (adminLogCategory === 'system' && adminLogType)) {
            autoRefreshInterval = setInterval(function() {
                if (adminLogCategory === 'user') {
                    fetchAdminLog();
                } else if (adminLogCategory === 'system') {
                    fetchSystemLog();
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
            autoRefreshBtn.className = 'button is-danger';
            icon.className = 'fas fa-stop';
            text.textContent = 'Stop Auto Refresh';
        } else {
            // Inactive state - light play button
            autoRefreshBtn.className = 'button is-light';
            icon.className = 'fas fa-play';
            text.textContent = 'Auto Refresh';
        }
    }
    async function fetchAdminLog() {
        if (!adminLogUser || !adminLogType) return;
        try {
            const resp = await fetch(`logs.php?admin_log_user=${encodeURIComponent(adminLogUser)}&admin_log_type=${encodeURIComponent(adminLogType)}`);
            const json = await resp.json();
            if (json.error) {
                if (json.error === "not_found") {
                    logTextarea.innerHTML = "Log file not found.";
                } else if (json.error === "connection_failed") {
                    logTextarea.innerHTML = "Unable to connect to the logging system.";
                } else {
                    logTextarea.innerHTML = "An unknown error occurred.";
                }
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = "(log file is empty)";
            } else if (!json.data || json.data.trim() === "") {
                logTextarea.innerHTML = "(log is empty or not found)";
            } else {
                logTextarea.innerHTML = json.data;
            }
        } catch (e) {
            logTextarea.innerHTML = "Unable to connect to the logging system.";
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
                    logTextarea.innerHTML = "System log file not found.";
                } else if (json.error === "permission_denied") {
                    logTextarea.innerHTML = "Permission denied: Unable to read the log file. Please check file permissions.";
                } else if (json.error === "connection_failed") {
                    logTextarea.innerHTML = "Unable to connect to the logging system.";
                } else {
                    logTextarea.innerHTML = "An unknown error occurred.";
                }
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = "(system log file is empty)";
            } else if (!json.data || json.data.trim() === "") {
                logTextarea.innerHTML = "(system log is empty or not found)";
            } else {
                logTextarea.innerHTML = json.data;
            }
        } catch (e) {
            logTextarea.innerHTML = "Unable to connect to the logging system.";
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
                    logTextarea.innerHTML = "Token log file not found.";
                } else if (json.error === "permission_denied") {
                    logTextarea.innerHTML = "Permission denied: Unable to read the log file. Please check file permissions.";
                } else if (json.error === "connection_failed") {
                    logTextarea.innerHTML = "Unable to connect to the logging system.";
                } else {
                    logTextarea.innerHTML = "An unknown error occurred.";
                }
                return;
            }
            if (json.empty) {
                logTextarea.innerHTML = "(token log file is empty)";
            } else if (!json.data || json.data.trim() === "") {
                logTextarea.innerHTML = "(token log is empty or not found)";
            } else {
                logTextarea.innerHTML = json.data;
            }
        } catch (e) {
            logTextarea.innerHTML = "Unable to connect to the logging system.";
            console.error(e);
        }
    }
});
</script>
<?php
$scripts = ob_get_clean();
$customStyles = "";
include "admin_layout.php";
echo $customStyles;
?>