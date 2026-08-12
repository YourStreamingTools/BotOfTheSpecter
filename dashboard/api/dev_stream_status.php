<?php
/**
 * Lightweight JSON probe for the dashboard topbar "dev stream online" badge.
 * Runs after first paint (client fetch) so layout.php never blocks on Helix/API.
 */
require_once '/var/www/lib/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['online' => false, 'error' => 'auth']);
    exit;
}

$online = false;
try {
    include '/var/www/config/admin_actions.php';
    if (!empty($admin_key)) {
        $apiUrl = 'https://api.botofthespecter.com/v2/streamonline?channel=gfaundead';
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $admin_key,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['online']) && $data['online'] === true) {
                $online = true;
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

echo json_encode(['online' => $online]);
