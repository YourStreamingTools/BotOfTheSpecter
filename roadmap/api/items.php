<?php
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/admin/database.php';
roadmap_session_start();

$CATEGORIES = ['REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED'];
$conn = getRoadmapConnection();
$subcatRows = getAvailableSubcategories($conn);
$subcatColorMap = [];
foreach ($subcatRows as $r) {
    $subcatColorMap[$r['name']] = $r['color'];
}

$search = trim((string) ($_GET['search'] ?? ''));
$category = (string) ($_GET['category'] ?? '');
if ($category !== '' && !in_array($category, $CATEGORIES, true)) {
    $category = '';
}

$sql = 'SELECT id, title, description, category, subcategory, priority, website_type, completed_date, created_by, created_at, updated_at FROM roadmap_items WHERE 1=1';
$types = '';
$params = [];
if ($search !== '') {
    $sql .= ' AND title LIKE ?';
    $types .= 's';
    $params[] = '%' . $search . '%';
}
if ($category !== '') {
    $sql .= ' AND category = ?';
    $types .= 's';
    $params[] = $category;
}
$sql .= ' ORDER BY updated_at DESC, created_at DESC, priority DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($items) {
    $ids = array_map(static fn($it) => (int) $it['id'], $items);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $idTypes = str_repeat('i', count($ids));

    $subMap = [];
    $subStmt = $conn->prepare("SELECT item_id, subcategory FROM roadmap_item_subcategories WHERE item_id IN ($placeholders)");
    $subStmt->bind_param($idTypes, ...$ids);
    $subStmt->execute();
    $subRes = $subStmt->get_result();
    while ($row = $subRes->fetch_assoc()) {
        $subMap[(int) $row['item_id']][] = $row['subcategory'];
    }
    $subStmt->close();

    $webMap = [];
    $webStmt = $conn->prepare("SELECT item_id, website_type FROM roadmap_item_website_types WHERE item_id IN ($placeholders)");
    $webStmt->bind_param($idTypes, ...$ids);
    $webStmt->execute();
    $webRes = $webStmt->get_result();
    while ($row = $webRes->fetch_assoc()) {
        $webMap[(int) $row['item_id']][] = $row['website_type'];
    }
    $webStmt->close();

    foreach ($items as &$it) {
        $id = (int) $it['id'];
        $subs = $subMap[$id] ?? [];
        if (!$subs && !empty($it['subcategory'])) {
            $subs = [$it['subcategory']];
        }
        $webs = $webMap[$id] ?? [];
        if (!$webs && !empty($it['website_type'])) {
            $webs = [$it['website_type']];
        }
        $it['id'] = $id;
        $it['subcategories'] = array_values($subs);
        $it['website_types'] = array_values($webs);
    }
    unset($it);
}

json_out([
    'ok' => true,
    'categories' => $CATEGORIES,
    'subcategories' => $subcatRows,
    'subcat_colors' => $subcatColorMap,
    'items' => $items,
    'is_admin' => roadmap_is_admin(),
]);
