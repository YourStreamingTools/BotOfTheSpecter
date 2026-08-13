<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('premium_features_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

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

function premium_fetch_subscription_status(array $plans, bool $betaAccess): array
{
    $currentPlan = 'free';
    $error_message = '';
    $subscription_message = '';

    $checkUrl = "https://dashboard.botofthespecter.com/api/check_subscription.php";
    $sessionCookie = session_name() . '=' . session_id();
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $subResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($subResponse !== false && $httpCode === 200) {
        $subData = json_decode($subResponse, true);
        if (isset($subData['subscribed']) && $subData['subscribed'] === true) {
            $twitchSubTierString = (string) $subData['tier'];
            if (array_key_exists($twitchSubTierString, $plans)) {
                $currentPlan = $twitchSubTierString;
                if ($betaAccess) {
                    $tierName = match ($twitchSubTierString) {
                        '1000' => t('premium_tier1_label'),
                        '2000' => t('premium_tier2_label'),
                        '3000' => t('premium_tier3_label'),
                        default => t('premium_tier_generic', [$twitchSubTierString])
                    };
                    $subscription_message = t('premium_subscription_beta_subscribed', [$tierName]);
                }
            }
        } elseif ($betaAccess) {
            $subscription_message = t('premium_subscription_beta_unsubscribed');
        } else {
            $error_message = t('premium_subscription_not_subscribed');
        }
    } elseif (!$betaAccess) {
        $error_message = t('premium_subscription_unknown');
        if ($subResponse !== false) {
            $subData = json_decode($subResponse, true);
            if (is_array($subData) && !empty($subData['error'])) {
                $error_message .= ' ' . t('premium_subscription_error_details', [$subData['error']]);
            }
        } elseif (!empty($curlError)) {
            $error_message .= ' ' . t('premium_subscription_error_details', [$curlError]);
        }
    }

    return [
        'current_plan' => $currentPlan,
        'beta_access' => $betaAccess,
        'subscription_message' => $subscription_message,
        'error_message' => $error_message,
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch subscription.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        echo json_encode(array_merge(['success' => true], premium_fetch_subscription_status($plans, (bool) $betaAccess)));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Start output buffering for layout
ob_start();
?>
<div id="premiumAlertHost" aria-busy="true"></div>
<div class="sp-plan-header">
    <h1 class="sp-plan-page-title"><?php echo t('premium_features_title'); ?></h1>
    <div class="sp-plan-status-badges" id="premiumStatusBadges" aria-busy="true">
        <span class="sp-skeleton-badge" aria-hidden="true"></span>
        <span class="sp-skeleton-badge" aria-hidden="true"></span>
    </div>
</div>
<div class="sp-plan-grid">
    <!-- Free Plan Card -->
    <div class="sp-card sp-plan-card" data-plan-key="free">
        <div class="sp-plan-current-ribbon" hidden><?php echo t('premium_current_ribbon'); ?></div>
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
                <li title="<?php echo htmlspecialchars(t('premium_plan_free_feature_custom_bot_tip')); ?>">
                    <i class="fas fa-flask sp-plan-feature-icon--amber"></i>
                    <?php echo t('premium_plan_free_feature_custom_bot'); ?>
                </li>
            </ul>
            <p class="sp-plan-note"><?php echo t('premium_plan_free_note'); ?></p>
            <div class="premium-plan-action" aria-busy="true">
                <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
            </div>
        </div>
    </div>
    <!-- Premium Plans -->
    <?php foreach ($plans as $planKey => $planDetails): ?>
        <?php
        $planIcons = ['1000' => 'fas fa-star', '2000' => 'fas fa-crown', '3000' => 'fas fa-gem'];
        $planIconColors = ['1000' => 'var(--blue)', '2000' => 'var(--amber)', '3000' => 'var(--red)'];
        ?>
        <div class="sp-card sp-plan-card" data-plan-key="<?php echo htmlspecialchars($planKey); ?>">
            <div class="sp-plan-current-ribbon" hidden><?php echo t('premium_current_ribbon'); ?></div>
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
                <div class="premium-plan-action" aria-busy="true">
                    <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<!-- Special Beta Plan Card (if beta user) -->
<?php if ($betaAccess): ?>
<div class="sp-plan-beta-wrapper">
    <div class="sp-card sp-plan-card is-current sp-plan-beta-card">
        <div class="sp-plan-current-ribbon"><?php echo t('premium_current_ribbon'); ?></div>
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

ob_start();
?>
<script>
const PREMIUM_I18N = {
    betaAccess: <?php echo !empty($betaAccess) ? 'true' : 'false'; ?>,
    currentPlan: <?php echo json_encode(t('premium_current_plan')); ?>,
    plusBeta: <?php echo json_encode(t('premium_plus_beta')); ?>,
    betaAccessLabel: <?php echo json_encode(t('premium_beta_access')); ?>,
    subscribe: <?php echo json_encode(t('premium_subscribe')); ?>,
    upgrade: <?php echo json_encode(t('premium_upgrade')); ?>,
    downgrade: <?php echo json_encode(t('premium_downgrade')); ?>,
    unknown: <?php echo json_encode(t('premium_subscription_unknown')); ?>
};

function premiumEscapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function premiumActionHtml(planKey, currentPlan, betaAccess) {
    var isCurrent = String(currentPlan) === String(planKey);
    if (betaAccess) {
        if (isCurrent) {
            return '<span class="sp-btn sp-btn-success sp-btn-block" style="cursor: default; pointer-events: none; opacity: 0.8;">' +
                '<i class="fas fa-check-circle"></i> ' + premiumEscapeHtml(PREMIUM_I18N.currentPlan) + ' ' + premiumEscapeHtml(PREMIUM_I18N.plusBeta) +
                '</span>';
        }
        return '<span class="sp-btn sp-btn-info sp-btn-block" style="cursor: default; pointer-events: none; opacity: 0.8;">' +
            '<i class="fas fa-flask"></i> ' + premiumEscapeHtml(PREMIUM_I18N.betaAccessLabel) +
            '</span>';
    }
    if (planKey === 'free') {
        if (!isCurrent) return '';
        return '<span class="sp-btn sp-btn-success sp-btn-block" style="cursor: default; pointer-events: none;">' +
            '<i class="fas fa-check-circle"></i> ' + premiumEscapeHtml(PREMIUM_I18N.currentPlan) +
            '</span>';
    }
    if (isCurrent) {
        return '<span class="sp-btn sp-btn-success sp-btn-block" style="cursor: default; pointer-events: none;">' +
            '<i class="fas fa-check-circle"></i> ' + premiumEscapeHtml(PREMIUM_I18N.currentPlan) +
            '</span>';
    }
    var icon = 'fas fa-plus-circle';
    var label = PREMIUM_I18N.subscribe;
    if (currentPlan !== 'free') {
        if (parseInt(currentPlan, 10) < parseInt(planKey, 10)) {
            icon = 'fas fa-arrow-up';
            label = PREMIUM_I18N.upgrade;
        } else {
            icon = 'fas fa-arrow-down';
            label = PREMIUM_I18N.downgrade;
        }
    }
    return '<a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="sp-btn sp-btn-primary sp-btn-block">' +
        '<i class="' + icon + '"></i> ' + premiumEscapeHtml(label) +
        '</a>';
}

function renderPremiumStatus(data) {
    var currentPlan = data && data.current_plan ? String(data.current_plan) : 'free';
    var betaAccess = !!(data && data.beta_access);
    var subMsg = (data && data.subscription_message) ? String(data.subscription_message) : '';
    var errMsg = (data && data.error_message) ? String(data.error_message) : '';

    var alertHost = document.getElementById('premiumAlertHost');
    if (alertHost) {
        alertHost.setAttribute('aria-busy', 'false');
        if (errMsg && !betaAccess) {
            alertHost.innerHTML = '<div class="sp-alert sp-alert-warning" style="margin-bottom: 1.5rem;">' +
                '<i class="fas fa-exclamation-triangle"></i> ' + premiumEscapeHtml(errMsg) + '</div>';
        } else {
            alertHost.innerHTML = '';
        }
    }

    var badges = document.getElementById('premiumStatusBadges');
    if (badges) {
        badges.setAttribute('aria-busy', 'false');
        var html = '';
        if (subMsg) {
            html += '<span class="sp-badge sp-badge-blue"><i class="fas fa-crown"></i> ' + premiumEscapeHtml(subMsg) + '</span>';
        }
        if (errMsg && !betaAccess) {
            html += '<span class="sp-badge sp-badge-amber"><i class="fas fa-exclamation-triangle"></i> ' + premiumEscapeHtml(errMsg) + '</span>';
        }
        badges.innerHTML = html;
    }

    document.querySelectorAll('.sp-plan-card[data-plan-key]').forEach(function(card) {
        var planKey = card.getAttribute('data-plan-key');
        var isCurrent = String(currentPlan) === String(planKey);
        card.classList.toggle('is-current', isCurrent);
        var ribbon = card.querySelector('.sp-plan-current-ribbon');
        if (ribbon) ribbon.hidden = !isCurrent;
        var action = card.querySelector('.premium-plan-action');
        if (action) {
            action.setAttribute('aria-busy', 'false');
            action.innerHTML = premiumActionHtml(planKey, currentPlan, betaAccess);
        }
    });
}

function renderPremiumError() {
    renderPremiumStatus({
        current_plan: 'free',
        beta_access: PREMIUM_I18N.betaAccess,
        subscription_message: '',
        error_message: PREMIUM_I18N.betaAccess ? '' : PREMIUM_I18N.unknown
    });
}

function loadPremiumStatus() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderPremiumError();
                return;
            }
            renderPremiumStatus(data);
        })
        .catch(function() {
            renderPremiumError();
        });
}

loadPremiumStatus();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
