<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('moderation_page_title');

// Includes
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
session_write_close();
include "includes/mod_access.php";
include 'includes/user_db.php';
// Ensure per-user schema (including warnings) exists before we query it
include_once 'includes/usr_database.php';

// Set timezone from profile
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

$statusMessage = null;
$statusType = 'is-info';

// Handle moderation actions (delete single warning / clear user warnings)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_warning') {
        $warningId = isset($_POST['warning_id']) ? (int) $_POST['warning_id'] : 0;
        if ($warningId > 0) {
            $del = $db->prepare("DELETE FROM warnings WHERE id = ?");
            if ($del) {
                $del->bind_param('i', $warningId);
                if ($del->execute() && $del->affected_rows > 0) {
                    $statusMessage = t('moderation_msg_warning_deleted');
                    $statusType = 'is-success';
                } else {
                    $statusMessage = t('moderation_msg_warning_not_found');
                    $statusType = 'is-warning';
                }
                $del->close();
            } else {
                $statusMessage = t('moderation_msg_delete_failed');
                $statusType = 'is-danger';
            }
        }
    } elseif ($action === 'clear_user_warnings') {
        $clearUserId = trim((string) ($_POST['user_id'] ?? ''));
        $clearUserName = strtolower(trim((string) ($_POST['user_name'] ?? '')));
        if ($clearUserId !== '' || $clearUserName !== '') {
            if ($clearUserId !== '') {
                $del = $db->prepare("DELETE FROM warnings WHERE user_id = ? OR user_name = ?");
                if ($del) {
                    $del->bind_param('ss', $clearUserId, $clearUserName);
                }
            } else {
                $del = $db->prepare("DELETE FROM warnings WHERE user_name = ?");
                if ($del) {
                    $del->bind_param('s', $clearUserName);
                }
            }
            if (!empty($del)) {
                if ($del->execute()) {
                    $removed = (int) $del->affected_rows;
                    $statusMessage = t('moderation_msg_user_warnings_cleared', ['count' => $removed, 'user' => $clearUserName !== '' ? $clearUserName : $clearUserId]);
                    $statusType = 'is-success';
                } else {
                    $statusMessage = t('moderation_msg_delete_failed');
                    $statusType = 'is-danger';
                }
                $del->close();
            } else {
                $statusMessage = t('moderation_msg_delete_failed');
                $statusType = 'is-danger';
            }
        }
    }
}

// Filters
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$filterUser = trim((string) ($_GET['user'] ?? ''));

$warnings = [];
$topWarned = [];
$totalWarnings = 0;
$uniqueWarned = 0;
$warningsThisWeek = 0;
$tableReady = true;

try {
    // Stats
    $statsRes = $db->query("SELECT COUNT(*) AS total, COUNT(DISTINCT COALESCE(NULLIF(user_id, ''), user_name)) AS unique_users FROM warnings");
    if ($statsRes) {
        $statsRow = $statsRes->fetch_assoc();
        $totalWarnings = (int) ($statsRow['total'] ?? 0);
        $uniqueWarned = (int) ($statsRow['unique_users'] ?? 0);
    }
    $weekRes = $db->query("SELECT COUNT(*) AS week_count FROM warnings WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
    if ($weekRes) {
        $weekRow = $weekRes->fetch_assoc();
        $warningsThisWeek = (int) ($weekRow['week_count'] ?? 0);
    }

    // Top warned users (by count)
    $topRes = $db->query(
        "SELECT user_name, user_id, COUNT(*) AS warning_count, MAX(created_at) AS last_warned_at
         FROM warnings
         GROUP BY user_name, user_id
         ORDER BY warning_count DESC, last_warned_at DESC
         LIMIT 10"
    );
    if ($topRes) {
        $topWarned = $topRes->fetch_all(MYSQLI_ASSOC);
    }

    // Warning log (optionally filtered)
    if ($searchQuery !== '' || $filterUser !== '') {
        $like = '%' . ($searchQuery !== '' ? $searchQuery : $filterUser) . '%';
        $filterExact = $filterUser !== '' ? strtolower($filterUser) : null;
        if ($filterExact !== null && $searchQuery === '') {
            $stmtList = $db->prepare(
                "SELECT id, user_id, user_name, warned_by_id, warned_by_name, reason, created_at
                 FROM warnings
                 WHERE LOWER(user_name) = ? OR user_id = ?
                 ORDER BY created_at DESC
                 LIMIT 500"
            );
            if ($stmtList) {
                $stmtList->bind_param('ss', $filterExact, $filterExact);
                $stmtList->execute();
                $listRes = $stmtList->get_result();
                $warnings = $listRes ? $listRes->fetch_all(MYSQLI_ASSOC) : [];
                $stmtList->close();
            }
        } else {
            $stmtList = $db->prepare(
                "SELECT id, user_id, user_name, warned_by_id, warned_by_name, reason, created_at
                 FROM warnings
                 WHERE user_name LIKE ? OR warned_by_name LIKE ? OR reason LIKE ? OR user_id LIKE ?
                 ORDER BY created_at DESC
                 LIMIT 500"
            );
            if ($stmtList) {
                $stmtList->bind_param('ssss', $like, $like, $like, $like);
                $stmtList->execute();
                $listRes = $stmtList->get_result();
                $warnings = $listRes ? $listRes->fetch_all(MYSQLI_ASSOC) : [];
                $stmtList->close();
            }
        }
    } else {
        $listRes = $db->query(
            "SELECT id, user_id, user_name, warned_by_id, warned_by_name, reason, created_at
             FROM warnings
             ORDER BY created_at DESC
             LIMIT 500"
        );
        if ($listRes) {
            $warnings = $listRes->fetch_all(MYSQLI_ASSOC);
        }
    }
} catch (Exception $e) {
    $tableReady = false;
    error_log('moderation.php warnings query failed: ' . $e->getMessage());
}

ob_start();
?>
<?php if ($statusMessage): ?>
<div class="sp-alert sp-alert-<?= $statusType === 'is-success' ? 'success' : ($statusType === 'is-danger' ? 'danger' : ($statusType === 'is-warning' ? 'warning' : 'info')) ?> mb-4">
    <?= htmlspecialchars($statusMessage) ?>
</div>
<?php endif; ?>

<div class="sp-alert sp-alert-info mb-4">
    <span class="icon"><i class="fas fa-info-circle"></i></span>
    <?= t('moderation_beta_notice') ?>
</div>

<div class="sp-page-header mb-4">
    <h1><?= t('moderation_page_title') ?></h1>
    <p><?= t('moderation_page_subtitle') ?></p>
</div>

<div class="sp-stat-row mb-4">
    <div class="sp-stat">
        <div class="sp-stat-label"><?= t('moderation_stat_total') ?></div>
        <div class="sp-stat-value"><?= (int) $totalWarnings ?></div>
    </div>
    <div class="sp-stat">
        <div class="sp-stat-label"><?= t('moderation_stat_unique') ?></div>
        <div class="sp-stat-value"><?= (int) $uniqueWarned ?></div>
    </div>
    <div class="sp-stat">
        <div class="sp-stat-label"><?= t('moderation_stat_week') ?></div>
        <div class="sp-stat-value"><?= (int) $warningsThisWeek ?></div>
    </div>
</div>

<div class="sp-card mb-5">
    <header class="sp-card-header">
        <div class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-terminal"></i></span>
            <?= t('moderation_how_title') ?>
        </div>
    </header>
    <div class="sp-card-body">
        <p class="mb-2"><?= t('moderation_how_intro') ?></p>
        <ul style="margin:0 0 0.75rem 1.25rem;">
            <li><code>!warn @username <?= t('moderation_how_reason_placeholder') ?></code></li>
            <li><code>!warning @username <?= t('moderation_how_reason_placeholder') ?></code> <span class="sp-text-muted">(<?= t('moderation_how_alias') ?>)</span></li>
        </ul>
        <p class="sp-text-muted mb-0"><?= t('moderation_how_permissions') ?></p>
    </div>
</div>

<div class="raids-layout mb-5">
    <div class="sp-card">
        <header class="sp-card-header">
            <div class="sp-card-title">
                <span class="icon mr-2"><i class="fas fa-triangle-exclamation"></i></span>
                <?= t('moderation_log_title') ?>
            </div>
        </header>
        <div class="sp-card-body">
            <form method="get" action="moderation.php" class="mb-4" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
                <div class="sp-form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                    <label class="sp-label" for="modSearch"><?= t('moderation_search_label') ?></label>
                    <input type="text" class="sp-input" id="modSearch" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="<?= htmlspecialchars(t('moderation_search_placeholder')) ?>">
                </div>
                <div class="sp-btn-group">
                    <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm">
                        <i class="fas fa-search"></i> <?= t('moderation_search_btn') ?>
                    </button>
                    <?php if ($searchQuery !== '' || $filterUser !== ''): ?>
                    <a href="moderation.php" class="sp-btn sp-btn-secondary sp-btn-sm"><?= t('moderation_clear_filter') ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!$tableReady): ?>
                <p class="sp-text-muted"><?= t('moderation_table_unavailable') ?></p>
            <?php elseif (empty($warnings)): ?>
                <div style="text-align:center;padding:2.5rem 0;">
                    <p class="sp-text-muted" style="font-size:1.05rem;"><?= t('moderation_no_warnings') ?></p>
                </div>
            <?php else: ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th><?= t('moderation_col_user') ?></th>
                                <th><?= t('moderation_col_reason') ?></th>
                                <th><?= t('moderation_col_by') ?></th>
                                <th><?= t('moderation_col_when') ?></th>
                                <th style="text-align:center;"><?= t('moderation_col_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($warnings as $w): ?>
                                <?php
                                    $uid = (string) ($w['user_id'] ?? '');
                                    $uname = (string) ($w['user_name'] ?? '');
                                    $when = $w['created_at'] ?? '';
                                    if ($when !== '' && $when !== '0000-00-00 00:00:00') {
                                        $whenDisplay = date('Y-m-d H:i:s', strtotime($when));
                                    } else {
                                        $whenDisplay = t('moderation_unknown_date');
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <a href="moderation.php?user=<?= urlencode($uname) ?>" title="<?= htmlspecialchars(t('moderation_filter_user_title')) ?>">
                                            <strong><?= htmlspecialchars($uname) ?></strong>
                                        </a>
                                        <?php if ($uid !== ''): ?>
                                            <div class="sp-text-muted" style="font-size:0.8rem;">ID: <?= htmlspecialchars($uid) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:28rem; word-break:break-word;"><?= htmlspecialchars($w['reason'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($w['warned_by_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($whenDisplay) ?></td>
                                    <td style="text-align:center; white-space:nowrap;">
                                        <form method="post" action="moderation.php<?= $searchQuery !== '' ? '?q=' . urlencode($searchQuery) : ($filterUser !== '' ? '?user=' . urlencode($filterUser) : '') ?>" style="display:inline;" class="mod-delete-form" data-confirm="<?= htmlspecialchars(t('moderation_confirm_delete_one')) ?>">
                                            <input type="hidden" name="action" value="delete_warning">
                                            <input type="hidden" name="warning_id" value="<?= (int) $w['id'] ?>">
                                            <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="<?= htmlspecialchars(t('moderation_delete_one')) ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($warnings) >= 500): ?>
                    <p class="sp-help mt-3"><?= t('moderation_limit_note') ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="sp-card">
        <header class="sp-card-header">
            <div class="sp-card-title">
                <span class="icon mr-2"><i class="fas fa-ranking-star"></i></span>
                <?= t('moderation_top_title') ?>
            </div>
        </header>
        <div class="sp-card-body">
            <?php if (empty($topWarned)): ?>
                <p class="sp-text-muted"><?= t('moderation_no_warnings') ?></p>
            <?php else: ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th><?= t('moderation_col_user') ?></th>
                                <th><?= t('moderation_col_count') ?></th>
                                <th><?= t('moderation_col_last') ?></th>
                                <th style="text-align:center;"><?= t('moderation_col_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topWarned as $row): ?>
                                <?php
                                    $tu = (string) ($row['user_name'] ?? '');
                                    $tid = (string) ($row['user_id'] ?? '');
                                    $last = $row['last_warned_at'] ?? '';
                                    $lastDisplay = ($last !== '' && $last !== '0000-00-00 00:00:00')
                                        ? date('Y-m-d H:i:s', strtotime($last))
                                        : t('moderation_unknown_date');
                                ?>
                                <tr>
                                    <td>
                                        <a href="moderation.php?user=<?= urlencode($tu) ?>">
                                            <strong><?= htmlspecialchars($tu) ?></strong>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="sp-badge sp-badge-amber"><?= (int) $row['warning_count'] ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($lastDisplay) ?></td>
                                    <td style="text-align:center;">
                                        <form method="post" action="moderation.php" style="display:inline;" class="mod-delete-form" data-confirm="<?= htmlspecialchars(t('moderation_confirm_clear_user', ['user' => $tu])) ?>">
                                            <input type="hidden" name="action" value="clear_user_warnings">
                                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($tid) ?>">
                                            <input type="hidden" name="user_name" value="<?= htmlspecialchars($tu) ?>">
                                            <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="<?= htmlspecialchars(t('moderation_clear_user')) ?>">
                                                <i class="fas fa-broom"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">
            <p class="sp-text-muted" style="font-size:0.9rem;margin:0;">
                <?= t('moderation_related_links') ?>
                <a href="mods.php"><?= t('navbar_moderators') ?></a>
                ·
                <a href="builtin.php"><?= t('navbar_view_builtin_commands') ?></a>
            </p>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mod-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
