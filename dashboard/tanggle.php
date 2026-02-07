<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Header
$pageTitle = "Tanggle Integration";
$pageDescription = "Configure Tanggle puzzle integration settings";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'storage_used.php';

require_once '/var/www/config/database.php';
$dbname = $username;
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Handle POST request to save credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['api_token']) || isset($_POST['community_uuid']))) {
    $api_token = trim($_POST['api_token'] ?? '');
    $community_uuid = trim($_POST['community_uuid'] ?? '');
    if (!empty($api_token) && !empty($community_uuid)) {
        // Ensure a profile row exists; if not insert, otherwise update
        $checkStmt = mysqli_prepare($db, "SELECT COUNT(*) as cnt FROM profile");
        if ($checkStmt) {
            mysqli_stmt_execute($checkStmt);
            $checkRes = mysqli_stmt_get_result($checkStmt);
            $row = mysqli_fetch_assoc($checkRes);
            mysqli_stmt_close($checkStmt);
        } else {
            $row = ['cnt' => 0];
        }
        if (!isset($row['cnt']) || $row['cnt'] == 0) {
            $stmt = mysqli_prepare($db, "INSERT INTO profile (tanggle_api_token, tanggle_community_uuid) VALUES (?, ?)");
        } else {
            $stmt = mysqli_prepare($db, "UPDATE profile SET tanggle_api_token = ?, tanggle_community_uuid = ?");
        }
        if ($stmt === false) {
            $message = "Database error: " . mysqli_error($db);
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $api_token, $community_uuid);
            if (mysqli_stmt_execute($stmt)) {
                $message = "Tanggle credentials saved successfully!";
            } else {
                $message = "Failed to save Tanggle credentials: " . mysqli_error($db);
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $message = "Please enter both API Token and Community UUID.";
    }
}

// Get current credentials
$current_api_token = '';
$current_community_uuid = '';
$result = $db->query("SELECT tanggle_api_token, tanggle_community_uuid FROM profile LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $current_api_token = $row['tanggle_api_token'] ?? '';
    $current_community_uuid = $row['tanggle_community_uuid'] ?? '';
}
$credentials_exist = !empty($current_api_token) && !empty($current_community_uuid);

// Fetch active puzzle room if credentials exist
$active_room = null;
$api_error = null;
if ($credentials_exist) {
    $tanggle_base_url = 'https://api.tanggle.io';
    $rooms_url = "$tanggle_base_url/communities/$current_community_uuid/rooms";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rooms_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $current_api_token,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code === 200 && $response) {
        $rooms_data = json_decode($response, true);
        if (isset($rooms_data['items']) && count($rooms_data['items']) > 0) {
            $first_room_uuid = $rooms_data['items'][0]['uuid'];
            $room_url = "$tanggle_base_url/communities/$current_community_uuid/rooms/$first_room_uuid";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $room_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $current_api_token,
                'Content-Type: application/json'
            ]);
            $room_response = curl_exec($ch);
            $room_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($room_http_code === 200 && $room_response) {
                $room_data = json_decode($room_response, true);
                if (isset($room_data['success']) && $room_data['success'] && isset($room_data['room'])) {
                    $active_room = $room_data['room'];
                }
            }
        }
    } else {
        $api_error = "Failed to fetch room data from Tanggle API";
    }

    // Fetch queue
    $queue_items = [];
    $queue_url = "$tanggle_base_url/communities/$current_community_uuid/queue";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $queue_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $current_api_token,
        'Content-Type: application/json'
    ]);
    $queue_response = curl_exec($ch);
    $queue_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($queue_http_code === 200 && $queue_response) {
        $queue_data = json_decode($queue_response, true);
        if (is_array($queue_data)) {
            $queue_items = $queue_data;
        }
    }
}

ob_start();
?>
<div class="columns is-centered">
    <div class="column is-12">
        <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-puzzle-piece"></i></span>
                    Tanggle Integration
                </span>
            </header>
            <div class="card-content">
                <div class="content">
                    <?php if (isset($message)): ?>
                        <div
                            class="notification <?php echo strpos($message, 'successfully') !== false ? 'is-success' : 'is-danger'; ?>">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$credentials_exist): ?>
                        <div class="notification is-warning">
                            <strong>Configuration Required:</strong> Please enter your Tanggle API credentials below to
                            enable puzzle integration.
                        </div>
                    <?php endif; ?>
                    <div class="box has-background-grey-darker has-text-white" style="margin-bottom: 1.5rem;">
                        <h4 class="title is-5 has-text-white">
                            <span class="icon mr-2"><i class="fas fa-wrench"></i></span>
                            Tanggle Configuration
                        </h4>
                        <p class="subtitle is-6 has-text-grey-light">Enter your Tanggle API credentials below. These
                            will be used to integrate with Tanggle puzzles.</p>
                        <form method="post" action="">
                            <div class="field">
                                <label class="label has-text-white">API Access Token</label>
                                <div class="control has-icons-right">
                                    <input class="input" type="password" name="api_token"
                                        value="<?php echo htmlspecialchars($current_api_token); ?>"
                                        placeholder="Enter your API Access Token" required id="api-token-field">
                                    <span class="icon is-small is-right" id="api-token-visibility-icon"
                                        style="pointer-events: none;">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>
                                <p class="help has-text-grey-light">Click the eye icon to show/hide the API token</p>
                            </div>
                            <div class="field">
                                <label class="label has-text-white">Community UUID</label>
                                <div class="control">
                                    <input class="input" type="text" name="community_uuid"
                                        value="<?php echo htmlspecialchars($current_community_uuid); ?>"
                                        placeholder="Enter your Community UUID" required id="community-uuid-field">
                                </div>
                                <p class="help has-text-grey-light">Example: 12345678-1234-1234-1234-123456789012</p>
                            </div>
                            <div class="field">
                                <div class="control">
                                    <button class="button is-primary" type="submit">Save Credentials</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php if (!$credentials_exist): ?>
                        <div class="box has-background-grey-darker has-text-white">
                            <h4 class="title is-5 has-text-white">
                                <span class="icon mr-2"><i class="fas fa-question-circle"></i></span>
                                How to Get Your Tanggle Credentials
                            </h4>
                            <div class="content">
                                <h5 class="subtitle is-6 has-text-white">API Access Token:</h5>
                                <ol style="color: #dbdbdb;">
                                    <li><strong>Navigate to your Tanggle community:</strong> Go to <a
                                            href="https://tanggle.io" target="_blank"
                                            class="has-text-link">https://tanggle.io</a></li>
                                    <li><strong>Access Administration:</strong> Click on your community settings or
                                        administration panel</li>
                                    <li><strong>Go to Integrations:</strong> Find and click on the "Integrations" section
                                    </li>
                                    <li><strong>Open Custom Tab:</strong> Click on the "Custom" tab within Integrations</li>
                                    <li><strong>Copy API Token:</strong> Click the "Copy token" button to copy your API
                                        access token</li>
                                    <li><strong>Paste Here:</strong> Paste the token in the "API Access Token" field above
                                    </li>
                                </ol>
                                <h5 class="subtitle is-6 has-text-white" style="margin-top: 1.5rem;">Community UUID:</h5>
                                <ol style="color: #dbdbdb;">
                                    <li><strong>View Your Community Page:</strong> Navigate to your community on Tanggle
                                    </li>
                                    <li><strong>Check the URL:</strong> Look at your browser's address bar</li>
                                    <li><strong>Find the UUID:</strong> The URL will look like
                                        <code>tanggle.io/community/12345678-1234-1234-1234-123456789012</code>
                                    </li>
                                    <li><strong>Copy the UUID:</strong> Copy the long string after <code>/community/</code>
                                        (e.g., <code>12345678-1234-1234-1234-123456789012</code>)</li>
                                    <li><strong>Paste Here:</strong> Paste the UUID in the "Community UUID" field above</li>
                                </ol>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($credentials_exist): ?>
                        <?php if ($api_error): ?>
                            <div class="notification is-danger">
                                <strong>API Error:</strong> <?php echo htmlspecialchars($api_error); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($active_room): ?>
                            <div class="box has-background-grey-darker has-text-white">
                                <h4 class="title is-5 has-text-white">
                                    <span class="icon mr-2"><i class="fas fa-puzzle-piece"></i></span>
                                    Active Puzzle
                                </h4>
                                <div class="columns">
                                    <div class="column is-4">
                                        <figure class="image">
                                            <img src="<?php echo htmlspecialchars($active_room['image']['sources']['preview'][3]['url'] ?? ''); ?>"
                                                alt="Puzzle Preview" style="border-radius: 8px;">
                                        </figure>
                                    </div>
                                    <div class="column is-8">
                                        <h5 class="title is-6 has-text-white">
                                            <?php echo htmlspecialchars($active_room['image']['attribution']['title'] ?? 'Untitled Puzzle'); ?>
                                        </h5>
                                        <div class="content has-text-grey-light">
                                            <p>
                                                <strong>Status:</strong>
                                                <?php if ($active_room['isCompleted']): ?>
                                                    <span class="tag is-success">Completed</span>
                                                <?php else: ?>
                                                    <span class="tag is-info">In Progress</span>
                                                <?php endif; ?>
                                            </p>
                                            <p>
                                                <strong>Pieces:</strong>
                                                <?php echo $active_room['pieces']['completed']; ?> /
                                                <?php echo $active_room['pieces']['count']; ?>
                                                (<?php echo round($active_room['pieces']['completedRate'] * 100, 1); ?>%)
                                            </p>
                                            <p>
                                                <strong>Grid:</strong>
                                                <?php echo $active_room['pieces']['x']; ?>x<?php echo $active_room['pieces']['y']; ?>
                                            </p>
                                            <p>
                                                <strong>Players:</strong>
                                                <?php echo $active_room['playerCount']; ?> /
                                                <?php echo $active_room['playerLimit']; ?>
                                            </p>
                                            <?php if (isset($active_room['image']['attribution']['creator']['name'])): ?>
                                                <p>
                                                    <strong>Creator:</strong>
                                                    <?php echo htmlspecialchars($active_room['image']['attribution']['creator']['name']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <p>
                                                <a href="<?php echo htmlspecialchars($active_room['redirectUrl']); ?>"
                                                    target="_blank" class="button is-primary is-small">
                                                    <span class="icon"><i class="fas fa-external-link-alt"></i></span>
                                                    <span>Open Puzzle</span>
                                                </a>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (!$api_error): ?>
                            <div class="notification is-info">
                                <strong>No Active Puzzle:</strong> There are currently no active puzzle rooms in your community.
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($queue_items)): ?>
                            <div class="box has-background-grey-darker has-text-white" style="margin-top: 1.5rem;">
                                <h4 class="title is-5 has-text-white">
                                    <span class="icon mr-2"><i class="fas fa-list"></i></span>
                                    Puzzle Queue (<?php echo count($queue_items); ?> puzzles)
                                </h4>
                                <div class="columns is-multiline">
                                    <?php foreach ($queue_items as $queue_item): ?>
                                        <div class="column is-4">
                                            <div class="card has-background-grey has-text-white">
                                                <div class="card-image">
                                                    <figure class="image is-4by3">
                                                        <img src="<?php echo htmlspecialchars($queue_item['image']['sources']['preview'][3]['url'] ?? ''); ?>"
                                                            alt="<?php echo htmlspecialchars($queue_item['image']['attribution']['title'] ?? 'Puzzle'); ?>"
                                                            style="object-fit: cover;">
                                                    </figure>
                                                </div>
                                                <div class="card-content" style="padding: 1rem;">
                                                    <p class="title is-6 has-text-white" style="margin-bottom: 0.5rem;">
                                                        <?php echo htmlspecialchars($queue_item['image']['attribution']['title'] ?? 'Untitled'); ?>
                                                    </p>
                                                    <p class="subtitle is-7 has-text-grey-light" style="margin-bottom: 0.5rem;">
                                                        Position: #<?php echo $queue_item['position']; ?>
                                                    </p>
                                                    <p class="is-size-7 has-text-grey-light">
                                                        <strong>Grid:</strong>
                                                        <?php echo $queue_item['body']['pieces']['x']; ?>x<?php echo $queue_item['body']['pieces']['y']; ?>
                                                        (<?php echo ($queue_item['body']['pieces']['x'] * $queue_item['body']['pieces']['y']); ?>
                                                        pieces)
                                                    </p>
                                                    <?php if (isset($queue_item['image']['attribution']['creator']['name'])): ?>
                                                        <p class="is-size-7 has-text-grey-light">
                                                            <strong>By:</strong>
                                                            <?php echo htmlspecialchars($queue_item['image']['attribution']['creator']['name']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const apiTokenField = document.getElementById('api-token-field');
        const visibilityIcon = document.getElementById('api-token-visibility-icon');
        if (apiTokenField && visibilityIcon) {
            visibilityIcon.style.pointerEvents = 'auto';
            visibilityIcon.style.cursor = 'pointer';
            visibilityIcon.addEventListener('click', function () {
                if (apiTokenField.type === 'password') {
                    apiTokenField.type = 'text';
                    visibilityIcon.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    apiTokenField.type = 'password';
                    visibilityIcon.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
        }
    });
</script>
<?php
$content = ob_get_clean();
include "layout.php";
?>