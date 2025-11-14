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
    <!-- Timeline with alternating columns -->
    <div class="timeline-container">
        <?php 
        $eventIndex = 0;
        foreach ($groupedEvents as $yearMonth => $monthData): 
        ?>
            <div class="timeline-section">
                <h2 class="title is-4 timeline-month-header">
                    <?php echo htmlspecialchars($monthData['month']); ?>
                </h2>
                <!-- Two-column layout for events -->
                <div class="timeline-grid">
                    <?php 
                    $leftEvents = [];
                    $rightEvents = [];
                    // Split events into left and right columns
                    foreach ($monthData['events'] as $idx => $event) {
                        if ($idx % 2 === 0) {
                            $leftEvents[] = $event;
                        } else {
                            $rightEvents[] = $event;
                        }
                    }
                    $maxCount = max(count($leftEvents), count($rightEvents));
                    for ($i = 0; $i < $maxCount; $i++):
                    ?>
                        <!-- Left column -->
                        <div>
                            <?php if (isset($leftEvents[$i])): 
                                $event = $leftEvents[$i];
                            ?>
                                <div class="timeline-card">
                                    <!-- Timeline indicator -->
                                    <div class="timeline-indicator">
                                        <div class="timeline-dot"></div>
                                        <small class="timeline-date">
                                            <?php 
                                            $now = new DateTime();
                                            $interval = $now->diff($event['date']);
                                            if ($interval->days > 0) {
                                                echo $event['date']->format('M d, Y');
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
                                    <h3 class="title is-5 timeline-title">
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </h3>
                                    <div class="timeline-tags">
                                        <?php echo getCategoryBadge($event['category']); ?>
                                        <?php if ($event['priority'] > 0): ?>
                                            <span class="tag is-warning">Priority <?php echo htmlspecialchars($event['priority']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($event['type'] === 'created'): ?>
                                        <div class="timeline-event-box timeline-event-created">
                                            <p class="timeline-event-text"><strong>Event:</strong> Item Created</p>
                                            <?php if (!empty($event['created_by'])): ?>
                                                <p class="timeline-event-meta"><strong>By:</strong> <?php echo htmlspecialchars($event['created_by']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($event['description'])): ?>
                                                <p class="timeline-event-description">
                                                    <?php 
                                                    $desc = htmlspecialchars($event['description']);
                                                    echo strlen($desc) > 150 ? substr($desc, 0, 150) . '...' : $desc;
                                                    ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($event['type'] === 'updated'): ?>
                                        <div class="timeline-event-box timeline-event-updated">
                                            <p class="timeline-event-updated-text"><strong>Event:</strong> Item Updated</p>
                                        </div>
                                    <?php endif; ?>
                                    <a href="index.php?search=<?php echo urlencode($event['title']); ?>" class="button is-small is-info is-light">
                                        <span class="icon is-small"><i class="fas fa-external-link-alt"></i></span>
                                        <span>View Details</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Right column -->
                        <div>
                            <?php if (isset($rightEvents[$i])): 
                                $event = $rightEvents[$i];
                            ?>
                                <div class="timeline-card">
                                    <!-- Timeline indicator -->
                                    <div class="timeline-indicator">
                                        <div class="timeline-dot"></div>
                                        <small class="timeline-date">
                                            <?php 
                                            $now = new DateTime();
                                            $interval = $now->diff($event['date']);
                                            if ($interval->days > 0) {
                                                echo $event['date']->format('M d, Y');
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
                                    <h3 class="title is-5 timeline-title">
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </h3>
                                    <div class="timeline-tags">
                                        <?php echo getCategoryBadge($event['category']); ?>
                                        <?php if ($event['priority'] > 0): ?>
                                            <span class="tag is-warning">Priority <?php echo htmlspecialchars($event['priority']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($event['type'] === 'created'): ?>
                                        <div class="timeline-event-box timeline-event-created">
                                            <p class="timeline-event-text"><strong>Event:</strong> Item Created</p>
                                            <?php if (!empty($event['created_by'])): ?>
                                                <p class="timeline-event-meta"><strong>By:</strong> <?php echo htmlspecialchars($event['created_by']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($event['description'])): ?>
                                                <p class="timeline-event-description">
                                                    <?php 
                                                    $desc = htmlspecialchars($event['description']);
                                                    echo strlen($desc) > 150 ? substr($desc, 0, 150) . '...' : $desc;
                                                    ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($event['type'] === 'updated'): ?>
                                        <div class="timeline-event-box timeline-event-updated">
                                            <p class="timeline-event-updated-text"><strong>Event:</strong> Item Updated</p>
                                        </div>
                                    <?php endif; ?>
                                    <a href="index.php?search=<?php echo urlencode($event['title']); ?>" class="button is-small is-info is-light">
                                        <span class="icon is-small"><i class="fas fa-external-link-alt"></i></span>
                                        <span>View Details</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($groupedEvents)): ?>
            <div class="timeline-no-events">
                <p class="has-text-grey-light"><i class="fas fa-calendar-times timeline-no-events-icon"></i>No timeline events yet</p>
            </div>
        <?php endif; ?>
    </div>

<?php
$pageContent = ob_get_clean();

// Include layout
require_once "layout.php";
?>
