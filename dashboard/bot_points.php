<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ../login.php');
    exit();
}

// Page Title
$pageTitle = t('bot_points_title');

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
$status = '';

// Handle POST requests (settings update, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_points'])) {
        $user_name = $_POST['user_name'];
        $points = $_POST['points'];
        $updatePointsStmt = $db->prepare("UPDATE bot_points SET points = ? WHERE user_name = ?");
        $updatePointsStmt->bind_param("is", $points, $user_name);
        $updatePointsStmt->execute();
        $updatePointsStmt->close();
        $status = t('bot_points_update_success');
    } elseif (isset($_POST['remove_user'])) {
        $user_name = $_POST['user_name'];
        $removeUserStmt = $db->prepare("DELETE FROM bot_points WHERE user_name = ?");
        $removeUserStmt->bind_param("s", $user_name);
        $removeUserStmt->execute();
        $removeUserStmt->close();
        $status = t('bot_points_remove_success');
    } else {
        $point_name = $_POST['point_name'];
        $point_amount_chat = $_POST['point_amount_chat'];
        $point_amount_follower = $_POST['point_amount_follower'];
        $point_amount_subscriber = $_POST['point_amount_subscriber'];
        $point_amount_cheer = $_POST['point_amount_cheer'];
        $point_amount_raid = $_POST['point_amount_raid'];
        $subscriber_multiplier = $_POST['subscriber_multiplier'];
        $excluded_users = $_POST['excluded_users'];
        $updateStmt = $db->prepare("UPDATE bot_settings SET 
            point_name = ?, 
            point_amount_chat = ?, 
            point_amount_follower = ?, 
            point_amount_subscriber = ?, 
            point_amount_cheer = ?, 
            point_amount_raid = ?, 
            subscriber_multiplier = ?, 
            excluded_users = ?
        WHERE id = 1");
        $updateStmt->bind_param(
            "siiiiiss", 
            $point_name, 
            $point_amount_chat, 
            $point_amount_follower, 
            $point_amount_subscriber, 
            $point_amount_cheer, 
            $point_amount_raid, 
            $subscriber_multiplier,
            $excluded_users
        );
        $updateStmt->execute();
        $updateStmt->close();
        $status = t('bot_points_settings_update_success');
    }
}

// Fetch settings (MySQLi)
$settingsStmt = $db->prepare("SELECT * FROM bot_settings WHERE id = 1");
$settingsStmt->execute();
$result = $settingsStmt->get_result();
$settings = $result->fetch_assoc();
$settingsStmt->close();
$pointsName = htmlspecialchars($settings['point_name']);
$excludedUsers = htmlspecialchars($settings['excluded_users']);

// Fetch users and their points from bot_points table (MySQLi)
$pointsStmt = $db->prepare("SELECT user_name, points FROM bot_points ORDER BY points DESC");
$pointsStmt->execute();
$result = $pointsStmt->get_result();
$pointsData = [];
while ($row = $result->fetch_assoc()) {
    $pointsData[] = $row;
}
$pointsStmt->close();

// If requested via AJAX, return the JSON data
if (isset($_GET['action']) && $_GET['action'] == 'get_points_data') {
    echo json_encode($pointsData);
    exit();
}

// Show connected database name (MySQLi)
$connectedDb = $db->query('select database()')->fetch_row()[0];

// Start output buffering for layout template
ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <span class="sp-card-title">
      <i class="fas fa-coins" style="margin-right:0.5rem;"></i>
      <?php echo t('bot_points_title'); ?>
    </span>
    <button class="sp-btn sp-btn-primary" style="margin-left:auto;" id="settingsButton">
      <span class="icon"><i class="fas fa-cog"></i></span>
      <span><?php echo t('bot_points_settings_btn'); ?></span>
    </button>
  </div>
  <div class="sp-card-body">
        <?php if ($status): ?>
          <div class="sp-alert sp-alert-success"><?php echo $status; ?></div>
        <?php endif; ?>
        <p id="updateInfo" class="mb-3"><?php echo t('bot_points_data_last_updated'); ?> <span id="secondsAgo">0</span> <?php echo t('bot_points_seconds_ago'); ?></p>
        <div class="sp-table-wrap">
          <table class="sp-table" id="pointsTable">
            <thead>
              <tr>
                <th style="text-align:center;"><?php echo t('bot_points_username'); ?></th>
                <th style="text-align:center;"><?php echo $pointsName !== 'Points' ? $pointsName . ' ' . t('bot_points_points') : t('bot_points_points'); ?></th>
                <th style="text-align:center;"><?php echo t('bot_points_actions'); ?></th>
              </tr>
            </thead>
            <tbody id="pointsTableBody">
              <?php foreach ($pointsData as $row): ?>
                <tr>
                  <td style="text-align:center; white-space:nowrap; vertical-align:middle;"><?php echo htmlspecialchars($row['user_name']); ?></td>
                  <td style="text-align:center; white-space:nowrap; vertical-align:middle;"><?php echo htmlspecialchars($row['points']); ?></td>
                  <td style="text-align:center; vertical-align:middle;">
                    <form method="POST" action="" style="display:inline;">
                      <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($row['user_name']); ?>">
                      <div style="display:flex; gap:0.4rem; justify-content:center; align-items:center;">
                        <input class="sp-input" type="number" name="points" value="<?php echo htmlspecialchars($row['points']); ?>" required style="width:100px;">
                        <button class="sp-btn sp-btn-primary" type="submit" name="update_points"><?php echo t('bot_points_update_btn'); ?></button>
                        <button class="sp-btn sp-btn-danger" type="submit" name="remove_user"><?php echo t('bot_points_remove_btn'); ?></button>
                      </div>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
  </div>
</div>
<!-- Settings Modal -->
<div class="cc-modal-backdrop" id="settingsModal">
  <div class="cc-modal">
    <div class="cc-modal-head">
      <span class="cc-modal-title"><?php echo t('bot_points_settings_title'); ?></span>
      <button class="cc-modal-close" aria-label="close" id="closeModal">&times;</button>
    </div>
    <div class="cc-modal-body">
      <form method="POST" action="">
        <div class="sp-form-group">
          <label class="sp-label"><?php echo t('bot_points_points_name'); ?></label>
          <input class="sp-input" type="text" name="point_name" value="<?php echo $pointsName; ?>" required>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_per_chat'); ?></label>
          <input class="sp-input" type="number" name="point_amount_chat" value="<?php echo htmlspecialchars($settings['point_amount_chat']); ?>" required>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_for_following'); ?></label>
          <input class="sp-input" type="number" name="point_amount_follower" value="<?php echo htmlspecialchars($settings['point_amount_follower']); ?>" required>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_for_subscribing'); ?></label>
          <input class="sp-input" type="number" name="point_amount_subscriber" value="<?php echo htmlspecialchars($settings['point_amount_subscriber']); ?>" required>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_per_cheer'); ?></label>
          <input class="sp-input" type="number" name="point_amount_cheer" value="<?php echo htmlspecialchars($settings['point_amount_cheer']); ?>" required>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_per_raid'); ?></label>
          <input class="sp-input" type="number" name="point_amount_raid" value="<?php echo htmlspecialchars($settings['point_amount_raid']); ?>" required>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo t('bot_points_subscriber_multiplier'); ?></label>
          <select class="sp-select" name="subscriber_multiplier">
            <option value="0" <?php echo $settings['subscriber_multiplier'] == 0 ? 'selected' : ''; ?>><?php echo t('bot_points_none'); ?></option>
            <?php for ($i = 2; $i <= 10; $i++): ?>
              <option value="<?php echo $i; ?>" <?php echo $settings['subscriber_multiplier'] == $i ? 'selected' : ''; ?>><?php echo $i; ?>x</option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="sp-form-group">
          <label class="sp-label"><?php echo t('bot_points_excluded_users'); ?></label>
          <input class="sp-input" type="text" name="excluded_users" value="<?php echo $excludedUsers; ?>" required>
          <small class="sp-help"><?php echo t('bot_points_excluded_users_help'); ?></small>
        </div>
        <button class="sp-btn sp-btn-primary" type="submit"><?php echo t('bot_points_update_settings_btn'); ?></button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
let secondsAgo = 0;
function updatePointsTable() {
  $.ajax({
    url: '?action=get_points_data',
    method: 'GET',
    success: function(data) {
      const pointsData = JSON.parse(data);
      let tableBody = '';
      const updateLabel = <?php echo json_encode(t('bot_points_update_btn')); ?>;
      const removeLabel = <?php echo json_encode(t('bot_points_remove_btn')); ?>;
      pointsData.forEach(function(row) {
        tableBody += `<tr>
          <td style="text-align:center; white-space:nowrap; vertical-align:middle;">${row.user_name}</td>
          <td style="text-align:center; white-space:nowrap; vertical-align:middle;">${row.points}</td>
          <td style="text-align:center; vertical-align:middle;">
            <form method="POST" action="" style="display:inline;">
              <input type="hidden" name="user_name" value="${row.user_name}">
              <div style="display:flex; gap:0.4rem; justify-content:center; align-items:center;">
                <input class="sp-input" type="number" name="points" value="${row.points}" required style="width:100px;">
                <button class="sp-btn sp-btn-primary" type="submit" name="update_points">${updateLabel}</button>
                <button class="sp-btn sp-btn-danger" type="submit" name="remove_user">${removeLabel}</button>
              </div>
            </form>
          </td>
        </tr>`;
      });
      $('#pointsTableBody').html(tableBody);
      secondsAgo = 0;
    }
  });
}

function updateSecondsAgo() {
  secondsAgo++;
  $('#secondsAgo').text(secondsAgo);
}

updatePointsTable();
setInterval(updatePointsTable, 30000);
setInterval(updateSecondsAgo, 1000);

// Modal Script
document.getElementById('settingsButton').addEventListener('click', function() {
  document.getElementById('settingsModal').classList.add('is-active');
});
document.getElementById('closeModal').addEventListener('click', function() {
  document.getElementById('settingsModal').classList.remove('is-active');
});
document.getElementById('settingsModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('is-active');
});
</script>
<?php
$scripts = ob_get_clean();

// Use the dashboard layout
include 'layout.php';
?>