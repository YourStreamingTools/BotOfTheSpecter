<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
$botScriptPath = "/var/www/bot/bot.py";
$statusScriptPath = "/var/www/bot/status.py";
$logPath = "/var/www/dashboard/logs/script/$username.txt";
$statusOutput = getBotStatus($statusScriptPath, $username, $logPath);

if (isset($_POST['runBot'])) {
    if (isBotRunning($statusScriptPath, $username, $logPath)) {
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));
        $statusOutput = "<h3>Bot is already running. Process ID: $pid.</h3>";
    } else {
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $webshocketPort, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));

        if ($pid > 0) {
            $statusOutput = "<h3>Bot started successfully. Process ID: $pid.</h3>";
        } else {
            $statusOutput = "<h3 style='color: red;'>Failed to start the bot. Please check the configuration or server status.</h3>";
        }
    }
}

if (isset($_POST['botStatus'])) {
    $statusOutput = getBotStatus($statusScriptPath, $username, $logPath);
}

if (isset($_POST['killBot'])) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    if ($pid > 0) {
        killBot($pid);
        $statusOutput = "<h3>Bot stopped successfully.</h3>";
    } else {
        $statusOutput = "<h3 style='color: red;'>Bot is not running.</h3>";
    }
}

if (isset($_POST['restartBot'])) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    if ($pid > 0) {
        killBot($pid);
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $webshocketPort, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));

        if ($pid > 0) {
            $statusOutput = "<h3>Bot restarted successfully. Process ID: $pid.</h3>";
        } else {
            $statusOutput = "<h3 style='color: red;'>Failed to restart the bot. Please check the configuration or server status.</h3>";
        }
    } else {
        $statusOutput = "<h3 style='color: red;'>Bot is not running.</h3>";
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
    if ($statusOutput !== null) {
        $pid = intval(preg_replace('/\D/', '', $statusOutput));
        if ($pid > 0) {
            return "<h3>Bot Running with PID: $pid.</h3>";
        } else {
            return "<h3 style='color: red;'>Bot is not running.</h3>";
        }
    } else {
        return "<h3 style='color: red;'>Unable to determine bot status - I'm guessing the bot is not running.</h3>";
    }
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $webshocketPort, $logPath) {
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -hookport $webhookPort -socketport $webshocketPort";
    $output = shell_exec($command . ' > /dev/null 2>&1 &');
    sleep(3);

    if (!empty($output) && strpos($output, 'error') !== false) {
        return false;
    }
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

?>