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
        $duration = intval($_POST['duration'] ?? 0);
        $weighted = isset($_POST['weighted']) ? 1 : 0;
        if ($name === '' || $duration <= 0) {
            $message = 'Invalid name or duration.';
        } else {
            $start_time = date('Y-m-d H:i:s');
            $end_time = date('Y-m-d H:i:s', time() + ($duration * 60));
            $stmt = $db->prepare("INSERT INTO raffles (name, description, start_time, end_time, status, is_weighted) VALUES (?, ?, ?, ?, 'running', ?)");
            $stmt->bind_param('ssssi', $name, $description = '', $start_time, $end_time, $weighted);
            if ($stmt->execute()) {
                $message = "Raffle '$name' started.";
            } else {
                $message = 'Failed to create raffle.';
            }
            $stmt->close();
        }
    } elseif ($action === 'draw' && isset($_POST['raffle_id'])) {
        $raffle_id = intval($_POST['raffle_id']);
        // Fetch entries
        $stmt = $db->prepare("SELECT id, name, is_weighted FROM raffles WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $raffle_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $raffle = $result->fetch_assoc();
        $stmt->close();
        if (!$raffle) {
            $message = 'Raffle not found.';
        } else {
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
                $total = 0;
                foreach ($entries as $e) $total += intval($e['weight']);
                $pick = rand(1, $total);
                $running = 0;
                $winner = null;
                foreach ($entries as $e) {
                    $running += intval($e['weight']);
                    if ($running >= $pick) { $winner = $e; break; }
                }
                if (!$winner) {
                    $message = 'Failed to pick a winner.';
                } else {
                    $winner_name = $db->real_escape_string($winner['username']);
                    $winner_id = $db->real_escape_string($winner['user_id']);
                    $stmt = $db->prepare("UPDATE raffles SET winner_username = ?, winner_user_id = ?, status = 'ended' WHERE id = ?");
                    $stmt->bind_param('ssi', $winner_name, $winner_id, $raffle_id);
                    if ($stmt->execute()) {
                        $message = "Winner selected: $winner_name";
                        // Notify websocket via API
                        if ($api_key_to_use) {
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
$res = $db->query("SELECT id, name, start_time, end_time, status, is_weighted, winner_username FROM raffles ORDER BY created_at DESC LIMIT 50");
if ($res) {
    while ($row = $res->fetch_assoc()) $raffles[] = $row;
}

$pageContent = '<div class="box">';
if ($message) $pageContent .= '<div class="notification is-info">' . htmlspecialchars($message) . '</div>';
$pageContent .= <<<HTML
<h3 class="title is-5">Create New Raffle</h3>
<form method="post">
<input type="hidden" name="action" value="create">
<div class="field">
<label class="label">Raffle Name</label>
<div class="control"><input class="input" name="name" required></div>
</div>
<div class="field">
<label class="label">Duration (minutes)</label>
<div class="control"><input class="input" type="number" name="duration" min="1" required></div>
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
<thead><tr><th>ID</th><th>Name</th><th>Start</th><th>End</th><th>Status</th><th>Winner</th><th>Action</th></tr></thead>
<tbody>
HTML;
foreach ($raffles as $r) {
    $pageContent .= '<tr>';
    $pageContent .= '<td>' . htmlspecialchars($r['id']) . '</td>';
    $pageContent .= '<td>' . htmlspecialchars($r['name']) . '</td>';
    $pageContent .= '<td>' . htmlspecialchars($r['start_time']) . '</td>';
    $pageContent .= '<td>' . htmlspecialchars($r['end_time']) . '</td>';
    $pageContent .= '<td>' . htmlspecialchars($r['status']) . '</td>';
    $pageContent .= '<td>' . htmlspecialchars($r['winner_username'] ?? '') . '</td>';
    $pageContent .= '<td>';
    if ($r['status'] !== 'ended') {
        $pageContent .= '<form method="post" style="display:inline"><input type="hidden" name="action" value="draw"><input type="hidden" name="raffle_id" value="' . htmlspecialchars($r['id']) . '"><button class="button is-small is-warning" type="submit">Draw</button></form>';
    }
    $pageContent .= '</td>';
    $pageContent .= '</tr>';
}
$pageContent .= '</tbody></table></div>';

include 'layout.php';
?>
<div class="page-content">
    <div class="columns" style="flex: 1 0 auto;">
        <div class="column is-10 is-offset-1 main-content">
            <section class="section">
                <?php echo $pageContent; ?>
            </section>
        </div>
    </div>
</div>