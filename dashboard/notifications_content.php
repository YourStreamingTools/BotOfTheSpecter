<?php
// This file renders the subscription content and can be called via AJAX or initial page load
// Expects: $subscriptions, $userId, $sessionNames from parent scope or passed data

if (!isset($renderData)) {
    // Initial page load - use existing variables
    $data = [
        'subscriptions' => $subscriptions,
        'userId' => $userId,
        'sessionNames' => $sessionNames,
        'totalCount' => $totalCount,
        'totalCost' => $totalCost,
        'maxCost' => $maxCost,
        'websocketSubsEnabled' => $websocketSubsEnabled,
        'websocketSubsDisabled' => $websocketSubsDisabled,
        'webhookSubs' => $webhookSubs,
        'sessionGroups' => $sessionGroups,
        'sessionGroupsDisabled' => $sessionGroupsDisabled
    ];
} else {
    // AJAX call - use passed data
    $data = $renderData;
}

// Calculate per-connection subscription counts
$connectionCounts = [];
foreach ($data['sessionGroups'] as $sessionId => $subs) {
    $connectionCounts[$sessionId] = count($subs);
}
$subCount = count($data['websocketSubsEnabled']);

// Determine color class for Active Sessions
$sessionCount = count($data['sessionGroups']);
$sessionColorClass = '';
if ($sessionCount >= 3) {
    $sessionColorClass = 'danger-card';
} elseif ($sessionCount >= 2) {
    $sessionColorClass = 'warning-card';
}
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Subscriptions</div>
        <div class="stat-value"><?php echo $data['totalCount']; ?></div>
        <div class="stat-secondary">across all transports</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active WebSocket Subscriptions</div>
        <div class="stat-value"><?php echo $subCount; ?></div>
        <div class="stat-secondary">limit: 300 per connection</div>
        <?php 
        $connectionNumber = 0;
        foreach ($connectionCounts as $sessionId => $count): 
            $connectionNumber++;
            $textColor = '#e6e6e6';
            if ($count >= 250) {
                $textColor = '#e74c3c';
            } elseif ($count >= 150) {
                $textColor = '#f39c12';
            }
        ?>
            <div class="stat-secondary" style="color: <?php echo $textColor; ?>; margin-top: 4px;">
                Connection <?php echo $connectionNumber; ?>: <?php echo $count; ?> subscriptions
            </div>
        <?php endforeach; ?>
        <?php if (count($data['websocketSubsDisabled']) > 0): ?>
            <div class="stat-secondary" style="color: #e74c3c; margin-top: 4px;">
                <?php echo count($data['websocketSubsDisabled']); ?> disabled/stale
            </div>
        <?php endif; ?>
    </div>
    <div class="stat-card <?php echo $sessionColorClass; ?>">
        <div class="stat-label">Active Connections</div>
        <div class="stat-value"><?php echo $sessionCount; ?></div>
        <div class="stat-secondary">limit: 3 connections</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Webhook Subscriptions</div>
        <div class="stat-value"><?php echo count($data['webhookSubs']); ?></div>
        <div class="stat-secondary">callback-based</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cost Usage</div>
        <div class="stat-value"><?php echo $data['totalCost']; ?></div>
        <div class="stat-secondary">of <?php echo $data['maxCost']; ?> max</div>
    </div>
</div>

<?php if (count($data['sessionGroups']) > 0): ?>
    <div class="box">
        <h2 class="title is-4">
            <i class="fas fa-network-wired"></i> Active WebSocket Sessions
        </h2>
        <div class="info-box">
            <strong><i class="fas fa-info-circle"></i> Tip:</strong> Twitch limits you to 3 WebSocket connections. 
            Each session below counts toward that limit. Your bot and YourChat each need their own session. 
            If you hit the limit, delete old/unused sessions.
        </div>
        <?php 
        $sessionNumber = 0;
        foreach ($data['sessionGroups'] as $sessionId => $subs): 
            $sessionNumber++;
            $sessionName = $data['sessionNames'][$sessionId] ?? "WebSocket Session " . $sessionNumber;
        ?>
            <div class="session-group">
                <div class="session-header">
                    <div>
                        <strong>Session Name:</strong> <span class="session-name"><?php echo htmlspecialchars($sessionName); ?></span>
                        <br>
                        <strong>Session ID:</strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                    </div>
                    <div class="sub-count"><?php echo count($subs); ?> subscriptions</div>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Version</th>
                            <th>Condition</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subs as $sub): ?>
                            <tr>
                                <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                                <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                                <td style="font-size: 12px; color: #aaa;">
                                    <?php 
                                    $conditions = [];
                                    foreach ($sub['condition'] as $key => $value) {
                                        if ($value === $data['userId']) {
                                            $conditions[] = "$key: <strong style='color: #00ff00;'>YOU</strong>";
                                        } else {
                                            $conditions[] = "$key: " . htmlspecialchars(substr($value, 0, 12));
                                        }
                                    }
                                    echo implode('<br>', $conditions);
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = $sub['status'];
                                    $statusClass = 'status-' . strtolower($status);
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                                </td>
                                <td style="font-size: 12px; color: #aaa;">
                                    <?php 
                                    $created = new DateTime($sub['created_at']);
                                    echo $created->format('M d, H:i:s');
                                    ?>
                                </td>
                                <td>
                                    <button onclick="deleteSingleSubscription('<?php echo htmlspecialchars($sub['id'], ENT_QUOTES); ?>')" class="delete-btn">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($data['sessionGroupsDisabled']) > 0): ?>
    <div class="box" style="border-left: 3px solid #e74c3c;">
        <h2 class="title is-4">
            <i class="fas fa-exclamation-triangle"></i> Disabled / Stale WebSocket Sessions
        </h2>
        <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: rgba(231, 76, 60, 0.3);">
            <strong><i class="fas fa-info-circle"></i> Note:</strong> These subscriptions are no longer active and can be safely deleted. 
            They do not count toward your connection or subscription limits.
        </div>
        <?php 
        $sessionNumber = 0;
        foreach ($data['sessionGroupsDisabled'] as $sessionId => $subs): 
            $sessionNumber++;
            $sessionName = $data['sessionNames'][$sessionId] ?? "WebSocket Session " . $sessionNumber;
        ?>
            <div class="session-group">
                <div class="session-header">
                    <div>
                        <strong>Session Name:</strong> <span class="session-name"><?php echo htmlspecialchars($sessionName); ?></span>
                        <br>
                        <strong>Session ID:</strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                    </div>
                    <div class="sub-count">
                        <?php echo count($subs); ?> subscriptions
                        <button class="custom-btn" onclick="deleteAllInSession('<?php echo htmlspecialchars($sessionId, ENT_QUOTES); ?>', <?php echo count($subs); ?>, '<?php echo htmlspecialchars($sessionName, ENT_QUOTES); ?>')" style="margin-left: 10px;">
                            <i class="fas fa-trash-alt"></i> Delete All in Session
                        </button>
                    </div>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Version</th>
                            <th>Condition</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subs as $sub): ?>
                            <tr style="opacity: 0.7;">
                                <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                                <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                                <td style="font-size: 12px; color: #aaa;">
                                    <?php 
                                    $conditions = [];
                                    foreach ($sub['condition'] as $key => $value) {
                                        if ($value === $data['userId']) {
                                            $conditions[] = "$key: <strong style='color: #00ff00;'>YOU</strong>";
                                        } else {
                                            $conditions[] = "$key: " . htmlspecialchars(substr($value, 0, 12));
                                        }
                                    }
                                    echo implode('<br>', $conditions);
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = $sub['status'];
                                    $statusClass = 'status-' . strtolower($status);
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                                </td>
                                <td style="font-size: 12px; color: #aaa;">
                                    <?php 
                                    $created = new DateTime($sub['created_at']);
                                    echo $created->format('M d, H:i:s');
                                    ?>
                                </td>
                                <td>
                                    <button onclick="deleteSingleSubscription('<?php echo htmlspecialchars($sub['id'], ENT_QUOTES); ?>')" class="delete-btn">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($data['webhookSubs']) > 0): ?>
    <div class="box">
        <h2 class="title is-4">
            <i class="fas fa-link"></i> Webhook Subscriptions
        </h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Version</th>
                    <th>Callback URL</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['webhookSubs'] as $sub): ?>
                    <tr>
                        <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                        <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                        <td style="font-size: 11px; color: #aaa; word-break: break-all;">
                            <?php echo htmlspecialchars($sub['transport']['callback'] ?? 'N/A'); ?>
                        </td>
                        <td>
                            <?php 
                            $status = $sub['status'];
                            $statusClass = 'status-' . strtolower($status);
                            ?>
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                        </td>
                        <td>
                            <button onclick="deleteSingleSubscription('<?php echo htmlspecialchars($sub['id'], ENT_QUOTES); ?>')" class="delete-btn">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (count($data['sessionGroups']) === 0 && count($data['webhookSubs']) === 0): ?>
    <div class="box has-text-centered">
        <p class="subtitle">
            <i class="fas fa-inbox"></i><br>
            No EventSub subscriptions found.
        </p>
    </div>
<?php endif; ?>
