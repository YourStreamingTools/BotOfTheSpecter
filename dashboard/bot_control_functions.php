<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// SSH Connection parameters - Include SSH configuration and connection manager
include_once "/var/www/config/ssh.php";

/**
    * Check if a bot is running
    * @param string $username - The username of the bot owner
    * @param string $botType - Type of bot (stable, beta, discord)
    * @return array - Status information including running state, PID, and version
*/
function checkBotRunning($username, $botType = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $statusScriptPath = "/home/botofthespecter/status.py";
    if ($botType === 'discord') {
        // Use global service for Discord bot
        $statusScriptPath = '/var/www/bot/scripts/status.sh'; // Example path
        $output = getBotsStatus($statusScriptPath, '', 'discord');
        return (strpos($output, 'PID') !== false);
    } else {
        $versionFilePath = "/var/www/logs/version/{$username}_" . ($botType === 'beta' ? "beta_" : "") . "version_control.txt";
        $botScriptPath = "/home/botofthespecter/" . ($botType === 'beta' ? "beta.py" : "bot.py");
    }
    // Initialize result
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
        // Check if SSH credentials are configured
        if (empty($bots_ssh_host) || empty($bots_ssh_username) || empty($bots_ssh_password)) {
            $config_status = "SSH Config Status - Host: " . (empty($bots_ssh_host) ? 'EMPTY' : 'SET') . 
                           ", Username: " . (empty($bots_ssh_username) ? 'EMPTY' : 'SET') . 
                           ", Password: " . (empty($bots_ssh_password) ? 'EMPTY' : 'SET');
            error_log($config_status);
            throw new Exception('Bot service is temporarily unavailable. Please contact support if this issue persists.');
        }
        // Get persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // SSH connection successful - now check bot status
        $result['success'] = true;
        // Get PID of the running bot
        $command = $botType === 'discord'
            ? "python $statusScriptPath -system discord"
            : "python $statusScriptPath -system $botType -channel $username";
        $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
        if ($statusOutput !== false) {
            $statusOutput = trim($statusOutput);
            // Parse the output to get PID
            if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches) || 
                preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
                $pid = intval($matches[1]);
                $result['running'] = ($pid > 0);
                $result['pid'] = $pid;
            } else {
                // No process found - this is normal if bot was never started
                $result['running'] = false;
                $result['pid'] = 0;
            }
        }
        // Get version information if the bot is running
        if ($result['running'] && file_exists($versionFilePath)) {
            $result['version'] = trim(file_get_contents($versionFilePath));
        } else {
            $result['version'] = '';
        }
        // Get file details - don't fail if this doesn't work
        $lastModified = "stat -c %Y " . escapeshellarg($botScriptPath);
        $output = SSHConnectionManager::executeCommand($connection, $lastModified);
        if ($output !== false) {
            $output = trim($output);
            // Parse the output to get last modified time
            if ($output && is_numeric($output)) {
                $result['lastModified'] = $output;
            } else {
                $result['lastModified'] = null;
            }
        } else {
            $result['lastModified'] = null;
        }
        // If we get here, SSH worked fine
        $result['message'] = 'Bot status retrieved successfully';
    } catch (Exception $e) {
        // These are real errors that should set success to false
        $result['success'] = false;
        $result['message'] = $e->getMessage();
    }
    return $result;
}

/**
    * Perform an action on the bot (start, stop)
    * @param string $action - The action to perform (run, stop)
    * @param string $botType - The type of bot to control (stable, beta, discord)
    * @param array $params - Additional parameters including username, tokens, etc.
    * @return array - Result of the operation
*/
function performBotAction($action, $botType, $params) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $username = $params['username'] ?? '';
    // Extract parameters
    $twitchUserId = $params['twitch_user_id'] ?? '';
    $authToken = $params['auth_token'] ?? '';
    $refreshToken = $params['refresh_token'] ?? '';
    $apiKey = $params['api_key'] ?? '';
    // Define paths
    $statusScriptPath = "/home/botofthespecter/status.py";
    if ($botType === 'discord') {
        $botScriptPath = "/home/botofthespecter/discordbot.py";
        $versionFilePath = '/var/www/logs/version/discord_version_control.txt';
        $username = null; // Ignore username for global Discord bot
    } else {
        $botScriptPath = "/home/botofthespecter/" . ($botType === 'beta' ? "beta.py" : "bot.py");
        $versionFilePath = "/var/www/logs/version/{$username}_" . ($botType === 'beta' ? "beta_" : "") . "version_control.txt";
    }
    // Get version information from API
    $versionApiUrl = 'https://api.botofthespecter.com/versions';
    $versionInfo = json_decode(@file_get_contents($versionApiUrl), true);
    $newVersion = '';
    if ($versionInfo) {
        $newVersion = $botType === 'stable' ? ($versionInfo['stable_version'] ?? '5.2') : 
                     ($botType === 'beta' ? ($versionInfo['beta_version'] ?? '5.4') : 
                     ($botType === 'discord' ? ($versionInfo['discord_bot'] ?? '2.1') : ''));
    }
    $result = [
        'success' => false,
        'action' => $action,
        'bot' => $botType,
        'message' => '',
        'pid' => 0,
        'version' => $newVersion
    ];
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Check current bot status
        $command = $botType === 'discord'
            ? "python $statusScriptPath -system discord"
            : "python $statusScriptPath -system $botType -channel $username";
        $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
        if ($statusOutput === false) { throw new Exception('Unable to check bot status. Please try again later.'); }
        $statusOutput = trim($statusOutput);
        // Get current PID
        $currentPid = 0;
        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $currentPid = intval($matches[1]);
        }
        switch ($action) {
            case 'run':
                if ($currentPid > 0) {
                    $result['message'] = "Bot is running (v{$newVersion})";
                    $result['pid'] = $currentPid;
                    $result['success'] = true;
                    file_put_contents($versionFilePath, $newVersion);
                } else {
                    if ($botType === 'discord') {
                        $startCommand = "python $botScriptPath 2>&1 &";
                    } else {
                        $startCommand = "python $botScriptPath -channel $username 2>&1 &";
                    }
                    $startOutput = SSHConnectionManager::executeCommand($connection, $startCommand, true);
                    if ($startOutput === false) {
                        $result['message'] = 'Failed to start bot.';
                    } else {
                        sleep(3);
                        $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
                        if ($statusOutput !== false) {
                            if (preg_match('/process ID:\s*(\\d+)/i', $statusOutput, $matches) || preg_match('/PID\\s+(\\d+)/i', $statusOutput, $matches)) {
                                $result['pid'] = intval($matches[1]);
                                $result['success'] = true;
                                $result['message'] = "Bot started (v{$newVersion})";
                                file_put_contents($versionFilePath, $newVersion);
                            } else {
                                $result['message'] = 'Bot started but PID not found.';
                            }
                        } else {
                            $result['message'] = 'Failed to check bot status after start.';
                        }
                    }
                }
                break;
            case 'stop':
                if ($currentPid > 0) {
                    $killCommand = "kill -s kill $currentPid";
                    $killOutput = SSHConnectionManager::executeCommand($connection, $killCommand);
                    if ($killOutput === false) {
                        $result['message'] = 'Unable to stop bot. Please try again later.';
                    } else {
                        sleep(1);
                        $result['message'] = 'Bot stopped.';
                        $result['success'] = true;
                    }
                } else {
                    $result['message'] = 'Bot is not running.';
                }
                break;
        }
    } catch (Exception $e) { $result['message'] = $e->getMessage(); }
    return $result;
}

/**
    * Ensure remote path exists
    * @param string $path - Path to check/create
    * @param bool $isFile - Whether the path is a file (true) or directory (false)
    * @return bool - Success status
*/
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
