<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define variables
$betaVersionFilePath = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
$betaNewVersion = file_get_contents("/var/www/api/beta_version_control.txt");
$BetaBotScriptPath = "/var/www/bot/beta.py";
$BetaStatusScriptPath = "/var/www/bot/beta_status.py";
$BetaLogPath = "/var/www/logs/script/$username.txt";
$betaStatusOutput = getBetaBotStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$betaBotSystemStatus = isBetaBotOnline($BetaStatusScriptPath, $username, $BetaLogPath);
$BetaDirectory = dirname($BetaLogPath);

// Check if the BetaDirectory exists, if not, create it
if (!file_exists($BetaDirectory)) {
    // Attempt to create the BetaDirectory with full permissions
    if (!mkdir($BetaDirectory, 0777, True)) {
        echo "Failed to create BetaDirectory: $BetaDirectory";
        exit;
    }
}

// Open the file in write mode ('w')
$file = fopen($BetaLogPath, 'w');

// Check if the file handle was successfully created
if ($file === False) {
    echo "Failed to create/open the file: $BetaLogPath";
    exit;
}

// Close the file handle
fclose($file);

if (isset($_POST['runBetaBot'])) {
    if (isBetaBotRunning($BetaStatusScriptPath, $username, $BetaLogPath)) {
        $betaStatusOutput = shell_exec("python $BetaStatusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $betaStatusOutput));
        $betaBotSystemStatus = True; // Set to True if bot is running
        $betaStatusOutput = "<div class='status-message'>Bot is already running. PID $pid.</div>";
    } else {
        startBetaBot($BetaBotScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath);
        $betaStatusOutput = shell_exec("python $BetaStatusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $betaStatusOutput));

        if ($pid > 0) {
            $betaBotSystemStatus = True; // Set to True if bot started successfully
            $betaStatusOutput = "<div class='status-message'>Bot started successfully. PID $pid.</div>";
        } else {
            $betaBotSystemStatus = False; // Set to False if failed to start the bot
            $betaStatusOutput = "<div class='status-message error'>Failed to start the bot. Please check the configuration or server status.</div>";
        }
    }
}

if (isset($_POST['killBetaBot'])) {
    $pid = getBetaBotPID($BetaStatusScriptPath, $username, $BetaLogPath);
    if ($pid > 0) {
        killBetaBot($pid);
        $betaBotSystemStatus = False; // Set to False when bot is stopped
        $betaStatusOutput = "<div class='status-message'>Bot stopped successfully.</div>";
    } else {
        $betaBotSystemStatus = False; // Set to False if bot is not running
        $betaStatusOutput = "<div class='status-message error'>Bot is not running.</div>";
    }
}

if (isset($_POST['restartBetaBot'])) {
    $pid = getBetaBotPID($BetaStatusScriptPath, $username, $BetaLogPath);
    if ($pid > 0) {
        killBetaBot($pid);
        startBetaBot($BetaBotScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath);
        $betaStatusOutput = shell_exec("python $BetaStatusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $betaStatusOutput));

        if ($pid > 0) {
            $betaBotSystemStatus = True; // Set to True if bot restarted successfully
            $betaStatusOutput = "<div class='status-message'>Bot restarted. PID $pid.</h5>";
        } else {
            $betaBotSystemStatus = False; // Set to False if failed to restart the bot
            $betaStatusOutput = "<div class='status-message error'>Failed to restart the bot.</div>";
        }
    } else {
        $betaBotSystemStatus = False; // Set to False if bot is not running
        $betaStatusOutput = "<div class='status-message error'>Bot is not running.</div>";
    }
}

function isBetaBotRunning($BetaStatusScriptPath, $username, $BetaLogPath) {
    $pid = getBetaBotPID($BetaStatusScriptPath, $username, $BetaLogPath);
    return ($pid > 0);
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

function isBetaBotOnline($BetaStatusScriptPath, $username, $BetaLogPath) {
    $betaStatusOnlineOutput = shell_exec("python $BetaStatusScriptPath -channel $username");
    $pid = intval(preg_replace('/\D/', '', $betaStatusOnlineOutput));
    if ($betaStatusOnlineOutput !== null) {
        if ($pid > 0) {
            return True;
        } else {
            return False;
        }
    } else {
        return False;
    }
}

function startBetaBot($BetaBotScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $BetaLogPath) {
    // Append the log path to the command
    $command = "python $BetaBotScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken >> $BetaLogPath 2>&1 &";
    
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

function killBetaBot($pid) {
    $output = shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
    if (isset($output) && strpos($output, 'error') !== False) {
        return False;
    }
    $betaBotSystemStatus = False;
    return True;
}

// Display running version if bot is running or if bot was started or restarted
if ($betaBotSystemStatus == True) {
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