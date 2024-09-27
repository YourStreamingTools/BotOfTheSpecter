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

// Include Stripe PHP library and set the API key
require_once('stripe-php/init.php');
$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK
\Stripe\Stripe::setApiKey($stripeSecretKey);

// Connect to database
require_once "../db_connect.php";
require_once "stripe_customer.php";

// Define your product and price IDs for each plan
$plans = [
    'standard' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
        'name' => 'Standard Plan',
        'price' => '$5 USD',
    ],
    'premium' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
        'name' => 'Premium Plan',
        'price' => '$10 USD',
    ],
    'ultimate' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
        'name' => 'Ultimate Plan',
        'price' => '$15 USD',
    ],
];

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$userEmail = $_SESSION['user_email'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$_SESSION['user_id'] = $user_id;
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$profileImageUrl = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$stripeCustomerId = $user['stripe_customer_id'];
$_SESSION['stripe_customer_id'] = $stripeCustomerId;
$subscriptionId = $user['subscription_id'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include '../bot_control.php';
include '../sqlite.php';

// Check if the user is already a Stripe customer
if (empty($stripeCustomerId)) {
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

// Fetch the user's subscription status from Stripe
$subscriptionActive = false;
$currentPlan = 'free'; // Default to free

if (!empty($subscriptionId)) {
    try {
        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
        if ($subscription->status === 'active') {
            $subscriptionActive = true;
            // Determine the current plan based on the subscription's price ID
            $currentPriceId = $subscription->items->data[0]->price->id;
            foreach ($plans as $planName => $planDetails) {
                if ($planDetails['priceId'] === $currentPriceId) {
                    $currentPlan = $planName;
                    break;
                }
            }
        }
    } catch (\Exception $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Header -->
    <?php include('../header.php'); ?>
    <link rel="stylesheet" href="custom.css">
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
    <h2 class="subtitle" style="display: flex; align-items: center;">
        <img src="https://cdn.botofthespecter.com/StripeClimate/StripeClimate-Small.png" width="64" height="64" alt="Stripe Climate" style="margin-right: 10px;">
        <span>
            At YourStreamingTools, we believe businesses have a critically important role in combating climate change. 
            As the team behind BotOfTheSpecter, we're dedicated to making a positive impact on the environment. 
            We're proud to fund next-generation carbon removal efforts. To support this cause, we're contributing 1% of our revenue to carbon removal. 
            <a href="https://climate.stripe.com/tPEkBr" target="_blank">Learn more</a>
        </span>
    </h2>

    <div class="card-container">
        <!-- Free Plan -->
        <div class="card">
            <div class="card-content">
                <div class="content">
                    <h2 class="card-title">Free Plan<br>$0 USD</h2>
                    <ul>
                        <li><a class="feature-link" data-modal="modal-feature-basic">Basic Commands</a></li>
                        <li><a class="feature-link" data-modal="modal-feature-limited-support">Limited Support</a></li>
                    </ul>
                </div>
            </div>
            <?php if ($currentPlan === 'free'): ?>
                <div class="card-footer">
                    <p class="card-footer-item">
                        <span>Current Plan</span>
                    </p>
                </div>
            <?php else: ?>
                <form action="subscribe.php" method="POST">
                    <input type="hidden" name="plan" value="free">
                    <button type="submit" class="button is-primary">Switch to Free Plan</button>
                </form>
            <?php endif; ?>
        </div>
        <?php foreach ($plans as $planKey => $planDetails): ?>
            <div class="card">
                <div class="card-content">
                    <h2 class="card-title"><?php echo $planDetails['name']; ?><br><?php echo $planDetails['price']; ?></h2>
                    <ul>
                        <?php if ($planKey === 'standard'): ?>
                            <li><a class="feature-link" data-modal="modal-feature-song-weather">!song & !weather Commands</a></li>
                            <li><a class="feature-link" data-modal="modal-feature-support">Full Support</a></li>
                            <li><a class="feature-link" data-modal="modal-feature-beta">Exclusive Beta Features</a></li>
                            <li>Shared Bot (BotOfTheSpecter)</li>
                        <?php elseif ($planKey === 'premium'): ?>
                            <li>Everything From Standard Plan</li>
                            <li><a class="feature-link" data-modal="modal-feature-personalized-support">Personalized Support</a></li>
                            <li><a class="feature-link" data-modal="modal-feature-ai">AI Features & Conversations</a></li>
                            <li>Shared Bot (BotOfTheSpecter)</li>
                        <?php elseif ($planKey === 'ultimate'): ?>
                            <li>Everything from Premium Plan</li>
                            <li><a class="feature-link" data-modal="modal-feature-dedicated-bot">Dedicated bot (custom bot name)</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php if ($currentPlan === $planKey): ?>
                    <div class="card-footer">
                        <p class="card-footer-item">
                            <span>Current Plan</span>
                        </p>
                        <form action="cancel_subscription.php" method="POST" class="card-footer-item">
                            <button type="submit" class="button is-danger">Cancel Subscription</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form action="subscribe.php" method="POST">
                        <input type="hidden" name="plan" value="<?php echo $planKey; ?>">
                        <?php if ($subscriptionActive): ?>
                            <?php if (array_search($planKey, array_keys($plans)) > array_search($currentPlan, array_keys($plans))): ?>
                                <button type="submit" class="button is-primary">Upgrade to <?php echo $planDetails['name']; ?></button>
                            <?php else: ?>
                                <button type="submit" class="button is-primary">Downgrade to <?php echo $planDetails['name']; ?></button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="submit" class="button is-primary">Subscribe to <?php echo $planDetails['name']; ?></button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script src="stripe.js"></script>
<script src="modal.js"></script>
</body>
</html>