<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_user_schema_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
require_once __DIR__ . '/../includes/usr_schema.php';
session_write_close();

function usr_schema_admin_json($success, $message, $extra = [])
{
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function usr_schema_admin_resolve_user(mysqli $conn, $username)
{
    $username = trim((string) $username);
    if (!usr_schema_valid_dbname($username)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $target = trim((string) ($_POST['username'] ?? ''));
    $userRow = usr_schema_admin_resolve_user($conn, $target);
    if (!$userRow) {
        usr_schema_admin_json(false, t('admin_user_schema_err_unknown_user'));
    }
    $target = $userRow['username'];
    try {
        if ($action === 'check') {
            $result = usr_schema_inspect($target);
            if (empty($result['ok'])) {
                admin_audit_log('user_schema_check', 'failed', ['error' => $result['error'] ?? ''], 'user_db', $target);
                usr_schema_admin_json(false, $result['error'] ?? t('admin_user_schema_err_check'), $result);
            }
            admin_audit_log('user_schema_check', 'success', [
                'pending' => count($result['pending'] ?? []),
                'current' => !empty($result['current']),
            ], 'user_db', $target);
            usr_schema_admin_json(true, t('admin_user_schema_msg_checked'), $result);
        }
        if ($action === 'apply') {
            @set_time_limit(180);
            $logs = usr_schema_apply_for_user($target);
            $after = usr_schema_inspect($target);
            $errors = 0;
            foreach ($logs as $entry) {
                if (($entry['level'] ?? '') === 'error') {
                    $errors++;
                }
            }
            admin_audit_log('user_schema_apply', $errors ? 'warning' : 'success', [
                'log_count' => count($logs),
                'errors' => $errors,
                'pending_after' => count($after['pending'] ?? []),
            ], 'user_db', $target);
            usr_schema_admin_json(true, t('admin_user_schema_msg_applied'), [
                'logs' => $logs,
                'errors' => $errors,
                'inspect' => $after,
            ]);
        }
        usr_schema_admin_json(false, t('admin_user_schema_err_unknown_action'));
    } catch (Throwable $e) {
        admin_audit_log('user_schema_' . $action, 'failed', ['error' => $e->getMessage()], 'user_db', $target);
        usr_schema_admin_json(false, $e->getMessage());
    }
}

$users = [];
$userStmt = $conn->prepare("SELECT id, username FROM users ORDER BY id ASC");
if ($userStmt) {
    $userStmt->execute();
    $userRes = $userStmt->get_result();
    while ($row = $userRes->fetch_assoc()) {
        $users[] = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
        ];
    }
    $userStmt->close();
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-database"></i> <?php echo t('admin_user_schema_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p class="usr-schema-intro"><?php echo t('admin_user_schema_intro'); ?></p>
        <div class="usr-schema-toolbar">
            <div class="sp-form-group usr-schema-field">
                <label class="sp-label" for="usr-schema-search"><?php echo t('admin_user_schema_user_label'); ?></label>
                <div class="search-wrapper">
                    <span class="search-icon"><i class="fas fa-search"></i></span>
                    <input id="usr-schema-search" class="search-input" type="text" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('admin_user_schema_search_placeholder')); ?>">
                    <button type="button" class="search-clear" id="usr-schema-search-clear" hidden><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="usr-schema-actions">
                <button type="button" class="sp-btn sp-btn-primary" id="usr-schema-check"><i class="fas fa-stethoscope"></i> <?php echo t('admin_user_schema_check'); ?></button>
                <button type="button" class="sp-btn sp-btn-success" id="usr-schema-apply" disabled><i class="fas fa-play"></i> <?php echo t('admin_user_schema_apply'); ?></button>
                <button type="button" class="sp-btn sp-btn-secondary" id="usr-schema-scan"><i class="fas fa-list-check"></i> <?php echo t('admin_user_schema_scan_all'); ?></button>
            </div>
        </div>
        <ul class="usr-schema-user-list" id="usr-schema-user-list" hidden></ul>
    </div>
</div>

<div class="sp-card" id="usr-schema-result" hidden>
    <div class="sp-card-header">
        <h2 class="sp-card-title" id="usr-schema-result-title"></h2>
    </div>
    <div class="sp-card-body">
        <p class="usr-schema-summary" id="usr-schema-summary"></p>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_user_schema_th_kind'); ?></th>
                        <th><?php echo t('admin_user_schema_th_target'); ?></th>
                        <th><?php echo t('admin_user_schema_th_change'); ?></th>
                    </tr>
                </thead>
                <tbody id="usr-schema-pending-body"></tbody>
            </table>
        </div>
        <details class="usr-schema-skipped" id="usr-schema-skipped" hidden>
            <summary id="usr-schema-skipped-summary"></summary>
            <ul id="usr-schema-skipped-list"></ul>
        </details>
        <details class="usr-schema-logs" id="usr-schema-logs" hidden>
            <summary><?php echo t('admin_user_schema_apply_log'); ?></summary>
            <pre class="mig-sql usr-schema-log" id="usr-schema-log-body"></pre>
        </details>
    </div>
</div>

<div class="sp-card" id="usr-schema-fleet">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_user_schema_fleet_title'); ?></h2>
        <span class="usr-schema-scan-progress" id="usr-schema-scan-progress"></span>
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_user_schema_th_user'); ?></th>
                        <th><?php echo t('admin_user_schema_th_status'); ?></th>
                        <th><?php echo t('admin_user_schema_th_pending'); ?></th>
                        <th><?php echo t('admin_user_schema_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="usr-schema-fleet-body"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const USERS = <?php echo json_encode($users, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const I18N = {
        errorTitle: <?php echo json_encode(t('admin_user_schema_js_error_title')); ?>,
        pickUser: <?php echo json_encode(t('admin_user_schema_err_pick_user')); ?>,
        current: <?php echo json_encode(t('admin_user_schema_status_current')); ?>,
        pending: <?php echo json_encode(t('admin_user_schema_status_pending')); ?>,
        missingDb: <?php echo json_encode(t('admin_user_schema_status_missing_db')); ?>,
        failed: <?php echo json_encode(t('admin_user_schema_status_failed')); ?>,
        summary: <?php echo json_encode(t('admin_user_schema_summary')); ?>,
        noPending: <?php echo json_encode(t('admin_user_schema_no_pending')); ?>,
        skipped: <?php echo json_encode(t('admin_user_schema_skipped')); ?>,
        resultTitle: <?php echo json_encode(t('admin_user_schema_result_title')); ?>,
        applyConfirmTitle: <?php echo json_encode(t('admin_user_schema_js_apply_confirm_title')); ?>,
        applyConfirmText: <?php echo json_encode(t('admin_user_schema_js_apply_confirm_text')); ?>,
        applyDestructiveText: <?php echo json_encode(t('admin_user_schema_js_apply_destructive_text')); ?>,
        confirmBtn: <?php echo json_encode(t('admin_user_schema_js_confirm_btn')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_user_schema_js_cancel_btn')); ?>,
        scanProgress: <?php echo json_encode(t('admin_user_schema_scan_progress')); ?>,
        open: <?php echo json_encode(t('admin_user_schema_open')); ?>,
        kinds: {
            create_database: <?php echo json_encode(t('admin_user_schema_kind_create_database')); ?>,
            create_table: <?php echo json_encode(t('admin_user_schema_kind_create_table')); ?>,
            create_tables: <?php echo json_encode(t('admin_user_schema_kind_create_tables')); ?>,
            add_column: <?php echo json_encode(t('admin_user_schema_kind_add_column')); ?>,
            drop_column: <?php echo json_encode(t('admin_user_schema_kind_drop_column')); ?>,
            drop_table: <?php echo json_encode(t('admin_user_schema_kind_drop_table')); ?>,
            rename_table: <?php echo json_encode(t('admin_user_schema_kind_rename_table')); ?>,
            add_index: <?php echo json_encode(t('admin_user_schema_kind_add_index')); ?>,
            modify_column: <?php echo json_encode(t('admin_user_schema_kind_modify_column')); ?>,
            drop_primary_key: <?php echo json_encode(t('admin_user_schema_kind_drop_primary_key')); ?>
        }
    };

    const search = document.getElementById('usr-schema-search');
    const clearBtn = document.getElementById('usr-schema-search-clear');
    const list = document.getElementById('usr-schema-user-list');
    const checkBtn = document.getElementById('usr-schema-check');
    const applyBtn = document.getElementById('usr-schema-apply');
    const scanBtn = document.getElementById('usr-schema-scan');
    const resultCard = document.getElementById('usr-schema-result');
    const resultTitle = document.getElementById('usr-schema-result-title');
    const summary = document.getElementById('usr-schema-summary');
    const pendingBody = document.getElementById('usr-schema-pending-body');
    const skippedWrap = document.getElementById('usr-schema-skipped');
    const skippedSummary = document.getElementById('usr-schema-skipped-summary');
    const skippedList = document.getElementById('usr-schema-skipped-list');
    const logsWrap = document.getElementById('usr-schema-logs');
    const logBody = document.getElementById('usr-schema-log-body');
    const fleetBody = document.getElementById('usr-schema-fleet-body');
    const scanProgress = document.getElementById('usr-schema-scan-progress');

    let selectedUser = '';
    let lastInspect = null;

    function fail(msg) {
        Swal.fire({ icon: 'error', title: I18N.errorTitle, text: msg || I18N.errorTitle });
    }

    async function post(fields) {
        const fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        const res = await fetch('user_schema.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        return res.json();
    }

    function kindLabel(kind) {
        return I18N.kinds[kind] || kind;
    }

    function pillClass(change) {
        if (change.destructive) return 'mig-pill mig-destructive';
        if (change.kind === 'create_database' || change.kind === 'create_table' || change.kind === 'create_tables') return 'mig-pill mig-pending';
        return 'mig-pill mig-applied';
    }

    function renderList(filter) {
        const q = (filter || '').toLowerCase();
        const matches = USERS.filter(function (u) {
            return !q || u.username.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 40);
        list.innerHTML = '';
        matches.forEach(function (u) {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'usr-schema-user-item' + (u.username === selectedUser ? ' is-selected' : '');
            btn.textContent = u.username;
            btn.addEventListener('click', function () {
                selectedUser = u.username;
                search.value = u.username;
                lastInspect = null;
                applyBtn.disabled = true;
                list.hidden = true;
                clearBtn.hidden = false;
            });
            li.appendChild(btn);
            list.appendChild(li);
        });
        list.hidden = matches.length === 0 || (matches.length === 1 && matches[0].username === search.value);
    }

    function renderInspect(username, data, logs) {
        lastInspect = data;
        resultCard.hidden = false;
        resultTitle.textContent = I18N.resultTitle.replace('%s', username);
        const pending = data.pending || [];
        const skipped = data.skipped || [];
        const current = !!data.current;
        summary.textContent = current
            ? I18N.noPending
            : I18N.summary.replace('%d', String(pending.length)).replace('%s', String(data.existing_tables || 0)).replace('%t', String(data.expected_tables || 0));
        pendingBody.innerHTML = '';
        if (!pending.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="3">' + I18N.noPending + '</td>';
            pendingBody.appendChild(tr);
        } else {
            pending.forEach(function (change) {
                const tr = document.createElement('tr');
                const target = [change.table, change.column].filter(Boolean).join('.') || '—';
                tr.innerHTML = '<td><span class="' + pillClass(change) + '">' + escapeHtml(kindLabel(change.kind)) + '</span></td>'
                    + '<td><code>' + escapeHtml(target) + '</code></td>'
                    + '<td>' + escapeHtml(change.message) + '</td>';
                pendingBody.appendChild(tr);
                if (change.sql) {
                    const sqlRow = document.createElement('tr');
                    sqlRow.innerHTML = '<td colspan="3"><pre class="mig-sql">' + escapeHtml(change.sql) + '</pre></td>';
                    pendingBody.appendChild(sqlRow);
                }
            });
        }
        if (skipped.length) {
            skippedWrap.hidden = false;
            skippedSummary.textContent = I18N.skipped.replace('%d', String(skipped.length));
            skippedList.innerHTML = skipped.map(function (s) { return '<li>' + escapeHtml(s.message) + '</li>'; }).join('');
        } else {
            skippedWrap.hidden = true;
        }
        if (logs && logs.length) {
            logsWrap.hidden = false;
            logBody.textContent = logs.map(function (entry) {
                const level = entry.level === 'error' ? 'ERROR' : 'LOG';
                return '[' + level + '] ' + (entry.message || '');
            }).join('\n');
        } else {
            logsWrap.hidden = true;
            logBody.textContent = '';
        }
        applyBtn.disabled = current;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function statusLabel(data) {
        if (!data || data.ok === false) return I18N.failed;
        if (!data.db_exists) return I18N.missingDb;
        if (data.current) return I18N.current;
        return I18N.pending;
    }

    async function checkUser(username, silent) {
        const data = await post({ action: 'check', username: username });
        if (!data.success) {
            if (!silent) fail(data.message);
            return data;
        }
        if (!silent) renderInspect(username, data);
        return data;
    }

    search.addEventListener('input', function () {
        selectedUser = USERS.some(function (u) { return u.username === search.value; }) ? search.value : '';
        applyBtn.disabled = true;
        lastInspect = null;
        clearBtn.hidden = this.value === '';
        renderList(this.value);
    });
    search.addEventListener('focus', function () { renderList(this.value); });
    clearBtn.addEventListener('click', function () {
        search.value = '';
        selectedUser = '';
        lastInspect = null;
        applyBtn.disabled = true;
        clearBtn.hidden = true;
        list.hidden = true;
    });

    checkBtn.addEventListener('click', async function () {
        const username = selectedUser || search.value.trim();
        if (!username) return fail(I18N.pickUser);
        selectedUser = username;
        checkBtn.disabled = true;
        try {
            const data = await checkUser(username, false);
            if (!data.success) fail(data.message);
        } catch (e) {
            fail(e.message);
        } finally {
            checkBtn.disabled = false;
        }
    });

    applyBtn.addEventListener('click', async function () {
        const username = selectedUser || search.value.trim();
        if (!username) return fail(I18N.pickUser);
        const pending = (lastInspect && lastInspect.pending) ? lastInspect.pending : [];
        const destructive = pending.some(function (c) { return c.destructive; });
        const confirm = await Swal.fire({
            icon: 'warning',
            title: I18N.applyConfirmTitle,
            text: (destructive ? I18N.applyDestructiveText : I18N.applyConfirmText).replace('%s', username),
            showCancelButton: true,
            confirmButtonText: I18N.confirmBtn,
            cancelButtonText: I18N.cancelBtn,
            confirmButtonColor: destructive ? '#f14668' : undefined
        });
        if (!confirm.isConfirmed) return;
        applyBtn.disabled = true;
        try {
            const data = await post({ action: 'apply', username: username });
            if (!data.success) return fail(data.message);
            renderInspect(username, data.inspect || {}, data.logs || []);
        } catch (e) {
            fail(e.message);
        }
    });

    fleetBody.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-open-user]');
        if (!btn) return;
        const username = btn.getAttribute('data-open-user');
        selectedUser = username;
        search.value = username;
        clearBtn.hidden = false;
        await checkUser(username, false);
        resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    scanBtn.addEventListener('click', async function () {
        scanBtn.disabled = true;
        fleetBody.innerHTML = '';
        USERS.forEach(function (user) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-user-id', String(user.id));
            tr.innerHTML = '<td><code>' + escapeHtml(user.username) + '</code></td>'
                + '<td><span class="mig-pill mig-pending">…</span></td>'
                + '<td>—</td>'
                + '<td></td>';
            fleetBody.appendChild(tr);
        });
        let done = 0;
        const queue = USERS.slice();
        async function worker() {
            while (queue.length) {
                const user = queue.shift();
                let data;
                try {
                    data = await checkUser(user.username, true);
                } catch (e) {
                    data = { success: false, message: e.message };
                }
                done += 1;
                scanProgress.textContent = I18N.scanProgress.replace('%d', String(done)).replace('%t', String(USERS.length));
                const tr = fleetBody.querySelector('tr[data-user-id="' + user.id + '"]');
                if (!tr) continue;
                const pendingCount = (data.pending && data.pending.length) || 0;
                const ok = !!data.success && data.ok !== false;
                const label = ok ? statusLabel(data) : I18N.failed;
                tr.innerHTML = '<td><code>' + escapeHtml(user.username) + '</code></td>'
                    + '<td><span class="mig-pill ' + (ok && data.current ? 'mig-applied' : 'mig-pending') + '">' + escapeHtml(label) + '</span></td>'
                    + '<td>' + (ok ? pendingCount : '—') + '</td>'
                    + '<td><button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" data-open-user="' + escapeHtml(user.username) + '">' + escapeHtml(I18N.open) + '</button></td>';
            }
        }
        await Promise.all([worker(), worker(), worker()]);
        scanBtn.disabled = false;
    });
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
