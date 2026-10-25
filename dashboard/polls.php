<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('polls_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// Twitch polls: started here through the Helix API, results kept in the user DB's poll_results
// (the same table the bot fills from channel.poll.* EventSub, so both stay one record per poll).
const POLLS_DURATIONS = [15, 30, 60, 120, 180, 300, 600, 900, 1800];

function polls_json(array $body, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit();
}

function polls_helix(string $method, string $url, ?array $body = null): array {
    global $authToken, $clientID;
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $authToken, 'Client-ID: ' . $clientID];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $code = $response === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) error_log('polls: ' . curl_error($ch));
    curl_close($ch);
    return [$code, $response === false ? [] : (json_decode($response, true) ?: [])];
}

function polls_twitch_error(int $code, array $data): string {
    if ($code === 0) return t('polls_err_twitch_down');
    $msg = trim((string) ($data['message'] ?? ''));
    return t('polls_err_twitch', ['msg' => $msg !== '' ? $msg : 'HTTP ' . $code]);
}

function polls_utc(?string $iso): ?string {
    if (!$iso) return null;
    $ts = strtotime($iso);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

// Same shape as the bot's save_poll_results: options ranked by votes, times in UTC.
function polls_save(mysqli $db, array $poll): void {
    $choices = $poll['choices'] ?? [];
    usort($choices, fn($a, $b) => (int) ($b['votes'] ?? 0) <=> (int) ($a['votes'] ?? 0));
    $names = []; $votes = [];
    for ($i = 0; $i < 5; $i++) {
        $names[] = isset($choices[$i]) ? (string) $choices[$i]['title'] : null;
        $votes[] = isset($choices[$i]) ? (int) ($choices[$i]['votes'] ?? 0) : null;
    }
    $bits = array_sum(array_map(fn($c) => (int) ($c['bits_votes'] ?? 0), $choices));
    $points = array_sum(array_map(fn($c) => (int) ($c['channel_points_votes'] ?? 0), $choices));
    $id = (string) $poll['id']; $title = (string) ($poll['title'] ?? '');
    $started = polls_utc($poll['started_at'] ?? null); $ended = polls_utc($poll['ended_at'] ?? null);
    $stmt = $db->prepare(
        "INSERT INTO poll_results (poll_id, poll_name, poll_option_one, poll_option_two, poll_option_three, poll_option_four, poll_option_five,
            poll_option_one_results, poll_option_two_results, poll_option_three_results, poll_option_four_results, poll_option_five_results,
            bits_used, channel_points_used, started_at, ended_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE poll_name = VALUES(poll_name),
            poll_option_one = VALUES(poll_option_one), poll_option_two = VALUES(poll_option_two), poll_option_three = VALUES(poll_option_three),
            poll_option_four = VALUES(poll_option_four), poll_option_five = VALUES(poll_option_five),
            poll_option_one_results = VALUES(poll_option_one_results), poll_option_two_results = VALUES(poll_option_two_results),
            poll_option_three_results = VALUES(poll_option_three_results), poll_option_four_results = VALUES(poll_option_four_results),
            poll_option_five_results = VALUES(poll_option_five_results), bits_used = VALUES(bits_used), channel_points_used = VALUES(channel_points_used),
            started_at = COALESCE(VALUES(started_at), started_at), ended_at = COALESCE(VALUES(ended_at), ended_at)"
    );
    $stmt->bind_param('sssssssiiiiiiiss', $id, $title, $names[0], $names[1], $names[2], $names[3], $names[4],
        $votes[0], $votes[1], $votes[2], $votes[3], $votes[4], $bits, $points, $started, $ended);
    $stmt->execute();
    $stmt->close();
}

function polls_active_json(?array $poll): ?array {
    if (!$poll || ($poll['status'] ?? '') !== 'ACTIVE') return null;
    return [
        'id' => (string) $poll['id'],
        'title' => (string) $poll['title'],
        'choices' => array_map(fn($c) => ['title' => (string) $c['title'], 'votes' => (int) ($c['votes'] ?? 0)], $poll['choices'] ?? []),
        'started_at' => (string) ($poll['started_at'] ?? ''),
        'ends_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $poll['started_at']) + (int) ($poll['duration'] ?? 0)),
        'channel_points_voting_enabled' => !empty($poll['channel_points_voting_enabled']),
    ];
}

// List: the live poll from Twitch, plus the recorded history. Unfinished rows (bot offline when the
// poll ended) are refreshed from Twitch first; Twitch keeps polls for 90 days.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    $twitchError = null;
    $active = null;
    try {
        $pending = [];
        $res = $db->query("SELECT poll_id FROM poll_results WHERE ended_at IS NULL AND (started_at IS NULL OR started_at > UTC_TIMESTAMP() - INTERVAL 90 DAY) ORDER BY started_at DESC LIMIT 20");
        while ($row = $res->fetch_assoc()) $pending[] = $row['poll_id'];
        $url = 'https://api.twitch.tv/helix/polls?broadcaster_id=' . urlencode($broadcasterID)
            . ($pending ? '&id=' . implode('&id=', array_map('urlencode', $pending)) : '&first=1');
        [$code, $data] = polls_helix('GET', $url);
        if ($code === 401) polls_json(['success' => false, 'error' => t('polls_err_twitch_auth')], 401);
        if ($code === 200) {
            foreach ($data['data'] ?? [] as $poll) {
                if ($pending) polls_save($db, $poll);
                $active = $active ?? polls_active_json($poll);
            }
            if ($pending && !$active) {
                [$code, $data] = polls_helix('GET', 'https://api.twitch.tv/helix/polls?broadcaster_id=' . urlencode($broadcasterID) . '&first=1');
                if ($code === 200) $active = polls_active_json($data['data'][0] ?? null);
            }
        } else {
            $twitchError = polls_twitch_error($code, $data);
        }
        $polls = [];
        $res = $db->query("SELECT * FROM poll_results ORDER BY COALESCE(started_at, '1970-01-01') DESC LIMIT 50");
        $words = ['one', 'two', 'three', 'four', 'five'];
        while ($row = $res->fetch_assoc()) {
            $choices = [];
            foreach ($words as $w) {
                if ($row["poll_option_$w"] !== null && $row["poll_option_$w"] !== '') $choices[] = ['title' => $row["poll_option_$w"], 'votes' => (int) $row["poll_option_{$w}_results"]];
            }
            $polls[] = [
                'id' => $row['poll_id'],
                'title' => $row['poll_name'],
                'choices' => $choices,
                'channel_points_used' => (int) $row['channel_points_used'],
                'started_at' => $row['started_at'] ? str_replace(' ', 'T', $row['started_at']) . 'Z' : null,
                'ended_at' => $row['ended_at'] ? str_replace(' ', 'T', $row['ended_at']) . 'Z' : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('polls list: ' . $e->getMessage());
        polls_json(['success' => false, 'error' => t('polls_err_load')]);
    }
    polls_json(['success' => true, 'active' => $active, 'polls' => $polls, 'twitch_error' => $twitchError]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $title)) polls_json(['success' => false, 'error' => t('polls_err_title')]);
        $choices = array_values(array_filter(array_map(fn($c) => trim((string) $c), (array) ($_POST['choices'] ?? [])), fn($c) => $c !== ''));
        if (count($choices) < 2 || count($choices) > 5) polls_json(['success' => false, 'error' => t('polls_err_choices')]);
        foreach ($choices as $c) if (mb_strlen($c) > 25 || preg_match('/[\x00-\x1F\x7F]/u', $c)) polls_json(['success' => false, 'error' => t('polls_err_choices')]);
        $duration = (int) ($_POST['duration'] ?? 0);
        if (!in_array($duration, POLLS_DURATIONS, true)) polls_json(['success' => false, 'error' => t('polls_err_duration')]);
        $body = ['broadcaster_id' => (string) $broadcasterID, 'title' => $title, 'choices' => array_map(fn($c) => ['title' => $c], $choices), 'duration' => $duration];
        if (($_POST['channel_points'] ?? '') === '1') {
            $cost = (string) ($_POST['channel_points_per_vote'] ?? '');
            if (!preg_match('/^\d{1,7}$/', $cost) || (int) $cost < 1 || (int) $cost > 1000000) polls_json(['success' => false, 'error' => t('polls_err_cp')]);
            $body['channel_points_voting_enabled'] = true;
            $body['channel_points_per_vote'] = (int) $cost;
        }
        [$code, $data] = polls_helix('POST', 'https://api.twitch.tv/helix/polls', $body);
        if ($code === 401) polls_json(['success' => false, 'error' => t('polls_err_twitch_auth')], 401);
        if ($code !== 200 || empty($data['data'][0])) polls_json(['success' => false, 'error' => polls_twitch_error($code, $data)]);
        try { polls_save($db, $data['data'][0]); } catch (Throwable $e) { error_log('polls save: ' . $e->getMessage()); }
        polls_json(['success' => true, 'message' => t('polls_msg_started')]);
    }
    if ($action === 'end') {
        $pollId = (string) ($_POST['poll_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $pollId)) polls_json(['success' => false, 'error' => t('polls_err_not_found')]);
        [$code, $data] = polls_helix('PATCH', 'https://api.twitch.tv/helix/polls', ['broadcaster_id' => (string) $broadcasterID, 'id' => $pollId, 'status' => 'TERMINATED']);
        if ($code === 401) polls_json(['success' => false, 'error' => t('polls_err_twitch_auth')], 401);
        if ($code !== 200 || empty($data['data'][0])) polls_json(['success' => false, 'error' => $code === 400 ? t('polls_err_not_found') : polls_twitch_error($code, $data)]);
        try { polls_save($db, $data['data'][0]); } catch (Throwable $e) { error_log('polls save: ' . $e->getMessage()); }
        polls_json(['success' => true, 'message' => t('polls_msg_ended')]);
    }
    polls_json(['success' => false, 'error' => t('polls_unknown_action')]);
}

ob_start();
?>
<!-- Start a poll -->
<div class="sp-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-poll"></i> <?= t('polls_create_title') ?></span>
    </header>
    <div class="sp-card-body">
        <p class="sp-help" style="margin-bottom:1rem;"><?= t('polls_create_help') ?></p>
        <form id="pollForm" autocomplete="off">
            <div class="sp-form-group">
                <label class="sp-label" for="pollTitle"><?= t('polls_field_title') ?></label>
                <input class="sp-input" id="pollTitle" name="title" maxlength="60" required>
            </div>
            <div class="sp-form-group">
                <label class="sp-label"><?= t('polls_field_choices') ?></label>
                <div id="pollChoices" style="display:flex;flex-direction:column;gap:0.5rem;"></div>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="pollAddChoice" style="margin-top:0.5rem;"><i class="fas fa-plus"></i> <?= t('polls_add_choice') ?></button>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="pollDuration"><?= t('polls_field_duration') ?></label>
                <select class="sp-select" id="pollDuration" name="duration" style="max-width:200px;">
                    <?php foreach (POLLS_DURATIONS as $d): ?>
                        <option value="<?= $d ?>"<?= $d === 120 ? ' selected' : '' ?>><?= htmlspecialchars($d < 60 ? t('polls_dur_seconds', ['n' => $d]) : ($d === 60 ? t('polls_dur_minute') : t('polls_dur_minutes', ['n' => $d / 60]))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sp-form-group">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;color:var(--text-secondary);">
                    <input type="checkbox" id="pollCp" name="channel_points" value="1"> <?= t('polls_field_cp') ?>
                </label>
            </div>
            <div class="sp-form-group" id="pollCpCostGroup" style="display:none;">
                <label class="sp-label" for="pollCpCost"><?= t('polls_field_cp_cost') ?></label>
                <input class="sp-input" type="number" id="pollCpCost" name="channel_points_per_vote" min="1" max="1000000" value="100" style="max-width:160px;">
            </div>
            <button class="sp-btn sp-btn-primary" type="submit" id="pollSubmit"><i class="fas fa-play"></i> <?= t('polls_btn_start') ?></button>
        </form>
    </div>
</div>

<!-- Live poll -->
<div class="sp-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-broadcast-tower"></i> <?= t('polls_active_title') ?></span>
    </header>
    <div class="sp-card-body" id="pollActive">
        <span class="sp-skeleton-line w-60"></span>
    </div>
</div>

<!-- History -->
<div class="sp-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-history"></i> <?= t('polls_history_title') ?></span>
    </header>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?= t('polls_col_poll') ?></th>
                        <th><?= t('polls_col_results') ?></th>
                        <th><?= t('polls_col_points') ?></th>
                        <th><?= t('polls_col_started') ?></th>
                        <th><?= t('polls_col_ended') ?></th>
                    </tr>
                </thead>
                <tbody id="pollsTableBody" aria-busy="true">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <tr>
                        <td><span class="sp-skeleton-line w-70"></span></td>
                        <td><span class="sp-skeleton-line w-60"></span></td>
                        <td><span class="sp-skeleton-line w-50"></span></td>
                        <td><span class="sp-skeleton-line w-50"></span></td>
                        <td><span class="sp-skeleton-line w-50"></span></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const POLL_I18N = {
    choice: <?php echo json_encode(t('polls_choice_placeholder')); ?>,
    remove: <?php echo json_encode(t('polls_remove_choice')); ?>,
    none: <?php echo json_encode(t('polls_active_none')); ?>,
    end: <?php echo json_encode(t('polls_btn_end')); ?>,
    confirmEnd: <?php echo json_encode(t('polls_confirm_end')); ?>,
    endsAt: <?php echo json_encode(t('polls_ends_at')); ?>,
    votes: <?php echo json_encode(t('polls_votes')); ?>,
    live: <?php echo json_encode(t('polls_status_live')); ?>,
    empty: <?php echo json_encode(t('polls_history_empty')); ?>,
    failed: <?php echo json_encode(t('polls_err_load')); ?>
};
const POLL_MAX_CHOICES = 5;

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function pollTime(iso) {
    return iso ? new Date(iso).toLocaleString() : '-';
}

function pollResultsHtml(choices) {
    var total = choices.reduce(function(s, c) { return s + Number(c.votes || 0); }, 0);
    return choices.map(function(c) {
        var pct = total ? Math.round(Number(c.votes || 0) * 100 / total) : 0;
        return '<div style="display:flex;justify-content:space-between;gap:1rem;"><span>' + escapeHtml(c.title) + '</span>' +
            '<span style="color:var(--text-muted);white-space:nowrap;">' + escapeHtml(c.votes) + ' ' + escapeHtml(POLL_I18N.votes) + ' (' + pct + '%)</span></div>';
    }).join('');
}

function addChoiceRow(value) {
    var box = document.getElementById('pollChoices');
    if (box.children.length >= POLL_MAX_CHOICES) return;
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:0.5rem;align-items:center;';
    row.innerHTML = '<input class="sp-input" name="choices[]" maxlength="25">' +
        '<button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" aria-label="' + escapeHtml(POLL_I18N.remove) + '"><i class="fas fa-times"></i></button>';
    row.querySelector('input').value = value || '';
    row.querySelector('button').addEventListener('click', function() {
        if (box.children.length > 2) { row.remove(); renumberChoices(); }
    });
    box.appendChild(row);
    renumberChoices();
}

function renumberChoices() {
    var rows = document.getElementById('pollChoices').children;
    for (var i = 0; i < rows.length; i++) {
        rows[i].querySelector('input').placeholder = POLL_I18N.choice.replace(':n', i + 1);
        rows[i].querySelector('button').disabled = rows.length <= 2;
    }
    document.getElementById('pollAddChoice').disabled = rows.length >= POLL_MAX_CHOICES;
}

function pollPost(body) {
    return fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: body
    }).then(function(r) { return r.json(); });
}

function renderActive(active) {
    var box = document.getElementById('pollActive');
    if (!active) {
        box.innerHTML = '<p style="color:var(--text-muted);">' + escapeHtml(POLL_I18N.none) + '</p>';
        return;
    }
    box.innerHTML = '<p style="font-weight:700;margin-bottom:0.5rem;">' + escapeHtml(active.title) + '</p>' +
        pollResultsHtml(active.choices) +
        '<p class="sp-help" style="margin:0.75rem 0;">' + escapeHtml(POLL_I18N.endsAt) + ' ' + escapeHtml(pollTime(active.ends_at)) + '</p>' +
        '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm" id="pollEnd"><i class="fas fa-stop"></i> ' + escapeHtml(POLL_I18N.end) + '</button>';
    document.getElementById('pollEnd').addEventListener('click', function() {
        if (!confirm(POLL_I18N.confirmEnd)) return;
        var fd = new FormData();
        fd.append('action', 'end');
        fd.append('poll_id', active.id);
        this.disabled = true;
        pollPost(fd).then(function(data) {
            Swal.fire({ icon: data.success ? 'success' : 'error', text: data.success ? data.message : data.error });
            loadPolls();
        }).catch(function() { Swal.fire({ icon: 'error', text: POLL_I18N.failed }); });
    });
}

function renderHistory(polls, activeId) {
    var tbody = document.getElementById('pollsTableBody');
    tbody.setAttribute('aria-busy', 'false');
    if (!polls.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:var(--text-muted);text-align:center;">' + escapeHtml(POLL_I18N.empty) + '</td></tr>';
        return;
    }
    tbody.innerHTML = polls.map(function(p) {
        var ended = p.ended_at ? escapeHtml(pollTime(p.ended_at))
            : (p.id === activeId ? '<span class="sp-badge sp-badge-green">' + escapeHtml(POLL_I18N.live) + '</span>' : '-');
        return '<tr>' +
            '<td>' + escapeHtml(p.title) + '</td>' +
            '<td style="min-width:220px;">' + pollResultsHtml(p.choices) + '</td>' +
            '<td>' + escapeHtml(p.channel_points_used) + '</td>' +
            '<td>' + escapeHtml(pollTime(p.started_at)) + '</td>' +
            '<td>' + ended + '</td>' +
            '</tr>';
    }).join('');
}

function loadPolls() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) throw new Error((data && data.error) || POLL_I18N.failed);
            if (data.twitch_error) {
                document.getElementById('pollActive').innerHTML = '<div class="sp-alert sp-alert-danger">' + escapeHtml(data.twitch_error) + '</div>';
            } else {
                renderActive(data.active);
            }
            renderHistory(Array.isArray(data.polls) ? data.polls : [], data.active ? data.active.id : null);
        })
        .catch(function(e) {
            document.getElementById('pollActive').innerHTML = '<div class="sp-alert sp-alert-danger">' + escapeHtml(e.message || POLL_I18N.failed) + '</div>';
            document.getElementById('pollsTableBody').setAttribute('aria-busy', 'false');
        });
}

document.getElementById('pollCp').addEventListener('change', function() {
    document.getElementById('pollCpCostGroup').style.display = this.checked ? 'block' : 'none';
});
document.getElementById('pollAddChoice').addEventListener('click', function() { addChoiceRow(''); });
document.getElementById('pollForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('action', 'create');
    var btn = document.getElementById('pollSubmit');
    btn.disabled = true;
    pollPost(fd).then(function(data) {
        Swal.fire({ icon: data.success ? 'success' : 'error', text: data.success ? data.message : data.error });
        if (data.success) {
            document.getElementById('pollTitle').value = '';
            document.getElementById('pollChoices').innerHTML = '';
            addChoiceRow(''); addChoiceRow('');
            loadPolls();
        }
    }).catch(function() {
        Swal.fire({ icon: 'error', text: POLL_I18N.failed });
    }).finally(function() { btn.disabled = false; });
});

addChoiceRow(''); addChoiceRow('');
loadPolls();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
