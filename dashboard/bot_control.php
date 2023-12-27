<?php
$botScriptPath = "/var/www/bot/bot.py";
$statusScriptPath = "/var/www/bot/status.py";
$logPath = "/var/www/html/www/script/$username.txt";
$statusOutput = getBotStatus($statusScriptPath, $username, $logPath);

if (isset($_POST['runBot'])) {
    if (isBotRunning($statusScriptPath, $username, $logPath)) {
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));
        $statusOutput = "Bot is already running. Process ID: $pid";
    } else {
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));

        if ($pid > 0) {
            $statusOutput = "Bot started successfully. Process ID: $pid";
        } else {
            $statusOutput = "Failed to start the bot. Please check the configuration or server status.";
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
        $statusOutput = "Bot stopped successfully.";
    } else {
        $statusOutput = "Bot is not running.";
    }
}

if (isset($_POST['restartBot'])) {
    $pid = getBotPID($statusScriptPath, $username, $logPath);
    if ($pid > 0) {
        killBot($pid);
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));

        if ($pid > 0) {
            $statusOutput = "Bot restarted successfully. Process ID: $pid";
        } else {
            $statusOutput = "Failed to restart the bot. Please check the configuration or server status.";
        }
    } else {
        $statusOutput = "Bot is not running.";
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
            return "Bot Running with PID: $pid.";
        } else {
            return "Bot is not running.";
        }
    } else {
        return "Unable to determine bot status - I'm guessing the bot is not running.";
    }
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $logPath) {
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -port $webhookPort";
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

    if (strpos($output, 'error') !== false) {
        return false;
    }
    return true;
}

?>