<?php
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/admin/database.php';
require_admin_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!verify_csrf_json()) {
    json_out(['ok' => false, 'error' => 'Invalid CSRF token'], 400);
}

$body = json_body();
$action = (string) ($body['action'] ?? '');
$conn = getRoadmapConnection();
ensureRoadmapBoardSchema($conn);
$subcatRows = getAvailableSubcategories($conn);
$subcategories = array_map(static fn($r) => $r['name'], $subcatRows);
$CATEGORIES = ['REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED'];
$PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
$WEB_TYPES = ['DASHBOARD', 'OVERLAYS'];
$username = (string) ($_SESSION['username'] ?? '');

function selected_list($raw, array $allowed, array $fallback): array {
    $vals = is_array($raw) ? $raw : (($raw !== null && $raw !== '') ? [$raw] : []);
    $vals = array_values(array_unique(array_filter(array_map('trim', $vals))));
    $vals = array_values(array_filter($vals, static fn($v) => in_array($v, $allowed, true)));
    return $vals ?: $fallback;
}

if ($action === 'add') {
    $title = trim((string) ($body['title'] ?? ''));
    $description = (string) ($body['description'] ?? '');
    $category = (string) ($body['category'] ?? 'REQUESTS');
    $priority = (string) ($body['priority'] ?? 'MEDIUM');
    if ($title === '') {
        json_out(['ok' => false, 'error' => 'Title is required'], 400);
    }
    if (!in_array($category, $CATEGORIES, true)) {
        $category = 'REQUESTS';
    }
    if (!in_array($priority, $PRIORITIES, true)) {
        $priority = 'MEDIUM';
    }
    $allowedSubs = $subcategories ?: ['TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER'];
    $subs = selected_list($body['subcategory'] ?? 'TWITCH BOT', $allowedSubs, ['TWITCH BOT']);
    $webs = selected_list($body['website_type'] ?? [], $WEB_TYPES, []);
    $primarySub = $subs[0];
    $primaryWeb = $webs[0] ?? null;
    $stmt = $conn->prepare('INSERT INTO roadmap_items (title, description, category, subcategory, priority, website_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssss', $title, $description, $category, $primarySub, $priority, $primaryWeb, $username);
    if (!$stmt->execute()) {
        json_out(['ok' => false, 'error' => 'Could not add item'], 500);
    }
    $newId = (int) $conn->insert_id;
    $stmt->close();
    $ins = $conn->prepare('INSERT INTO roadmap_item_subcategories (item_id, subcategory) VALUES (?, ?)');
    foreach ($subs as $subVal) {
        $ins->bind_param('is', $newId, $subVal);
        $ins->execute();
    }
    $ins->close();
    if ($webs) {
        $insW = $conn->prepare('INSERT INTO roadmap_item_website_types (item_id, website_type) VALUES (?, ?)');
        foreach ($webs as $wt) {
            $insW->bind_param('is', $newId, $wt);
            $insW->execute();
        }
        $insW->close();
    }
    json_out(['ok' => true, 'id' => $newId, 'message' => 'Roadmap item added successfully!']);
}

if ($action === 'update') {
    $id = (int) ($body['id'] ?? 0);
    $status = (string) ($body['status'] ?? '');
    $category = (string) ($body['category'] ?? 'REQUESTS');
    if ($id <= 0) {
        json_out(['ok' => false, 'error' => 'Invalid item'], 400);
    }
    if ($status === 'completed') {
        $stmt = $conn->prepare("UPDATE roadmap_items SET category = 'COMPLETED', completed_date = NOW(), sort_order = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
    } else {
        if (!in_array($category, $CATEGORIES, true)) {
            json_out(['ok' => false, 'error' => 'Invalid category'], 400);
        }
        $stmt = $conn->prepare('UPDATE roadmap_items SET category = ?, sort_order = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $category, $id);
    }
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => 'Item updated successfully!']);
}

if ($action === 'move_item') {
    // Board drag and drop: change column (and optionally priority) and store the column order.
    $id = (int) ($body['id'] ?? 0);
    $category = (string) ($body['category'] ?? '');
    $priority = (string) ($body['priority'] ?? '');
    $order = $body['ordered_ids'] ?? [];
    if ($id <= 0 || !in_array($category, $CATEGORIES, true)) {
        json_out(['ok' => false, 'error' => 'Invalid move'], 400);
    }
    if ($priority !== '' && !in_array($priority, $PRIORITIES, true)) {
        json_out(['ok' => false, 'error' => 'Invalid priority'], 400);
    }
    if (!is_array($order)) {
        $order = [];
    }
    $order = array_values(array_unique(array_filter(array_map('intval', $order), static fn($v) => $v > 0)));
    $cur = $conn->prepare('SELECT category, priority FROM roadmap_items WHERE id = ?');
    $cur->bind_param('i', $id);
    $cur->execute();
    $row = $cur->get_result()->fetch_assoc();
    $cur->close();
    if (!$row) {
        json_out(['ok' => false, 'error' => 'Item not found'], 404);
    }
    $changedCat = $row['category'] !== $category;
    $newPrio = $priority !== '' ? $priority : $row['priority'];
    $changedPrio = $newPrio !== $row['priority'];
    if ($changedCat || $changedPrio) {
        $sets = 'category = ?, priority = ?, updated_at = NOW()';
        if ($changedCat && $category === 'COMPLETED') {
            $sets .= ', completed_date = NOW()';
        }
        $stmt = $conn->prepare("UPDATE roadmap_items SET $sets WHERE id = ?");
        $stmt->bind_param('ssi', $category, $newPrio, $id);
        $stmt->execute();
        $stmt->close();
    }
    if ($order) {
        // Only touch items that are in the target column, and keep their updated_at.
        $stmt = $conn->prepare('UPDATE roadmap_items SET sort_order = ?, updated_at = updated_at WHERE id = ? AND category = ?');
        foreach ($order as $pos => $oid) {
            $stmt->bind_param('iis', $pos, $oid, $category);
            $stmt->execute();
        }
        $stmt->close();
    }
    json_out(['ok' => true, 'message' => 'Item moved.']);
}

if ($action === 'set_wip_limit') {
    $category = (string) ($body['category'] ?? '');
    if (!in_array($category, $CATEGORIES, true)) {
        json_out(['ok' => false, 'error' => 'Invalid column'], 400);
    }
    $raw = $body['limit'] ?? null;
    if ($raw === null || $raw === '' || (int) $raw <= 0) {
        $stmt = $conn->prepare('DELETE FROM roadmap_column_limits WHERE category = ?');
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $stmt->close();
        json_out(['ok' => true, 'message' => 'WIP limit cleared for ' . $category . '.']);
    }
    $limit = min(999, (int) $raw);
    $stmt = $conn->prepare('INSERT INTO roadmap_column_limits (category, wip_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE wip_limit = VALUES(wip_limit)');
    $stmt->bind_param('si', $category, $limit);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => 'WIP limit for ' . $category . ' set to ' . $limit . '.']);
}

if ($action === 'edit_item') {
    $id = (int) ($body['id'] ?? 0);
    $title = trim((string) ($body['title'] ?? ''));
    $description = (string) ($body['description'] ?? '');
    $category = (string) ($body['category'] ?? 'REQUESTS');
    $priority = (string) ($body['priority'] ?? 'MEDIUM');
    if ($id <= 0 || $title === '') {
        json_out(['ok' => false, 'error' => 'Title is required'], 400);
    }
    if (!in_array($category, $CATEGORIES, true)) {
        $category = 'REQUESTS';
    }
    if (!in_array($priority, $PRIORITIES, true)) {
        $priority = 'MEDIUM';
    }
    $allowedSubs = $subcategories ?: ['TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER'];
    $subs = selected_list($body['subcategory'] ?? 'TWITCH BOT', $allowedSubs, ['TWITCH BOT']);
    $webs = selected_list($body['website_type'] ?? [], $WEB_TYPES, []);
    $primarySub = $subs[0];
    $primaryWeb = $webs[0] ?? null;
    $stmt = $conn->prepare('UPDATE roadmap_items SET title = ?, description = ?, category = ?, subcategory = ?, priority = ?, website_type = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssssssi', $title, $description, $category, $primarySub, $priority, $primaryWeb, $id);
    $stmt->execute();
    $stmt->close();
    $del = $conn->prepare('DELETE FROM roadmap_item_subcategories WHERE item_id = ?');
    $del->bind_param('i', $id);
    $del->execute();
    $del->close();
    $ins = $conn->prepare('INSERT INTO roadmap_item_subcategories (item_id, subcategory) VALUES (?, ?)');
    foreach ($subs as $subVal) {
        $ins->bind_param('is', $id, $subVal);
        $ins->execute();
    }
    $ins->close();
    $delW = $conn->prepare('DELETE FROM roadmap_item_website_types WHERE item_id = ?');
    $delW->bind_param('i', $id);
    $delW->execute();
    $delW->close();
    if ($webs) {
        $insW = $conn->prepare('INSERT INTO roadmap_item_website_types (item_id, website_type) VALUES (?, ?)');
        foreach ($webs as $wt) {
            $insW->bind_param('is', $id, $wt);
            $insW->execute();
        }
        $insW->close();
    }
    json_out(['ok' => true, 'message' => 'Item edited successfully!']);
}

if ($action === 'delete') {
    $id = (int) ($body['id'] ?? 0);
    $stmt = $conn->prepare('DELETE FROM roadmap_items WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => 'Item deleted successfully!']);
}

if ($action === 'add_comment') {
    $itemId = (int) ($body['item_id'] ?? 0);
    $comment = trim((string) ($body['comment_text'] ?? ''));
    if ($itemId <= 0 || $comment === '') {
        json_out(['ok' => false, 'error' => 'Comment required'], 400);
    }
    $stmt = $conn->prepare('INSERT INTO roadmap_comments (item_id, username, comment) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $itemId, $username, $comment);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => 'Comment added successfully!']);
}

if ($action === 'remove_subcategory') {
    $subName = trim((string) ($body['subcategory_name'] ?? ''));
    if ($subName === '') {
        json_out(['ok' => false, 'error' => 'Name required'], 400);
    }
    $stmt = $conn->prepare('DELETE FROM roadmap_subcategories WHERE name = ?');
    $stmt->bind_param('s', $subName);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if ($ok) {
        $del = $conn->prepare('DELETE FROM roadmap_item_subcategories WHERE subcategory = ?');
        $del->bind_param('s', $subName);
        $del->execute();
        $del->close();
        json_out(['ok' => true, 'message' => 'Subcategory "' . $subName . '" removed successfully!']);
    }
    json_out(['ok' => false, 'error' => 'Subcategory not found.'], 404);
}

if ($action === 'add_subcategory') {
    $subName = strtoupper(trim((string) ($body['subcategory_name'] ?? '')));
    $subColor = trim((string) ($body['subcategory_color'] ?? 'light'));
    $allowedColors = ['primary', 'info', 'success', 'warning', 'danger', 'light'];
    if (!in_array($subColor, $allowedColors, true)) {
        $subColor = 'light';
    }
    if ($subName === '') {
        json_out(['ok' => false, 'error' => 'Name required'], 400);
    }
    $maxOrder = 0;
    $res = $conn->query('SELECT MAX(sort_order) AS mx FROM roadmap_subcategories');
    if ($res) {
        $row = $res->fetch_assoc();
        $maxOrder = (int) ($row['mx'] ?? 0);
    }
    $newOrder = $maxOrder + 1;
    $stmt = $conn->prepare('INSERT IGNORE INTO roadmap_subcategories (name, color, sort_order) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $subName, $subColor, $newOrder);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => 'Subcategory added.']);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
