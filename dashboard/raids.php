<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Auth check
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('navbar_raids');

// Includes
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
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
    // Top raiders (overall)
    $topRes = $db->query("SELECT raider_name, COUNT(*) AS raids, ROUND(AVG(viewers),1) AS avg_viewers, MAX(viewers) AS max_viewers FROM analytic_raids GROUP BY raider_name ORDER BY raids DESC LIMIT 5");
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
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header is-flex is-align-items-center is-justify-content-space-between" style="border-bottom: 1px solid #23272f; padding: 1rem 1.5rem;">
        <div class="card-header-title is-size-4 has-text-white" style="font-weight:700; padding: 0;">
          <span class="icon mr-2"><i class="fas fa-bullhorn"></i></span>
          Raids
        </div>
      </header>
      <div class="card-content">
        <div class="content">
          <div class="columns">
            <div class="column is-two-thirds">
              <h3 class="title is-5 has-text-white">Recent Raids — Received</h3>
              <?php if (empty($recentReceivedRaids)): ?>
                <div class="has-text-centered py-6">
                  <p class="has-text-grey-light is-size-5">No received raid data available yet.</p>
                </div>
              <?php else: ?>
                <div class="table-container">
                  <table class="table is-fullwidth has-background-dark has-text-white">
                    <thead class="has-background-grey-darker">
                      <tr>
                        <th class="has-text-white">Raider</th>
                        <th class="has-text-white">Viewers</th>
                        <th class="has-text-white">Date / Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recentReceivedRaids as $r): ?>
                        <tr>
                          <td class="has-text-white"><?php echo htmlspecialchars($r['raider_name']); ?></td>
                          <td class="has-text-white"><?php echo htmlspecialchars($r['viewers']); ?></td>
                          <td class="has-text-white"><?php echo htmlspecialchars($r['created_at']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
            <div class="column">
              <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
                <h3 class="title is-5 has-text-white mb-0">Latest Raid — Sent</h3>
                <button class="button is-small is-info" id="showLastFiveSentRaidsBtn" <?php echo empty($recentSentRaids) ? 'disabled' : ''; ?>>
                  Show Last 5
                </button>
              </div>
              <?php if (empty($latestSentRaid)): ?>
                <p class="has-text-grey-light">No sent raid data available yet.</p>
              <?php else: ?>
                <div class="table-container">
                  <table class="table is-fullwidth has-background-dark has-text-white">
                    <thead class="has-background-grey-darker">
                      <tr>
                        <th class="has-text-white">Target</th>
                        <th class="has-text-white">Viewers</th>
                        <th class="has-text-white">Date / Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td class="has-text-white"><?php echo htmlspecialchars($latestSentRaid['raider_name']); ?></td>
                        <td class="has-text-white"><?php echo htmlspecialchars($latestSentRaid['viewers']); ?></td>
                        <td class="has-text-white"><?php echo htmlspecialchars($latestSentRaid['created_at']); ?></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>

              <div class="modal" id="lastFiveSentRaidsModal">
                <div class="modal-background"></div>
                <div class="modal-card" style="background-color: #23272f; color: #fff; width: min(900px, 95vw);">
                  <header class="modal-card-head" style="background-color: #1a1a1a; border-bottom: 1px solid #23272f;">
                    <p class="modal-card-title has-text-white">Last 5 Sent Raids</p>
                    <button class="delete" aria-label="close" id="closeLastFiveSentRaidsModal"></button>
                  </header>
                  <section class="modal-card-body" style="background-color: #23272f;">
                    <?php if (empty($recentSentRaids)): ?>
                      <p class="has-text-grey-light">No sent raid data available yet.</p>
                    <?php else: ?>
                      <div class="table-container">
                        <table class="table is-fullwidth has-background-dark has-text-white">
                          <thead class="has-background-grey-darker">
                            <tr>
                              <th class="has-text-white">Target</th>
                              <th class="has-text-white">Viewers</th>
                              <th class="has-text-white">Date / Time</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($recentSentRaids as $s): ?>
                              <tr>
                                <td class="has-text-white"><?php echo htmlspecialchars($s['raider_name']); ?></td>
                                <td class="has-text-white"><?php echo htmlspecialchars($s['viewers']); ?></td>
                                <td class="has-text-white"><?php echo htmlspecialchars($s['created_at']); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </section>
                </div>
              </div>

              <hr>
              <h3 class="title is-5 has-text-white">Top Raiders</h3>
              <?php if (empty($topRaiders)): ?>
                <p class="has-text-grey-light">No data yet.</p>
              <?php else: ?>
                <ul>
                  <?php foreach ($topRaiders as $t): ?>
                    <li class="mb-2"><strong><?php echo htmlspecialchars($t['raider_name']); ?></strong> — <?php echo htmlspecialchars($t['raids']); ?> raids, Avg: <?php echo htmlspecialchars($t['avg_viewers']); ?> viewers</li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <hr>
              <h4 class="subtitle is-6 has-text-white">Overall Average Viewers</h4>
              <p class="has-text-white is-size-5"><?php echo $avgViewers !== null ? htmlspecialchars($avgViewers) . ' viewers' : 'N/A'; ?></p>
            </div>
          </div>
        </div>
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

  const modalBackground = modal.querySelector('.modal-background');
  if (modalBackground) {
    modalBackground.addEventListener('click', closeModal);
  }
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>