<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('caddy_page_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once __DIR__ . '/../includes/caddy_admin.php';
include "../includes/userdata.php";
session_write_close();

// ---- Determine super-admin (controls write access) ----
$isSuperAdmin = false;
if (isset($conn) && ($uid = (int) ($_SESSION['user_id'] ?? 0)) > 0) {
    $stmt = $conn->prepare("SELECT super_admin FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($saFlag);
        if ($stmt->fetch()) {
            $isSuperAdmin = ((int) $saFlag === 1);
        }
        $stmt->close();
    }
}

// ---- AJAX action handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $action = (string) $_POST['action'];

    // -- Reads: available to any admin --
    if ($action === 'caddy_version') {
        if (empty($web_ssh_host)) {
            echo json_encode(['ok' => false, 'error' => t('caddy_err_ssh_not_configured')]);
            exit;
        }
        $version = '';
        try {
            $sshConn = SSHConnectionManager::getConnection($web_ssh_host, $web_ssh_username, $web_ssh_password);
            if ($sshConn) {
                $version = SSHConnectionManager::executeCommand($sshConn, 'caddy version');
            }
        } catch (Exception $e) {
            $version = '';
        }
        $version = is_string($version) ? trim($version) : '';
        echo json_encode(['ok' => ($version !== ''), 'version' => $version]);
        exit;
    }

    // -- Writes: super admin only (server-side enforcement, not just UI) --
    $writeActions = ['api_call', 'adapt_config', 'load_config', 'reload_caddy', 'restart_caddy'];
    if (in_array($action, $writeActions, true)) {
        if (!$isSuperAdmin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => t('caddy_err_not_super')]);
            exit;
        }

        if ($action === 'api_call') {
            $method = strtoupper((string) ($_POST['method'] ?? 'GET'));
            $path = (string) ($_POST['path'] ?? '');
            $body = (string) ($_POST['body'] ?? '');
            $res = caddy_admin_request($method, $path, ($body !== '' ? $body : null));
            admin_audit_log(
                'caddy_api_call',
                $res['ok'] ? 'success' : 'failed',
                ['method' => $method, 'path' => $path, 'status' => $res['status'], 'error' => $res['error']],
                'caddy',
                $method . ' ' . $path
            );
            if (is_array($res['body'])) {
                $res['body'] = caddy_redact_secrets($res['body']);
            }
            echo json_encode($res);
            exit;
        }

        if ($action === 'adapt_config') {
            $config = (string) ($_POST['config'] ?? '');
            $format = (string) ($_POST['format'] ?? 'caddyfile');
            $contentType = ($format === 'json') ? 'application/json' : 'text/caddyfile';
            $res = caddy_admin_request('POST', '/adapt', $config, $contentType);
            admin_audit_log(
                'caddy_adapt',
                $res['ok'] ? 'success' : 'failed',
                ['format' => $format, 'status' => $res['status'], 'error' => $res['error']],
                'caddy',
                '/adapt'
            );
            if (is_array($res['body'])) {
                $res['body'] = caddy_redact_secrets($res['body']);
            }
            echo json_encode($res);
            exit;
        }

        if ($action === 'load_config') {
            $config = (string) ($_POST['config'] ?? '');
            $format = (string) ($_POST['format'] ?? 'json');
            $contentType = ($format === 'caddyfile') ? 'text/caddyfile' : 'application/json';
            $res = caddy_admin_request('POST', '/load', $config, $contentType);
            admin_audit_log(
                'caddy_load',
                $res['ok'] ? 'success' : 'failed',
                ['format' => $format, 'status' => $res['status'], 'error' => $res['error']],
                'caddy',
                '/load'
            );
            $msg = $res['ok'] ? t('caddy_msg_loaded') : ($res['error'] ?? 'Error');
            echo json_encode(['ok' => $res['ok'], 'status' => $res['status'], 'message' => $msg, 'body' => (is_array($res['body']) ? caddy_redact_secrets($res['body']) : $res['body'])]);
            exit;
        }

        if ($action === 'reload_caddy' || $action === 'restart_caddy') {
            $verb = ($action === 'reload_caddy') ? 'reload' : 'restart';
            if (empty($web_ssh_host)) {
                echo json_encode(['ok' => false, 'error' => t('caddy_err_ssh_not_configured')]);
                exit;
            }
            $ok = false;
            $output = '';
            try {
                $sshConn = SSHConnectionManager::getConnection($web_ssh_host, $web_ssh_username, $web_ssh_password);
                if ($sshConn) {
                    $output = SSHConnectionManager::executeCommand($sshConn, "sudo -n systemctl $verb caddy");
                    $exitStatus = SSHConnectionManager::$last_exit_status ?? null;
                    // Mirror admin/index.php success heuristic: exit 0, or null-but-ran.
                    if ($exitStatus === 0 || $exitStatus === '0' || intval($exitStatus) === 0) {
                        $ok = true;
                    } elseif ($exitStatus === null && $output !== false) {
                        $ok = true;
                    }
                } else {
                    $output = 'SSH connection failed';
                }
            } catch (Exception $e) {
                $output = 'Exception: ' . $e->getMessage();
            }
            admin_audit_log(
                'caddy_' . $verb,
                $ok ? 'success' : 'failed',
                ['output_preview' => mb_substr((string) $output, 0, 300)],
                'caddy',
                $verb
            );
            $msg = $ok ? ($verb === 'reload' ? t('caddy_msg_reloaded') : t('caddy_msg_restarted')) : (string) $output;
            echo json_encode(['ok' => $ok, 'message' => $msg, 'output' => (string) $output]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ---- Read-only data for the page render ----
$cfgRes = caddy_admin_request('GET', '/config/');
$caddyUp = $cfgRes['ok'];
$config = is_array($cfgRes['body']) ? $cfgRes['body'] : [];
$sites = caddy_parse_sites($config);
$tls = caddy_summarize_tls($config);
$upRes = caddy_admin_request('GET', '/reverse_proxy/upstreams');
$upstreams = is_array($upRes['body']) ? $upRes['body'] : [];
$redactedConfigJson = json_encode(caddy_redact_secrets($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header"><h1 class="sp-card-title"><i class="fas fa-server"></i> <?php echo t('caddy_page_title'); ?></h1></div>
    <div class="sp-card-body">
        <?php if (!$isSuperAdmin): ?>
            <div class="sp-alert sp-alert-info"><i class="fas fa-eye"></i> <?php echo t('caddy_readonly_notice'); ?></div>
        <?php endif; ?>
        <span class="sp-badge <?php echo $caddyUp ? 'sp-badge-green' : 'sp-badge-red'; ?>">
            <?php echo $caddyUp ? t('caddy_status_running') : t('caddy_status_unreachable'); ?>
        </span>
        <span style="margin-left:1rem; color:var(--text-muted);">
            <?php echo t('caddy_version_label'); ?>: <span id="caddy-version-value">&mdash;</span>
        </span>
        <button class="sp-btn sp-btn-sm" id="caddy-version-btn" type="button"><i class="fas fa-rotate"></i> <?php echo t('caddy_check_version'); ?></button>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header"><h2 class="sp-card-title"><?php echo t('caddy_sites_heading'); ?></h2></div>
    <div class="sp-card-body sp-table-wrap">
        <table class="sp-table">
            <thead><tr>
                <th><?php echo t('caddy_th_server'); ?></th>
                <th><?php echo t('caddy_th_listen'); ?></th>
                <th><?php echo t('caddy_th_hosts'); ?></th>
                <th><?php echo t('caddy_th_handlers'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (!empty($sites)): foreach ($sites as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['server']); ?></td>
                    <td><?php echo htmlspecialchars(implode(', ', $s['listen'])); ?></td>
                    <td><?php echo htmlspecialchars(implode(', ', $s['hosts'])); ?></td>
                    <td><?php echo htmlspecialchars(implode(', ', $s['handlers'])); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" style="color:var(--text-muted);"><?php echo t('caddy_no_data'); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header"><h2 class="sp-card-title"><?php echo t('caddy_tls_heading'); ?></h2></div>
    <div class="sp-card-body">
        <p><?php echo t('caddy_tls_email'); ?>: <strong><?php echo htmlspecialchars((string) ($tls['acme_email'] ?? '—')); ?></strong></p>
        <p><?php echo t('caddy_tls_dns_provider'); ?>: <strong><?php echo htmlspecialchars((string) ($tls['dns_provider'] ?? '—')); ?></strong></p>
        <p><?php echo t('caddy_tls_policies'); ?>: <strong><?php echo (int) $tls['policies']; ?></strong></p>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header"><h2 class="sp-card-title"><?php echo t('caddy_upstreams_heading'); ?></h2></div>
    <div class="sp-card-body">
        <?php if (!empty($upstreams)): ?>
            <pre style="max-height:280px; overflow:auto; background:var(--bg-input); padding:0.75rem; border-radius:6px;"><?php echo htmlspecialchars(json_encode($upstreams, JSON_PRETTY_PRINT)); ?></pre>
        <?php else: ?>
            <span style="color:var(--text-muted);"><?php echo t('caddy_upstreams_none'); ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('caddy_config_heading'); ?></h2>
        <button class="sp-btn sp-btn-sm" id="caddy-config-toggle" type="button"><?php echo t('caddy_config_toggle'); ?></button>
    </div>
    <div class="sp-card-body">
        <pre id="caddy-config-view" style="display:none; max-height:480px; overflow:auto; background:var(--bg-input); padding:0.75rem; border-radius:6px;"><?php echo htmlspecialchars((string) $redactedConfigJson); ?></pre>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="sp-card">
    <div class="sp-card-header"><h2 class="sp-card-title"><i class="fas fa-sliders"></i> <?php echo t('caddy_control_heading'); ?></h2></div>
    <div class="sp-card-body">
        <div class="sp-alert sp-alert-warning"><i class="fas fa-triangle-exclamation"></i> <?php echo t('caddy_control_drift_notice'); ?></div>

        <h3 class="sp-card-title" style="font-size:1rem;"><?php echo t('caddy_console_heading'); ?></h3>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-start; margin-bottom:0.5rem;">
            <select id="caddy-method" class="sp-input" style="flex:0 0 110px;">
                <option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option>
            </select>
            <input id="caddy-path" class="sp-input" style="flex:1 1 280px;" value="/config/" aria-label="<?php echo htmlspecialchars(t('caddy_console_path')); ?>">
            <button class="sp-btn sp-btn-primary" id="caddy-send" type="button"><?php echo t('caddy_console_send'); ?></button>
        </div>
        <textarea id="caddy-body" class="sp-input" rows="4" placeholder="<?php echo htmlspecialchars(t('caddy_console_body')); ?>" style="width:100%; font-family:monospace;"></textarea>

        <hr>
        <h3 class="sp-card-title" style="font-size:1rem;"><?php echo t('caddy_load_heading'); ?></h3>
        <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;">
            <label><?php echo t('caddy_load_format'); ?>:</label>
            <select id="caddy-load-format" class="sp-input" style="flex:0 0 160px;">
                <option value="json">application/json</option>
                <option value="caddyfile">text/caddyfile</option>
            </select>
            <button class="sp-btn sp-btn-info" id="caddy-validate" type="button"><?php echo t('caddy_load_validate'); ?></button>
            <button class="sp-btn sp-btn-danger" id="caddy-apply" type="button"><?php echo t('caddy_load_apply'); ?></button>
        </div>
        <textarea id="caddy-load-config" class="sp-input" rows="6" style="width:100%; font-family:monospace;"></textarea>

        <hr>
        <h3 class="sp-card-title" style="font-size:1rem;"><?php echo t('caddy_reload_heading'); ?></h3>
        <button class="sp-btn sp-btn-warning" id="caddy-reload" type="button" <?php echo empty($web_ssh_host) ? 'disabled title="' . htmlspecialchars(t('caddy_err_ssh_not_configured')) . '"' : ''; ?>><i class="fas fa-rotate"></i> <?php echo t('caddy_reload_btn'); ?></button>
        <button class="sp-btn sp-btn-danger" id="caddy-restart" type="button" <?php echo empty($web_ssh_host) ? 'disabled title="' . htmlspecialchars(t('caddy_err_ssh_not_configured')) . '"' : ''; ?>><i class="fas fa-power-off"></i> <?php echo t('caddy_restart_btn'); ?></button>
    </div>
</div>
<?php endif; ?>

<div class="sp-card">
    <div class="sp-card-body">
        <pre id="caddy-output" style="max-height:420px; overflow:auto; background:var(--bg-input); padding:0.75rem; border-radius:6px; margin:0;">&mdash;</pre>
    </div>
</div>

<script>
const CADDY_IS_SUPER = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
const CADDY_I18N = {
    destructiveTitle: <?php echo json_encode(t('caddy_destructive_confirm_title')); ?>,
    destructiveText: <?php echo json_encode(t('caddy_destructive_confirm_text')); ?>,
    restartTitle: <?php echo json_encode(t('caddy_restart_confirm_title')); ?>,
    restartText: <?php echo json_encode(t('caddy_restart_confirm_text')); ?>,
    confirmBtn: <?php echo json_encode(t('caddy_confirm_btn')); ?>,
    cancelBtn: <?php echo json_encode(t('caddy_cancel_btn')); ?>,
    invalidJson: <?php echo json_encode(t('caddy_err_invalid_json')); ?>
};

function caddyShow(obj) {
    const out = document.getElementById('caddy-output');
    out.textContent = (typeof obj === 'string') ? obj : JSON.stringify(obj, null, 2);
}
async function caddyPost(data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    const r = await fetch('caddy.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return r.json();
}
async function caddyTypedConfirm(title, text, keyword) {
    const r = await Swal.fire({
        icon: 'warning', title: title, text: text, input: 'text',
        showCancelButton: true, confirmButtonText: CADDY_I18N.confirmBtn, cancelButtonText: CADDY_I18N.cancelBtn,
        confirmButtonColor: '#e74c3c',
        preConfirm: (v) => { if ((v || '').trim() !== keyword) { Swal.showValidationMessage(keyword); return false; } return true; }
    });
    return r.isConfirmed;
}

document.getElementById('caddy-config-toggle')?.addEventListener('click', () => {
    const v = document.getElementById('caddy-config-view');
    v.style.display = (v.style.display === 'none') ? 'block' : 'none';
});

document.getElementById('caddy-version-btn')?.addEventListener('click', async (e) => {
    e.target.disabled = true;
    const res = await caddyPost({ action: 'caddy_version' });
    document.getElementById('caddy-version-value').textContent = res.ok ? res.version : (res.error || 'unknown');
    e.target.disabled = false;
});

if (CADDY_IS_SUPER) {
    document.getElementById('caddy-send')?.addEventListener('click', async () => {
        const method = document.getElementById('caddy-method').value;
        const path = document.getElementById('caddy-path').value.trim();
        const body = document.getElementById('caddy-body').value;
        if (body.trim() !== '') {
            try { JSON.parse(body); } catch (err) { caddyShow(CADDY_I18N.invalidJson); return; }
        }
        if (method !== 'GET') {
            if (!await caddyTypedConfirm(CADDY_I18N.destructiveTitle, CADDY_I18N.destructiveText, 'CONFIRM')) return;
        }
        caddyShow(await caddyPost({ action: 'api_call', method, path, body }));
    });

    document.getElementById('caddy-validate')?.addEventListener('click', async () => {
        const config = document.getElementById('caddy-load-config').value;
        const format = document.getElementById('caddy-load-format').value;
        caddyShow(await caddyPost({ action: 'adapt_config', config, format }));
    });

    document.getElementById('caddy-apply')?.addEventListener('click', async () => {
        const config = document.getElementById('caddy-load-config').value;
        const format = document.getElementById('caddy-load-format').value;
        if (format === 'json' && config.trim() !== '') {
            try { JSON.parse(config); } catch (err) { caddyShow(CADDY_I18N.invalidJson); return; }
        }
        if (!await caddyTypedConfirm(CADDY_I18N.destructiveTitle, CADDY_I18N.destructiveText, 'CONFIRM')) return;
        caddyShow(await caddyPost({ action: 'load_config', config, format }));
    });

    document.getElementById('caddy-reload')?.addEventListener('click', async (e) => {
        if (!await caddyTypedConfirm(CADDY_I18N.destructiveTitle, CADDY_I18N.destructiveText, 'CONFIRM')) return;
        e.target.disabled = true;
        caddyShow(await caddyPost({ action: 'reload_caddy' }));
        e.target.disabled = false;
    });

    document.getElementById('caddy-restart')?.addEventListener('click', async (e) => {
        if (!await caddyTypedConfirm(CADDY_I18N.restartTitle, CADDY_I18N.restartText, 'RESTART')) return;
        e.target.disabled = true;
        caddyShow(await caddyPost({ action: 'restart_caddy' }));
        e.target.disabled = false;
    });
}
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
