<?php
// Start session to access it
session_start();
session_write_close();

// Destroy the session
session_unset();
session_destroy();

// Redirect to home page
header('Location: index.php');
exit;
?>