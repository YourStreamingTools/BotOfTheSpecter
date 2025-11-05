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
            /* Shared bot name is included in the Free plan; removed from Tier 1 features */
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
            /* Shared bot name is included in the Free plan; removed from Tier 2 features */
        ],
    ],
    '3000' => [
        'name' => t('premium_plan_tier3_name'),
        'price' => t('premium_plan_tier3_price'),
        'features' => [
            ['text' => t('premium_plan_tier3_feature_everything_t2'), 'tip' => t('premium_plan_tier3_feature_everything_t2_tip')],
            ['text' => t('premium_plan_tier3_feature_storage'), 'tip' => t('premium_plan_tier3_feature_storage_tip')],
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
$subscription_message = ''; // Initialize subscription message
// Always fetch subscription tier to show accurate status
$twitchSubTier = fetchTwitchSubscriptionTier($access_token, $twitchUserId, $error_message);
if ($twitchSubTier) {
    // Ensure the tier is treated as a string for comparison
    $twitchSubTierString = (string) $twitchSubTier;
    if (array_key_exists($twitchSubTierString, $plans)) { 
        $currentPlan = $twitchSubTierString;  
        if ($betaAccess) { 
            // Convert tier code to user-friendly name
            $tierName = match($twitchSubTierString) {
                '1000' => 'Tier 1',
                '2000' => 'Tier 2', 
                '3000' => 'Tier 3',
                default => "Tier $twitchSubTierString"
            };
            $subscription_message = "You have beta access and are also subscribed at $tierName."; 
        }
    }
} else {
    // Handle the case where no subscription was found or any error occurred
    if ($betaAccess) { $subscription_message = "You have beta access but are not currently subscribed on Twitch."; }
    else { $error_message = !empty($error_message) ? $error_message : "Unable to determine your subscription status."; }
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
<?php if (isset($error_message) && !empty($error_message) && !$betaAccess): ?>
    <div class="columns is-centered mb-3">
        <div class="column is-8-desktop is-10-tablet">
            <div class="notification is-warning is-light is-size-6 py-3">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="hero is-small">
    <div class="hero-body has-text-centered">
        <div class="container">
            <h1 class="title is-2 has-text-weight-bold">
                <?php echo t('premium_features_title'); ?>
            </h1>
            <!-- Inline Status Badges -->
            <div class="field is-grouped is-grouped-centered">
                <?php if (isset($subscription_message) && !empty($subscription_message)): ?>
                    <div class="control">
                        <span class="tag is-info is-medium has-text-weight-semibold">
                            <span class="icon is-small">
                                <i class="fas fa-crown"></i>
                            </span>
                            <span><?php echo htmlspecialchars($subscription_message); ?></span>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if (isset($error_message) && !empty($error_message) && !$betaAccess): ?>
                    <div class="control">
                        <span class="tag is-warning is-medium has-text-weight-semibold">
                            <span class="icon is-small">
                                <i class="fas fa-exclamation-triangle"></i>
                            </span>
                            <span><?php echo htmlspecialchars($error_message); ?></span>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="hero">
    <div class="hero-body">
        <div class="container has-text-centered">
            <div class="is-centered">
                <div class="box has-background-dark">
                    <div class="content">
                        <h2 class="title is-4 has-text-weight-bold">
                            <span class="icon has-text-info">
                                <i class="fas fa-flask"></i>
                            </span>
                            <?php echo t('premium_experimental_title', 'Experimental Features'); ?>
                        </h2>
                        <h3 class="subtitle is-5 has-text-weight-semibold mb-3">
                            <?php echo t('premium_experimental_subtitle', 'Features may be incomplete or unstable'); ?>
                        </h3>
                        <p class="is-size-6 mb-4">
                            <?php echo t('premium_experimental_description', 'Some premium features are available as experimental. Use with caution.'); ?>
                        </p>
                        <div class="field is-grouped is-grouped-centered">
                            <div class="control">
                                <a href="https://store.botofthespecter.com/en-usd" target="_blank" class="button is-info is-rounded has-text-weight-semibold">
                                    <span class="icon">
                                        <i class="fas fa-store"></i>
                                    </span>
                                    <span><?php echo t('premium_visit_store', 'Visit Our Store'); ?></span>
                                </a>
                            </div>
                        </div>
                        <p class="is-size-7 has-text-grey">
                            <strong><?php echo t('premium_coming_soon_note', 'This feature will be available in version 5.6'); ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="container">
    <div class="columns is-multiline is-variable is-5">
        <!-- Free Plan Card -->
        <div class="column is-12-mobile is-6-tablet is-3-desktop">
            <div class="card has-shadow is-shadowless-mobile" style="height: 100%; border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease; <?php echo ($currentPlan === 'free') ? 'border: 3px solid #00d1b2; box-shadow: 0 8px 16px rgba(0, 209, 178, 0.2);' : ''; ?> position: relative;">
                <?php if ($currentPlan === 'free'): ?>
                    <div class="ribbon is-primary" style="position: absolute; top: 15px; right: -10px; background: linear-gradient(45deg, #00d1b2, #00c4a7); color: white; padding: 8px 20px; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; transform: rotate(12deg); z-index: 10; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); border-radius: 4px;">
                        CURRENT
                    </div>
                <?php endif; ?>
                <div class="card-content" style="height: 100%; display: flex; flex-direction: column;">
                    <div class="has-text-centered mb-4">
                        <div class="icon is-large has-text-grey-light mb-2">
                            <i class="fas fa-rocket fa-2x"></i>
                        </div>
                        <h3 class="title is-4 has-text-weight-bold mb-2">
                            <?php echo t('premium_plan_free_name'); ?>
                        </h3>
                        <p class="subtitle is-5 has-text-weight-semibold has-text-primary">
                            <?php echo t('premium_plan_free_price'); ?>
                        </p>
                    </div>
                    <div class="content" style="flex-grow: 1;">
                        <ul class="is-size-6" style="list-style: none; padding-left: 0;">
                            <li class="mb-2" title="<?php echo t('premium_plan_free_feature_commands_tip'); ?>">
                                <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                <?php echo t('premium_plan_free_feature_commands'); ?>
                            </li>
                            <li class="mb-2" title="<?php echo t('premium_plan_free_feature_support_tip'); ?>">
                                <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                <?php echo t('premium_plan_free_feature_support'); ?>
                            </li>
                            <li class="mb-2" title="<?php echo t('premium_plan_free_feature_storage_tip'); ?>">
                                <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                <?php echo t('premium_plan_free_feature_storage'); ?>
                            </li>
                            <li class="mb-2" title="<?php echo t('premium_plan_free_feature_shared_bot_tip'); ?>">
                                <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                <?php echo t('premium_plan_free_feature_shared_bot'); ?>
                            </li>
                            <li class="mb-2" title="Custom Bot Name - Experimental or Coming Soon">
                                                <span class="icon has-text-warning"><i class="fas fa-flask"></i></span>
                                                Custom Bot Name (Your Custom Bot Name, Experimental/Coming Soon)
                                            </li>
                        </ul>
                        <p class="is-size-7 has-text-grey mt-3 has-text-centered">
                            <strong>90-95% of the bot is FREE!</strong>
                        </p>
                    </div>
                    <?php if ($currentPlan === 'free'): ?>
                        <footer class="mt-4">
                            <?php if ($betaAccess): ?>
                                <span class="button is-info is-fullwidth is-rounded has-text-weight-semibold" style="cursor: default; pointer-events: none; opacity: 0.8;">
                                    <span class="icon"><i class="fas fa-flask"></i></span>
                                    <span>Beta Access</span>
                                </span>
                            <?php else: ?>
                                <span class="button is-success is-fullwidth is-rounded has-text-weight-semibold">
                                    <span class="icon"><i class="fas fa-check-circle"></i></span>
                                    <span><?php echo t('premium_current_plan'); ?></span>
                                </span>
                            <?php endif; ?>
                        </footer>
                    <?php endif; ?>
                </div>
            </div>
        </div>        
        <!-- Premium Plans -->
        <?php foreach ($plans as $planKey => $planDetails): ?>
            <?php 
            $trimmedCurrentPlan = trim((string)$currentPlan); 
            $trimmedPlanKey = trim((string)$planKey);
            $isCurrentPlan = ($trimmedCurrentPlan === $trimmedPlanKey);
            $planIcons = ['1000' => 'fas fa-star', '2000' => 'fas fa-crown', '3000' => 'fas fa-gem'];
            $planColors = ['1000' => 'has-text-info', '2000' => 'has-text-warning', '3000' => 'has-text-danger'];
            ?>
            <div class="column is-12-mobile is-6-tablet is-3-desktop">
                <div class="card has-shadow is-shadowless-mobile" style="height: 100%; border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease; <?php echo $isCurrentPlan ? 'border: 3px solid #00d1b2; box-shadow: 0 8px 16px rgba(0, 209, 178, 0.2);' : ''; ?> position: relative;">
                    <?php if ($isCurrentPlan): ?>
                        <div class="ribbon is-primary" style="position: absolute; top: 15px; right: -10px; background: linear-gradient(45deg, #00d1b2, #00c4a7); color: white; padding: 8px 20px; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; transform: rotate(12deg); z-index: 10; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); border-radius: 4px;">
                            CURRENT
                        </div>
                    <?php endif; ?>
                    <div class="card-content" style="height: 100%; display: flex; flex-direction: column;">
                        <div class="has-text-centered mb-4">
                            <div class="icon is-large <?php echo $planColors[$planKey] ?? 'has-text-primary'; ?> mb-2">
                                <i class="<?php echo $planIcons[$planKey] ?? 'fas fa-star'; ?> fa-2x"></i>
                            </div>
                            <h3 class="title is-4 has-text-weight-bold mb-2">
                                <?php echo htmlspecialchars($planDetails['name']); ?>
                            </h3>
                            <p class="subtitle is-5 has-text-weight-semibold has-text-primary">
                                <?php echo htmlspecialchars($planDetails['price']); ?>
                            </p>
                        </div>
                        <div class="content" style="flex-grow: 1;">
                            <ul class="is-size-6" style="list-style: none; padding-left: 0;">
                                <?php foreach ($planDetails['features'] as $feature): ?>
                                    <li class="mb-2" title="<?php echo htmlspecialchars($feature['tip']); ?>">
                                        <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                        <?php echo htmlspecialchars($feature['text']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <footer class="mt-4">
                            <?php if ($betaAccess): ?>
                                <?php if ($isCurrentPlan): ?>
                                    <span class="button is-success is-fullwidth is-rounded has-text-weight-semibold" style="cursor: default; pointer-events: none; opacity: 0.8;">
                                        <span class="icon"><i class="fas fa-check-circle"></i></span>
                                        <span><?php echo t('premium_current_plan'); ?> + Beta</span>
                                    </span>
                                <?php else: ?>
                                    <span class="button is-info is-fullwidth is-rounded has-text-weight-semibold" style="cursor: default; pointer-events: none; opacity: 0.8;">
                                        <span class="icon"><i class="fas fa-flask"></i></span>
                                        <span>Beta Access</span>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($isCurrentPlan): ?>
                                    <span class="button is-success is-fullwidth is-rounded has-text-weight-semibold">
                                        <span class="icon"><i class="fas fa-check-circle"></i></span>
                                        <span><?php echo t('premium_current_plan'); ?></span>
                                    </span>
                                <?php else: ?>
                                    <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="button is-primary is-fullwidth is-rounded has-text-weight-semibold" style="transition: all 0.2s ease;">
                                        <span class="icon">
                                            <?php if ($currentPlan === 'free'): ?>
                                                <i class="fas fa-plus-circle"></i>
                                            <?php elseif ((int)$currentPlan < (int)$planKey): ?>
                                                <i class="fas fa-arrow-up"></i>
                                            <?php else: ?>
                                                <i class="fas fa-arrow-down"></i>
                                            <?php endif; ?>
                                        </span>
                                        <span>
                                            <?php
                                            if ($currentPlan === 'free') {
                                                echo t('premium_subscribe');
                                            } elseif ((int)$currentPlan < (int)$planKey) {
                                                echo t('premium_upgrade');
                                            } else {
                                                echo t('premium_downgrade');
                                            }
                                            ?>
                                        </span>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </footer>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <!-- Special Beta Plan Card (if beta user) -->
    <?php if ($betaAccess): ?>
    <div class="columns is-centered mt-5">
        <div class="column is-12-mobile is-6-tablet is-3-desktop">
            <div class="card has-shadow is-shadowless-mobile" style="height: 100%; border-radius: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; position: relative; border: 3px solid #00d1b2; box-shadow: 0 8px 16px rgba(0, 209, 178, 0.2);">
                <div class="ribbon is-primary" style="position: absolute; top: 15px; right: -10px; background: linear-gradient(45deg, #00d1b2, #00c4a7); color: white; padding: 8px 20px; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; transform: rotate(12deg); z-index: 10; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); border-radius: 4px;">
                    CURRENT
                </div>
                <div class="card-content" style="height: 100%; display: flex; flex-direction: column;">
                        <div class="has-text-centered mb-4">
                            <div class="icon is-large has-text-white mb-2">
                                <i class="fas fa-flask fa-2x"></i>
                            </div>
                            <h3 class="title is-4 has-text-white has-text-weight-bold mb-2">
                                <?php echo t('premium_beta_plan_name'); ?>
                            </h3>
                            <p class="subtitle is-5 has-text-white-ter has-text-weight-semibold">
                                <?php echo t('premium_beta_plan_price'); ?>
                            </p>
                        </div>
                        <div class="content" style="flex-grow: 1;">
                            <ul class="is-size-6" style="list-style: none; padding-left: 0;">
                                <li class="mb-2" title="<?php echo t('premium_beta_plan_feature_all_tip'); ?>">
                                    <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                    <?php echo t('premium_beta_plan_feature_all'); ?>
                                </li>
                                <li class="mb-2" title="<?php echo t('premium_beta_plan_feature_storage_tip'); ?>">
                                    <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                    <?php echo t('premium_beta_plan_feature_storage'); ?>
                                </li>
                            </ul>
                        </div>
                        <footer class="mt-4 has-text-centered">
                            <span class="is-fullwidth is-rounded has-text-weight-semibold" style="cursor: default; pointer-events: none; opacity: 0.8; white-space: normal; height: auto; min-height: 2.5em; padding: 0.75em;">
                                <span><?php echo t('premium_beta_plan_footer'); ?></span>
                            </span>
                        </footer>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include 'layout.php';
?>