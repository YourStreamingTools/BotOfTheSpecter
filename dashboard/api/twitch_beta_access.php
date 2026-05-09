<?php
/**
 * Determines whether the user has beta access and their subscription tier.
 * Returns ['betaAccess' => bool, 'tier' => string].
 */
function checkBetaAccess($user, $twitchUserId, $authToken, $clientID) {
  if ((int)($user['beta_access'] ?? 0) === 1) {
    return ['betaAccess' => true, 'tier' => '4000'];
  }
  $url = "https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id=" . urlencode($twitchUserId);
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
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($response === false || $httpCode !== 200) {
    return ['betaAccess' => false, 'tier' => 'None'];
  }
  $data = json_decode($response, true);
  if (!is_array($data) || !isset($data['data'][0]['tier'])) {
    return ['betaAccess' => false, 'tier' => 'None'];
  }
  $tier = (string)$data['data'][0]['tier'];
  $betaAccess = in_array($tier, ['1000', '2000', '3000'], true);
  return ['betaAccess' => $betaAccess, 'tier' => $tier];
}
