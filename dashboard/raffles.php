<?php
session_start();
include_once __DIR__ . '/lang/i18n.php';
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
        if ($name === '' || $number_of_winners <= 0) {
            $message = 'Invalid name or number of winners.';
        } else {
            $stmt = $db->prepare("INSERT INTO raffles (name, prize, number_of_winners, status, is_weighted) VALUES (?, ?, ?, 'running', ?)");
            $stmt->bind_param('ssii', $name, $prize, $number_of_winners, $weighted);
            if ($stmt->execute()) {
                $message = "Raffle '$name' started.";
            } else {
                $message = 'Failed to create raffle.';
            }
            $stmt->close();
        }
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
            $stmt = $db->prepare("SELECT username, user_id, weight FROM raffle_entries WHERE raffle_id = ?");
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
                    // Store winners as JSON
                    $winner_names = array_column($winners, 'username');
                    $winner_ids = array_column($winners, 'user_id');
                    $winner_names_json = json_encode($winner_names);
                    $winner_ids_json = json_encode($winner_ids);
                    
                    $stmt = $db->prepare("UPDATE raffles SET winner_username = ?, winner_user_id = ?, status = 'ended' WHERE id = ?");
                    $stmt->bind_param('ssi', $winner_names_json, $winner_ids_json, $raffle_id);
                    if ($stmt->execute()) {
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
                    } else {
                        $message = 'Failed to update raffle with winner.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch raffles list
$raffles = [];
$res = $db->query("SELECT id, name, prize, number_of_winners, status, is_weighted, winner_username FROM raffles ORDER BY created_at DESC LIMIT 50");
if ($res) {
    while ($row = $res->fetch_assoc()) $raffles[] = $row;
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
                        <label class="checkbox"><input type="checkbox" name="weighted"> Weighted (subscribers get enhanced odds)</label>
                    </div>
                    <div class="field">
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
                            <th>Weighted</th>
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
                                <td><?php echo $r['is_weighted'] ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <?php 
                                    if ($r['winner_username']) {
                                        $winners = json_decode($r['winner_username'], true);
                                        if (is_array($winners)) {
                                            echo htmlspecialchars(implode(', ', $winners));
                                        } else {
                                            echo htmlspecialchars($r['winner_username']);
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($r['status'] !== 'ended'): ?>
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