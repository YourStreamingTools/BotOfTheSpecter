<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('navbar_game_tracker');

require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php';
session_write_close();

function tracker_json($payload, $code = 200)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function tracker_tables_ready(mysqli $db): bool
{
    $res = $db->query("SHOW TABLES LIKE 'tracked_games'");
    return $res && $res->num_rows > 0;
}

function tracker_format_seconds($seconds): string
{
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0 && $minutes > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $minutes . 'm';
}

function tracker_naive_utc(?string $value): ?DateTime
{
    if ($value === null || $value === '') {
        return null;
    }
    try {
        return new DateTime(substr($value, 0, 19), new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
}

function tracker_session_seconds(mysqli $db, int $gameId): int
{
    $stmt = $db->prepare("SELECT started_at, ended_at, duration_seconds FROM tracked_game_sessions WHERE game_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = 0;
    $now = new DateTime('now', new DateTimeZone('UTC'));
    while ($row = $result->fetch_assoc()) {
        if ($row['ended_at'] === null || $row['ended_at'] === '') {
            $started = tracker_naive_utc($row['started_at']);
            if ($started) {
                $total += max(0, $now->getTimestamp() - $started->getTimestamp());
            }
        } else {
            $total += (int)($row['duration_seconds'] ?? 0);
        }
    }
    $stmt->close();
    return $total;
}

function tracker_displayed_seconds(mysqli $db, array $game): int
{
    return tracker_session_seconds($db, (int)$game['id']) + (int)($game['extra_seconds'] ?? 0);
}

function tracker_close_open_for_game(mysqli $db, int $gameId): void
{
    $stmt = $db->prepare("SELECT id, started_at FROM tracked_game_sessions WHERE game_id = ? AND ended_at IS NULL");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $nowDt = new DateTime('now', new DateTimeZone('UTC'));
    while ($row = $result->fetch_assoc()) {
        $started = tracker_naive_utc($row['started_at']);
        $duration = 0;
        if ($started) {
            $duration = max(0, $nowDt->getTimestamp() - $started->getTimestamp());
        }
        $upd = $db->prepare("UPDATE tracked_game_sessions SET ended_at = ?, duration_seconds = ? WHERE id = ?");
        if ($upd) {
            $sid = (int)$row['id'];
            $upd->bind_param('sii', $now, $duration, $sid);
            $upd->execute();
            $upd->close();
        }
    }
    $stmt->close();
}

function tracker_box_art(?string $twitchId, ?string $existing = null): ?string
{
    if (is_string($existing) && $existing !== '') {
        return str_replace(['{width}', '{height}'], ['285', '380'], $existing);
    }
    if ($twitchId) {
        return 'https://static-cdn.jtvnw.net/ttv-boxart/' . rawurlencode($twitchId) . '-285x380.jpg';
    }
    return null;
}

function tracker_helix_search(string $query, string $clientID, string $accessToken): array
{
    $url = 'https://api.twitch.tv/helix/search/categories?query=' . urlencode($query) . '&first=10';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $clientID,
        'Authorization: Bearer ' . $accessToken,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        return [];
    }
    $data = json_decode($resp, true);
    $out = [];
    foreach (($data['data'] ?? []) as $item) {
        $id = (string)($item['id'] ?? '');
        $name = (string)($item['name'] ?? '');
        if ($id === '' || $name === '') {
            continue;
        }
        $art = tracker_box_art($id, $item['box_art_url'] ?? null);
        $out[] = [
            'id' => $id,
            'name' => $name,
            'box_art_url' => $art,
        ];
    }
    return $out;
}

function tracker_game_payload(mysqli $db, array $game): array
{
    $sessionSeconds = tracker_session_seconds($db, (int)$game['id']);
    $displayed = $sessionSeconds + (int)($game['extra_seconds'] ?? 0);
    return [
        'id' => (int)$game['id'],
        'twitch_game_id' => $game['twitch_game_id'],
        'title' => $game['title'],
        'box_art_url' => $game['box_art_url'],
        'status' => $game['status'],
        'completion_100' => (int)$game['completion_100'] === 1,
        'extra_seconds' => (int)$game['extra_seconds'],
        'session_seconds' => $sessionSeconds,
        'displayed_seconds' => $displayed,
        'displayed_time' => tracker_format_seconds($displayed),
        'updated_at' => $game['updated_at'] ?? null,
    ];
}

$helixToken = (string)($_SESSION['access_token'] ?? '');
$allowedStatuses = ['playing', 'on_hold', 'finished', 'dropped'];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_categories') {
    $q = substr(trim((string)($_GET['q'] ?? '')), 0, 200);
    if ($q === '') {
        tracker_json([]);
    }
    tracker_json(tracker_helix_search($q, $clientID ?? '', $helixToken));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tracker_action'])) {
    if (!tracker_tables_ready($db)) {
        tracker_json(['success' => false, 'error' => t('tracker_not_ready')], 503);
    }
    $action = (string)$_POST['tracker_action'];

    if ($action === 'add_game') {
        $title = substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $twitchId = substr(trim((string)($_POST['twitch_game_id'] ?? '')), 0, 32);
        $boxArt = tracker_box_art($twitchId !== '' ? $twitchId : null, $_POST['box_art_url'] ?? null) ?? '';
        if ($title === '') {
            tracker_json(['success' => false, 'error' => t('tracker_error_title_required')], 400);
        }
        if ($twitchId !== '') {
            $chk = $db->prepare("SELECT id FROM tracked_games WHERE twitch_game_id = ? LIMIT 1");
            $chk->bind_param('s', $twitchId);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $chk->close();
                tracker_json(['success' => false, 'error' => t('tracker_error_already_listed')], 409);
            }
            $chk->close();
        }
        $chk = $db->prepare("SELECT id FROM tracked_games WHERE LOWER(title) = LOWER(?) LIMIT 1");
        $chk->bind_param('s', $title);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            tracker_json(['success' => false, 'error' => t('tracker_error_already_listed')], 409);
        }
        $chk->close();
        if ($twitchId !== '') {
            $stmt = $db->prepare("INSERT INTO tracked_games (twitch_game_id, title, box_art_url, status) VALUES (?, ?, ?, 'playing')");
            $stmt->bind_param('sss', $twitchId, $title, $boxArt);
        } else {
            $stmt = $db->prepare("INSERT INTO tracked_games (twitch_game_id, title, box_art_url, status) VALUES (NULL, ?, ?, 'playing')");
            $stmt->bind_param('ss', $title, $boxArt);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 500);
        }
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        tracker_json(['success' => true, 'id' => $newId]);
    }

    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($id < 1 || !in_array($status, $allowedStatuses, true)) {
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
        }
        $stmt = $db->prepare("UPDATE tracked_games SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();
        tracker_json(['success' => true]);
    }

    if ($action === 'update_100') {
        $id = (int)($_POST['id'] ?? 0);
        $flag = !empty($_POST['completion_100']) ? 1 : 0;
        if ($id < 1) {
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
        }
        $stmt = $db->prepare("UPDATE tracked_games SET completion_100 = ? WHERE id = ?");
        $stmt->bind_param('ii', $flag, $id);
        $stmt->execute();
        $stmt->close();
        tracker_json(['success' => true]);
    }

    if ($action === 'set_time') {
        $id = (int)($_POST['id'] ?? 0);
        $hours = max(0, (int)($_POST['hours'] ?? 0));
        $minutes = max(0, (int)($_POST['minutes'] ?? 0));
        if ($id < 1) {
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
        }
        $desired = ($hours * 3600) + ($minutes * 60);
        $sessionSeconds = tracker_session_seconds($db, $id);
        $extra = $desired - $sessionSeconds;
        $stmt = $db->prepare("UPDATE tracked_games SET extra_seconds = ? WHERE id = ?");
        $stmt->bind_param('ii', $extra, $id);
        $stmt->execute();
        $stmt->close();
        tracker_json(['success' => true, 'displayed_time' => tracker_format_seconds($desired)]);
    }

    if ($action === 'delete_game') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
        }
        $stmt = $db->prepare("DELETE FROM tracked_games WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        tracker_json(['success' => true]);
    }

    if ($action === 'add_denylist') {
        $title = substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $twitchId = substr(trim((string)($_POST['twitch_game_id'] ?? '')), 0, 32);
        if ($title === '') {
            tracker_json(['success' => false, 'error' => t('tracker_error_title_required')], 400);
        }
        if ($twitchId !== '') {
            $stmt = $db->prepare("INSERT IGNORE INTO tracked_game_denylist (twitch_game_id, title) VALUES (?, ?)");
            $stmt->bind_param('ss', $twitchId, $title);
        } else {
            $stmt = $db->prepare("INSERT IGNORE INTO tracked_game_denylist (twitch_game_id, title) VALUES (NULL, ?)");
            $stmt->bind_param('s', $title);
        }
        $stmt->execute();
        $stmt->close();
        if ($twitchId !== '') {
            $find = $db->prepare("SELECT id FROM tracked_games WHERE twitch_game_id = ? LIMIT 1");
            $find->bind_param('s', $twitchId);
            $find->execute();
            $found = $find->get_result()->fetch_assoc();
            $find->close();
            if ($found) {
                tracker_close_open_for_game($db, (int)$found['id']);
            }
        }
        tracker_json(['success' => true]);
    }

    if ($action === 'remove_denylist') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
        }
        $stmt = $db->prepare("DELETE FROM tracked_game_denylist WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        tracker_json(['success' => true]);
    }

    if ($action === 'sessions') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
        }
        $stmt = $db->prepare("SELECT id, started_at, ended_at, duration_seconds FROM tracked_game_sessions WHERE game_id = ? ORDER BY started_at DESC");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $tzName = 'UTC';
        $tzStmt = $db->prepare("SELECT timezone FROM profile");
        if ($tzStmt) {
            $tzStmt->execute();
            $tzRow = $tzStmt->get_result()->fetch_assoc();
            $tzStmt->close();
            if (!empty($tzRow['timezone'])) {
                $tzName = $tzRow['timezone'];
            }
        }
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }
        while ($row = $result->fetch_assoc()) {
            $start = tracker_naive_utc($row['started_at']);
            $end = tracker_naive_utc($row['ended_at']);
            if ($start) {
                $start->setTimezone($tz);
            }
            if ($end) {
                $end->setTimezone($tz);
            }
            $live = ($row['ended_at'] === null || $row['ended_at'] === '');
            $duration = $live
                ? tracker_session_seconds($db, $id) // not right for one session
                : (int)$row['duration_seconds'];
            if ($live && $start) {
                $now = new DateTime('now', new DateTimeZone('UTC'));
                $startUtc = tracker_naive_utc($row['started_at']);
                $duration = $startUtc ? max(0, $now->getTimestamp() - $startUtc->getTimestamp()) : 0;
            }
            $rows[] = [
                'id' => (int)$row['id'],
                'started_at' => $start ? $start->format('Y-m-d H:i') : $row['started_at'],
                'ended_at' => $live ? null : ($end ? $end->format('Y-m-d H:i') : $row['ended_at']),
                'live' => $live,
                'duration' => tracker_format_seconds($duration),
            ];
        }
        $stmt->close();
        tracker_json(['success' => true, 'sessions' => $rows]);
    }

    tracker_json(['success' => false, 'error' => t('tracker_error_save')], 400);
}

$trackerReady = tracker_tables_ready($db);
$games = [];
$denylist = [];
if ($trackerReady) {
    if ($res = $db->query("SELECT * FROM tracked_games ORDER BY FIELD(status,'playing','on_hold','finished','dropped'), updated_at DESC, id DESC")) {
        while ($row = $res->fetch_assoc()) {
            $games[] = tracker_game_payload($db, $row);
        }
        $res->free();
    }
    if ($res = $db->query("SELECT id, twitch_game_id, title FROM tracked_game_denylist ORDER BY title ASC")) {
        while ($row = $res->fetch_assoc()) {
            $denylist[] = $row;
        }
        $res->free();
    }
}

$statusLabels = [
    'playing' => t('tracker_status_playing'),
    'on_hold' => t('tracker_status_on_hold'),
    'finished' => t('tracker_status_finished'),
    'dropped' => t('tracker_status_dropped'),
];

ob_start();
?>
<div class="sp-page-header">
    <h1><i class="fas fa-gamepad"></i> <?= htmlspecialchars(t('navbar_game_tracker')) ?></h1>
    <p><?= t('tracker_intro') ?></p>
</div>
<div class="sp-alert sp-alert-info mb-4">
    <span class="icon"><i class="fas fa-info-circle"></i></span>
    <?= t('tracker_beta_notice') ?>
</div>

<?php if (!$trackerReady): ?>
<div class="sp-alert sp-alert-info"><?= htmlspecialchars(t('tracker_not_ready')) ?></div>
<?php else: ?>

<div class="sp-card tg-add-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-plus"></i> <?= htmlspecialchars(t('tracker_add_title')) ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?= htmlspecialchars(t('tracker_add_help')) ?></p>
        <div class="tg-add-row">
            <div class="tg-search-wrap">
                <input type="text" id="tgSearch" class="sp-input" autocomplete="off" placeholder="<?= htmlspecialchars(t('tracker_search_placeholder')) ?>">
                <div id="tgSearchResults" class="tg-search-results" hidden></div>
            </div>
            <button type="button" class="sp-btn sp-btn-secondary" id="tgAddTyped"><?= htmlspecialchars(t('tracker_add_typed')) ?></button>
        </div>
    </div>
</div>

<div class="tg-filters" role="tablist">
    <button type="button" class="sp-btn sp-btn-sm tg-filter is-active" data-filter="all"><?= htmlspecialchars(t('tracker_filter_all')) ?></button>
    <button type="button" class="sp-btn sp-btn-sm tg-filter" data-filter="playing"><?= htmlspecialchars(t('tracker_status_playing')) ?></button>
    <button type="button" class="sp-btn sp-btn-sm tg-filter" data-filter="on_hold"><?= htmlspecialchars(t('tracker_status_on_hold')) ?></button>
    <button type="button" class="sp-btn sp-btn-sm tg-filter" data-filter="finished"><?= htmlspecialchars(t('tracker_status_finished')) ?></button>
    <button type="button" class="sp-btn sp-btn-sm tg-filter" data-filter="dropped"><?= htmlspecialchars(t('tracker_status_dropped')) ?></button>
</div>

<div id="tgEmpty" class="sp-alert sp-alert-info" <?= $games ? 'hidden' : '' ?>><?= htmlspecialchars(t('tracker_empty')) ?></div>
<div id="tgGrid" class="tg-grid">
<?php foreach ($games as $game): ?>
    <article class="sp-card tg-card" data-status="<?= htmlspecialchars($game['status']) ?>" data-id="<?= (int)$game['id'] ?>">
        <div class="tg-card-main">
            <div class="tg-art">
                <?php if (!empty($game['box_art_url'])): ?>
                    <img src="<?= htmlspecialchars($game['box_art_url']) ?>" alt="">
                <?php else: ?>
                    <span class="tg-art-fallback"><i class="fas fa-gamepad"></i></span>
                <?php endif; ?>
            </div>
            <div class="tg-card-body">
                <h2 class="tg-title"><?= htmlspecialchars($game['title']) ?></h2>
                <div class="tg-meta">
                    <span class="sp-badge tg-status-badge tg-status-<?= htmlspecialchars($game['status']) ?>"><?= htmlspecialchars($statusLabels[$game['status']] ?? $game['status']) ?></span>
                    <?php if ($game['completion_100']): ?>
                        <span class="sp-badge sp-badge-amber">100%</span>
                    <?php endif; ?>
                    <span class="tg-time"><?= htmlspecialchars($game['displayed_time']) ?></span>
                </div>
                <div class="tg-actions">
                    <select class="sp-select tg-status" data-id="<?= (int)$game['id'] ?>">
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $game['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="tg-100">
                        <input type="checkbox" class="tg-100-input" data-id="<?= (int)$game['id'] ?>" <?= $game['completion_100'] ? 'checked' : '' ?>>
                        100%
                    </label>
                    <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary tg-sessions-btn" data-id="<?= (int)$game['id'] ?>" data-title="<?= htmlspecialchars($game['title']) ?>"><?= htmlspecialchars(t('tracker_sessions')) ?></button>
                    <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary tg-time-btn" data-id="<?= (int)$game['id'] ?>" data-seconds="<?= (int)$game['displayed_seconds'] ?>"><?= htmlspecialchars(t('tracker_edit_time')) ?></button>
                    <button type="button" class="sp-btn sp-btn-sm sp-btn-danger tg-delete-btn" data-id="<?= (int)$game['id'] ?>"><?= htmlspecialchars(t('tracker_remove')) ?></button>
                </div>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>

<div class="sp-card tg-denylist-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-ban"></i> <?= htmlspecialchars(t('tracker_denylist_title')) ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?= htmlspecialchars(t('tracker_denylist_help')) ?></p>
        <div class="tg-add-row">
            <div class="tg-search-wrap">
                <input type="text" id="tgDenySearch" class="sp-input" autocomplete="off" placeholder="<?= htmlspecialchars(t('tracker_search_placeholder')) ?>">
                <div id="tgDenyResults" class="tg-search-results" hidden></div>
            </div>
        </div>
        <ul id="tgDenyList" class="tg-deny-list">
            <?php foreach ($denylist as $row): ?>
                <li data-id="<?= (int)$row['id'] ?>">
                    <span><?= htmlspecialchars($row['title']) ?></span>
                    <button type="button" class="sp-btn sp-btn-sm sp-btn-ghost tg-deny-remove" data-id="<?= (int)$row['id'] ?>"><?= htmlspecialchars(t('tracker_denylist_remove')) ?></button>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (!$denylist): ?>
            <p class="sp-help" id="tgDenyEmpty"><?= htmlspecialchars(t('tracker_denylist_empty')) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="sp-modal-backdrop" id="tgModal" hidden>
    <div class="sp-modal" role="dialog" aria-modal="true">
        <div class="sp-modal-head">
            <div class="sp-modal-title" id="tgModalTitle"></div>
            <button type="button" class="sp-modal-close" id="tgModalClose" aria-label="<?= htmlspecialchars(t('tracker_close')) ?>">&times;</button>
        </div>
        <div class="sp-modal-body" id="tgModalBody"></div>
    </div>
</div>

<?php endif; ?>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
(function () {
    var i18n = <?= json_encode([
        'confirmDelete' => t('tracker_confirm_delete'),
        'saveFailed' => t('tracker_error_save'),
        'noResults' => t('tracker_search_none'),
        'sessionsTitle' => t('tracker_sessions_for'),
        'editTimeTitle' => t('tracker_edit_time_for'),
        'hours' => t('tracker_hours'),
        'minutes' => t('tracker_minutes'),
        'save' => t('tracker_save'),
        'live' => t('tracker_session_live'),
        'noSessions' => t('tracker_no_sessions'),
        'addTypedPrompt' => t('tracker_typed_prompt'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function post(data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (r) { return r.json(); });
    }

    function bindSearch(inputId, resultsId, onPick) {
        var input = document.getElementById(inputId);
        var box = document.getElementById(resultsId);
        if (!input || !box) return;
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) {
                box.hidden = true;
                box.innerHTML = '';
                return;
            }
            timer = setTimeout(function () {
                var url = new URL(window.location.href);
                url.searchParams.set('ajax', 'search_categories');
                url.searchParams.set('q', q);
                fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (items) {
                        box.innerHTML = '';
                        if (!Array.isArray(items) || !items.length) {
                            box.innerHTML = '<div class="tg-search-empty">' + i18n.noResults + '</div>';
                            box.hidden = false;
                            return;
                        }
                        items.forEach(function (item) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'tg-search-item';
                            btn.innerHTML = (item.box_art_url
                                ? '<img src="' + item.box_art_url.replace(/"/g, '') + '" alt="">'
                                : '') + '<span></span>';
                            btn.querySelector('span').textContent = item.name;
                            btn.addEventListener('click', function () {
                                box.hidden = true;
                                input.value = '';
                                onPick(item);
                            });
                            box.appendChild(btn);
                        });
                        box.hidden = false;
                    })
                    .catch(function () {
                        box.hidden = true;
                    });
            }, 250);
        });
        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && e.target !== input) {
                box.hidden = true;
            }
        });
    }

    bindSearch('tgSearch', 'tgSearchResults', function (item) {
        post({
            tracker_action: 'add_game',
            title: item.name,
            twitch_game_id: item.id,
            box_art_url: item.box_art_url || ''
        }).then(function (res) {
            if (res && res.success) { location.reload(); }
            else { alert((res && res.error) || i18n.saveFailed); }
        }).catch(function () { alert(i18n.saveFailed); });
    });

    bindSearch('tgDenySearch', 'tgDenyResults', function (item) {
        post({
            tracker_action: 'add_denylist',
            title: item.name,
            twitch_game_id: item.id
        }).then(function (res) {
            if (res && res.success) { location.reload(); }
            else { alert((res && res.error) || i18n.saveFailed); }
        }).catch(function () { alert(i18n.saveFailed); });
    });

    var addTyped = document.getElementById('tgAddTyped');
    if (addTyped) {
        addTyped.addEventListener('click', function () {
            var title = window.prompt(i18n.addTypedPrompt);
            if (!title) return;
            post({ tracker_action: 'add_game', title: title }).then(function (res) {
                if (res && res.success) { location.reload(); }
                else { alert((res && res.error) || i18n.saveFailed); }
            }).catch(function () { alert(i18n.saveFailed); });
        });
    }

    document.querySelectorAll('.tg-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tg-filter').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            var filter = btn.getAttribute('data-filter');
            var shown = 0;
            document.querySelectorAll('.tg-card').forEach(function (card) {
                var match = filter === 'all' || card.getAttribute('data-status') === filter;
                card.hidden = !match;
                if (match) shown += 1;
            });
            var empty = document.getElementById('tgEmpty');
            if (empty) empty.hidden = shown > 0;
        });
    });

    document.querySelectorAll('.tg-status').forEach(function (sel) {
        sel.addEventListener('change', function () {
            post({ tracker_action: 'update_status', id: sel.getAttribute('data-id'), status: sel.value })
                .then(function (res) {
                    if (res && res.success) { location.reload(); }
                    else { alert((res && res.error) || i18n.saveFailed); }
                });
        });
    });

    document.querySelectorAll('.tg-100-input').forEach(function (box) {
        box.addEventListener('change', function () {
            post({
                tracker_action: 'update_100',
                id: box.getAttribute('data-id'),
                completion_100: box.checked ? '1' : ''
            }).then(function (res) {
                if (res && res.success) { location.reload(); }
                else { alert((res && res.error) || i18n.saveFailed); }
            });
        });
    });

    document.querySelectorAll('.tg-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.confirm(i18n.confirmDelete)) return;
            post({ tracker_action: 'delete_game', id: btn.getAttribute('data-id') })
                .then(function (res) {
                    if (res && res.success) { location.reload(); }
                    else { alert((res && res.error) || i18n.saveFailed); }
                });
        });
    });

    document.querySelectorAll('.tg-deny-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            post({ tracker_action: 'remove_denylist', id: btn.getAttribute('data-id') })
                .then(function (res) {
                    if (res && res.success) { location.reload(); }
                    else { alert((res && res.error) || i18n.saveFailed); }
                });
        });
    });

    var modal = document.getElementById('tgModal');
    var modalTitle = document.getElementById('tgModalTitle');
    var modalBody = document.getElementById('tgModalBody');
    var modalClose = document.getElementById('tgModalClose');
    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        modalBody.innerHTML = '';
    }
    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    document.querySelectorAll('.tg-sessions-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            post({ tracker_action: 'sessions', id: btn.getAttribute('data-id') }).then(function (res) {
                if (!res || !res.success) {
                    alert((res && res.error) || i18n.saveFailed);
                    return;
                }
                modalTitle.textContent = i18n.sessionsTitle + ' ' + btn.getAttribute('data-title');
                var sessions = res.sessions || [];
                if (!sessions.length) {
                    modalBody.textContent = i18n.noSessions;
                } else {
                    var html = '<table class="sp-table"><thead><tr><th>Start</th><th>End</th><th>Time</th></tr></thead><tbody>';
                    sessions.forEach(function (s) {
                        html += '<tr><td>' + s.started_at + '</td><td>' + (s.live ? i18n.live : (s.ended_at || '')) + '</td><td>' + s.duration + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    modalBody.innerHTML = html;
                }
                modal.hidden = false;
            });
        });
    });

    document.querySelectorAll('.tg-time-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var seconds = parseInt(btn.getAttribute('data-seconds') || '0', 10);
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            modalTitle.textContent = i18n.editTimeTitle;
            modalBody.innerHTML =
                '<form id="tgTimeForm" class="tg-time-form">' +
                '<div class="sp-form-group"><label class="sp-label">' + i18n.hours + '</label><input class="sp-input" type="number" min="0" name="hours" value="' + hours + '"></div>' +
                '<div class="sp-form-group"><label class="sp-label">' + i18n.minutes + '</label><input class="sp-input" type="number" min="0" name="minutes" value="' + minutes + '"></div>' +
                '<button type="submit" class="sp-btn sp-btn-primary">' + i18n.save + '</button></form>';
            modal.hidden = false;
            var form = document.getElementById('tgTimeForm');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                post({
                    tracker_action: 'set_time',
                    id: btn.getAttribute('data-id'),
                    hours: form.hours.value,
                    minutes: form.minutes.value
                }).then(function (res) {
                    if (res && res.success) { location.reload(); }
                    else { alert((res && res.error) || i18n.saveFailed); }
                });
            });
        });
    });
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
