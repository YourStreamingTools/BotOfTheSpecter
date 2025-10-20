<?php
session_start();

// Require admin authentication
if (!isset($_SESSION['username']) || !($_SESSION['admin'] ?? false)) {
    header('Location: ../login.php');
    exit;
}

require_once 'database.php';
require_once "/var/www/config/database.php";

// Initialize database and run migrations
initializeRoadmapDatabase();

// Set page metadata
$pageTitle = 'Roadmap Admin';

// Get all roadmap items grouped by category
$conn = getRoadmapConnection();

// Handle form submissions
$message = '';
$message_type = '';

// Add new item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'] ?? 'REQUESTS';
        $subcategory = $_POST['subcategory'] ?? 'TWITCH BOT';
        $priority = $_POST['priority'] ?? 'MEDIUM';
        $website_type = (!empty($_POST['website_type']) ? $_POST['website_type'] : null);
        $stmt = $conn->prepare("INSERT INTO roadmap_items (title, description, category, subcategory, priority, website_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssss", $title, $description, $category, $subcategory, $priority, $website_type, $_SESSION['username']);
            if ($stmt->execute()) {
                $message = 'Roadmap item added successfully!';
                $message_type = 'success';
            } else {
                $message = 'Error adding item: ' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        } else {
            $message = 'Error preparing statement: ' . $conn->error;
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] === 'update') {
        $id = $_POST['id'] ?? 0;
        $category = $_POST['category'] ?? 'REQUESTS';
        $status = $_POST['status'] ?? '';
        if ($status === 'completed') {
            $stmt = $conn->prepare("UPDATE roadmap_items SET category = 'COMPLETED', completed_date = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("UPDATE roadmap_items SET category = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $category, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        $message = 'Item updated successfully!';
        $message_type = 'success';
    } elseif ($_POST['action'] === 'edit_item') {
        $id = $_POST['id'] ?? 0;
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'] ?? 'REQUESTS';
        $subcategory = $_POST['subcategory'] ?? 'TWITCH BOT';
        $priority = $_POST['priority'] ?? 'MEDIUM';
        $website_type = (!empty($_POST['website_type']) ? $_POST['website_type'] : null);
        $stmt = $conn->prepare("UPDATE roadmap_items SET title = ?, description = ?, category = ?, subcategory = ?, priority = ?, website_type = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $title, $description, $category, $subcategory, $priority, $website_type, $id);
            if ($stmt->execute()) {
                $message = 'Item edited successfully!';
                $message_type = 'success';
            } else {
                $message = 'Error editing item: ' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        } else {
            $message = 'Error preparing statement: ' . $conn->error;
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = $_POST['id'] ?? 0;
        $stmt = $conn->prepare("DELETE FROM roadmap_items WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $message = 'Item deleted successfully!';
                $message_type = 'success';
            } else {
                $message = 'Error deleting item: ' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        } else {
            $message = 'Error preparing statement: ' . $conn->error;
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] === 'add_comment') {
        $item_id = $_POST['item_id'] ?? 0;
        $comment_text = $_POST['comment_text'] ?? '';
        if (!empty($comment_text) && $item_id > 0) {
            $stmt = $conn->prepare("INSERT INTO roadmap_comments (item_id, username, comment) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("iss", $item_id, $_SESSION['username'], $comment_text);
                if ($stmt->execute()) {
                    $message = 'Comment added successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error adding comment: ' . $stmt->error;
                    $message_type = 'danger';
                }
                $stmt->close();
            }
        }
    }
}

// Get all categories and items
$categories = array('REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED');
$subcategories = array('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE');

// Get all items
$allItems = [];
$query = "SELECT * FROM roadmap_items ORDER BY priority DESC, created_at DESC";
if ($result = $conn->query($query)) {
    while ($row = $result->fetch_assoc()) {
        $allItems[] = $row;
    }
    $result->free();
}

// Group items by category
$itemsByCategory = [];
foreach ($categories as $cat) {
    $itemsByCategory[$cat] = [];
}
foreach ($allItems as $item) {
    $itemsByCategory[$item['category']][] = $item;
}

// Build page content
ob_start();
?>
<div class="mb-6">
    <h1 class="title">Roadmap Administration</h1>
    <p class="subtitle">Manage roadmap items and track development progress</p>
</div>
<?php if ($message): ?>
    <div class="notification is-<?php echo htmlspecialchars($message_type); ?>">
        <button class="delete"></button>
        <strong><?php echo ucfirst($message_type); ?>:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<!-- Add New Item Form -->
<div class="box mb-6">
    <h2 class="title is-4 mb-5">
        <span class="icon-text">
            <span class="icon"><i class="fas fa-plus"></i></span>
            <span>Add New Roadmap Item</span>
        </span>
    </h2>
    <form method="POST" action="">
        <input type="hidden" name="action" value="add">
        <div class="columns">
            <div class="column is-two-thirds">
                <div class="field">
                    <label class="label">Title</label>
                    <div class="control">
                        <input class="input" type="text" name="title" placeholder="Item title" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Description</label>
                    <div class="control">
                        <textarea class="textarea" name="description" placeholder="Item description (optional)" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="column is-one-third">
                <div class="field">
                    <label class="label">Category</label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="category" id="category-select" required>
                                <option value="REQUESTS">Requests</option>
                                <option value="IN PROGRESS">In Progress</option>
                                <option value="BETA TESTING">Beta Testing</option>
                                <option value="COMPLETED">Completed</option>
                                <option value="REJECTED">Rejected</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Subcategory</label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="subcategory" required>
                                <option value="TWITCH BOT">Twitch Bot</option>
                                <option value="DISCORD BOT">Discord Bot</option>
                                <option value="WEBSOCKET SERVER">WebSocket Server</option>
                                <option value="API SERVER">API Server</option>
                                <option value="WEBSITE">Website</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field" id="website-type-field" style="display: none;">
                    <label class="label">Website Type</label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="website_type" id="website-type-select">
                                <option value="">None</option>
                                <option value="DASHBOARD">Dashboard</option>
                                <option value="OVERLAYS">Overlays</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Priority</label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="priority" id="priority-select">
                                <option value="LOW">Low</option>
                                <option value="MEDIUM" selected>Medium</option>
                                <option value="HIGH">High</option>
                                <option value="CRITICAL">Critical</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field is-grouped">
                    <div class="control">
                        <button type="submit" class="button is-primary is-fullwidth">
                            <span class="icon-text">
                                <span class="icon"><i class="fas fa-save"></i></span>
                                <span>Add Item</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<!-- Category Columns -->
<div class="columns is-multiline">
    <?php foreach ($categories as $category): ?>
        <div class="column is-one-fifth">
            <div class="box roadmap-column">
                <h2 class="title is-5 mb-4">
                    <span class="icon-text">
                        <span class="icon"><i class="fas fa-<?php echo getCategoryIcon($category); ?>"></i></span>
                        <span><?php echo htmlspecialchars($category); ?></span>
                    </span>
                </h2>
                <div class="mb-2 roadmap-item-count">
                    <strong><?php echo count($itemsByCategory[$category]); ?></strong> item<?php echo count($itemsByCategory[$category]) !== 1 ? 's' : ''; ?>
                </div>
                <hr class="my-3">
                <div class="roadmap-column-content">
                    <?php if (empty($itemsByCategory[$category])): ?>
                        <div class="notification is-dark" style="margin: 0;">
                            <small>No items in this category</small>
                        </div>
                    <?php else: ?>
                        <?php foreach ($itemsByCategory[$category] as $item): ?>
                            <div class="roadmap-card is-<?php echo strtolower($item['priority']); ?>">
                                <div class="roadmap-card-title">
                                    <?php echo htmlspecialchars($item['title']); ?>
                                </div>
                                <div class="mb-2">
                                    <span class="tag is-small is-<?php echo getSubcategoryColor($item['subcategory']); ?>">
                                        <?php echo htmlspecialchars($item['subcategory']); ?>
                                    </span>
                                    <?php if (!empty($item['website_type'])): ?>
                                        <span class="tag is-small is-info">
                                            <?php echo htmlspecialchars($item['website_type']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="roadmap-card-tags mb-3">
                                    <span class="tag is-small is-<?php echo getPriorityColor($item['priority']); ?>">
                                        <?php echo htmlspecialchars($item['priority']); ?>
                                    </span>
                                </div>
                                <?php if ($item['description']): ?>
                                    <div class="mb-3">
                                        <button class="button is-small is-light is-fullwidth details-btn" data-item-id="<?php echo $item['id']; ?>" data-description="<?php echo htmlspecialchars($item['description']); ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>">
                                            <span class="icon is-small"><i class="fas fa-info-circle"></i></span>
                                            <span>Details</span>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <button type="button" class="button is-small is-primary is-fullwidth add-comment-btn" data-item-id="<?php echo $item['id']; ?>">
                                        <span class="icon is-small"><i class="fas fa-comment"></i></span>
                                        <span>Add Comment</span>
                                    </button>
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="button is-small is-warning is-fullwidth edit-item-btn" data-item-id="<?php echo $item['id']; ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>" data-description="<?php echo htmlspecialchars($item['description']); ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>" data-subcategory="<?php echo htmlspecialchars($item['subcategory']); ?>" data-priority="<?php echo htmlspecialchars($item['priority']); ?>" data-website-type="<?php echo htmlspecialchars($item['website_type'] ?? ''); ?>">
                                        <span class="icon is-small"><i class="fas fa-edit"></i></span>
                                        <span>Edit</span>
                                    </button>
                                </div>
                                <div class="buttons are-small" style="gap: 0.25rem;">
                                    <form method="POST" action="" style="display:inline;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="category" value="IN PROGRESS">
                                        <button type="submit" class="button is-info is-small is-fullwidth" title="Move to In Progress">
                                            <span class="icon is-small"><i class="fas fa-play"></i></span>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display:inline;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" class="button is-success is-small is-fullwidth" title="Mark as Completed">
                                            <span class="icon is-small"><i class="fas fa-check"></i></span>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="button is-danger is-small is-fullwidth" onclick="return confirm('Are you sure?')" title="Delete">
                                            <span class="icon is-small"><i class="fas fa-trash"></i></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
function getCategoryIcon($category) {
    $icons = [
        'REQUESTS' => 'lightbulb',
        'IN PROGRESS' => 'spinner',
        'BETA TESTING' => 'flask',
        'COMPLETED' => 'check-circle',
        'REJECTED' => 'times-circle'
    ];
    return $icons[$category] ?? 'folder';
}

function getCategoryColor($category) {
    $colors = [
        'REQUESTS' => 'info',
        'IN PROGRESS' => 'warning',
        'BETA TESTING' => 'primary',
        'COMPLETED' => 'success',
        'REJECTED' => 'danger'
    ];
    return $colors[$category] ?? 'light';
}

function getPriorityColor($priority) {
    $colors = [
        'LOW' => 'success',
        'MEDIUM' => 'info',
        'HIGH' => 'warning',
        'CRITICAL' => 'danger'
    ];
    return $colors[$priority] ?? 'light';
}

function getSubcategoryColor($subcategory) {
    $colors = [
        'TWITCH BOT' => 'primary',
        'DISCORD BOT' => 'info',
        'WEBSOCKET SERVER' => 'success',
        'API SERVER' => 'warning',
        'WEBSITE' => 'danger'
    ];
    return $colors[$subcategory] ?? 'light';
}

$pageContent = ob_get_clean();
require_once '../layout.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const categorySelect = document.getElementById('category-select');
    const prioritySelect = document.getElementById('priority-select');
    const subcategorySelect = document.querySelector('select[name="subcategory"]');
    const websiteTypeField = document.getElementById('website-type-field');
    if (categorySelect && prioritySelect) {
        categorySelect.addEventListener('change', function() {
            if (this.value === 'REQUESTS') {
                prioritySelect.value = 'LOW';
            }
        });
    }
    if (subcategorySelect && websiteTypeField) {
        function toggleWebsiteType() {
            if (subcategorySelect.value === 'WEBSITE') {
                websiteTypeField.style.display = 'block';
            } else {
                websiteTypeField.style.display = 'none';
            }
        }
        subcategorySelect.addEventListener('change', toggleWebsiteType);
        toggleWebsiteType(); // Run on page load
    }
    // Close notification when delete button is clicked
    (document.querySelectorAll('.notification .delete') || []).forEach(($delete) => {
        const $notification = $delete.parentNode;
        $delete.addEventListener('click', () => {
            $notification.parentNode.removeChild($notification);
        });
    });
    // Add Comment button handler
    document.querySelectorAll('.add-comment-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const itemId = this.getAttribute('data-item-id');
            const addCommentModal = document.getElementById('addCommentModal');
            const commentItemId = document.getElementById('commentItemId');
            const commentTextarea = document.getElementById('commentTextarea');
            if (addCommentModal && commentItemId) {
                commentItemId.value = itemId;
                commentTextarea.value = '';
                addCommentModal.classList.add('is-active');
            }
        });
    });
});
</script>
