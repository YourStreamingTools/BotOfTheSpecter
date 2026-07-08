<?php
require_once dirname(__DIR__) . '/includes/session.php';
roadmap_session_start();
date_default_timezone_set('Australia/Sydney');

if (!roadmap_is_logged_in() || !roadmap_is_admin()) {
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

// Load available subcategories from DB (used by form handlers and UI)
$subcatRows = getAvailableSubcategories($conn);
$subcategories = array_map(function($r){ return $r['name']; }, $subcatRows);
$subcatColorMap = [];
foreach ($subcatRows as $r) { $subcatColorMap[$r['name']] = $r['color']; }

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
            $allowed_subcategories = $subcategories ?: array('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER');
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
                $stmt->bind_param("sssssss", $title, $description, $category, $primary_subcategory, $priority, $primary_website_type, $_SESSION['username']);
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
            $allowed_subcategories = $subcategories ?: array('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER');
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
    } elseif ($_POST['action'] === 'remove_subcategory') {
        // CSRF check
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
            $subName = trim($_POST['subcategory_name'] ?? '');
            if (!empty($subName)) {
                // Remove from the available subcategories table
                $stmt = $conn->prepare("DELETE FROM roadmap_subcategories WHERE name = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $subName);
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        // Remove this subcategory from all items in the junction table
                        $del = $conn->prepare("DELETE FROM roadmap_item_subcategories WHERE subcategory = ?");
                        if ($del) { $del->bind_param("s", $subName); $del->execute(); $del->close(); }
                        $message = 'Subcategory "' . $subName . '" removed successfully!';
                        $message_type = 'success';
                        // Refresh the subcategory list
                        $subcatRows = getAvailableSubcategories($conn);
                        $subcategories = array_map(function($r){ return $r['name']; }, $subcatRows);
                        $subcatColorMap = [];
                        foreach ($subcatRows as $r) { $subcatColorMap[$r['name']] = $r['color']; }
                    } else {
                        $message = 'Subcategory not found.';
                        $message_type = 'warning';
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($_POST['action'] === 'add_subcategory') {
        // CSRF check
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $message = 'Invalid CSRF token';
            $message_type = 'danger';
        } else {
            $subName = strtoupper(trim($_POST['subcategory_name'] ?? ''));
            $subColor = trim($_POST['subcategory_color'] ?? 'light');
            $allowedColors = ['primary', 'info', 'success', 'warning', 'danger', 'light'];
            if (!in_array($subColor, $allowedColors)) $subColor = 'light';
            if (!empty($subName)) {
                $maxOrder = 0;
                $res = $conn->query("SELECT MAX(sort_order) AS mx FROM roadmap_subcategories");
                if ($res) { $row = $res->fetch_assoc(); $maxOrder = (int)($row['mx'] ?? 0); $res->free(); }
                $newOrder = $maxOrder + 1;
                $stmt = $conn->prepare("INSERT INTO roadmap_subcategories (name, color, sort_order) VALUES (?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssi", $subName, $subColor, $newOrder);
                    if ($stmt->execute()) {
                        $message = 'Subcategory "' . $subName . '" added successfully!';
                        $message_type = 'success';
                        // Refresh
                        $subcatRows = getAvailableSubcategories($conn);
                        $subcategories = array_map(function($r){ return $r['name']; }, $subcatRows);
                        $subcatColorMap = [];
                        foreach ($subcatRows as $r) { $subcatColorMap[$r['name']] = $r['color']; }
                    } else {
                        if ($conn->errno === 1062) {
                            $message = 'Subcategory "' . $subName . '" already exists.';
                        } else {
                            $message = 'Error adding subcategory: ' . $stmt->error;
                        }
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

// Global counts for the admin overview (unaffected by active filters)
$statsCounts = array_fill_keys($categories, 0);
$statsRes = $conn->query("SELECT category, COUNT(*) AS cnt FROM roadmap_items GROUP BY category");
if ($statsRes) {
    while ($statsRow = $statsRes->fetch_assoc()) {
        $catKey = $statsRow['category'] ?? '';
        if (isset($statsCounts[$catKey])) {
            $statsCounts[$catKey] = (int)$statsRow['cnt'];
        }
    }
    $statsRes->free();
}
$statsTotal = array_sum($statsCounts);

// Get search and filter parameters
$searchQuery = $_GET['search'] ?? '';
$selectedCategory = $_GET['category'] ?? '';
$selectedPriority = $_GET['priority'] ?? '';
$allowedPriorities = array('LOW', 'MEDIUM', 'HIGH', 'CRITICAL');

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

// Add priority filter
if (!empty($selectedPriority) && in_array($selectedPriority, $allowedPriorities, true)) {
    $query .= " AND priority = '" . $conn->real_escape_string($selectedPriority) . "'";
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
    global $subcatColorMap;
    if (!empty($subcatColorMap[$subcategory])) return $subcatColorMap[$subcategory];
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

function renderAdminNextAction($item): string {
    $cat = $item['category'] ?? '';
    $id = (int)$item['id'];
    $actions = array(
        'REQUESTS' => array('label' => 'Start Work', 'icon' => 'play', 'btn' => 'sp-btn-info', 'category' => 'IN PROGRESS'),
        'IN PROGRESS' => array('label' => 'Send to Beta', 'icon' => 'flask', 'btn' => 'sp-btn-primary', 'category' => 'BETA TESTING'),
        'BETA TESTING' => array('label' => 'Complete', 'icon' => 'check', 'btn' => 'sp-btn-success', 'category' => '', 'status' => 'completed'),
        'REJECTED' => array('label' => 'Restore', 'icon' => 'rotate-left', 'btn' => 'sp-btn-warning', 'category' => 'REQUESTS'),
    );
    if (!isset($actions[$cat])) {
        return '';
    }
    $act = $actions[$cat];
    $out = '<form method="POST" class="rm-admin-action-form">';
    $out .= '<input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . $id . '">';
    if (!empty($act['status']) && $act['status'] === 'completed') {
        $out .= '<input type="hidden" name="status" value="completed">';
    } else {
        $out .= '<input type="hidden" name="category" value="' . htmlspecialchars($act['category']) . '">';
    }
    $out .= '<button type="submit" class="sp-btn ' . $act['btn'] . ' sp-btn-sm sp-btn-full">';
    $out .= '<i class="fa-solid fa-' . $act['icon'] . '"></i> ' . htmlspecialchars($act['label']);
    $out .= '</button></form>';
    return $out;
}

function renderAdminCard($item, array $categories): string {
    $pri    = strtolower($item['priority']);
    $b64    = htmlspecialchars(base64_encode($item['description']), ENT_QUOTES, 'UTF-8');
    $subArr = (!empty($item['subcategories'])&&is_array($item['subcategories']))?$item['subcategories']:(!empty($item['subcategory'])?[$item['subcategory']]:[]);
    $webArr = (!empty($item['website_types'])&&is_array($item['website_types']))?$item['website_types']:(!empty($item['website_type'])?[$item['website_type']]:[]);
    $subJson = htmlspecialchars(json_encode($subArr), ENT_QUOTES, 'UTF-8');
    $webJson = htmlspecialchars(json_encode($webArr), ENT_QUOTES, 'UTF-8');
    $created = new DateTime($item['created_at'] ?? 'now');
    $updated = !empty($item['updated_at']) ? new DateTime($item['updated_at']) : null;
    $cat = $item['category'] ?? '';
    $out = '<div class="rm-card rm-admin-card rm-card-'.$pri.'">';
    $out .= '<div class="rm-card-title">'.htmlspecialchars($item['title']).'</div>';
    $out .= '<div class="rm-card-meta">';
    $out .= 'Created ' . htmlspecialchars($created->format('M j, Y'));
    if ($updated) {
        $out .= ' &middot; Updated ' . htmlspecialchars($updated->format('M j, Y'));
    }
    $out .= '</div>';
    $out .= '<div class="rm-card-tags">';
    foreach ($subArr as $sub) $out .= '<span class="rm-tag rm-tag-'.getSubcategoryColor($sub).'">'.htmlspecialchars($sub).'</span>';
    foreach ($webArr as $wt) $out .= '<span class="rm-tag rm-tag-info website-type-tag">'.htmlspecialchars($wt).'</span>';
    $out .= '<span class="rm-tag rm-tag-'.getPriorityColor($item['priority']).'">'.htmlspecialchars($item['priority']).'</span>';
    $out .= '</div>';

    $nextAction = renderAdminNextAction($item);
    if ($nextAction !== '') {
        $out .= '<div class="rm-admin-primary-action">' . $nextAction . '</div>';
    }

    $out .= '<div class="rm-admin-card-actions">';
    $out .= '<button type="button" class="sp-btn sp-btn-secondary sp-btn-sm edit-item-btn"'
        .' data-item-id="'.$item['id'].'" data-title="'.htmlspecialchars($item['title']).'"'
        .' data-description="'.$b64.'" data-category="'.htmlspecialchars($item['category']).'"'
        .' data-subcategory="'.$subJson.'" data-priority="'.htmlspecialchars($item['priority']).'"'
        .' data-website-type="'.htmlspecialchars($item['website_type']??'').'">'
        .'<i class="fa-solid fa-pen"></i> Edit</button>';
    $out .= '<button type="button" class="sp-btn sp-btn-ghost sp-btn-sm details-btn"'
        .' data-item-id="'.$item['id'].'" data-description="'.$b64.'"'
        .' data-title="'.htmlspecialchars($item['title']).'"'
        .' data-created-at="'.htmlspecialchars($item['created_at']??'').'"'
        .' data-updated-at="'.htmlspecialchars($item['updated_at']??'').'"'
        .' data-category="'.htmlspecialchars($item['category']).'"'
        .' data-priority="'.htmlspecialchars($item['priority']).'"'
        .' data-subcategories="'.$subJson.'" data-website-types="'.$webJson.'">'
        .'<i class="fa-solid fa-circle-info"></i> Details</button>';
    $out .= '</div>';

    $out .= '<form method="POST" class="rm-admin-move-form">';
    $out .= '<input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.(int)$item['id'].'">';
    $out .= '<label class="sp-label rm-admin-move-label">Move to</label>';
    $out .= '<select class="sp-select sp-select-sm rm-admin-move-select" onchange="rmAdminMoveItem(this)">';
    $out .= '<option value="">Choose stage&hellip;</option>';
    foreach ($categories as $moveCat) {
        if ($moveCat === $cat) continue;
        $out .= '<option value="'.htmlspecialchars($moveCat).'">'.htmlspecialchars($moveCat).'</option>';
    }
    if ($cat !== 'COMPLETED') {
        $out .= '<option value="__completed__">Mark completed</option>';
    }
    $out .= '</select></form>';

    $out .= '<div class="rm-admin-card-footer">';
    if ($cat !== 'REJECTED' && $cat !== 'COMPLETED') {
        $out .= '<form method="POST" class="rm-admin-action-form">';
        $out .= '<input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.(int)$item['id'].'">';
        $out .= '<input type="hidden" name="category" value="REJECTED">';
        $out .= '<button type="submit" class="sp-btn sp-btn-ghost sp-btn-sm" onclick="return confirm(\'Reject this item?\')">';
        $out .= '<i class="fa-solid fa-ban"></i> Reject</button></form>';
    }
    $out .= '<form method="POST" class="rm-admin-action-form rm-admin-delete-form">';
    $out .= '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.(int)$item['id'].'">';
    $out .= '<button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" onclick="return confirm(\'Permanently delete this item?\')">';
    $out .= '<i class="fa-solid fa-trash-can"></i> Delete</button></form>';
    $out .= '</div></div>';
    return $out;
}

$openAddItemPanel = ($message_type === 'success' && stripos($message, 'added') !== false);
$openSubcatPanel = ($message_type === 'success' && stripos($message, 'subcategory') !== false);

// Build page content
ob_start();
?>
<script>
window.__ROADMAP_SUBCATEGORIES = <?php echo json_encode(array_values($subcategories)); ?>;
window.__ROADMAP_SUBCAT_COLORS = <?php echo json_encode($subcatColorMap); ?>;
</script>
<!-- Page header -->
<div class="sp-page-header">
    <div>
        <h1 class="sp-page-title">Roadmap Administration</h1>
        <p class="sp-page-subtitle">Manage items, move work through stages, and keep the public roadmap up to date</p>
    </div>
    <div class="rm-admin-header-actions">
        <a href="#rm-admin-board" class="sp-btn sp-btn-ghost sp-btn-sm"><i class="fa-solid fa-table-columns"></i> Board</a>
        <a href="#rm-admin-add-item" class="sp-btn sp-btn-primary sp-btn-sm"><i class="fa-solid fa-plus"></i> Add Item</a>
        <a href="../index.php" class="sp-btn sp-btn-secondary sp-btn-sm"><i class="fa-solid fa-arrow-left"></i> View Roadmap</a>
    </div>
</div>
<?php if ($message): ?>
    <div class="sp-alert sp-alert-<?php echo htmlspecialchars($message_type); ?>" id="rm-admin-flash">
        <i class="fa-solid fa-<?php echo $message_type==='success'?'circle-check':($message_type==='danger'?'triangle-exclamation':'circle-info'); ?>"></i>
        <strong><?php echo ucfirst($message_type); ?>:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Overview stats -->
<div class="rm-admin-stats">
    <div class="rm-admin-stat rm-admin-stat-total">
        <span class="rm-admin-stat-value"><?php echo (int)$statsTotal; ?></span>
        <span class="rm-admin-stat-label">Total Items</span>
    </div>
    <?php
    $statIcons = array('REQUESTS'=>'lightbulb','IN PROGRESS'=>'spinner','BETA TESTING'=>'flask','COMPLETED'=>'circle-check','REJECTED'=>'circle-xmark');
    foreach ($categories as $cat):
        $statClass = 'rm-admin-stat-' . strtolower(str_replace(' ', '-', $cat));
    ?>
    <div class="rm-admin-stat <?php echo htmlspecialchars($statClass); ?>">
        <span class="rm-admin-stat-value"><?php echo (int)($statsCounts[$cat] ?? 0); ?></span>
        <span class="rm-admin-stat-label"><i class="fa-solid fa-<?php echo $statIcons[$cat] ?? 'folder'; ?>"></i> <?php echo htmlspecialchars($cat); ?></span>
    </div>
    <?php endforeach; ?>
</div>

<!-- Search / Filter toolbar -->
<div class="rm-admin-toolbar sp-card">
    <div class="sp-card-body">
    <form method="GET" action="" class="rm-admin-filter-form">
        <div class="rm-admin-filter-row">
            <div class="rm-admin-filter-search">
                <label class="sp-label" for="admin-search">Search</label>
                <div class="sp-input-group">
                    <input class="sp-input" id="admin-search" type="text" name="search" placeholder="Search by title..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <button type="submit" class="sp-btn sp-btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                </div>
            </div>
            <div class="rm-admin-filter-field">
                <label class="sp-label" for="admin-category">Category</label>
                <select class="sp-select" id="admin-category" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $selectedCategory===$cat?'selected':''; ?>><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rm-admin-filter-field">
                <label class="sp-label" for="admin-priority">Priority</label>
                <select class="sp-select" id="admin-priority" name="priority" onchange="this.form.submit()">
                    <option value="">All Priorities</option>
                    <?php foreach ($allowedPriorities as $pri): ?>
                        <option value="<?php echo htmlspecialchars($pri); ?>" <?php echo $selectedPriority===$pri?'selected':''; ?>><?php echo htmlspecialchars($pri); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($searchQuery) || !empty($selectedCategory) || !empty($selectedPriority)): ?>
                <div class="rm-admin-filter-actions">
                    <a href="index.php" class="sp-btn sp-btn-ghost sp-btn-sm"><i class="fa-solid fa-xmark"></i> Clear filters</a>
                </div>
            <?php endif; ?>
        </div>
    </form>
    </div>
</div>

<div id="rm-admin-board" class="rm-admin-board-section">
<?php
$hasActiveFilters = !empty($searchQuery) || !empty($selectedCategory) || !empty($selectedPriority);
if (!$hasActiveFilters): ?>
<div class="rm-admin-board-toolbar">
    <p class="rm-admin-board-hint"><i class="fa-solid fa-hand-pointer"></i> Drag-free kanban — use the action buttons or &ldquo;Move to&rdquo; menu on each card to advance work.</p>
    <button id="toggleRejectedBtn" type="button" class="sp-btn sp-btn-ghost sp-btn-sm" onclick="toggleRejected()">
        <i class="fa-solid fa-eye"></i> Show Rejected
    </button>
</div>
<?php endif; ?>

<?php if ($hasActiveFilters): ?>
<!-- Filtered Results -->
<div class="sp-card">
    <div class="sp-card-body">
    <div class="rm-admin-results-head">
        <h2 class="rm-admin-results-title">
            <i class="fa-solid fa-filter"></i>
            Search Results
            <?php if (!empty($searchQuery)): ?>for &ldquo;<?php echo htmlspecialchars($searchQuery); ?>&rdquo;<?php endif; ?>
            <?php if (!empty($selectedCategory)): ?>in <?php echo htmlspecialchars($selectedCategory); ?><?php endif; ?>
            <?php if (!empty($selectedPriority)): ?>at <?php echo htmlspecialchars($selectedPriority); ?> priority<?php endif; ?>
        </h2>
        <span class="rm-admin-results-count">
            <strong><?php echo count($allItems); ?></strong>
            result<?php echo count($allItems)!==1?'s':''; ?> found
        </span>
    </div>
    <?php if (empty($allItems)): ?>
        <div class="sp-alert sp-alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> No roadmap items found matching your search criteria.</div>
    <?php else: ?>
        <div class="rm-results-grid">
            <?php foreach ($allItems as $item): echo renderAdminCard($item, $categories); endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php else: ?>
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
                    <?php foreach ($itemsByCategory[$category] as $item): echo renderAdminCard($item, $categories); endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- Add new item (collapsed by default; opens after successful add) -->
<details class="rm-admin-panel" id="rm-admin-add-item"<?php echo $openAddItemPanel ? ' open' : ''; ?>>
    <summary class="rm-admin-panel-summary">
        <span><i class="fa-solid fa-plus"></i> Add New Roadmap Item</span>
        <span class="rm-admin-panel-hint">Create a request, bug report, or feature entry</span>
    </summary>
    <div class="sp-card rm-admin-panel-body">
        <div class="sp-card-body">
        <form method="POST" action="" id="addItemForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            <div class="rm-form-cols">
                <div class="rm-admin-form-col">
                    <div class="sp-form-group">
                        <label class="sp-label" for="add-item-title">Title</label>
                        <input class="sp-input" id="add-item-title" type="text" name="title" placeholder="Item title" required>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="add-item-description">Description</label>
                        <textarea class="sp-textarea sp-textarea-mono" id="add-item-description" name="description" placeholder="Optional — supports markdown" rows="7"></textarea>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Attachments (optional)</label>
                        <label class="rm-upload-zone" id="dragDropZone">
                            <input type="file" name="initial_attachments[]" id="initialAttachments" multiple
                                accept="image/*,.pdf,.doc,.docx,.txt,.xls,.xlsx"
                                style="position:absolute;opacity:0;width:0;height:0;">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span class="rm-upload-label">Click to choose or drag &amp; drop</span>
                            <span class="rm-upload-hint">Images, PDF, Word, Excel, TXT — max 10MB each</span>
                            <span class="rm-upload-filename" id="initialAttachmentFileName"></span>
                        </label>
                    </div>
                </div>
                <div class="rm-admin-form-col">
                    <div class="sp-form-group">
                        <label class="sp-label" for="category-select">Category</label>
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
                        <div class="tag-multiselect" id="addItemSubcategory" data-name="subcategory[]" data-allowed='<?php echo htmlspecialchars(json_encode(array_values($subcategories)), ENT_QUOTES, "UTF-8"); ?>' data-initial='<?php echo htmlspecialchars(json_encode([!empty($subcategories) ? $subcategories[0] : "OTHER"]), ENT_QUOTES, "UTF-8"); ?>'></div>
                        <div class="sp-field-hint">Only predefined tags allowed.</div>
                    </div>
                    <div class="sp-form-group" id="website-type-field" style="display:none;">
                        <label class="sp-label">Website Type</label>
                        <div class="tag-multiselect" id="addItemWebsiteType" data-name="website_type[]" data-allowed='["DASHBOARD","OVERLAYS"]'></div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="priority-select">Priority</label>
                        <select class="sp-select" name="priority" id="priority-select">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM" selected>Medium</option>
                            <option value="HIGH">High</option>
                            <option value="CRITICAL">Critical</option>
                        </select>
                    </div>
                    <div class="rm-form-actions">
                        <button type="submit" class="sp-btn sp-btn-primary"><i class="fa-solid fa-floppy-disk"></i> Add Item</button>
                    </div>
                </div>
            </div>
        </form>
        </div>
    </div>
</details>

<!-- Manage subcategories -->
<details class="rm-admin-panel" id="rm-admin-subcategories"<?php echo $openSubcatPanel ? ' open' : ''; ?>>
    <summary class="rm-admin-panel-summary">
        <span><i class="fa-solid fa-tags"></i> Manage Subcategories</span>
        <span class="rm-admin-panel-hint">Add or remove tags used on roadmap cards</span>
    </summary>
    <div class="sp-card rm-admin-panel-body">
        <div class="sp-card-body">
        <div class="rm-admin-subcat-list">
            <?php foreach ($subcatRows as $sc): ?>
                <div class="rm-admin-subcat-chip">
                    <span class="rm-tag rm-tag-<?php echo htmlspecialchars($sc['color']); ?>"><?php echo htmlspecialchars($sc['name']); ?></span>
                    <form method="POST" class="rm-admin-inline-form">
                        <input type="hidden" name="action" value="remove_subcategory">
                        <input type="hidden" name="subcategory_name" value="<?php echo htmlspecialchars($sc['name']); ?>">
                        <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm sp-btn-icon" onclick="return confirm('Remove subcategory &quot;<?php echo htmlspecialchars($sc['name'], ENT_QUOTES); ?>&quot;? This will also remove it from all items.')" title="Remove <?php echo htmlspecialchars($sc['name']); ?>"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (empty($subcatRows)): ?>
                <div class="sp-alert sp-alert-warning rm-admin-subcat-empty"><i class="fa-solid fa-triangle-exclamation"></i> No subcategories defined.</div>
            <?php endif; ?>
        </div>
        <form method="POST" class="rm-admin-subcat-add-form">
            <input type="hidden" name="action" value="add_subcategory">
            <div class="sp-form-group rm-admin-subcat-field">
                <label class="sp-label" for="subcategory-name">New Subcategory</label>
                <input class="sp-input rm-admin-subcat-input" id="subcategory-name" type="text" name="subcategory_name" placeholder="e.g. MOBILE APP" required>
            </div>
            <div class="sp-form-group rm-admin-subcat-field rm-admin-subcat-field-color">
                <label class="sp-label" for="subcategory-color">Color</label>
                <select class="sp-select" id="subcategory-color" name="subcategory_color">
                    <option value="primary">Primary (Purple)</option>
                    <option value="info">Info (Blue)</option>
                    <option value="success">Success (Green)</option>
                    <option value="warning">Warning (Yellow)</option>
                    <option value="danger">Danger (Red)</option>
                    <option value="light" selected>Light (Gray)</option>
                </select>
            </div>
            <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm"><i class="fa-solid fa-plus"></i> Add</button>
        </form>
        </div>
    </div>
</details>

<?php
$pageContent = ob_get_clean();
require_once '../layout.php';
?>
<script>
function rmAdminMoveItem(select) {
    var val = select.value;
    if (!val) return;
    var form = select.closest('form');
    if (!form) return;
    var label = (val === '__completed__')
        ? 'Mark this item as completed?'
        : 'Move this item to ' + val + '?';
    if (!confirm(label)) {
        select.value = '';
        return;
    }
    form.querySelectorAll('input[name="category"], input[name="status"]').forEach(function(el) { el.remove(); });
    var inp = document.createElement('input');
    inp.type = 'hidden';
    if (val === '__completed__') {
        inp.name = 'status';
        inp.value = 'completed';
    } else {
        inp.name = 'category';
        inp.value = val;
    }
    form.appendChild(inp);
    form.submit();
}
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
    var flash = document.getElementById('rm-admin-flash');
    if (flash) {
        flash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
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
