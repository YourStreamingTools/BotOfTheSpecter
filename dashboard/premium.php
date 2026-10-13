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

// Plan cards in tier order. storage is MB, sounds is Make Your Sound generations in any 24 hours (matches alerts_sound_gen_limit in alerts.php).
$plans = [
    'free' => [
        'name' => t('premium_plan_free_name'),
        'price' => t('premium_plan_free_price'),
        'icon' => 'fas fa-rocket',
        'mod' => 'free',
        'storage' => 20,
        'storage_tip' => t('premium_plan_free_feature_storage_tip'),
        'sounds' => 10,
        'features' => [
            ['text' => t('premium_plan_free_feature_commands'), 'tip' => t('premium_plan_free_feature_commands_tip')],
            ['text' => t('premium_plan_free_feature_support'), 'tip' => t('premium_plan_free_feature_support_tip')],
            ['text' => t('premium_plan_free_feature_shared_bot'), 'tip' => t('premium_plan_free_feature_shared_bot_tip')],
            ['text' => t('premium_plan_free_feature_custom_bot'), 'tip' => t('premium_plan_free_feature_custom_bot_tip'), 'icon' => 'fas fa-flask sp-plan-feature-icon--amber'],
        ],
        'note' => t('premium_plan_free_note'),
    ],
    '1000' => [
        'name' => t('premium_plan_tier1_name'),
        'price' => t('premium_plan_tier1_price'),
        'icon' => 'fas fa-star',
        'mod' => 't1',
        'storage' => 50,
        'storage_tip' => t('premium_plan_tier1_feature_storage_tip'),
        'sounds' => 20,
        'features' => [
            ['text' => t('premium_plan_tier1_feature_song_command'), 'tip' => t('premium_plan_tier1_feature_song_command_tip')],
            ['text' => t('premium_plan_tier1_feature_support'), 'tip' => t('premium_plan_tier1_feature_support_tip')],
            ['text' => t('premium_plan_tier1_feature_beta'), 'tip' => t('premium_plan_tier1_feature_beta_tip')],
        ],
    ],
    '2000' => [
        'name' => t('premium_plan_tier2_name'),
        'price' => t('premium_plan_tier2_price'),
        'icon' => 'fas fa-crown',
        'mod' => 't2',
        'storage' => 100,
        'storage_tip' => t('premium_plan_tier2_feature_storage_tip'),
        'sounds' => 30,
        'features' => [
            ['text' => t('premium_plan_tier2_feature_everything_t1'), 'tip' => t('premium_plan_tier2_feature_everything_t1_tip')],
            ['text' => t('premium_plan_tier2_feature_personal_support'), 'tip' => t('premium_plan_tier2_feature_personal_support_tip')],
            ['text' => t('premium_plan_tier2_feature_ai'), 'tip' => t('premium_plan_tier2_feature_ai_tip')],
        ],
    ],
    '3000' => [
        'name' => t('premium_plan_tier3_name'),
        'price' => t('premium_plan_tier3_price'),
        'icon' => 'fas fa-gem',
        'mod' => 't3',
        'storage' => 200,
        'storage_tip' => t('premium_plan_tier3_feature_storage_tip'),
        'sounds' => 40,
        'features' => [
            ['text' => t('premium_plan_tier3_feature_everything_t2'), 'tip' => t('premium_plan_tier3_feature_everything_t2_tip')],
        ],
    ],
];
$betaPlan = [
    'name' => t('premium_beta_plan_name'),
    'price' => t('premium_beta_plan_price'),
    'icon' => 'fas fa-flask',
    'mod' => 'beta',
    'storage' => 500,
    'storage_tip' => t('premium_beta_plan_feature_storage_tip'),
    'sounds' => 40,
    'features' => [
        ['text' => t('premium_beta_plan_feature_all'), 'tip' => t('premium_beta_plan_feature_all_tip')],
    ],
    'footer' => t('premium_beta_plan_footer'),
];

// $planKey null leaves the card out of the subscription status pass (the beta card is always current).
function premium_plan_card($planKey, array $plan, bool $current = false)
{
    $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
    ?>
    <div class="sp-card sp-plan-card sp-plan-card--<?php echo $h($plan['mod']); ?><?php echo $current ? ' is-current' : ''; ?>"<?php echo $planKey !== null ? ' data-plan-key="' . $h($planKey) . '"' : ''; ?>>
        <div class="sp-card-body sp-plan-body">
            <div class="sp-plan-identity">
                <div class="sp-plan-top">
                    <span class="sp-plan-icon-wrap"><i class="<?php echo $h($plan['icon']); ?> sp-plan-icon" aria-hidden="true"></i></span>
                    <span class="sp-plan-current-tag"<?php echo $current ? '' : ' hidden'; ?>><i class="fas fa-check" aria-hidden="true"></i> <?php echo $h(t('premium_current_ribbon')); ?></span>
                </div>
                <h3 class="sp-plan-name"><?php echo $h($plan['name']); ?></h3>
                <p class="sp-plan-price"><?php echo $h($plan['price']); ?></p>
            </div>
            <dl class="sp-plan-allowance">
                <div title="<?php echo $h($plan['storage_tip']); ?>">
                    <dt><?php echo $h(t('premium_allowance_storage')); ?></dt>
                    <dd><?php echo (int) $plan['storage']; ?> MB</dd>
                </div>
                <div title="<?php echo $h(t('premium_feature_sound_gen_tip', [(int) $plan['sounds']])); ?>">
                    <dt><?php echo $h(t('premium_allowance_sounds')); ?></dt>
                    <dd><?php echo (int) $plan['sounds']; ?></dd>
                </div>
            </dl>
            <ul class="sp-plan-features">
                <?php foreach ($plan['features'] as $feature): ?>
                <li title="<?php echo $h($feature['tip']); ?>">
                    <i class="<?php echo $h($feature['icon'] ?? 'fas fa-check sp-plan-feature-icon'); ?>" aria-hidden="true"></i>
                    <span><?php echo $h($feature['text']); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($plan['note'])): ?>
            <p class="sp-plan-note"><?php echo $plan['note']; ?></p>
            <?php endif; ?>
            <?php if (!empty($plan['footer'])): ?>
            <p class="sp-plan-beta-footer"><?php echo $h($plan['footer']); ?></p>
            <?php endif; ?>
            <?php if ($planKey !== null): ?>
            <div class="premium-plan-action" aria-busy="true">
                <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

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
    <?php foreach ($plans as $planKey => $planDetails): ?>
        <?php premium_plan_card((string) $planKey, $planDetails); ?>
    <?php endforeach; ?>
    <?php if ($betaAccess): ?>
        <?php premium_plan_card(null, $betaPlan, true); ?>
    <?php endif; ?>
</div>
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
        var tag = card.querySelector('.sp-plan-current-tag');
        if (tag) tag.hidden = !isCurrent;
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
