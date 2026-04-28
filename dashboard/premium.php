<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
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
session_write_close();
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

// Check Twitch subscription tier by calling check_subscription.php
$currentPlan = 'free'; // Default to free
$error_message = ''; // Initialize error message
$subscription_message = ''; // Initialize subscription message

// Make internal request to check subscription
$checkUrl = "https://dashboard.botofthespecter.com/check_subscription.php";
$sessionCookie = session_name() . '=' . session_id();
session_write_close();
$ch = curl_init($checkUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$subResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_write_close();

if ($subResponse !== false && $httpCode === 200) {
    $subData = json_decode($subResponse, true);
    if (isset($subData['subscribed']) && $subData['subscribed'] === true) {
        $twitchSubTierString = (string) $subData['tier'];
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
        // No subscription found
        if ($betaAccess) { 
            $subscription_message = "You have beta access but are not currently subscribed on Twitch."; 
        } else { 
            $error_message = "You are not subscribed or we couldn't find a subscription on Twitch."; 
        }
    }
} else {
    // API call failed
    if (!$betaAccess) {
        $error_message = "Unable to determine your subscription status.";
        if ($subResponse !== false) {
            $subData = json_decode($subResponse, true);
            if (is_array($subData) && !empty($subData['error'])) {
                $error_message .= " Details: " . $subData['error'];
            }
        } elseif (!empty($curlError)) {
            $error_message .= " Details: $curlError";
        }
    }
}

// Start output buffering for layout
ob_start();
?>
<?php if (isset($error_message) && !empty($error_message) && !$betaAccess): ?>
    <div class="sp-alert sp-alert-warning" style="margin-bottom: 1.5rem;">
        <i class="fas fa-exclamation-triangle"></i>
        <?php echo htmlspecialchars($error_message); ?>
    </div>
<?php endif; ?>
<div class="sp-plan-header">
    <h1 class="sp-plan-page-title"><?php echo t('premium_features_title'); ?></h1>
    <div class="sp-plan-status-badges">
        <?php if (isset($subscription_message) && !empty($subscription_message)): ?>
            <span class="sp-badge sp-badge-blue">
                <i class="fas fa-crown"></i>
                <?php echo htmlspecialchars($subscription_message); ?>
            </span>
        <?php endif; ?>
        <?php if (isset($error_message) && !empty($error_message) && !$betaAccess): ?>
            <span class="sp-badge sp-badge-amber">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </span>
        <?php endif; ?>
    </div>
</div>
<div class="sp-plan-grid">
    <!-- Free Plan Card -->
    <div class="sp-card sp-plan-card <?php echo ($currentPlan === 'free') ? 'is-current' : ''; ?>">
        <?php if ($currentPlan === 'free'): ?>
            <div class="sp-plan-current-ribbon">CURRENT</div>
        <?php endif; ?>
        <div class="sp-card-body sp-plan-body">
            <div class="sp-plan-icon-area">
                <div class="sp-plan-icon-wrap">
                    <i class="fas fa-rocket sp-plan-icon" style="color: var(--text-muted);"></i>
                </div>
                <h3 class="sp-plan-name"><?php echo t('premium_plan_free_name'); ?></h3>
                <p class="sp-plan-price"><?php echo t('premium_plan_free_price'); ?></p>
            </div>
            <ul class="sp-plan-features">
                <li title="<?php echo t('premium_plan_free_feature_commands_tip'); ?>">
                    <i class="fas fa-check sp-plan-feature-icon"></i>
                    <?php echo t('premium_plan_free_feature_commands'); ?>
                </li>
                <li title="<?php echo t('premium_plan_free_feature_support_tip'); ?>">
                    <i class="fas fa-check sp-plan-feature-icon"></i>
                    <?php echo t('premium_plan_free_feature_support'); ?>
                </li>
                <li title="<?php echo t('premium_plan_free_feature_storage_tip'); ?>">
                    <i class="fas fa-check sp-plan-feature-icon"></i>
                    <?php echo t('premium_plan_free_feature_storage'); ?>
                </li>
                <li title="<?php echo t('premium_plan_free_feature_shared_bot_tip'); ?>">
                    <i class="fas fa-check sp-plan-feature-icon"></i>
                    <?php echo t('premium_plan_free_feature_shared_bot'); ?>
                </li>
                <li title="Custom Bot Name - Experimental or Coming Soon">
                    <i class="fas fa-flask sp-plan-feature-icon--amber"></i>
                    Custom Bot Name (Your Custom Bot Name, Experimental/Coming Soon)
                </li>
            </ul>
            <p class="sp-plan-note"><strong>90-95% of the bot is FREE!</strong></p>
            <?php if ($currentPlan === 'free'): ?>
                <?php if ($betaAccess): ?>
                    <span class="sp-btn sp-btn-info sp-btn-block" style="cursor: default; pointer-events: none; opacity: 0.8;">
                        <i class="fas fa-flask"></i> Beta Access
                    </span>
                <?php else: ?>
                    <span class="sp-btn sp-btn-success sp-btn-block" style="cursor: default; pointer-events: none;">
                        <i class="fas fa-check-circle"></i> <?php echo t('premium_current_plan'); ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <!-- Premium Plans -->
    <?php foreach ($plans as $planKey => $planDetails): ?>
        <?php 
        $trimmedCurrentPlan = trim((string)$currentPlan); 
        $trimmedPlanKey = trim((string)$planKey);
        $isCurrentPlan = ($trimmedCurrentPlan === $trimmedPlanKey);
        $planIcons = ['1000' => 'fas fa-star', '2000' => 'fas fa-crown', '3000' => 'fas fa-gem'];
        $planIconColors = ['1000' => 'var(--blue)', '2000' => 'var(--amber)', '3000' => 'var(--red)'];
        ?>
        <div class="sp-card sp-plan-card <?php echo $isCurrentPlan ? 'is-current' : ''; ?>">
            <?php if ($isCurrentPlan): ?>
                <div class="sp-plan-current-ribbon">CURRENT</div>
            <?php endif; ?>
            <div class="sp-card-body sp-plan-body">
                <div class="sp-plan-icon-area">
                    <div class="sp-plan-icon-wrap">
                        <i class="<?php echo $planIcons[$planKey] ?? 'fas fa-star'; ?> sp-plan-icon" style="color: <?php echo $planIconColors[$planKey] ?? 'var(--accent-hover)'; ?>;"></i>
                    </div>
                    <h3 class="sp-plan-name"><?php echo htmlspecialchars($planDetails['name']); ?></h3>
                    <p class="sp-plan-price"><?php echo htmlspecialchars($planDetails['price']); ?></p>
                </div>
                <ul class="sp-plan-features">
                    <?php foreach ($planDetails['features'] as $feature): ?>
                        <li title="<?php echo htmlspecialchars($feature['tip']); ?>">
                            <i class="fas fa-check sp-plan-feature-icon"></i>
                            <?php echo htmlspecialchars($feature['text']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div>
                    <?php if ($betaAccess): ?>
                        <?php if ($isCurrentPlan): ?>
                            <span class="sp-btn sp-btn-success sp-btn-block" style="cursor: default; pointer-events: none; opacity: 0.8;">
                                <i class="fas fa-check-circle"></i> <?php echo t('premium_current_plan'); ?> + Beta
                            </span>
                        <?php else: ?>
                            <span class="sp-btn sp-btn-info sp-btn-block" style="cursor: default; pointer-events: none; opacity: 0.8;">
                                <i class="fas fa-flask"></i> Beta Access
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($isCurrentPlan): ?>
                            <span class="sp-btn sp-btn-success sp-btn-block" style="cursor: default; pointer-events: none;">
                                <i class="fas fa-check-circle"></i> <?php echo t('premium_current_plan'); ?>
                            </span>
                        <?php else: ?>
                            <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="sp-btn sp-btn-primary sp-btn-block">
                                <?php if ($currentPlan === 'free'): ?>
                                    <i class="fas fa-plus-circle"></i>
                                <?php elseif ((int)$currentPlan < (int)$planKey): ?>
                                    <i class="fas fa-arrow-up"></i>
                                <?php else: ?>
                                    <i class="fas fa-arrow-down"></i>
                                <?php endif; ?>
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
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<!-- Special Beta Plan Card (if beta user) -->
<?php if ($betaAccess): ?>
<div class="sp-plan-beta-wrapper">
    <div class="sp-card sp-plan-card is-current sp-plan-beta-card">
        <div class="sp-plan-current-ribbon">CURRENT</div>
        <div class="sp-card-body sp-plan-body">
            <div class="sp-plan-icon-area">
                <div class="sp-plan-icon-wrap">
                    <i class="fas fa-flask sp-plan-icon" style="color: #fff;"></i>
                </div>
                <h3 class="sp-plan-name"><?php echo t('premium_beta_plan_name'); ?></h3>
                <p class="sp-plan-price"><?php echo t('premium_beta_plan_price'); ?></p>
            </div>
            <ul class="sp-plan-features">
                <li title="<?php echo t('premium_beta_plan_feature_all_tip'); ?>">
                    <i class="fas fa-check sp-plan-feature-icon"></i>
                    <?php echo t('premium_beta_plan_feature_all'); ?>
                </li>
                <li title="<?php echo t('premium_beta_plan_feature_storage_tip'); ?>">
                    <i class="fas fa-check sp-plan-feature-icon"></i>
                    <?php echo t('premium_beta_plan_feature_storage'); ?>
                </li>
            </ul>
            <div class="sp-plan-beta-footer">
                <span><?php echo t('premium_beta_plan_footer'); ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include 'layout.php';
?>