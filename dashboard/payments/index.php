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

<section class="section">
    <div class="container">
        <h1 class="title">Pay for Bot Features</h1>
        <p>Welcome, <?php echo htmlspecialchars($twitchDisplayName); ?>!</p>
        <img src="<?php echo htmlspecialchars($profileImageUrl); ?>" alt="Profile Image" width="100">
        <p>Email: <?php echo htmlspecialchars($userEmail); ?></p>

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

        <button id="checkout-button" class="button is-primary">Checkout</button>
    </div>
</section>

<script src="https://js.stripe.com/v3/"></script>
<script src="scripts.js"></script>
</body>
</html>