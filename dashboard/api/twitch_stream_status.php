<?php
/**
 * Checks if a Twitch channel is currently live via the Helix streams endpoint.
 * Returns 'True' if live, null otherwise (never returns 'False').
 */
function checkTwitchStreamStatus($twitchUserId, $authToken, $clientID) {
  if (empty($twitchUserId) || empty($authToken) || empty($clientID)) {
    error_log("Twitch API check failed: Missing required parameters");
    return null;
  }
  try {
    $url = "https://api.twitch.tv/helix/streams?user_id=" . urlencode($twitchUserId);
    $headers = [
      'Authorization: Bearer ' . $authToken,
      'Client-ID: ' . $clientID
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
      error_log("Twitch API request failed. HTTP Code: $httpCode");
      return null;
    }
    $data = json_decode($response, true);
    if (!isset($data['data'])) {
      error_log("Twitch API response missing data field");
      return null;
    }
    if (!empty($data['data'])) {
      return 'True';
    }
    return null;
  } catch (Exception $e) {
    error_log("Twitch API check exception: " . $e->getMessage());
    return null;
  }
}
