<?php
function streamApiRequest(string $base, string $apiKey, string $path, int $timeout, string $method = 'GET', ?array $jsonBody = null): array
{
    if ($base === '' || $apiKey === '') {
        return ['ok' => false, 'error' => 'not_configured', 'http' => 0, 'body' => null];
    }
    $ch = curl_init($base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $headers = [
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'curl_failed', 'http' => $http, 'body' => null];
    }
    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'error' => 'http_' . $http, 'http' => $http, 'body' => $body];
    }
    return ['ok' => true, 'error' => '', 'http' => $http, 'body' => $body];
}

function streamFetchStorage(string $base, string $apiKey, int $timeout): array
{
    $out = [
        'used_bytes' => 0,
        'quota_bytes' => 100 * 1024 * 1024 * 1024,
        'unlimited' => false,
    ];
    $list = streamApiRequest($base, $apiKey, '/api/me/recordings', $timeout);
    if (!$list['ok'] || !is_string($list['body'])) {
        return $out;
    }
    $payload = json_decode($list['body'], true);
    if (!is_array($payload)) {
        return $out;
    }
    if (array_key_exists('used_bytes', $payload)) {
        $out['used_bytes'] = max(0, (int) $payload['used_bytes']);
    }
    if (array_key_exists('quota_bytes', $payload)) {
        $out['quota_bytes'] = max(0, (int) $payload['quota_bytes']);
    }
    $out['unlimited'] = !empty($payload['quota_unlimited']) || $out['quota_bytes'] === 0;
    return $out;
}
