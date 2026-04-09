<?php
// support/docs.php
// ----------------------------------------------------------------
// Staff-only documentation CMS.
// Manages support_doc_sections and support_docs tables.
//
// Routes (GET ?action=):
//   (none)              — section list overview
//   new_section         — form to create a section
//   edit_section&key=   — form to edit/delete a section
//   new&section=        — form to create a doc block
//   edit&id=            — form to edit/delete a doc block
//
// POST _action values:
//   save_section        — insert or update a section
//   delete_section      — delete section + all its blocks
//   save_doc            — insert or update a doc block
//   delete_doc          — delete a single doc block
//   toggle_vis          — flip is_visible on a doc block
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
support_session_start();
require_login();

if (!is_staff()) {
    header('Location: /index.php');
    exit;
}

$db     = support_db();
$action = $_GET['action'] ?? 'list';
$flash  = [];

// ----------------------------------------------------------------
// POST handlers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $flash[] = ['type' => 'danger', 'msg' => 'Security token mismatch. Please try again.'];
    } else {
        $postAction = $_POST['_action'] ?? '';
        // ---- Save section (new or update) ----
        if ($postAction === 'save_section') {
            $key   = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['section_key'] ?? '')));
            $label = trim($_POST['section_label'] ?? '');
            $icon  = trim($_POST['section_icon']  ?? 'fa-solid fa-file');
            $order = (int)($_POST['section_order'] ?? 0);
            $editId = (int)($_POST['edit_id'] ?? 0);
            if (strlen($key) < 1)   $flash[] = ['type'=>'danger','msg'=>'Section key is required.'];
            if (strlen($label) < 1) $flash[] = ['type'=>'danger','msg'=>'Section label is required.'];
            if (empty($flash)) {
                if ($editId > 0) {
                    // Get the old key first so we can update doc rows too
                    $old = $db->query("SELECT section_key FROM support_doc_sections WHERE id = {$editId}");
                    $oldRow = $old ? $old->fetch_assoc() : null;
                    $oldKey = $oldRow['section_key'] ?? '';
                    $stmt = $db->prepare(
                        'UPDATE support_doc_sections SET section_key=?, section_label=?, section_icon=?, section_order=? WHERE id=?'
                    );
                    $stmt->bind_param('sssii', $key, $label, $icon, $order, $editId);
                    $stmt->execute();
                    $stmt->close();
                    // Cascade key rename to docs
                    if ($oldKey && $oldKey !== $key) {
                        $esc = $db->real_escape_string($oldKey);
                        $escNew = $db->real_escape_string($key);
                        $db->query("UPDATE support_docs SET section_key='{$escNew}' WHERE section_key='{$esc}'");
                    }
                    $flash[] = ['type'=>'success','msg'=>'Section updated.'];
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO support_doc_sections (section_key, section_label, section_icon, section_order) VALUES (?,?,?,?)'
                    );
                    $stmt->bind_param('sssi', $key, $label, $icon, $order);
                    if ($stmt->execute()) {
                        $flash[] = ['type'=>'success','msg'=>'Section created.'];
                    } else {
                        $flash[] = ['type'=>'danger','msg'=>'Could not create section — key may already exist.'];
                    }
                    $stmt->close();
                }
                header('Location: /docs.php');
                exit;
            }
        }
        // ---- Delete section ----
        if ($postAction === 'delete_section') {
            $delId = (int)($_POST['section_id'] ?? 0);
            if ($delId > 0) {
                // Fetch key so we can delete docs too
                $row = $db->query("SELECT section_key FROM support_doc_sections WHERE id = {$delId}");
                $row = $row ? $row->fetch_assoc() : null;
                if ($row) {
                    $esc = $db->real_escape_string($row['section_key']);
                    $db->query("DELETE FROM support_docs WHERE section_key = '{$esc}'");
                    $db->query("DELETE FROM support_doc_sections WHERE id = {$delId}");
                }
            }
            header('Location: /docs.php');
            exit;
        }
        // ---- Save doc block (new or update) ----
        if ($postAction === 'save_doc') {
            $secKey   = trim($_POST['section_key'] ?? '');
            $title    = trim($_POST['doc_title']   ?? '');
            $content  = trim($_POST['doc_content'] ?? '');
            $order    = (int)($_POST['doc_order']   ?? 0);
            $visible  = isset($_POST['is_visible']) ? 1 : 0;
            $editDocId = (int)($_POST['edit_id']   ?? 0);
            $author   = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'staff';
            if (empty($secKey))  $flash[] = ['type'=>'danger','msg'=>'A section is required.'];
            if (empty($content)) $flash[] = ['type'=>'danger','msg'=>'Content cannot be empty.'];
            if (empty($flash)) {
                if ($editDocId > 0) {
                    $stmt = $db->prepare(
                        'UPDATE support_docs SET section_key=?, title=?, content=?, doc_order=?, is_visible=?, updated_by=? WHERE id=?'
                    );
                    $stmt->bind_param('sssiisi', $secKey, $title, $content, $order, $visible, $author, $editDocId);
                    $stmt->execute();
                    $stmt->close();
                    header('Location: /docs.php?saved=1');
                    exit;
                } else {
                    // 7 params: section_key(s) title(s) content(s) doc_order(i) is_visible(i) created_by(s) updated_by(s)
                    $stmt = $db->prepare(
                        'INSERT INTO support_docs (section_key, title, content, doc_order, is_visible, created_by, updated_by) VALUES (?,?,?,?,?,?,?)'
                    );
                    $stmt->bind_param('sssiiss', $secKey, $title, $content, $order, $visible, $author, $author);
                    $newId = 0;
                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                    }
                    $stmt->close();
                    header('Location: /docs.php?action=edit&id=' . $newId . '&saved=1');
                    exit;
                }
            }
        }
        // ---- Delete doc block ----
        if ($postAction === 'delete_doc') {
            $delId = (int)($_POST['id'] ?? 0);
            $back  = $_POST['back']    ?? 'docs';
            $sec   = $_POST['section'] ?? '';
            if ($delId > 0) {
                $db->query("DELETE FROM support_docs WHERE id = {$delId}");
            }
            if ($back === 'index' && $sec) {
                header('Location: /index.php#' . urlencode($sec));
            } else {
                header('Location: /docs.php');
            }
            exit;
        }
        // ---- Toggle visibility ----
        if ($postAction === 'toggle_vis') {
            $togId = (int)($_POST['id'] ?? 0);
            $back  = $_POST['back']    ?? 'docs';
            $sec   = $_POST['section'] ?? '';
            if ($togId > 0) {
                $db->query("UPDATE support_docs SET is_visible = NOT is_visible WHERE id = {$togId}");
            }
            if ($back === 'index' && $sec) {
                header('Location: /index.php#' . urlencode($sec));
            } else {
                header('Location: /docs.php');
            }
            exit;
        }
    }
}

// ----------------------------------------------------------------
// Load sections
// ----------------------------------------------------------------
$secResult = $db->query('SELECT * FROM support_doc_sections ORDER BY section_order ASC, section_label ASC');
$sections  = $secResult ? $secResult->fetch_all(MYSQLI_ASSOC) : [];
$sectionsByKey = [];
foreach ($sections as $s) $sectionsByKey[$s['section_key']] = $s;

// ----------------------------------------------------------------
// Routing for edit views
// ----------------------------------------------------------------
$editSection = null;
$editDoc     = null;
$newInSection = $_GET['section'] ?? '';

if ($action === 'edit_section') {
    $key = $_GET['key'] ?? '';
    foreach ($sections as $s) {
        if ($s['section_key'] === $key) { $editSection = $s; break; }
    }
}

if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $result = $db->query("SELECT * FROM support_docs WHERE id = {$id}");
    if ($result) $editDoc = $result->fetch_assoc();
}

// Doc block count per section for list view
$countsResult = $db->query('SELECT section_key, COUNT(*) as total, SUM(is_visible) as visible FROM support_docs GROUP BY section_key');
$counts = [];
if ($countsResult) {
    while ($r = $countsResult->fetch_assoc()) {
        $counts[$r['section_key']] = $r;
    }
}

// Max doc_order per section (used to auto-fill the order field on new doc forms)
$maxOrdersResult = $db->query('SELECT section_key, COALESCE(MAX(doc_order), 0) AS max_order FROM support_docs GROUP BY section_key');
$maxOrdersBySec = [];
if ($maxOrdersResult) {
    while ($r = $maxOrdersResult->fetch_assoc()) {
        $maxOrdersBySec[$r['section_key']] = (int)$r['max_order'];
    }
}

// Next section_order for new sections
$maxSecOrderResult = $db->query('SELECT COALESCE(MAX(section_order), 0) AS max_order FROM support_doc_sections');
$nextSecOrder = 1;
if ($maxSecOrderResult) {
    $r = $maxSecOrderResult->fetch_assoc();
    $nextSecOrder = (int)$r['max_order'] + 1;
}

// ----------------------------------------------------------------
// Page setup
// ----------------------------------------------------------------
$pageTitle   = 'Manage Documentation';
$topbarTitle = 'Docs CMS';

ob_start();

// Saved flash
if (isset($_GET['saved'])): ?>
<div class="sp-alert sp-alert-success" data-dismiss="4000">
    <i class="fa-solid fa-circle-check"></i>
    <span>Changes saved successfully.</span>
</div>
<?php endif;

// Any inline flash errors
foreach ($flash as $f): ?>
<div class="sp-alert sp-alert-<?php echo htmlspecialchars($f['type']); ?>" data-dismiss="6000">
    <i class="fa-solid <?php echo $f['type']==='success' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
    <span><?php echo htmlspecialchars($f['msg']); ?></span>
</div>
<?php endforeach;

// ================================================================
// VIEW: EDIT DOC BLOCK
// ================================================================
if ($action === 'edit' || $action === 'new'):
    $isNew = ($action === 'new');
    $defaultOrder = 1;
    if ($isNew && $newInSection !== '' && isset($maxOrdersBySec[$newInSection])) {
        $defaultOrder = $maxOrdersBySec[$newInSection] + 1;
    }
    $d = $editDoc ?? [
        'id'          => 0,
        'section_key' => $newInSection,
        'title'       => '',
        'content'     => '',
        'doc_order'   => $defaultOrder,
        'is_visible'  => 1,
    ];
    $heading = $isNew ? 'New Doc Block' : 'Edit Doc Block';
    if (!$isNew && $editDoc && !empty($editDoc['title'])) $heading = 'Edit: ' . $editDoc['title'];
?>
<div class="sp-page-header">
    <div>
        <a href="/docs.php" class="sp-back-link"><i class="fa-solid fa-arrow-left"></i> All Sections</a>
        <h1><?php echo htmlspecialchars($heading); ?></h1>
        <?php if (!$isNew && $editDoc): ?>
        <p style="color:var(--text-secondary);">
            Section: <strong><?php echo htmlspecialchars($sectionsByKey[$editDoc['section_key']]['section_label'] ?? $editDoc['section_key']); ?></strong>
            &nbsp;—&nbsp; ID: <code><?php echo (int)$editDoc['id']; ?></code>
        </p>
        <?php endif; ?>
    </div>
    <?php if (!$isNew && $editDoc): ?>
    <div style="display:flex;gap:0.5rem;">
        <a href="/index.php#<?php echo urlencode($editDoc['section_key']); ?>" target="_blank"
           class="sp-btn sp-btn-ghost sp-btn-sm">
            <i class="fa-solid fa-eye"></i> View on Site
        </a>
        <form method="POST" action="/docs.php"
              onsubmit="return confirm('Delete this doc block permanently?');" style="display:inline;">
            <input type="hidden" name="_action"    value="delete_doc">
            <input type="hidden" name="id"         value="<?php echo (int)$editDoc['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="back"       value="docs">
            <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm">
                <i class="fa-solid fa-trash"></i> Delete Block
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<form method="POST" action="/docs.php" id="sp-doc-form">
    <input type="hidden" name="_action"    value="save_doc">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="edit_id"    value="<?php echo (int)$d['id']; ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
        <div class="sp-form-group" style="margin-bottom:0;">
            <label class="sp-label" for="section_key">Section <span class="sp-req">*</span></label>
            <select id="section_key" name="section_key" class="sp-select" required>
                <option value="">— choose section —</option>
                <?php foreach ($sections as $s): ?>
                <option value="<?php echo htmlspecialchars($s['section_key']); ?>"
                    <?php echo ($s['section_key'] === ($d['section_key'] ?? '')) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['section_label']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sp-form-group" style="margin-bottom:0;">
            <label class="sp-label" for="doc_title">Internal Title <span style="color:var(--text-muted);font-weight:400;">(staff reference only)</span></label>
            <input type="text" id="doc_title" name="doc_title" class="sp-input" maxlength="255"
                   placeholder="e.g. Spotify Setup Section"
                   value="<?php echo htmlspecialchars($d['title'] ?? ''); ?>">
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
        <div class="sp-form-group" style="margin-bottom:0;">
            <label class="sp-label" for="doc_order">Display Order</label>
            <input type="number" id="doc_order" name="doc_order" class="sp-input"
                   min="1" max="9999" value="<?php echo (int)$d['doc_order']; ?>"
                   <?php if ($isNew): ?>data-auto="1"<?php endif; ?>>
            <span class="sp-field-hint">Lower numbers appear first within the section.</span>
        </div>
        <?php if ($isNew): ?>
        <script>
        (function () {
            var maxOrders = <?php echo json_encode($maxOrdersBySec); ?>;
            var secSelect  = document.getElementById('section_key');
            var orderInput = document.getElementById('doc_order');
            if (!secSelect || !orderInput) return;
            orderInput.addEventListener('input', function () {
                // User manually edited — stop auto-filling
                delete orderInput.dataset.auto;
            });
            secSelect.addEventListener('change', function () {
                if (!('auto' in orderInput.dataset)) return;
                var sec  = secSelect.value;
                var next = (maxOrders[sec] !== undefined) ? (maxOrders[sec] + 1) : 1;
                orderInput.value = next;
            });
        }());
        </script>
        <?php endif; ?>
        <div class="sp-form-group" style="margin-bottom:0;">
            <label class="sp-label">Visibility</label>
            <label class="sp-toggle-label">
                <input type="checkbox" name="is_visible" value="1"
                       <?php echo ($d['is_visible'] ? 'checked' : ''); ?>>
                <span class="sp-toggle-track"><span class="sp-toggle-thumb"></span></span>
                <span class="sp-toggle-text">Visible to the public</span>
            </label>
        </div>
    </div>
    <!-- HTML editor + live preview -->
    <div class="sp-editor-wrap">
        <div class="sp-editor-col">
            <div class="sp-editor-col-header">
                <span><i class="fa-solid fa-code"></i> HTML Content <span class="sp-req">*</span></span>
                <div style="display:flex;gap:0.5rem;">
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-h2">H2</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-h3">H3</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-p">P</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-ul">UL</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-code">Code</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-step">Step</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-table">Table</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-alert">Alert</button>
                    <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm" id="sp-insert-hr"><i class="fa-solid fa-minus"></i></button>
                </div>
            </div>
            <textarea id="doc_content" name="doc_content" class="sp-code-editor" spellcheck="false"
                      required placeholder="Enter HTML content here…"><?php echo htmlspecialchars($d['content'] ?? ''); ?></textarea>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:0.75rem;align-items:center;">
                <button type="submit" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> <?php echo $isNew ? 'Create Doc Block' : 'Save Changes'; ?>
                </button>
                <a href="/docs.php" class="sp-btn sp-btn-ghost">Cancel</a>
                <button type="button" class="sp-btn sp-btn-sm" id="sp-ai-open"
                        style="background:var(--accent);color:#fff;margin-left:auto;">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> AI Assistant
                </button>
            </div>
        </div>
        <div class="sp-preview-col">
            <div class="sp-editor-col-header">
                <span><i class="fa-solid fa-eye"></i> Live Preview</span>
                <span class="sp-badge sp-badge-muted" id="sp-char-count" style="font-size:0.75rem;"></span>
            </div>
            <div class="sp-preview-pane sp-doc-content" id="sp-preview"></div>
        </div>
    </div>
</form>
<!-- AI Assistant Modal -->
<div id="sp-ai-overlay" class="sp-ai-overlay" style="display:none;">
    <div class="sp-ai-modal">
        <div class="sp-ai-modal-header">
            <h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Content Assistant</h3>
            <button type="button" id="sp-ai-close" class="sp-btn sp-btn-ghost sp-btn-sm">&times;</button>
        </div>
        <div class="sp-ai-modal-body">
            <label class="sp-label" for="sp-ai-prompt">What would you like the AI to do?</label>
            <textarea id="sp-ai-prompt" class="sp-input" rows="4"
                      placeholder="e.g. &quot;Edit the first paragraph to mention broadcasters&quot; or &quot;Write a new section about Spotify integration&quot;"></textarea>
            <div class="sp-ai-options" style="margin-top:0.75rem;display:flex;gap:1rem;align-items:center;">
                <label style="font-size:0.85rem;display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;"
                       title="AI rewrites the entire block with your changes applied">
                    <input type="radio" name="sp_ai_mode" value="replace" checked>
                    Edit existing content
                </label>
                <label style="font-size:0.85rem;display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;"
                       title="AI generates new HTML and adds it after your current content">
                    <input type="radio" name="sp_ai_mode" value="append">
                    Add new content below
                </label>
            </div>
            <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.35rem;">
                <strong>Edit existing</strong> — AI reads your current content, applies your requested changes, and replaces it.<br>
                <strong>Add new below</strong> — AI generates new HTML and appends it after what you already have.
            </p>
            <div id="sp-ai-error" class="sp-alert sp-alert-danger" style="display:none;margin-top:0.75rem;">
                <i class="fa-solid fa-circle-xmark"></i>
                <span id="sp-ai-error-msg"></span>
            </div>
        </div>
        <div class="sp-ai-modal-footer">
            <button type="button" id="sp-ai-cancel" class="sp-btn sp-btn-ghost">Cancel</button>
            <button type="button" id="sp-ai-submit" class="sp-btn sp-btn-primary">
                <i class="fa-solid fa-paper-plane"></i> Generate
            </button>
        </div>
    </div>
</div>
<?php if (!$isNew && $editDoc): ?>
<form method="POST" action="/docs.php" style="margin-top:0.75rem;">
    <input type="hidden" name="_action"    value="toggle_vis">
    <input type="hidden" name="id"         value="<?php echo (int)$editDoc['id']; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="back"       value="docs">
    <button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm">
        <i class="fa-solid <?php echo $editDoc['is_visible'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
        <?php echo $editDoc['is_visible'] ? 'Hide from public' : 'Make visible'; ?>
    </button>
</form>
<?php
endif; 

// ================================================================
// VIEW: NEW / EDIT SECTION
// ================================================================
elseif ($action === 'new_section' || $action === 'edit_section'):
    $isNewSec = ($action === 'new_section');
    $s = $editSection ?? ['id'=>0,'section_key'=>'','section_label'=>'','section_icon'=>'fa-solid fa-file','section_order'=>$nextSecOrder];
    $heading = $isNewSec ? 'New Section' : 'Edit Section: ' . ($editSection['section_label'] ?? '');
?>
<div class="sp-page-header">
    <div>
        <a href="/docs.php" class="sp-back-link"><i class="fa-solid fa-arrow-left"></i> All Sections</a>
        <h1><?php echo htmlspecialchars($heading); ?></h1>
    </div>
    <?php if (!$isNewSec && $editSection): ?>
    <form method="POST" action="/docs.php"
          onsubmit="return confirm('Delete section &quot;<?php echo htmlspecialchars(addslashes($editSection['section_label'])); ?>&quot; and ALL its doc blocks? This cannot be undone.');">
        <input type="hidden" name="_action"    value="delete_section">
        <input type="hidden" name="section_id" value="<?php echo (int)$editSection['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm">
            <i class="fa-solid fa-trash"></i> Delete Section
        </button>
    </form>
    <?php endif; ?>
</div>
<div class="sp-card" style="max-width:640px;">
    <div class="sp-card-body">
        <form method="POST" action="/docs.php">
            <input type="hidden" name="_action"    value="save_section">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="edit_id"    value="<?php echo (int)$s['id']; ?>">
            <div class="sp-form-group">
                <label class="sp-label" for="section_key">
                    Section Key <span class="sp-req">*</span>
                    <span style="font-weight:400;color:var(--text-muted);">(URL slug — lowercase letters, numbers, hyphens only)</span>
                </label>
                <input type="text" id="section_key" name="section_key" class="sp-input"
                       pattern="[a-z0-9_-]+" maxlength="60" required
                       placeholder="e.g. setup, faq, integrations"
                       value="<?php echo htmlspecialchars($s['section_key']); ?>"
                       <?php echo (!$isNewSec ? 'readonly style="opacity:0.6;"' : ''); ?>>
                <?php if (!$isNewSec): ?>
                <span class="sp-field-hint">Key cannot be changed after creation (would break existing links).</span>
                <?php else: ?>
                <span class="sp-field-hint">Used as the tab ID. Use only lowercase letters, numbers, hyphens, underscores.</span>
                <?php endif; ?>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="section_label">Display Name <span class="sp-req">*</span></label>
                <input type="text" id="section_label" name="section_label" class="sp-input"
                       maxlength="100" required
                       placeholder="e.g. First Time Setup"
                       value="<?php echo htmlspecialchars($s['section_label']); ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="section_icon">Font Awesome Icon Class</label>
                <input type="text" id="section_icon" name="section_icon" class="sp-input"
                       placeholder="fa-solid fa-rocket"
                       value="<?php echo htmlspecialchars($s['section_icon']); ?>">
                <span class="sp-field-hint">
                    E.g. <code>fa-solid fa-rocket</code>, <code>fa-brands fa-twitch</code>.
                    Preview: <i id="sp-icon-preview" class="<?php echo htmlspecialchars($s['section_icon']); ?>" style="color:var(--accent);"></i>
                </span>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="section_order">Sort Order</label>
                <input type="number" id="section_order" name="section_order" class="sp-input"
                       min="1" max="9999" style="max-width:140px;"
                       value="<?php echo (int)$s['section_order']; ?>"
                       <?php if ($isNewSec): ?>data-auto="1"<?php endif; ?>>
                <span class="sp-field-hint">Lower numbers appear first. Sections with the same order are sorted alphabetically.</span>
            </div>
            <?php if ($isNewSec): ?>
            <script>
            (function () {
                var orderInput = document.getElementById('section_order');
                if (!orderInput) return;
                orderInput.addEventListener('input', function () {
                    delete orderInput.dataset.auto;
                });
            }());
            </script>
            <?php endif; ?>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:1rem;">
                <button type="submit" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> <?php echo $isNewSec ? 'Create Section' : 'Save Changes'; ?>
                </button>
                <a href="/docs.php" class="sp-btn sp-btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php

// ================================================================
// VIEW: SECTION LIST (default)
// ================================================================
else:
    // Load all docs so we can list them under each section
    $allDocsResult = $db->query('SELECT * FROM support_docs ORDER BY section_key ASC, doc_order ASC, id ASC');
    $allDocsList   = $allDocsResult ? $allDocsResult->fetch_all(MYSQLI_ASSOC) : [];
    $docsBySec = [];
    foreach ($allDocsList as $doc) { $docsBySec[$doc['section_key']][] = $doc; }
?>
<div class="sp-page-header">
    <div>
        <h1><i class="fa-solid fa-pen-to-square"></i> Documentation Manager</h1>
        <p style="color:var(--text-secondary);">
            <?php echo count($sections); ?> section<?php echo count($sections)!==1?'s':''; ?>
            &nbsp;·&nbsp;
            <?php echo count($allDocsList); ?> total doc block<?php echo count($allDocsList)!==1?'s':''; ?>
        </p>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <a href="/index.php" target="_blank" class="sp-btn sp-btn-ghost sp-btn-sm">
            <i class="fa-solid fa-eye"></i> View Docs Site
        </a>
        <a href="/docs.php?action=new_section" class="sp-btn sp-btn-secondary">
            <i class="fa-solid fa-folder-plus"></i> New Section
        </a>
        <a href="/docs.php?action=new" class="sp-btn sp-btn-primary">
            <i class="fa-solid fa-plus"></i> New Doc Block
        </a>
    </div>
</div>
<?php if (empty($sections)): ?>
<div class="sp-empty-state" style="padding:4rem 1rem;">
    <div class="sp-empty-icon"><i class="fa-solid fa-folder-open"></i></div>
    <h3>No sections yet</h3>
    <p>Create your first documentation section to get started.</p>
    <a href="/docs.php?action=new_section" class="sp-btn sp-btn-primary sp-mt-2">
        <i class="fa-solid fa-folder-plus"></i> Create First Section
    </a>
</div>
<?php else: ?>
<div class="sp-cms-sections">
<?php foreach ($sections as $sec):
    $secKey  = $sec['section_key'];
    $secDocs = $docsBySec[$secKey] ?? [];
    $total   = count($secDocs);
    $visible = count(array_filter($secDocs, fn($d) => $d['is_visible']));
?>
<div class="sp-cms-section-card" data-sec="<?php echo htmlspecialchars($secKey); ?>">
    <div class="sp-cms-section-head">
        <div class="sp-cms-section-title">
            <i class="<?php echo htmlspecialchars($sec['section_icon']); ?>"></i>
            <?php echo htmlspecialchars($sec['section_label']); ?>
            <code style="font-size:0.72rem;color:var(--text-muted);font-weight:400;"><?php echo htmlspecialchars($secKey); ?></code>
        </div>
        <div class="sp-cms-section-meta">
            <span class="sp-badge sp-badge-muted" title="Total blocks"><?php echo $total; ?> block<?php echo $total!==1?'s':''; ?></span>
            <span class="sp-badge <?php echo $visible > 0 ? 'sp-status-open' : 'sp-badge-muted'; ?>"
                  title="Visible blocks"><?php echo $visible; ?> visible</span>
            <span style="color:var(--text-muted);font-size:0.8rem;">order: <?php echo (int)$sec['section_order']; ?></span>
        </div>
        <div class="sp-cms-section-actions">
            <a href="/docs.php?action=new&section=<?php echo urlencode($secKey); ?>"
               class="sp-btn sp-btn-primary sp-btn-sm">
                <i class="fa-solid fa-plus"></i> Add Block
            </a>
            <a href="/docs.php?action=edit_section&key=<?php echo urlencode($secKey); ?>"
               class="sp-btn sp-btn-ghost sp-btn-sm">
                <i class="fa-solid fa-pen"></i> Edit Section
            </a>
            <a href="/index.php#<?php echo urlencode($secKey); ?>" target="_blank"
               class="sp-btn sp-btn-ghost sp-btn-sm" title="View on site">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
            <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-collapse-toggle" title="Collapse / expand">
                <i class="fa-solid fa-chevron-down"></i>
            </button>
        </div>
    </div>
    <div class="sp-cms-section-body">
    <?php if (empty($secDocs)): ?>
    <div class="sp-cms-empty-section">
        <i class="fa-solid fa-file-circle-plus"></i>
        No doc blocks yet.
        <a href="/docs.php?action=new&section=<?php echo urlencode($secKey); ?>">Add the first one</a>
    </div>
    <?php else: ?>
    <table class="sp-table sp-table-compact">
        <thead>
            <tr>
                <th style="width:36px;">#</th>
                <th>Internal Title / Preview</th>
                <th style="width:80px;">Order</th>
                <th style="width:80px;">Visible</th>
                <th style="width:90px;">Updated</th>
                <th style="width:120px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($secDocs as $doc): ?>
            <tr class="<?php echo (!$doc['is_visible'] ? 'sp-row-hidden' : ''); ?>">
                <td style="color:var(--text-muted);font-size:0.8rem;"><?php echo (int)$doc['id']; ?></td>
                <td>
                    <div class="sp-doc-list-title">
                        <?php if ($doc['title']): ?>
                            <strong><?php echo htmlspecialchars($doc['title']); ?></strong>
                        <?php else: ?>
                            <em style="color:var(--text-muted);">Untitled block</em>
                        <?php endif; ?>
                    </div>
                    <div class="sp-doc-list-preview">
                        <?php
                        // Strip tags, collapse whitespace, truncate to 120 chars
                        $preview = preg_replace('/\s+/', ' ', strip_tags($doc['content']));
                        echo htmlspecialchars(mb_strimwidth($preview, 0, 120, '…'));
                        ?>
                    </div>
                </td>
                <td><?php echo (int)$doc['doc_order']; ?></td>
                <td>
                    <?php if ($doc['is_visible']): ?>
                    <span class="sp-badge sp-status-open"><i class="fa-solid fa-eye"></i> Yes</span>
                    <?php else: ?>
                    <span class="sp-badge sp-badge-muted"><i class="fa-solid fa-eye-slash"></i> No</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.78rem;color:var(--text-muted);">
                    <?php echo $doc['updated_at'] ? date('d M Y', strtotime($doc['updated_at'])) : '—'; ?>
                </td>
                <td>
                    <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                        <a href="/docs.php?action=edit&id=<?php echo (int)$doc['id']; ?>"
                           class="sp-btn sp-btn-ghost sp-btn-sm" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <form method="POST" action="/docs.php" style="display:inline;">
                            <input type="hidden" name="_action"    value="toggle_vis">
                            <input type="hidden" name="id"         value="<?php echo (int)$doc['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="back"       value="docs">
                            <button type="submit" class="sp-btn sp-btn-ghost sp-btn-sm"
                                    title="<?php echo $doc['is_visible'] ? 'Hide' : 'Show'; ?>">
                                <i class="fa-solid <?php echo $doc['is_visible'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            </button>
                        </form>
                        <form method="POST" action="/docs.php" style="display:inline;"
                              onsubmit="return confirm('Delete this doc block permanently?');">
                            <input type="hidden" name="_action"    value="delete_doc">
                            <input type="hidden" name="id"         value="<?php echo (int)$doc['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="back"       value="docs">
                            <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div><!-- /.sp-cms-section-body -->
</div><!-- /.sp-cms-section-card -->
<?php endforeach; ?>
</div><!-- /.sp-cms-sections -->
<?php endif; // sections list ?>
<?php
endif; // end action router

$content = ob_get_clean();

// Page-specific JS: icon preview, live HTML preview, snippet inserts, char count
$extraScripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ---- Icon preview (section form) ---- */
    var iconInput   = document.getElementById('section_icon');
    var iconPreview = document.getElementById('sp-icon-preview');
    if (iconInput && iconPreview) {
        iconInput.addEventListener('input', function () {
            iconPreview.className = iconInput.value.trim();
        });
        // Auto-slug from label
        var labelInput = document.getElementById('section_label');
        var keyInput   = document.getElementById('section_key');
        if (labelInput && keyInput && keyInput.value === '') {
            labelInput.addEventListener('input', function () {
                if (!keyInput.readOnly) {
                    keyInput.value = labelInput.value
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, '');
                }
            });
        }
    }
    /* ---- Live preview ---- */
    var textarea = document.getElementById('doc_content');
    var preview  = document.getElementById('sp-preview');
    var counter  = document.getElementById('sp-char-count');
    function updatePreview() {
        if (!textarea || !preview) return;
        preview.innerHTML = textarea.value;
        if (counter) counter.textContent = textarea.value.length + ' chars';
    }
    if (textarea && preview) {
        textarea.addEventListener('input', updatePreview);
        updatePreview();
    }
    /* ---- Snippet inserts ---- */
    function insertSnippet(text) {
        if (!textarea) return;
        var start = textarea.selectionStart;
        var end   = textarea.selectionEnd;
        var before = textarea.value.substring(0, start);
        var after  = textarea.value.substring(end);
        textarea.value = before + text + after;
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
        textarea.focus();
        textarea.dispatchEvent(new Event('input'));
    }
    var snippets = {
        'sp-insert-h2':    '<h2>Heading</h2>\n',
        'sp-insert-h3':    '<h3>Sub-heading</h3>\n',
        'sp-insert-p':     '<p>Paragraph text goes here.</p>\n',
        'sp-insert-ul':    '<ul>\n    <li>Item one</li>\n    <li>Item two</li>\n</ul>\n',
        'sp-insert-code':  '<code>your-code-here</code>',
        'sp-insert-hr':    '<hr class="sp-divider">\n',
        'sp-insert-table': '<table class="sp-var-table">\n    <thead><tr><th>Column 1</th><th>Column 2</th></tr></thead>\n    <tbody>\n        <tr><td>Value</td><td>Description</td></tr>\n    </tbody>\n</table>\n',
        'sp-insert-alert': '<div class="sp-alert sp-alert-info">\n    <i class="fa-solid fa-circle-info"></i>\n    <span>Your message here.</span>\n</div>\n',
        'sp-insert-step':  '<div class="sp-step">\n    <div class="sp-step-num">1</div>\n    <div class="sp-step-body">\n        <h4>Step Title</h4>\n        <p>Step description.</p>\n    </div>\n</div>\n',
    };
    Object.keys(snippets).forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', function () { insertSnippet(snippets[id]); });
    });
    /* ---- AI Assistant ---- */
    (function () {
        var aiBtn     = document.getElementById('sp-ai-open');
        var overlay   = document.getElementById('sp-ai-overlay');
        var closeBtn  = document.getElementById('sp-ai-close');
        var cancelBtn = document.getElementById('sp-ai-cancel');
        var submitBtn = document.getElementById('sp-ai-submit');
        var promptEl  = document.getElementById('sp-ai-prompt');
        var errorWrap = document.getElementById('sp-ai-error');
        var errorMsg  = document.getElementById('sp-ai-error-msg');
        if (!aiBtn || !overlay) return;
        var csrfToken = document.querySelector('input[name="csrf_token"]');
        csrfToken = csrfToken ? csrfToken.value : '';
        function openModal() {
            overlay.style.display = 'flex';
            promptEl.value = '';
            errorWrap.style.display = 'none';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Generate';
            setTimeout(function () { promptEl.focus(); }, 100);
        }
        function closeModal() {
            overlay.style.display = 'none';
        }
        aiBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.style.display === 'flex') closeModal();
        });
        // Submit prompt to AI
        submitBtn.addEventListener('click', function () {
            var prompt = (promptEl.value || '').trim();
            if (!prompt) {
                errorMsg.textContent = 'Please enter a prompt.';
                errorWrap.style.display = 'flex';
                return;
            }
            var secSelect = document.getElementById('section_key');
            var editIdInput = document.querySelector('input[name="edit_id"]');
            var mode = document.querySelector('input[name="sp_ai_mode"]:checked');
            mode = mode ? mode.value : 'replace';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
            errorWrap.style.display = 'none';
            fetch('/api/ai_docs.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    prompt:       prompt,
                    current_html: textarea ? textarea.value : '',
                    section_key:  secSelect ? secSelect.value : '',
                    doc_id:       editIdInput ? parseInt(editIdInput.value, 10) : 0
                })
            })
            .then(function (res) { return res.text(); })
            .then(function (rawText) {
                var data;
                try {
                    data = JSON.parse(rawText);
                } catch (e) {
                    errorMsg.textContent = 'Invalid response from server.';
                    errorWrap.style.display = 'flex';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Generate';
                    return;
                }
                if (!data.ok) {
                    errorMsg.textContent = data.error || 'Unknown error.';
                    errorWrap.style.display = 'flex';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Generate';
                    return;
                }
                if (textarea) {
                    if (mode === 'append') {
                        textarea.value = textarea.value + (textarea.value ? '\n' : '') + data.html;
                    } else {
                        textarea.value = data.html;
                    }
                    // Force preview + char count update
                    updatePreview();
                    // Scroll textarea to top so change is visible
                    textarea.scrollTop = 0;
                    // Flash the textarea border to signal success
                    textarea.style.outline = '2px solid var(--accent)';
                    setTimeout(function () { textarea.style.outline = ''; }, 1500);
                }
                closeModal();
                // Show a success toast
                var toast = document.createElement('div');
                toast.className = 'sp-alert sp-alert-success';
                toast.setAttribute('data-dismiss', '4000');
                var modeLabel = mode === 'append' ? 'added below existing content' : 'existing content updated';
                toast.innerHTML = '<i class="fa-solid fa-circle-check"></i><span>AI content applied — ' + modeLabel + '. Review the changes before saving.</span>';
                var form = document.getElementById('sp-doc-form');
                if (form) form.parentNode.insertBefore(toast, form);
                setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 4000);
            })
            .catch(function (err) {
                errorMsg.textContent = 'Network error: ' + err.message;
                errorWrap.style.display = 'flex';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Generate';
            });
        });
        // Allow Ctrl+Enter to submit
        promptEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                submitBtn.click();
            }
        });
    }());
    /* ---- Collapsible section cards ---- */
    var STORAGE_KEY = 'sp_cms_collapsed';
    function getCollapsed() {
        try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { return []; }
    }
    function setCollapsed(list) {
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch (e) {}
    }
    document.querySelectorAll('.sp-cms-section-card[data-sec]').forEach(function (card) {
        var key = card.dataset.sec;
        // Restore from session
        if (getCollapsed().indexOf(key) !== -1) {
            card.classList.add('collapsed');
        }
        card.querySelector('.sp-cms-section-head').addEventListener('click', function (e) {
            // Don't toggle when clicking links or buttons inside (except the chevron toggle)
            if (e.target.closest('a, button:not(.sp-collapse-toggle), form')) return;
            card.classList.toggle('collapsed');
            var collapsed = getCollapsed();
            if (card.classList.contains('collapsed')) {
                if (collapsed.indexOf(key) === -1) collapsed.push(key);
            } else {
                collapsed = collapsed.filter(function (k) { return k !== key; });
            }
            setCollapsed(collapsed);
        });
    });
});
</script>
JS;

include __DIR__ . '/layout.php';
?>