<?php
require_once dirname(__DIR__) . '/_live.php';
session_write_close();
// Topbar "Dev Stream Online" pill, same probe as the live dashboard's api/dev_stream_status.php.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$online = false;
try {
    include '/var/www/config/admin_actions.php';
    if (!empty($admin_key)) {
        $ch = curl_init('https://api.botofthespecter.com/v2/dashboard/live?channel=gfaundead');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $admin_key]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $online = isset($data['online']) && $data['online'] === true;
        }
    }
} catch (Throwable $e) {
    // ignore: the pill just stays hidden
}
echo json_encode(['online' => $online]);
