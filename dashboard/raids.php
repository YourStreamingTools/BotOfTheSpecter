<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Auth check
if (!isset($_SESSION['access_token'])) {
  $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
  header('Location: login.php');
  exit();
}

// Page Title
$pageTitle = t('navbar_raids');

// Includes
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
session_write_close();
include "mod_access.php";
include 'user_db.php';

// Set timezone from profile
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Fetch analytics data
$recentReceivedRaids = [];
$recentSentRaids = [];
$latestSentRaid = null;
$topRaiders = [];
$avgViewers = null;

$formatViewerAverage = static function ($value): string {
  if ($value === null || $value === '') {
    return '0';
  }
  $number = (float) $value;
  if (floor($number) == $number) {
    return (string) (int) $number;
  }
  return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
};

try {
  // Recent received raids (includes NULL source for historical rows)
  $recentReceivedRes = $db->query("SELECT raider_name, viewers, created_at FROM analytic_raids WHERE source = 'received' OR source IS NULL ORDER BY created_at DESC LIMIT 25");
  if ($recentReceivedRes) {
    $recentReceivedRaids = $recentReceivedRes->fetch_all(MYSQLI_ASSOC);
  }
  // Recent sent raids (for latest + modal history)
  $recentSentRes = $db->query("SELECT raider_name, viewers, created_at FROM analytic_raids WHERE source = 'sent' ORDER BY created_at DESC LIMIT 5");
  if ($recentSentRes) {
      $recentSentRaids = $recentSentRes->fetch_all(MYSQLI_ASSOC);
    if (!empty($recentSentRaids)) {
      $latestSentRaid = $recentSentRaids[0];
    }
  }
  // Top raiders (received only; includes historical NULL source rows)
  $topRes = $db->query("SELECT raider_name, COUNT(*) AS raids, ROUND(AVG(viewers),1) AS avg_viewers, MAX(viewers) AS max_viewers FROM analytic_raids WHERE source = 'received' OR source IS NULL GROUP BY raider_name ORDER BY raids DESC, avg_viewers DESC LIMIT 5");
  if ($topRes) {
    $topRaiders = $topRes->fetch_all(MYSQLI_ASSOC);
  }
  $avgRes = $db->query("SELECT ROUND(AVG(viewers),1) AS avg_viewers FROM analytic_raids");
  if ($avgRes) {
    $avgRow = $avgRes->fetch_assoc();
    $avgViewers = $avgRow['avg_viewers'];
  }
} catch (Exception $e) {
  // Quietly fail and show empty state
}

ob_start();
?>
<div class="sp-card mb-5">
  <header class="sp-card-header">
    <div class="sp-card-title">
      <span class="icon mr-2"><i class="fas fa-bullhorn"></i></span>
      Raids
    </div>
  </header>
  <div class="sp-card-body">
    <div class="raids-layout">
      <div>
        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.85rem;">Recent Raids - Received</h3>
        <?php if (empty($recentReceivedRaids)): ?>
          <div style="text-align:center;padding:3rem 0;">
            <p class="sp-text-muted" style="font-size:1.1rem;">No received raid data available yet.</p>
          </div>
        <?php else: ?>
          <div class="sp-table-wrap">
            <table class="sp-table">
              <thead>
                <tr>
                  <th>Raider</th>
                  <th>Viewers</th>
                  <th>Date / Time</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentReceivedRaids as $r): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($r['raider_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['viewers']); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <div class="raids-section-head">
          <h3>Latest Raid - Sent</h3>
          <button class="sp-btn sp-btn-info sp-btn-sm" id="showLastFiveSentRaidsBtn" <?php echo empty($recentSentRaids) ? 'disabled' : ''; ?>>
            Show Last 5
          </button>
        </div>
        <?php if (empty($latestSentRaid)): ?>
          <p class="sp-text-muted">No sent raid data available yet.</p>
        <?php else: ?>
          <div class="sp-table-wrap">
            <table class="sp-table">
              <thead>
                <tr>
                  <th>Target</th>
                  <th>Viewers</th>
                  <th>Date / Time</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><?php echo htmlspecialchars($latestSentRaid['raider_name']); ?></td>
                  <td><?php echo htmlspecialchars($latestSentRaid['viewers']); ?></td>
                  <td><?php echo htmlspecialchars($latestSentRaid['created_at']); ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <div class="sp-modal-backdrop" id="lastFiveSentRaidsModal">
          <div class="sp-modal" style="max-width:min(900px,95vw);">
            <header class="sp-modal-head">
              <p class="sp-modal-title">Last 5 Sent Raids</p>
              <button class="sp-modal-close" aria-label="close" id="closeLastFiveSentRaidsModal">&times;</button>
            </header>
            <section class="sp-modal-body">
              <?php if (empty($recentSentRaids)): ?>
                <p class="sp-text-muted">No sent raid data available yet.</p>
              <?php else: ?>
                <div class="sp-table-wrap">
                  <table class="sp-table">
                    <thead>
                      <tr>
                        <th>Target</th>
                        <th>Viewers</th>
                        <th>Date / Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recentSentRaids as $s): ?>
                        <tr>
                          <td><?php echo htmlspecialchars($s['raider_name']); ?></td>
                          <td><?php echo htmlspecialchars($s['viewers']); ?></td>
                          <td><?php echo htmlspecialchars($s['created_at']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          </div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;">Top Raiders</h3>
        <?php if (empty($topRaiders)): ?>
          <p class="sp-text-muted">No data yet.</p>
        <?php else: ?>
          <ul style="padding-left:1.25rem;margin:0;">
            <?php foreach ($topRaiders as $t): ?>
              <li style="margin-bottom:0.5rem;"><strong><?php echo htmlspecialchars($t['raider_name']); ?></strong> - <?php echo htmlspecialchars($t['raids']); ?> raids, Avg: <?php echo htmlspecialchars($formatViewerAverage($t['avg_viewers'])); ?> viewers</li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
        <h4 style="font-size:0.9rem;font-weight:700;color:var(--text-primary);margin-bottom:0.4rem;">Overall Average Viewers</h4>
        <p style="font-size:1.1rem;"><?php echo $avgViewers !== null ? htmlspecialchars($avgViewers) . ' viewers' : 'N/A'; ?></p>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const openBtn = document.getElementById('showLastFiveSentRaidsBtn');
  const modal = document.getElementById('lastFiveSentRaidsModal');
  const closeBtn = document.getElementById('closeLastFiveSentRaidsModal');
  if (!openBtn || !modal) {
    return;
  }
  const closeModal = function () {
    modal.classList.remove('is-active');
  };
  openBtn.addEventListener('click', function () {
    if (!openBtn.disabled) {
      modal.classList.add('is-active');
    }
  });
  if (closeBtn) {
    closeBtn.addEventListener('click', closeModal);
  }
  modal.addEventListener('click', function (e) {
    if (e.target === modal) {
      closeModal();
    }
  });
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>