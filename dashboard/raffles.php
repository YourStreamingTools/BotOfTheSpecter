<?php
require_once '/var/www/lib/session_bootstrap.php';
include_once __DIR__ . '/lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
session_write_close();
include 'includes/mod_access.php';
include_once 'includes/usr_database.php';
include 'includes/user_db.php';

$pageTitle = t('raffles_page_title');

require_once '/var/www/lib/require_auth.php';

$api_key_to_use = isset($api_key) ? $api_key : (isset($admin_key) ? $admin_key : '');
$message = '';
$editRaffle = null;

// Handle create raffle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'create' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $prize = trim($_POST['prize'] ?? '');
        $number_of_winners = intval($_POST['number_of_winners'] ?? 1);
        $weighted = isset($_POST['weighted']) ? 1 : 0;
        $weight_sub_t1 = floatval($_POST['weight_sub_t1'] ?? 2.00);
        $weight_sub_t2 = floatval($_POST['weight_sub_t2'] ?? 3.00);
        $weight_sub_t3 = floatval($_POST['weight_sub_t3'] ?? 4.00);
        $weight_vip = floatval($_POST['weight_vip'] ?? 1.50);
        $exclude_mods = isset($_POST['exclude_mods']) ? 1 : 0;
        $subscribers_only = isset($_POST['subscribers_only']) ? 1 : 0;
        $followers_only = isset($_POST['followers_only']) ? 1 : 0;
        $followers_min_enabled = isset($_POST['followers_min_enabled']) ? 1 : 0;
        $followers_min_value = max(0, intval($_POST['followers_min_value'] ?? 0));
        $followers_min_unit = $_POST['followers_min_unit'] ?? 'days';
        $valid_follow_units = ['days', 'weeks', 'months', 'years'];
        if (!in_array($followers_min_unit, $valid_follow_units, true)) {
            $followers_min_unit = 'days';
        }
        if (!$followers_only) {
            $followers_min_enabled = 0;
            $followers_min_value = 0;
            $followers_min_unit = 'days';
        }
        if (!$followers_min_enabled) {
            $followers_min_value = 0;
            $followers_min_unit = 'days';
        }
        if ($action === 'edit') {
            // Editing is allowed only while the raffle is still 'scheduled'
            // (each entry's weight is baked in at join time).
            $raffle_id = intval($_POST['raffle_id'] ?? 0);
            // Snapshot the submitted values so a failed edit keeps the form populated.
            $editPostback = ['id' => $raffle_id, 'name' => $name, 'prize' => $prize, 'number_of_winners' => $number_of_winners, 'is_weighted' => $weighted, 'weight_sub_t1' => $weight_sub_t1, 'weight_sub_t2' => $weight_sub_t2, 'weight_sub_t3' => $weight_sub_t3, 'weight_vip' => $weight_vip, 'exclude_mods' => $exclude_mods, 'subscribers_only' => $subscribers_only, 'followers_only' => $followers_only, 'followers_min_enabled' => $followers_min_enabled, 'followers_min_value' => $followers_min_value, 'followers_min_unit' => $followers_min_unit];
            $chk = $db->prepare("SELECT status FROM raffles WHERE id = ? LIMIT 1");
            $chk->bind_param('i', $raffle_id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$existing) {
                $message = t('raffles_msg_not_found');
            } elseif ($existing['status'] !== 'scheduled') {
                $message = t('raffles_msg_edit_not_scheduled');
            } elseif ($name === '' || $number_of_winners <= 0) {
                $message = t('raffles_msg_invalid_name');
                $editRaffle = $editPostback;
            } else {
                $stmt = $db->prepare("UPDATE raffles SET name = ?, prize = ?, number_of_winners = ?, is_weighted = ?, weight_sub_t1 = ?, weight_sub_t2 = ?, weight_sub_t3 = ?, weight_vip = ?, exclude_mods = ?, subscribers_only = ?, followers_only = ?, followers_min_enabled = ?, followers_min_value = ?, followers_min_unit = ? WHERE id = ? AND status = 'scheduled'");
                $stmt->bind_param('ssiiddddiiiiisi', $name, $prize, $number_of_winners, $weighted, $weight_sub_t1, $weight_sub_t2, $weight_sub_t3, $weight_vip, $exclude_mods, $subscribers_only, $followers_only, $followers_min_enabled, $followers_min_value, $followers_min_unit, $raffle_id);
                if ($stmt->execute()) {
                    $message = t('raffles_msg_updated');
                } else {
                    $message = t('raffles_msg_update_failed');
                    $editRaffle = $editPostback;
                }
                $stmt->close();
            }
        } elseif ($name === '' || $number_of_winners <= 0) {
            $message = t('raffles_msg_invalid_name');
        } else {
            $stmt = $db->prepare("INSERT INTO raffles (name, prize, number_of_winners, status, is_weighted, weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip, exclude_mods, subscribers_only, followers_only, followers_min_enabled, followers_min_value, followers_min_unit) VALUES (?, ?, ?, 'scheduled', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiiddddiiiiis', $name, $prize, $number_of_winners, $weighted, $weight_sub_t1, $weight_sub_t2, $weight_sub_t3, $weight_vip, $exclude_mods, $subscribers_only, $followers_only, $followers_min_enabled, $followers_min_value, $followers_min_unit);
            if ($stmt->execute()) {
                $message = t('raffles_msg_created', [$name]);
            } else {
                $message = t('raffles_msg_create_failed');
            }
            $stmt->close();
        }
    } elseif ($action === 'start' && isset($_POST['raffle_id'])) {
        $raffle_id = intval($_POST['raffle_id']);
        // Update raffle status from scheduled to running
        $stmt = $db->prepare("UPDATE raffles SET status = 'running' WHERE id = ? AND status = 'scheduled'");
        $stmt->bind_param('i', $raffle_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = t('raffles_msg_started');
        } else {
            $message = t('raffles_msg_start_failed');
        }
        $stmt->close();
    } elseif ($action === 'draw' && isset($_POST['raffle_id'])) {
        $raffle_id = intval($_POST['raffle_id']);
        // Fetch raffle details
        $stmt = $db->prepare("SELECT id, name, prize, number_of_winners, is_weighted FROM raffles WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $raffle_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $raffle = $result->fetch_assoc();
        $stmt->close();
        if (!$raffle) {
            $message = t('raffles_msg_not_found');
        } else {
            $number_of_winners = intval($raffle['number_of_winners']);
            $stmt = $db->prepare("SELECT id, username, user_id, weight FROM raffle_entries WHERE raffle_id = ?");
            $stmt->bind_param('i', $raffle_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $entries = [];
            while ($row = $res->fetch_assoc()) $entries[] = $row;
            $stmt->close();
            if (count($entries) === 0) {
                $message = t('raffles_msg_no_entries');
            } else {
                $winners = [];
                $available_entries = $entries;
                
                // Draw multiple winners
                for ($i = 0; $i < min($number_of_winners, count($entries)); $i++) {
                    $total = 0;
                    foreach ($available_entries as $e) $total += intval($e['weight']);
                    $pick = rand(1, $total);
                    $running = 0;
                    $winner = null;
                    $winner_index = -1;
                    
                    foreach ($available_entries as $idx => $e) {
                        $running += intval($e['weight']);
                        if ($running >= $pick) {
                            $winner = $e;
                            $winner_index = $idx;
                            break;
                        }
                    }
                    
                    if ($winner) {
                        $winners[] = $winner;
                        // Remove winner from available entries for next draw
                        array_splice($available_entries, $winner_index, 1);
                    }
                }
                
                if (count($winners) === 0) {
                    $message = t('raffles_msg_pick_failed');
                } else {
                    // Begin transaction
                    $db->begin_transaction();
                    try {
                        // Insert winners into raffle_winners table
                        $stmt = $db->prepare("INSERT INTO raffle_winners (raffle_id, entry_id, username, user_id) VALUES (?, ?, ?, ?)");
                        $winner_names = [];
                        foreach ($winners as $winner) {
                            $entry_id = intval($winner['id']);
                            $username = $winner['username'];
                            $user_id = $winner['user_id'];
                            $winner_names[] = $username;
                            $stmt->bind_param('iiss', $raffle_id, $entry_id, $username, $user_id);
                            $stmt->execute();
                        }
                        $stmt->close();
                        // Update raffle status to ended
                        $stmt = $db->prepare("UPDATE raffles SET status = 'ended' WHERE id = ?");
                        $stmt->bind_param('i', $raffle_id);
                        $stmt->execute();
                        $stmt->close();
                        $db->commit();
                        $winner_list = implode(', ', $winner_names);
                        $message = t('raffles_msg_winners_selected', [$winner_list]);
                        // Notify websocket via API for each winner
                        if ($api_key_to_use) {
                            foreach ($winners as $winner) {
                                $raffle_name_encoded = urlencode($raffle['name']);
                                $winner_encoded = urlencode($winner['username']);
                                $url = "https://api.botofthespecter.com/websocket/raffle_winner?api_key=" . urlencode($api_key_to_use) . "&raffle_name={$raffle_name_encoded}&winner={$winner_encoded}";
                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                                $resp = curl_exec($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode !== 200) {
                                    // warn but continue
                                    $message .= ' ' . t('raffles_msg_websocket_warning');
                                    break;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $db->rollback();
                        $message = t('raffles_msg_save_winners_failed', [$e->getMessage()]);
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'delete' && isset($_POST['raffle_id'])) {
        // Delete a raffle in any state; entries and winners cascade away (FK ON DELETE CASCADE).
        $raffle_id = intval($_POST['raffle_id']);
        $stmt = $db->prepare("DELETE FROM raffles WHERE id = ?");
        $stmt->bind_param('i', $raffle_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = t('raffles_msg_deleted');
        } else {
            $message = t('raffles_msg_delete_failed');
        }
        $stmt->close();
    }
}

// Load the raffle being edited (only scheduled raffles are editable). Skip when a failed
// edit submit already repopulated $editRaffle from POST, so the user's input is preserved.
if (!$editRaffle && isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $estmt = $db->prepare("SELECT id, name, prize, number_of_winners, is_weighted, weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip, exclude_mods, subscribers_only, followers_only, followers_min_enabled, followers_min_value, followers_min_unit FROM raffles WHERE id = ? AND status = 'scheduled' LIMIT 1");
    $estmt->bind_param('i', $edit_id);
    $estmt->execute();
    $editRaffle = $estmt->get_result()->fetch_assoc();
    $estmt->close();
}

// Fetch raffles list with winners
$raffles = [];
$res = $db->query("SELECT id, name, prize, number_of_winners, status, is_weighted, weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip, exclude_mods, subscribers_only, followers_only, followers_min_enabled, followers_min_value, followers_min_unit FROM raffles ORDER BY created_at DESC LIMIT 50");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        // Fetch winners for this raffle
        $raffle_id = $row['id'];
        $winners_stmt = $db->prepare("SELECT username FROM raffle_winners WHERE raffle_id = ?");
        $winners_stmt->bind_param('i', $raffle_id);
        $winners_stmt->execute();
        $winners_res = $winners_stmt->get_result();
        $winners = [];
        while ($winner = $winners_res->fetch_assoc()) {
            $winners[] = $winner['username'];
        }
        $winners_stmt->close();
        $row['winners'] = $winners;
        $raffles[] = $row;
    }
}

ob_start();
?>
<div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
    <strong><?= t('raffles_beta_label') ?></strong> <?= t('raffles_beta_notice') ?>
</div>
<?php if ($message): ?>
    <div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php
$ef = $editRaffle;
$isEdit = $ef !== null;
$ef_weighted  = $isEdit && intval($ef['is_weighted']) === 1;
$ef_excl_mods = $isEdit && intval($ef['exclude_mods']) === 1;
$ef_subs_only = $isEdit && intval($ef['subscribers_only']) === 1;
$ef_followers = $isEdit && intval($ef['followers_only']) === 1;
$ef_fmin      = $isEdit && intval($ef['followers_min_enabled']) === 1;
$ef_unit      = $isEdit ? ($ef['followers_min_unit'] ?? 'days') : 'days';
?>
<!-- Create / Edit Raffle -->
<div class="sp-card" id="raffle-form">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-ticket-alt"></i> <?= $isEdit ? t('raffles_edit_title') : t('raffles_create_new_title') ?></span>
    </header>
    <div class="sp-card-body">
        <form method="post">
            <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'create' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="raffle_id" value="<?php echo htmlspecialchars($ef['id']); ?>"><?php endif; ?>
            <div class="sp-form-group">
                <label class="sp-label"><?= t('raffles_field_name') ?></label>
                <input class="sp-input" name="name" value="<?php echo htmlspecialchars($ef['name'] ?? ''); ?>" required>
            </div>
            <div class="sp-form-group">
                <label class="sp-label"><?= t('raffles_field_prize') ?></label>
                <textarea class="sp-textarea" name="prize" placeholder="<?= htmlspecialchars(t('raffles_field_prize_placeholder')) ?>" required><?php echo htmlspecialchars($ef['prize'] ?? ''); ?></textarea>
            </div>
            <div class="sp-form-group">
                <label class="sp-label"><?= t('raffles_field_number_of_winners') ?></label>
                <input class="sp-input" type="number" name="number_of_winners" min="1" value="<?php echo htmlspecialchars($ef['number_of_winners'] ?? 1); ?>" required style="max-width:120px;">
            </div>
            <div class="sp-form-group">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;color:var(--text-secondary);">
                    <input type="checkbox" name="weighted" id="weighted-checkbox" onchange="toggleWeightSettings()" <?= $ef_weighted ? 'checked' : '' ?>>
                    <?= t('raffles_enable_weighted') ?>
                </label>
            </div>
            <div id="weight-settings" style="display:<?= $ef_weighted ? 'block' : 'none' ?>;border-left:3px solid var(--accent);padding-left:1rem;margin-left:1rem;margin-bottom:1rem;">
                <p style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary);margin-bottom:0.75rem;"><?= t('raffles_weight_multipliers') ?></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="sp-form-group" style="margin-bottom:0;">
                        <label class="sp-label"><?= t('raffles_weight_sub_t1') ?></label>
                        <input class="sp-input" type="number" name="weight_sub_t1" step="0.01" min="1" value="<?php echo htmlspecialchars($ef['weight_sub_t1'] ?? '2.00'); ?>">
                    </div>
                    <div class="sp-form-group" style="margin-bottom:0;">
                        <label class="sp-label"><?= t('raffles_weight_sub_t2') ?></label>
                        <input class="sp-input" type="number" name="weight_sub_t2" step="0.01" min="1" value="<?php echo htmlspecialchars($ef['weight_sub_t2'] ?? '3.00'); ?>">
                    </div>
                    <div class="sp-form-group" style="margin-bottom:0;">
                        <label class="sp-label"><?= t('raffles_weight_sub_t3') ?></label>
                        <input class="sp-input" type="number" name="weight_sub_t3" step="0.01" min="1" value="<?php echo htmlspecialchars($ef['weight_sub_t3'] ?? '4.00'); ?>">
                    </div>
                    <div class="sp-form-group" style="margin-bottom:0;">
                        <label class="sp-label"><?= t('raffles_weight_vip') ?></label>
                        <input class="sp-input" type="number" name="weight_vip" step="0.01" min="1" value="<?php echo htmlspecialchars($ef['weight_vip'] ?? '1.50'); ?>">
                    </div>
                </div>
            </div>
            <div class="sp-form-group">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;color:var(--text-secondary);">
                    <input type="checkbox" name="exclude_mods" <?= $ef_excl_mods ? 'checked' : '' ?>> <?= t('raffles_exclude_mods') ?>
                </label>
            </div>
            <div class="sp-form-group">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;color:var(--text-secondary);">
                    <input type="checkbox" name="subscribers_only" <?= $ef_subs_only ? 'checked' : '' ?>> <?= t('raffles_subscribers_only') ?>
                </label>
            </div>
            <div class="sp-form-group">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;color:var(--text-secondary);">
                    <input type="checkbox" name="followers_only" id="followers-only-checkbox" onchange="toggleFollowerMinimumSettings()" <?= $ef_followers ? 'checked' : '' ?>> <?= t('raffles_followers_only') ?>
                </label>
            </div>
            <div id="follower-minimum-settings" style="display:<?= $ef_followers ? 'block' : 'none' ?>;border-left:3px solid var(--green);padding-left:1rem;margin-left:1rem;margin-bottom:1rem;">
                <div class="sp-form-group">
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;color:var(--text-secondary);">
                        <input type="checkbox" name="followers_min_enabled" id="followers-min-enabled-checkbox" onchange="toggleFollowerMinimumInputs()" <?= $ef_fmin ? 'checked' : '' ?>> <?= t('raffles_require_min_follow_time') ?>
                    </label>
                </div>
                <div id="follower-minimum-inputs" style="display:<?= $ef_fmin ? 'block' : 'none' ?>;">
                    <div class="sp-form-group">
                        <label class="sp-label"><?= t('raffles_min_follow_time') ?></label>
                        <div style="display:flex;gap:0.75rem;align-items:center;">
                            <input class="sp-input" type="number" name="followers_min_value" min="0" value="<?php echo htmlspecialchars($ef['followers_min_value'] ?? 0); ?>" style="max-width:100px;">
                            <select class="sp-select" name="followers_min_unit" style="max-width:140px;">
                                <option value="days" <?= $ef_unit === 'days' ? 'selected' : '' ?>><?= t('raffles_unit_days') ?></option>
                                <option value="weeks" <?= $ef_unit === 'weeks' ? 'selected' : '' ?>><?= t('raffles_unit_weeks') ?></option>
                                <option value="months" <?= $ef_unit === 'months' ? 'selected' : '' ?>><?= t('raffles_unit_months') ?></option>
                                <option value="years" <?= $ef_unit === 'years' ? 'selected' : '' ?>><?= t('raffles_unit_years') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                function toggleWeightSettings() {
                    const checkbox = document.getElementById('weighted-checkbox');
                    const settings = document.getElementById('weight-settings');
                    settings.style.display = checkbox.checked ? 'block' : 'none';
                }
                function toggleFollowerMinimumSettings() {
                    const checkbox = document.getElementById('followers-only-checkbox');
                    const settings = document.getElementById('follower-minimum-settings');
                    settings.style.display = checkbox.checked ? 'block' : 'none';
                    if (!checkbox.checked) {
                        const minEnabled = document.getElementById('followers-min-enabled-checkbox');
                        if (minEnabled) {
                            minEnabled.checked = false;
                        }
                        toggleFollowerMinimumInputs();
                    }
                }
                function toggleFollowerMinimumInputs() {
                    const checkbox = document.getElementById('followers-min-enabled-checkbox');
                    const settings = document.getElementById('follower-minimum-inputs');
                    settings.style.display = checkbox && checkbox.checked ? 'block' : 'none';
                }
            </script>
            <div style="margin-top:1.5rem;">
                <button class="sp-btn sp-btn-primary" type="submit">
                    <i class="fas fa-ticket-alt"></i> <?= $isEdit ? t('raffles_update_btn') : t('raffles_create_btn') ?>
                </button>
                <?php if ($isEdit): ?>
                    <a class="sp-btn sp-btn-secondary" href="raffles.php" style="margin-left:0.5rem;"><i class="fas fa-times"></i> <?= t('raffles_cancel_edit') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Active Raffles -->
<div class="sp-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-list"></i> <?= t('raffles_active_title') ?></span>
    </header>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?= t('raffles_col_id') ?></th>
                        <th><?= t('raffles_col_name') ?></th>
                        <th><?= t('raffles_col_prize') ?></th>
                        <th><?= t('raffles_col_num_winners') ?></th>
                        <th><?= t('raffles_col_status') ?></th>
                        <th><?= t('raffles_col_weights') ?></th>
                        <th><?= t('raffles_col_exclusions') ?></th>
                        <th><?= t('raffles_col_winners') ?></th>
                        <th><?= t('raffles_col_action') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($raffles as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['id']); ?></td>
                            <td><?php echo htmlspecialchars($r['name']); ?></td>
                            <td><?php echo htmlspecialchars($r['prize'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($r['number_of_winners']); ?></td>
                            <td>
                                <?php
                                $statusClass = match($r['status']) {
                                    'running'   => 'sp-badge-green',
                                    'scheduled' => 'sp-badge-blue',
                                    'ended'     => 'sp-badge-grey',
                                    default     => 'sp-badge-grey',
                                };
                                ?>
                                <span class="sp-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($r['status']); ?></span>
                            </td>
                            <td>
                                <?php if ($r['is_weighted']): ?>
                                    <span class="sp-badge sp-badge-accent" title="T1: <?php echo $r['weight_sub_t1']; ?>x | T2: <?php echo $r['weight_sub_t2']; ?>x | T3: <?php echo $r['weight_sub_t3']; ?>x | VIP: <?php echo $r['weight_vip']; ?>x">
                                        <?= t('raffles_badge_weighted') ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);"><?= t('raffles_badge_no') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $exclusions = [];
                                if ($r['exclude_mods']) $exclusions[] = t('raffles_excl_mods');
                                if ($r['subscribers_only']) $exclusions[] = t('raffles_excl_subs');
                                if ($r['followers_only']) {
                                    $followersLabel = t('raffles_excl_followers');
                                    if (intval($r['followers_min_enabled']) === 1 && intval($r['followers_min_value']) > 0) {
                                        $followersLabel .= ' (' . intval($r['followers_min_value']) . ' ' . htmlspecialchars($r['followers_min_unit']) . ' ' . t('raffles_excl_min') . ')';
                                    }
                                    $exclusions[] = $followersLabel;
                                }
                                echo !empty($exclusions) ? htmlspecialchars(implode(', ', $exclusions)) : '<span style="color:var(--text-muted);">' . t('raffles_excl_none') . '</span>';
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($r['winners'])): ?>
                                    <span style="color:var(--green);"><?php echo htmlspecialchars(implode(', ', $r['winners'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                                    <?php if ($r['status'] === 'scheduled'): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="start">
                                            <input type="hidden" name="raffle_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                            <button class="sp-btn sp-btn-success sp-btn-sm" type="submit"><i class="fas fa-play"></i> <?= t('raffles_btn_start') ?></button>
                                        </form>
                                        <a class="sp-btn sp-btn-secondary sp-btn-sm" href="?edit=<?php echo htmlspecialchars($r['id']); ?>#raffle-form"><i class="fas fa-edit"></i> <?= t('raffles_btn_edit') ?></a>
                                    <?php elseif ($r['status'] === 'running'): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="draw">
                                            <input type="hidden" name="raffle_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                            <button class="sp-btn sp-btn-warning sp-btn-sm" type="submit"><i class="fas fa-star"></i> <?= t('raffles_btn_draw') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('<?php echo htmlspecialchars(t('raffles_confirm_delete'), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="raffle_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                        <button class="sp-btn sp-btn-danger sp-btn-sm" type="submit"><i class="fas fa-trash"></i> <?= t('raffles_btn_delete') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

require 'layout.php';
?>
