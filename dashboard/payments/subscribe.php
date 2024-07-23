<?php
session_start();
require_once('stripe-php/init.php');
require_once "../db_connect.php";

$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK
\Stripe\Stripe::setApiKey($stripeSecretKey);

$customerId = $_SESSION['stripe_customer_id'];

// Define your product and price IDs for each plan
$plans = [
    'free' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
    ],
    'standard' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
    ],
    'premium' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
    ],
    'ultimate' => [
        'product' => '', // CHANGE TO MAKE THIS WORK
        'priceId' => '', // CHANGE TO MAKE THIS WORK
    ]
];

// Assume that the plan type is being sent as a POST request
$selectedPlan = $_POST['plan'] ?? 'free'; // Default to free plan if no plan is selected

if (!array_key_exists($selectedPlan, $plans)) {
    echo json_encode(['error' => 'Invalid plan selected']);
    exit;
}

$product = $plans[$selectedPlan]['product'];
$priceId = $plans[$selectedPlan]['priceId'];

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