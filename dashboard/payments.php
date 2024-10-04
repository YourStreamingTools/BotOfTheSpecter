<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Payments";

// Connect to database
require_once "db_connect.php";

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
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// Define plans with features
$plans = [
    '1000' => [
        'name' => 'Twitch Tier 1',
        'price' => '$5.99 USD',
        'features' => [
            '!song & !weather Commands',
            'Full Support',
            'Exclusive Beta Features',
            'Shared Bot (BotOfTheSpecter)',
        ],
    ],
    '2000' => [
        'name' => 'Twitch Tier 2',
        'price' => '$9.99 USD',
        'features' => [
            'Everything From Standard Plan',
            'Personalized Support',
            'AI Features & Conversations',
            'Shared Bot (BotOfTheSpecter)',
        ],
    ],
    '3000' => [
        'name' => 'Twitch Tier 3',
        'price' => '$24.99 USD',
        'features' => [
            'Everything from Premium Plan',
            'Dedicated bot (custom bot name)',
        ],
    ],
];

// Check Twitch subscription tier
$currentPlan = 'free'; // Default to free
$twitchSubTier = fetchTwitchSubscriptionTier($authToken, $twitchUserId);
if ($twitchSubTier) {
    // Ensure the tier is treated as a string for comparison
    $twitchSubTierString = (string) $twitchSubTier;
    if (array_key_exists($twitchSubTierString, $plans)) {
        $currentPlan = $twitchSubTierString; 
    }
}
function fetchTwitchSubscriptionTier($token, $twitchUserId) {
    $url = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=140296994&user_id=$twitchUserId";
    $headers = [
        "Authorization: Bearer $token",
        "Client-ID: mrjucsmsnri89ifucl66jj1n35jkj8",
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data['data']) && count($data['data']) > 0) {
        return $data['data'][0]['tier']; // Return the subscription tier
    }
    return false; // No subscription found
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Header -->
    <?php include('header.php'); ?>
    <link rel="stylesheet" href="../css/payments.css">
    <!-- /Header -->
</head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$profileImageUrl' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <h1 class="title">Premium Features</h1>
    <div class="card-container">
        <!-- Free Plan -->
        <div class="card">
            <div class="card-content">
                <h2 class="card-title subtitle">Free Plan<br>$0 USD</h2>
                <ul>
                    <li>Basic Commands</li>
                    <li>Limited Support</li>
                </ul>
            </div>
            <?php if ($currentPlan === 'free'): ?>
                <div class="card-footer">
                    <p class="card-footer-item">
                        <span>Current Plan</span>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php foreach ($plans as $planKey => $planDetails): ?>
            <?php $trimmedCurrentPlan = trim((string)$currentPlan); $trimmedPlanKey = trim((string)$planKey);?>
            <div class="card">
                <div class="card-content">
                    <h2 class="card-title subtitle"><?php echo $planDetails['name']; ?><br><?php echo $planDetails['price']; ?></h2>
                    <ul>
                        <?php foreach ($planDetails['features'] as $feature): ?>
                            <li><?php echo $feature; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php if ($trimmedCurrentPlan === $trimmedPlanKey): ?> 
                    <div class="card-footer">
                        <p class="card-footer-item">
                            <span>Current Plan</span> 
                        </p> 
                    </div>
                <?php else: ?>
                    <div class="card-footer">
                        <p class="card-footer-item">
                            <span>Not Subscribed</span> 
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="modal.js"></script>
</body>
</html>