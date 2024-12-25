<?php
session_start();

// Destroy all session data
session_unset();
session_destroy();

// Redirect the user back to the index page
header('Location: https://specterbot.app/index.php');
exit();
?>
