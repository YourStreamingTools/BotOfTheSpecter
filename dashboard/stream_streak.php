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
    // Most recently updated streaks (one row per user - ordered by last milestone hit)
    $recentRes = $db->query("SELECT user_name, streak_value, GREATEST(highest_streak, streak_value) AS highest_streak, GREATEST(total_streams_watched, highest_streak, streak_value) AS total_streams_watched, updated_at FROM analytic_stream_watch_streak ORDER BY updated_at DESC LIMIT 25");
    if ($recentRes) {
        $recentStreaks = $recentRes->fetch_all(MYSQLI_ASSOC);
    }
    // Top users by highest all-time streak
    $topRes = $db->query("SELECT user_name, GREATEST(highest_streak, streak_value) AS highest_streak, GREATEST(total_streams_watched, highest_streak, streak_value) AS total_streams_watched FROM analytic_stream_watch_streak ORDER BY GREATEST(highest_streak, streak_value) DESC LIMIT 10");
    if ($topRes) {
        $topStreakers = $topRes->fetch_all(MYSQLI_ASSOC);
    }
    // Breakdown: how many viewers are at each current streak level
    $milestoneRes = $db->query("SELECT streak_value, COUNT(*) AS user_count FROM analytic_stream_watch_streak GROUP BY streak_value ORDER BY streak_value ASC");
    if ($milestoneRes) {
        $milestoneBreakdown = $milestoneRes->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    // Quietly fail and show empty state
}

ob_start();
?>
<div class="sp-alert sp-alert-info mb-4">
    <span class="icon"><i class="fas fa-info-circle"></i></span>
    <strong>Beta 5.8 Feature:</strong> Stream Watch Streak tracking is available in version 5.8 and above. Milestones are automatically recorded when viewers hit consecutive stream watch streaks (e.g. 3, 7, 10, 50 streams in a row).
</div>
<div class="sp-card mb-5">
    <header class="sp-card-header">
        <div class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-fire"></i></span>
            Stream Watch Streaks
        </div>
    </header>
    <div class="sp-card-body">
        <div class="raids-layout">
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.85rem;">Recent Milestones</h3>
                <?php if (empty($recentStreaks)): ?>
                    <div style="text-align:center;padding:3rem 0;">
                        <p class="sp-text-muted" style="font-size:1.1rem;">No stream watch streak data available yet.</p>
                    </div>
                <?php else: ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th>Viewer</th>
                                    <th>Current Streak</th>
                                    <th>Best Streak</th>
                                    <th>Total Watched</th>
                                    <th>Last Milestone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentStreaks as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['streak_value']); ?> streams</td>
                                        <td><?php echo htmlspecialchars(max((int)$row['highest_streak'], (int)$row['streak_value'])); ?> streams</td>
                                        <td><?php echo htmlspecialchars(max((int)$row['total_streams_watched'], (int)$row['streak_value'])); ?> streams</td>
                                        <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;">All-Time Highest Streaks</h3>
                <?php if (empty($topStreakers)): ?>
                    <p class="sp-text-muted">No data yet.</p>
                <?php else: ?>
                    <ul style="padding-left:1.25rem;margin:0 0 1rem;">
                        <?php foreach ($topStreakers as $t): ?>
                            <li style="margin-bottom:0.5rem;">
                                <strong><?php echo htmlspecialchars($t['user_name']); ?></strong>
                                &mdash; best: <?php echo htmlspecialchars($t['highest_streak']); ?> streams
                                &bull; total: <?php echo htmlspecialchars($t['total_streams_watched']); ?> streams
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;">Milestone Breakdown</h3>
                <?php if (empty($milestoneBreakdown)): ?>
                    <p class="sp-text-muted">No data yet.</p>
                <?php else: ?>
                    <ul style="padding-left:1.25rem;margin:0;">
                        <?php foreach ($milestoneBreakdown as $m): ?>
                            <li style="margin-bottom:0.4rem;">
                                <strong><?php echo htmlspecialchars($m['streak_value']); ?> streams</strong>
                                - <?php echo htmlspecialchars($m['user_count']); ?> viewer<?php echo $m['user_count'] != 1 ? 's' : ''; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include 'layout.php';
?>