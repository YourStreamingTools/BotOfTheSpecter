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
$botSystemStatus = isBotRunning($statusScriptPath, $username, $logPath);
$directory = dirname($logPath);

// Check if the directory exists, if not, create it
if (!file_exists($directory)) {
    // Attempt to create the directory with full permissions
    if (!mkdir($directory, 0777, true)) {
        echo "Failed to create directory: $directory";
        exit;
    }
}

// Open the file in write mode ('w')
$file = fopen($logPath, 'w');

// Check if the file handle was successfully created
if ($file === false) {
    echo "Failed to create/open the file: $logPath";
    exit;
}

// Close the file handle
fclose($file);

function isBotRunning($statusScriptPath, $username, $logPath) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    return ($pid > 0);
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $logPath) {
    // Append the log path to the command
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken >> $logPath 2>&1 &";
    
    // Execute the command
    $output = shell_exec($command);

    sleep(1);

    // Check for errors in the output
    if (!empty($output) && strpos($output, 'error') !== false) {
        return false;
    }
    
    // Bot started successfully
    return true;
}

function killBot($pid) {
    $output = shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
    if (isset($output) && strpos($output, 'error') !== false) {
        return false;
    }
    return true;
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

// Define variables
$betaVersionFilePath = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
$betaNewVersion = file_get_contents("/var/www/api/beta_version_control.txt");
$BetaBotScriptPath = "/var/www/bot/beta.py";
$BetaStatusScriptPath = "/var/www/bot/beta_status.py";
$BetaLogPath = "/var/www/logs/script/$username.txt";
$betaStatusOutput = getBetaBotStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$botSystemStatus = isBetaBotRunning($BetaStatusScriptPath, $username, $BetaLogPath);
$BetaDirectory = dirname($BetaLogPath);

// Check if the BetaDirectory exists, if not, create it
if (!file_exists($BetaDirectory)) {
    // Attempt to create the BetaDirectory with full permissions
    if (!mkdir($BetaDirectory, 0777, true)) {
        echo "Failed to create BetaDirectory: $BetaDirectory";
        exit;
    }
}

// Open the file in write mode ('w')
$file = fopen($BetaLogPath, 'w');

// Check if the file handle was successfully created
if ($file === false) {
    echo "Failed to create/open the file: $BetaLogPath";
    exit;
}

// Close the file handle
fclose($file);

function isBetaBotRunning($BetaStatusScriptPath, $username, $BetaLogPath) {
    $pid = getBetaBotPID($BetaStatusScriptPath, $username, $BetaLogPath);
    return ($pid > 0);
}

function startBetaBot($BetaBotScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath) {
    // Append the log path to the command
    $command = "python $BetaBotScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken >> $BetaLogPath 2>&1 &";
    
    // Execute the command
    $output = shell_exec($command);

    sleep(1);

    // Check for errors in the output
    if (!empty($output) && strpos($output, 'error') !== false) {
        return false;
    }
    
    // Bot started successfully
    return true;
}

function killBetaBot($pid) {
    $output = shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
    if (isset($output) && strpos($output, 'error') !== false) {
        return false;
    }
    return true;
}

function getBetaBotPID($BetaStatusScriptPath, $username, $BetaLogPath) {
    $betaStatusOutput = shell_exec("python $BetaStatusScriptPath -channel $username");
    if ($betaStatusOutput !== null) {
        return intval(preg_replace('/\D/', '', $betaStatusOutput));
    } else {
        return 0;
    }
}

function getBetaBotStatus($BetaStatusScriptPath, $username, $BetaLogPath) {
    $betaStatusOutput = shell_exec("python $BetaStatusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $betaStatusOutput));
    if ($betaStatusOutput !== null) {
        if ($pid > 0) {
            return "<div class='status-message'>Status: PID $pid.</div>";
        } else {
            return "<div class='status-message error'>Status: NOT RUNNING</div>";
        }
    } else {
        return "<div class='status-message error'>Unable to determine bot status.</div>";
    }
}

// Display running version if bot is running or if bot was started or restarted
if ($botSystemStatus == true) {
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

// Display running version if bot is running or if bot was started or restarted
if ($botSystemStatus == true) {
    // Check if the version control file exists
    if (file_exists($betaVersionFilePath)) {
        // If the file exists, read its contents
        $versionContent = file_get_contents($betaVersionFilePath); 
        $betaVersionRunning = "<div class='status-message'>Running Version: $versionContent</div>";

        // Compare the running version with the new version
        if ($versionContent !== $betaNewVersion) {
            // Display message for update if versions are different
            $betaVersionRunning .= "<div class='status-message'>Update (V$betaNewVersion) is available.</div>";
        }
    } else {
        // If the file doesn't exist, display a message indicating that the version is not available
        $betaVersionRunning = "<div class='status-message'>Version information not available.</div>";
    }
}
?>