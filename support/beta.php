<?php
// support/beta.php
// ----------------------------------------------------------------
// Beta Programs listing and management.
//
// Users:   view active programs, see enrolment status, request access.
// Staff:   all of the above + create / edit / toggle / delete programs
//          + view the pending requests queue.
//
// POST _action values:
//   save_program    — staff: create (edit_id=0) or update a program
//   toggle_program  — staff: flip is_active
//   delete_program  — staff: delete (blocked if pending requests exist)
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
support_session_start();
require_login();

$db    = support_db();
$wdb   = website_db();
$flash = [];
$errors = [];

// ----------------------------------------------------------------
// POST handlers (staff only)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_staff()) {
        $flash[] = ['type' => 'danger', 'msg' => 'Insufficient permissions.'];
    } elseif (!verify_csrf()) {
        $flash[] = ['type' => 'danger', 'msg' => 'Security token mismatch. Please try again.'];
    } else {
        $postAction = $_POST['_action'] ?? '';

        if ($postAction === 'save_program') {
            $editId = (int)($_POST['edit_id'] ?? 0);
            $slug   = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['slug'] ?? '')));
            $name   = trim($_POST['name'] ?? '');
            $desc   = trim($_POST['description'] ?? '');
            if ($editId === 0 && strlen($slug) < 2) $errors[] = 'Slug must be at least 2 characters (lowercase letters, numbers, hyphens, underscores).';
            if (strlen($name) < 2) $errors[] = 'Program name is required.';
            if (empty($errors)) {
                if ($editId > 0) {
                    $stmt = $wdb->prepare('UPDATE beta_programs SET name=?, description=? WHERE id=?');
                    $stmt->bind_param('ssi', $name, $desc, $editId);
                    $stmt->execute();
                    $stmt->close();
                    $flash[] = ['type' => 'success', 'msg' => 'Program updated.'];
                } else {
                    $chk = $wdb->prepare('SELECT id FROM beta_programs WHERE slug=?');
                    $chk->bind_param('s', $slug);
                    $chk->execute();
                    $chk->store_result();
                    $exists = $chk->num_rows > 0;
                    $chk->close();
                    if ($exists) {
                        $errors[] = 'A program with that slug already exists.';
                    } else {
                        $stmt = $wdb->prepare('INSERT INTO beta_programs (slug, name, description) VALUES (?, ?, ?)');
                        $stmt->bind_param('sss', $slug, $name, $desc);
                        $stmt->execute();
                        $stmt->close();
                        $flash[] = ['type' => 'success', 'msg' => "Program \"{$name}\" created."];
                    }
                }
            }

        } elseif ($postAction === 'toggle_program') {
            $pid = (int)($_POST['program_id'] ?? 0);
            $wdb->query("UPDATE beta_programs SET is_active = NOT is_active WHERE id = {$pid}");
            $flash[] = ['type' => 'success', 'msg' => 'Program status updated.'];

        } elseif ($postAction === 'delete_program') {
            $pid  = (int)($_POST['program_id'] ?? 0);
            $row  = $wdb->query("SELECT slug, name FROM beta_programs WHERE id = {$pid}")->fetch_assoc();
            if ($row) {
                $esc  = $db->real_escape_string($row['slug']);
                $pend = $db->query("SELECT COUNT(*) AS cnt FROM tickets WHERE category='beta_request' AND JSON_EXTRACT(meta,'$.program')='{$esc}' AND status IN('open','in_progress')");
                $cnt  = $pend ? (int)$pend->fetch_assoc()['cnt'] : 0;
                if ($cnt > 0) {
                    $flash[] = ['type' => 'danger', 'msg' => "Cannot delete \"{$row['name']}\": {$cnt} pending request(s) still reference it. Resolve them first."];
                } else {
                    $wdb->query("DELETE FROM beta_programs WHERE id = {$pid}");
                    $flash[] = ['type' => 'success', 'msg' => "Program \"{$row['name']}\" deleted."];
                }
            }
        }
    }
}

// ----------------------------------------------------------------
// Fetch data
// ----------------------------------------------------------------

// Programs list (staff sees all, users see only active) — website DB
$allPrograms = [];
$res = is_staff()
    ? $wdb->query('SELECT * FROM beta_programs ORDER BY is_active DESC, name ASC')
    : $wdb->query('SELECT * FROM beta_programs WHERE is_active = 1 ORDER BY name ASC');
if ($res) $allPrograms = $res->fetch_all(MYSQLI_ASSOC);

// Current user's enrolled programs — website DB
$userPrograms = [];
$wstmt = $wdb->prepare('SELECT beta_programs FROM users WHERE twitch_user_id = ? LIMIT 1');
$wstmt->bind_param('s', $_SESSION['twitch_user_id']);
$wstmt->execute();
$wstmt->bind_result($rawProgs);
$wstmt->fetch();
$wstmt->close();
$userPrograms = json_decode($rawProgs ?? '[]', true) ?? [];

// User's pending requests (open/in_progress beta_request tickets)
$pendingPrograms = [];
$pstmt = $db->prepare("SELECT meta FROM tickets WHERE twitch_user_id = ? AND category = 'beta_request' AND status IN ('open','in_progress')");
$pstmt->bind_param('s', $_SESSION['twitch_user_id']);
$pstmt->execute();
$prows = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pstmt->close();
foreach ($prows as $prow) {
    $pm = json_decode($prow['meta'] ?? '{}', true);
    if (!empty($pm['program'])) $pendingPrograms[] = $pm['program'];
}

// Staff: pending beta request tickets
$pendingRequests = [];
if (is_staff()) {
    $qres = $db->query(
        "SELECT t.*, (SELECT COUNT(*) FROM ticket_replies WHERE ticket_id = t.id) AS reply_count
         FROM tickets t
         WHERE t.category = 'beta_request' AND t.status IN ('open','in_progress')
         ORDER BY t.created_at ASC"
    );
    if ($qres) $pendingRequests = $qres->fetch_all(MYSQLI_ASSOC);
}

// ----------------------------------------------------------------
// Build page
// ----------------------------------------------------------------
$pageTitle   = 'Beta Programs';
$topbarTitle = 'Beta Programs';
ob_start();
?>
<?php foreach ($flash as $f): ?>
<div class="sp-alert sp-alert-<?php echo htmlspecialchars($f['type']); ?>" data-dismiss="6000">
    <i class="fa-solid <?php echo $f['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
    <span><?php echo htmlspecialchars($f['msg']); ?></span>
</div>
<?php endforeach; ?>
<?php foreach ($errors as $err): ?>
<div class="sp-alert sp-alert-danger">
    <i class="fa-solid fa-circle-xmark"></i><span><?php echo htmlspecialchars($err); ?></span>
</div>
<?php endforeach; ?>

<div class="sp-page-header">
    <div>
        <h1><i class="fa-solid fa-flask"></i> Beta Programs</h1>
        <p style="color:var(--text-secondary);">Request early access to features currently in testing.</p>
    </div>
</div>

<!-- ============================================================ -->
<!-- Program cards                                                -->
<!-- ============================================================ -->
<?php if (empty($allPrograms)): ?>
<div class="sp-empty-state">
    <div class="sp-empty-icon"><i class="fa-solid fa-flask"></i></div>
    <h3>No Beta Programs Available</h3>
    <p>There are no beta programs open right now. Check back later.</p>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem;margin-bottom:2rem;">
<?php foreach ($allPrograms as $prog):
    $isEnrolled = in_array($prog['slug'], $userPrograms, true);
    $isPending  = in_array($prog['slug'], $pendingPrograms, true);
    $isInactive = !(bool)$prog['is_active'];
?>
<div class="sp-card" style="<?php echo $isInactive ? 'opacity:0.55;' : ''; ?>">
    <div class="sp-card-header" style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
        <span><?php echo htmlspecialchars($prog['name']); ?></span>
        <div style="display:flex;gap:0.3rem;align-items:center;">
            <?php if ($isInactive): ?>
                <span class="sp-badge" style="background:var(--text-muted);color:#fff;font-size:0.7rem;">Inactive</span>
            <?php endif; ?>
            <?php if ($isEnrolled): ?>
                <span class="sp-badge sp-badge-green"><i class="fa-solid fa-circle-check"></i> Enrolled</span>
            <?php elseif ($isPending): ?>
                <span class="sp-badge sp-badge-amber"><i class="fa-solid fa-clock"></i> Pending</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="sp-card-body">
        <?php if (!empty($prog['description'])): ?>
        <p style="color:var(--text-secondary);margin-bottom:1rem;font-size:0.9rem;"><?php echo nl2br(htmlspecialchars($prog['description'])); ?></p>
        <?php endif; ?>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <code style="font-size:0.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($prog['slug']); ?></code>
            <?php if (!$isInactive && !$isEnrolled && !$isPending): ?>
                <a href="/tickets.php?action=new&category=beta_request&program=<?php echo urlencode($prog['slug']); ?>"
                   class="sp-btn sp-btn-primary sp-btn-sm" style="margin-left:auto;">
                    <i class="fa-solid fa-paper-plane"></i> Request Access
                </a>
            <?php endif; ?>
            <?php if (is_staff()): ?>
            <div style="margin-left:auto;display:flex;gap:0.25rem;">
                <button class="sp-btn sp-btn-sm" title="Edit"
                    onclick="openEditProgram(<?php echo (int)$prog['id']; ?>, <?php echo json_encode($prog['name']); ?>, <?php echo json_encode($prog['description'] ?? ''); ?>)">
                    <i class="fa-solid fa-pen"></i>
                </button>
                <form method="POST" action="/beta.php" style="display:inline;">
                    <input type="hidden" name="_action"    value="toggle_program">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="program_id" value="<?php echo (int)$prog['id']; ?>">
                    <button type="submit" class="sp-btn sp-btn-sm" title="<?php echo $isInactive ? 'Activate' : 'Deactivate'; ?>">
                        <i class="fa-solid <?php echo $isInactive ? 'fa-eye' : 'fa-eye-slash'; ?>"></i>
                    </button>
                </form>
                <form method="POST" action="/beta.php" style="display:inline;"
                      onsubmit="return confirm('Delete this program? This cannot be undone.');">
                    <input type="hidden" name="_action"    value="delete_program">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="program_id" value="<?php echo (int)$prog['id']; ?>">
                    <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="Delete">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (is_staff()): ?>
<!-- ============================================================ -->
<!-- Staff: Create / Edit program                                 -->
<!-- ============================================================ -->
<div class="sp-card sp-mt-3" style="max-width:560px;" id="program-card">
    <div class="sp-card-header"><i class="fa-solid fa-plus"></i> <span id="program-form-title">Create Beta Program</span></div>
    <div class="sp-card-body">
        <form method="POST" action="/beta.php" id="program-form">
            <input type="hidden" name="_action"    value="save_program">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="edit_id"    value="0" id="program-edit-id">
            <div class="sp-form-group" id="slug-group">
                <label class="sp-label" for="prog_slug">
                    Slug <span class="sp-req">*</span>
                    <span style="font-size:0.75rem;color:var(--text-muted);">— this becomes the program key (lowercase, no spaces)</span>
                </label>
                <input type="text" id="prog_slug" name="slug" class="sp-input"
                    placeholder="e.g. streaming" maxlength="50"
                    pattern="[a-z0-9_-]+" title="Lowercase letters, numbers, hyphens and underscores only">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="prog_name">Name <span class="sp-req">*</span></label>
                <input type="text" id="prog_name" name="name" class="sp-input"
                    placeholder="e.g. Streaming Beta" maxlength="100">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="prog_desc">Description</label>
                <textarea id="prog_desc" name="description" class="sp-textarea" rows="3"
                    placeholder="What does this beta program test?"></textarea>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit" class="sp-btn sp-btn-primary" id="program-submit-btn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Program
                </button>
                <button type="button" class="sp-btn sp-btn-ghost" id="program-cancel-btn"
                    style="display:none;" onclick="resetProgramForm()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- Staff: Pending requests queue                                -->
<!-- ============================================================ -->
<?php if (!empty($pendingRequests)): ?>
<div class="sp-card sp-mt-3">
    <div class="sp-card-header">
        <i class="fa-solid fa-clock"></i> Pending Requests
        <span class="sp-badge sp-badge-amber" style="margin-left:0.5rem;"><?php echo count($pendingRequests); ?></span>
    </div>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>User</th>
                    <th>Program</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingRequests as $req):
                $rm   = json_decode($req['meta'] ?? '{}', true);
                $rProg = $rm['program_name'] ?? $rm['program'] ?? '—';
            ?>
            <tr>
                <td><a href="/tickets.php?id=<?php echo urlencode($req['ticket_number']); ?>"
                       style="font-family:monospace;white-space:nowrap;"><?php echo htmlspecialchars($req['ticket_number']); ?></a></td>
                <td><?php echo htmlspecialchars($req['display_name'] ?: $req['username']); ?></td>
                <td><span class="sp-badge sp-badge-blue"><?php echo htmlspecialchars($rProg); ?></span></td>
                <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($req['created_at'])); ?></td>
                <td>
                    <a href="/tickets.php?id=<?php echo urlencode($req['ticket_number']); ?>"
                       class="sp-btn sp-btn-sm sp-btn-secondary">
                        <i class="fa-solid fa-eye"></i> Review
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function openEditProgram(id, name, desc) {
    document.getElementById('program-form-title').textContent = 'Edit Beta Program';
    document.getElementById('program-edit-id').value = id;
    document.getElementById('prog_name').value = name;
    document.getElementById('prog_desc').value = desc;
    document.getElementById('slug-group').style.display = 'none';
    document.getElementById('program-submit-btn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    document.getElementById('program-cancel-btn').style.display = '';
    document.getElementById('program-card').scrollIntoView({ behavior: 'smooth' });
}
function resetProgramForm() {
    document.getElementById('program-form-title').textContent = 'Create Beta Program';
    document.getElementById('program-edit-id').value = '0';
    document.getElementById('prog_name').value = '';
    document.getElementById('prog_desc').value = '';
    document.getElementById('prog_slug').value = '';
    document.getElementById('slug-group').style.display = '';
    document.getElementById('program-submit-btn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Program';
    document.getElementById('program-cancel-btn').style.display = 'none';
}
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
