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
$pageTitle = 'Stream Watch Streaks';

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
$recentStreaks = [];
$topStreakers = [];
$milestoneBreakdown = [];

try {
    // Most recently updated streaks (one row per user — ordered by last milestone hit)
    $recentRes = $db->query("SELECT user_name, streak_value, updated_at FROM analytic_stream_watch_streak ORDER BY updated_at DESC LIMIT 25");
    if ($recentRes) {
        $recentStreaks = $recentRes->fetch_all(MYSQLI_ASSOC);
    }
    // Top users by highest current streak
    $topRes = $db->query("SELECT user_name, streak_value AS highest_streak FROM analytic_stream_watch_streak ORDER BY streak_value DESC LIMIT 10");
    if ($topRes) {
        $topStreakers = $topRes->fetch_all(MYSQLI_ASSOC);
    }
    // Breakdown: how many viewers are at each streak level
    $milestoneRes = $db->query("SELECT streak_value, COUNT(*) AS user_count FROM analytic_stream_watch_streak GROUP BY streak_value ORDER BY streak_value ASC");
    if ($milestoneRes) {
        $milestoneBreakdown = $milestoneRes->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    // Quietly fail and show empty state
}

ob_start();
?>
<div class="notification is-info is-light mb-4">
    <span class="icon">
        <i class="fas fa-info-circle"></i>
    </span>
    <strong>Beta 5.8 Feature:</strong> Stream Watch Streak tracking is available in version 5.8 and above. Milestones are automatically recorded when viewers hit consecutive stream watch streaks (e.g. 3, 7, 10, 50 streams in a row).
</div>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header is-flex is-align-items-center is-justify-content-space-between" style="border-bottom: 1px solid #23272f; padding: 1rem 1.5rem;">
                <div class="card-header-title is-size-4 has-text-white" style="font-weight:700; padding: 0;">
                    <span class="icon mr-2"><i class="fas fa-fire"></i></span>
                    Stream Watch Streaks
                </div>
            </header>
            <div class="card-content">
                <div class="content">
                    <div class="columns">
                        <div class="column is-two-thirds">
                            <h3 class="title is-5 has-text-white">Recent Milestones</h3>
                            <?php if (empty($recentStreaks)): ?>
                                <div class="has-text-centered py-6">
                                    <p class="has-text-grey-light is-size-5">No stream watch streak data available yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table is-fullwidth has-background-dark has-text-white">
                                        <thead class="has-background-grey-darker">
                                            <tr>
                                                <th class="has-text-white">Viewer</th>
                                                <th class="has-text-white">Streak</th>
                                                <th class="has-text-white">Last Milestone</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentStreaks as $row): ?>
                                                <tr>
                                                    <td class="has-text-white"><?php echo htmlspecialchars($row['user_name']); ?></td>
                                                    <td class="has-text-white"><?php echo htmlspecialchars($row['streak_value']); ?> streams</td>
                                                    <td class="has-text-white"><?php echo htmlspecialchars($row['updated_at']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="column">
                            <h3 class="title is-5 has-text-white">Highest Current Streaks</h3>
                            <?php if (empty($topStreakers)): ?>
                                <p class="has-text-grey-light">No data yet.</p>
                            <?php else: ?>
                                <ul>
                                    <?php foreach ($topStreakers as $t): ?>
                                        <li class="mb-2">
                                            <strong><?php echo htmlspecialchars($t['user_name']); ?></strong>
                                            — <?php echo htmlspecialchars($t['highest_streak']); ?> streams
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <hr>
                            <h3 class="title is-5 has-text-white">Milestone Breakdown</h3>
                            <?php if (empty($milestoneBreakdown)): ?>
                                <p class="has-text-grey-light">No data yet.</p>
                            <?php else: ?>
                                <ul>
                                    <?php foreach ($milestoneBreakdown as $m): ?>
                                        <li class="mb-1">
                                            <strong><?php echo htmlspecialchars($m['streak_value']); ?> streams</strong>
                                            — <?php echo htmlspecialchars($m['user_count']); ?> viewer<?php echo $m['user_count'] != 1 ? 's' : ''; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
