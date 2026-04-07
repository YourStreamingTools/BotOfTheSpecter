<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Page Title and Header
$pageTitle = "Bingo Games";
$pageDescription = "Manage bingo games for Stream Bounty integration";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';

// Handle POST request to save API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_key'])) {
    $api_key = trim($_POST['api_key']);
    if (!empty($api_key)) {
        // Save to database - update profile table with stream_bounty_api_key column
        $stmt = $db->prepare("UPDATE profile SET stream_bounty_api_key = ?");
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $stmt->close();
        $message = "API Key saved successfully!";
    } else {
        $message = "Please enter a valid API Key.";
    }
}

// Get current API key
$current_api_key = '';
$result = $db->query("SELECT stream_bounty_api_key FROM profile LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $current_api_key = $row['stream_bounty_api_key'] ?? '';
}
$api_key_exists = !empty($current_api_key);

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fas fa-trophy"></i>
            Stream Bounty Integration
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <button class="sp-btn sp-btn-primary" id="call-random-btn"<?php echo $api_key_exists ? '' : ' disabled'; ?>>Call Random</button>
            <button class="sp-btn sp-btn-primary" id="call-all-btn"<?php echo $api_key_exists ? '' : ' disabled'; ?>>Call All</button>
            <button class="sp-btn sp-btn-primary" id="start-vote-btn"<?php echo $api_key_exists ? '' : ' disabled'; ?>>Start Vote</button>
        </div>
    </div>
    <div class="sp-card-body">
        <div id="bingo-alerts">
        <?php if (isset($message)): ?>
            <div class="sp-alert <?php echo strpos($message, 'successfully') !== false ? 'sp-alert-success' : 'sp-alert-danger'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if (!$api_key_exists): ?>
            <div class="sp-alert sp-alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>API Key Required:</strong> Please enter your Stream Bounty API key below to enable the bingo game controls.
            </div>
        <?php endif; ?>
        </div>

        <div class="sp-card" style="margin-bottom:1.5rem;">
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-wrench"></i>
                    Twitch Extension Configuration
                </div>
            </div>
            <div class="sp-card-body">
                <p style="color:var(--text-secondary); margin-bottom:1rem;">Enter your Stream Bounty API Key below. This key will be used to integrate with the bingo games.</p>
                <form method="post" action="">
                    <div class="sp-form-group">
                        <label class="sp-label" for="api-key-field">API Key</label>
                        <div style="position:relative;">
                            <input class="sp-input" type="password" name="api_key" id="api-key-field"
                                value="<?php echo htmlspecialchars($current_api_key); ?>"
                                placeholder="Enter your API Key" required style="padding-right:2.75rem;">
                            <span id="api-key-visibility-icon" style="position:absolute; right:0.75rem; top:50%; transform:translateY(-50%); color:var(--text-muted); cursor:pointer; line-height:1;">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <p style="font-size:0.8rem; color:var(--text-muted); margin-top:0.35rem;">Click the eye icon to show/hide the API key</p>
                    </div>
                    <button class="sp-btn sp-btn-primary" type="submit">Save API Key</button>
                </form>
            </div>
        </div>

        <div class="sp-card" style="margin-bottom:1.5rem;">
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-question-circle"></i>
                    How to Get Your Stream Bounty API Key
                </div>
            </div>
            <div class="sp-card-body">
                <ol style="color:var(--text-secondary); padding-left:1.25rem; margin:0;">
                    <li><strong>Go to your Twitch Stream Dashboard:</strong> Visit <a href="https://dashboard.twitch.tv/" target="_blank">https://dashboard.twitch.tv/</a></li>
                    <li><strong>Navigate to Extensions:</strong> Click on "Extensions" in the left-hand menu panel</li>
                    <li><strong>Access My Extensions:</strong> On the Extensions page, click the "My Extensions" tab</li>
                    <li>Find the activated Stream Bounty extension</li>
                    <li><strong>Access Extension Settings:</strong> Click the settings cog wheel on the Stream Bounty extension</li>
                    <li><strong>Navigate to Bot Integration:</strong> When the configuration page opens, scroll down to the bottom of the left-hand menu panel and click "Bot Integration"</li>
                    <li><strong>Copy the API Key:</strong> Click the copy icon for the "API Key Management" section</li>
                    <li><strong>Paste and Save:</strong> Paste the API key in the field above and click the "Save API Key" button</li>
                </ol>
            </div>
        </div>

        <?php
        // Fetch bingo games from database
        $games_result = $db->query("SELECT * FROM bingo_games ORDER BY start_time DESC");
        $games = [];
        if ($games_result) {
            while ($row = $games_result->fetch_assoc()) {
                $games[] = $row;
            }
        }
        ?>
        <div class="sp-card">
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-history"></i>
                    Bingo Games History
                </div>
            </div>
            <div class="sp-card-body">
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>Game ID</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Events</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($games)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; color:var(--text-muted);">No bingo games found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($games as $game): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($game['game_id']); ?></td>
                                        <td><?php echo htmlspecialchars($game['start_time']); ?></td>
                                        <td><?php echo $game['end_time'] ? htmlspecialchars($game['end_time']) : 'Ongoing'; ?></td>
                                        <td><?php echo htmlspecialchars($game['events_count']); ?></td>
                                        <td>
                                            <span class="sp-badge <?php echo $game['status'] === 'active' ? 'sp-badge-green' : 'sp-badge-blue'; ?>">
                                                <?php echo htmlspecialchars(ucfirst($game['status'])); ?>
                                            </span>
                                        </td>
                                        <td style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                                            <button class="sp-btn sp-btn-info sp-btn-sm view-winners-btn"
                                                    data-game-id="<?php echo htmlspecialchars($game['game_id']); ?>">
                                                <i class="fas fa-trophy"></i>
                                                View Winners
                                            </button>
                                            <button class="sp-btn sp-btn-secondary sp-btn-sm view-players-btn"
                                                    data-game-id="<?php echo htmlspecialchars($game['game_id']); ?>">
                                                <i class="fas fa-users"></i>
                                                View Players
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Players Modal -->
<div class="db-modal-backdrop hidden" id="players-modal">
    <div class="db-modal">
        <div class="db-modal-head" style="background:var(--bg-surface);">
            <div class="db-modal-title" style="color:var(--text-primary);">
                <i class="fas fa-users"></i>
                Game Players &ndash; <span id="modal-players-game-id"></span>
            </div>
            <button class="db-modal-close" id="players-modal-close" aria-label="close">&times;</button>
        </div>
        <div class="db-modal-body" id="players-content">
            <!-- Players will be loaded here -->
        </div>
        <div class="db-modal-foot">
            <button class="sp-btn sp-btn-secondary" id="close-players-modal-btn">Close</button>
        </div>
    </div>
</div>

<!-- Winners Modal -->
<div class="db-modal-backdrop hidden" id="winners-modal">
    <div class="db-modal">
        <div class="db-modal-head" style="background:var(--bg-surface);">
            <div class="db-modal-title" style="color:var(--text-primary);">
                <i class="fas fa-trophy"></i>
                Game Winners &ndash; <span id="modal-game-id"></span>
            </div>
            <button class="db-modal-close" aria-label="close">&times;</button>
        </div>
        <div class="db-modal-body" id="winners-content">
            <!-- Winners will be loaded here -->
        </div>
        <div class="db-modal-foot">
            <button class="sp-btn sp-btn-secondary" id="close-modal-btn">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const apiKeyField = document.getElementById('api-key-field');
    const visibilityIcon = document.getElementById('api-key-visibility-icon');
    if (apiKeyField && visibilityIcon) {
        visibilityIcon.addEventListener('click', function() {
            if (apiKeyField.type === 'password') {
                apiKeyField.type = 'text';
                visibilityIcon.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                apiKeyField.type = 'password';
                visibilityIcon.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });
    }
    // Stream Bounty API functions
    const apiKey = '<?php echo addslashes($current_api_key); ?>';
    const twitchUserId = '<?php echo addslashes($twitchUserId); ?>';
    function callRandom() {
        fetch('https://api.stream-bingo.com/games/events/' + twitchUserId + '/' + apiKey + '/callrandom', {
            method: 'POST'
        })
        .then(response => {
            if (response.ok) {
                showNotification('Random number called successfully!', 'success');
            } else {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
        })
        .catch(error => {
            showNotification('Error calling random number: ' + error.message, 'danger');
        });
    }
    function callAll() {
        fetch('https://api.stream-bingo.com/games/events/' + twitchUserId + '/' + apiKey + '/callall', {
            method: 'POST'
        })
        .then(response => {
            if (response.ok) {
                showNotification('All numbers called successfully!', 'success');
            } else {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
        })
        .catch(error => {
            showNotification('Error calling all numbers: ' + error.message, 'danger');
        });
    }
    function startVote() {
        fetch('https://api.stream-bingo.com/games/voting/' + twitchUserId + '/' + apiKey + '/start', {
            method: 'POST'
        })
        .then(response => {
            if (response.ok) {
                showNotification('Vote started successfully!', 'success');
            } else {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
        })
        .catch(error => {
            showNotification('Error starting vote: ' + error.message, 'danger');
        });
    }
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = 'sp-alert sp-alert-' + type + ' sp-notif';
        notification.innerHTML = '<button class="sp-notif-close">&times;</button> ' + message;
        document.getElementById('bingo-alerts').prepend(notification);
        setTimeout(() => { notification.remove(); }, 5000);
        notification.querySelector('.sp-notif-close').addEventListener('click', () => {
            notification.remove();
        });
    }
    // Add event listeners to buttons if API key exists
    <?php if ($api_key_exists): ?>
    document.getElementById('call-random-btn').addEventListener('click', callRandom);
    document.getElementById('call-all-btn').addEventListener('click', callAll);
    document.getElementById('start-vote-btn').addEventListener('click', startVote);
    <?php endif; ?>

    // Modal functionality
    const winnersModal = document.getElementById('winners-modal');
    const closeModalBtn = document.getElementById('close-modal-btn');
    const modalCloseBtn = winnersModal.querySelector('.db-modal-close');
    const winnersContent = document.getElementById('winners-content');
    const modalGameId = document.getElementById('modal-game-id');

    // View players buttons
    const playersModal = document.getElementById('players-modal');
    const closePlayersModalBtn = document.getElementById('close-players-modal-btn');
    const playersModalCloseBtn = document.getElementById('players-modal-close');
    const playersContent = document.getElementById('players-content');
    const modalPlayersGameId = document.getElementById('modal-players-game-id');

    document.querySelectorAll('.view-players-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const gameId = this.getAttribute('data-game-id');
            loadPlayers(gameId);
        });
    });

    function loadPlayers(gameId) {
        modalPlayersGameId.textContent = gameId;
        playersContent.innerHTML = '<div style="text-align:center; padding:1.5rem; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading players...</div>';

        fetch('bingo_players.php?game_id=' + encodeURIComponent(gameId))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPlayers(data.players);
                } else {
                    playersContent.innerHTML = '<div class="sp-alert sp-alert-danger">Error loading players</div>';
                }
            })
            .catch(error => {
                playersContent.innerHTML = '<div class="sp-alert sp-alert-danger">Error loading players: ' + error.message + '</div>';
            });

        playersModal.classList.remove('hidden');
    }

    function displayPlayers(players) {
        if (players.length === 0) {
            playersContent.innerHTML = '<div class="sp-alert sp-alert-info">No players joined this game</div>';
            return;
        }

        let html = '<div style="margin-bottom:0.5rem; color:var(--text-muted); font-size:0.875rem;">' + players.length + ' player' + (players.length !== 1 ? 's' : '') + '</div>';
        players.forEach(function(player, index) {
            html += '<div style="display:flex; align-items:center; justify-content:space-between; padding:0.6rem 0; border-bottom:1px solid var(--border);">';
            html += '<div style="display:flex; align-items:center; gap:0.75rem;">';
            html += '<span class="sp-badge sp-badge-blue">' + (index + 1) + '</span>';
            html += '<strong>' + player.player_name + '</strong>';
            html += '</div>';
            html += '<small style="color:var(--text-muted);">' + player.joined_at + '</small>';
            html += '</div>';
        });
        playersContent.innerHTML = html;
    }

    function closePlayersModal() {
        playersModal.classList.add('hidden');
    }

    closePlayersModalBtn.addEventListener('click', closePlayersModal);
    playersModalCloseBtn.addEventListener('click', closePlayersModal);
    playersModal.addEventListener('click', function(e) {
        if (e.target === playersModal) closePlayersModal();
    });

    // View winners buttons
    document.querySelectorAll('.view-winners-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const gameId = this.getAttribute('data-game-id');
            loadWinners(gameId);
        });
    });

    function loadWinners(gameId) {
        modalGameId.textContent = gameId;
        winnersContent.innerHTML = '<div style="text-align:center; padding:1.5rem; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading winners...</div>';

        fetch('bingo_winners.php?game_id=' + encodeURIComponent(gameId))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayWinners(data.winners);
                } else {
                    winnersContent.innerHTML = '<div class="sp-alert sp-alert-danger">Error loading winners</div>';
                }
            })
            .catch(error => {
                winnersContent.innerHTML = '<div class="sp-alert sp-alert-danger">Error loading winners: ' + error.message + '</div>';
            });

        winnersModal.classList.remove('hidden');
    }

    function displayWinners(winners) {
        if (winners.length === 0) {
            winnersContent.innerHTML = '<div class="sp-alert sp-alert-info">No winners for this game yet</div>';
            return;
        }

        let html = '';
        winners.forEach((winner) => {
            const rankText = getRankText(winner.rank);
            const rankClass = getRankClass(winner.rank);
            html += '<div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 0; border-bottom:1px solid var(--border);">';
            html += '<div style="display:flex; align-items:center; gap:0.75rem;">';
            html += '<span class="sp-badge ' + rankClass + '">' + rankText + '</span>';
            html += '<strong>' + winner.player_name + '</strong>';
            html += '</div>';
            html += '<small style="color:var(--text-muted);">' + winner.timestamp + '</small>';
            html += '</div>';
        });
        winnersContent.innerHTML = html;
    }

    function getRankText(rank) {
        const rankNames = {
            1: '1st Place',
            2: '2nd Place',
            3: '3rd Place',
            4: '4th Place',
            5: '5th Place'
        };
        return rankNames[rank] || rank + 'th Place';
    }

    function getRankClass(rank) {
        const classes = {
            1: 'sp-badge-amber',
            2: 'sp-badge-grey',
            3: 'sp-badge-amber'
        };
        return classes[rank] || 'sp-badge-blue';
    }

    function closeModal() {
        winnersModal.classList.add('hidden');
    }

    closeModalBtn.addEventListener('click', closeModal);
    modalCloseBtn.addEventListener('click', closeModal);
    winnersModal.addEventListener('click', function(e) {
        if (e.target === winnersModal) closeModal();
    });
});
</script>

<?php
$content = ob_get_clean();
include "layout.php";
?>
