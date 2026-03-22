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
            // allow single value or an array for subcategory (treat as tags)
            $allowed_subcategories = array('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER');
            $rawSub = $_POST['subcategory'] ?? 'TWITCH BOT';
            $selected_subcategories = is_array($rawSub) ? $rawSub : array($rawSub);
            // normalize and validate
            $selected_subcategories = array_values(array_unique(array_filter(array_map('trim', $selected_subcategories))));
            $selected_subcategories = array_filter($selected_subcategories, function($v) use ($allowed_subcategories){ return in_array($v, $allowed_subcategories); });
            if (empty($selected_subcategories)) $selected_subcategories = array('TWITCH BOT');
            // primary/legacy column will hold the first selected value
            $primary_subcategory = $selected_subcategories[0];
            $priority = $_POST['priority'] ?? 'MEDIUM';
            // website_type: accept single or array, validate against allowed set
            $allowed_website_types = array('DASHBOARD', 'OVERLAYS');
            $rawWeb = $_POST['website_type'] ?? null;
            $selected_website_types = [];
            if (is_array($rawWeb)) {
                $selected_website_types = array_values(array_unique(array_filter(array_map('trim', $rawWeb))));
            } elseif (!empty($rawWeb) && is_string($rawWeb)) {
                $selected_website_types = [trim($rawWeb)];
            }
            $selected_website_types = array_filter($selected_website_types, function($v) use ($allowed_website_types){ return in_array($v, $allowed_website_types); });
            $primary_website_type = !empty($selected_website_types) ? $selected_website_types[0] : null;
            $stmt = $conn->prepare("INSERT INTO roadmap_items (title, description, category, subcategory, priority, website_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssssss", $title, $description, $category, $primary_subcategory, $priority, $website_type, $_SESSION['username']);
                if ($stmt->execute()) {
                    $newItemId = $conn->insert_id;
                    // store all selected subcategories in the junction table
                    $insertSubStmt = $conn->prepare("INSERT INTO roadmap_item_subcategories (item_id, subcategory) VALUES (?, ?)");
                    if ($insertSubStmt) {
                        foreach ($selected_subcategories as $subVal) {
                            $insertSubStmt->bind_param("is", $newItemId, $subVal);
                            $insertSubStmt->execute();
                        }
                        $insertSubStmt->close();
                    }

                    // store selected website types in the junction table (if any)
                    if (!empty($selected_website_types)) {
                        $insertWebStmt = $conn->prepare("INSERT INTO roadmap_item_website_types (item_id, website_type) VALUES (?, ?)");
                        if ($insertWebStmt) {
                            foreach ($selected_website_types as $wt) {
                                $insertWebStmt->bind_param("is", $newItemId, $wt);
                                $insertWebStmt->execute();
                            }
                            $insertWebStmt->close();
                        }
                    }

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
            $id = (int)($_POST['id'] ?? 0);
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $category = $_POST['category'] ?? 'REQUESTS';
            // accept single or multiple subcategory values
            $allowed_subcategories = array('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER');
            $rawSub = $_POST['subcategory'] ?? 'TWITCH BOT';
            $selected_subcategories = is_array($rawSub) ? $rawSub : array($rawSub);
            $selected_subcategories = array_values(array_unique(array_filter(array_map('trim', $selected_subcategories))));
            $selected_subcategories = array_filter($selected_subcategories, function($v) use ($allowed_subcategories){ return in_array($v, $allowed_subcategories); });
            if (empty($selected_subcategories)) $selected_subcategories = array('TWITCH BOT');
            $primary_subcategory = $selected_subcategories[0];
            $priority = $_POST['priority'] ?? 'MEDIUM';
            // website_type: accept single or array
            $allowed_website_types = array('DASHBOARD', 'OVERLAYS');
            $rawWeb = $_POST['website_type'] ?? null;
            $selected_website_types = [];
            if (is_array($rawWeb)) {
                $selected_website_types = array_values(array_unique(array_filter(array_map('trim', $rawWeb))));
            } elseif (!empty($rawWeb) && is_string($rawWeb)) {
                $selected_website_types = [trim($rawWeb)];
            }
            $selected_website_types = array_filter($selected_website_types, function($v) use ($allowed_website_types){ return in_array($v, $allowed_website_types); });
            $primary_website_type = !empty($selected_website_types) ? $selected_website_types[0] : null;

            $stmt = $conn->prepare("UPDATE roadmap_items SET title = ?, description = ?, category = ?, subcategory = ?, priority = ?, website_type = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssssssi", $title, $description, $category, $primary_subcategory, $priority, $primary_website_type, $id);
                if ($stmt->execute()) {
                    // update junction table: delete existing and insert new selections
                    $del = $conn->prepare("DELETE FROM roadmap_item_subcategories WHERE item_id = ?");
                    if ($del) {
                        $del->bind_param("i", $id);
                        $del->execute();
                        $del->close();
                    }
                    $insertSubStmt = $conn->prepare("INSERT INTO roadmap_item_subcategories (item_id, subcategory) VALUES (?, ?)");
                    if ($insertSubStmt) {
                        foreach ($selected_subcategories as $subVal) {
                            $insertSubStmt->bind_param("is", $id, $subVal);
                            $insertSubStmt->execute();
                        }
                        $insertSubStmt->close();
                    }

                    // update website types junction table
                    $delWeb = $conn->prepare("DELETE FROM roadmap_item_website_types WHERE item_id = ?");
                    if ($delWeb) {
                        $delWeb->bind_param("i", $id);
                        $delWeb->execute();
                        $delWeb->close();
                    }
                    if (!empty($selected_website_types)) {
                        $insWeb = $conn->prepare("INSERT INTO roadmap_item_website_types (item_id, website_type) VALUES (?, ?)");
                        if ($insWeb) {
                            foreach ($selected_website_types as $wt) {
                                $insWeb->bind_param("is", $id, $wt);
                                $insWeb->execute();
                            }
                            $insWeb->close();
                        }
                    }

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
        // prepare placeholder for multiple subcategories
        $row['subcategories'] = [];
        $allItems[] = $row;
    }
    $result->free();
}

// If we have items, fetch their subcategories in one query and map them back
if (!empty($allItems)) {
    $ids = array_map(function($it){ return (int)$it['id']; }, $allItems);
    $idList = implode(',', $ids);
    $subRes = $conn->query("SELECT item_id, subcategory FROM roadmap_item_subcategories WHERE item_id IN ($idList)");
    $subMap = [];
    if ($subRes) {
        while ($srow = $subRes->fetch_assoc()) {
            $subMap[(int)$srow['item_id']][] = $srow['subcategory'];
        }
        $subRes->free();
    }

    // fetch website types for items
    $webRes = $conn->query("SELECT item_id, website_type FROM roadmap_item_website_types WHERE item_id IN ($idList)");
    $webMap = [];
    if ($webRes) {
        while ($wrow = $webRes->fetch_assoc()) {
            $webMap[(int)$wrow['item_id']][] = $wrow['website_type'];
        }
        $webRes->free();
    }

    // attach arrays back to items; keep legacy single columns as primary values for compatibility
    foreach ($allItems as &$it) {
        $itId = (int)$it['id'];
        if (!empty($subMap[$itId])) {
            $it['subcategories'] = $subMap[$itId];
            $it['subcategory'] = $it['subcategory'] ?: $subMap[$itId][0];
        } else {
            $it['subcategories'] = [$it['subcategory']];
        }
        if (!empty($webMap[$itId])) {
            $it['website_types'] = $webMap[$itId];
            $it['website_type'] = $it['website_type'] ?: $webMap[$itId][0];
        } else {
            $it['website_types'] = $it['website_type'] ? [$it['website_type']] : [];
        }
    }
    unset($it);
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
<!-- Page header -->
<div class="sp-page-header">
    <div>
        <h1 class="sp-page-title">Roadmap Administration</h1>
        <p class="sp-page-subtitle">Manage roadmap items and track development progress</p>
    </div>
    <a href="../index.php" class="sp-btn sp-btn-secondary sp-btn-sm"><i class="fa-solid fa-arrow-left"></i> View Roadmap</a>
</div>
<?php if ($message): ?>
    <div class="sp-alert sp-alert-<?php echo htmlspecialchars($message_type); ?>" style="margin-bottom:1rem;">
        <i class="fa-solid fa-<?php echo $message_type==='success'?'circle-check':($message_type==='danger'?'triangle-exclamation':'circle-info'); ?>"></i>
        <strong><?php echo ucfirst($message_type); ?>:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<!-- Search / Filter -->
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-body">
    <form method="GET" action="">
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:2;min-width:200px;">
                <label class="sp-label">Search</label>
                <div style="display:flex;">
                    <input class="sp-input" style="border-radius:var(--radius) 0 0 var(--radius);" type="text" name="search" placeholder="Search roadmap items..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <button type="submit" class="sp-btn sp-btn-info" style="border-radius:0 var(--radius) var(--radius) 0;white-space:nowrap;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                </div>
            </div>
            <div style="flex:1;min-width:160px;">
                <label class="sp-label">Category</label>
                <select class="sp-select" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $selectedCategory===$cat?'selected':''; ?>><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($searchQuery)||!empty($selectedCategory)): ?>
                <div><a href="index.php" class="sp-btn sp-btn-ghost sp-btn-sm" style="margin-top:1.5rem;"><i class="fa-solid fa-xmark"></i> Clear</a></div>
            <?php endif; ?>
        </div>
    </form>
    </div>
</div>
<!-- Add New Item -->
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-body">
    <h2 style="font-size:1rem;font-weight:600;margin-bottom:1rem;"><i class="fa-solid fa-plus" style="color:var(--accent-hover);margin-right:0.4rem;"></i>Add New Roadmap Item</h2>
    <form method="POST" action="" id="addItemForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add">
        <div class="rm-form-cols">
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <div class="sp-form-group">
                    <label class="sp-label">Title</label>
                    <input class="sp-input" type="text" name="title" placeholder="Item title" required>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label">Description</label>
                    <textarea class="sp-textarea sp-textarea-mono" name="description" placeholder="Item description (optional, supports markdown)" rows="7"></textarea>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label">Attachments (Optional)</label>
                    <label class="rm-upload-zone" id="dragDropZone">
                        <input type="file" name="initial_attachments[]" id="initialAttachments" multiple
                            accept="image/*,.pdf,.doc,.docx,.txt,.xls,.xlsx"
                            style="position:absolute;opacity:0;width:0;height:0;">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span class="rm-upload-label">Click to choose or drag &amp; drop</span>
                        <span class="rm-upload-hint">Images, PDF, Word, Excel, TXT &mdash; max 10MB each</span>
                        <span class="rm-upload-filename" id="initialAttachmentFileName"></span>
                    </label>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <div class="sp-form-group">
                    <label class="sp-label">Category</label>
                    <select class="sp-select" name="category" id="category-select" required>
                        <option value="REQUESTS">Requests</option>
                        <option value="IN PROGRESS">In Progress</option>
                        <option value="BETA TESTING">Beta Testing</option>
                        <option value="COMPLETED">Completed</option>
                        <option value="REJECTED">Rejected</option>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label">Subcategory</label>
                    <div class="tag-multiselect" id="addItemSubcategory" data-name="subcategory[]" data-initial='["TWITCH BOT"]'></div>
                    <div class="sp-field-hint">Only predefined tags allowed.</div>
                </div>
                <div class="sp-form-group" id="website-type-field" style="display:none;">
                    <label class="sp-label">Website Type</label>
                    <div class="tag-multiselect" id="addItemWebsiteType" data-name="website_type[]" data-allowed='["DASHBOARD","OVERLAYS"]'></div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label">Priority</label>
                    <select class="sp-select" name="priority" id="priority-select">
                        <option value="LOW">Low</option>
                        <option value="MEDIUM" selected>Medium</option>
                        <option value="HIGH">High</option>
                        <option value="CRITICAL">Critical</option>
                    </select>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:auto;">
                    <button type="submit" class="sp-btn sp-btn-primary"><i class="fa-solid fa-floppy-disk"></i> ADD ITEM</button>
                </div>
            </div>
        </div>
    </form>
    </div>
</div>
<?php
function renderAdminCard($item): string {
    $pri    = strtolower($item['priority']);
    $b64    = htmlspecialchars(base64_encode($item['description']), ENT_QUOTES, 'UTF-8');
    $subArr = (!empty($item['subcategories'])&&is_array($item['subcategories']))?$item['subcategories']:(!empty($item['subcategory'])?[$item['subcategory']]:[]);
    $webArr = (!empty($item['website_types'])&&is_array($item['website_types']))?$item['website_types']:(!empty($item['website_type'])?[$item['website_type']]:[]);
    $subJson = htmlspecialchars(json_encode($subArr), ENT_QUOTES, 'UTF-8');
    $webJson = htmlspecialchars(json_encode($webArr), ENT_QUOTES, 'UTF-8');
    $dtc = new DateTime($item['created_at']??'now');
    $out = '<div class="rm-card rm-card-'.$pri.'">';
    $out .= '<div class="rm-card-title">'.htmlspecialchars($item['title']).'</div>';
    $out .= '<div class="rm-card-meta">'.htmlspecialchars($dtc->format('M j, Y')).'</div>';
    $out .= '<div class="rm-card-tags">';
    foreach ($subArr as $sub) $out .= '<span class="rm-tag rm-tag-'.getSubcategoryColor($sub).'">'.htmlspecialchars($sub).'</span>';
    foreach ($webArr as $wt) $out .= '<span class="rm-tag rm-tag-info website-type-tag">'.htmlspecialchars($wt).'</span>';
    $out .= '<span class="rm-tag rm-tag-'.getCategoryColor($item['category']).'">'.htmlspecialchars($item['category']).'</span>';
    $out .= '<span class="rm-tag rm-tag-'.getPriorityColor($item['priority']).'">'.htmlspecialchars($item['priority']).'</span>';
    $out .= '</div>';
    $out .= '<button class="sp-btn sp-btn-secondary sp-btn-sm sp-btn-full details-btn" style="margin-bottom:0.4rem;"'
        .' data-item-id="'.$item['id'].'" data-description="'.$b64.'"'
        .' data-title="'.htmlspecialchars($item['title']).'"'
        .' data-created-at="'.htmlspecialchars($item['created_at']??'').'"'
        .' data-updated-at="'.htmlspecialchars($item['updated_at']??'').'"'
        .' data-category="'.htmlspecialchars($item['category']).'"'
        .' data-priority="'.htmlspecialchars($item['priority']).'"'
        .' data-subcategories="'.$subJson.'" data-website-types="'.$webJson.'">'
        .'<i class="fa-solid fa-circle-info"></i> Details</button>';
    $out .= '<button type="button" class="sp-btn sp-btn-warning sp-btn-sm sp-btn-full edit-item-btn" style="margin-bottom:0.4rem;"'
        .' data-item-id="'.$item['id'].'" data-title="'.htmlspecialchars($item['title']).'"'
        .' data-description="'.$b64.'" data-category="'.htmlspecialchars($item['category']).'"'
        .' data-subcategory="'.$subJson.'" data-priority="'.htmlspecialchars($item['priority']).'"'
        .' data-website-type="'.htmlspecialchars($item['website_type']??'').'">'
        .'<i class="fa-solid fa-pen"></i> Edit</button>';
    $cat = $item['category']??'';
    $out .= '<div style="display:flex;gap:0.3rem;margin-top:0.2rem;">';
    if ($cat==='REQUESTS')
        $out .= '<form method="POST" style="flex:1;"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.$item['id'].'"><input type="hidden" name="category" value="IN PROGRESS"><button type="submit" class="sp-btn sp-btn-info sp-btn-sm sp-btn-icon sp-btn-full" title="Move to In Progress"><i class="fa-solid fa-play"></i></button></form>';
    elseif ($cat==='IN PROGRESS')
        $out .= '<form method="POST" style="flex:1;"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.$item['id'].'"><input type="hidden" name="category" value="BETA TESTING"><button type="submit" class="sp-btn sp-btn-primary sp-btn-sm sp-btn-icon sp-btn-full" title="Move to Beta Testing"><i class="fa-solid fa-flask"></i></button></form>';
    elseif ($cat==='BETA TESTING')
        $out .= '<form method="POST" style="flex:1;"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.$item['id'].'"><input type="hidden" name="status" value="completed"><button type="submit" class="sp-btn sp-btn-success sp-btn-sm sp-btn-icon sp-btn-full" title="Mark Completed"><i class="fa-solid fa-check"></i></button></form>';
    $out .= '<form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.$item['id'].'"><button type="submit" class="sp-btn sp-btn-danger sp-btn-sm sp-btn-icon" onclick="return confirm(\'Are you sure?\')" title="Delete"><i class="fa-solid fa-trash-can"></i></button></form>';
    $out .= '</div></div>';
    return $out;
}
if (!empty($searchQuery) || !empty($selectedCategory)): ?>
<!-- Filtered Results -->
<div class="sp-card">
    <div class="sp-card-body">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
        <h2 style="font-size:1rem;font-weight:600;">
            <i class="fa-solid fa-filter" style="color:var(--accent-hover);margin-right:0.4rem;"></i>
            Search Results
            <?php if (!empty($searchQuery)): ?>for &ldquo;<?php echo htmlspecialchars($searchQuery); ?>&rdquo;<?php endif; ?>
            <?php if (!empty($selectedCategory)): ?>in <?php echo htmlspecialchars($selectedCategory); ?><?php endif; ?>
        </h2>
        <span style="font-size:0.875rem;color:var(--text-muted);">
            <strong style="color:var(--text-primary);"><?php echo count($allItems); ?></strong>
            result<?php echo count($allItems)!==1?'s':''; ?> found
        </span>
    </div>
    <?php if (empty($allItems)): ?>
        <div class="sp-alert sp-alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> No roadmap items found matching your search criteria.</div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:0.75rem;">
            <?php foreach ($allItems as $item): echo renderAdminCard($item); endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php else: ?>
<!-- Kanban Board -->
<div style="display:flex;justify-content:flex-end;margin-bottom:0.75rem;">
    <button id="toggleRejectedBtn" class="sp-btn sp-btn-ghost sp-btn-sm" onclick="toggleRejected()">
        <i class="fa-solid fa-eye"></i> Show Rejected
    </button>
</div>
<div class="rm-board">
    <?php foreach ($categories as $category): ?>
        <div class="rm-column" data-category="<?php echo htmlspecialchars($category); ?>"<?php echo $category==='REJECTED'?' style="display:none;"':''; ?>>
            <div class="rm-column-head">
                <span><i class="fa-solid fa-<?php echo getCategoryIcon($category); ?>" style="margin-right:0.4rem;"></i><?php echo htmlspecialchars($category); ?></span>
                <span class="sp-badge"><?php echo count($itemsByCategory[$category]); ?></span>
            </div>
            <div class="rm-column-body">
                <?php if (empty($itemsByCategory[$category])): ?>
                    <div class="rm-empty-state">No items</div>
                <?php else: ?>
                    <?php foreach ($itemsByCategory[$category] as $item): echo renderAdminCard($item); endforeach; ?>
                <?php endif; ?>
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
        'COMPLETED' => 'circle-check',
        'REJECTED' => 'circle-xmark'
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
function toggleRejected() {
    var col = document.querySelector('.rm-column[data-category="REJECTED"]');
    var btn = document.getElementById('toggleRejectedBtn');
    if (!col) return;
    var visible = col.style.display !== 'none';
    col.style.display = visible ? 'none' : '';
    btn.innerHTML = visible
        ? '<i class="fa-solid fa-eye"></i> Show Rejected'
        : '<i class="fa-solid fa-eye-slash"></i> Hide Rejected';
}
document.addEventListener('DOMContentLoaded', function() {
    const categorySelect         = document.getElementById('category-select');
    const prioritySelect         = document.getElementById('priority-select');
    const addTagEl               = document.getElementById('addItemSubcategory');
    const websiteTypeField       = document.getElementById('website-type-field');
    const initialAttachments     = document.getElementById('initialAttachments');
    const initialAttachmentFileName = document.getElementById('initialAttachmentFileName');
    const dragDropZone           = document.getElementById('dragDropZone');

    if (dragDropZone && initialAttachments) {
        dragDropZone.addEventListener('dragover', (e) => {
            e.preventDefault(); e.stopPropagation();
            dragDropZone.style.borderColor = 'var(--accent)';
            dragDropZone.style.backgroundColor = 'rgba(99,102,241,0.08)';
        });
        dragDropZone.addEventListener('dragleave', (e) => {
            e.preventDefault(); e.stopPropagation();
            dragDropZone.style.borderColor = '';
            dragDropZone.style.backgroundColor = '';
        });
        dragDropZone.addEventListener('drop', (e) => {
            e.preventDefault(); e.stopPropagation();
            dragDropZone.style.borderColor = '';
            dragDropZone.style.backgroundColor = '';
            initialAttachments.files = e.dataTransfer.files;
            initialAttachments.dispatchEvent(new Event('change', {bubbles:true}));
        });
    }
    if (initialAttachments) {
        initialAttachments.addEventListener('change', function() {
            if (initialAttachmentFileName)
                initialAttachmentFileName.textContent = this.files.length > 0 ? this.files.length + ' file(s) selected' : '';
        });
    }
    const addItemForm = document.getElementById('addItemForm');
    if (addItemForm) {
        addItemForm.addEventListener('submit', function(e) {
            const count = document.querySelectorAll('#addItemForm input[name="subcategory[]"]').length;
            if (count === 0) { alert('Please select at least one Subcategory'); e.preventDefault(); }
        });
    }
    if (categorySelect && prioritySelect) {
        categorySelect.addEventListener('change', function() {
            if (this.value === 'REQUESTS') prioritySelect.value = 'LOW';
        });
    }
    if (addTagEl && websiteTypeField) {
        function toggleWebsiteTypeAdd() {
            const values = addTagEl._tms ? addTagEl._tms.getValues()
                : Array.from(document.querySelectorAll('#addItemForm input[name="subcategory[]"]')).map(i=>i.value);
            websiteTypeField.style.display = values.includes('WEBSITE') ? '' : 'none';
        }
        addTagEl.addEventListener('tms:change', toggleWebsiteTypeAdd);
        setTimeout(toggleWebsiteTypeAdd, 0);
    }
});
</script>
