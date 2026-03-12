<?php
/**
 * Checks whether BotOfTheSpecter is a moderator in the given channel.
 * Returns true if the bot is a moderator, false otherwise.
 */
function checkBotIsMod($broadcasterId, $authToken, $clientID) {
  try {
    $url = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=" . urlencode($broadcasterId);
    $headers = [
      'Authorization: Bearer ' . $authToken,
      'Client-ID: ' . $clientID
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
      error_log("Twitch API mod check failed. HTTP Code: $httpCode");
      return false;
    }
    $data = json_decode($response, true);
    if (!isset($data['data'])) {
      error_log("Twitch API mod check response missing data field");
      return false;
    }
    $botUserId = '971436498';
    foreach ($data['data'] as $mod) {
      if ($mod['user_id'] === $botUserId) {
        return true;
      }
    }
    return false;
  } catch (Exception $e) {
    error_log("Twitch API mod check exception: " . $e->getMessage());
    return false;
  }
}

/**
 * Checks whether BotOfTheSpecter is banned in the given channel.
 * Returns ['banned' => bool, 'reason' => string].
 */
function checkBotIsBanned($broadcasterId, $authToken, $clientID) {
  try {
    $botUserId = '971436498';
    $url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=" . urlencode($broadcasterId) . "&user_id=" . urlencode($botUserId);
    $headers = [
      'Authorization: Bearer ' . $authToken,
      'Client-ID: ' . $clientID
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
      return ['banned' => false, 'reason' => ''];
    }
    $data = json_decode($response, true);
    if (!isset($data['data'])) {
      return ['banned' => false, 'reason' => ''];
    }
    if (!empty($data['data'])) {
      $banReason = $data['data'][0]['reason'] ?? 'No reason given';
      return ['banned' => true, 'reason' => $banReason];
    }
    return ['banned' => false, 'reason' => ''];
  } catch (Exception $e) {
    error_log("Twitch API ban check exception: " . $e->getMessage());
    return ['banned' => false, 'reason' => ''];
  }
}
