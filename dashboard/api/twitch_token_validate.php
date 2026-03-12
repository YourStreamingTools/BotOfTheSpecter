<?php
/**
 * Validates a Twitch OAuth token against the Twitch validation endpoint.
 * Returns the decoded token data on success, or false on failure.
 */
if (!function_exists('validateTwitchToken')) {
  function validateTwitchToken($token) {
    $url = "https://id.twitch.tv/oauth2/validate";
    $headers = ['Authorization: OAuth ' . $token];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
      $data = json_decode($response, true);
      return $data;
    } else {
      return false;
    }
  }
}
