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
<html>
<head>
    <title>Logging out...</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
</head>
<body>
    <section class="hero is-fullheight is-dark">
        <div class="hero-body">
            <div class="container has-text-centered">
                <div class="box" style="max-width: 400px; margin: 0 auto;">
                    <h1 class="title is-4">Logging out...</h1>
                    <p>You are being redirected to the login page.</p>
                </div>
            </div>
        </div>
    </section>
</body>
</html>