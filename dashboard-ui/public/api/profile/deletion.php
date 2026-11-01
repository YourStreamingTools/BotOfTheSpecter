<?php
require_once dirname(__DIR__) . '/_live.php';
session_write_close();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
include_once '/var/www/dashboard/lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once '/var/www/dashboard/includes/account_deletion.php';

// Profile page "Request data deletion", shared with the live dashboard's account_deletion.php.
account_deletion_handle($conn);
