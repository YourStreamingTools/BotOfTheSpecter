<?php
// Display errors for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once "admin/database.php";

// Set page metadata
$pageTitle = 'Roadmap';

// Get database connection
$conn = getRoadmapConnection();

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

$query .= " ORDER BY priority DESC, created_at DESC";

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
    <div class="level">
        <div class="level-left">
            <div class="level-item">
                <div>
                    <h1 class="title">BotOfTheSpecter Roadmap</h1>
                    <p class="subtitle">View our development progress and upcoming features</p>
                </div>
            </div>
        </div>
        <div class="level-right">
            <div class="level-item" style="display: flex; gap: 0.5rem;">
                <button type="button" class="button is-info" id="legendBtn">
                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                    <span>Legend</span>
                </button>
                <?php if (($_SESSION['admin'] ?? false)): ?>
                    <a href="admin/index.php" class="button is-primary">
                        <span class="icon"><i class="fas fa-cog"></i></span>
                        <span>Admin Panel</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- Search and Filter Section -->
<div class="box mb-6">
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
                                <?php if ($item['description']): ?>
                                    <div class="mt-3">
                                        <button class="button is-small is-light is-fullwidth details-btn" data-item-id="<?php echo $item['id']; ?>" data-description="<?php echo htmlspecialchars($item['description']); ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>">
                                            <span class="icon is-small"><i class="fas fa-info-circle"></i></span>
                                            <span>Details</span>
                                        </button>
                                    </div>
                                <?php endif; ?>
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
                                <div class="roadmap-card-tags">
                                    <span class="tag is-small is-<?php echo getPriorityColor($item['priority']); ?>">
                                        <?php echo htmlspecialchars($item['priority']); ?>
                                    </span>
                                </div>
                                <?php if ($item['description']): ?>
                                    <div class="mt-3">
                                        <button class="button is-small is-light is-fullwidth details-btn" data-item-id="<?php echo $item['id']; ?>" data-description="<?php echo htmlspecialchars($item['description']); ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>">
                                            <span class="icon is-small"><i class="fas fa-info-circle"></i></span>
                                            <span>Details</span>
                                        </button>
                                    </div>
                                <?php endif; ?>
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
require_once 'layout.php';
?>