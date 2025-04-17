<?php
// SSH Connection parameters
include "/var/www/config/ssh.php";

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
$discordVersionFilePath = '/var/www/logs/version/' . $username . '_discord_version_control.txt';
$discordNewVersion = file_get_contents("/var/www/api/discord_version_control.txt") ?: 'N/A';
$discordBotScriptPath = "/var/www/bot/discordbot.py";
$discordStatusScriptPath = "/var/www/bot/discordstatus.py";
$discordLogPath = "/var/www/logs/script/{$username}_discord.txt";

// Define variables for Alpha bot
$alphaVersionFilePath = '/var/www/logs/version/' . $username . '_alpha_version_control.txt';
$alphaNewVersion = file_get_contents("/var/www/api/alpha_version_control.txt") ?: 'N/A';
$alphaBotScriptPath = "/var/www/bot/alpha.py";
$alphaStatusScriptPath = "/var/www/bot/alpha_status.py";
$alphaLogPath = "/var/www/logs/script/{$username}_alpha.txt";

// Get bot status and check if it's running
$statusOutput = getBotsStatus($statusScriptPath, $username, $logPath);
$botSystemStatus = checkBotsRunning($statusScriptPath, $username, $logPath);
$betaStatusOutput = getBotsStatus($BetaStatusScriptPath, $username, $BetaLogPath);
$betaBotSystemStatus = checkBotsRunning($BetaStatusScriptPath, $username, $BetaLogPath);
$alphaStatusOutput = getBotsStatus($alphaStatusScriptPath, $username, $alphaLogPath);
$alphaBotSystemStatus = checkBotsRunning($alphaStatusScriptPath, $username, $alphaLogPath);
$discordStatusOutput = getBotsStatus($discordStatusScriptPath, $username, $discordLogPath);
$discordBotSystemStatus = checkBotsRunning($discordStatusScriptPath, $username, $discordLogPath);

// Check if log directories exist, if not, create them
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

// Check if directories exist, if not, create them
$alphaDirectory = dirname($alphaLogPath);
if (!file_exists($alphaDirectory)) {
    if (!mkdir($alphaDirectory, 0777, true)) {
        echo "<script>console.error('Failed to create directory: $alphaDirectory');</script>";
        exit;
    }
}

// Open and close the log file to ensure it exists
if (($file = fopen($alphaLogPath, 'w')) === false) {
    echo "<script>console.error('Failed to create/open the file: $alphaLogPath');</script>";
    exit;
}
fclose($file);

// Handle standard bot actions
if (isset($_POST['runBot'])) {
    $statusOutput = handleTwitchBotAction('run', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
    // The version running will be updated in the handleTwitchBotAction function
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if (isset($_POST['killBot'])) {
    $statusOutput = handleTwitchBotAction('kill', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
    $versionRunning = "";
}

if (isset($_POST['restartBot'])) {
    $statusOutput = handleTwitchBotAction('restart', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
    // The version running will be updated in the handleTwitchBotAction function
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

// Handle beta bot actions
if (isset($_POST['runBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('run', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $BetaLogPath);
    // The version running will be updated in the handleTwitchBotAction function
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if (isset($_POST['killBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('kill', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $BetaLogPath);
    $betaVersionRunning = "";
}

if (isset($_POST['restartBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('restart', $BetaBotScriptPath, $BetaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $BetaLogPath);
    // The version running will be updated in the handleTwitchBotAction function
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

// Handle Discord bot actions
if (isset($_POST['runDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('run', $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath);
    // Get the updated version running after action is performed
    $discordVersionRunning = getRunningVersion($discordVersionFilePath, $discordNewVersion);
}

// Handling Discord bot stop
if (isset($_POST['killDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('kill', $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath);
    $discordVersionRunning = "";
}

// Handling Discord bot restart
if (isset($_POST['restartDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('restart', $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath);
    // Get the updated version running after action is performed
    $discordVersionRunning = getRunningVersion($discordVersionFilePath, $discordNewVersion);
}

// Handle Alpha bot actions
if (isset($_POST['runAlphaBot'])) {
    $alphaStatusOutput = handleTwitchBotAction('run', $alphaBotScriptPath, $alphaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $alphaLogPath);
    // The version running will be updated in the handleTwitchBotAction function
    $alphaVersionRunning = getRunningVersion($alphaVersionFilePath, $alphaNewVersion, 'alpha');
}

if (isset($_POST['killAlphaBot'])) {
    $alphaStatusOutput = handleTwitchBotAction('kill', $alphaBotScriptPath, $alphaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $alphaLogPath);
    $alphaVersionRunning = "";
}

if (isset($_POST['restartAlphaBot'])) {
    $alphaStatusOutput = handleTwitchBotAction('restart', $alphaBotScriptPath, $alphaStatusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $alphaLogPath);
    // The version running will be updated in the handleTwitchBotAction function
    $alphaVersionRunning = getRunningVersion($alphaVersionFilePath, $alphaNewVersion, 'alpha');
}

// Function to handle Discord bot actions
function handleDiscordBotAction($action, $discordBotScriptPath, $discordStatusScriptPath, $username, $discordLogPath) {
    global $ssh_host, $ssh_username, $ssh_password, $discordVersionFilePath, $discordNewVersion;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    // Get PID of the running bot
    $command = "python $discordStatusScriptPath -channel $username";
    $statusOutput = ssh2_exec($connection, $command);
    if (!$statusOutput) { throw new Exception('Failed to get bot status'); }
    stream_set_blocking($statusOutput, true);
    $pid = intval(preg_replace('/\D/', '', stream_get_contents($statusOutput)));
    fclose($statusOutput);
    $message = '';
    switch ($action) {
        case 'run':
            if ($pid > 0) {
                $message = "<div class='status-message'>Discord bot is already running. PID $pid.</div>";
                // Ensure version file is up to date even if the bot is already running
                updateVersionFile($discordVersionFilePath, $discordNewVersion);
            } else {
                startDiscordBot($discordBotScriptPath, $username, $discordLogPath);
                sleep(2);
                $statusOutput = ssh2_exec($connection, "python $discordStatusScriptPath -channel $username");
                if (!$statusOutput) { throw new Exception('Failed to check bot status after start'); }
                stream_set_blocking($statusOutput, true);
                $pid = intval(preg_replace('/\D/', '', stream_get_contents($statusOutput)));
                fclose($statusOutput);
                if ($pid > 0) {
                    // Update version file with latest version when bot is started
                    updateVersionFile($discordVersionFilePath, $discordNewVersion);
                    $message = "<div class='status-message'>Discord bot started successfully. PID $pid.</div>";
                } else {
                    $message = "<div class='status-message error'>Failed to start the Discord bot. Please check the configuration or server status.</div>";
                    $discordVersionRunning = "";
                }
            }
            break;
        case 'kill':
            if ($pid > 0) {
                killBot($pid);
                $message = "<div class='status-message'>Discord bot stopped successfully.</div>";
                $discordVersionRunning = "";
            } else {
                $message = "<div class='status-message error'>Discord bot is not running.</div>";
                $discordVersionRunning = "";
            }
            break;
        case 'restart':
            if ($pid > 0) {
                killBot($pid);
                startDiscordBot($discordBotScriptPath, $username, $discordLogPath);
                $statusOutput = ssh2_exec($connection, "python $discordStatusScriptPath -channel $username");
                if (!$statusOutput) {
                    throw new Exception('Failed to check bot status after restart');
                }
                stream_set_blocking($statusOutput, true);
                $pid = intval(preg_replace('/\D/', '', stream_get_contents($statusOutput)));
                fclose($statusOutput);
                if ($pid > 0) {
                    // Update version file with latest version on restart
                    updateVersionFile($discordVersionFilePath, $discordNewVersion);
                    $message = "<div class='status-message'>Discord bot restarted. PID $pid.</div>";
                } else {
                    $message = "<div class='status-message error'>Failed to restart the Discord bot.</div>";
                    $discordVersionRunning = "";
                }
            } else {
                $message = "<div class='status-message error'>Discord bot is not running.</div>";
                $discordVersionRunning = "";
            }
            break;
    }
    ssh2_disconnect($connection);
    return $message;
}

function handleTwitchBotAction($action, $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath) {
    global $ssh_host, $ssh_username, $ssh_password;
    // Also get access to the version file paths and new versions for updating
    global $versionFilePath, $newVersion, $betaVersionFilePath, $betaNewVersion, $alphaVersionFilePath, $alphaNewVersion;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    // Get PID of the running bot
    $command = "python $statusScriptPath -channel $username";
    $statusOutput = ssh2_exec($connection, $command);
    if (!$statusOutput) { throw new Exception('Failed to get bot status'); }
    stream_set_blocking($statusOutput, true);
    $pid = intval(preg_replace('/\D/', '', stream_get_contents($statusOutput)));
    fclose($statusOutput);
    $message = '';
    // Determine which version file to update based on the bot script path
    $currentVersionFilePath = "";
    $currentNewVersion = "";
    if (strpos($botScriptPath, 'beta.py') !== false) {
        $currentVersionFilePath = $betaVersionFilePath;
        $currentNewVersion = $betaNewVersion;
    } elseif (strpos($botScriptPath, 'alpha.py') !== false) {
        $currentVersionFilePath = $alphaVersionFilePath;
        $currentNewVersion = $alphaNewVersion;
    } else {
        $currentVersionFilePath = $versionFilePath;
        $currentNewVersion = $newVersion;
    }
    try {
        switch ($action) {
            case 'run':
                if ($pid > 0) {
                    // Ensure version file is up to date even if the bot is already running
                    updateVersionFile($currentVersionFilePath, $currentNewVersion);
                    $message = "<div class='status-message'>Bot is already running. PID $pid.</div>";
                } else {
                    startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath);
                    sleep(2);
                    $statusOutput = ssh2_exec($connection, "python $statusScriptPath -channel $username");
                    if (!$statusOutput) { throw new Exception('Failed to check bot status after start'); }
                    stream_set_blocking($statusOutput, true);
                    $pid = intval(preg_replace('/\D/', '', stream_get_contents($statusOutput)));
                    fclose($statusOutput);
                    if ($pid > 0) {
                        // Update version file with latest version on successful start
                        updateVersionFile($currentVersionFilePath, $currentNewVersion);
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
                    sleep(2);
                    $statusOutput = ssh2_exec($connection, "python $statusScriptPath -channel $username");
                    if (!$statusOutput) { throw new Exception('Failed to check bot status after restart'); }
                    stream_set_blocking($statusOutput, true);
                    $pid = intval(preg_replace('/\D/', '', stream_get_contents($statusOutput)));
                    fclose($statusOutput);
                    if ($pid > 0) {
                        // Update version file with latest version on successful restart
                        updateVersionFile($currentVersionFilePath, $currentNewVersion);
                        $message = "<div class='status-message'>Bot restarted successfully. PID $pid.</div>";
                    } else {
                        $message = "<div class='status-message error'>Failed to restart the bot.</div>";
                    }
                } else {
                    $message = "<div class='status-message error'>Bot is not running.</div>";
                }
                break;
        }
    } catch (Exception $e) {
        error_log('Error handling bot action: ' . $e->getMessage());
        $message = "<div class='status-message error'>An error occurred: " . $e->getMessage() . "</div>";
    }
    ssh2_disconnect($connection);
    return $message;
}

// Add new function to update version file with latest version
function updateVersionFile($versionFilePath, $newVersion) {
    // Create the file if it doesn't exist and write the new version to it
    file_put_contents($versionFilePath, $newVersion);
    return true;
}

function getBotsStatus($statusScriptPath, $username) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    // Authenticate using username and password
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    // Run the command to get the bot's status
    $command = "python $statusScriptPath -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    // Set stream to blocking mode to read the output
    stream_set_blocking($stream, true);
    $statusOutput = stream_get_contents($stream);
    fclose($stream);
    ssh2_disconnect($connection);
    // Process the output to extract PID
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    if ($pid > 0) {
        return "<div class='status-message'>Status: PID $pid.</div>";
    } else {
        return "<div class='status-message error'>Status: NOT RUNNING</div>";
    }
}

function isBotRunning($statusScriptPath, $username) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    $command = "python $statusScriptPath -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    stream_set_blocking($stream, true);
    $statusOutput = stream_get_contents($stream);
    fclose($stream);
    ssh2_disconnect($connection);
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    return ($pid > 0);
}

function getBotPID($statusScriptPath, $username) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    $command = "python $statusScriptPath -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    stream_set_blocking($stream, true);
    $statusOutput = stream_get_contents($stream);
    fclose($stream);
    ssh2_disconnect($connection);
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    return $pid;
}

function checkBotsRunning($statusScriptPath, $username, $logPath) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    $command = "python $statusScriptPath -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    stream_set_blocking($stream, true);
    $statusOutput = stream_get_contents($stream);
    fclose($stream);
    ssh2_disconnect($connection);
    $pid = intval(preg_replace('/\D/', '', $statusOutput));
    return ($pid > 0);
}

function killBot($pid) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    $command = "kill -s kill $pid";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    fclose($stream);
    ssh2_disconnect($connection);
    sleep(1);
    if (empty($output) || strpos($output, 'No such process') === false) {
        return true;
    } else {
        throw new Exception('Failed to kill the bot process. Output: ' . $output);
    }
}

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key, $logPath) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        throw new Exception('SSH authentication failed'); }
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $api_key > $logPath 2>&1 &";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    fclose($stream);
    ssh2_disconnect($connection);
    return true;
}

function startDiscordBot($botScriptPath, $username, $logPath) {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        error_log('SSH authentication failed for Discord bot');
        throw new Exception('SSH authentication failed'); }
    $command = "python $botScriptPath -channel $username > $logPath 2>&1 &";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    fclose($stream);
    ssh2_disconnect($connection);
    return true;
}

if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if ($discordBotSystemStatus) {
    $discordVersionRunning = getRunningVersion($discordVersionFilePath, $discordNewVersion);
    $discordRunning = "<div class='status-message'>Discord bot is running.</div>";
} else {
    $discordRunning = "<div class='status-message error'>Discord bot is NOT RUNNING.</div>";
    $discordVersionRunning = "";
}

if ($alphaBotSystemStatus) {
    $alphaVersionRunning = getRunningVersion($alphaVersionFilePath, $alphaNewVersion, 'alpha');
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
        // If file doesn't exist, create it with the new version
        file_put_contents($versionFilePath, $newVersion);
        return "<div class='status-message'>" . ucfirst($type) . " Running Version: $newVersion</div>";
    }
}
?>