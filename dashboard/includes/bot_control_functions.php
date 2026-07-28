<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// SSH Connection parameters - Include SSH configuration and connection manager
// (still used for non-process ops: logs, token refresh scripts, etc.)
include_once "/var/www/config/ssh.php";
// Database connection for token lookups
require_once "/var/www/config/db_connect.php";
// Private bot-host control API (start/stop/status — no SSH for process control)
require_once __DIR__ . '/bots_api_client.php';

/**
 * Absolute path to the bot-host Python for a given process family.
 * Each family has its own venv under (server) /home/botofthespecter/venvs/<name>
 * so TwitchIO 2/3 and Discord/Kick deps never clobber each other.
 *
 * @param string $botType stable|beta|v6|discord|kick|status (status uses stable venv tools)
 * @return string full path to the venv python binary
 */
function botHostPython($botType = 'stable') {
    $map = [
        'stable'  => '/home/botofthespecter/venvs/stable/bin/python',
        'beta'    => '/home/botofthespecter/venvs/beta/bin/python',
        'v6'      => '/home/botofthespecter/venvs/v6/bin/python',
        'discord' => '/home/botofthespecter/venvs/discord/bin/python',
        'kick'    => '/home/botofthespecter/venvs/kick/bin/python',
        // status.py / running_bots.py need psutil; live in the stable requirements set
        'status'  => '/home/botofthespecter/venvs/stable/bin/python',
        'custom'  => '/home/botofthespecter/venvs/beta/bin/python',
    ];
    return $map[$botType] ?? $map['stable'];
}

/**
 * Sanitize raw SSH command output to remove any appended exit-code markers
 * or internal markers injected by the SSH connection manager. This ensures
 * downstream parsing (numeric checks, exact string matches) isn't broken
 * by things like "[exit_code:0]".
 *
 * @param string|false|null $output
 * @return string|false|null
 */
function sanitizeSSHOutput($output) {
    if ($output === false || $output === null) return $output;
    // Cast to string to be safe
    $o = (string)$output;
    // Remove any trailing [exit_code:NN] marker (with optional surrounding whitespace/newlines)
    $o = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', $o);
    // Remove any internal unique marker used by the SSH wrapper
    $o = preg_replace('/__SSH_EXIT_STATUS__-?\d+\s*$/', '', $o);
    return trim($o);
}

/**
    * Check if a bot is running
    * @param string $username - The username of the bot owner
    * @param string $botType - Type of bot (stable, beta)
    * @return array - Status information including running state, PID, and version
*/
function checkBotRunning($username, $botType = 'stable') {
    $result = [
        'success' => false,
        'running' => false,
        'pid' => 0,
        'version' => '',
        'lastModified' => null,
        'lastRun' => null,
        'message' => ''
    ];
    // --- Attempt 1: private bots control API ---
    try {
        $resp = bots_api_bot_status($username, $botType);
        if (!$resp['ok'] && $botType === 'beta') {
            // Beta may run as "custom" flag — try without type filter
            $resp = bots_api_bot_status($username, null);
        }
        // Only trust the result when the call actually reached the server (status > 0).
        // status === 0 means a curl error — the service is not yet deployed; fall through to SSH.
        if ($resp['status'] > 0) {
            if (!$resp['ok']) {
                $result['message'] = is_string($resp['error']) ? $resp['error'] : 'Bots API status request failed';
                return $result;
            }
            $data = is_array($resp['data']) ? $resp['data'] : [];
            $result['success'] = true;
            $foundType = $data['bot_type'] ?? null;
            $running = !empty($data['running']);
            if ($running && $botType) {
                $match = ($foundType === $botType)
                    || ($botType === 'beta' && in_array($foundType, ['beta', 'custom'], true));
                if (!$match && $foundType !== null) {
                    $running = false;
                }
            }
            $result['running'] = $running;
            $result['pid'] = $running ? intval($data['pid'] ?? 0) : 0;
            $result['version'] = $running ? (string)($data['version'] ?? '') : '';
            $result['message'] = 'Bot status retrieved successfully';
            return $result;
        }
    } catch (Exception $e) {
        // Fall through to SSH fallback
    }
    // --- Fallback: SSH + status.py (used while bots API is not yet deployed) ---
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!extension_loaded('ssh2')) {
        $result['message'] = 'SSH2 extension not available';
        return $result;
    }
    $statusPython = function_exists('botHostPython') ? botHostPython('status') : '/home/botofthespecter/venvs/stable/bin/python';
    $statusScriptPath = '/home/botofthespecter/status.py';
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $command = escapeshellarg($statusPython) . " $statusScriptPath -system $botType -channel $username";
        $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
        if ($statusOutput === false || $statusOutput === null) {
            $result['message'] = 'SSH command execution failed';
            return $result;
        }
        if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
        else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
        $statusOutput = trim($statusOutput);
        $pid = 0;
        if (preg_match('/Bot is running with process ID:\s*(\d+)/i', $statusOutput, $matches) ||
            preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        }
        $result['success'] = true;
        $result['running'] = ($pid > 0);
        $result['pid'] = $pid;
        $result['message'] = $pid > 0 ? 'Bot status retrieved successfully (SSH)' : 'Bot is not running';
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    return $result;
}

/**
    * Perform an action on the bot (start, stop) via private bots control API
    * @param string $action - The action to perform (run, stop)
    * @param string $botType - The type of bot to control (stable, beta, v6)
    * @param array $params - Additional parameters including username, tokens, etc.
    * @return array - Result of the operation
*/
function performBotAction($action, $botType, $params) {
    $username          = $params['username'] ?? '';
    $twitchUserId      = $params['twitch_user_id'] ?? '';
    $authToken         = $params['auth_token'] ?? '';
    $refreshToken      = $params['refresh_token'] ?? '';
    $apiKey            = $params['api_key'] ?? '';
    $useCustomBot      = $params['use_custom_bot'] ?? false;
    $customBotUsername = $params['custom_bot_username'] ?? null;
    $useSelf           = $params['use_self'] ?? false;

    $versionsUrl  = 'https://api.botofthespecter.com/versions';
    $versionsData = json_decode(@file_get_contents($versionsUrl), true);
    $version = '';
    if ($versionsData) {
        if ($botType === 'stable') {
            $version = $versionsData['stable_version'] ?? '';
        } elseif ($botType === 'beta') {
            $version = $versionsData['beta_version'] ?? '';
        } elseif ($botType === 'v6') {
            $version = $versionsData['v6_version'] ?? '6.0';
        } else {
            $version = $versionsData['stable_version'] ?? '';
        }
    }
    $result = [
        'success' => false,
        'action'  => $action,
        'bot'     => $botType,
        'message' => '',
        'pid'     => 0,
        'version' => $version
    ];
    try {
        switch ($action) {
            case 'run':
                if (empty($username) || empty($twitchUserId) || empty($authToken) || empty($refreshToken) || empty($apiKey)) {
                    $result['message'] = 'Missing required bot parameters (username, tokens, etc.)';
                    break;
                }
                $payload = [
                    'channel'     => $username,
                    'bot_type'    => $botType,
                    'channel_id'  => $twitchUserId,
                    'token'       => $authToken,
                    'refresh'     => $refreshToken,
                    'apitoken'    => $apiKey,
                    'custom'      => (bool)($useCustomBot && $botType === 'beta'),
                    'botusername' => ($useCustomBot && $botType === 'beta') ? $customBotUsername : null,
                    'self'        => (bool)($useSelf && $botType === 'beta'),
                    'version'     => $version,
                ];
                $resp = bots_api_start_bot($payload);
                if (!$resp['ok']) {
                    $result['message'] = is_string($resp['error']) ? $resp['error'] : 'Failed to start bot via bots API';
                    break;
                }
                $data = is_array($resp['data']) ? $resp['data'] : [];
                $result['success'] = !empty($data['success']) || in_array($data['state'] ?? '', ['started', 'already_running', 'start_pending'], true);
                $result['pid']     = intval($data['pid'] ?? 0);
                $result['version'] = $data['version'] ?? $version;
                $result['message'] = $data['message'] ?? 'Bot start requested';
                break;
            case 'stop':
                $resp = bots_api_stop_bot($username, $botType);
                if (!$resp['ok']) {
                    $result['message'] = is_string($resp['error']) ? $resp['error'] : 'Failed to stop bot via bots API';
                    break;
                }
                $data = is_array($resp['data']) ? $resp['data'] : [];
                $result['success'] = true;
                $result['pid']     = 0;
                $result['message'] = $data['message'] ?? 'Bot stop requested';
                break;
            default:
                $result['message'] = 'Unknown action';
        }
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    return $result;
}

function ensure_remote_path_exists($path, $isFile = false) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($isFile) {
            $dir = dirname($path);
            $cmd = "mkdir -p " . escapeshellarg($dir) . " && touch " . escapeshellarg($path);
        } else { $cmd = "mkdir -p " . escapeshellarg($path); }
        $output = SSHConnectionManager::executeCommand($connection, $cmd);
        if ($output === false) {
            error_log("SSH command failed: $cmd");
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log('SSH error in ensure_remote_path_exists: ' . $e->getMessage());
        return false;
    }
}

/**
    * Fallback SSH connection using system SSH command
    * @param string $host SSH host
    * @param string $username SSH username  
    * @param string $password SSH password
    * @param string $command Command to execute
    * @return array Result with output and success status
*/
function executeSSHCommand($host, $username, $password, $command) {
    $result = ['success' => false, 'output' => '', 'debug' => []];
    // Use sshpass to handle password authentication
    $ssh_command = sprintf(
        'sshpass -p %s ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 %s@%s %s 2>&1',
        escapeshellarg($password),
        escapeshellarg($username),
        escapeshellarg($host),
        escapeshellarg($command)
    );
    $result['debug'][] = "Executing SSH command via system: ssh {$username}@{$host}";
    $output = [];
    $return_code = 0;
    exec($ssh_command, $output, $return_code);
    $result['output'] = implode("\n", $output);
    $result['success'] = ($return_code === 0);
    $result['debug'][] = "SSH command return code: {$return_code}";
    $result['debug'][] = "SSH command output: " . $result['output'];
    return $result;
}
?>
