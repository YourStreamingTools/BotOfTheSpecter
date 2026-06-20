<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_migrations_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
require_once __DIR__ . '/../includes/migration_runner.php';
session_write_close();

function mig_json($success, $message, $extra = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$registry = migration_registry();

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $db = $_POST['db'] ?? '';
    $appliedBy = isset($username) ? $username : 'admin';
    if (!isset($registry[$db])) mig_json(false, t('admin_migrations_err_unknown_db'));
    try {
        if ($action === 'apply') {
            $id = $_POST['id'] ?? '';
            $confirm = !empty($_POST['confirm_destructive']);
            migration_apply($db, $id, $confirm, $appliedBy);
            admin_audit_log('migration_apply', 'success', ['db' => $db, 'id' => $id], 'migration', $db . '/' . $id);
            mig_json(true, t('admin_migrations_msg_applied'));
        } elseif ($action === 'rollback') {
            $id = $_POST['id'] ?? '';
            migration_rollback($db, $id, $appliedBy);
            admin_audit_log('migration_rollback', 'success', ['db' => $db, 'id' => $id], 'migration', $db . '/' . $id);
            mig_json(true, t('admin_migrations_msg_rolled_back'));
        } elseif ($action === 'adopt_baseline') {
            migration_adopt_baseline($db, $appliedBy);
            admin_audit_log('migration_adopt_baseline', 'success', ['db' => $db], 'migration', $db);
            mig_json(true, t('admin_migrations_msg_adopted'));
        } elseif ($action === 'apply_all') {
            $status = migration_status($db);
            $applied = [];
            foreach ($status['pending'] as $m) {
                if ($m['destructive']) continue; // skip destructive in bulk apply
                migration_apply($db, $m['id'], false, $appliedBy);
                admin_audit_log('migration_apply', 'success', ['db' => $db, 'id' => $m['id'], 'bulk' => true], 'migration', $db . '/' . $m['id']);
                $applied[] = $m['id'];
            }
            mig_json(true, t('admin_migrations_msg_applied_all', [count($applied)]), ['applied' => $applied]);
        } else {
            mig_json(false, t('admin_migrations_err_unknown_action'));
        }
    } catch (Throwable $e) {
        mig_json(false, $e->getMessage());
    }
}

// Build view model (catch per-DB connection errors so one bad DB doesn't break the page)
$view = [];
foreach ($registry as $db => $meta) {
    try {
        $view[$db] = ['label' => $meta['label'], 'status' => migration_status($db), 'error' => null];
    } catch (Throwable $e) {
        $view[$db] = ['label' => $meta['label'], 'status' => null, 'error' => $e->getMessage()];
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header"><h1 class="sp-card-title"><i class="fas fa-database"></i> <?php echo t('admin_migrations_page_title'); ?></h1></div>
    <div class="sp-card-body"><p style="color:var(--text-secondary);"><?php echo t('admin_migrations_intro'); ?></p></div>
</div>
<?php foreach ($view as $db => $info): ?>
<div class="sp-card" data-db="<?php echo htmlspecialchars($db); ?>">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo htmlspecialchars($info['label']); ?> <span class="mig-dbname">(<?php echo htmlspecialchars($db); ?>)</span></h2>
    </div>
    <div class="sp-card-body">
    <?php if ($info['error']): ?>
        <div class="sp-alert sp-alert-danger"><?php echo htmlspecialchars($info['error']); ?></div>
    <?php else: $s = $info['status']; ?>
        <p class="mig-counts">
            <?php echo t('admin_migrations_counts', [count($s['applied']), count($s['pending'])]); ?>
            <?php if (!empty($s['missing'])): ?><span class="mig-warn"><?php echo t('admin_migrations_missing_warn', [count($s['missing'])]); ?></span><?php endif; ?>
        </p>
        <?php
        $hasBaselineApplied = false; foreach ($s['applied'] as $am) { $hasBaselineApplied = true; break; }
        if (!$hasBaselineApplied && !empty($s['pending'])): ?>
            <button class="sp-btn sp-btn-info mig-adopt" data-db="<?php echo htmlspecialchars($db); ?>"><i class="fas fa-check-double"></i> <?php echo t('admin_migrations_adopt_baseline'); ?></button>
        <?php endif; ?>
        <?php if (!empty($s['pending'])): ?>
            <button class="sp-btn sp-btn-primary mig-apply-all" data-db="<?php echo htmlspecialchars($db); ?>"><i class="fas fa-forward"></i> <?php echo t('admin_migrations_apply_all'); ?></button>
        <?php endif; ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead><tr>
                    <th><?php echo t('admin_migrations_th_status'); ?></th>
                    <th><?php echo t('admin_migrations_th_id'); ?></th>
                    <th><?php echo t('admin_migrations_th_desc'); ?></th>
                    <th><?php echo t('admin_migrations_th_actions'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($s['applied'] as $i => $m): $isLast = ($i === count($s['applied']) - 1); ?>
                    <tr>
                        <td><span class="mig-pill mig-applied"><?php echo t('admin_migrations_status_applied'); ?></span><?php if (!empty($m['drift'])): ?> <span class="mig-pill mig-drift"><?php echo t('admin_migrations_status_drift'); ?></span><?php endif; ?></td>
                        <td><code><?php echo htmlspecialchars($m['id']); ?></code></td>
                        <td><?php echo htmlspecialchars($m['description']); ?><br><small><?php echo htmlspecialchars(($m['applied_at'] ?? '') . ' · ' . ($m['applied_by'] ?? '')); ?></small></td>
                        <td><?php if ($isLast): ?><button class="sp-btn sp-btn-warning sp-btn-sm mig-rollback" data-db="<?php echo htmlspecialchars($db); ?>" data-id="<?php echo htmlspecialchars($m['id']); ?>"><?php echo t('admin_migrations_rollback'); ?></button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($s['pending'] as $m): ?>
                    <tr>
                        <td><span class="mig-pill mig-pending"><?php echo t('admin_migrations_status_pending'); ?></span><?php if ($m['destructive']): ?> <span class="mig-pill mig-destructive"><?php echo t('admin_migrations_status_destructive'); ?></span><?php endif; ?><?php if ($m['procedural']): ?> <span class="mig-pill mig-procedural"><?php echo t('admin_migrations_status_procedural'); ?></span><?php endif; ?></td>
                        <td><code><?php echo htmlspecialchars($m['id']); ?></code></td>
                        <td>
                            <?php echo htmlspecialchars($m['description']); ?>
                            <details class="mig-review"><summary><?php echo t('admin_migrations_review_sql'); ?></summary>
                                <?php if ($m['procedural']): ?>
                                    <pre class="mig-sql"><?php echo htmlspecialchars($m['preview'] ?: t('admin_migrations_procedural_note')); ?></pre>
                                <?php else: ?>
                                    <pre class="mig-sql"><?php echo htmlspecialchars(implode(";\n", array_map('trim', (array) $m['up']))); ?></pre>
                                <?php endif; ?>
                            </details>
                        </td>
                        <td><button class="sp-btn sp-btn-success sp-btn-sm mig-apply" data-db="<?php echo htmlspecialchars($db); ?>" data-id="<?php echo htmlspecialchars($m['id']); ?>" data-destructive="<?php echo $m['destructive'] ? '1' : '0'; ?>"><?php echo t('admin_migrations_apply'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const I18N = {
        errorTitle: <?php echo json_encode(t('admin_migrations_js_error_title')); ?>,
        confirmApplyTitle: <?php echo json_encode(t('admin_migrations_js_apply_confirm_title')); ?>,
        destructiveText: <?php echo json_encode(t('admin_migrations_js_destructive_text')); ?>,
        rollbackConfirmTitle: <?php echo json_encode(t('admin_migrations_js_rollback_confirm_title')); ?>,
        rollbackConfirmText: <?php echo json_encode(t('admin_migrations_js_rollback_confirm_text')); ?>,
        confirmBtn: <?php echo json_encode(t('admin_migrations_js_confirm_btn')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_migrations_js_cancel_btn')); ?>
    };
    async function post(fields) {
        const fd = new FormData();
        for (const k in fields) fd.append(k, fields[k]);
        const res = await fetch('migrations.php', { method: 'POST', body: fd });
        return res.json();
    }
    function reloadSoon() { setTimeout(function () { window.location.reload(); }, 700); }
    function fail(msg) { Swal.fire({ icon: 'error', title: I18N.errorTitle, text: msg }); }

    document.querySelectorAll('.mig-apply').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const db = this.dataset.db, id = this.dataset.id, destructive = this.dataset.destructive === '1';
            let confirmDestructive = false;
            if (destructive) {
                const r = await Swal.fire({ icon: 'warning', title: I18N.confirmApplyTitle, text: I18N.destructiveText.replace('%s', id),
                    showCancelButton: true, confirmButtonText: I18N.confirmBtn, cancelButtonText: I18N.cancelBtn, confirmButtonColor: '#f14668' });
                if (!r.isConfirmed) return;
                confirmDestructive = true;
            }
            const data = await post({ action: 'apply', db: db, id: id, confirm_destructive: confirmDestructive ? '1' : '' });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
    document.querySelectorAll('.mig-apply-all').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const data = await post({ action: 'apply_all', db: this.dataset.db });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
    document.querySelectorAll('.mig-adopt').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const data = await post({ action: 'adopt_baseline', db: this.dataset.db });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
    document.querySelectorAll('.mig-rollback').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const db = this.dataset.db, id = this.dataset.id;
            const r = await Swal.fire({ icon: 'warning', title: I18N.rollbackConfirmTitle, text: I18N.rollbackConfirmText.replace('%s', id),
                showCancelButton: true, confirmButtonText: I18N.confirmBtn, cancelButtonText: I18N.cancelBtn, confirmButtonColor: '#f39c12' });
            if (!r.isConfirmed) return;
            const data = await post({ action: 'rollback', db: db, id: id });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
