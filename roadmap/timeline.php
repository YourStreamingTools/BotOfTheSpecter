<?php
// Timeline view for roadmap progression
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Australia/Sydney');

session_start();

require_once "admin/database.php";

// Set page metadata
$pageTitle = 'Timeline';

// Get database connection
$conn = getRoadmapConnection();

// Get all items with their activity
$query = "SELECT * FROM roadmap_items ORDER BY created_at ASC";
$result = $conn->query($query);
$allItems = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allItems[] = $row;
    }
    $result->free();
}

// Build timeline data
$timelineEvents = [];

foreach ($allItems as $item) {
    $createdAt = new DateTime($item['created_at']);
    
    // Add creation event
    $timelineEvents[] = [
        'date' => $createdAt,
        'type' => 'created',
        'item_id' => $item['id'],
        'title' => $item['title'],
        'description' => $item['description'],
        'category' => $item['category'],
        'created_by' => $item['created_by'],
        'priority' => $item['priority']
    ];
    
    // Get last status change (use updated_at if available)
    if (!empty($item['updated_at'])) {
        $updatedAt = new DateTime($item['updated_at']);
        if ($updatedAt > $createdAt) {
            $timelineEvents[] = [
                'date' => $updatedAt,
                'type' => 'updated',
                'item_id' => $item['id'],
                'title' => $item['title'],
                'category' => $item['category'],
                'priority' => $item['priority']
            ];
        }
    }
}

// Sort timeline by date (newest first)
usort($timelineEvents, function($a, $b) {
    return $b['date']->getTimestamp() - $a['date']->getTimestamp();
});

// Group events by month/year
$groupedEvents = [];
foreach ($timelineEvents as $event) {
    $yearMonth = $event['date']->format('Y-m');
    $monthName = $event['date']->format('F Y');
    
    if (!isset($groupedEvents[$yearMonth])) {
        $groupedEvents[$yearMonth] = [
            'month' => $monthName,
            'events' => []
        ];
    }
    
    $groupedEvents[$yearMonth]['events'][] = $event;
}

// Sort grouped events by month (newest first for chronological timeline)
krsort($groupedEvents);

// Helper function to get category color
function getCategoryColor($category) {
    $colors = [
        'REQUESTS' => '#FF6B6B',
        'IN PROGRESS' => '#FFD93D',
        'BETA TESTING' => '#6BCB77',
        'COMPLETED' => '#4D96FF',
        'REJECTED' => '#808080'
    ];
    return $colors[$category] ?? '#667eea';
}

// Helper function to get category badge
function getCategoryBadge($category) {
    return '<span class="tag" style="background-color: ' . getCategoryColor($category) . '; color: white; margin-right: 0.5rem;">' . htmlspecialchars($category) . '</span>';
}

// Set page content
$pageContent = '';

ob_start();
?>
<div class="content">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
        <div>
            <h1 class="title">Development Timeline</h1>
            <p class="subtitle">Track the progress and evolution of BotOfTheSpecter features</p>
        </div>
        <a href="index.php" class="button is-light">
            <span class="icon"><i class="fas fa-th"></i></span>
            <span>Back to Roadmap</span>
        </a>
    </div>

    <!-- Timeline -->
    <div style="position: relative; padding: 2rem 0;">
        <?php foreach ($groupedEvents as $yearMonth => $monthData): ?>
            <div style="margin-bottom: 3rem;">
                <h2 class="title is-4" style="color: #667eea; border-bottom: 2px solid #667eea; padding-bottom: 0.5rem; margin-bottom: 1.5rem;">
                    <?php echo htmlspecialchars($monthData['month']); ?>
                </h2>
                
                <div style="position: relative; padding-left: 2rem;">
                    <!-- Vertical line -->
                    <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 2px; background: linear-gradient(to bottom, #667eea, transparent);"></div>
                    
                    <?php foreach ($monthData['events'] as $event): ?>
                        <div style="margin-bottom: 2rem; position: relative;">
                            <!-- Timeline dot -->
                            <div style="position: absolute; left: -2.5rem; top: 0; width: 12px; height: 12px; background-color: #667eea; border-radius: 50%; border: 3px solid #1a1a2e;"></div>
                            
                            <!-- Event card -->
                            <div style="background: rgba(102, 126, 234, 0.1); border: 1px solid rgba(102, 126, 234, 0.3); border-radius: 8px; padding: 1.5rem;">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                                    <div>
                                        <h3 class="title is-5" style="margin-bottom: 0.5rem;">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </h3>
                                        <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                                            <?php echo getCategoryBadge($event['category']); ?>
                                            <?php if ($event['priority'] > 0): ?>
                                                <span class="tag is-warning">Priority <?php echo htmlspecialchars($event['priority']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <small style="color: #888; white-space: nowrap;">
                                        <?php 
                                        $now = new DateTime();
                                        $interval = $now->diff($event['date']);
                                        if ($interval->days > 0) {
                                            echo $event['date']->format('M d, Y \a\t g:i A');
                                        } elseif ($interval->h > 0) {
                                            echo $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
                                        } elseif ($interval->i > 0) {
                                            echo $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
                                        } else {
                                            echo 'Just now';
                                        }
                                        ?>
                                    </small>
                                </div>
                                
                                <?php if ($event['type'] === 'created'): ?>
                                    <div style="background: rgba(102, 126, 234, 0.2); padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                                        <p style="color: #b0b0b0; margin-bottom: 0.5rem;"><strong>Event:</strong> Item Created</p>
                                        <?php if (!empty($event['created_by'])): ?>
                                            <p style="color: #b0b0b0;"><strong>By:</strong> <?php echo htmlspecialchars($event['created_by']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($event['description'])): ?>
                                            <p style="color: #888; margin-top: 0.5rem; font-size: 0.9rem;">
                                                <?php 
                                                $desc = htmlspecialchars($event['description']);
                                                echo strlen($desc) > 200 ? substr($desc, 0, 200) . '...' : $desc;
                                                ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($event['type'] === 'updated'): ?>
                                    <div style="background: rgba(255, 217, 61, 0.15); padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                                        <p style="color: #FFD93D;"><strong>Event:</strong> Item Updated</p>
                                    </div>
                                <?php endif; ?>
                                
                                <div style="display: flex; gap: 1rem;">
                                    <a href="index.php?search=<?php echo urlencode($event['title']); ?>" class="button is-small is-info is-light">
                                        <span class="icon is-small"><i class="fas fa-search"></i></span>
                                        <span>View Item</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($groupedEvents)): ?>
            <div style="text-align: center; padding: 3rem;">
                <p class="has-text-grey-light"><i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>No timeline events yet</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    @media (max-width: 768px) {
        .timeline-event {
            padding-left: 1rem;
        }
    }
</style>

<?php
$pageContent = ob_get_clean();

// Include layout
require_once "layout.php";
?>
