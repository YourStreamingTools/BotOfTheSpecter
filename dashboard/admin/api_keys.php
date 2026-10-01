<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_api_keys_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
session_write_close();

function admin_api_keys_column_exists(mysqli $conn, $column) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_api_keys' AND COLUMN_NAME = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function admin_api_keys_ensure_websocket_columns(mysqli $conn) {
    // Same change as migrations/website/20261001_0001_admin_api_keys_websocket.php, so the page works before that migration is applied.
    $added = false;
    if (!admin_api_keys_column_exists($conn, 'websocket_access')) {
        if (!$conn->query("ALTER TABLE admin_api_keys ADD COLUMN websocket_access TINYINT(1) NOT NULL DEFAULT 0")) {
            return false;
        }
        $added = true;
    }
    if (!admin_api_keys_column_exists($conn, 'websocket_global')) {
        if (!$conn->query("ALTER TABLE admin_api_keys ADD COLUMN websocket_global TINYINT(1) NOT NULL DEFAULT 0")) {
            return false;
        }
        $added = true;
    }
    if (!$added) {
        // Columns already present. Do not overwrite toggles the admin has set.
        return true;
    }
    $conn->query("UPDATE admin_api_keys SET websocket_access = 1, websocket_global = 1 WHERE LOWER(service) IN ('admin', 'freestuff', 'github')");
    $tables = $conn->query("SHOW TABLES LIKE 'custom\\_webhooks'");
    if ($tables && $tables->num_rows > 0) {
        $conn->query("UPDATE admin_api_keys k INNER JOIN custom_webhooks w ON LOWER(w.service) = LOWER(k.service) SET k.websocket_access = 1, k.websocket_global = 1 WHERE w.scope IN ('global', 'discord_logs')");
    }
    if ($tables) {
        $tables->free();
    }
    return true;
}

function admin_api_keys_flag($value) {
    return ((string) $value === '1' || $value === 1) ? 1 : 0;
}

if ($conn) {
    admin_api_keys_ensure_websocket_columns($conn);
}

// Handle API key creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_key'])) {
    $service = trim($_POST['service']);
    $success = false;
    $message = '';
    if (empty($service)) {
        $message = t('admin_api_keys_error_service_empty');
    } else {
        // Generate a random 32-character API key
        $api_key = bin2hex(random_bytes(16));
        $ws_access = admin_api_keys_flag($_POST['websocket_access'] ?? 0);
        $ws_global = $ws_access ? admin_api_keys_flag($_POST['websocket_global'] ?? 0) : 0;
        try {
            $stmt = $conn->prepare("INSERT INTO admin_api_keys (service, api_key, websocket_access, websocket_global) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssii", $service, $api_key, $ws_access, $ws_global);
            if ($stmt->execute()) {
                $success = true;
                $message = t('admin_api_keys_msg_created');
                admin_audit_log('admin_api_key_create', 'info', [
                    'service' => $service,
                    'websocket_access' => $ws_access,
                    'websocket_global' => $ws_global,
                ], 'admin_api_key', $service);
            } else {
                $message = t('admin_api_keys_error_create_failed', [$stmt->error]);
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = t('admin_api_keys_error_generic', [$e->getMessage()]);
        }
    }
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'api_key' => $success ? $api_key : null,
        'websocket_access' => $success ? $ws_access : 0,
        'websocket_global' => $success ? $ws_global : 0,
    ]);
    exit;
}

// Handle WebSocket access flags
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_ws'])) {
    $service = trim($_POST['service'] ?? '');
    $success = false;
    $message = '';
    $ws_access = 0;
    $ws_global = 0;
    if ($service === '') {
        $message = t('admin_api_keys_error_service_empty');
    } else {
        $ws_access = admin_api_keys_flag($_POST['websocket_access'] ?? 0);
        $ws_global = $ws_access ? admin_api_keys_flag($_POST['websocket_global'] ?? 0) : 0;
        try {
            $check = $conn->prepare("SELECT service FROM admin_api_keys WHERE service = ? LIMIT 1");
            $check->bind_param('s', $service);
            $check->execute();
            $found = $check->get_result()->num_rows > 0;
            $check->close();
            if (!$found) {
                $message = t('admin_api_keys_error_ws_update_failed');
            } else {
                $stmt = $conn->prepare("UPDATE admin_api_keys SET websocket_access = ?, websocket_global = ? WHERE service = ?");
                $stmt->bind_param('iis', $ws_access, $ws_global, $service);
                if ($stmt->execute()) {
                    $success = true;
                    $message = t('admin_api_keys_msg_ws_updated');
                    admin_audit_log('admin_api_key_websocket', 'info', [
                        'service' => $service,
                        'websocket_access' => $ws_access,
                        'websocket_global' => $ws_global,
                    ], 'admin_api_key', $service);
                } else {
                    $message = t('admin_api_keys_error_ws_update_failed');
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $message = t('admin_api_keys_error_generic', [$e->getMessage()]);
        }
    }
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'websocket_access' => $ws_access,
        'websocket_global' => $ws_global,
    ]);
    exit;
}

// Handle API key deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_key'])) {
    $service = trim($_POST['service']);
    $success = false;
    $message = '';
    if (empty($service)) {
        $message = t('admin_api_keys_error_service_empty');
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM admin_api_keys WHERE service = ?");
            $stmt->bind_param("s", $service);

            if ($stmt->execute()) {
                $success = true;
                $message = t('admin_api_keys_msg_deleted');
            } else {
                $message = t('admin_api_keys_error_delete_failed', [$stmt->error]);
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = t('admin_api_keys_error_generic', [$e->getMessage()]);
        }
    }
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Handle API key regeneration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['regenerate_key'])) {
    $service = trim($_POST['service']);
    $success = false;
    $message = '';
    if (empty($service)) {
        $message = t('admin_api_keys_error_service_empty');
    } else {
        // Generate a new random 32-character API key
        $api_key = bin2hex(random_bytes(16));
        try {
            $stmt = $conn->prepare("UPDATE admin_api_keys SET api_key = ? WHERE service = ?");
            $stmt->bind_param("ss", $api_key, $service);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = true;
                $message = t('admin_api_keys_msg_regenerated');
            } else {
                $message = t('admin_api_keys_error_regenerate_failed');
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = t('admin_api_keys_error_generic', [$e->getMessage()]);
        }
    }
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'api_key' => $success ? $api_key : null]);
    exit;
}

// Fetch all API keys
$api_keys = [];
$api_keys_error = '';
if ($conn) {
    $result = $conn->query("SELECT service, api_key, websocket_access, websocket_global FROM admin_api_keys ORDER BY service");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $api_keys[] = $row;
        }
        $result->free();
    } else {
        $api_keys_error = $conn->error;
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-key"></i> <?php echo t('admin_api_keys_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p style="color:var(--text-secondary);"><?php echo t('admin_api_keys_intro'); ?></p>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_api_keys_create_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <form id="createKeyForm">
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_api_keys_service_name_label'); ?></label>
            <input class="sp-input" type="text" name="service" placeholder="<?php echo htmlspecialchars(t('admin_api_keys_service_name_placeholder')); ?>" required>
            <p class="sp-help"><?php echo t('admin_api_keys_service_name_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <input type="checkbox" class="switch" id="create-ws-access" name="websocket_access" value="1">
            <label for="create-ws-access"><?php echo t('admin_api_keys_ws_access_label'); ?></label>
            <p class="sp-help"><?php echo t('admin_api_keys_ws_access_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <input type="checkbox" class="switch" id="create-ws-global" name="websocket_global" value="1" disabled>
            <label for="create-ws-global"><?php echo t('admin_api_keys_ws_global_label'); ?></label>
            <p class="sp-help"><?php echo t('admin_api_keys_ws_global_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <button type="submit" class="sp-btn sp-btn-primary">
                <span class="icon">
                    <i class="fas fa-plus"></i>
                </span>
                <span><?php echo t('admin_api_keys_generate_button'); ?></span>
            </button>
        </div>
    </form>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_api_keys_existing_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <?php if ($api_keys_error !== ''): ?>
        <div class="sp-alert sp-alert-danger">
            <?php echo htmlspecialchars($api_keys_error); ?>
        </div>
    <?php elseif (empty($api_keys)): ?>
        <div class="sp-alert sp-alert-info">
            <?php echo t('admin_api_keys_empty_state'); ?>
        </div>
    <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_api_keys_th_service'); ?></th>
                        <th><?php echo t('admin_api_keys_th_api_key'); ?></th>
                        <th><?php echo t('admin_api_keys_th_websocket'); ?></th>
                        <th><?php echo t('admin_api_keys_th_global'); ?></th>
                        <th><?php echo t('admin_api_keys_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="apiKeysTable">
                    <?php foreach ($api_keys as $key): ?>
                        <?php
                            $wsOn = (int) ($key['websocket_access'] ?? 0) === 1;
                            $wsGlobal = $wsOn && (int) ($key['websocket_global'] ?? 0) === 1;
                            $flagId = substr(hash('sha256', $key['service']), 0, 12);
                        ?>
                        <tr data-service="<?php echo htmlspecialchars($key['service']); ?>">
                            <td><strong><?php echo htmlspecialchars($key['service']); ?></strong></td>
                            <td>
                                <div class="sp-btn-group">
                                    <input class="sp-input api-key-input" type="text" value="<?php echo htmlspecialchars($key['api_key']); ?>" readonly autocomplete="off" style="flex:1;font-family:monospace;-webkit-text-security:disc;">
                                    <button class="sp-btn sp-btn-info toggle-visibility" title="<?php echo htmlspecialchars(t('admin_api_keys_toggle_title')); ?>">
                                        <span class="icon">
                                            <i class="fas fa-eye"></i>
                                        </span>
                                    </button>
                                    <button class="sp-btn sp-btn-success copy-key" title="<?php echo htmlspecialchars(t('admin_api_keys_copy_title')); ?>">
                                        <span class="icon">
                                            <i class="fas fa-copy"></i>
                                        </span>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <input type="checkbox" class="switch ws-access-toggle" id="ws-access-<?php echo $flagId; ?>" data-service="<?php echo htmlspecialchars($key['service'], ENT_QUOTES); ?>"<?php echo $wsOn ? ' checked' : ''; ?>>
                                <label for="ws-access-<?php echo $flagId; ?>" aria-label="<?php echo htmlspecialchars(t('admin_api_keys_ws_access_label')); ?>"></label>
                            </td>
                            <td>
                                <input type="checkbox" class="switch ws-global-toggle" id="ws-global-<?php echo $flagId; ?>" data-service="<?php echo htmlspecialchars($key['service'], ENT_QUOTES); ?>"<?php echo $wsGlobal ? ' checked' : ''; ?><?php echo $wsOn ? '' : ' disabled'; ?>>
                                <label for="ws-global-<?php echo $flagId; ?>" aria-label="<?php echo htmlspecialchars(t('admin_api_keys_ws_global_label')); ?>"></label>
                            </td>
                            <td>
                                <div class="sp-btn-group">
                                    <button class="sp-btn sp-btn-warning sp-btn-sm regenerate-key" data-service="<?php echo htmlspecialchars($key['service']); ?>">
                                        <span class="icon">
                                            <i class="fas fa-sync"></i>
                                        </span>
                                        <span><?php echo t('admin_api_keys_regenerate_button'); ?></span>
                                    </button>
                                    <button class="sp-btn sp-btn-danger sp-btn-sm delete-key" data-service="<?php echo htmlspecialchars($key['service']); ?>">
                                        <span class="icon">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                        <span><?php echo t('admin_api_keys_delete_button'); ?></span>
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
document.addEventListener('DOMContentLoaded', function() {
    // Localized strings injected from PHP
    const I18N = {
        errorTitle: <?php echo json_encode(t('admin_api_keys_js_error_title')); ?>,
        copyFailed: <?php echo json_encode(t('admin_api_keys_js_copy_failed')); ?>,
        regenConfirmTitle: <?php echo json_encode(t('admin_api_keys_js_regen_confirm_title')); ?>,
        regenConfirmText: <?php echo json_encode(t('admin_api_keys_js_regen_confirm_text')); ?>,
        regenConfirmBtn: <?php echo json_encode(t('admin_api_keys_js_regen_confirm_btn')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_api_keys_js_cancel_btn')); ?>,
        regenSuccessTitle: <?php echo json_encode(t('admin_api_keys_js_regen_success_title')); ?>,
        newKeyLabel: <?php echo json_encode(t('admin_api_keys_js_new_key_label')); ?>,
        newKeyWarning: <?php echo json_encode(t('admin_api_keys_js_new_key_warning')); ?>,
        newKeyInfo: <?php echo json_encode(t('admin_api_keys_js_new_key_info')); ?>,
        okBtn: <?php echo json_encode(t('admin_api_keys_js_ok_btn')); ?>,
        regenError: <?php echo json_encode(t('admin_api_keys_js_regen_error')); ?>,
        deleteConfirmTitle: <?php echo json_encode(t('admin_api_keys_js_delete_confirm_title')); ?>,
        deleteConfirmText: <?php echo json_encode(t('admin_api_keys_js_delete_confirm_text')); ?>,
        deleteConfirmBtn: <?php echo json_encode(t('admin_api_keys_js_delete_confirm_btn')); ?>,
        deletedTitle: <?php echo json_encode(t('admin_api_keys_js_deleted_title')); ?>,
        deleteError: <?php echo json_encode(t('admin_api_keys_js_delete_error')); ?>,
        createSuccessTitle: <?php echo json_encode(t('admin_api_keys_js_create_success_title')); ?>,
        createError: <?php echo json_encode(t('admin_api_keys_js_create_error')); ?>,
        existingHeading: <?php echo json_encode(t('admin_api_keys_existing_heading')); ?>,
        emptyState: <?php echo json_encode(t('admin_api_keys_empty_state')); ?>,
        thService: <?php echo json_encode(t('admin_api_keys_th_service')); ?>,
        thApiKey: <?php echo json_encode(t('admin_api_keys_th_api_key')); ?>,
        thActions: <?php echo json_encode(t('admin_api_keys_th_actions')); ?>,
        toggleTitle: <?php echo json_encode(t('admin_api_keys_toggle_title')); ?>,
        copyTitle: <?php echo json_encode(t('admin_api_keys_copy_title')); ?>,
        regenerateButton: <?php echo json_encode(t('admin_api_keys_regenerate_button')); ?>,
        deleteButton: <?php echo json_encode(t('admin_api_keys_delete_button')); ?>,
        thWebsocket: <?php echo json_encode(t('admin_api_keys_th_websocket')); ?>,
        thGlobal: <?php echo json_encode(t('admin_api_keys_th_global')); ?>,
        wsAccessLabel: <?php echo json_encode(t('admin_api_keys_ws_access_label')); ?>,
        wsGlobalLabel: <?php echo json_encode(t('admin_api_keys_ws_global_label')); ?>,
        wsError: <?php echo json_encode(t('admin_api_keys_js_ws_error')); ?>
    };
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    function flagSwitch(kind, service, on, disabled) {
        const id = kind + '-' + Math.random().toString(36).slice(2, 10);
        const cls = kind === 'access' ? 'ws-access-toggle' : 'ws-global-toggle';
        const label = kind === 'access' ? I18N.wsAccessLabel : I18N.wsGlobalLabel;
        const serviceAttr = escapeHtml(service).replace(/"/g, '&quot;');
        return '<input type="checkbox" class="switch ' + cls + '" id="' + id + '" data-service="' + serviceAttr + '"' +
            (on ? ' checked' : '') + (disabled ? ' disabled' : '') + '>' +
            '<label for="' + id + '" aria-label="' + escapeHtml(label) + '"></label>';
    }
    // Function to attach event listeners to row buttons
    function attachRowEventListeners(row) {
        // Toggle visibility
        const toggleBtn = row.querySelector('.toggle-visibility');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const input = this.closest('.sp-btn-group').querySelector('input');
                const icon = this.querySelector('i');
                if (input.style.webkitTextSecurity === 'disc') {
                    input.style.webkitTextSecurity = 'none';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.style.webkitTextSecurity = 'disc';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        }
        // Copy key
        const copyBtn = row.querySelector('.copy-key');
        if (copyBtn) {
            copyBtn.addEventListener('click', async function() {
                const input = this.closest('.sp-btn-group').querySelector('input');
                try {
                    await navigator.clipboard.writeText(input.value);
                    const originalIcon = this.querySelector('i');
                    originalIcon.classList.remove('fa-copy');
                    originalIcon.classList.add('fa-check');
                    setTimeout(() => {
                        originalIcon.classList.remove('fa-check');
                        originalIcon.classList.add('fa-copy');
                    }, 2000);
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: I18N.errorTitle,
                        text: I18N.copyFailed
                    });
                }
            });
        }
        // Regenerate key
        const regenerateBtn = row.querySelector('.regenerate-key');
        if (regenerateBtn) {
            regenerateBtn.addEventListener('click', handleRegenerateKey);
        }
        // Delete key
        const deleteBtn = row.querySelector('.delete-key');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', handleDeleteKey);
        }
        row.querySelectorAll('.ws-access-toggle, .ws-global-toggle').forEach(function(input) {
            input.addEventListener('change', handleWsToggle);
        });
    }
    async function handleWsToggle(event) {
        const changed = event.currentTarget;
        const row = changed.closest('tr');
        const accessEl = row.querySelector('.ws-access-toggle');
        const globalEl = row.querySelector('.ws-global-toggle');
        const prevAccess = changed === accessEl ? !accessEl.checked : accessEl.checked;
        const prevGlobal = changed === globalEl ? !globalEl.checked : globalEl.checked;
        if (changed === globalEl && globalEl.checked) {
            accessEl.checked = true;
        }
        if (changed === accessEl && !accessEl.checked) {
            globalEl.checked = false;
        }
        globalEl.disabled = !accessEl.checked;
        accessEl.disabled = true;
        globalEl.disabled = true;
        const formData = new FormData();
        formData.append('update_ws', '1');
        formData.append('service', accessEl.dataset.service);
        formData.append('websocket_access', accessEl.checked ? '1' : '0');
        formData.append('websocket_global', globalEl.checked ? '1' : '0');
        try {
            const response = await fetch('api_keys.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || I18N.wsError);
            }
            accessEl.checked = !!Number(data.websocket_access);
            globalEl.checked = !!Number(data.websocket_global);
        } catch (error) {
            accessEl.checked = prevAccess;
            globalEl.checked = prevGlobal;
            Swal.fire({
                icon: 'error',
                title: I18N.errorTitle,
                text: error.message || I18N.wsError
            });
        }
        accessEl.disabled = false;
        globalEl.disabled = !accessEl.checked;
    }
    // Handle regenerate key
    async function handleRegenerateKey() {
        const service = this.dataset.service;
        const result = await Swal.fire({
            icon: 'warning',
            title: I18N.regenConfirmTitle,
            text: I18N.regenConfirmText.replace('%s', service),
            showCancelButton: true,
            confirmButtonText: I18N.regenConfirmBtn,
            cancelButtonText: I18N.cancelBtn,
            confirmButtonColor: '#f39c12',
            cancelButtonColor: '#3085d6'
        });
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('regenerate_key', '1');
            formData.append('service', service);
            try {
                const response = await fetch('api_keys.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: I18N.regenSuccessTitle,
                        html: `
                            <p>${data.message}</p>
                            <div class="sp-form-group">
                                <label class="sp-label">${I18N.newKeyLabel}</label>
                                <input class="sp-input" style="font-family:monospace;" type="text" value="${data.api_key}" readonly>
                                <p class="sp-help sp-help-danger" style="margin-top:0.5rem;">${I18N.newKeyWarning}</p>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: I18N.okBtn
                    });
                    // Update the key in the table row
                    const row = document.querySelector(`tr[data-service="${service}"]`);
                    if (row) {
                        const input = row.querySelector('input.api-key-input');
                        if (input) {
                            input.value = data.api_key;
                            input.style.webkitTextSecurity = 'disc';
                            
                            // Reset the eye icon if it was showing
                            const eyeIcon = row.querySelector('.toggle-visibility i');
                            if (eyeIcon) {
                                eyeIcon.classList.remove('fa-eye-slash');
                                eyeIcon.classList.add('fa-eye');
                            }
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: I18N.errorTitle,
                        text: data.message
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: I18N.errorTitle,
                    text: I18N.regenError
                });
            }
        }
    }
    // Handle delete key
    async function handleDeleteKey() {
        const service = this.dataset.service;
        const result = await Swal.fire({
            icon: 'warning',
            title: I18N.deleteConfirmTitle,
            text: I18N.deleteConfirmText.replace('%s', service),
            showCancelButton: true,
            confirmButtonText: I18N.deleteConfirmBtn,
            cancelButtonText: I18N.cancelBtn,
            confirmButtonColor: '#f14668',
            cancelButtonColor: '#3085d6'
        });
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('delete_key', '1');
            formData.append('service', service);
            try {
                const response = await fetch('api_keys.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: I18N.deletedTitle,
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    // Remove the row from the table
                    const row = document.querySelector(`tr[data-service="${service}"]`);
                    if (row) {
                        row.remove();
                        // Check if table is now empty
                        const tableBody = document.getElementById('apiKeysTable');
                        if (tableBody && tableBody.children.length === 0) {
                            const box = tableBody.closest('.sp-card');
                            if (box) {
                                box.innerHTML = `
                                    <div class="sp-card-header"><h2 class="sp-card-title">${escapeHtml(I18N.existingHeading)}</h2></div>
                                    <div class="sp-card-body"><div class="sp-alert sp-alert-info">
                                        ${escapeHtml(I18N.emptyState)}
                                    </div></div>
                                `;
                            }
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: I18N.errorTitle,
                        text: data.message
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: I18N.errorTitle,
                    text: I18N.deleteError
                });
            }
        }
    }
    // Handle create key form submission
    const createForm = document.getElementById('createKeyForm');
    createForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData();
        formData.append('create_key', '1');
        const service = createForm.querySelector('[name="service"]').value;
        const accessOn = createForm.querySelector('[name="websocket_access"]').checked;
        const globalOn = accessOn && createForm.querySelector('[name="websocket_global"]').checked;
        formData.append('service', service);
        formData.append('websocket_access', accessOn ? '1' : '0');
        formData.append('websocket_global', globalOn ? '1' : '0');
        try {
            const response = await fetch('api_keys.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                await Swal.fire({
                    icon: 'success',
                    title: I18N.createSuccessTitle,
                    html: `
                        <p>${result.message}</p>
                        <div class="sp-form-group">
                            <label class="sp-label">${I18N.newKeyLabel}</label>
                            <input class="sp-input" style="font-family:monospace;" type="text" value="${result.api_key}" readonly>
                            <p class="sp-help sp-help-info" style="margin-top:0.5rem;">${I18N.newKeyInfo}</p>
                        </div>
                    `,
                    showConfirmButton: true,
                    confirmButtonText: I18N.okBtn
                });
                // Check if table exists or if we need to create it
                let tableBody = document.getElementById('apiKeysTable');
                const noKeysNotification = document.querySelector('.sp-alert.sp-alert-info');
                if (!tableBody && noKeysNotification) {
                    // Replace notification with table
                    const box = noKeysNotification.closest('.sp-card');
                    noKeysNotification.remove();
                    box.innerHTML = `
                        <div class="sp-card-header"><h2 class="sp-card-title">${escapeHtml(I18N.existingHeading)}</h2></div>
                        <div class="sp-card-body">
                        <div class="sp-table-wrap">
                            <table class="sp-table">
                                <thead>
                                    <tr>
                                        <th>${escapeHtml(I18N.thService)}</th>
                                        <th>${escapeHtml(I18N.thApiKey)}</th>
                                        <th>${escapeHtml(I18N.thWebsocket)}</th>
                                        <th>${escapeHtml(I18N.thGlobal)}</th>
                                        <th>${escapeHtml(I18N.thActions)}</th>
                                    </tr>
                                </thead>
                                <tbody id="apiKeysTable"></tbody>
                            </table>
                        </div>
                        </div>
                    `;
                    tableBody = document.getElementById('apiKeysTable');
                }
                // Create new row
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-service', service);
                newRow.innerHTML = `
                    <td><strong>${escapeHtml(service)}</strong></td>
                    <td>
                        <div class="sp-btn-group">
                            <input class="sp-input api-key-input" type="text" value="${result.api_key}" readonly autocomplete="off" style="flex:1;font-family:monospace;-webkit-text-security:disc;">
                            <button class="sp-btn sp-btn-info toggle-visibility" title="${escapeHtml(I18N.toggleTitle)}">
                                <span class="icon">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </button>
                            <button class="sp-btn sp-btn-success copy-key" title="${escapeHtml(I18N.copyTitle)}">
                                <span class="icon">
                                    <i class="fas fa-copy"></i>
                                </span>
                            </button>
                        </div>
                    </td>
                    <td>${flagSwitch('access', service, accessOn, false)}</td>
                    <td>${flagSwitch('global', service, globalOn, !accessOn)}</td>
                    <td>
                        <div class="sp-btn-group">
                            <button class="sp-btn sp-btn-warning sp-btn-sm regenerate-key" data-service="${escapeHtml(service)}">
                                <span class="icon">
                                    <i class="fas fa-sync"></i>
                                </span>
                                <span>${escapeHtml(I18N.regenerateButton)}</span>
                            </button>
                            <button class="sp-btn sp-btn-danger sp-btn-sm delete-key" data-service="${escapeHtml(service)}">
                                <span class="icon">
                                    <i class="fas fa-trash"></i>
                                </span>
                                <span>${escapeHtml(I18N.deleteButton)}</span>
                            </button>
                        </div>
                    </td>
                `;
                tableBody.appendChild(newRow);
                // Attach event listeners to new row
                attachRowEventListeners(newRow);
                // Clear form
                createForm.reset();
                syncCreateGlobal();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: I18N.errorTitle,
                    text: result.message
                });
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: I18N.errorTitle,
                text: I18N.createError
            });
        }
    });
    function syncCreateGlobal() {
        const accessEl = document.getElementById('create-ws-access');
        const globalEl = document.getElementById('create-ws-global');
        if (!accessEl || !globalEl) return;
        if (!accessEl.checked) {
            globalEl.checked = false;
            globalEl.disabled = true;
        } else {
            globalEl.disabled = false;
        }
    }
    const createAccess = document.getElementById('create-ws-access');
    const createGlobal = document.getElementById('create-ws-global');
    if (createAccess && createGlobal) {
        createAccess.addEventListener('change', syncCreateGlobal);
        createGlobal.addEventListener('change', function() {
            if (this.checked) createAccess.checked = true;
            syncCreateGlobal();
        });
        syncCreateGlobal();
    }
    // Initialize event listeners for existing rows
    document.querySelectorAll('#apiKeysTable tr').forEach(row => {
        attachRowEventListeners(row);
    });
});
</script>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>