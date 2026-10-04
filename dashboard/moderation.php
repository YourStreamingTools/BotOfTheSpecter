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
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

function moderation_format_when($when)
{
    if ($when !== '' && $when !== '0000-00-00 00:00:00') {
        return date('Y-m-d H:i:s', strtotime($when));
    }
    return t('moderation_unknown_date');
}

function moderation_apply_timezone($db)
{
    $tzStmt = $db->prepare("SELECT timezone FROM profile");
    if ($tzStmt) {
        $tzStmt->execute();
        $tzResult = $tzStmt->get_result();
        $channelData = $tzResult ? $tzResult->fetch_assoc() : null;
        date_default_timezone_set($channelData['timezone'] ?? 'UTC');
        $tzStmt->close();
    } else {
        date_default_timezone_set('UTC');
    }
}

// Twitch ban list (Helix), one page per request. The browser keeps the cursors
// it has seen so it can step back a page; Helix only hands out "after" cursors.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'bans') {
    header('Content-Type: application/json');
    try {
        moderation_apply_timezone($db);
    } catch (Exception $e) {
        date_default_timezone_set('UTC');
    }
    include_once '/var/www/config/twitch.php';
    $helixClientId = trim((string) ($clientID ?? ''));
    if ($helixClientId === '' || empty($authToken) || empty($broadcasterID)) {
        echo json_encode(['success' => false, 'error' => 'missing_credentials']);
        exit();
    }
    $bansUrl = 'https://api.twitch.tv/helix/moderation/banned?broadcaster_id=' . rawurlencode((string) $broadcasterID) . '&first=25';
    $after = trim((string) ($_GET['after'] ?? ''));
    if ($after !== '') {
        $bansUrl .= '&after=' . rawurlencode($after);
    }
    $curl = curl_init($bansUrl);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $authToken,
        'Client-ID: ' . $helixClientId
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false) {
        echo json_encode(['success' => false, 'error' => 'helix_request_failed']);
        exit();
    }
    if ($httpCode !== 200) {
        echo json_encode([
            'success' => false,
            'error' => 'helix_http_' . $httpCode,
            'token_invalid' => ($httpCode === 401 || $httpCode === 403),
        ]);
        exit();
    }
    $bansData = json_decode($response, true);
    $bans = [];
    foreach (($bansData['data'] ?? []) as $ban) {
        $expiresAt = (string) ($ban['expires_at'] ?? '');
        $bans[] = [
            'user_id' => (string) ($ban['user_id'] ?? ''),
            'user_login' => (string) ($ban['user_login'] ?? ''),
            'user_name' => (string) ($ban['user_name'] ?? ''),
            'reason' => (string) ($ban['reason'] ?? ''),
            'moderator_name' => (string) ($ban['moderator_name'] ?? ''),
            'banned_display' => moderation_format_when((string) ($ban['created_at'] ?? '')),
            'is_timeout' => $expiresAt !== '',
            'expires_display' => $expiresAt !== '' ? moderation_format_when($expiresAt) : '',
        ];
    }
    echo json_encode([
        'success' => true,
        'bans' => $bans,
        'cursor' => (string) ($bansData['pagination']['cursor'] ?? ''),
    ]);
    exit();
}

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        moderation_apply_timezone($db);

        $searchQuery = trim((string) ($_GET['q'] ?? ''));
        $filterUser = trim((string) ($_GET['user'] ?? ''));
        $warnings = [];
        $totalWarnings = 0;
        $uniqueWarned = 0;
        $warningsThisWeek = 0;

        $statsRes = $db->query("SELECT COUNT(*) AS total, COUNT(DISTINCT COALESCE(NULLIF(user_id, ''), user_name)) AS unique_users FROM warnings");
        if ($statsRes === false) {
            echo json_encode(['success' => false, 'unavailable' => true]);
            exit();
        }
        $statsRow = $statsRes->fetch_assoc();
        $totalWarnings = (int) ($statsRow['total'] ?? 0);
        $uniqueWarned = (int) ($statsRow['unique_users'] ?? 0);

        $weekRes = $db->query("SELECT COUNT(*) AS week_count FROM warnings WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
        if ($weekRes) {
            $weekRow = $weekRes->fetch_assoc();
            $warningsThisWeek = (int) ($weekRow['week_count'] ?? 0);
        }

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

        foreach ($warnings as &$w) {
            $w['when_display'] = moderation_format_when($w['created_at'] ?? '');
        }
        unset($w);

        echo json_encode([
            'success' => true,
            'total' => $totalWarnings,
            'unique' => $uniqueWarned,
            'week' => $warningsThisWeek,
            'warnings' => $warnings,
        ]);
    } catch (mysqli_sql_exception $e) {
        error_log('moderation.php warnings query failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'unavailable' => true, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        error_log('moderation.php warnings query failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'unavailable' => true, 'error' => $e->getMessage()]);
    }
    exit();
}

$statusMessage = null;
$statusType = 'is-info';

// Handle moderation actions (delete single warning)
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
    }
}

// Filters (form chrome only — list rows load via ajax_action=list)
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$filterUser = trim((string) ($_GET['user'] ?? ''));

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

<div class="sp-stat-row mb-4" id="mod-stats" aria-busy="true">
    <?php for ($sk = 0; $sk < 3; $sk++): ?>
    <div class="sp-skeleton-stat" aria-hidden="true">
        <span class="sp-skeleton-line w-55"></span>
        <span class="sp-skeleton-line w-40"></span>
        <span class="sp-skeleton-line w-40"></span>
    </div>
    <?php endfor; ?>
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

<div class="sp-card mb-5">
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

        <div id="mod-log-host" aria-busy="true">
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
                    <tbody id="mod-log-body">
                        <?php for ($sk = 0; $sk < 5; $sk++): ?>
                        <tr aria-hidden="true">
                            <td><span class="sp-skeleton-line w-50"></span></td>
                            <td><span class="sp-skeleton-line w-80"></span></td>
                            <td><span class="sp-skeleton-line w-40"></span></td>
                            <td><span class="sp-skeleton-line w-60"></span></td>
                            <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <p id="mod-limit-note" class="sp-help mt-3" style="display:none;"><?= t('moderation_limit_note') ?></p>
        </div>
    </div>
</div>

<div class="sp-card mb-5">
    <header class="sp-card-header">
        <div class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-ban"></i></span>
            <?= t('moderation_bans_title') ?>
        </div>
    </header>
    <div class="sp-card-body">
        <p class="sp-text-muted mb-4"><?= t('moderation_bans_intro') ?></p>
        <div id="mod-bans-host" aria-busy="true">
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <thead>
                        <tr>
                            <th><?= t('moderation_col_user') ?></th>
                            <th><?= t('moderation_col_reason') ?></th>
                            <th><?= t('moderation_col_banned_by') ?></th>
                            <th><?= t('moderation_col_banned_at') ?></th>
                            <th><?= t('moderation_col_expires') ?></th>
                        </tr>
                    </thead>
                    <tbody id="mod-bans-body">
                        <?php for ($sk = 0; $sk < 5; $sk++): ?>
                        <tr aria-hidden="true">
                            <td><span class="sp-skeleton-line w-50"></span></td>
                            <td><span class="sp-skeleton-line w-80"></span></td>
                            <td><span class="sp-skeleton-line w-40"></span></td>
                            <td><span class="sp-skeleton-line w-60"></span></td>
                            <td><span class="sp-skeleton-badge"></span></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <nav class="sp-pagination" id="mod-bans-pager" aria-label="<?= htmlspecialchars(t('moderation_bans_title')) ?>" hidden>
                <button type="button" class="sp-pagination-link" id="mod-bans-prev"><i class="fas fa-chevron-left"></i>&nbsp;<?= t('moderation_page_prev') ?></button>
                <span class="sp-pagination-link is-current" id="mod-bans-page">1</span>
                <button type="button" class="sp-pagination-link" id="mod-bans-next"><?= t('moderation_page_next') ?>&nbsp;<i class="fas fa-chevron-right"></i></button>
            </nav>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
var MOD_FILTER = {
    q: <?= json_encode($searchQuery) ?>,
    user: <?= json_encode($filterUser) ?>
};
var MOD_I18N = {
    statTotal: <?= json_encode(t('moderation_stat_total')) ?>,
    statUnique: <?= json_encode(t('moderation_stat_unique')) ?>,
    statWeek: <?= json_encode(t('moderation_stat_week')) ?>,
    noWarnings: <?= json_encode(t('moderation_no_warnings')) ?>,
    tableUnavailable: <?= json_encode(t('moderation_table_unavailable')) ?>,
    filterUserTitle: <?= json_encode(t('moderation_filter_user_title')) ?>,
    deleteOne: <?= json_encode(t('moderation_delete_one')) ?>,
    confirmDeleteOne: <?= json_encode(t('moderation_confirm_delete_one')) ?>,
    bansEmpty: <?= json_encode(t('moderation_bans_empty')) ?>,
    bansError: <?= json_encode(t('moderation_bans_error')) ?>,
    bansReauth: <?= json_encode(t('moderation_bans_reauth')) ?>,
    banPermanent: <?= json_encode(t('moderation_ban_permanent')) ?>,
    banTimeoutUntil: <?= json_encode(t('moderation_ban_timeout_until')) ?>,
    noReason: <?= json_encode(t('moderation_no_reason')) ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function filterActionQuery() {
    if (MOD_FILTER.q) return '?q=' + encodeURIComponent(MOD_FILTER.q);
    if (MOD_FILTER.user) return '?user=' + encodeURIComponent(MOD_FILTER.user);
    return '';
}

function renderModStats(data) {
    var host = document.getElementById('mod-stats');
    if (!host) return;
    host.setAttribute('aria-busy', 'false');
    host.innerHTML =
        '<div class="sp-stat"><div class="sp-stat-label">' + escapeHtml(MOD_I18N.statTotal) + '</div><div class="sp-stat-value">' + escapeHtml(data.total) + '</div></div>' +
        '<div class="sp-stat"><div class="sp-stat-label">' + escapeHtml(MOD_I18N.statUnique) + '</div><div class="sp-stat-value">' + escapeHtml(data.unique) + '</div></div>' +
        '<div class="sp-stat"><div class="sp-stat-label">' + escapeHtml(MOD_I18N.statWeek) + '</div><div class="sp-stat-value">' + escapeHtml(data.week) + '</div></div>';
}

function renderModLog(warnings) {
    var host = document.getElementById('mod-log-host');
    var tbody = document.getElementById('mod-log-body');
    var limitNote = document.getElementById('mod-limit-note');
    if (host) host.setAttribute('aria-busy', 'false');
    if (limitNote) limitNote.style.display = warnings.length >= 500 ? '' : 'none';
    if (!tbody) return;
    if (!warnings.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:2.5rem 0;"><p class="sp-text-muted" style="font-size:1.05rem;">' + escapeHtml(MOD_I18N.noWarnings) + '</p></td></tr>';
        return;
    }
    var actionQs = filterActionQuery();
    tbody.innerHTML = warnings.map(function(w) {
        var uid = String(w.user_id || '');
        var uname = String(w.user_name || '');
        var idHtml = uid !== '' ? '<div class="sp-text-muted" style="font-size:0.8rem;">ID: ' + escapeHtml(uid) + '</div>' : '';
        return '<tr>' +
            '<td><a href="moderation.php?user=' + encodeURIComponent(uname) + '" title="' + escapeHtml(MOD_I18N.filterUserTitle) + '"><strong>' + escapeHtml(uname) + '</strong></a>' + idHtml + '</td>' +
            '<td style="max-width:28rem; word-break:break-word;">' + escapeHtml(w.reason || '') + '</td>' +
            '<td>' + escapeHtml(w.warned_by_name || '') + '</td>' +
            '<td>' + escapeHtml(w.when_display || '') + '</td>' +
            '<td style="text-align:center; white-space:nowrap;">' +
                '<form method="post" action="moderation.php' + actionQs + '" style="display:inline;" class="mod-delete-form" data-confirm="' + escapeHtml(MOD_I18N.confirmDeleteOne) + '">' +
                    '<input type="hidden" name="action" value="delete_warning">' +
                    '<input type="hidden" name="warning_id" value="' + escapeHtml(w.id) + '">' +
                    '<button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="' + escapeHtml(MOD_I18N.deleteOne) + '"><i class="fas fa-trash"></i></button>' +
                '</form>' +
            '</td></tr>';
    }).join('');
}

function renderModUnavailable() {
    var stats = document.getElementById('mod-stats');
    var logHost = document.getElementById('mod-log-host');
    var limitNote = document.getElementById('mod-limit-note');
    if (stats) {
        stats.setAttribute('aria-busy', 'false');
        stats.innerHTML =
            '<div class="sp-stat"><div class="sp-stat-label">' + escapeHtml(MOD_I18N.statTotal) + '</div><div class="sp-stat-value">—</div></div>' +
            '<div class="sp-stat"><div class="sp-stat-label">' + escapeHtml(MOD_I18N.statUnique) + '</div><div class="sp-stat-value">—</div></div>' +
            '<div class="sp-stat"><div class="sp-stat-label">' + escapeHtml(MOD_I18N.statWeek) + '</div><div class="sp-stat-value">—</div></div>';
    }
    if (limitNote) limitNote.style.display = 'none';
    if (logHost) {
        logHost.setAttribute('aria-busy', 'false');
        logHost.innerHTML = '<p class="sp-text-muted">' + escapeHtml(MOD_I18N.tableUnavailable) + '</p>';
    }
}

function loadModeration() {
    var url = new URL(window.location.href);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderModUnavailable();
                return;
            }
            renderModStats(data);
            renderModLog(Array.isArray(data.warnings) ? data.warnings : []);
        })
        .catch(function() {
            renderModUnavailable();
        });
}

// Ban list paging: cursors[i] is the Helix "after" cursor that loads page i.
var MOD_BANS = { cursors: [''], page: 0, hasNext: false, loading: false };

function setBansPager() {
    var pager = document.getElementById('mod-bans-pager');
    var prev = document.getElementById('mod-bans-prev');
    var next = document.getElementById('mod-bans-next');
    var label = document.getElementById('mod-bans-page');
    if (!pager) return;
    pager.hidden = MOD_BANS.page === 0 && !MOD_BANS.hasNext;
    if (prev) prev.disabled = MOD_BANS.loading || MOD_BANS.page === 0;
    if (next) next.disabled = MOD_BANS.loading || !MOD_BANS.hasNext;
    if (label) label.textContent = String(MOD_BANS.page + 1);
}

function renderBansMessage(text) {
    var tbody = document.getElementById('mod-bans-body');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:2.5rem 0;"><p class="sp-text-muted" style="font-size:1.05rem;">' + escapeHtml(text) + '</p></td></tr>';
    }
}

function renderBans(bans) {
    var tbody = document.getElementById('mod-bans-body');
    if (!tbody) return;
    if (!bans.length) {
        renderBansMessage(MOD_I18N.bansEmpty);
        return;
    }
    tbody.innerHTML = bans.map(function (b) {
        var login = String(b.user_login || '');
        var name = String(b.user_name || login);
        var reason = b.reason ? escapeHtml(b.reason) : '<span class="sp-text-muted">' + escapeHtml(MOD_I18N.noReason) + '</span>';
        var expires = b.is_timeout
            ? '<span class="sp-badge sp-badge-amber">' + escapeHtml(String(MOD_I18N.banTimeoutUntil).replace(':when', b.expires_display || '')) + '</span>'
            : '<span class="sp-badge sp-badge-red">' + escapeHtml(MOD_I18N.banPermanent) + '</span>';
        return '<tr>' +
            '<td><strong>' + escapeHtml(name) + '</strong>' + (login && login.toLowerCase() !== name.toLowerCase() ? '<div class="sp-text-muted" style="font-size:0.8rem;">' + escapeHtml(login) + '</div>' : '') + '</td>' +
            '<td style="max-width:28rem; word-break:break-word;">' + reason + '</td>' +
            '<td>' + escapeHtml(b.moderator_name || '') + '</td>' +
            '<td>' + escapeHtml(b.banned_display || '') + '</td>' +
            '<td>' + expires + '</td>' +
            '</tr>';
    }).join('');
}

function loadBans(pageIdx) {
    if (MOD_BANS.loading) return;
    MOD_BANS.loading = true;
    setBansPager();
    var host = document.getElementById('mod-bans-host');
    if (host) host.setAttribute('aria-busy', 'true');
    var url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('ajax_action', 'bans');
    if (MOD_BANS.cursors[pageIdx]) url.searchParams.set('after', MOD_BANS.cursors[pageIdx]);
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                renderBansMessage(data && data.token_invalid ? MOD_I18N.bansReauth : MOD_I18N.bansError);
                MOD_BANS.hasNext = false;
                return;
            }
            var bans = Array.isArray(data.bans) ? data.bans : [];
            // Helix can hand out a cursor that leads to an empty page; stay on the last real page.
            if (!bans.length && pageIdx > 0) {
                MOD_BANS.cursors.length = pageIdx;
                MOD_BANS.hasNext = false;
                return;
            }
            MOD_BANS.page = pageIdx;
            MOD_BANS.cursors.length = pageIdx + 1;
            if (data.cursor) MOD_BANS.cursors.push(data.cursor);
            MOD_BANS.hasNext = !!data.cursor;
            renderBans(bans);
        })
        .catch(function () {
            renderBansMessage(MOD_I18N.bansError);
            MOD_BANS.hasNext = false;
        })
        .then(function () {
            MOD_BANS.loading = false;
            if (host) host.setAttribute('aria-busy', 'false');
            setBansPager();
        });
}

document.addEventListener('DOMContentLoaded', function () {
    var bansPrev = document.getElementById('mod-bans-prev');
    var bansNext = document.getElementById('mod-bans-next');
    if (bansPrev) bansPrev.addEventListener('click', function () { if (MOD_BANS.page > 0) loadBans(MOD_BANS.page - 1); });
    if (bansNext) bansNext.addEventListener('click', function () { if (MOD_BANS.hasNext) loadBans(MOD_BANS.page + 1); });
    loadBans(0);
    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('.mod-delete-form') : null;
        if (!form) return;
        var msg = form.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });
    loadModeration();
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
