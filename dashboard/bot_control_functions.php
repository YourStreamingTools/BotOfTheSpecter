<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// SSH Connection parameters - Include SSH configuration
include_once "/var/www/config/ssh.php";

/**
    * Check if a bot is running
    * @param string $username - The username of the bot owner
    * @param string $botType - Type of bot (stable, beta, discord)
    * @return array - Status information including running state, PID, and version
*/
function checkBotRunning($username, $botType = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    // Prevent concurrent SSH connections for the same user
    static $connection_locks = array();
    $lock_key = $username . '_' . $botType;
    if (isset($connection_locks[$lock_key])) {
        return [
            'success' => false,
            'running' => false,
            'pid' => 0,
            'version' => '',
            'lastModified' => null,
            'lastRun' => null,
            'message' => 'Another connection attempt is in progress, please wait'
        ];
    }
    $connection_locks[$lock_key] = time();
    // Debug: Check if SSH config file exists
    $ssh_config_path = "/var/www/config/ssh.php";
    $ssh_config_exists = file_exists($ssh_config_path);
    $ssh_config_readable = is_readable($ssh_config_path);
    $statusScriptPath = "/home/botofthespecter/status.py";
    $versionFilePath = "/var/www/logs/version/{$username}_" . (
        $botType === 'stable' ? "" :
        ($botType === 'beta' ? "beta_" :
        ($botType === 'discord' ? "discord_" : ""))
    ) . "version_control.txt";
    if ($botType === 'stable') { $botScriptPath = "/home/botofthespecter/bot.py"; }
        elseif ($botType === 'beta') { $botScriptPath = "/home/botofthespecter/beta.py"; }
        elseif ($botType === 'discord') { $botScriptPath = "/home/botofthespecter/discordbot.py"; }    else { $botScriptPath = "/home/botofthespecter/bot.py"; }
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
        // Check if SSH2 extension is loaded
        if (!extension_loaded('ssh2')) { throw new Exception('SSH2 PHP extension is not loaded'); }
        // Check if SSH credentials are configured
        if (empty($bots_ssh_host) || empty($bots_ssh_username) || empty($bots_ssh_password)) {
            throw new Exception('SSH credentials not configured. Please check config/ssh.php');
        }
        // Test basic network connectivity first (with cache to avoid spam)
        static $connectivity_cache = array();
        $connectivity_key = $bots_ssh_host . ':22';
        $cache_duration = 30; // seconds
        if (!isset($connectivity_cache[$connectivity_key]) || 
            (time() - $connectivity_cache[$connectivity_key]['time']) > $cache_duration) {
            $fp = @fsockopen($bots_ssh_host, 22, $errno, $errstr, 10);
            if (!$fp) {
                $connectivity_cache[$connectivity_key] = ['success' => false, 'time' => time(), 'error' => $errstr];
            } else {
                fclose($fp);
                $connectivity_cache[$connectivity_key] = ['success' => true, 'time' => time()];
            }
        }
        // Establish SSH connection with retry logic
        $connection = false;
        $max_retries = 5; // Increased from 3 to 5
        $base_delay = 0.5; // Reduced from 1 second to 0.5 seconds
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            // Add small random delay to prevent connection conflicts
            if ($attempt > 1) {
                $random_delay = mt_rand(100, 500); // 100-500ms
                usleep($random_delay * 1000);
            }
            // Try connection with explicit timeout and options
            $connection = ssh2_connect($bots_ssh_host, 22, array(
                'hostkey' => 'ssh-rsa,ssh-dss,ssh-ed25519',
                'kex' => 'diffie-hellman-group1-sha1,diffie-hellman-group14-sha1,diffie-hellman-group-exchange-sha1,diffie-hellman-group-exchange-sha256',
                'client_to_server' => array(
                    'crypt' => 'aes128-ctr,aes192-ctr,aes256-ctr,aes256-cbc,aes192-cbc,aes128-cbc,3des-cbc',
                    'comp' => 'none'
                ),
                'server_to_client' => array(
                    'crypt' => 'aes128-ctr,aes192-ctr,aes256-ctr,aes256-cbc,aes192-cbc,aes128-cbc,3des-cbc',
                    'comp' => 'none'
                )
            ));
        if ($connection) {
            // Track success statistics
            static $success_stats = array();
            if (!isset($success_stats['total'])) $success_stats = ['total' => 0, 'first_try' => 0];
            $success_stats['total']++;
            if ($attempt == 1) $success_stats['first_try']++;
            $success_rate = round(($success_stats['first_try'] / $success_stats['total']) * 100, 1);
            break;
        }
            // Fallback to basic connection
            $connection = ssh2_connect($bots_ssh_host, 22);        if ($connection) {
            // Track success statistics for basic connection too
            static $basic_success_stats = array();
            if (!isset($basic_success_stats['total'])) $basic_success_stats = ['total' => 0, 'first_try' => 0];
            $basic_success_stats['total']++;
            if ($attempt == 1) $basic_success_stats['first_try']++;
            $basic_success_rate = round(($basic_success_stats['first_try'] / $basic_success_stats['total']) * 100, 1);
            break;
        }
            if ($attempt < $max_retries) {
                $retry_delay = $base_delay * $attempt; // Exponential backoff: 0.5s, 1s, 1.5s, 2s
                usleep($retry_delay * 1000000); // Convert to microseconds
            }
        }
        if (!$connection) {
            // Try fallback method using system SSH
            $ssh_result = executeSSHCommand($bots_ssh_host, $bots_ssh_username, $bots_ssh_password, 'echo "SSH_CONNECTION_TEST"');
            if (!$ssh_result['success']) {
                error_log("SSH connection failed to {$bots_ssh_host}:22 for user {$username}");
                throw new Exception("SSH connection failed to bot server - both SSH2 extension and system SSH failed"); 
            } else { throw new Exception("SSH2 extension connection failed, but system SSH works - contact administrator"); }
        }
        // Authenticate
        if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
            if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
            error_log("SSH authentication failed for user {$bots_ssh_username} to {$bots_ssh_host}");
            throw new Exception("SSH authentication failed");
        }
        // SSH connection successful - now check bot status
        $result['success'] = true; // SSH is working
        // Get PID of the running bot
        $command = "python $statusScriptPath -system $botType -channel $username";
        $stream = ssh2_exec($connection, $command);
        if (!$stream) {}
        else {
            stream_set_blocking($stream, true);
            $statusOutput = trim(stream_get_contents($stream));
            fclose($stream);
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
        $stream = ssh2_exec($connection, $lastModified);
        if (!$stream) { 
            $result['lastModified'] = null;
        } else {
            stream_set_blocking($stream, true);
            $output = trim(stream_get_contents($stream));
            fclose($stream);
            // Parse the output to get last modified time
            if ($output && is_numeric($output)) {
                $result['lastModified'] = $output;
            } else {
                $result['lastModified'] = null;
            }
        }
        // Close SSH connection
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        // If we get here, SSH worked fine
        $result['message'] = 'Bot status retrieved successfully';
    } catch (Exception $e) { 
        // These are real errors that should set success to false
        $result['success'] = false;
        $result['message'] = $e->getMessage(); 
    }
    // Clean up connection lock
    unset($connection_locks[$lock_key]);
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
    // Extract parameters
    $username = $params['username'] ?? '';
    $twitchUserId = $params['twitch_user_id'] ?? '';
    $authToken = $params['auth_token'] ?? '';
    $refreshToken = $params['refresh_token'] ?? '';
    $apiKey = $params['api_key'] ?? '';
    // Define paths
    $statusScriptPath = "/home/botofthespecter/status.py";
    $botScriptPath = "/home/botofthespecter/" . (
        $botType === 'stable' ? "bot.py" :
        ($botType === 'beta' ? "beta.py" :
        ($botType === 'discord' ? "discordbot.py" : "bot.py"))
    );
    $versionFilePath = "/var/www/logs/version/{$username}_" . (
        $botType === 'stable' ? "" :
        ($botType === 'beta' ? "beta_" :
        ($botType === 'discord' ? "discord_" : ""))
    ) . "version_control.txt";
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
        // Establish SSH connection
        $connection = ssh2_connect($bots_ssh_host, 22);
        if (!$connection) {
            throw new Exception('SSH connection failed');
        }
        // Authenticate
        if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
            if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
            throw new Exception('SSH authentication failed');
        }
        // Check current bot status
        $command = "python $statusScriptPath -system $botType -channel $username";
        $stream = ssh2_exec($connection, $command);
        if (!$stream) { throw new Exception('Failed to check bot status'); }
        stream_set_blocking($stream, true);
        $statusOutput = trim(stream_get_contents($stream));
        fclose($stream);
        // Get current PID
        $currentPid = 0;
        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $currentPid = intval($matches[1]);
        }
        switch ($action) {
            case 'run':
                if ($currentPid > 0) {
                    // Only one message: Bot is running with version
                    $result['message'] = "Bot is running (v{$newVersion})";
                    $result['pid'] = $currentPid;
                    $result['success'] = true;
                    file_put_contents($versionFilePath, $newVersion);
                } else {
                    // Start the bot
                    if ($botType === 'discord') {
                        $startCommand = "python $botScriptPath -channel $username 2>&1 &";
                    } else {
                        $startCommand = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $apiKey 2>&1 &";
                    }
                    $stream = ssh2_exec($connection, $startCommand);
                    if (!$stream) { throw new Exception('Failed to run bot'); }
                    fclose($stream);
                    sleep(2);
                    $stream = ssh2_exec($connection, $command);
                    stream_set_blocking($stream, true);
                    $statusOutput = trim(stream_get_contents($stream));
                    fclose($stream);
                    if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches) || 
                        preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
                        $newPid = intval($matches[1]);
                        if ($newPid > 0) {
                            // Only one message: Bot is running with version
                            $result['message'] = "Bot is running (v{$newVersion})";
                            $result['pid'] = $newPid;
                            $result['success'] = true;
                            file_put_contents($versionFilePath, $newVersion);
                        } else { $result['message'] = "Failed to start bot"; }
                    } else { $result['message'] = "Failed to start bot"; }
                }
                break;
            case 'stop':
                if ($currentPid > 0) {
                    // Kill the bot
                    $killCommand = "kill -s kill $currentPid";
                    $stream = ssh2_exec($connection, $killCommand);
                    if (!$stream) { throw new Exception('Failed to stop bot'); }
                    fclose($stream);
                    // Wait a moment for the bot to stop
                    sleep(1);
                    // Check if bot stopped
                    $stream = ssh2_exec($connection, $command);
                    stream_set_blocking($stream, true);
                    $statusOutput = trim(stream_get_contents($stream));
                    fclose($stream);
                    if (!preg_match('/process ID:\s*(\d+)/i', $statusOutput) && 
                        !preg_match('/PID\s+(\d+)/i', $statusOutput)) {
                        $result['message'] = "Bot stopped successfully";
                        $result['success'] = true;
                    } else { $result['message'] = "Failed to stop bot"; }
                } else {
                    $result['message'] = "Bot is not running";
                    $result['success'] = true;
                }
                break;
        }
        // Close SSH connection
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
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
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) {
        error_log('SSH connection failed for ensure_remote_path_exists');
        return false;
    }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        error_log('SSH authentication failed for ensure_remote_path_exists');
        return false;
    }
    if ($isFile) {
        $dir = dirname($path);
        $cmd = "mkdir -p " . escapeshellarg($dir) . " && touch " . escapeshellarg($path);
    } else {
        $cmd = "mkdir -p " . escapeshellarg($path);
    }
    $stream = ssh2_exec($connection, $cmd);
    if (!$stream) {
        error_log("SSH command failed: $cmd");
        return false;
    }
    stream_set_blocking($stream, true);
    stream_get_contents($stream);
    fclose($stream);
    if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
    return true;
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
