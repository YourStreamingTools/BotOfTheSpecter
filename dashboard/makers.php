<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('makers_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/bot_control.php';
include "includes/mod_access.php";
include 'includes/user_db.php';
include 'includes/storage_used.php';
require_once 'includes/upload_helpers.php';
session_write_close();

// API key for pushing live overlay updates (falls back to the session value).
$makerApiKey = isset($api_key) && $api_key ? $api_key : ($_SESSION['api_key'] ?? '');

// Images shown by this overlay are validated against the existing media-library
// MIME map, which covers png/jpg/jpeg/gif (not webp). Keep to that set.
$makerImageExts = ['png', 'jpg', 'jpeg', 'gif'];

// Ensure the singleton settings row exists so reads/writes are simple.
$db->query("INSERT INTO maker_overlay_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");

// Signal the live overlay to re-fetch its state.
function maker_notify_overlay($api_key) {
    if (!$api_key) { return; }
    $url = "https://websocket.botofthespecter.com/notify?code=" . urlencode($api_key) . "&event=MAKER_UPDATE";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_exec($ch);
} else {
        @file_get_contents($url);
    }
}

function maker_json($payload) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

// ---------------------------------------------------------------------------
// POST action handlers (each returns JSON and exits)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maker_action'])) {
    $action = $_POST['maker_action'];

    // --- Save overlay settings ---
    if ($action === 'save_settings') {
        $validLayouts = ['positioned', 'stacked-left', 'stacked-right'];
        $boxLayout = in_array($_POST['box_layout'] ?? '', $validLayouts, true) ? $_POST['box_layout'] : 'positioned';
        $validCanvases = ['1280x720', '1920x1080', '2560x1440'];
        $previewCanvas = in_array($_POST['preview_canvas'] ?? '', $validCanvases, true) ? $_POST['preview_canvas'] : '1920x1080';
        // Drag-editor coordinates: each box's top-left as a 0-100 percentage of the canvas.
        $posClamp = function ($v, $default) {
            return is_numeric($v) ? max(0, min(100, (float)$v)) : $default;
        };
        $fx = $posClamp($_POST['position_featured_x'] ?? null, 79);
        $fy = $posClamp($_POST['position_featured_y'] ?? null, 64);
        $cx = $posClamp($_POST['position_current_x'] ?? null, 2);
        $cy = $posClamp($_POST['position_current_y'] ?? null, 64);
        $ux = $posClamp($_POST['position_upcoming_x'] ?? null, 79);
        $uy = $posClamp($_POST['position_upcoming_y'] ?? null, 3);
        $dx = $posClamp($_POST['position_finished_x'] ?? null, 2);
        $dy = $posClamp($_POST['position_finished_y'] ?? null, 3);
        $visible = intval(!empty($_POST['visible']));
        $showTitle = intval(!empty($_POST['show_title']));
        $showDesc = intval(!empty($_POST['show_description']));
        $showFeatured = intval(!empty($_POST['show_featured']));
        $showCurrent = intval(!empty($_POST['show_current']));
        $showFinished = intval(!empty($_POST['show_finished']));
        $showUpcoming = intval(!empty($_POST['show_upcoming']));
        $carousel = max(2, min(60, intval($_POST['carousel_seconds'] ?? 6)));
        $rotate = max(3, min(120, intval($_POST['project_rotate_seconds'] ?? 15)));
        $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['accent_color'] ?? '') ? $_POST['accent_color'] : '#9146FF';
        $textColor = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['text_color'] ?? '') ? $_POST['text_color'] : '#FFFFFF';
        $allowedFonts = ['Arial', 'Verdana', 'Georgia', 'Tahoma', 'Trebuchet MS', 'Times New Roman', 'Courier New', 'Inter'];
        $font = in_array($_POST['font_family'] ?? '', $allowedFonts, true) ? $_POST['font_family'] : 'Arial';

        // Legacy display_mode/position/current_project_id columns are intentionally NOT
        // written here. Which boxes show and where they sit is driven by the show_*/
        // position_* flags; the featured project is derived from recency.
        $stmt = $db->prepare("INSERT INTO maker_overlay_settings
            (id, visible, carousel_seconds, project_rotate_seconds, accent_color, text_color, font_family, show_title, show_description, show_featured, show_current, show_finished, show_upcoming, box_layout, position_featured_x, position_featured_y, position_current_x, position_current_y, position_upcoming_x, position_upcoming_y, position_finished_x, position_finished_y, preview_canvas)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE visible = VALUES(visible),
                carousel_seconds = VALUES(carousel_seconds), project_rotate_seconds = VALUES(project_rotate_seconds),
                accent_color = VALUES(accent_color), text_color = VALUES(text_color), font_family = VALUES(font_family),
                show_title = VALUES(show_title), show_description = VALUES(show_description),
                show_featured = VALUES(show_featured), show_current = VALUES(show_current),
                show_finished = VALUES(show_finished), show_upcoming = VALUES(show_upcoming),
                box_layout = VALUES(box_layout),
                position_featured_x = VALUES(position_featured_x), position_featured_y = VALUES(position_featured_y),
                position_current_x = VALUES(position_current_x), position_current_y = VALUES(position_current_y),
                position_upcoming_x = VALUES(position_upcoming_x), position_upcoming_y = VALUES(position_upcoming_y),
                position_finished_x = VALUES(position_finished_x), position_finished_y = VALUES(position_finished_y),
                preview_canvas = VALUES(preview_canvas)");
        // Types: visible(i) carousel(i) rotate(i) accent(s) text(s) font(s) show_title(i) show_description(i) show_featured(i) show_current(i) show_finished(i) show_upcoming(i) box_layout(s) + 8 position coords (d) + preview_canvas(s)
        $stmt->bind_param("iiisssiiiiiisdddddddds", $visible, $carousel, $rotate, $accent, $textColor, $font, $showTitle, $showDesc, $showFeatured, $showCurrent, $showFinished, $showUpcoming, $boxLayout, $fx, $fy, $cx, $cy, $ux, $uy, $dx, $dy, $previewCanvas);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        if ($ok) { maker_notify_overlay($makerApiKey); }
        maker_json(['success' => $ok, 'error' => $ok ? null : $err]);
    }

    // --- Create a project ---
    if ($action === 'create_project') {
        $title = trim($_POST['title'] ?? '');
        $validStatus = ['current', 'finished', 'upcoming'];
        $status = in_array($_POST['status'] ?? '', $validStatus, true) ? $_POST['status'] : 'current';
        if ($title === '') { maker_json(['success' => false, 'error' => t('makers_err_title_required')]); }
        $title = mb_substr($title, 0, 255);
        $completed = ($status === 'finished') ? date('Y-m-d H:i:s') : null;
        $stmt = $db->prepare("INSERT INTO maker_projects (title, status, completed_at) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $title, $status, $completed);
        $ok = $stmt->execute();
        $newId = $db->insert_id;
        $err = $stmt->error;
        $stmt->close();
        // Auto-track model: a freshly created current project has the newest updated_at,
        // so it becomes the featured card on its own — no manual featured pointer to set.
        if ($ok) { maker_notify_overlay($makerApiKey); }
        maker_json(['success' => $ok, 'id' => $newId, 'error' => $ok ? null : $err]);
    }

    // --- Update a project ---
    if ($action === 'update_project') {
        $id = intval($_POST['id'] ?? 0);
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 255);
        $description = mb_substr(trim($_POST['description'] ?? ''), 0, 2000);
        $validStatus = ['current', 'finished', 'upcoming'];
        $status = in_array($_POST['status'] ?? '', $validStatus, true) ? $_POST['status'] : 'current';
        $link = trim($_POST['link_url'] ?? '');
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            maker_json(['success' => false, 'error' => t('makers_err_link_scheme')]);
        }
        $link = ($link === '') ? null : mb_substr($link, 0, 500);
        if ($id <= 0 || $title === '') { maker_json(['success' => false, 'error' => t('makers_err_invalid_project')]); }
        // Stamp completed_at when moving into finished and it's not already set.
        $completedSql = ($status === 'finished') ? ", completed_at = COALESCE(completed_at, NOW())" : "";
        $stmt = $db->prepare("UPDATE maker_projects SET title = ?, description = ?, status = ?, link_url = ?, updated_at = NOW()" . $completedSql . " WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $description, $status, $link, $id);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        if ($ok) { maker_notify_overlay($makerApiKey); }
        maker_json(['success' => $ok, 'error' => $ok ? null : $err]);
    }

    // --- Delete a project (+ its images) ---
    if ($action === 'delete_project') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { maker_json(['success' => false, 'error' => t('makers_err_invalid_project')]); }
        $delImgs = $db->prepare("DELETE FROM maker_project_images WHERE project_id = ?");
        $delImgs->bind_param("i", $id);
        $delImgs->execute();
        $delImgs->close();
        $stmt = $db->prepare("DELETE FROM maker_projects WHERE id = ?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        // No manual featured pointer to clear: the overlay auto-falls to the next
        // most-recent current project after a delete.
        if ($ok) { maker_notify_overlay($makerApiKey); }
        maker_json(['success' => $ok]);
    }

    // --- Feature a project as the current one (auto-track: promote + touch) ---
    if ($action === 'set_current') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { maker_json(['success' => false, 'error' => t('makers_err_invalid_project')]); }
        $chk = $db->prepare("SELECT id FROM maker_projects WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) { maker_json(['success' => false, 'error' => t('makers_err_no_such_project')]); }
        // "Feature now" = mark it current and stamp it as the most recently worked-on
        // project, so it becomes the featured card immediately.
        $stmt = $db->prepare("UPDATE maker_projects SET status = 'current', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        $db->query("UPDATE maker_overlay_settings SET display_mode = 'current' WHERE id = 1");
        if ($ok) { maker_notify_overlay($makerApiKey); }
        maker_json(['success' => $ok]);
    }

    // --- Upload image file(s) and attach them to a project ---
    if ($action === 'upload_image') {
        $projectId = intval($_POST['project_id'] ?? 0);
        if ($projectId <= 0) { maker_json(['success' => false, 'error' => t('makers_err_invalid_project')]); }
        if (!isset($_FILES['imageFiles'])) { maker_json(['success' => false, 'error' => t('makers_err_no_files')]); }
        ensureDirectoryWritable($media_path);
        $added = [];
        $errors = [];
        foreach ($_FILES['imageFiles']['tmp_name'] as $key => $tmp) {
            if (empty($tmp)) { continue; }
            $origName = $_FILES['imageFiles']['name'][$key];
            $fileSize = $_FILES['imageFiles']['size'][$key];
            $fileError = $_FILES['imageFiles']['error'][$key] ?? 0;
            $display = htmlspecialchars(basename($origName));
            if ($fileError !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $errors[] = t('makers_err_upload_failed', [$display]); continue; }
            if ($current_storage_used + $fileSize > $max_storage_size) { $errors[] = t('makers_err_storage_limit', [$display]); continue; }
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $makerImageExts, true)) { $errors[] = t('makers_err_not_image', [$display]); continue; }
            if (!upload_validate_extension_and_mime($tmp, $ext, $makerImageExts)) { $errors[] = t('makers_err_mime_mismatch', [$display]); continue; }
            $safeName = upload_sanitize_filename($origName, $ext);
            $target = upload_unique_target($media_path, $safeName);
            if (move_uploaded_file($tmp, $target['path'])) {
                $current_storage_used += $fileSize;
                $stmt = $db->prepare("INSERT INTO maker_project_images (project_id, media_file) VALUES (?, ?)");
                $stmt->bind_param("is", $projectId, $target['name']);
                if ($stmt->execute()) {
                    $added[] = $target['name'];
                } else {
                    $errors[] = t('makers_err_saved_not_recorded', [htmlspecialchars($target['name'])]);
                }
                $stmt->close();
            } else {
                $errors[] = t('makers_err_could_not_save', [$display]);
            }
        }
        if (!empty($added)) {
            // Adding images counts as working on the project: bump its timestamp so it
            // becomes/stays the featured current card.
            $touch = $db->prepare("UPDATE maker_projects SET updated_at = NOW() WHERE id = ?");
            $touch->bind_param("i", $projectId);
            $touch->execute();
            $touch->close();
            maker_notify_overlay($makerApiKey);
        }
        maker_json(['success' => !empty($added), 'added' => $added, 'errors' => $errors]);
    }

    // --- Attach an already-uploaded media file by name ---
    if ($action === 'attach_image') {
        $projectId = intval($_POST['project_id'] ?? 0);
        $file = preg_replace('/[^A-Za-z0-9._-]/', '', $_POST['media_file'] ?? '');
        $caption = mb_substr(trim($_POST['caption'] ?? ''), 0, 255);
        if ($projectId <= 0 || $file === '') { maker_json(['success' => false, 'error' => t('makers_err_project_file_required')]); }
        $caption = ($caption === '') ? null : $caption;
        $stmt = $db->prepare("INSERT INTO maker_project_images (project_id, media_file, caption) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $projectId, $file, $caption);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $touch = $db->prepare("UPDATE maker_projects SET updated_at = NOW() WHERE id = ?");
            $touch->bind_param("i", $projectId);
            $touch->execute();
            $touch->close();
            maker_notify_overlay($makerApiKey);
        }
        maker_json(['success' => $ok]);
    }

    // --- Remove an image row (file stays in the library) ---
    if ($action === 'delete_image') {
        $imgId = intval($_POST['image_id'] ?? 0);
        if ($imgId <= 0) { maker_json(['success' => false, 'error' => t('makers_err_invalid_image')]); }
        $stmt = $db->prepare("DELETE FROM maker_project_images WHERE id = ?");
        $stmt->bind_param("i", $imgId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) { maker_notify_overlay($makerApiKey); }
        maker_json(['success' => $ok]);
    }

    maker_json(['success' => false, 'error' => t('makers_err_unknown_action')]);
}

// ---------------------------------------------------------------------------
// Load data for rendering
// ---------------------------------------------------------------------------
$settings = [
    'display_mode' => 'current', 'current_project_id' => null, 'visible' => 1,
    'carousel_seconds' => 6, 'project_rotate_seconds' => 15, 'accent_color' => '#9146FF',
    'text_color' => '#FFFFFF', 'font_family' => 'Arial', 'position' => 'bottom-right',
    'show_title' => 1, 'show_description' => 1,
    'show_featured' => 1, 'show_current' => 0, 'show_finished' => 0, 'show_upcoming' => 0,
    'box_layout' => 'positioned',
    'position_featured_x' => 79, 'position_featured_y' => 64,
    'position_current_x' => 2, 'position_current_y' => 64,
    'position_upcoming_x' => 79, 'position_upcoming_y' => 3,
    'position_finished_x' => 2, 'position_finished_y' => 3,
    'preview_canvas' => '1920x1080',
];
if ($res = $db->query("SELECT * FROM maker_overlay_settings WHERE id = 1")) {
    if ($row = $res->fetch_assoc()) { $settings = array_merge($settings, $row); }
    $res->free();
}

$projects = [];
if ($res = $db->query("SELECT id, title, description, status, link_url, completed_at, updated_at FROM maker_projects ORDER BY FIELD(status,'current','upcoming','finished'), sort_order ASC, id ASC")) {
    while ($row = $res->fetch_assoc()) {
        $row['images'] = [];
        $projects[(int)$row['id']] = $row;
    }
    $res->free();
}

// Auto-track: the featured "current" card is the current-status project worked on most
// recently (newest updated_at; newer id breaks ties). Mirrors overlay/maker.php so the
// "Featured" badge here matches what viewers actually see.
$featuredId = 0;
$featuredStamp = '';
foreach ($projects as $pid => $pr) {
    if ($pr['status'] !== 'current') { continue; }
    $stamp = (string)($pr['updated_at'] ?? '');
    if ($featuredId === 0 || strcmp($stamp, $featuredStamp) > 0
        || ($stamp === $featuredStamp && $pid > $featuredId)) {
        $featuredId = $pid;
        $featuredStamp = $stamp;
    }
}
if (!empty($projects)) {
    if ($res = $db->query("SELECT id, project_id, media_file, caption FROM maker_project_images ORDER BY sort_order ASC, id ASC")) {
        while ($img = $res->fetch_assoc()) {
            $pid = (int)$img['project_id'];
            if (isset($projects[$pid])) { $projects[$pid]['images'][] = $img; }
        }
        $res->free();
    }
}

$mediaBase = 'https://media.botofthespecter.com/' . rawurlencode($username) . '/';
$overlayUrlBase = 'https://overlay.botofthespecter.com/maker.php?code=';
$overlayUrl = $overlayUrlBase . urlencode($makerApiKey);
// Masked version for default (visible) display so the API key is never shown on screen.
$overlayKeyEncoded = urlencode($makerApiKey);
$overlayUrlMasked = $overlayUrlBase . str_repeat('•', max(12, min(strlen($overlayKeyEncoded), 32)));
$fontOptions = ['Arial', 'Verdana', 'Georgia', 'Tahoma', 'Trebuchet MS', 'Times New Roman', 'Courier New', 'Inter'];
$statusLabels = ['current' => t('makers_status_current'), 'upcoming' => t('makers_status_upcoming'), 'finished' => t('makers_status_finished')];

// ---------------------------------------------------------------------------
// Page content
// ---------------------------------------------------------------------------
ob_start();
?>
<div class="sp-alert sp-alert-info" style="display:flex; gap:1.25rem; align-items:flex-start; margin-bottom:1.5rem;">
    <span style="font-size:1.75rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-palette"></i></span>
    <div>
        <p style="font-weight:700; margin-bottom:0.4rem;"><?= t('makers_page_title') ?></p>
        <p style="margin-bottom:0.4rem;"><?= t('makers_intro') ?></p>
        <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_overlay_url_label') ?></label>
        <div id="makerOverlayUrlBox" data-full-url="<?= htmlspecialchars($overlayUrl) ?>" data-masked-url="<?= htmlspecialchars($overlayUrlMasked) ?>" data-revealed="false" style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.4rem;">
            <code id="makerOverlayUrlText" class="info-box" style="font-family:monospace; margin-bottom:0; flex:1 1 auto; overflow:auto; white-space:nowrap;"><?= htmlspecialchars($overlayUrlMasked) ?></code>
            <button type="button" id="makerCopyUrlBtn" class="sp-btn sp-btn-secondary" title="<?= htmlspecialchars(t('makers_copy_url')) ?>" style="flex:0 0 auto; width:2.5rem; height:2.5rem; padding:0;">
                <i class="fas fa-copy" id="makerCopyUrlIcon"></i>
            </button>
            <button type="button" id="makerRevealUrlBtn" class="sp-btn sp-btn-secondary" title="<?= htmlspecialchars(t('makers_reveal_show')) ?>" data-show-label="<?= htmlspecialchars(t('makers_reveal_show')) ?>" data-hide-label="<?= htmlspecialchars(t('makers_reveal_hide')) ?>" style="flex:0 0 auto; width:2.5rem; height:2.5rem; padding:0;">
                <i class="fas fa-eye" id="makerRevealUrlIcon"></i>
            </button>
        </div>
        <p style="font-size:0.85rem; color:var(--text-muted, #888); margin:0;"><i class="fas fa-shield-alt" style="margin-right:0.4rem;"></i><?= t('makers_key_warning') ?></p>
    </div>
</div>

<!-- Overlay settings -->
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header"><div class="sp-card-title"><i class="fas fa-sliders-h"></i> <?= t('makers_settings_title') ?></div></div>
    <div class="sp-card-body">
        <form id="makerSettingsForm">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
                <div style="grid-column:1 / -1;">
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_layout') ?></label>
                    <select name="box_layout" class="sp-input" style="max-width:280px; margin-bottom:0.3rem;">
                        <?php foreach (['positioned' => t('makers_layout_positioned'), 'stacked-left' => t('makers_layout_stacked_left'), 'stacked-right' => t('makers_layout_stacked_right')] as $lv => $ll): ?>
                            <option value="<?= $lv ?>" <?= (($settings['box_layout'] ?? 'positioned') === $lv) ? 'selected' : '' ?>><?= htmlspecialchars($ll) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 0.6rem;"><?= t('makers_layout_hint') ?></p>
                    <label style="display:block; font-weight:600; margin-bottom:0.4rem;"><?= t('makers_boxes') ?></label>
                    <table style="width:100%; max-width:380px; border-collapse:collapse; margin-bottom:0.8rem;">
                        <thead>
                            <tr style="text-align:left; color:var(--text-secondary);">
                                <th style="padding:0.25rem 0.5rem; font-weight:600;"><?= t('makers_box') ?></th>
                                <th style="padding:0.25rem 0.5rem; font-weight:600; width:5rem;"><?= t('makers_show') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $boxRows = [
                                'featured' => t('makers_box_featured'),
                                'current'  => t('makers_box_current'),
                                'upcoming' => t('makers_box_upcoming'),
                                'finished' => t('makers_box_finished'),
                            ];
                            foreach ($boxRows as $boxKey => $boxLbl):
                                $showKey = 'show_' . $boxKey;
                            ?>
                            <tr>
                                <td style="padding:0.3rem 0.5rem;"><?= htmlspecialchars($boxLbl) ?></td>
                                <td style="padding:0.3rem 0.5rem;"><input type="checkbox" name="<?= $showKey ?>" value="1" <?= intval($settings[$showKey] ?? 0) ? 'checked' : '' ?>></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_drag_label') ?></label>
                    <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 0.5rem;"><?= t('makers_drag_hint') ?></p>
                    <label style="display:block; font-weight:600; margin-bottom:0.2rem;"><?= t('makers_canvas_size') ?></label>
                    <select id="makerCanvasSize" name="preview_canvas" class="sp-input" style="max-width:220px; margin-bottom:0.6rem;">
                        <?php foreach (['1280x720' => '1280 × 720 (720p)', '1920x1080' => '1920 × 1080 (1080p)', '2560x1440' => '2560 × 1440 (2K)'] as $cv => $cl): ?>
                            <option value="<?= $cv ?>" <?= (($settings['preview_canvas'] ?? '1920x1080') === $cv) ? 'selected' : '' ?>><?= $cl ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="makerPosCanvas" class="maker-pos-canvas">
                        <?php
                        $dragBoxes = [
                            'featured' => t('makers_box_featured'),
                            'current'  => t('makers_box_current'),
                            'upcoming' => t('makers_box_upcoming'),
                            'finished' => t('makers_box_finished'),
                        ];
                        foreach ($dragBoxes as $bk => $bl):
                            $xv = (float)($settings['position_' . $bk . '_x'] ?? 0);
                            $yv = (float)($settings['position_' . $bk . '_y'] ?? 0);
                        ?>
                        <div class="maker-pos-chip" data-box="<?= $bk ?>" style="left:<?= $xv ?>%; top:<?= $yv ?>%;"><?= htmlspecialchars($bl) ?></div>
                        <input type="hidden" name="position_<?= $bk ?>_x" id="makerPosX_<?= $bk ?>" value="<?= $xv ?>">
                        <input type="hidden" name="position_<?= $bk ?>_y" id="makerPosY_<?= $bk ?>" value="<?= $yv ?>">
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_font') ?></label>
                    <select name="font_family" class="sp-input">
                        <?php foreach ($fontOptions as $f): ?>
                            <option value="<?= htmlspecialchars($f) ?>" <?= ($settings['font_family'] === $f) ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_image_change_sec') ?></label>
                    <input type="number" name="carousel_seconds" class="sp-input" min="2" max="60" value="<?= intval($settings['carousel_seconds']) ?>">
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_project_rotate_sec') ?></label>
                    <input type="number" name="project_rotate_seconds" class="sp-input" min="3" max="120" value="<?= intval($settings['project_rotate_seconds']) ?>">
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_accent_colour') ?></label>
                    <input type="color" name="accent_color" value="<?= htmlspecialchars($settings['accent_color']) ?>" style="width:100%; height:38px;">
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_text_colour') ?></label>
                    <input type="color" name="text_color" value="<?= htmlspecialchars($settings['text_color']) ?>" style="width:100%; height:38px;">
                </div>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:1.25rem; margin-top:1rem;">
                <label><input type="checkbox" name="visible" value="1" <?= intval($settings['visible']) ? 'checked' : '' ?>> <?= t('makers_overlay_visible') ?></label>
                <label><input type="checkbox" name="show_title" value="1" <?= intval($settings['show_title']) ? 'checked' : '' ?>> <?= t('makers_show_title') ?></label>
                <label><input type="checkbox" name="show_description" value="1" <?= intval($settings['show_description']) ? 'checked' : '' ?>> <?= t('makers_show_description') ?></label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <span id="makerSettingsStatus" style="align-self:center; font-size:0.85rem;"></span>
                <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-save"></i> <?= t('makers_save_settings') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- New project -->
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header"><div class="sp-card-title"><i class="fas fa-plus-circle"></i> <?= t('makers_add_project') ?></div></div>
    <div class="sp-card-body">
        <form id="makerNewProjectForm" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end;">
            <div style="flex:1 1 240px;">
                <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_title') ?></label>
                <input type="text" name="title" class="sp-input" maxlength="255" placeholder="<?= htmlspecialchars(t('makers_title_placeholder')) ?>" required>
            </div>
            <div>
                <label style="display:block; font-weight:600; margin-bottom:0.25rem;"><?= t('makers_status') ?></label>
                <select name="status" class="sp-input">
                    <option value="current"><?= t('makers_status_current') ?></option>
                    <option value="upcoming"><?= t('makers_status_upcoming') ?></option>
                    <option value="finished"><?= t('makers_status_finished') ?></option>
                </select>
            </div>
            <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-plus"></i> <?= t('makers_add') ?></button>
        </form>
    </div>
</div>

<!-- Project library -->
<div class="sp-card">
    <div class="sp-card-header"><div class="sp-card-title"><i class="fas fa-layer-group"></i> <?= t('makers_library_title') ?></div></div>
    <div class="sp-card-body">
        <?php if (empty($projects)): ?>
            <p style="color:var(--text-secondary);"><?= t('makers_empty') ?></p>
        <?php else: ?>
            <?php foreach ($projects as $p):
                $pid = (int)$p['id'];
                $isFeatured = ($featuredId === $pid);
            ?>
            <div class="sp-card" style="margin-bottom:1rem; border:1px solid var(--border, rgba(255,255,255,0.1));" data-project="<?= $pid ?>">
                <div class="sp-card-body">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                        <div>
                            <span style="font-weight:700;">#<?= $pid ?> <?= htmlspecialchars($p['title']) ?></span>
                            <span class="sp-badge" style="margin-left:0.5rem; text-transform:capitalize;"><?= htmlspecialchars($statusLabels[$p['status']] ?? $p['status']) ?></span>
                            <?php if ($isFeatured): ?><span class="sp-badge" style="margin-left:0.25rem; background:var(--accent, #9146FF); color:#fff;"><?= t('makers_featured') ?></span><?php endif; ?>
                        </div>
                        <div style="display:flex; gap:0.4rem;">
                            <?php if (!$isFeatured): ?>
                            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary maker-set-current" data-id="<?= $pid ?>" title="<?= htmlspecialchars(t('makers_feature_tooltip')) ?>"><i class="fas fa-star"></i></button>
                            <?php endif; ?>
                            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary maker-edit-toggle" data-id="<?= $pid ?>"><i class="fas fa-edit"></i> <?= t('makers_edit') ?></button>
                            <button type="button" class="sp-btn sp-btn-sm sp-btn-danger maker-delete" data-id="<?= $pid ?>"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>

                    <!-- Inline edit form -->
                    <form class="maker-edit-form" data-id="<?= $pid ?>" style="display:none; margin-top:0.75rem;">
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:0.75rem;">
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:0.2rem;"><?= t('makers_title') ?></label>
                                <input type="text" name="title" class="sp-input" maxlength="255" value="<?= htmlspecialchars($p['title']) ?>">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:0.2rem;"><?= t('makers_status') ?></label>
                                <select name="status" class="sp-input">
                                    <?php foreach (['current', 'upcoming', 'finished'] as $st): ?>
                                        <option value="<?= $st ?>" <?= ($p['status'] === $st) ? 'selected' : '' ?>><?= $statusLabels[$st] ?? ucfirst($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="grid-column:1/-1;">
                                <label style="display:block; font-weight:600; margin-bottom:0.2rem;"><?= t('makers_description_context') ?></label>
                                <textarea name="description" class="sp-input" rows="2" maxlength="2000"><?= htmlspecialchars($p['description'] ?? '') ?></textarea>
                            </div>
                            <div style="grid-column:1/-1;">
                                <label style="display:block; font-weight:600; margin-bottom:0.2rem;"><?= t('makers_link_optional') ?></label>
                                <input type="text" name="link_url" class="sp-input" maxlength="500" value="<?= htmlspecialchars($p['link_url'] ?? '') ?>" placeholder="https://...">
                            </div>
                        </div>
                        <div style="display:flex; justify-content:flex-end; margin-top:0.5rem;">
                            <button type="submit" class="sp-btn sp-btn-sm sp-btn-primary"><i class="fas fa-save"></i> <?= t('makers_save') ?></button>
                        </div>
                    </form>

                    <!-- Images -->
                    <div style="margin-top:0.75rem;">
                        <div style="font-weight:600; margin-bottom:0.4rem;"><?= t('makers_images') ?> (<?= count($p['images']) ?>)</div>
                        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.5rem;">
                            <?php foreach ($p['images'] as $img): ?>
                                <div style="position:relative; width:90px; height:90px; border-radius:8px; overflow:hidden; border:1px solid var(--border, rgba(255,255,255,0.12));">
                                    <img src="<?= htmlspecialchars($mediaBase . rawurlencode($img['media_file'])) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                                    <button type="button" class="maker-delete-image" data-image="<?= (int)$img['id'] ?>" title="<?= htmlspecialchars(t('makers_remove')) ?>" style="position:absolute; top:2px; right:2px; background:rgba(0,0,0,0.7); color:#fff; border:none; border-radius:4px; cursor:pointer; padding:2px 6px;">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form class="maker-upload-form" data-id="<?= $pid ?>" enctype="multipart/form-data" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;">
                            <input type="file" name="imageFiles[]" accept="image/png,image/jpeg,image/gif" multiple class="sp-input" style="flex:1 1 200px;">
                            <button type="submit" class="sp-btn sp-btn-sm sp-btn-secondary"><i class="fas fa-upload"></i> <?= t('makers_upload_images') ?></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function post(data, isForm) {
        return fetch('makers.php', {
            method: 'POST',
            body: isForm ? data : new URLSearchParams(data)
        }).then(function (r) { return r.json(); });
    }

    // Settings save
    var settingsForm = document.getElementById('makerSettingsForm');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var status = document.getElementById('makerSettingsStatus');
            var fd = new FormData(settingsForm);
            fd.append('maker_action', 'save_settings');
            post(fd, true).then(function (res) {
                status.textContent = res.success ? <?= json_encode(t('makers_js_saved')) ?> : (<?= json_encode(t('makers_js_error_prefix')) ?> + (res.error || <?= json_encode(t('makers_js_failed_generic')) ?>));
                status.style.color = res.success ? 'var(--green, #23d160)' : 'var(--red, #ff5252)';
                setTimeout(function () { status.textContent = ''; }, 2500);
            });
        });
    }

    // New project
    var newForm = document.getElementById('makerNewProjectForm');
    if (newForm) {
        newForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(newForm);
            fd.append('maker_action', 'create_project');
            post(fd, true).then(function (res) {
                if (res.success) { location.reload(); }
                else { alert(res.error || <?= json_encode(t('makers_js_add_failed')) ?>); }
            });
        });
    }

    // Per-project controls (event delegation)
    document.addEventListener('click', function (e) {
        var t = e.target.closest('button');
        if (!t) { return; }

        if (t.classList.contains('maker-set-current')) {
            post({ maker_action: 'set_current', id: t.dataset.id }).then(function (res) {
                if (res.success) { location.reload(); } else { alert(res.error || <?= json_encode(t('makers_js_failed')) ?>); }
            });
        } else if (t.classList.contains('maker-delete')) {
            if (!confirm(<?= json_encode(t('makers_js_confirm_delete')) ?>)) { return; }
            post({ maker_action: 'delete_project', id: t.dataset.id }).then(function (res) {
                if (res.success) { location.reload(); } else { alert(<?= json_encode(t('makers_js_failed')) ?>); }
            });
        } else if (t.classList.contains('maker-edit-toggle')) {
            var f = document.querySelector('.maker-edit-form[data-id="' + t.dataset.id + '"]');
            if (f) { f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none'; }
        } else if (t.classList.contains('maker-delete-image')) {
            post({ maker_action: 'delete_image', image_id: t.dataset.image }).then(function (res) {
                if (res.success) { location.reload(); } else { alert(<?= json_encode(t('makers_js_failed')) ?>); }
            });
        }
    });

    // Edit form submit (delegation)
    document.querySelectorAll('.maker-edit-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            fd.append('maker_action', 'update_project');
            fd.append('id', form.dataset.id);
            post(fd, true).then(function (res) {
                if (res.success) { location.reload(); } else { alert(res.error || <?= json_encode(t('makers_js_failed')) ?>); }
            });
        });
    });

    // Image upload (delegation)
    document.querySelectorAll('.maker-upload-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            fd.append('maker_action', 'upload_image');
            fd.append('project_id', form.dataset.id);
            post(fd, true).then(function (res) {
                if (res.success) { location.reload(); }
                else { alert((res.errors && res.errors.join('\n')) || res.error || <?= json_encode(t('makers_js_upload_failed')) ?>); }
            });
        });
    });

    // Overlay URL: masked display with copy + reveal (keeps the API key off-screen by default)
    var overlayUrlBox = document.getElementById('makerOverlayUrlBox');
    if (overlayUrlBox) {
        var overlayUrlText = document.getElementById('makerOverlayUrlText');
        var copyUrlBtn = document.getElementById('makerCopyUrlBtn');
        var copyUrlIcon = document.getElementById('makerCopyUrlIcon');
        var revealUrlBtn = document.getElementById('makerRevealUrlBtn');
        var revealUrlIcon = document.getElementById('makerRevealUrlIcon');
        var fullUrl = overlayUrlBox.dataset.fullUrl || '';
        var maskedUrl = overlayUrlBox.dataset.maskedUrl || '';

        if (revealUrlBtn) {
            revealUrlBtn.addEventListener('click', function () {
                var revealed = overlayUrlBox.dataset.revealed === 'true';
                if (revealed) {
                    overlayUrlText.textContent = maskedUrl;
                    overlayUrlBox.dataset.revealed = 'false';
                    revealUrlIcon.classList.remove('fa-eye-slash');
                    revealUrlIcon.classList.add('fa-eye');
                    revealUrlBtn.title = revealUrlBtn.dataset.showLabel || '';
                } else {
                    overlayUrlText.textContent = fullUrl;
                    overlayUrlBox.dataset.revealed = 'true';
                    revealUrlIcon.classList.remove('fa-eye');
                    revealUrlIcon.classList.add('fa-eye-slash');
                    revealUrlBtn.title = revealUrlBtn.dataset.hideLabel || '';
                }
            });
        }

        if (copyUrlBtn) {
            copyUrlBtn.addEventListener('click', function () {
                function showCopied() {
                    copyUrlIcon.classList.remove('fa-copy');
                    copyUrlIcon.classList.add('fa-check');
                    copyUrlBtn.classList.add('sp-btn-success');
                    var prevTitle = copyUrlBtn.title;
                    copyUrlBtn.title = <?= json_encode(t('makers_url_copied')) ?>;
                    setTimeout(function () {
                        copyUrlIcon.classList.remove('fa-check');
                        copyUrlIcon.classList.add('fa-copy');
                        copyUrlBtn.classList.remove('sp-btn-success');
                        copyUrlBtn.title = prevTitle;
                    }, 2000);
                }
                function fallbackCopy() {
                    var ta = document.createElement('textarea');
                    ta.value = fullUrl;
                    ta.style.position = 'fixed';
                    ta.style.left = '-999999px';
                    ta.style.top = '-999999px';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    try { document.execCommand('copy'); showCopied(); }
                    catch (err) { console.error('Fallback copy failed: ', err); }
                    document.body.removeChild(ta);
                }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(fullUrl).then(showCopied).catch(function (err) {
                        console.error('Failed to copy: ', err);
                        fallbackCopy();
                    });
                } else {
                    fallbackCopy();
                }
            });
        }
    }

    // Drag-to-place editor: drag each box on the preview canvas; persist its top-left as
    // an x/y percentage into the hidden inputs that save with the settings form.
    (function () {
        var canvas = document.getElementById('makerPosCanvas');
        if (!canvas) { return; }
        var chips = canvas.querySelectorAll('.maker-pos-chip');
        var dragging = null, grabX = 0, grabY = 0;
        function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

        // Size the chips to the real box footprint for the chosen OBS canvas, so the
        // preview is true-to-scale. The overlay card is a fixed 360px wide, so its share
        // of the canvas (and representative height) scales with the canvas width.
        var canvasWidths = { '1280x720': 1280, '1920x1080': 1920, '2560x1440': 2560 };
        var sizeSel = document.getElementById('makerCanvasSize');
        function applyCanvasSize() {
            var w = canvasWidths[sizeSel ? sizeSel.value : '1920x1080'] || 1920;
            var scale = 1920 / w;
            // Chip matches the real box footprint: 360px wide, 320px tall (ref 1920x1080).
            var chipW = (360 / 1920 * 100) * scale, chipH = (320 / 1080 * 100) * scale;
            chips.forEach(function (chip) {
                chip.style.width = chipW + '%';
                chip.style.height = chipH + '%';
                var left = clamp(parseFloat(chip.style.left) || 0, 0, 100 - chipW);
                var top = clamp(parseFloat(chip.style.top) || 0, 0, 100 - chipH);
                chip.style.left = left + '%';
                chip.style.top = top + '%';
                var box = chip.getAttribute('data-box');
                document.getElementById('makerPosX_' + box).value = left.toFixed(2);
                document.getElementById('makerPosY_' + box).value = top.toFixed(2);
            });
        }
        if (sizeSel) { sizeSel.addEventListener('change', applyCanvasSize); }

        chips.forEach(function (chip) {
            chip.addEventListener('pointerdown', function (e) {
                dragging = chip;
                chip.setPointerCapture(e.pointerId);
                var r = chip.getBoundingClientRect();
                grabX = e.clientX - r.left;
                grabY = e.clientY - r.top;
                e.preventDefault();
            });
            chip.addEventListener('pointermove', function (e) {
                if (dragging !== chip) { return; }
                var cr = canvas.getBoundingClientRect();
                var wpct = (chip.offsetWidth / cr.width) * 100;
                var hpct = (chip.offsetHeight / cr.height) * 100;
                var xp = clamp(((e.clientX - cr.left - grabX) / cr.width) * 100, 0, 100 - wpct);
                var yp = clamp(((e.clientY - cr.top - grabY) / cr.height) * 100, 0, 100 - hpct);
                chip.style.left = xp + '%';
                chip.style.top = yp + '%';
                var box = chip.getAttribute('data-box');
                document.getElementById('makerPosX_' + box).value = xp.toFixed(2);
                document.getElementById('makerPosY_' + box).value = yp.toFixed(2);
            });
            function endDrag() { if (dragging === chip) { dragging = null; } }
            chip.addEventListener('pointerup', endDrag);
            chip.addEventListener('pointercancel', endDrag);
        });
        // Dim a chip when its box is unchecked, so the canvas mirrors what will show.
        function syncDisabled() {
            ['featured', 'current', 'upcoming', 'finished'].forEach(function (box) {
                var cb = document.querySelector('input[name="show_' + box + '"]');
                var chip = canvas.querySelector('.maker-pos-chip[data-box="' + box + '"]');
                if (cb && chip) { chip.classList.toggle('is-disabled', !cb.checked); }
            });
        }
        ['featured', 'current', 'upcoming', 'finished'].forEach(function (box) {
            var cb = document.querySelector('input[name="show_' + box + '"]');
            if (cb) { cb.addEventListener('change', syncDisabled); }
        });
        applyCanvasSize();
        syncDisabled();
    })();
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>

