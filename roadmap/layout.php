<?php
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
    <link rel="stylesheet" href="../css/custom.css?v=<?php echo uuidv4(); ?>">
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
        <div class="modal-card" style="width: 90%; max-width: 900px; max-height: 85vh; display: flex; flex-direction: column;">
            <header class="modal-card-head">
                <p class="modal-card-title" id="detailsTitle">Item Details</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; flex: 1; overflow: hidden; padding: 1.5rem;">
                <!-- Left Column: Description -->
                <div style="overflow-y: auto; padding-right: 1rem;">
                    <h4 class="title is-6">Description</h4>
                    <div id="detailsContent" style="color: #b0b0b0; line-height: 1.6;"></div>
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
                    <textarea class="textarea" name="description" id="editItemDescription" placeholder="Item description..." style="height: 60px; padding: 0.25rem; font-size: 0.8rem; resize: none; margin: 0;"></textarea>
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
    document.addEventListener('DOMContentLoaded', function() {
        const detailsBtns = document.querySelectorAll('.details-btn');
        const detailsModal = document.getElementById('detailsModal');
        const addCommentModal = document.getElementById('addCommentModal');
        const cancelCommentBtn = document.getElementById('cancelCommentBtn');
        const addCommentForm = document.getElementById('addCommentForm');
        const legendBtn = document.getElementById('legendBtn');
        const legendModal = document.getElementById('legendModal');
        const closeLegendModal = document.getElementById('closeLegendModal');
        const closeLegendBtn = document.getElementById('closeLegendBtn');
        let currentItemId = null;
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
                // Linkify the description
                document.getElementById('detailsContent').innerHTML = linkifyText(description);
                // Load comments via AJAX
                fetch('../get-comments.php?item_id=' + encodeURIComponent(currentItemId))
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
                    // Reload comments in details modal
                    fetch('../get-comments.php?item_id=' + encodeURIComponent(currentItemId))
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