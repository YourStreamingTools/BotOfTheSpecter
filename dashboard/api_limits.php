<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ob_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Connect to the website database
require_once "/var/www/config/db_connect.php";

// Query the api_counts table for limits
$query = "SELECT type, count, updated FROM api_counts";
$result = $conn->query($query);

$limits = [
    'shazam' => [
        'requests_remaining' => null,
        'requests_limit' => 500,
        'last_updated' => null
    ],
    'exchangerate' => [
        'requests_remaining' => null,
        'requests_limit' => 1500,
        'last_updated' => null
    ],
    'weather' => [
        'requests_remaining' => null,
        'requests_limit' => 1000,
        'last_updated' => null
    ]
];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $type = strtolower($row['type']);
        if (isset($limits[$type])) {
            $limits[$type]['requests_remaining'] = (int)$row['count'];
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row['updated'], new DateTimeZone(date_default_timezone_get()));
            if ($dt) {
                $dt->setTimezone(new DateTimeZone('UTC'));
                $limits[$type]['last_updated'] = $dt->format('Y-m-d\TH:i:s\Z');
            } else {
                $limits[$type]['last_updated'] = null;
            }
        }
    }
}

ob_clean();
header('Content-Type: application/json');
echo json_encode($limits);
exit();
?>
