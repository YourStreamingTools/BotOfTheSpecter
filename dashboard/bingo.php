<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
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
<div class="columns is-centered">
    <div class="column is-12">
        <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-trophy"></i></span>
                    Stream Bounty Integration
                </span>
                <div class="card-header-icon">
                    <button class="button is-primary mr-2 <?php echo $api_key_exists ? '' : 'is-disabled'; ?>" id="call-random-btn" <?php echo $api_key_exists ? '' : 'disabled'; ?>>Call Random</button>
                    <button class="button is-primary mr-2 <?php echo $api_key_exists ? '' : 'is-disabled'; ?>" id="call-all-btn" <?php echo $api_key_exists ? '' : 'disabled'; ?>>Call All</button>
                    <button class="button is-primary <?php echo $api_key_exists ? '' : 'is-disabled'; ?>" id="start-vote-btn" <?php echo $api_key_exists ? '' : 'disabled'; ?>>Start Vote</button>
                </div>
            </header>
            <div class="card-content">
                <div class="content">
                    <?php if (isset($message)): ?>
                        <div class="notification <?php echo strpos($message, 'successfully') !== false ? 'is-success' : 'is-danger'; ?>">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$api_key_exists): ?>
                        <div class="notification is-warning">
                            <strong>API Key Required:</strong> Please enter your Stream Bounty API key below to enable the bingo game controls.
                        </div>
                    <?php endif; ?>
                    <div class="box has-background-grey-darker has-text-white" style="margin-bottom: 1.5rem;">
                        <h4 class="title is-5 has-text-white">
                            <span class="icon mr-2"><i class="fas fa-wrench"></i></span>
                            Twitch Extension Configuration
                        </h4>
                        <p class="subtitle is-6 has-text-grey-light">Enter your Stream Bounty API Key below. This key will be used to integrate with the bingo games.</p>
                        <form method="post" action="">
                            <div class="field">
                                <label class="label has-text-white">API Key</label>
                                <div class="control has-icons-right">
                                    <input class="input" type="password" name="api_key" value="<?php echo htmlspecialchars($current_api_key); ?>" placeholder="Enter your API Key" required id="api-key-field">
                                    <span class="icon is-small is-right" id="api-key-visibility-icon" style="pointer-events: none;">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>
                                <p class="help has-text-grey-light">Click the eye icon to show/hide the API key</p>
                            </div>
                            <div class="field">
                                <div class="control">
                                    <button class="button is-primary" type="submit">Save API Key</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="box has-background-grey-darker has-text-white">
                        <h4 class="title is-5 has-text-white">
                            <span class="icon mr-2"><i class="fas fa-question-circle"></i></span>
                            How to Get Your Stream Bounty API Key
                        </h4>
                        <div class="content">
                            <ol style="color: #dbdbdb;">
                                <li><strong>Go to your Twitch Stream Dashboard:</strong> Visit <a href="https://dashboard.twitch.tv/" target="_blank" class="has-text-link">https://dashboard.twitch.tv/</a></li>
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
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const apiKeyField = document.getElementById('api-key-field');
    const visibilityIcon = document.getElementById('api-key-visibility-icon');
    if (apiKeyField && visibilityIcon) {
        visibilityIcon.style.pointerEvents = 'auto';
        visibilityIcon.style.cursor = 'pointer';
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
        fetch(`https://api.stream-bounty.com/games/events/${twitchUserId}/${apiKey}/callrandom`, {
            method: 'POST'
        })
        .then(response => {
            if (response.ok) {
                showNotification('Random number called successfully!', 'is-success');
            } else {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
        })
        .catch(error => {
            showNotification('Error calling random number: ' + error.message, 'is-danger');
        });
    }
    function callAll() {
        fetch(`https://api.stream-bingo.com/games/events/${twitchUserId}/${apiKey}/callall`, {
            method: 'POST'
        })
        .then(response => {
            if (response.ok) {
                showNotification('All numbers called successfully!', 'is-success');
            } else {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
        })
        .catch(error => {
            showNotification('Error calling all numbers: ' + error.message, 'is-danger');
        });
    }
    function startVote() {
        fetch(`https://api.stream-bingo.com/games/voting/${twitchUserId}/${apiKey}/start`, {
            method: 'POST'
        })
        .then(response => {
            if (response.ok) {
                showNotification('Vote started successfully!', 'is-success');
            } else {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
        })
        .catch(error => {
            showNotification('Error starting vote: ' + error.message, 'is-danger');
        });
    }
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = 'notification ' + type;
        notification.innerHTML = '<button class="delete"></button>' + message;
        document.querySelector('.card-content .content').prepend(notification);
        // Auto-remove after 5 seconds
        setTimeout(() => { notification.remove(); }, 5000);
        // Delete button functionality
        notification.querySelector('.delete').addEventListener('click', () => {
            notification.remove();
        });
    }
    // Add event listeners to buttons if API key exists
    <?php if ($api_key_exists): ?>
    document.getElementById('call-random-btn').addEventListener('click', callRandom);
    document.getElementById('call-all-btn').addEventListener('click', callAll);
    document.getElementById('start-vote-btn').addEventListener('click', startVote);
    <?php endif; ?>
});
</script>

<?php
$content = ob_get_clean();
include "layout.php";
?>
