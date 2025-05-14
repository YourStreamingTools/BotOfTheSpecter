<?php
session_start();

// Include necessary files
require_once "/var/www/config/db_connect.php";
include 'userdata.php';

// SSH Connection parameters
include "/var/www/config/ssh.php";

// Define paths based on the selected bot
$selectedBot = $_GET['bot'] ?? 'stable';
$statusScriptPath = "/var/www/bot/status.py";
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

// Set appropriate paths based on selected bot
switch ($selectedBot) {
    case 'beta':
        $logPath = "/var/www/logs/script/{$username}_beta.txt";
        $versionFilePath = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
        $newVersion = file_get_contents("/var/www/api/beta_version_control.txt") ?: 'N/A';
        $system = 'beta';
        break;
    case 'alpha':
        $logPath = "/var/www/logs/script/{$username}_alpha.txt";
        $versionFilePath = '/var/www/logs/version/' . $username . '_alpha_version_control.txt';
        $newVersion = file_get_contents("/var/www/api/alpha_version_control.txt") ?: 'N/A';
        $system = 'alpha';
        break;
    case 'discord':
        $logPath = "/var/www/logs/script/{$username}_discord.txt";
        $versionFilePath = '/var/www/logs/version/' . $username . '_discord_version_control.txt';
        $newVersion = file_get_contents("/var/www/api/discord_version_control.txt") ?: 'N/A';
        $system = 'discord';
        break;
    default:
        $logPath = "/var/www/logs/script/$username.txt";
        $versionFilePath = '/var/www/logs/version/' . $username . '_version_control.txt';
        $newVersion = file_get_contents("/var/www/api/bot_version_control.txt") ?: 'N/A';
        $system = 'stable';
        break;
}

// Function to check bot status - using the updated regex pattern
function getBotsStatus($statusScriptPath, $username, $logPath = '', $system = 'stable') {
    global $ssh_host, $ssh_username, $ssh_password;
    $connection = ssh2_connect($ssh_host, 22);
    if (!$connection) { return json_encode(['error' => 'SSH connection failed']); }
    
    // Authenticate using username and password
    if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
        return json_encode(['error' => 'SSH authentication failed']); 
    }
    
    // Run the command to get the bot's status
    $command = "python $statusScriptPath -system $system -channel $username";
    $stream = ssh2_exec($connection, $command);
    if (!$stream) { return json_encode(['error' => 'SSH command execution failed']); }
    
    // Set stream to blocking mode to read the output
    stream_set_blocking($stream, true);
    $statusOutput = trim(stream_get_contents($stream));
    fclose($stream);
    ssh2_disconnect($connection);
    
    // Use the updated regex pattern that matches "Bot is running with process ID: X"
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
}

// Function to get running version
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

// Check if the bot is running
$statusOutput = getBotsStatus($statusScriptPath, $username, $logPath, $system);
$botSystemStatus = strpos($statusOutput, 'PID') !== false;

// Get version info if running
$versionRunning = '';
if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion, $system !== 'stable' ? $system : '');
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'status' => $statusOutput,
    'version' => $versionRunning
]);
?>