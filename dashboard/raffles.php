<?php
session_start();
include_once __DIR__ . '/lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'mod_access.php';
include_once 'usr_database.php';
include 'user_db.php';

$pageTitle = 'Raffles';

// Ensure logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

$api_key_to_use = isset($api_key) ? $api_key : (isset($admin_key) ? $admin_key : '');
$message = '';

// Handle create raffle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'create') {
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
        if ($name === '' || $number_of_winners <= 0) {
            $message = 'Invalid name or number of winners.';
        } else {
            $stmt = $db->prepare("INSERT INTO raffles (name, prize, number_of_winners, status, is_weighted, weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip, exclude_mods, subscribers_only, followers_only) VALUES (?, ?, ?, 'scheduled', ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiiddddiii', $name, $prize, $number_of_winners, $weighted, $weight_sub_t1, $weight_sub_t2, $weight_sub_t3, $weight_vip, $exclude_mods, $subscribers_only, $followers_only);
            if ($stmt->execute()) {
                $message = "Raffle '$name' created and scheduled.";
            } else {
                $message = 'Failed to create raffle.';
            }
            $stmt->close();
        }
    } elseif ($action === 'start' && isset($_POST['raffle_id'])) {
        $raffle_id = intval($_POST['raffle_id']);
        // Update raffle status from scheduled to running
        $stmt = $db->prepare("UPDATE raffles SET status = 'running' WHERE id = ? AND status = 'scheduled'");
        $stmt->bind_param('i', $raffle_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Raffle started successfully!";
        } else {
            $message = 'Failed to start raffle. It may not be in scheduled status.';
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
            $message = 'Raffle not found.';
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
                $message = 'No entries for this raffle.';
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
                    $message = 'Failed to pick a winner.';
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
                        $message = "Winner(s) selected: $winner_list";
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
                                curl_close($ch);
                                if ($httpCode !== 200) {
                                    // warn but continue
                                    $message .= ' (warning: failed to notify websocket)';
                                    break;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $db->rollback();
                        $message = 'Failed to save raffle winners: ' . $e->getMessage();
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch raffles list with winners
$raffles = [];
$res = $db->query("SELECT id, name, prize, number_of_winners, status, is_weighted, weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip, exclude_mods, subscribers_only, followers_only FROM raffles ORDER BY created_at DESC LIMIT 50");
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
<div class="columns" style="flex: 1 0 auto;">
    <div class="column is-10 is-offset-1 main-content">
        <section class="section">
            <div class="notification is-warning">
                <strong>⚠️ Beta Feature:</strong> This is a beta 5.8 version feature currently in testing. Functionality may change or have unexpected behavior.
            </div>
            <?php if ($message): ?>
                <div class="notification is-info"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <div class="box">
                <h3 class="title is-5">Create New Raffle</h3>
                <form method="post">
                    <input type="hidden" name="action" value="create">
                    <div class="field">
                        <label class="label">Raffle Name</label>
                        <div class="control"><input class="input" name="name" required></div>
                    </div>
                    <div class="field">
                        <label class="label">Prize Description</label>
                        <div class="control"><textarea class="textarea" name="prize" placeholder="What are they winning?" required></textarea></div>
                    </div>
                    <div class="field">
                        <label class="label">Number of Winners</label>
                        <div class="control"><input class="input" type="number" name="number_of_winners" min="1" value="1" required></div>
                    </div>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="weighted" id="weighted-checkbox" onchange="toggleWeightSettings()"> 
                            Enable Weighted Raffle (subscribers and VIPs get enhanced odds)
                        </label>
                    </div>
                    <div id="weight-settings" style="display: none; border-left: 3px solid #3273dc; padding-left: 1rem; margin-left: 1rem;">
                        <h4 class="subtitle is-6">Weight Multipliers</h4>
                        <div class="columns">
                            <div class="column">
                                <div class="field">
                                    <label class="label">Tier 1 Subscriber</label>
                                    <div class="control"><input class="input" type="number" name="weight_sub_t1" step="0.01" min="1" value="2.00"></div>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label class="label">Tier 2 Subscriber</label>
                                    <div class="control"><input class="input" type="number" name="weight_sub_t2" step="0.01" min="1" value="3.00"></div>
                                </div>
                            </div>
                        </div>
                        <div class="columns">
                            <div class="column">
                                <div class="field">
                                    <label class="label">Tier 3 Subscriber</label>
                                    <div class="control"><input class="input" type="number" name="weight_sub_t3" step="0.01" min="1" value="4.00"></div>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label class="label">VIP</label>
                                    <div class="control"><input class="input" type="number" name="weight_vip" step="0.01" min="1" value="1.50"></div>
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <label class="checkbox">
                                <input type="checkbox" name="exclude_mods"> Exclude Moderators from winning
                            </label>
                        </div>
                    </div>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="subscribers_only"> Only Subscribers Can Enter
                        </label>
                    </div>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="followers_only"> Only Followers Can Enter
                        </label>
                    </div>
                    <script>
                        function toggleWeightSettings() {
                            const checkbox = document.getElementById('weighted-checkbox');
                            const settings = document.getElementById('weight-settings');
                            settings.style.display = checkbox.checked ? 'block' : 'none';
                        }
                    </script>
                    <div class="field" style="margin-top: 1.5rem;">
                        <div class="control"><button class="button is-primary" type="submit">Start Raffle</button></div>
                    </div>
                </form>
                <hr>
                <h3 class="title is-5">Active Raffles</h3>
                <table class="table is-fullwidth is-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Prize</th>
                            <th># Winners</th>
                            <th>Status</th>
                            <th>Weights</th>
                            <th>Exclusions</th>
                            <th>Winner(s)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($raffles as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['id']); ?></td>
                                <td><?php echo htmlspecialchars($r['name']); ?></td>
                                <td><?php echo htmlspecialchars($r['prize'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r['number_of_winners']); ?></td>
                                <td><?php echo htmlspecialchars($r['status']); ?></td>
                                <td>
                                    <?php if ($r['is_weighted']): ?>
                                        <span title="T1: <?php echo $r['weight_sub_t1']; ?>x | T2: <?php echo $r['weight_sub_t2']; ?>x | T3: <?php echo $r['weight_sub_t3']; ?>x | VIP: <?php echo $r['weight_vip']; ?>x">
                                            ✓ (hover for details)
                                        </span>
                                    <?php else: ?>
                                        No
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $exclusions = [];
                                    if ($r['exclude_mods']) $exclusions[] = 'Mods excluded';
                                    if ($r['subscribers_only']) $exclusions[] = 'Subs only';
                                    if ($r['followers_only']) $exclusions[] = 'Followers only';
                                    echo !empty($exclusions) ? implode(', ', $exclusions) : 'None';
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if (!empty($r['winners'])) {
                                        echo htmlspecialchars(implode(', ', $r['winners']));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($r['status'] === 'scheduled'): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="start">
                                            <input type="hidden" name="raffle_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                            <button class="button is-small is-success" type="submit">Start</button>
                                        </form>
                                    <?php elseif ($r['status'] === 'running'): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="draw">
                                            <input type="hidden" name="raffle_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                            <button class="button is-small is-warning" type="submit">Draw</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php
$content = ob_get_clean();

require 'layout.php';
?>