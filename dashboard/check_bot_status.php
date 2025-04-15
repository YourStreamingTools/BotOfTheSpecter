<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Not authenticated']);
  exit();
}

// Include required files
require_once "/var/www/config/db_connect.php";
include '/var/www/config/ssh.php';
include 'userdata.php';

// Get the bot type from the query parameter
$selectedBot = $_GET['bot'] ?? 'stable';
if (!in_array($selectedBot, ['stable', 'beta', 'alpha', 'discord'])) {
  $selectedBot = 'stable';
}

// Initialize response data
$responseData = [
  'status' => '<div class="status-message error">Status: NOT RUNNING</div>',
  'version' => '',
  'success' => true
];

// Define paths and variables based on selected bot
switch ($selectedBot) {
  case 'stable':
    $statusScriptPath = "/var/www/bot/status.py";
    $versionFilePath = '/var/www/logs/version/' . $username . '_version_control.txt';
    $newVersion = file_get_contents("/var/www/api/bot_version_control.txt") ?: 'N/A';
    $logPath = "/var/www/logs/script/$username.txt";
    break;
  case 'beta':
    $statusScriptPath = "/var/www/bot/beta_status.py";
    $versionFilePath = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
    $newVersion = file_get_contents("/var/www/api/beta_version_control.txt") ?: 'N/A';
    $logPath = "/var/www/logs/script/{$username}_beta.txt";
    $type = 'beta';
    break;
  case 'alpha':
    $statusScriptPath = "/var/www/bot/alpha_status.py";
    $versionFilePath = '/var/www/logs/version/' . $username . '_alpha_version_control.txt';
    $newVersion = file_get_contents("/var/www/api/alpha_version_control.txt") ?: 'N/A';
    $logPath = "/var/www/logs/script/{$username}_alpha.txt";
    $type = 'alpha';
    break;
  case 'discord':
    $statusScriptPath = "/var/www/bot/discordstatus.py";
    $versionFilePath = '/var/www/logs/version/' . $username . '_discord_version_control.txt';
    $newVersion = file_get_contents("/var/www/api/discord_version_control.txt") ?: 'N/A';
    $logPath = "/var/www/logs/script/{$username}_discord.txt";
    break;
}

try {
  // Check bot status
  $connection = ssh2_connect($ssh_host, 22);
  if (!$connection) { 
    throw new Exception('SSH connection failed'); 
  }
  
  if (!ssh2_auth_password($connection, $ssh_username, $ssh_password)) {
    throw new Exception('SSH authentication failed'); 
  }
  
  // Get PID of the running bot
  $command = "python $statusScriptPath -channel $username";
  $stream = ssh2_exec($connection, $command);
  if (!$stream) { 
    throw new Exception('SSH command execution failed'); 
  }
  
  // Set stream to blocking mode to read the output
  stream_set_blocking($stream, true);
  $statusOutput = stream_get_contents($stream);
  fclose($stream);
  
  // Process the output to extract PID
  $pid = intval(preg_replace('/\D/', '', $statusOutput));
  
  if ($pid > 0) {
    $responseData['status'] = "<div class='status-message'>Status: PID $pid.</div>";
    
    // Check if version file exists and get the version
    if (file_exists($versionFilePath)) {
      $versionContent = file_get_contents($versionFilePath);
      if ($versionContent !== false) {
        $versionContent = trim($versionContent);
        $output = "<div class='status-message'>" . ucfirst($type ?? '') . " Running Version: $versionContent</div>";
        if ($versionContent !== $newVersion) {
          $output .= "<div class='status-message'>Update (V$newVersion) is available.</div>";
        }
        $responseData['version'] = $output;
      }
    }
  }
  
  ssh2_disconnect($connection);
  
} catch (Exception $e) {
  $responseData['success'] = false;
  $responseData['error'] = $e->getMessage();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($responseData);
?>