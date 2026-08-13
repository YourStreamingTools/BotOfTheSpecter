<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('known_users_title');

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
        $tzStmt = $db->prepare("SELECT timezone FROM profile");
        $tzStmt->execute();
        $tzRow = $tzStmt->get_result()->fetch_assoc();
        $tzStmt->close();
        date_default_timezone_set($tzRow['timezone'] ?? 'UTC');

        $stmt = $db->prepare("SELECT id, username, first_seen, last_seen, welcome_message, status FROM seen_users ORDER BY id");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            foreach (['first_seen', 'last_seen'] as $seenCol) {
                $seenVal = $row[$seenCol] ?? '';
                if (!empty($seenVal) && $seenVal !== '0000-00-00 00:00:00') {
                    $row[$seenCol] = date('Y-m-d H:i:s', strtotime($seenVal));
                } else {
                    $row[$seenCol] = '';
                }
            }
        }
        unset($row);
        echo json_encode(['success' => true, 'users' => $rows, 'total' => count($rows)]);
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Fetch the total number of users in the seen_users table
$totalUsersSTMT = $db->prepare("SELECT COUNT(*) as total_users FROM seen_users");
$totalUsersSTMT->execute();
$totalUsersResult = $totalUsersSTMT->get_result()->fetch_assoc();
$totalUsers = (int)($totalUsersResult['total_users'] ?? 0);
$totalUsersSTMT->close();

// Cache for banned users
$cacheExpiration = 86400; // 24 hours
$loggedInUsername = $_SESSION['username'];
$cacheBaseDir = "/var/www/cache/known_users";
$cacheFile = "$cacheBaseDir/$loggedInUsername.json";
$cacheWarningMessage = null; // Initialize warning message

if (!is_dir($cacheBaseDir)) {
  if (!mkdir($cacheBaseDir, 0755, true) && !is_dir($cacheBaseDir)) {
    $cacheWarningMessage = t('known_users_cache_dir_error', ['dir' => $cacheBaseDir]);
    error_log($cacheWarningMessage . " User: " . $loggedInUsername);
  }
}
$bannedUsersCache = [];
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
    $cacheContent = file_get_contents($cacheFile);
    if ($cacheContent) {
        $bannedUsersCache = json_decode($cacheContent, true);
    }
} else {
    // Clear the cache if it is expired
    $bannedUsersCache = [];
}

// Handle POST requests for updates
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['username']) && isset($_POST['status'])) {
    $dbusername = $_POST['username'];
    $status = $_POST['status'];
    $updateQuery = $db->prepare("UPDATE seen_users SET status = ? WHERE username = ?");
    $updateQuery->bind_param('ss', $status, $dbusername);
    $updateQuery->execute();
    $updateQuery->close();
  }
  if (isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
    $userId = $_POST['userId'];
    $newWelcomeMessage = $_POST['newWelcomeMessage'];
    $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = ? WHERE id = ?");
    $messageQuery->bind_param('si', $newWelcomeMessage, $userId);
    $messageQuery->execute();
    $messageQuery->close();
    header("Location: known_users.php");
    exit();
  }
  if (isset($_POST['deleteUserId'])) {
    $deleteUserId = $_POST['deleteUserId'];
    $deleteQuery = $db->prepare("DELETE FROM seen_users WHERE id = ?");
    $deleteQuery->bind_param('i', $deleteUserId);
    $deleteQuery->execute();
    $deleteQuery->close();
    header("Location: known_users.php");
    exit();
  }
}

// Start output buffering for layout
ob_start();
?>
<script>
// Mobile device detection
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth < 768;
}

if (isMobileDevice()) {
    document.addEventListener('DOMContentLoaded', function() {
        // Hide all page content
        const pageContent = document.getElementById('knownUsersPageContent');
        if (pageContent) pageContent.style.display = 'none';
        // Create and insert mobile warning message
        const mobileWarning = document.createElement('div');
        mobileWarning.className = 'sp-mobile-warning-wrap';
        mobileWarning.innerHTML = `
            <div class="sp-alert sp-alert-warning" style="margin-top:2rem;">
                <h1 style="font-size:1.3rem; font-weight:700; text-align:center; margin-bottom:0.75rem;"><?= t('known_users_mobile_title') ?></h1>
                <div style="text-align:center;">
                    <p><?= t('known_users_mobile_apology') ?></p>
                    <p><?= t('known_users_mobile_unavailable') ?></p>
                    <p><?= t('known_users_mobile_working') ?></p>
                    <p><?= t('known_users_mobile_desktop') ?></p>
                </div>
            </div>
        `;
        // Insert the warning at the beginning of the page
        if (pageContent && pageContent.parentNode) {
            pageContent.parentNode.insertBefore(mobileWarning, pageContent);
        }
    });
}
</script>
<div id="knownUsersPageContent">
  <div id="loadingNoticeBox" class="sp-alert sp-alert-warning" style="margin-bottom:1rem;" aria-busy="true">
    <p id="loadingNotice">
      <?php echo t('known_users_loading', ['loaded' => 0, 'total' => $totalUsers]); ?>
    </p>
  </div>
  <?php if ($cacheWarningMessage): ?>
  <div class="sp-alert sp-alert-danger">
      <?php echo htmlspecialchars($cacheWarningMessage); ?>
  </div>
  <?php endif; ?>
  <div id="content" style="display: block;">
    <div class="sp-card">
          <div class="sp-card-header">
            <span class="sp-card-title">
              <i class="fas fa-users" style="margin-right:0.5rem;"></i>
              <?php echo t('known_users_title'); ?>
            </span>
          </div>
          <div class="sp-card-body">
            <div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
              <p style="font-weight:700; margin-bottom:0.25rem;">
                <span class="icon"><i class="fas fa-code"></i></span>
                <?= t('known_users_custom_variables_title') ?>
              </p>
              <p><?= t('known_users_custom_variables_intro') ?></p>
              <ul>
                <li><?= t('known_users_variable_shoutout') ?></li>
              </ul>
            </div>
            <div class="sp-alert sp-alert-warning" style="margin-bottom:1.25rem;">
              <p style="font-weight:700; margin-bottom:0.25rem;">
                <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                <?= t('known_users_how_to_use_title') ?>
              </p>
              <p>
                <?= t('known_users_how_to_use_no_post') ?>
              </p>
              <p style="margin-top:0.75rem;">
                <?= t('known_users_how_to_use_include_text') ?>
              </p>
              <ul style="margin-top:0.5rem;">
                <li><strong style="color: var(--green);">✓ <?= t('known_users_will_send_label') ?></strong> <code>Welcome back, BotOfTheSpecter! (shoutout)</code></li>
                <li><strong style="color: var(--green);">✓ <?= t('known_users_will_send_label') ?></strong> <code>Great to see you again, BotOfTheSpecter! (shoutout)</code></li>
                <li><strong style="color: var(--red);">✗ <?= t('known_users_no_send_label') ?></strong> <code>(shoutout)</code> <em><?= t('known_users_only_variable_note') ?></em></li>
              </ul>
              <p style="margin-top:0.75rem;">
                <strong style="color: var(--red);">⚠️ <?= t('known_users_note_label') ?></strong> <?= t('known_users_override_note') ?>
              </p>
            </div>
            <div class="sp-alert sp-alert-danger" style="font-weight:700; margin-bottom:1.25rem;"><?php echo t('known_users_edit_notice'); ?></div>
            <!-- Search Bar -->
            <input type="text" id="searchInput" class="sp-input" placeholder="<?php echo t('known_users_search_placeholder'); ?>" onkeyup="searchFunction()" style="margin-bottom:1rem;">
            <div class="sp-table-wrap">
              <table class="sp-table" id="commandsTable" aria-busy="true">
                <thead>
                  <tr>
                    <th><?php echo t('counters_username_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_first_seen_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_last_seen_column'); ?></th>
                    <th><?php echo t('known_users_welcome_message_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_status_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_action_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_editing_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_test_column'); ?></th>
                    <th style="text-align:center;"><?php echo t('known_users_removing_column'); ?></th>
                  </tr>
                </thead>
                <tbody id="user-table" aria-busy="true">
                  <?php for ($sk = 0; $sk < 5; $sk++): ?>
                  <tr aria-hidden="true">
                    <td><span class="sp-skeleton-line w-60"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-70"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-70"></span></td>
                    <td><span class="sp-skeleton-line w-80"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-40"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-30"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-30"></span></td>
                    <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-30"></span></td>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
  </div>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
let totalUsers = <?php echo (int)$totalUsers; ?>;
let loadedUsers = 0;
const bannedUsersCache = <?php echo json_encode(is_array($bannedUsersCache) ? $bannedUsersCache : []); ?> || {};
const KU_I18N = {
  noUsers: <?php echo json_encode(t('known_users_no_users')); ?>,
  loadError: <?php echo json_encode(t('known_users_test_invalid_response')); ?>,
  unknown: <?php echo json_encode(t('known_users_unknown')); ?>,
  characters: <?php echo json_encode(t('known_users_characters_label')); ?>,
  statusTrue: <?php echo json_encode(t('known_users_status_true')); ?>,
  statusFalse: <?php echo json_encode(t('known_users_status_false')); ?>,
  banned: <?php echo json_encode(t('known_users_banned_label')); ?>,
  userInactive: <?php echo json_encode(t('known_users_user_inactive_title')); ?>,
  loadingJs: <?php echo json_encode(t('known_users_loading_js')); ?>,
  loadingDone: <?php echo json_encode(t('known_users_loading_done')); ?>,
  deleteTitle: <?php echo json_encode(t('known_users_delete_confirm_title')); ?>,
  deleteText: <?php echo json_encode(t('known_users_delete_confirm_text')); ?>,
  deleteBtn: <?php echo json_encode(t('known_users_delete_confirm_btn')); ?>,
  cancel: <?php echo json_encode(t('cancel')); ?>,
  testSent: <?php echo json_encode(t('known_users_test_sent')); ?>,
  testError: <?php echo json_encode(t('known_users_test_error')); ?>,
  testFailed: <?php echo json_encode(t('known_users_test_failed')); ?>,
  testInvalid: <?php echo json_encode(t('known_users_test_invalid_response')); ?>,
  testFailedStatus: <?php echo json_encode(t('known_users_test_failed_status')); ?>
};

function escapeHtml(str) {
  return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
  });
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

function renderKnownUsersTable(users) {
  var tbody = document.getElementById('user-table');
  var table = document.getElementById('commandsTable');
  if (!tbody) return;
  tbody.setAttribute('aria-busy', 'false');
  if (table) table.setAttribute('aria-busy', users.length ? 'true' : 'false');
  if (!users.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;">' + escapeHtml(KU_I18N.noUsers) + '</td></tr>';
    return;
  }
  tbody.innerHTML = users.map(function(userData) {
    var userId = String(userData.id == null ? '' : userData.id);
    var kuName = String(userData.username == null ? '' : userData.username);
    var welcome = String(userData.welcome_message == null ? '' : userData.welcome_message);
    var isActive = userData.status === 'True';
    var firstSeen = userData.first_seen ? String(userData.first_seen) : '';
    var lastSeen = userData.last_seen ? String(userData.last_seen) : '';
    var kuBanCached = kuName !== '' && Object.prototype.hasOwnProperty.call(bannedUsersCache, kuName);
    var kuIsBanned = kuBanCached ? !!bannedUsersCache[kuName] : false;
    var banHtml = '';
    if (kuBanCached && kuIsBanned) {
      banHtml = '<em style="color:red">(' + escapeHtml(KU_I18N.banned) + ')</em>';
    } else if (!kuBanCached) {
      banHtml = '<span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;margin-left:0.35rem;vertical-align:middle;"></span>';
    }
    var testDisabled = isActive ? '' : ' disabled title="' + escapeHtml(KU_I18N.userInactive) + '"';
    return '<tr>' +
      '<td>' +
        '<span class="username" data-username="' + escapeHtml(kuName) + '">' + escapeHtml(kuName) + '</span>' +
        '<span class="banned-status"' + (kuBanCached ? '' : ' data-ban-pending="1"') + '>' + banHtml + '</span>' +
      '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' + escapeHtml(firstSeen || KU_I18N.unknown) + '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' + escapeHtml(lastSeen || KU_I18N.unknown) + '</td>' +
      '<td>' +
        '<div id="welcome-message-' + escapeHtml(userId) + '">' + escapeHtml(welcome) + '</div>' +
        '<div class="edit-box" id="edit-box-' + escapeHtml(userId) + '" style="display: none;">' +
          '<textarea class="sp-input welcome-message" data-user-id="' + escapeHtml(userId) + '" maxlength="255" style="height:auto; min-height:4rem;">' + escapeHtml(welcome) + '</textarea>' +
          '<div class="character-counter" id="counter-' + escapeHtml(userId) + '" style="font-size: 0.8em; margin-top: 0.25em; text-align: right; color: var(--text-muted);">' +
            '<span class="current-count">' + welcome.length + '</span>/255 ' + escapeHtml(KU_I18N.characters) +
          '</div>' +
        '</div>' +
      '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' +
        '<span style="color: ' + (isActive ? 'var(--green)' : 'var(--red)') + ';">' +
          escapeHtml(isActive ? KU_I18N.statusTrue : KU_I18N.statusFalse) +
        '</span>' +
      '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' +
        '<label style="cursor:pointer;">' +
          '<input type="checkbox" class="toggle-checkbox" data-username="' + escapeHtml(kuName) + '"' + (isActive ? ' checked' : '') + ' style="display:none;">' +
          '<span class="status-toggle-icon">' +
            '<i class="fa-solid ' + (isActive ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i>' +
          '</span>' +
        '</label>' +
      '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' +
        '<div class="edit-action-group" style="display: flex; flex-direction: column; align-items: center; gap:0.25rem;">' +
          '<button class="sp-btn sp-btn-primary sp-btn-sm edit-btn" data-user-id="' + escapeHtml(userId) + '">' +
            '<i class="fas fa-pencil-alt"></i>' +
          '</button>' +
          '<button class="sp-btn sp-btn-success sp-btn-sm save-edit-btn" data-user-id="' + escapeHtml(userId) + '" style="display:none;">' +
            '<i class="fas fa-floppy-disk"></i>' +
          '</button>' +
          '<button class="sp-btn sp-btn-danger sp-btn-sm cancel-edit-btn" data-user-id="' + escapeHtml(userId) + '" style="display:none;">' +
            '<i class="fas fa-xmark"></i>' +
          '</button>' +
        '</div>' +
      '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' +
        '<button class="sp-btn sp-btn-sm test-welcome-btn" style="background:var(--blue-bg); color:var(--blue); border-color:var(--blue);" data-username="' + escapeHtml(kuName) + '" data-message="' + escapeHtml(welcome) + '"' + testDisabled + '>' +
          '<i class="fas fa-paper-plane"></i>' +
        '</button>' +
      '</td>' +
      '<td style="text-align:center; vertical-align:middle;">' +
        '<form method="POST" style="display:inline;" class="delete-user-form">' +
          '<input type="hidden" name="deleteUserId" value="' + escapeHtml(userId) + '">' +
          '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm delete-user-btn"><i class="fas fa-trash-alt"></i></button>' +
        '</form>' +
      '</td>' +
    '</tr>';
  }).join('');
}

function renderKnownUsersError() {
  var tbody = document.getElementById('user-table');
  var table = document.getElementById('commandsTable');
  var loadingNoticeBox = document.getElementById('loadingNoticeBox');
  var loadingNotice = document.getElementById('loadingNotice');
  if (tbody) {
    tbody.setAttribute('aria-busy', 'false');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;">' + escapeHtml(KU_I18N.loadError) + '</td></tr>';
  }
  if (table) table.removeAttribute('aria-busy');
  if (loadingNotice) loadingNotice.innerText = KU_I18N.loadError;
  if (loadingNoticeBox) {
    loadingNoticeBox.classList.remove('sp-alert-warning', 'sp-alert-success', 'sp-alert-info');
    loadingNoticeBox.classList.add('sp-alert-danger');
    loadingNoticeBox.removeAttribute('aria-busy');
  }
}

function showEmptyNotice() {
  var table = document.getElementById('commandsTable');
  var loadingNoticeBox = document.getElementById('loadingNoticeBox');
  var loadingNotice = document.getElementById('loadingNotice');
  if (table) table.removeAttribute('aria-busy');
  if (loadingNotice) loadingNotice.innerText = KU_I18N.noUsers;
  if (loadingNoticeBox) {
    loadingNoticeBox.classList.remove('sp-alert-warning', 'sp-alert-success', 'sp-alert-danger');
    loadingNoticeBox.classList.add('sp-alert-info');
    loadingNoticeBox.removeAttribute('aria-busy');
  }
}

function loadKnownUsers() {
  var url = new URL(window.location.pathname, window.location.origin);
  url.searchParams.set('ajax_action', 'list');
  fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.success) {
        renderKnownUsersError();
        return;
      }
      var users = Array.isArray(data.users) ? data.users : [];
      totalUsers = users.length;
      renderKnownUsersTable(users);
      if (typeof searchFunction === 'function') {
        searchFunction();
      }
      if (!users.length) {
        showEmptyNotice();
        return;
      }
      loadedUsers = 0;
      updateLoadingNotice();
      fetchBannedStatuses();
    })
    .catch(function() {
      renderKnownUsersError();
    });
}

function bindKnownUsersTableEvents() {
  var tbody = document.getElementById('user-table');
  if (!tbody || tbody.dataset.kuBound === '1') return;
  tbody.dataset.kuBound = '1';
  tbody.addEventListener('click', function(e) {
    var toggleIcon = e.target.closest('.status-toggle-icon');
    if (toggleIcon) {
      var checkbox = toggleIcon.previousElementSibling;
      if (checkbox) checkbox.click();
      return;
    }
    var editBtn = e.target.closest('.edit-btn');
    if (editBtn) {
      var userId = editBtn.getAttribute('data-user-id');
      var editBox = document.getElementById('edit-box-' + userId);
      var welcomeMessage = document.getElementById('welcome-message-' + userId);
      var editActionGroup = editBtn.parentElement;
      var saveBtn = editActionGroup.querySelector('.save-edit-btn');
      var cancelBtn = editActionGroup.querySelector('.cancel-edit-btn');
      if (editBox) editBox.style.display = 'block';
      if (welcomeMessage) welcomeMessage.style.display = 'none';
      editBtn.style.display = 'none';
      if (saveBtn) saveBtn.style.display = '';
      if (cancelBtn) cancelBtn.style.display = '';
      return;
    }
    var saveBtn = e.target.closest('.save-edit-btn');
    if (saveBtn) {
      var userId = saveBtn.getAttribute('data-user-id');
      var editBox = document.getElementById('edit-box-' + userId);
      var newWelcomeMessage = editBox ? editBox.querySelector('.welcome-message').value : '';
      var editActionGroup = saveBtn.parentElement;
      var editBtn = editActionGroup.querySelector('.edit-btn');
      var cancelBtn = editActionGroup.querySelector('.cancel-edit-btn');
      var originalIcon = saveBtn.innerHTML;
      saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      saveBtn.disabled = true;
      updateWelcomeMessage(userId, newWelcomeMessage, saveBtn, originalIcon, editBtn, cancelBtn);
      return;
    }
    var cancelBtn = e.target.closest('.cancel-edit-btn');
    if (cancelBtn) {
      var userId = cancelBtn.getAttribute('data-user-id');
      var editActionGroup = cancelBtn.parentElement;
      var editBtn = editActionGroup.querySelector('.edit-btn');
      var saveBtn = editActionGroup.querySelector('.save-edit-btn');
      var editBox = document.getElementById('edit-box-' + userId);
      var welcomeMessage = document.getElementById('welcome-message-' + userId);
      if (editBox) editBox.style.display = 'none';
      if (welcomeMessage) welcomeMessage.style.display = '';
      if (editBtn) editBtn.style.display = '';
      if (saveBtn) saveBtn.style.display = 'none';
      cancelBtn.style.display = 'none';
      return;
    }
    var deleteBtn = e.target.closest('.delete-user-btn');
    if (deleteBtn) {
      e.preventDefault();
      var form = deleteBtn.closest('form');
      Swal.fire({
        title: KU_I18N.deleteTitle,
        text: KU_I18N.deleteText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: KU_I18N.deleteBtn,
        cancelButtonText: KU_I18N.cancel
      }).then(function(result) {
        if (result.isConfirmed && form) {
          form.submit();
        }
      });
      return;
    }
    var testBtn = e.target.closest('.test-welcome-btn');
    if (testBtn) {
      var username = testBtn.getAttribute('data-username');
      var message = testBtn.getAttribute('data-message');
      var originalIcon = testBtn.innerHTML;
      testBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      testBtn.disabled = true;
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/send_welcome_message.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
          testBtn.innerHTML = originalIcon;
          testBtn.disabled = false;
          if (xhr.status === 200) {
            try {
              var response = JSON.parse(xhr.responseText);
              if (response.success) {
                loadToastify().then(function() {
                  showToast(KU_I18N.testSent + ': ' + response.message, true);
                });
              } else {
                loadToastify().then(function() {
                  showToast(KU_I18N.testError + ': ' + (response.message || KU_I18N.testFailed), false);
                });
              }
            } catch (err) {
              loadToastify().then(function() {
                showToast(KU_I18N.testError + ': ' + KU_I18N.testInvalid, false);
              });
            }
          } else {
            loadToastify().then(function() {
              showToast(KU_I18N.testError + ': ' + KU_I18N.testFailedStatus + ' ' + xhr.status, false);
            });
          }
        }
      };
      xhr.send('username=' + encodeURIComponent(username) + '&message=' + encodeURIComponent(message));
    }
  });
  tbody.addEventListener('change', function(e) {
    if (e.target.classList.contains('toggle-checkbox')) {
      toggleStatus(e.target.getAttribute('data-username'), e.target.checked);
    }
  });
  tbody.addEventListener('input', function(e) {
    if (!e.target.classList.contains('welcome-message')) return;
    var userId = e.target.getAttribute('data-user-id');
    var counter = document.getElementById('counter-' + userId);
    if (!counter) return;
    var currentCount = e.target.value.length;
    var currentCountSpan = counter.querySelector('.current-count');
    if (currentCountSpan) currentCountSpan.textContent = currentCount;
    if (currentCount >= 240) {
      counter.style.color = '#ff3860';
    } else if (currentCount >= 200) {
      counter.style.color = '#ff9f43';
    } else {
      counter.style.color = '#ccc';
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  bindKnownUsersTableEvents();
  loadKnownUsers();
});

function setBanStatus(element, isBanned) {
  const bannedStatusElement = element.nextElementSibling;
  if (!bannedStatusElement) return;
  bannedStatusElement.removeAttribute('data-ban-pending');
  if (isBanned) {
    bannedStatusElement.innerHTML = " <em style='color:red'>(<?php echo t('known_users_banned_label'); ?>)</em>";
  } else {
    bannedStatusElement.innerHTML = "";
  }
}

function fetchBannedStatuses() {
  const usernamesElements = document.querySelectorAll('.username');
  if (totalUsers === 0) {
    return;
  }
  if (usernamesElements.length === 0 && totalUsers > 0) {
    handleAllUsersProcessed(false);
    return;
  }
  const uncachedUsers = [];
  const cachedUsers = [];
  usernamesElements.forEach(usernameElement => {
    const username = usernameElement.dataset.username;
    if (!(username in bannedUsersCache)) {
      // Keep SSR skeleton badge until batch returns
      uncachedUsers.push({username, element: usernameElement});
    } else {
      cachedUsers.push({username, element: usernameElement});
    }
  });
  cachedUsers.forEach(({username, element}) => {
    setBanStatus(element, !!bannedUsersCache[username]);
    loadedUsers++;
    updateLoadingNotice();
  });
  if (uncachedUsers.length === 0) {
    handleAllUsersProcessed(false);
    return;
  }
  const batchSize = 10;
  const batches = [];
  for (let i = 0; i < uncachedUsers.length; i += batchSize) {
    batches.push(uncachedUsers.slice(i, i + batchSize));
  }
  let completedBatches = 0;
  let newCacheEntriesMade = false;
  batches.forEach(batch => {
    fetchBannedStatusBatch(batch, (batchHadNewEntries) => {
      if (batchHadNewEntries) {
        newCacheEntriesMade = true;
      }
      completedBatches++;
      if (completedBatches === batches.length) {
        handleAllUsersProcessed(newCacheEntriesMade);
      }
    });
  });
}

function fetchBannedStatusBatch(userBatch, callback) {
  const usernames = userBatch.map(user => user.username);
  console.log(`Fetching banned status for batch of ${usernames.length} users:`, usernames);
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "/api/fetch_banned_status.php", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      let batchHadNewEntries = false;
      console.log(`Response received for batch of ${usernames.length} users`);
      if (xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          console.log(`Batch response:`, response);
          userBatch.forEach(({username, element}) => {
            const isBanned = response.bannedUsers && response.bannedUsers[username] === true;
            setBanStatus(element, isBanned);
            bannedUsersCache[username] = isBanned;
            batchHadNewEntries = true;
            loadedUsers++;
            updateLoadingNotice();
          });
          if (batchHadNewEntries) {
            const cacheUpdate = {};
            userBatch.forEach(({username}) => {
              cacheUpdate[username] = bannedUsersCache[username];
            });
            updateCacheOnServer(cacheUpdate);
          }
          
        } catch (e) {
          console.error(`Error parsing JSON for batch:`, e, xhr.responseText);
          userBatch.forEach(({element}) => {
            setBanStatus(element, false);
            loadedUsers++;
            updateLoadingNotice();
          });
        }
      } else {
        console.log(`Error fetching banned status for batch: ${xhr.status}`);
        userBatch.forEach(({element}) => {
          setBanStatus(element, false);
          loadedUsers++;
          updateLoadingNotice();
        });
      }
      if (callback) callback(batchHadNewEntries);
    }
  };
  xhr.send("usernames=" + encodeURIComponent(JSON.stringify(usernames)));
}

function updateCacheOnServer(cacheUpdate) {
  fetch('/api/update_banned_users_cache.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(cacheUpdate)
  }).then(res => {
    if (!res.ok) {
      return res.text().then(text => { throw new Error(`HTTP error! status: ${res.status}, body: ${text}`); });
    }
    return res.json();
  }).then(data => {
    console.log(`Cache updated on server for batch:`, data);
  }).catch(error => {
    console.error(`Error updating cache on server for batch:`, error);
  });
}
function handleAllUsersProcessed(cacheWasModified) {
  const loadingNoticeBox = document.getElementById('loadingNoticeBox');
  const loadingNotice = document.getElementById('loadingNotice');
  const table = document.getElementById('commandsTable');
  // Clear any leftover ban skeletons (e.g. race / partial failures)
  document.querySelectorAll('.banned-status[data-ban-pending]').forEach(function(el) {
    el.removeAttribute('data-ban-pending');
    el.innerHTML = '';
  });
  if (table) table.removeAttribute('aria-busy');
  if (!loadingNoticeBox || !loadingNotice) {
      console.error('Required UI elements for loading notice not found.');
      return;
  }
  loadingNotice.innerText = KU_I18N.loadingDone;
  loadingNoticeBox.classList.remove('sp-alert-warning', 'sp-alert-info');
  loadingNoticeBox.classList.add('sp-alert-success');
  loadingNoticeBox.removeAttribute('aria-busy');
  // Table stays visible the whole time; only the progress banner fades out.
  setTimeout(() => {
    loadingNoticeBox.style.display = 'none';
  }, 2000);
}
function updateLoadingNotice() {
  const loadingNotice = document.getElementById('loadingNotice');
  if (loadingNotice) {
    loadingNotice.innerText = KU_I18N.loadingJs.replace('{loaded}', loadedUsers).replace('{total}', totalUsers);
  }
}
function updateWelcomeMessage(userId, newWelcomeMessage, button, originalIcon, editBtn, cancelBtn) {
  console.log(`Updating welcome message for user ID ${userId} to "${newWelcomeMessage}"`);
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      // Restore button state
      button.innerHTML = originalIcon;
      button.disabled = false;
      // Hide save/cancel, show edit
      button.style.display = 'none';
      if (cancelBtn) cancelBtn.style.display = 'none';
      if (editBtn) editBtn.style.display = '';
      console.log(`Response received for updating welcome message of user ID ${userId}`);
      location.reload();
    }
  };
  xhr.send("userId=" + encodeURIComponent(userId) + "&newWelcomeMessage=" + encodeURIComponent(newWelcomeMessage));
}

function toggleStatus(username, isChecked) {
  console.log(`Toggling status for ${username} to ${isChecked ? 'True' : 'False'}`);
  var status = isChecked ? 'True' : 'False';
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      console.log(`Response received for toggling status of ${username}`);
      console.log(xhr.responseText);
      location.reload();
    }
  };
  xhr.send("username=" + encodeURIComponent(username) + "&status=" + status);
}
</script>
<?php
$scripts = ob_get_clean();

// Use the layout
include 'layout.php';
?>