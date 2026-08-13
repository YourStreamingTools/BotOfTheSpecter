<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

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
            echo json_encode(['status' => 'error', 'message' => t('mods_acting_as_disabled_message')]);
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
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        $broadcasterId = isset($_SESSION['twitchUserId']) ? (string)$_SESSION['twitchUserId'] : (string)($broadcasterID ?? '');
        $stmt = $conn->prepare('SELECT moderator_id FROM moderator_access WHERE broadcaster_id = ?');
        $stmt->bind_param('s', $broadcasterId);
        $stmt->execute();
        $moderatorsAccess = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $accessIds = [];
        foreach ($moderatorsAccess as $accessRow) {
            $accessModeratorId = (string)($accessRow['moderator_id'] ?? '');
            if ($accessModeratorId !== '') {
                $accessIds[$accessModeratorId] = true;
            }
        }

        $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
        $allModerators = [];
        $cursor = null;
        do {
            $moderatorsURL = 'https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=' . rawurlencode($broadcasterId);
            if ($cursor) {
                $moderatorsURL .= '&after=' . rawurlencode($cursor);
            }
            $curl = curl_init($moderatorsURL);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $authToken,
                'Client-ID: ' . $clientID
            ]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($response === false) {
                echo json_encode(['success' => false, 'error' => 'helix_request_failed']);
                exit();
            }
            if ($httpCode !== 200) {
                echo json_encode([
                    'success' => false,
                    'error' => 'helix_http_' . $httpCode,
                    'token_invalid' => ($httpCode === 401),
                ]);
                exit();
            }
            $moderatorsData = json_decode($response, true);
            if (!empty($moderatorsData['data']) && is_array($moderatorsData['data'])) {
                $allModerators = array_merge($allModerators, $moderatorsData['data']);
            }
            $cursor = $moderatorsData['pagination']['cursor'] ?? null;
        } while ($cursor);

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

        $filteredModerators = array_values(array_filter($allModerators, function ($moderator) use ($botAccounts) {
            return !in_array(strtolower($moderator['user_name'] ?? ''), $botAccounts, true);
        }));

        $botOfTheSpecterMod = null;
        foreach ($filteredModerators as $key => $mod) {
            if (strtolower($mod['user_name'] ?? '') === 'botofthespecter') {
                $botOfTheSpecterMod = $mod;
                unset($filteredModerators[$key]);
                break;
            }
        }
        if ($botOfTheSpecterMod) {
            $filteredModerators = array_merge([$botOfTheSpecterMod], array_values($filteredModerators));
        }

        $currentModeratorIdSet = array_flip(array_map('strval', array_column($allModerators, 'user_id')));
        $staleIds = [];
        foreach ($accessIds as $accessModeratorId => $_hasAccess) {
            if (!isset($currentModeratorIdSet[$accessModeratorId])) {
                $staleIds[] = $accessModeratorId;
            }
        }

        $registeredUsersByTwitchId = [];
        if (!empty($staleIds)) {
            $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
            $userLookupStmt = $conn->prepare('SELECT twitch_user_id, twitch_display_name, username, profile_image FROM users WHERE twitch_user_id IN (' . $placeholders . ')');
            if ($userLookupStmt) {
                $userLookupStmt->bind_param(str_repeat('s', count($staleIds)), ...$staleIds);
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
        }

        $staleProfileImages = [];
        foreach ($staleIds as $accessModeratorId) {
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

        $displayNames = [];
        foreach ($filteredModerators as $moderator) {
            $displayName = trim((string)($moderator['user_name'] ?? ''));
            if ($displayName !== '') {
                $displayNames[] = $displayName;
            }
        }
        $displayNames = array_values(array_unique($displayNames));
        $registeredUsers = [];
        if (!empty($displayNames)) {
            $placeholders = implode(',', array_fill(0, count($displayNames), '?'));
            $userStmt = $conn->prepare('SELECT twitch_display_name FROM users WHERE twitch_display_name IN (' . $placeholders . ')');
            if ($userStmt) {
                $userStmt->bind_param(str_repeat('s', count($displayNames)), ...$displayNames);
                $userStmt->execute();
                $result = $userStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $registeredUsers[] = strtolower($row['twitch_display_name']);
                }
                $userStmt->close();
            }
        }

        $modUserIds = array_values(array_filter(array_map('strval', array_column($filteredModerators, 'user_id'))));
        $modProfileImages = [];
        if (!empty($modUserIds)) {
            $chunks = array_chunk($modUserIds, 100);
            foreach ($chunks as $chunk) {
                $idsParam = implode('&id=', $chunk);
                $usersUrl = 'https://api.twitch.tv/helix/users?id=' . $idsParam;
                $curl = curl_init($usersUrl);
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $authToken,
                    'Client-ID: ' . $clientID
                ]);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $usersResponse = curl_exec($curl);
                curl_close($curl);
                if ($usersResponse !== false) {
                    $usersData = json_decode($usersResponse, true);
                    if (isset($usersData['data'])) {
                        foreach ($usersData['data'] as $helixUser) {
                            $modProfileImages[$helixUser['id']] = $helixUser['profile_image_url'];
                        }
                    }
                }
            }
        }

        if (!empty($staleProfileImages)) {
            $modProfileImages = array_merge($modProfileImages, $staleProfileImages);
        }

        $rows = [];
        foreach ($filteredModerators as $moderator) {
            $modDisplayName = (string)($moderator['user_name'] ?? '');
            $modUserId = (string)($moderator['user_id'] ?? '');
            $isStaleAccess = !empty($moderator['is_stale_access']);
            $hasAccess = isset($accessIds[$modUserId]);
            $isRegistered = in_array(strtolower($modDisplayName), $registeredUsers, true);
            $isSpecter = (strtolower($modDisplayName) === 'botofthespecter');
            if ($isSpecter) {
                $hasAccess = true;
                $isRegistered = true;
            }
            $rows[] = [
                'user_id' => $modUserId,
                'user_name' => $modDisplayName,
                'is_stale_access' => $isStaleAccess,
                'is_registered' => $isRegistered,
                'has_access' => $hasAccess,
                'is_specter' => $isSpecter,
                'profile_image' => isset($modProfileImages[$modUserId]) ? (string)$modProfileImages[$modUserId] : '',
            ];
        }

        echo json_encode(['success' => true, 'moderators' => $rows]);
    } catch (\Throwable $e) {
        error_log('mods.php list: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
$isActingAs = isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true;
$isActingAsAdmin = $isActingAs && isset($_SESSION['admin_act_as_actor_role']) && $_SESSION['admin_act_as_actor_role'] === 'admin';
$disableModActions = $isActingAs && !$isActingAsAdmin;

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
                            <tbody id="modsTableBody" aria-busy="true">
                                <?php for ($sk = 0; $sk < 5; $sk++): ?>
                                <tr aria-hidden="true">
                                    <td>
                                        <span style="display:flex;align-items:center;gap:0.5em;">
                                            <span class="sp-skeleton-avatar"></span>
                                            <span class="sp-skeleton-line w-50"></span>
                                        </span>
                                    </td>
                                    <td><span class="sp-skeleton-line w-40"></span></td>
                                    <td><span class="sp-skeleton-badge"></span></td>
                                </tr>
                                <?php endfor; ?>
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
    var actingAsDisabledMessage = <?php echo json_encode(t('mods_acting_as_disabled_message')); ?>;
    var actingAsDisabledTitle = <?php echo json_encode(t('mods_acting_as_disabled')); ?>;
    var accessUpdatedSuccess = <?php echo json_encode(t('mods_access_updated_success')); ?>;
    var accessUpdateFailed = <?php echo json_encode(t('mods_access_update_generic_failed')); ?>;
    var tokenInvalidHtml = <?php echo json_encode(t('mods_token_invalid')); ?>;
    var yesText = <?php echo json_encode(t('yes')); ?>;
    var noText = <?php echo json_encode(t('no')); ?>;
    var alwaysHasAccess = <?php echo json_encode(t('mods_always_has_access')); ?>;
    var noLongerMod = <?php echo json_encode(t('mods_no_longer_mod')); ?>;
    var disableModActions = <?php echo json_encode($disableModActions); ?>;
    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }
    function initialFromName(name) {
        var value = String(name || '');
        return value ? value.charAt(0).toUpperCase() : '?';
    }
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
            loadToastify().then(function() { showToast(actingAsDisabledMessage, false); });
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
                    btn.setAttribute('title', actingAsDisabledTitle);
                } else {
                    btn.removeAttribute('title');
                }
                loadToastify().then(function() { showToast(accessUpdatedSuccess, true); });
            } else {
                console.error('Server error:', json.message || json);
                loadToastify().then(function() { showToast(json.message || accessUpdateFailed, false); });
            }
        }).catch(function(err) {
            console.error('Error updating mod access:', err);
            loadToastify().then(function() { showToast(accessUpdateFailed, false); });
        }).finally(function() {
            if (!disableModActions) {
                btn.disabled = false;
            }
            btn.classList.remove('sp-btn-loading');
        });
    }
    function bindAccessButtons(root) {
        var buttons = (root || document).querySelectorAll('.access-control');
        buttons.forEach(function(btn) {
            btn.addEventListener('click', handleClick);
        });
    }
    function renderModsError(data) {
        var tbody = document.getElementById('modsTableBody');
        if (!tbody) return;
        tbody.setAttribute('aria-busy', 'false');
        if (data && data.token_invalid) {
            tbody.innerHTML = '<tr><td colspan="3"><div style="color:red;font-weight:bold;">' + tokenInvalidHtml + '</div></td></tr>';
            return;
        }
        tbody.innerHTML = '<tr><td colspan="3">' + escapeHtml(accessUpdateFailed) + '</td></tr>';
    }
    function renderMods(rows) {
        var tbody = document.getElementById('modsTableBody');
        if (!tbody) return;
        tbody.setAttribute('aria-busy', 'false');
        if (!rows.length) {
            tbody.innerHTML = '';
            return;
        }
        tbody.innerHTML = rows.map(function(mod) {
            var name = escapeHtml(mod.user_name);
            var img = mod.profile_image
                ? '<img src="' + escapeHtml(mod.profile_image) + '" alt="' + name + '" style="width:32px;height:32px;margin-right:0.5em;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                : '<span style="width:32px;height:32px;font-size:1.1rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;margin-right:0.5em;border-radius:50%;background:var(--accent-light);color:var(--accent-hover);flex-shrink:0;">' + escapeHtml(initialFromName(mod.user_name)) + '</span>';
            var stale = mod.is_stale_access
                ? '<span class="sp-badge sp-badge-amber ml-2">' + escapeHtml(noLongerMod) + '</span>'
                : '';
            var registered = mod.is_registered
                ? '<span class="sp-text-success">' + escapeHtml(yesText) + '</span>'
                : '<span class="sp-text-danger">' + escapeHtml(noText) + '</span>';
            var disabledAttrs = disableModActions
                ? ' disabled title="' + escapeHtml(actingAsDisabledTitle) + '"'
                : '';
            var access;
            if (mod.is_specter) {
                access = '<button class="sp-btn sp-btn-success" disabled>' + escapeHtml(alwaysHasAccess) + '</button>';
            } else if (mod.has_access) {
                access = '<button class="sp-btn sp-btn-danger access-control" data-user-id="' + escapeHtml(mod.user_id) + '" data-action="remove"' + disabledAttrs + '>' + escapeHtml(removeText) + '</button>';
            } else {
                access = '<button class="sp-btn sp-btn-primary access-control" data-user-id="' + escapeHtml(mod.user_id) + '" data-action="add"' + disabledAttrs + '>' + escapeHtml(addText) + '</button>';
            }
            return '<tr><td><span style="display:flex;align-items:center;">' + img + name + stale + '</span></td><td>' + registered + '</td><td>' + access + '</td></tr>';
        }).join('');
        bindAccessButtons(tbody);
    }
    function loadMods() {
        var url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax_action', 'list');
        fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    renderModsError(data);
                    return;
                }
                renderMods(Array.isArray(data.moderators) ? data.moderators : []);
            })
            .catch(function() {
                renderModsError(null);
            });
    }
    loadMods();
});
</script>
<?php
// Include the layout template
include 'layout.php';
?>
