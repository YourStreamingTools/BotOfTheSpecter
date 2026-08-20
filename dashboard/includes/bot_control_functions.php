<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// SSH Connection parameters - Include SSH configuration and connection manager
// (still used for non-process ops: logs, token refresh scripts, etc.)
include_once "/var/www/config/ssh.php";
// Database connection for token lookups
require_once "/var/www/config/db_connect.php";
// Private bot-host control API (start/stop/status — no SSH for process control)
require_once __DIR__ . '/bots_api_client.php';

/**
 * Sanitize raw SSH command output to remove any appended exit-code markers
 * or internal markers injected by the SSH connection manager. This ensures
 * downstream parsing (numeric checks, exact string matches) isn't broken
 * by things like "[exit_code:0]".
 *
 * @param string|false|null $output
 * @return string|false|null
 */
function sanitizeSSHOutput($output) {
    if ($output === false || $output === null) return $output;
    // Cast to string to be safe
    $o = (string)$output;
    // Remove any trailing [exit_code:NN] marker (with optional surrounding whitespace/newlines)
    $o = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', $o);
    // Remove any internal unique marker used by the SSH wrapper
    $o = preg_replace('/__SSH_EXIT_STATUS__-?\d+\s*$/', '', $o);
    return trim($o);
}

/**
    * Check if a bot is running
    * @param string $username - The username of the bot owner
    * @param string $botType - Type of bot (stable, beta)
    * @return array - Status information including running state, PID, and version
*/
function checkBotRunning($username, $botType = 'stable') {
    $result = [
        'success' => false,
        'running' => false,
        'pid' => 0,
        'version' => '',
        'lastModified' => null,
        'lastRun' => null,
        // Update-notice fields from bots API (local file mtimes on bot host)
        'script_mtime' => null,
        'last_run_mtime' => null,
        'code_update_available' => false,
        // Operator-deployed custom_channel_modules/{channel}.py on bot host
        'custom_module_available' => false,
        'message' => ''
    ];
    $resp = bots_api_bot_status($username, $botType);
    // Custom-flag bots report bot_type=custom; beta status still finds them (fallback if miss).
    if ($resp['ok'] && $botType === 'beta') {
        $probe = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        if (empty($probe['running'])) {
            $fallback = bots_api_bot_status($username, 'custom');
            if ($fallback['ok'] && !empty(($fallback['data'] ?? [])['running'])) {
                $resp = $fallback;
            }
        }
    }
    if (!$resp['ok']) {
        $result['message'] = is_string($resp['error']) ? $resp['error'] : 'Bots API status request failed';
        return $result;
    }
    $data = is_array($resp['data']) ? $resp['data'] : [];
    $result['success'] = true;
    $foundType = $data['bot_type'] ?? null;
    $running = !empty($data['running']);
    if ($running && $botType) {
        $match = ($foundType === $botType)
            || ($botType === 'beta' && in_array($foundType, ['beta', 'custom'], true));
        if (!$match && $foundType !== null) {
            $running = false;
        }
    }
    $result['running'] = $running;
    $result['pid'] = $running ? intval($data['pid'] ?? 0) : 0;
    // Version-control file contents (last run version) — available even when offline
    $result['version'] = isset($data['version']) && $data['version'] !== null && $data['version'] !== ''
        ? (string)$data['version']
        : '';
    $result['script_mtime'] = isset($data['script_mtime']) && $data['script_mtime'] !== null
        ? (int)$data['script_mtime']
        : null;
    $result['last_run_mtime'] = isset($data['last_run_mtime']) && $data['last_run_mtime'] !== null
        ? (int)$data['last_run_mtime']
        : null;
    $result['code_update_available'] = !empty($data['code_update_available']);
    $result['custom_module_available'] = !empty($data['custom_module_available']);
    $result['lastModified'] = $result['script_mtime'];
    $result['lastRun'] = $result['last_run_mtime'];
    $result['message'] = 'Bot status retrieved successfully';
    return $result;
}

/**
    * Perform an action on the bot (start, stop) via the private bots control API.
    * @param string $action - The action to perform (run, stop)
    * @param string $botType - The type of bot to control (stable, beta, v6)
    * @param array $params - Additional parameters including username, tokens, etc.
    * @return array - Result of the operation
*/
function performBotAction($action, $botType, $params) {
    $username          = $params['username'] ?? '';
    $twitchUserId      = $params['twitch_user_id'] ?? '';
    $authToken         = $params['auth_token'] ?? '';
    $refreshToken      = $params['refresh_token'] ?? '';
    $apiKey            = $params['api_key'] ?? '';
    $useCustomBot      = $params['use_custom_bot'] ?? false;
    $customBotUsername = $params['custom_bot_username'] ?? null;
    $useSelf           = $params['use_self'] ?? false;
    $loadCustomModule  = !empty($params['load_custom_module']);

    // Version lookup
    $versionsUrl  = 'https://api.botofthespecter.com/versions';
    $versionsData = json_decode(@file_get_contents($versionsUrl), true);
    $version = '';
    if ($versionsData) {
        if ($botType === 'stable')     { $version = $versionsData['stable_version'] ?? ''; }
        elseif ($botType === 'beta')   { $version = $versionsData['beta_version']   ?? ''; }
        elseif ($botType === 'v6')     { $version = $versionsData['v6_version']      ?? '6.0.0'; }
        elseif ($botType === 'kick')   { $version = $versionsData['kick_bot']       ?? ''; }
        else                           { $version = $versionsData['stable_version'] ?? ''; }
    }

    $result = [
        'success' => false,
        'action'  => $action,
        'bot'     => $botType,
        'message' => '',
        'pid'     => 0,
        'version' => $version,
    ];

    if (!function_exists('bots_api_start_bot')) {
        require_once __DIR__ . '/bots_api_client.php';
    }
    if ($action === 'run') {
        if ($botType === 'kick') {
            $kickMissing = empty($username) || empty($twitchUserId) || empty($authToken) || empty($refreshToken) || empty($apiKey)
                || empty($params['kick_username'] ?? '') || empty($params['kick_chatroom_id'] ?? '')
                || empty($params['kick_client_id'] ?? '') || empty($params['kick_client_secret'] ?? '');
            if ($kickMissing) {
                $result['message'] = 'Kick is not connected or Kick app credentials are missing.';
                return $result;
            }
        } elseif (empty($username) || empty($twitchUserId) || empty($authToken) || empty($refreshToken) || empty($apiKey)) {
            $result['message'] = 'Missing required bot parameters (username, tokens, etc.)';
            return $result;
        }
        $payload = [
            'channel'     => $username,
            'bot_type'    => $botType,
            'channel_id'  => $twitchUserId,
            'token'       => $authToken,
            'refresh'     => $refreshToken,
            'apitoken'    => $apiKey,
            'custom'      => (bool)($useCustomBot && $botType === 'beta'),
            'botusername' => ($useCustomBot && $botType === 'beta') ? $customBotUsername : null,
            'self'        => (bool)($useSelf && $botType === 'beta'),
            'version'     => $version,
            // beta/v6 only; bots API also requires the {channel}.py file on the host
            'load_custom_module' => (bool)($loadCustomModule && in_array($botType, ['beta', 'v6'], true)),
        ];
        if ($botType === 'kick') {
            $payload['kick_username'] = $params['kick_username'] ?? '';
            $payload['chatroom_id'] = $params['kick_chatroom_id'] ?? '';
            $payload['client_id'] = $params['kick_client_id'] ?? '';
            $payload['client_secret'] = $params['kick_client_secret'] ?? '';
        }
        $resp = bots_api_start_bot($payload);
        if (!$resp['ok']) {
            $result['message'] = is_string($resp['error']) ? $resp['error'] : 'Failed to start bot via bots API';
            return $result;
        }
        $data = is_array($resp['data']) ? $resp['data'] : [];
        $result['success'] = !empty($data['success']) || in_array($data['state'] ?? '', ['started', 'already_running', 'start_pending'], true);
        $result['pid']     = intval($data['pid'] ?? 0);
        $result['version'] = $data['version'] ?? $version;
        $result['message'] = $data['message'] ?? 'Bot start requested';
        return $result;
    } elseif ($action === 'stop') {
        $resp = bots_api_stop_bot($username, $botType);
        if (!$resp['ok']) {
            $result['message'] = is_string($resp['error']) ? $resp['error'] : 'Failed to stop bot via bots API';
            return $result;
        }
        $data = is_array($resp['data']) ? $resp['data'] : [];
        $result['success'] = true;
        $result['pid']     = 0;
        $result['message'] = $data['message'] ?? 'Bot stop requested';
        return $result;
    }
    $result['message'] = 'Unknown action';
    return $result;
}
?>
