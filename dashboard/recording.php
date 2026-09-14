<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/lib/require_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    include_once __DIR__ . '/lang/i18n.php';
    require_once '/var/www/config/db_connect.php';
    require_once '/var/www/config/stream.php';
    include '/var/www/config/twitch.php';
    include 'includes/userdata.php';
    include 'includes/mod_access.php';
    include 'includes/user_db_connect.php';
    require_once __DIR__ . '/includes/youtube.php';
    require_once __DIR__ . '/includes/stream_api_client.php';
    require_once __DIR__ . '/includes/stream_hub_data.php';
}

$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    header('Location: streaming.php?' . $query);
    exit();
}
header('Location: streaming.php#library');
exit();
