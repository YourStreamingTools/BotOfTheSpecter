<?php
session_start();
require_once('stripe-php/init.php');
require_once "../db_connect.php";

$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK
\Stripe\Stripe::setApiKey($stripeSecretKey);

$subscriptionId = $_SESSION['subscription_id'];

try {
    $subscription = \Stripe\Subscription::retrieve($subscriptionId);
    $subscription->cancel();

    // Update the user's record to remove the subscription ID
    $updateSTMT = $conn->prepare("UPDATE users SET subscription_id = NULL WHERE id = ?");
    $updateSTMT->bind_param("i", $_SESSION['user_id']);
    $updateSTMT->execute();
    $updateSTMT->close();

    echo json_encode(['success' => true]);

} catch (Error $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
