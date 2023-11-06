<?php
$botScriptPath = "/var/www/bot/bot.py";
$statusScriptPath = "/var/www/bot/status.py";
$logPath = "/var/www/html/script/$username.txt";

if (isset($_POST['runBot'])) {
    if (isBotRunning($statusScriptPath, $username, $logPath)) {
        $statusOutput = shell_exec("python $statusScriptPath -channel $username > $logPath");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));
        $statusOutput = "Bot is already running. Process ID: $pid";
    } else {
        startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $logPath);
        $statusOutput = shell_exec("python $statusScriptPath -channel $username > $logPath");
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
        $statusOutput = "Bot restarted successfully.";
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
    return intval(preg_replace('/\D/', '', $statusOutput));
}

function getBotStatus($statusScriptPath, $username, $logPath) {
    return shell_exec("python $statusScriptPath -channel $username > $logPath");
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $webhookPort, $logPath) {
    $command = "screen -dmS $username python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -port $webhookPort > $logPath";
    shell_exec($command);
    sleep(3);
}

function killBot($pid) {
    shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
}
?>