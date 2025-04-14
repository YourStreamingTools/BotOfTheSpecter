<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Premium Features";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
date_default_timezone_set($timezone);

// Define plans with features
$plans = [
    '1000' => [
        'name' => 'Twitch Tier 1/Prime',
        'price' => '$5.99 USD',
        'features' => [
            ['text' => '!song Command', 'tip' => 'Use this command to view what song is playing in chat. (NOT REQUIRED FOR SPOTIFY)'],
            ['text' => 'Full Support', 'tip' => 'Get priority assistance and support for any bot-related issues.'],
            ['text' => 'Exclusive Beta Features', 'tip' => 'Early access to new and experimental bot features.'],
            ['text' => '50MB of total storage', 'tip' => 'Store your own sound effects and music files for alerts and walk-on sounds.'],
            ['text' => 'Shared Bot (BotOfTheSpecter)', 'tip' => 'The bot\'s username will be the same across all channels.'] 
        ],
    ],
    '2000' => [
        'name' => 'Twitch Tier 2',
        'price' => '$9.99 USD',
        'features' => [
            ['text' => 'Everything From Tier 1', 'tip' => 'Includes all the benefits of the Tier 1 subscription.'],
            ['text' => 'Personalized Support', 'tip' => 'Receive dedicated one-on-one support for your bot needs.'],
            ['text' => 'AI Features & Conversations', 'tip' => 'Enjoy advanced AI-powered features and interactive conversations in chat.'],
            ['text' => '100MB of total storage', 'tip' => 'Store more of your own sound effects and music files for alerts and walk-on sounds.'],
            ['text' => 'Shared Bot (BotOfTheSpecter)', 'tip' => 'The bot\'s username will be the same across all channels.'] 
        ],
    ],
    '3000' => [
        'name' => 'Twitch Tier 3',
        'price' => '$24.99 USD',
        'features' => [
            ['text' => 'Everything from Tier 2', 'tip' => 'Includes all the benefits of the Tier 2 subscription.'],
            ['text' => '200MB of total storage', 'tip' => 'Store an even larger collection of custom sound effects and music files.'],
            ['text' => 'Dedicated bot (custom bot name) [feature coming soon]', 'tip' => 'Get a dedicated bot instance with a custom name for your channel (coming soon).'] 
        ],
    ],
];

// Check Twitch subscription tier
$currentPlan = 'free'; // Default to free
$error_message = ''; // Initialize error message
// Only fetch subscription tier if the user is not a beta user
if (!$isBetaUser) {
    $twitchSubTier = fetchTwitchSubscriptionTier($access_token, $twitchUserId, $error_message);
    if ($twitchSubTier) {
        // Ensure the tier is treated as a string for comparison
        $twitchSubTierString = (string) $twitchSubTier;
        if (array_key_exists($twitchSubTierString, $plans)) {
            $currentPlan = $twitchSubTierString; 
        }
    } else {
        // Handle the case where no subscription was found or any error occurred
        $error_message = !empty($error_message) ? $error_message : "Unable to determine your subscription status.";
    }
}
// Updated fetch function to return both tier and check if it's a gift
function fetchTwitchSubscriptionTier($access_token, $twitchUserId, &$error_message) {
    $url = "https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id=$twitchUserId";
    $headers = [
        "Authorization: Bearer $access_token",
        "Client-ID: mrjucsmsnri89ifucl66jj1n35jkj8",
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_message = curl_error($ch); // Capture the error message
        curl_close($ch);
        return false; // Return false if an error occurred
    }
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    // Check if the HTTP status is 404 (user not subscribed)
    if ($http_status == 404 && isset($data['message']) && strpos($data['message'], 'does not subscribe') !== false) {
        $error_message = "You are not subscribed or we couldn't find a subscription on Twitch."; // Set an error message
        return false; // No subscription found
    }
    // Check if there's a subscription
    if (isset($data['data']) && count($data['data']) > 0) {
        return $data['data'][0]['tier']; // Return the subscription tier
    }
    // Handle if no subscription found
    $error_message = "You are not subscribed or we couldn't find a subscription on Twitch."; // Set an error message
    return false; // No subscription found
}

// Load beta users from the JSON file
$betaUsersJson = file_get_contents('/var/www/api/authusers.json');
$betaUsersData = json_decode($betaUsersJson, true);
$betaUsers = $betaUsersData['users'] ?? [];
$isBetaUser = in_array($twitchDisplayName, $betaUsers);
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
    <br>
    <?php if (isset($error_message) && !$isBetaUser) { ?><div class="notification is-warning"><?php echo htmlspecialchars($error_message); ?></div><?php } ?>
    <br>
    <h1 class="title">Premium Features</h1>
    <div class="card-container">
        <!-- Free Plan -->
        <div class="card">
            <div class="card-content">
                <h2 class="card-title subtitle has-text-centered">Free Plan<br>$0 USD</h2>
                <ul>
                    <li title="Use basic bot commands in your chat.">Basic Commands</li>
                    <li title="Receive community-based support for basic issues.">Limited Support</li>
                    <li title="Upload your own sound effects and music files to use for alerts and walk-on sounds.">20MB of total storage</li> 
                    <li title="The bot's username will be the same across all channels.">Shared Bot (BotOfTheSpecter)</li>
                </ul>
            </div>
            <?php if ($currentPlan === 'free' && !$isBetaUser): ?>
                <div class="card-footer">
                    <p class="card-footer-item">
                        <span>Current Plan</span>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php foreach ($plans as $planKey => $planDetails): ?>
            <?php $trimmedCurrentPlan = trim((string)$currentPlan); $trimmedPlanKey = trim((string)$planKey); ?>
            <div class="card">
                <div class="card-content">
                    <h2 class="card-title subtitle has-text-centered"><?php echo $planDetails['name']; ?><br><?php echo $planDetails['price']; ?></h2>
                    <ul>
                        <?php foreach ($planDetails['features'] as $feature): ?>
                            <li title="<?php echo $feature['tip']; ?>"><?php echo $feature['text']; ?></li> 
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card-footer">
                    <p class="card-footer-item">
                        <?php if ($isBetaUser): ?>
                            <span>No subscription required for beta users</span>
                        <?php else: ?>
                            <?php if ($trimmedCurrentPlan === $trimmedPlanKey): ?> 
                                <span class="button is-primary">Current Plan</span>
                            <?php else: ?>
                                <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="button is-primary">
                                    <?php if ($currentPlan === 'free') { echo "Subscribe"; } 
                                    elseif ((int)$currentPlan < (int)$planKey) { echo "Upgrade"; } 
                                    else { echo "Downgrade"; } ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endforeach; ?>
        <!-- Show a special plan for beta users -->
        <?php if ($isBetaUser): ?>
            <div class="card">
                <div class="card-content">
                    <h2 class="card-title subtitle has-text-centered">Exclusive Beta Plan<br>Free Access Forever!</h2>
                    <ul>
                        <li title="Enjoy all bot features for free, forever!">All Features FOREVER</li>
                        <li title="Upload your own sound effects and music files to use for alerts and walk-on sounds.">500MB of total storage</li> 
                    </ul>
                </div>
                <div class="card-footer">
                    <p class="card-footer-item">Thank you for helping make BotOfTheSpecter!</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>