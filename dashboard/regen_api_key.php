<?php
require 'db_connect.php';

// Start session only if it hasn't already been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'regen_api_key') {
    // Ensure the Twitch user ID exists in the session
    if (!isset($_SESSION['twitchUserId'])) {
        die("Twitch user ID not set. Please log in again.");
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
        echo "Error updating API key: " . $stmt->error;
    }
    // Close the prepared statement
    $stmt->close();
}
?>