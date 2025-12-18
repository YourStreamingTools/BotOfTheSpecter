<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_dashboard_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once "/var/www/config/admin_actions.php";
require_once "/var/www/config/twitch.php";
include "../userdata.php";

// Handle service control actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['service'])) {
    $action = $_POST['action'];
    $service = $_POST['service'];
    // Initialize defaults so we always return useful JSON
    $success = false;
    $output = '';
    // Define allowed services
    $allowedServices = ['discordbot.service', 'fastapi.service', 'websocket.service', 'mysql.service'];
    if (in_array($service, $allowedServices)) {
        try {
            // Determine which server credentials to use based on service
            $ssh_host = $bots_ssh_host;
            $ssh_username = $bots_ssh_username;
            $ssh_password = $bots_ssh_password;
            if ($service == 'fastapi.service') {
                // Use the variable names defined in config/ssh.php
                $ssh_host = $api_server_host;
                $ssh_username = $api_server_username;
                $ssh_password = $api_server_password;
            } elseif ($service == 'websocket.service') {
                // Use the variable names defined in config/ssh.php
                $ssh_host = $websocket_server_host;
                $ssh_username = $websocket_server_username;
                $ssh_password = $websocket_server_password;
            } elseif ($service == 'mysql.service') {
                $ssh_host = $sql_server_host;
                $ssh_username = $sql_server_username;
                $ssh_password = $sql_server_password;
            }
            $connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password);
            if (!$connection) {
                $output = "SSH connection failed to host: {$ssh_host} (check config/ssh.php and network)";
                $success = false;
            } else {
                // Use non-interactive sudo (-n) so the command fails quickly if a password is required
                $command = "sudo -n systemctl $action $service";
                $output = SSHConnectionManager::executeCommand($connection, $command);
                // If executeCommand returned false, it likely timed out or failed to run
                if ($output === false) {
                    $success = false;
                    $output = "Command execution failed or timed out";
                } else {
                    // The most reliable indicator is the exit status code (0 = success)
                    $exit_status = SSHConnectionManager::$last_exit_status ?? null;
                    // Log raw values for debugging
                    error_log("[admin service control] Raw exit_status type: " . gettype($exit_status) . ", value: " . var_export($exit_status, true));
                    error_log("[admin service control] Raw output (first 300 chars): " . substr($output, 0, 300));
                    // Handle both int and string representations of 0
                    // Also consider it success if we didn't get a non-zero exit code and the command executed without returning false
                    if ($exit_status === 0 || $exit_status === '0' || intval($exit_status) === 0) {
                        $success = true;
                    } elseif ($exit_status === null) {
                        // If exit status is null but we got output (command didn't fail), treat as success
                        // The SSH fallback may not have captured the exit code properly
                        $success = true;
                        error_log("[admin service control] Exit status was null, but command executed - assuming success");
                    } else {
                        // Non-zero exit status means failure
                        $success = false;
                    }
                    error_log("[admin service control] $service $action - success: " . ($success ? 'true' : 'false') . ", exit_status: " . var_export($exit_status, true));
                    // Provide a user-friendly message even if output is empty
                    if ($success && empty(trim($output))) {
                        $output = "Command executed successfully";
                    }
                }
            }
        } catch (Exception $e) {
            $success = false;
            $output = 'Exception: ' . $e->getMessage();
        }
    } else {
        $output = 'Invalid service requested';
        $success = false;
    }
    // Return JSON response instead of redirect. Include command output for diagnostics.
    if (function_exists('ob_get_length') && ob_get_length() !== false && ob_get_length() > 0) {
        $prev = ob_get_clean();
        @error_log("[admin service control] cleared previous output buffer: " . substr(trim(preg_replace('/\s+/', ' ', $prev)), 0, 1000));
        // Start a fresh buffer to ensure headers can be sent
        ob_start();
    }
    // Log the result server-side to aid debugging (will appear in PHP error log)
    @error_log("[admin service control] service={$service} action={$action} success=" . ($success ? '1' : '0') . " exit_status=" . (SSHConnectionManager::$last_exit_status ?? 'null') . " output=" . str_replace("\n", "\\n", substr($output ?? '', 0, 1000)));
    header('Content-Type: application/json');
    $exit_status = SSHConnectionManager::$last_exit_status ?? null;
    // Include helpful diagnostics for the browser to show
    $diagnostics = [
        'exit_status' => $exit_status,
        'ssh2_loaded' => extension_loaded('ssh2'),
        'connection_host' => isset($ssh_host) ? $ssh_host : null,
        'output_length' => is_string($output) ? strlen($output) : 0,
    ];
    echo json_encode(['success' => $success, 'output' => $output ?? '', 'diagnostics' => $diagnostics]);
    exit;
}

// Handle refresh Spotify tokens action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_spotify_tokens'])) {
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($connection) {
            $command = "cd /home/botofthespecter && python3 refresh_spotify_tokens.py";
            $output = SSHConnectionManager::executeCommand($connection, $command);
            $success = true;
        } else {
            $output = "Failed to connect to bot server.";
            $success = false;
        }
    } catch (Exception $e) {
        $output = "Error: " . $e->getMessage();
        $success = false;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

// Handle refresh StreamElements tokens action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_streamelements_tokens'])) {
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($connection) {
            $command = "cd /home/botofthespecter && python3 refresh_streamelements_tokens.py";
            $output = SSHConnectionManager::executeCommand($connection, $command);
            $success = true;
        } else {
            $output = "Failed to connect to bot server.";
            $success = false;
        }
    } catch (Exception $e) {
        $output = "Error: " . $e->getMessage();
        $success = false;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

// Handle refresh Discord tokens action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_discord_tokens'])) {
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($connection) {
            $command = "cd /home/botofthespecter && python3 refresh_discord_tokens.py";
            $output = SSHConnectionManager::executeCommand($connection, $command);
            $success = true;
        } else {
            $output = "Failed to connect to bot server.";
            $success = false;
        }
    } catch (Exception $e) {
        $output = "Error: " . $e->getMessage();
        $success = false;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

// Handle bot stop action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['stop_bot']) && isset($_POST['pid'])) {
    $pid = intval($_POST['pid']);
    if ($pid > 0) {
        try {
            $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
                if ($connection) {
                    // Use SIGKILL explicitly to force-stop the process on the bots server
                    SSHConnectionManager::executeCommand($connection, "kill -s kill $pid");
                }
        } catch (Exception $e) {}
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle send message action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $message = trim($_POST['message']);
    $channel_id = $_POST['channel_id'];
    if (!empty($message) && !empty($channel_id)) {
        if (strlen($message) > 255) {
            $error_message = "Message too long: " . strlen($message) . " characters (max 255)";
        } else {
            // Send message directly via Twitch API using bot token
            $url = "https://api.twitch.tv/helix/chat/messages";
            $headers = [
                "Authorization: Bearer " . $oauth,
                "Client-Id: " . $clientID,
                "Content-Type: application/json"
            ];
            $data = [
                "broadcaster_id" => $channel_id,
                "sender_id" => "971436498", // Bot's user ID
                "message" => $message
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);
            if ($curl_errno) {
                $error_message = "Failed to send message: " . $curl_error;
            } elseif ($http_code === 200) {
                $response_data = json_decode($response, true);
                if ($response_data && isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data']) > 0) {
                    $msg_data = $response_data['data'][0];
                    $is_sent = $msg_data['is_sent'] ?? false;
                    $drop_reason = $msg_data['drop_reason'] ?? null;
                    if ($is_sent) {
                        $success_message = "Message sent successfully as bot! It should appear in chat shortly.";
                    } else {
                        $error_message = "Message not sent.";
                        if ($drop_reason) {
                            $error_message .= " Drop reason: " . $drop_reason;
                        }
                    }
                } else {
                    $error_message = "Invalid response from Twitch API.";
                }
            } else {
                $error_message = "Failed to send message. HTTP $http_code";
                if ($response) {
                    $response_data = json_decode($response, true);
                    if ($response_data && isset($response_data['message'])) {
                        $error_message .= ": " . $response_data['message'];
                    } else {
                        $error_message .= ": " . $response;
                    }
                }
            }
        }
    } else {
        $error_message = "Message and channel are required.";
    }
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode([
        'success' => isset($success_message),
        'message' => $success_message ?? $error_message ?? 'Unknown error'
    ]);
    exit;
}

// Function to check if a channel is online
function isOnline($user_id, $client_id, $bearer) {
    $url = "https://api.twitch.tv/helix/streams?user_id=" . urlencode($user_id);
    $headers = [
        "Authorization: Bearer " . $bearer,
        "Client-Id: " . $client_id
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return isset($data['data']) && !empty($data['data']);
}

// Function to get online user IDs in batch
function getOnlineUserIds($user_ids, $client_id, $bearer) {
    if (empty($user_ids)) return [];
    $url = "https://api.twitch.tv/helix/streams?";
    $params = [];
    foreach ($user_ids as $user_id) {
        $params[] = "user_id=" . urlencode($user_id);
    }
    $url .= implode('&', $params);
    $headers = [
        "Authorization: Bearer " . $bearer,
        "Client-Id: " . $client_id
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    $online_ids = [];
    if (isset($data['data'])) {
        foreach ($data['data'] as $stream) {
            $online_ids[] = $stream['user_id'];
        }
    }
    return $online_ids;
}

// Prepare an empty placeholder for the online channels — they'll be populated by JS via AJAX.
$online_channels = [];
// AJAX handlers: bot_overview and online_channels
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];
    header('Content-Type: application/json');
    if ($ajax === 'bot_overview') {
        // Perform the heavy SSH call now (only for the AJAX request)
        $bot_output = getBotStatus($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        $stable_bots = [];
        $beta_bots = [];
        $custom_bots = [];
        $lines = explode("\n", $bot_output);
        $section = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'Stable bots running:') === 0) {
                $section = 'stable';
            } elseif (strpos($line, 'Beta bots running:') === 0) {
                $section = 'beta';
            } elseif (strpos($line, 'Custom bots running:') === 0) {
                $section = 'custom';
            } elseif (preg_match('/- Channel: (\S+), PID: (\d+), Version: (.+?)\s*\|(.+)/', $line, $matches)) {
                $version = $matches[3];
                $status_text = trim($matches[4]);
                $is_outdated = strpos($status_text, 'OUTDATED') !== false;
                $bot = [
                    'channel' => $matches[1],
                    'pid' => $matches[2],
                    'version' => $version,
                    'is_outdated' => $is_outdated
                ];
                if ($section == 'stable') {
                    $stable_bots[] = $bot;
                } elseif ($section == 'beta') {
                    $beta_bots[] = $bot;
                } elseif ($section == 'custom') {
                    $custom_bots[] = $bot;
                }
            }
        }
        $all_bots = [];
        foreach ($beta_bots as $bot) {
            $bot['type'] = 'beta';
            $all_bots[] = $bot;
        }
        foreach ($stable_bots as $bot) {
            $bot['type'] = 'stable';
            $all_bots[] = $bot;
        }
        foreach ($custom_bots as $bot) {
            $bot['type'] = 'custom';
            $all_bots[] = $bot;
        }
        // Fetch user IDs and profile images
        if ($conn) {
            foreach ($all_bots as &$bot) {
                $stmt = $conn->prepare("SELECT id, profile_image FROM users WHERE username = ?");
                $stmt->bind_param("s", $bot['channel']);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $bot['id'] = $row['id'];
                    $bot['profile_image'] = $row['profile_image'];
                } else {
                    $bot['id'] = PHP_INT_MAX;
                    $bot['profile_image'] = '';
                }
                $stmt->close();
            }
            usort($all_bots, function($a, $b) {
                return ($a['id'] ?? PHP_INT_MAX) <=> ($b['id'] ?? PHP_INT_MAX);
            });
        }
        echo json_encode([
            'bots' => $all_bots,
            'error' => empty($all_bots) ? ($bot_output ?: 'None') : null
        ]);
        exit;
    } elseif ($ajax === 'online_channels') {
        // Build channels list (online or all based on parameter)
        $channels = [];
        $include_offline = isset($_GET['include_offline']) && $_GET['include_offline'] == '1';
        if ($conn && isset($_SESSION['access_token'])) {
            $result = $conn->query("SELECT id, twitch_user_id, twitch_display_name FROM users");
            if ($result) {
                $user_ids = [];
                $user_data = [];
                while ($row = $result->fetch_assoc()) {
                    $user_ids[] = $row['twitch_user_id'];
                    $user_data[$row['twitch_user_id']] = $row;
                }
                // Get online status in batch
                $online_user_ids = getOnlineUserIds($user_ids, $clientID, $_SESSION['access_token']);
                foreach ($user_data as $user_id => $row) {
                    $is_online = in_array($user_id, $online_user_ids);
                    if ($include_offline || $is_online) {
                        $row['is_online'] = $is_online;
                        $channels[] = $row;
                    }
                }
            }
        }
        echo json_encode(['channels' => $channels]);
        exit;
    } elseif ($ajax === 'bot_message_counts') {
        // Fetch bot message counts and last updated times
        $botMessageStats = [];
        if ($conn) {
            $result = $conn->query("SELECT bot_system, messages_sent, last_updated FROM bot_messages WHERE bot_system IN ('discordbot', 'twitch_stable', 'twitch_beta', 'twitch_custom')");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $botMessageStats[$row['bot_system']] = [
                        'messages_sent' => $row['messages_sent'],
                        'last_updated' => $row['last_updated']
                    ];
                }
            }
        }
        echo json_encode(['botMessageStats' => $botMessageStats]);
        exit;
    }
}

// Function to get service status
function getServiceStatus($service_name, $ssh_host, $ssh_username, $ssh_password) {
    $status = 'Unknown';
    $pid = 'N/A';
    try {
        $connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password);
        if ($connection) {
            $output = SSHConnectionManager::executeCommand($connection, "systemctl status $service_name");
            if ($output) {
                if (preg_match('/Active:\s*active\s*\(running\)/', $output)) {
                    $status = 'Running';
                } elseif (preg_match('/Active:\s*inactive/', $output)) {
                    $status = 'Stopped';
                } elseif (preg_match('/Active:\s*failed/', $output)) {
                    $status = 'Failed';
                }
                if (preg_match('/Main PID:\s*(\d+)/', $output, $matches)) {
                    $pid = $matches[1];
                }
            }
        }
    } catch (Exception $e) {
        $status = 'Error';
        $pid = 'N/A';
    }
    return ['status' => $status, 'pid' => $pid];
}

// Function to get bot status
function getBotStatus($bots_ssh_host, $bots_ssh_username, $bots_ssh_password) {
    $output = '';
    try {
        $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if ($connection) {
            $output = SSHConnectionManager::executeCommand($connection, "python3 /home/botofthespecter/running_bots.py 2>&1");
        }
    } catch (Exception $e) {
        $output = "Error fetching bot status: " . $e->getMessage();
    }
    return $output;
}

// Function to get Twitch subscription tier
function getTwitchSubTier($twitch_user_id) {
    global $clientID;
    $accessToken = $_SESSION['access_token'];
    if (empty($twitch_user_id) || empty($accessToken)) {
        return null;
    }
    $broadcaster_id = "140296994";
    $url = "https://api.twitch.tv/helix/subscriptions?broadcaster_id={$broadcaster_id}&user_id={$twitch_user_id}";
    $headers = [ "Client-ID: $clientID", "Authorization: Bearer $accessToken" ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }
    $data = json_decode($response, true);
    curl_close($ch);
    // Check if we have subscription data in the response
    if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
        return $data['data'][0]['tier'];
    }
    return null;
}

// Service statuses will be loaded asynchronously via JavaScript
$discord_status = ['status' => 'Loading...', 'pid' => '...'];
$api_status = ['status' => 'Loading...', 'pid' => '...'];
$websocket_status = ['status' => 'Loading...', 'pid' => '...'];
$mysql_status = ['status' => 'Loading...', 'pid' => '...'];

// Fetch user statistics for pie chart
$total_users = 0;
$admin_count = 0;
$beta_count = 0;
$premium_count = 0;
$regular_count = 0;

if ($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $total_users = $result->fetch_assoc()['count'];
    }
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1");
    if ($result) {
        $admin_count = $result->fetch_assoc()['count'];
    }
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE beta_access = 1");
    if ($result) {
        $beta_count = $result->fetch_assoc()['count'];
    }
    // Count premium users (actual Twitch subscribers who are NOT beta users)
    $premium_count = 0;
    if (isset($_SESSION['access_token'])) {
        $result = $conn->query("SELECT twitch_user_id FROM users WHERE twitch_user_id IS NOT NULL AND twitch_user_id != '' AND beta_access = 0");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tier = getTwitchSubTier($row['twitch_user_id']);
                if ($tier && in_array($tier, ["1000", "2000", "3000"])) {
                    $premium_count++;
                }
            }
        }
    }
    $regular_count = $total_users - $admin_count - $beta_count - $premium_count;
    // Ensure regular count doesn't go negative if users have multiple roles
    if ($regular_count < 0) $regular_count = 0;
}

// Fetch bot message counts and last updated times
$botMessageStats = [];
$messageSystemNames = [
    'discordbot' => 'Discord Bot',
    'twitch_stable' => 'Chat Bot Stable',
    'twitch_beta' => 'Chat Bot Beta',
    'twitch_custom' => 'Chat Bot Custom'
];
if ($conn) {
    $result = $conn->query("SELECT bot_system, messages_sent, last_updated FROM bot_messages WHERE bot_system IN ('discordbot', 'twitch_stable', 'twitch_beta', 'twitch_custom')");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $botMessageStats[$row['bot_system']] = [
                'messages_sent' => $row['messages_sent'],
                'last_updated' => $row['last_updated']
            ];
        }
    }
}

// Defer bot status fetching until the AJAX request
$bot_output = '';
$stable_bots = [];
$beta_bots = [];
$all_bots = [];

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<div class="box">
    <h1 class="title is-3"><span class="icon"><i class="fas fa-shield-alt"></i></span> Administrator Dashboard</h1>
    <p class="mb-4">This is the admin dashboard. Use the links below to manage users, view logs, and perform other administrative tasks.</p>
    <div class="buttons">
        <a href="logs.php" class="button is-info is-light">
            <span class="icon"><i class="fas fa-clipboard-list"></i></span>
            <span>Log Management</span>
        </a>
        <a href="twitch_tokens.php" class="button is-primary is-light">
            <span class="icon"><i class="fab fa-twitch"></i></span>
            <span>Twitch Tokens</span>
        </a>
        <a href="discordbot_overview.php" class="button is-link is-light">
            <span class="icon"><i class="fab fa-discord"></i></span>
            <span>Discord Bot Overview</span>
        </a>
        <a href="websocket_clients.php" class="button is-success is-light">
            <span class="icon"><i class="fas fa-plug"></i></span>
            <span>Websocket Clients</span>
        </a>
        <a href="terminal.php" class="button is-warning is-light">
            <span class="icon"><i class="fas fa-terminal"></i></span>
            <span>Web Terminal</span>
        </a>
    </div>
</div>

<div class="box">
    <h2 class="title is-4"><span class="icon"><i class="fas fa-server"></i></span> Server Overview</h2>
    <div class="columns is-multiline">
        <!-- Discord Bot Service -->
        <div class="column is-full-mobile is-one-quarter-tablet">
            <div class="box hover-box">
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                        <span class="icon has-text-link">
                            <i class="fab fa-discord fa-lg"></i>
                        </span>
                        <div style="min-width: 0;">
                            <p class="heading">Discord Bot</p>
                            <p class="title is-6 has-text-info" id="discord-status">Loading...</p>
                        </div>
                    </div>
                    <div>
                        <span class="tag is-light has-text-black" id="discord-pid">PID: ...</span>
                    </div>
                </div>
                <div class="buttons are-small mt-4" id="discord-buttons">
                    <button type="button" class="button is-success" onclick="controlService('discordbot.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('discordbot.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('discordbot.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
        <!-- API Server Service -->
        <div class="column is-full-mobile is-one-quarter-tablet">
            <div class="box hover-box">
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                        <span class="icon has-text-primary">
                            <i class="fas fa-code fa-lg"></i>
                        </span>
                        <div style="min-width: 0;">
                            <p class="heading">API Server</p>
                            <p class="title is-6 has-text-info" id="api-status">Loading...</p>
                        </div>
                    </div>
                    <div>
                        <span class="tag is-light has-text-black" id="api-pid">PID: ...</span>
                    </div>
                </div>
                <div class="buttons are-small mt-4" id="api-buttons">
                    <button type="button" class="button is-success" onclick="controlService('fastapi.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('fastapi.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('fastapi.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
        <!-- WebSocket Server Service -->
        <div class="column is-full-mobile is-one-quarter-tablet">
            <div class="box hover-box">
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                        <span class="icon has-text-success">
                            <i class="fas fa-plug fa-lg"></i>
                        </span>
                        <div style="min-width: 0;">
                            <p class="heading">WebSocket Server</p>
                            <p class="title is-6 has-text-info" id="websocket-status">Loading...</p>
                        </div>
                    </div>
                    <div>
                        <span class="tag is-light has-text-black" id="websocket-pid">PID: ...</span>
                    </div>
                </div>
                <div class="buttons are-small mt-4" id="websocket-buttons">
                    <button type="button" class="button is-success" onclick="controlService('websocket.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('websocket.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('websocket.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
        <!-- MySQL Server Service -->
        <div class="column is-full-mobile is-one-quarter-tablet">
            <div class="box hover-box">
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                        <span class="icon has-text-warning">
                            <i class="fas fa-database fa-lg"></i>
                        </span>
                        <div style="min-width: 0;">
                            <p class="heading">MySQL Server</p>
                            <p class="title is-6 has-text-info" id="mysql-status">Loading...</p>
                        </div>
                    </div>
                    <div>
                        <span class="tag is-light has-text-black" id="mysql-pid">PID: ...</span>
                    </div>
                </div>
                <div class="buttons are-small mt-4" id="mysql-buttons">
                    <button type="button" class="button is-success" onclick="controlService('mysql.service', 'start')" disabled>
                        <span class="icon"><i class="fas fa-play"></i></span>
                    </button>
                    <button type="button" class="button is-danger" onclick="controlService('mysql.service', 'stop')" disabled>
                        <span class="icon"><i class="fas fa-stop"></i></span>
                    </button>
                    <button type="button" class="button is-warning" onclick="controlService('mysql.service', 'restart')" disabled>
                        <span class="icon"><i class="fas fa-redo"></i></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="box">
    <div class="collapsible-header" onclick="toggleCollapsible('token-management', event)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 0 0 1rem 0;">
        <h2 class="title is-4" style="margin-bottom: 0;"><span class="icon"><i class="fas fa-key"></i></span> Token Management</h2>
        <span class="collapse-icon" data-section="token-management" style="font-size: 1.5rem; transition: transform 0.3s ease;">▶</span>
    </div>
    <div class="collapsible-content" id="token-management" style="display: none; padding-top: 1rem;">
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between;">
        <div style="flex: 1; min-width: 250px;">
            <div class="box hover-box">
                <h3 class="title is-5"><span class="icon"><i class="fab fa-spotify"></i></span> Spotify</h3>
                <button type="button" class="button is-success is-fullwidth" onclick="refreshSpotifyTokens()" style="white-space: normal; overflow-wrap: break-word; height: auto; padding: 0.75rem;">
                    <span class="icon"><i class="fas fa-sync"></i></span>
                    <span>Refresh Spotify Tokens</span>
                </button>
            </div>
        </div>
        <div style="flex: 1; min-width: 250px;">
            <div class="box hover-box">
                <h3 class="title is-5"><span class="icon"><i class="fas fa-stream"></i></span> StreamElements</h3>
                <button type="button" class="button is-info is-fullwidth" onclick="refreshStreamElementsTokens()" style="white-space: normal; overflow-wrap: break-word; height: auto; padding: 0.75rem;">
                    <span class="icon"><i class="fas fa-sync"></i></span>
                    <span>Refresh StreamElements Tokens</span>
                </button>
            </div>
        </div>
        <div style="flex: 1; min-width: 250px;">
            <div class="box hover-box">
                <h3 class="title is-5"><span class="icon"><i class="fab fa-discord"></i></span> Discord</h3>
                <button type="button" class="button is-link is-fullwidth" onclick="refreshDiscordTokens()" style="white-space: normal; overflow-wrap: break-word; height: auto; padding: 0.75rem;">
                    <span class="icon"><i class="fas fa-sync"></i></span>
                    <span>Refresh Discord Tokens</span>
                </button>
            </div>
        </div>
        <div style="flex: 1; min-width: 250px;">
            <div class="box hover-box">
                <h3 class="title is-5"><span class="icon"><i class="fas fa-robot"></i></span> Custom Bots</h3>
                <button type="button" class="button is-warning is-fullwidth" onclick="refreshCustomBotTokens()" style="white-space: normal; overflow-wrap: break-word; height: auto; padding: 0.75rem;">
                    <span class="icon"><i class="fas fa-sync"></i></span>
                    <span>Refresh Custom Bot Tokens</span>
                </button>
            </div>
        </div>
    </div>
    </div>
</div>
<div class="box">
    <div class="collapsible-header" onclick="toggleCollapsible('bot-message-counts', event)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 0 0 1rem 0;">
        <h2 class="title is-4" style="margin-bottom: 0;"><span class="icon"><i class="fas fa-comments"></i></span> Bot Message Counts</h2>
        <span class="collapse-icon" data-section="bot-message-counts" style="font-size: 1.5rem; transition: transform 0.3s ease;">▶</span>
    </div>
    <div class="collapsible-content" id="bot-message-counts" style="display: none; padding-top: 1rem;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; grid-auto-rows: 1fr;">
        <?php foreach ($messageSystemNames as $key => $label): ?>
        <div class="box hover-box bot-message-box" data-bot-system="<?php echo $key; ?>">
            <h3 class="title is-5"><?php echo $label; ?></h3>
            <div class="bot-message-count-display">
                <div class="bot-message-count-number">
                    <?php 
                    if (isset($botMessageStats[$key]) && $botMessageStats[$key]['messages_sent'] > 0) {
                        echo number_format($botMessageStats[$key]['messages_sent']);
                    } else {
                        echo 'Not Counting Yet';
                    }
                    ?>
                </div>
                <div class="bot-message-count-timestamp">
                    <?php 
                    if (isset($botMessageStats[$key]['last_updated'])) {
                        echo 'Last Updated: ' . date('M d, Y H:i:s', strtotime($botMessageStats[$key]['last_updated']));
                    } else {
                        echo 'No data yet';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>
<div class="box">
    <div class="collapsible-header" onclick="toggleCollapsible('bot-overview', event)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 0 0 1rem 0;">
        <h2 class="title is-4" style="margin-bottom: 0;"><span class="icon"><i class="fas fa-robot"></i></span> Bot Overview</h2>
        <span class="collapse-icon" data-section="bot-overview" style="font-size: 1.5rem; transition: transform 0.3s ease;">▼</span>
    </div>
    <div class="collapsible-content" id="bot-overview" style="display: block;">
        <div id="bot-overview-container">
            <p class="mb-5">Loading bot overview...</p>
        </div>
    </div>
</div>
<div class="columns is-variable is-3" style="align-items: stretch;">
    <div class="column is-half is-hidden">
        <div class="box" style="height: 100%; display: flex; flex-direction: column;">
            <h2 class="title is-4"><span class="icon"><i class="fas fa-chart-pie"></i></span> User Overview</h2>
            <div class="columns" style="flex: 1;">
                <div class="column is-half">
                    <div style="max-width: 300px; margin: 0 auto;">
                        <canvas id="userChart" width="300" height="300"></canvas>
                    </div>
                </div>
                <div class="column is-half" style="display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <p class="mb-4">Quick stats on user distribution:</p>
                        <ul>
                            <li><strong>Total Users:</strong> <?php echo $total_users; ?></li>
                            <li><strong>Admins:</strong> <?php echo $admin_count; ?></li>
                            <li><strong>Beta Users:</strong> <?php echo $beta_count; ?></li>
                            <li><strong>Premium Users:</strong> <?php echo $premium_count; ?></li>
                            <li><strong>Regular Users:</strong> <?php echo $regular_count; ?></li>
                        </ul>
                    </div>
                    <a href="users.php" class="button is-link is-light mt-4">
                        <span class="icon"><i class="fas fa-users-cog"></i></span>
                        <span>Manage Users</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-half">
        <div class="box" style="height: 100%">
            <h2 class="title is-4"><span class="icon"><i class="fas fa-paper-plane"></i></span> Send Bot Message</h2>
            <form id="send-message-form" method="post">
                <div class="field">
                    <label class="label">Select Channel</label>
                    <div class="control">
                        <div class="select">
                            <!-- Populated via AJAX to avoid blocking page load -->
                            <select name="channel_id" id="channel-select" required>
                                <option value="">Loading channels...</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <label class="checkbox">
                            <input type="checkbox" id="include-offline">
                            Include offline channels
                        </label>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Message</label>
                    <div class="control">
                        <textarea class="textarea" name="message" id="message" placeholder="Enter your message..." required></textarea>
                    </div>
                    <small id="char-count" class="has-text-grey">0 / 255 characters</small>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit" name="send_message" id="send" disabled>Send Message</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== Cookie Management for Collapsible Sections =====
    function setCookie(name, value, days = 365) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        const expires = "expires=" + date.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/";
    }

    function getCookie(name) {
        const nameEQ = name + "=";
        const cookies = document.cookie.split(';');
        for (let i = 0; i < cookies.length; i++) {
            let cookie = cookies[i].trim();
            if (cookie.indexOf(nameEQ) === 0) return cookie.substring(nameEQ.length);
        }
        return null;
    }

    // Toggle collapsible section and save state to cookie
    window.toggleCollapsible = function(sectionId, event) {
        event.preventDefault();
        const content = document.getElementById(sectionId);
        const icon = document.querySelector(`.collapse-icon[data-section="${sectionId}"]`);
        
        if (content) {
            content.classList.toggle('open');
            content.style.display = content.classList.contains('open') ? 'block' : 'none';
            
            if (icon) {
                icon.classList.toggle('open');
            }
            
            // Save state to cookie
            const isOpen = content.classList.contains('open');
            setCookie(`collapsible_${sectionId}`, isOpen ? 'open' : 'closed');
        }
    };

    // Initialize collapsible states from cookies
    function initializeCollapsibles() {
        const sections = ['token-management', 'bot-message-counts', 'bot-overview'];
        sections.forEach(sectionId => {
            const content = document.getElementById(sectionId);
            const icon = document.querySelector(`.collapse-icon[data-section="${sectionId}"]`);
            const savedState = getCookie(`collapsible_${sectionId}`);
            
            if (content && icon) {
                // Set default state (bot-overview defaults to open, others to closed)
                let shouldOpen = (sectionId === 'bot-overview');
                
                // Check if we have a saved state
                if (savedState === 'open') {
                    shouldOpen = true;
                } else if (savedState === 'closed') {
                    shouldOpen = false;
                }
                
                if (shouldOpen) {
                    content.classList.add('open');
                    content.style.display = 'block';
                    icon.classList.add('open');
                } else {
                    content.classList.remove('open');
                    content.style.display = 'none';
                    icon.classList.remove('open');
                }
            }
        });
    }

    // Initialize on page load
    initializeCollapsibles();

    // ===== End Collapsible Section Code =====
    
    // Show toast notifications for messages
    <?php if (isset($success_message)): ?>
    Swal.fire({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        icon: 'success',
        title: <?php echo json_encode($success_message); ?>
    });
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
    Swal.fire({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 5000,
        timerProgressBar: true,
        icon: 'error',
        title: <?php echo json_encode($error_message); ?>
    });
    <?php endif; ?>
    // Initialize user chart
    const ctx = document.getElementById('userChart');
    if (ctx) {
        const chartCtx = ctx.getContext('2d');
        const data = [<?php echo $admin_count; ?>, <?php echo $beta_count; ?>, <?php echo $premium_count; ?>, <?php echo $regular_count; ?>];
        if (data.some(val => val > 0)) {
            const userChart = new Chart(chartCtx, {
                type: 'pie',
                data: {
                    labels: ['Admins', 'Beta Users', 'Premium Users', 'Regular Users'],
                    datasets: [{
                        data: data,
                        backgroundColor: [
                            '#ff6384',
                            '#36a2eb',
                            '#cc65fe',
                            '#ffce56'
                        ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        }
                    }
                }
            });
        } else {
            ctx.style.display = 'none';
            const noDataMsg = document.createElement('p');
            noDataMsg.textContent = 'No user data available to display.';
            ctx.parentNode.appendChild(noDataMsg);
        }
    }
    const serviceConfig = {
        'discordbot.service': { statusKey: 'discordbot', statusId: 'discord-status', pidId: 'discord-pid', buttonsId: 'discord-buttons' },
        'fastapi.service': { statusKey: 'fastapi', statusId: 'api-status', pidId: 'api-pid', buttonsId: 'api-buttons' },
        'websocket.service': { statusKey: 'websocket', statusId: 'websocket-status', pidId: 'websocket-pid', buttonsId: 'websocket-buttons' },
        'mysql.service': { statusKey: 'mysql', statusId: 'mysql-status', pidId: 'mysql-pid', buttonsId: 'mysql-buttons' }
    };
    function scheduleStatusRefresh(meta) {
        if (!meta) return;
        // Give systemd a moment to settle before querying status again
        setTimeout(() => {
            updateServiceStatus(meta.statusKey, meta.statusId, meta.pidId, meta.buttonsId);
        }, 500);
    }
    // Function to control service
    window.controlService = function(service, action) {
        const meta = serviceConfig[service];
        if (!meta) {
            console.error('Unknown service mapping for', service);
            return;
        }
    const buttonsElement = document.getElementById(meta.buttonsId);
    const buttons = buttonsElement ? buttonsElement.querySelectorAll('button') : [];
    buttons.forEach(btn => btn.disabled = true);
        const formData = new FormData();
        formData.append('service', service);
        formData.append('action', action);
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            // Read raw text so we can log invalid JSON too
            const text = await response.text();
            try {
                const data = JSON.parse(text);
                console.log('[admin control] response JSON:', data);
                const success = data.success;
                const output = data.output || '';
                if (success) {
                    console.log('[admin control] command success output:', output);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Command executed successfully',
                        showConfirmButton: false,
                        timer: 3500,
                        timerProgressBar: true
                    });
                    if (output) {
                        console.log('[admin control] stdout/stderr:', output);
                    }
                    scheduleStatusRefresh(meta);
                } else {
                    console.error('[admin control] command failed output:', output);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: 'Command failed',
                        text: output || 'Check logs for details.',
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true
                    });
                    if (output) {
                        console.log('[admin control] error details:', output);
                    }
                    if (data.diagnostics && data.diagnostics.exit_status !== null && data.diagnostics.exit_status !== 0) {
                        console.error('[admin control] exit status:', data.diagnostics.exit_status);
                    }
                    scheduleStatusRefresh(meta);
                }
            } catch (e) {
                // Not valid JSON — log raw text for diagnosis and show it to user
                console.error('[admin control] invalid JSON response:', text);
                Swal.fire({
                    title: 'Error',
                    html: '<p>Invalid server response (not JSON).</p><pre style="text-align:left; white-space:pre-wrap;">' + text + '</pre>',
                    icon: 'error',
                    confirmButtonText: 'OK',
                    width: 800
                });
            }
            buttons.forEach(btn => btn.disabled = false);
        })
        .catch(error => {
            console.error('[admin control] fetch error:', error);
            Swal.fire({
                title: 'Error',
                text: 'Network error: ' + error.message,
                icon: 'error',
                confirmButtonText: 'OK'
            });
            buttons.forEach(btn => btn.disabled = false);
        });
    };
    // Function to stop bot
    window.stopBot = function(pid, element) {
        Swal.fire({
            title: 'Are you sure?',
            text: 'Do you want to stop this bot?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, stop it!'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('stop_bot', '1');
                formData.append('pid', pid);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        element.remove();
                    }
                })
                .catch(error => {
                    console.error('Error stopping bot:', error);
                });
            }
        });
    };
    // Function to refresh Spotify tokens
    window.refreshSpotifyTokens = function() {
        const button = document.querySelector('button[onclick="refreshSpotifyTokens()"]');
        button.disabled = true;
        button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Refreshing...</span>';
        const formData = new FormData();
        formData.append('refresh_spotify_tokens', '1');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            const success = data.success;
            const output = data.output || '';
            if (success) {
                Swal.fire({
                    title: 'Spotify Tokens Refreshed',
                    html: '<pre style="text-align: left; white-space: pre-wrap;">' + output + '</pre>',
                    icon: 'success',
                    confirmButtonText: 'OK',
                    width: '600px'
                });
            } else {
                Swal.fire({
                    title: 'Error',
                    text: 'Failed to refresh tokens: ' + output,
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
            button.disabled = false;
            button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>Refresh Spotify Tokens</span>';
        })
        .catch(error => {
            Swal.fire({
                title: 'Error',
                text: 'Network error: ' + error.message,
                icon: 'error',
                confirmButtonText: 'OK'
            });
            button.disabled = false;
            button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>Refresh Spotify Tokens</span>';
        });
    };
    // Generic function to stream command output via SSE
    function streamCommand(scriptKey, serviceName, buttonSelector) {
        const button = document.querySelector(buttonSelector);
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Refreshing...</span>';
        }
        // Create modal with an output container
        let outputHtml = '<div style="text-align:left; max-height:500px; overflow:auto; white-space:pre-wrap; font-family: monospace;" id="stream-output">Connecting...\n</div>';
        Swal.fire({
            title: serviceName + ' - Live Output',
            html: outputHtml,
            showCancelButton: true,
            cancelButtonText: 'Close',
            showConfirmButton: false,
            width: 800,
            didOpen: () => {
                const outputEl = document.getElementById('stream-output');
                const es = new EventSource('stream_command.php?script=' + encodeURIComponent(scriptKey));
                es.onmessage = function(e) {
                    // Generic messages
                    outputEl.textContent += e.data + '\n';
                    outputEl.scrollTop = outputEl.scrollHeight;
                };
                es.addEventListener('error', function(e) {
                    outputEl.textContent += '[ERROR] ' + (e.data || 'An error occurred') + '\n';
                    outputEl.scrollTop = outputEl.scrollHeight;
                });
                es.addEventListener('done', function(e) {
                    try {
                        const info = JSON.parse(e.data);
                        outputEl.textContent += '\n[PROCESS DONE] ' + (info.success ? 'Success' : 'Failed') + '\n';
                    } catch (err) {
                        outputEl.textContent += '\n[PROCESS DONE]\n';
                    }
                    outputEl.scrollTop = outputEl.scrollHeight;
                    es.close();
                    if (button) {
                        button.disabled = false;
                        // reset button label based on selector
                        if (buttonSelector.includes('Spotify')) button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>Refresh Spotify Tokens</span>';
                        else if (buttonSelector.includes('StreamElements')) button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>Refresh StreamElements Tokens</span>';
                        else if (buttonSelector.includes('CustomBot')) button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>Refresh Custom Bot Tokens</span>';
                        else button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>Refresh Discord Tokens</span>';
                    }
                });
                es.onerror = function(ev) {
                    // Some browsers call onerror on stream end, so keep it lightweight
                };
            }
        });
    }
    // Function to refresh StreamElements tokens (streams output)
    window.refreshStreamElementsTokens = function() {
        streamCommand('streamelements', 'StreamElements', 'button[onclick="refreshStreamElementsTokens()"]');
    };
    // Function to refresh Discord tokens (streams output)
    window.refreshDiscordTokens = function() {
        streamCommand('discord', 'Discord', 'button[onclick="refreshDiscordTokens()"]');
    };
    // Function to refresh Spotify tokens (streams output)
    window.refreshSpotifyTokens = function() {
        streamCommand('spotify', 'Spotify', 'button[onclick="refreshSpotifyTokens()"]');
    };
    // Function to refresh Custom Bot tokens (streams output)
    window.refreshCustomBotTokens = function() {
        streamCommand('custom_bot', 'Custom Bot', 'button[onclick="refreshCustomBotTokens()"]');
    };
    // Function to update service status
    function updateServiceStatus(service, statusElementId, pidElementId, buttonsElementId) {
        fetch(`service_status.php?service=${service}`)
            .then(response => response.json())
            .then(data => {
                const statusElement = document.getElementById(statusElementId);
                const pidElement = document.getElementById(pidElementId);
                const buttonsElement = document.getElementById(buttonsElementId);
                // Update status with appropriate color
                statusElement.textContent = data.status;
                statusElement.className = 'title is-6';
                if (data.status === 'Running') {
                    statusElement.classList.add('has-text-success');
                } else if (data.status === 'Stopped' || data.status === 'Failed') {
                    statusElement.classList.add('has-text-danger');
                } else {
                    statusElement.classList.add('has-text-warning');
                }
                // Update PID
                pidElement.textContent = `PID: ${data.pid}`;
                // Enable/disable buttons based on status
                const startBtn = buttonsElement.querySelector('button[onclick*="start"]');
                const stopBtn = buttonsElement.querySelector('button[onclick*="stop"]');
                const restartBtn = buttonsElement.querySelector('button[onclick*="restart"]');
                if (data.status === 'Running') {
                    startBtn.disabled = true;
                    stopBtn.disabled = false;
                    restartBtn.disabled = false;
                } else if (data.status === 'Stopped' || data.status === 'Failed') {
                    startBtn.disabled = false;
                    stopBtn.disabled = true;
                    restartBtn.disabled = false;
                } else {
                    startBtn.disabled = false;
                    stopBtn.disabled = false;
                    restartBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error(`Error fetching ${service} status:`, error);
                const statusElement = document.getElementById(statusElementId);
                statusElement.textContent = 'Error';
                statusElement.className = 'title is-6 has-text-danger';
            });
    }
    // Load service statuses after page load
    setTimeout(() => {
        updateServiceStatus('discordbot', 'discord-status', 'discord-pid', 'discord-buttons');
        updateServiceStatus('fastapi', 'api-status', 'api-pid', 'api-buttons');
        updateServiceStatus('websocket', 'websocket-status', 'websocket-pid', 'websocket-buttons');
        updateServiceStatus('mysql', 'mysql-status', 'mysql-pid', 'mysql-buttons');
    }, 100);
    // Utility to create safe DOM ids from channel names
    function sanitizeId(str) {
        return String(str).replace(/[^a-zA-Z0-9_-]/g, '-').toLowerCase();
    }
    // Track last updated time and show compact relative time (e.g. "Updated: 12s ago", "Updated: 2m ago")
    let botLastUpdated = null;
    let botRelativeInterval = null;
    let botHasLoadedOnce = false; // track if we've completed the first load
    function updateRelativeTime() {
        const el = document.getElementById('bot-updated-at');
        if (!el || !botLastUpdated) return;
        const delta = Math.floor((Date.now() - botLastUpdated) / 1000);
        if (delta < 5) {
            el.textContent = 'Updated: just now';
        } else if (delta < 60) {
            el.textContent = 'Updated: ' + delta + 's ago';
        } else if (delta < 3600) {
            const mins = Math.floor(delta / 60);
            el.textContent = 'Updated: ' + mins + 'm ago';
        } else {
            const hours = Math.floor(delta / 3600);
            el.textContent = 'Updated: ' + hours + 'h ago';
        }
    }
    function setBotUpdatedNow() {
        botLastUpdated = Date.now();
        updateRelativeTime();
        if (!botRelativeInterval) {
            botRelativeInterval = setInterval(updateRelativeTime, 1000);
        }
    }
    // Clear interval on unload
    window.addEventListener('beforeunload', function() {
        if (botRelativeInterval) {
            clearInterval(botRelativeInterval);
            botRelativeInterval = null;
        }
    });
    // Function to generate HTML for a single bot (returns element HTML and uses stable data attributes)
    function generateBotHtml(bot) {
        const profileImage = bot.profile_image || '';
        const iconColor = bot.type === 'beta' ? 'has-text-warning' : (bot.type === 'custom' ? 'has-text-grey' : 'has-text-primary');
        const tagClass = bot.type === 'beta' ? 'is-warning' : (bot.type === 'custom' ? 'is-dark' : 'is-primary');
        const typeLabel = bot.type.charAt(0).toUpperCase() + bot.type.slice(1);
        const safeId = 'bot-' + sanitizeId(bot.channel);
        let html = '<div class="column is-one-quarter" id="' + safeId + '" data-bot-id="' + safeId + '">';
        html += '<div class="box hover-box">';
        html += '<div class="level is-mobile">';
        html += '<div class="level-left">';
        html += '<div class="level-item">';
        if (profileImage) {
            html += '<figure class="image is-32x32">';
            html += '<img src="' + profileImage + '" alt="Profile" class="is-rounded bot-profile-img">';
            html += '</figure>';
        } else {
            html += '<span class="icon ' + iconColor + '">';
            html += '<i class="fas fa-robot fa-lg"></i>';
            html += '</span>';
        }
        html += '</div>';
        html += '<div class="level-item" style="min-width: 0;">';
        html += '<p class="heading bot-channel" style="word-break: break-word; overflow-wrap: break-word;">' + bot.channel + '</p>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        html += '<div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">';
        html += '<span class="tag bot-type-tag ' + tagClass + '">' + typeLabel + '</span>';
        html += '<span class="tag is-light has-text-black bot-pid">PID: ' + bot.pid + '</span>';
        if (bot.version) {
            html += '<span class="tag is-info bot-version">v' + bot.version + '</span>';
        }
        if (bot.is_outdated) {
            html += '<span class="tag is-danger">OUTDATED</span>';
        }
        html += '<button type="button" class="button is-danger is-small bot-stop-button" data-pid="' + bot.pid + '">';
        html += '<span class="icon"><i class="fas fa-stop"></i></span>';
        html += '</button>';
        html += '</div>';
        html += '</div>';
        return html;
    }
    // Load bot overview after page load (diffs DOM instead of replacing everything)
    const loadBotOverview = () => {
        // Check if bot-overview section is open before fetching
        const botOverviewSection = document.getElementById('bot-overview');
        if (botOverviewSection && !botOverviewSection.classList.contains('open')) {
            // Section is closed, skip refresh to save resources
            return;
        }
        const botContainer = document.getElementById('bot-overview-container');
        if (!botContainer) return;
        // Add timestamp to the collapsible header if not already present
        const collapsibleHeader = botContainer.closest('.collapsible-content')?.previousElementSibling;
        if (collapsibleHeader && !document.getElementById('bot-updated-at')) {
            const headerTitle = collapsibleHeader.querySelector('.title');
            if (headerTitle) {
                const small = document.createElement('small');
                small.id = 'bot-updated-at';
                small.className = 'ml-2 has-text-grey';
                small.textContent = 'Updated: --';
                headerTitle.appendChild(small);
            }
        }
        // Ensure columns wrapper exists
        let columns = document.getElementById('bot-columns');
        if (!columns) {
            columns = document.createElement('div');
            columns.id = 'bot-columns';
            columns.className = 'columns is-multiline';
            botContainer.appendChild(columns);
        }
        const base = window.location.href.split('?')[0];
        fetch(base + '?ajax=bot_overview')
            .then(response => response.json())
            .then(data => {
                // Clear loading text on first successful load
                if (!botHasLoadedOnce) {
                    botContainer.innerHTML = '';
                    botContainer.appendChild(columns);
                }
                botHasLoadedOnce = true;
                // update the 'updated at' relative timestamp
                setBotUpdatedNow();
                if (!data.bots || data.bots.length === 0) {
                    // No bots: clear columns and show message
                    botHasLoadedOnce = true;
                    setBotUpdatedNow();
                    // No bots: clear columns and show message
                    columns.innerHTML = '<div class="column"><p>' + (data.error || 'None') + '</p></div>';
                    return;
                }
                Array.from(columns.children).forEach(child => {
                    if (!(child instanceof Element) || !child.hasAttribute || !child.hasAttribute('data-bot-id')) {
                        // remove placeholder or non-bot child nodes
                        columns.removeChild(child);
                    }
                });
                const returnedMap = new Map();
                data.bots.forEach(b => returnedMap.set('bot-' + sanitizeId(b.channel), b));
                // Remove DOM nodes that are no longer present
                const existing = Array.from(columns.querySelectorAll('[data-bot-id]'));
                existing.forEach(el => {
                    const botId = el.getAttribute('data-bot-id');
                    if (!returnedMap.has(botId)) {
                        // remove missing bot
                        el.parentNode && el.parentNode.removeChild(el);
                    }
                });
                // Add or update bots
                let addIndex = 0;
                const hasExistingBots = columns.querySelector('[data-bot-id]') !== null;
                data.bots.forEach((bot) => {
                    const botId = 'bot-' + sanitizeId(bot.channel);
                    const existingEl = document.getElementById(botId);
                    if (existingEl) {
                        // update pid
                        const pidEl = existingEl.querySelector('.bot-pid');
                        if (pidEl) pidEl.textContent = 'PID: ' + bot.pid;
                        // update profile image if present
                        const imgEl = existingEl.querySelector('.bot-profile-img');
                        if (imgEl && bot.profile_image) imgEl.src = bot.profile_image;
                        // update type tag
                        const tagEl = existingEl.querySelector('.bot-type-tag');
                        if (tagEl) {
                            tagEl.textContent = bot.type.charAt(0).toUpperCase() + bot.type.slice(1);
                            tagEl.className = 'tag bot-type-tag ' + (bot.type === 'beta' ? 'is-warning' : (bot.type === 'custom' ? 'is-dark' : 'is-primary'));
                        }
                        // update version tag
                        const versionEl = existingEl.querySelector('.bot-version');
                        if (versionEl) {
                            versionEl.textContent = 'v' + bot.version;
                        } else if (bot.version) {
                            // Add version tag if it doesn't exist
                            const pidEl = existingEl.querySelector('.bot-pid');
                            if (pidEl) {
                                const versionTag = document.createElement('span');
                                versionTag.className = 'tag is-info bot-version';
                                versionTag.textContent = 'v' + bot.version;
                                pidEl.insertAdjacentElement('afterend', versionTag);
                            }
                        }
                        // update outdated tag
                        let outdatedEl = existingEl.querySelector('.tag.is-danger:not(.is-small)');
                        if (bot.is_outdated) {
                            if (!outdatedEl) {
                                // Add outdated tag if it doesn't exist
                                const versionEl = existingEl.querySelector('.bot-version');
                                if (versionEl) {
                                    const outdatedTag = document.createElement('span');
                                    outdatedTag.className = 'tag is-danger';
                                    outdatedTag.textContent = 'OUTDATED';
                                    versionEl.insertAdjacentElement('afterend', outdatedTag);
                                }
                            }
                        } else {
                            // Remove outdated tag if no longer outdated
                            if (outdatedEl && outdatedEl.textContent === 'OUTDATED') {
                                outdatedEl.remove();
                            }
                        }
                        // update stop button pid data attribute
                        const stopBtn = existingEl.querySelector('.bot-stop-button');
                        if (stopBtn) {
                            stopBtn.setAttribute('data-pid', bot.pid);
                            // Remove existing listeners to prevent duplicates
                            const newStopBtn = stopBtn.cloneNode(true);
                            stopBtn.parentNode.replaceChild(newStopBtn, stopBtn);
                            newStopBtn.addEventListener('click', function() {
                                const pid = this.getAttribute('data-pid');
                                const element = this.closest('.column');
                                stopBot(pid, element);
                            });
                        }
                    } else {
                        // create new element for new bots
                        const insertFunc = () => {
                            const botHtml = generateBotHtml(bot);
                            columns.insertAdjacentHTML('beforeend', botHtml);
                            // attach click handler for newly inserted button
                            const newEl = document.getElementById('bot-' + sanitizeId(bot.channel));
                            if (newEl) {
                                const stopButton = newEl.querySelector('.bot-stop-button');
                                if (stopButton) {
                                    stopButton.addEventListener('click', function() {
                                        const pid = this.getAttribute('data-pid');
                                        const element = this.closest('.column');
                                        stopBot(pid, element);
                                    });
                                }
                            }
                        };
                        // Stagger insert only when there are no existing bots (initial load). On subsequent polling, insert immediately.
                        if (hasExistingBots) {
                            insertFunc();
                        } else {
                            setTimeout(insertFunc, addIndex * 150);
                        }
                        addIndex++;
                    }
                });
            })
            .catch(error => {
                console.error('Error loading bot overview:', error);
                botHasLoadedOnce = true;
                setBotUpdatedNow();
                columns.innerHTML = '<div class="column"><p>Error loading bot overview.</p></div>';
            });
    };
    
    // Smart refresh for bot overview - only refresh if section is open
    let botOverviewRefreshInterval = null;
    
    function startBotOverviewRefresh() {
        if (botOverviewRefreshInterval === null) {
            loadBotOverview();
            setTimeout(() => {
                botOverviewRefreshInterval = setInterval(loadBotOverview, 60000);
            }, 200);
        }
    }
    
    function stopBotOverviewRefresh() {
        if (botOverviewRefreshInterval !== null) {
            clearInterval(botOverviewRefreshInterval);
            botOverviewRefreshInterval = null;
        }
    }
    
    // Initial load and setup refresh based on open/closed state
    const botOverviewSection = document.getElementById('bot-overview');
    if (botOverviewSection && botOverviewSection.classList.contains('open')) {
        startBotOverviewRefresh();
    }
    
    // Override toggle function to handle bot overview refresh
    const originalToggleCollapsible = window.toggleCollapsible;
    window.toggleCollapsible = function(sectionId, event) {
        originalToggleCollapsible(sectionId, event);
        
        // Handle bot overview refresh logic
        if (sectionId === 'bot-overview') {
            const content = document.getElementById(sectionId);
            if (content && content.classList.contains('open')) {
                startBotOverviewRefresh();
            } else {
                stopBotOverviewRefresh();
            }
        }
    };
    
    // Function to update bot message counts
    function updateBotMessageCounts() {
        fetch('?ajax=bot_message_counts')
            .then(res => res.json())
            .then(data => {
                if (data.botMessageStats) {
                    const messageSystemNames = {
                        'discordbot': 'Discord Bot',
                        'twitch_stable': 'Chat Bot Stable',
                        'twitch_beta': 'Chat Bot Beta',
                        'twitch_custom': 'Chat Bot Custom'
                    };
                    
                    for (const [key, label] of Object.entries(messageSystemNames)) {
                        const stats = data.botMessageStats[key];
                        if (stats) {
                            // Update message count
                            const countElement = document.querySelector(`[data-bot-system="${key}"] .bot-message-count-number`);
                            if (countElement) {
                                if (stats.messages_sent > 0) {
                                    countElement.textContent = new Intl.NumberFormat().format(stats.messages_sent);
                                } else {
                                    countElement.textContent = 'Not Counting Yet';
                                }
                            }
                            
                            // Update timestamp
                            const timestampElement = document.querySelector(`[data-bot-system="${key}"] .bot-message-count-timestamp`);
                            if (timestampElement && stats.last_updated) {
                                const date = new Date(stats.last_updated);
                                const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
                                timestampElement.textContent = 'Last Updated: ' + formattedDate;
                            }
                        }
                    }
                }
            })
            .catch(err => console.error('Error updating bot message counts:', err));
    }
    
    // Update bot message counts immediately and every 60 seconds
    updateBotMessageCounts();
    setInterval(updateBotMessageCounts, 60000);
    
    // Populate online channels asynchronously and enable send button only when both a channel is selected and a message is entered.
    const messageTextarea = document.getElementById('message');
    const sendButton = document.getElementById('send');
    const channelSelect = document.getElementById('channel-select');
    const includeOfflineCheckbox = document.getElementById('include-offline');
    const charCountElement = document.getElementById('char-count');
    function updateCharCount() {
        if (!messageTextarea || !charCountElement) return;
        const length = messageTextarea.value.length;
        charCountElement.textContent = length + ' / 255 characters';
        if (length > 255) {
            charCountElement.className = 'has-text-danger';
        } else if (length > 230) {
            charCountElement.className = 'has-text-warning';
        } else {
            charCountElement.className = 'has-text-grey';
        }
    }
    function updateSendButtonState() {
        if (!sendButton) return;
        const length = messageTextarea ? messageTextarea.value.length : 0;
        const hasMessage = messageTextarea && messageTextarea.value.trim() !== '' && length <= 255;
        const hasChannel = channelSelect && channelSelect.value && channelSelect.value !== '';
        sendButton.disabled = !(hasMessage && hasChannel);
    }
    // Fetch channels via AJAX (deferred heavy work)
    function fetchChannels() {
        const includeOffline = includeOfflineCheckbox && includeOfflineCheckbox.checked;
        const base = window.location.href.split('?')[0];
        const url = base + '?ajax=online_channels' + (includeOffline ? '&include_offline=1' : '');
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (!channelSelect) return;
                channelSelect.innerHTML = '';
                const channels = data.channels || [];
                if (channels.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = includeOffline ? 'No channels found' : 'No online channels';
                    channelSelect.appendChild(opt);
                    channelSelect.disabled = true;
                } else {
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = 'Choose a channel...';
                    channelSelect.appendChild(placeholder);
                    channels.forEach(ch => {
                        const opt = document.createElement('option');
                        opt.value = ch.twitch_user_id;
                        const displayName = ch.twitch_display_name || ch.twitch_user_id;
                        opt.textContent = ch.is_online ? displayName : displayName + ' (Offline)';
                        channelSelect.appendChild(opt);
                    });
                    channelSelect.disabled = false;
                }
                updateSendButtonState();
            })
            .catch(err => {
                console.error('Failed to load channels:', err);
                if (channelSelect) {
                    channelSelect.innerHTML = '';
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'Error loading channels';
                    channelSelect.appendChild(opt);
                    channelSelect.disabled = true;
                }
                updateSendButtonState();
            });
    }
    // Initial fetch
    fetchChannels();
    // Refetch when checkbox changes
    if (includeOfflineCheckbox) {
        includeOfflineCheckbox.addEventListener('change', fetchChannels);
    }
    if (messageTextarea) {
        messageTextarea.addEventListener('input', function() {
            updateCharCount();
            updateSendButtonState();
        });
    }
    if (channelSelect) {
        channelSelect.addEventListener('change', updateSendButtonState);
    }
    // Initial updates
    updateCharCount();
    updateSendButtonState();
    // Handle form submission via AJAX
    const sendMessageForm = document.getElementById('send-message-form');
    if (sendMessageForm) {
        sendMessageForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent normal form submission
            const formData = new FormData(this);
            formData.append('send_message', '1');
            const sendButton = document.getElementById('send');
            const originalText = sendButton.innerHTML;
            sendButton.disabled = true;
            sendButton.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Sending...</span>';
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Message send response status:', response.status);
                console.log('Message send response headers:', response.headers);
                return response.json();
            })
            .then(data => {
                console.log('Message send response data:', data);
                if (data.success) {
                    // Success - clear the textarea and show toast
                    document.getElementById('message').value = '';
                    updateSendButtonState(); // This will disable the send button since textarea is now empty
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        icon: 'success',
                        title: data.message
                    });
                } else {
                    // Error - show error toast
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true,
                        icon: 'error',
                        title: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                console.error('Error details:', {
                    message: error.message,
                    stack: error.stack,
                    name: error.name
                });
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 5000,
                    timerProgressBar: true,
                    icon: 'error',
                    title: 'Network error: ' + error.message
                });
            })
            .finally(() => {
                sendButton.disabled = false;
                sendButton.innerHTML = originalText;
            });
        });
    }
});
</script>
<?php
include "admin_layout.php";
?>