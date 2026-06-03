<?php
require '/var/www/config/db_connect.php';

require_once '/var/www/lib/session_bootstrap.php';
session_write_close();

// Load translations so user-facing messages are localized.
if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    $i18nPath = __DIR__ . '/../lang/i18n.php';
    if (file_exists($i18nPath)) {
        include_once $i18nPath;
    }
    if (!function_exists('t')) {
        function t($key, $replacements = [])
        {
            return $key;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'regen_api_key') {
    // Ensure the Twitch user ID exists in the session
    if (!isset($_SESSION['twitchUserId'])) {
        die(t('regen_api_key_error_no_user_id'));
    }
    // Retrieve Twitch user ID from session
    $twitchUserId = $_SESSION['twitchUserId'];
    // Generate a new API Key
    $new_api_key = bin2hex(random_bytes(16));
    // Update the database with the new API Key using prepared statements
    $stmt = $conn->prepare("UPDATE users SET api_key = ? WHERE twitch_user_id = ?");
    if ($stmt === false) {
        die('Prepare failed: ' . $conn->error);
    }
    // Bind parameters (s for string, i for integer)
    $stmt->bind_param("si", $new_api_key, $twitchUserId);
    // Execute the statement
    if ($stmt->execute()) {
        // Return the new API key back to the frontend
        echo $new_api_key;
    } else {
        // If the query fails, return an error
        echo t('regen_api_key_error_update_failed', [$stmt->error]);
    }
    // Close the prepared statement
    $stmt->close();
}
?>