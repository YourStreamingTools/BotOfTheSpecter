<?php
session_start();

// Include necessary files to get user data
require_once '/var/www/config/db_connect.php';
include '../userdata.php';
require_once '/var/www/config/ssh.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['access_token']) || !isset($_SESSION['username'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo "event: error\n";
    echo "data: Unauthorized - Please log in\n\n";
    echo "event: done\n";
    echo "data: {\"success\":false}\n\n";
    exit;
}

// Check if user is admin
if (!isset($is_admin) || !$is_admin) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo "event: error\n";
    echo "data: Unauthorized - Admin access required\n\n";
    echo "event: done\n";
    echo "data: {\"success\":false}\n\n";
    exit;
}

// Allow only specific scripts
$mapping = [
    'spotify' => 'refresh_spotify_tokens.py',
    'streamelements' => 'refresh_streamelements_tokens.py',
    'discord' => 'refresh_discord_tokens.py'
];

$script_key = isset($_GET['script']) ? $_GET['script'] : '';
if (!isset($mapping[$script_key])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo "event: error\n";
    echo "data: Invalid script requested\n\n";
    echo "event: done\n";
    echo "data: {\"success\":false}\n\n";
    exit;
}

$script = $mapping[$script_key];

// Prepare SSE
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

// Helper to send an SSE message
function sse_send($data, $event = null) {
    if ($event) echo "event: {$event}\n";
    // split lines and send each as its own data: line
    $lines = preg_split("/\r\n|\n|\r/", rtrim($data, "\n"));
    foreach ($lines as $line) {
        // Escape any lone closing script tags or similar
        $line = str_replace("</", "<\/", $line);
        echo "data: {$line}\n";
    }
    echo "\n";
    @ob_flush(); @flush();
}

try {
    // Use bots server connection
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    if (!$connection) {
        sse_send('Failed to connect to bot server', 'error');
        sse_send(json_encode(['success' => false]), 'done');
        exit;
    }
    $cmd = "cd /home/botofthespecter && python3 " . escapeshellarg($script);
    $streams = SSHConnectionManager::executeCommandStream($connection, $cmd);
    if (!$streams || !isset($streams['stdout'])) {
        sse_send('Failed to start remote command', 'error');
        sse_send(json_encode(['success' => false]), 'done');
        exit;
    }
    $stdout = $streams['stdout'];
    $stderr = $streams['stderr'];
    $read = [$stdout, $stderr];
    // Loop until both streams are closed
    while (true) {
        $r = $read;
        $w = null; $e = null;
        $num = @stream_select($r, $w, $e, 1, 0);
        if ($num === false) break;
        if ($num > 0) {
            foreach ($r as $res) {
                $chunk = stream_get_contents($res);
                if ($chunk !== false && strlen($chunk) > 0) {
                    // Send the chunk as SSE data (preserve newlines)
                    sse_send($chunk);
                }
            }
        }
        // Exit when both streams are at EOF
        $stdout_eof = feof($stdout);
        $stderr_eof = feof($stderr);
        if ($stdout_eof && $stderr_eof) break;
    }
    // Final summary
    sse_send(json_encode(['success' => true]), 'done');
} catch (Exception $e) {
    sse_send('Exception: ' . $e->getMessage(), 'error');
    sse_send(json_encode(['success' => false]), 'done');
}
exit;
?>
