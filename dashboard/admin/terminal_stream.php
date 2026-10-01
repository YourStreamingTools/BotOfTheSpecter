<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '../includes/userdata.php';
// The session stays open until the request has been validated / the one-shot stream id consumed.
// It is released before any long-running streaming so other dashboard requests are not blocked.

// Load translations so user-facing SSE/error messages are localized.
if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
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

@set_time_limit(0);
// Keep running after the browser disconnects so the remote process can be killed and the run audited.
ignore_user_abort(true);

const TERMINAL_MAX_COMMAND_LENGTH = 2000;
const TERMINAL_STREAM_TTL = 60;
const TERMINAL_HEARTBEAT_SECONDS = 2;

$streamTerminated = false;

// Safe mode is a guard rail against accidents, not a security boundary: the boundary is super admin + CSRF + audit log.
function isDangerousCommand(string $command): bool {
    $patterns = [
        '/\brm\s+-rf\s+\//i',
        '/\brm\b(?=[^;&|]*\s(?:-[a-zA-Z]*[rR][a-zA-Z]*|--recursive)(?=\s))[^;&|]*\s(?:\/\*?|~\/?\*?|\$HOME|\*)(?:\s|$)/',
        '/\bfind\b[^;&|]*\s-delete\b/i',
        '/\b(?:drop|truncate)\s+(?:database|table|schema)\b/i',
        '/\bchmod\s+-R\b[^;&|]*\s\/(?:\s|$)/',
        '/>{1,2}\s*\/(?:etc|boot|usr|bin|sbin|lib)\//',
        '/>\s*\/dev\/(?:sd|nvme|vd)[a-z0-9]*/i',
        '/\bdd\s+if=.*\bof=\/dev\//i',
        '/\bmkfs(\.|\s)/i',
        '/:\s*\(\)\s*\{\s*:\s*\|\s*:\s*&\s*\};\s*:/',
        '/\bshutdown\b/i',
        '/\breboot\b/i',
        '/\bpoweroff\b/i',
        '/\bhalt\b/i',
        '/\bformat\b/i',
        '/\bdel\s+\/f\s+\/s\s+\/q\b/i',
        '/\btruncate\s+-s\s+0\b/i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $command)) {
            return true;
        }
    }
    return false;
}

// One JSON-encoded payload per event, so blank lines and odd bytes survive the trip to the browser.
function sse_send($data, $event = 'message') {
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    echo "event: $event\ndata: $json\n\n";
    @ob_flush();
    flush();
}

function sse_heartbeat() {
    echo ": ping\n\n";
    @ob_flush();
    flush();
}

function sendDoneEvent(array $payload = []) {
    global $streamTerminated;
    if ($streamTerminated) {
        return;
    }
    $streamTerminated = true;
    sse_send($payload, 'done');
}

// Only registered once the SSE phase begins, so JSON replies and plain 403s are not followed by a stray "done" event.
function terminal_register_stream_shutdown() {
    register_shutdown_function(function() {
        global $streamTerminated;
        if ($streamTerminated) {
            return;
        }
        $error = error_get_last();
        if ($error) {
            sse_send(t('admin_terminal_stream_unhandled_error', [$error['message']]), 'stderr');
            sendDoneEvent(['error' => $error['message']]);
        } else {
            sendDoneEvent([]);
        }
    });
}

function terminal_is_super_admin(): bool {
    global $conn;
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT super_admin FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($flag);
    $isSuper = $stmt->fetch() && (int) $flag === 1;
    $stmt->close();
    return $isSuper;
}

function terminal_json_exit(array $payload, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Split a raw chunk into complete lines; the unfinished tail stays in $buffer for the next chunk.
function terminal_pump(string &$buffer, string $chunk, callable $onLine) {
    $buffer .= $chunk;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = rtrim(substr($buffer, 0, $pos), "\r");
        $buffer = substr($buffer, $pos + 1);
        // A carriage return rewinds the line (progress bars), so only the last segment is what a terminal would show.
        if (($cr = strrpos($line, "\r")) !== false) {
            $line = substr($line, $cr + 1);
        }
        $onLine($line);
    }
}

// Kill the remote process tree (children first) so an interrupted run does not keep going on the server.
function terminal_kill_remote($connection, $pid) {
    if (!$pid) {
        return;
    }
    $kill = 'kt() { for c in $(pgrep -P "$1" 2>/dev/null); do kt "$c"; done; kill -TERM "$1" 2>/dev/null; }; kt ' . (int) $pid;
    $channel = @ssh2_exec($connection, $kill);
    if ($channel) {
        stream_set_blocking($channel, true);
        stream_set_timeout($channel, 3);
        @stream_get_contents($channel);
        fclose($channel);
    }
}

// Authorization: super admin only (is_admin has already been enforced by admin_access.php)
if (!terminal_is_super_admin()) {
    session_write_close();
    terminal_json_exit(['ok' => false, 'error' => t('admin_terminal_super_admin_only')], 403);
}

// Map server names to SSH credentials
$ssh_configs = [
    'bots' => [
        'host' => $bots_ssh_host,
        'username' => $bots_ssh_username,
        'password' => $bots_ssh_password,
        'name' => 'Bot Server'
    ],
    'api' => [
        'host' => $api_server_host,
        'username' => $api_server_username,
        'password' => $api_server_password,
        'name' => 'API Server'
    ],
    'web' => [
        'host' => 'localhost',
        'username' => $server_username,
        'password' => $server_password,
        'name' => 'Web Server'
    ],
    'websocket' => [
        'host' => $websocket_server_host,
        'username' => $websocket_server_username,
        'password' => $websocket_server_password,
        'name' => 'WebSocket Server'
    ],
    'sql' => [
        'host' => $sql_server_host,
        'username' => $sql_server_username,
        'password' => $sql_server_password,
        'name' => 'SQL Server'
    ]
];

// Step 1 (POST): validate the request and hand back a one-shot stream id bound to this session.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['terminal_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $csrf)) {
        session_write_close();
        terminal_json_exit(['ok' => false, 'error' => t('admin_terminal_stream_err_csrf')], 403);
    }
    $server = (string) ($_POST['server'] ?? '');
    $command = trim((string) ($_POST['command'] ?? ''));
    $safeMode = ($_POST['safe'] ?? '1') === '1';
    $confirmed = ($_POST['confirmed'] ?? '0') === '1';
    if ($server === '' || $command === '') {
        session_write_close();
        terminal_json_exit(['ok' => false, 'error' => t('admin_terminal_stream_err_missing_param')], 400);
    }
    if (strlen($command) > TERMINAL_MAX_COMMAND_LENGTH) {
        session_write_close();
        terminal_json_exit(['ok' => false, 'error' => t('admin_terminal_stream_err_too_long', [TERMINAL_MAX_COMMAND_LENGTH])], 400);
    }
    if (!isset($ssh_configs[$server])) {
        session_write_close();
        terminal_json_exit(['ok' => false, 'error' => t('admin_terminal_stream_err_invalid_server')], 400);
    }
    if ($safeMode && !$confirmed && isDangerousCommand($command)) {
        session_write_close();
        terminal_json_exit(['ok' => false, 'needs_confirm' => true]);
    }
    $pending = $_SESSION['terminal_pending'] ?? [];
    foreach ($pending as $pendingId => $entry) {
        if (time() - (int) ($entry['created'] ?? 0) > TERMINAL_STREAM_TTL) {
            unset($pending[$pendingId]);
        }
    }
    $pending = array_slice($pending, -19, null, true);
    $streamId = bin2hex(random_bytes(16));
    $pending[$streamId] = ['server' => $server, 'command' => $command, 'created' => time()];
    $_SESSION['terminal_pending'] = $pending;
    session_write_close();
    terminal_json_exit(['ok' => true, 'stream' => $streamId]);
}

// Step 2 (GET): consume the stream id exactly once. A replayed id (e.g. an EventSource auto-reconnect) is rejected,
// which makes the browser close the connection instead of silently re-running the command.
$streamId = (string) ($_GET['stream'] ?? '');
$job = $_SESSION['terminal_pending'][$streamId] ?? null;
unset($_SESSION['terminal_pending'][$streamId]);
session_write_close();
if (!$job || time() - (int) $job['created'] > TERMINAL_STREAM_TTL || !isset($ssh_configs[$job['server']])) {
    http_response_code(403);
    exit;
}
$server = $job['server'];
$command = $job['command'];
$config = $ssh_configs[$server];

terminal_register_stream_shutdown();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

admin_audit_log('terminal_command_started', 'started', ['server' => $server, 'command' => $command], 'terminal', $server);
$startedAt = microtime(true);
$state = ['pid' => null, 'exit' => null];
$aborted = false;
$connection = null;

try {
    $connection = SSHConnectionManager::getConnection($config['host'], $config['username'], $config['password']);
    if (!$connection) {
        sse_send(t('admin_terminal_stream_err_connect', [$config['name']]), 'stderr');
        sendDoneEvent(['error' => t('admin_terminal_stream_err_ssh_failed_short')]);
        admin_audit_log('terminal_command_finished', 'failed', ['server' => $server, 'command' => $command, 'error' => 'ssh_connect'], 'terminal', $server);
        exit;
    }
    sse_send(t('admin_terminal_stream_executing_on', [$config['name'], $command]));
    // Report the shell pid first (so an interrupt can kill the tree) and the real exit status last.
    $remote = 'echo "__TS_PID__$$"; "${SHELL:-/bin/sh}" -c ' . escapeshellarg($command) . '; rc=$?; printf "__TS_EXIT__%s\n" "$rc"';
    $stream = SSHConnectionManager::executeCommandStream($connection, $remote);
    if (!$stream) {
        sse_send(t('admin_terminal_stream_err_exec'), 'stderr');
        sendDoneEvent(['error' => t('admin_terminal_stream_err_exec_short')]);
        admin_audit_log('terminal_command_finished', 'failed', ['server' => $server, 'command' => $command, 'error' => 'exec'], 'terminal', $server);
        exit;
    }
    if (is_array($stream)) {
        $stdout = $stream['stdout'] ?? null;
        $stderr = $stream['stderr'] ?? null;
    } else {
        $stdout = $stream;
        $stderr = null;
    }
    $onStdoutLine = function (string $line) use (&$state) {
        if ($state['pid'] === null && preg_match('/^__TS_PID__(\d+)$/', $line, $m)) {
            $state['pid'] = (int) $m[1];
            return;
        }
        if (preg_match('/^(.*)__TS_EXIT__(-?\d+)$/s', $line, $m)) {
            $state['exit'] = (int) $m[2];
            $line = $m[1];
            if ($line === '') {
                return;
            }
        }
        sse_send($line);
    };
    $onStderrLine = function (string $line) {
        sse_send($line, 'stderr');
    };
    $outBuffer = '';
    $errBuffer = '';
    $lastSend = time();
    while (($stdout && !feof($stdout)) || ($stderr && !feof($stderr))) {
        if (connection_aborted()) {
            $aborted = true;
            break;
        }
        $dataRead = false;
        if ($stdout && !feof($stdout)) {
            $data = @fread($stdout, 8192);
            if ($data !== false && $data !== '') {
                terminal_pump($outBuffer, $data, $onStdoutLine);
                $dataRead = true;
            }
        }
        if ($stderr && !feof($stderr)) {
            $edata = @fread($stderr, 8192);
            if ($edata !== false && $edata !== '') {
                terminal_pump($errBuffer, $edata, $onStderrLine);
                $dataRead = true;
            }
        }
        if ($dataRead) {
            $lastSend = time();
        } else {
            // Idle: the heartbeat doubles as the probe that notices a disconnected browser.
            if (time() - $lastSend >= TERMINAL_HEARTBEAT_SECONDS) {
                sse_heartbeat();
                $lastSend = time();
            }
            usleep(20000);
        }
    }
    if (!$aborted) {
        // Flush any unterminated trailing output.
        if ($outBuffer !== '') {
            terminal_pump($outBuffer, "\n", $onStdoutLine);
        }
        if ($errBuffer !== '') {
            terminal_pump($errBuffer, "\n", $onStderrLine);
        }
    }
    if ($aborted) {
        terminal_kill_remote($connection, $state['pid']);
    }
    if ($stdout && is_resource($stdout)) { fclose($stdout); }
    if ($stderr && is_resource($stderr)) { fclose($stderr); }
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    if ($aborted) {
        admin_audit_log('terminal_command_finished', 'interrupted', ['server' => $server, 'command' => $command, 'duration_ms' => $durationMs], 'terminal', $server);
        exit;
    }
    $exitCode = $state['exit'];
    admin_audit_log('terminal_command_finished', $exitCode === 0 ? 'success' : 'failed', ['server' => $server, 'command' => $command, 'exit_code' => $exitCode, 'duration_ms' => $durationMs], 'terminal', $server);
    sendDoneEvent(['success' => $exitCode === 0, 'exit_code' => $exitCode]);
} catch (Exception $e) {
    if ($connection && $state['pid']) {
        terminal_kill_remote($connection, $state['pid']);
    }
    sse_send(t('admin_terminal_stream_err_prefix', [$e->getMessage()]), 'stderr');
    admin_audit_log('terminal_command_finished', 'failed', ['server' => $server, 'command' => $command, 'error' => $e->getMessage()], 'terminal', $server);
    sendDoneEvent(['error' => $e->getMessage()]);
}
?>
