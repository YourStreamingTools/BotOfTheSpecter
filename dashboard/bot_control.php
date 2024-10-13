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

// Define variables for Discord bot
$discordBotScriptPath = "/var/www/bot/discordbot.py";
$discordStatusScriptPath = "/var/www/bot/discordstatus.py";
$discordLogPath = "/var/www/logs/script/{$username}_discord.txt";

$statusOutput = getBotsStatus($statusScriptPath, $username, $logPath);
$botSystemStatus = checkBotsRunning($statusScriptPath, $username, $logPath);
$betaStatusOutput = getBotsStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$betaBotSystemStatus = checkBotsRunning($BetaStatusScriptPath, $username, $BetaLogPath);
$discordStatusOutput = getBotsStatus($discordStatusScriptPath, $username, $discordLogPath);
$discordBotSystemStatus = checkBotsRunning($discordStatusScriptPath, $username, $discordLogPath);

$directory = dirname($logPath);
$betaDirectory = dirname($BetaLogPath);
$discordDirectory = dirname($discordLogPath);

// Check if directories exist, if not, create them
if (!file_exists($directory)) {
    if (!mkdir($directory, 0777, true)) {
        echo "<script>console.error('Failed to create directory: $directory');</script>";
        exit;
    }
}
if (!file_exists($betaDirectory)) {
    if (!mkdir($betaDirectory, 0777, true)) {
        echo "<script>console.error('Failed to create directory: $betaDirectory');</script>";
        exit;
    }
}
if (!file_exists($discordDirectory)) {
    if (!mkdir($discordDirectory, 0777, true)) {
        echo "<script>console.error('Failed to create directory: $discordDirectory');</script>";
        exit;
    }
}

// Open and close the log files to ensure they exist
if (($file = fopen($logPath, 'w')) === false) {
    echo "<script>console.error('Failed to create/open the file: $logPath');</script>";
    exit;
}
fclose($file);

if (($file = fopen($BetaLogPath, 'w')) === false) {
    echo "<script>console.error('Failed to create/open the file: $BetaLogPath');</script>";
    exit;
}
fclose($file);

if (($file = fopen($discordLogPath, 'w')) === false) {
    echo "<script>console.error('Failed to create/open the file: $discordLogPath');</script>";
    exit;
}
fclose($file);

// Initialize status message variables
$statusOutput = getBotsStatus($statusScriptPath, $username, $logPath);
$botSystemStatus = checkBotsRunning($statusScriptPath, $username, $logPath);
$betaStatusOutput = getBotsStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$betaBotSystemStatus = checkBotsRunning($BetaStatusScriptPath, $username, $BetaLogPath);
$discordStatusOutput = getBotsStatus($discordStatusScriptPath, $username, $discordLogPath);
$discordBotSystemStatus = checkBotsRunning($discordStatusScriptPath, $username, $discordLogPath);

// Handle standard bot actions
if (isset($_POST['runBot'])) {
    $statusOutput = handleTwitchBotAction('run', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
    sleep(5);
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if (isset($_POST['killBot'])) {
    $statusOutput = handleTwitchBotAction('kill', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
}

if (isset($_POST['restartBot'])) {
    $statusOutput = handleTwitchBotAction('restart', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
    sleep(5);
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

// Handle beta bot actions
if (isset($_POST['runBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('run', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $BetaLogPath);
    sleep(5);
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if (isset($_POST['killBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('kill', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $BetaLogPath);
}

if (isset($_POST['restartBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('restart', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $BetaLogPath);
    sleep(5);
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

// Handle Discord bot actions
if (isset($_POST['runDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('run', $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath);
}

if (isset($_POST['killDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('kill', $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath);
}

if (isset($_POST['restartDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('restart', $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath);
}

// Function to handle bot actions
function handleTwitchBotAction($action, $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    $message = '';
    switch ($action) {
        case 'run':
            if ($pid > 0) {
                $message = "<div class='status-message'>Bot is already running. PID $pid.</div>";
            } else {
                startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
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
                startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
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

// Function to handle Discord Bot Actions
function handleDiscordBotAction($action, $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath) {
    $statusOutput = shell_exec("python $discordStatusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    $message = '';
    switch ($action) {
        case 'run':
            if ($pid > 0) {
                $message = "<div class='status-message'>Discord bot is already running. PID $pid.</div>";
            } else {
                startDiscordBot($discordBotScriptPath, $username, $discordLogPath);
                $statusOutput = shell_exec("python $discordStatusScriptPath -channel $username");
                $pid = intval(preg_replace('/\D/', '', $statusOutput));
                if ($pid > 0) {
                    $message = "<div class='status-message'>Discord bot started successfully. PID $pid.</div>";
                } else {
                    $message = "<div class='status-message error'>Failed to start the Discord bot. Please check the configuration or server status.</div>";
                }
            }
            break;
        case 'kill':
            if ($pid > 0) {
                killBot($pid);
                $message = "<div class='status-message'>Discord bot stopped successfully.</div>";
            } else {
                $message = "<div class='status-message error'>Discord bot is not running.</div>";
            }
            break;
        case 'restart':
            if ($pid > 0) {
                killBot($pid);
                startDiscordBot($discordBotScriptPath, $username, $discordLogPath);
                $statusOutput = shell_exec("python $discordStatusScriptPath -channel $username");
                $pid = intval(preg_replace('/\D/', '', $statusOutput));
                if ($pid > 0) {
                    $message = "<div class='status-message'>Discord bot restarted. PID $pid.</div>";
                } else {
                    $message = "<div class='status-message error'>Failed to restart the Discord bot.</div>";
                }
            } else {
                $message = "<div class='status-message error'>Discord bot is not running.</div>";
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

function getBotsStatus($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    if ($pid > 0) {
        return "<div class='status-message'>Status: PID $pid.</div>";
    } else {
        return "<div class='status-message error'>Status: NOT RUNNING</div>";
    }
}

function checkBotsRunning($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    return ($pid > 0);
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath) {
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $api_key >> $logPath 2>&1 &";
    $output = shell_exec($command);
    sleep(1);
    return !(empty($output) || strpos($output, 'error') !== false);
}

function startDiscordBot($discordBotScriptPath, $username, $discordLogPath) {
    $command = "python $discordBotScriptPath -channel $username >> $discordLogPath 2>&1 &";
    $output = shell_exec($command);
    sleep(1);
    return !(empty($output) || strpos($output, 'error') !== false);
}

function killBot($pid) {
    $output = shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
    return (empty($output) || strpos($output, 'error') === false);
}

// Display running versions if bots are running
$versionRunning = '';
$betaVersionRunning = '';
$discordRunning = '';

if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if ($discordBotSystemStatus) {
    $discordRunning = "<div class='status-message'>Discord bot is running.</div>";
} else {
    $discordRunning = "<div class='status-message error'>Discord bot is NOT RUNNING.</div>";
}

function getRunningVersion($versionFilePath, $newVersion, $type = '') {
    if (file_exists($versionFilePath)) {
        $versionContent = file_get_contents($versionFilePath);
        if ($versionContent === false) {
            return "<div class='status-message error'>Failed to read version information.</div>";
        }
        $versionContent = trim($versionContent);
        $output = "<div class='status-message'>" . ucfirst($type) . " Running Version: $versionContent</div>";
        if ($versionContent !== $newVersion) {
            $output .= "<div class='status-message'>Update (V$newVersion) is available.</div>";
        }
        return $output;
    } else {
        return "<div class='status-message error'>Version information not available.</div>";
    }
}
?>