<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// SSH Connection parameters
include "/var/www/config/ssh.php";

// Define variables for standard bot
$versionFilePath = '/var/www/logs/version/' . $username . '_version_control.txt';
$botScriptPath = "/home/botofthespecter/bot.py";
$statusScriptPath = "/home/botofthespecter/status.py";

// Define variables for beta bot
$betaVersionFilePath = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
$BetaBotScriptPath = "/home/botofthespecter/beta.py";

// Define variables for Discord bot
$discordVersionFilePath = '/var/www/logs/version/' . $username . '_discord_version_control.txt';
$discordBotScriptPath = "/home/botofthespecter/discordbot.py";

// Fetch all versions from the API ONCE at the top
$versionApiUrl = 'https://api.botofthespecter.com/versions';
$versionApiData = @file_get_contents($versionApiUrl);
if ($versionApiData !== false) {
    $versionInfo = json_decode($versionApiData, true);
    $newVersion = $versionInfo['stable_version'] ?? 'N/A';
    $betaNewVersion = $versionInfo['beta_version'] ?? 'N/A';
    $discordNewVersion = $versionInfo['discord_bot'] ?? 'N/A';
} else {
    $newVersion = $betaNewVersion = $discordNewVersion = 'N/A';
}

// Get bot status and check if it's running
try {
    $statusOutput = getBotsStatus($statusScriptPath, $username, 'stable');
    $botSystemStatus = checkBotsRunning($statusScriptPath, $username, 'stable');
    $betaStatusOutput = getBotsStatus($statusScriptPath, $username, 'beta');
    $betaBotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'beta');
    $discordStatusOutput = getBotsStatus($statusScriptPath, $username, 'discord');
    $discordBotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'discord');
} catch (Exception $e) {
    $statusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $botSystemStatus = false;
    $betaStatusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $betaBotSystemStatus = false;
    $discordStatusOutput = "<div class='status-message error'>Status: SSH connection error</div>";
    $discordBotSystemStatus = false;
}

// Define a timeout for bot shutdown
$shutdownTimeoutSeconds = 5;

// Handle standard bot actions
if (isset($_POST['runBot'])) {
    $waited = 0;
    while (checkBotsRunning($statusScriptPath, $username, 'stable') && $waited < $shutdownTimeoutSeconds) {
        sleep(1);
        $waited++;
    }
    if ($waited >= $shutdownTimeoutSeconds) { /* timeout warning */  }
    $statusOutput = handleTwitchBotAction('run', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    // The version running will be updated in the handleTwitchBotAction function
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if (isset($_POST['killBot'])) {
    $statusOutput = handleTwitchBotAction('kill', $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    $versionRunning = "";
}

// Handle beta bot actions
if (isset($_POST['runBetaBot'])) {
    $waited = 0;
    while (checkBotsRunning($statusScriptPath, $username, 'beta') && $waited < $shutdownTimeoutSeconds) {
        sleep(1);
        $waited++;
    }
    if ($waited >= $shutdownTimeoutSeconds) { /* timeout warning */ }
    $betaStatusOutput = handleTwitchBotAction('run', $BetaBotScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    // The version running will be updated in the handleTwitchBotAction function
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if (isset($_POST['killBetaBot'])) {
    $betaStatusOutput = handleTwitchBotAction('kill', $BetaBotScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
    $betaVersionRunning = "";
}

// Handle Discord bot actions
if (isset($_POST['runDiscordBot'])) {
    $waited = 0;
    while (checkBotsRunning($statusScriptPath, $username, 'discord') && $waited < $shutdownTimeoutSeconds) {
        sleep(1);
        $waited++;
    }
    if ($waited >= $shutdownTimeoutSeconds) { /* timeout warning */ }
    $discordStatusOutput = handleDiscordBotAction('run', $discordBotScriptPath, $statusScriptPath, $username);
    // Get the updated version running after action is performed
    $discordVersionRunning = getRunningVersion($discordVersionFilePath, $discordNewVersion);
}

// Handling Discord bot stop
if (isset($_POST['killDiscordBot'])) {
    $discordStatusOutput = handleDiscordBotAction('kill', $discordBotScriptPath, $statusScriptPath, $username);
    $discordVersionRunning = "";
}

// Function to handle Discord bot actions
function handleDiscordBotAction($action, $discordBotScriptPath, $statusScriptPath, $username) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password, $discordVersionFilePath, $discordNewVersion;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        throw new Exception('SSH authentication failed'); 
    }
    // Get PID of the running bot
    $command = "python $statusScriptPath -system discord -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('Failed to get bot status'); }
    stream_set_blocking($stream, true);
    $statusOutput = trim(stream_get_contents($stream));
    fclose($stream);
    if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } else {
        $pid = 0;
    }
    $message = '';
    switch ($action) {
        case 'run':
            if ($pid > 0) {
                $message = "<div class='status-message'>Discord bot is already running. PID $pid.</div>";
                // Ensure version file is up to date even if the bot is already running
                updateVersionFile($discordVersionFilePath, $discordNewVersion);
            } else {
                startDiscordBot($discordBotScriptPath, $username);
                sleep(2);
                $stream = ssh2_exec($connection, "python $statusScriptPath -system discord -channel $username");
                if (!$stream) { throw new Exception('Failed to check bot status after start'); }
                stream_set_blocking($stream, true);
                $statusOutput = trim(stream_get_contents($stream));
                fclose($stream);
                if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
                    $pid = intval($matches[1]);
                } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
                    $pid = intval($matches[1]);
                } else {
                    $pid = 0;
                }
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
    }
    if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
    return $message;
}

function handleTwitchBotAction($action, $botScriptPath, $statusScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    // Also get access to the version file paths and new versions for updating
    global $versionFilePath, $newVersion, $betaVersionFilePath, $betaNewVersion;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        throw new Exception('SSH authentication failed'); }
    // Get PID of the running bot
    $system = 'stable';
    if (strpos($botScriptPath, 'beta.py') !== false) { $system = 'beta'; } 
    $command = "python $statusScriptPath -system $system -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('Failed to get bot status'); }
    stream_set_blocking($stream, true);
    $statusOutput = trim(stream_get_contents($stream));
    fclose($stream);
    if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } else {
        $pid = 0;
    }
    $message = '';
    // Determine which version file to update based on the bot script path
    $currentVersionFilePath = "";
    $currentNewVersion = "";
    if (strpos($botScriptPath, 'beta.py') !== false) {
        $currentVersionFilePath = $betaVersionFilePath;
        $currentNewVersion = $betaNewVersion;
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
                    startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key);
                    sleep(2);
                    $stream = ssh2_exec($connection, "python $statusScriptPath -system $system -channel $username");
                    if (!$stream) { throw new Exception('Failed to check bot status after start'); }
                    stream_set_blocking($stream, true);
                    $statusOutput = trim(stream_get_contents($stream));
                    fclose($stream);
                    if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
                        $pid = intval($matches[1]);
                    } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
                        $pid = intval($matches[1]);
                    } else {
                        $pid = 0;
                    }
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
        }
    } catch (Exception $e) {
        error_log('Error handling bot action: ' . $e->getMessage());
        $message = "<div class='status-message error'>An error occurred: " . $e->getMessage() . "</div>";
    }
    if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
    return $message;
}

// Add new function to update version file with latest version
function updateVersionFile($versionFilePath, $newVersion) {
    // Create the file if it doesn't exist and write the new version to it
    file_put_contents($versionFilePath, $newVersion);
    return true;
}

function getBotsStatus($statusScriptPath, $username, $system = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    
    // Check if SSH2 functions are available
    if (!function_exists('ssh2_connect')) {
        return "<div class='status-message error'>Status: SSH2 extension not available</div>";
    }
      try {
        // Test basic connectivity first with timeout
        $fp = @fsockopen($bots_ssh_host, 22, $errno, $errstr, 5);
        if (!$fp) {
            return "<div class='status-message error'>Status: Cannot reach SSH server - $errstr ($errno)</div>";
        }
        fclose($fp);
        $connection = @ssh2_connect($bots_ssh_host, 22);
        if (!$connection) { 
            return "<div class='status-message error'>Status: SSH connection failed to $bots_ssh_host</div>";
        }
        // Authenticate using username and password
        if (!@ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
            if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
            return "<div class='status-message error'>Status: SSH authentication failed</div>";
        }
        // Run the command to get the bot's status
        $command = "python $statusScriptPath -system $system -channel $username";
        $stream = @ssh2_exec($connection, $command);
        if (!$stream) { 
            ssh2_disconnect($connection);
            return "<div class='status-message error'>Status: SSH command execution failed</div>";
        }
        // Set stream to blocking mode to read the output
        stream_set_blocking($stream, true);
        $statusOutput = trim(stream_get_contents($stream));
        fclose($stream);
        ssh2_disconnect($connection);
        if (preg_match('/Bot is running with process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else {
            $pid = 0;
        }
        if ($pid > 0) {
            return "<div class='status-message'>Status: PID $pid.</div>";
        } else {
            return "<div class='status-message error'>Status: NOT RUNNING</div>";
        }
    } catch (Exception $e) {
        return "<div class='status-message error'>Status: Error - " . $e->getMessage() . "</div>";
    }
}

function isBotRunning($statusScriptPath, $username) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        throw new Exception('SSH authentication failed'); }
    $command = "python $statusScriptPath -system stable -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    stream_set_blocking($stream, true);
    $statusOutput = trim(stream_get_contents($stream));
    fclose($stream);
    ssh2_disconnect($connection);
    if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } else {
        $pid = 0;
    }
    return ($pid > 0);
}

function getBotPID($statusScriptPath, $username) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        throw new Exception('SSH authentication failed'); }
    $command = "python $statusScriptPath -system stable -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    stream_set_blocking($stream, true);
    $statusOutput = trim(stream_get_contents($stream));
    fclose($stream);
    ssh2_disconnect($connection);
    if (preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
        $pid = intval($matches[1]);
    } else {
        $pid = 0;
    }
    return $pid;
}

function checkBotsRunning($statusScriptPath, $username, $system = 'stable') {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    
    // Check if SSH2 functions are available
    if (!function_exists('ssh2_connect')) {
        return false;
    }
      try {
        // Test basic connectivity first with timeout
        $fp = @fsockopen($bots_ssh_host, 22, $errno, $errstr, 5);
        if (!$fp) {
            return false;
        }
        fclose($fp);
        $connection = @ssh2_connect($bots_ssh_host, 22);
        if (!$connection) { 
            return false;
        }
        if (!@ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
            if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
            return false;
        }
        $command = "python $statusScriptPath -system $system -channel $username";
        $stream = @ssh2_exec($connection, $command);
        if (!$stream) { 
            ssh2_disconnect($connection);
            return false;
        }
        stream_set_blocking($stream, true);
        $statusOutput = trim(stream_get_contents($stream));
        fclose($stream);
        ssh2_disconnect($connection);
        if (preg_match('/Bot is running with process ID:\s*(\d+)/i', $statusOutput, $matches) || 
            preg_match('/process ID:\s*(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } elseif (preg_match('/PID\s+(\d+)/i', $statusOutput, $matches)) {
            $pid = intval($matches[1]);
        } else {
            $pid = 0;
        }
        
        return ($pid > 0);
    } catch (Exception $e) {
        return false;
    }
}

function killBot($pid) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
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

function startBot($botScriptPath, $username, $twitchUserId, $authToken, $refreshToken, $api_key) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        throw new Exception('SSH authentication failed'); }
    $command = "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -refresh $refreshToken -apitoken $api_key &";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { throw new Exception('SSH command execution failed'); }
    fclose($stream);
    ssh2_disconnect($connection);
    return true;
}

function startDiscordBot($botScriptPath, $username) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $connection = ssh2_connect($bots_ssh_host, 22);
    if (!$connection) { throw new Exception('SSH connection failed'); }
    if (!ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
        error_log('SSH authentication failed for Discord bot');
        throw new Exception('SSH authentication failed'); }
    $command = "python $botScriptPath -channel $username &";
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
    // Remove hardcoded English, use translation for status
    $discordRunning = "<div class='status-message'>" . t('bot_status_online') . "</div>";
} else {
    $discordRunning = "<div class='status-message error'>" . t('bot_status_offline') . "</div>";
    $discordVersionRunning = "";
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
        // If file doesn't exist, just show N/A
        return "<div class='status-message'>" . ucfirst($type) . " Running Version: N/A</div>";
    }
}

// Function to test SSH connectivity
function testSSHConnection() {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    if (!function_exists('ssh2_connect')) {
        return ['success' => false, 'message' => 'SSH2 extension not available'];
    }
    // Test basic connectivity first
    $fp = @fsockopen($bots_ssh_host, 22, $errno, $errstr, 5);
    if (!$fp) {
        return ['success' => false, 'message' => "Cannot connect to $bots_ssh_host:22 - $errstr ($errno)"];
    }
    fclose($fp);
    // Test SSH connection
    try {
        $connection = @ssh2_connect($bots_ssh_host, 22);
        if (!$connection) {
            return ['success' => false, 'message' => 'SSH connection failed'];
        }
        if (!@ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
            ssh2_disconnect($connection);
            return ['success' => false, 'message' => 'SSH authentication failed'];
        }
        ssh2_disconnect($connection);
        return ['success' => true, 'message' => 'SSH connection successful'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'SSH error: ' . $e->getMessage()];
    }
}

// Handle SSH test request
if (isset($_GET['test_ssh']) && $_GET['test_ssh'] == '1') {
    $testResult = testSSHConnection();
    echo json_encode($testResult);
    exit;
}
?>