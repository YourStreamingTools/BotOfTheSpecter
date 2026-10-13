<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_media_migrate_page_title');
require_once '/var/www/config/db_connect.php';
include '../includes/userdata.php';
require_once __DIR__ . '/../includes/media_migrate.php';
session_write_close();

function media_migrate_json($success, $message, $extra = []) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate_user'])) {
    $target = trim((string) ($_POST['username'] ?? ''));
    if ($target === '') {
        media_migrate_json(false, t('admin_media_migrate_missing_user'));
    }
    $result = migrate_user_media_library($target);
    $ok = !empty($result['success']);
    if (function_exists('admin_audit_log')) {
        admin_audit_log(
            'media_migrate',
            $ok ? 'success' : 'failed',
            ['username' => $target, 'message' => $result['message'] ?? ''],
            'username',
            $target
        );
    }
    media_migrate_json($ok, $result['message'] ?? t('admin_media_migrate_failed'), [
        'copied' => $result['copied'] ?? 0,
        'skipped' => $result['skipped'] ?? 0,
    ]);
}

$channels = [];
$list = $conn->query("SELECT id, username, twitch_display_name, new_media FROM users ORDER BY id ASC");
if ($list) {
    while ($row = $list->fetch_assoc()) {
        $channels[] = $row;
    }
    $list->free();
}
$pendingCount = 0;
foreach ($channels as $row) {
    if ((int) ($row['new_media'] ?? 0) !== 1) {
        $pendingCount++;
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-page-header">
    <h1><i class="fas fa-photo-film"></i> <?php echo t('admin_media_migrate_page_title'); ?></h1>
    <p class="sp-text-muted"><?php echo t('admin_media_migrate_intro'); ?></p>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <span class="sp-card-title"><?php echo t('admin_media_migrate_th_user'); ?></span>
        <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" id="migrate-all-btn" <?php echo $pendingCount === 0 ? 'disabled' : ''; ?>>
            <span class="icon"><i class="fas fa-arrow-right"></i></span>
            <span><?php echo t('admin_media_migrate_all', [(string) $pendingCount]); ?></span>
        </button>
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_media_migrate_th_user'); ?></th>
                        <th><?php echo t('admin_media_migrate_th_status'); ?></th>
                        <th><?php echo t('admin_media_migrate_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channels as $row): ?>
                        <?php $done = (int) ($row['new_media'] ?? 0) === 1; ?>
                        <tr data-username="<?php echo htmlspecialchars($row['username']); ?>" data-pending="<?php echo $done ? '0' : '1'; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($row['twitch_display_name'] ?: $row['username']); ?></strong>
                                <div class="sp-text-muted"><?php echo htmlspecialchars($row['username']); ?></div>
                            </td>
                            <td>
                                <span class="sp-badge <?php echo $done ? 'sp-badge-green' : 'sp-badge-amber'; ?> migrate-status">
                                    <?php echo $done ? t('admin_media_migrate_status_done') : t('admin_media_migrate_status_pending'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!$done): ?>
                                <button type="button" class="sp-btn sp-btn-warning sp-btn-sm migrate-one-btn">
                                    <span class="icon"><i class="fas fa-arrow-right"></i></span>
                                    <span><?php echo t('admin_media_migrate_btn'); ?></span>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
const MEDIA_MIGRATE_I18N = {
    working: <?php echo json_encode(t('admin_media_migrate_working')); ?>,
    failed: <?php echo json_encode(t('admin_media_migrate_failed')); ?>,
    done: <?php echo json_encode(t('admin_media_migrate_status_done')); ?>,
    allDone: <?php echo json_encode(t('admin_media_migrate_all_done')); ?>
};
async function migrateUsername(username) {
    const body = new FormData();
    body.append('migrate_user', '1');
    body.append('username', username);
    const response = await fetch('media_migrate.php', { method: 'POST', body: body });
    const data = await response.json();
    return data;
}
function markRowDone(row) {
    row.setAttribute('data-pending', '0');
    const badge = row.querySelector('.migrate-status');
    if (badge) {
        badge.className = 'sp-badge sp-badge-green migrate-status';
        badge.textContent = MEDIA_MIGRATE_I18N.done;
    }
    const btn = row.querySelector('.migrate-one-btn');
    if (btn) btn.remove();
}
document.querySelectorAll('.migrate-one-btn').forEach((btn) => {
    btn.addEventListener('click', async function () {
        const row = btn.closest('tr');
        const username = row.getAttribute('data-username');
        btn.disabled = true;
        try {
            const data = await migrateUsername(username);
            if (data && data.success) {
                markRowDone(row);
                Swal.fire({ icon: 'success', title: username, text: data.message });
            } else {
                btn.disabled = false;
                Swal.fire({ icon: 'error', title: MEDIA_MIGRATE_I18N.failed, text: (data && data.message) ? data.message : MEDIA_MIGRATE_I18N.failed });
            }
        } catch (e) {
            btn.disabled = false;
            Swal.fire({ icon: 'error', title: MEDIA_MIGRATE_I18N.failed, text: MEDIA_MIGRATE_I18N.failed });
        }
    });
});
document.getElementById('migrate-all-btn').addEventListener('click', async function () {
    const allBtn = this;
    const rows = Array.from(document.querySelectorAll('tr[data-pending="1"]'));
    if (!rows.length) return;
    allBtn.disabled = true;
    let failed = 0;
    for (const row of rows) {
        const username = row.getAttribute('data-username');
        const btn = row.querySelector('.migrate-one-btn');
        if (btn) btn.disabled = true;
        try {
            const data = await migrateUsername(username);
            if (data && data.success) {
                markRowDone(row);
            } else {
                failed++;
                if (btn) btn.disabled = false;
            }
        } catch (e) {
            failed++;
            if (btn) btn.disabled = false;
        }
    }
    allBtn.disabled = document.querySelectorAll('tr[data-pending="1"]').length === 0;
    Swal.fire({
        icon: failed ? 'warning' : 'success',
        title: MEDIA_MIGRATE_I18N.allDone,
        text: failed ? String(failed) : ''
    });
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
