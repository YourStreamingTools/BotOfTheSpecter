<?php
session_start();
// Unset all mod context/session variables
unset($_SESSION['editing_user']);
unset($_SESSION['editing_username']);
unset($_SESSION['editing_display_name']);
unset($_SESSION['editing_profile_image']);
unset($_SESSION['editing_access_token']);
unset($_SESSION['editing_refresh_token']);
unset($_SESSION['editing_api_key']);
unset($_SESSION['broadcaster_id']);
// Redirect to main dashboard or login
header('Location: ../bot.php');
exit();
?>
