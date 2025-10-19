<?php
// Start session to access it
session_start();

// Destroy the session
session_unset();
session_destroy();

// Redirect to home page
header('Location: index.php');
exit;
?>