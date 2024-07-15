<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$title = "Payments";

// Connect to database
require_once "../db_connect.php";
require_once "payment.php";
require_once "stripe_customer.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$userEmail = $_SESSION['user_email'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$profileImageUrl = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

// Check if the user is already a Stripe customer
if (empty($user['stripe_customer_id'])) {
    $stripeCustomerId = createStripeCustomer($userEmail, $twitchDisplayName);
    if ($stripeCustomerId) {
        // Update the user's record with the new Stripe customer ID
        $updateSTMT = $conn->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ?");
        $updateSTMT->bind_param("si", $stripeCustomerId, $user_id);
        $updateSTMT->execute();
        $updateSTMT->close();
    } else {
        // Handle the case where the Stripe customer creation failed
        die('Failed to create Stripe customer. Please try again later.');
    }
}

// Check for status messages
$status = isset($_GET['status']) ? $_GET['status'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Header -->
    <?php include('../header.php'); ?>
    <!-- /Header -->
</head>
<body>
<!-- Navigation -->
<?php include('../navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <!-- Notifications -->
    <?php if ($status === 'success'): ?>
        <div class="notification is-success">
            <button class="delete"></button>
            Payment Successful! Your paid features are now enabled.
        </div>
    <?php elseif ($status === 'cancel'): ?>
        <div class="notification is-danger">
            <button class="delete"></button>
            Payment Canceled. If this was a mistake, you can try again.
        </div>
    <?php endif; ?>
    <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$profileImageUrl' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <h1 class="title">Premium Features</h1>

    <button id="checkout-button" class="button is-primary">Checkout</button>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script src="stripe.js"></script>
</body>
</html>