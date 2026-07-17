<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('cdn_files_page_title');
require_once "/var/www/config/db_connect.php";
require_once __DIR__ . '/../includes/megas4_s3.php';
include '../includes/userdata.php';
session_write_close();

// Determine super-admin (controls write access)
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

// Store config (from /var/www/config/megas4.php, loaded transitively by megas4_s3.php)
global $megas4_stores;
$validStores = is_array($megas4_stores) ? array_keys($megas4_stores) : [];

// Allowed upload extensions
// MIME-validated: full extension + MIME check via upload_validate_extension_and_mime().
$mimeValidatedExts = ['mp3', 'mp4', 'webm', 'gif', 'png', 'webp', 'jpg', 'jpeg'];
// Safe web-asset extensions: extension-only check is sufficient for admin-only text/binary uploads.
$safeWebExts = ['svg', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf', 'json', 'xml', 'txt', 'html'];
$allAllowedExts = array_merge($mimeValidatedExts, $safeWebExts);

// Local helpers
if (!function_exists('cdnfm_human_size')) {
    function cdnfm_human_size(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}

if (!function_exists('cdnfm_served_url')) {
    function cdnfm_served_url(string $store, string $key): string {
        global $megas4_stores;
        if (!isset($megas4_stores[$store]['domain']) || (string) $megas4_stores[$store]['domain'] === '') {
            return '';
        }
        $path = (string) substr($key, strlen($store) + 1);
        $path = implode('/', array_map('rawurlencode', explode('/', $path)));
        return 'https://' . $megas4_stores[$store]['domain'] . '/' . $path;
    }
}

if (!function_exists('cdnfm_safe_folder_name')) {
    function cdnfm_safe_folder_name(string $name): string {
        $name = trim($name);
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim($name, '-._');
        return (string) mb_substr($name, 0, 80);
    }
}

if (!function_exists('cdnfm_key_in_store')) {
    /** Return true when $key starts with "{$store}/". */
    function cdnfm_key_in_store(string $store, string $key): bool {
        $prefix = $store . '/';
        return substr($key, 0, strlen($prefix)) === $prefix;
    }
}

// ---- AJAX action handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $action = (string) $_POST['action'];
    // -- Validate store for every action before touching S3 --
    $store = trim((string) ($_POST['store'] ?? ''));
    if (!in_array($store, $validStores, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_store')]);
        exit;
    }
    // -- list: read-only, any admin --
    if ($action === 'list') {
        $folder = trim((string) ($_POST['folder'] ?? ''));
        $result = megas4_list($store, $folder);
        if (isset($result['error'])) {
            echo json_encode(['ok' => false, 'error' => $result['error']]);
            exit;
        }
        // Augment file entries with human-readable size and served URL.
        $augmented = [];
        foreach ($result['files'] as $f) {
            $augmented[] = [
                'key'           => $f['key'],
                'basename'      => $f['basename'],
                'size'          => cdnfm_human_size((int) $f['size']),
                'size_bytes'    => (int) $f['size'],
                'last_modified' => (int) $f['last_modified'],
                'served_url'    => cdnfm_served_url($store, $f['key']),
            ];
        }
        $isPerUserRoot = false;
        $folderUsers   = [];
        $storeIsPerUser = !empty($megas4_stores[$store]['per_user']);
        if ($storeIsPerUser && $folder === '' && !empty($result['folders']) && isset($conn)) {
            $isPerUserRoot = true;
            $folderNames   = $result['folders'];
            $n             = count($folderNames);
            $placeholders  = rtrim(str_repeat('?,', $n), ',');
            $types         = str_repeat('s', $n);
            $stmt2 = $conn->prepare(
                "SELECT username, twitch_display_name, profile_image FROM users WHERE username IN ($placeholders)"
            );
            if ($stmt2) {
                $stmt2->bind_param($types, ...$folderNames);
                $stmt2->execute();
                $rows2   = $stmt2->get_result();
                $userMap = [];
                while ($row2 = $rows2->fetch_assoc()) {
                    // Index by lowercase username for case-insensitive lookup.
                    $userMap[strtolower((string) $row2['username'])] = $row2;
                }
                $stmt2->close();
                foreach ($folderNames as $fn) {
                    $fnLower = strtolower($fn);
                    if (isset($userMap[$fnLower])) {
                        $u = $userMap[$fnLower];
                        $folderUsers[$fn] = [
                            'display_name' => (string) ($u['twitch_display_name'] !== '' ? $u['twitch_display_name'] : $fn),
                            'avatar'       => (string) ($u['profile_image'] ?? ''),
                        ];
                    } else {
                        $folderUsers[$fn] = null; // orphaned - no matching user account
                    }
                }
            }
        }
        echo json_encode([
            'ok'            => true,
            'folders'       => $result['folders'],
            'files'         => $augmented,
            'per_user_root' => $isPerUserRoot,
            'folder_users'  => $folderUsers,
        ]);
        exit;
    }
    // -- Write actions: super admin only (server-side enforcement, not just UI) --
    $writeActions = ['upload', 'delete', 'rename', 'mkdir'];
    if (in_array($action, $writeActions, true)) {
        if (!$isSuperAdmin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => t('cdn_files_err_not_super')]);
            exit;
        }
        // upload
        if ($action === 'upload') {
            $folder = trim((string) ($_POST['folder'] ?? ''));
            if (!isset($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $code = isset($_FILES['file']['error']) ? (int) $_FILES['file']['error'] : -1;
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_upload_failed') . " (code: $code)"]);
                exit;
            }
            $origName = (string) $_FILES['file']['name'];
            $tmpPath  = (string) $_FILES['file']['tmp_name'];
            $ext      = strtolower((string) pathinfo($origName, PATHINFO_EXTENSION));
            // For image/media types: full MIME + extension validation.
            if (in_array($ext, $mimeValidatedExts, true)) {
                if (!upload_validate_extension_and_mime($tmpPath, $ext, $mimeValidatedExts)) {
                    echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_file')]);
                    exit;
                }
            } elseif (!in_array($ext, $safeWebExts, true)) {
                // Extension not in any allowed list.
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_file')]);
                exit;
            }
            $safeFilename = upload_sanitize_filename($origName, $ext);
            $folderPart   = ($folder !== '') ? trim($folder, '/') . '/' : '';
            $targetKey    = $store . '/' . $folderPart . $safeFilename;
            $overwrite = ((string) ($_POST['overwrite'] ?? '0') === '1');
            if (!$overwrite && megas4_exists($store, $targetKey)) {
                echo json_encode([
                    'ok'       => false,
                    'exists'   => true,
                    'filename' => $safeFilename,
                ]);
                exit;
            }
            $ok = megas4_upload($store, $folder, $tmpPath, $safeFilename);
            admin_audit_log(
                'cdn_files_upload',
                $ok ? 'success' : 'failed',
                ['store' => $store, 'folder' => $folder, 'filename' => $safeFilename, 'original_name' => $origName, 'overwrite' => $overwrite],
                'cdn_file',
                $targetKey
            );
            echo json_encode([
                'ok'    => $ok,
                'error' => $ok ? null : t('cdn_files_err_upload_failed'),
            ]);
            exit;
        }
        // delete
        if ($action === 'delete') {
            $key      = (string) ($_POST['key'] ?? '');
            $isFolder = ((string) ($_POST['is_folder'] ?? '0') === '1');
            if ($key === '') {
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_missing_key')]);
                exit;
            }
            // Guard: key must belong to the stated store.
            if (!cdnfm_key_in_store($store, $key)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_store')]);
                exit;
            }
            if ($isFolder) {
                // megas4_delete_folder requires the key to end with '/'.
                if (substr($key, -1) !== '/') {
                    $key .= '/';
                }
                $ok = megas4_delete_folder($store, $key);
                admin_audit_log(
                    'cdn_files_delete_folder',
                    $ok ? 'success' : 'failed',
                    ['store' => $store, 'key' => $key],
                    'cdn_folder',
                    $key
                );
            } else {
                $ok = megas4_delete($store, $key);
                admin_audit_log(
                    'cdn_files_delete_file',
                    $ok ? 'success' : 'failed',
                    ['store' => $store, 'key' => $key],
                    'cdn_file',
                    $key
                );
            }
            echo json_encode([
                'ok'    => $ok,
                'error' => $ok ? null : t('cdn_files_err_delete_failed'),
            ]);
            exit;
        }
        // rename
        if ($action === 'rename') {
            $oldKey  = (string) ($_POST['old_key'] ?? '');
            $newName = trim((string) ($_POST['new_name'] ?? ''));
            if ($oldKey === '' || $newName === '') {
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_missing_param')]);
                exit;
            }
            // Guard: old key must belong to the stated store.
            if (!cdnfm_key_in_store($store, $oldKey)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_store')]);
                exit;
            }
            // Folder rename is intentionally unsupported: it would move only the
            // zero-byte marker, not the folder's contents. The UI exposes rename
            // on files only; reject a folder key arriving via a crafted POST.
            if (substr($oldKey, -1) === '/') {
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_name')]);
                exit;
            }
            // Build the new key from the sanitised new filename, in the same
            // folder as the old key.
            $parent       = (string) dirname($oldKey);
            $folderPrefix = ($parent === '.' || $parent === '') ? '' : $parent . '/';
            $ext = strtolower((string) pathinfo($newName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allAllowedExts, true)) {
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_file')]);
                exit;
            }
            $safeName = upload_sanitize_filename($newName, $ext);
            $newKey   = $folderPrefix . $safeName;
            // Guard: new key must also stay within the same store.
            if (!cdnfm_key_in_store($store, $newKey)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_store')]);
                exit;
            }
            // No-op if the name is unchanged - S3 copy-to-self would throw.
            if ($newKey === $oldKey) {
                echo json_encode(['ok' => true, 'new_key' => $newKey, 'error' => null]);
                exit;
            }
            $ok = megas4_rename($store, $oldKey, $newKey);
            admin_audit_log(
                'cdn_files_rename',
                $ok ? 'success' : 'failed',
                ['store' => $store, 'old_key' => $oldKey, 'new_key' => $newKey],
                'cdn_file',
                mb_substr($oldKey . ' -> ' . $newKey, 0, 255)
            );
            echo json_encode([
                'ok'      => $ok,
                'new_key' => $newKey,
                'error'   => $ok ? null : t('cdn_files_err_rename_failed'),
            ]);
            exit;
        }
        // mkdir
        if ($action === 'mkdir') {
            $folder   = trim((string) ($_POST['folder'] ?? ''));
            $name     = trim((string) ($_POST['name'] ?? ''));
            $safeName = cdnfm_safe_folder_name($name);
            if ($safeName === '') {
                echo json_encode(['ok' => false, 'error' => t('cdn_files_err_invalid_name')]);
                exit;
            }
            $ok = megas4_make_folder($store, $folder, $safeName);
            $folderPart = ($folder !== '') ? trim($folder, '/') . '/' : '';
            $targetKey  = $store . '/' . $folderPart . $safeName . '/';
            admin_audit_log(
                'cdn_files_mkdir',
                $ok ? 'success' : 'failed',
                ['store' => $store, 'folder' => $folder, 'name' => $safeName],
                'cdn_folder',
                $targetKey
            );
            echo json_encode([
                'ok'    => $ok,
                'error' => $ok ? null : t('cdn_files_err_mkdir_failed'),
            ]);
            exit;
        }
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// Page render
ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-folder-open"></i> <?php echo t('cdn_files_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <?php if (!$isSuperAdmin): ?>
            <div class="sp-alert sp-alert-info"><i class="fas fa-eye"></i> <?php echo t('cdn_files_readonly_notice'); ?></div>
        <?php endif; ?>
        <?php if (empty($validStores)): ?>
            <div class="sp-alert sp-alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo t('cdn_files_err_no_stores'); ?></div>
        <?php else: ?>
        <!-- Toolbar: store selector + breadcrumb + write actions -->
        <div class="cdnfm-toolbar">
            <div class="cdnfm-toolbar-left">
                <label class="cdnfm-label" for="cdnfm-store"><?php echo t('cdn_files_store_label'); ?></label>
                <select id="cdnfm-store" class="sp-input cdnfm-store-select" aria-label="<?php echo htmlspecialchars(t('cdn_files_store_label')); ?>">
                    <?php foreach ($megas4_stores as $storeKey => $storeInfo): ?>
                        <option value="<?php echo htmlspecialchars((string) $storeKey); ?>">
                            <?php echo htmlspecialchars((string) ($storeInfo['name'] ?? $storeKey)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="cdnfm-breadcrumb" id="cdnfm-breadcrumb" aria-label="breadcrumb">
                <!-- Populated by JS -->
            </nav>
            <?php if ($isSuperAdmin): ?>
            <div class="cdnfm-toolbar-actions">
                <button class="sp-btn sp-btn-primary sp-btn-sm" id="cdnfm-upload-btn" type="button">
                    <i class="fas fa-upload"></i> <?php echo t('cdn_files_upload_btn'); ?>
                </button>
                <button class="sp-btn sp-btn-secondary sp-btn-sm" id="cdnfm-mkdir-btn" type="button">
                    <i class="fas fa-folder-plus"></i> <?php echo t('cdn_files_mkdir_btn'); ?>
                </button>
                <input type="file" id="cdnfm-file-input" multiple style="display:none;">
            </div>
            <?php endif; ?>
        </div>
        <!-- Loading indicator -->
        <div id="cdnfm-status" class="cdnfm-status" style="display:none;">
            <i class="fas fa-spinner fa-spin"></i> <?php echo t('cdn_files_loading'); ?>
        </div>
        <!-- Alert area -->
        <div id="cdnfm-alert" style="display:none;"></div>
        <!-- File listing -->
        <div class="sp-table-wrap" id="cdnfm-listing-wrap">
            <table class="sp-table" id="cdnfm-table">
                <thead>
                    <tr>
                        <th><?php echo t('cdn_files_th_name'); ?></th>
                        <th><?php echo t('cdn_files_th_size'); ?></th>
                        <th><?php echo t('cdn_files_th_modified'); ?></th>
                        <th><?php echo t('cdn_files_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="cdnfm-tbody">
                    <tr><td colspan="4" style="color:var(--text-muted); text-align:center;"><i class="fas fa-spinner fa-spin"></i> <?php echo t('cdn_files_loading'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php endif; // end !empty($validStores) ?>
    </div>
</div>
<script>
(function () {
    const IS_SUPER  = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
    const STORES    = <?php echo json_encode(is_array($megas4_stores) ? $megas4_stores : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const I18N = {
        loading:             <?php echo json_encode(t('cdn_files_loading')); ?>,
        empty:               <?php echo json_encode(t('cdn_files_empty')); ?>,
        folderLabel:         <?php echo json_encode(t('cdn_files_folder_label')); ?>,
        rootLabel:           <?php echo json_encode(t('cdn_files_root_label')); ?>,
        confirmDeleteFile:   <?php echo json_encode(t('cdn_files_confirm_delete_file')); ?>,
        confirmDeleteFolder: <?php echo json_encode(t('cdn_files_confirm_delete_folder')); ?>,
        typeKeyword:         <?php echo json_encode(t('cdn_files_type_delete_keyword')); ?>,
        typePrompt:          <?php echo json_encode(t('cdn_files_type_to_confirm')); ?>,
        renameTitle:         <?php echo json_encode(t('cdn_files_rename_prompt')); ?>,
        mkdirTitle:          <?php echo json_encode(t('cdn_files_mkdir_prompt')); ?>,
        confirmBtn:          <?php echo json_encode(t('cdn_files_confirm_btn')); ?>,
        cancelBtn:           <?php echo json_encode(t('cdn_files_cancel_btn')); ?>,
        copiedMsg:           <?php echo json_encode(t('cdn_files_url_copied')); ?>,
        copyBtn:             <?php echo json_encode(t('cdn_files_copy_url_btn')); ?>,
        renameBtn:           <?php echo json_encode(t('cdn_files_rename_btn')); ?>,
        deleteBtn:           <?php echo json_encode(t('cdn_files_delete_btn')); ?>,
        uploadDone:          <?php echo json_encode(t('cdn_files_upload_done')); ?>,
        confirmOverwrite:    <?php echo json_encode(t('cdn_files_confirm_overwrite')); ?>,
        overwriteBtn:        <?php echo json_encode(t('cdn_files_overwrite_btn')); ?>,
        errNotSuper:         <?php echo json_encode(t('cdn_files_err_not_super')); ?>,
        errNameEmpty:        <?php echo json_encode(t('cdn_files_err_name_empty')); ?>,
        orphanedBadge:       <?php echo json_encode(t('cdn_files_orphaned_badge')); ?>,
        viewUser:            <?php echo json_encode(t('cdn_files_view_user')); ?>,
    };
    // Current navigation state.
    let curStore  = '';
    let curFolder = '';
    // Utility
    function esc(s) {
        // Escape for BOTH text and (double/single-quoted) attribute contexts -
        // textContent alone leaves " and ' unescaped, allowing attribute breakout.
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function setLoading(on) {
        const statusEl = document.getElementById('cdnfm-status');
        const wrapEl   = document.getElementById('cdnfm-listing-wrap');
        if (statusEl) statusEl.style.display = on ? '' : 'none';
        if (wrapEl)   wrapEl.style.opacity   = on ? '0.4' : '1';
    }
    function showAlert(msg, type) {
        const iconMap = { success: 'check-circle', danger: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
        const icon = iconMap[type] || 'info-circle';
        const el = document.getElementById('cdnfm-alert');
        if (!el) return;
        el.innerHTML = '<div class="sp-alert sp-alert-' + esc(type) + '"><i class="fas fa-' + icon + '"></i> ' + esc(msg) + '</div>';
        el.style.display = '';
    }
    function hideAlert() {
        const el = document.getElementById('cdnfm-alert');
        if (el) el.style.display = 'none';
    }
    function humanDate(ts) {
        if (!ts) return '-';
        const d = new Date(ts * 1000);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    async function post(data, file) {
        const fd = new FormData();
        fd.append('store', curStore);
        for (const [k, v] of Object.entries(data)) {
            fd.append(k, v == null ? '' : String(v));
        }
        if (file) fd.append('file', file);
        const r = await fetch('cdn_files.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return r.json();
    }
    // Breadcrumb
    function updateBreadcrumb() {
        const nav = document.getElementById('cdnfm-breadcrumb');
        if (!nav) return;
        const storeInfo = STORES[curStore] || {};
        const storeName = storeInfo['name'] || curStore;
        let html = '<span class="cdnfm-bc-item" data-folder="">' + esc(storeName) + '</span>';
        if (curFolder) {
            const parts = curFolder.split('/').filter(Boolean);
            let acc = '';
            parts.forEach(function (part, i) {
                acc = acc ? acc + '/' + part : part;
                const isLast = (i === parts.length - 1);
                html += ' <i class="fas fa-chevron-right cdnfm-bc-sep"></i> ';
                if (isLast) {
                    html += '<span class="cdnfm-bc-current">' + esc(part) + '</span>';
                } else {
                    html += '<span class="cdnfm-bc-item" data-folder="' + esc(acc) + '">' + esc(part) + '</span>';
                }
            });
        }
        nav.innerHTML = html;
        nav.querySelectorAll('.cdnfm-bc-item[data-folder]').forEach(function (el) {
            el.style.cursor = 'pointer';
            el.addEventListener('click', function () {
                navigateTo(curStore, el.getAttribute('data-folder') || '');
            });
        });
    }
    // Listing
    async function loadListing() {
        setLoading(true);
        hideAlert();
        let res;
        try {
            res = await post({ action: 'list', folder: curFolder });
        } catch (e) {
            setLoading(false);
            showAlert(String(e), 'danger');
            return;
        }
        setLoading(false);
        if (!res.ok) {
            showAlert(res.error || 'Error loading listing', 'danger');
            return;
        }
        renderListing(res);
    }
    function renderListing(data) {
        const tbody = document.getElementById('cdnfm-tbody');
        if (!tbody) return;
        const folders = data.folders || [];
        const files   = data.files   || [];
        let html = '';
        const isPerUserRoot = !!data.per_user_root;
        const folderUsers   = (isPerUserRoot && data.folder_users) ? data.folder_users : {};
        folders.forEach(function (seg) {
            const folderPath    = curFolder ? curFolder + '/' + seg : seg;
            const folderKey     = curStore + '/' + folderPath + '/';
            const userInfo      = isPerUserRoot ? (folderUsers.hasOwnProperty(seg) ? folderUsers[seg] : undefined) : undefined;
            const isOrphaned    = isPerUserRoot && userInfo === null;
            const isMatchedUser = isPerUserRoot && userInfo && typeof userInfo === 'object';
            html += '<tr>';
            html += '<td>';
            // The folder-link span keeps the existing folder-navigation behaviour.
            // It wraps the icon, optional avatar, and the raw folder name (username).
            html += '<span class="cdnfm-folder-link" data-folder="' + esc(folderPath) + '" style="cursor:pointer;">' +
                    '<i class="fas fa-folder" style="color:var(--amber);"></i> ';
            if (isMatchedUser) {
                if (userInfo.avatar) {
                    html += '<img class="cdnfm-avatar" src="' + esc(userInfo.avatar) + '" ' +
                            'alt="" onerror="this.style.display=\'none\'" loading="lazy"> ';
                }
                html += esc(seg) + '</span>';
                // Display name (parenthetical, outside the folder-link span so it does not trigger navigation).
                html += ' <span class="cdnfm-folder-display-name">' + esc(userInfo.display_name) + '</span>';
                // "View user" link: opens users.php filtered to this username.
                html += ' <a class="cdnfm-view-user-link sp-btn sp-btn-ghost sp-btn-sm" ' +
                        'href="users.php?search=' + encodeURIComponent(seg) + '" ' +
                        'title="' + esc(I18N.viewUser) + '">' +
                        '<i class="fas fa-external-link-alt"></i></a>';
            } else if (isOrphaned) {
                html += esc(seg) + '</span>';
                // Orphaned badge: no matching user account.
                html += ' <span class="cdnfm-orphan-badge sp-badge sp-badge-amber" title="' + esc(I18N.orphanedBadge) + '">' +
                        '<i class="fas fa-exclamation-triangle"></i> ' + esc(I18N.orphanedBadge) + '</span>';
            } else {
                // Non-per-user store or sub-folder inside a per-user store: original rendering.
                html += esc(seg) + '</span>';
            }
            html += '</td>';
            html += '<td><span class="sp-badge sp-badge-grey">' + esc(I18N.folderLabel) + '</span></td>';
            html += '<td>-</td>';
            html += '<td class="cdnfm-row-actions">';
            if (IS_SUPER) {
                html += '<button class="sp-btn sp-btn-sm sp-btn-danger cdnfm-del-btn" ' +
                        'data-key="' + esc(folderKey) + '" data-is-folder="1" data-name="' + esc(seg) + '" ' +
                        'title="' + esc(I18N.deleteBtn) + '"><i class="fas fa-trash"></i></button>';
            }
            html += '</td></tr>';
        });
        if (folders.length === 0 && files.length === 0) {
            html += '<tr><td colspan="4" style="color:var(--text-muted); text-align:center;">' + esc(I18N.empty) + '</td></tr>';
        }
        files.forEach(function (f) {
            html += '<tr>';
            html += '<td><i class="fas fa-file" style="color:var(--text-muted);"></i> ' + esc(f.basename) + '</td>';
            html += '<td>' + esc(f.size) + '</td>';
            html += '<td>' + esc(humanDate(f.last_modified)) + '</td>';
            html += '<td class="cdnfm-row-actions">';
            html += '<button class="sp-btn sp-btn-sm sp-btn-ghost cdnfm-copy-btn" ' +
                    'data-url="' + esc(f.served_url) + '" title="' + esc(I18N.copyBtn) + '">' +
                    '<i class="fas fa-link"></i></button>';
            if (IS_SUPER) {
                html += '<button class="sp-btn sp-btn-sm sp-btn-secondary cdnfm-rename-btn" ' +
                        'data-key="' + esc(f.key) + '" data-name="' + esc(f.basename) + '" ' +
                        'title="' + esc(I18N.renameBtn) + '"><i class="fas fa-pencil-alt"></i></button>';
                html += '<button class="sp-btn sp-btn-sm sp-btn-danger cdnfm-del-btn" ' +
                        'data-key="' + esc(f.key) + '" data-is-folder="0" data-name="' + esc(f.basename) + '" ' +
                        'title="' + esc(I18N.deleteBtn) + '"><i class="fas fa-trash"></i></button>';
            }
            html += '</td></tr>';
        });
        tbody.innerHTML = html;
        // Bind folder navigation.
        tbody.querySelectorAll('.cdnfm-folder-link').forEach(function (el) {
            el.addEventListener('click', function () {
                navigateTo(curStore, el.getAttribute('data-folder') || '');
            });
        });
        // Bind copy-URL buttons.
        tbody.querySelectorAll('.cdnfm-copy-btn').forEach(function (el) {
            el.addEventListener('click', function () { copyUrl(el.getAttribute('data-url') || '', el); });
        });
        if (IS_SUPER) {
            tbody.querySelectorAll('.cdnfm-del-btn').forEach(function (el) {
                el.addEventListener('click', function () {
                    doDelete(el.getAttribute('data-key') || '', el.getAttribute('data-is-folder') === '1', el.getAttribute('data-name') || '');
                });
            });
            tbody.querySelectorAll('.cdnfm-rename-btn').forEach(function (el) {
                el.addEventListener('click', function () {
                    doRename(el.getAttribute('data-key') || '', el.getAttribute('data-name') || '');
                });
            });
        }
    }
    // Navigation
    function navigateTo(store, folder) {
        curStore  = store;
        curFolder = folder || '';
        updateBreadcrumb();
        loadListing();
    }
    // Copy URL
    function copyUrl(url, btn) {
        if (!url) {
            showAlert(I18N.copiedMsg + ' (URL not available)', 'warning');
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(function () { btn.innerHTML = orig; }, 1500);
            }).catch(function () {
                window.prompt(I18N.copiedMsg, url);
            });
        } else {
            window.prompt(I18N.copiedMsg, url);
        }
    }
    // Upload
    async function doUpload(file) {
        setLoading(true);
        let res;
        try {
            res = await post({ action: 'upload', folder: curFolder }, file);
        } catch (e) {
            setLoading(false);
            showAlert(String(e), 'danger');
            return false;
        }
        // Server refused to silently overwrite an existing file - confirm first.
        if (res && res.exists) {
            setLoading(false);
            const owResult = await Swal.fire({
                icon: 'warning',
                title: esc(res.filename || file.name),
                text: I18N.confirmOverwrite,
                showCancelButton: true,
                confirmButtonText: I18N.overwriteBtn,
                cancelButtonText:  I18N.cancelBtn,
                confirmButtonColor: '#e74c3c'
            });
            if (!owResult.isConfirmed) {
                return false;
            }
            setLoading(true);
            try {
                res = await post({ action: 'upload', folder: curFolder, overwrite: '1' }, file);
            } catch (e) {
                setLoading(false);
                showAlert(String(e), 'danger');
                return false;
            }
        }
        setLoading(false);
        if (res.ok) {
            showAlert(I18N.uploadDone, 'success');
        } else {
            showAlert(res.error || I18N.errNotSuper, 'danger');
        }
        return res.ok;
    }
    // Delete
    async function doDelete(key, isFolder, displayName) {
        const confirmText = (isFolder ? I18N.confirmDeleteFolder : I18N.confirmDeleteFile) +
                            '\n' + I18N.typePrompt + ': ' + I18N.typeKeyword;
        const result = await Swal.fire({
            icon: 'warning',
            title: esc(displayName),
            text:  confirmText,
            input: 'text',
            inputPlaceholder: I18N.typeKeyword,
            showCancelButton: true,
            confirmButtonText: I18N.confirmBtn,
            cancelButtonText:  I18N.cancelBtn,
            confirmButtonColor: '#e74c3c',
            preConfirm: function (v) {
                if ((v || '').trim() !== I18N.typeKeyword) {
                    Swal.showValidationMessage(I18N.typeKeyword);
                    return false;
                }
                return true;
            }
        });
        if (!result.isConfirmed) return;
        setLoading(true);
        let res;
        try {
            res = await post({ action: 'delete', key: key, is_folder: isFolder ? '1' : '0' });
        } catch (e) {
            setLoading(false);
            showAlert(String(e), 'danger');
            return;
        }
        setLoading(false);
        if (res.ok) {
            hideAlert();
            await loadListing();
        } else {
            showAlert(res.error || 'Delete failed', 'danger');
        }
    }
    // Rename
    async function doRename(oldKey, currentName) {
        const result = await Swal.fire({
            title: I18N.renameTitle,
            input: 'text',
            inputValue: currentName,
            showCancelButton: true,
            confirmButtonText: I18N.confirmBtn,
            cancelButtonText:  I18N.cancelBtn,
            preConfirm: function (v) {
                if (!v || v.trim() === '') {
                    Swal.showValidationMessage(I18N.errNameEmpty);
                    return false;
                }
                return v.trim();
            }
        });
        if (!result.isConfirmed || !result.value) return;
        setLoading(true);
        let res;
        try {
            res = await post({ action: 'rename', old_key: oldKey, new_name: result.value });
        } catch (e) {
            setLoading(false);
            showAlert(String(e), 'danger');
            return;
        }
        setLoading(false);
        if (res.ok) {
            hideAlert();
            await loadListing();
        } else {
            showAlert(res.error || 'Rename failed', 'danger');
        }
    }
    // Mkdir
    async function doMkdir() {
        const result = await Swal.fire({
            title: I18N.mkdirTitle,
            input: 'text',
            showCancelButton: true,
            confirmButtonText: I18N.confirmBtn,
            cancelButtonText:  I18N.cancelBtn,
            preConfirm: function (v) {
                if (!v || v.trim() === '') {
                    Swal.showValidationMessage(I18N.errNameEmpty);
                    return false;
                }
                return v.trim();
            }
        });
        if (!result.isConfirmed || !result.value) return;
        setLoading(true);
        let res;
        try {
            res = await post({ action: 'mkdir', folder: curFolder, name: result.value });
        } catch (e) {
            setLoading(false);
            showAlert(String(e), 'danger');
            return;
        }
        setLoading(false);
        if (res.ok) {
            hideAlert();
            await loadListing();
        } else {
            showAlert(res.error || 'Create folder failed', 'danger');
        }
    }
    // Init
    document.addEventListener('DOMContentLoaded', function () {
        const storeEl = document.getElementById('cdnfm-store');
        if (!storeEl) return; // page is in error state (no stores configured)
        curStore  = storeEl.value || '';
        curFolder = '';
        updateBreadcrumb();
        storeEl.addEventListener('change', function () {
            navigateTo(this.value, '');
        });
        if (IS_SUPER) {
            const uploadBtn = document.getElementById('cdnfm-upload-btn');
            const fileInput = document.getElementById('cdnfm-file-input');
            const mkdirBtn  = document.getElementById('cdnfm-mkdir-btn');
            if (uploadBtn && fileInput) {
                uploadBtn.addEventListener('click', function () { fileInput.click(); });
                fileInput.addEventListener('change', async function () {
                    const files = Array.from(this.files || []);
                    for (const f of files) {
                        await doUpload(f);
                    }
                    this.value = '';
                    await loadListing();
                });
            }
            if (mkdirBtn) {
                mkdirBtn.addEventListener('click', doMkdir);
            }
        }
        loadListing();
    });
})();
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>