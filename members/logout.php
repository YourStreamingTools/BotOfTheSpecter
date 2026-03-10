<?php
// Initialize the session
session_start();

// Unset all of the session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page with logout success message
header("Location: login.php?logout=success");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging out...</title>
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="/style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body class="sp-hero-page">
    <div class="sp-hero">
        <div class="sp-login-card">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="width:80px;height:80px;object-fit:contain;border-radius:50%;margin-bottom:1rem;">
            <h1>BotOfTheSpecter</h1>
            <div class="sp-spinner" style="margin:1.5rem auto;"></div>
            <p style="color:var(--text-muted);font-size:0.9rem;">Logging out&hellip; You will be redirected to the login page.</p>
        </div>
    </div>
</body>
</html>