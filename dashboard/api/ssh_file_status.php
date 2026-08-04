<?php
// Stream online marker via bots API (logs/online/{channel}.txt). Returns 'True', 'False', or null.
function checkSSHFileStatus($username) {
  // Name kept for callers (dashboard/bot.php); implementation is HTTP bots API.
  $client = __DIR__ . '/../includes/bots_api_client.php';
  if (!is_file($client)) {
    error_log('bots_api_client.php missing — cannot check online marker');
    return null;
  }
  require_once $client;
  $username = strtolower(trim((string)$username));
  if ($username === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $username)) {
    return null;
  }
  try {
    $resp = bots_api_online_marker($username);
    if (empty($resp['ok']) || !is_array($resp['data'] ?? null)) {
      return null;
    }
    $online = $resp['data']['online'] ?? null;
    if ($online === 'True' || $online === 'False') {
      return $online;
    }
    return null;
  } catch (Exception $e) {
    error_log("Online marker check failed for user {$username}: " . $e->getMessage());
    return null;
  }
}
