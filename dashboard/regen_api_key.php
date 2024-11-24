<?php
require 'db_connect.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'regen_api_key') {
    // Generate a new API Key
    $new_api_key = bin2hex(random_bytes(16));
    // Update the database with new API Key
    $stmt = $conn->prepare("UPDATE users SET api_key = :api_key WHERE id = :user_id");
    $stmt->execute(['api_key' => $new_api_key, 'user_id' => $user_id]);
    // Return Key Back to Profile Page
    echo $new_api_key;
}