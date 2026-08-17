<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
$pageTitle = t('admin_user_management_title');
$currentAdminUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$currentAdminIsSuperAdmin = false;
$helixAccessToken = (string) ($_SESSION['access_token'] ?? '');
$helixClientId = isset($clientID) ? (string) $clientID : '';

if ($currentAdminUserId > 0) {
    $stmt = $conn->prepare("SELECT super_admin FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $currentAdminUserId);
    $stmt->execute();
    $stmt->bind_result($currentSuperAdminFlag);
    if ($stmt->fetch()) {
        $currentAdminIsSuperAdmin = ((int) $currentSuperAdminFlag === 1);
    }
    $stmt->close();
}

$actAsNotice = null;
$actAsNoticeClass = 'is-info';
if (isset($_GET['act_as'])) {
    $actAsState = (string) $_GET['act_as'];
    switch ($actAsState) {
        case 'invalid':
            $actAsNotice = t('admin_users_act_as_invalid');
            $actAsNoticeClass = 'is-danger';
            break;
        case 'not_found':
            $actAsNotice = t('admin_users_act_as_not_found');
            $actAsNoticeClass = 'is-danger';
            break;
        case 'no_token':
            $actAsNotice = t('admin_users_act_as_no_token');
            $actAsNoticeClass = 'is-warning';
            break;
        case 'error':
            $actAsNotice = t('admin_users_act_as_error');
            $actAsNoticeClass = 'is-danger';
            break;
        case 'started':
            $actAsNotice = t('admin_users_act_as_started');
            $actAsNoticeClass = 'is-success';
            break;
    }
}

if (!function_exists('format_pretty_date')) {
    function format_pretty_date($dateStr) {
        if (!$dateStr) return '-';
        try {
            $dt = new DateTime($dateStr);
        } catch (Exception $e) {
            return '-';
        }
        $day = (int)$dt->format('j');
        if ($day >= 11 && $day <= 13) {
            $suffix = 'th';
        } else {
            switch ($day % 10) {
                case 1: $suffix = 'st'; break;
                case 2: $suffix = 'nd'; break;
                case 3: $suffix = 'rd'; break;
                default: $suffix = 'th';
            }
        }
        return '<span class="admin-date-line">' . $day . $suffix . ' ' . $dt->format('M Y') . '</span><br><span class="admin-time-line">' . $dt->format('g:ia') . '</span>';
    }
}

function admin_users_send_json($payload) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function admin_users_restricted_map($conn) {
    $restricted_users = [];
    $restricted_result = $conn->query("SELECT username, twitch_user_id FROM restricted_users");
    if ($restricted_result) {
        while ($row = $restricted_result->fetch_assoc()) {
            if (!empty($row['username'])) {
                $restricted_users[$row['username']] = true;
            }
            if (!empty($row['twitch_user_id'])) {
                $restricted_users[$row['twitch_user_id']] = true;
            }
        }
    }
    return $restricted_users;
}

function getTwitchSubTiers(array $twitchUserIds, $clientID, $accessToken) {
    $tiers = [];
    $ids = [];
    foreach ($twitchUserIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === [] || $clientID === '' || $accessToken === '') {
        return $tiers;
    }
    $broadcaster_id = '140296994';
    foreach (array_chunk($ids, 100) as $chunk) {
        $query = http_build_query(['broadcaster_id' => $broadcaster_id]);
        foreach ($chunk as $uid) {
            $query .= '&user_id=' . rawurlencode($uid);
        }
        $ch = curl_init('https://api.twitch.tv/helix/subscriptions?' . $query);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Client-ID: ' . $clientID,
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) {
            continue;
        }
        $data = json_decode($response, true);
        if (!isset($data['data']) || !is_array($data['data'])) {
            continue;
        }
        foreach ($data['data'] as $sub) {
            if (!empty($sub['user_id'])) {
                $tiers[(string) $sub['user_id']] = (string) ($sub['tier'] ?? '');
            }
        }
    }
    return $tiers;
}

function mask_email($email) {
    if (!$email) return '';
    $atPos = strpos($email, '@');
    if ($atPos === false) return str_repeat('�', strlen($email));
    return str_repeat('�', $atPos) . substr($email, $atPos);
}

function mask_api_key($api_key) {
    if (!$api_key) return '';
    return str_repeat('�', strlen($api_key));
}

$isListAjax = isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list';
$isPremiumAjax = isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'premium';
$isAjaxPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// Release the session lock before Helix / list work so the shell request is not blocked.
if ($isListAjax || $isPremiumAjax || $isAjaxPost) {
    session_write_close();
}

if ($isListAjax) {
    $users = [];
    $restricted_users = admin_users_restricted_map($conn);
    $stmt = $conn->prepare("SELECT * FROM users ORDER BY id ASC");
    if (!$stmt) {
        admin_users_send_json(['success' => false, 'error' => t('admin_users_load_failed')]);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $usernameKey = (string) ($row['username'] ?? '');
        $twitchKey = !empty($row['twitch_user_id']) ? (string) $row['twitch_user_id'] : '';
        $users[] = [
            'id' => (int) $row['id'],
            'username' => $row['username'] ?? '',
            'twitch_display_name' => $row['twitch_display_name'] ?? '',
            'twitch_user_id' => $twitchKey !== '' ? $twitchKey : null,
            'profile_image' => $row['profile_image'] ?? '',
            'is_admin' => (int) ($row['is_admin'] ?? 0),
            'super_admin' => (int) ($row['super_admin'] ?? 0),
            'beta_access' => (int) ($row['beta_access'] ?? 0),
            'beta_programs' => $row['beta_programs'] ?? '[]',
            'is_deceased' => (int) ($row['is_deceased'] ?? 0),
            'deceased_date' => $row['deceased_date'] ?? null,
            'signup_date' => $row['signup_date'] ?? null,
            'last_login' => $row['last_login'] ?? null,
            'email' => $row['email'] ?? '',
            'api_key' => $row['api_key'] ?? '',
            'is_restricted' => ($usernameKey !== '' && isset($restricted_users[$usernameKey]))
                || ($twitchKey !== '' && isset($restricted_users[$twitchKey])),
            'signup_html' => format_pretty_date($row['signup_date'] ?? ''),
            'last_login_html' => format_pretty_date($row['last_login'] ?? ''),
        ];
    }
    $stmt->close();
    admin_users_send_json([
        'success' => true,
        'users' => $users,
        'current_admin_id' => $currentAdminUserId,
        'is_super_admin' => $currentAdminIsSuperAdmin,
    ]);
}

if ($isPremiumAjax) {
    $twitchIds = [];
    $idResult = $conn->query("SELECT twitch_user_id FROM users WHERE twitch_user_id IS NOT NULL AND twitch_user_id <> ''");
    if ($idResult) {
        while ($idRow = $idResult->fetch_assoc()) {
            $twitchIds[] = $idRow['twitch_user_id'];
        }
    }
    $tiers = getTwitchSubTiers($twitchIds, $helixClientId, $helixAccessToken);
    admin_users_send_json(['success' => true, 'tiers' => $tiers]);
}

// Handle AJAX delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['delete_user_id']);
    $delete_db = isset($_POST['delete_db']) ? $_POST['delete_db'] : null;
    $response = ['success' => false, 'msg' => ''];
    // Get username from POST if provided (sent from JS before user row is deleted)
    $username = isset($_POST['username']) ? $_POST['username'] : null;
    // If not provided, fallback to DB lookup (for safety)
    if (!$username) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($username);
        $stmt->fetch();
        $stmt->close();
    }
    if (!$username) {
        $response['msg'] = t('admin_users_msg_user_not_found');
        echo json_encode($response);
        exit;
    }
    // Cannot delete yourself
    if ($user_id === $currentAdminUserId) {
        $response['msg'] = t('admin_users_msg_cannot_delete_self');
        echo json_encode($response);
        exit;
    }
    // Non-super-admins cannot delete admins or super admins
    if (!$currentAdminIsSuperAdmin) {
        $chkStmt = $conn->prepare("SELECT is_admin, super_admin FROM users WHERE id = ? LIMIT 1");
        $chkStmt->bind_param("i", $user_id);
        $chkStmt->execute();
        $chkStmt->bind_result($targetIsAdminFlag, $targetIsSuperAdminFlag);
        $chkStmt->fetch();
        $chkStmt->close();
        if ($targetIsAdminFlag || $targetIsSuperAdminFlag) {
            $response['msg'] = t('admin_users_msg_no_permission_delete_admin');
            echo json_encode($response);
            exit;
        }
    }
    if ($delete_db === '1') {
        // Drop the user's database
        $db_name = $username;
        require_once "/var/www/config/database.php";
        $mysqli = new mysqli($db_servername, $db_username, $db_password);
        if ($mysqli->connect_errno) {
            $response['msg'] = t('admin_users_msg_db_connect_failed');
            echo json_encode($response);
            exit;
        }
        if ($mysqli->query("DROP DATABASE `" . $mysqli->real_escape_string($db_name) . "`")) {
            $response['success'] = true;
            $response['msg'] = t('admin_users_msg_db_deleted');
        } else {
            $response['msg'] = t('admin_users_msg_db_delete_failed');
        }
        $mysqli->close();
        echo json_encode($response);
        exit;
    } else {
        // Delete user from users table
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['username'] = $username;
        } else {
            $response['msg'] = t('admin_users_msg_delete_user_failed');
        }
        $stmt->close();
        echo json_encode($response);
        exit;
    }
}

// Handle AJAX restrict/unrestrict requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restrict_action'])) {
    $action = $_POST['restrict_action'];
    $username = $_POST['username'] ?? '';
    $twitch_user_id = $_POST['twitch_user_id'] ?? '';
    $target_user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $response = ['success' => false, 'msg' => ''];
    $targetIsAdmin = false;
    $targetIsSuperAdmin = false;
    if ($target_user_id > 0) {
        $targetStmt = $conn->prepare("SELECT is_admin, super_admin FROM users WHERE id = ? LIMIT 1");
        $targetStmt->bind_param("i", $target_user_id);
    } else {
        $targetStmt = $conn->prepare("SELECT is_admin, super_admin FROM users WHERE username = ? OR twitch_user_id = ? LIMIT 1");
        $targetStmt->bind_param("ss", $username, $twitch_user_id);
    }
    $targetStmt->execute();
    $targetStmt->bind_result($targetIsAdminRaw, $targetIsSuperAdminRaw);
    if ($targetStmt->fetch()) {
        $targetIsAdmin = ((int) $targetIsAdminRaw === 1);
        $targetIsSuperAdmin = ((int) $targetIsSuperAdminRaw === 1);
    }
    $targetStmt->close();
    if ($action === 'restrict') {
        if ($targetIsSuperAdmin) {
            $response['msg'] = t('admin_users_msg_super_admin_cannot_restrict');
            echo json_encode($response);
            exit;
        }
        if ($targetIsAdmin && !$currentAdminIsSuperAdmin) {
            $response['msg'] = t('admin_users_msg_only_super_restrict_admins');
            echo json_encode($response);
            exit;
        }
        $stmt = $conn->prepare("INSERT IGNORE INTO restricted_users (username, twitch_user_id) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $twitch_user_id);
        $response['success'] = $stmt->execute();
        $stmt->close();
        if (!$response['success']) $response['msg'] = t('admin_users_msg_restrict_failed');
    } elseif ($action === 'unrestrict') {
        $stmt = $conn->prepare("DELETE FROM restricted_users WHERE username = ? OR twitch_user_id = ?");
        $stmt->bind_param("ss", $username, $twitch_user_id);
        $response['success'] = $stmt->execute();
        $stmt->close();
        if (!$response['success']) $response['msg'] = t('admin_users_msg_unrestrict_failed');
    }
    echo json_encode($response);
    exit;
}

// Handle AJAX beta_programs add/remove request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['beta_programs_action'])) {
    $action   = $_POST['beta_programs_action'];
    $user_id  = isset($_POST['user_id'])  ? (int) $_POST['user_id']            : 0;
    $program  = isset($_POST['program'])  ? trim($_POST['program'])             : '';
    $response = ['success' => false, 'msg' => ''];
    if (($action !== 'add' && $action !== 'remove') || $user_id <= 0 || $program === '') {
        $response['msg'] = t('admin_users_msg_invalid_beta_programs_request');
        echo json_encode($response);
        exit;
    }
    $fetchStmt = $conn->prepare("SELECT beta_programs FROM users WHERE id = ? LIMIT 1");
    $fetchStmt->bind_param("i", $user_id);
    $fetchStmt->execute();
    $fetchStmt->bind_result($raw);
    $fetchStmt->fetch();
    $fetchStmt->close();
    $programs = json_decode($raw ?? '[]', true) ?? [];
    if ($action === 'add') {
        if (!in_array($program, $programs)) {
            $programs[] = $program;
        }
    } else {
        $programs = array_values(array_filter($programs, fn($p) => $p !== $program));
    }
    $newJson = json_encode(array_values($programs));
    $updStmt = $conn->prepare("UPDATE users SET beta_programs = ? WHERE id = ?");
    $updStmt->bind_param("si", $newJson, $user_id);
    if ($updStmt->execute()) {
        $response['success'] = true;
    } else {
        $response['msg'] = t('admin_users_msg_update_beta_programs_failed');
    }
    $updStmt->close();
    echo json_encode($response);
    exit;
}

// Handle AJAX beta access grant request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['beta_action'])) {
    $action = $_POST['beta_action'];
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $response = ['success' => false, 'msg' => ''];
    if (($action !== 'grant_beta' && $action !== 'remove_beta') || $user_id <= 0) {
        $response['msg'] = t('admin_users_msg_invalid_beta_access_request');
        echo json_encode($response);
        exit;
    }
    // Non-super-admins cannot modify beta access for super admin users
    if (!$currentAdminIsSuperAdmin) {
        $saChk = $conn->prepare("SELECT super_admin FROM users WHERE id = ? LIMIT 1");
        $saChk->bind_param("i", $user_id);
        $saChk->execute();
        $saChk->bind_result($targetSAFlag);
        $saChk->fetch();
        $saChk->close();
        if ((int) $targetSAFlag === 1) {
            $response['msg'] = t('admin_users_msg_only_super_manage_super');
            echo json_encode($response);
            exit;
        }
    }
    $betaValue = ($action === 'grant_beta') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET beta_access = ? WHERE id = ?");
    $stmt->bind_param("ii", $betaValue, $user_id);
    if ($stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['msg'] = t('admin_users_msg_update_beta_access_failed');
    }
    $stmt->close();
    echo json_encode($response);
    exit;
}

// Handle AJAX admin grant request (super admins only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    $action = $_POST['admin_action'];
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $response = ['success' => false, 'msg' => ''];
    if (!$currentAdminIsSuperAdmin) {
        $response['msg'] = t('admin_users_msg_only_super_grant_admin');
        echo json_encode($response);
        exit;
    }
    if (($action !== 'grant_admin' && $action !== 'remove_admin') || $user_id <= 0) {
        $response['msg'] = t('admin_users_msg_invalid_admin_access_request');
        echo json_encode($response);
        exit;
    }
    $adminValue = ($action === 'grant_admin') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    $stmt->bind_param("ii", $adminValue, $user_id);
    if ($stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['msg'] = t('admin_users_msg_update_admin_access_failed');
    }
    $stmt->close();
    echo json_encode($response);
    exit;
}

// Handle AJAX memorial/deceased action (super admins only)
// Required DB migration: ALTER TABLE users ADD COLUMN is_deceased TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN deceased_date DATE NULL DEFAULT NULL;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deceased_action'])) {
    $action = $_POST['deceased_action'];
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $response = ['success' => false, 'msg' => ''];
    if (!$currentAdminIsSuperAdmin) {
        $response['msg'] = t('admin_users_msg_only_super_manage_memorial');
        echo json_encode($response);
        exit;
    }
    if (($action !== 'mark_deceased' && $action !== 'unmark_deceased') || $user_id <= 0) {
        $response['msg'] = t('admin_users_msg_invalid_memorial_request');
        echo json_encode($response);
        exit;
    }
    if ($action === 'mark_deceased') {
        $deceasedDate = date('Y-m-d');
        $stmt = $conn->prepare("UPDATE users SET is_deceased = 1, deceased_date = ? WHERE id = ?");
        $stmt->bind_param("si", $deceasedDate, $user_id);
        if ($stmt->execute()) {
            $stmt->close();
            // Automatically restrict the account to prevent login
            $usernameStmt = $conn->prepare("SELECT username, twitch_user_id FROM users WHERE id = ? LIMIT 1");
            $usernameStmt->bind_param("i", $user_id);
            $usernameStmt->execute();
            $usernameStmt->bind_result($decUsername, $decTwitchId);
            $usernameStmt->fetch();
            $usernameStmt->close();
            if ($decUsername) {
                $restrictStmt = $conn->prepare("INSERT IGNORE INTO restricted_users (username, twitch_user_id) VALUES (?, ?)");
                $restrictStmt->bind_param("ss", $decUsername, $decTwitchId);
                $restrictStmt->execute();
                $restrictStmt->close();
            }
            $response['success'] = true;
        } else {
            $stmt->close();
            $response['msg'] = t('admin_users_msg_mark_memorial_failed');
        }
    } elseif ($action === 'unmark_deceased') {
        $stmt = $conn->prepare("UPDATE users SET is_deceased = 0, deceased_date = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $response['success'] = true;
        } else {
            $response['msg'] = t('admin_users_msg_remove_memorial_failed');
        }
        $stmt->close();
    }
    echo json_encode($response);
    exit;
}

include '../includes/userdata.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ob_start();
?>
<?php if ($actAsNotice): ?>
    <?php $alertClass = str_replace('is-', '', $actAsNoticeClass); ?>
    <div class="sp-alert sp-alert-<?php echo htmlspecialchars($alertClass); ?>">
        <?php echo htmlspecialchars($actAsNotice); ?>
    </div>
<?php endif; ?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-users-cog" style="margin-right:0.5rem;"></i><?php echo t('admin_user_management_title'); ?></h1>
        <div class="search-wrapper" style="max-width:320px;">
            <span class="search-icon"><i class="fas fa-search"></i></span>
            <input class="search-input" type="text" placeholder="<?php echo htmlspecialchars(t('admin_users_search_placeholder')); ?>" id="user-search" autocomplete="off"<?php if (isset($_GET['search'])) { echo ' value="' . htmlspecialchars(trim((string) $_GET['search']), ENT_QUOTES) . '"'; } ?>>
            <button type="button" class="search-clear" id="user-search-clear" style="display:none;" onclick="document.getElementById('user-search').value='';this.style.display='none';filterUsers();"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table admin-users-table">
                <thead>
                    <tr>
                        <th style="text-align:center;"><?php echo t('admin_users_th_id'); ?></th>
                        <th><?php echo t('admin_users_th_user'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_admin'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_super_admin'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_beta'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_beta_programs'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_premium'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_signup'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_last_login'); ?></th>
                        <th style="text-align:center;"><?php echo t('admin_users_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="admin-users-tbody" aria-busy="true">
                    <?php for ($sk = 0; $sk < 8; $sk++): ?>
                    <tr aria-hidden="true">
                        <td style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-line w-40"></span></td>
                        <td style="vertical-align:middle;">
                            <div style="display:flex;align-items:center;gap:0.5rem;">
                                <span class="sp-skeleton-avatar"></span>
                                <span class="sp-skeleton-line w-60"></span>
                            </div>
                        </td>
                        <td style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                        <td class="admin-date-cell" style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-line w-70"></span></td>
                        <td class="admin-date-cell" style="text-align:center;vertical-align:middle;"><span class="sp-skeleton-line w-70"></span></td>
                        <td style="text-align:center;vertical-align:middle;">
                            <div class="actions-wrap">
                                <span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>
                                <span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>
                                <span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>
                                <span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>
                            </div>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Sensitive Info Modal -->
<div class="sp-modal-backdrop" id="sensitive-modal" onclick="closeSensitiveModal()">
    <div class="sp-modal" style="max-width:800px;" onclick="event.stopPropagation()">
        <div class="sp-modal-head">
            <h2 class="sp-modal-title"><i class="fas fa-user-secret" style="margin-right:0.5rem;"></i><?php echo t('admin_users_modal_title'); ?></h2>
            <button class="sp-modal-close" aria-label="<?php echo htmlspecialchars(t('admin_users_aria_close')); ?>" onclick="closeSensitiveModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="sp-modal-body" id="sensitive-modal-content">
            <!-- Populated by JS -->
        </div>
        <div style="padding:1rem;display:flex;justify-content:flex-end;gap:0.5rem;border-top:1px solid var(--border);">
            <button class="sp-btn sp-btn-primary" id="export-sensitive-btn" onclick="exportSensitiveUser()" title="<?php echo htmlspecialchars(t('admin_users_btn_export_user_data')); ?>"><i class="fas fa-download" style="margin-right:0.4rem;"></i><?php echo t('admin_users_btn_export_data'); ?></button>
            <button class="sp-btn sp-btn-secondary" onclick="closeSensitiveModal()"><?php echo t('admin_users_btn_close'); ?></button>
        </div>
    </div>
</div>
<script>
let usersData = [];
let premiumTiers = {};
let premiumLoaded = false;
const currentAdminUserId = <?php echo (int) $currentAdminUserId; ?>;
const currentAdminIsSuperAdmin = <?php echo $currentAdminIsSuperAdmin ? 'true' : 'false'; ?>;
const T = {
    twitch_id: <?php echo json_encode(t('admin_users_modal_twitch_id')); ?>,
    th_admin: <?php echo json_encode(t('admin_users_th_admin')); ?>,
    th_super_admin: <?php echo json_encode(t('admin_users_th_super_admin')); ?>,
    beta_access: <?php echo json_encode(t('admin_users_modal_beta_access')); ?>,
    beta_programs: <?php echo json_encode(t('admin_users_th_beta_programs')); ?>,
    premium_access: <?php echo json_encode(t('admin_users_modal_premium_access')); ?>,
    signup_date: <?php echo json_encode(t('admin_users_modal_signup_date')); ?>,
    last_login: <?php echo json_encode(t('admin_users_th_last_login')); ?>,
    email: <?php echo json_encode(t('admin_users_modal_email')); ?>,
    api_key: <?php echo json_encode(t('admin_users_modal_api_key')); ?>,
    val_true: <?php echo json_encode(t('admin_users_val_true')); ?>,
    val_false: <?php echo json_encode(t('admin_users_val_false')); ?>,
    tier_1: <?php echo json_encode(t('admin_users_tier_1')); ?>,
    tier_2: <?php echo json_encode(t('admin_users_tier_2')); ?>,
    tier_3: <?php echo json_encode(t('admin_users_tier_3')); ?>,
    tier_none: <?php echo json_encode(t('admin_users_tier_none')); ?>,
    none: <?php echo json_encode(t('admin_users_val_none')); ?>,
    reveal_title: <?php echo json_encode(t('admin_users_js_reveal_title')); ?>,
    reveal_text: <?php echo json_encode(t('admin_users_js_reveal_text')); ?>,
    reveal_confirm: <?php echo json_encode(t('admin_users_js_reveal_confirm')); ?>,
    cancel: <?php echo json_encode(t('admin_users_js_cancel')); ?>,
    label_email: <?php echo json_encode(t('admin_users_modal_email')); ?>,
    label_api_key: <?php echo json_encode(t('admin_users_modal_api_key')); ?>,
    memorial_delete_warning: <?php echo json_encode(t('admin_users_js_memorial_delete_warning')); ?>,
    delete_user_title: <?php echo json_encode(t('admin_users_js_delete_user_title')); ?>,
    delete_user_html: <?php echo json_encode(t('admin_users_js_delete_user_html')); ?>,
    yes: <?php echo json_encode(t('admin_users_js_yes')); ?>,
    no: <?php echo json_encode(t('admin_users_js_no')); ?>,
    delete_db_title: <?php echo json_encode(t('admin_users_js_delete_db_title')); ?>,
    delete_db_html: <?php echo json_encode(t('admin_users_js_delete_db_html')); ?>,
    final_confirm_title: <?php echo json_encode(t('admin_users_js_final_confirm_title')); ?>,
    final_confirm_with_db: <?php echo json_encode(t('admin_users_js_final_confirm_with_db')); ?>,
    final_confirm_no_db: <?php echo json_encode(t('admin_users_js_final_confirm_no_db')); ?>,
    yes_delete: <?php echo json_encode(t('admin_users_js_yes_delete')); ?>,
    deleted_title: <?php echo json_encode(t('admin_users_js_deleted_title')); ?>,
    deleted_user_and_db: <?php echo json_encode(t('admin_users_js_deleted_user_and_db')); ?>,
    user_deleted_title: <?php echo json_encode(t('admin_users_js_user_deleted_title')); ?>,
    db_delete_failed_html: <?php echo json_encode(t('admin_users_js_db_delete_failed_html')); ?>,
    db_delete_reason: <?php echo json_encode(t('admin_users_js_db_delete_reason')); ?>,
    unknown_error: <?php echo json_encode(t('admin_users_js_unknown_error')); ?>,
    deleted_user_no_db: <?php echo json_encode(t('admin_users_js_deleted_user_no_db')); ?>,
    error: <?php echo json_encode(t('admin_users_js_error')); ?>,
    could_not_delete_user: <?php echo json_encode(t('admin_users_msg_delete_user_failed')); ?>,
    restrict_note_label: <?php echo json_encode(t('admin_users_js_restrict_note_label')); ?>,
    restrict_note_1: <?php echo json_encode(t('admin_users_js_restrict_note_1')); ?>,
    restrict_note_2: <?php echo json_encode(t('admin_users_js_restrict_note_2')); ?>,
    restrict_note_3: <?php echo json_encode(t('admin_users_js_restrict_note_3')); ?>,
    unrestrict_word: <?php echo json_encode(t('admin_users_btn_unrestrict')); ?>,
    confirm_restrict_html: <?php echo json_encode(t('admin_users_js_confirm_restrict_html')); ?>,
    action_restrict_word: <?php echo json_encode(t('admin_users_js_action_word_restrict')); ?>,
    action_unrestrict_word: <?php echo json_encode(t('admin_users_js_action_word_unrestrict')); ?>,
    restrict_user_title: <?php echo json_encode(t('admin_users_js_restrict_user_title')); ?>,
    remove_restriction_title: <?php echo json_encode(t('admin_users_js_remove_restriction_title')); ?>,
    confirm_restrict_word: <?php echo json_encode(t('admin_users_btn_restrict')); ?>,
    success: <?php echo json_encode(t('admin_users_js_success')); ?>,
    user_restricted: <?php echo json_encode(t('admin_users_js_user_restricted')); ?>,
    restriction_removed: <?php echo json_encode(t('admin_users_js_restriction_removed')); ?>,
    could_not_update_restriction: <?php echo json_encode(t('admin_users_js_could_not_update_restriction')); ?>,
    grant_beta_title: <?php echo json_encode(t('admin_users_js_grant_beta_title')); ?>,
    grant_beta_html: <?php echo json_encode(t('admin_users_js_grant_beta_html')); ?>,
    grant_word: <?php echo json_encode(t('admin_users_js_grant_word')); ?>,
    updated: <?php echo json_encode(t('admin_users_js_updated')); ?>,
    beta_access_granted: <?php echo json_encode(t('admin_users_js_beta_access_granted')); ?>,
    could_not_update_beta_access: <?php echo json_encode(t('admin_users_msg_update_beta_access_failed')); ?>,
    remove_beta_title: <?php echo json_encode(t('admin_users_js_remove_beta_title')); ?>,
    remove_beta_html: <?php echo json_encode(t('admin_users_js_remove_beta_html')); ?>,
    remove_word: <?php echo json_encode(t('admin_users_js_remove_word')); ?>,
    beta_access_removed: <?php echo json_encode(t('admin_users_js_beta_access_removed')); ?>,
    beta_programs_title: <?php echo json_encode(t('admin_users_js_beta_programs_title')); ?>,
    current_programs_label: <?php echo json_encode(t('admin_users_js_current_programs_label')); ?>,
    none_em: <?php echo json_encode(t('admin_users_js_none')); ?>,
    program_placeholder: <?php echo json_encode(t('admin_users_js_program_placeholder')); ?>,
    add_word: <?php echo json_encode(t('admin_users_js_add_word')); ?>,
    close_word: <?php echo json_encode(t('admin_users_js_close')); ?>,
    added: <?php echo json_encode(t('admin_users_js_added')); ?>,
    added_program_html: <?php echo json_encode(t('admin_users_js_added_program_html')); ?>,
    could_not_update_beta_programs: <?php echo json_encode(t('admin_users_msg_update_beta_programs_failed')); ?>,
    remove_program_title: <?php echo json_encode(t('admin_users_js_remove_program_title')); ?>,
    remove_program_html: <?php echo json_encode(t('admin_users_js_remove_program_html')); ?>,
    removed: <?php echo json_encode(t('admin_users_js_removed')); ?>,
    removed_program_html: <?php echo json_encode(t('admin_users_js_removed_program_html')); ?>,
    grant_admin_title: <?php echo json_encode(t('admin_users_js_grant_admin_title')); ?>,
    grant_admin_html: <?php echo json_encode(t('admin_users_js_grant_admin_html')); ?>,
    admin_access_granted: <?php echo json_encode(t('admin_users_js_admin_access_granted')); ?>,
    could_not_update_admin_access: <?php echo json_encode(t('admin_users_msg_update_admin_access_failed')); ?>,
    remove_admin_title: <?php echo json_encode(t('admin_users_js_remove_admin_title')); ?>,
    remove_admin_html: <?php echo json_encode(t('admin_users_js_remove_admin_html')); ?>,
    admin_access_removed: <?php echo json_encode(t('admin_users_js_admin_access_removed')); ?>,
    mark_memorial_title: <?php echo json_encode(t('admin_users_js_mark_memorial_title')); ?>,
    mark_memorial_html: <?php echo json_encode(t('admin_users_js_mark_memorial_html')); ?>,
    mark_memorial_confirm: <?php echo json_encode(t('admin_users_js_mark_memorial_confirm')); ?>,
    preserved: <?php echo json_encode(t('admin_users_js_preserved')); ?>,
    marked_memorial: <?php echo json_encode(t('admin_users_js_marked_memorial')); ?>,
    could_not_mark_memorial: <?php echo json_encode(t('admin_users_msg_mark_memorial_failed')); ?>,
    remove_memorial_title: <?php echo json_encode(t('admin_users_js_remove_memorial_title')); ?>,
    remove_memorial_html: <?php echo json_encode(t('admin_users_js_remove_memorial_html')); ?>,
    memorial_status_removed: <?php echo json_encode(t('admin_users_js_memorial_status_removed')); ?>,
    could_not_remove_memorial: <?php echo json_encode(t('admin_users_msg_remove_memorial_failed')); ?>,
    no_user_export: <?php echo json_encode(t('admin_users_js_no_user_export')); ?>,
    export_title: <?php echo json_encode(t('admin_users_js_export_title')); ?>,
    export_html: <?php echo json_encode(t('admin_users_js_export_html')); ?>,
    export_account_email: <?php echo json_encode(t('admin_users_js_export_account_email')); ?>,
    yes_export: <?php echo json_encode(t('admin_users_js_yes_export')); ?>,
    queued: <?php echo json_encode(t('admin_users_js_queued')); ?>,
    export_started: <?php echo json_encode(t('admin_users_js_export_started')); ?>,
    could_not_start_export: <?php echo json_encode(t('admin_users_js_could_not_start_export')); ?>,
    could_not_reach_export: <?php echo json_encode(t('admin_users_js_could_not_reach_export')); ?>,
    profile_alt: <?php echo json_encode(t('admin_users_modal_profile_alt')); ?>,
    none_found: <?php echo json_encode(t('admin_users_none_found')); ?>,
    load_failed: <?php echo json_encode(t('admin_users_load_failed')); ?>,
    label_memorial: <?php echo json_encode(t('admin_users_label_memorial')); ?>,
    label_restricted: <?php echo json_encode(t('admin_users_label_restricted')); ?>,
    title_not_admin: <?php echo json_encode(t('admin_users_title_not_admin')); ?>,
    title_not_super_admin: <?php echo json_encode(t('admin_users_title_not_super_admin')); ?>,
    title_beta_access: <?php echo json_encode(t('admin_users_title_beta_access')); ?>,
    title_no_beta_access: <?php echo json_encode(t('admin_users_title_no_beta_access')); ?>,
    btn_view_details: <?php echo json_encode(t('admin_users_btn_view_details')); ?>,
    btn_delete_user: <?php echo json_encode(t('admin_users_btn_delete_user')); ?>,
    title_cannot_delete_self: <?php echo json_encode(t('admin_users_title_cannot_delete_self')); ?>,
    title_only_super_delete_admin: <?php echo json_encode(t('admin_users_title_only_super_delete_admin')); ?>,
    title_memorial_cannot_delete: <?php echo json_encode(t('admin_users_title_memorial_cannot_delete')); ?>,
    btn_remove_admin: <?php echo json_encode(t('admin_users_btn_remove_admin')); ?>,
    btn_give_admin: <?php echo json_encode(t('admin_users_btn_give_admin')); ?>,
    title_only_super_manage_super: <?php echo json_encode(t('admin_users_title_only_super_manage_super')); ?>,
    btn_remove_beta: <?php echo json_encode(t('admin_users_btn_remove_beta')); ?>,
    btn_give_beta: <?php echo json_encode(t('admin_users_btn_give_beta')); ?>,
    btn_manage_beta_programs: <?php echo json_encode(t('admin_users_btn_manage_beta_programs')); ?>,
    btn_restrict: <?php echo json_encode(t('admin_users_btn_restrict')); ?>,
    title_cannot_restrict: <?php echo json_encode(t('admin_users_title_cannot_restrict')); ?>,
    btn_remove_memorial: <?php echo json_encode(t('admin_users_btn_remove_memorial')); ?>,
    btn_mark_memorial: <?php echo json_encode(t('admin_users_btn_mark_memorial')); ?>,
    btn_act_as: <?php echo json_encode(t('admin_users_btn_act_as')); ?>,
    title_already_own_dashboard: <?php echo json_encode(t('admin_users_title_already_own_dashboard')); ?>
};
function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}
function parseAjaxJson(text) {
    try {
        return JSON.parse(String(text || '').replace(/^\uFEFF/, ''));
    } catch (e) {
        return null;
    }
}
function isTruthyFlag(value) {
    return value === true || value === 1 || value === '1';
}
function userPrograms(user) {
    if (!user) return [];
    if (Array.isArray(user.beta_programs)) return user.beta_programs;
    try {
        const parsed = JSON.parse(user.beta_programs || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}
function flagBadge(on, onClass, onTitle, offTitle) {
    if (on) {
        return '<span class="sp-badge ' + onClass + '" title="' + escapeHtml(onTitle) + '"><i class="fas fa-check"></i></span>';
    }
    return '<span class="sp-badge sp-badge-grey" title="' + escapeHtml(offTitle) + '"><i class="fas fa-minus"></i></span>';
}
function premiumBadgeHtml(twitchUserId) {
    if (!premiumLoaded) {
        return '<span class="sp-skeleton-badge" aria-hidden="true"></span>';
    }
    const tier = twitchUserId ? (premiumTiers[String(twitchUserId)] || null) : null;
    if (tier === '1000') return '<span class="sp-badge sp-badge-amber">' + escapeHtml(T.tier_1) + '</span>';
    if (tier === '2000') return '<span class="sp-badge sp-badge-blue">' + escapeHtml(T.tier_2) + '</span>';
    if (tier === '3000') return '<span class="sp-badge sp-badge-red">' + escapeHtml(T.tier_3) + '</span>';
    return '<span class="sp-badge sp-badge-grey">' + escapeHtml(T.tier_none) + '</span>';
}
function applyPremiumTiers(tiers) {
    premiumTiers = tiers || {};
    premiumLoaded = true;
    usersData.forEach(function(user) {
        user.twitch_sub_tier = user.twitch_user_id ? (premiumTiers[String(user.twitch_user_id)] || null) : null;
    });
    document.querySelectorAll('[data-premium-cell]').forEach(function(el) {
        el.innerHTML = premiumBadgeHtml(el.getAttribute('data-premium-cell'));
    });
}
function renderUserRow(user) {
    const userId = Number(user.id);
    const isRestricted = isTruthyFlag(user.is_restricted);
    const isSuperAdmin = isTruthyFlag(user.super_admin);
    const isAdminUser = isTruthyFlag(user.is_admin);
    const isDeceased = isTruthyFlag(user.is_deceased);
    const hasBeta = isTruthyFlag(user.beta_access);
    const canRestrictUser = !isSuperAdmin && (!isAdminUser || currentAdminIsSuperAdmin);
    const canDeleteUser = (userId !== currentAdminUserId)
        && (currentAdminIsSuperAdmin || (!isAdminUser && !isSuperAdmin));
    const targetLockedForAdmin = isSuperAdmin && !currentAdminIsSuperAdmin;
    const rowClass = isDeceased ? 'is-memorial-row' : (isRestricted ? 'is-restricted-row' : '');
    const programs = userPrograms(user);
    let statusBadge = '';
    if (isDeceased) {
        statusBadge = '<span class="sp-badge memorial-label"><i class="fas fa-dove"></i>&nbsp;' + escapeHtml(T.label_memorial) + '</span>';
    } else if (isRestricted) {
        statusBadge = '<span class="sp-badge sp-badge-amber restricted-label">' + escapeHtml(T.label_restricted) + '</span>';
    }
    const programHtml = programs.length
        ? programs.map(function(prog) {
            return '<span class="sp-badge sp-badge-blue" style="margin:1px;">' + escapeHtml(prog) + '</span>';
        }).join('')
        : '<span class="sp-badge sp-badge-grey"><i class="fas fa-minus"></i></span>';
    let deleteTitle = T.btn_delete_user;
    if (userId === currentAdminUserId) deleteTitle = T.title_cannot_delete_self;
    else if (!currentAdminIsSuperAdmin && (isAdminUser || isSuperAdmin)) deleteTitle = T.title_only_super_delete_admin;
    else if (isDeceased) deleteTitle = T.title_memorial_cannot_delete;
    const avatar = user.profile_image ? user.profile_image : 'https://cdn.botofthespecter.com/logo.png';
    const twitchId = user.twitch_user_id ? String(user.twitch_user_id) : '';
    const betaTitle = targetLockedForAdmin
        ? T.title_only_super_manage_super
        : (hasBeta ? T.btn_remove_beta : T.btn_give_beta);
    const restrictTitle = isRestricted
        ? T.unrestrict_word
        : (canRestrictUser ? T.btn_restrict : T.title_cannot_restrict);
    let actions = '';
    actions += '<button class="sp-btn sp-btn-sm" title="' + escapeHtml(T.btn_view_details) + '" onclick="showSensitiveModal(' + userId + ')">'
        + '<span class="icon"><i class="fas fa-eye"></i></span></button>';
    actions += '<button class="sp-btn sp-btn-danger sp-btn-sm" title="' + escapeHtml(deleteTitle) + '" onclick="deleteUser(' + userId + ')"'
        + ((!canDeleteUser || isDeceased) ? ' disabled' : '') + '>'
        + '<span class="icon"><i class="fas fa-trash"></i></span></button>';
    if (isAdminUser) {
        actions += '<button class="sp-btn sp-btn-warning sp-btn-sm" onclick="removeAdminAccess(' + userId + ')" title="'
            + escapeHtml(T.btn_remove_admin) + '"'
            + ((!currentAdminIsSuperAdmin || isDeceased) ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-user-shield"></i></span></button>';
    } else {
        actions += '<button class="sp-btn sp-btn-primary sp-btn-sm" onclick="grantAdminAccess(' + userId + ')" title="'
            + escapeHtml(T.btn_give_admin) + '"'
            + ((!currentAdminIsSuperAdmin || isDeceased) ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-user-shield"></i></span></button>';
    }
    if (hasBeta) {
        actions += '<button class="sp-btn sp-btn-warning sp-btn-sm" onclick="removeBetaAccess(' + userId + ')" title="'
            + escapeHtml(betaTitle) + '"'
            + ((isDeceased || targetLockedForAdmin) ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-flask"></i></span></button>';
    } else {
        actions += '<button class="sp-btn sp-btn-primary sp-btn-sm" onclick="grantBetaAccess(' + userId + ')" title="'
            + escapeHtml(betaTitle) + '"'
            + ((isDeceased || targetLockedForAdmin) ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-flask"></i></span></button>';
    }
    actions += '<button class="sp-btn sp-btn-primary sp-btn-sm" onclick="manageBetaPrograms(' + userId + ')" title="'
        + escapeHtml(T.btn_manage_beta_programs) + '"'
        + (isDeceased ? ' disabled' : '') + '>'
        + '<span class="icon"><i class="fas fa-list-check"></i></span></button>';
    if (isRestricted) {
        actions += '<button class="sp-btn sp-btn-warning sp-btn-sm" title="' + escapeHtml(restrictTitle) + '" onclick="toggleRestrictUser(' + userId + ', false)"'
            + (isDeceased ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-user-lock"></i></span></button>';
    } else {
        actions += '<button class="sp-btn sp-btn-sm" title="' + escapeHtml(restrictTitle) + '" onclick="toggleRestrictUser(' + userId + ', true)"'
            + ((!canRestrictUser || isDeceased) ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-user-lock"></i></span></button>';
    }
    if (isDeceased) {
        actions += '<button class="sp-btn sp-btn-sm memorial-action-btn" title="' + escapeHtml(T.btn_remove_memorial) + '" onclick="unmarkDeceased(' + userId + ')"'
            + (!currentAdminIsSuperAdmin ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-dove"></i></span></button>';
    } else {
        actions += '<button class="sp-btn sp-btn-sm memorial-action-btn" title="' + escapeHtml(T.btn_mark_memorial) + '" onclick="markDeceased(' + userId + ')"'
            + (!currentAdminIsSuperAdmin ? ' disabled' : '') + '>'
            + '<span class="icon"><i class="fas fa-dove"></i></span></button>';
    }
    if (userId !== currentAdminUserId) {
        actions += '<a class="sp-btn sp-btn-info sp-btn-sm" href="act_as_user.php?user_id=' + userId + '" title="'
            + escapeHtml(T.btn_act_as) + '"><span class="icon"><i class="fas fa-user-secret"></i></span></a>';
    } else {
        actions += '<button class="sp-btn sp-btn-info sp-btn-sm" type="button" disabled title="'
            + escapeHtml(T.title_already_own_dashboard) + '"><span class="icon"><i class="fas fa-user-secret"></i></span></button>';
    }
    return '<tr' + (rowClass ? ' class="' + rowClass + '"' : '') + '>'
        + '<td style="text-align:center;vertical-align:middle;">' + escapeHtml(user.id) + '</td>'
        + '<td style="vertical-align:middle;"><div style="display:flex;align-items:center;gap:0.5rem;">'
        + '<img src="' + escapeHtml(avatar) + '" alt="" onerror="this.src=\'https://cdn.botofthespecter.com/logo.png\';" style="width:28px;height:28px;border-radius:50%;flex-shrink:0;">'
        + '<span>' + escapeHtml(user.username) + '</span>'
        + statusBadge
        + '</div></td>'
        + '<td style="text-align:center;vertical-align:middle;">' + flagBadge(isAdminUser, 'sp-badge-green', T.th_admin, T.title_not_admin) + '</td>'
        + '<td style="text-align:center;vertical-align:middle;">' + flagBadge(isSuperAdmin, 'sp-badge-green', T.th_super_admin, T.title_not_super_admin) + '</td>'
        + '<td style="text-align:center;vertical-align:middle;">' + flagBadge(hasBeta, 'sp-badge-blue', T.title_beta_access, T.title_no_beta_access) + '</td>'
        + '<td style="text-align:center;vertical-align:middle;">' + programHtml + '</td>'
        + '<td style="text-align:center;vertical-align:middle;" data-premium-cell="' + escapeHtml(twitchId) + '">' + premiumBadgeHtml(twitchId) + '</td>'
        + '<td class="admin-date-cell" style="text-align:center;vertical-align:middle;">' + (user.signup_html || '-') + '</td>'
        + '<td class="admin-date-cell" style="text-align:center;vertical-align:middle;">' + (user.last_login_html || '-') + '</td>'
        + '<td style="text-align:center;vertical-align:middle;"><div class="actions-wrap">' + actions + '</div></td>'
        + '</tr>';
}
function renderUsersTable() {
    const tbody = document.getElementById('admin-users-tbody');
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    if (!usersData.length) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;">' + escapeHtml(T.none_found) + '</td></tr>';
        return;
    }
    tbody.innerHTML = usersData.map(renderUserRow).join('');
    filterUsers();
}
function renderUsersError() {
    const tbody = document.getElementById('admin-users-tbody');
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;">' + escapeHtml(T.load_failed) + '</td></tr>';
}
function loadAdminUsers() {
    const url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            const data = parseAjaxJson(text);
            if (!data || !data.success || !Array.isArray(data.users)) {
                renderUsersError();
                return;
            }
            usersData = data.users;
            if (premiumLoaded) {
                applyPremiumTiers(premiumTiers);
            }
            renderUsersTable();
        })
        .catch(function() {
            renderUsersError();
        });
}
function loadPremiumTiers() {
    const url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'premium');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            const data = parseAjaxJson(text);
            applyPremiumTiers((data && data.success && data.tiers) ? data.tiers : {});
        })
        .catch(function() {
            applyPremiumTiers({});
        });
}
function maskEmail(email) {
    if (!email) return '';
    const atPos = email.indexOf('@');
    if (atPos === -1) return '�'.repeat(email.length);
    return '�'.repeat(atPos) + email.substring(atPos);
}
function maskApiKey(api) {
    if (!api) return '';
    return '�'.repeat(api.length);
}
function showSensitiveModal(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    function pretty(dateStr) {
        if (!dateStr) return '-';
        const dt = new Date(dateStr);
        if (isNaN(dt)) return '-';
        const day = dt.getDate();
        let suffix = 'th';
        if (day < 11 || day > 13) {
            switch (day % 10) {
                case 1: suffix = 'st'; break;
                case 2: suffix = 'nd'; break;
                case 3: suffix = 'rd'; break;
            }
        }
        return `${day}${suffix} ${dt.toLocaleString('en-US', { month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}`;
    }
    let html = `
    <div class="sp-card">
      <div class="sp-card-body">
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:0.75rem;">
            <img class="admin-bot-avatar" src="${escapeHtml(user.profile_image ? user.profile_image : 'https://cdn.botofthespecter.com/logo.png')}" alt="${escapeHtml(T.profile_alt)}" onerror="this.src='https://cdn.botofthespecter.com/logo.png';" style="width:48px;height:48px;flex-shrink:0;">
            <div>
                <p style="font-size:1.1rem;font-weight:700;margin:0 0 0.25rem;">${escapeHtml(user.twitch_display_name || '')}</p>
                <p style="font-size:0.85rem;color:var(--text-muted);margin:0;">${T.twitch_id} <span class="sp-text-danger">${escapeHtml(user.twitch_user_id || '')}</span></p>
            </div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin:0.75rem 0;">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <tbody>
                    <tr>
                        <th>${T.th_admin}</th>
                        <td>${isTruthyFlag(user.is_admin) ? `<span class="sp-badge sp-badge-green">${T.val_true}</span>` : `<span class="sp-badge sp-badge-red">${T.val_false}</span>`}</td>
                    </tr>
                    <tr>
                        <th>${T.beta_access}</th>
                        <td>${isTruthyFlag(user.beta_access) ? `<span class="sp-badge sp-badge-green">${T.val_true}</span>` : `<span class="sp-badge sp-badge-red">${T.val_false}</span>`}</td>
                    </tr>
                    <tr>
                        <th>${T.beta_programs}</th>
                        <td>${userPrograms(user).length ? userPrograms(user).map(p => `<span class="sp-badge sp-badge-blue">${escapeHtml(p)}</span>`).join(' ') : `<span class="sp-badge sp-badge-grey">${T.tier_none}</span>`}</td>
                    </tr>
                    <tr>
                        <th>${T.premium_access}</th>
                        <td>
                            ${
                                premiumBadgeHtml(user.twitch_user_id)
                            }
                        </td>
                    </tr>
                    <tr>
                        <th>${T.signup_date}</th>
                        <td>${pretty(user.signup_date)}</td>
                    </tr>
                    <tr>
                        <th>${T.last_login}</th>
                        <td>${pretty(user.last_login)}</td>
                    </tr>
                    <tr>
                        <th>${T.email}</th>
                        <td>
                            ${user.email ? `
                                <span id="modal-email-masked">${escapeHtml(maskEmail(user.email))}</span>
                                <span id="modal-email-unmasked" style="display:none;">${escapeHtml(user.email)}</span>
                                <button class="sp-btn sp-btn-sm ml-2" id="modal-email-eye" onclick="toggleModalInfo('email', true)" style="vertical-align:middle;">
                                    <span class="icon"><i class="fas fa-eye"></i></span>
                                </button>
                                <button class="sp-btn sp-btn-sm ml-2" id="modal-email-eye-slash" style="display:none;vertical-align:middle;" onclick="toggleModalInfo('email', false)">
                                    <span class="icon"><i class="fas fa-eye-slash"></i></span>
                                </button>
                            ` : `<span class="sp-text-muted">${T.none}</span>`}
                        </td>
                    </tr>
                    <tr>
                        <th>${T.api_key}</th>
                        <td>
                            <span id="modal-api-masked">${escapeHtml(maskApiKey(user.api_key))}</span>
                            <span id="modal-api-unmasked" style="display:none;">${escapeHtml(user.api_key)}</span>
                            <button class="sp-btn sp-btn-sm ml-2" id="modal-api-eye" onclick="toggleModalInfo('api', true)" style="vertical-align:middle;">
                                <span class="icon"><i class="fas fa-eye"></i></span>
                            </button>
                            <button class="sp-btn sp-btn-sm ml-2" id="modal-api-eye-slash" style="display:none;vertical-align:middle;" onclick="toggleModalInfo('api', false)">
                                <span class="icon"><i class="fas fa-eye-slash"></i></span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
      </div>
    </div>
    `;
    document.getElementById('sensitive-modal-content').innerHTML = html;
    // expose current modal user for export action
    window.currentSensitiveUserId = user.id;
    window.currentSensitiveUserEmail = user.email || '';
    window.currentSensitiveUsername = user.username || '';
    document.getElementById('sensitive-modal').classList.add('is-active');
}
function closeSensitiveModal() {
    document.getElementById('sensitive-modal').classList.remove('is-active');
}
function toggleModalInfo(type, reveal) {
    let label = type === 'email' ? T.label_email : T.label_api_key;
    if (reveal) {
        Swal.fire({
            title: T.reveal_title.replace(':label', label),
            text: T.reveal_text.replace(':label', label.toLowerCase()),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: T.reveal_confirm,
            cancelButtonText: T.cancel
        }).then((result) => {
            if (result.isConfirmed) {
                if (type === 'email') {
                    document.getElementById('modal-email-masked').style.display = 'none';
                    document.getElementById('modal-email-unmasked').style.display = '';
                    document.getElementById('modal-email-eye').style.display = 'none';
                    document.getElementById('modal-email-eye-slash').style.display = '';
                } else if (type === 'api') {
                    document.getElementById('modal-api-masked').style.display = 'none';
                    document.getElementById('modal-api-unmasked').style.display = '';
                    document.getElementById('modal-api-eye').style.display = 'none';
                    document.getElementById('modal-api-eye-slash').style.display = '';
                }
            }
        });
    } else {
        if (type === 'email') {
            document.getElementById('modal-email-masked').style.display = '';
            document.getElementById('modal-email-unmasked').style.display = 'none';
            document.getElementById('modal-email-eye').style.display = '';
            document.getElementById('modal-email-eye-slash').style.display = 'none';
        } else if (type === 'api') {
            document.getElementById('modal-api-masked').style.display = '';
            document.getElementById('modal-api-unmasked').style.display = 'none';
            document.getElementById('modal-api-eye').style.display = '';
            document.getElementById('modal-api-eye-slash').style.display = 'none';
        }
    }
}
function filterUsers() {
    const tbody = document.getElementById('admin-users-tbody');
    if (!tbody || tbody.getAttribute('aria-busy') === 'true') return;
    const searchEl = document.getElementById('user-search');
    const input = searchEl ? searchEl.value.toLowerCase() : '';
    const table = tbody;
    const rows = table.getElementsByTagName('tr');
    for (let row of rows) {
        const usernameCell = row.cells[1];
        // Extract username text (skip image)
        let username = '';
        if (usernameCell) {
            const spans = usernameCell.getElementsByTagName('span');
            if (spans.length > 0) {
                username = spans[0].textContent.toLowerCase();
            }
        }
        if (username.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}
function deleteUser(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    const memorialWarning = isTruthyFlag(user.is_deceased) ? `<br><br><span style="color:#7b2fa8;"><strong>&#128540; ${T.memorial_delete_warning}</strong></span>` : '';
    Swal.fire({
        title: T.delete_user_title,
        html: `${T.delete_user_html.replace(':name', `<b>${user.username}</b>`)}${memorialWarning}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: T.yes,
        cancelButtonText: T.no
    }).then((result) => {
        if (result.isConfirmed) {
            // Ask about DB deletion BEFORE deleting user
            Swal.fire({
                title: T.delete_db_title,
                html: T.delete_db_html.replace(':name', `<b>${user.username}</b>`),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: T.yes,
                cancelButtonText: T.no
            }).then((dbResult) => {
                const deleteDb = dbResult.isConfirmed;
                // Final confirmation
                Swal.fire({
                    title: T.final_confirm_title,
                    text: deleteDb
                        ? T.final_confirm_with_db.replace(':name', user.username)
                        : T.final_confirm_no_db.replace(':name', user.username),
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonText: T.yes_delete,
                    cancelButtonText: T.cancel
                }).then((finalResult) => {
                    if (finalResult.isConfirmed) {
                        // Always delete user first
                        $.post('', { delete_user_id: userId, username: user.username }, function(resp) {
                            let data = {};
                            try { data = JSON.parse(resp); } catch {}
                            if (data.success) {
                                if (deleteDb) {
                                    // Now delete DB
                                    $.post('', { delete_user_id: userId, delete_db: 1, username: user.username }, function(dbResp) {
                                        let dbData = {};
                                        try { dbData = JSON.parse(dbResp); } catch {}
                                        if (dbData.success) {
                                            Swal.fire(T.deleted_title, T.deleted_user_and_db, 'success').then(() => location.reload());
                                        } else {
                                            Swal.fire(
                                                T.user_deleted_title,
                                                T.db_delete_failed_html + '<br>' +
                                                (dbData.msg ? T.db_delete_reason.replace(':reason', dbData.msg) : T.unknown_error),
                                                'warning'
                                            ).then(() => location.reload());
                                        }
                                    });
                                } else {
                                    Swal.fire(T.deleted_title, T.deleted_user_no_db, 'success').then(() => location.reload());
                                }
                            } else {
                                Swal.fire(T.error, data.msg || T.could_not_delete_user, 'error');
                            }
                        });
                    }
                });
            });
        }
    });
}
function toggleRestrictUser(userId, restrict) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    const username = user.username || '';
    const twitch_user_id = user.twitch_user_id || '';
    const action = restrict ? 'restrict' : 'unrestrict';
    const actionText = restrict ? T.action_restrict_word : T.action_unrestrict_word;
    const confirmText = restrict ? T.confirm_restrict_word : T.unrestrict_word;
    const restrictInfoHtml = `
        <div style="text-align:left;margin-top:0.75rem;">
            <p style="margin-bottom:0.5rem;font-weight:700;">${T.restrict_note_label}</p>
            <p style="margin-bottom:0.5rem;">${T.restrict_note_1}</p>
            <p style="margin-bottom:0.5rem;">${T.restrict_note_2}</p>
            <p style="margin-bottom:0;">${T.restrict_note_3.replace(':unrestrict', `<span style="font-weight:700;">${T.unrestrict_word}</span>`)}</p>
        </div>
    `;
    const baseConfirm = T.confirm_restrict_html
        .replace(':action', actionText)
        .replace(':name', `<b>${username}</b>`);
    const modalHtml = restrict
        ? `${baseConfirm}${restrictInfoHtml}`
        : baseConfirm;
    Swal.fire({
        title: restrict ? T.restrict_user_title : T.remove_restriction_title,
        html: modalHtml,
        icon: restrict ? 'warning' : 'info',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('', {
                restrict_action: action,
                user_id: userId,
                username: username,
                twitch_user_id: twitch_user_id
            }, function(resp) {
                let data = {};
                try { data = JSON.parse(resp); } catch {}
                if (data.success) {
                    Swal.fire(T.success, restrict ? T.user_restricted : T.restriction_removed, 'success').then(() => location.reload());
                } else {
                    Swal.fire(T.error, data.msg || T.could_not_update_restriction, 'error');
                }
            });
        }
    });
}
function grantBetaAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: T.grant_beta_title,
        html: T.grant_beta_html.replace(':name', `<b>${user.username}</b>`),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: T.grant_word,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            beta_action: 'grant_beta',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.updated, T.beta_access_granted, 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_update_beta_access, 'error');
            }
        });
    });
}
function removeBetaAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: T.remove_beta_title,
        html: T.remove_beta_html.replace(':name', `<b>${user.username}</b>`),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: T.remove_word,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            beta_action: 'remove_beta',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.updated, T.beta_access_removed, 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_update_beta_access, 'error');
            }
        });
    });
}
function manageBetaPrograms(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    const programs = userPrograms(user);
    const currentList = programs.length
        ? programs.map(p => `<span class="sp-badge sp-badge-blue" style="margin:2px;cursor:pointer;" onclick="removeBetaProgram(${userId},'${p}')">${p} &times;</span>`).join(' ')
        : `<em>${T.none_em}</em>`;
    Swal.fire({
        title: T.beta_programs_title.replace(':name', user.username),
        html: `
            <p style="margin-bottom:0.5rem;">${T.current_programs_label}</p>
            <div id="swal-programs-list" style="margin-bottom:1rem;">${currentList}</div>
            <div style="display:flex;gap:0.5rem;">
                <input id="swal-program-input" class="swal2-input" style="margin:0;flex:1;" placeholder="${T.program_placeholder}">
                <button class="swal2-confirm swal2-styled" style="margin:0;" onclick="addBetaProgram(${userId})">${T.add_word}</button>
            </div>`,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: T.close_word,
    });
}
function addBetaProgram(userId) {
    const program = document.getElementById('swal-program-input').value.trim().toLowerCase();
    if (!program) return;
    $.post('', { beta_programs_action: 'add', user_id: userId, program }, function(resp) {
        let data = {};
        try { data = JSON.parse(resp); } catch {}
        if (data.success) {
            Swal.fire(T.added, T.added_program_html.replace(':program', `<b>${program}</b>`), 'success').then(() => location.reload());
        } else {
            Swal.fire(T.error, data.msg || T.could_not_update_beta_programs, 'error');
        }
    });
}
function removeBetaProgram(userId, program) {
    Swal.fire({
        title: T.remove_program_title,
        html: T.remove_program_html.replace(':program', `<b>${program}</b>`),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: T.remove_word,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', { beta_programs_action: 'remove', user_id: userId, program }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.removed, T.removed_program_html.replace(':program', `<b>${program}</b>`), 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_update_beta_programs, 'error');
            }
        });
    });
}
function grantAdminAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: T.grant_admin_title,
        html: T.grant_admin_html.replace(':name', `<b>${user.username}</b>`),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: T.grant_word,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            admin_action: 'grant_admin',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.updated, T.admin_access_granted, 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_update_admin_access, 'error');
            }
        });
    });
}
function removeAdminAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: T.remove_admin_title,
        html: T.remove_admin_html.replace(':name', `<b>${user.username}</b>`),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: T.remove_word,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            admin_action: 'remove_admin',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.updated, T.admin_access_removed, 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_update_admin_access, 'error');
            }
        });
    });
}
document.getElementById('user-search').addEventListener('input', function() {
    const clearBtn = document.getElementById('user-search-clear');
    if (clearBtn) clearBtn.style.display = this.value ? '' : 'none';
    filterUsers();
});
(function () {
    const searchEl  = document.getElementById('user-search');
    const clearBtn  = document.getElementById('user-search-clear');
    if (searchEl && searchEl.value && clearBtn) {
        clearBtn.style.display = '';
    }
    loadAdminUsers();
    loadPremiumTiers();
}());
function markDeceased(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: T.mark_memorial_title,
        html: T.mark_memorial_html.replace(':name', `<b>${user.username}</b>`),
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: T.mark_memorial_confirm,
        cancelButtonText: T.cancel,
        confirmButtonColor: '#7b2fa8'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            deceased_action: 'mark_deceased',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.preserved, T.marked_memorial, 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_mark_memorial, 'error');
            }
        });
    });
}
function unmarkDeceased(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: T.remove_memorial_title,
        html: T.remove_memorial_html.replace(':name', `<b>${user.username}</b>`),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: T.remove_word,
        cancelButtonText: T.cancel
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            deceased_action: 'unmark_deceased',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire(T.updated, T.memorial_status_removed, 'success').then(() => location.reload());
            } else {
                Swal.fire(T.error, data.msg || T.could_not_remove_memorial, 'error');
            }
        });
    });
}
function exportSensitiveUser() {
    const uid = window.currentSensitiveUserId;
    const email = window.currentSensitiveUserEmail || '';
    const username = window.currentSensitiveUsername || '';
    if (!uid) return Swal.fire(T.error, T.no_user_export, 'error');
    Swal.fire({
        title: T.export_title,
        html: T.export_html
            .replace(':name', `<b>${username}</b>`)
            .replace(':id', uid)
            .replace(':email', `<b>${email || T.export_account_email}</b>`),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: T.yes_export,
        cancelButtonText: T.cancel
    }).then((res)=>{
        if (!res.isConfirmed) return;
        const postData = { user_id: uid, email: email, username: username };
        $.post('export_user_data.php', postData, function(resp){
            let data = {};
            try { data = typeof resp === 'object' ? resp : JSON.parse(resp); } catch(e){}
            if (data && data.success) {
                Swal.fire(T.queued, T.export_started, 'success');
                closeSensitiveModal();
            } else {
                Swal.fire(T.error, data.msg || T.could_not_start_export, 'error');
            }
        }).fail(function(){
            Swal.fire(T.error, T.could_not_reach_export, 'error');
        });
    });
}
</script>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>
