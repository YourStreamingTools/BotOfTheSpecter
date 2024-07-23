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
$stripeCustomerId = $user['stripe_customer_id'];
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
            We’re proud to fund next-generation carbon removal efforts. To support this cause, we're contributing 1% of our revenue to carbon removal. 
            <a href="https://climate.stripe.com/tPEkBr" target="_blank">Learn more</a>
        </span>
    </h2>

    <div class="card-container">
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
        </div>

        <div class="card">
            <div class="card-content">
                <div class="content">
                    <h2 class="card-title">Standard Plan<br>$5 USD</h2>
                    <ul>
                        <li><a class="feature-link" data-modal="modal-feature-song-weather">!song & !weather Commands</a></li>
                        <li><a class="feature-link" data-modal="modal-feature-support">Full Support</a></li>
                        <li><a class="feature-link" data-modal="modal-feature-beta">Exclusive Beta Features</a></li>
                        <li>Shared Bot (BotOfTheSpecter)</li>
                    </ul>
                    <h2 class="card-subtitle">Coming Soon</h2>
                </div>
            </div>
        </div>

        <div class="card" id="premium-card">
            <div class="card-content">
                <div class="content">
                    <h2 class="card-title">Premium Plan<br>$10 USD</h2>
                    <ul>
                        <li>Everything From Standard Plan</li>
                        <li><a class="feature-link" data-modal="modal-feature-personalized-support">Personalized Support</a></li>
                        <li><a class="feature-link" data-modal="modal-feature-ai">AI Features & Conversations</a></li>
                        <li>Shared Bot (BotOfTheSpecter)</li>
                    </ul>
                    <h2 class="card-subtitle">Coming Soon</h2>
                </div>
            </div>
        </div>

        <div class="card" id="ultimate-card">
            <div class="card-content">
                <div class="content">
                    <h2 class="card-title">Ultimate Plan<br>$15 USD</h2>
                    <ul>
                        <li>Everything from Premium Plan</li>
                        <li><a class="feature-link" data-modal="modal-feature-dedicated-bot">Dedicated bot (custom bot name)</a></li>
                    </ul>
                    <h2 class="card-subtitle">Coming Soon</h2>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modal-feature-basic" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">Basic Commands</h2>
            <p>Access basic bot commands that allow you to interact with the chat, manage simple tasks, and enhance viewer engagement.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-limited-support" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">Limited Support</h2>
            <p>Get access to limited support to help you with the basic setup and troubleshooting of your bot.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-song-weather" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">!song & !weather Commands</h2>
            <p>Use the !song command to display the current song playing, and the !weather command to get real-time weather updates for your location.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-support" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">Full Support</h2>
            <p>Receive full support for any issues or questions you have about using and configuring your bot. Our team is here to help you every step of the way.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-beta" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">Exclusive Beta Features</h2>
            <p>Gain access to beta features before they are released to the public. Help us test and improve new functionalities.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-personalized-support" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">Personalized Support</h2>
            <p>Receive personalized support tailored to your specific needs, including custom configurations and advanced troubleshooting.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-ai" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">AI Features & Conversations</h2>
            <p>Utilize advanced AI features for more interactive and engaging chat experiences. Includes AI-driven conversations and responses.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<div id="modal-feature-dedicated-bot" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <h2 class="title">Dedicated Bot (Custom Bot Name)</h2>
            <p>Have your own dedicated bot with a custom name that is unique to your channel. Enjoy all future features and custom integrations.</p>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close"></button>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script src="stripe.js"></script>
<script src="modal.js"></script>
</body>
</html>

<!--
<?php if (empty($subscriptionId)): ?>
    <button id="checkout-button" class="button is-primary">Subscribe Now</button>
<?php else: ?>
    <button id="cancel-subscription-button" class="button is-danger">Cancel Subscription</button>
<?php endif; ?>
-->