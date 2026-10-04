<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('birthdays_page_title');

// Includes
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php';
session_write_close();

// Days from $today until the next occurrence of day/month (0 = today).
// 29 Feb birthdays fall on 28 Feb in non-leap years, matching the bot.
function birthdays_days_until($day, $month, DateTimeImmutable $today)
{
    $year = (int) $today->format('Y');
    for ($offset = 0; $offset < 2; $offset++) {
        $y = $year + $offset;
        $d = ($month === 2 && $day === 29 && !checkdate(2, 29, $y)) ? 28 : $day;
        $next = $today->setDate($y, $month, $d);
        if ($next >= $today) {
            return (int) $today->diff($next)->days;
        }
    }
    return 365;
}

function birthdays_format_date($day, $month, $language)
{
    $date = new DateTimeImmutable(sprintf('2000-%02d-%02d', $month, $day));
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter(strtolower((string) $language), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'd MMMM');
        $formatted = $formatter->format($date);
        if ($formatted !== false) {
            return $formatted;
        }
    }
    return $date->format('j F');
}

$statusMessage = null;
$statusType = 'info';

// Remove a saved birthday
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_birthday') {
    $birthdayId = isset($_POST['birthday_id']) ? (int) $_POST['birthday_id'] : 0;
    if ($birthdayId > 0) {
        try {
            $del = $db->prepare("DELETE FROM birthdays WHERE id = ?");
            $del->bind_param('i', $birthdayId);
            $del->execute();
            if ($del->affected_rows > 0) {
                $statusMessage = t('birthdays_msg_deleted');
                $statusType = 'success';
            } else {
                $statusMessage = t('birthdays_msg_not_found');
                $statusType = 'warning';
            }
            $del->close();
        } catch (Exception $e) {
            error_log('birthdays.php delete failed: ' . $e->getMessage());
            $statusMessage = t('birthdays_msg_delete_failed');
            $statusType = 'danger';
        }
    }
}

// Channel timezone decides what "today" is (same as the bot)
$channelTimezone = 'UTC';
try {
    $tzRes = $db->query("SELECT timezone FROM profile LIMIT 1");
    $tzRow = $tzRes ? $tzRes->fetch_assoc() : null;
    if (!empty($tzRow['timezone']) && in_array($tzRow['timezone'], timezone_identifiers_list(), true)) {
        $channelTimezone = $tzRow['timezone'];
    }
} catch (Exception $e) {
    $channelTimezone = 'UTC';
}
$today = new DateTimeImmutable('today', new DateTimeZone($channelTimezone));

$birthdays = [];
$tableAvailable = true;
try {
    $listRes = $db->query("SELECT id, user_id, user_name, birth_day, birth_month, added_by, updated_at FROM birthdays");
    if ($listRes === false) {
        $tableAvailable = false;
    } else {
        $birthdays = $listRes->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log('birthdays.php list failed: ' . $e->getMessage());
    $tableAvailable = false;
}

$countToday = 0;
$countUpcoming = 0;
foreach ($birthdays as &$b) {
    $b['days_until'] = birthdays_days_until((int) $b['birth_day'], (int) $b['birth_month'], $today);
    $b['date_display'] = birthdays_format_date((int) $b['birth_day'], (int) $b['birth_month'], $userLanguage);
    if ($b['days_until'] === 0) {
        $countToday++;
    }
    if ($b['days_until'] <= 30) {
        $countUpcoming++;
    }
}
unset($b);
usort($birthdays, function ($a, $b) {
    return [$a['days_until'], $a['user_name']] <=> [$b['days_until'], $b['user_name']];
});

ob_start();
?>
<?php if ($statusMessage): ?>
<div class="sp-alert sp-alert-<?= $statusType ?> mb-4">
    <?= htmlspecialchars($statusMessage) ?>
</div>
<?php endif; ?>

<div class="sp-alert sp-alert-info mb-4">
    <span class="icon"><i class="fas fa-info-circle"></i></span>
    <?= t('birthdays_beta_notice') ?>
</div>

<div class="sp-page-header mb-4">
    <h1><?= t('birthdays_page_title') ?></h1>
    <p><?= t('birthdays_page_subtitle') ?></p>
</div>

<div class="sp-stat-row mb-4">
    <div class="sp-stat"><div class="sp-stat-label"><?= t('birthdays_stat_total') ?></div><div class="sp-stat-value"><?= $tableAvailable ? count($birthdays) : '—' ?></div></div>
    <div class="sp-stat"><div class="sp-stat-label"><?= t('birthdays_stat_today') ?></div><div class="sp-stat-value"><?= $tableAvailable ? $countToday : '—' ?></div></div>
    <div class="sp-stat"><div class="sp-stat-label"><?= t('birthdays_stat_upcoming') ?></div><div class="sp-stat-value"><?= $tableAvailable ? $countUpcoming : '—' ?></div></div>
</div>

<div class="sp-card mb-5">
    <header class="sp-card-header">
        <div class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-terminal"></i></span>
            <?= t('birthdays_commands_title') ?>
        </div>
    </header>
    <div class="sp-card-body">
        <ul class="bd-command-list">
            <li><code>!addbirthday @username 23/8</code> <span class="sp-text-muted"><?= t('birthdays_cmd_add') ?></span></li>
            <li><code>!mybirthday</code> / <code>!mybirthday 23/8</code> <span class="sp-text-muted"><?= t('birthdays_cmd_my') ?></span></li>
            <li><code>!updatebirthday 23/8</code> <span class="sp-text-muted"><?= t('birthdays_cmd_update') ?></span></li>
        </ul>
        <p class="sp-text-muted mb-0"><?= t('birthdays_commands_note') ?></p>
    </div>
</div>

<div class="sp-card mb-5">
    <header class="sp-card-header">
        <div class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-cake-candles"></i></span>
            <?= t('birthdays_list_title') ?>
        </div>
    </header>
    <div class="sp-card-body">
        <?php if (!$tableAvailable): ?>
        <p class="sp-text-muted"><?= t('birthdays_table_unavailable') ?></p>
        <?php elseif (!$birthdays): ?>
        <p class="sp-text-muted bd-empty"><?= t('birthdays_empty') ?></p>
        <?php else: ?>
        <div class="sp-form-group bd-filter">
            <input type="search" class="sp-input" id="birthdayFilter" placeholder="<?= htmlspecialchars(t('birthdays_search_placeholder')) ?>" aria-label="<?= htmlspecialchars(t('birthdays_search_placeholder')) ?>">
        </div>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?= t('birthdays_col_user') ?></th>
                        <th><?= t('birthdays_col_birthday') ?></th>
                        <th><?= t('birthdays_col_next') ?></th>
                        <th><?= t('birthdays_col_added_by') ?></th>
                        <th class="bd-col-actions"><?= t('birthdays_col_actions') ?></th>
                    </tr>
                </thead>
                <tbody id="birthdayRows">
                    <?php foreach ($birthdays as $b): ?>
                    <tr data-user="<?= htmlspecialchars(strtolower($b['user_name'])) ?>">
                        <td><strong><?= htmlspecialchars($b['user_name']) ?></strong></td>
                        <td><?= htmlspecialchars($b['date_display']) ?></td>
                        <td>
                            <?php if ($b['days_until'] === 0): ?>
                            <span class="sp-badge sp-badge-green"><i class="fas fa-cake-candles"></i> <?= t('birthdays_today') ?></span>
                            <?php elseif ($b['days_until'] === 1): ?>
                            <span class="sp-badge sp-badge-amber"><?= t('birthdays_tomorrow') ?></span>
                            <?php elseif ($b['days_until'] <= 30): ?>
                            <span class="sp-badge sp-badge-blue"><?= htmlspecialchars(t('birthdays_in_days', ['days' => $b['days_until']])) ?></span>
                            <?php else: ?>
                            <span class="sp-text-muted"><?= htmlspecialchars(t('birthdays_in_days', ['days' => $b['days_until']])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($b['added_by'] ?? '')) ?></td>
                        <td class="bd-col-actions">
                            <form method="post" action="birthdays.php" class="bd-delete-form" data-confirm="<?= htmlspecialchars(t('birthdays_confirm_delete')) ?>">
                                <input type="hidden" name="action" value="delete_birthday">
                                <input type="hidden" name="birthday_id" value="<?= (int) $b['id'] ?>">
                                <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="<?= htmlspecialchars(t('birthdays_delete')) ?>" aria-label="<?= htmlspecialchars(t('birthdays_delete')) ?>"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p id="birthdayNoMatch" class="sp-text-muted bd-empty" hidden><?= t('birthdays_no_match') ?></p>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var filter = document.getElementById('birthdayFilter');
    var rows = document.querySelectorAll('#birthdayRows tr');
    var noMatch = document.getElementById('birthdayNoMatch');
    if (filter) {
        filter.addEventListener('input', function () {
            var term = filter.value.trim().toLowerCase().replace(/^@/, '');
            var shown = 0;
            rows.forEach(function (row) {
                var match = term === '' || (row.getAttribute('data-user') || '').indexOf(term) !== -1;
                row.hidden = !match;
                if (match) shown++;
            });
            if (noMatch) noMatch.hidden = shown !== 0;
        });
    }
    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('.bd-delete-form') : null;
        if (form && !window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
            e.preventDefault();
        }
    });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
