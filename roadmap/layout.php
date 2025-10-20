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
                &copy; 2024 BotOfTheSpecter. All rights reserved.
            </p>
        </div>
    </footer>
    <!-- Details Modal (Public) -->
    <div class="modal" id="detailsModal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title" id="detailsTitle">Item Details</p>
                <button class="delete"></button>
            </header>
            <section class="modal-card-body" style="display: flex; gap: 2rem; height: 600px;">
                <!-- Left Column: Description -->
                <div id="descriptionSection" style="flex: 1; overflow-y: auto; padding-right: 1rem;">
                    <h4 class="title is-6" style="margin-bottom: 1rem;">Description</h4>
                    <div id="detailsContent" style="line-height: 1.6; color: #b0b0b0;"></div>
                </div>
                
                <!-- Right Column: Activity/Comments Feed -->
                <div id="commentsContainer" style="flex: 1; border-left: 1px solid rgba(255, 255, 255, 0.1); padding-left: 1.5rem; display: flex; flex-direction: column;">
                    <h4 class="title is-6" style="margin-bottom: 1rem;">Activity</h4>
                    <div id="commentsSection" style="flex: 1; overflow-y: auto;">
                        <!-- Comments will be loaded here -->
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
    <?php endif; ?>
    <?php if (isset($extraJS)): ?>
        <?php foreach ($extraJS as $js): ?>
            <script src="<?php echo htmlspecialchars($js); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const detailsBtns = document.querySelectorAll('.details-btn');
        const detailsModal = document.getElementById('detailsModal');
        const addCommentModal = document.getElementById('addCommentModal');
        const cancelCommentBtn = document.getElementById('cancelCommentBtn');
        const addCommentForm = document.getElementById('addCommentForm');
        let currentItemId = null;
        // Details button handler
        detailsBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const title = this.dataset.title;
                const description = this.dataset.description;
                currentItemId = this.getAttribute('data-item-id');
                document.getElementById('detailsTitle').textContent = title;
                document.getElementById('detailsContent').textContent = description;
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