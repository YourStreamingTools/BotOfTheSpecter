<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
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
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header" style="border-bottom: 1px solid #23272f;">
        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
          <span class="icon mr-2"><i class="fas fa-coins"></i></span>
          <?php echo t('bot_points_title'); ?>
        </span>
        <button class="button is-primary ml-auto" id="settingsButton">
          <span class="icon"><i class="fas fa-cog"></i></span>
          <span><?php echo t('bot_points_settings_btn'); ?></span>
        </button>
      </header>
      <div class="card-content">
        <?php if ($status): ?>
          <div class="notification is-success"><?php echo $status; ?></div>
        <?php endif; ?>
        <p id="updateInfo" class="mb-3"><?php echo t('bot_points_data_last_updated'); ?> <span id="secondsAgo">0</span> <?php echo t('bot_points_seconds_ago'); ?></p>
        <div class="table-container">
          <table class="table is-fullwidth has-background-dark" id="pointsTable">
            <thead>
              <tr>
                <th class="has-text-centered"><?php echo t('bot_points_username'); ?></th>
                <th class="has-text-centered"><?php echo $pointsName !== 'Points' ? $pointsName . ' ' . t('bot_points_points') : t('bot_points_points'); ?></th>
                <th class="has-text-centered"><?php echo t('bot_points_actions'); ?></th>
              </tr>
            </thead>
            <tbody id="pointsTableBody">
              <?php foreach ($pointsData as $row): ?>
                <tr>
                  <td class="has-text-centered" style="white-space: nowrap; vertical-align: middle;"><?php echo htmlspecialchars($row['user_name']); ?></td>
                  <td class="has-text-centered" style="white-space: nowrap; vertical-align: middle;"><?php echo htmlspecialchars($row['points']); ?></td>
                  <td class="has-text-centered is-centered is-flex is-justify-content-center is-align-items-center" style="white-space: nowrap; vertical-align: middle;">
                    <form method="POST" action="" style="display:inline;">
                      <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($row['user_name']); ?>">
                      <div class="field has-addons is-flex is-justify-content-center is-align-items-center">
                        <div class="control"><input class="input" type="number" name="points" value="<?php echo htmlspecialchars($row['points']); ?>" required style="width: 100px;"></div>
                        <div class="control" style="margin-left: 5px;"><button class="button is-primary" type="submit" name="update_points"><?php echo t('bot_points_update_btn'); ?></button></div>
                        <div class="control" style="margin-left: 5px;"><button class="button is-danger" type="submit" name="remove_user"><?php echo t('bot_points_remove_btn'); ?></button></div>
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
  </div>
</div>
<!-- Settings Modal -->
<div class="modal" id="settingsModal">
  <div class="modal-background"></div>
  <div class="modal-card" style="background-color: #23272f; color: #fff;">
    <header class="modal-card-head" style="background-color: #1a1a1a;">
      <p class="modal-card-title"><?php echo t('bot_points_settings_title'); ?></p>
      <button class="delete" aria-label="close" id="closeModal"></button>
    </header>
    <section class="modal-card-body">
      <form method="POST" action="">
        <div class="field">
          <label class="label"><?php echo t('bot_points_points_name'); ?></label>
          <div class="control">
            <input class="input" type="text" name="point_name" value="<?php echo $pointsName; ?>" required>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_per_chat'); ?></label>
          <div class="control">
            <input class="input" type="number" name="point_amount_chat" value="<?php echo htmlspecialchars($settings['point_amount_chat']); ?>" required>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_for_following'); ?></label>
          <div class="control">
            <input class="input" type="number" name="point_amount_follower" value="<?php echo htmlspecialchars($settings['point_amount_follower']); ?>" required>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_for_subscribing'); ?></label>
          <div class="control">
            <input class="input" type="number" name="point_amount_subscriber" value="<?php echo htmlspecialchars($settings['point_amount_subscriber']); ?>" required>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_per_cheer'); ?></label>
          <div class="control">
            <input class="input" type="number" name="point_amount_cheer" value="<?php echo htmlspecialchars($settings['point_amount_cheer']); ?>" required>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo $pointsName; ?> <?php echo t('bot_points_earned_per_raid'); ?></label>
          <div class="control">
            <input class="input" type="number" name="point_amount_raid" value="<?php echo htmlspecialchars($settings['point_amount_raid']); ?>" required>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo t('bot_points_subscriber_multiplier'); ?></label>
          <div class="control">
            <div class="select is-fullwidth">
              <select name="subscriber_multiplier">
                <option value="0" <?php echo $settings['subscriber_multiplier'] == 0 ? 'selected' : ''; ?>><?php echo t('bot_points_none'); ?></option>
                <?php for ($i = 2; $i <= 10; $i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo $settings['subscriber_multiplier'] == $i ? 'selected' : ''; ?>><?php echo $i; ?>x</option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="field">
          <label class="label"><?php echo t('bot_points_excluded_users'); ?></label>
          <div class="control">
            <input class="input" type="text" name="excluded_users" value="<?php echo $excludedUsers; ?>" required>
          </div>
          <p class="help"><?php echo t('bot_points_excluded_users_help'); ?></p>
        </div>
        <div class="field">
          <div class="control">
            <button class="button is-primary" type="submit"><?php echo t('bot_points_update_settings_btn'); ?></button>
          </div>
        </div>
      </form>
    </section>
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
          <td class="has-text-centered" style="white-space: nowrap; vertical-align: middle;">${row.user_name}</td>
          <td class="has-text-centered" style="white-space: nowrap; vertical-align: middle;">${row.points}</td>
          <td class="has-text-centered is-centered is-flex is-justify-content-center is-align-items-center" style="white-space: nowrap; vertical-align: middle;">
            <form method="POST" action="" style="display:inline;">
              <input type="hidden" name="user_name" value="${row.user_name}">
              <div class="field has-addons is-flex is-justify-content-center is-align-items-center">
                <div class="control"><input class="input" type="number" name="points" value="${row.points}" required style="width: 100px;"></div>
                <div class="control" style="margin-left: 5px;"><button class="button is-primary" type="submit" name="update_points">${updateLabel}</button></div>
                <div class="control" style="margin-left: 5px;"><button class="button is-danger" type="submit" name="remove_user">${removeLabel}</button></div>
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
document.querySelector('.modal-background').addEventListener('click', function() {
  document.getElementById('settingsModal').classList.remove('is-active');
});
</script>
<?php
$scripts = ob_get_clean();

// Use the dashboard layout
include 'layout.php';
?>