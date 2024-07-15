<?php
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: https://dashboard.botofthespecter.com/login.php');
    exit();
}

// Redirect to index.php with success message
header('Location: index.php?status=success');
exit();
?>