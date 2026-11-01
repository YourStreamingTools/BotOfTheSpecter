<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/lib/require_auth_ajax.php';
session_write_close();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
include_once __DIR__ . '/lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once __DIR__ . '/includes/account_deletion.php';

// Profile page "Request data deletion": status, request and cancel as JSON.
account_deletion_handle($conn);
