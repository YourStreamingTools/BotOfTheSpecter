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
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    $recentStreaks = [];
    $topStreakers = [];
    $milestoneBreakdown = [];
    try {
        $recentRes = $db->query("SELECT user_name, streak_value, GREATEST(highest_streak, streak_value) AS highest_streak, GREATEST(total_streams_watched, highest_streak, streak_value) AS total_streams_watched, updated_at FROM analytic_stream_watch_streak ORDER BY updated_at DESC LIMIT 25");
        if ($recentRes) {
            $recentStreaks = $recentRes->fetch_all(MYSQLI_ASSOC);
        }
        $topRes = $db->query("SELECT user_name, GREATEST(highest_streak, streak_value) AS highest_streak, GREATEST(total_streams_watched, highest_streak, streak_value) AS total_streams_watched FROM analytic_stream_watch_streak ORDER BY GREATEST(highest_streak, streak_value) DESC LIMIT 10");
        if ($topRes) {
            $topStreakers = $topRes->fetch_all(MYSQLI_ASSOC);
        }
        $milestoneRes = $db->query("SELECT streak_value, COUNT(*) AS user_count FROM analytic_stream_watch_streak GROUP BY streak_value ORDER BY streak_value ASC");
        if ($milestoneRes) {
            $milestoneBreakdown = $milestoneRes->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode([
            'success' => true,
            'recent_streaks' => $recentStreaks,
            'top_streakers' => $topStreakers,
            'milestone_breakdown' => $milestoneBreakdown,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Set timezone from profile
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

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
                <div id="recentStreaksHost" aria-busy="true">
                    <div class="sp-table-wrap" id="recentStreaksTableWrap">
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
                            <tbody id="recentStreaksBody">
                                <?php for ($sk = 0; $sk < 5; $sk++): ?>
                                <tr aria-hidden="true">
                                    <td><span class="sp-skeleton-line w-60"></span></td>
                                    <td><span class="sp-skeleton-line w-40"></span></td>
                                    <td><span class="sp-skeleton-line w-40"></span></td>
                                    <td><span class="sp-skeleton-line w-40"></span></td>
                                    <td><span class="sp-skeleton-line w-70"></span></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="recentStreaksEmpty" style="text-align:center;padding:3rem 0;display:none;">
                        <p class="sp-text-muted" style="font-size:1.1rem;"><?= t('stream_streak_no_data') ?></p>
                    </div>
                    <p id="recentStreaksError" class="sp-text-muted" style="text-align:center;padding:3rem 0;display:none;"><?= t('dashboard_js_load_error') ?></p>
                </div>
            </div>
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;"><?= t('stream_streak_all_time_title') ?></h3>
                <div id="topStreakersHost" aria-busy="true">
                    <div id="topStreakersSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-80"></span>
                        <span class="sp-skeleton-line w-70"></span>
                        <span class="sp-skeleton-line w-90"></span>
                        <span class="sp-skeleton-line w-60"></span>
                    </div>
                    <ul id="topStreakersList" style="padding-left:1.25rem;margin:0 0 1rem;display:none;"></ul>
                    <p id="topStreakersEmpty" class="sp-text-muted" style="display:none;"><?= t('stream_streak_no_data_yet') ?></p>
                    <p id="topStreakersError" class="sp-text-muted" style="display:none;"><?= t('dashboard_js_load_error') ?></p>
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;"><?= t('stream_streak_breakdown_title') ?></h3>
                <div id="milestoneBreakdownHost" aria-busy="true">
                    <div id="milestoneBreakdownSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-70"></span>
                        <span class="sp-skeleton-line w-60"></span>
                        <span class="sp-skeleton-line w-80"></span>
                        <span class="sp-skeleton-line w-50"></span>
                    </div>
                    <ul id="milestoneBreakdownList" style="padding-left:1.25rem;margin:0;display:none;"></ul>
                    <p id="milestoneBreakdownEmpty" class="sp-text-muted" style="display:none;"><?= t('stream_streak_no_data_yet') ?></p>
                    <p id="milestoneBreakdownError" class="sp-text-muted" style="display:none;"><?= t('dashboard_js_load_error') ?></p>
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
const SS_I18N = {
    unitStreams: <?php echo json_encode(t('stream_streak_unit_streams')); ?>,
    bestLabel: <?php echo json_encode(t('stream_streak_best_label')); ?>,
    totalLabel: <?php echo json_encode(t('stream_streak_total_label')); ?>,
    unitViewer: <?php echo json_encode(t('stream_streak_unit_viewer')); ?>,
    unitViewers: <?php echo json_encode(t('stream_streak_unit_viewers')); ?>,
    noData: <?php echo json_encode(t('stream_streak_no_data')); ?>,
    noDataYet: <?php echo json_encode(t('stream_streak_no_data_yet')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function asInt(value) {
    var n = parseInt(value, 10);
    return isNaN(n) ? 0 : n;
}

function hideEl(el) {
    if (el) el.style.display = 'none';
}

function showEl(el, display) {
    if (el) el.style.display = display || '';
}

function renderRecentStreaks(rows) {
    var host = document.getElementById('recentStreaksHost');
    var wrap = document.getElementById('recentStreaksTableWrap');
    var tbody = document.getElementById('recentStreaksBody');
    var empty = document.getElementById('recentStreaksEmpty');
    var error = document.getElementById('recentStreaksError');
    if (host) host.setAttribute('aria-busy', 'false');
    hideEl(error);
    if (!rows.length) {
        hideEl(wrap);
        showEl(empty);
        return;
    }
    hideEl(empty);
    showEl(wrap);
    if (!tbody) return;
    tbody.innerHTML = rows.map(function(row) {
        var current = asInt(row.streak_value);
        var highest = Math.max(asInt(row.highest_streak), current);
        var total = Math.max(asInt(row.total_streams_watched), current);
        return '<tr>' +
            '<td>' + escapeHtml(row.user_name) + '</td>' +
            '<td>' + escapeHtml(current) + ' ' + escapeHtml(SS_I18N.unitStreams) + '</td>' +
            '<td>' + escapeHtml(highest) + ' ' + escapeHtml(SS_I18N.unitStreams) + '</td>' +
            '<td>' + escapeHtml(total) + ' ' + escapeHtml(SS_I18N.unitStreams) + '</td>' +
            '<td>' + escapeHtml(row.updated_at) + '</td>' +
            '</tr>';
    }).join('');
}

function renderNamedList(hostId, skeletonId, listId, emptyId, errorId, rows, itemHtml) {
    var host = document.getElementById(hostId);
    var skeleton = document.getElementById(skeletonId);
    var list = document.getElementById(listId);
    var empty = document.getElementById(emptyId);
    var error = document.getElementById(errorId);
    if (host) host.setAttribute('aria-busy', 'false');
    hideEl(skeleton);
    hideEl(error);
    if (!rows.length) {
        hideEl(list);
        showEl(empty);
        return;
    }
    hideEl(empty);
    if (list) {
        list.innerHTML = rows.map(itemHtml).join('');
        showEl(list);
    }
}

function renderStreakLists(topStreakers, breakdown) {
    renderNamedList('topStreakersHost', 'topStreakersSkeleton', 'topStreakersList', 'topStreakersEmpty', 'topStreakersError', topStreakers, function(row) {
        return '<li style="margin-bottom:0.5rem;">' +
            '<strong>' + escapeHtml(row.user_name) + '</strong> ' +
            SS_I18N.bestLabel + ' ' + escapeHtml(row.highest_streak) + ' ' + escapeHtml(SS_I18N.unitStreams) + ' ' +
            SS_I18N.totalLabel + ' ' + escapeHtml(row.total_streams_watched) + ' ' + escapeHtml(SS_I18N.unitStreams) +
            '</li>';
    });
    renderNamedList('milestoneBreakdownHost', 'milestoneBreakdownSkeleton', 'milestoneBreakdownList', 'milestoneBreakdownEmpty', 'milestoneBreakdownError', breakdown, function(row) {
        var count = asInt(row.user_count);
        var unit = count !== 1 ? SS_I18N.unitViewers : SS_I18N.unitViewer;
        return '<li style="margin-bottom:0.4rem;">' +
            '<strong>' + escapeHtml(row.streak_value) + ' ' + escapeHtml(SS_I18N.unitStreams) + '</strong>' +
            ' - ' + escapeHtml(count) + ' ' + escapeHtml(unit) +
            '</li>';
    });
}

function renderStreakError() {
    var recentHost = document.getElementById('recentStreaksHost');
    if (recentHost) recentHost.setAttribute('aria-busy', 'false');
    hideEl(document.getElementById('recentStreaksTableWrap'));
    hideEl(document.getElementById('recentStreaksEmpty'));
    showEl(document.getElementById('recentStreaksError'));
    [['topStreakersHost', 'topStreakersSkeleton', 'topStreakersList', 'topStreakersEmpty', 'topStreakersError'],
     ['milestoneBreakdownHost', 'milestoneBreakdownSkeleton', 'milestoneBreakdownList', 'milestoneBreakdownEmpty', 'milestoneBreakdownError']].forEach(function(ids) {
        var host = document.getElementById(ids[0]);
        if (host) host.setAttribute('aria-busy', 'false');
        hideEl(document.getElementById(ids[1]));
        hideEl(document.getElementById(ids[2]));
        hideEl(document.getElementById(ids[3]));
        showEl(document.getElementById(ids[4]));
    });
}

function loadStreamStreaks() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderStreakError();
                return;
            }
            renderRecentStreaks(Array.isArray(data.recent_streaks) ? data.recent_streaks : []);
            renderStreakLists(
                Array.isArray(data.top_streakers) ? data.top_streakers : [],
                Array.isArray(data.milestone_breakdown) ? data.milestone_breakdown : []
            );
        })
        .catch(function() {
            renderStreakError();
        });
}

document.addEventListener('DOMContentLoaded', loadStreamStreaks);
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
