<?php
session_start();
require_once('stripe-php/init.php');
require_once "../db_connect.php";

// Set the secret key
$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK
\Stripe\Stripe::setApiKey($stripeSecretKey);

// Retrieve the customer ID from the session
$customerId = $_SESSION['stripe_customer_id'];
$userId = $_SESSION['user_id'];

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

// Retrieve the selected plan from POST data
$selectedPlan = $_POST['plan'] ?? null;

if (!$selectedPlan || !array_key_exists($selectedPlan, $plans)) {
    die('Invalid plan selected');
}

$priceId = $plans[$selectedPlan]['priceId'];

// Retrieve the user's current subscription ID from the database
$userSTMT = $conn->prepare("SELECT subscription_id FROM users WHERE id = ?");
$userSTMT->bind_param("i", $userId);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$subscriptionId = $user['subscription_id'];

try {
    if (!empty($subscriptionId)) {
        // Update the existing subscription
        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
        $updatedSubscription = \Stripe\Subscription::update($subscriptionId, [
            'cancel_at_period_end' => false,
            'items' => [
                [
                    'id' => $subscription->items->data[0]->id,
                    'price' => $priceId,
                ],
            ],
            'proration_behavior' => 'create_prorations',
        ]);
        // Redirect to success page
        header('Location: index.php?status=success');
        exit();
    } else {
        // Create a new subscription through Checkout Session
        $checkout_session = \Stripe\Checkout\Session::create([
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => 'https://dashboard.botofthespecter.com/payments/success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://dashboard.botofthespecter.com/payments/cancel.php',
        ]);
        // Redirect the user to the Stripe Checkout page
        header("Location: " . $checkout_session->url);
        exit();
    }
} catch (Exception $e) {
    // Handle errors (you may want to log these errors)
    die('Error processing subscription: ' . $e->getMessage());
}
?>