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
unset($_SESSION['mod_act_as_active']);
unset($_SESSION['mod_act_as_started_at']);
unset($_SESSION['mod_act_as_actor_username']);
unset($_SESSION['mod_act_as_target_user_id']);
unset($_SESSION['mod_act_as_target_username']);
unset($_SESSION['mod_act_as_target_display_name']);
// Redirect to main dashboard or login
header('Location: ../mod_channels.php?act_as=stopped');
exit();
?>