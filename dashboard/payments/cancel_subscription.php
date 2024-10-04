<?php
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

require_once('stripe-php/init.php');
require_once "../db_connect.php";

// Set the secret key
$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK
\Stripe\Stripe::setApiKey($stripeSecretKey);

// Retrieve the user's subscription ID
$userId = $_SESSION['user_id'];

// Fetch the user's subscription ID from the database
$userSTMT = $conn->prepare("SELECT subscription_id FROM users WHERE id = ?");
$userSTMT->bind_param("i", $userId);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$subscriptionId = $user['subscription_id'];

if (!$subscriptionId) {
    die('No active subscription found.');
}

try {
    // Cancel the subscription immediately
    $subscription = \Stripe\Subscription::retrieve($subscriptionId);
    $subscription->cancel();
    // Update the database
    $updateSTMT = $conn->prepare("UPDATE users SET subscription_id = NULL WHERE id = ?");
    $updateSTMT->bind_param("i", $userId);
    $updateSTMT->execute();
    $updateSTMT->close();
    // Redirect or display a success message
    header('Location: index.php?status=cancel');
    exit();

} catch (Exception $e) {
    die('Error canceling subscription: ' . $e->getMessage());
}
?>