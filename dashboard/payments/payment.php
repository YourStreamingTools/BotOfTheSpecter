<?php
$stripeSecretKey = ''; // CHANGE TO MAKE THIS WORK

// Include Stripe PHP bindings
require_once('stripe-php/init.php');

\Stripe\Stripe::setApiKey($stripeSecretKey);

header('Content-Type: application/json');

try {
    $DOMAIN = 'https://dashboard.botofthespecter.com/payments';
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'aud',
                'product_data' => [
                    'name' => 'Twitch Bot Paid Feature',
                ],
                'unit_amount' => 800,
            ],
            'quantity' => 1,
        ]],
        'customer_email' => $_SESSION['user_email'],
        'mode' => 'payment',
        'success_url' => $DOMAIN . '/success.php',
        'cancel_url' => $DOMAIN . '/cancel.php',
    ]);

    echo json_encode(['id' => $checkout_session->id]);

} catch (Error $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
