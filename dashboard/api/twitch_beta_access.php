<?php
/**
 * Determines whether the user has beta access and their subscription tier.
 * Returns ['betaAccess' => bool, 'tier' => string].
 */
function checkBetaAccess($user, $twitchUserId, $authToken, $clientID) {
  if ($user['beta_access'] == 1) {
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
  $response = curl_exec($ch);
  curl_close($ch);
  $data = json_decode($response, true);
  if (isset($data['data'][0])) {
    $tier = $data['data'][0]['tier'];
    $betaAccess = in_array($tier, ['1000', '2000', '3000']);
    return ['betaAccess' => $betaAccess, 'tier' => $tier];
  }
  return ['betaAccess' => false, 'tier' => 'None'];
}
