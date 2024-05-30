<?php
// Initialize the session
session_start();
 
// Unset all of the session variables
$_SESSION = array();
 
// Destroy the session
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - Unauthorized</title>
    <link rel="stylesheet" type="text/css" href="https://botofthespecter.com/style.css">
    <link rel="stylesheet" href="pagination.css">
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <style>.bio { text-align: center; }</style>
</head>
<body>
    <header>
        <h1>BotOfTheSpecter</h1>
    </header>
    <div class="container">
        <div class="bio">
            <p>Apologies for the inconvenience, but you are currently unable to access the Specter Bot dashboard.
            <br>This restriction is due to the extensive ongoing development of the project, which is presently open to a limited number of users for testing and feedback purposes.
            <br>We sincerely regret any inconvenience this may cause. Rest assured, we are diligently working to enhance Specter Bot's capabilities and look forward to providing broader access soon.
            <br>Thank you for your patience and understanding as we strive to improve the experience for all users.</p>
        </div>
    </div>
    <footer>
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.
    </footer>
</body>
</html>