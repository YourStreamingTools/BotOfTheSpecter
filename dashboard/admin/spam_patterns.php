<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
header('Content-Type: text/html; charset=utf-8');
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_spam_page_title');
require_once "/var/www/config/db_connect.php";
$spam_db_name = 'spam_pattern';
$spam_conn = new mysqli($servername, $username, $password, $spam_db_name);
if ($spam_conn->connect_error) {
    die(t('admin_spam_connection_failed') . $spam_conn->connect_error);
}
$spam_conn->set_charset("utf8mb4");
include "../includes/userdata.php";
session_write_close();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_pattern'])) {
    $pattern = trim($_POST['pattern']);
    $success = false;
    $message = '';
    if (empty($pattern)) {
        $message = t('admin_spam_msg_pattern_empty');
    } else {
        try {
            $stmt = $spam_conn->prepare("INSERT INTO spam_patterns (spam_pattern) VALUES (?)");
            $stmt->bind_param("s", $pattern);
            if ($stmt->execute()) {
                $success = true;
                $message = t('admin_spam_msg_added');
                $new_id = $stmt->insert_id;
            } else {
                $message = t('admin_spam_msg_add_failed', [$stmt->error]);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $message = t('admin_spam_msg_error', [$e->getMessage()]);
        }
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'id' => $success ? $new_id : null], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_pattern'])) {
    $id = intval($_POST['id']);
    $pattern = trim($_POST['pattern']);
    $success = false;
    $message = '';
    if (empty($pattern)) {
        $message = t('admin_spam_msg_pattern_empty');
    } elseif ($id <= 0) {
        $message = t('admin_spam_msg_invalid_id');
    } else {
        try {
            $stmt = $spam_conn->prepare("UPDATE spam_patterns SET spam_pattern = ? WHERE id = ?");
            $stmt->bind_param("si", $pattern, $id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = true;
                $message = t('admin_spam_msg_updated');
            } else {
                $message = t('admin_spam_msg_update_failed');
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $message = t('admin_spam_msg_error', [$e->getMessage()]);
        }
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_pattern'])) {
    $id = intval($_POST['id']);
    $success = false;
    $message = '';
    if ($id <= 0) {
        $message = t('admin_spam_msg_invalid_id');
    } else {
        try {
            $stmt = $spam_conn->prepare("DELETE FROM spam_patterns WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success = true;
                $message = t('admin_spam_msg_deleted');
            } else {
                $message = t('admin_spam_msg_delete_failed', [$stmt->error]);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $message = t('admin_spam_msg_error', [$e->getMessage()]);
        }
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_duplicates'])) {
    $duplicates = [];
    $count = 0;
    $message = '';
    try {
        // Find patterns that appear more than once
        $sql = "SELECT spam_pattern, COUNT(*) as count, GROUP_CONCAT(id) as ids 
                FROM spam_patterns 
                GROUP BY spam_pattern 
                HAVING count > 1";
        $result = $spam_conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $duplicates[] = [
                    'pattern' => $row['spam_pattern'],
                    'count' => $row['count'],
                    'ids' => $row['ids']
                ];
                $count++;
            }
            $result->free();
            $success = true;
            $message = $count > 0 ? t('admin_spam_msg_found_duplicates', [$count]) : t('admin_spam_msg_no_duplicates');
        } else {
            $success = false;
            $message = t('admin_spam_msg_db_error', [$spam_conn->error]);
        }
    } catch (\Throwable $e) {
        $success = false;
        $message = t('admin_spam_msg_error', [$e->getMessage()]);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'duplicates' => $duplicates], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cleanup_duplicates'])) {
    $deleted_count = 0;
    $kept_count = 0;
    $success = false;
    $message = '';
    try {
        // Start transaction
        $spam_conn->begin_transaction();
        // Find patterns that appear more than once
        $sql = "SELECT spam_pattern, MIN(id) as keep_id, GROUP_CONCAT(id ORDER BY id) as all_ids 
                FROM spam_patterns 
                GROUP BY spam_pattern 
                HAVING COUNT(*) > 1";
        $result = $spam_conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $all_ids = explode(',', $row['all_ids']);
                $keep_id = $row['keep_id'];
                // Remove keep_id from the list to get IDs to delete
                $delete_ids = array_filter($all_ids, function($id) use ($keep_id) {
                    return $id != $keep_id;
                });
                if (!empty($delete_ids)) {
                    $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
                    $stmt = $spam_conn->prepare("DELETE FROM spam_patterns WHERE id IN ($placeholders)");
                    // Create types string (all integers)
                    $types = str_repeat('i', count($delete_ids));
                    $stmt->bind_param($types, ...$delete_ids);
                    if ($stmt->execute()) {
                        $deleted_count += $stmt->affected_rows;
                        $kept_count++;
                    }
                    $stmt->close();
                }
            }
            $result->free();
            // Reset AUTO_INCREMENT to next available ID after the highest existing ID
            $max_id_result = $spam_conn->query("SELECT MAX(id) as max_id FROM spam_patterns");
            if ($max_id_result) {
                $max_row = $max_id_result->fetch_assoc();
                $next_id = $max_row['max_id'] + 1;
                $spam_conn->query("ALTER TABLE spam_patterns AUTO_INCREMENT = $next_id");
                $max_id_result->free();
            }
            // Commit transaction
            $spam_conn->commit();
            $success = true;
            $message = t('admin_spam_msg_cleanup_done', ['kept' => $kept_count, 'removed' => $deleted_count]);
        } else {
            $spam_conn->rollback();
            $success = false;
            $message = t('admin_spam_msg_db_error', [$spam_conn->error]);
        }
    } catch (\Throwable $e) {
        $spam_conn->rollback();
        $success = false;
        $message = t('admin_spam_msg_error', [$e->getMessage()]);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'deleted_count' => $deleted_count, 'kept_count' => $kept_count], JSON_UNESCAPED_UNICODE);
    exit;
}

$spam_patterns = [];
if ($spam_conn) {
    $result = $spam_conn->query("SELECT id, spam_pattern FROM spam_patterns ORDER BY id");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $spam_patterns[] = $row;
        }
        $result->free();
    }
}

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-ban"></i> <?php echo t('admin_spam_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p style="color:var(--text-secondary);margin-bottom:1rem;"><?php echo t('admin_spam_subtitle'); ?></p>
        <div class="sp-alert sp-alert-info">
            <p><strong><?php echo t('admin_spam_note_label'); ?></strong> <?php echo t('admin_spam_note_text'); ?></p>
        </div>
        <div class="sp-btn-group">
            <button id="checkDuplicatesBtn" class="sp-btn sp-btn-warning">
                <span class="icon">
                    <i class="fas fa-copy"></i>
                </span>
                <span><?php echo t('admin_spam_check_duplicates'); ?></span>
            </button>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_spam_add_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <form id="addPatternForm">
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_spam_pattern_label'); ?></label>
            <input class="sp-input" type="text" name="pattern" placeholder="<?php echo htmlspecialchars(t('admin_spam_pattern_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" required>
            <p class="sp-help"><?php echo t('admin_spam_pattern_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <button type="submit" class="sp-btn sp-btn-primary">
                <span class="icon">
                    <i class="fas fa-plus"></i>
                </span>
                <span><?php echo t('admin_spam_add_button'); ?></span>
            </button>
        </div>
    </form>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_spam_list_heading', [count($spam_patterns)]); ?></h2>
    </div>
    <div class="sp-card-body">
    <?php if (empty($spam_patterns)): ?>
        <div class="sp-alert sp-alert-info">
            <?php echo t('admin_spam_empty_state'); ?>
        </div>
    <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php echo t('admin_spam_th_id'); ?></th>
                        <th><?php echo t('admin_spam_th_pattern'); ?></th>
                        <th style="width: 200px;"><?php echo t('admin_spam_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="patternsTable">
                    <?php foreach ($spam_patterns as $pattern): ?>
                        <tr data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td><strong>#<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td>
                                <span
                                    class="pattern-display"><?php echo htmlspecialchars($pattern['spam_pattern'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <input class="sp-input pattern-edit" type="text"
                                    value="<?php echo htmlspecialchars($pattern['spam_pattern'], ENT_QUOTES, 'UTF-8'); ?>"
                                    style="display: none;">
                            </td>
                            <td>
                                <div class="sp-btn-group action-buttons">
                                    <button class="sp-btn sp-btn-info sp-btn-sm edit-pattern"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-edit"></i>
                                        </span>
                                        <span><?php echo t('admin_spam_btn_edit'); ?></span>
                                    </button>
                                    <button class="sp-btn sp-btn-danger sp-btn-sm delete-pattern"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                        <span><?php echo t('admin_spam_btn_delete'); ?></span>
                                    </button>
                                </div>
                                <div class="sp-btn-group edit-buttons" style="display: none;">
                                    <button class="sp-btn sp-btn-success sp-btn-sm save-pattern"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-check"></i>
                                        </span>
                                        <span><?php echo t('admin_spam_btn_save'); ?></span>
                                    </button>
                                    <button class="sp-btn sp-btn-sm cancel-edit"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-times"></i>
                                        </span>
                                        <span><?php echo t('admin_spam_btn_cancel'); ?></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div><!-- /sp-card-body -->
</div><!-- /sp-card -->
<script>
    const SPAM_I18N = {
        cleanupCompleteTitle: <?php echo json_encode(t('admin_spam_js_cleanup_complete_title')); ?>,
        cleanupKept: <?php echo json_encode(t('admin_spam_js_cleanup_kept')); ?>,
        cleanupRemoved: <?php echo json_encode(t('admin_spam_js_cleanup_removed')); ?>,
        cleanupFailedTitle: <?php echo json_encode(t('admin_spam_js_cleanup_failed_title')); ?>,
        errorTitle: <?php echo json_encode(t('admin_spam_js_error_title')); ?>,
        cleanupErrorText: <?php echo json_encode(t('admin_spam_js_cleanup_error_text')); ?>,
        checkErrorText: <?php echo json_encode(t('admin_spam_js_check_error_text')); ?>,
        addErrorText: <?php echo json_encode(t('admin_spam_js_add_error_text')); ?>,
        updateErrorText: <?php echo json_encode(t('admin_spam_js_update_error_text')); ?>,
        deleteErrorText: <?php echo json_encode(t('admin_spam_js_delete_error_text')); ?>,
        dupThPattern: <?php echo json_encode(t('admin_spam_js_dup_th_pattern')); ?>,
        dupThCount: <?php echo json_encode(t('admin_spam_js_dup_th_count')); ?>,
        dupThIds: <?php echo json_encode(t('admin_spam_js_dup_th_ids')); ?>,
        dupHint: <?php echo json_encode(t('admin_spam_js_dup_hint')); ?>,
        foundDuplicatesTitle: <?php echo json_encode(t('admin_spam_js_found_duplicates_title')); ?>,
        cleanupBtn: <?php echo json_encode(t('admin_spam_js_cleanup_btn')); ?>,
        closeBtn: <?php echo json_encode(t('admin_spam_js_close_btn')); ?>,
        cleanDbTitle: <?php echo json_encode(t('admin_spam_js_clean_db_title')); ?>,
        cleanDbText: <?php echo json_encode(t('admin_spam_js_clean_db_text')); ?>,
        patternAddedTitle: <?php echo json_encode(t('admin_spam_js_pattern_added_title')); ?>,
        listHeadingPrefix: <?php echo json_encode(t('admin_spam_js_list_heading_prefix')); ?>,
        thId: <?php echo json_encode(t('admin_spam_th_id')); ?>,
        thPattern: <?php echo json_encode(t('admin_spam_th_pattern')); ?>,
        thActions: <?php echo json_encode(t('admin_spam_th_actions')); ?>,
        btnEdit: <?php echo json_encode(t('admin_spam_btn_edit')); ?>,
        btnDelete: <?php echo json_encode(t('admin_spam_btn_delete')); ?>,
        btnSave: <?php echo json_encode(t('admin_spam_btn_save')); ?>,
        btnCancel: <?php echo json_encode(t('admin_spam_btn_cancel')); ?>,
        emptyState: <?php echo json_encode(t('admin_spam_empty_state')); ?>,
        patternEmpty: <?php echo json_encode(t('admin_spam_msg_pattern_empty')); ?>,
        updatedTitle: <?php echo json_encode(t('admin_spam_js_updated_title')); ?>,
        deleteTitle: <?php echo json_encode(t('admin_spam_js_delete_title')); ?>,
        deleteConfirm: <?php echo json_encode(t('admin_spam_js_delete_confirm')); ?>,
        deleteYes: <?php echo json_encode(t('admin_spam_js_delete_yes')); ?>,
        deletedTitle: <?php echo json_encode(t('admin_spam_js_deleted_title')); ?>
    };
    document.addEventListener('DOMContentLoaded', function () {
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        async function cleanupDuplicates() {
            try {
                const formData = new FormData();
                formData.append('cleanup_duplicates', '1');
                const response = await fetch('spam_patterns.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: SPAM_I18N.cleanupCompleteTitle,
                        html: `<p>${result.message}</p><p>${SPAM_I18N.cleanupKept.replace('%s', result.kept_count)}<br>${SPAM_I18N.cleanupRemoved.replace('%s', result.deleted_count)}</p>`,
                        timer: 3000,
                        showConfirmButton: false
                    });
                    // Reload the page to show updated list
                    location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: SPAM_I18N.cleanupFailedTitle,
                        text: result.message
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: SPAM_I18N.errorTitle,
                    text: SPAM_I18N.cleanupErrorText
                });
            }
        }
        const checkDuplicatesBtn = document.getElementById('checkDuplicatesBtn');
        if (checkDuplicatesBtn) {
            checkDuplicatesBtn.addEventListener('click', async function () {
                try {
                    checkDuplicatesBtn.classList.add('sp-btn-loading');
                    const formData = new FormData();
                    formData.append('check_duplicates', '1');
                    const response = await fetch('spam_patterns.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    checkDuplicatesBtn.classList.remove('sp-btn-loading');
                    if (result.success) {
                        if (result.duplicates.length > 0) {
                            let tableHtml = `
                                <div class="sp-table-wrap">
                                    <table class="sp-table">
                                        <thead>
                                            <tr>
                                                <th>${escapeHtml(SPAM_I18N.dupThPattern)}</th>
                                                <th>${escapeHtml(SPAM_I18N.dupThCount)}</th>
                                                <th>${escapeHtml(SPAM_I18N.dupThIds)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;
                            result.duplicates.forEach(dup => {
                                tableHtml += `
                                    <tr>
                                        <td>${escapeHtml(dup.pattern)}</td>
                                        <td>${dup.count}</td>
                                        <td>${dup.ids}</td>
                                    </tr>
                                `;
                            });
                            tableHtml += `
                                        </tbody>
                                    </table>
                                </div>
                                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.5rem;">${escapeHtml(SPAM_I18N.dupHint)}</p>
                            `;
                            Swal.fire({
                                icon: 'warning',
                                title: SPAM_I18N.foundDuplicatesTitle.replace('%s', result.duplicates.length),
                                html: tableHtml,
                                width: '600px',
                                showCancelButton: true,
                                confirmButtonText: SPAM_I18N.cleanupBtn,
                                confirmButtonColor: '#f14668',
                                cancelButtonText: SPAM_I18N.closeBtn
                            }).then(async (cleanupResult) => {
                                if (cleanupResult.isConfirmed) {
                                    await cleanupDuplicates();
                                }
                            });
                        } else {
                            Swal.fire({
                                icon: 'success',
                                title: SPAM_I18N.cleanDbTitle,
                                text: SPAM_I18N.cleanDbText,
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: SPAM_I18N.errorTitle,
                            text: result.message
                        });
                    }
                } catch (error) {
                    checkDuplicatesBtn.classList.remove('sp-btn-loading');
                    Swal.fire({
                        icon: 'error',
                        title: SPAM_I18N.errorTitle,
                        text: SPAM_I18N.checkErrorText
                    });
                }
            });
        }
        const addForm = document.getElementById('addPatternForm');
        addForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('add_pattern', '1');
            const pattern = addForm.querySelector('[name="pattern"]').value;
            formData.append('pattern', pattern);
            try {
                const response = await fetch('spam_patterns.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: SPAM_I18N.patternAddedTitle,
                        text: result.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    let tableBody = document.getElementById('patternsTable');
                    if (!tableBody) {
                        const box = document.querySelector('.sp-card:last-of-type');
                        if (box) {
                            box.innerHTML = `
                            <div class="sp-card-header"><h2 class="sp-card-title">${escapeHtml(SPAM_I18N.listHeadingPrefix)} (1)</h2></div>
                            <div class="sp-card-body">
                            <div class="sp-table-wrap">
                                <table class="sp-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">${escapeHtml(SPAM_I18N.thId)}</th>
                                            <th>${escapeHtml(SPAM_I18N.thPattern)}</th>
                                            <th style="width: 200px;">${escapeHtml(SPAM_I18N.thActions)}</th>
                                        </tr>
                                    </thead>
                                    <tbody id="patternsTable"></tbody>
                                </table>
                            </div>
                            </div>
                        `;
                            tableBody = document.getElementById('patternsTable');
                        }
                    }
                    const newRow = document.createElement('tr');
                    newRow.setAttribute('data-id', result.id);
                    newRow.innerHTML = `
                    <td><strong>#${result.id}</strong></td>
                    <td>
                        <span class="pattern-display">${escapeHtml(pattern)}</span>
                        <input class="sp-input pattern-edit" type="text" value="${escapeHtml(pattern)}" style="display: none;">
                    </td>
                    <td>
                        <div class="sp-btn-group action-buttons">
                            <button class="sp-btn sp-btn-info sp-btn-sm edit-pattern" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-edit"></i></span>
                                <span>${escapeHtml(SPAM_I18N.btnEdit)}</span>
                            </button>
                            <button class="sp-btn sp-btn-danger sp-btn-sm delete-pattern" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-trash"></i></span>
                                <span>${escapeHtml(SPAM_I18N.btnDelete)}</span>
                            </button>
                        </div>
                        <div class="sp-btn-group edit-buttons" style="display: none;">
                            <button class="sp-btn sp-btn-success sp-btn-sm save-pattern" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-check"></i></span>
                                <span>${escapeHtml(SPAM_I18N.btnSave)}</span>
                            </button>
                            <button class="sp-btn sp-btn-sm cancel-edit" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-times"></i></span>
                                <span>${escapeHtml(SPAM_I18N.btnCancel)}</span>
                            </button>
                        </div>
                    </td>
                `;
                    tableBody.appendChild(newRow);
                    attachRowEventListeners(newRow);
                    addForm.reset();
                    const titleElement = document.querySelector('.sp-card:last-of-type .sp-card-title');
                    const currentCount = tableBody.children.length;
                    if (titleElement) {
                        titleElement.textContent = `${SPAM_I18N.listHeadingPrefix} (${currentCount})`;
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: SPAM_I18N.errorTitle,
                        text: result.message
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: SPAM_I18N.errorTitle,
                    text: SPAM_I18N.addErrorText
                });
            }
        });
        function attachRowEventListeners(row) {
            const editBtn = row.querySelector('.edit-pattern');
            const deleteBtn = row.querySelector('.delete-pattern');
            const saveBtn = row.querySelector('.save-pattern');
            const cancelBtn = row.querySelector('.cancel-edit');
            if (editBtn) {
                editBtn.addEventListener('click', function () {
                    const patternDisplay = row.querySelector('.pattern-display');
                    const patternEdit = row.querySelector('.pattern-edit');
                    const actionButtons = row.querySelector('.action-buttons');
                    const editButtons = row.querySelector('.edit-buttons');
                    patternDisplay.style.display = 'none';
                    patternEdit.style.display = 'block';
                    actionButtons.style.display = 'none';
                    editButtons.style.display = 'flex';
                    patternEdit.focus();
                });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    const patternDisplay = row.querySelector('.pattern-display');
                    const patternEdit = row.querySelector('.pattern-edit');
                    const actionButtons = row.querySelector('.action-buttons');
                    const editButtons = row.querySelector('.edit-buttons');
                    patternEdit.value = patternDisplay.textContent;
                    patternDisplay.style.display = 'inline';
                    patternEdit.style.display = 'none';
                    actionButtons.style.display = 'flex';
                    editButtons.style.display = 'none';
                });
            }
            if (saveBtn) {
                saveBtn.addEventListener('click', async function () {
                    const id = this.dataset.id;
                    const patternEdit = row.querySelector('.pattern-edit');
                    const newPattern = patternEdit.value.trim();
                    if (!newPattern) {
                        Swal.fire({
                            icon: 'error',
                            title: SPAM_I18N.errorTitle,
                            text: SPAM_I18N.patternEmpty
                        });
                        return;
                    }
                    const formData = new FormData();
                    formData.append('update_pattern', '1');
                    formData.append('id', id);
                    formData.append('pattern', newPattern);
                    try {
                        const response = await fetch('spam_patterns.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            const patternDisplay = row.querySelector('.pattern-display');
                            const actionButtons = row.querySelector('.action-buttons');
                            const editButtons = row.querySelector('.edit-buttons');
                            patternDisplay.textContent = newPattern;
                            patternDisplay.style.display = 'inline';
                            patternEdit.style.display = 'none';
                            actionButtons.style.display = 'flex';
                            editButtons.style.display = 'none';
                            Swal.fire({
                                icon: 'success',
                                title: SPAM_I18N.updatedTitle,
                                text: result.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: SPAM_I18N.errorTitle,
                                text: result.message
                            });
                        }
                    } catch (error) {
                        Swal.fire({
                            icon: 'error',
                            title: SPAM_I18N.errorTitle,
                            text: SPAM_I18N.updateErrorText
                        });
                    }
                });
            }
            if (deleteBtn) {
                deleteBtn.addEventListener('click', async function () {
                    const id = this.dataset.id;
                    const patternText = row.querySelector('.pattern-display').textContent;
                    const result = await Swal.fire({
                        icon: 'warning',
                        title: SPAM_I18N.deleteTitle,
                        text: SPAM_I18N.deleteConfirm.replace(':id', id).replace(':pattern', patternText),
                        showCancelButton: true,
                        confirmButtonText: SPAM_I18N.deleteYes,
                        cancelButtonText: SPAM_I18N.btnCancel,
                        confirmButtonColor: '#f14668',
                        cancelButtonColor: '#3085d6'
                    });
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        formData.append('delete_pattern', '1');
                        formData.append('id', id);
                        try {
                            const response = await fetch('spam_patterns.php', {
                                method: 'POST',
                                body: formData
                            });
                            const data = await response.json();
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: SPAM_I18N.deletedTitle,
                                    text: data.message,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                row.remove();
                                const tableBody = document.getElementById('patternsTable');
                                const titleElement = document.querySelector('.sp-card:last-of-type .sp-card-title');
                                const currentCount = tableBody.children.length;
                                titleElement.textContent = `${SPAM_I18N.listHeadingPrefix} (${currentCount})`;
                                if (tableBody && tableBody.children.length === 0) {
                                    const box = tableBody.closest('.sp-card');
                                    if (box) {
                                        box.innerHTML = `
                                        <div class="sp-card-header"><h2 class="sp-card-title">${escapeHtml(SPAM_I18N.listHeadingPrefix)} (0)</h2></div>
                                        <div class="sp-card-body"><div class="sp-alert sp-alert-info">
                                            ${escapeHtml(SPAM_I18N.emptyState)}
                                        </div></div>
                                    `;
                                    }
                                }
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: SPAM_I18N.errorTitle,
                                    text: data.message
                                });
                            }
                        } catch (error) {
                            Swal.fire({
                                icon: 'error',
                                title: SPAM_I18N.errorTitle,
                                text: SPAM_I18N.deleteErrorText
                            });
                        }
                    }
                });
            }
        }
        document.querySelectorAll('#patternsTable tr').forEach(row => {
            attachRowEventListeners(row);
        });
    });
</script>
<?php
$spam_conn->close();
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>