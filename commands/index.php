<?php
// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// Check if a user is specified in the URL parameter
if (isset($_GET['user']) && !empty($_GET['user'])) {
    // Get username from URL
    $username = sanitize_input($_GET['user']);
    // Redirect to the new members page with the username
    header("Location: https://members.botofthespecter.com/$username");
    exit();
} else {
    // No user specified, redirect to the home page
    header("Location: https://members.botofthespecter.com/");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>BotOfTheSpecter - Redirecting</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body>
    <p>If you are not redirected automatically, follow this <a href="https://members.botofthespecter.com/">link</a>.</p>
</body>
</html>