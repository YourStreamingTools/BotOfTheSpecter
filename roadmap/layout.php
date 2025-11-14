<?php
date_default_timezone_set('Australia/Sydney');

function uuidv4() { 
    return bin2hex(random_bytes(2)); 
} 
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" class="theme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - BotOfTheSpecter Roadmap' : 'BotOfTheSpecter Roadmap'; ?></title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <link rel="stylesheet" href="../css/custom.css?v=<?php echo uuidv4(); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar is-dark is-fixed-top">
        <div class="navbar-brand">
            <div class="navbar-item">
                <figure class="image is-32x32" style="margin-right: 1rem;">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="border-radius: 50%;">
                </figure>
                <span class="title is-5">BotOfTheSpecter Roadmap</span>
            </div>
        </div>
        <div class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item" href="../index.php">
                    <span class="icon-text">
                        <span class="icon"><i class="fas fa-home"></i></span>
                        <span>Home</span>
                    </span>
                </a>
                <?php if (isset($_SESSION['admin']) && $_SESSION['admin']): ?>
                <a class="navbar-item" href="../admin/">
                    <span class="icon-text">
                        <span class="icon"><i class="fas fa-cog"></i></span>
                        <span>Admin</span>
                    </span>
                </a>
                <?php endif; ?>
            </div>
            <div class="navbar-end">
                <?php if (isset($_SESSION['username'])): ?>
                    <div class="navbar-item has-dropdown is-hoverable">
                        <a class="navbar-link">
                            <span class="icon-text">
                                <span class="icon"><i class="fas fa-user-circle"></i></span>
                                <span><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username']); ?></span>
                            </span>
                        </a>
                        <div class="navbar-dropdown is-right">
                            <a class="navbar-item" href="logout.php">
                                <span class="icon-text">
                                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                                    <span>Logout</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="navbar-item">
                        <div class="buttons">
                            <a class="button is-primary" href="login.php">
                                <span class="icon-text">
                                    <span class="icon"><i class="fas fa-sign-in-alt"></i></span>
                                    <span>Login</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <!-- Main Content -->
    <main>
        <section class="section" style="margin-top: 3.25rem;">
            <div class="container">
                <?php if (isset($pageContent)): ?>
                    <?php echo $pageContent; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <!-- Footer -->
    <footer class="footer">
        <div class="content has-text-centered">
            <span class="has-text-weight-bold">BotOfTheSpecter Roadmap</span> - A comprehensive Twitch bot platform for streamers.
            <p style="margin-top: 1rem; font-size: 0.875rem;">
                &copy; <?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.
            </p>
        </div>
    </footer>
    <!-- Details Modal (Public) -->
    <div class="modal" id="detailsModal">
        <div class="modal-background"></div>
        <div class="modal-card" style="width: 85vw; height: 88vh; max-width: 1400px; display: flex; flex-direction: column;">
            <header class="modal-card-head">
                <p class="modal-card-title" id="detailsTitle">Item Details</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; flex: 1; overflow: hidden; padding: 1.5rem;">
                <!-- Left Column: Description and Attachments -->
                <div style="overflow-y: auto; padding-right: 1rem; display: flex; flex-direction: column; gap: 1.5rem;">
                    <div>
                        <h4 class="title is-6">Description</h4>
                        <div id="detailsContent" style="color: #b0b0b0; line-height: 1.6;"></div>
                    </div>
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 class="title is-6" style="margin: 0;">Attachments</h4>
                            <?php if (isset($_SESSION['admin']) && $_SESSION['admin']): ?>
                                <button class="button is-small is-success" id="addAttachmentTrigger" style="flex-shrink: 0;">
                                    <span class="icon is-small"><i class="fas fa-plus"></i></span>
                                    <span>Add</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div id="attachmentsSection" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <!-- Attachments will load here -->
                        </div>
                    </div>
                </div>
                <!-- Right Column: Comments -->
                <div style="display: flex; flex-direction: column; border-left: 1px solid rgba(255, 255, 255, 0.1); padding-left: 1.5rem; overflow: hidden;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-shrink: 0;">
                        <h4 class="title is-6" style="margin: 0;">Activity</h4>
                        <?php if (isset($_SESSION['admin']) && $_SESSION['admin']): ?>
                            <button class="button is-small is-primary" id="addCommentTrigger" style="flex-shrink: 0;">
                                <span class="icon is-small"><i class="fas fa-comment"></i></span>
                                <span>Comment</span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div id="commentsSection" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 0.5rem;">
                        <!-- Comments will load here -->
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot">
                <button class="button is-primary">Close</button>
            </footer>
        </div>
    </div>
    <!-- Image Zoom Modal -->
    <div class="modal" id="imageZoomModal">
        <div class="modal-background"></div>
        <div class="modal-card" style="width: 90%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; background-color: #0a0a0a;">
            <header class="modal-card-head" style="background-color: #1a1a2e;">
                <p class="modal-card-title" id="zoomImageName">Image</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body" style="flex: 1; display: flex; align-items: center; justify-content: center; overflow: auto; background-color: #0a0a0a; padding: 2rem;">
                <img id="zoomImageContent" src="" alt="" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            </section>
        </div>
    </div>
    <!-- Add Comment Modal (Admin Only) -->
    <?php if (isset($_SESSION['admin']) && $_SESSION['admin']): ?>
    <div class="modal" id="addCommentModal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Add Comment</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body">
                <form id="addCommentForm" method="POST">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="item_id" id="commentItemId" value="">
                    <div class="field">
                        <label class="label">Comment</label>
                        <div class="control">
                            <textarea class="textarea" name="comment_text" id="commentTextarea" placeholder="Enter your comment..." required></textarea>
                        </div>
                    </div>
                    <div class="field is-grouped">
                        <div class="control">
                            <button type="submit" class="button is-primary">Submit</button>
                        </div>
                        <div class="control">
                            <button type="button" class="button is-light" id="cancelCommentBtn">Cancel</button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
    </div>
        <!-- Edit Item Modal (Admin Only) -->
    <?php if (isset($_SESSION['admin']) && $_SESSION['admin']): ?>
    <div class="modal" id="editItemModal">
        <div class="modal-background"></div>
        <div class="modal-card" style="width: 90vw; max-width: 600px;">
            <header class="modal-card-head" style="padding: 1rem;">
                <p class="modal-card-title" style="font-size: 1.25rem;">Edit Item</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body" style="max-height: 60vh; overflow-y: auto; padding: 0.25rem;">
                <form id="editItemForm" method="POST" style="display: flex; flex-direction: column; gap: 0.125rem;">
                    <input type="hidden" name="action" value="edit_item">
                    <input type="hidden" name="id" id="editItemId" value="">
                    <label class="label" style="margin: 0 0 0.1rem 0; font-size: 0.8rem;">Title</label>
                    <input class="input" type="text" name="title" id="editItemTitle" placeholder="Item title" required style="padding: 0.25rem; font-size: 0.8rem; margin: 0;">
                    <label class="label" style="margin: 0.125rem 0 0.1rem 0; font-size: 0.8rem;">Description</label>
                    <textarea class="textarea" name="description" id="editItemDescription" placeholder="Item description (supports markdown)..." style="height: 150px; padding: 0.5rem; font-size: 0.9rem; resize: vertical; margin: 0; font-family: 'Courier New', monospace;"></textarea>
                    <div style="display: flex; gap: 0.25rem; margin-top: 0.125rem;">
                        <div style="flex: 1;">
                            <label class="label" style="margin: 0 0 0.1rem 0; font-size: 0.8rem;">Category</label>
                            <select name="category" id="editItemCategory" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; line-height: 1.2; border: 1px solid #444; background-color: #1a1a2e; color: #e0e0e0; border-radius: 4px; margin: 0;">
                                <option value="REQUESTS">REQUESTS</option>
                                <option value="IN PROGRESS">IN PROGRESS</option>
                                <option value="BETA TESTING">BETA TESTING</option>
                                <option value="COMPLETED">COMPLETED</option>
                                <option value="REJECTED">REJECTED</option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label class="label" style="margin: 0 0 0.1rem 0; font-size: 0.8rem;">Priority</label>
                            <select name="priority" id="editItemPriority" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; line-height: 1.2; border: 1px solid #444; background-color: #1a1a2e; color: #e0e0e0; border-radius: 4px; margin: 0;">
                                <option value="LOW">Low</option>
                                <option value="MEDIUM">Medium</option>
                                <option value="HIGH">High</option>
                                <option value="CRITICAL">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.25rem; margin-top: 0.125rem;">
                        <div style="flex: 1;">
                            <label class="label" style="margin: 0 0 0.1rem 0; font-size: 0.8rem;">Subcategory</label>
                            <select name="subcategory" id="editItemSubcategory" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; line-height: 1.2; border: 1px solid #444; background-color: #1a1a2e; color: #e0e0e0; border-radius: 4px; margin: 0;">
                                <option value="TWITCH BOT">TWITCH BOT</option>
                                <option value="DISCORD BOT">DISCORD BOT</option>
                                <option value="WEBSOCKET SERVER">WEBSOCKET SERVER</option>
                                <option value="API SERVER">API SERVER</option>
                                <option value="WEBSITE">WEBSITE</option>
                                <option value="OTHER">OTHER</option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label class="label" style="margin: 0 0 0.1rem 0; font-size: 0.8rem;">Website Type</label>
                            <select name="website_type" id="editItemWebsiteType" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; line-height: 1.2; border: 1px solid #444; background-color: #1a1a2e; color: #e0e0e0; border-radius: 4px; margin: 0;">
                                <option value="">None</option>
                                <option value="DASHBOARD">Dashboard</option>
                                <option value="OVERLAYS">Overlays</option>
                            </select>
                        </div>
                    </div>
                </form>
            </section>
            <footer class="modal-card-foot" style="padding: 0.5rem;">
                <button type="submit" form="editItemForm" class="button is-warning" style="font-size: 0.875rem; padding: 0.4rem 1rem;">Save Changes</button>
                <button type="button" class="button is-light" id="cancelEditBtn" style="font-size: 0.875rem; padding: 0.4rem 1rem;">Cancel</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>
    <!-- Upload Attachment Modal (Admin Only) -->
    <?php if (isset($_SESSION['admin']) && $_SESSION['admin']): ?>
    <div class="modal" id="uploadAttachmentModal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Upload Attachment</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body">
                <form id="uploadAttachmentForm" enctype="multipart/form-data">
                    <input type="hidden" name="item_id" id="uploadItemId" value="">
                    <div class="field">
                        <label class="label">Select File</label>
                        <div class="file is-boxed is-centered">
                            <label class="file-label">
                                <input class="file-input" type="file" name="file" id="attachmentFileInput" required accept="image/*,.pdf,.doc,.docx,.txt,.xls,.xlsx">
                                <span class="file-cta">
                                    <span class="file-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </span>
                                    <span class="file-label">
                                        Choose a file...
                                    </span>
                                </span>
                                <span class="file-name" id="attachmentFileName">
                                    No file selected
                                </span>
                            </label>
                        </div>
                        <p class="help">Allowed: Images (JPG, PNG, GIF, WebP, SVG), PDF, Word, Excel, TXT. Max 10MB</p>
                    </div>
                    <div id="uploadProgress" style="display: none;">
                        <progress class="progress is-primary" value="0" max="100" id="uploadProgressBar"></progress>
                        <p class="help has-text-centered" id="uploadStatusText">Uploading...</p>
                    </div>
                    <div id="uploadError" style="display: none;">
                        <div class="notification is-danger" style="margin-bottom: 1rem;">
                            <button class="delete"></button>
                            <span id="uploadErrorMessage"></span>
                        </div>
                    </div>
                </form>
            </section>
            <footer class="modal-card-foot">
                <button type="submit" form="uploadAttachmentForm" class="button is-success" id="uploadAttachmentBtn">
                    <span class="icon is-small"><i class="fas fa-upload"></i></span>
                    <span>Upload</span>
                </button>
                <button type="button" class="button is-light" id="cancelUploadBtn">Cancel</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <!-- Legend Modal -->
    <div class="modal" id="legendModal">
        <div class="modal-background"></div>
        <div class="modal-card" style="width: 90%; max-width: 500px;">
            <header class="modal-card-head">
                <p class="modal-card-title">Legend</p>
                <button class="delete" id="closeLegendModal"></button>
            </header>
            <section class="modal-card-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div>
                        <strong style="font-size: 0.875rem; display: block; margin-bottom: 0.75rem;">Priority Levels:</strong>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <span class="tag is-small is-success">Low</span>
                            <span class="tag is-small is-info">Medium</span>
                            <span class="tag is-small is-warning">High</span>
                            <span class="tag is-small is-danger">Critical</span>
                        </div>
                    </div>
                    <div>
                        <strong style="font-size: 0.875rem; display: block; margin-bottom: 0.75rem;">Subcategories:</strong>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <span class="tag is-small is-primary">Twitch Bot</span>
                            <span class="tag is-small is-info">Discord Bot</span>
                            <span class="tag is-small is-success">WebSocket Server</span>
                            <span class="tag is-small is-warning">API Server</span>
                            <span class="tag is-small is-danger">Website</span>
                            <span class="tag is-small is-light">Other</span>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot" style="justify-content: flex-end;">
                <button type="button" class="button is-light" id="closeLegendBtn">Close</button>
            </footer>
        </div>
    </div>
    <?php if (isset($extraJS)): ?>
        <?php foreach ($extraJS as $js): ?>
            <script src="<?php echo htmlspecialchars($js); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
    // Helper function to convert URLs in text to clickable links
    function linkifyText(text) {
        // Regular expression to match URLs
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        return text.replace(urlRegex, function(url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color: #667eea; text-decoration: underline; cursor: pointer;">' + url + '</a>';
        });
    }
    // Format date in Australia/Sydney timezone
    function formatDateSydney(dateString) {
        const date = new Date(dateString);
        const options = {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            timeZone: 'Australia/Sydney'
        };
        return date.toLocaleDateString('en-AU', options);
    }
    // Helper function to get file icon based on type
    function getFileIcon(mimeType) {
        if (mimeType.startsWith('image/')) return 'fa-image';
        if (mimeType === 'application/pdf') return 'fa-file-pdf';
        if (mimeType.includes('word') || mimeType.includes('document')) return 'fa-file-word';
        if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'fa-file-excel';
        if (mimeType === 'text/plain') return 'fa-file-alt';
        return 'fa-file';
    }
    function isImage(mimeType) {
        return mimeType.startsWith('image/');
    }
    // Load attachments for an item
    function loadAttachments(itemId) {
        const attachmentsSection = document.getElementById('attachmentsSection');
        if (!attachmentsSection) return;
        fetch('../admin/get-attachments.php?item_id=' + encodeURIComponent(itemId))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.attachments.length > 0) {
                    let html = '';
                    data.attachments.forEach(att => {
                        const fileIcon = getFileIcon(att.file_type);
                        const isImageFile = isImage(att.file_type);
                        
                        if (isImageFile) {
                            // Display image inline
                            html += `
                                <div class="box p-3" style="background-color: rgba(100, 126, 234, 0.1); border-left: 3px solid #667eea; margin-bottom: 0.5rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                                        <div style="flex: 1; min-width: 0;">
                                            <small style="color: #888; display: block; margin-bottom: 0.5rem;">
                                                ${att.file_name} • ${att.file_size_formatted} • ${att.uploaded_by} • ${formatDateSydney(att.created_at)}
                                            </small>
                                            <div style="text-align: center; cursor: pointer;">
                                                <img src="${att.file_path}" alt="${att.file_name}" style="max-width: 100%; max-height: 400px; border-radius: 4px; transition: opacity 0.2s; opacity: 1;" class="zoom-image" data-filename="${att.file_name}">
                                            </div>
                                        </div>
        `;
                            if (att.can_delete) {
                                html += `
                                        <button class="button is-small is-danger is-light delete-attachment-btn" data-attachment-id="${att.id}" data-item-id="${itemId}" style="flex-shrink: 0; align-self: flex-start;">
                                            <span class="icon is-small"><i class="fas fa-trash" style="color: white;"></i></span>
                                        </button>
                `;
                            }
                            html += `
                                    </div>
                                </div>
                            `;
                        } else {
                            // Display document with download link
                            html += `
                                <div class="box p-3" style="background-color: rgba(100, 126, 234, 0.1); border-left: 3px solid #667eea; margin-bottom: 0.5rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                                                <i class="fas ${fileIcon}" style="color: #667eea;"></i>
                                                <a href="${att.file_path}" download style="color: #667eea; text-decoration: underline; word-break: break-word;">
                                                    ${att.file_name}
                                                </a>
                                            </div>
                                            <small style="color: #888;">
                                                ${att.file_size_formatted} • ${att.uploaded_by} • ${formatDateSydney(att.created_at)}
                                            </small>
                                        </div>
        `;
                            if (att.can_delete) {
                                html += `
                                        <button class="button is-small is-danger is-light delete-attachment-btn" data-attachment-id="${att.id}" data-item-id="${itemId}" style="flex-shrink: 0;">
                                            <span class="icon is-small"><i class="fas fa-trash" style="color: white;"></i></span>
                                        </button>
                `;
                            }
                            html += `
                                    </div>
                                </div>
                            `;
                        }
                    });
                    attachmentsSection.innerHTML = html;
                    // Add delete event listeners
                    document.querySelectorAll('.delete-attachment-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            if (confirm('Delete this attachment?')) {
                                const attachmentId = this.getAttribute('data-attachment-id');
                                const itemId = this.getAttribute('data-item-id');
                                
                                const formData = new FormData();
                                formData.append('attachment_id', attachmentId);
                                
                                fetch('../admin/delete-attachment.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        loadAttachments(itemId);
                                    } else {
                                        alert('Error deleting attachment: ' + data.message);
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('Error deleting attachment');
                                });
                            }
                        });
                    });
                    // Add zoom image event listeners
                    const imageZoomModal = document.getElementById('imageZoomModal');
                    const zoomImageContent = document.getElementById('zoomImageContent');
                    const zoomImageName = document.getElementById('zoomImageName');
                    document.querySelectorAll('.zoom-image').forEach(img => {
                        img.addEventListener('click', function() {
                            zoomImageContent.src = this.src;
                            zoomImageName.textContent = this.getAttribute('data-filename');
                            if (imageZoomModal) {
                                imageZoomModal.classList.add('is-active');
                            }
                        });
                    });
                } else {
                    attachmentsSection.innerHTML = '<p class="has-text-grey-light"><em>No attachments</em></p>';
                }
            })
            .catch(error => {
                console.error('Error loading attachments:', error);
                attachmentsSection.innerHTML = '<p class="has-text-danger">Error loading attachments</p>';
            });
    }
    document.addEventListener('DOMContentLoaded', function() {
        const detailsBtns = document.querySelectorAll('.details-btn');
        const detailsModal = document.getElementById('detailsModal');
        const addCommentModal = document.getElementById('addCommentModal');
        const cancelCommentBtn = document.getElementById('cancelCommentBtn');
        const addCommentForm = document.getElementById('addCommentForm');
        const uploadAttachmentModal = document.getElementById('uploadAttachmentModal');
        const uploadAttachmentForm = document.getElementById('uploadAttachmentForm');
        const uploadAttachmentBtn = document.getElementById('uploadAttachmentBtn');
        const cancelUploadBtn = document.getElementById('cancelUploadBtn');
        const addAttachmentTrigger = document.getElementById('addAttachmentTrigger');
        const attachmentFileInput = document.getElementById('attachmentFileInput');
        const attachmentFileName = document.getElementById('attachmentFileName');
        const legendBtn = document.getElementById('legendBtn');
        const legendModal = document.getElementById('legendModal');
        const closeLegendModal = document.getElementById('closeLegendModal');
        const closeLegendBtn = document.getElementById('closeLegendBtn');
        let currentItemId = null;
        // File input change handler
        if (attachmentFileInput) {
            attachmentFileInput.addEventListener('change', function() {
                if (attachmentFileName) {
                    attachmentFileName.textContent = this.files.length > 0 ? this.files[0].name : 'No file selected';
                }
            });
        }
        // Add attachment trigger button (for admins)
        if (addAttachmentTrigger) {
            addAttachmentTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('uploadItemId').value = currentItemId;
                if (uploadAttachmentForm) {
                    uploadAttachmentForm.reset();
                    if (attachmentFileName) attachmentFileName.textContent = 'No file selected';
                    const uploadError = document.getElementById('uploadError');
                    if (uploadError) uploadError.style.display = 'none';
                }
                if (uploadAttachmentModal) {
                    uploadAttachmentModal.classList.add('is-active');
                }
            });
        }
        // Upload attachment form submission
        if (uploadAttachmentForm) {
            uploadAttachmentForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const fileInput = document.getElementById('attachmentFileInput');
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    const uploadError = document.getElementById('uploadError');
                    if (uploadError) {
                        uploadError.style.display = 'block';
                        const uploadErrorMessage = document.getElementById('uploadErrorMessage');
                        if (uploadErrorMessage) uploadErrorMessage.textContent = 'Please select a file';
                    }
                    return;
                }
                const itemIdInput = document.getElementById('uploadItemId');
                if (!itemIdInput || !itemIdInput.value) {
                    const uploadError = document.getElementById('uploadError');
                    if (uploadError) {
                        uploadError.style.display = 'block';
                        const uploadErrorMessage = document.getElementById('uploadErrorMessage');
                        if (uploadErrorMessage) uploadErrorMessage.textContent = 'No item selected';
                    }
                    return;
                }
                const formData = new FormData(uploadAttachmentForm);
                const uploadProgress = document.getElementById('uploadProgress');
                const uploadProgressBar = document.getElementById('uploadProgressBar');
                const uploadStatusText = document.getElementById('uploadStatusText');
                const uploadError = document.getElementById('uploadError');
                const uploadErrorMessage = document.getElementById('uploadErrorMessage');
                const uploadAttachmentBtn = document.getElementById('uploadAttachmentBtn');
                if (uploadProgress) uploadProgress.style.display = 'block';
                if (uploadAttachmentBtn) uploadAttachmentBtn.disabled = true;
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percentComplete = (e.loaded / e.total) * 100;
                        if (uploadProgressBar) uploadProgressBar.value = percentComplete;
                        if (uploadStatusText) uploadStatusText.textContent = 'Uploading: ' + Math.round(percentComplete) + '%';
                    }
                });
                xhr.addEventListener('load', function() {
                    if (uploadProgress) uploadProgress.style.display = 'none';
                    if (uploadAttachmentBtn) uploadAttachmentBtn.disabled = false;
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (xhr.status === 200 && response.success) {
                            // Close modal
                            if (uploadAttachmentModal) uploadAttachmentModal.classList.remove('is-active');
                            if (uploadError) uploadError.style.display = 'none';
                            // Reload attachments
                            loadAttachments(currentItemId);
                            // Reset form
                            uploadAttachmentForm.reset();
                            if (attachmentFileName) attachmentFileName.textContent = 'No file selected';
                        } else {
                            if (uploadError) uploadError.style.display = 'block';
                            if (uploadErrorMessage) uploadErrorMessage.textContent = response.message || 'Upload failed';
                        }
                    } catch (e) {
                        if (uploadError) uploadError.style.display = 'block';
                        if (uploadErrorMessage) uploadErrorMessage.textContent = 'Error uploading file: ' + e.message;
                    }
                });
                xhr.addEventListener('error', function() {
                    if (uploadProgress) uploadProgress.style.display = 'none';
                    if (uploadAttachmentBtn) uploadAttachmentBtn.disabled = false;
                    if (uploadError) uploadError.style.display = 'block';
                    if (uploadErrorMessage) uploadErrorMessage.textContent = 'Network error: ' + xhr.statusText;
                });
                xhr.addEventListener('abort', function() {
                    if (uploadProgress) uploadProgress.style.display = 'none';
                    if (uploadAttachmentBtn) uploadAttachmentBtn.disabled = false;
                });
                xhr.open('POST', '../admin/upload-attachment.php', true);
                xhr.send(formData);
            });
        }
        // Cancel upload button
        if (cancelUploadBtn) {
            cancelUploadBtn.addEventListener('click', function() {
                if (uploadAttachmentModal) uploadAttachmentModal.classList.remove('is-active');
            });
        }
        // Legend button handler
        if (legendBtn) {
            legendBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (legendModal) {
                    legendModal.classList.add('is-active');
                }
            });
        }
        // Close legend modal handlers
        if (closeLegendModal) {
            closeLegendModal.addEventListener('click', function() {
                if (legendModal) {
                    legendModal.classList.remove('is-active');
                }
            });
        }
        if (closeLegendBtn) {
            closeLegendBtn.addEventListener('click', function() {
                if (legendModal) {
                    legendModal.classList.remove('is-active');
                }
            });
        }
        // Details button handler
        detailsBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const title = this.dataset.title;
                const description = this.dataset.description;
                currentItemId = this.getAttribute('data-item-id');
                document.getElementById('detailsTitle').textContent = title;
                // Parse markdown and linkify the description
                const markdownHtml = marked.parse(description || '');
                const detailsContent = document.getElementById('detailsContent');
                detailsContent.innerHTML = markdownHtml;
                // Highlight code blocks
                detailsContent.querySelectorAll('pre code').forEach(block => {
                    hljs.highlightElement(block);
                });
                // Load attachments
                loadAttachments(currentItemId);
                // Load activity via AJAX
                fetch('../get-activity.php?item_id=' + encodeURIComponent(currentItemId))
                    .then(response => response.text())
                    .then(html => {
                        const commentsSection = document.getElementById('commentsSection');
                        commentsSection.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Error loading comments:', error);
                        document.getElementById('commentsSection').innerHTML = '<p class="has-text-danger">Error loading comments</p>';
                    });
                detailsModal.classList.add('is-active');
            });
        });
        // Add comment trigger button (for admins)
        const addCommentTrigger = document.getElementById('addCommentTrigger');
        if (addCommentTrigger) {
            addCommentTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('commentItemId').value = currentItemId;
                addCommentModal.classList.add('is-active');
            });
        }
        // Add comment form submission
        if (addCommentForm) {
            addCommentForm.addEventListener('submit', function(e) {
                e.preventDefault();
                // Submit the form
                fetch(window.location.href, {
                    method: 'POST',
                    body: new FormData(addCommentForm)
                })
                .then(() => {
                    // Close comment modal
                    if (addCommentModal) {
                        addCommentModal.classList.remove('is-active');
                    }
                    // Clear form
                    addCommentForm.reset();
                    // Reload activity in details modal
                    fetch('../get-activity.php?item_id=' + encodeURIComponent(currentItemId))
                        .then(response => response.text())
                        .then(html => {
                            const commentsSection = document.getElementById('commentsSection');
                            commentsSection.innerHTML = html;
                        });
                })
                .catch(error => {
                    console.error('Error submitting comment:', error);
                    alert('Error adding comment. Please try again.');
                });
            });
        }
        // Close comment modal cancel button
        if (cancelCommentBtn) {
            cancelCommentBtn.addEventListener('click', function() {
                if (addCommentModal) {
                    addCommentModal.classList.remove('is-active');
                }
            });
        }
        // Edit item button handler
        const editItemBtns = document.querySelectorAll('.edit-item-btn');
        const editItemModal = document.getElementById('editItemModal');
        const editItemForm = document.getElementById('editItemForm');
        const cancelEditBtn = document.getElementById('cancelEditBtn');
        editItemBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('editItemId').value = this.getAttribute('data-item-id');
                document.getElementById('editItemTitle').value = this.getAttribute('data-title');
                document.getElementById('editItemDescription').value = this.getAttribute('data-description');
                document.getElementById('editItemCategory').value = this.getAttribute('data-category');
                document.getElementById('editItemSubcategory').value = this.getAttribute('data-subcategory');
                document.getElementById('editItemPriority').value = this.getAttribute('data-priority');
                document.getElementById('editItemWebsiteType').value = this.getAttribute('data-website-type');
                if (editItemModal) {
                    editItemModal.classList.add('is-active');
                }
            });
        });
        if (editItemForm) {
            editItemForm.addEventListener('submit', function(e) {
                e.preventDefault();
                this.submit();
            });
        }
        if (cancelEditBtn) {
            cancelEditBtn.addEventListener('click', function() {
                if (editItemModal) {
                    editItemModal.classList.remove('is-active');
                }
            });
        }
        // Close modal handlers for both modals
        const deleteButtons = document.querySelectorAll('.modal .delete');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    modal.classList.remove('is-active');
                }
            });
        });
        const closeButtons = document.querySelectorAll('.modal-card-foot .button');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    modal.classList.remove('is-active');
                }
            });
        });
        // Close modal on background click
        const modalBackgrounds = document.querySelectorAll('.modal-background');
        modalBackgrounds.forEach(bg => {
            bg.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    modal.classList.remove('is-active');
                }
            });
        });
    });
    </script>
</body>
</html>