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

// Get bot status and check if it's running - Used for initial page load only
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

// Utility function to get bot status message
function getBotsStatus($statusScriptPath, $username, $system = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!extension_loaded('ssh2')) { return "<div class='status-message error'>Status: SSH2 extension not available</div>"; }
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
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

// Check if a bot is running (returns boolean)
function checkBotsRunning($statusScriptPath, $username, $system = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!extension_loaded('ssh2')) { return false; }
    try {
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

// Get the version currently running from version control file
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

// Set running versions if bots are running
if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if ($v6BotSystemStatus) {
    $v6VersionRunning = getRunningVersion($v6VersionFilePath, $v6NewVersion, 'v6');
}

// Function to test SSH connectivity
function testSSHConnection() {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!extension_loaded('ssh2')) {
        return ['success' => false, 'message' => 'SSH2 extension not available'];
    }
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        return ['success' => true, 'message' => 'SSH connection successful'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'SSH connection failed: ' . $e->getMessage()];
    }
}

// Handle SSH test request
if (isset($_GET['test_ssh']) && $_GET['test_ssh'] == '1') {
    $result = testSSHConnection();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}
?>
