<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('stream_streak_page_title');

// Includes
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
session_write_close();
include "includes/mod_access.php";
include 'includes/user_db.php';

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
    <?= t('stream_streak_beta_notice') ?>
</div>
<div class="sp-card mb-5">
    <header class="sp-card-header">
        <div class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-fire"></i></span>
            <?= t('stream_streak_card_title') ?>
        </div>
    </header>
    <div class="sp-card-body">
        <div class="raids-layout">
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.85rem;"><?= t('stream_streak_recent_milestones') ?></h3>
                <?php if (empty($recentStreaks)): ?>
                    <div style="text-align:center;padding:3rem 0;">
                        <p class="sp-text-muted" style="font-size:1.1rem;"><?= t('stream_streak_no_data') ?></p>
                    </div>
                <?php else: ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th><?= t('stream_streak_th_viewer') ?></th>
                                    <th><?= t('stream_streak_th_current') ?></th>
                                    <th><?= t('stream_streak_th_best') ?></th>
                                    <th><?= t('stream_streak_th_total') ?></th>
                                    <th><?= t('stream_streak_th_last_milestone') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentStreaks as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['streak_value']); ?> <?= t('stream_streak_unit_streams') ?></td>
                                        <td><?php echo htmlspecialchars(max((int)$row['highest_streak'], (int)$row['streak_value'])); ?> <?= t('stream_streak_unit_streams') ?></td>
                                        <td><?php echo htmlspecialchars(max((int)$row['total_streams_watched'], (int)$row['streak_value'])); ?> <?= t('stream_streak_unit_streams') ?></td>
                                        <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;"><?= t('stream_streak_all_time_title') ?></h3>
                <?php if (empty($topStreakers)): ?>
                    <p class="sp-text-muted"><?= t('stream_streak_no_data_yet') ?></p>
                <?php else: ?>
                    <ul style="padding-left:1.25rem;margin:0 0 1rem;">
                        <?php foreach ($topStreakers as $t): ?>
                            <li style="margin-bottom:0.5rem;">
                                <strong><?php echo htmlspecialchars($t['user_name']); ?></strong>
                                <?= t('stream_streak_best_label') ?> <?php echo htmlspecialchars($t['highest_streak']); ?> <?= t('stream_streak_unit_streams') ?>
                                <?= t('stream_streak_total_label') ?> <?php echo htmlspecialchars($t['total_streams_watched']); ?> <?= t('stream_streak_unit_streams') ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;"><?= t('stream_streak_breakdown_title') ?></h3>
                <?php if (empty($milestoneBreakdown)): ?>
                    <p class="sp-text-muted"><?= t('stream_streak_no_data_yet') ?></p>
                <?php else: ?>
                    <ul style="padding-left:1.25rem;margin:0;">
                        <?php foreach ($milestoneBreakdown as $m): ?>
                            <li style="margin-bottom:0.4rem;">
                                <strong><?php echo htmlspecialchars($m['streak_value']); ?> <?= t('stream_streak_unit_streams') ?></strong>
                                - <?php echo htmlspecialchars($m['user_count']); ?> <?php echo $m['user_count'] != 1 ? t('stream_streak_unit_viewers') : t('stream_streak_unit_viewer'); ?>
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