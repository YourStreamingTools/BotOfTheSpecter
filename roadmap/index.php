<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Australia/Sydney');
session_start();
require_once "admin/database.php";

$pageTitle = 'Roadmap';
$topbarTitle = 'Development Roadmap';
$conn = getRoadmapConnection();

$categories    = ['REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED'];
$subcategories = ['TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER'];

$searchQuery      = $_GET['search']   ?? '';
$selectedCategory = $_GET['category'] ?? '';

$allItems = [];
$query = "SELECT * FROM roadmap_items WHERE 1=1";
if (!empty($searchQuery))      $query .= " AND title LIKE '%" . $conn->real_escape_string($searchQuery) . "%'";
if (!empty($selectedCategory) && in_array($selectedCategory, $categories))
    $query .= " AND category = '" . $conn->real_escape_string($selectedCategory) . "'";
$query .= " ORDER BY updated_at DESC, created_at DESC, priority DESC";

if ($result = $conn->query($query)) {
    while ($row = $result->fetch_assoc()) { $row['subcategories'] = []; $allItems[] = $row; }
    $result->free();
}
if (!empty($allItems)) {
    $ids    = array_map(function($it){ return (int)$it['id']; }, $allItems);
    $idList = implode(',', $ids);
    $subRes = $conn->query("SELECT item_id, subcategory FROM roadmap_item_subcategories WHERE item_id IN ($idList)");
    $subMap = [];
    if ($subRes) { while ($srow=$subRes->fetch_assoc()) $subMap[(int)$srow['item_id']][]=$srow['subcategory']; $subRes->free(); }
    foreach ($allItems as &$it) {
        $itId=(int)$it['id'];
        if (!empty($subMap[$itId])) { $it['subcategories']=$subMap[$itId]; $it['subcategory']=$it['subcategory']?:$subMap[$itId][0]; }
        else { $it['subcategories']=[$it['subcategory']]; }
    }
    unset($it);
}
$itemsByCategory = [];
foreach ($categories as $cat) $itemsByCategory[$cat] = [];
foreach ($allItems as $item) $itemsByCategory[$item['category']][] = $item;

ob_start();
?>
<!-- Page header -->
<div class="sp-page-header">
    <div>
        <h1 class="sp-page-title">BotOfTheSpecter Roadmap</h1>
        <p class="sp-page-subtitle">View our development progress and upcoming features</p>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <button type="button" class="sp-btn sp-btn-secondary" id="legendBtn">
            <i class="fa-solid fa-circle-info"></i> Legend
        </button>
        <?php if ($_SESSION['admin'] ?? false): ?>
            <a href="admin/index.php" class="sp-btn sp-btn-primary">
                <i class="fa-solid fa-screwdriver-wrench"></i> Admin Panel
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search / Filter -->
<div class="sp-card" style="margin-bottom:1.5rem;">
    <form method="GET" action="">
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:2;min-width:200px;">
                <label class="sp-label">Search</label>
                <div style="display:flex;gap:0;">
                    <input class="sp-input" style="border-radius:var(--radius) 0 0 var(--radius);" type="text" name="search" placeholder="Search roadmap items by title..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <button type="submit" class="sp-btn sp-btn-info" style="border-radius:0 var(--radius) var(--radius) 0;white-space:nowrap;">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </div>
            </div>
            <div style="flex:1;min-width:160px;">
                <label class="sp-label">Category</label>
                <select class="sp-select" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $selectedCategory===$cat?'selected':''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($searchQuery) || !empty($selectedCategory)): ?>
                <div>
                    <a href="index.php" class="sp-btn sp-btn-ghost sp-btn-sm" style="margin-top:1.5rem;">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (!empty($searchQuery) || !empty($selectedCategory)): ?>
<!-- Filtered Results -->
<div class="sp-card">
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
        <div class="sp-alert sp-alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            No roadmap items found matching your search criteria.
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:0.75rem;">
            <?php foreach ($allItems as $item):
                $dtc = new DateTime($item['created_at']); ?>
                <div class="rm-card rm-card-<?php echo strtolower($item['priority']); ?>">
                    <div class="rm-card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <div class="rm-card-meta"><?php echo htmlspecialchars($dtc->format('M j, Y')); ?></div>
                    <div class="rm-card-tags">
                        <?php foreach ($item['subcategories'] as $sub): ?>
                            <span class="rm-tag rm-tag-<?php echo getSubcategoryColor($sub); ?>"><?php echo htmlspecialchars($sub); ?></span>
                        <?php endforeach; ?>
                        <?php if (!empty($item['website_type'])): ?>
                            <span class="rm-tag rm-tag-info"><?php echo htmlspecialchars($item['website_type']); ?></span>
                        <?php endif; ?>
                        <span class="rm-tag rm-tag-<?php echo getCategoryColor($item['category']); ?>"><?php echo htmlspecialchars($item['category']); ?></span>
                        <span class="rm-tag rm-tag-<?php echo getPriorityColor($item['priority']); ?>"><?php echo htmlspecialchars($item['priority']); ?></span>
                    </div>
                    <button class="sp-btn sp-btn-secondary sp-btn-sm sp-btn-full details-btn"
                        data-item-id="<?php echo $item['id']; ?>"
                        data-description="<?php echo htmlspecialchars(base64_encode($item['description']),ENT_QUOTES,'UTF-8'); ?>"
                        data-title="<?php echo htmlspecialchars($item['title']); ?>"
                        data-created-at="<?php echo htmlspecialchars($item['created_at']); ?>"
                        data-updated-at="<?php echo htmlspecialchars($item['updated_at']); ?>"
                        data-category="<?php echo htmlspecialchars($item['category']); ?>"
                        data-priority="<?php echo htmlspecialchars($item['priority']); ?>"
                        data-subcategories="<?php echo htmlspecialchars(json_encode((!empty($item['subcategories'])&&is_array($item['subcategories']))?$item['subcategories']:(!empty($item['subcategory'])?[$item['subcategory']]:[]),JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8'); ?>"
                        data-website-types="<?php echo htmlspecialchars(json_encode(!empty($item['website_type'])?[$item['website_type']]:[],JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8'); ?>">
                        <i class="fa-solid fa-circle-info"></i> Details
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
                    <?php foreach ($itemsByCategory[$category] as $item):
                        $dtc = new DateTime($item['created_at']); ?>
                        <div class="rm-card rm-card-<?php echo strtolower($item['priority']); ?>">
                            <div class="rm-card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="rm-card-meta"><?php echo htmlspecialchars($dtc->format('M j, Y')); ?></div>
                            <div class="rm-card-tags">
                                <?php if (!empty($item['subcategories']) && is_array($item['subcategories'])): ?>
                                    <?php foreach ($item['subcategories'] as $sub): ?>
                                        <span class="rm-tag rm-tag-<?php echo getSubcategoryColor($sub); ?>"><?php echo htmlspecialchars($sub); ?></span>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($item['subcategory'])): ?>
                                    <span class="rm-tag rm-tag-<?php echo getSubcategoryColor($item['subcategory']); ?>"><?php echo htmlspecialchars($item['subcategory']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['website_type'])): ?>
                                    <span class="rm-tag rm-tag-info"><?php echo htmlspecialchars($item['website_type']); ?></span>
                                <?php endif; ?>
                                <span class="rm-tag rm-tag-<?php echo getPriorityColor($item['priority']); ?>"><?php echo htmlspecialchars($item['priority']); ?></span>
                            </div>
                            <button class="sp-btn sp-btn-secondary sp-btn-sm sp-btn-full details-btn"
                                data-item-id="<?php echo $item['id']; ?>"
                                data-description="<?php echo htmlspecialchars(base64_encode($item['description']),ENT_QUOTES,'UTF-8'); ?>"
                                data-title="<?php echo htmlspecialchars($item['title']); ?>"
                                data-created-at="<?php echo htmlspecialchars($item['created_at']); ?>"
                                data-updated-at="<?php echo htmlspecialchars($item['updated_at']); ?>"
                                data-category="<?php echo htmlspecialchars($item['category']); ?>"
                                data-priority="<?php echo htmlspecialchars($item['priority']); ?>"
                                data-subcategories="<?php echo htmlspecialchars(json_encode((!empty($item['subcategories'])&&is_array($item['subcategories']))?$item['subcategories']:(!empty($item['subcategory'])?[$item['subcategory']]:[]),JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8'); ?>"
                                data-website-types="<?php echo htmlspecialchars(json_encode(!empty($item['website_type'])?[$item['website_type']]:[],JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8'); ?>">
                                <i class="fa-solid fa-circle-info"></i> Details
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
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
</script>

<?php
function getCategoryIcon($category) {
    $icons = ['REQUESTS'=>'lightbulb','IN PROGRESS'=>'spinner','BETA TESTING'=>'flask','COMPLETED'=>'circle-check','REJECTED'=>'circle-xmark'];
    return $icons[$category] ?? 'folder';
}
function getCategoryColor($category) {
    $colors = ['REQUESTS'=>'info','IN PROGRESS'=>'warning','BETA TESTING'=>'primary','COMPLETED'=>'success','REJECTED'=>'danger'];
    return $colors[$category] ?? 'light';
}
function getPriorityColor($priority) {
    $colors = ['LOW'=>'success','MEDIUM'=>'info','HIGH'=>'warning','CRITICAL'=>'danger'];
    return $colors[$priority] ?? 'light';
}
function getSubcategoryColor($subcategory) {
    $colors = ['TWITCH BOT'=>'primary','DISCORD BOT'=>'info','WEBSOCKET SERVER'=>'success','API SERVER'=>'warning','WEBSITE'=>'danger','OTHER'=>'light'];
    return $colors[$subcategory] ?? 'light';
}
$pageContent = ob_get_clean();
require_once 'layout.php';
?>
