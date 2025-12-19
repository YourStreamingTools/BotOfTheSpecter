<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Start output buffering immediately to prevent any accidental output
ob_start();

session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '/var/www/config/twitch.php';
$pageTitle = 'Start User Bots';

// Token cache path
$tokenCacheFile = '/var/www/cache/tokens/start_bot_tokens.json';

function ensureTokenCacheDirExists($filePath) {
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function readTokenCacheFile($filePath) {
    if (!file_exists($filePath)) return [];
    $contents = @file_get_contents($filePath);
    if ($contents === false) return [];
    $data = json_decode($contents, true);
    return is_array($data) ? $data : [];
}

function writeTokenCacheFile($filePath, $data) {
    ensureTokenCacheDirExists($filePath);
    $tmp = $filePath . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $filePath);
}

function getTokenCacheEntry($filePath, $twitchId) {
    $cache = readTokenCacheFile($filePath);
    return $cache[$twitchId] ?? null;
}

function setTokenCacheEntry($filePath, $twitchId, $entry) {
    $cache = readTokenCacheFile($filePath);
    $cache[$twitchId] = $entry;
    return writeTokenCacheFile($filePath, $cache);
}

function removeTokenCacheEntry($filePath, $twitchId) {
    $cache = readTokenCacheFile($filePath);
    if (isset($cache[$twitchId])) {
        unset($cache[$twitchId]);
        return writeTokenCacheFile($filePath, $cache);
    }
    return true;
}

// Lightweight wrapper to provide a start_bot_for_user() function when missing.
// This uses the central performBotAction() implementation in dashboard/bot_control_functions.php.
if (!function_exists('start_bot_for_user')) {
    function start_bot_for_user($username, $botType = 'stable') {
        global $conn;
        // Load tokens and api_key for the user
        $stmt = $conn->prepare("SELECT twitch_user_id, access_token, refresh_token, api_key FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) return 'Database error preparing token lookup';
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return 'User not found';
        $twitchUserId = $row['twitch_user_id'] ?? '';
        $accessToken = $row['access_token'] ?? '';
        $refreshToken = $row['refresh_token'] ?? '';
        // Prefer the per-user api_key stored in users table, fallback to any global
        $apiKey = $row['api_key'] ?? ($GLOBALS['bots_api_key'] ?? $GLOBALS['api_key'] ?? $GLOBALS['BOT_API_KEY'] ?? '');
        // If performBotAction exists, delegate to it
        if (function_exists('performBotAction')) {
            $params = [
                'username' => $username,
                'twitch_user_id' => $twitchUserId,
                'auth_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'api_key' => $apiKey
            ];
            $res = performBotAction('run', $botType, $params);
            // performBotAction returns an array; normalize to true/string expected by caller
            if (is_array($res)) return $res;
            return $res;
        }
        return 'No bot start implementation available';
    }
}

// Check for AJAX requests IMMEDIATELY - before any other includes
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_running_bots'])) {
    // Clean ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    try {
            // Clean ALL output buffers and start a fresh buffer to capture incidental output
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_start();
        // Check if SSH config is available
        if (!isset($bots_ssh_host) || !isset($bots_ssh_username) || !isset($bots_ssh_password)) {
            echo json_encode(['success' => false, 'message' => 'SSH configuration not available', 'bots' => []]);
            exit;
        }
        // Use the bot control helper to check each user's stable bot status (matches admin index behaviour)
        require_once __DIR__ . '/../bot_control_functions.php';
        $running_bots = [];
        // Fetch all users to check their bot status
        $stmtUsers = $conn->prepare("SELECT username FROM users");
        if ($stmtUsers && $stmtUsers->execute()) {
            $res = $stmtUsers->get_result();
            while ($u = $res->fetch_assoc()) {
                $uname = $u['username'];
                try {
                    $status = checkBotRunning($uname, 'stable');
                    if (isset($status['running']) && $status['running']) {
                        $running_bots[] = [
                            'username' => $uname,
                            'pid' => $status['pid'] ?? 'unknown',
                            'version' => $status['version'] ?? ''
                        ];
                    }
                } catch (Exception $e) {
                    // Ignore per-user errors but capture in debug
                    error_log('checkBotRunning error for ' . $uname . ': ' . $e->getMessage());
                }
            }
            $stmtUsers->close();
        } else {
            $debug = ob_get_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to fetch users from database', 'bots' => [], 'debug' => $debug]);
            exit;
        }
            $debug = ob_get_clean();
            echo json_encode(['success' => true, 'bots' => $running_bots, 'debug' => $debug]);
    } catch (Exception $e) {
            $debug = ob_get_clean();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'bots' => [], 'debug' => $debug]);
    }
    exit;
}

// Handle AJAX request to validate a user's token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_user_token'])) {
    // Clean any output that may have been generated
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        // Clean any output that may have been generated and capture incidental output
        while (ob_get_level()) ob_end_clean();
        ob_start();
        $twitch_user_id = $_POST['twitch_user_id'] ?? '';
        if (empty($twitch_user_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing twitch_user_id']);
            exit;
        }
        // Fetch the user's access token from database
        $stmt = $conn->prepare("SELECT access_token FROM users WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No token found for user']);
            exit;
        }
        $row = $result->fetch_assoc();
        $access_token = $row['access_token'];
        $stmt->close();
        // Check cache first
        $cacheEntry = getTokenCacheEntry($tokenCacheFile, $twitch_user_id);
        if ($cacheEntry && isset($cacheEntry['expires_at']) && $cacheEntry['expires_at'] > time()) {
            $expires_in = $cacheEntry['expires_at'] - time();
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'valid' => true,
                'expires_in' => $expires_in,
                'message' => 'Token is valid (cached)',
                'debug' => $debug,
                'cached' => true
            ]);
            exit;
        }

        // Validate the token with Twitch
        $url = "https://id.twitch.tv/oauth2/validate";
        $headers = ["Authorization: OAuth $access_token"];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            // Update cache with expires_at
            $expires = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
            if ($expires > 0) {
                $entry = ['access_token' => $access_token, 'expires_at' => time() + $expires];
                @setTokenCacheEntry($tokenCacheFile, $twitch_user_id, $entry);
            }
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'valid' => true,
                'expires_in' => $data['expires_in'] ?? 0,
                'message' => 'Token is valid',
                'debug' => $debug
            ]);
        } else {
                // Remove potentially stale cache entry
                @removeTokenCacheEntry($tokenCacheFile, $twitch_user_id);
                $debug = ob_get_clean();
                echo json_encode([
                    'success' => true,
                    'valid' => false,
                    'message' => 'Token is invalid or expired',
                    'debug' => $debug
                ]);
        }
    } catch (Exception $e) {
            $debug = ob_get_clean();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Handle AJAX request to renew a user's token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_user_token'])) {
    // Clean any output that may have been generated
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        // Clean any output that may have been generated and capture incidental output
        while (ob_get_level()) ob_end_clean();
        ob_start();
        $twitch_user_id = $_POST['twitch_user_id'] ?? '';
        if (empty($twitch_user_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing twitch_user_id']);
            exit;
        }
        // Fetch the user's refresh token from database
        $stmt = $conn->prepare("SELECT refresh_token FROM users WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No refresh token found for user']);
            exit;
        }
        $row = $result->fetch_assoc();
        $refresh_token = $row['refresh_token'];
        $stmt->close();
        // Renew the token with Twitch
        $url = "https://id.twitch.tv/oauth2/token";
        $postFields = http_build_query([
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        ]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $new_access_token = $data['access_token'];
            $new_refresh_token = $data['refresh_token'];
            // Update the database with new tokens
            $stmt = $conn->prepare("UPDATE users SET access_token = ?, refresh_token = ? WHERE twitch_user_id = ?");
            $stmt->bind_param("sss", $new_access_token, $new_refresh_token, $twitch_user_id);
            if ($stmt->execute()) {
                $stmt->close();
                    // Update cache with new token expiry
                    $expires = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
                    if ($expires > 0) {
                        $entry = ['access_token' => $new_access_token, 'expires_at' => time() + $expires];
                        @setTokenCacheEntry($tokenCacheFile, $twitch_user_id, $entry);
                    }
                    $debug = ob_get_clean();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Token renewed successfully',
                        'expires_in' => $data['expires_in'] ?? 0,
                        'debug' => $debug
                    ]);
            } else {
                // Log DB update failure for debugging
                $dbErr = $stmt->error;
                error_log('Failed to update auth tokens for twitch_user_id=' . $twitch_user_id . ' stmt_error=' . $dbErr);
                $stmt->close();
                    $debug = ob_get_clean();
                    echo json_encode(['success' => false, 'message' => 'Failed to update database', 'debug' => $debug, 'error_details' => ['db_error' => $dbErr]]);
            }
        } else {
                // Log Twitch renewal failure for debugging
                $respSnippet = substr((string)$response, 0, 200);
                error_log('Twitch token renew failed for twitch_user_id=' . $twitch_user_id . ' http_code=' . $httpCode . ' curl_error=' . $curlError . ' response=' . $respSnippet);
                $debug = ob_get_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to renew token with Twitch', 'debug' => $debug, 'error_details' => ['http_code' => $httpCode, 'curl_error' => $curlError, 'response' => $respSnippet]]);
        }
        } catch (Exception $e) {
            $debug = ob_get_clean();
            error_log('Exception in renew_user_token: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug, 'error_details' => ['exception' => $e->getMessage()]]);
        }
    exit;
}

// Handle AJAX request to start bot for user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_user_bot'])) {
    // Clean any output that may have been generated and start a fresh buffer
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../bot_control_functions.php';
    $username = trim($_POST['username'] ?? '');
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        exit;
    }
    try {
        // Start the stable bot for the user while capturing incidental output
        $handledShutdown = false;
        // Ensure a fresh buffer to capture any HTML/error output produced during bot startup
        while (ob_get_level()) ob_end_clean();
        ob_start();
        register_shutdown_function(function() use (&$handledShutdown) {
            $err = error_get_last();
            if ($err && !$handledShutdown) {
                $debug = '';
                if (ob_get_level()) $debug = ob_get_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Fatal error during bot start', 'debug' => $debug, 'error_details' => ['shutdown_error' => $err]]);
                exit;
            }
        });
        try {
            $result = start_bot_for_user($username, 'stable');
            $debug = '';
            if (ob_get_level()) $debug = ob_get_clean();
            $handledShutdown = true;
            if (is_array($result)) {
                $ok = $result['success'] ?? false;
                $msg = $result['message'] ?? '';
                $pid = $result['pid'] ?? null;
                echo json_encode(['success' => $ok, 'message' => $msg, 'pid' => $pid, 'debug' => $debug, 'details' => $result]);
            } elseif ($result === true) {
                echo json_encode(['success' => true, 'message' => 'Bot started successfully', 'debug' => $debug]);
            } else {
                echo json_encode(['success' => false, 'message' => $result, 'debug' => $debug]);
            }
        } catch (Throwable $e) {
            $debug = '';
            if (ob_get_level()) $debug = ob_get_clean();
            $handledShutdown = true;
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug, 'error_details' => ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]]);
        }
    } catch (Exception $e) {
           $debug = ob_get_clean();
           echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Include userdata.php AFTER all AJAX handling to prevent output corruption
include '../userdata.php';

// Fetch all users from database
$users = [];
$stmt = $conn->prepare("SELECT id, username, twitch_user_id, twitch_display_name, profile_image FROM users ORDER BY id");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<div class="box">
    <h1 class="title is-4"><span class="icon"><i class="fas fa-play-circle"></i></span> Start User Bots</h1>
    <p class="mb-4">Validate tokens and start stable bots for users. The system will automatically check if the token is valid and renew it if necessary before starting the bot.</p>
    
    <div class="buttons mb-4">
        <button class="button is-info" onclick="refreshBotStatus()">
            <span class="icon"><i class="fas fa-sync-alt"></i></span>
            <span>Refresh Status</span>
        </button>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Twitch ID</th>
                    <th>Bot Status</th>
                    <th>Token Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="users-table-body">
                <?php foreach ($users as $user): ?>
                <tr data-username="<?php echo htmlspecialchars($user['username']); ?>" 
                    data-twitch-id="<?php echo htmlspecialchars($user['twitch_user_id']); ?>">
                    <td>
                        <div class="is-flex is-align-items-center">
                            <figure class="image is-32x32 mr-2">
                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($user['username']); ?>" 
                                     class="is-rounded">
                            </figure>
                            <span><?php echo htmlspecialchars($user['twitch_display_name'] ?: $user['username']); ?></span>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($user['twitch_user_id']); ?></td>
                    <td>
                        <span class="tag bot-status-tag" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                            <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
                            <span>Checking...</span>
                        </span>
                    </td>
                    <td>
                        <span class="tag token-status-tag" data-twitch-id="<?php echo htmlspecialchars($user['twitch_user_id']); ?>">
                            <span class="icon"><i class="fas fa-question"></i></span>
                            <span>Unknown</span>
                        </span>
                    </td>
                    <td>
                        <div class="buttons">
                            <button class="button is-small is-info validate-token-btn" 
                                    onclick="validateUserToken('<?php echo htmlspecialchars($user['twitch_user_id']); ?>')">
                                <span class="icon"><i class="fas fa-check-circle"></i></span>
                                <span>Validate Token</span>
                            </button>
                            <button class="button is-small is-warning renew-token-btn" 
                                    onclick="renewUserToken('<?php echo htmlspecialchars($user['twitch_user_id']); ?>')"
                                    title="Attempt to renew this user's Twitch token">
                                <span class="icon"><i class="fas fa-sync-alt"></i></span>
                                <span>Renew Token</span>
                            </button>
                            <button class="button is-small is-success start-bot-btn" 
                                    onclick="startUserBot('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>')"
                                    disabled>
                                <span class="icon"><i class="fas fa-play"></i></span>
                                <span>Start Bot</span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
let runningBots = [];
// Helper to escape HTML for safe display in alerts
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
document.addEventListener('DOMContentLoaded', function() {
    // Load running bots status on page load
    refreshBotStatus();
});
function refreshBotStatus() {
    fetch('?get_running_bots=1')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                runningBots = data.bots;
                // Hide rows for running bots and validate tokens for non-running users
                const rows = Array.from(document.querySelectorAll('#users-table-body tr'));
                let validateDelay = 0;
                rows.forEach(row => {
                    const uname = row.getAttribute('data-username');
                    const twitchId = row.getAttribute('data-twitch-id');
                    const isRunning = runningBots.find(b => b.username === uname);
                    if (isRunning) {
                        // Hide running users from the table
                        row.style.display = 'none';
                    } else {
                        // Ensure visible
                        row.style.display = '';
                        // Update bot status tag for not running
                        const botTag = row.querySelector('.bot-status-tag');
                        if (botTag) {
                            botTag.className = 'tag is-danger bot-status-tag';
                            botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Not Running</span>';
                        }
                        // Stagger token validation to avoid hammering the server
                        if (twitchId) {
                            setTimeout(() => validateUserToken(twitchId), validateDelay);
                            validateDelay += 200; // 200ms between validations
                        }
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error fetching running bots:', error);
        });
}
function updateBotStatusDisplay() {
    // Only update visible rows (those not hidden because bot is running)
    document.querySelectorAll('#users-table-body tr').forEach(row => {
        if (row.style.display === 'none') return;
        const tag = row.querySelector('.bot-status-tag');
        const username = tag ? tag.getAttribute('data-username') : row.getAttribute('data-username');
        const isRunning = runningBots.find(bot => bot.username === username);
        if (tag) {
            if (isRunning) {
                tag.className = 'tag is-success bot-status-tag';
                tag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Running (PID: ' + isRunning.pid + ')</span>';
            } else {
                tag.className = 'tag is-danger bot-status-tag';
                tag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Not Running</span>';
            }
        }
    });
}
async function validateUserToken(twitchUserId) {
    const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
    const tokenTag = row.querySelector('.token-status-tag');
    const validateBtn = row.querySelector('.validate-token-btn');
    validateBtn.disabled = true;
    validateBtn.classList.add('is-loading');
    tokenTag.className = 'tag is-info token-status-tag';
    tokenTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Validating...</span>';
    try {
        const formData = new FormData();
        formData.append('validate_user_token', '1');
        formData.append('twitch_user_id', twitchUserId);
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success && data.valid) {
            tokenTag.className = 'tag is-success token-status-tag';
            const hours = Math.floor(data.expires_in / 3600);
            tokenTag.innerHTML = `<span class="icon"><i class="fas fa-check-circle"></i></span><span>Valid (${hours}h)</span>`;
            // Enable start button if token is valid
            const startBtn = row.querySelector('.start-bot-btn');
            startBtn.disabled = false;
            // Disable renew button when token is valid
            const renewBtn = row.querySelector('.renew-token-btn');
            if (renewBtn) renewBtn.disabled = true;
        } else {
            // Token invalid — attempt an automatic silent renewal
            tokenTag.className = 'tag is-warning token-status-tag';
            tokenTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>Invalid — attempting renew...</span>';
            const renewed = await renewUserToken(twitchUserId, true);
            if (renewed) {
                tokenTag.className = 'tag is-success token-status-tag';
                tokenTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Renewed</span>';
                const startBtn = row.querySelector('.start-bot-btn');
                if (startBtn) startBtn.disabled = false;
                const renewBtn = row.querySelector('.renew-token-btn');
                if (renewBtn) { renewBtn.disabled = true; renewBtn.classList.remove('is-loading'); }
            } else {
                tokenTag.className = 'tag is-danger token-status-tag';
                tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Renewal Failed</span>';
                const renewBtn = row.querySelector('.renew-token-btn');
                if (renewBtn) { renewBtn.disabled = false; renewBtn.classList.remove('is-loading'); }
            }
        }
    } catch (error) {
        tokenTag.className = 'tag is-danger token-status-tag';
        tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
        console.error('Error validating token:', error);
    } finally {
        validateBtn.disabled = false;
        validateBtn.classList.remove('is-loading');
    }
}

async function renewUserToken(twitchUserId, silent = false) {
    const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
    const tokenTag = row.querySelector('.token-status-tag');
    const renewBtn = row.querySelector('.renew-token-btn');
    if (renewBtn) { renewBtn.disabled = true; renewBtn.classList.add('is-loading'); }
    tokenTag.className = 'tag is-info token-status-tag';
    tokenTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Renewing...</span>';
    try {
        const formData = new FormData();
        formData.append('renew_user_token', '1');
        formData.append('twitch_user_id', twitchUserId);
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) {
            tokenTag.className = 'tag is-success token-status-tag';
            const hours = Math.floor(data.expires_in / 3600);
            tokenTag.innerHTML = `<span class="icon"><i class="fas fa-check-circle"></i></span><span>Renewed (${hours}h)</span>`;
            // Enable start button after renewal
            const startBtn = row.querySelector('.start-bot-btn');
            startBtn.disabled = false;
            if (renewBtn) { renewBtn.disabled = true; renewBtn.classList.remove('is-loading'); }
            return true;
        } else {
            tokenTag.className = 'tag is-danger token-status-tag';
            tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Renewal Failed</span>';
            if (!silent) {
                Swal.fire({
                    icon: 'error',
                    title: 'Token Renewal Failed',
                    text: data.message || 'Could not renew token'
                });
            }
            // Dump server error details to browser console for debugging
            try { console.error('Token renew failed details:', data.error_details || data.debug || data.message); } catch (e) {}
            if (renewBtn) { renewBtn.disabled = false; renewBtn.classList.remove('is-loading'); }
            return false;
        }
    } catch (error) {
        tokenTag.className = 'tag is-danger token-status-tag';
        tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
        console.error('Error renewing token:', error);
        if (!silent) {
            Swal.fire({
                icon: 'error',
                title: 'Token Renewal Error',
                text: 'An error occurred while renewing the token'
            });
        }
        if (renewBtn) { renewBtn.disabled = false; renewBtn.classList.remove('is-loading'); }
        return false;
    }
}

async function startUserBot(username, twitchUserId) {
    const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
    const botTag = row.querySelector('.bot-status-tag');
    const tokenTag = row.querySelector('.token-status-tag');
    const startBtn = row.querySelector('.start-bot-btn');
    // Check if bot is already running
    const isRunning = runningBots.find(bot => bot.username === username);
    if (isRunning) {
        Swal.fire({
            icon: 'info',
            title: 'Bot Already Running',
            text: `Bot for ${username} is already running (PID: ${isRunning.pid})`
        });
        return;
    }
    // First, validate the token
    startBtn.disabled = true;
    startBtn.classList.add('is-loading');
    try {
        // Validate token
        const validateFormData = new FormData();
        validateFormData.append('validate_user_token', '1');
        validateFormData.append('twitch_user_id', twitchUserId);
        const validateResponse = await fetch('', {
            method: 'POST',
            body: validateFormData
        });
        const validateData = await validateResponse.json();
        // If token is invalid, try to renew it
        if (!validateData.success || !validateData.valid) {
            Swal.fire({
                icon: 'warning',
                title: 'Token Invalid',
                text: 'Token is invalid. Attempting to renew...',
                showConfirmButton: false,
                timer: 2000
            });
            const renewed = await renewUserToken(twitchUserId);
            
            if (!renewed) {
                startBtn.disabled = false;
                startBtn.classList.remove('is-loading');
                return;
            }
        }
        // Token is valid, proceed to start bot
        botTag.className = 'tag is-info bot-status-tag';
        botTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Starting...</span>';
        const startFormData = new FormData();
        startFormData.append('start_user_bot', '1');
        startFormData.append('username', username);
        const startResponse = await fetch('', {
            method: 'POST',
            body: startFormData
        });
        const startText = await startResponse.text();
        let startData;
        try {
            startData = JSON.parse(startText);
        } catch (e) {
            console.error('Non-JSON start response:', startText);
            Swal.fire({
                icon: 'error',
                title: 'Server Response (non-JSON)',
                html: '<div style="text-align:left;max-height:400px;overflow:auto"><pre>' + escapeHtml(startText) + '</pre></div>'
            });
            return;
        }
        if (startData.success) {
            Swal.fire({
                icon: 'success',
                title: 'Bot Started',
                text: `Bot for ${username} has been started successfully`,
                timer: 2000,
                showConfirmButton: false
            });
            // Refresh bot status after a short delay
            setTimeout(() => {
                refreshBotStatus();
            }, 2000);
        } else {
            botTag.className = 'tag is-danger bot-status-tag';
            botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Start Failed</span>';
            Swal.fire({
                icon: 'error',
                title: 'Failed to Start Bot',
                text: startData.message || 'Could not start bot'
            });
        }
    } catch (error) {
        botTag.className = 'tag is-danger bot-status-tag';
        botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while starting the bot'
        });
        console.error('Error starting bot:', error);
    } finally {
        startBtn.disabled = false;
        startBtn.classList.remove('is-loading');
    }
}
</script>
<?php
$content = ob_get_clean();
include "admin_layout.php";
?>