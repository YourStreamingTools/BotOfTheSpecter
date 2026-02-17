<?php
session_start();
date_default_timezone_set('Australia/Sydney');

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
        // CSRF check for add
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
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
                    $newItemId = $conn->insert_id;
                    $message = 'Roadmap item added successfully!';
                    $message_type = 'success';
                    // Handle file uploads if any
                    if (isset($_FILES['initial_attachments']) && !empty($_FILES['initial_attachments']['name'][0])) {
                        $uploadDir = '../uploads/attachments/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
                        $allowedDocTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                        $allowedFileTypes = array_merge($allowedImageTypes, $allowedDocTypes);
                        $maxFileSize = 10 * 1024 * 1024;
                        $uploadedCount = 0;
                        $uploadErrors = [];

                        // SVG sanitizer helper (top-level alternative recommended)
                        function sanitize_svg_content($svg) {
                            if (!is_string($svg) || trim($svg) === '') return '';
                            $svg = preg_replace('/<\?xml.*?\?>/s', '', $svg);
                            $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg);
                            $svg = preg_replace('/<script.*?>.*?<\/script>/is', '', $svg);
                            $svg = preg_replace('/<\/?(foreignObject|iframe|object|embed|link)[^>]*>/is', '', $svg);
                            $svg = preg_replace('/\s(on[a-z]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);
                            $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')?javascript:[^"\'\s>]+("|\')?/i', '$1="#"', $svg);
                            $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')?(https?:|file:)[^"\'\s>]+("|\')?/i', '$1="#"', $svg);
                            return trim($svg);
                        }

                        for ($i = 0; $i < count($_FILES['initial_attachments']['name']); $i++) {
                            if ($_FILES['initial_attachments']['error'][$i] === UPLOAD_ERR_OK) {
                                $fileName = $_FILES['initial_attachments']['name'][$i];
                                $fileTmp = $_FILES['initial_attachments']['tmp_name'][$i];
                                $fileSize = $_FILES['initial_attachments']['size'][$i];
                                // Validate file size
                                if ($fileSize > $maxFileSize) {
                                    $uploadErrors[] = "$fileName exceeds 10MB limit";
                                    continue;
                                }
                                // Validate file type
                                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                $mimeType = finfo_file($finfo, $fileTmp);
                                finfo_close($finfo);
                                if (!in_array($mimeType, $allowedFileTypes)) {
                                    $uploadErrors[] = "$fileName has unsupported file type";
                                    continue;
                                }
                                // Determine if it's an image
                                $isImage = in_array($mimeType, $allowedImageTypes) ? 1 : 0;
                                // Generate unique filename
                                $uniqueName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $fileName);
                                $filePath = $uploadDir . $uniqueName;
                                // Handle SVG sanitization or move uploaded file
                                if ($mimeType === 'image/svg+xml') {
                                    $svgContent = file_get_contents($fileTmp);
                                    $sanitized = sanitize_svg_content($svgContent);
                                    if (empty($sanitized)) {
                                        $uploadErrors[] = "$fileName failed SVG sanitization";
                                        continue;
                                    }
                                    if (file_put_contents($filePath, $sanitized) === false) {
                                        $uploadErrors[] = "Failed to save sanitized $fileName";
                                        continue;
                                    }
                                } else {
                                    if (!move_uploaded_file($fileTmp, $filePath)) {
                                        $uploadErrors[] = "Failed to save $fileName";
                                        continue;
                                    }
                                }
                                // Store in database
                                $stmt2 = $conn->prepare("INSERT INTO roadmap_attachments (item_id, file_name, file_path, file_type, file_size, is_image, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                if ($stmt2) {
                                    $relativeFilePath = str_replace('\\', '/', $filePath);
                                    // types: item_id (i), file_name (s), file_path (s), file_type (s), file_size (i), is_image (i), uploaded_by (s)
                                    $stmt2->bind_param("isssiis", $newItemId, $fileName, $relativeFilePath, $mimeType, $fileSize, $isImage, $_SESSION['username']);
                                    if ($stmt2->execute()) {
                                        $uploadedCount++;
                                    } else {
                                        $uploadErrors[] = "Database error for $fileName";
                                    }
                                    $stmt2->close();
                                } else {
                                    $uploadErrors[] = "Error saving $fileName to database";
                                }
                            }
                        }
                        if ($uploadedCount > 0) {
                            $message .= " ($uploadedCount file(s) uploaded)";
                        }
                        if (!empty($uploadErrors)) {
                            $message .= " - Some files failed: " . implode(", ", $uploadErrors);
                            $message_type = 'warning';
                        }
                    }
                } else {
                    $message = 'Error adding item: ' . $stmt->error;
                    $message_type = 'danger';
                }
                $stmt->close();
            } else {
                $message = 'Error preparing statement: ' . $conn->error;
                $message_type = 'danger';
            }
        }
    } elseif ($_POST['action'] === 'update') {
        // CSRF check for update
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
            $id = $_POST['id'] ?? 0;
            $category = $_POST['category'] ?? 'REQUESTS';
            $status = $_POST['status'] ?? '';
            if ($status === 'completed') {
                $stmt = $conn->prepare("UPDATE roadmap_items SET category = 'COMPLETED', completed_date = NOW(), updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("UPDATE roadmap_items SET category = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $category, $id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $message = 'Item updated successfully!';
            $message_type = 'success';
        }
    } elseif ($_POST['action'] === 'edit_item') {
        // CSRF check for edit
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
            $id = $_POST['id'] ?? 0;
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $category = $_POST['category'] ?? 'REQUESTS';
            $subcategory = $_POST['subcategory'] ?? 'TWITCH BOT';
            $priority = $_POST['priority'] ?? 'MEDIUM';
            $website_type = (!empty($_POST['website_type']) ? $_POST['website_type'] : null);
            $stmt = $conn->prepare("UPDATE roadmap_items SET title = ?, description = ?, category = ?, subcategory = ?, priority = ?, website_type = ?, updated_at = NOW() WHERE id = ?");
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
        }
    } elseif ($_POST['action'] === 'delete') {
        // CSRF check for delete
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
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
        }
    } elseif ($_POST['action'] === 'add_comment') {
        // CSRF check for comments
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
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
}

// Get all categories and items
$categories = array('REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED');
$subcategories = array('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER');

// Get search and filter parameters
$searchQuery = $_GET['search'] ?? '';
$selectedCategory = $_GET['category'] ?? '';

// Get all items
$allItems = [];
$query = "SELECT * FROM roadmap_items WHERE 1=1";

// Add search filter
if (!empty($searchQuery)) {
    $query .= " AND title LIKE '%" . $conn->real_escape_string($searchQuery) . "%'";
}

// Add category filter
if (!empty($selectedCategory) && in_array($selectedCategory, $categories)) {
    $query .= " AND category = '" . $conn->real_escape_string($selectedCategory) . "'";
}

$query .= " ORDER BY updated_at DESC, created_at DESC, priority DESC";

if ($result = $conn->query($query)) {
    while ($row = $result->fetch_assoc()) {
        $allItems[] = $row;
    }
    $result->free();
}

// Group items by category (only if no category filter is applied)
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
<!-- Search and Filter Section -->
<div class="box mb-6">
    <h2 class="title is-5 mb-4">
        <span class="icon-text">
            <span class="icon"><i class="fas fa-search"></i></span>
            <span>Search & Filter</span>
        </span>
    </h2>
    <form method="GET" action="">
        <div class="columns">
            <div class="column is-two-thirds">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <input class="input" type="text" name="search" placeholder="Search roadmap items by title..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <div class="control">
                        <button type="submit" class="button is-info">
                            <span class="icon"><i class="fas fa-search"></i></span>
                            <span>Search</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="column is-one-third">
                <div class="field">
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="category" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $selectedCategory === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!empty($searchQuery) || !empty($selectedCategory)): ?>
            <div class="field">
                <a href="index.php" class="button is-light is-small">
                    <span class="icon"><i class="fas fa-times"></i></span>
                    <span>Clear Filters</span>
                </a>
            </div>
        <?php endif; ?>
    </form>
</div>
<!-- Add New Item Form -->
<div class="box mb-6">
    <h2 class="title is-4 mb-5">
        <span class="icon-text">
            <span class="icon"><i class="fas fa-plus"></i></span>
            <span>Add New Roadmap Item</span>
        </span>
    </h2>
    <form method="POST" action="" id="addItemForm" enctype="multipart/form-data">
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
                        <textarea class="textarea" name="description" placeholder="Item description (optional, supports markdown)" rows="8" style="font-family: 'Courier New', monospace; font-size: 0.9rem;"></textarea>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Attachments (Optional)</label>
                    <div class="file is-boxed" style="padding: 0.75rem; border: 2px dashed rgba(102, 126, 234, 0.3); text-align: center;" id="dragDropZone">
                        <label class="file-label">
                            <input class="file-input" type="file" name="initial_attachments[]" id="initialAttachments" multiple accept="image/*,.pdf,.doc,.docx,.txt,.xls,.xlsx">
                            <span class="file-cta" style="flex-direction: column; gap: 0.25rem; padding: 0; justify-content: center; align-items: center;">
                                <span class="file-icon" style="font-size: 1.25rem;">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </span>
                                <span class="file-label" style="font-size: 0.8rem;">
                                    Choose files or drag here
                                </span>
                            </span>
                            <span class="file-name" id="initialAttachmentFileName" style="font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                                No files selected
                            </span>
                        </label>
                    </div>
                    <p class="help" style="font-size: 0.7rem; margin-top: 0.25rem;">Images, PDF, Word, Excel, TXT (max 10MB each)</p>
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
                                <option value="OTHER">Other</option>
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
            </div>
        </div>
        <div class="is-flex is-justify-content-flex-end mt-3">
            <button type="submit" class="button is-primary">
                <span class="icon-text">
                    <span class="icon"><i class="fas fa-save"></i></span>
                    <span>ADD ITEM</span>
                </span>
            </button>
        </div>
    </form>
</div>
<?php if (!empty($searchQuery) || !empty($selectedCategory)): ?>
    <!-- Filtered Results -->
    <div class="mb-6">
        <div class="box">
            <h2 class="title is-5 mb-4">
                <span class="icon-text">
                    <span class="icon"><i class="fas fa-filter"></i></span>
                    <span>
                        Search Results 
                        <?php if (!empty($searchQuery)): ?>
                            for "<?php echo htmlspecialchars($searchQuery); ?>"
                        <?php endif; ?>
                        <?php if (!empty($selectedCategory)): ?>
                            in <?php echo htmlspecialchars($selectedCategory); ?>
                        <?php endif; ?>
                    </span>
                </span>
            </h2>
            <div class="mb-3">
                <strong><?php echo count($allItems); ?></strong> result<?php echo count($allItems) !== 1 ? 's' : ''; ?> found
            </div>
            <?php if (empty($allItems)): ?>
                <div class="notification is-warning">
                    <p>No roadmap items found matching your search criteria.</p>
                </div>
            <?php else: ?>
                <div class="columns is-multiline">
                    <?php foreach ($allItems as $item): ?>
                        <div class="column is-one-third">
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
                                <div class="mb-3">
                                    <span class="tag is-small is-<?php echo getCategoryColor($item['category']); ?>">
                                        <?php echo htmlspecialchars($item['category']); ?>
                                    </span>
                                    <span class="tag is-small is-<?php echo getPriorityColor($item['priority']); ?>">
                                        <?php echo htmlspecialchars($item['priority']); ?>
                                    </span>
                                </div>
                                <div class="mb-3">
                                    <button class="button is-small is-light is-fullwidth details-btn" data-item-id="<?php echo $item['id']; ?>" data-description="<?php echo htmlspecialchars(base64_encode($item['description']), ENT_QUOTES, 'UTF-8'); ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>">
                                        <span class="icon is-small"><i class="fas fa-info-circle"></i></span>
                                        <span>Details</span>
                                    </button>
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="button is-small is-primary is-fullwidth add-comment-btn" data-item-id="<?php echo $item['id']; ?>">
                                        <span class="icon is-small"><i class="fas fa-comment"></i></span>
                                        <span>Add Comment</span>
                                    </button>
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="button is-small is-warning is-fullwidth edit-item-btn" data-item-id="<?php echo $item['id']; ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>" data-description="<?php echo htmlspecialchars(base64_encode($item['description']), ENT_QUOTES, 'UTF-8'); ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>" data-subcategory="<?php echo htmlspecialchars($item['subcategory']); ?>" data-priority="<?php echo htmlspecialchars($item['priority']); ?>" data-website-type="<?php echo htmlspecialchars($item['website_type'] ?? ''); ?>">
                                        <span class="icon is-small"><i class="fas fa-edit"></i></span>
                                        <span>Edit</span>
                                    </button>
                                </div>
                                <div class="buttons are-small" style="display: flex; gap: 0.25rem; flex-wrap: nowrap;">
                                    <form method="POST" action="" style="display: flex; flex: 1; min-width: 0;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="category" value="IN PROGRESS">
                                        <button type="submit" class="button is-info is-small" style="flex: 1;" title="Move to In Progress">
                                            <span class="icon is-small"><i class="fas fa-play"></i></span>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display: flex; flex: 1; min-width: 0;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" class="button is-success is-small" style="flex: 1;" title="Mark as Completed">
                                            <span class="icon is-small"><i class="fas fa-check"></i></span>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display: flex; flex: 0 0 auto;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="button is-danger is-small" onclick="return confirm('Are you sure?')" title="Delete">
                                            <span class="icon is-small"><i class="fas fa-trash"></i></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
<!-- Category Columns (Default View) -->
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
                                <div class="mb-3">
                                    <button class="button is-small is-light is-fullwidth details-btn" data-item-id="<?php echo $item['id']; ?>" data-description="<?php echo htmlspecialchars(base64_encode($item['description']), ENT_QUOTES, 'UTF-8'); ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>">
                                        <span class="icon is-small"><i class="fas fa-info-circle"></i></span>
                                        <span>Details</span>
                                    </button>
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="button is-small is-primary is-fullwidth add-comment-btn" data-item-id="<?php echo $item['id']; ?>">
                                        <span class="icon is-small"><i class="fas fa-comment"></i></span>
                                        <span>Add Comment</span>
                                    </button>
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="button is-small is-warning is-fullwidth edit-item-btn" data-item-id="<?php echo $item['id']; ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>" data-description="<?php echo htmlspecialchars(base64_encode($item['description']), ENT_QUOTES, 'UTF-8'); ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>" data-subcategory="<?php echo htmlspecialchars($item['subcategory']); ?>" data-priority="<?php echo htmlspecialchars($item['priority']); ?>" data-website-type="<?php echo htmlspecialchars($item['website_type'] ?? ''); ?>">
                                        <span class="icon is-small"><i class="fas fa-edit"></i></span>
                                        <span>Edit</span>
                                    </button>
                                </div>
                                <div class="buttons are-small" style="display: flex; gap: 0.25rem; flex-wrap: nowrap;">
                                    <form method="POST" action="" style="display: flex; flex: 1; min-width: 0;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="category" value="IN PROGRESS">
                                        <button type="submit" class="button is-info is-small" style="flex: 1;" title="Move to In Progress">
                                            <span class="icon is-small"><i class="fas fa-play"></i></span>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display: flex; flex: 1; min-width: 0;">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" class="button is-success is-small" style="flex: 1;" title="Mark as Completed">
                                            <span class="icon is-small"><i class="fas fa-check"></i></span>
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display: flex; flex: 0 0 auto;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="button is-danger is-small" onclick="return confirm('Are you sure?')" title="Delete">
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
<?php endif; ?>
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
        'WEBSITE' => 'danger',
        'OTHER' => 'light'
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
    const initialAttachments = document.getElementById('initialAttachments');
    const initialAttachmentFileName = document.getElementById('initialAttachmentFileName');
    const dragDropZone = document.getElementById('dragDropZone');
    // Handle drag and drop
    if (dragDropZone && initialAttachments) {
        dragDropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dragDropZone.style.backgroundColor = 'rgba(102, 126, 234, 0.1)';
            dragDropZone.style.borderColor = '#667eea';
            dragDropZone.style.borderWidth = '2px';
        });
        dragDropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dragDropZone.style.backgroundColor = '';
            dragDropZone.style.borderColor = 'rgba(102, 126, 234, 0.3)';
            dragDropZone.style.borderWidth = '2px';
        });
        dragDropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dragDropZone.style.backgroundColor = '';
            dragDropZone.style.borderColor = 'rgba(102, 126, 234, 0.3)';
            
            const files = e.dataTransfer.files;
            initialAttachments.files = files;
            
            // Trigger change event
            const event = new Event('change', { bubbles: true });
            initialAttachments.dispatchEvent(event);
        });
    }
    // Handle initial attachments file input
    if (initialAttachments) {
        initialAttachments.addEventListener('change', function() {
            if (this.files.length > 0) {
                initialAttachmentFileName.textContent = `${this.files.length} file(s) selected`;
            } else {
                initialAttachmentFileName.textContent = 'No files selected';
            }
        });
    }
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