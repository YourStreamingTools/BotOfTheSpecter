<?php
// Start session to access it
session_start();

// Destroy the session
session_destroy();

// Redirect to index page
header('Location: index.php');
exit;
?>
