<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('navbar_streamelements');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include "/var/www/config/streamelements.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load

$isActAsUser = isset($isActAs) && $isActAs === true;
$twitchUserId = $_SESSION['twitchUserId'] ?? $twitchUserId ?? null;
$isLinked = false;
$linkingMessage = '';
$linkingMessageType = '';
$hasSeToken = false;

$client_id = $streamelements_client_id;
$client_secret = $streamelements_client_secret;
$redirect_uri = 'https://dashboard.botofthespecter.com/streamelements.php';
$scope = 'channel:read tips:read';

function streamelements_api_get(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode(is_string($body) ? $body : '', true);
    return [$code, is_array($data) ? $data : []];
}

function streamelements_format_expires(?int $expires_in): string
{
    if ($expires_in === null) {
        return '';
    }
    $days = floor($expires_in / 86400);
    $hours = floor(($expires_in % 86400) / 3600);
    $minutes = floor(($expires_in % 3600) / 60);
    $parts = [];
    if ($days > 0) {
        $parts[] = ($days > 1 ? t('streamelements_duration_days', [$days]) : t('streamelements_duration_day', [$days]));
    }
    if ($hours > 0) {
        $parts[] = ($hours > 1 ? t('streamelements_duration_hours', [$hours]) : t('streamelements_duration_hour', [$hours]));
    }
    if ($minutes > 0 && count($parts) < 2) {
        $parts[] = ($minutes > 1 ? t('streamelements_duration_minutes', [$minutes]) : t('streamelements_duration_minute', [$minutes]));
    }
    return implode(', ', $parts);
}

function streamelements_format_amount($amount, $currency): string
{
    $formatted_amount = number_format((float) $amount, 2);
    $currency_symbol = match ($currency) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => 'C$',
        'AUD' => 'A$',
        default => $currency . ' ',
    };
    return $currency_symbol . $formatted_amount;
}

function streamelements_build_list_payload(mysqli $db, mysqli $conn, $twitchUserId): array
{
    $timezone = 'UTC';
    $tzStmt = $db->prepare("SELECT timezone FROM profile");
    if ($tzStmt) {
        $tzStmt->execute();
        $channelData = $tzStmt->get_result()->fetch_assoc();
        $tzStmt->close();
        $timezone = $channelData['timezone'] ?? 'UTC';
    }
    date_default_timezone_set($timezone);

    if (!$twitchUserId) {
        return ['success' => true, 'linked' => false];
    }

    $access_token = null;
    $stored_jwt_token = null;
    $stmt = $conn->prepare("SELECT access_token, jwt_token FROM streamelements_tokens WHERE twitch_user_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $twitchUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $access_token = $row['access_token'] ?? null;
            $stored_jwt_token = $row['jwt_token'] ?? null;
        }
        $stmt->close();
    }

    if (empty($access_token)) {
        return ['success' => true, 'linked' => false];
    }

    [$validate_code, $validate_data] = streamelements_api_get(
        "https://api.streamelements.com/oauth2/validate",
        ["Authorization: oAuth {$access_token}"]
    );
    if ($validate_code !== 200 || !isset($validate_data['channel_id'])) {
        return ['success' => true, 'linked' => false];
    }

    $channelId = $validate_data['channel_id'];
    $expires_in = isset($validate_data['expires_in']) ? (int) $validate_data['expires_in'] : null;
    $expires_str = streamelements_format_expires($expires_in);
    $expires_html = $expires_str
        ? t('streamelements_token_status_autorenew', ['expires' => '<strong>' . htmlspecialchars($expires_str) . '</strong>'])
        : '';

    [$profile_code, $profile_data] = streamelements_api_get(
        "https://api.streamelements.com/kappa/v2/channels/me",
        [
            "Accept: application/json",
            "Authorization: oAuth {$access_token}",
        ]
    );
    unset($profile_code);
    $apiToken = $profile_data['apiToken'] ?? null;
    $createdAt = $profile_data['createdAt'] ?? null;
    $createdAtFormatted = '';
    if ($createdAt) {
        try {
            $dt = new DateTime($createdAt);
            $createdAtFormatted = $dt->format('F j, Y');
        } catch (Exception $e) {
            $createdAtFormatted = (string) $createdAt;
        }
    }
    $inactive = array_key_exists('inactive', $profile_data) ? (bool) $profile_data['inactive'] : null;
    $isPartner = array_key_exists('isPartner', $profile_data) ? (bool) $profile_data['isPartner'] : null;
    $suspended = array_key_exists('suspended', $profile_data) ? (bool) $profile_data['suspended'] : null;

    $jwtToken = null;
    if ($stored_jwt_token) {
        [$current_user_code, $current_user_data] = streamelements_api_get(
            "https://api.streamelements.com/kappa/v2/users/current",
            [
                "Accept: application/json; charset=utf-8",
                "Authorization: Bearer {$stored_jwt_token}",
            ]
        );
        if ($current_user_code === 200 && isset($current_user_data['channels']) && is_array($current_user_data['channels'])) {
            foreach ($current_user_data['channels'] as $channel) {
                if (isset($channel['_id'])) {
                    $channelId = $channel['_id'];
                }
                if (!$stored_jwt_token && isset($channel['lastJWTToken']) && $channel['lastJWTToken'] !== '') {
                    $jwtToken = $channel['lastJWTToken'];
                }
                if ($channelId) {
                    break;
                }
            }
        }
        if ($jwtToken && !$stored_jwt_token) {
            $update_jwt_stmt = $conn->prepare("UPDATE streamelements_tokens SET jwt_token = ? WHERE twitch_user_id = ?");
            if ($update_jwt_stmt) {
                $update_jwt_stmt->bind_param("ss", $jwtToken, $twitchUserId);
                $update_jwt_stmt->execute();
                $update_jwt_stmt->close();
                $stored_jwt_token = $jwtToken;
            }
        }
    }

    if (!$channelId) {
        [$current_user_code, $current_user_data] = streamelements_api_get(
            "https://api.streamelements.com/kappa/v2/users/current",
            [
                "Accept: application/json; charset=utf-8",
                "Authorization: Bearer {$stored_jwt_token}",
            ]
        );
        if ($current_user_code === 200 && isset($current_user_data['channels']) && is_array($current_user_data['channels'])) {
            foreach ($current_user_data['channels'] as $channel) {
                if (isset($channel['_id'])) {
                    $channelId = $channel['_id'];
                    break;
                }
            }
        }
    }

    $recentTips = [];
    $tips_code = null;
    if ($channelId) {
        [$tips_code, $tips_data] = streamelements_api_get(
            "https://api.streamelements.com/kappa/v2/tips/{$channelId}?limit=100",
            [
                "Accept: application/json; charset=utf-8",
                "Authorization: oAuth {$access_token}",
            ]
        );
        if ($tips_code === 200 && isset($tips_data['docs']) && is_array($tips_data['docs'])) {
            foreach ($tips_data['docs'] as $tip) {
                $message = $tip['donation']['message'] ?? '';
                $date = t('streamelements_date_unknown');
                if (isset($tip['createdAt'])) {
                    try {
                        $dt = new DateTime($tip['createdAt']);
                        $date = $dt->format('M j, Y g:i A');
                    } catch (Exception $e) {
                        $date = (string) $tip['createdAt'];
                    }
                }
                $recentTips[] = [
                    'username' => $tip['donation']['user']['username'] ?? t('streamelements_tipper_anonymous'),
                    'amount' => streamelements_format_amount(
                        $tip['donation']['amount'] ?? 0,
                        $tip['donation']['currency'] ?? 'USD'
                    ),
                    'message' => strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message,
                    'provider' => ucfirst($tip['provider'] ?? t('streamelements_provider_unknown')),
                    'date' => $date,
                ];
            }
        }
    }

    return [
        'success' => true,
        'linked' => true,
        'inactive' => $inactive,
        'created_at' => $createdAtFormatted,
        'is_partner' => $isPartner,
        'suspended' => $suspended,
        'expires_html' => $expires_html,
        'api_token' => $apiToken,
        'jwt_token' => $stored_jwt_token,
        'channel_id' => $channelId,
        'tips_code' => $tips_code,
        'tips_failed_message' => ($tips_code !== null && $tips_code !== 200)
            ? t('streamelements_tips_api_failed', ['code' => $tips_code])
            : '',
        'tips' => $recentTips,
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch SE data.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    session_write_close();
    header('Content-Type: application/json');
    try {
        echo json_encode(streamelements_build_list_payload($db, $conn, $twitchUserId));
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle user denial (error=true in query string)
if ($isActAsUser && isset($_GET['code'])) {
    $linkingMessage = t('streamelements_msg_actas_disabled');
    $linkingMessageType = "is-warning";
} elseif (isset($_GET['error']) && $_GET['error'] === 'true') {
    $linkingMessage = t('streamelements_msg_auth_denied');
    $linkingMessageType = "is-danger";
}

// Handle OAuth callback
if (isset($_GET['code']) && !$isActAsUser) {
    // Optional: Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamelements_oauth_state']) || $_GET['state'] !== $_SESSION['streamelements_oauth_state']) {
        $linkingMessage = t('streamelements_msg_invalid_state');
        $linkingMessageType = "is-danger";
    } else {
        unset($_SESSION['streamelements_oauth_state']);
        $code = $_GET['code'];
        $token_url = "https://api.streamelements.com/oauth2/token";
        $post_fields = http_build_query([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri
        ]);
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $token_data = json_decode($response, true);
        if ($httpcode === 200 && isset($token_data['access_token'])) {
            $access_token = $token_data['access_token'];
            $refresh_token = $token_data['refresh_token'];
            // Store refresh_token and expires_in if present
            if (isset($token_data['refresh_token'])) { $_SESSION['streamelements_refresh_token'] = $token_data['refresh_token']; }
            if (isset($token_data['expires_in'])) { $_SESSION['streamelements_expires_in'] = $token_data['expires_in']; }
            // Validate the token
            $validate_url = "https://api.streamelements.com/oauth2/validate";
            $ch = curl_init($validate_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: oAuth {$access_token}"]);
            $validate_response = curl_exec($ch);
            $validate_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $validate_data = json_decode($validate_response, true);
            if ($validate_code === 200 && isset($validate_data['channel_id'])) {
                $_SESSION['streamelements_token'] = $access_token;
                // Fetch StreamElements current user to get JWT token
                $current_user_url = "https://api.streamelements.com/kappa/v2/users/current";
                $ch = curl_init($current_user_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Accept: application/json; charset=utf-8",
                    "Authorization: Bearer {$access_token}"
                ]);
                $current_user_response = curl_exec($ch);
                $current_user_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $jwtToken = null;
                $channelId = null;
                if ($current_user_code === 200) {
                    $current_user_data = json_decode($current_user_response, true);
                    if (isset($current_user_data['channels']) && is_array($current_user_data['channels'])) {
                        // Find the primary channel or first channel
                        foreach ($current_user_data['channels'] as $channel) {
                            // Get channel ID
                            if (isset($channel['_id'])) {
                                $channelId = $channel['_id'];
                            }
                            // Get JWT token if available
                            if (isset($channel['lastJWTToken']) && !empty($channel['lastJWTToken'])) {
                                $jwtToken = $channel['lastJWTToken'];
                            }
                            // Break after first channel (usually the primary one)
                            if ($channelId) {
                                break;
                            }
                        }
                    }
                    // Store channel ID in session for other API calls
                    if ($channelId) {
                        $_SESSION['streamelements_channel_id'] = $channelId;
                    }
                }
                if (isset($_SESSION['twitchUserId']) && $refresh_token) {
                    $twitchUserId = $_SESSION['twitchUserId'];
                    // Prepare the query with JWT token support
                    if ($jwtToken) {
                        $query = "INSERT INTO streamelements_tokens (twitch_user_id, access_token, refresh_token, jwt_token) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), jwt_token = VALUES(jwt_token)";
                        if ($stmt = $conn->prepare($query)) {
                            $stmt->bind_param('ssss', $twitchUserId, $access_token, $refresh_token, $jwtToken);
                        }
                    } else {
                        $query = "INSERT INTO streamelements_tokens (twitch_user_id, access_token, refresh_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token)";
                        if ($stmt = $conn->prepare($query)) {
                            $stmt->bind_param('sss', $twitchUserId, $access_token, $refresh_token);
                        }
                    }
                    if ($stmt) {
                        if ($stmt->execute()) {
                            $linkingMessage = t('streamelements_msg_link_success');
                            $linkingMessageType = "is-success";
                            $isLinked = true;
                            // Redirect to refresh page and show linked status
                            header("Location: streamelements.php");
                            exit();
                        } else {
                            $linkingMessage = t('streamelements_msg_link_save_failed');
                            $linkingMessageType = "is-warning";
                        }
                        $stmt->close();
                    } else {
                        $linkingMessage = t('streamelements_msg_link_prepare_failed');
                        $linkingMessageType = "is-warning";
                    }
                } else {
                    $linkingMessage = t('streamelements_msg_link_missing_ids');
                    $linkingMessageType = "is-warning";
                }
            } else {
                $linkingMessage = t('streamelements_msg_token_validation_failed');
                $linkingMessageType = "is-danger";
                if (isset($validate_data['message'])) {
                    $linkingMessage .= " " . t('streamelements_msg_error_prefix') . " " . htmlspecialchars($validate_data['message']);
                }
            }
        } else {
            $linkingMessage = t('streamelements_msg_link_failed');
            $linkingMessageType = "is-danger";
            if (isset($token_data['error'])) {
                $linkingMessage .= " " . t('streamelements_msg_error_prefix') . " " . htmlspecialchars($token_data['error']);
            }
            if (isset($token_data['error_description'])) {
                $linkingMessage .= " " . t('streamelements_msg_description_prefix') . " " . htmlspecialchars($token_data['error_description']);
            }
        }
    }
}

// Generate auth URL for manual linking / re-link if validate later fails
$authURL = '';
if (!$isActAsUser) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['streamelements_oauth_state'] = $state;
    $authURL = "https://api.streamelements.com/oauth2/authorize"
        . "?client_id={$client_id}"
        . "&response_type=code"
        . "&scope=" . urlencode($scope)
        . "&state={$state}"
        . "&redirect_uri=" . $redirect_uri;
}

session_write_close();

if ($twitchUserId) {
    $stmt = $conn->prepare("SELECT access_token FROM streamelements_tokens WHERE twitch_user_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $twitchUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $hasSeToken = !empty($row['access_token']);
        }
        $stmt->close();
    }
}
$isLinked = $hasSeToken;

ob_start();
?>
<div class="sp-card">
    <header class="sp-card-header">
        <div style="display:flex;align-items:center;flex:1;min-width:0;flex-wrap:wrap;gap:0.5rem;">
            <p class="sp-card-title" style="flex-shrink:0;">
                <i class="fas fa-bolt" style="color:var(--accent-hover);margin-right:0.4em;"></i>
                <?= t('streamelements_integration_title') ?>
            </p>
            <div id="se-badges" style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;margin-left:0.75rem;"<?php if ($isLinked): ?> aria-busy="true"<?php endif; ?>>
                <?php if ($isLinked): ?>
                    <span class="sp-skeleton-badge" aria-hidden="true"></span>
                    <span class="sp-skeleton-badge" aria-hidden="true"></span>
                    <span class="sp-skeleton-badge" aria-hidden="true"></span>
                    <span class="sp-skeleton-badge" aria-hidden="true"></span>
                <?php else: ?>
                    <span class="sp-badge sp-badge-red">
                        <i class="fas fa-times-circle"></i>
                        <?= t('streamelements_status_not_connected') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <div class="sp-card-body">
        <?php if ($linkingMessage): ?>
            <div class="sp-alert <?php echo $linkingMessageType === 'is-success' ? 'sp-alert-success' : ($linkingMessageType === 'is-danger' ? 'sp-alert-danger' : 'sp-alert-warning'); ?>" style="margin-bottom:1.5rem;">
                <?php if ($linkingMessageType === 'is-danger'): ?>
                    <i class="fas fa-exclamation-triangle"></i>
                <?php elseif ($linkingMessageType === 'is-success'): ?>
                    <i class="fas fa-check"></i>
                <?php else: ?>
                    <i class="fas fa-info-circle"></i>
                <?php endif; ?>
                <?php echo $linkingMessage; ?>
            </div>
        <?php endif; ?>
        <?php if ($isLinked): ?>
            <div id="se-linked-panel">
                <div id="se-status-host" aria-busy="true" style="text-align:center;padding:1rem 2rem 2rem;">
                    <div id="se-status-skeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-80"></span>
                        <span class="sp-skeleton-line w-60"></span>
                    </div>
                    <div id="se-status-ready" style="display:none;">
                        <p style="color:var(--text-secondary);max-width:600px;margin:0 auto 1rem;">
                            <?= t('streamelements_linked_success_message') ?>
                        </p>
                        <div id="se-expires-alert" class="sp-alert sp-alert-info" style="max-width:600px;margin:0 auto;display:none;">
                            <i class="fas fa-clock"></i>
                            <span id="se-expires-text"></span>
                        </div>
                    </div>
                </div>
                <div id="se-tokens-host" aria-busy="true">
                    <div id="se-tokens-skeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-90"></span>
                        <span class="sp-skeleton-line w-70"></span>
                        <span class="sp-skeleton-line w-80"></span>
                    </div>
                    <div id="se-tokens-grid" style="display:none;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:1.5rem;margin-bottom:1.5rem;">
                        <div class="sp-card" id="se-api-token-card" style="margin-bottom:0;display:none;">
                            <header class="sp-card-header">
                                <p class="sp-card-title">
                                    <i class="fas fa-key" style="color:var(--amber);margin-right:0.4em;"></i>
                                    <?= t('streamelements_api_token_title') ?>
                                </p>
                            </header>
                            <div class="sp-card-body">
                                <div class="sp-form-group">
                                    <label class="sp-label"><?= t('streamelements_api_token_label') ?></label>
                                    <div style="display:flex;">
                                        <input class="sp-input" type="text" id="apiTokenDisplay" value="" readonly style="border-radius:var(--radius) 0 0 var(--radius);font-family:monospace;letter-spacing:1.5px;">
                                        <button id="showApiTokenBtn" class="sp-btn sp-btn-warning" style="border-radius:0 var(--radius) var(--radius) 0;border-left:none;" title="<?= htmlspecialchars(t('streamelements_show_api_token')) ?>">
                                            <i id="apiTokenEye" class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem;">
                                        <i class="fas fa-exclamation-triangle" style="color:var(--amber);margin-right:0.25em;"></i>
                                        <?= t('streamelements_api_token_warning') ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="sp-card" id="se-jwt-token-card" style="margin-bottom:0;display:none;">
                            <header class="sp-card-header">
                                <p class="sp-card-title">
                                    <i class="fas fa-shield-alt" style="color:var(--blue);margin-right:0.4em;"></i>
                                    <?= t('streamelements_jwt_token_title') ?>
                                </p>
                            </header>
                            <div class="sp-card-body">
                                <div class="sp-form-group">
                                    <label class="sp-label"><?= t('streamelements_jwt_token_label') ?></label>
                                    <div style="display:flex;">
                                        <input class="sp-input" type="text" id="jwtTokenDisplay" value="" readonly style="border-radius:var(--radius) 0 0 var(--radius);font-family:monospace;letter-spacing:1.5px;">
                                        <button id="showJwtTokenBtn" class="sp-btn sp-btn-info" style="border-radius:0 var(--radius) var(--radius) 0;border-left:none;" title="<?= htmlspecialchars(t('streamelements_show_jwt_token')) ?>">
                                            <i id="jwtTokenEye" class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem;">
                                        <i class="fas fa-exclamation-triangle" style="color:var(--amber);margin-right:0.25em;"></i>
                                        <?= t('streamelements_jwt_token_warning') ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="sp-card" id="se-tips-card" style="margin-top:1.5rem;">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-dollar-sign" style="color:var(--green);margin-right:0.4em;"></i>
                            <?= t('streamelements_recent_tips_title') ?> <span id="se-tips-count"></span>
                        </p>
                    </header>
                    <div class="sp-card-body" style="padding:0;">
                        <div class="sp-table-wrap" style="border:none;border-radius:0;">
                            <table class="sp-table">
                                <thead>
                                    <tr>
                                        <th><?= t('streamelements_table_tipper') ?></th>
                                        <th><?= t('streamelements_table_amount') ?></th>
                                        <th><?= t('streamelements_table_message') ?></th>
                                        <th><?= t('streamelements_table_provider') ?></th>
                                        <th><?= t('streamelements_table_date') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="se-tips-tbody" aria-busy="true">
                                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                                    <tr aria-hidden="true">
                                        <td><span class="sp-skeleton-line w-50"></span></td>
                                        <td><span class="sp-skeleton-badge"></span></td>
                                        <td><span class="sp-skeleton-line w-80"></span></td>
                                        <td><span class="sp-skeleton-badge"></span></td>
                                        <td><span class="sp-skeleton-line w-60"></span></td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="sp-card" id="se-tips-empty" style="margin-top:1.5rem;display:none;">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-info-circle" style="color:var(--blue);margin-right:0.4em;"></i>
                            <?= t('streamelements_tips_status_title') ?>
                        </p>
                    </header>
                    <div class="sp-card-body">
                        <div class="sp-alert sp-alert-info">
                            <p><strong><?= t('streamelements_no_recent_tips') ?></strong></p>
                            <div id="se-tips-empty-details"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="se-connect-fallback" style="display:none;">
                <div style="text-align:center;padding:1.5rem 0;">
                    <p style="color:var(--text-secondary);margin-bottom:1.5rem;"><?= t('streamelements_connect_prompt') ?></p>
                    <div class="sp-card" style="max-width:600px;margin:0 auto 1.5rem;text-align:left;">
                        <div class="sp-card-body">
                            <p style="font-weight:700;margin-bottom:0.75rem;">
                                <i class="fas fa-bolt" style="color:var(--blue);margin-right:0.4em;"></i>
                                <?= t('streamelements_available_features') ?>
                            </p>
                            <ul style="list-style:disc;padding-left:1.25rem;color:var(--text-secondary);">
                                <li style="margin-bottom:0.4rem;"><?= t('streamelements_feature_tips_data') ?></li>
                                <li style="margin-bottom:0.4rem;"><?= t('streamelements_feature_bot_commands') ?></li>
                                <li><?= t('streamelements_feature_engagement') ?></li>
                            </ul>
                        </div>
                    </div>
                    <?php if ($authURL): ?>
                        <a href="<?php echo $authURL; ?>" class="sp-btn sp-btn-info" style="padding:0.75rem 1.5rem;font-size:1rem;">
                            <i class="fas fa-bolt"></i>
                            <span><?= t('streamelements_link_account_button') ?></span>
                        </a>
                    <?php elseif ($isActAsUser): ?>
                        <div class="sp-alert sp-alert-warning" style="max-width:700px;margin:0 auto;">
                            <i class="fas fa-info-circle"></i>
                            <?= t('streamelements_act_as_disabled') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align:center;padding:1.5rem 0;">
                <p style="color:var(--text-secondary);margin-bottom:1.5rem;"><?= t('streamelements_connect_prompt') ?></p>
                <div class="sp-card" style="max-width:600px;margin:0 auto 1.5rem;text-align:left;">
                    <div class="sp-card-body">
                        <p style="font-weight:700;margin-bottom:0.75rem;">
                            <i class="fas fa-bolt" style="color:var(--blue);margin-right:0.4em;"></i>
                            <?= t('streamelements_available_features') ?>
                        </p>
                        <ul style="list-style:disc;padding-left:1.25rem;color:var(--text-secondary);">
                            <li style="margin-bottom:0.4rem;"><?= t('streamelements_feature_tips_data') ?></li>
                            <li style="margin-bottom:0.4rem;"><?= t('streamelements_feature_bot_commands') ?></li>
                            <li><?= t('streamelements_feature_engagement') ?></li>
                        </ul>
                    </div>
                </div>
                <?php if ($authURL): ?>
                    <a href="<?php echo $authURL; ?>" class="sp-btn sp-btn-info" style="padding:0.75rem 1.5rem;font-size:1rem;">
                        <i class="fas fa-bolt"></i>
                        <span><?= t('streamelements_link_account_button') ?></span>
                    </a>
                <?php elseif ($isActAsUser): ?>
                    <div class="sp-alert sp-alert-warning" style="max-width:700px;margin:0 auto;">
                        <i class="fas fa-info-circle"></i>
                        <?= t('streamelements_act_as_disabled') ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

if ($isLinked):
ob_start();
?>
<script>
const SE_I18N = {
    statusInactive: <?php echo json_encode(t('streamelements_status_inactive')); ?>,
    statusActive: <?php echo json_encode(t('streamelements_status_active')); ?>,
    statusSuspended: <?php echo json_encode(t('streamelements_status_suspended')); ?>,
    statusConnected: <?php echo json_encode(t('streamelements_status_connected')); ?>,
    statusNotConnected: <?php echo json_encode(t('streamelements_status_not_connected')); ?>,
    badgeAccountStatus: <?php echo json_encode(t('streamelements_badge_account_status')); ?>,
    badgeAccountCreated: <?php echo json_encode(t('streamelements_badge_account_created')); ?>,
    badgePartner: <?php echo json_encode(t('streamelements_badge_partner')); ?>,
    badgeSePartner: <?php echo json_encode(t('streamelements_badge_se_partner')); ?>,
    badgeSuspended: <?php echo json_encode(t('streamelements_badge_suspended')); ?>,
    channelIdLabel: <?php echo json_encode(t('streamelements_channel_id_label')); ?>,
    channelIdChecking: <?php echo json_encode(t('streamelements_channel_id_checking')); ?>,
    tipsApiResponseCode: <?php echo json_encode(t('streamelements_tips_api_response_code')); ?>,
    tipsApiSuccessEmpty: <?php echo json_encode(t('streamelements_tips_api_success_empty')); ?>,
    channelIdUnavailable: <?php echo json_encode(t('streamelements_channel_id_unavailable')); ?>,
    currentUserCallFailed: <?php echo json_encode(t('streamelements_current_user_call_failed')); ?>,
    tokenValidationFailed: <?php echo json_encode(t('streamelements_msg_token_validation_failed')); ?>,
    revealApiTitle: <?php echo json_encode(t('streamelements_js_reveal_api_title')); ?>,
    revealApiText: <?php echo json_encode(t('streamelements_js_reveal_api_text')); ?>,
    revealJwtTitle: <?php echo json_encode(t('streamelements_js_reveal_jwt_title')); ?>,
    revealJwtText: <?php echo json_encode(t('streamelements_js_reveal_jwt_text')); ?>,
    confirmShow: <?php echo json_encode(t('streamelements_js_confirm_show')); ?>,
    confirmCancel: <?php echo json_encode(t('streamelements_js_confirm_cancel')); ?>,
    showApiToken: <?php echo json_encode(t('streamelements_show_api_token')); ?>,
    hideApiToken: <?php echo json_encode(t('streamelements_js_hide_api_token')); ?>,
    showJwtToken: <?php echo json_encode(t('streamelements_show_jwt_token')); ?>,
    hideJwtToken: <?php echo json_encode(t('streamelements_js_hide_jwt_token')); ?>
};

let apiToken = '';
let apiTokenDotCount = 0;
let apiTokenVisible = false;
let jwtToken = '';
let jwtTokenDotCount = 0;
let jwtTokenVisible = false;

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function seBadge(className, icon, text, title) {
    return '<span class="sp-badge ' + className + '"' + (title ? ' title="' + escapeHtml(title) + '"' : '') + '>' +
        '<i class="' + icon + '"></i> ' + escapeHtml(text) + '</span>';
}

function renderSeBadges(data) {
    var host = document.getElementById('se-badges');
    if (!host) return;
    host.setAttribute('aria-busy', 'false');
    if (!data || !data.linked) {
        host.innerHTML = seBadge('sp-badge-red', 'fas fa-times-circle', SE_I18N.statusNotConnected, '');
        return;
    }
    var html = '';
    if (data.inactive !== null && data.inactive !== undefined) {
        html += seBadge(data.inactive ? 'sp-badge-red' : 'sp-badge-green', 'fa-regular fa-user', data.inactive ? SE_I18N.statusInactive : SE_I18N.statusActive, SE_I18N.badgeAccountStatus);
    }
    if (data.created_at) {
        html += seBadge('sp-badge-blue', 'fa-regular fa-calendar', data.created_at, SE_I18N.badgeAccountCreated);
    }
    if (data.is_partner !== null && data.is_partner !== undefined) {
        html += seBadge(data.is_partner ? 'sp-badge-accent' : 'sp-badge-grey', 'fa-solid fa-star', SE_I18N.badgeSePartner, SE_I18N.badgePartner);
    }
    if (data.suspended !== null && data.suspended !== undefined) {
        html += seBadge(data.suspended ? 'sp-badge-red' : 'sp-badge-green', 'fa-solid fa-ban', data.suspended ? SE_I18N.statusSuspended : SE_I18N.statusActive, SE_I18N.badgeSuspended);
    }
    html += seBadge('sp-badge-green', 'fas fa-check-circle', SE_I18N.statusConnected, '');
    host.innerHTML = html;
}

function renderSeNotLinked() {
    renderSeBadges({ linked: false });
    var linkedPanel = document.getElementById('se-linked-panel');
    var fallback = document.getElementById('se-connect-fallback');
    if (linkedPanel) linkedPanel.style.display = 'none';
    if (fallback) fallback.style.display = '';
}

function renderSeStatus(data) {
    var host = document.getElementById('se-status-host');
    var skeleton = document.getElementById('se-status-skeleton');
    var ready = document.getElementById('se-status-ready');
    var alertEl = document.getElementById('se-expires-alert');
    var textEl = document.getElementById('se-expires-text');
    if (skeleton) skeleton.style.display = 'none';
    if (host) host.setAttribute('aria-busy', 'false');
    if (ready) ready.style.display = '';
    if (alertEl && textEl && data.expires_html) {
        textEl.innerHTML = data.expires_html;
        alertEl.style.display = '';
    }
}

function bindTokenReveal(btnId, eyeId, displayId, getToken, getDots, isVisible, setVisible, revealTitle, revealText, showTitle, hideTitle, showClass) {
    var btn = document.getElementById(btnId);
    var eye = document.getElementById(eyeId);
    var display = document.getElementById(displayId);
    if (!btn || !eye || !display) return;
    btn.addEventListener('click', function() {
        if (!isVisible()) {
            Swal.fire({
                title: revealTitle,
                text: revealText,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: SE_I18N.confirmShow,
                cancelButtonText: SE_I18N.confirmCancel,
                confirmButtonColor: showClass === 'sp-btn-warning' ? '#f39c12' : '#3273dc',
                cancelButtonColor: '#6c757d'
            }).then(function(result) {
                if (result.isConfirmed) {
                    display.value = getToken();
                    eye.classList.remove('fa-eye');
                    eye.classList.add('fa-eye-slash');
                    btn.title = hideTitle;
                    btn.classList.remove(showClass);
                    btn.classList.add('sp-btn-danger');
                    setVisible(true);
                }
            });
        } else {
            display.value = '•'.repeat(getDots());
            eye.classList.remove('fa-eye-slash');
            eye.classList.add('fa-eye');
            btn.title = showTitle;
            btn.classList.remove('sp-btn-danger');
            btn.classList.add(showClass);
            setVisible(false);
        }
    });
}

function renderSeTokens(data) {
    var host = document.getElementById('se-tokens-host');
    var skeleton = document.getElementById('se-tokens-skeleton');
    var grid = document.getElementById('se-tokens-grid');
    var apiCard = document.getElementById('se-api-token-card');
    var jwtCard = document.getElementById('se-jwt-token-card');
    if (skeleton) skeleton.style.display = 'none';
    if (host) host.setAttribute('aria-busy', 'false');
    apiToken = data.api_token || '';
    jwtToken = data.jwt_token || '';
    apiTokenDotCount = apiToken.length;
    jwtTokenDotCount = jwtToken.length;
    var hasAny = !!(apiToken || jwtToken);
    if (!hasAny) {
        if (host) host.style.display = 'none';
        return;
    }
    if (grid) grid.style.display = 'grid';
    if (apiToken && apiCard) {
        var apiDisplay = document.getElementById('apiTokenDisplay');
        if (apiDisplay) apiDisplay.value = '•'.repeat(apiTokenDotCount);
        apiCard.style.display = '';
        bindTokenReveal('showApiTokenBtn', 'apiTokenEye', 'apiTokenDisplay', function() { return apiToken; }, function() { return apiTokenDotCount; }, function() { return apiTokenVisible; }, function(v) { apiTokenVisible = v; }, SE_I18N.revealApiTitle, SE_I18N.revealApiText, SE_I18N.showApiToken, SE_I18N.hideApiToken, 'sp-btn-warning');
    }
    if (jwtToken && jwtCard) {
        var jwtDisplay = document.getElementById('jwtTokenDisplay');
        if (jwtDisplay) jwtDisplay.value = '•'.repeat(jwtTokenDotCount);
        jwtCard.style.display = '';
        bindTokenReveal('showJwtTokenBtn', 'jwtTokenEye', 'jwtTokenDisplay', function() { return jwtToken; }, function() { return jwtTokenDotCount; }, function() { return jwtTokenVisible; }, function(v) { jwtTokenVisible = v; }, SE_I18N.revealJwtTitle, SE_I18N.revealJwtText, SE_I18N.showJwtToken, SE_I18N.hideJwtToken, 'sp-btn-info');
    }
}

function renderSeTips(data) {
    var tbody = document.getElementById('se-tips-tbody');
    var card = document.getElementById('se-tips-card');
    var emptyCard = document.getElementById('se-tips-empty');
    var countEl = document.getElementById('se-tips-count');
    var details = document.getElementById('se-tips-empty-details');
    var tips = Array.isArray(data.tips) ? data.tips : [];
    if (tbody) tbody.setAttribute('aria-busy', 'false');
    if (tips.length) {
        if (card) card.style.display = '';
        if (emptyCard) emptyCard.style.display = 'none';
        if (countEl) countEl.textContent = '(' + tips.length + ')';
        if (tbody) {
            tbody.innerHTML = tips.map(function(tip) {
                return '<tr>' +
                    '<td>' + escapeHtml(tip.username) + '</td>' +
                    '<td><span class="sp-badge sp-badge-green">' + escapeHtml(tip.amount) + '</span></td>' +
                    '<td style="max-width:200px;word-break:break-word;">' + escapeHtml(tip.message) + '</td>' +
                    '<td><span class="sp-badge sp-badge-blue">' + escapeHtml(tip.provider) + '</span></td>' +
                    '<td>' + escapeHtml(tip.date) + '</td>' +
                    '</tr>';
            }).join('');
        }
        return;
    }
    if (card) card.style.display = 'none';
    if (emptyCard) emptyCard.style.display = '';
    if (!details) return;
    var html = '';
    if (data.channel_id) {
        html += '<p><strong>' + escapeHtml(SE_I18N.channelIdLabel) + '</strong> ' + escapeHtml(data.channel_id) + '</p>';
        html += '<p>' + escapeHtml(SE_I18N.channelIdChecking) + '</p>';
        if (data.tips_code !== null && data.tips_code !== undefined) {
            html += '<p><strong>' + escapeHtml(SE_I18N.tipsApiResponseCode) + '</strong> ' + escapeHtml(data.tips_code) + '</p>';
            if (Number(data.tips_code) !== 200) {
                html += '<p style="color:var(--red);">' + escapeHtml(data.tips_failed_message || '') + '</p>';
            } else {
                html += '<p>' + escapeHtml(SE_I18N.tipsApiSuccessEmpty) + '</p>';
            }
        }
    } else {
        html += '<p style="color:var(--amber);">' + escapeHtml(SE_I18N.channelIdUnavailable) + '</p>';
        html += '<p>' + escapeHtml(SE_I18N.currentUserCallFailed) + '</p>';
    }
    details.innerHTML = html;
}

function renderSeError() {
    renderSeBadges({ linked: false });
    var tbody = document.getElementById('se-tips-tbody');
    if (tbody) {
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">' + escapeHtml(SE_I18N.tokenValidationFailed) + '</td></tr>';
    }
    var statusSkeleton = document.getElementById('se-status-skeleton');
    var tokensSkeleton = document.getElementById('se-tokens-skeleton');
    var statusHost = document.getElementById('se-status-host');
    var tokensHost = document.getElementById('se-tokens-host');
    if (statusSkeleton) statusSkeleton.style.display = 'none';
    if (tokensSkeleton) tokensSkeleton.style.display = 'none';
    if (statusHost) statusHost.setAttribute('aria-busy', 'false');
    if (tokensHost) tokensHost.setAttribute('aria-busy', 'false');
}

function loadStreamElements() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderSeError();
                return;
            }
            if (!data.linked) {
                renderSeNotLinked();
                return;
            }
            renderSeBadges(data);
            renderSeStatus(data);
            renderSeTokens(data);
            renderSeTips(data);
        })
        .catch(function() {
            renderSeError();
        });
}

document.addEventListener('DOMContentLoaded', loadStreamElements);
</script>
<?php
$scripts = ob_get_clean();
endif;

include 'layout.php';
?>
