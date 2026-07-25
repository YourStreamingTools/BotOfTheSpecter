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
    try {
        $resp = bots_api_bot_status($username, $botType);
        if (!$resp['ok']) {
            // Beta may run as "custom" flag — try without type filter
            if ($botType === 'beta') {
                $resp = bots_api_bot_status($username, null);
            }
        }
        if (!$resp['ok']) {
            throw new Exception(is_string($resp['error']) ? $resp['error'] : 'Bots API status request failed');
        }
        $data = is_array($resp['data']) ? $resp['data'] : [];
        $result['success'] = true;
        $foundType = $data['bot_type'] ?? null;
        $running = !empty($data['running']);
        // If we asked for a specific type, only treat as running when types match
        // (or beta when custom is found)
        if ($running && $botType) {
            $match = ($foundType === $botType)
                || ($botType === 'beta' && in_array($foundType, ['beta', 'custom'], true));
            if (!$match && $foundType !== null) {
                // A different variant is running; for this check, not this type
                $running = false;
            }
        }
        $result['running'] = $running;
        $result['pid'] = $running ? intval($data['pid'] ?? 0) : 0;
        $result['version'] = $running ? (string)($data['version'] ?? '') : '';
        $result['message'] = 'Bot status retrieved successfully';
    } catch (Exception $e) {
        $result['success'] = false;
        $result['message'] = $e->getMessage();
    }
    return $result;
}

/**
    * Perform an action on the bot (start, stop) via private bots control API
    * @param string \ - The action to perform (run, stop)
    * @param string \ - The type of bot to control (stable, beta, v6)
    * @param array \ - Additional parameters including username, tokens, etc.
    * @return array - Result of the operation
*/
function performBotAction(\, \, \) {
    \ = \['username'] ?? '';
    \ = \['twitch_user_id'] ?? '';
    \ = \['auth_token'] ?? '';
    \ = \['refresh_token'] ?? '';
    \ = \['api_key'] ?? '';
    \ = \['use_custom_bot'] ?? false;
    \ = \['custom_bot_username'] ?? null;
    \ = \['use_self'] ?? false;

    \ = 'https://api.botofthespecter.com/versions';
    \ = json_decode(@file_get_contents(\), true);
    \ = '';
    if (\) {
        if (\ === 'stable') {
            \ = \['stable_version'] ?? '';
        } elseif (\ === 'beta') {
            \ = \['beta_version'] ?? '';
        } elseif (\ === 'v6') {
            \ = \['v6_version'] ?? '6.0';
        } else {
            \ = \['stable_version'] ?? '';
        }
    }
    \ = [
        'success' => false,
        'action' => \,
        'bot' => \,
        'message' => '',
        'pid' => 0,
        'version' => \
    ];
    try {
        switch (\) {
            case 'run':
                if (empty(\) || empty(\) || empty(\) || empty(\) || empty(\)) {
                    \['message'] = 'Missing required bot parameters (username, tokens, etc.)';
                    break;
                }
                \ = [
                    'channel' => \,
                    'bot_type' => \,
                    'channel_id' => \,
                    'token' => \,
                    'refresh' => \,
                    'apitoken' => \,
                    'custom' => (bool)(\ && \ === 'beta'),
                    'botusername' => (\ && \ === 'beta') ? \ : null,
                    'self' => (bool)(\ && \ === 'beta'),
                    'version' => \,
                ];
                \ = bots_api_start_bot(\);
                if (!\['ok']) {
                    \['message'] = is_string(\['error']) ? \['error'] : 'Failed to start bot via bots API';
                    break;
                }
                \ = is_array(\['data']) ? \['data'] : [];
                \['success'] = !empty(\['success']) || in_array(\['state'] ?? '', ['started', 'already_running', 'start_pending'], true);
                \['pid'] = intval(\['pid'] ?? 0);
                \['version'] = \['version'] ?? \;
                \['message'] = \['message'] ?? 'Bot start requested';
                break;
            case 'stop':
                \ = bots_api_stop_bot(\, \);
                if (!\['ok']) {
                    \['message'] = is_string(\['error']) ? \['error'] : 'Failed to stop bot via bots API';
                    break;
                }
                \ = is_array(\['data']) ? \['data'] : [];
                \['success'] = true;
                \['pid'] = 0;
                \['message'] = \['message'] ?? 'Bot stop requested';
                break;
            default:
                \['message'] = 'Unknown action';
        }
    } catch (Exception \) {
        \['message'] = \->getMessage();
    }
    return \;
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
