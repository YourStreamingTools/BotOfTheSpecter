<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('point_store_title');

require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

$status = '';
$statusType = 'success';
$allowedTypes = ['sound_alert', 'video_alert', 'tts', 'chat_message'];

function point_store_slugify($title) {
    $slug = strtolower(trim((string) $title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'item';
    }
    return substr($slug, 0, 150);
}

function point_store_unique_slug(mysqli $db, $baseSlug, $excludeId = null) {
    $slug = $baseSlug;
    $n = 2;
    while (true) {
        if ($excludeId) {
            $stmt = $db->prepare("SELECT id FROM point_store_items WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->bind_param('si', $slug, $excludeId);
        } else {
            $stmt = $db->prepare("SELECT id FROM point_store_items WHERE slug = ? LIMIT 1");
            $stmt->bind_param('s', $slug);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $slug;
        }
        $slug = substr($baseSlug, 0, 140) . '-' . $n;
        $n++;
    }
}

function point_store_media_path() {
    $username = $_SESSION['username'] ?? 'unknown';
    return '/var/www/media/' . $username;
}

/**
 * List files from the unified media library ($media_path / media.php).
 * Same layout as media.php: root files + one subdirectory level.
 */
function point_store_list_media_library($mediaPath, $extensions) {
    $files = [];
    if (!is_dir($mediaPath)) {
        return $files;
    }
    foreach (scandir($mediaPath) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $mediaPath . '/' . $entry;
        if (is_file($full)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                $files[] = $entry;
            }
        } elseif (is_dir($full)) {
            foreach (scandir($full) as $sub) {
                if ($sub === '.' || $sub === '..') {
                    continue;
                }
                $subFull = $full . '/' . $sub;
                if (!is_file($subFull)) {
                    continue;
                }
                $ext = strtolower(pathinfo($sub, PATHINFO_EXTENSION));
                if (in_array($ext, $extensions, true)) {
                    $files[] = $entry . '/' . $sub;
                }
            }
        }
    }
    sort($files, SORT_STRING | SORT_FLAG_CASE);
    return $files;
}

/** Relative path under media library only - no traversal. */
function point_store_normalize_media_rel($path) {
    $path = str_replace('\\', '/', trim((string) $path));
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    // At most one directory segment + filename (matches media.php scan)
    if (substr_count($path, '/') > 1) {
        return '';
    }
    return $path;
}

function point_store_media_file_allowed($mediaPath, $relative, $extensions) {
    $relative = point_store_normalize_media_rel($relative);
    if ($relative === '') {
        return false;
    }
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        return false;
    }
    $full = $mediaPath . '/' . $relative;
    return is_file($full);
}

function point_store_build_payload($itemType, $post, $mediaPath, $soundFiles, $videoFiles) {
    switch ($itemType) {
        case 'sound_alert':
            $sound = point_store_normalize_media_rel($post['sound_file'] ?? '');
            if ($sound === '' || !in_array($sound, $soundFiles, true)) {
                return null;
            }
            if (!point_store_media_file_allowed($mediaPath, $sound, ['mp3'])) {
                return null;
            }
            return ['sound' => $sound];
        case 'video_alert':
            $video = point_store_normalize_media_rel($post['video_file'] ?? '');
            if ($video === '' || !in_array($video, $videoFiles, true)) {
                return null;
            }
            if (!point_store_media_file_allowed($mediaPath, $video, ['mp4'])) {
                return null;
            }
            return ['video' => $video];
        case 'tts':
            $text = trim((string) ($post['tts_text'] ?? ''));
            return $text !== '' ? ['text' => $text] : null;
        case 'chat_message':
            $text = trim((string) ($post['chat_text'] ?? ''));
            return $text !== '' ? ['text' => $text] : null;
        default:
            return null;
    }
}

function point_store_fetch_items(mysqli $db) {
    $items = [];
    $iStmt = $db->query("SELECT * FROM point_store_items ORDER BY sort_order ASC, cost ASC, title ASC");
    if ($iStmt) {
        while ($row = $iStmt->fetch_assoc()) {
            $row['payload_decoded'] = [];
            if (!empty($row['payload'])) {
                $decoded = json_decode($row['payload'], true);
                if (is_array($decoded)) {
                    $row['payload_decoded'] = $decoded;
                }
            }
            $items[] = $row;
        }
    }
    return $items;
}

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        $storeMediaPath = point_store_media_path();
        echo json_encode([
            'success' => true,
            'items' => point_store_fetch_items($db),
            'sound_files' => point_store_list_media_library($storeMediaPath, ['mp3']),
            'video_files' => point_store_list_media_library($storeMediaPath, ['mp4']),
        ], JSON_UNESCAPED_UNICODE);
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $paused = isset($_POST['paused']) ? 1 : 0;
        $streamOnlineOnly = isset($_POST['stream_online_only']) ? 1 : 0;
        $globalCooldown = max(0, (int) ($_POST['global_cooldown_seconds'] ?? 0));
        $maxPerStream = trim((string) ($_POST['max_purchases_per_user_per_stream'] ?? ''));
        // Empty string → SQL NULL (unlimited). Bound as string so NULLIF works cleanly.
        $maxPerStreamStr = ($maxPerStream === '') ? '' : (string) max(1, (int) $maxPerStream);

        $stmt = $db->prepare("UPDATE point_store_settings SET enabled = ?, paused = ?, stream_online_only = ?, global_cooldown_seconds = ?, max_purchases_per_user_per_stream = NULLIF(?, '') WHERE id = 1");
        $stmt->bind_param('iiiis', $enabled, $paused, $streamOnlineOnly, $globalCooldown, $maxPerStreamStr);
        if ($stmt->execute()) {
            $status = t('point_store_settings_saved');
        } else {
            $status = t('point_store_error_generic');
            $statusType = 'danger';
        }
        $stmt->close();
    } elseif ($action === 'save_item') {
        $storeMediaPath = point_store_media_path();
        $soundFiles = point_store_list_media_library($storeMediaPath, ['mp3']);
        $videoFiles = point_store_list_media_library($storeMediaPath, ['mp4']);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $cost = (int) ($_POST['cost'] ?? 0);
        $itemType = (string) ($_POST['item_type'] ?? 'sound_alert');
        $enabled = isset($_POST['item_enabled']) ? 1 : 0;
        $cooldown = max(0, (int) ($_POST['cooldown_seconds'] ?? 0));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $maxPerStreamItem = trim((string) ($_POST['max_per_stream'] ?? ''));
        $maxPerStreamItemStr = ($maxPerStreamItem === '') ? '' : (string) max(1, (int) $maxPerStreamItem);
        $stock = trim((string) ($_POST['stock'] ?? ''));
        $stockStr = ($stock === '') ? '' : (string) max(0, (int) $stock);

        if ($title === '' || $cost < 1 || !in_array($itemType, $allowedTypes, true)) {
            $status = t('point_store_error_validation');
            $statusType = 'danger';
        } else {
            $payload = point_store_build_payload($itemType, $_POST, $storeMediaPath, $soundFiles, $videoFiles);
            if ($payload === null) {
                $status = t('point_store_error_payload');
                $statusType = 'danger';
            } else {
                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $slug = point_store_unique_slug($db, point_store_slugify($title), $itemId > 0 ? $itemId : null);
                $descVal = $description !== '' ? $description : null;

                if ($itemId > 0) {
                    $stmt = $db->prepare("UPDATE point_store_items SET title = ?, slug = ?, description = ?, cost = ?, item_type = ?, payload = ?, enabled = ?, cooldown_seconds = ?, max_per_stream = NULLIF(?, ''), stock = NULLIF(?, ''), sort_order = ? WHERE id = ?");
                    $stmt->bind_param(
                        'sssissiissii',
                        $title,
                        $slug,
                        $descVal,
                        $cost,
                        $itemType,
                        $payloadJson,
                        $enabled,
                        $cooldown,
                        $maxPerStreamItemStr,
                        $stockStr,
                        $sortOrder,
                        $itemId
                    );
                    if ($stmt->execute()) {
                        $status = t('point_store_item_updated');
                    } else {
                        $status = t('point_store_error_generic');
                        $statusType = 'danger';
                    }
                    $stmt->close();
                } else {
                    $stmt = $db->prepare("INSERT INTO point_store_items (title, slug, description, cost, item_type, payload, enabled, cooldown_seconds, max_per_stream, stock, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)");
                    $stmt->bind_param(
                        'sssissiissi',
                        $title,
                        $slug,
                        $descVal,
                        $cost,
                        $itemType,
                        $payloadJson,
                        $enabled,
                        $cooldown,
                        $maxPerStreamItemStr,
                        $stockStr,
                        $sortOrder
                    );
                    if ($stmt->execute()) {
                        $status = t('point_store_item_added');
                    } else {
                        $status = t('point_store_error_generic');
                        $statusType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'delete_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $stmt = $db->prepare("DELETE FROM point_store_items WHERE id = ?");
            $stmt->bind_param('i', $itemId);
            if ($stmt->execute()) {
                $status = t('point_store_item_deleted');
            } else {
                $status = t('point_store_error_generic');
                $statusType = 'danger';
            }
            $stmt->close();
        }
    } elseif ($action === 'toggle_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $stmt = $db->prepare("UPDATE point_store_items SET enabled = IF(enabled = 1, 0, 1) WHERE id = ?");
            $stmt->bind_param('i', $itemId);
            if ($stmt->execute()) {
                $status = t('point_store_item_toggled');
            } else {
                $status = t('point_store_error_generic');
                $statusType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Point name for display
$pointName = 'Points';
$pnStmt = $db->prepare("SELECT point_name FROM bot_settings LIMIT 1");
if ($pnStmt) {
    $pnStmt->execute();
    $pnRow = $pnStmt->get_result()->fetch_assoc();
    if ($pnRow && !empty($pnRow['point_name'])) {
        $pointName = $pnRow['point_name'];
    }
    $pnStmt->close();
}

// Load settings
$settings = [
    'enabled' => 0,
    'paused' => 0,
    'stream_online_only' => 0,
    'global_cooldown_seconds' => 0,
    'max_purchases_per_user_per_stream' => null,
];
$sStmt = $db->prepare("SELECT * FROM point_store_settings WHERE id = 1");
if ($sStmt) {
    $sStmt->execute();
    $row = $sStmt->get_result()->fetch_assoc();
    if ($row) {
        $settings = $row;
    }
    $sStmt->close();
}

$membersStoreUrl = 'https://members.botofthespecter.com/' . rawurlencode($_SESSION['username'] ?? '') . '/store';

ob_start();
?>
<div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
    <strong><?php echo t('point_store_beta_label'); ?></strong> <?php echo t('point_store_beta_notice'); ?>
</div>

<div class="sp-page-header" style="margin-bottom:1.25rem;">
    <h1 style="font-size:1.6rem;font-weight:700;margin:0;"><?php echo htmlspecialchars(t('point_store_title')); ?></h1>
    <p style="color:var(--text-secondary);margin:0.35rem 0 0;">
        <?php echo t('point_store_intro', ['point_name' => htmlspecialchars($pointName)]); ?>
    </p>
</div>

<?php if ($status): ?>
    <div class="sp-alert sp-alert-<?php echo $statusType === 'danger' ? 'danger' : 'success'; ?>" style="margin-bottom:1rem;">
        <?php echo htmlspecialchars($status); ?>
    </div>
<?php endif; ?>

<div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
    <div style="display:flex;align-items:flex-start;gap:1rem;">
        <span style="font-size:1.35rem;flex-shrink:0;"><i class="fas fa-store"></i></span>
        <div>
            <p style="font-weight:700;margin-bottom:0.35rem;"><?php echo t('point_store_howto_title'); ?></p>
            <ul style="margin:0;padding-left:1.1rem;color:var(--text-secondary);">
                <li><?php echo t('point_store_howto_1'); ?></li>
                <li><?php echo t('point_store_howto_2', ['url' => $membersStoreUrl]); ?></li>
                <li><?php echo t('point_store_howto_3'); ?></li>
            </ul>
        </div>
    </div>
</div>

<div class="sp-card" style="margin-bottom:1.25rem;">
    <div class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-sliders-h" style="margin-right:0.5rem;"></i><?php echo t('point_store_settings_title'); ?></span>
    </div>
    <div class="sp-card-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_settings">
            <div class="cc-form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1rem;">
                <div class="sp-form-group" style="margin:0;">
                    <label class="sp-label">
                        <input type="checkbox" name="enabled" value="1" <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                        <?php echo t('point_store_enabled'); ?>
                    </label>
                    <span class="sp-help"><?php echo t('point_store_enabled_help'); ?></span>
                </div>
                <div class="sp-form-group" style="margin:0;">
                    <label class="sp-label">
                        <input type="checkbox" name="paused" value="1" <?php echo !empty($settings['paused']) ? 'checked' : ''; ?>>
                        <?php echo t('point_store_paused'); ?>
                    </label>
                    <span class="sp-help"><?php echo t('point_store_paused_help'); ?></span>
                </div>
                <div class="sp-form-group" style="margin:0;">
                    <label class="sp-label">
                        <input type="checkbox" name="stream_online_only" value="1" <?php echo !empty($settings['stream_online_only']) ? 'checked' : ''; ?>>
                        <?php echo t('point_store_stream_online_only'); ?>
                    </label>
                    <span class="sp-help"><?php echo t('point_store_stream_online_only_help'); ?></span>
                </div>
            </div>
            <div class="cc-form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div class="sp-form-group" style="margin:0;">
                    <label class="sp-label" for="global_cooldown_seconds"><?php echo t('point_store_global_cooldown'); ?></label>
                    <input class="sp-input" type="number" min="0" id="global_cooldown_seconds" name="global_cooldown_seconds" value="<?php echo (int) ($settings['global_cooldown_seconds'] ?? 0); ?>">
                    <span class="sp-help"><?php echo t('point_store_global_cooldown_help'); ?></span>
                </div>
                <div class="sp-form-group" style="margin:0;">
                    <label class="sp-label" for="max_purchases_per_user_per_stream"><?php echo t('point_store_max_per_stream'); ?></label>
                    <input class="sp-input" type="number" min="1" id="max_purchases_per_user_per_stream" name="max_purchases_per_user_per_stream" value="<?php echo htmlspecialchars((string) ($settings['max_purchases_per_user_per_stream'] ?? '')); ?>" placeholder="<?php echo htmlspecialchars(t('point_store_unlimited')); ?>">
                    <span class="sp-help"><?php echo t('point_store_max_per_stream_help'); ?></span>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary" type="submit">
                <i class="fas fa-save"></i> <?php echo t('point_store_save_settings'); ?>
            </button>
        </form>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-box-open" style="margin-right:0.5rem;"></i><?php echo t('point_store_items_title'); ?></span>
        <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="addItemBtn" style="margin-left:auto;">
            <i class="fas fa-plus"></i> <?php echo t('point_store_add_item'); ?>
        </button>
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('point_store_col_title'); ?></th>
                        <th><?php echo t('point_store_col_type'); ?></th>
                        <th><?php echo t('point_store_col_cost', ['point_name' => htmlspecialchars($pointName)]); ?></th>
                        <th><?php echo t('point_store_col_payload'); ?></th>
                        <th><?php echo t('point_store_col_status'); ?></th>
                        <th><?php echo t('point_store_col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="pointStoreItemsBody" aria-busy="true">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                        <td><span class="sp-skeleton-line w-70"></span></td>
                        <td><span class="sp-skeleton-line w-50"></span></td>
                        <td><span class="sp-skeleton-line w-40"></span></td>
                        <td><span class="sp-skeleton-line w-60"></span></td>
                        <td><span class="sp-skeleton-badge"></span></td>
                        <td><span class="sp-skeleton-line w-80"></span></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit item modal -->
<div class="cc-modal-backdrop" id="itemModal">
    <div class="cc-modal" style="max-width:560px;">
        <div class="cc-modal-head">
            <span class="cc-modal-title" id="itemModalTitle"><?php echo t('point_store_add_item'); ?></span>
            <button type="button" class="cc-modal-close" id="itemModalClose" aria-label="<?php echo htmlspecialchars(t('point_store_close')); ?>">&times;</button>
        </div>
        <div class="cc-modal-body">
            <form method="POST" action="" id="itemForm">
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="item_id" id="item_id" value="0">

                <div class="sp-form-group">
                    <label class="sp-label" for="item_title"><?php echo t('point_store_field_title'); ?></label>
                    <input class="sp-input" type="text" name="title" id="item_title" maxlength="150" required>
                </div>

                <div class="sp-form-group">
                    <label class="sp-label" for="item_description"><?php echo t('point_store_field_description'); ?></label>
                    <textarea class="sp-textarea" name="description" id="item_description" rows="2" maxlength="500"></textarea>
                    <span class="sp-help"><?php echo t('point_store_field_description_help'); ?></span>
                </div>

                <div class="cc-form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="sp-form-group">
                        <label class="sp-label" for="item_cost"><?php echo t('point_store_field_cost', ['point_name' => htmlspecialchars($pointName)]); ?></label>
                        <input class="sp-input" type="number" name="cost" id="item_cost" min="1" value="100" required>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="item_type"><?php echo t('point_store_field_type'); ?></label>
                        <select class="sp-select" name="item_type" id="item_type">
                            <option value="sound_alert"><?php echo t('point_store_type_sound'); ?></option>
                            <option value="video_alert"><?php echo t('point_store_type_video'); ?></option>
                            <option value="tts"><?php echo t('point_store_type_tts'); ?></option>
                            <option value="chat_message"><?php echo t('point_store_type_chat'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="sp-form-group payload-field" data-type="sound_alert" id="soundFileHost" aria-busy="true">
                    <label class="sp-label" for="sound_file"><?php echo t('point_store_field_sound'); ?></label>
                    <div id="soundFileSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-90"></span>
                    </div>
                    <select class="sp-select" name="sound_file" id="sound_file" style="display:none;">
                        <option value=""><?php echo t('point_store_select_file'); ?></option>
                    </select>
                    <span id="soundFileEmptyHelp" class="sp-help sp-help-warning" style="display:none;"><?php echo t('point_store_no_sounds'); ?></span>
                </div>

                <div class="sp-form-group payload-field" data-type="video_alert" id="videoFileHost" aria-busy="true" style="display:none;">
                    <label class="sp-label" for="video_file"><?php echo t('point_store_field_video'); ?></label>
                    <div id="videoFileSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-90"></span>
                    </div>
                    <select class="sp-select" name="video_file" id="video_file" style="display:none;">
                        <option value=""><?php echo t('point_store_select_file'); ?></option>
                    </select>
                    <span id="videoFileEmptyHelp" class="sp-help sp-help-warning" style="display:none;"><?php echo t('point_store_no_videos'); ?></span>
                </div>

                <div class="sp-form-group payload-field" data-type="tts" style="display:none;">
                    <label class="sp-label" for="tts_text"><?php echo t('point_store_field_tts'); ?></label>
                    <textarea class="sp-textarea" name="tts_text" id="tts_text" rows="2" maxlength="300"></textarea>
                    <span class="sp-help"><?php echo t('point_store_field_tts_help'); ?></span>
                </div>

                <div class="sp-form-group payload-field" data-type="chat_message" style="display:none;">
                    <label class="sp-label" for="chat_text"><?php echo t('point_store_field_chat'); ?></label>
                    <textarea class="sp-textarea" name="chat_text" id="chat_text" rows="2" maxlength="400"></textarea>
                    <span class="sp-help"><?php echo t('point_store_field_chat_help'); ?></span>
                </div>

                <div class="cc-form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                    <div class="sp-form-group">
                        <label class="sp-label" for="cooldown_seconds"><?php echo t('point_store_field_cooldown'); ?></label>
                        <input class="sp-input" type="number" name="cooldown_seconds" id="cooldown_seconds" min="0" value="0">
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="sort_order"><?php echo t('point_store_field_sort'); ?></label>
                        <input class="sp-input" type="number" name="sort_order" id="sort_order" value="0">
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="max_per_stream"><?php echo t('point_store_field_max_stream'); ?></label>
                        <input class="sp-input" type="number" name="max_per_stream" id="max_per_stream" min="1" placeholder="<?php echo htmlspecialchars(t('point_store_unlimited')); ?>">
                    </div>
                </div>

                <div class="sp-form-group">
                    <label class="sp-label" for="stock"><?php echo t('point_store_field_stock'); ?></label>
                    <input class="sp-input" type="number" name="stock" id="stock" min="0" placeholder="<?php echo htmlspecialchars(t('point_store_unlimited')); ?>">
                    <span class="sp-help"><?php echo t('point_store_field_stock_help'); ?></span>
                </div>

                <div class="sp-form-group">
                    <label class="sp-label">
                        <input type="checkbox" name="item_enabled" id="item_enabled" value="1" checked>
                        <?php echo t('point_store_item_enabled'); ?>
                    </label>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:0.5rem;">
                    <button type="button" class="sp-btn sp-btn-secondary" id="itemModalCancel"><?php echo t('point_store_cancel'); ?></button>
                    <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-save"></i> <?php echo t('point_store_save_item'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
(function () {
    var modal = document.getElementById('itemModal');
    var titleEl = document.getElementById('itemModalTitle');
    var addLabels = {
        add: <?php echo json_encode(t('point_store_add_item')); ?>,
        edit: <?php echo json_encode(t('point_store_edit_item')); ?>
    };
    var typeLabels = {
        sound_alert: <?php echo json_encode(t('point_store_type_sound')); ?>,
        video_alert: <?php echo json_encode(t('point_store_type_video')); ?>,
        tts: <?php echo json_encode(t('point_store_type_tts')); ?>,
        chat_message: <?php echo json_encode(t('point_store_type_chat')); ?>
    };
    var I18N = {
        empty: <?php echo json_encode(t('point_store_empty')); ?>,
        loadError: <?php echo json_encode(t('point_store_error_generic')); ?>,
        statusEnabled: <?php echo json_encode(t('point_store_status_enabled')); ?>,
        statusDisabled: <?php echo json_encode(t('point_store_status_disabled')); ?>,
        edit: <?php echo json_encode(t('point_store_edit')); ?>,
        enable: <?php echo json_encode(t('point_store_enable')); ?>,
        disable: <?php echo json_encode(t('point_store_disable')); ?>,
        deleteConfirm: <?php echo json_encode(t('point_store_delete_confirm')); ?>,
        selectFile: <?php echo json_encode(t('point_store_select_file')); ?>
    };
    var soundFiles = [];
    var videoFiles = [];
    var mediaLoaded = false;
    var pendingEditData = null;

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function truncateText(str, maxLen) {
        var value = String(str == null ? '' : str);
        if (value.length <= maxLen) return value;
        return value.slice(0, maxLen - 1) + '…';
    }

    function payloadSummary(pd) {
        pd = pd || {};
        if (pd.sound) return String(pd.sound);
        if (pd.video) return String(pd.video);
        if (pd.text) return truncateText(pd.text, 48);
        return '';
    }

    function fillFileSelect(selectEl, files, placeholder) {
        if (!selectEl) return;
        var previous = selectEl.value;
        selectEl.innerHTML = '';
        var placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = placeholder;
        selectEl.appendChild(placeholderOpt);
        (files || []).forEach(function (file) {
            var opt = document.createElement('option');
            opt.value = file;
            opt.textContent = file;
            selectEl.appendChild(opt);
        });
        if (previous && (files || []).indexOf(previous) !== -1) {
            selectEl.value = previous;
        }
    }

    function revealFileSelect(hostId, skeletonId, selectId, emptyId, files) {
        var host = document.getElementById(hostId);
        var skeleton = document.getElementById(skeletonId);
        var selectEl = document.getElementById(selectId);
        var emptyHelp = document.getElementById(emptyId);
        if (skeleton) skeleton.style.display = 'none';
        if (selectEl) selectEl.style.display = '';
        if (emptyHelp) emptyHelp.style.display = files.length ? 'none' : '';
        if (host) host.setAttribute('aria-busy', 'false');
    }

    function populateMediaSelects() {
        fillFileSelect(document.getElementById('sound_file'), soundFiles, I18N.selectFile);
        fillFileSelect(document.getElementById('video_file'), videoFiles, I18N.selectFile);
        revealFileSelect('soundFileHost', 'soundFileSkeleton', 'sound_file', 'soundFileEmptyHelp', soundFiles);
        revealFileSelect('videoFileHost', 'videoFileSkeleton', 'video_file', 'videoFileEmptyHelp', videoFiles);
        mediaLoaded = true;
        if (pendingEditData) {
            applyPayloadFiles(pendingEditData);
            pendingEditData = null;
        }
    }

    function applyPayloadFiles(editData) {
        var p = (editData && editData.payload) || {};
        if (p.sound) document.getElementById('sound_file').value = p.sound;
        if (p.video) document.getElementById('video_file').value = p.video;
    }

    function renderItemsError() {
        var tbody = document.getElementById('pointStoreItemsBody');
        if (!tbody) return;
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '<tr><td colspan="6" style="color:var(--text-muted);">' + escapeHtml(I18N.loadError) + '</td></tr>';
    }

    function renderItems(items) {
        var tbody = document.getElementById('pointStoreItemsBody');
        if (!tbody) return;
        tbody.setAttribute('aria-busy', 'false');
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="color:var(--text-muted);">' + escapeHtml(I18N.empty) + '</td></tr>';
            return;
        }
        tbody.innerHTML = items.map(function (item) {
            var pd = item.payload_decoded || {};
            var summary = payloadSummary(pd);
            var enabled = Number(item.enabled) === 1;
            var typeLabel = typeLabels[item.item_type] || item.item_type;
            var slugHtml = item.slug
                ? '<div class="sp-help" style="margin:0;">!store ' + escapeHtml(item.slug) + '</div>'
                : '';
            var editPayload = {
                id: Number(item.id),
                title: item.title,
                description: item.description || '',
                cost: Number(item.cost),
                item_type: item.item_type,
                enabled: Number(item.enabled),
                cooldown_seconds: Number(item.cooldown_seconds),
                sort_order: Number(item.sort_order),
                max_per_stream: item.max_per_stream,
                stock: item.stock,
                payload: pd
            };
            return '<tr>' +
                '<td><strong>' + escapeHtml(item.title) + '</strong>' + slugHtml + '</td>' +
                '<td>' + escapeHtml(typeLabel) + '</td>' +
                '<td>' + escapeHtml(item.cost) + '</td>' +
                '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(summary) + '">' + escapeHtml(summary) + '</td>' +
                '<td>' + (enabled
                    ? '<span class="sp-badge sp-badge-green">' + escapeHtml(I18N.statusEnabled) + '</span>'
                    : '<span class="sp-badge sp-badge-grey">' + escapeHtml(I18N.statusDisabled) + '</span>') + '</td>' +
                '<td><div style="display:flex;flex-wrap:wrap;gap:0.35rem;">' +
                    '<button type="button" class="sp-btn sp-btn-secondary sp-btn-sm edit-item-btn" data-item="' + escapeHtml(JSON.stringify(editPayload)) + '">' +
                        '<i class="fas fa-edit"></i> ' + escapeHtml(I18N.edit) +
                    '</button>' +
                    '<form method="POST" action="" style="display:inline;margin:0;">' +
                        '<input type="hidden" name="action" value="toggle_item">' +
                        '<input type="hidden" name="item_id" value="' + escapeHtml(item.id) + '">' +
                        '<button class="sp-btn sp-btn-ghost sp-btn-sm" type="submit">' +
                            escapeHtml(enabled ? I18N.disable : I18N.enable) +
                        '</button>' +
                    '</form>' +
                    '<form method="POST" action="" style="display:inline;margin:0;" onsubmit="return confirm(' + JSON.stringify(I18N.deleteConfirm) + ');">' +
                        '<input type="hidden" name="action" value="delete_item">' +
                        '<input type="hidden" name="item_id" value="' + escapeHtml(item.id) + '">' +
                        '<button class="sp-btn sp-btn-danger sp-btn-sm" type="submit"><i class="fas fa-trash"></i></button>' +
                    '</form>' +
                '</div></td></tr>';
        }).join('');
    }

    function loadPointStore() {
        var url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax_action', 'list');
        fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    renderItemsError();
                    return;
                }
                soundFiles = Array.isArray(data.sound_files) ? data.sound_files : [];
                videoFiles = Array.isArray(data.video_files) ? data.video_files : [];
                populateMediaSelects();
                renderItems(Array.isArray(data.items) ? data.items : []);
            })
            .catch(function () {
                renderItemsError();
            });
    }

    function showPayloadFields(type) {
        document.querySelectorAll('.payload-field').forEach(function (el) {
            el.style.display = el.getAttribute('data-type') === type ? '' : 'none';
        });
    }

    function openModal(editData) {
        document.getElementById('itemForm').reset();
        document.getElementById('item_id').value = '0';
        document.getElementById('item_enabled').checked = true;
        document.getElementById('item_cost').value = '100';
        document.getElementById('cooldown_seconds').value = '0';
        document.getElementById('sort_order').value = '0';
        showPayloadFields('sound_alert');
        pendingEditData = null;

        if (editData) {
            titleEl.textContent = addLabels.edit;
            document.getElementById('item_id').value = editData.id || 0;
            document.getElementById('item_title').value = editData.title || '';
            document.getElementById('item_description').value = editData.description || '';
            document.getElementById('item_cost').value = editData.cost || 100;
            document.getElementById('item_type').value = editData.item_type || 'sound_alert';
            document.getElementById('item_enabled').checked = !!editData.enabled;
            document.getElementById('cooldown_seconds').value = editData.cooldown_seconds || 0;
            document.getElementById('sort_order').value = editData.sort_order || 0;
            document.getElementById('max_per_stream').value = editData.max_per_stream != null ? editData.max_per_stream : '';
            document.getElementById('stock').value = editData.stock != null ? editData.stock : '';
            showPayloadFields(editData.item_type || 'sound_alert');
            var p = editData.payload || {};
            if (editData.item_type === 'tts' && p.text) document.getElementById('tts_text').value = p.text;
            if (editData.item_type === 'chat_message' && p.text) document.getElementById('chat_text').value = p.text;
            if (mediaLoaded) {
                applyPayloadFiles(editData);
            } else {
                pendingEditData = editData;
            }
        } else {
            titleEl.textContent = addLabels.add;
        }
        modal.classList.add('is-active');
    }

    function closeModal() {
        modal.classList.remove('is-active');
    }

    document.getElementById('addItemBtn').addEventListener('click', function () { openModal(null); });
    document.getElementById('itemModalClose').addEventListener('click', closeModal);
    document.getElementById('itemModalCancel').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.getElementById('item_type').addEventListener('change', function () {
        showPayloadFields(this.value);
    });
    document.getElementById('pointStoreItemsBody').addEventListener('click', function (e) {
        var btn = e.target.closest('.edit-item-btn');
        if (!btn) return;
        try {
            openModal(JSON.parse(btn.getAttribute('data-item')));
        } catch (err) {
            console.error(err);
        }
    });

    loadPointStore();
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
