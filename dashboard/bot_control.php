<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// SSH Connection parameters
include_once "/var/www/config/ssh.php";

// Define variables for standard bot
$versionFilePath = '/home/botofthespecter/logs/version/' . $username . '_version_control.txt';
$botScriptPath = "/home/botofthespecter/bot.py";
$statusScriptPath = "/home/botofthespecter/status.py";

// Define variables for beta bot
$betaVersionFilePath = '/home/botofthespecter/logs/version/' . $username . '_beta_version_control.txt';
$BetaBotScriptPath = "/home/botofthespecter/beta.py";

// Fetch all versions from the API ONCE at the top
$versionApiUrl = 'https://api.botofthespecter.com/versions';
$versionApiData = @file_get_contents($versionApiUrl);
if ($versionApiData !== false) {
    $versionInfo = json_decode($versionApiData, true);
    $newVersion = $versionInfo['stable_version'] ?? 'N/A';
    $betaNewVersion = $versionInfo['beta_version'] ?? 'N/A';
} else {
    $newVersion = $betaNewVersion = 'N/A';
}

// Get bot status and check if it's running
try {
    $statusOutput = getBotsStatus($statusScriptPath, $username, 'stable');
    $botSystemStatus = checkBotsRunning($statusScriptPath, $username, 'stable');
    $betaStatusOutput = getBotsStatus($statusScriptPath, $username, 'beta');
    $betaBotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'beta');
} catch (Exception $e) {
    $statusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $botSystemStatus = false;
    $betaStatusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $betaBotSystemStatus = false;
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

function handleTwitchBotAction($action, $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    // Also get access to the version file paths and new versions for updating
    global $versionFilePath, $newVersion, $betaVersionFilePath, $betaNewVersion;
    try {
        // Use connection manager for persistent SSH connection
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        // Get PID of the running bot
        $system = 'stable';
        if (strpos($botScriptPath, 'beta.py') !== false) { $system = 'beta'; } 
        $command = "python $statusScriptPath -system $system -channel $username";
        $statusOutput = SSHConnectionManager::executeCommand($connection, $command);
        if ($statusOutput === false) { throw new Exception('Failed to get bot status'); }
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
                if ($pid > 0) {
                    // Ensure version file is up to date even if the bot is already running
                    updateVersionFile($currentVersionFilePath, $currentNewVersion);
                    $message = "<div class='status-message'>Bot is already running. PID $pid.</div>";
                } else {
                    startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
                    sleep(2);
                    // Check status again using connection manager
                    $statusOutput = SSHConnectionManager::executeCommand($connection, "python $statusScriptPath -system $system -channel $username");
                    if ($statusOutput !== false) {
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
                        $message = "<div class='status-message'>Bot started successfully. PID $pid.</div>";
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
        if ($statusOutput === false) { return "<div class='status-message error'>Status: SSH command execution failed</div>"; }
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
        if ($statusOutput === false) { throw new Exception('SSH command execution failed'); }
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
        if ($statusOutput === false) { throw new Exception('SSH command execution failed'); }
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
        if ($statusOutput === false) { return false; }
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
        if ($output === false) { throw new Exception('SSH command execution failed'); }
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
        $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $api_key &";
        $output = SSHConnectionManager::executeCommand($connection, $command);
        if ($output === false) { throw new Exception('SSH command execution failed'); }
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

function getRunningVersion($versionFilePath, $newVersion, $type = '') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $versionCmd = "cat " . escapeshellarg($versionFilePath);
        $output = SSHConnectionManager::executeCommand($connection, $versionCmd);
        if ($output !== false) {
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
        if ($output !== false && trim($output) === 'test') {
            return ['success' => true, 'message' => 'SSH connection successful'];
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