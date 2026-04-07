<?php
ob_start();
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Handle POST requests before any includes that may produce output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean(); // Discard any output accumulated so far (i18n, session, etc.)
    ini_set('display_errors', 0); // Prevent PHP error HTML from corrupting JSON
    header('Content-Type: application/json');
    // Catch any PHP fatal error and return it as JSON instead of a blank 500
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            while (ob_get_level()) ob_end_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => $error['message'],
                'file' => basename($error['file']),
                'line' => $error['line'],
            ]);
        }
    });
    try {
        ob_start(); // Buffer the DB include output
        require_once "/var/www/config/db_connect.php";
        ob_end_clean(); // Discard DB setup messages before sending JSON
        $moderator_id = isset($_POST['moderator_id']) ? $_POST['moderator_id'] : null;
        $broadcaster_id = isset($_SESSION['twitchUserId']) ? $_SESSION['twitchUserId'] : null;
        $action = isset($_POST['action']) ? $_POST['action'] : null;
        $isActingAs = isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true;
        $isActingAsAdmin = $isActingAs && isset($_SESSION['admin_act_as_actor_role']) && $_SESSION['admin_act_as_actor_role'] === 'admin';
        if (!$moderator_id || !$broadcaster_id || !$action) {
            echo json_encode(['status' => 'error', 'message' => 'missing_parameters']);
            exit();
        }
        if ($isActingAs && !$isActingAsAdmin && in_array($action, ['add', 'remove'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Managing dashboard access is disabled while acting as another channel.']);
            exit();
        }
        if ($action === 'add') {
            $stmt = $conn->prepare('INSERT INTO moderator_access (moderator_id, broadcaster_id) VALUES (?, ?)');
            if ($stmt === false) {
                $err = $conn->error;
                error_log('mods.php PREPARE ADD FAILED: ' . $err);
                echo json_encode(['status' => 'error', 'message' => $err]);
                exit();
            }
            if (!$stmt->bind_param('ss', $moderator_id, $broadcaster_id)) {
                $err = $stmt->error ?: $conn->error;
                error_log('mods.php BIND ADD FAILED: ' . $err);
                echo json_encode(['status' => 'error', 'message' => $err]);
                exit();
            }
            $res = $stmt->execute();
            if ($res) {
                echo json_encode(['status' => 'ok', 'action' => 'add', 'moderator_id' => $moderator_id]);
            } else {
                $err = $stmt->error ?: $conn->error;
                error_log('mods.php EXECUTE ADD FAILED: ' . $err);
                echo json_encode(['status' => 'error', 'message' => $err]);
            }
        } elseif ($action === 'remove') {
            $stmt = $conn->prepare('DELETE FROM moderator_access WHERE moderator_id = ? AND broadcaster_id = ?');
            if ($stmt === false) {
                $err = $conn->error;
                error_log('mods.php PREPARE REMOVE FAILED: ' . $err);
                echo json_encode(['status' => 'error', 'message' => $err]);
                exit();
            }
            if (!$stmt->bind_param('ss', $moderator_id, $broadcaster_id)) {
                $err = $stmt->error ?: $conn->error;
                error_log('mods.php BIND REMOVE FAILED: ' . $err);
                echo json_encode(['status' => 'error', 'message' => $err]);
                exit();
            }
            $res = $stmt->execute();
            if ($res) {
                echo json_encode(['status' => 'ok', 'action' => 'remove', 'moderator_id' => $moderator_id]);
            } else {
                $err = $stmt->error ?: $conn->error;
                error_log('mods.php EXECUTE REMOVE FAILED: ' . $err);
                echo json_encode(['status' => 'error', 'message' => $err]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'invalid_action']);
        }
    } catch (\Throwable $e) {
        error_log('mods.php throwable: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    }
    exit();
}

// Page Title
$pageTitle = t('mods_page_title');

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
$isActingAs = isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true;
$isActingAsAdmin = $isActingAs && isset($_SESSION['admin_act_as_actor_role']) && $_SESSION['admin_act_as_actor_role'] === 'admin';
$disableModActions = $isActingAs && !$isActingAsAdmin;

// Fetch all moderators and their access status (requires $conn from db_connect.php)
$stmt = $conn->prepare('SELECT * FROM moderator_access WHERE broadcaster_id = ?');
$stmt->bind_param('s', $_SESSION['twitchUserId']);
$stmt->execute();
$moderatorsAccess = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all registered users from the users table
$registeredUsers = [];
$registeredUsersByTwitchId = [];
$userStmt = $conn->prepare('SELECT twitch_display_name FROM users');
$userStmt->execute();
$result = $userStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $registeredUsers[] = strtolower($row['twitch_display_name']);
}

// Build a lookup for Twitch user ID -> display/profile data (used for stale access entries)
$userLookupStmt = $conn->prepare('SELECT twitch_user_id, twitch_display_name, username, profile_image FROM users WHERE twitch_user_id IS NOT NULL AND twitch_user_id != ""');
if ($userLookupStmt) {
    $userLookupStmt->execute();
    $lookupResult = $userLookupStmt->get_result();
    while ($lookupRow = $lookupResult->fetch_assoc()) {
        $lookupId = (string)($lookupRow['twitch_user_id'] ?? '');
        if ($lookupId === '') {
            continue;
        }
        $registeredUsersByTwitchId[$lookupId] = [
            'display_name' => (string)($lookupRow['twitch_display_name'] ?? ''),
            'username' => (string)($lookupRow['username'] ?? ''),
            'profile_image' => (string)($lookupRow['profile_image'] ?? ''),
        ];
    }
    $userLookupStmt->close();
}

// API endpoint to fetch moderators
$moderatorsURL = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=$broadcasterID";
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

$allModerators = [];
do {
    // Set up cURL request with headers
    $curl = curl_init($moderatorsURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $authToken,
        'Client-ID: ' . $clientID
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    // Execute cURL request
    $response = curl_exec($curl);
    if ($response === false) { exit; }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        if ($httpCode === 401) {
            echo "<div style='color:red;font-weight:bold;'>Your Twitch authentication token is invalid or expired. Please <a href='logout.php'>log in again</a> to refresh your session.</div>";
        }
        exit;
    }
    curl_close($curl);
    // Process and append moderator information to the array
    $moderatorsData = json_decode($response, true);
    $allModerators = array_merge($allModerators, $moderatorsData['data']);
    // Check if there are more pages of moderators
    $cursor = $moderatorsData['pagination']['cursor'] ?? null;
    $moderatorsURL = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=$broadcasterID&after=$cursor";
} while ($cursor);

// Number of moderators per page
$moderatorsPerPage = 50;

// Calculate the total number of pages
$totalPages = ceil(count($allModerators) / $moderatorsPerPage);

// Current page (default to 1 if not specified)
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, intval($_GET['page']))) : 1;

// Calculate the start and end index for the current page
$startIndex = ($currentPage - 1) * $moderatorsPerPage;
$endIndex = $startIndex + $moderatorsPerPage;

// Get moderators for the current page
$moderatorsForCurrentPage = array_slice($allModerators, $startIndex, $moderatorsPerPage);

// Filter out common bot accounts
$botAccounts = [
    'yourstreamingtools',
    'botrixoficial',
    'streamelements',
    'lumiastream',
    'kofistreambot',
    'fourthwall',
    'fourthwallhq',
    'nightbot',
    'moobot',
    'streamlabs',
    'commanderroot',
    'botisimo',
    'fossabot',
    'wizebot',
    'deepbot',
    'streamcaptainbot',
    'moderator',
    'raidshield',
    'ankhbot',
    'phantombot',
    'streamlooter',
    'revlobot',
    'scottybot',
    'ai_licia',
    'pokemoncommunitygame'
];

$filteredModerators = array_filter($allModerators, function($moderator) use ($botAccounts) {
    return !in_array(strtolower($moderator['user_name']), $botAccounts);
});

// Move BotOfTheSpecter to the top if present
$botOfTheSpecterMod = null;
foreach ($filteredModerators as $key => $mod) {
    if (strtolower($mod['user_name']) === 'botofthespecter') {
        $botOfTheSpecterMod = $mod;
        unset($filteredModerators[$key]);
        break;
    }
}
if ($botOfTheSpecterMod) {
    $filteredModerators = array_merge([$botOfTheSpecterMod], $filteredModerators);
}

// Include users who still have dashboard access but are no longer moderators on Twitch
$currentModeratorIds = array_map('strval', array_column($allModerators, 'user_id'));
$currentModeratorIdSet = array_flip($currentModeratorIds);
$staleProfileImages = [];
foreach ($moderatorsAccess as $accessRow) {
    $accessModeratorId = (string)($accessRow['moderator_id'] ?? '');
    if ($accessModeratorId === '' || isset($currentModeratorIdSet[$accessModeratorId])) {
        continue;
    }

    $lookup = $registeredUsersByTwitchId[$accessModeratorId] ?? null;
    $staleName = '';
    if (is_array($lookup)) {
        $staleName = trim((string)($lookup['display_name'] ?? ''));
        if ($staleName === '') {
            $staleName = trim((string)($lookup['username'] ?? ''));
        }
        if (!empty($lookup['profile_image'])) {
            $staleProfileImages[$accessModeratorId] = (string)$lookup['profile_image'];
        }
    }
    if ($staleName === '') {
        $staleName = 'User ' . $accessModeratorId;
    }

    $filteredModerators[] = [
        'user_id' => $accessModeratorId,
        'user_name' => $staleName,
        'is_stale_access' => true,
    ];
}

// Check if BotOfTheSpecter is already in the list
$botOfTheSpecterExists = false;
foreach ($filteredModerators as $mod) {
    if (strtolower($mod['user_name']) === 'botofthespecter') {
        $botOfTheSpecterExists = true;
        break;
    }
}

// Fetch profile images for all moderators (batch up to 100 per request)
$modUserIds = array_column($filteredModerators, 'user_id');
$modProfileImages = [];
if (!empty($modUserIds)) {
    $chunks = array_chunk($modUserIds, 100);
    foreach ($chunks as $chunk) {
        $idsParam = implode('&id=', $chunk);
        $usersUrl = "https://api.twitch.tv/helix/users?id=" . $idsParam;
        $curl = curl_init($usersUrl);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Client-ID: ' . $clientID
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $usersResponse = curl_exec($curl);
        if ($usersResponse !== false) {
            $usersData = json_decode($usersResponse, true);
            if (isset($usersData['data'])) {
                foreach ($usersData['data'] as $user) {
                    $modProfileImages[$user['id']] = $user['profile_image_url'];
                }
            }
        }
        curl_close($curl);
    }
}

if (!empty($staleProfileImages)) {
    $modProfileImages = array_merge($modProfileImages, $staleProfileImages);
}

// Start output buffering for layout
ob_start();
?>
<div class="sp-card mb-5">
    <div class="sp-card-header">
        <span class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-user-shield"></i></span>
            <?php echo t('mods_heading'); ?>
        </span>
    </div>
    <div class="sp-card-body">
                    <div class="sp-alert sp-alert-info mb-4" style="display:flex;gap:1rem;align-items:flex-start;">
                        <span class="icon" style="flex-shrink:0;font-size:1.5rem;"><i class="fas fa-user-shield"></i></span>
                        <div>
                            <p><strong><?php echo t('mods_dashboard_access_title'); ?></strong></p>
                            <p><?php echo t('mods_dashboard_access_desc'); ?></p>
                            <hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0;">
                            <p><strong><?php echo t('mods_security_warning'); ?></strong></p>
                        </div>
                    </div>
                    <div class="sp-card mb-4">
                        <div class="sp-card-body">
                            <p class="mb-2"><strong><i class="fas fa-info-circle mr-2"></i><?php echo t('mods_how_it_works_title'); ?></strong></p>
                            <p><strong><?php echo t('mods_table_name'); ?>:</strong> <?php echo t('mods_column_name_desc'); ?></p>
                            <p><strong><?php echo t('mods_table_registered'); ?>:</strong> <?php echo t('mods_column_registration_desc'); ?></p>
                            <p><strong><?php echo t('mods_table_access'); ?>:</strong> <?php echo t('mods_column_access_desc'); ?></p>
                            <hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0;">
                            <p class="sp-text-muted" style="font-size:0.82rem;"><span class="sp-text-info"><strong><?php echo t('mods_automatic_access_note'); ?></strong></span></p>
                            <p class="sp-text-muted" style="font-size:0.82rem;"><span class="sp-text-info"><strong><?php echo t('mods_bot_filtering_note'); ?></strong></span></p>
                        </div>
                    </div>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th><?php echo t('mods_table_name'); ?></th>
                                    <th><?php echo t('mods_table_registered'); ?></th>
                                    <th><?php echo t('mods_table_access'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($filteredModerators as $moderator) : 
                                    $modDisplayName = $moderator['user_name'];
                                    $modUserId = $moderator['user_id'];
                                    $isStaleAccess = !empty($moderator['is_stale_access']);
                                    $hasAccess = in_array($modUserId, array_column($moderatorsAccess, 'moderator_id'));
                                    $isRegistered = in_array(strtolower($modDisplayName), $registeredUsers);
                                    if (strtolower($modDisplayName) === 'botofthespecter') {
                                        $hasAccess = true;
                                        $isRegistered = true;
                                    }
                                    $profileImg = isset($modProfileImages[$modUserId]) && $modProfileImages[$modUserId]
                                        ? '<img src="' . htmlspecialchars($modProfileImages[$modUserId]) . '" alt="' . htmlspecialchars($modDisplayName) . '" style="width:32px;height:32px;margin-right:0.5em;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                                        : '<span style="width:32px;height:32px;font-size:1.1rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;margin-right:0.5em;border-radius:50%;background:var(--accent-light);color:var(--accent-hover);flex-shrink:0;">' . strtoupper(mb_substr($modDisplayName, 0, 1)) . '</span>';
                                ?>
                                <tr>
                                    <td>
                                        <span style="display:flex;align-items:center;">
                                            <?php echo $profileImg; ?>
                                            <?php echo htmlspecialchars($modDisplayName); ?>
                                            <?php if ($isStaleAccess): ?>
                                                <span class="sp-badge sp-badge-amber ml-2">No longer mod</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isRegistered) : ?>
                                            <span class="sp-text-success"><?php echo t('yes'); ?></span>
                                        <?php else : ?>
                                            <span class="sp-text-danger"><?php echo t('no'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (strtolower($modDisplayName) === 'botofthespecter') : ?>
                                            <button class="sp-btn sp-btn-success" disabled><?php echo t('mods_always_has_access'); ?></button>
                                        <?php elseif ($hasAccess) : ?>
                                            <button class="sp-btn sp-btn-danger access-control" data-user-id="<?php echo $modUserId; ?>" data-action="remove" <?php echo $disableModActions ? 'disabled title="Disabled while acting as another channel"' : ''; ?>><?php echo t('mods_remove_access'); ?></button>
                                        <?php else : ?>
                                            <button class="sp-btn sp-btn-primary access-control" data-user-id="<?php echo $modUserId; ?>" data-action="add" <?php echo $disableModActions ? 'disabled title="Disabled while acting as another channel"' : ''; ?>><?php echo t('mods_add_access'); ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var addText = <?php echo json_encode(t('mods_add_access')); ?>;
    var removeText = <?php echo json_encode(t('mods_remove_access')); ?>;
    var disableModActions = <?php echo json_encode($disableModActions); ?>;
    function loadToastify() {
        return new Promise(function(resolve) {
            if (window.Toastify) return resolve();
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css';
            document.head.appendChild(css);
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/toastify-js';
            script.onload = function() { resolve(); };
            script.onerror = function() { resolve(); };
            document.body.appendChild(script);
        });
    }
    function showToast(message, success) {
        if (window.Toastify) {
            Toastify({
                text: message,
                duration: 3500,
                close: true,
                gravity: 'top',
                position: 'right',
                style: { background: success ? '#48c774' : '#f14668' }
            }).showToast();
        } else {
            alert(message);
        }
    }
    function handleClick(e) {
        var btn = e.currentTarget;
        var twitchUserId = btn.getAttribute('data-user-id');
        var action = btn.getAttribute('data-action');
        if (disableModActions && (action === 'add' || action === 'remove')) {
            loadToastify().then(function() { showToast('Managing dashboard access is disabled while acting as another channel.', false); });
            return;
        }
        console.debug('mods: sending', { moderator_id: twitchUserId, action: action });
        btn.disabled = true;
        btn.classList.add('sp-btn-loading');
        var formData = new FormData();
        formData.append('moderator_id', twitchUserId);
        formData.append('action', action);
        fetch('mods.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function(resp) {
            if (!resp.ok) throw new Error('Network response was not ok ' + resp.status);
            return resp.json();
        }).then(function(json) {
            if (json.status === 'ok') {
                if (json.action === 'add') {
                    btn.classList.remove('sp-btn-primary');
                    btn.classList.add('sp-btn-danger');
                    btn.setAttribute('data-action', 'remove');
                    btn.textContent = removeText;
                } else if (json.action === 'remove') {
                    btn.classList.remove('sp-btn-danger');
                    btn.classList.add('sp-btn-primary');
                    btn.setAttribute('data-action', 'add');
                    btn.textContent = addText;
                }
                if (disableModActions) {
                    btn.disabled = true;
                    btn.setAttribute('title', 'Disabled while acting as another channel');
                } else {
                    btn.removeAttribute('title');
                }
                loadToastify().then(function() { showToast('Access updated successfully', true); });
            } else {
                console.error('Server error:', json.message || json);
                loadToastify().then(function() { showToast(json.message || 'Failed to update access', false); });
            }
        }).catch(function(err) {
            console.error('Error updating mod access:', err);
            loadToastify().then(function() { showToast('Failed to update access', false); });
        }).finally(function() {
            if (!disableModActions) {
                btn.disabled = false;
            }
            btn.classList.remove('sp-btn-loading');
        });
    }
    var buttons = document.querySelectorAll('.access-control');
    buttons.forEach(function(btn) {
        btn.addEventListener('click', handleClick);
    });
});
</script>
<?php
// Include the layout template
include 'layout.php';
?>