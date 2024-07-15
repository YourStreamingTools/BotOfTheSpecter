<?php
session_start();
require_once('stripe-php/init.php');
require_once "../db_connect.php";

\Stripe\Stripe::setApiKey(''); // CHANGE TO MAKE THIS WORK

$customerId = $_SESSION['stripe_customer_id'];
$product = ''; // CHANGE TO MAKE THIS WORK
$priceId = ''; // CHANGE TO MAKE THIS WORK

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'customer' => $customerId,
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price' => $priceId,
            'quantity' => 1,
        ]],
        'mode' => 'subscription',
        'success_url' => 'https://dashboard.botofthespecter.com/payments/success.php',
        'cancel_url' => 'https://dashboard.botofthespecter.com/payments/cancel.php',
    ]);

    echo json_encode(['id' => $checkout_session->id]);

} catch (Error $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>