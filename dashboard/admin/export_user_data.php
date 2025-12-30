<?php
session_start();
header('Content-Type: application/json');

// Auth: allow admin or the user requesting their own data
if (!isset($_SESSION['access_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit();
}

$requestUsername = isset($_POST['username']) ? trim($_POST['username']) : '';

// Authorization: admin or matching username (case-insensitive)
$isAllowed = false;
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    $isAllowed = true;
}
// Allow a user to request their own export by username match (CI)
if (!empty($requestUsername) && isset($_SESSION['username']) && strcasecmp($_SESSION['username'], $requestUsername) === 0) {
    $isAllowed = true;
}

if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Forbidden']);
    exit();
}

// SSH-only: start worker on bot server. Load SSH config if present.
@include_once '/var/www/config/ssh.php';

$finalUsername = '';
if (!empty($requestUsername)) {
    $finalUsername = $requestUsername;
} elseif (!empty($_SESSION['username'])) {
    $finalUsername = $_SESSION['username'];
}

if (empty($finalUsername)) {
    echo json_encode(['success' => false, 'msg' => 'Username missing; cannot start export']);
    exit();
}

$escapedArgs = escapeshellarg($finalUsername);

// Cooldown: prevent repeated export requests (7 days) for non-admins.
$cooldownDir = '/var/www/data_export_requests';
$cooldownFile = $cooldownDir . '/' . preg_replace('/[^a-z0-9._-]/', '_', strtolower($finalUsername)) . '.json';
$cooldownSeconds = 7 * 24 * 3600; // 7 days
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    if (file_exists($cooldownFile)) {
        $data = json_decode(@file_get_contents($cooldownFile), true);
        $last = isset($data['last_requested_at']) ? intval($data['last_requested_at']) : 0;
        $now = time();
        if ($now - $last < $cooldownSeconds) {
            $remain = $cooldownSeconds - ($now - $last);
            $days = floor($remain / 86400);
            $hours = floor(($remain % 86400) / 3600);
            $minutes = floor(($remain % 3600) / 60);
            $parts = [];
            if ($days) $parts[] = "{$days}d";
            if ($hours) $parts[] = "{$hours}h";
            if ($minutes) $parts[] = "{$minutes}m";
            $when = $parts ? implode(' ', $parts) : 'a short while';
            echo json_encode(['success' => false, 'msg' => "Please wait {$when} before requesting another export."]);
            exit();
        }
    }
}

// Use SSHConnectionManager if configured
if (isset($bots_ssh_host) && !empty($bots_ssh_host) && class_exists('SSHConnectionManager')) {
    try {
        $conn = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if (!$conn) {
            echo json_encode(['success' => false, 'msg' => 'Could not establish SSH connection to bot server']);
            exit();
        }
        // Enqueue job on the bot server for sequential processing
        try {
            $job = [
                'username' => $finalUsername,
                'requested_at' => time(),
                'requested_by' => isset($_SESSION['username']) ? $_SESSION['username'] : 'web',
            ];
            $jobJson = json_encode($job);
            $rand = bin2hex(random_bytes(4));
            $remoteFile = "/home/botofthespecter/export_queue/job-" . time() . "-" . $rand . ".json";
            // create queue dir and write job via heredoc to avoid escaping issues
            $remoteCmd = "mkdir -p /home/botofthespecter/export_queue && cat > " . escapeshellarg($remoteFile) . " <<'JSON'\n" . $jobJson . "\nJSON\n";
            $res = SSHConnectionManager::executeCommand($conn, $remoteCmd, true);
            if ($res === false) {
                echo json_encode(['success' => false, 'msg' => 'Failed to enqueue remote export job via SSH. Check SSH credentials and permissions.']);
                exit();
            }
            // Record the request timestamp so users cannot re-request within the cooldown window.
            if (!is_dir($cooldownDir)) {
                @mkdir($cooldownDir, 0755, true);
            }
            @file_put_contents($cooldownFile, json_encode(['last_requested_at' => time()]));
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'msg' => 'SSH error creating job: ' . $e->getMessage()]);
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => 'SSH connection error: ' . $e->getMessage()]);
        exit();
    }
} else {
    // SSH not configured - provide diagnostic info
    $diagnostic = [];
    if (!isset($bots_ssh_host) || empty($bots_ssh_host)) {
        $diagnostic[] = 'SSH host not configured';
    }
    if (!class_exists('SSHConnectionManager')) {
        $diagnostic[] = 'SSHConnectionManager class not found';
    }
    $msg = 'SSH configuration missing: ' . implode(', ', $diagnostic);
    echo json_encode(['success' => false, 'msg' => $msg]);
    exit();
}

echo json_encode(['success' => true, 'msg' => 'Export started']);
exit();
