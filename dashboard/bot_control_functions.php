<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// SSH Connection parameters - Include SSH configuration and connection manager
include_once "/var/www/config/ssh.php";

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
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $statusScriptPath = "/home/botofthespecter/status.py";
    // Version file locations and bot script path vary by system
    $versionFilePath = "/home/botofthespecter/logs/version";
    if ($botType === 'beta') {
        $versionFilePath .= "/beta/{$username}_beta_version_control.txt";
    } elseif ($botType === 'custom') {
        $versionFilePath .= "/custom/{$username}_custom_version_control.txt";
    } else {
        $versionFilePath .= "/{$username}_version_control.txt";
    }
    // Choose the bot script based on type
    if ($botType === 'beta') {
        $botScriptPath = "/home/botofthespecter/beta.py";
    } elseif ($botType === 'custom') {
        $botScriptPath = "/home/botofthespecter/custom.py";
    } else {
        $botScriptPath = "/home/botofthespecter/bot.py";
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
        // Set a timeout for the entire operation
        $operationStartTime = time();
        $maxOperationTime = 10; // Maximum 10 seconds for the entire operation
        // Get persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Check if we're approaching timeout
        if ((time() - $operationStartTime) >= $maxOperationTime) {
            throw new Exception('Operation timed out during connection setup.');
        }
        // SSH connection successful - now check bot status
        $result['success'] = true;
        // Get PID of the running bot
        $command = "python $statusScriptPath -system $botType -channel $username";
        $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
        $statusOutput = sanitizeSSHOutput($statusOutput);
        if ($statusOutput !== false && $statusOutput !== null) {
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
        if ($result['running']) {
            // Try to read version file via SSH
            $versionCmd = "cat " . escapeshellarg($versionFilePath);
            $versionOutput = SSHConnectionManager::executeCommand($connection, $versionCmd);
            $versionOutput = sanitizeSSHOutput($versionOutput);
            if ($versionOutput !== false && $versionOutput !== null) {
                $result['version'] = trim($versionOutput);
            } else {
                $result['version'] = '';
            }
        } else {
            $result['version'] = '';
        }
        // Get file details - don't fail if this doesn't work
        $lastModified = "stat -c %Y " . escapeshellarg($botScriptPath);
        $output = SSHConnectionManager::executeCommand($connection, $lastModified);
        $output = sanitizeSSHOutput($output);
        if ($output !== false && $output !== null) {
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
        // Clean up connections on error
        SSHConnectionManager::closeAllConnections();
    }
    return $result;
}

/**
    * Perform an action on the bot (start, stop)
    * @param string $action - The action to perform (run, stop)
    * @param string $botType - The type of bot to control (stable, beta)
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
    // Determine bot script and version path based on bot type
    if ($botType === 'beta') {
        $botScriptPath = "/home/botofthespecter/beta.py";
        $versionFilePath = "/home/botofthespecter/logs/version/beta/{$username}_beta_version_control.txt";
    } elseif ($botType === 'custom') {
        $botScriptPath = "/home/botofthespecter/custom.py";
        $versionFilePath = "/home/botofthespecter/logs/version/custom/{$username}_custom_version_control.txt";
    } else {
        $botScriptPath = "/home/botofthespecter/bot.py";
        $versionFilePath = "/home/botofthespecter/logs/version/{$username}_version_control.txt";
    }
    // Get version information from API
    $versionApiUrl = 'https://api.botofthespecter.com/versions';
    $versionInfo = json_decode(@file_get_contents($versionApiUrl), true);
    $newVersion = '';
    if ($versionInfo) {
        $newVersion = $botType === 'stable' ? ($versionInfo['stable_version'] ?? '5.2') : 
                     ($botType === 'beta' ? ($versionInfo['beta_version'] ?? '5.4') : '5.2');
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
        // Set a timeout for the entire operation
        $operationStartTime = time();
        $maxOperationTime = 8; // Maximum 8 seconds for bot actions
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Check if we're approaching timeout
        if ((time() - $operationStartTime) >= $maxOperationTime) {
            throw new Exception('Operation timed out during connection setup.');
        }
        // Check current bot status
        $command = "python $statusScriptPath -system $botType -channel $username";
    $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
    $statusOutput = sanitizeSSHOutput($statusOutput);
    if ($statusOutput === false || $statusOutput === null) { throw new Exception('Unable to check bot status. Please try again later.'); }
    $statusOutput = trim($statusOutput);
        // Get current PID
        $currentPid = 0;
        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $currentPid = intval($matches[1]);
        }
        switch ($action) {
            case 'run':
                // Before starting this bot, ensure the other bot is stopped
                $otherBotType = ($botType === 'stable') ? 'beta' : 'stable';
                $otherBotScriptPath = "/home/botofthespecter/" . ($otherBotType === 'beta' ? "beta.py" : "bot.py");
                $otherCommand = "python $statusScriptPath -system $otherBotType -channel $username";
                $otherStatusOutput = SSHConnectionManager::executeCommand($connection, $otherCommand);
                $otherStatusOutput = sanitizeSSHOutput($otherStatusOutput);
                $otherBotStoppedMessage = '';
                if ($otherStatusOutput !== false && $otherStatusOutput !== null) {
                    $otherStatusOutput = trim($otherStatusOutput);
                    $otherPid = 0;
                    if (preg_match('/process ID:\s*(\d+)/i', $otherStatusOutput, $matches) || 
                        preg_match('/PID\s+(\d+)/i', $otherStatusOutput, $matches)) {
                        $otherPid = intval($matches[1]);
                    }
                    if ($otherPid > 0) {    
                        // Stop the other bot
                        $killCommand = "kill -s kill $otherPid";
                        SSHConnectionManager::executeCommand($connection, $killCommand);
                        // Wait a moment for the process to terminate
                        usleep(500000); // 0.5 seconds
                        $otherBotStoppedMessage = "Found $otherBotType bot running, stopping it. ";
                    }
                }
                if ($currentPid > 0) {
                    $result['message'] = "Bot is running (v{$newVersion})";
                    $result['pid'] = $currentPid;
                    $result['success'] = true;
                    // Create version file via SSH
                    $versionDir = dirname($versionFilePath);
                    $createDirCmd = "mkdir -p " . escapeshellarg($versionDir);
                    SSHConnectionManager::executeCommand($connection, $createDirCmd);
                    $writeVersionCmd = "echo " . escapeshellarg($newVersion) . " > " . escapeshellarg($versionFilePath);
                    SSHConnectionManager::executeCommand($connection, $writeVersionCmd);
                } else {
                    // Validate required parameters for bot start
                    if (empty($username) || empty($twitchUserId) || empty($authToken) || empty($refreshToken) || empty($apiKey)) {
                        $result['message'] = 'Missing required bot parameters (username, tokens, etc.)';
                        break;
                    }
                    // Construct proper bot start command with all required parameters - MAKE IT BACKGROUND
                    $startCommand = "nohup python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $apiKey > /dev/null 2>&1 &";
                        $startOutput = SSHConnectionManager::executeCommand($connection, $startCommand, true); // true for background
                        $startOutput = sanitizeSSHOutput($startOutput);
                    if ($startOutput === false || $startOutput === null) {
                        $result['message'] = 'Failed to start bot - SSH command execution failed.';
                    } else {
                        // Check timeout before starting bot
                        if ((time() - $operationStartTime) >= $maxOperationTime) {
                            throw new Exception('Operation timed out before bot start.');
                        }
                        // Don't wait too long - just give it a moment and respond
                        usleep(500000); // 0.5 seconds instead of 3 seconds
                        // Quick status check with short timeout
                        $quickStatusOutput = SSHConnectionManager::executeCommand($connection, $command);
                        $quickStatusOutput = sanitizeSSHOutput($quickStatusOutput);
                        if ($quickStatusOutput !== false && $quickStatusOutput !== null) {
                            if (preg_match('/process ID:\s*(\\d+)/i', $quickStatusOutput, $matches) || preg_match('/PID\\s+(\\d+)/i', $quickStatusOutput, $matches)) {
                                $result['pid'] = intval($matches[1]);
                                $result['success'] = true;
                                $result['message'] = $otherBotStoppedMessage . "Bot started successfully (v{$newVersion})";
                                // Create version file via SSH
                                $versionDir = dirname($versionFilePath);
                                $createDirCmd = "mkdir -p " . escapeshellarg($versionDir);
                                SSHConnectionManager::executeCommand($connection, $createDirCmd);
                                $writeVersionCmd = "echo " . escapeshellarg($newVersion) . " > " . escapeshellarg($versionFilePath);
                                SSHConnectionManager::executeCommand($connection, $writeVersionCmd);
                            } else {
                                $result['message'] = $otherBotStoppedMessage . "Bot start command sent. Status will update shortly.";
                                $result['success'] = true; // Background process started
                            }
                        } else {
                            $result['message'] = $otherBotStoppedMessage . "Bot start command sent. Status check failed but process may be starting.";
                            $result['success'] = true; // Background process likely started
                        }
                    }
                }
                break;
            case 'stop':
                if ($currentPid > 0) {
                    $killCommand = "kill -s kill $currentPid";
                    $killOutput = SSHConnectionManager::executeCommand($connection, $killCommand);
                    $killOutput = sanitizeSSHOutput($killOutput);
                    if ($killOutput === false || $killOutput === null) {
                        $result['message'] = 'Unable to stop bot. Please try again later.';
                    } else {
                        // Don't wait - killing is instant
                        $result['message'] = 'Bot stop command sent.';
                        $result['success'] = true;
                    }
                } else {
                    $result['message'] = 'Bot is not running.';
                    $result['success'] = true;
                }
                break;
        }
        // Clean up old connections periodically to prevent accumulation
        SSHConnectionManager::closeAllConnections();
    } catch (Exception $e) { 
        $result['message'] = $e->getMessage(); 
        // Clean up connections on error
        SSHConnectionManager::closeAllConnections();
    }
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
