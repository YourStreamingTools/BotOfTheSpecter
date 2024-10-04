<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

require_once('stripe-php/init.php');
$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK
\Stripe\Stripe::setApiKey($stripeSecretKey);

function createStripeCustomer($email, $name) {
    try {
        $customer = \Stripe\Customer::create([
            'email' => $email,
            'name' => $name,
        ]);
        return $customer->id;
    } catch (Exception $e) {
        error_log("Error creating Stripe customer: " . $e->getMessage());
        return null;
    }
}
?>
