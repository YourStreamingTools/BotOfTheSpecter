<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('premium_features_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Define plans with features
$plans = [
    '1000' => [
        'name' => t('premium_plan_tier1_name'),
        'price' => t('premium_plan_tier1_price'),
        'features' => [
            ['text' => t('premium_plan_tier1_feature_song_command'), 'tip' => t('premium_plan_tier1_feature_song_command_tip')],
            ['text' => t('premium_plan_tier1_feature_support'), 'tip' => t('premium_plan_tier1_feature_support_tip')],
            ['text' => t('premium_plan_tier1_feature_beta'), 'tip' => t('premium_plan_tier1_feature_beta_tip')],
            ['text' => t('premium_plan_tier1_feature_storage'), 'tip' => t('premium_plan_tier1_feature_storage_tip')],
            ['text' => t('premium_plan_tier1_feature_shared_bot'), 'tip' => t('premium_plan_tier1_feature_shared_bot_tip')],
        ],
    ],
    '2000' => [
        'name' => t('premium_plan_tier2_name'),
        'price' => t('premium_plan_tier2_price'),
        'features' => [
            ['text' => t('premium_plan_tier2_feature_everything_t1'), 'tip' => t('premium_plan_tier2_feature_everything_t1_tip')],
            ['text' => t('premium_plan_tier2_feature_personal_support'), 'tip' => t('premium_plan_tier2_feature_personal_support_tip')],
            ['text' => t('premium_plan_tier2_feature_ai'), 'tip' => t('premium_plan_tier2_feature_ai_tip')],
            ['text' => t('premium_plan_tier2_feature_storage'), 'tip' => t('premium_plan_tier2_feature_storage_tip')],
            ['text' => t('premium_plan_tier2_feature_shared_bot'), 'tip' => t('premium_plan_tier2_feature_shared_bot_tip')],
        ],
    ],
    '3000' => [
        'name' => t('premium_plan_tier3_name'),
        'price' => t('premium_plan_tier3_price'),
        'features' => [
            ['text' => t('premium_plan_tier3_feature_everything_t2'), 'tip' => t('premium_plan_tier3_feature_everything_t2_tip')],
            ['text' => t('premium_plan_tier3_feature_storage'), 'tip' => t('premium_plan_tier3_feature_storage_tip')],
            ['text' => t('premium_plan_tier3_feature_dedicated_bot'), 'tip' => t('premium_plan_tier3_feature_dedicated_bot_tip')],
        ],
    ],
];

// Check beta access from database
$betaAccess = false; // Default to false
if (isset($twitchDisplayName) && !empty($twitchDisplayName)) {
    try {
        $stmt = $conn->prepare("SELECT beta_access FROM users WHERE twitch_display_name = ?");
        if ($stmt) {
            $stmt->bind_param("s", $twitchDisplayName);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) { $betaAccess = ($row['beta_access'] == 1); } // Set beta access based on database value
            $stmt->close();
        }
    } catch (Exception $e) {
        // Log error but continue with default beta access = false
        error_log("Error checking beta access for user $twitchDisplayName: " . $e->getMessage());
    }
}

// Check Twitch subscription tier
$currentPlan = 'free'; // Default to free
$error_message = ''; // Initialize error message
// Only fetch subscription tier if the user is not a beta user
if (!$betaAccess) {
    $twitchSubTier = fetchTwitchSubscriptionTier($access_token, $twitchUserId, $error_message);
    if ($twitchSubTier) {
        // Ensure the tier is treated as a string for comparison
        $twitchSubTierString = (string) $twitchSubTier;
        if (array_key_exists($twitchSubTierString, $plans)) { $currentPlan = $twitchSubTierString;  }
    } else {
        // Handle the case where no subscription was found or any error occurred
        $error_message = !empty($error_message) ? $error_message : "Unable to determine your subscription status.";
    }
}
// Updated fetch function to return both tier and check if it's a gift
function fetchTwitchSubscriptionTier($access_token, $twitchUserId, &$error_message) {
    // Validate input parameters
    if (empty($access_token) || empty($twitchUserId)) { $error_message = "Missing required parameters for subscription check."; return false; }
    $url = "https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id=$twitchUserId";
    $headers = ["Authorization: Bearer $access_token","Client-ID: mrjucsmsnri89ifucl66jj1n35jkj8",];
    $ch = curl_init();
    if (!$ch) { $error_message = "Failed to initialize curl."; return false; }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Add timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Add connection timeout
    $response = curl_exec($ch);
    // Check for cURL errors
    if (curl_errno($ch)) { $error_message = "Connection error: " . curl_error($ch); curl_close($ch); return false; }
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Validate response
    if ($response === false) { $error_message = "Failed to get response from Twitch API."; return false; }
    $data = json_decode($response, true);
    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) { $error_message = "Invalid response format from Twitch API."; return false; }
    // Check if the HTTP status is 404 (user not subscribed)
    if ($http_status == 404 && isset($data['message']) && strpos($data['message'], 'does not subscribe') !== false) {
        $error_message = "You are not subscribed or we couldn't find a subscription on Twitch."; return false;
    }
    // Check for other HTTP errors
    if ($http_status >= 400) { $error_message = "API error (HTTP $http_status): " . ($data['message'] ?? 'Unknown error'); return false; }
    // Check if there's a subscription
    if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) { return $data['data'][0]['tier']; } // Return the subscription tier
    // Handle if no subscription found
    $error_message = "You are not subscribed or we couldn't find a subscription on Twitch.";
    return false;
}

// Start output buffering for layout
ob_start();
?>
<?php if (isset($error_message) && !$betaAccess): ?>
    <div class="notification is-warning is-light mb-5">
        <?php echo htmlspecialchars($error_message); ?>
    </div>
<?php endif; ?>
<h1 class="title has-text-centered mb-6"><?php echo t('premium_features_title'); ?></h1>
<div class="columns is-multiline is-variable is-6 card-container is-flex is-flex-wrap-wrap">
    <!-- Free Plan -->
    <div class="column is-12-mobile is-6-tablet is-3-desktop is-flex">
        <div class="card h-100 is-flex is-flex-direction-column" style="width:100%;">
            <div class="card-content">
                <h2 class="subtitle has-text-centered mb-4"><?php echo t('premium_plan_free_name'); ?><br><span class="has-text-weight-bold"><?php echo t('premium_plan_free_price'); ?></span></h2>
                <ul>
                    <li title="<?php echo t('premium_plan_free_feature_basic_commands_tip'); ?>"><?php echo t('premium_plan_free_feature_basic_commands'); ?></li>
                    <li title="<?php echo t('premium_plan_free_feature_support_tip'); ?>"><?php echo t('premium_plan_free_feature_support'); ?></li>
                    <li title="<?php echo t('premium_plan_free_feature_storage_tip'); ?>"><?php echo t('premium_plan_free_feature_storage'); ?></li>
                    <li title="<?php echo t('premium_plan_free_feature_shared_bot_tip'); ?>"><?php echo t('premium_plan_free_feature_shared_bot'); ?></li>
                </ul>
            </div>
            <?php if ($currentPlan === 'free' && !$betaAccess): ?>
                <footer class="card-footer mt-auto">
                    <span class="card-footer-item has-text-success has-text-weight-semibold"><?php echo t('premium_current_plan'); ?></span>
                </footer>
            <?php endif; ?>
        </div>
    </div>
    <?php foreach ($plans as $planKey => $planDetails): ?>
        <?php $trimmedCurrentPlan = trim((string)$currentPlan); $trimmedPlanKey = trim((string)$planKey); ?>
        <div class="column is-12-mobile is-6-tablet is-3-desktop is-flex">
            <div class="card h-100 is-flex is-flex-direction-column" style="width:100%;">
                <div class="card-content">
                    <h2 class="subtitle has-text-centered mb-4">
                        <?php echo htmlspecialchars($planDetails['name']); ?><br>
                        <span class="has-text-weight-bold">
                            <?php echo htmlspecialchars($planDetails['price']); ?>
                        </span>
                    </h2>
                    <ul>
                        <?php foreach ($planDetails['features'] as $feature): ?>
                            <li title="<?php echo htmlspecialchars($feature['tip']); ?>">
                                <?php echo htmlspecialchars($feature['text']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <footer class="card-footer mt-auto">
                    <?php if ($betaAccess): ?>
                        <span class="card-footer-item has-text-info"><?php echo t('premium_beta_no_subscription'); ?></span>
                    <?php else: ?>
                        <?php if ($trimmedCurrentPlan === $trimmedPlanKey): ?>
                            <span class="card-footer-item">
                                <span class="button is-primary is-light is-fullwidth"><?php echo t('premium_current_plan'); ?></span>
                            </span>
                        <?php else: ?>
                            <span class="card-footer-item">
                                <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="button is-primary is-fullwidth">
                                    <?php
                                    if ($currentPlan === 'free') {
                                        echo t('premium_subscribe');
                                    } elseif ((int)$currentPlan < (int)$planKey) {
                                        echo t('premium_upgrade');
                                    } else {
                                        echo t('premium_downgrade');
                                    }
                                    ?>
                                </a>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </footer>
            </div>
        </div>
    <?php endforeach; ?>
    <!-- Show a special plan for beta users -->
    <?php if ($betaAccess): ?>
        <div class="column is-12 is-flex is-justify-content-center is-align-items-center" style="flex-basis:100%;padding-top:2rem;">
            <div class="card h-100 is-flex is-flex-direction-column" style="width:350px;max-width:100%;">
                <div class="card-content">
                    <h2 class="subtitle has-text-centered mb-4"><?php echo t('premium_beta_plan_name'); ?><br>
                        <span class="has-text-weight-bold"><?php echo t('premium_beta_plan_price'); ?></span>
                    </h2>
                    <ul>
                        <li title="<?php echo t('premium_beta_plan_feature_all_tip'); ?>"><?php echo t('premium_beta_plan_feature_all'); ?></li>
                        <li title="<?php echo t('premium_beta_plan_feature_storage_tip'); ?>"><?php echo t('premium_beta_plan_feature_storage'); ?></li>
                    </ul>
                </div>
                <footer class="card-footer mt-auto">
                    <span class="card-footer-item has-text-success has-text-centered"><?php echo t('premium_beta_plan_footer'); ?></span>
                </footer>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include 'layout.php';
?>