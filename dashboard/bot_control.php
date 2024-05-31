<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define variables
$versionFilePath = '/var/www/logs/version/' . $username . '_version_control.txt';
$newVersion = file_get_contents("/var/www/api/bot_version_control.txt");
$botScriptPath = "/var/www/bot/bot.py";
$statusScriptPath = "/var/www/bot/status.py";
$logPath = "/var/www/logs/script/$username.txt";
$statusOutput = getBotStatus($statusScriptPath, $username, $logPath);
$botSystemStatus = checkBotRunning($statusScriptPath, $username, $logPath);
$directory = dirname($logPath);

// Check if the directory exists, if not, create it
if (!file_exists($directory)) {
    // Attempt to create the directory with full permissions
    if (!mkdir($directory, 0777, True)) {
        echo "Failed to create directory: $directory";
        exit;
    }
}

// Open the file in write mode ('w')
$file = fopen($logPath, 'w');

// Check if the file handle was successfully created
if ($file === False) {
    echo "Failed to create/open the file: $logPath";
    exit;
}

// Close the file handle
fclose($file);

if (isset($_POST['runBot'])) {
    if (isBotRunning($statusScriptPath, $username, $logPath)) {
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));
        $botSystemStatus = True; // Set to True if bot is running
        $statusOutput = "<div class='status-message'>Bot is already running. PID $pid.</div>";
    } else {
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));

        if ($pid > 0) {
            $botSystemStatus = True; // Set to True if bot started successfully
            $statusOutput = "<div class='status-message'>Bot started successfully. PID $pid.</div>";
        } else {
            $botSystemStatus = False; // Set to False if failed to start the bot
            $versionRunning = '';
            $statusOutput = "<div class='status-message error'>Failed to start the bot. Please check the configuration or server status.</div>";
        }
    }
}

if (isset($_POST['killBot'])) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    if ($pid > 0) {
        killBot($pid);
        $botSystemStatus = False; // Set to False when bot is stopped
        $versionRunning = '';
        $statusOutput = "<div class='status-message'>Bot stopped successfully.</div>";
    } else {
        $botSystemStatus = False; // Set to False if bot is not running
        $versionRunning = '';
        $statusOutput = "<div class='status-message error'>Bot is not running.</div>";
    }
}

if (isset($_POST['restartBot'])) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    if ($pid > 0) {
        killBot($pid);
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));

        if ($pid > 0) {
            $botSystemStatus = True; // Set to True if bot restarted successfully
            $statusOutput = "<div class='status-message'>Bot restarted. PID $pid.</h5>";
        } else {
            $botSystemStatus = False; // Set to False if failed to restart the bot
            $versionRunning = '';
            $statusOutput = "<div class='status-message error'>Failed to restart the bot.</div>";
        }
    } else {
        $botSystemStatus = False; // Set to False if bot is not running
        $versionRunning = '';
        $statusOutput = "<div class='status-message error'>Bot is not running.</div>";
    }
}

function isBotRunning($statusScriptPath, $username, $logPath) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    return ($pid > 0);
}

function getBotPID($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    if ($statusOutput !== null) {
        return intval(preg_replace('/\D/', '', $statusOutput));
    } else {
        return 0;
    }
}

function getBotStatus($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    if ($statusOutput !== null) {
        if ($pid > 0) {
            return "<div class='status-message'>Status: PID $pid.</div>";
        } else {
            return "<div class='status-message error'>Status: NOT RUNNING</div>";
        }
    } else {
        return "<div class='status-message error'>Unable to determine bot status.</div>";
    }
}

function checkBotRunning($statusScriptPath, $username, $logPath) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    if ($statusOutput !== null) {
        if ($pid > 0) {
            $botSystemStatus = True;
            return True;
        } else {
            $botSystemStatus = False;
            $versionRunning = '';
            return False;
        }
    } else {
        $botSystemStatus = False;
        $versionRunning = '';
        return False;
    }
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath) {
    // Append the log path to the command
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken >> $logPath 2>&1 &";
    
    // Execute the command
    $output = shell_exec($command);

    sleep(1);

    // Check for errors in the output
    if (!empty($output) && strpos($output, 'error') !== False) {
        return False;
    }
    
    // Bot started successfully
    return True;
}

function killBot($pid) {
    $output = shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
    if (isset($output) && strpos($output, 'error') !== False) {
        return False;
    }
    $botSystemStatus = False;
    $versionRunning = '';
    return True;
}

// Display running version if bot is running or if bot was started or restarted
if ($botSystemStatus == True) {
    // Check if the version control file exists
    if (file_exists($versionFilePath)) {
        // If the file exists, read its contents
        $versionContent = file_get_contents($versionFilePath); 
        $versionRunning = "<div class='status-message'>Running Version: $versionContent</div>";

        // Compare the running version with the new version
        if ($versionContent !== $newVersion) {
            // Display message for update if versions are different
            $versionRunning .= "<div class='status-message'>Update (V$newVersion) is available.</div>";
        }
    } else {
        // If the file doesn't exist, display a message indicating that the version is not available
        $versionRunning = "<div class='status-message'>Version information not available.</div>";
    }
}
?>