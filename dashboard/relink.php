<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';

// Unset all of the session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit;
?>