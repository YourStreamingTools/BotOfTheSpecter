<?php
session_start();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
$pageTitle = t('admin_user_management_title');
$currentAdminUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$currentAdminIsSuperAdmin = false;

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
            $actAsNotice = 'Invalid user selected for Act As.';
            $actAsNoticeClass = 'is-danger';
            break;
        case 'not_found':
            $actAsNotice = 'The selected user could not be found.';
            $actAsNoticeClass = 'is-danger';
            break;
        case 'no_token':
            $actAsNotice = 'Cannot Act As this user because no access token is available.';
            $actAsNoticeClass = 'is-warning';
            break;
        case 'error':
            $actAsNotice = 'Unable to start Act As mode due to an internal error.';
            $actAsNoticeClass = 'is-danger';
            break;
        case 'started':
            $actAsNotice = 'Act As mode started.';
            $actAsNoticeClass = 'is-success';
            break;
    }
}
ob_start();

function getTwitchSubTier($twitch_user_id) {
    global $clientID;
    $accessToken = $_SESSION['access_token'];
    if (empty($twitch_user_id)) {
        return null;
    }
    $broadcaster_id = "140296994";
    $url = "https://api.twitch.tv/helix/subscriptions?broadcaster_id={$broadcaster_id}&user_id={$twitch_user_id}";
    $headers = [ "Client-ID: $clientID", "Authorization: Bearer $accessToken" ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }
    $data = json_decode($response, true);
    curl_close($ch);
    // Check if we have subscription data in the response
    if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
        return $data['data'][0]['tier'];
    }
    return null;
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

// Fetch users from database
$users = [];
$stmt = $conn->prepare("SELECT * FROM users");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['twitch_user_id'] = (!empty($row['twitch_user_id'])) ? $row['twitch_user_id'] : null;
    $users[] = $row;
}
$stmt->close();

// Fetch restricted users into an array for quick lookup
$restricted_users = [];
$restricted_result = $conn->query("SELECT username, twitch_user_id FROM restricted_users");
while ($row = $restricted_result->fetch_assoc()) {
    $restricted_users[$row['username']] = true;
    if ($row['twitch_user_id']) {
        $restricted_users[$row['twitch_user_id']] = true;
    }
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
        $response['msg'] = 'User not found.';
        echo json_encode($response);
        exit;
    }
    if ($delete_db === '1') {
        // Drop the user's database
        $db_name = $username;
        require_once "/var/www/config/database.php";
        $mysqli = new mysqli($db_servername, $db_username, $db_password);
        if ($mysqli->connect_errno) {
            $response['msg'] = 'Failed to connect to MySQL for DB drop.';
            echo json_encode($response);
            exit;
        }
        if ($mysqli->query("DROP DATABASE `" . $mysqli->real_escape_string($db_name) . "`")) {
            $response['success'] = true;
            $response['msg'] = 'User database deleted.';
        } else {
            $response['msg'] = 'Could not delete user database.';
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
            $response['msg'] = 'Could not delete user.';
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
            $response['msg'] = 'Super admins cannot be restricted.';
            echo json_encode($response);
            exit;
        }
        if ($targetIsAdmin && !$currentAdminIsSuperAdmin) {
            $response['msg'] = 'Only super admins can restrict admins.';
            echo json_encode($response);
            exit;
        }
        $stmt = $conn->prepare("INSERT IGNORE INTO restricted_users (username, twitch_user_id) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $twitch_user_id);
        $response['success'] = $stmt->execute();
        $stmt->close();
        if (!$response['success']) $response['msg'] = 'Could not restrict user.';
    } elseif ($action === 'unrestrict') {
        $stmt = $conn->prepare("DELETE FROM restricted_users WHERE username = ? OR twitch_user_id = ?");
        $stmt->bind_param("ss", $username, $twitch_user_id);
        $response['success'] = $stmt->execute();
        $stmt->close();
        if (!$response['success']) $response['msg'] = 'Could not unrestrict user.';
    }
    echo json_encode($response);
    exit;
}

// Handle AJAX beta access grant request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['beta_action'])) {
    $action = $_POST['beta_action'];
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $response = ['success' => false, 'msg' => ''];
    if (($action !== 'grant_beta' && $action !== 'remove_beta') || $user_id <= 0) {
        $response['msg'] = 'Invalid beta access request.';
        echo json_encode($response);
        exit;
    }
    $betaValue = ($action === 'grant_beta') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET beta_access = ? WHERE id = ?");
    $stmt->bind_param("ii", $betaValue, $user_id);
    if ($stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['msg'] = 'Could not update beta access.';
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
        $response['msg'] = 'Only super admins can grant admin access.';
        echo json_encode($response);
        exit;
    }
    if (($action !== 'grant_admin' && $action !== 'remove_admin') || $user_id <= 0) {
        $response['msg'] = 'Invalid admin access request.';
        echo json_encode($response);
        exit;
    }
    $adminValue = ($action === 'grant_admin') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    $stmt->bind_param("ii", $adminValue, $user_id);
    if ($stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['msg'] = 'Could not update admin access.';
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
        $response['msg'] = 'Only super admins can manage memorial accounts.';
        echo json_encode($response);
        exit;
    }
    if (($action !== 'mark_deceased' && $action !== 'unmark_deceased') || $user_id <= 0) {
        $response['msg'] = 'Invalid memorial request.';
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
            $response['msg'] = 'Could not mark account as memorial.';
        }
    } elseif ($action === 'unmark_deceased') {
        $stmt = $conn->prepare("UPDATE users SET is_deceased = 0, deceased_date = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $response['success'] = true;
        } else {
            $response['msg'] = 'Could not remove memorial status.';
        }
        $stmt->close();
    }
    echo json_encode($response);
    exit;
}
?>
<?php if ($actAsNotice): ?>
    <?php $alertClass = str_replace('is-', '', $actAsNoticeClass); ?>
    <div class="sp-alert sp-alert-<?php echo htmlspecialchars($alertClass); ?>">
        <?php echo htmlspecialchars($actAsNotice); ?>
    </div>
<?php endif; ?>
<div class="sp-card">
  <div class="sp-card-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
        <h1 style="font-size:1.25rem;font-weight:700;margin:0;"><span class="icon"><i class="fas fa-users-cog"></i></span> User Management</h1>
        <form onsubmit="event.preventDefault(); filterUsers();">
            <div style="position:relative;">
                <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text-muted);"><i class="fas fa-search"></i></span>
                <input class="sp-input" style="padding-left:2.25rem;border-radius:2rem;" type="text" placeholder="Search users..." id="user-search" autocomplete="off">
            </div>
        </form>
    </div>
    <div class="sp-table-wrap">
        <table class="sp-table admin-users-table">
            <thead>
                <tr>
                    <th style="text-align:center;">ID</th>
                    <th style="text-align:center;">User</th>
                    <th style="text-align:center;">Admin</th>
                    <th style="text-align:center;">Super Admin</th>
                    <th style="text-align:center;">Beta Access</th>
                    <th style="text-align:center;">Premium Access</th>
                    <th style="text-align:center;">Signup Date</th>
                    <th style="text-align:center;">Last Login</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                function format_pretty_date($dateStr) {
                    if (!$dateStr) return '-';
                    $dt = new DateTime($dateStr);
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
                foreach ($users as $user):
                    $is_restricted =
                        (isset($user['username']) && isset($restricted_users[$user['username']]))
                        || (isset($user['twitch_user_id']) && isset($restricted_users[$user['twitch_user_id']]));
                    $is_super_admin = isset($user['super_admin']) && (int) $user['super_admin'] === 1;
                    $is_admin_user = isset($user['is_admin']) && (int) $user['is_admin'] === 1;
                    $can_restrict_user = !$is_super_admin && (!$is_admin_user || $currentAdminIsSuperAdmin);
                    $is_deceased = isset($user['is_deceased']) && (int) $user['is_deceased'] === 1;
                ?>
                <?php
                $rowClass = '';
                if ($is_deceased) $rowClass = 'is-memorial-row';
                elseif ($is_restricted) $rowClass = 'is-restricted-row';
                ?>
                <tr<?php if ($rowClass) echo ' class="' . htmlspecialchars($rowClass) . '"'; ?>>
                    <td style="text-align:center;vertical-align:middle;"><?php echo htmlspecialchars($user['id']); ?></td>
                    <td style="vertical-align: middle;">
                        <img class="admin-bot-avatar" src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" onerror="this.src='https://cdn.botofthespecter.com/logo.png';" style="margin-right:0.5rem;vertical-align:middle;">
                        <span style="vertical-align:middle;"><?php echo htmlspecialchars($user['username']); ?></span>
                        <?php if ($is_deceased): ?>
                            <span class="sp-badge memorial-label">
                            <span class="icon"><i class="fas fa-dove"></i></span>&nbsp;Memorial
                            </span>
                        <?php elseif ($is_restricted): ?>
                            <span class="sp-badge sp-badge-amber restricted-label">Restricted</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <?php if ($user['is_admin']): ?>
                            <span class="sp-badge sp-badge-green">True</span>
                        <?php else: ?>
                            <span class="sp-badge sp-badge-red">False</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <?php if ($is_super_admin): ?>
                            <span class="sp-badge sp-badge-green">True</span>
                        <?php else: ?>
                            <span class="sp-badge sp-badge-red">False</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <?php if ($user['beta_access']): ?>
                            <span class="sp-badge sp-badge-green">True</span>
                        <?php else: ?>
                            <span class="sp-badge sp-badge-red">False</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <?php
                        if (!empty($user['twitch_user_id'])) {
                            $tier = getTwitchSubTier($user['twitch_user_id']);
                            if ($tier === "1000") {
                                echo '<span class="sp-badge sp-badge-amber">Tier 1</span>';
                            } elseif ($tier === "2000") {
                                echo '<span class="sp-badge sp-badge-blue">Tier 2</span>';
                            } elseif ($tier === "3000") {
                                echo '<span class="sp-badge sp-badge-red">Tier 3</span>';
                            } else {
                                echo '<span class="sp-badge sp-badge-grey">None</span>';
                            }
                        } else {
                            echo '<span class="sp-badge sp-badge-grey">None</span>';
                        }
                        ?>
                    </td>
                    <td class="admin-date-cell" style="text-align:center;vertical-align:middle;"><?php echo format_pretty_date($user['signup_date']); ?></td>
                    <td class="admin-date-cell" style="text-align:center;vertical-align:middle;"><?php echo format_pretty_date($user['last_login']); ?></td>
                    <td style="text-align:center;vertical-align:middle;">
                        <div class="actions-wrap">
                            <button class="sp-btn sp-btn-sm" title="View Details" onclick="showSensitiveModal(<?php echo $user['id']; ?>)">
                                <span class="icon"><i class="fas fa-eye"></i></span>
                            </button>
                            <button class="sp-btn sp-btn-danger sp-btn-sm" title="Delete User" onclick="deleteUser(<?php echo $user['id']; ?>)" <?php if ($is_deceased): ?>disabled<?php endif; ?>>
                                <span class="icon"><i class="fas fa-trash"></i></span>
                            </button>
                            <?php if ((int) $user['is_admin']): ?>
                                <button
                                    class="sp-btn sp-btn-warning sp-btn-sm"
                                    onclick="removeAdminAccess(<?php echo (int) $user['id']; ?>)"
                                    title="Remove Admin"
                                    <?php if (!$currentAdminIsSuperAdmin || $is_deceased): ?>disabled<?php endif; ?>
                                >
                                    <span class="icon"><i class="fas fa-user-shield"></i></span>
                                </button>
                            <?php else: ?>
                                <button
                                    class="sp-btn sp-btn-primary sp-btn-sm"
                                    onclick="grantAdminAccess(<?php echo (int) $user['id']; ?>)"
                                    title="Give Admin"
                                    <?php if (!$currentAdminIsSuperAdmin || $is_deceased): ?>disabled<?php endif; ?>
                                >
                                    <span class="icon"><i class="fas fa-user-shield"></i></span>
                                </button>
                            <?php endif; ?>
                            <?php if ((int) $user['beta_access']): ?>
                                <button class="sp-btn sp-btn-warning sp-btn-sm" onclick="removeBetaAccess(<?php echo (int) $user['id']; ?>)" title="Remove Beta" <?php if ($is_deceased): ?>disabled<?php endif; ?>>
                                    <span class="icon"><i class="fas fa-flask"></i></span>
                                </button>
                            <?php else: ?>
                                <button class="sp-btn sp-btn-primary sp-btn-sm" onclick="grantBetaAccess(<?php echo (int) $user['id']; ?>)" title="Give Beta" <?php if ($is_deceased): ?>disabled<?php endif; ?>>
                                    <span class="icon"><i class="fas fa-flask"></i></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($is_restricted): ?>
                                <button class="sp-btn sp-btn-warning sp-btn-sm" title="Unrestrict"
                                    onclick="toggleRestrictUser(<?php echo (int) $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', false)" <?php if ($is_deceased): ?>disabled<?php endif; ?>>
                                    <span class="icon"><i class="fas fa-user-lock"></i></span>
                                </button>
                            <?php else: ?>
                                <button class="sp-btn sp-btn-sm" title="<?php echo $can_restrict_user ? 'Restrict' : 'Only super admins can restrict admins. Super admins cannot be restricted.'; ?>"
                                    onclick="toggleRestrictUser(<?php echo (int) $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', true)"
                                    <?php if (!$can_restrict_user || $is_deceased): ?>disabled<?php endif; ?>>
                                    <span class="icon"><i class="fas fa-user-lock"></i></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($is_deceased): ?>
                                <button class="sp-btn sp-btn-sm memorial-action-btn" title="Remove Memorial"
                                    onclick="unmarkDeceased(<?php echo (int) $user['id']; ?>)"
                                    <?php if (!$currentAdminIsSuperAdmin): ?>disabled<?php endif; ?>>
                                    <span class="icon"><i class="fas fa-dove"></i></span>
                                </button>
                            <?php else: ?>
                                <button class="sp-btn sp-btn-sm memorial-action-btn" title="Mark as Memorial"
                                    onclick="markDeceased(<?php echo (int) $user['id']; ?>)"
                                    <?php if (!$currentAdminIsSuperAdmin): ?>disabled<?php endif; ?>>
                                    <span class="icon"><i class="fas fa-dove"></i></span>
                                </button>
                            <?php endif; ?>
                            <?php if ((int) $user['id'] !== $currentAdminUserId): ?>
                                <a class="sp-btn sp-btn-info sp-btn-sm" href="act_as_user.php?user_id=<?php echo (int) $user['id']; ?>" title="Act As">
                                    <span class="icon"><i class="fas fa-user-secret"></i></span>
                                </a>
                            <?php else: ?>
                                <button class="sp-btn sp-btn-info sp-btn-sm" type="button" disabled title="You are already viewing your own dashboard">
                                    <span class="icon"><i class="fas fa-user-secret"></i></span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
  </div>
</div>

<!-- Sensitive Info Modal -->
<div class="sp-modal-backdrop" id="sensitive-modal" style="display:none;" onclick="closeSensitiveModal()">
    <div class="sp-modal" style="max-width:800px;" onclick="event.stopPropagation()">
        <div class="sp-modal-head">
            <span class="sp-modal-title"><span class="icon"><i class="fas fa-user-secret"></i></span> User Details</span>
            <button class="sp-modal-close" aria-label="close" onclick="closeSensitiveModal()">&#x2715;</button>
        </div>
        <div class="sp-modal-body" id="sensitive-modal-content">
            <!-- Populated by JS -->
        </div>
        <div style="padding:1rem;display:flex;justify-content:flex-end;gap:0.5rem;border-top:1px solid var(--border);">
            <button class="sp-btn sp-btn-primary" id="export-sensitive-btn" onclick="exportSensitiveUser()" title="Export user data">Export Data</button>
            <button class="sp-btn" onclick="closeSensitiveModal()">Close</button>
        </div>
    </div>
</div>

<script>
const usersData = <?php echo json_encode($users); ?>;

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
            <img class="admin-bot-avatar" src="${user.profile_image ? user.profile_image : 'https://cdn.botofthespecter.com/logo.png'}" alt="Profile" onerror="this.src='https://cdn.botofthespecter.com/logo.png';" style="width:48px;height:48px;flex-shrink:0;">
            <div>
                <p style="font-size:1.1rem;font-weight:700;margin:0 0 0.25rem;">${user.twitch_display_name ? user.twitch_display_name : ''}</p>
                <p style="font-size:0.85rem;color:var(--text-muted);margin:0;">Twitch ID: <span class="sp-text-danger">${user.twitch_user_id}</span></p>
            </div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin:0.75rem 0;">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <tbody>
                    <tr>
                        <th>Admin</th>
                        <td>${user.is_admin ? '<span class="sp-badge sp-badge-green">True</span>' : '<span class="sp-badge sp-badge-red">False</span>'}</td>
                    </tr>
                    <tr>
                        <th>Beta Access</th>
                        <td>${user.beta_access ? '<span class="sp-badge sp-badge-green">True</span>' : '<span class="sp-badge sp-badge-red">False</span>'}</td>
                    </tr>
                    <tr>
                        <th>Premium Access</th>
                        <td>
                            ${
                                user.twitch_user_id
                                ? (() => {
                                    let tier = '';
                                    if (user.twitch_sub_tier === "1000") return '<span class="sp-badge sp-badge-amber">Tier 1</span>';
                                    if (user.twitch_sub_tier === "2000") return '<span class="sp-badge sp-badge-blue">Tier 2</span>';
                                    if (user.twitch_sub_tier === "3000") return '<span class="sp-badge sp-badge-red">Tier 3</span>';
                                    return '<span class="sp-badge sp-badge-grey">None</span>';
                                })()
                                : '<span class="sp-badge sp-badge-grey">None</span>'
                            }
                        </td>
                    </tr>
                    <tr>
                        <th>Signup Date</th>
                        <td>${pretty(user.signup_date)}</td>
                    </tr>
                    <tr>
                        <th>Last Login</th>
                        <td>${pretty(user.last_login)}</td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td>
                            ${user.email ? `
                                <span id="modal-email-masked">${maskEmail(user.email)}</span>
                                <span id="modal-email-unmasked" style="display:none;">${user.email}</span>
                                <button class="sp-btn sp-btn-sm ml-2" id="modal-email-eye" onclick="toggleModalInfo('email', true)" style="vertical-align:middle;">
                                    <span class="icon"><i class="fas fa-eye"></i></span>
                                </button>
                                <button class="sp-btn sp-btn-sm ml-2" id="modal-email-eye-slash" style="display:none;vertical-align:middle;" onclick="toggleModalInfo('email', false)">
                                    <span class="icon"><i class="fas fa-eye-slash"></i></span>
                                </button>
                            ` : '<span class="sp-text-muted">None</span>'}
                        </td>
                    </tr>
                    <tr>
                        <th>API Key</th>
                        <td>
                            <span id="modal-api-masked">${maskApiKey(user.api_key)}</span>
                            <span id="modal-api-unmasked" style="display:none;">${user.api_key}</span>
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
    document.getElementById('sensitive-modal').style.display = 'flex';
}

function closeSensitiveModal() {
    document.getElementById('sensitive-modal').style.display = 'none';
}

function toggleModalInfo(type, reveal) {
    let label = type === 'email' ? 'Email' : 'API Key';
    if (reveal) {
        Swal.fire({
            title: `Reveal ${label}?`,
            text: `Are you sure you want to view this user's ${label.toLowerCase()}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Reveal',
            cancelButtonText: 'Cancel'
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
    const input = document.getElementById('user-search').value.toLowerCase();
    const table = document.querySelector('.sp-table tbody');
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
    const memorialWarning = user.is_deceased ? '<br><br><span style="color:#7b2fa8;"><strong>&#128540; This is a memorial account for a deceased user. All data will be permanently lost.</strong></span>' : '';
    Swal.fire({
        title: 'Delete User?',
        html: `Are you sure you want to delete <b>${user.username}</b> from the users table?${memorialWarning}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            // Ask about DB deletion BEFORE deleting user
            Swal.fire({
                title: 'Delete User Database?',
                html: `Do you also want to delete the database <b>${user.username}</b>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No'
            }).then((dbResult) => {
                const deleteDb = dbResult.isConfirmed;
                // Final confirmation
                Swal.fire({
                    title: 'Are you absolutely sure?',
                    text: deleteDb
                        ? `This will permanently delete the user and the database "${user.username}". This action cannot be undone.`
                        : `This will permanently delete the user "${user.username}". The database will NOT be deleted.`,
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete',
                    cancelButtonText: 'Cancel'
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
                                            Swal.fire('Deleted!', 'User and database deleted.', 'success').then(() => location.reload());
                                        } else {
                                            Swal.fire(
                                                'User deleted!',
                                                'User was deleted, but the database could not be deleted.<br>' +
                                                (dbData.msg ? `Reason: ${dbData.msg}` : 'Unknown error.'),
                                                'warning'
                                            ).then(() => location.reload());
                                        }
                                    });
                                } else {
                                    Swal.fire('Deleted!', 'User deleted. Database was not deleted.', 'success').then(() => location.reload());
                                }
                            } else {
                                Swal.fire('Error', data.msg || 'Could not delete user.', 'error');
                            }
                        });
                    }
                });
            });
        }
    });
}

function toggleRestrictUser(userId, username, twitch_user_id, restrict) {
    const action = restrict ? 'restrict' : 'unrestrict';
    const actionText = restrict ? 'restrict' : 'remove restriction for';
    const confirmText = restrict ? 'Restrict' : 'Unrestrict';
    const restrictInfoHtml = `
        <div style="text-align:left;margin-top:0.75rem;">
            <p style="margin-bottom:0.5rem;font-weight:700;">Admin note:</p>
            <p style="margin-bottom:0.5rem;">Restricting a user blocks dashboard access.</p>
            <p style="margin-bottom:0.5rem;">They will not be able to use dashboard controls, including starting or stopping the bot.</p>
            <p style="margin-bottom:0;">This can be temporary - you can restore access anytime by clicking <span style="font-weight:700;">Unrestrict</span>.</p>
        </div>
    `;
    const modalHtml = restrict
        ? `Are you sure you want to ${actionText} <b>${username}</b>?${restrictInfoHtml}`
        : `Are you sure you want to ${actionText} <b>${username}</b>?`;
    Swal.fire({
        title: restrict ? 'Restrict User?' : 'Remove Restriction?',
        html: modalHtml,
        icon: restrict ? 'warning' : 'info',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel'
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
                    Swal.fire('Success', restrict ? 'User restricted.' : 'Restriction removed.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.msg || 'Could not update restriction.', 'error');
                }
            });
        }
    });
}

function grantBetaAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Grant Beta Access?',
        html: `Give <b>${user.username}</b> beta access? This sets beta_access to <b>1</b>.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Grant',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            beta_action: 'grant_beta',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire('Updated', 'Beta access granted.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.msg || 'Could not update beta access.', 'error');
            }
        });
    });
}

function removeBetaAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Remove Beta Access?',
        html: `Remove beta access for <b>${user.username}</b>? This sets beta_access to <b>0</b>.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            beta_action: 'remove_beta',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire('Updated', 'Beta access removed.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.msg || 'Could not update beta access.', 'error');
            }
        });
    });
}

function grantAdminAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Grant Admin Access?',
        html: `Give <b>${user.username}</b> admin access? This sets is_admin to <b>1</b>.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Grant',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            admin_action: 'grant_admin',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire('Updated', 'Admin access granted.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.msg || 'Could not update admin access.', 'error');
            }
        });
    });
}

function removeAdminAccess(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Remove Admin Access?',
        html: `Remove admin access for <b>${user.username}</b>? This sets is_admin to <b>0</b>.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            admin_action: 'remove_admin',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire('Updated', 'Admin access removed.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.msg || 'Could not update admin access.', 'error');
            }
        });
    });
}

document.getElementById('user-search').addEventListener('keyup', function(e) {
    filterUsers();
});

function markDeceased(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Mark Account as Memorial?',
        html: `<p>This will preserve <b>${user.username}</b>'s account in memory of the account holder who has passed away.</p>
               <p class="mt-2">The account will be restricted to prevent login. All data will be permanently retained.</p>`,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Mark as Memorial',
        cancelButtonText: 'Cancel',
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
                Swal.fire('Preserved', 'Account has been marked as memorial and restricted.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.msg || 'Could not mark account as memorial.', 'error');
            }
        });
    });
}

function unmarkDeceased(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Remove Memorial Status?',
        html: `Remove memorial status from <b>${user.username}</b>?<br>Note: this does not automatically unrestrict the account.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('', {
            deceased_action: 'unmark_deceased',
            user_id: userId
        }, function(resp) {
            let data = {};
            try { data = JSON.parse(resp); } catch {}
            if (data.success) {
                Swal.fire('Updated', 'Memorial status removed.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.msg || 'Could not remove memorial status.', 'error');
            }
        });
    });
}

function exportSensitiveUser() {
    const uid = window.currentSensitiveUserId;
    const email = window.currentSensitiveUserEmail || '';
    const username = window.currentSensitiveUsername || '';
    if (!uid) return Swal.fire('Error','No user selected for export.','error');
    Swal.fire({
        title: 'Export user data?',
        html: `Queue export for <b>${username}</b> (${uid}) and email to <b>${email || 'their account email'}</b>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, export',
        cancelButtonText: 'Cancel'
    }).then((res)=>{
        if (!res.isConfirmed) return;
        const postData = { user_id: uid, email: email, username: username };
        $.post('export_user_data.php', postData, function(resp){
            let data = {};
            try { data = typeof resp === 'object' ? resp : JSON.parse(resp); } catch(e){}
            if (data && data.success) {
                Swal.fire('Queued','User export has been started in background.','success');
                closeSensitiveModal();
            } else {
                Swal.fire('Error', data.msg || 'Could not start export.', 'error');
            }
        }).fail(function(){
            Swal.fire('Error','Could not reach export endpoint.','error');
        });
    });
}
</script>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>
