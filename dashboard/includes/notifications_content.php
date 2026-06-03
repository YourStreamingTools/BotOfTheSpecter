<?php
// This file renders the subscription content and can be called via AJAX or initial page load
// Expects: $subscriptions, $userId, $sessionNames from parent scope or passed data

// Ensure translations are available even if this partial is rendered without its parent
// loading i18n first. The guard prevents double-loading when the parent already defined t().
if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    $i18nPath = __DIR__ . '/../lang/i18n.php';
    if (file_exists($i18nPath)) {
        include_once $i18nPath;
    }
    if (!function_exists('t')) {
        function t($key, $replacements = [])
        {
            return $key;
        }
    }
}

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
        <div class="stat-label"><?= t('notifications_content_stat_total_subscriptions') ?></div>
        <div class="stat-value"><?php echo $data['totalCount']; ?></div>
        <div class="stat-secondary"><?= t('notifications_content_stat_across_all_transports') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= t('notifications_content_stat_active_websocket_subs') ?></div>
        <div class="stat-value"><?php echo $subCount; ?></div>
        <div class="stat-secondary"><?= t('notifications_content_stat_limit_300_per_connection') ?></div>
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
                <?= t('notifications_content_connection_label') ?> <?php echo $connectionNumber; ?>: <?php echo $count; ?> <?= t('notifications_content_subscriptions_word') ?>
            </div>
        <?php endforeach; ?>
        <?php if (count($data['websocketSubsDisabled']) > 0): ?>
            <div class="stat-secondary" style="color: var(--red); margin-top: 4px;">
                <?php echo count($data['websocketSubsDisabled']); ?> <?= t('notifications_content_disabled_stale_word') ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="stat-card <?php echo $sessionColorClass; ?>">
        <div class="stat-label"><?= t('notifications_content_stat_active_connections') ?></div>
        <div class="stat-value"><?php echo $sessionCount; ?></div>
        <div class="stat-secondary"><?= t('notifications_content_stat_limit_3_connections') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= t('notifications_content_stat_webhook_subscriptions') ?></div>
        <div class="stat-value"><?php echo count($data['webhookSubs']); ?></div>
        <div class="stat-secondary"><?= t('notifications_content_stat_callback_based') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= t('notifications_content_stat_cost_usage') ?></div>
        <div class="stat-value"><?php echo $data['totalCost']; ?></div>
        <div class="stat-secondary"><?= t('notifications_content_stat_of_max') ?> <?php echo $data['maxCost']; ?> <?= t('notifications_content_stat_max_word') ?></div>
    </div>
</div>

<?php if (count($data['sessionGroups']) > 0): ?>
    <div class="sp-card"><div class="sp-card-body">
        <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
            <i class="fas fa-network-wired"></i> <?= t('notifications_content_heading_active_sessions') ?>
        </h2>
        <div class="info-box">
            <?= t('notifications_content_infobox_active_sessions') ?>
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
                        <strong><?= t('notifications_content_label_session_name') ?></strong> <span class="session-name"><?php echo htmlspecialchars($sessionName); ?></span>
                        <br>
                        <strong><?= t('notifications_content_label_session_id') ?></strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                    </div>
                    <div class="sub-count"><?php echo count($subs); ?> <?= t('notifications_content_subscriptions_word') ?></div>
                </div>
                <table class="data-table sp-table">
                    <thead>
                        <tr>
                            <th><?= t('notifications_content_th_type') ?></th>
                            <th><?= t('notifications_content_th_version') ?></th>
                            <th><?= t('notifications_content_th_condition') ?></th>
                            <th><?= t('notifications_content_th_status') ?></th>
                            <th><?= t('notifications_content_th_created') ?></th>
                            <th><?= t('notifications_content_th_action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subs as $sub): ?>
                            <tr>
                                <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                                <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                                <td style="font-size: 12px; color: var(--text-muted);">
                                    <?php 
                                    $conditions = [];
                                    foreach ($sub['condition'] as $key => $value) {
                                        if ($value === $data['userId']) {
                                            $conditions[] = "$key: <strong style='color: var(--green);'>" . t('notifications_content_condition_you') . "</strong>";
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
                                <td style="font-size: 12px; color: var(--text-muted);">
                                    <?php 
                                    $created = new DateTime($sub['created_at']);
                                    echo $created->format('M d, H:i:s');
                                    ?>
                                </td>
                                <td>
                                    <button onclick="deleteSingleSubscription('<?php echo htmlspecialchars($sub['id'], ENT_QUOTES); ?>')" class="delete-btn">
                                        <i class="fas fa-trash"></i> <?= t('notifications_content_btn_delete') ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div></div>
<?php endif; ?>

<?php if (count($data['sessionGroupsDisabled']) > 0): ?>
    <div class="sp-card" style="border-left:3px solid var(--red);"><div class="sp-card-body">
        <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
            <i class="fas fa-exclamation-triangle"></i> <?= t('notifications_content_heading_disabled_sessions') ?>
        </h2>
        <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: rgba(231, 76, 60, 0.3);">
            <?= t('notifications_content_infobox_disabled_sessions') ?>
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
                        <strong><?= t('notifications_content_label_session_name') ?></strong> <span class="session-name"><?php echo htmlspecialchars($sessionName); ?></span>
                        <br>
                        <strong><?= t('notifications_content_label_session_id') ?></strong> <span class="session-id"><?php echo htmlspecialchars($sessionId); ?></span>
                    </div>
                    <div class="sub-count">
                        <?php echo count($subs); ?> <?= t('notifications_content_subscriptions_word') ?>
                        <button class="custom-btn" onclick="deleteAllInSession('<?php echo htmlspecialchars($sessionId, ENT_QUOTES); ?>', <?php echo count($subs); ?>, '<?php echo htmlspecialchars($sessionName, ENT_QUOTES); ?>')" style="margin-left: 10px;">
                            <i class="fas fa-trash-alt"></i> <?= t('notifications_content_btn_delete_all_in_session') ?>
                        </button>
                    </div>
                </div>
                <table class="data-table sp-table">
                    <thead>
                        <tr>
                            <th><?= t('notifications_content_th_type') ?></th>
                            <th><?= t('notifications_content_th_version') ?></th>
                            <th><?= t('notifications_content_th_condition') ?></th>
                            <th><?= t('notifications_content_th_status') ?></th>
                            <th><?= t('notifications_content_th_created') ?></th>
                            <th><?= t('notifications_content_th_action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subs as $sub): ?>
                            <tr style="opacity: 0.7;">
                                <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                                <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                                <td style="font-size: 12px; color: var(--text-muted);">
                                    <?php 
                                    $conditions = [];
                                    foreach ($sub['condition'] as $key => $value) {
                                        if ($value === $data['userId']) {
                                            $conditions[] = "$key: <strong style='color: var(--green);'>" . t('notifications_content_condition_you') . "</strong>";
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
                                <td style="font-size: 12px; color: var(--text-muted);">
                                    <?php 
                                    $created = new DateTime($sub['created_at']);
                                    echo $created->format('M d, H:i:s');
                                    ?>
                                </td>
                                <td>
                                    <button onclick="deleteSingleSubscription('<?php echo htmlspecialchars($sub['id'], ENT_QUOTES); ?>')" class="delete-btn">
                                        <i class="fas fa-trash"></i> <?= t('notifications_content_btn_delete') ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div></div>
<?php endif; ?>

<?php if (count($data['webhookSubs']) > 0): ?>
    <div class="sp-card"><div class="sp-card-body">
        <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem;">
            <i class="fas fa-link"></i> <?= t('notifications_content_heading_webhook_subscriptions') ?>
        </h2>
        <table class="data-table sp-table">
            <thead>
                <tr>
                    <th><?= t('notifications_content_th_type') ?></th>
                    <th><?= t('notifications_content_th_version') ?></th>
                    <th><?= t('notifications_content_th_callback_url') ?></th>
                    <th><?= t('notifications_content_th_status') ?></th>
                    <th><?= t('notifications_content_th_action') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['webhookSubs'] as $sub): ?>
                    <tr>
                        <td><span class="sub-type"><?php echo htmlspecialchars($sub['type']); ?></span></td>
                        <td><span class="sub-version">v<?php echo htmlspecialchars($sub['version']); ?></span></td>
                        <td style="font-size: 11px; color: var(--text-muted); word-break: break-all;">
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
                                <i class="fas fa-trash"></i> <?= t('notifications_content_btn_delete') ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>

<?php if (count($data['sessionGroups']) === 0 && count($data['webhookSubs']) === 0): ?>
    <div class="sp-card" style="text-align:center;"><div class="sp-card-body">
        <p style="color:var(--text-secondary);">
            <i class="fas fa-inbox"></i><br>
            <?= t('notifications_content_empty_no_subscriptions') ?>
        </p>
    </div></div>
<?php endif; ?>
