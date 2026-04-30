<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
session_write_close();
$pageTitle = 'Beta Programs';
ob_start();

// ----------------------------------------------------------------
// AJAX / POST handlers
// ----------------------------------------------------------------

// Create or update program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_program'])) {
    $editId = (int)($_POST['edit_id'] ?? 0);
    $slug   = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['slug'] ?? '')));
    $name   = trim($_POST['name'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $errors = [];
    if ($editId === 0 && strlen($slug) < 2) $errors[] = 'Slug must be at least 2 characters.';
    if (strlen($name) < 2) $errors[] = 'Name is required.';
    if ($errors) {
        echo json_encode(['success' => false, 'msg' => implode(' ', $errors)]);
        exit;
    }
    if ($editId > 0) {
        $stmt = $conn->prepare('UPDATE beta_programs SET name=?, description=? WHERE id=?');
        $stmt->bind_param('ssi', $name, $desc, $editId);
        $ok = $stmt->execute();
        $stmt->close();
        admin_audit_log('beta_program_update', 'success', ['id' => $editId, 'name' => $name], 'beta_program', (string)$editId);
        echo json_encode(['success' => $ok, 'msg' => $ok ? 'Program updated.' : 'Update failed.']);
    } else {
        $chk = $conn->prepare('SELECT id FROM beta_programs WHERE slug=?');
        $chk->bind_param('s', $slug);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();
        if ($exists) {
            echo json_encode(['success' => false, 'msg' => 'A program with that slug already exists.']);
        } else {
            $stmt = $conn->prepare('INSERT INTO beta_programs (slug, name, description) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $slug, $name, $desc);
            $ok = $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();
            admin_audit_log('beta_program_create', 'success', ['slug' => $slug, 'name' => $name], 'beta_program', $slug);
            echo json_encode(['success' => $ok, 'msg' => $ok ? "Program \"{$name}\" created." : 'Insert failed.', 'id' => $newId]);
        }
    }
    exit;
}

// Toggle active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_program'])) {
    $pid = (int)($_POST['program_id'] ?? 0);
    $ok  = $conn->query("UPDATE beta_programs SET is_active = NOT is_active WHERE id = {$pid}");
    $row = $conn->query("SELECT name, is_active FROM beta_programs WHERE id = {$pid}")->fetch_assoc();
    admin_audit_log('beta_program_toggle', 'success', ['id' => $pid, 'is_active' => $row['is_active'] ?? null], 'beta_program', (string)$pid);
    echo json_encode(['success' => (bool)$ok, 'msg' => 'Status updated.', 'is_active' => (int)($row['is_active'] ?? 0)]);
    exit;
}

// Delete program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_program'])) {
    $pid = (int)($_POST['program_id'] ?? 0);
    $row = $conn->query("SELECT slug, name FROM beta_programs WHERE id = {$pid}")->fetch_assoc();
    if (!$row) {
        echo json_encode(['success' => false, 'msg' => 'Program not found.']);
        exit;
    }
    // Check pending requests in support DB (direct connection using same credentials)
    $pendingCount = 0;
    $sdb = @new mysqli($servername, $username, $password, 'support_tickets');
    if (!$sdb->connect_error) {
        $esc  = $sdb->real_escape_string($row['slug']);
        $pres = $sdb->query("SELECT COUNT(*) AS cnt FROM tickets WHERE category='beta_request' AND JSON_EXTRACT(meta,'$.program')='{$esc}' AND status IN('open','in_progress')");
        if ($pres) $pendingCount = (int)$pres->fetch_assoc()['cnt'];
        $sdb->close();
    }
    if ($pendingCount > 0) {
        echo json_encode(['success' => false, 'msg' => "Cannot delete \"{$row['name']}\": {$pendingCount} pending request(s) still reference it. Resolve them first."]);
        exit;
    }
    $ok = $conn->query("DELETE FROM beta_programs WHERE id = {$pid}");
    admin_audit_log('beta_program_delete', 'success', ['slug' => $row['slug'], 'name' => $row['name']], 'beta_program', $row['slug']);
    echo json_encode(['success' => (bool)$ok, 'msg' => "Program \"{$row['name']}\" deleted."]);
    exit;
}

// Fetch all programs
$programs = [];
$res = $conn->query('SELECT * FROM beta_programs ORDER BY is_active DESC, name ASC');
if ($res) $programs = $res->fetch_all(MYSQLI_ASSOC);
?>

<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <h1 class="sp-card-title"><i class="fa-solid fa-flask"></i> Beta Programs</h1>
        <button class="sp-btn sp-btn-primary sp-btn-sm" onclick="openCreateProgram()">
            <i class="fa-solid fa-plus"></i> New Program
        </button>
    </div>
    <div class="sp-card-body">
        <p style="color:var(--text-secondary);margin-bottom:1.25rem;">
            Manage beta programs. Each program has a unique slug that gates access via the <code>beta_programs</code> JSON
            column on the <code>users</code> table. Users can request access through the
            <a href="https://support.botofthespecter.com/beta.php" target="_blank" rel="noopener">support portal</a>.
        </p>
        <?php if (empty($programs)): ?>
        <div class="sp-alert sp-alert-info"><p>No beta programs have been created yet.</p></div>
        <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table" id="bp-table">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $prog): ?>
                    <tr id="bp-row-<?php echo (int)$prog['id']; ?>">
                        <td><code><?php echo htmlspecialchars($prog['slug']); ?></code></td>
                        <td><?php echo htmlspecialchars($prog['name']); ?></td>
                        <td style="max-width:300px;word-wrap:break-word;"><?php echo nl2br(htmlspecialchars($prog['description'] ?? '')); ?></td>
                        <td>
                            <span class="sp-badge bp-status-<?php echo (int)$prog['id']; ?> <?php echo $prog['is_active'] ? 'sp-badge-green' : 'sp-badge-red'; ?>">
                                <?php echo $prog['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($prog['created_at']))); ?></td>
                        <td>
                            <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                                <button class="sp-btn sp-btn-sm" title="Edit"
                                    onclick="openEditProgram(<?php echo (int)$prog['id']; ?>, <?php echo htmlspecialchars(json_encode($prog['name']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($prog['description'] ?? ''), ENT_QUOTES); ?>)">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button class="sp-btn sp-btn-sm" title="Toggle active"
                                    onclick="toggleProgram(<?php echo (int)$prog['id']; ?>, this)">
                                    <i class="fa-solid <?php echo $prog['is_active'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                </button>
                                <button class="sp-btn sp-btn-danger sp-btn-sm" title="Delete"
                                    onclick="deleteProgram(<?php echo (int)$prog['id']; ?>, <?php echo htmlspecialchars(json_encode($prog['name']), ENT_QUOTES); ?>)">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create / Edit modal -->
<div id="bp-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;width:100%;max-width:520px;margin:1rem;padding:1.5rem;">
        <h2 id="bp-modal-title" style="margin-bottom:1rem;font-size:1.1rem;"></h2>
        <form id="bp-form">
            <input type="hidden" id="bp-edit-id" value="0">
            <div class="sp-form-group" id="bp-slug-group">
                <label class="sp-label" for="bp-slug">Slug <small style="color:var(--text-muted);">(lowercase, letters, numbers, hyphens, underscores)</small></label>
                <input type="text" id="bp-slug" class="sp-input" placeholder="e.g. streaming" pattern="[a-z0-9_-]+" minlength="2" maxlength="50">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="bp-name">Name</label>
                <input type="text" id="bp-name" class="sp-input" placeholder="e.g. Streaming Beta" maxlength="100" required>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="bp-desc">Description</label>
                <textarea id="bp-desc" class="sp-input" rows="3" placeholder="Optional description shown to users on the support portal" style="resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="sp-btn" onclick="closeBpModal()">Cancel</button>
                <button type="submit" class="sp-btn sp-btn-primary" id="bp-save-btn">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateProgram() {
    document.getElementById('bp-modal-title').textContent = 'Create Beta Program';
    document.getElementById('bp-edit-id').value = '0';
    document.getElementById('bp-slug').value = '';
    document.getElementById('bp-name').value = '';
    document.getElementById('bp-desc').value = '';
    document.getElementById('bp-slug-group').style.display = '';
    document.getElementById('bp-modal').style.display = 'flex';
}

function openEditProgram(id, name, desc) {
    document.getElementById('bp-modal-title').textContent = 'Edit Beta Program';
    document.getElementById('bp-edit-id').value = id;
    document.getElementById('bp-name').value = name;
    document.getElementById('bp-desc').value = desc;
    document.getElementById('bp-slug-group').style.display = 'none';
    document.getElementById('bp-modal').style.display = 'flex';
}

function closeBpModal() {
    document.getElementById('bp-modal').style.display = 'none';
}

document.getElementById('bp-modal').addEventListener('click', function(e) {
    if (e.target === this) closeBpModal();
});

document.getElementById('bp-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const editId = document.getElementById('bp-edit-id').value;
    const data = {
        save_program: '1',
        edit_id: editId,
        name: document.getElementById('bp-name').value.trim(),
        description: document.getElementById('bp-desc').value.trim(),
    };
    if (editId === '0') {
        data.slug = document.getElementById('bp-slug').value.trim();
    }
    const btn = document.getElementById('bp-save-btn');
    btn.disabled = true;
    $.post('', data, function(resp) {
        btn.disabled = false;
        let r = {};
        try { r = JSON.parse(resp); } catch {}
        if (r.success) {
            Swal.fire({ icon: 'success', title: 'Saved', text: r.msg, timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: r.msg || 'Something went wrong.' });
        }
    });
});

function toggleProgram(id, btn) {
    $.post('', { toggle_program: '1', program_id: id }, function(resp) {
        let r = {};
        try { r = JSON.parse(resp); } catch {}
        if (r.success) {
            const badge = document.querySelector('.bp-status-' + id);
            const icon  = btn.querySelector('i');
            if (r.is_active) {
                badge.textContent = 'Active';
                badge.className = 'sp-badge bp-status-' + id + ' sp-badge-green';
                icon.className = 'fa-solid fa-eye-slash';
            } else {
                badge.textContent = 'Inactive';
                badge.className = 'sp-badge bp-status-' + id + ' sp-badge-red';
                icon.className = 'fa-solid fa-eye';
            }
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: r.msg || 'Toggle failed.' });
        }
    });
}

function deleteProgram(id, name) {
    Swal.fire({
        title: 'Delete Program',
        html: `Delete <strong>${name}</strong>? This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete',
    }).then(result => {
        if (!result.isConfirmed) return;
        $.post('', { delete_program: '1', program_id: id }, function(resp) {
            let r = {};
            try { r = JSON.parse(resp); } catch {}
            if (r.success) {
                Swal.fire({ icon: 'success', title: 'Deleted', text: r.msg, timer: 1500, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Cannot Delete', text: r.msg || 'Delete failed.' });
            }
        });
    });
}
</script>

<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
