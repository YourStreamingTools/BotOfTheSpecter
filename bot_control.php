<?php
$botScriptPath = "/var/www/bot/bot.py";
$statusScriptPath = "/var/www/bot/status.py";

if (isset($_POST['runBot'])) {
    if (isBotRunning($statusScriptPath, $username)) {
        $statusOutput = shell_exec("python $statusScriptPath -channel $username");
        $pid = intval(preg_replace('/\D/', '', $statusOutput));
        $statusOutput = "Bot is already running. Process ID: $pid";
    } else {
        startBot($botScriptPath, $username, $twitchUserId, $authToken);
        $statusOutput = "Bot started successfully.";
    }
}

if (isset($_POST['botStatus'])) {
    $statusOutput = getBotStatus($statusScriptPath, $username);
}

if (isset($_POST['killBot'])) {
    $pid = getBotPID($statusScriptPath, $username);
    if ($pid > 0) {
        killBot($pid);
        $statusOutput = "Bot stopped successfully.";
    } else {
        $statusOutput = "Bot is not running.";
    }
}

if (isset($_POST['restartBot'])) {
    $pid = getBotPID($statusScriptPath, $username);
    if ($pid > 0) {
        killBot($pid);
        startBot($botScriptPath, $username, $twitchUserId, $authToken);
        $statusOutput = "Bot restarted successfully.";
    } else {
        $statusOutput = "Bot is not running.";
    }
}

function isBotRunning($statusScriptPath, $username) {
    $pid = getBotPID($statusScriptPath, $username);
    return ($pid > 0);
}

function getBotPID($statusScriptPath, $username) {
    $statusOutput = shell_exec("python $statusScriptPath -channel $username");
    return intval(preg_replace('/\D/', '', $statusOutput));
}

function getBotStatus($statusScriptPath, $username) {
    return shell_exec("python $statusScriptPath -channel $username");
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken) {
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken > /dev/null 2>&1 &";
    shell_exec($command);
    sleep(3);
}

function killBot($pid) {
    shell_exec("kill $pid > /dev/null 2>&1 &");
    sleep(3);
}
?>