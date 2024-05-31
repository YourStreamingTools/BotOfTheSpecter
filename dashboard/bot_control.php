<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define variables for standard bot
$versionFilePath = '/var/www/logs/version/' . $username . '_version_control.txt';
$newVersion = file_get_contents("/var/www/api/bot_version_control.txt") ?: 'N/A';
$botScriptPath = "/var/www/bot/bot.py";
$statusScriptPath = "/var/www/bot/status.py";
$logPath = "/var/www/logs/script/$username.txt";

// Define variables for beta bot
$betaVersionFilePath = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
$betaNewVersion = file_get_contents("/var/www/api/beta_version_control.txt") ?: 'N/A';
$BetaBotScriptPath = "/var/www/bot/beta.py";
$BetaStatusScriptPath = "/var/www/bot/beta_status.py";
$BetaLogPath = "/var/www/logs/script/{$username}_beta.txt";

$statusOutput = getBotStatus($statusScriptPath, $username, $logPath);
$botSystemStatus = checkBotRunning($statusScriptPath, $username, $logPath);
$betaStatusOutput = getBotStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$betaBotSystemStatus = checkBotRunning($BetaStatusScriptPath, $username, $BetaLogPath);

$directory = dirname($logPath);
$betaDirectory = dirname($BetaLogPath);

// Check if directories exist, if not, create them
if (!file_exists($directory)) {
    if (!mkdir($directory, 0777, true)) {
        echo "Failed to create directory: $directory";
        exit;
    }
}
if (!file_exists($betaDirectory)) {
    if (!mkdir($betaDirectory, 0777, true)) {
        echo "Failed to create directory: $betaDirectory";
        exit;
    }
}

// Open and close the log files to ensure they exist
if (($file = fopen($logPath, 'w')) === false) {
    echo "Failed to create/open the file: $logPath";
    exit;
}
fclose($file);

if (($file = fopen($BetaLogPath, 'w')) === false) {
    echo "Failed to create/open the file: $BetaLogPath";
    exit;
}
fclose($file);

// Initialize status message variables
$statusOutput = getBotStatus($statusScriptPath, $username, $logPath);
$botSystemStatus = checkBotRunning($statusScriptPath, $username, $logPath);
$betaStatusOutput = getBotStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$betaBotSystemStatus = checkBotRunning($BetaStatusScriptPath, $username, $BetaLogPath);

// Handle standard bot actions
if (isset($_POST['runBot'])) {
    $statusOutput = handleBotAction('run', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
}

if (isset($_POST['killBot'])) {
    $statusOutput = handleBotAction('kill', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
}

if (isset($_POST['restartBot'])) {
    $statusOutput = handleBotAction('restart', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
}

// Handle beta bot actions
if (isset($_POST['runBetaBot'])) {
    $betaStatusOutput = handleBotAction('run', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath);
}

if (isset($_POST['killBetaBot'])) {
    $betaStatusOutput = handleBotAction('kill', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath);
}

if (isset($_POST['restartBetaBot'])) {
    $betaStatusOutput = handleBotAction('restart', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath);
}

// Function to handle bot actions
function handleBotAction($action, $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    $message = '';

    switch ($action) {
        case 'run':
            if ($pid > 0) {
                $message = "<div class='status-message'>Bot is already running. PID $pid.</div>";
            } else {
                startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
                $statusOutput = shell_exec("python $statusScriptPath -channel $username");
                $pid = intval(preg_replace('/\D/', '', $statusOutput));
                if ($pid > 0) {
                    $message = "<div class='status-message'>Bot started successfully. PID $pid.</div>";
                } else {
                    $message = "<div class='status-message error'>Failed to start the bot. Please check the configuration or server status.</div>";
                }
            }
            break;
        case 'kill':
            if ($pid > 0) {
                killBot($pid);
                $message = "<div class='status-message'>Bot stopped successfully.</div>";
            } else {
                $message = "<div class='status-message error'>Bot is not running.</div>";
            }
            break;
        case 'restart':
            if ($pid > 0) {
                killBot($pid);
                startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
                $statusOutput = shell_exec("python $statusScriptPath -channel $username");
                $pid = intval(preg_replace('/\D/', '', $statusOutput));
                if ($pid > 0) {
                    $message = "<div class='status-message'>Bot restarted. PID $pid.</div>";
                } else {
                    $message = "<div class='status-message error'>Failed to restart the bot.</div>";
                }
            } else {
                $message = "<div class='status-message error'>Bot is not running.</div>";
            }
            break;
    }

    return $message;
}

function isBotRunning($statusScriptPath, $username, $logPath) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    return ($pid > 0);
}

function getBotPID($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    return intval(preg_replace('/\D/', '', $statusOutput));
}

function getBotStatus($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    if ($pid > 0) {
        return "<div class='status-message'>Status: PID $pid.</div>";
    } else {
        return "<div class='status-message error'>Status: NOT RUNNING</div>";
    }
}

function checkBotRunning($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    return ($pid > 0);
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath) {
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken >> $logPath 2>&1 &";
    $output = shell_exec($command);
    sleep(1);
    return !(empty($output) && strpos($output, 'error') === false);
}

function killBot($pid) {
    $output = shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
    return (isset($output) && strpos($output, 'error') === false);
}

// Display running versions if bots are running
$versionRunning = '';
$betaVersionRunning = '';

if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

function getRunningVersion($versionFilePath, $newVersion, $type = '') {
    if (file_exists($versionFilePath)) {
        $versionContent = file_get_contents($versionFilePath);
        $output = "<div class='status-message'>" . ucfirst($type) . " Running Version: $versionContent</div>";
        if ($versionContent !== $newVersion) {
            $output .= "<div class='status-message'>Update (V$newVersion) is available.</div>";
        }
        return $output;
    } else {
        return "<div class='status-message'>Version information not available.</div>";
    }
}
?>