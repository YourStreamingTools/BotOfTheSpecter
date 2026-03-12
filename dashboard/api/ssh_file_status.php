<?php
/**
 * Checks the bot's online status file via SSH.
 * Returns 'True', 'False', or null if SSH is unavailable or the status cannot be determined.
 */
function checkSSHFileStatus($username) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
  if (!extension_loaded('ssh2')) {
    error_log("SSH2 extension not loaded - cannot check SSH file status");
    return null;
  }
  if (empty($bots_ssh_host) || empty($bots_ssh_username) || empty($bots_ssh_password)) {
    error_log("SSH configuration incomplete - cannot check SSH file status");
    return null;
  }
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    if (!$connection) {
      return null;
    }
    $filePath = "/home/botofthespecter/logs/online/" . escapeshellarg($username) . ".txt";
    $command = "cat " . $filePath . " 2>/dev/null";
    $output = SSHConnectionManager::executeCommand($connection, $command);
    if ($output !== false && $output !== null) {
      if (function_exists('sanitizeSSHOutput')) {
        $output = sanitizeSSHOutput($output);
      } else {
        $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output);
        $output = preg_replace('/__SSH_EXIT_STATUS__-?\d+\s*$/', '', (string)$output);
      }
      $status = trim($output);
      if ($status === 'True' || $status === 'False') {
        return $status;
      }
    }
    return null;
  } catch (Exception $e) {
    error_log("SSH status check failed for user {$username}: " . $e->getMessage());
    return null;
  }
}
