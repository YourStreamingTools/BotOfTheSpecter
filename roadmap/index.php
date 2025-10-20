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
            <div class="level-item">
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
<!-- Legend -->
<div class="box mb-6">
    <h3 class="title is-5 mb-4">Legend</h3>
    <div class="columns is-multiline is-gapless">
        <div class="column is-one-quarter">
            <div>
                <strong>Priority Levels:</strong>
                <div class="mt-2">
                    <span class="tag is-small is-success">Low</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-info">Medium</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-warning">High</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-danger">Critical</span>
                </div>
            </div>
        </div>
        <div class="column is-one-quarter">
            <div>
                <strong>Subcategories:</strong>
                <div class="mt-2">
                    <span class="tag is-small is-primary">Twitch Bot</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-info">Discord Bot</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-success">WebSocket Server</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-warning">API Server</span>
                </div>
                <div class="mt-2">
                    <span class="tag is-small is-danger">Website</span>
                </div>
            </div>
        </div>
    </div>
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
                                <div class="roadmap-card-tags">
                                    <span class="tag is-small is-<?php echo getPriorityColor($item['priority']); ?>">
                                        <?php echo htmlspecialchars($item['priority']); ?>
                                    </span>
                                </div>
                                <?php if ($item['description']): ?>
                                    <div class="mt-3">
                                        <button class="button is-small is-light is-fullwidth details-btn" data-description="<?php echo htmlspecialchars($item['description']); ?>" data-title="<?php echo htmlspecialchars($item['title']); ?>">
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
require_once 'layout.php';
?>