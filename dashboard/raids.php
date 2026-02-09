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
$recentRaids = [];
$topRaiders = [];
$avgViewers = null;
try {
    $recentRes = $db->query("SELECT id, raider_name, viewers, created_at FROM analytic_raids ORDER BY created_at DESC LIMIT 25");
    if ($recentRes) {
        $recentRaids = $recentRes->fetch_all(MYSQLI_ASSOC);
    }
    $topRes = $db->query("SELECT raider_name, COUNT(*) AS raids, ROUND(AVG(viewers),1) AS avg_viewers, MAX(viewers) AS max_viewers FROM analytic_raids GROUP BY raider_name ORDER BY raids DESC LIMIT 10");
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
              <h3 class="title is-5 has-text-white">Recent Raids</h3>
              <?php if (empty($recentRaids)): ?>
                <div class="has-text-centered py-6">
                  <p class="has-text-grey-light is-size-5">No raid data available yet.</p>
                </div>
              <?php else: ?>
                <table class="table is-fullwidth is-striped is-hoverable has-text-white">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Raider</th>
                      <th>Viewers</th>
                      <th>Date / Time</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentRaids as $r): ?>
                      <tr>
                        <td><?php echo htmlspecialchars($r['id']); ?></td>
                        <td><?php echo htmlspecialchars($r['raider_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['viewers']); ?></td>
                        <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
            <div class="column">
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
// Placeholder for future client-side interactivity
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>