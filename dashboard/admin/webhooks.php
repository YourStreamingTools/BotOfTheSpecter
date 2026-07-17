<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_webhooks_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
session_write_close();

// Public base URL of the API server that receives the inbound webhooks.
$apiBase = 'https://api.botofthespecter.com';

// JSON responder for AJAX actions
function wh_json($payload) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Normalize an event name the same way the WebSocket /notify endpoint does
// (uppercased, spaces -> underscores) so what the admin sees matches delivery.
function wh_normalize_event($name) {
    return strtoupper(str_replace(' ', '_', trim($name)));
}

// Generate a webhook secret
function wh_make_secret() {
    return bin2hex(random_bytes(24)); // 48 hex chars
}

// Default header name for a given verify mode
function wh_default_header($mode) {
    return ($mode === 'hmac') ? 'X-Webhook-Signature' : 'X-Webhook-Secret';
}

$validScopes = ['channel', 'global'];
$validModes  = ['none', 'secret', 'hmac'];

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_webhook'])) {
    $name          = trim($_POST['name'] ?? '');
    $slug          = strtolower(trim($_POST['slug'] ?? ''));
    $service       = trim($_POST['service'] ?? '');
    $event_name    = wh_normalize_event($_POST['event_name'] ?? '');
    $scope         = in_array($_POST['scope'] ?? '', $validScopes, true) ? $_POST['scope'] : 'channel';
    $target        = trim($_POST['target_username'] ?? '');
    $verify_mode   = in_array($_POST['verify_mode'] ?? '', $validModes, true) ? $_POST['verify_mode'] : 'secret';
    $secret_header = trim($_POST['secret_header'] ?? '');

    if ($name === '')            wh_json(['success' => false, 'message' => t('admin_webhooks_err_name_required')]);
    if ($slug === '')            wh_json(['success' => false, 'message' => t('admin_webhooks_err_slug_required')]);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$/', $slug)) wh_json(['success' => false, 'message' => t('admin_webhooks_err_slug_format')]);
    if ($service === '')         wh_json(['success' => false, 'message' => t('admin_webhooks_err_service_required')]);
    if ($event_name === '')      wh_json(['success' => false, 'message' => t('admin_webhooks_err_event_required')]);
    if ($scope === 'channel' && $target === '') wh_json(['success' => false, 'message' => t('admin_webhooks_err_target_required')]);
    if ($scope === 'global' && $verify_mode === 'none') wh_json(['success' => false, 'message' => t('admin_webhooks_err_global_needs_secret')]);

    // Uniqueness check
    $chk = $conn->prepare("SELECT id FROM custom_webhooks WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); wh_json(['success' => false, 'message' => t('admin_webhooks_err_slug_taken')]); }
    $chk->close();

    if ($secret_header === '') $secret_header = wh_default_header($verify_mode);
    $secret = ($verify_mode === 'none') ? null : wh_make_secret();
    $target_val = ($scope === 'channel') ? $target : null;
    $created_by = $_SESSION['username'] ?? '';
    $enabled = 1;

    try {
        $stmt = $conn->prepare("INSERT INTO custom_webhooks
            (slug, name, service, event_name, scope, target_username, verify_mode, secret, secret_header, enabled, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssssssis', $slug, $name, $service, $event_name, $scope, $target_val, $verify_mode, $secret, $secret_header, $enabled, $created_by);
        if ($stmt->execute()) {
            $stmt->close();
            admin_audit_log('custom_webhook_create', 'success', ['slug' => $slug, 'service' => $service, 'scope' => $scope, 'verify_mode' => $verify_mode], 'custom_webhook', $slug);
            wh_json(['success' => true, 'message' => t('admin_webhooks_msg_created'), 'secret' => $secret, 'secret_header' => $secret_header]);
        } else {
            $err = $stmt->error; $stmt->close();
            wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$err])]);
        }
    } catch (Exception $e) {
        wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$e->getMessage()])]);
    }
}

// Update (slug is immutable to avoid breaking live integrations)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_webhook'])) {
    $id            = (int) ($_POST['webhook_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $service       = trim($_POST['service'] ?? '');
    $event_name    = wh_normalize_event($_POST['event_name'] ?? '');
    $scope         = in_array($_POST['scope'] ?? '', $validScopes, true) ? $_POST['scope'] : 'channel';
    $target        = trim($_POST['target_username'] ?? '');
    $verify_mode   = in_array($_POST['verify_mode'] ?? '', $validModes, true) ? $_POST['verify_mode'] : 'secret';
    $secret_header = trim($_POST['secret_header'] ?? '');

    if ($id <= 0)                wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);
    if ($name === '')            wh_json(['success' => false, 'message' => t('admin_webhooks_err_name_required')]);
    if ($service === '')         wh_json(['success' => false, 'message' => t('admin_webhooks_err_service_required')]);
    if ($event_name === '')      wh_json(['success' => false, 'message' => t('admin_webhooks_err_event_required')]);
    if ($scope === 'channel' && $target === '') wh_json(['success' => false, 'message' => t('admin_webhooks_err_target_required')]);
    if ($scope === 'global' && $verify_mode === 'none') wh_json(['success' => false, 'message' => t('admin_webhooks_err_global_needs_secret')]);

    // Load current row (need slug for audit + current secret state)
    $cur = $conn->prepare("SELECT slug, secret FROM custom_webhooks WHERE id = ? LIMIT 1");
    $cur->bind_param('i', $id);
    $cur->execute();
    $curRes = $cur->get_result();
    $curRow = $curRes ? $curRes->fetch_assoc() : null;
    $cur->close();
    if (!$curRow) wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);

    if ($secret_header === '') $secret_header = wh_default_header($verify_mode);
    $target_val = ($scope === 'channel') ? $target : null;

    // If switching to a verifying mode without an existing secret, mint one.
    $newSecret = null;
    if ($verify_mode !== 'none' && empty($curRow['secret'])) {
        $newSecret = wh_make_secret();
    }

    try {
        // Single atomic UPDATE; include the freshly minted secret when one was created.
        if ($newSecret !== null) {
            $stmt = $conn->prepare("UPDATE custom_webhooks SET
                name = ?, service = ?, event_name = ?, scope = ?, target_username = ?, verify_mode = ?, secret_header = ?, secret = ?
                WHERE id = ?");
            $stmt->bind_param('ssssssssi', $name, $service, $event_name, $scope, $target_val, $verify_mode, $secret_header, $newSecret, $id);
        } else {
            $stmt = $conn->prepare("UPDATE custom_webhooks SET
                name = ?, service = ?, event_name = ?, scope = ?, target_username = ?, verify_mode = ?, secret_header = ?
                WHERE id = ?");
            $stmt->bind_param('sssssssi', $name, $service, $event_name, $scope, $target_val, $verify_mode, $secret_header, $id);
        }
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        if (!$ok) {
            wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$err])]);
        }
        admin_audit_log('custom_webhook_update', 'success', ['slug' => $curRow['slug'], 'service' => $service, 'scope' => $scope, 'verify_mode' => $verify_mode], 'custom_webhook', $curRow['slug']);
        wh_json(['success' => true, 'message' => t('admin_webhooks_msg_updated'), 'secret' => $newSecret, 'secret_header' => $secret_header]);
    } catch (Exception $e) {
        wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$e->getMessage()])]);
    }
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_webhook'])) {
    $id = (int) ($_POST['webhook_id'] ?? 0);
    if ($id <= 0) wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);
    // Capture slug for audit
    $slug = '';
    $g = $conn->prepare("SELECT slug FROM custom_webhooks WHERE id = ? LIMIT 1");
    $g->bind_param('i', $id); $g->execute(); $gr = $g->get_result();
    if ($gr && ($row = $gr->fetch_assoc())) { $slug = $row['slug']; }
    $g->close();
    try {
        $stmt = $conn->prepare("DELETE FROM custom_webhooks WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            admin_audit_log('custom_webhook_delete', 'warning', ['slug' => $slug], 'custom_webhook', $slug);
            wh_json(['success' => true, 'message' => t('admin_webhooks_msg_deleted')]);
        } else {
            $stmt->close();
            wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);
        }
    } catch (Exception $e) {
        wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$e->getMessage()])]);
    }
}

// Toggle enabled
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_webhook'])) {
    $id = (int) ($_POST['webhook_id'] ?? 0);
    $enabled = (int) (!empty($_POST['enabled']) ? 1 : 0);
    if ($id <= 0) wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);
    try {
        $stmt = $conn->prepare("UPDATE custom_webhooks SET enabled = ? WHERE id = ?");
        $stmt->bind_param('ii', $enabled, $id);
        $stmt->execute();
        $stmt->close();
        admin_audit_log('custom_webhook_toggle', 'info', ['id' => $id, 'enabled' => $enabled], 'custom_webhook', (string) $id);
        wh_json(['success' => true, 'message' => t('admin_webhooks_msg_toggled')]);
    } catch (Exception $e) {
        wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$e->getMessage()])]);
    }
}

// Regenerate secret
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_secret'])) {
    $id = (int) ($_POST['webhook_id'] ?? 0);
    if ($id <= 0) wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);
    // Find slug + mode; only meaningful for verifying webhooks
    $g = $conn->prepare("SELECT slug, verify_mode, secret_header FROM custom_webhooks WHERE id = ? LIMIT 1");
    $g->bind_param('i', $id); $g->execute(); $gr = $g->get_result();
    $row = $gr ? $gr->fetch_assoc() : null; $g->close();
    if (!$row) wh_json(['success' => false, 'message' => t('admin_webhooks_err_not_found')]);
    $secret = wh_make_secret();
    try {
        $stmt = $conn->prepare("UPDATE custom_webhooks SET secret = ? WHERE id = ?");
        $stmt->bind_param('si', $secret, $id);
        $stmt->execute();
        $stmt->close();
        admin_audit_log('custom_webhook_regen_secret', 'warning', ['slug' => $row['slug']], 'custom_webhook', $row['slug']);
        wh_json(['success' => true, 'message' => t('admin_webhooks_msg_regenerated'), 'secret' => $secret, 'secret_header' => $row['secret_header']]);
    } catch (Exception $e) {
        wh_json(['success' => false, 'message' => t('admin_webhooks_err_generic', [$e->getMessage()])]);
    }
}

// Fetch all webhooks for display
$webhooks = [];
if ($conn) {
    $result = $conn->query("SELECT id, slug, name, service, event_name, scope, target_username, verify_mode, secret, secret_header, enabled, last_received_at, received_count FROM custom_webhooks ORDER BY name");
    if ($result) {
        while ($row = $result->fetch_assoc()) { $webhooks[] = $row; }
        $result->free();
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-satellite-dish"></i> <?php echo t('admin_webhooks_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p style="color:var(--text-secondary);"><?php echo t('admin_webhooks_intro'); ?></p>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title" id="formHeading"><?php echo t('admin_webhooks_create_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <form id="webhookForm">
        <input type="hidden" name="webhook_id" id="webhook_id" value="">
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_webhooks_field_name'); ?></label>
            <input class="sp-input" type="text" name="name" id="f_name" placeholder="<?php echo htmlspecialchars(t('admin_webhooks_field_name_ph')); ?>" required>
        </div>
        <div class="sp-form-group" id="slugGroup">
            <label class="sp-label"><?php echo t('admin_webhooks_field_slug'); ?></label>
            <input class="sp-input" type="text" name="slug" id="f_slug" placeholder="<?php echo htmlspecialchars(t('admin_webhooks_field_slug_ph')); ?>" style="font-family:monospace;">
            <p class="sp-help"><?php echo t('admin_webhooks_field_slug_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_webhooks_field_service'); ?></label>
            <input class="sp-input" type="text" name="service" id="f_service" placeholder="<?php echo htmlspecialchars(t('admin_webhooks_field_service_ph')); ?>" required>
            <p class="sp-help"><?php echo t('admin_webhooks_field_service_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_webhooks_field_event'); ?></label>
            <input class="sp-input" type="text" name="event_name" id="f_event" placeholder="<?php echo htmlspecialchars(t('admin_webhooks_field_event_ph')); ?>" style="font-family:monospace;" required>
            <p class="sp-help"><?php echo t('admin_webhooks_field_event_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_webhooks_field_scope'); ?></label>
            <div class="select">
                <select class="sp-input" name="scope" id="f_scope">
                    <option value="channel"><?php echo t('admin_webhooks_scope_channel'); ?></option>
                    <option value="global"><?php echo t('admin_webhooks_scope_global'); ?></option>
                </select>
            </div>
            <p class="sp-help"><?php echo t('admin_webhooks_scope_help'); ?></p>
        </div>
        <div class="sp-form-group" id="targetGroup">
            <label class="sp-label"><?php echo t('admin_webhooks_field_target'); ?></label>
            <input class="sp-input" type="text" name="target_username" id="f_target" placeholder="<?php echo htmlspecialchars(t('admin_webhooks_field_target_ph')); ?>">
            <p class="sp-help"><?php echo t('admin_webhooks_field_target_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_webhooks_field_verify'); ?></label>
            <div class="select">
                <select class="sp-input" name="verify_mode" id="f_verify">
                    <option value="secret"><?php echo t('admin_webhooks_verify_secret'); ?></option>
                    <option value="hmac"><?php echo t('admin_webhooks_verify_hmac'); ?></option>
                    <option value="none"><?php echo t('admin_webhooks_verify_none'); ?></option>
                </select>
            </div>
        </div>
        <div class="sp-form-group" id="headerGroup">
            <label class="sp-label"><?php echo t('admin_webhooks_field_secret_header'); ?></label>
            <input class="sp-input" type="text" name="secret_header" id="f_header" placeholder="X-Webhook-Secret" style="font-family:monospace;">
            <p class="sp-help"><?php echo t('admin_webhooks_field_secret_header_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <button type="submit" class="sp-btn sp-btn-primary" id="submitBtn">
                <span class="icon"><i class="fas fa-plus"></i></span>
                <span id="submitBtnText"><?php echo t('admin_webhooks_create_button'); ?></span>
            </button>
            <button type="button" class="sp-btn" id="cancelEditBtn" style="display:none;">
                <span><?php echo t('admin_webhooks_cancel_edit_button'); ?></span>
            </button>
        </div>
    </form>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_webhooks_existing_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <?php if (empty($webhooks)): ?>
        <div class="sp-alert sp-alert-info"><?php echo t('admin_webhooks_empty_state'); ?></div>
    <?php else: ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_webhooks_th_name'); ?></th>
                        <th><?php echo t('admin_webhooks_th_url'); ?></th>
                        <th><?php echo t('admin_webhooks_th_routing'); ?></th>
                        <th><?php echo t('admin_webhooks_th_verify'); ?></th>
                        <th><?php echo t('admin_webhooks_th_enabled'); ?></th>
                        <th><?php echo t('admin_webhooks_th_received'); ?></th>
                        <th><?php echo t('admin_webhooks_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="webhooksTable">
                    <?php foreach ($webhooks as $wh): ?>
                        <?php $url = $apiBase . '/webhook/' . $wh['slug']; ?>
                        <tr data-id="<?php echo (int) $wh['id']; ?>"
                            data-name="<?php echo htmlspecialchars($wh['name']); ?>"
                            data-slug="<?php echo htmlspecialchars($wh['slug']); ?>"
                            data-service="<?php echo htmlspecialchars($wh['service']); ?>"
                            data-event="<?php echo htmlspecialchars($wh['event_name']); ?>"
                            data-scope="<?php echo htmlspecialchars($wh['scope']); ?>"
                            data-target="<?php echo htmlspecialchars($wh['target_username'] ?? ''); ?>"
                            data-verify="<?php echo htmlspecialchars($wh['verify_mode']); ?>"
                            data-header="<?php echo htmlspecialchars($wh['secret_header']); ?>"
                            data-enabled="<?php echo (int) $wh['enabled']; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($wh['name']); ?></strong><br>
                                <span class="sp-help"><?php echo htmlspecialchars($wh['service']); ?> &middot; <code><?php echo htmlspecialchars($wh['event_name']); ?></code></span>
                            </td>
                            <td>
                                <div class="sp-btn-group">
                                    <input class="sp-input" type="text" value="<?php echo htmlspecialchars($url); ?>" readonly autocomplete="off" style="flex:1;font-family:monospace;min-width:240px;">
                                    <button class="sp-btn sp-btn-success copy-url" title="<?php echo htmlspecialchars(t('admin_webhooks_copy_url_title')); ?>">
                                        <span class="icon"><i class="fas fa-copy"></i></span>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <?php if ($wh['scope'] === 'global'): ?>
                                    <span class="sp-badge sp-badge-info"><?php echo t('admin_webhooks_scope_global'); ?></span>
                                <?php else: ?>
                                    <span class="sp-badge sp-badge-info"><?php echo t('admin_webhooks_scope_channel'); ?></span><br>
                                    <span class="sp-help"><?php echo htmlspecialchars($wh['target_username'] ?? ''); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars($wh['verify_mode']); ?></code>
                                <?php if ($wh['verify_mode'] !== 'none' && !empty($wh['secret'])): ?>
                                    <br><span class="sp-help" style="font-family:monospace;">&bull;&bull;&bull;&bull;<?php echo htmlspecialchars(substr($wh['secret'], -4)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sp-badge <?php echo $wh['enabled'] ? 'sp-badge-success' : 'sp-badge-dark'; ?>"><?php echo $wh['enabled'] ? t('admin_webhooks_enabled_yes') : t('admin_webhooks_enabled_no'); ?></span>
                            </td>
                            <td>
                                <strong><?php echo (int) $wh['received_count']; ?></strong><br>
                                <span class="sp-help"><?php echo $wh['last_received_at'] ? htmlspecialchars($wh['last_received_at']) : t('admin_webhooks_never_received'); ?></span>
                            </td>
                            <td>
                                <div class="sp-btn-group">
                                    <button class="sp-btn sp-btn-info sp-btn-sm edit-webhook"><span class="icon"><i class="fas fa-edit"></i></span></button>
                                    <button class="sp-btn sp-btn-sm toggle-webhook"><span class="icon"><i class="fas <?php echo $wh['enabled'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i></span></button>
                                    <?php if ($wh['verify_mode'] !== 'none'): ?>
                                    <button class="sp-btn sp-btn-warning sp-btn-sm regen-secret"><span class="icon"><i class="fas fa-sync"></i></span></button>
                                    <?php endif; ?>
                                    <button class="sp-btn sp-btn-danger sp-btn-sm delete-webhook"><span class="icon"><i class="fas fa-trash"></i></span></button>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const I18N = {
        errorTitle:        <?php echo json_encode(t('admin_webhooks_js_error_title')); ?>,
        okBtn:             <?php echo json_encode(t('admin_webhooks_js_ok_btn')); ?>,
        cancelBtn:         <?php echo json_encode(t('admin_webhooks_js_cancel_btn')); ?>,
        copyFailed:        <?php echo json_encode(t('admin_webhooks_js_copy_failed')); ?>,
        requestFailed:     <?php echo json_encode(t('admin_webhooks_js_request_failed')); ?>,
        secretLabel:       <?php echo json_encode(t('admin_webhooks_js_secret_label')); ?>,
        secretWarning:     <?php echo json_encode(t('admin_webhooks_js_secret_warning')); ?>,
        headerLabel:       <?php echo json_encode(t('admin_webhooks_js_header_label')); ?>,
        createdTitle:      <?php echo json_encode(t('admin_webhooks_js_created_title')); ?>,
        updatedTitle:      <?php echo json_encode(t('admin_webhooks_js_updated_title')); ?>,
        deletedTitle:      <?php echo json_encode(t('admin_webhooks_js_deleted_title')); ?>,
        regenTitle:        <?php echo json_encode(t('admin_webhooks_js_regen_title')); ?>,
        deleteConfirmTitle:<?php echo json_encode(t('admin_webhooks_js_delete_confirm_title')); ?>,
        deleteConfirmText: <?php echo json_encode(t('admin_webhooks_js_delete_confirm_text')); ?>,
        deleteConfirmBtn:  <?php echo json_encode(t('admin_webhooks_js_delete_confirm_btn')); ?>,
        regenConfirmTitle: <?php echo json_encode(t('admin_webhooks_js_regen_confirm_title')); ?>,
        regenConfirmText:  <?php echo json_encode(t('admin_webhooks_js_regen_confirm_text')); ?>,
        regenConfirmBtn:   <?php echo json_encode(t('admin_webhooks_js_regen_confirm_btn')); ?>
    };

    const form        = document.getElementById('webhookForm');
    const idField     = document.getElementById('webhook_id');
    const slugField   = document.getElementById('f_slug');
    const slugGroup   = document.getElementById('slugGroup');
    const scopeSel    = document.getElementById('f_scope');
    const targetGroup = document.getElementById('targetGroup');
    const verifySel   = document.getElementById('f_verify');
    const headerGroup = document.getElementById('headerGroup');
    const headerField = document.getElementById('f_header');
    const heading     = document.getElementById('formHeading');
    const submitText  = document.getElementById('submitBtnText');
    const cancelBtn   = document.getElementById('cancelEditBtn');

    function refreshConditionalFields() {
        targetGroup.style.display = (scopeSel.value === 'channel') ? '' : 'none';
        headerGroup.style.display = (verifySel.value === 'none') ? 'none' : '';
        if (!headerField.value) {
            headerField.placeholder = (verifySel.value === 'hmac') ? 'X-Webhook-Signature' : 'X-Webhook-Secret';
        }
    }
    scopeSel.addEventListener('change', refreshConditionalFields);
    verifySel.addEventListener('change', refreshConditionalFields);
    refreshConditionalFields();

    function resetToCreate() {
        form.reset();
        idField.value = '';
        slugField.disabled = false;
        slugGroup.style.display = '';
        heading.textContent = <?php echo json_encode(t('admin_webhooks_create_heading')); ?>;
        submitText.textContent = <?php echo json_encode(t('admin_webhooks_create_button')); ?>;
        cancelBtn.style.display = 'none';
        refreshConditionalFields();
    }
    cancelBtn.addEventListener('click', resetToCreate);

    // Show a generated secret once, then reload
    function showSecretThenReload(title, message, secret, header) {
        if (!secret) {
            Swal.fire({ icon: 'success', title: title, text: message }).then(() => location.reload());
            return;
        }
        Swal.fire({
            icon: 'success',
            title: title,
            html: `
                <p>${message}</p>
                <div class="sp-form-group" style="text-align:left;">
                    <label class="sp-label">${I18N.headerLabel}</label>
                    <input class="sp-input" style="font-family:monospace;" type="text" value="${header || ''}" readonly>
                </div>
                <div class="sp-form-group" style="text-align:left;">
                    <label class="sp-label">${I18N.secretLabel}</label>
                    <input class="sp-input" style="font-family:monospace;" type="text" value="${secret}" readonly>
                    <p class="sp-help sp-help-danger" style="margin-top:0.5rem;">${I18N.secretWarning}</p>
                </div>
            `,
            confirmButtonText: I18N.okBtn
        }).then(() => location.reload());
    }

    async function postAction(payload, opts) {
        const fd = new FormData();
        Object.keys(payload).forEach(k => fd.append(k, payload[k]));
        try {
            const res = await fetch('webhooks.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                opts.onSuccess(data);
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.requestFailed });
        }
    }

    // Create / Update submit
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const editing = idField.value !== '';
        const payload = {
            name: document.getElementById('f_name').value,
            service: document.getElementById('f_service').value,
            event_name: document.getElementById('f_event').value,
            scope: scopeSel.value,
            target_username: document.getElementById('f_target').value,
            verify_mode: verifySel.value,
            secret_header: headerField.value
        };
        if (editing) {
            payload.update_webhook = '1';
            payload.webhook_id = idField.value;
            postAction(payload, { onSuccess: (d) => showSecretThenReload(I18N.updatedTitle, d.message, d.secret, d.secret_header) });
        } else {
            payload.create_webhook = '1';
            payload.slug = slugField.value;
            postAction(payload, { onSuccess: (d) => showSecretThenReload(I18N.createdTitle, d.message, d.secret, d.secret_header) });
        }
    });

    function attachRow(row) {
        const copyBtn = row.querySelector('.copy-url');
        if (copyBtn) copyBtn.addEventListener('click', async function() {
            const input = this.closest('.sp-btn-group').querySelector('input');
            try {
                await navigator.clipboard.writeText(input.value);
                const icon = this.querySelector('i');
                icon.classList.remove('fa-copy'); icon.classList.add('fa-check');
                setTimeout(() => { icon.classList.remove('fa-check'); icon.classList.add('fa-copy'); }, 2000);
            } catch (err) {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.copyFailed });
            }
        });

        const editBtn = row.querySelector('.edit-webhook');
        if (editBtn) editBtn.addEventListener('click', function() {
            idField.value = row.dataset.id;
            document.getElementById('f_name').value = row.dataset.name;
            slugField.value = row.dataset.slug;
            slugField.disabled = true; // slug immutable on edit
            document.getElementById('f_service').value = row.dataset.service;
            document.getElementById('f_event').value = row.dataset.event;
            scopeSel.value = row.dataset.scope;
            document.getElementById('f_target').value = row.dataset.target;
            verifySel.value = row.dataset.verify;
            headerField.value = row.dataset.header;
            heading.textContent = <?php echo json_encode(t('admin_webhooks_edit_heading')); ?>;
            submitText.textContent = <?php echo json_encode(t('admin_webhooks_save_button')); ?>;
            cancelBtn.style.display = '';
            refreshConditionalFields();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        const toggleBtn = row.querySelector('.toggle-webhook');
        if (toggleBtn) toggleBtn.addEventListener('click', function() {
            const newEnabled = row.dataset.enabled === '1' ? 0 : 1;
            postAction({ toggle_webhook: '1', webhook_id: row.dataset.id, enabled: newEnabled }, { onSuccess: () => location.reload() });
        });

        const regenBtn = row.querySelector('.regen-secret');
        if (regenBtn) regenBtn.addEventListener('click', async function() {
            const r = await Swal.fire({
                icon: 'warning', title: I18N.regenConfirmTitle,
                text: I18N.regenConfirmText.replace('%s', row.dataset.name),
                showCancelButton: true, confirmButtonText: I18N.regenConfirmBtn, cancelButtonText: I18N.cancelBtn,
                confirmButtonColor: '#f39c12'
            });
            if (r.isConfirmed) {
                postAction({ regenerate_secret: '1', webhook_id: row.dataset.id }, { onSuccess: (d) => showSecretThenReload(I18N.regenTitle, d.message, d.secret, d.secret_header) });
            }
        });

        const delBtn = row.querySelector('.delete-webhook');
        if (delBtn) delBtn.addEventListener('click', async function() {
            const r = await Swal.fire({
                icon: 'warning', title: I18N.deleteConfirmTitle,
                text: I18N.deleteConfirmText.replace('%s', row.dataset.name),
                showCancelButton: true, confirmButtonText: I18N.deleteConfirmBtn, cancelButtonText: I18N.cancelBtn,
                confirmButtonColor: '#f14668'
            });
            if (r.isConfirmed) {
                postAction({ delete_webhook: '1', webhook_id: row.dataset.id }, { onSuccess: (d) => {
                    Swal.fire({ icon: 'success', title: I18N.deletedTitle, text: d.message, timer: 1500, showConfirmButton: false }).then(() => location.reload());
                }});
            }
        });
    }

    document.querySelectorAll('#webhooksTable tr').forEach(attachRow);
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
