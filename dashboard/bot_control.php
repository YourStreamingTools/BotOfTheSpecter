<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// SSH Connection parameters
include_once "/var/www/config/ssh.php";

// Define the versions for the bots
$remoteVersionFile = "/home/botofthespecter/logs/version";
$versionFilePath = "{$remoteVersionFile}/{$username}_version_control.txt";
$betaVersionFilePath = "{$remoteVersionFile}/beta/{$username}_beta_version_control.txt";
$v6VersionFilePath = "{$remoteVersionFile}/v6/{$username}_v6_version_control.txt";

// Define the status script path
$statusScriptPath = "/home/botofthespecter/status.py";

// Define script path for stable bot
$botScriptPath = "/home/botofthespecter/bot.py";

// Define script path for beta bot
$BetaBotScriptPath = "/home/botofthespecter/beta.py";

// Define script path for v6 bot
$V6BotScriptPath = "/home/botofthespecter/beta-v6.py";

// Fetch all versions from the API ONCE at the top
$versionApiUrl = 'https://api.botofthespecter.com/versions';
$versionApiData = @file_get_contents($versionApiUrl);
if ($versionApiData !== false) {
    $versionInfo = json_decode($versionApiData, true);
    $newVersion = $versionInfo['stable_version'] ?? 'N/A';
    $betaNewVersion = $versionInfo['beta_version'] ?? 'N/A';
    $v6NewVersion = $versionInfo['v6_version'] ?? '6.0';
} else {
    $newVersion = $betaNewVersion = $v6NewVersion = 'N/A';
}

// Get bot status and check if it's running
try {
    $statusOutput = getBotsStatus($statusScriptPath, $username, 'stable');
    $botSystemStatus = checkBotsRunning($statusScriptPath, $username, 'stable');
    $betaStatusOutput = getBotsStatus($statusScriptPath, $username, 'beta');
    $betaBotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'beta');
    $v6StatusOutput = getBotsStatus($statusScriptPath, $username, 'v6');
    $v6BotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'v6');
} catch (Exception $e) {
    $statusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $botSystemStatus = false;
    $betaStatusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $betaBotSystemStatus = false;
    $v6StatusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $v6BotSystemStatus = false;
}

// Define a timeout for bot shutdown
$shutdownTimeoutSeconds = 5;

// Handle standard bot actions
if (isset($_POST['runBot'])) {
    $waited = 0;
    while (checkBotsRunning($statusScriptPath, $username, 'stable') && $waited < $shutdownTimeoutSeconds) {
        sleep(1);
        $waited++;
    }
    if ($waited >= $shutdownTimeoutSeconds) { /* timeout warning */  }
    $statusOutput = handleTwitchBotAction('run', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    // The version running will be updated in the handleTwitchBotAction function
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if (isset($_POST['killBot'])) {
    $statusOutput = handleTwitchBotAction('kill', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    $versionRunning = "";
}

// Handle beta bot actions
if (isset($_POST['runBetaBot'])) {
    $waited = 0;
    while (checkBotsRunning($statusScriptPath, $username, 'beta') && $waited < $shutdownTimeoutSeconds) {
        sleep(1);
        $waited++;
    }
    if ($waited >= $shutdownTimeoutSeconds) { /* timeout warning */ }
    $betaStatusOutput = handleTwitchBotAction('run', $BetaBotScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    // The version running will be updated in the handleTwitchBotAction function
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if (isset($_POST['killBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('kill', $BetaBotScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    $betaVersionRunning = "";
}

// Handle v6 bot actions
if (isset($_POST['runV6Bot'])) {
    $waited = 0;
    while (checkBotsRunning($statusScriptPath, $username, 'v6') && $waited < $shutdownTimeoutSeconds) {
        sleep(1);
        $waited++;
    }
    if ($waited >= $shutdownTimeoutSeconds) { /* timeout warning */ }
    $v6StatusOutput = handleTwitchBotAction('run', $V6BotScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    $v6VersionRunning = getRunningVersion($v6VersionFilePath, $v6NewVersion, 'v6');
}

if (isset($_POST['killV6Bot'])) {
    $v6StatusOutput = handleTwitchBotAction('kill', $V6BotScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    $v6VersionRunning = "";
}

function handleTwitchBotAction($action, $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    // Also get access to the version file paths and new versions for updating
    global $versionFilePath, $newVersion, $betaVersionFilePath, $betaNewVersion, $v6VersionFilePath, $v6NewVersion;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Get PID of the running bot
        $system = 'stable';
        if (strpos($botScriptPath, 'beta.py') !== false) { $system = 'beta'; } 
        $command = "python $statusScriptPath -system $system -channel $username";
    $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
    if ($statusOutput === false || $statusOutput === null) { throw new Exception('Failed to get bot status'); }
    if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
    else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
    $statusOutput = trim($statusOutput);
        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else { $pid = 0; }
        $message = '';
        // Determine which version file to update based on the bot script path
        $currentVersionFilePath = "";
        $currentNewVersion = "";
        if (strpos($botScriptPath, 'beta.py') !== false) {
            $currentVersionFilePath = $betaVersionFilePath;
            $currentNewVersion = $betaNewVersion;
        } else {
            $currentVersionFilePath = $versionFilePath;
            $currentNewVersion = $newVersion;
        }
        switch ($action) {
            case 'run':
                // Before starting this bot, ensure the other bot is stopped
                $otherSystem = ($system === 'stable') ? 'beta' : 'stable';
                $otherBotScriptPath = "/home/botofthespecter/" . ($otherSystem === 'beta' ? "beta.py" : "bot.py");
                $otherCommand = "python $statusScriptPath -system $otherSystem -channel $username";
                $otherStatusOutput = SSHConnectionManager::executeCommand($connection, $otherCommand);
                $otherBotStoppedMessage = '';
                if ($otherStatusOutput !== false && $otherStatusOutput !== null) {
                    if (function_exists('sanitizeSSHOutput')) { $otherStatusOutput = sanitizeSSHOutput($otherStatusOutput); }
                    else { $otherStatusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$otherStatusOutput); }
                    $otherStatusOutput = trim($otherStatusOutput);
                    $otherPid = 0;
                    if (preg_match('/process ID:\s*(\d+)/i', $otherStatusOutput, $matches)) {
                        $otherPid = intval($matches[1]);
                    } elseif (preg_match('/PID\s+(\d+)/i', $otherStatusOutput, $matches)) {
                        $otherPid = intval($matches[1]);
                    }
                    if ($otherPid > 0) {
                        // Stop the other bot
                        $killCommand = "kill -s kill $otherPid";
                        SSHConnectionManager::executeCommand($connection, $killCommand);
                        // Wait a moment for the process to terminate
                        sleep(1);
                        $otherBotStoppedMessage = "Found $otherSystem bot running, stopping it. ";
                    }
                }
                if ($pid > 0) {
                    // Ensure version file is up to date even if the bot is already running
                    updateVersionFile($currentVersionFilePath, $currentNewVersion);
                    $message = "<div class='status-message'>Bot is already running. PID $pid.</div>";
                } else {
                    startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
                    sleep(2);
                    // Check status again using connection manager
                    $statusOutput = SSHConnectionManager::executeCommand($connection, "python $statusScriptPath -system $system -channel $username");
                    if ($statusOutput !== false && $statusOutput !== null) {
                        if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
                        else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
                        $statusOutput = trim($statusOutput);
                        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
                            $pid = intval($matches[1]);
                        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
                            $pid = intval($matches[1]);
                        } else { $pid = 0; }
                    }
                    if ($pid > 0) {
                        // Update version file with latest version on successful start
                        updateVersionFile($currentVersionFilePath, $currentNewVersion);
                        $message = "<div class='status-message'>$otherBotStoppedMessage" . "Bot started successfully. PID $pid.</div>";
                    } else { $message = "<div class='status-message error'>Failed to start the bot. Please check the configuration or server status.</div>"; }
                }
                break;
            case 'kill':
                if ($pid > 0) {
                    killBot($pid);
                    $message = "<div class='status-message'>Bot stopped successfully.</div>";
                } else { $message = "<div class='status-message error'>Bot is not running.</div>"; }
                break;
        }
    } catch (Exception $e) {
        error_log('Error handling bot action: ' . $e->getMessage());
        $message = "<div class='status-message error'>An error occurred: " . $e->getMessage() . "</div>";
    }
    return $message;
}

// Add new function to update version file with latest version
function updateVersionFile($versionFilePath, $newVersion) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $versionDir = dirname($versionFilePath);
        $createDirCmd = "mkdir -p " . escapeshellarg($versionDir);
        SSHConnectionManager::executeCommand($connection, $createDirCmd);
        $writeVersionCmd = "echo " . escapeshellarg($newVersion) . " > " . escapeshellarg($versionFilePath);
        SSHConnectionManager::executeCommand($connection, $writeVersionCmd);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getBotsStatus($statusScriptPath, $username, $system = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    // Check if SSH2 extension is available
    if (!extension_loaded('ssh2')) { return "<div class='status-message error'>Status: SSH2 extension not available</div>"; }
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Run the command to get the bot's status
        $command = "python $statusScriptPath -system $system -channel $username";
    $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
    if ($statusOutput === false || $statusOutput === null) { return "<div class='status-message error'>Status: SSH command execution failed</div>"; }
    if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
    else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
    $statusOutput = trim($statusOutput);
        if (preg_match('/Bot is running with process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else { $pid = 0; }
        if ($pid > 0) { return "<div class='status-message'>Status: PID $pid.</div>"; }
        else { return "<div class='status-message error'>Status: NOT RUNNING</div>"; }
    } catch (Exception $e) { return "<div class='status-message error'>Status: Error - " . $e->getMessage() . "</div>"; }
}

function isBotRunning($statusScriptPath, $username) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $command = "python $statusScriptPath -system stable -channel $username";
    $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
    if ($statusOutput === false || $statusOutput === null) { throw new Exception('SSH command execution failed'); }
    if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
    else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
    $statusOutput = trim($statusOutput);
        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else { $pid = 0; }
        return ($pid > 0);
    } catch (Exception $e) { throw $e; }
}

function getBotPID($statusScriptPath, $username) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $command = "python $statusScriptPath -system stable -channel $username";
    $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
    if ($statusOutput === false || $statusOutput === null) { throw new Exception('SSH command execution failed'); }
    if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
    else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
    $statusOutput = trim($statusOutput);
        if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else { $pid = 0; }
        return $pid;
    } catch (Exception $e) { throw $e; }
}

function checkBotsRunning($statusScriptPath, $username, $system = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    // Check if SSH2 extension is available
    if (!extension_loaded('ssh2')) { return false; }
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $command = "python $statusScriptPath -system $system -channel $username";
    $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
    if ($statusOutput === false || $statusOutput === null) { return false; }
    if (function_exists('sanitizeSSHOutput')) { $statusOutput = sanitizeSSHOutput($statusOutput); }
    else { $statusOutput = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$statusOutput); }
    $statusOutput = trim($statusOutput);
        if (preg_match('/Bot is running with process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else { $pid = 0; }
        return ($pid > 0);
    } catch (Exception $e) { return false; }
}

function killBot($pid) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $command = "kill -s kill $pid";
    $output = SSHConnectionManager::executeCommand($connection, $command);
    if ($output === false || $output === null) { throw new Exception('SSH command execution failed'); }
    if (function_exists('sanitizeSSHOutput')) { $output = sanitizeSSHOutput($output); }
    else { $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output); }
        sleep(1);
    } catch (Exception $e) { throw $e; }
    if (empty($output) || strpos($output, 'No such process') === false) { return true;
    } else { throw new Exception('Failed to kill the bot process. Output: ' . $output); }
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // V6 uses venv (beta-v6.py), beta and others use regular python
        $pythonCmd = (strpos($botScriptPath, 'beta-v6') !== false) ? '/home/botofthespecter/beta_env/bin/python' : 'python';
        $command = "$pythonCmd $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $api_key &";
    $output = SSHConnectionManager::executeCommand($connection, $command);
    if ($output === false || $output === null) { throw new Exception('SSH command execution failed'); }
    if (function_exists('sanitizeSSHOutput')) { $output = sanitizeSSHOutput($output); }
    else { $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output); }
        return true;
    } catch (Exception $e) {
        throw $e;
    }
}

if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if ($v6BotSystemStatus) {
    $v6VersionRunning = getRunningVersion($v6VersionFilePath, $v6NewVersion, 'v6');
}

function getRunningVersion($versionFilePath, $newVersion, $type = '') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $versionCmd = "cat " . escapeshellarg($versionFilePath);
        $output = SSHConnectionManager::executeCommand($connection, $versionCmd);
        if ($output !== false && $output !== null) {
            if (function_exists('sanitizeSSHOutput')) { $output = sanitizeSSHOutput($output); }
            else { $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output); }
            return trim($output);
        } else {
            return $newVersion;
        }
    } catch (Exception $e) {
        return $newVersion;
    }
}

// Function to test SSH connectivity
function testSSHConnection() {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!extension_loaded('ssh2')) { return ['success' => false, 'message' => 'SSH2 extension not available']; }
    try {
        // Use connection manager to test connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Test a simple command
        $output = SSHConnectionManager::executeCommand($connection, 'echo "test"');
        if ($output !== false && $output !== null) {
            if (function_exists('sanitizeSSHOutput')) { $output = sanitizeSSHOutput($output); }
            else { $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output); }
            if (trim($output) === 'test') {
                return ['success' => true, 'message' => 'SSH connection successful'];
            } else {
                return ['success' => false, 'message' => 'SSH command test failed'];
            }
        } else {
            return ['success' => false, 'message' => 'SSH command test failed'];
        }
    } catch (Exception $e) { return ['success' => false, 'message' => 'SSH error: ' . $e->getMessage()]; }
}

// Handle SSH test request
if (isset($_GET['test_ssh']) && $_GET['test_ssh'] == '1') {
    $testResult = testSSHConnection();
    echo json_encode($testResult);
    exit;
}
?>