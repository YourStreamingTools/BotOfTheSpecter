<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_known_bots_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
session_write_close();

function kb_json($success, $message, $extra = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Add a bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bot'])) {
    $login = strtolower(ltrim(trim($_POST['bot_login'] ?? ''), '@'));
    $notes = trim($_POST['notes'] ?? '');
    if ($notes === '') { $notes = null; }
    if ($login === '') {
        kb_json(false, t('admin_known_bots_error_login_empty'));
    }
    if (!preg_match('/^[a-z0-9_]{1,25}$/', $login)) {
        kb_json(false, t('admin_known_bots_error_login_invalid'));
    }
    try {
        $stmt = $conn->prepare("INSERT INTO known_bots (bot_login, added_by, notes) VALUES (?, ?, ?)");
        $addedBy = isset($username) ? $username : 'admin';
        $stmt->bind_param("sss", $login, $addedBy, $notes);
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();
            admin_audit_log('known_bot_add', 'success', ['login' => $login, 'notes' => $notes], 'known_bot', $login);
            kb_json(true, t('admin_known_bots_msg_added'), ['id' => $newId, 'login' => $login, 'added_by' => $addedBy]);
        } else {
            $err = $stmt->errno;
            $stmt->close();
            if ($err === 1062) { // duplicate key
                kb_json(false, t('admin_known_bots_error_duplicate'));
            }
            kb_json(false, t('admin_known_bots_error_generic', [(string) $err]));
        }
    } catch (Exception $e) {
        kb_json(false, t('admin_known_bots_error_generic', [$e->getMessage()]));
    }
}

// Toggle active state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bot'])) {
    $id = intval($_POST['id'] ?? 0);
    $active = (intval($_POST['is_active'] ?? 0) === 1) ? 1 : 0;
    if ($id <= 0) {
        kb_json(false, t('admin_known_bots_error_not_found'));
    }
    try {
        $stmt = $conn->prepare("UPDATE known_bots SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $active, $id);
        $ok = $stmt->execute() && $stmt->affected_rows >= 0;
        $stmt->close();
        if ($ok) {
            admin_audit_log('known_bot_toggle', 'success', ['id' => $id, 'is_active' => $active], 'known_bot', (string) $id);
            kb_json(true, t('admin_known_bots_msg_toggled'), ['id' => $id, 'is_active' => $active]);
        }
        kb_json(false, t('admin_known_bots_error_not_found'));
    } catch (Exception $e) {
        kb_json(false, t('admin_known_bots_error_generic', [$e->getMessage()]));
    }
}

// Delete a bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bot'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        kb_json(false, t('admin_known_bots_error_not_found'));
    }
    try {
        $stmt = $conn->prepare("DELETE FROM known_bots WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            admin_audit_log('known_bot_delete', 'success', ['id' => $id], 'known_bot', (string) $id);
            kb_json(true, t('admin_known_bots_msg_deleted'), ['id' => $id]);
        }
        $stmt->close();
        kb_json(false, t('admin_known_bots_error_not_found'));
    } catch (Exception $e) {
        kb_json(false, t('admin_known_bots_error_generic', [$e->getMessage()]));
    }
}

// Fetch all known bots for display
$known_bots = [];
if ($conn) {
    $result = $conn->query("SELECT id, bot_login, is_active, added_by, created_at FROM known_bots ORDER BY bot_login");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $known_bots[] = $row;
        }
        $result->free();
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-robot"></i> <?php echo t('admin_known_bots_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p style="color:var(--text-secondary);"><?php echo t('admin_known_bots_intro'); ?></p>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_known_bots_add_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <form id="addBotForm">
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_known_bots_login_label'); ?></label>
            <input class="sp-input" type="text" name="bot_login" placeholder="<?php echo htmlspecialchars(t('admin_known_bots_login_placeholder')); ?>" required>
            <p class="sp-help"><?php echo t('admin_known_bots_login_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_known_bots_notes_label'); ?></label>
            <input class="sp-input" type="text" name="notes" placeholder="<?php echo htmlspecialchars(t('admin_known_bots_notes_placeholder')); ?>">
        </div>
        <div class="sp-form-group">
            <button type="submit" class="sp-btn sp-btn-primary">
                <span class="icon"><i class="fas fa-plus"></i></span>
                <span><?php echo t('admin_known_bots_add_button'); ?></span>
            </button>
        </div>
    </form>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_known_bots_existing_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <?php if (empty($known_bots)): ?>
        <div class="sp-alert sp-alert-info" id="knownBotsEmpty"><?php echo t('admin_known_bots_empty_state'); ?></div>
    <?php endif; ?>
        <div class="sp-table-wrap"<?php if (empty($known_bots)) echo ' style="display:none;"'; ?> id="knownBotsWrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_known_bots_th_login'); ?></th>
                        <th><?php echo t('admin_known_bots_th_status'); ?></th>
                        <th><?php echo t('admin_known_bots_th_added_by'); ?></th>
                        <th><?php echo t('admin_known_bots_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="knownBotsTable">
                    <?php foreach ($known_bots as $b): ?>
                        <tr data-id="<?php echo (int) $b['id']; ?>" data-login="<?php echo htmlspecialchars($b['bot_login']); ?>">
                            <td><strong><?php echo htmlspecialchars($b['bot_login']); ?></strong></td>
                            <td>
                                <span class="kb-status <?php echo $b['is_active'] ? 'kb-status-active' : 'kb-status-disabled'; ?>">
                                    <?php echo $b['is_active'] ? t('admin_known_bots_status_active') : t('admin_known_bots_status_disabled'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($b['added_by'] ?? ''); ?></td>
                            <td>
                                <div class="sp-btn-group">
                                    <button class="sp-btn sp-btn-warning sp-btn-sm toggle-bot" data-id="<?php echo (int) $b['id']; ?>" data-active="<?php echo (int) $b['is_active']; ?>">
                                        <span class="icon"><i class="fas <?php echo $b['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></span>
                                        <span><?php echo $b['is_active'] ? t('admin_known_bots_disable_button') : t('admin_known_bots_enable_button'); ?></span>
                                    </button>
                                    <button class="sp-btn sp-btn-danger sp-btn-sm delete-bot" data-id="<?php echo (int) $b['id']; ?>">
                                        <span class="icon"><i class="fas fa-trash"></i></span>
                                        <span><?php echo t('admin_known_bots_delete_button'); ?></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const I18N = {
        errorTitle: <?php echo json_encode(t('admin_known_bots_js_error_title')); ?>,
        deleteConfirmTitle: <?php echo json_encode(t('admin_known_bots_js_delete_confirm_title')); ?>,
        deleteConfirmText: <?php echo json_encode(t('admin_known_bots_js_delete_confirm_text')); ?>,
        deleteConfirmBtn: <?php echo json_encode(t('admin_known_bots_js_delete_confirm_btn')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_known_bots_js_cancel_btn')); ?>,
        deletedTitle: <?php echo json_encode(t('admin_known_bots_js_deleted_title')); ?>,
        addError: <?php echo json_encode(t('admin_known_bots_js_add_error')); ?>,
        deleteError: <?php echo json_encode(t('admin_known_bots_js_delete_error')); ?>,
        toggleError: <?php echo json_encode(t('admin_known_bots_js_toggle_error')); ?>,
        statusActive: <?php echo json_encode(t('admin_known_bots_status_active')); ?>,
        statusDisabled: <?php echo json_encode(t('admin_known_bots_status_disabled')); ?>,
        enableBtn: <?php echo json_encode(t('admin_known_bots_enable_button')); ?>,
        disableBtn: <?php echo json_encode(t('admin_known_bots_disable_button')); ?>,
        addedByYou: <?php echo json_encode($username ?? 'admin'); ?>
    };
    function esc(text) { const d = document.createElement('div'); d.textContent = text == null ? '' : text; return d.innerHTML; }
    const tableBody = document.getElementById('knownBotsTable');
    const wrap = document.getElementById('knownBotsWrap');
    const empty = document.getElementById('knownBotsEmpty');

    function showTable() {
        if (wrap) wrap.style.display = '';
        if (empty) empty.style.display = 'none';
    }

    function buildRow(id, login, addedBy) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', id);
        tr.setAttribute('data-login', login);
        tr.innerHTML =
            '<td><strong>' + esc(login) + '</strong></td>' +
            '<td><span class="kb-status kb-status-active">' + esc(I18N.statusActive) + '</span></td>' +
            '<td>' + esc(addedBy) + '</td>' +
            '<td><div class="sp-btn-group">' +
                '<button class="sp-btn sp-btn-warning sp-btn-sm toggle-bot" data-id="' + id + '" data-active="1">' +
                    '<span class="icon"><i class="fas fa-toggle-off"></i></span><span>' + esc(I18N.disableBtn) + '</span></button>' +
                '<button class="sp-btn sp-btn-danger sp-btn-sm delete-bot" data-id="' + id + '">' +
                    '<span class="icon"><i class="fas fa-trash"></i></span><span>' + esc(<?php echo json_encode(t('admin_known_bots_delete_button')); ?>) + '</span></button>' +
            '</div></td>';
        attachRow(tr);
        return tr;
    }

    async function handleToggle() {
        const id = this.dataset.id;
        const active = parseInt(this.dataset.active, 10) === 1 ? 0 : 1; // flip
        const fd = new FormData();
        fd.append('toggle_bot', '1');
        fd.append('id', id);
        fd.append('is_active', String(active));
        try {
            const res = await fetch('known_bots.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const row = tableBody.querySelector('tr[data-id="' + id + '"]');
                if (row) {
                    this.dataset.active = String(active);
                    const badge = row.querySelector('.kb-status');
                    badge.className = 'kb-status ' + (active ? 'kb-status-active' : 'kb-status-disabled');
                    badge.textContent = active ? I18N.statusActive : I18N.statusDisabled;
                    const label = this.querySelector('span:last-child');
                    label.textContent = active ? I18N.disableBtn : I18N.enableBtn;
                    const icon = this.querySelector('i');
                    icon.className = 'fas ' + (active ? 'fa-toggle-off' : 'fa-toggle-on');
                }
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.toggleError });
        }
    }

    async function handleDelete() {
        const id = this.dataset.id;
        const row = tableBody.querySelector('tr[data-id="' + id + '"]');
        const login = row ? row.getAttribute('data-login') : '';
        const confirm = await Swal.fire({
            icon: 'warning',
            title: I18N.deleteConfirmTitle,
            text: I18N.deleteConfirmText.replace('%s', login),
            showCancelButton: true,
            confirmButtonText: I18N.deleteConfirmBtn,
            cancelButtonText: I18N.cancelBtn,
            confirmButtonColor: '#f14668',
            cancelButtonColor: '#3085d6'
        });
        if (!confirm.isConfirmed) return;
        const fd = new FormData();
        fd.append('delete_bot', '1');
        fd.append('id', id);
        try {
            const res = await fetch('known_bots.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                if (row) row.remove();
                Swal.fire({ icon: 'success', title: I18N.deletedTitle, text: data.message, timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.deleteError });
        }
    }

    function attachRow(row) {
        const t = row.querySelector('.toggle-bot');
        if (t) t.addEventListener('click', handleToggle);
        const d = row.querySelector('.delete-bot');
        if (d) d.addEventListener('click', handleDelete);
    }

    tableBody && tableBody.querySelectorAll('tr').forEach(attachRow);

    const form = document.getElementById('addBotForm');
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('add_bot', '1');
        fd.append('bot_login', form.querySelector('[name="bot_login"]').value);
        fd.append('notes', form.querySelector('[name="notes"]').value);
        try {
            const res = await fetch('known_bots.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showTable();
                tableBody.appendChild(buildRow(data.id, data.login, data.added_by || I18N.addedByYou));
                form.reset();
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.addError });
        }
    });
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
