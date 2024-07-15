<?php
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Retrieve user information from session
$userEmail = $_SESSION['user_email'];
$twitchDisplayName = $_SESSION['twitch_display_name'];
$profileImageUrl = $_SESSION['profile_image_url'];

// Check for status messages
$status = isset($_GET['status']) ? $_GET['status'] : null;
$title = "Payments";
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

    <script src="https://js.stripe.com/v3/"></>
    <script src="scripts.js"></script>
</body>
</html>