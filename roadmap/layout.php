<?php
// roadmap/layout.php
date_default_timezone_set('Australia/Sydney');

if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        return bin2hex(random_bytes(4));
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

if (!isset($pageTitle))   $pageTitle   = 'Roadmap';
if (!isset($topbarTitle)) $topbarTitle = $pageTitle;

$isLoggedIn  = !empty($_SESSION['username']);
$isAdmin     = !empty($_SESSION['admin']);
$displayName = htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? '', ENT_QUOTES);
$v = uuidv4();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <title><?php echo htmlspecialchars($pageTitle); ?> — BotOfTheSpecter Roadmap</title>
    <meta name="description" content="BotOfTheSpecter development roadmap.">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?> — BotOfTheSpecter Roadmap">
    <meta property="og:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <link rel="stylesheet" href="/css/style.css?v=<?php echo $v; ?>">
</head>
<body>
<div id="sp-sidebar-overlay" class="sp-sidebar-overlay"></div>
<div class="sp-layout">
    <aside id="sp-sidebar" class="sp-sidebar">
        <div class="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter">
            <div class="sp-brand-text">
                <span class="sp-brand-title">BotOfTheSpecter</span>
                <span class="sp-brand-sub">Roadmap</span>
            </div>
        </div>
        <nav class="sp-nav">
            <div class="sp-nav-section">
                <div class="sp-nav-label">Navigation</div>
                <a href="/index.php" class="sp-nav-link"><i class="fa-solid fa-map"></i> Roadmap</a>
                <a href="/timeline.php" class="sp-nav-link"><i class="fa-solid fa-timeline"></i> Timeline</a>
                <?php if ($isAdmin): ?>
                <a href="/admin/" class="sp-nav-link">
                    <i class="fa-solid fa-screwdriver-wrench"></i> Admin Panel
                    <span class="sp-badge sp-badge-accent" style="margin-left:auto;font-size:0.6rem;">Admin</span>
                </a>
                <?php endif; ?>
            </div>
            <div class="sp-nav-section">
                <div class="sp-nav-label">Resources</div>
                <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-gauge"></i> Dashboard <i class="fa-solid fa-arrow-up-right-from-square sp-link-ext"></i>
                </a>
                <a href="https://support.botofthespecter.com/" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-circle-question"></i> Support <i class="fa-solid fa-arrow-up-right-from-square sp-link-ext"></i>
                </a>
                <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-brands fa-github"></i> GitHub <i class="fa-solid fa-arrow-up-right-from-square sp-link-ext"></i>
                </a>
            </div>
        </nav>
        <div class="sp-sidebar-footer">
            <?php if ($isLoggedIn): ?>
                <div class="sp-user-block">
                    <div class="sp-user-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                    <div style="min-width:0;">
                        <div class="sp-user-name"><?php echo $displayName; ?></div>
                        <div class="sp-user-role"><?php echo $isAdmin ? 'Admin' : 'Viewer'; ?></div>
                    </div>
                </div>
                <a href="/logout.php" class="sp-nav-link sp-text-small"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
            <?php else: ?>
                <a href="/login.php" class="sp-btn sp-btn-primary" style="width:100%;justify-content:center;">
                    <i class="fa-brands fa-twitch"></i> Log In
                </a>
            <?php endif; ?>
        </div>
    </aside>
    <div class="sp-main">
        <header class="sp-topbar">
            <button id="sp-hamburger" class="sp-hamburger" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <span class="sp-topbar-title"><?php echo htmlspecialchars($topbarTitle); ?></span>
            <div class="sp-topbar-actions">
                <?php if (!$isLoggedIn): ?>
                    <a href="/login.php" class="sp-btn sp-btn-secondary sp-btn-sm">
                        <i class="fa-brands fa-twitch"></i> Log In
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <main class="sp-content sp-content-wide">
            <?php echo $pageContent; ?>
        </main>
        <footer class="sp-footer">
            &copy; 2023&ndash;<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            BotOfTheSpecter is operated under the business name &quot;YourStreamingTools&quot;, registered in Australia (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).<br>
            Not affiliated with Twitch Interactive, Inc., Discord Inc., or any other platform.
        </footer>
    </div>
</div>

<!-- Details Modal -->
<div class="rm-modal" id="detailsModal">
    <div class="rm-modal-backdrop"></div>
    <div class="rm-modal-card rm-modal-card-xlg" style="height:88vh;">
        <div class="rm-modal-head">
            <span class="rm-modal-title" id="detailsTitle">Item Details</span>
            <button class="rm-modal-close" id="closeDetailsBtn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-tabs">
            <button class="rm-modal-tab active" data-details-tab="description"><i class="fa-solid fa-align-left"></i> Description</button>
            <button class="rm-modal-tab" data-details-tab="attachments"><i class="fa-solid fa-paperclip"></i> Attachments</button>
            <button class="rm-modal-tab" data-details-tab="activity"><i class="fa-solid fa-comments"></i> Activity</button>
        </div>
        <div class="rm-modal-body" style="padding-top:0;">
            <div id="detailsPanelDescription" class="rm-modal-panel active" style="padding-top:1rem;">
                <div id="detailsTags" style="display:flex;flex-wrap:wrap;gap:0.3rem;margin-bottom:0.6rem;"></div>
                <p id="detailsMeta" style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.6rem;"></p>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="copyShareLinkBtn" style="margin-bottom:0.75rem;">
                    <i class="fa-solid fa-link"></i> Copy Share Link
                </button>
                <div id="detailsContent" class="rm-doc-content" style="line-height:1.7;"></div>
            </div>
            <div id="detailsPanelAttachments" class="rm-modal-panel" style="padding-top:1rem;display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                    <h4 style="font-size:0.9rem;font-weight:600;">Attachments</h4>
                    <?php if ($isAdmin): ?>
                        <button class="sp-btn sp-btn-success sp-btn-sm" id="addAttachmentTrigger"><i class="fa-solid fa-plus"></i> Add</button>
                    <?php endif; ?>
                </div>
                <div id="attachmentsSection"></div>
            </div>
            <div id="detailsPanelActivity" class="rm-modal-panel" style="padding-top:1rem;display:none;flex-direction:column;height:100%;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                    <h4 style="font-size:0.9rem;font-weight:600;">Activity</h4>
                    <?php if ($isAdmin): ?>
                        <button class="sp-btn sp-btn-primary sp-btn-sm" id="addCommentTrigger"><i class="fa-solid fa-comment"></i> Comment</button>
                    <?php endif; ?>
                </div>
                <div id="commentsSection" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:0.5rem;"></div>
            </div>
        </div>
        <div class="rm-modal-foot">
            <button class="sp-btn sp-btn-secondary" id="closeDetailsFootBtn">Close</button>
        </div>
    </div>
</div>

<!-- Image Zoom Modal -->
<div class="rm-modal" id="imageZoomModal">
    <div class="rm-modal-backdrop" id="imageZoomBackdrop"></div>
    <div class="rm-modal-card rm-modal-card-xlg" style="max-height:95vh;background:var(--bg-base);">
        <div class="rm-modal-head">
            <span class="rm-modal-title" id="zoomImageName">Image</span>
            <button class="rm-modal-close" id="closeImageZoomBtn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-body" style="display:flex;align-items:center;justify-content:center;background:var(--bg-base);padding:2rem;">
            <img id="zoomImageContent" src="" alt="" style="max-width:100%;max-height:80vh;object-fit:contain;">
        </div>
    </div>
</div>

<!-- Version Modal -->
<div class="rm-modal" id="versionModal">
    <div class="rm-modal-backdrop" onclick="closeVersionModal()"></div>
    <div class="rm-modal-card rm-modal-card-lg" style="max-height:88vh;">
        <div class="rm-modal-head">
            <span class="rm-modal-title">Version <span id="modalVersionNumber"></span> — Changelog</span>
            <button class="rm-modal-close" onclick="closeVersionModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-body rm-doc-content" id="modalContent"></div>
        <div class="rm-modal-foot">
            <button class="sp-btn sp-btn-secondary" onclick="closeVersionModal()">Close</button>
        </div>
    </div>
</div>

<!-- Legend Modal -->
<div class="rm-modal" id="legendModal">
    <div class="rm-modal-backdrop" id="legendModalBackdrop"></div>
    <div class="rm-modal-card rm-modal-card-sm">
        <div class="rm-modal-head">
            <span class="rm-modal-title"><i class="fa-solid fa-circle-info" style="color:var(--accent-hover);margin-right:0.4rem;"></i>Legend</span>
            <button class="rm-modal-close" id="closeLegendModal" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-body">
            <div class="rm-legend-grid">
                <div class="rm-legend-group">
                    <h4>Priority Levels</h4>
                    <div class="rm-legend-items">
                        <span class="rm-tag rm-tag-success">Low</span>
                        <span class="rm-tag rm-tag-info">Medium</span>
                        <span class="rm-tag rm-tag-warning">High</span>
                        <span class="rm-tag rm-tag-danger">Critical</span>
                    </div>
                </div>
                <div class="rm-legend-group">
                    <h4>Subcategories</h4>
                    <div class="rm-legend-items">
                        <span class="rm-tag rm-tag-primary">Twitch Bot</span>
                        <span class="rm-tag rm-tag-info">Discord Bot</span>
                        <span class="rm-tag rm-tag-success">WebSocket Server</span>
                        <span class="rm-tag rm-tag-warning">API Server</span>
                        <span class="rm-tag rm-tag-danger">Website</span>
                        <span class="rm-tag rm-tag-light">Other</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="rm-modal-foot">
            <button class="sp-btn sp-btn-secondary" id="closeLegendBtn">Close</button>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Add Comment Modal -->
<div class="rm-modal" id="addCommentModal">
    <div class="rm-modal-backdrop" id="commentModalBackdrop"></div>
    <div class="rm-modal-card rm-modal-card-sm">
        <div class="rm-modal-head">
            <span class="rm-modal-title"><i class="fa-solid fa-comment" style="color:var(--accent-hover);margin-right:0.4rem;"></i>Add Comment</span>
            <button class="rm-modal-close" id="cancelCommentClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-body">
            <form id="addCommentForm" method="POST">
                <input type="hidden" name="action" value="add_comment">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="item_id" id="commentItemId" value="">
                <div class="sp-form-group">
                    <label class="sp-label">Comment</label>
                    <textarea class="sp-textarea" name="comment_text" id="commentTextarea" placeholder="Enter your comment..." required rows="5"></textarea>
                </div>
            </form>
        </div>
        <div class="rm-modal-foot">
            <button type="button" class="sp-btn sp-btn-ghost" id="cancelCommentBtn">Cancel</button>
            <button type="submit" form="addCommentForm" class="sp-btn sp-btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit</button>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="rm-modal" id="editItemModal">
    <div class="rm-modal-backdrop" id="editModalBackdrop"></div>
    <div class="rm-modal-card rm-modal-card-lg">
        <div class="rm-modal-head">
            <span class="rm-modal-title"><i class="fa-solid fa-pen" style="color:var(--accent-hover);margin-right:0.4rem;"></i>Edit Roadmap Item</span>
            <button class="rm-modal-close" id="cancelEditClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-body">
            <form id="editItemForm" method="POST">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="id" id="editItemId" value="">
                <div class="sp-form-group">
                    <label class="sp-label">Title</label>
                    <input class="sp-input" type="text" name="title" id="editItemTitle" required>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label">Description</label>
                    <textarea class="sp-textarea sp-textarea-mono" name="description" id="editItemDescription" placeholder="Supports markdown..." rows="6"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="sp-form-group">
                        <label class="sp-label">Category</label>
                        <select class="sp-select" name="category" id="editItemCategory">
                            <option value="REQUESTS">REQUESTS</option>
                            <option value="IN PROGRESS">IN PROGRESS</option>
                            <option value="BETA TESTING">BETA TESTING</option>
                            <option value="COMPLETED">COMPLETED</option>
                            <option value="REJECTED">REJECTED</option>
                        </select>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Priority</label>
                        <select class="sp-select" name="priority" id="editItemPriority">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM">Medium</option>
                            <option value="HIGH">High</option>
                            <option value="CRITICAL">Critical</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="sp-form-group">
                        <label class="sp-label">Subcategory</label>
                        <div class="tag-multiselect" id="editItemSubcategory" data-name="subcategory[]"></div>
                        <div class="sp-field-hint">Only predefined tags allowed.</div>
                    </div>
                    <div class="sp-form-group" id="edit-website-type-field" style="display:none;">
                        <label class="sp-label">Website Type</label>
                        <div class="tag-multiselect" id="editItemWebsiteType" data-name="website_type[]" data-allowed='["DASHBOARD","OVERLAYS"]'></div>
                    </div>
                </div>
            </form>
        </div>
        <div class="rm-modal-foot">
            <button type="button" class="sp-btn sp-btn-ghost" id="cancelEditBtn">Cancel</button>
            <button type="submit" form="editItemForm" class="sp-btn sp-btn-warning"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- Upload Attachment Modal -->
<div class="rm-modal" id="uploadAttachmentModal">
    <div class="rm-modal-backdrop" id="uploadModalBackdrop"></div>
    <div class="rm-modal-card rm-modal-card-sm">
        <div class="rm-modal-head">
            <span class="rm-modal-title"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--accent-hover);margin-right:0.4rem;"></i>Upload Attachment</span>
            <button class="rm-modal-close" id="cancelUploadClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-modal-body">
            <form id="uploadAttachmentForm" enctype="multipart/form-data">
                <input type="hidden" name="item_id" id="uploadItemId" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="sp-form-group">
                    <label class="sp-label">Select File</label>
                    <label class="rm-upload-zone" id="uploadDropZone">
                        <input type="file" name="file" id="attachmentFileInput" required
                            accept="image/*,.pdf,.doc,.docx,.txt,.xls,.xlsx"
                            style="position:absolute;opacity:0;width:0;height:0;">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span class="rm-upload-label">Click to choose or drag &amp; drop</span>
                        <span class="rm-upload-hint">Images, PDF, Word, Excel, TXT — max 10MB</span>
                        <span class="rm-upload-filename" id="attachmentFileName"></span>
                    </label>
                </div>
                <div id="uploadProgress" style="display:none;" class="rm-progress-wrap">
                    <div class="rm-progress"><div class="rm-progress-bar" id="uploadProgressBar"></div></div>
                    <div class="rm-progress-text" id="uploadStatusText">Uploading…</div>
                </div>
                <div id="uploadError" style="display:none;">
                    <div class="sp-alert sp-alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <span id="uploadErrorMessage"></span></div>
                </div>
            </form>
        </div>
        <div class="rm-modal-foot">
            <button type="button" class="sp-btn sp-btn-ghost" id="cancelUploadBtn">Cancel</button>
            <button type="submit" form="uploadAttachmentForm" class="sp-btn sp-btn-success" id="uploadAttachmentBtn"><i class="fa-solid fa-upload"></i> Upload</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@2.4.0/dist/purify.min.js"></script>
<script src="/js/app.js?v=<?php echo $v; ?>" defer></script>
<script>
// ---- Utilities ----
function linkifyText(text) {
    return text.replace(/(https?:\/\/[^\s]+)/g, function(url) {
        return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color:var(--accent-hover);text-decoration:underline;">' + url + '</a>';
    });
}
function formatDateSydney(dateString) {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-AU', { year:'numeric', month:'short', day:'numeric', timeZone:'Australia/Sydney' });
}
function escapeHtml(value) {
    return String(value||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function parseTagList(rawValue) {
    if (!rawValue) return [];
    if (Array.isArray(rawValue)) return rawValue.filter(Boolean).map(v=>String(v).trim()).filter(Boolean);
    const text = String(rawValue).trim();
    if (!text) return [];
    try {
        const parsed = JSON.parse(text);
        if (Array.isArray(parsed)) return parsed.filter(Boolean).map(v=>String(v).trim()).filter(Boolean);
        if (typeof parsed==='string') return parsed.trim()?[parsed.trim()]:[];
    } catch(err) { return text?[text]:[]; }
    return [];
}
function getCategoryTagClass(category) {
    const map = {'REQUESTS':'rm-tag-info','IN PROGRESS':'rm-tag-warning','BETA TESTING':'rm-tag-primary','COMPLETED':'rm-tag-success','REJECTED':'rm-tag-danger'};
    return map[category]||'rm-tag-light';
}
function getPriorityTagClass(priority) {
    const map = {'LOW':'rm-tag-success','MEDIUM':'rm-tag-info','HIGH':'rm-tag-warning','CRITICAL':'rm-tag-danger'};
    return map[priority]||'rm-tag-light';
}
function getSubcategoryTagClass(subcategory) {
    const map = {'TWITCH BOT':'rm-tag-primary','DISCORD BOT':'rm-tag-info','WEBSOCKET SERVER':'rm-tag-success','API SERVER':'rm-tag-warning','WEBSITE':'rm-tag-danger','OTHER':'rm-tag-light'};
    return map[subcategory]||'rm-tag-light';
}
function getFileIcon(mimeType) {
    if (mimeType.startsWith('image/')) return 'fa-image';
    if (mimeType==='application/pdf') return 'fa-file-pdf';
    if (mimeType.includes('word')||mimeType.includes('document')) return 'fa-file-word';
    if (mimeType.includes('sheet')||mimeType.includes('excel')) return 'fa-file-excel';
    if (mimeType==='text/plain') return 'fa-file-lines';
    return 'fa-file';
}
function isImage(mimeType) { return mimeType.startsWith('image/'); }
function renderDetailsTags(button) {
    const tagsEl = document.getElementById('detailsTags');
    if (!tagsEl) return;
    const category   = (button.dataset.category||'').trim();
    const priority   = (button.dataset.priority||'').trim();
    const subcats    = parseTagList(button.dataset.subcategories||button.dataset.subcategory||'');
    const websiteTypes = parseTagList(button.dataset.websiteTypes||button.dataset.websiteType||'');
    const html = [];
    if (category) html.push('<span class="rm-tag '+getCategoryTagClass(category)+'">'+escapeHtml(category)+'</span>');
    if (priority) html.push('<span class="rm-tag '+getPriorityTagClass(priority)+'">'+escapeHtml(priority)+'</span>');
    subcats.forEach(sub=>html.push('<span class="rm-tag '+getSubcategoryTagClass(sub)+'">'+escapeHtml(sub)+'</span>'));
    websiteTypes.forEach(wt=>html.push('<span class="rm-tag rm-tag-info">'+escapeHtml(wt)+'</span>'));
    tagsEl.innerHTML = html.join('');
    tagsEl.style.display = html.length?'flex':'none';
}
function loadAttachments(itemId) {
    const sec = document.getElementById('attachmentsSection');
    if (!sec) return;
    fetch('../admin/get-attachments.php?item_id='+encodeURIComponent(itemId))
        .then(r=>r.json()).then(data=>{
            if (data.success && data.attachments.length>0) {
                let html='';
                data.attachments.forEach(att=>{
                    const fileIcon=getFileIcon(att.file_type), isImg=isImage(att.file_type);
                    const delBtn = att.can_delete
                        ? `<button class="sp-btn sp-btn-danger sp-btn-xs sp-btn-icon delete-attachment-btn" data-attachment-id="${att.id}" data-item-id="${itemId}" title="Delete"><i class="fa-solid fa-trash-can"></i></button>`
                        : '';
                    if (isImg) {
                        html+=`<div class="rm-attachment"><div class="rm-attachment-body"><div class="rm-attachment-meta">${escapeHtml(att.file_name)} &middot; ${escapeHtml(att.file_size_formatted)} &middot; ${escapeHtml(att.uploaded_by)} &middot; ${formatDateSydney(att.created_at)}</div><img src="${att.file_path}" alt="${escapeHtml(att.file_name)}" class="rm-attachment-img zoom-image" data-filename="${escapeHtml(att.file_name)}"></div>${delBtn}</div>`;
                    } else {
                        html+=`<div class="rm-attachment"><div class="rm-attachment-body"><div class="rm-attachment-meta">${escapeHtml(att.file_size_formatted)} &middot; ${escapeHtml(att.uploaded_by)} &middot; ${formatDateSydney(att.created_at)}</div><a href="${att.file_path}" download class="rm-attachment-name"><i class="fa-solid ${fileIcon}"></i> ${escapeHtml(att.file_name)}</a></div>${delBtn}</div>`;
                    }
                });
                sec.innerHTML=html;
                sec.querySelectorAll('.delete-attachment-btn').forEach(btn=>{
                    btn.addEventListener('click',function(){
                        if(!confirm('Delete this attachment?')) return;
                        const fd=new FormData();
                        fd.append('attachment_id',this.dataset.attachmentId);
                        const csrfEl=document.querySelector('meta[name="csrf-token"]');
                        fd.append('csrf_token',csrfEl?csrfEl.getAttribute('content'):'');
                        fetch('../admin/delete-attachment.php',{method:'POST',body:fd})
                            .then(r=>r.json()).then(d=>{ if(d.success) loadAttachments(itemId); else alert('Error: '+d.message); })
                            .catch(()=>alert('Network error'));
                    });
                });
                const zoomModal=document.getElementById('imageZoomModal'),zoomImg=document.getElementById('zoomImageContent'),zoomName=document.getElementById('zoomImageName');
                sec.querySelectorAll('.zoom-image').forEach(img=>{
                    img.addEventListener('click',function(){
                        zoomImg.src=this.src; zoomName.textContent=this.dataset.filename||'';
                        if(zoomModal) zoomModal.classList.add('open');
                    });
                });
            } else { sec.innerHTML='<p style="color:var(--text-muted);font-size:0.875rem;font-style:italic;">No attachments</p>'; }
        }).catch(()=>{ sec.innerHTML='<p style="color:var(--red);font-size:0.875rem;">Error loading attachments</p>'; });
}

document.addEventListener('DOMContentLoaded', function() {
    // CSRF injection
    try {
        const csrfEl=document.querySelector('meta[name="csrf-token"]');
        const csrfVal=csrfEl?csrfEl.getAttribute('content'):'';
        if (csrfVal) {
            document.querySelectorAll('form[method="POST"]').forEach(f=>{
                if (!f.querySelector('input[name="csrf_token"]')) {
                    const inp=document.createElement('input');inp.type='hidden';inp.name='csrf_token';inp.value=csrfVal;f.prepend(inp);
                }
            });
        }
    } catch(e) {}

    // Tag multiselect (TMS)
    const TMS_ALLOWED=['TWITCH BOT','DISCORD BOT','WEBSOCKET SERVER','API SERVER','WEBSITE','OTHER'];
    function createTagMultiSelect(container) {
        if (!container) return null;
        let allowedList=TMS_ALLOWED;
        if (container.dataset.allowed) {
            try { const p=JSON.parse(container.dataset.allowed); if(Array.isArray(p)) allowedList=p.map(x=>String(x).trim()); }
            catch(e) { allowedList=String(container.dataset.allowed).split(',').map(s=>s.trim()).filter(Boolean); }
        }
        const name=container.dataset.name||'subcategory[]';
        container.classList.add('tms-container');
        const chipsEl=document.createElement('div'); chipsEl.className='tms-chips';
        const inputEl=document.createElement('input'); inputEl.type='text'; inputEl.className='tms-input'; inputEl.placeholder='Select\u2026'; inputEl.autocomplete='off';
        const suggEl=document.createElement('div'); suggEl.className='tms-suggestions'; suggEl.style.display='none';
        const hidWrap=document.createElement('div'); hidWrap.style.display='none';
        container.append(chipsEl,inputEl,suggEl,hidWrap);
        let selected=[];
        function render() {
            chipsEl.innerHTML=''; hidWrap.innerHTML='';
            selected.forEach(val=>{
                const chip=document.createElement('span'); chip.className='tag'; chip.textContent=val;
                const del=document.createElement('button'); del.type='button'; del.className='delete is-small'; del.style.marginLeft='0.4rem';
                del.addEventListener('click',()=>remove(val)); chip.appendChild(del); chipsEl.appendChild(chip);
                const hid=document.createElement('input'); hid.type='hidden'; hid.name=name; hid.value=val; hidWrap.appendChild(hid);
            });
        }
        function dispatchChange() { container.dispatchEvent(new CustomEvent('tms:change',{detail:{values:selected.slice()}})); }
        function add(val) { val=String(val).trim(); if(!allowedList.includes(val)||selected.includes(val)) return; selected.push(val); render(); dispatchChange(); }
        function remove(val) { selected=selected.filter(x=>x!==val); render(); dispatchChange(); }
        function setValues(arr) { selected=[]; (arr||[]).forEach(v=>{const s=String(v).trim(); if(allowedList.includes(s)&&!selected.includes(s)) selected.push(s);}); render(); dispatchChange(); }
        function getValues() { return selected.slice(); }
        function showSuggestions(q) {
            const ql=(q||'').toLowerCase();
            const list=allowedList.filter(x=>!selected.includes(x)&&x.toLowerCase().includes(ql));
            suggEl.innerHTML='';
            if (!list.length) { suggEl.style.display='none'; return; }
            list.slice(0,12).forEach(x=>{ const it=document.createElement('div'); it.className='tms-suggestion'; it.textContent=x; it.addEventListener('click',()=>{add(x);inputEl.value='';hideSuggestions();}); suggEl.appendChild(it); });
            suggEl.style.display='block';
        }
        function hideSuggestions() { suggEl.style.display='none'; }
        inputEl.addEventListener('input',e=>showSuggestions(e.target.value));
        inputEl.addEventListener('focus',()=>showSuggestions(inputEl.value));
        inputEl.addEventListener('keydown',e=>{
            if (e.key==='Enter') { e.preventDefault(); const m=allowedList.find(x=>x.toUpperCase()===inputEl.value.trim().toUpperCase()); if(m) add(m); inputEl.value=''; hideSuggestions(); return; }
            if (e.key==='Backspace'&&inputEl.value==='') { if(selected.length) remove(selected[selected.length-1]); }
        });
        document.addEventListener('click',ev=>{ if(!container.contains(ev.target)) hideSuggestions(); });
        showSuggestions('');
        if (container.dataset.initial) {
            try { const arr=JSON.parse(container.dataset.initial); setValues(Array.isArray(arr)?arr:[arr]); } catch(err) { setValues([container.dataset.initial]); }
        }
        container._tms={setValues,getValues,add,remove};
        return container._tms;
    }
    document.querySelectorAll('.tag-multiselect').forEach(el=>createTagMultiSelect(el));

    const editTagEl=document.getElementById('editItemSubcategory');
    const editWebWrapper=document.getElementById('edit-website-type-field');
    if (editTagEl&&editWebWrapper) {
        editTagEl.addEventListener('tms:change',e=>{ editWebWrapper.style.display=(e.detail.values||[]).includes('WEBSITE')?'':'none'; });
    }

    // Modal helpers
    function openModal(el) { if(el) el.classList.add('open'); }
    function closeModal(el) { if(el) el.classList.remove('open'); }

    // Details modal
    const detailsModal=document.getElementById('detailsModal');
    const detailsBtns=document.querySelectorAll('.details-btn');
    const detailsTabBtns=document.querySelectorAll('[data-details-tab]');
    const panels={description:document.getElementById('detailsPanelDescription'),attachments:document.getElementById('detailsPanelAttachments'),activity:document.getElementById('detailsPanelActivity')};
    let currentItemId=null;
    function clearItemParam(){ const u=new URL(window.location.href); if(!u.searchParams.has('item')) return; u.searchParams.delete('item'); window.history.replaceState({},'',u.toString()); }
    function closeDetailsModal(){ closeModal(detailsModal); currentItemId=null; clearItemParam(); }
    function setDetailsTab(name) {
        detailsTabBtns.forEach(t=>t.classList.toggle('active',t.dataset.detailsTab===name));
        Object.entries(panels).forEach(([k,p])=>{ if(!p) return; p.classList.toggle('active',k===name); p.style.display=(k===name)?'':'none'; });
    }
    detailsTabBtns.forEach(tab=>tab.addEventListener('click',e=>{ e.preventDefault(); setDetailsTab(tab.dataset.detailsTab); }));
    document.getElementById('closeDetailsBtn')&&document.getElementById('closeDetailsBtn').addEventListener('click',closeDetailsModal);
    document.getElementById('closeDetailsFootBtn')&&document.getElementById('closeDetailsFootBtn').addEventListener('click',closeDetailsModal);
    detailsModal&&detailsModal.querySelector('.rm-modal-backdrop')&&detailsModal.querySelector('.rm-modal-backdrop').addEventListener('click',closeDetailsModal);

    // Copy share link
    const copyShareLinkBtn=document.getElementById('copyShareLinkBtn');
    if (copyShareLinkBtn) {
        copyShareLinkBtn.addEventListener('click',async e=>{
            e.preventDefault(); if(!currentItemId) return;
            const u=new URL(window.location.href); u.searchParams.delete('search'); u.searchParams.delete('category'); u.searchParams.set('item',String(currentItemId));
            const url=u.toString();
            try { if(navigator.clipboard&&window.isSecureContext) await navigator.clipboard.writeText(url); else { const t=document.createElement('textarea');t.value=url;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t); }
                const lbl=copyShareLinkBtn; const orig=lbl.textContent; lbl.textContent='Copied!'; setTimeout(()=>lbl.textContent=orig,1400);
            } catch(err) { window.prompt('Copy share link:',url); }
        });
    }

    detailsBtns.forEach(btn=>{
        btn.addEventListener('click',function(e){
            e.preventDefault();
            currentItemId=this.dataset.itemId;
            const title=this.dataset.title||'';
            const encoded=this.dataset.description||'';
            const description=encoded?atob(encoded):'';
            const createdAt=this.dataset.createdAt||'', updatedAt=this.dataset.updatedAt||'';
            setDetailsTab('description');
            renderDetailsTags(this);
            if (currentItemId) { const u=new URL(window.location.href); u.searchParams.set('item',String(currentItemId)); window.history.replaceState({},'',u.toString()); }
            document.getElementById('detailsTitle').textContent=title;
            const dirtyHtml=typeof marked!=='undefined'?marked.parse(description||''):(description||'');
            const cleanHtml=window.DOMPurify?DOMPurify.sanitize(dirtyHtml):dirtyHtml;
            const content=document.getElementById('detailsContent'); content.innerHTML=cleanHtml;
            const meta=document.getElementById('detailsMeta');
            if(meta){ let t=createdAt?'Created '+formatDateSydney(createdAt):''; if(updatedAt&&updatedAt!==createdAt) t+=' \u2022 Updated '+formatDateSydney(updatedAt); meta.textContent=t; }
            if(typeof hljs!=='undefined') content.querySelectorAll('pre code').forEach(b=>hljs.highlightElement(b));
            loadAttachments(currentItemId);
            fetch('../get-activity.php?item_id='+encodeURIComponent(currentItemId))
                .then(r=>r.text()).then(html=>{ const cs=document.getElementById('commentsSection'); if(cs) cs.innerHTML=html; })
                .catch(()=>{ const cs=document.getElementById('commentsSection'); if(cs) cs.innerHTML='<p style="color:var(--red);font-size:0.875rem;">Error loading activity</p>'; });
            openModal(detailsModal);
        });
    });
    const deepItem=parseInt((new URLSearchParams(window.location.search)).get('item'),10);
    if (!isNaN(deepItem)) { const tb=Array.from(detailsBtns).find(b=>b.dataset.itemId===String(deepItem)); if(tb) tb.click(); }

    // Image zoom modal
    const imageZoomModal=document.getElementById('imageZoomModal');
    document.getElementById('closeImageZoomBtn')&&document.getElementById('closeImageZoomBtn').addEventListener('click',()=>closeModal(imageZoomModal));
    document.getElementById('imageZoomBackdrop')&&document.getElementById('imageZoomBackdrop').addEventListener('click',()=>closeModal(imageZoomModal));

    // Legend modal
    const legendModal=document.getElementById('legendModal');
    const legendBtn=document.getElementById('legendBtn');
    if(legendBtn) legendBtn.addEventListener('click',e=>{ e.preventDefault(); openModal(legendModal); });
    document.getElementById('closeLegendModal')&&document.getElementById('closeLegendModal').addEventListener('click',()=>closeModal(legendModal));
    document.getElementById('closeLegendBtn')&&document.getElementById('closeLegendBtn').addEventListener('click',()=>closeModal(legendModal));
    document.getElementById('legendModalBackdrop')&&document.getElementById('legendModalBackdrop').addEventListener('click',()=>closeModal(legendModal));

    // Comment modal
    const addCommentModal=document.getElementById('addCommentModal');
    const addCommentTrigger=document.getElementById('addCommentTrigger');
    const cancelCommentBtn=document.getElementById('cancelCommentBtn');
    const addCommentForm=document.getElementById('addCommentForm');
    if(addCommentTrigger) addCommentTrigger.addEventListener('click',e=>{ e.preventDefault(); const idEl=document.getElementById('commentItemId'); if(idEl) idEl.value=currentItemId; openModal(addCommentModal); });
    if(cancelCommentBtn) cancelCommentBtn.addEventListener('click',()=>closeModal(addCommentModal));
    document.getElementById('cancelCommentClose')&&document.getElementById('cancelCommentClose').addEventListener('click',()=>closeModal(addCommentModal));
    document.getElementById('commentModalBackdrop')&&document.getElementById('commentModalBackdrop').addEventListener('click',()=>closeModal(addCommentModal));
    if(addCommentForm) {
        addCommentForm.addEventListener('submit',function(e){
            e.preventDefault();
            fetch(window.location.href,{method:'POST',body:new FormData(addCommentForm)})
                .then(()=>{ closeModal(addCommentModal); addCommentForm.reset(); fetch('../get-activity.php?item_id='+encodeURIComponent(currentItemId)).then(r=>r.text()).then(html=>{ const cs=document.getElementById('commentsSection'); if(cs) cs.innerHTML=html; }); })
                .catch(()=>alert('Error adding comment.'));
        });
    }

    // Edit item modal
    const editItemModal=document.getElementById('editItemModal');
    const cancelEditBtn=document.getElementById('cancelEditBtn');
    document.querySelectorAll('.edit-item-btn').forEach(btn=>{
        btn.addEventListener('click',function(e){
            e.preventDefault();
            document.getElementById('editItemId').value=this.dataset.itemId;
            document.getElementById('editItemTitle').value=this.dataset.title;
            const encoded=this.dataset.description||'';
            document.getElementById('editItemDescription').value=encoded?atob(encoded):'';
            document.getElementById('editItemCategory').value=this.dataset.category;
            document.getElementById('editItemPriority').value=this.dataset.priority;
            const tagEl=document.getElementById('editItemSubcategory');
            if(tagEl&&tagEl._tms){ try{const arr=JSON.parse(this.dataset.subcategory||'[]'); tagEl._tms.setValues(Array.isArray(arr)?arr:[arr]);}catch(err){tagEl._tms.setValues(this.dataset.subcategory?[this.dataset.subcategory]:[]);} }
            const webWrapper=document.getElementById('edit-website-type-field');
            if(webWrapper){ const hasWeb=tagEl&&tagEl._tms&&tagEl._tms.getValues().includes('WEBSITE'); webWrapper.style.display=hasWeb?'':'none'; }
            const webEl=document.getElementById('editItemWebsiteType');
            const webData=this.dataset.websiteType||''; let webValues=[];
            try{ const a=JSON.parse(webData); webValues=Array.isArray(a)?a:[a]; }catch(e){ if(webData) webValues=[webData]; }
            if(webEl&&webEl._tms) webEl._tms.setValues(webValues);
            openModal(editItemModal);
        });
    });
    if(cancelEditBtn) cancelEditBtn.addEventListener('click',()=>closeModal(editItemModal));
    document.getElementById('cancelEditClose')&&document.getElementById('cancelEditClose').addEventListener('click',()=>closeModal(editItemModal));
    document.getElementById('editModalBackdrop')&&document.getElementById('editModalBackdrop').addEventListener('click',()=>closeModal(editItemModal));
    if(document.getElementById('editItemForm')) {
        document.getElementById('editItemForm').addEventListener('submit',function(e){
            const tagEl=document.getElementById('editItemSubcategory');
            const vals=(tagEl&&tagEl._tms)?tagEl._tms.getValues():[];
            if(!vals.length){ alert('Please select at least one Subcategory'); e.preventDefault(); return; }
            e.preventDefault(); this.submit();
        });
    }

    // Upload attachment modal
    const uploadModal=document.getElementById('uploadAttachmentModal');
    const uploadForm=document.getElementById('uploadAttachmentForm');
    const uploadBtn=document.getElementById('uploadAttachmentBtn');
    const cancelUploadBtn=document.getElementById('cancelUploadBtn');
    const addAttachTrigger=document.getElementById('addAttachmentTrigger');
    const fileInput=document.getElementById('attachmentFileInput');
    const fileNameEl=document.getElementById('attachmentFileName');
    if(addAttachTrigger) addAttachTrigger.addEventListener('click',e=>{ e.preventDefault(); const idEl=document.getElementById('uploadItemId'); if(idEl) idEl.value=currentItemId; if(uploadForm){uploadForm.reset(); if(fileNameEl) fileNameEl.textContent='';} const errEl=document.getElementById('uploadError'); if(errEl) errEl.style.display='none'; openModal(uploadModal); });
    if(fileInput) fileInput.addEventListener('change',function(){ if(fileNameEl) fileNameEl.textContent=this.files.length?this.files[0].name:''; });
    if(cancelUploadBtn) cancelUploadBtn.addEventListener('click',()=>closeModal(uploadModal));
    document.getElementById('cancelUploadClose')&&document.getElementById('cancelUploadClose').addEventListener('click',()=>closeModal(uploadModal));
    document.getElementById('uploadModalBackdrop')&&document.getElementById('uploadModalBackdrop').addEventListener('click',()=>closeModal(uploadModal));
    if(uploadForm) {
        uploadForm.addEventListener('submit',function(e){
            e.preventDefault();
            const fi=document.getElementById('attachmentFileInput'), uidEl=document.getElementById('uploadItemId');
            const errEl=document.getElementById('uploadError'), errMsg=document.getElementById('uploadErrorMessage');
            function showErr(msg){ if(errEl) errEl.style.display='block'; if(errMsg) errMsg.textContent=msg; }
            if(!fi||!fi.files||!fi.files.length){ showErr('Please select a file'); return; }
            if(!uidEl||!uidEl.value){ showErr('No item selected'); return; }
            const fd=new FormData(uploadForm);
            const progWrap=document.getElementById('uploadProgress'), progBar=document.getElementById('uploadProgressBar'), progText=document.getElementById('uploadStatusText');
            if(progWrap) progWrap.style.display='block';
            if(uploadBtn) uploadBtn.disabled=true;
            if(errEl) errEl.style.display='none';
            const xhr=new XMLHttpRequest();
            xhr.upload.addEventListener('progress',e=>{ if(e.lengthComputable&&progBar){ const pct=Math.round((e.loaded/e.total)*100); progBar.style.width=pct+'%'; if(progText) progText.textContent='Uploading: '+pct+'%'; } });
            xhr.addEventListener('load',function(){ if(progWrap) progWrap.style.display='none'; if(uploadBtn) uploadBtn.disabled=false; try{ const res=JSON.parse(xhr.responseText); if(xhr.status===200&&res.success){ closeModal(uploadModal); loadAttachments(currentItemId); uploadForm.reset(); if(fileNameEl) fileNameEl.textContent=''; } else { showErr(res.message||'Upload failed'); } }catch(err){ showErr('Server error'); } });
            xhr.addEventListener('error',function(){ if(progWrap) progWrap.style.display='none'; if(uploadBtn) uploadBtn.disabled=false; showErr('Network error'); });
            xhr.open('POST','../admin/upload-attachment.php',true);
            xhr.send(fd);
        });
    }

    // Escape key
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape'){
            closeVersionModal();
            if(detailsModal&&detailsModal.classList.contains('open')) closeDetailsModal();
            if(imageZoomModal) closeModal(imageZoomModal);
            if(legendModal) closeModal(legendModal);
            if(addCommentModal) closeModal(addCommentModal);
            if(editItemModal) closeModal(editItemModal);
            if(uploadModal) closeModal(uploadModal);
        }
    });
}); // end DOMContentLoaded

// Version modal (global scope for timeline buttons)
function decodeBase64Utf8(value) {
    if (!value) return '';
    try { return decodeURIComponent(Array.prototype.map.call(atob(value),function(ch){ return '%'+('00'+ch.charCodeAt(0).toString(16)).slice(-2); }).join('')); }
    catch(e) { return ''; }
}
function openVersionModalFromButton(button) {
    const version=(button&&button.dataset&&button.dataset.version)?button.dataset.version:'';
    const markdownB64=(button&&button.dataset&&button.dataset.markdownB64)?button.dataset.markdownB64:'';
    openVersionModal(version,decodeBase64Utf8(markdownB64));
}
function openVersionModal(version,markdownText) {
    const modal=document.getElementById('versionModal');
    const numEl=document.getElementById('modalVersionNumber');
    const content=document.getElementById('modalContent');
    if(numEl) numEl.textContent=version;
    const render=(md)=>{
        if(md&&md.trim()&&typeof marked!=='undefined'){ const html=marked.parse(md); content.innerHTML=window.DOMPurify?DOMPurify.sanitize(html):html; }
        else { content.innerHTML='<p style="color:var(--text-muted);">No changelog available.</p>'; }
        if(modal) modal.classList.add('open');
    };
    if(markdownText&&markdownText.trim()){ render(markdownText); return; }
    fetch('https://changelog.botofthespecter.com/'+version+'.md').then(r=>r.text()).then(render).catch(()=>render(''));
}
function closeVersionModal() {
    const modal=document.getElementById('versionModal');
    if(modal) modal.classList.remove('open');
}
</script>
</body>
</html>
