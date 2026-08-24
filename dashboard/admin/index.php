<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_dashboard_title');
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once "/var/www/config/admin_actions.php";
require_once "/var/www/config/twitch.php";
require_once __DIR__ . '/../includes/websocket_control_client.php';
include "../includes/userdata.php";
session_write_close();

function get_twitch_app_credentials_for_dashboard($conn) {
    $resolvedClientId = isset($GLOBALS['clientID']) ? trim((string)$GLOBALS['clientID']) : '';
    $resolvedOAuth = isset($GLOBALS['oauth']) ? trim((string)$GLOBALS['oauth']) : '';
    if (!isset($conn) || !$conn) {
        return [
            'client_id' => $resolvedClientId,
            'oauth' => $resolvedOAuth
        ];
    }
    $res = $conn->query("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1");
    if ($res) {
        $row = $res->fetch_assoc();
        if (is_array($row)) {
            foreach (['twitch_client_id', 'client_id', 'clientID'] as $clientIdKey) {
                if (array_key_exists($clientIdKey, $row) && !empty($row[$clientIdKey])) {
                    $resolvedClientId = trim((string)$row[$clientIdKey]);
                    break;
                }
            }
            foreach (['twitch_oauth_api_token', 'oauth', 'chat_oauth_token', 'twitch_oauth_token', 'twitch_access_token', 'bot_oauth_token'] as $oauthKey) {
                if (array_key_exists($oauthKey, $row) && !empty($row[$oauthKey])) {
                    $resolvedOAuth = trim((string)$row[$oauthKey]);
                    break;
                }
            }
        }
    }
    return [
        'client_id' => $resolvedClientId,
        'oauth' => $resolvedOAuth
    ];
}

// Collect server-side logs for browser console output instead of writing to server error log
$client_console_logs = [];
function client_console_log($msg, $level = 'error') {
    global $client_console_logs;
    if (!is_string($msg)) {
        $msg = print_r($msg, true);
    }
    // Basic sanitization to avoid leaking tokens or long binary data
    $msg = preg_replace('/(Authorization:\s*Bearer\s+)[^\s\\]+/i', '$1[REDACTED]', $msg);
    $msg = preg_replace('/(access_token|refresh_token|api_key|apiKey)["\']?\s*[:=]\s*[^\s\,\)\}]+/i', '$1: [REDACTED]', $msg);
    $msg = mb_substr($msg, 0, 2000);
    $client_console_logs[] = ['level' => $level, 'msg' => $msg];
}

// Heuristic extractor for OpenAI usage responses: searches nested arrays/objects for token metrics and model names
function extract_openai_usage_metrics($obj) {
    $result = [
        'model' => null,
        'input_tokens' => null,
        'output_tokens' => null
    ];
    $stack = [$obj];
    // Pattern to match model-like identifiers including hyphens, underscores and dots
    $modelPattern = '/\b(?:gpt|claude|anthropic|mistral|llama)[-_0-9a-zA-Z\.]+/i';
    while (!empty($stack)) {
        $cur = array_pop($stack);
        // Normalize objects to arrays for consistent handling
        if (is_object($cur)) $cur = get_object_vars($cur);
        if (!is_array($cur)) continue;
        // If this node looks like a grouped bucket with 'group' or 'by' keys, prioritize scanning its children
        if (isset($cur['group']) && (is_array($cur['group']) || is_object($cur['group']))) {
            $stack[] = $cur['group'];
        }
        if (isset($cur['by']) && (is_array($cur['by']) || is_object($cur['by']))) {
            $stack[] = $cur['by'];
        }
        foreach ($cur as $k => $v) {
            // If the key itself looks like a model name (e.g., grouped responses keyed by model), capture it
            if ($result['model'] === null && is_string($k) && preg_match($modelPattern, $k)) {
                $result['model'] = $k;
            }
            if (is_array($v) || is_object($v)) {
                $stack[] = $v;
                continue;
            }
            $key = is_string($k) ? strtolower($k) : '';
            // Heuristics for model identification from scalar values
            if ($result['model'] === null && is_string($v)) {
                if (in_array($key, ['model','model_id','modelname','model_name','modelid','id','by']) && strlen($v) > 0 && preg_match($modelPattern, $v)) {
                    $result['model'] = $v;
                } elseif (preg_match($modelPattern, $v)) {
                    $result['model'] = $v;
                }
            }
            // Token detection
            if ($result['input_tokens'] === null && (preg_match('/input.*token/', $key) || in_array($key, ['input_tokens','input_token','prompt_tokens']))) {
                if (is_numeric($v)) $result['input_tokens'] = intval($v);
            }
            if ($result['output_tokens'] === null && (preg_match('/output.*token/', $key) || preg_match('/completion|response|total/', $key) || in_array($key, ['output_tokens','output_token','completion_tokens','completion_token','response_tokens','total_tokens']))) {
                if (is_numeric($v)) $result['output_tokens'] = intval($v);
            }
            // Sometimes model name appears under generic keys like 'name' or 'title'
            if ($result['model'] === null && in_array($key, ['name','title']) && is_string($v) && preg_match($modelPattern, $v)) {
                $result['model'] = $v;
            }
        }
        // Early exit if we've at least discovered a model and any token metric
        if ($result['model'] !== null && ($result['input_tokens'] !== null || $result['output_tokens'] !== null)) break;
    }
    return $result;
}

// Find all metric-bearing sub-objects in a JSON structure
function find_all_metrics($obj) {
    $results = [];
    $stack = [$obj];
    while (!empty($stack)) {
        $cur = array_pop($stack);
        if (is_array($cur)) {
            $isAssoc = array_keys($cur) !== range(0, count($cur) - 1);
            if ($isAssoc) {
                $m = extract_openai_usage_metrics($cur);
                if ($m['model'] !== null || $m['input_tokens'] !== null || $m['output_tokens'] !== null) {
                    $results[] = $m;
                }
            }
            foreach ($cur as $v) {
                if (is_array($v) || is_object($v)) $stack[] = $v;
            }
        } elseif (is_object($cur)) {
            $vars = get_object_vars($cur);
            $m = extract_openai_usage_metrics($vars);
            if ($m['model'] !== null || $m['input_tokens'] !== null || $m['output_tokens'] !== null) {
                $results[] = $m;
            }
            foreach ($vars as $v) {
                if (is_array($v) || is_object($v)) $stack[] = $v;
            }
        }
    }
    return $results;
}

// Aggregate a JSON usage response into a map of model => metrics
function parse_openai_grouped_usage($data) {
    $map = [];
    $items = find_all_metrics($data);
    foreach ($items as $m) {
        $model = $m['model'] ?? 'unknown';
        if (empty($model)) $model = 'unknown';
        if (!isset($map[$model])) {
            $map[$model] = ['input' => 0, 'output' => 0];
        }
        if (!empty($m['input_tokens'])) $map[$model]['input'] += intval($m['input_tokens']);
        if (!empty($m['output_tokens'])) $map[$model]['output'] += intval($m['output_tokens']);
    }
    return $map;
}

function openai_normalize_model_name($model) {
    $name = strtolower(trim((string)$model));
    if ($name === '') return 'unknown';
    $name = preg_replace('/\s+/', '', $name);
    return $name;
}

function openai_get_default_pricing_per_million() {
    return [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        'o3' => ['input' => 10.00, 'output' => 40.00],
        'o3-mini' => ['input' => 1.10, 'output' => 4.40],
        'o1' => ['input' => 15.00, 'output' => 60.00],
        'o1-mini' => ['input' => 3.00, 'output' => 12.00]
    ];
}

function openai_resolve_pricing_for_model($model, $openai_config = null) {
    $defaults = openai_get_default_pricing_per_million();
    $configured = [];
    if (is_array($openai_config) && isset($openai_config['pricing_per_million']) && is_array($openai_config['pricing_per_million'])) {
        foreach ($openai_config['pricing_per_million'] as $k => $v) {
            if (!is_array($v)) continue;
            $nk = openai_normalize_model_name($k);
            $configured[$nk] = [
                'input' => isset($v['input']) ? floatval($v['input']) : 0.0,
                'output' => isset($v['output']) ? floatval($v['output']) : 0.0
            ];
        }
    }
    $priceMap = !empty($configured) ? $configured : $defaults;
    $source = !empty($configured) ? 'config' : 'default';
    $normalizedModel = openai_normalize_model_name($model);
    if (isset($priceMap[$normalizedModel])) {
        return [
            'input' => max(0, floatval($priceMap[$normalizedModel]['input'] ?? 0)),
            'output' => max(0, floatval($priceMap[$normalizedModel]['output'] ?? 0)),
            'matched_model' => $normalizedModel,
            'source' => $source
        ];
    }
    $bestKey = null;
    foreach (array_keys($priceMap) as $candidate) {
        if ($candidate === 'default') continue;
        if ($candidate !== '' && strpos($normalizedModel, $candidate) === 0) {
            if ($bestKey === null || strlen($candidate) > strlen($bestKey)) {
                $bestKey = $candidate;
            }
        }
    }
    if ($bestKey !== null) {
        return [
            'input' => max(0, floatval($priceMap[$bestKey]['input'] ?? 0)),
            'output' => max(0, floatval($priceMap[$bestKey]['output'] ?? 0)),
            'matched_model' => $bestKey,
            'source' => $source
        ];
    }
    $defaultInput = 0.0;
    $defaultOutput = 0.0;
    if (isset($priceMap['default']) && is_array($priceMap['default'])) {
        $defaultInput = max(0, floatval($priceMap['default']['input'] ?? 0));
        $defaultOutput = max(0, floatval($priceMap['default']['output'] ?? 0));
    } elseif (is_array($openai_config) && isset($openai_config['default_pricing_per_million']) && is_array($openai_config['default_pricing_per_million'])) {
        $defaultInput = max(0, floatval($openai_config['default_pricing_per_million']['input'] ?? 0));
        $defaultOutput = max(0, floatval($openai_config['default_pricing_per_million']['output'] ?? 0));
    }
    return [
        'input' => $defaultInput,
        'output' => $defaultOutput,
        'matched_model' => $defaultInput > 0 || $defaultOutput > 0 ? 'default' : 'unpriced',
        'source' => $source
    ];
}

function openai_estimate_model_cost($model, $input_tokens, $output_tokens, $openai_config = null) {
    $pricing = openai_resolve_pricing_for_model($model, $openai_config);
    $inputTokens = max(0, intval($input_tokens));
    $outputTokens = max(0, intval($output_tokens));
    $inputCost = ($inputTokens / 1000000) * floatval($pricing['input']);
    $outputCost = ($outputTokens / 1000000) * floatval($pricing['output']);
    return [
        'total' => $inputCost + $outputCost,
        'input_cost' => $inputCost,
        'output_cost' => $outputCost,
        'input_rate_per_million' => floatval($pricing['input']),
        'output_rate_per_million' => floatval($pricing['output']),
        'matched_model' => $pricing['matched_model'] ?? 'unpriced',
        'source' => $pricing['source'] ?? 'default'
    ];
}

function render_ai_platform_stats_content($ai_model_stats, $ai_model_cost_stats, $ai_total_estimated_cost, $ai_cost_pricing_source, $ai_cost_window_label, $ai_has_priced_models, $ai_total_requests, $ai_requests_per_day) {
    $sorted_model_stats = is_array($ai_model_stats) ? $ai_model_stats : [];
    $total_input_tokens = 0;
    $total_output_tokens = 0;
    foreach ($sorted_model_stats as $mvals) {
        $total_input_tokens += isset($mvals['input']) ? intval($mvals['input']) : 0;
        $total_output_tokens += isset($mvals['output']) ? intval($mvals['output']) : 0;
    }
    $total_efficiency_ratio = $total_input_tokens > 0 ? ($total_output_tokens / $total_input_tokens) : null;
    uasort($sorted_model_stats, function($a, $b) {
        $ain = $a['input'] ?? 0; $bin = $b['input'] ?? 0;
        if ($bin !== $ain) return $bin <=> $ain;
        $aout = $a['output'] ?? 0; $bout = $b['output'] ?? 0;
        return $bout <=> $aout;
    });
ob_start();
?>
<div style="display: flex; flex-wrap: wrap; gap: 0.75rem 1.5rem; margin-bottom: 0.5rem;">
    <div style="flex: 1 1 170px; min-width: 170px;">
        <span class="admin-heading"><?php echo t('admin_index_total_input_tokens'); ?></span>
        <p style="font-size:1.1rem; font-weight:700; margin:0;"><?php echo number_format($total_input_tokens); ?></p>
    </div>
    <div style="flex: 1 1 170px; min-width: 170px;">
        <span class="admin-heading"><?php echo t('admin_index_total_output_tokens'); ?></span>
        <p style="font-size:1.1rem; font-weight:700; margin:0;"><?php echo number_format($total_output_tokens); ?></p>
    </div>
    <div style="flex: 1 1 170px; min-width: 170px;">
        <span class="admin-heading"><?php echo t('admin_index_estimated_cost'); ?></span>
        <p style="font-size:1.1rem; font-weight:700; margin:0;">$<?php echo number_format($ai_total_estimated_cost, 4); ?></p>
    </div>
    <div style="flex: 1 1 170px; min-width: 170px;">
        <span class="admin-heading"><?php echo t('admin_index_token_efficiency_out_in'); ?></span>
        <p style="font-size:1.1rem; font-weight:700; margin:0;"><?php echo $total_efficiency_ratio !== null ? number_format($total_efficiency_ratio, 2) . 'x' : 'N/A'; ?></p>
    </div>
    <div style="flex: 1 1 170px; min-width: 170px;">
        <span class="admin-heading"><?php echo t('admin_index_total_requests'); ?></span>
        <p style="font-size:1.1rem; font-weight:700; margin:0;"><?php echo number_format((int)$ai_total_requests); ?></p>
    </div>
    <div style="flex: 1 1 170px; min-width: 170px;">
        <span class="admin-heading"><?php echo t('admin_index_requests_per_day'); ?></span>
        <p style="font-size:1.1rem; font-weight:700; margin:0;"><?php echo $ai_requests_per_day !== null ? number_format($ai_requests_per_day, 1) : 'N/A'; ?></p>
    </div>
</div>
<br>
<div class="sp-table-wrap">
    <table class="sp-table">
        <thead>
            <tr>
                <th><?php echo t('admin_index_th_model'); ?></th>
                <th><?php echo t('admin_index_th_input_tokens'); ?></th>
                <th><?php echo t('admin_index_th_output_tokens'); ?></th>
                <th><?php echo t('admin_index_th_token_efficiency'); ?></th>
                <th><?php echo t('admin_index_th_estimated_cost'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($sorted_model_stats)): ?>
            <?php foreach ($sorted_model_stats as $mname => $vals): ?>
                <?php
                    $model_input_tokens = isset($vals['input']) ? intval($vals['input']) : 0;
                    $model_output_tokens = isset($vals['output']) ? intval($vals['output']) : 0;
                    $model_efficiency = $model_input_tokens > 0 ? ($model_output_tokens / $model_input_tokens) : null;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$mname); ?></td>
                    <td><?php echo number_format($model_input_tokens); ?></td>
                    <td><?php echo number_format($model_output_tokens); ?></td>
                    <td><?php echo $model_efficiency !== null ? number_format($model_efficiency, 2) . 'x' : 'N/A'; ?></td>
                    <td>$<?php echo number_format($ai_model_cost_stats[$mname]['total'] ?? 0, 4); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" class="sp-text-muted"><?php echo t('admin_index_no_ai_usage_data'); ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
return (string)ob_get_clean();
}

// Handle service control actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['service'])) {
    $action = $_POST['action'];
    $service = $_POST['service'];
    // Initialize defaults so we always return useful JSON
    $success = false;
    $output = '';
    // Define allowed services
    $allowedServices = ['discordbot.service', 'bots-api.service', 'bots-caddy.service', 'fastapi.service', 'api-caddy.service', 'websocket.service', 'mysql.service', 'export_queue_worker.service', 'twitch-recorder.service', 'caddy.service'];
    // Some allowed "service" identifiers are dashboard-only aliases so the same real unit name
    // (e.g. caddy.service) can be routed to different hosts; map alias -> actual systemd unit here.
    $serviceUnitOverrides = [
        'bots-caddy.service' => 'caddy.service',
        'api-caddy.service' => 'caddy.service',
    ];
    if (in_array($service, $allowedServices)) {
        try {
            // WebSocket host lifecycle via private HTTP control API (no SSH) — same idea as bots_api
            if ($service == 'websocket.service') {
                $wsUnit = 'websocket';
                $wsResp = websocket_control_service_action($action, $wsUnit);
                $success = !empty($wsResp['ok']);
                if ($success) {
                    $data = is_array($wsResp['data'] ?? null) ? $wsResp['data'] : [];
                    $output = (string)($data['message'] ?? ("WebSocket " . $action . "ed successfully"));
                } else {
                    $output = (string)($wsResp['error'] ?? 'websocket control API failed');
                }
            } else {
            // Determine which server credentials to use based on service
            $ssh_host = $bots_ssh_host ?? '';
            $ssh_username = $bots_ssh_username ?? '';
            $ssh_password = $bots_ssh_password ?? '';
            if ($service == 'fastapi.service' || $service == 'api-caddy.service') {
                // Use the variable names defined in config/ssh.php
                $ssh_host = $api_server_host ?? '';
                $ssh_username = $api_server_username ?? '';
                $ssh_password = $api_server_password ?? '';
            } elseif ($service == 'mysql.service') {
                $ssh_host = $sql_server_host ?? '';
                $ssh_username = $sql_server_username ?? '';
                $ssh_password = $sql_server_password ?? '';
            } elseif ($service == 'twitch-recorder.service') {
                // Retired service — refuse control (status is fixed SHUTDOWN in service_status.php)
                $output = 'Twitch Recorder is shut down and cannot be controlled from the admin panel.';
                $success = false;
                $ssh_host = '';
            } elseif ($service == 'caddy.service') {
                // The web host's Caddy instance - separate box from the bots host
                $ssh_host = $web_ssh_host ?? '';
                $ssh_username = $web_ssh_username ?? '';
                $ssh_password = $web_ssh_password ?? '';
            }
            if ($service == 'twitch-recorder.service') {
                // no SSH — status is fixed SHUTDOWN
            } elseif (!($connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password))) {
                $output = "SSH connection failed to host: {$ssh_host} (check config/ssh.php and network)";
                $success = false;
            } else {
                // Use non-interactive sudo (-n) so the command fails quickly if a password is required
                $actualUnit = $serviceUnitOverrides[$service] ?? $service;
                $command = "sudo -n systemctl $action $actualUnit";
                $output = SSHConnectionManager::executeCommand($connection, $command);
                // If executeCommand returned false, it likely timed out or failed to run
                if ($output === false) {
                    $success = false;
                    $output = "Command execution failed or timed out";
                } else {
                    // The most reliable indicator is the exit status code (0 = success)
                    $exit_status = SSHConnectionManager::$last_exit_status ?? null;
                    // Log raw values for debugging
                    client_console_log("[admin service control] Raw exit_status type: " . gettype($exit_status) . ", value: " . var_export($exit_status, true));
                    client_console_log("[admin service control] Raw output (first 300 chars): " . substr($output, 0, 300));
                    // Handle both int and string representations of 0
                    // Also consider it success if we didn't get a non-zero exit code and the command executed without returning false
                    if ($exit_status === 0 || $exit_status === '0' || intval($exit_status) === 0) {
                        $success = true;
                    } elseif ($exit_status === null) {
                        // If exit status is null but we got output (command didn't fail), treat as success
                        // The SSH fallback may not have captured the exit code properly
                        $success = true;
                        client_console_log("[admin service control] Exit status was null, but command executed - assuming success");
                    } else {
                        // Non-zero exit status means failure
                        $success = false;
                    }
                    client_console_log("[admin service control] $service $action - success: " . ($success ? 'true' : 'false') . ", exit_status: " . var_export($exit_status, true));
                    // Provide a user-friendly message even if output is empty
                    if ($success && empty(trim($output))) {
                        $serviceNames = [
                            'discordbot.service' => 'Discord Bot',
                            'bots-api.service' => 'Bots API',
                            'bots-caddy.service' => 'CADDY — BOTS SERVER',
                            'fastapi.service' => 'FastAPI',
                            'api-caddy.service' => 'CADDY — API SERVER',
                            'websocket.service' => 'WebSocket',
                            'mysql.service' => 'MySQL',
                            'export_queue_worker.service' => 'Export Queue Worker',
                            'twitch-recorder.service' => 'Twitch Recorder',
                            'caddy.service' => 'CADDY — WEB SERVER',
                        ];
                        $actionLabels = ['start' => 'started', 'stop' => 'stopped', 'restart' => 'restarted'];
                        $svcLabel = $serviceNames[$service] ?? $service;
                        $actLabel = $actionLabels[$action] ?? ($action . 'ed');
                        $output = "{$svcLabel} {$actLabel} successfully";
                    }
                }
            }
            } // end non-websocket SSH branch
        } catch (Exception $e) {
            $success = false;
            $output = 'Exception: ' . $e->getMessage();
        }
    } else {
        $output = t('admin_index_invalid_service');
        $success = false;
    }
    // Return JSON response instead of redirect. Include command output for diagnostics.
    if (function_exists('ob_get_length') && ob_get_length() !== false && ob_get_length() > 0) {
        $prev = ob_get_clean();
        @client_console_log("[admin service control] cleared previous output buffer: " . substr(trim(preg_replace('/\s+/', ' ', $prev)), 0, 1000));
        // Start a fresh buffer to ensure headers can be sent
        ob_start();
    }
    // Log the result server-side to aid debugging (will appear in PHP error log)
    @client_console_log("[admin service control] service={$service} action={$action} success=" . ($success ? '1' : '0') . " exit_status=" . (SSHConnectionManager::$last_exit_status ?? 'null') . " output=" . str_replace("\n", "\\n", substr($output ?? '', 0, 1000)));
    admin_audit_log(
        'service_control',
        $success ? 'success' : 'failed',
        [
            'action' => $action,
            'service' => $service,
            'exit_status' => SSHConnectionManager::$last_exit_status ?? null,
            'output_preview' => mb_substr((string) ($output ?? ''), 0, 300)
        ],
        'service',
        $service
    );
    ob_clean();
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

// Handle refresh Spotify / StreamElements / Discord tokens via bots API (no SSH)
require_once __DIR__ . '/../includes/bots_api_client.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_spotify_tokens'])) {
    $resp = bots_api_run_script('refresh_spotify');
    $success = !empty($resp['ok']);
    $output = is_array($resp['data'] ?? null)
        ? (string)(($resp['data']['output'] ?? '') ?: ($resp['data']['message'] ?? ''))
        : (string)($resp['error'] ?? 'bots API error');
    admin_audit_log(
        'refresh_spotify_tokens',
        $success ? 'success' : 'failed',
        ['output_preview' => mb_substr((string) ($output ?? ''), 0, 300)],
        'script',
        'refresh_spotify_tokens.py'
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_streamelements_tokens'])) {
    $resp = bots_api_run_script('refresh_streamelements');
    $success = !empty($resp['ok']);
    $output = is_array($resp['data'] ?? null)
        ? (string)(($resp['data']['output'] ?? '') ?: ($resp['data']['message'] ?? ''))
        : (string)($resp['error'] ?? 'bots API error');
    admin_audit_log(
        'refresh_streamelements_tokens',
        $success ? 'success' : 'failed',
        ['output_preview' => mb_substr((string) ($output ?? ''), 0, 300)],
        'script',
        'refresh_streamelements_tokens.py'
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_discord_tokens'])) {
    $resp = bots_api_run_script('refresh_discord');
    $success = !empty($resp['ok']);
    $output = is_array($resp['data'] ?? null)
        ? (string)(($resp['data']['output'] ?? '') ?: ($resp['data']['message'] ?? ''))
        : (string)($resp['error'] ?? 'bots API error');
    admin_audit_log(
        'refresh_discord_tokens',
        $success ? 'success' : 'failed',
        ['output_preview' => mb_substr((string) ($output ?? ''), 0, 300)],
        'script',
        'refresh_discord_tokens.py'
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['refresh_token_script'])) {
    $scriptMap = [
        'spotify' => ['api' => 'refresh_spotify', 'file' => 'refresh_spotify_tokens.py'],
        'streamelements' => ['api' => 'refresh_streamelements', 'file' => 'refresh_streamelements_tokens.py'],
        'discord' => ['api' => 'refresh_discord', 'file' => 'refresh_discord_tokens.py'],
        'custom_bot' => ['api' => 'refresh_custom_bot', 'file' => 'refresh_custom_bot_tokens.py'],
        'twitch_app' => ['api' => 'refresh_twitch_app_token', 'file' => 'refresh_twitch_app_token.py'],
    ];
    $scriptKey = trim((string) $_POST['refresh_token_script']);
    ob_clean();
    header('Content-Type: application/json');
    if (!isset($scriptMap[$scriptKey])) {
        echo json_encode(['success' => false, 'output' => t('admin_stream_command_invalid_script')]);
        exit;
    }
    $mapped = $scriptMap[$scriptKey];
    $resp = bots_api_run_script($mapped['api']);
    $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
    $err = $resp['error'] ?? null;
    if (is_array($err) && (isset($err['output']) || isset($err['message']) || array_key_exists('success', $err))) {
        $data = $err;
    } elseif (is_array($data) && isset($data['detail']) && is_array($data['detail'])) {
        $data = $data['detail'];
    }
    $success = !empty($resp['ok']) && (($data['success'] ?? true) !== false);
    if (isset($data['output']) && $data['output'] !== '') {
        $output = (string) $data['output'];
    } elseif (!empty($data['message'])) {
        $output = (string) $data['message'];
    } elseif (is_string($err) && $err !== '') {
        $output = $err;
    } elseif (is_array($err)) {
        $output = json_encode($err);
    } else {
        $output = '';
    }
    admin_audit_log(
        'refresh_token_script',
        $success ? 'success' : 'failed',
        ['script_key' => $scriptKey, 'output_preview' => mb_substr($output, 0, 300)],
        'script',
        $mapped['file']
    );
    echo json_encode(['success' => $success, 'output' => $output]);
    exit;
}

function admin_index_days_until_reset_day($reset_day) {
    $rd = intval($reset_day);
    if ($rd < 1 || $rd > 31) {
        return null;
    }
    try {
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n');
        $day = (int) $today->format('j');
        if ($day >= $rd) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
        $useDay = min($rd, (int) $monthStart->format('t'));
        $next = $monthStart->setDate($year, $month, $useDay);
        return max(0, (int) $today->diff($next)->days);
    } catch (Exception $e) {
        return null;
    }
}

function admin_index_resolve_exchangerate_sync_key() {
    global $conn, $admin_key;
    if (isset($conn) && $conn) {
        $sql = "SELECT api_key FROM admin_api_keys
                WHERE LOWER(service) IN ('exchangerate', 'admin')
                ORDER BY CASE WHEN LOWER(service) = 'exchangerate' THEN 0 ELSE 1 END
                LIMIT 1";
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row && !empty($row['api_key'])) {
                return (string) $row['api_key'];
            }
        }
    }
    if (!empty($admin_key)) {
        return (string) $admin_key;
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_exchangerate_quota'])) {
    $success = false;
    $message = '';
    $payload = [
        'requests_remaining' => null,
        'reset_day' => null,
        'days_remaining' => null,
        'plan_quota' => null,
        'updated' => null,
        'quota_reached' => false,
    ];
    $syncKey = admin_index_resolve_exchangerate_sync_key();
    if ($syncKey === '') {
        $message = t('admin_index_exchangerate_no_admin_key');
    } else {
        $url = 'https://api.botofthespecter.com/v2/api/exchangerate/quota-sync';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $syncKey,
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false || $curlError) {
            $message = t('admin_index_exchangerate_sync_failed') . ' ' . $curlError;
        } else {
            $data = json_decode($response, true);
            if (!is_array($data)) {
                $message = t('admin_index_invalid_json');
            } elseif ($httpCode >= 200 && $httpCode < 300 && !empty($data['success'])) {
                $success = true;
                $payload['requests_remaining'] = isset($data['requests_remaining']) ? (int) $data['requests_remaining'] : null;
                $payload['reset_day'] = isset($data['reset_day']) ? (int) $data['reset_day'] : null;
                $payload['days_remaining'] = isset($data['days_remaining']) ? (int) $data['days_remaining'] : null;
                $payload['plan_quota'] = isset($data['plan_quota']) ? (int) $data['plan_quota'] : null;
                $payload['updated'] = !empty($data['updated']) ? (string) $data['updated'] : date('Y-m-d H:i:s');
                $payload['quota_reached'] = !empty($data['quota_reached']);
                $message = t('admin_index_exchangerate_synced');
            } else {
                $detail = '';
                if (isset($data['detail'])) {
                    $detail = is_string($data['detail']) ? $data['detail'] : json_encode($data['detail']);
                }
                $message = trim(t('admin_index_exchangerate_sync_failed') . ' ' . $detail);
            }
        }
    }
    admin_audit_log(
        'check_exchangerate_quota',
        $success ? 'success' : 'failed',
        [
            'requests_remaining' => $payload['requests_remaining'],
            'reset_day' => $payload['reset_day'],
            'message_preview' => mb_substr((string) $message, 0, 300),
        ],
        'api',
        'exchangerate_quota_sync'
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $payload));
    exit;
}

// Handle bot stop action (bots API only — no SSH kill)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['stop_bot'])) {
    $pid = intval($_POST['pid'] ?? 0);
    $stopUsername = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['username'] ?? '')));
    $stopBotType = trim($_POST['bot_type'] ?? '');
    $allowedStopTypes = ['stable', 'beta', 'v6', 'custom'];
    if (!in_array($stopBotType, $allowedStopTypes, true)) {
        $stopBotType = '';
    }
    $stopSuccess = false;
    $stopError = '';
    if ($stopUsername === '') {
        $stopError = 'Username required';
    } else {
        try {
            if ($stopBotType === '') {
                $status = bots_api_bot_status($stopUsername, null);
                if ($status['ok'] && is_array($status['data'] ?? null) && !empty($status['data']['bot_type'])) {
                    $stopBotType = (string)$status['data']['bot_type'];
                } else {
                    $stopBotType = 'stable';
                }
            }
            $resp = bots_api_stop_bot($stopUsername, $stopBotType);
            $stopSuccess = !empty($resp['ok']);
            if (!$stopSuccess) {
                $stopError = is_string($resp['error'] ?? null) ? $resp['error'] : 'bots API stop failed';
            }
        } catch (Exception $e) {
            $stopError = $e->getMessage();
        }
    }
    admin_audit_log(
        'stop_bot',
        $stopSuccess ? 'success' : 'failed',
        ['pid' => $pid, 'username' => $stopUsername, 'bot_type' => $stopBotType, 'error' => $stopError],
        'bot_pid',
        (string) $pid
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $stopSuccess, 'error' => $stopError]);
    exit;
}

// Handle bot restart action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restart_bot'])) {
    require_once '../includes/bot_control_functions.php';
    $username = trim($_POST['username'] ?? '');
    $originalBotType = trim($_POST['bot_type'] ?? 'stable');
    $pid = intval($_POST['pid'] ?? 0);
    $allowedBotTypes = ['stable', 'beta', 'custom'];
    $botType = in_array($originalBotType, $allowedBotTypes, true) ? $originalBotType : 'stable';
    // Log the restart attempt
    client_console_log("Bot restart request - Username: {$username}, Requested Type: {$originalBotType}, Restarting as: {$botType}, PID: {$pid}");
    $success = false;
    $message = '';
    if (empty($username)) {
        $message = t('admin_index_username_required_short');
    } else {
        try {
            // Get user data including refresh_token and api_key from users table
            $stmt = $conn->prepare("SELECT twitch_user_id, refresh_token, api_key FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $userData = $result->fetch_assoc();
                $twitchUserId = $userData['twitch_user_id'];
                $refreshToken = $userData['refresh_token'];
                $apiKey = $userData['api_key'];
                // Get bot access token from twitch_bot_access table
                $stmt2 = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?");
                $stmt2->bind_param("s", $twitchUserId);
                $stmt2->execute();
                $tokenResult = $stmt2->get_result();
                if ($tokenResult->num_rows > 0) {
                    $tokenData = $tokenResult->fetch_assoc();
                    $botAccessToken = $tokenData['twitch_access_token'];
                    client_console_log("RESTART DEBUG - About to restart: Username={$username}, BotType={$botType}, PID={$pid}");
                    // Step 1: Stop via bots API (no SSH kill)
                    $stopType = ($botType === 'custom') ? 'custom' : $botType;
                    client_console_log("RESTART DEBUG - Stopping via bots API type={$stopType}");
                    try {
                        require_once __DIR__ . '/../includes/bots_api_client.php';
                        $stopResp = bots_api_stop_bot($username, $stopType);
                        if (empty($stopResp['ok']) && $stopType === 'beta') {
                            bots_api_stop_bot($username, 'custom');
                        }
                        sleep(1);
                    } catch (Exception $e) {
                        client_console_log("Error stopping bot during restart: " . $e->getMessage());
                    }
                    // Step 2: Start the bot with correct tokens
                    $params = [
                        'username' => $username,
                        'twitch_user_id' => $twitchUserId,
                        'auth_token' => $botAccessToken,  // Bot token from twitch_bot_access
                        'refresh_token' => $refreshToken,  // Refresh token from users table
                        'api_key' => $apiKey
                    ];
                    client_console_log("RESTART DEBUG - Calling performBotAction('run', '{$botType}', ...) for {$username}");
                    $result = performBotAction('run', $botType, $params);
                    client_console_log("RESTART DEBUG - performBotAction result: " . json_encode($result));
                    $success = $result['success'];
                    $message = $result['message'] ?? t('admin_index_bot_restart_completed');
                } else {
                    $message = t('admin_index_bot_token_not_found');
                }
                $stmt2->close();
            } else {
                $message = t('admin_index_user_not_found');
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = t('admin_index_err_restarting_bot') . ' ' . $e->getMessage();
            client_console_log("Bot restart error: " . $e->getMessage());
        }
    }
    admin_audit_log(
        'restart_bot',
        $success ? 'success' : 'failed',
        [
            'username' => $username,
            'requested_bot_type' => $originalBotType,
            'started_bot_type' => $botType,
            'pid' => $pid,
            'message' => $message
        ],
        'username',
        $username
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Handle send message action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $twitchAppCreds = get_twitch_app_credentials_for_dashboard($conn);
    $chatClientId = $twitchAppCreds['client_id'] ?? '';
    $chatOAuth = $twitchAppCreds['oauth'] ?? '';
    $message = trim($_POST['message']);
    $channel_id = $_POST['channel_id'];
    if (!empty($message) && !empty($channel_id) && !empty($chatClientId) && !empty($chatOAuth)) {
        if (strlen($message) > 255) {
            $error_message = t('admin_index_msg_too_long', [strlen($message)]);
        } else {
            $runningCheck = assertChannelHasRunningTwitchBot($conn, (string)$channel_id);
            if (!$runningCheck['ok']) {
                $error_message = $runningCheck['error'];
            } else {
            // Send message directly via Twitch API using bot token
            $url = "https://api.twitch.tv/helix/chat/messages";
            $headers = [
                "Authorization: Bearer " . $chatOAuth,
                "Client-Id: " . $chatClientId,
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
            $curl_error = curl_error($ch);
if ($curl_errno) {
                $error_message = t('admin_index_msg_send_failed') . ' ' . $curl_error;
            } elseif ($http_code === 200) {
                $response_data = json_decode($response, true);
                if ($response_data && isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data']) > 0) {
                    $msg_data = $response_data['data'][0];
                    $is_sent = $msg_data['is_sent'] ?? false;
                    $drop_reason = $msg_data['drop_reason'] ?? null;
                    if ($is_sent) {
                        $success_message = t('admin_index_msg_sent_success');
                    } else {
                        $error_message = t('admin_index_msg_not_sent');
                        if ($drop_reason) {
                            $error_message .= ' ' . t('admin_index_msg_drop_reason') . ' ' . $drop_reason;
                        }
                    }
                } else {
                    $error_message = t('admin_index_msg_invalid_response');
                }
            } else {
                $error_message = t('admin_index_msg_send_failed_http', [$http_code]);
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
        }
    } else {
        if (empty($chatClientId) || empty($chatOAuth)) {
            $error_message = t('admin_index_creds_missing');
        } else {
        $error_message = t('admin_index_msg_channel_required');
        }
    }
    admin_audit_log(
        'send_chat_message',
        isset($success_message) ? 'success' : 'failed',
        [
            'channel_id' => $channel_id ?? '',
            'message_length' => isset($message) ? strlen($message) : 0,
            'message_preview' => isset($message) ? mb_substr($message, 0, 120) : '',
            'result' => $success_message ?? ($error_message ?? 'Unknown error')
        ],
        'channel_id',
        (string) ($channel_id ?? '')
    );
    // Return JSON response for AJAX
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => isset($success_message),
        'message' => $success_message ?? $error_message ?? t('admin_index_unknown_error')
    ]);
    exit;
}

// Handle send shoutout action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_shoutout'])) {
    $twitchAppCreds = get_twitch_app_credentials_for_dashboard($conn);
    $chatClientId = $twitchAppCreds['client_id'] ?? '';
    $chatOAuth = $twitchAppCreds['oauth'] ?? '';
    $from_broadcaster_id = trim((string)($_POST['channel_id'] ?? ''));
    $target_login_raw = trim((string)($_POST['shoutout_username'] ?? ''));
    $target_login = ltrim(strtolower($target_login_raw), '@');
    $moderator_id = '971436498';
    $success = false;
    $response_message = 'Unknown error';
    $resolved_target_id = '';
    if (empty($chatClientId) || empty($chatOAuth)) {
        $response_message = t('admin_index_creds_missing');
    } elseif (empty($from_broadcaster_id) || empty($target_login)) {
        $response_message = t('admin_index_shoutout_channel_required');
    } elseif (!preg_match('/^[a-z0-9_]{3,25}$/', $target_login)) {
        $response_message = t('admin_index_invalid_username_format');
    } else {
        $runningCheck = assertChannelHasRunningTwitchBot($conn, $from_broadcaster_id);
        if (!$runningCheck['ok']) {
            $response_message = $runningCheck['error'];
        } else {
        $lookup_url = 'https://api.twitch.tv/helix/users?login=' . rawurlencode($target_login);
        $headers = [
            'Authorization: Bearer ' . $chatOAuth,
            'Client-Id: ' . $chatClientId,
            'Content-Type: application/json'
        ];
        $lookup_ch = curl_init($lookup_url);
        curl_setopt($lookup_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($lookup_ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($lookup_ch, CURLOPT_TIMEOUT, 10);
        $lookup_response = curl_exec($lookup_ch);
        $lookup_http_code = curl_getinfo($lookup_ch, CURLINFO_HTTP_CODE);
        $lookup_curl_errno = curl_errno($lookup_ch);
        $lookup_curl_error = curl_error($lookup_ch);
if ($lookup_curl_errno) {
            $response_message = t('admin_index_validate_user_failed') . ' ' . $lookup_curl_error;
        } elseif ($lookup_http_code !== 200) {
            $response_message = t('admin_index_validate_user_failed_http', [$lookup_http_code]);
            $lookup_error_json = json_decode((string)$lookup_response, true);
            if (is_array($lookup_error_json) && !empty($lookup_error_json['message'])) {
                $response_message .= ': ' . $lookup_error_json['message'];
            }
        } else {
            $lookup_data = json_decode((string)$lookup_response, true);
            $resolved_user = (is_array($lookup_data) && isset($lookup_data['data'][0]) && is_array($lookup_data['data'][0])) ? $lookup_data['data'][0] : null;
            if (!$resolved_user || empty($resolved_user['id'])) {
                $response_message = t('admin_index_user_not_found_for', [$target_login]);
            } else {
                $resolved_target_id = (string)$resolved_user['id'];
                $resolved_target_login = (string)($resolved_user['login'] ?? $target_login);
                $resolved_target_display = (string)($resolved_user['display_name'] ?? $resolved_target_login);
                if ($resolved_target_id === $from_broadcaster_id) {
                    $response_message = t('admin_index_shoutout_same_broadcaster');
                } else {
                    $shoutout_query = http_build_query([
                        'from_broadcaster_id' => $from_broadcaster_id,
                        'to_broadcaster_id' => $resolved_target_id,
                        'moderator_id' => $moderator_id
                    ]);
                    $shoutout_url = 'https://api.twitch.tv/helix/chat/shoutouts?' . $shoutout_query;
                    $shoutout_ch = curl_init($shoutout_url);
                    curl_setopt($shoutout_ch, CURLOPT_POST, true);
                    curl_setopt($shoutout_ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($shoutout_ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($shoutout_ch, CURLOPT_TIMEOUT, 10);
                    $shoutout_response = curl_exec($shoutout_ch);
                    $shoutout_http_code = curl_getinfo($shoutout_ch, CURLINFO_HTTP_CODE);
                    $shoutout_curl_errno = curl_errno($shoutout_ch);
                    $shoutout_curl_error = curl_error($shoutout_ch);
if ($shoutout_curl_errno) {
                        $response_message = t('admin_index_shoutout_send_failed') . ' ' . $shoutout_curl_error;
                    } elseif ($shoutout_http_code === 204) {
                        $success = true;
                        $response_message = t('admin_index_shoutout_sent_success', [$resolved_target_display]);
                    } else {
                        $response_message = t('admin_index_shoutout_send_failed_http', [$shoutout_http_code]);
                        $shoutout_error_json = json_decode((string)$shoutout_response, true);
                        if (is_array($shoutout_error_json) && !empty($shoutout_error_json['message'])) {
                            $response_message .= ': ' . $shoutout_error_json['message'];
                        }
                    }
                }
            }
        }
        }
    }
    admin_audit_log(
        'send_shoutout',
        $success ? 'success' : 'failed',
        [
            'channel_id' => $from_broadcaster_id,
            'target_login' => $target_login,
            'target_user_id' => $resolved_target_id,
            'target_game' => $resolved_target_game,
            'chat_message_sent' => $chat_message_sent ? 1 : 0,
            'result' => $response_message
        ],
        'channel_id',
        (string)$from_broadcaster_id
    );
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $response_message,
        'target_user_id' => $resolved_target_id,
        'target_game' => $resolved_target_game,
        'chat_message_sent' => $chat_message_sent
    ]);
    exit;
}

// Live Twitch bot inventory keyed by channel login (stable/beta/v6/custom). Kick is excluded.
function getRunningTwitchBotInventory(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $client = __DIR__ . '/../includes/bots_api_client.php';
    if (!is_file($client)) {
        $cached = ['ok' => false, 'channels' => [], 'error' => 'bots_api_client.php missing'];
        return $cached;
    }
    require_once $client;
    $resp = bots_api_running_bots();
    if (empty($resp['ok']) || !is_array($resp['data'])) {
        $err = $resp['error'] ?? 'Bots API request failed';
        if (!is_string($err) || $err === '') {
            $err = 'Bots API request failed';
        }
        $cached = ['ok' => false, 'channels' => [], 'error' => $err];
        return $cached;
    }
    $bots = $resp['data']['bots'] ?? [];
    if (!is_array($bots)) {
        $bots = [];
    }
    $isBucketMap = isset($bots['stable']) || isset($bots['beta']) || isset($bots['v6']) || isset($bots['custom']);
    if (!$isBucketMap && $bots !== [] && array_keys($bots) === range(0, count($bots) - 1)) {
        $bots = ['stable' => $bots];
    }
    $channels = [];
    foreach (['stable', 'beta', 'v6', 'custom'] as $botType) {
        foreach ((array)($bots[$botType] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ch = strtolower(trim((string)($entry['channel'] ?? '')));
            if ($ch === '' || isset($channels[$ch])) {
                continue;
            }
            $channels[$ch] = (string)($entry['bot_type'] ?? $botType);
        }
    }
    $cached = ['ok' => true, 'channels' => $channels, 'error' => null];
    return $cached;
}

function lookupUsernameByTwitchUserId($conn, string $twitchUserId): string {
    if (!$conn || $twitchUserId === '') {
        return '';
    }
    $stmt = $conn->prepare("SELECT username FROM users WHERE twitch_user_id = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param("s", $twitchUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return strtolower(trim((string)($row['username'] ?? '')));
}

function assertChannelHasRunningTwitchBot($conn, string $twitchUserId): array {
    $inventory = getRunningTwitchBotInventory();
    if (!$inventory['ok']) {
        return ['ok' => false, 'error' => t('admin_index_err_bots_inventory')];
    }
    $login = lookupUsernameByTwitchUserId($conn, $twitchUserId);
    if ($login === '' || !isset($inventory['channels'][$login])) {
        return ['ok' => false, 'error' => t('admin_index_bot_not_running')];
    }
    return ['ok' => true, 'username' => $login, 'bot_type' => $inventory['channels'][$login]];
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
$data = json_decode($response, true);
    $online_ids = [];
    if (isset($data['data'])) {
        foreach ($data['data'] as $stream) {
            $online_ids[] = $stream['user_id'];
        }
    }
    return $online_ids;
}

// Prepare an empty placeholder for the online channels - they'll be populated by JS via AJAX.
$online_channels = [];
$return_ai_stats_json = false;
// AJAX handlers: bot_overview and online_channels
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];
    ob_clean();
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
        // Live (or all, with include_offline) channels that currently have a Twitch bot running.
        $channels = [];
        $include_offline = isset($_GET['include_offline']) && $_GET['include_offline'] == '1';
        $inventory = getRunningTwitchBotInventory();
        if (!$inventory['ok']) {
            echo json_encode([
                'channels' => [],
                'error' => 'bots_inventory'
            ]);
            exit;
        }
        $running = $inventory['channels'];
        if ($conn && isset($_SESSION['access_token']) && !empty($running)) {
            $result = $conn->query("SELECT id, username, twitch_user_id, twitch_display_name FROM users");
            if ($result) {
                $user_ids = [];
                $user_data = [];
                while ($row = $result->fetch_assoc()) {
                    $login = strtolower(trim((string)($row['username'] ?? '')));
                    if ($login === '' || !isset($running[$login])) {
                        continue;
                    }
                    $twitchUserId = (string)($row['twitch_user_id'] ?? '');
                    if ($twitchUserId === '') {
                        continue;
                    }
                    $user_ids[] = $twitchUserId;
                    $user_data[$twitchUserId] = [
                        'id' => $row['id'],
                        'twitch_user_id' => $twitchUserId,
                        'twitch_display_name' => $row['twitch_display_name'],
                        'bot_type' => $running[$login]
                    ];
                }
                $online_user_ids = getOnlineUserIds($user_ids, $clientID, $_SESSION['access_token']);
                $onlineLookup = [];
                foreach ($online_user_ids as $onlineId) {
                    $onlineLookup[(string)$onlineId] = true;
                }
                foreach ($user_data as $user_id => $row) {
                    $is_online = isset($onlineLookup[(string)$user_id]);
                    if ($include_offline || $is_online) {
                        $row['is_online'] = $is_online;
                        $channels[] = $row;
                    }
                }
            }
        }
        echo json_encode(['channels' => $channels]);
        exit;
    } elseif ($ajax === 'validate_shoutout_user') {
        $twitchAppCreds = get_twitch_app_credentials_for_dashboard($conn);
        $chatClientId = $twitchAppCreds['client_id'] ?? '';
        $chatOAuth = $twitchAppCreds['oauth'] ?? '';
        if (empty($chatClientId) || empty($chatOAuth)) {
            echo json_encode(['valid' => false, 'message' => t('admin_index_creds_missing_short')]);
            exit;
        }
        $login_raw = trim((string)($_GET['login'] ?? ''));
        $login = ltrim(strtolower($login_raw), '@');
        if (empty($login)) {
            echo json_encode(['valid' => false, 'message' => t('admin_index_username_required')]);
            exit;
        }
        if (!preg_match('/^[a-z0-9_]{3,25}$/', $login)) {
            echo json_encode(['valid' => false, 'message' => t('admin_index_invalid_username_format')]);
            exit;
        }

        $lookup_url = 'https://api.twitch.tv/helix/users?login=' . rawurlencode($login);
        $headers = [
            'Authorization: Bearer ' . $chatOAuth,
            'Client-Id: ' . $chatClientId,
            'Content-Type: application/json'
        ];
        $lookup_ch = curl_init($lookup_url);
        curl_setopt($lookup_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($lookup_ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($lookup_ch, CURLOPT_TIMEOUT, 10);
        $lookup_response = curl_exec($lookup_ch);
        $lookup_http_code = curl_getinfo($lookup_ch, CURLINFO_HTTP_CODE);
        $lookup_curl_errno = curl_errno($lookup_ch);
        $lookup_curl_error = curl_error($lookup_ch);
if ($lookup_curl_errno) {
            echo json_encode(['valid' => false, 'message' => t('admin_index_validation_request_failed') . ' ' . $lookup_curl_error]);
            exit;
        }
        if ($lookup_http_code !== 200) {
            $error_message = t('admin_index_validation_failed_http', [$lookup_http_code]);
            $lookup_error_json = json_decode((string)$lookup_response, true);
            if (is_array($lookup_error_json) && !empty($lookup_error_json['message'])) {
                $error_message .= ': ' . $lookup_error_json['message'];
            }
            echo json_encode(['valid' => false, 'message' => $error_message]);
            exit;
        }

        $lookup_data = json_decode((string)$lookup_response, true);
        $resolved_user = (is_array($lookup_data) && isset($lookup_data['data'][0]) && is_array($lookup_data['data'][0])) ? $lookup_data['data'][0] : null;
        if (!$resolved_user || empty($resolved_user['id'])) {
            echo json_encode(['valid' => false, 'message' => t('admin_index_twitch_user_not_found')]);
            exit;
        }

        echo json_encode([
            'valid' => true,
            'user_id' => (string)$resolved_user['id'],
            'login' => (string)($resolved_user['login'] ?? $login),
            'display_name' => (string)($resolved_user['display_name'] ?? $login)
        ]);
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
    } elseif ($ajax === 'ai_platform_stats') {
        $return_ai_stats_json = true;
        // Give extra time for OpenAI API pagination calls
        set_time_limit(120);
    }
}

// Function to get service status
function getServiceStatus($service_name, $ssh_host, $ssh_username, $ssh_password) {
    $status = 'Unknown';
    $pid = 'N/A';
    if (empty($ssh_host)) {
        return ['status' => $status, 'pid' => $pid];
    }
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

// Function to get bot status (bots control API only — no SSH)
function getBotStatus($bots_ssh_host = null, $bots_ssh_username = null, $bots_ssh_password = null) {
    $client = __DIR__ . '/../includes/bots_api_client.php';
    if (!is_file($client)) {
        return "Error: bots_api_client.php missing";
    }
    require_once $client;
    $resp = bots_api_running_bots();
    if ($resp['ok'] && is_array($resp['data'])) {
        $data = $resp['data'];
        $lines = [];
        $bots = $data['bots'] ?? [];
        // Optional published versions for OUTDATED tag (same parse format as AJAX overview)
        $latest = [];
        $verRaw = @file_get_contents('https://api.botofthespecter.com/versions');
        if ($verRaw) {
            $latest = json_decode($verRaw, true) ?: [];
        }
        $latestMap = [
            'stable' => $latest['stable_version'] ?? null,
            'beta' => $latest['beta_version'] ?? null,
            'v6' => $latest['v6_version'] ?? ($latest['beta_version'] ?? null),
            'custom' => $latest['beta_version'] ?? ($latest['stable_version'] ?? null),
            'kick' => null,
        ];
        foreach (['stable' => 'Stable', 'beta' => 'Beta', 'v6' => 'V6', 'custom' => 'Custom', 'kick' => 'Kick'] as $key => $label) {
            $list = $bots[$key] ?? [];
            $lines[] = "{$label} bots running:";
            if (!$list) {
                $lines[] = "None";
            } else {
                foreach ($list as $b) {
                    $ch = $b['channel'] ?? '?';
                    $pid = $b['pid'] ?? '?';
                    $ver = $b['version'] ?? 'Unknown';
                    $statusTag = 'OK';
                    $pub = $latestMap[$key] ?? null;
                    if ($pub && $ver && $ver !== 'Unknown' && version_compare((string)$ver, (string)$pub, '<')) {
                        $statusTag = 'OUTDATED';
                    }
                    $lines[] = "- Channel: {$ch}, PID: {$pid}, Version: {$ver} | {$statusTag}";
                }
                $lines[] = "Total: " . count($list);
            }
            $lines[] = "";
        }
        $lines[] = "Total all: " . intval($data['total'] ?? 0);
        return implode("\n", $lines);
    }
    if (!empty($resp['error'])) {
        return "Error fetching bot status via bots API: " . $resp['error'];
    }
    return "Error fetching bot status via bots API (unknown)";
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
return null;
    }
    $data = json_decode($response, true);
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
$twitch_recorder_status = ['status' => 'Loading...', 'pid' => '...'];

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
    if (!$return_ai_stats_json && isset($_SESSION['access_token'])) {
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
    'discordbot' => t('admin_index_msgsys_discord_bot'),
    'twitch_stable' => t('admin_index_msgsys_chat_bot_stable'),
    'twitch_beta' => t('admin_index_msgsys_chat_bot_beta'),
    'twitch_custom' => t('admin_index_msgsys_chat_bot_custom')
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

$exchangerateQuota = [
    'count' => null,
    'reset_day' => null,
    'days_remaining' => null,
    'updated' => null,
];
if ($conn) {
    $erRes = $conn->query("SELECT count, reset_day, updated FROM api_counts WHERE type = 'exchangerate' LIMIT 1");
    if ($erRes && ($erRow = $erRes->fetch_assoc())) {
        $exchangerateQuota['count'] = isset($erRow['count']) ? (int) $erRow['count'] : null;
        $exchangerateQuota['reset_day'] = isset($erRow['reset_day']) ? (int) $erRow['reset_day'] : null;
        $exchangerateQuota['days_remaining'] = admin_index_days_until_reset_day($exchangerateQuota['reset_day']);
        $exchangerateQuota['updated'] = !empty($erRow['updated']) ? $erRow['updated'] : null;
    }
}

// Defer bot status fetching until the AJAX request
$bot_output = '';
$stable_bots = [];
$beta_bots = [];
$all_bots = [];

// Message templates for quick send (can be moved to config file later)
$message_templates = [
    'Update Complete' => 'An automated update has been applied, no further action is needed.',
    'Scheduled Maintenance' => 'An automated update is being applied. Bots will automatically restart during the process and a follow-up message will be sent once the restart completes.',
    'Reconnection Successful' => 'I lost connection to internal services earlier; reboot completed and reconnection successful.',
];
// Fetch OpenAI organization usage for completions (show basic stats)
$ai_model = 'N/A';
$ai_input_tokens = 'N/A';
$ai_output_tokens = 'N/A';
$ai_model_stats = [];
$ai_model_cost_stats = [];
$ai_total_estimated_cost = 0.0;
$ai_has_priced_models = false;
$ai_cost_window_label = 'N/A';
$ai_cost_pricing_source = 'Default pricing table';
$ai_total_requests = 0;
$ai_requests_per_day = null;
$ai_window_days = null;
$ai_model_request_stats = [];
$openai_debug_info = [];
$openai_config = null;
$configPath = '/var/www/config/openai.php';
if (file_exists($configPath)) {
    $openai_config = require $configPath;
}
$openai_key = null;
if (is_array($openai_config)) {
    $openai_key = $openai_config['admin_key'] ?? null;
}
// NOTE: do not fallback to environment variables; rely on config file per project conventions
if (!empty($openai_key) && $return_ai_stats_json) {
    // Determine bucket width (config only) and map to defaults and caps per API docs
    $bucket_width = is_array($openai_config) ? ($openai_config['bucket_width'] ?? '1d') : '1d';
    if (!in_array($bucket_width, ['1m','1h','1d'])) $bucket_width = '1d';
    if ($bucket_width === '1m') { $default_limit = 60; $max_limit = 1440; $bucket_seconds = 60; }
    elseif ($bucket_width === '1h') { $default_limit = 24; $max_limit = 168; $bucket_seconds = 3600; }
    else { $default_limit = 7; $max_limit = 31; $bucket_seconds = 86400; }
    // Determine limit (respect config, clamp to max, fallback to default)
    if (is_array($openai_config) && isset($openai_config['limit'])) {
        $limit = max(1, intval($openai_config['limit']));
        if ($limit > $max_limit) $limit = $max_limit;
    } else {
        // Default to 30 days for daily buckets to increase coverage (but respect API max)
        if ($bucket_width === '1d') {
            $limit = min(30, $max_limit);
        } else {
            $limit = $default_limit;
        }
    }
    // If no start_time is specified and using daily buckets, extend limit to 30 days for better coverage
    if (is_array($openai_config) && !isset($openai_config['start_time']) && $bucket_width === '1d') {
        if ($limit < 30) $limit = min(30, $max_limit);
    }
    // Determine end_time (optional in config)
    if (is_array($openai_config) && isset($openai_config['end_time'])) {
        $end_time = is_numeric($openai_config['end_time']) ? intval($openai_config['end_time']) : strtotime($openai_config['end_time']);
    } else {
        $end_time = time();
    }
    // Determine start_time: required by API; use config if present, otherwise compute from end_time and limit*buckets
    if (is_array($openai_config) && isset($openai_config['start_time'])) {
        $start_cfg = $openai_config['start_time'];
        $start_time = is_numeric($start_cfg) ? intval($start_cfg) : strtotime($start_cfg);
    } else {
        $start_time = $end_time - ($limit * $bucket_seconds);
    }
    // Force a 30-day window override to ensure we capture models across the month.
    // This will replace computed start_time/limit unless a config explicitly provides a start_time.
    $forceThirtyDays = true;
    if ($forceThirtyDays && !(is_array($openai_config) && isset($openai_config['start_time']))) {
        $start_time = time() - (30 * 86400);
        $end_time = time();
        $limit = min(30, $max_limit);
        @client_console_log('[openai override] forcing 30-day window start_time=' . $start_time . ' limit=' . $limit);
    }
    if (is_numeric($start_time) && is_numeric($end_time)) {
        $ai_cost_window_label = date('M j, Y', intval($start_time)) . ' - ' . date('M j, Y', intval($end_time));
        $windowSeconds = max(0, intval($end_time) - intval($start_time));
        $ai_window_days = max(1, (int)ceil($windowSeconds / 86400));
    }
    $base = 'https://api.openai.com/v1';
    // Build query params using documented fields. Only include optional params if present in config.
    $queryParams = [
        'start_time' => $start_time,
        'bucket_width' => $bucket_width,
        'limit' => $limit
    ];
    if (is_array($openai_config) && !empty($openai_config['api_key_ids'])) $queryParams['api_key_ids'] = $openai_config['api_key_ids'];
    if (is_array($openai_config) && isset($openai_config['batch'])) $queryParams['batch'] = $openai_config['batch'] ? 'true' : 'false';
    if (is_array($openai_config) && !empty($openai_config['group_by'])) $queryParams['group_by'] = $openai_config['group_by'];
    else $queryParams['group_by'] = ['model'];
    if (is_array($openai_config) && !empty($openai_config['models'])) $queryParams['models'] = $openai_config['models'];
    if (is_array($openai_config) && !empty($openai_config['project_ids'])) $queryParams['project_ids'] = $openai_config['project_ids'];
    if (is_array($openai_config) && !empty($openai_config['user_ids'])) $queryParams['user_ids'] = $openai_config['user_ids'];
    if (is_array($openai_config) && !empty($openai_config['page'])) $queryParams['page'] = $openai_config['page'];
    if (is_array($openai_config) && isset($openai_config['end_time'])) $queryParams['end_time'] = $end_time;
    $qs = http_build_query($queryParams);
    // Helper: fetch all paginated pages for an OpenAI usage endpoint
    function openai_get_all_pages($path, $baseQuery, $openai_config = null, $openai_key = null, $maxPages = 20) {
        $allBuckets = [];
        $page = null;
        $pagesFetched = 0;
        $debug_entries = [];
        do {
            $qp = $baseQuery;
            if ($page !== null) $qp['page'] = $page;
            $qs = http_build_query($qp);
            $url = 'https://api.openai.com/v1' . $path . '?' . $qs;
            $resArr = openai_multi_curl([['method'=>'GET','url'=>$url,'timeout'=>30]], $openai_config, $openai_key);
            $r = $resArr[0] ?? null;
            if (!$r) break;
            $pagesFetched++;
            $body = $r['response'] ?? null;
            $decoded = $body ? json_decode($body, true) : null;
            // Build a compact per-page summary (models and token totals) to avoid dumping full JSON into the page
            $page_summary = null;
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                $page_summary = [];
                foreach ($decoded['data'] as $bucket) {
                    if (!is_array($bucket) || empty($bucket['results'])) continue;
                    foreach ($bucket['results'] as $res) {
                        $m = $res['model'] ?? ($res['model_name'] ?? 'unknown');
                        if (empty($m)) $m = 'unknown';
                        if (!isset($page_summary[$m])) $page_summary[$m] = ['input' => 0, 'output' => 0, 'count' => 0];
                        $page_summary[$m]['input'] += !empty($res['input_tokens']) ? intval($res['input_tokens']) : 0;
                        $page_summary[$m]['output'] += !empty($res['output_tokens']) ? intval($res['output_tokens']) : 0;
                        $page_summary[$m]['count'] += 1;
                    }
                }
            }
            // record debug info for this fetch (keep only compact summary)
            $debug_entries[] = [
                'url' => $url,
                'http_code' => $r['http_code'] ?? null,
                'curl_error' => $r['curl_error'] ?? null,
                'summary' => $page_summary
            ];
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                // append buckets
                foreach ($decoded['data'] as $bucket) {
                    $allBuckets[] = $bucket;
                }
            }
            if (is_array($decoded) && !empty($decoded['next_page'])) {
                $page = $decoded['next_page'];
            } else {
                $page = null;
            }
            // stop if API indicates no more or we've hit maxPages
        } while ($page !== null && $pagesFetched < $maxPages);
        return ['object'=>'page','data'=>$allBuckets,'has_more'=>false,'next_page'=>null,'pages_fetched'=>$pagesFetched,'debug'=>$debug_entries];
    }
    // Increase timeout for larger time windows and fetch all pages for each endpoint
    $results_completions = openai_get_all_pages('/organization/usage/completions', $queryParams, $openai_config, $openai_key);
    // Prepare per-model stats map
    $ai_model_stats = [];
    // completions: $results_completions is a combined page-like structure
    $data = is_array($results_completions) ? $results_completions : null;
    if ($data) {
        // Try direct extraction first
        $row = $data;
        if (isset($data[0]) && is_array($data[0])) $row = $data[0];
        $ai_model = $row['model'] ?? $ai_model;
        $ai_input_tokens = isset($row['input_tokens']) ? number_format($row['input_tokens']) : $ai_input_tokens;
        $ai_output_tokens = isset($row['output_tokens']) ? number_format($row['output_tokens']) : $ai_output_tokens;
        // Heuristic extraction fallback if still N/A
        if ($ai_model === 'N/A' || $ai_input_tokens === 'N/A' || $ai_output_tokens === 'N/A') {
            $metrics = extract_openai_usage_metrics($data);
            if ($metrics['model'] !== null) $ai_model = $metrics['model'];
            if ($metrics['input_tokens'] !== null) $ai_input_tokens = number_format($metrics['input_tokens']);
            if ($metrics['output_tokens'] !== null) $ai_output_tokens = number_format($metrics['output_tokens']);
        }
        // Explicit parsing for documented page/bucket/results shape
        $explicit_map = [];
        $fallback_result_count = 0;
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $bucket) {
                if (!is_array($bucket)) continue;
                if (isset($bucket['results']) && is_array($bucket['results'])) {
                    foreach ($bucket['results'] as $res) {
                        if (!is_array($res)) continue;
                        $fallback_result_count++;
                        $mname = $res['model'] ?? ($res['model_name'] ?? null);
                        if (empty($mname)) $mname = 'unknown';
                        if (!isset($explicit_map[$mname])) $explicit_map[$mname] = ['input' => 0, 'output' => 0, 'requests' => 0];
                        if (!empty($res['input_tokens'])) $explicit_map[$mname]['input'] += intval($res['input_tokens']);
                        if (!empty($res['output_tokens'])) $explicit_map[$mname]['output'] += intval($res['output_tokens']);
                        $requestCount = 0;
                        if (isset($res['num_model_requests']) && is_numeric($res['num_model_requests'])) {
                            $requestCount = intval($res['num_model_requests']);
                        } elseif (isset($res['requests']) && is_numeric($res['requests'])) {
                            $requestCount = intval($res['requests']);
                        } elseif (isset($res['request_count']) && is_numeric($res['request_count'])) {
                            $requestCount = intval($res['request_count']);
                        } elseif (isset($res['total_requests']) && is_numeric($res['total_requests'])) {
                            $requestCount = intval($res['total_requests']);
                        }
                        $explicit_map[$mname]['requests'] += max(0, $requestCount);
                        // audio output tokens omitted (audio endpoint disabled)
                    }
                }
            }
        }
        if (!empty($explicit_map)) {
            @client_console_log('[openai explicit_map completions] ' . json_encode($explicit_map));
            foreach ($explicit_map as $mname => $vals) {
                if (!isset($ai_model_stats[$mname])) $ai_model_stats[$mname] = ['input' => 0, 'output' => 0];
                $ai_model_stats[$mname]['input'] += $vals['input'];
                $ai_model_stats[$mname]['output'] += $vals['output'];
                $modelRequests = intval($vals['requests'] ?? 0);
                $ai_total_requests += $modelRequests;
                if (!isset($ai_model_request_stats[$mname])) $ai_model_request_stats[$mname] = 0;
                $ai_model_request_stats[$mname] += $modelRequests;
            }
        }
        if ($ai_total_requests <= 0 && $fallback_result_count > 0) {
            $ai_total_requests = $fallback_result_count;
        }
        if (!empty($ai_model_stats) && empty($ai_model_request_stats) && $ai_total_requests > 0) {
            $split = intdiv($ai_total_requests, max(1, count($ai_model_stats)));
            foreach ($ai_model_stats as $mname => $_unused) {
                $ai_model_request_stats[$mname] = $split;
            }
        }
        if (!empty($ai_window_days) && $ai_total_requests > 0) {
            $ai_requests_per_day = $ai_total_requests / $ai_window_days;
        }
        // Aggregate grouped usage into model stats
        $map = parse_openai_grouped_usage($data);
        foreach ($map as $mname => $vals) {
            if (!isset($ai_model_stats[$mname])) $ai_model_stats[$mname] = ['input' => 0, 'output' => 0];
            $ai_model_stats[$mname]['input'] += $vals['input'];
            $ai_model_stats[$mname]['output'] += $vals['output'];
        }
        foreach ($ai_model_stats as $mname => $vals) {
            $estimate = openai_estimate_model_cost($mname, $vals['input'] ?? 0, $vals['output'] ?? 0, $openai_config);
            $ai_model_cost_stats[$mname] = $estimate;
            $ai_total_estimated_cost += $estimate['total'];
            if (($estimate['input_rate_per_million'] ?? 0) > 0 || ($estimate['output_rate_per_million'] ?? 0) > 0) {
                $ai_has_priced_models = true;
            }
        }
        if (is_array($openai_config) && isset($openai_config['pricing_per_million']) && is_array($openai_config['pricing_per_million'])) {
            $ai_cost_pricing_source = 'Custom pricing from openai.php';
        }
    } else {
        client_console_log('OpenAI completions: no data returned');
    }
    // Note: audio_speeches endpoint disabled to conserve calls.
    // Log aggregated per-model stats for browser console inspection (sanitized)
    @client_console_log('[openai models] ' . json_encode($ai_model_stats));
    // Collect debug info for the completions request (do NOT include full API keys)
    $openai_debug_info = [];
    $completions_url = $base . '/organization/usage/completions?' . http_build_query($queryParams);
    $metrics = extract_openai_usage_metrics($results_completions);
    // If the page-fetcher returned debug entries, include them for per-page visibility, but only with compact summaries
    $pages_fetched = $results_completions['pages_fetched'] ?? null;
    $page_debug = $results_completions['debug'] ?? null;
    // Build an overall compact summary from the fetched data to present in the UI instead of raw JSON
    $response_summary = null;
    if (is_array($results_completions) && isset($results_completions['data'])) {
        $response_summary = parse_openai_grouped_usage($results_completions);
    }
    $openai_debug_info[] = [
        'method' => 'GET',
        'url' => $completions_url,
        'http_code' => null,
        'curl_error' => null,
        'pages_fetched' => $pages_fetched,
        'page_debug' => $page_debug,
        'response_summary' => $response_summary,
        'metrics' => $metrics,
        'query_params' => $queryParams
    ];
    @client_console_log(sprintf('[openai debug] url=%s pages=%s', $completions_url, var_export($pages_fetched, true)));
} elseif (!empty($openai_key)) {
    // OpenAI stats are intentionally deferred to the ai_platform_stats AJAX endpoint.
} else {
    // No API key available in config or environment
}

if ($return_ai_stats_json) {
    ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => true,
        'html' => render_ai_platform_stats_content(
            $ai_model_stats,
            $ai_model_cost_stats,
            $ai_total_estimated_cost,
            $ai_cost_pricing_source,
            $ai_cost_window_label,
            $ai_has_priced_models,
            $ai_total_requests,
            $ai_requests_per_day
        )
    ]);
    exit;
}

// Helper: perform multiple OpenAI HTTP requests in parallel using curl_multi
function openai_multi_curl(array $requests, $openai_config = null, $openai_key = null) {
    // Prefer key from config; do not fallback to environment variables.
    if (empty($openai_key) && is_array($openai_config)) {
        $openai_key = $openai_config['admin_key'] ?? null;
    }
    $openai_org = is_array($openai_config) ? ($openai_config['organization'] ?? $openai_config['org'] ?? $openai_config['organization_id'] ?? null) : null;
    $openai_project = is_array($openai_config) ? ($openai_config['project'] ?? $openai_config['project_id'] ?? null) : null;
    $multiHandle = curl_multi_init();
    $handles = [];
    $results = [];
    foreach ($requests as $idx => $req) {
        $method = strtoupper($req['method'] ?? 'GET');
        $url = $req['url'] ?? '';
        $body = $req['body'] ?? null;
        $timeout = isset($req['timeout']) ? intval($req['timeout']) : 30;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $headers = [
            'Authorization: Bearer ' . $openai_key,
            'Content-Type: application/json'
        ];
        if (!empty($openai_org)) $headers[] = 'OpenAI-Organization: ' . $openai_org;
        if (!empty($openai_project)) $headers[] = 'OpenAI-Project: ' . $openai_project;
        if (!empty($req['headers']) && is_array($req['headers'])) {
            foreach ($req['headers'] as $k => $v) {
                if (is_int($k)) {
                    $headers[] = $v;
                } else {
                    $headers[] = $k . ': ' . $v;
                }
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$idx] = $ch;
    }
    // Execute the multi handles
    $running = null;
    do {
        $mrc = curl_multi_exec($multiHandle, $running);
        // Wait for activity on any curl-connection
        curl_multi_select($multiHandle, 0.5);
    } while ($running > 0 && $mrc == CURLM_OK);
    // Collect results
    foreach ($handles as $idx => $ch) {
        $response = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$idx] = [
            'response' => $response,
            'http_code' => $http_code,
            'curl_error' => $error
        ];
        curl_multi_remove_handle($multiHandle, $ch);
}
    curl_multi_close($multiHandle);
    return $results;
}

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><span class="icon"><i class="fas fa-shield-alt"></i></span> <?php echo t('admin_index_heading'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p style="margin-bottom:1rem;"><?php echo t('admin_index_intro'); ?></p>
        <div class="sp-btn-group" style="flex-wrap:wrap;">
            <a href="users.php" class="sp-btn sp-btn-primary">
                <span class="icon"><i class="fas fa-users-cog"></i></span>
                <span><?php echo t('admin_index_link_user_management'); ?></span>
            </a>
            <a href="start_bots.php" class="sp-btn sp-btn-success">
                <span class="icon"><i class="fas fa-play-circle"></i></span>
                <span><?php echo t('admin_index_link_start_bots'); ?></span>
            </a>
            <a href="logs.php" class="sp-btn sp-btn-info">
                <span class="icon"><i class="fas fa-clipboard-list"></i></span>
                <span><?php echo t('admin_index_link_logs'); ?></span>
            </a>
            <a href="twitch_tokens.php" class="sp-btn sp-btn-primary">
                <span class="icon"><i class="fab fa-twitch"></i></span>
                <span><?php echo t('admin_index_link_twitch_tokens'); ?></span>
            </a>
            <a href="discordbot_overview.php" class="sp-btn sp-btn-info">
                <span class="icon"><i class="fab fa-discord"></i></span>
                <span><?php echo t('admin_index_link_discord_overview'); ?></span>
            </a>
            <a href="websocket_clients.php" class="sp-btn sp-btn-success">
                <span class="icon"><i class="fas fa-plug"></i></span>
                <span><?php echo t('admin_index_link_websocket_clients'); ?></span>
            </a>
            <a href="terminal.php" class="sp-btn sp-btn-warning">
                <span class="icon"><i class="fas fa-terminal"></i></span>
                <span><?php echo t('admin_index_link_terminal'); ?></span>
            </a>
            <a href="beta_programs.php" class="sp-btn sp-btn-secondary">
                <span class="icon"><i class="fas fa-flask"></i></span>
                <span><?php echo t('admin_index_link_beta_programs'); ?></span>
            </a>
        </div>
    </div>
</div>

<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><span class="icon"><i class="fas fa-server"></i></span> <?php echo t('admin_index_server_overview'); ?></h2>
        <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="refresh-server-overview" onclick="refreshServerOverview()" title="<?php echo t('admin_index_refresh_server_status'); ?>">
            <span class="icon"><i class="fas fa-sync-alt"></i></span> <?php echo t('admin_index_refresh'); ?>
        </button>
    </div>
    <div class="sp-card-body">
    <div class="admin-service-groups">
        <!-- ========== BOTS HOST ========== -->
        <section class="admin-service-group">
            <h3 class="admin-service-group-title">
                <span class="sp-badge sp-badge-blue">BOTS</span>
                <?php echo t('admin_index_group_bots_host'); ?>
            </h3>
            <span class="admin-service-group-desc"><?php echo t('admin_index_group_bots_host_desc'); ?></span>
            <div class="admin-service-grid">
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-info"><i class="fab fa-discord fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_discord_bot'); ?></span>
                                    <span class="admin-service-status" id="discord-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="discord-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="discord-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('discordbot.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('discordbot.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('discordbot.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-accent"><i class="fas fa-server fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_bots_api'); ?></span>
                                    <span class="admin-service-status" id="bots-api-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="bots-api-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="bots-api-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('bots-api.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('bots-api.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('bots-api.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-info"><i class="fas fa-file-export fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_export_queue_worker'); ?></span>
                                    <span class="admin-service-status" id="export-queue-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="export-queue-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="export-queue-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('export_queue_worker.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('export_queue_worker.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('export_queue_worker.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- ========== API HOST ========== -->
        <section class="admin-service-group">
            <h3 class="admin-service-group-title">
                <span class="sp-badge sp-badge-accent">API</span>
                <?php echo t('admin_index_group_api_host'); ?>
            </h3>
            <span class="admin-service-group-desc"><?php echo t('admin_index_group_api_host_desc'); ?></span>
            <div class="admin-service-grid">
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-accent"><i class="fas fa-code fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_api_server'); ?></span>
                                    <span class="admin-service-status" id="api-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="api-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="api-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('fastapi.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('fastapi.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('fastapi.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- ========== WEBSOCKET HOST ========== -->
        <section class="admin-service-group">
            <h3 class="admin-service-group-title">
                <span class="sp-badge sp-badge-green">WS</span>
                <?php echo t('admin_index_group_websocket_host'); ?>
            </h3>
            <span class="admin-service-group-desc"><?php echo t('admin_index_group_websocket_host_desc'); ?></span>
            <div class="admin-service-grid">
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-success"><i class="fas fa-plug fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_websocket_server'); ?></span>
                                    <span class="admin-service-status" id="websocket-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="websocket-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="websocket-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('websocket.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('websocket.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('websocket.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- ========== SQL HOST ========== -->
        <section class="admin-service-group">
            <h3 class="admin-service-group-title">
                <span class="sp-badge sp-badge-amber">SQL</span>
                <?php echo t('admin_index_group_sql_host'); ?>
            </h3>
            <span class="admin-service-group-desc"><?php echo t('admin_index_group_sql_host_desc'); ?></span>
            <div class="admin-service-grid">
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-warning"><i class="fas fa-database fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_mysql_server'); ?></span>
                                    <span class="admin-service-status" id="mysql-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="mysql-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="mysql-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('mysql.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('mysql.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('mysql.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- ========== CADDY (all hosts — same service type) ========== -->
        <section class="admin-service-group">
            <h3 class="admin-service-group-title">
                <span class="sp-badge sp-badge-blue">CADDY</span>
                <?php echo t('admin_index_group_caddy'); ?>
            </h3>
            <span class="admin-service-group-desc"><?php echo t('admin_index_group_caddy_desc'); ?></span>
            <div class="admin-service-grid">
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-info"><i class="fas fa-shield-alt fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="sp-badge sp-badge-blue" style="margin-bottom:0.25rem;">BOTS</span>
                                    <span class="admin-heading"><?php echo t('admin_index_svc_bots_caddy'); ?></span>
                                    <span class="sp-text-muted" style="display:block;font-size:0.8rem;margin-top:0.15rem;"><?php echo t('admin_index_svc_bots_caddy_sub'); ?></span>
                                    <span class="admin-service-status" id="bots-caddy-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="bots-caddy-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="bots-caddy-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('bots-caddy.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('bots-caddy.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('bots-caddy.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-accent"><i class="fas fa-shield-alt fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="sp-badge sp-badge-accent" style="margin-bottom:0.25rem;">API</span>
                                    <span class="admin-heading"><?php echo t('admin_index_svc_api_caddy'); ?></span>
                                    <span class="sp-text-muted" style="display:block;font-size:0.8rem;margin-top:0.15rem;"><?php echo t('admin_index_svc_api_caddy_sub'); ?></span>
                                    <span class="admin-service-status" id="api-caddy-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="api-caddy-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="api-caddy-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('api-caddy.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('api-caddy.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('api-caddy.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-warning"><i class="fas fa-shield-alt fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="sp-badge sp-badge-amber" style="margin-bottom:0.25rem;">WEB</span>
                                    <span class="admin-heading"><?php echo t('admin_index_svc_web_caddy'); ?></span>
                                    <span class="sp-text-muted" style="display:block;font-size:0.8rem;margin-top:0.15rem;"><?php echo t('admin_index_svc_web_caddy_sub'); ?></span>
                                    <span class="admin-service-status" id="web-caddy-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="web-caddy-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="web-caddy-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('caddy.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('caddy.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('caddy.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- ========== RETIRED ========== -->
        <section class="admin-service-group">
            <h3 class="admin-service-group-title">
                <span class="sp-badge sp-badge-grey">OFF</span>
                <?php echo t('admin_index_group_retired'); ?>
            </h3>
            <span class="admin-service-group-desc"><?php echo t('admin_index_group_retired_desc'); ?></span>
            <div class="admin-service-grid">
                <div>
                    <div class="admin-service-card">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                                <span class="icon sp-text-danger"><i class="fas fa-video fa-lg"></i></span>
                                <div style="min-width: 0;">
                                    <span class="admin-heading"><?php echo t('admin_index_svc_twitch_recorder'); ?></span>
                                    <span class="admin-service-status" id="twitch-recorder-status" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span></span>
                                </div>
                            </div>
                            <div><span class="sp-badge sp-badge-grey" id="twitch-recorder-pid" aria-busy="true"><span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span></span></div>
                        </div>
                        <div class="sp-btn-group" style="margin-top:1rem;" id="twitch-recorder-buttons">
                            <button type="button" class="sp-btn sp-btn-success sp-btn-sm" onclick="controlService('twitch-recorder.service', 'start')" disabled><span class="icon"><i class="fas fa-play"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" onclick="controlService('twitch-recorder.service', 'stop')" disabled><span class="icon"><i class="fas fa-stop"></i></span></button>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" onclick="controlService('twitch-recorder.service', 'restart')" disabled><span class="icon"><i class="fas fa-redo"></i></span></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
    </div>
</div>
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><span class="icon"><i class="fas fa-key"></i></span> <?php echo t('admin_index_token_management'); ?></h2>
    </div>
    <div class="sp-card-body">
        <div class="admin-token-grid">
            <div>
                <div class="admin-service-card">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                        <span class="icon sp-text-success"><i class="fab fa-spotify fa-lg"></i></span>
                        <div>
                            <span class="admin-heading"><?php echo t('admin_index_token_service'); ?></span>
                            <span style="display:block; font-size:0.95rem; font-weight:700; color:var(--text-primary);">Spotify</span>
                        </div>
                    </div>
                    <button type="button" class="sp-btn sp-btn-success" style="width:100%;" onclick="refreshSpotifyTokens()">
                        <span class="icon"><i class="fas fa-sync"></i></span>
                        <span><?php echo t('admin_index_refresh_spotify_tokens'); ?></span>
                    </button>
                </div>
            </div>
            <div>
                <div class="admin-service-card">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                        <span class="icon sp-text-info"><i class="fas fa-stream fa-lg"></i></span>
                        <div>
                            <span class="admin-heading"><?php echo t('admin_index_token_service'); ?></span>
                            <span style="display:block; font-size:0.95rem; font-weight:700; color:var(--text-primary);">StreamElements</span>
                        </div>
                    </div>
                    <button type="button" class="sp-btn sp-btn-info" style="width:100%;" onclick="refreshStreamElementsTokens()">
                        <span class="icon"><i class="fas fa-sync"></i></span>
                        <span><?php echo t('admin_index_refresh_streamelements_tokens'); ?></span>
                    </button>
                </div>
            </div>
            <div>
                <div class="admin-service-card">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                        <span class="icon sp-text-accent"><i class="fab fa-discord fa-lg"></i></span>
                        <div>
                            <span class="admin-heading"><?php echo t('admin_index_token_service'); ?></span>
                            <span style="display:block; font-size:0.95rem; font-weight:700; color:var(--text-primary);">Discord</span>
                        </div>
                    </div>
                    <button type="button" class="sp-btn sp-btn-primary" style="width:100%;" onclick="refreshDiscordTokens()">
                        <span class="icon"><i class="fas fa-sync"></i></span>
                        <span><?php echo t('admin_index_refresh_discord_tokens'); ?></span>
                    </button>
                </div>
            </div>
            <div>
                <div class="admin-service-card">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                        <span class="icon sp-text-warning"><i class="fas fa-robot fa-lg"></i></span>
                        <div>
                            <span class="admin-heading"><?php echo t('admin_index_token_service'); ?></span>
                            <span style="display:block; font-size:0.95rem; font-weight:700; color:var(--text-primary);"><?php echo t('admin_index_custom_bots'); ?></span>
                        </div>
                    </div>
                    <button type="button" class="sp-btn sp-btn-warning" style="width:100%;" onclick="refreshCustomBotTokens()">
                        <span class="icon"><i class="fas fa-sync"></i></span>
                        <span><?php echo t('admin_index_refresh_custom_bot_tokens'); ?></span>
                    </button>
                </div>
            </div>
            <div>
                <div class="admin-service-card">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                        <span class="icon sp-text-accent"><i class="fab fa-twitch fa-lg"></i></span>
                        <div>
                            <span class="admin-heading"><?php echo t('admin_index_token_service'); ?></span>
                            <span style="display:block; font-size:0.95rem; font-weight:700; color:var(--text-primary);"><?php echo t('admin_index_twitch_app_token'); ?></span>
                        </div>
                    </div>
                    <button type="button" class="sp-btn sp-btn-primary" style="width:100%;" onclick="refreshTwitchAppToken()">
                        <span class="icon"><i class="fas fa-sync"></i></span>
                        <span><?php echo t('admin_index_refresh_twitch_app_token'); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$erRemainingDisplay = $exchangerateQuota['count'] === null ? '--' : number_format($exchangerateQuota['count']);
$erResetDayDisplay = $exchangerateQuota['reset_day'] ? (string) $exchangerateQuota['reset_day'] : '--';
$erDaysDisplay = $exchangerateQuota['days_remaining'] === null ? '--' : (string) $exchangerateQuota['days_remaining'];
$erUpdatedDisplay = t('admin_index_no_data_yet');
if (!empty($exchangerateQuota['updated']) && $exchangerateQuota['updated'] !== '0000-00-00 00:00:00') {
    $erTs = strtotime($exchangerateQuota['updated']);
    if ($erTs) {
        $erUpdatedDisplay = t('admin_index_last_updated_prefix') . ' ' . date('M d, Y H:i:s', $erTs);
    }
}
?>
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header" style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
        <h2 class="sp-card-title" style="margin:0;"><span class="icon"><i class="fas fa-coins"></i></span> <?php echo t('admin_index_exchangerate_quota'); ?></h2>
        <button id="exchangerate-check-now" type="button" class="sp-btn sp-btn-sm sp-btn-primary">
            <span class="icon"><i class="fas fa-sync"></i></span>
            <span><?php echo t('admin_index_exchangerate_check_now'); ?></span>
        </button>
    </div>
    <div class="sp-card-body">
        <div class="sp-stat-row" style="margin-bottom:0.75rem;">
            <div class="sp-stat">
                <div class="sp-stat-label"><?php echo t('admin_index_exchangerate_remaining'); ?></div>
                <div class="sp-stat-value" id="exchangerate-remaining"><?php echo htmlspecialchars($erRemainingDisplay); ?></div>
            </div>
            <div class="sp-stat">
                <div class="sp-stat-label"><?php echo t('admin_index_exchangerate_reset_day'); ?></div>
                <div class="sp-stat-value" id="exchangerate-reset-day"><?php echo htmlspecialchars($erResetDayDisplay); ?></div>
            </div>
            <div class="sp-stat">
                <div class="sp-stat-label"><?php echo t('admin_index_exchangerate_days_remaining'); ?></div>
                <div class="sp-stat-value" id="exchangerate-days-remaining"><?php echo htmlspecialchars($erDaysDisplay); ?></div>
            </div>
        </div>
        <p class="sp-help" id="exchangerate-updated"><?php echo htmlspecialchars($erUpdatedDisplay); ?></p>
    </div>
</div>
<?php
$botIconMap = [
    'discordbot'     => ['icon' => 'fab fa-discord',  'color' => 'sp-text-info'],
    'twitch_stable'  => ['icon' => 'fab fa-twitch',   'color' => 'sp-text-accent'],
    'twitch_beta'    => ['icon' => 'fab fa-twitch',   'color' => 'sp-text-success'],
    'twitch_custom'  => ['icon' => 'fas fa-robot',    'color' => 'sp-text-warning'],
];
?>
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><span class="icon"><i class="fas fa-comments"></i></span> <?php echo t('admin_index_bot_message_counts'); ?></h2>
    </div>
    <div class="sp-card-body">
        <div class="admin-msg-grid">
            <?php foreach ($messageSystemNames as $key => $label):
                $iconCfg = $botIconMap[$key] ?? ['icon' => 'fas fa-robot', 'color' => 'sp-text-info'];
                $hasData = isset($botMessageStats[$key]) && $botMessageStats[$key]['messages_sent'] > 0;
            ?>
            <div class="admin-service-card" data-bot-system="<?php echo $key; ?>">
                <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem;">
                    <span class="icon <?php echo $iconCfg['color']; ?>"><i class="<?php echo $iconCfg['icon']; ?> fa-lg"></i></span>
                    <div>
                        <span class="admin-heading"><?php echo t('admin_index_message_count'); ?></span>
                        <span style="display:block; font-size:0.95rem; font-weight:700; color:var(--text-primary);"><?php echo $label; ?></span>
                    </div>
                </div>
                <div class="bot-message-count-display">
                    <div class="bot-message-count-number" style="color:var(--blue);">
                        <?php echo $hasData ? number_format($botMessageStats[$key]['messages_sent']) : '<span style="font-size:0.9rem; color:var(--text-muted);">' . t('admin_index_not_counting_yet') . '</span>'; ?>
                    </div>
                    <div class="bot-message-count-timestamp">
                        <?php echo isset($botMessageStats[$key]['last_updated'])
                            ? t('admin_index_last_updated_prefix') . ' ' . date('M d, Y H:i:s', strtotime($botMessageStats[$key]['last_updated']))
                            : t('admin_index_no_data_yet'); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header" style="cursor:pointer;" onclick="toggleCollapsible('bot-overview', event)">
        <h2 class="sp-card-title"><span class="icon"><i class="fas fa-robot"></i></span> <?php echo t('admin_index_bot_overview'); ?></h2>
        <span class="collapse-icon" data-section="bot-overview">?</span>
    </div>
    <div class="collapsible-content" id="bot-overview">
        <div class="sp-card-body">
            <div id="bot-overview-container" aria-busy="true">
                <div class="admin-bot-grid" id="bot-columns" aria-hidden="true">
                    <?php for ($sk = 0; $sk < 6; $sk++): ?>
                    <div class="admin-bot-card">
                        <div class="admin-service-card">
                            <div class="admin-level-row" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;">
                                <span class="sp-skeleton-avatar"></span>
                                <span class="sp-skeleton-line w-60"></span>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.5rem;">
                                <span class="sp-skeleton-badge"></span>
                                <span class="sp-skeleton-badge" style="width:4rem;"></span>
                                <span class="sp-skeleton-badge" style="width:3rem;"></span>
                            </div>
                            <div style="display:flex;gap:0.5rem;">
                                <span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>
                                <span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="admin-cols-row">
    <div style="display:none;">
        <div class="sp-card" style="height: 100%; display: flex; flex-direction: column;">
            <div class="sp-card-header">
                <h2 class="sp-card-title"><span class="icon"><i class="fas fa-chart-pie"></i></span> <?php echo t('admin_index_user_overview'); ?></h2>
            </div>
            <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; flex:1;">
                    <div>
                        <div style="max-width: 300px; margin: 0 auto;">
                            <canvas id="userChart" width="300" height="300"></canvas>
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <p style="margin-bottom:1rem;"><?php echo t('admin_index_user_distribution'); ?></p>
                            <ul>
                                <li><strong><?php echo t('admin_index_total_users'); ?></strong> <?php echo $total_users; ?></li>
                                <li><strong><?php echo t('admin_index_admins'); ?></strong> <?php echo $admin_count; ?></li>
                                <li><strong><?php echo t('admin_index_beta_users'); ?></strong> <?php echo $beta_count; ?></li>
                                <li><strong><?php echo t('admin_index_premium_users'); ?></strong> <?php echo $premium_count; ?></li>
                                <li><strong><?php echo t('admin_index_regular_users'); ?></strong> <?php echo $regular_count; ?></li>
                            </ul>
                        </div>
                        <a href="users.php" class="sp-btn sp-btn-primary" style="margin-top:1rem;">
                            <span class="icon"><i class="fas fa-users-cog"></i></span>
                            <span><?php echo t('admin_index_manage_users'); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="sp-card">
        <div class="sp-card-header" style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
            <h2 class="sp-card-title" style="margin:0;"><span class="icon"><i class="fas fa-brain"></i></span> <?php echo t('admin_index_ai_platform_stats'); ?></h2>
            <button id="refresh-ai-stats" type="button" class="sp-btn sp-btn-sm">
                <span class="icon is-small"><i class="fas fa-sync-alt"></i></span>
                <span><?php echo t('admin_index_refresh'); ?></span>
            </button>
        </div>
        <div class="sp-card-body">
            <div id="ai-platform-stats-content" aria-busy="true">
                <div class="sp-skeleton-stack" aria-hidden="true" style="gap:0.75rem;">
                    <div class="sp-stat-row" style="grid-template-columns:repeat(3,1fr);">
                        <div class="sp-skeleton-stat"><span class="sp-skeleton-line w-55"></span><span class="sp-skeleton-value"></span><span class="sp-skeleton-line w-40"></span></div>
                        <div class="sp-skeleton-stat"><span class="sp-skeleton-line w-55"></span><span class="sp-skeleton-value"></span><span class="sp-skeleton-line w-40"></span></div>
                        <div class="sp-skeleton-stat"><span class="sp-skeleton-line w-55"></span><span class="sp-skeleton-value"></span><span class="sp-skeleton-line w-40"></span></div>
                    </div>
                    <span class="sp-skeleton-line w-70"></span>
                    <span class="sp-skeleton-line w-90"></span>
                    <span class="sp-skeleton-line w-50"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="sp-card">
        <div class="sp-card-header">
            <h2 class="sp-card-title"><span class="icon"><i class="fas fa-paper-plane"></i></span> <?php echo t('admin_index_send_bot_message'); ?></h2>
        </div>
        <div class="sp-card-body">
            <form id="send-message-form" method="post">
                <div class="sp-form-group">
                    <label class="sp-label"><?php echo t('admin_index_select_channel'); ?></label>
                    <select class="sp-select" name="channel_id" id="channel-select" required>
                        <option value=""><?php echo t('admin_index_loading_channels'); ?></option>
                    </select>
                    <small class="sp-help"><?php echo t('admin_index_channel_list_help'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label style="display:flex; align-items:center; gap:0.5rem; color:var(--text-secondary); cursor:pointer;">
                        <input type="checkbox" id="include-offline">
                        <?php echo t('admin_index_include_offline_channels'); ?>
                    </label>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label"><?php echo t('admin_index_template'); ?></label>
                    <select class="sp-select" id="message-template-select">
                        <option value=""><?php echo t('admin_index_choose_template'); ?></option>
                        <?php foreach ($message_templates as $tpl_key => $tpl_text): ?>
                            <option value="<?php echo htmlspecialchars($tpl_key); ?>"><?php echo htmlspecialchars($tpl_key); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label"><?php echo t('admin_index_message_label'); ?></label>
                    <textarea class="sp-textarea" name="message" id="message" placeholder="<?php echo t('admin_index_message_placeholder'); ?>" required></textarea>
                    <small id="char-count" class="sp-text-muted"><?php echo t('admin_index_char_count', [0]); ?></small>
                </div>
                <div class="sp-form-group">
                    <button class="sp-btn sp-btn-primary" type="submit" name="send_message" id="send" disabled><?php echo t('admin_index_send_message_btn'); ?></button>
                </div>
            </form>
            <hr style="border:none; border-top:1px solid var(--border); margin:1rem 0;">
            <div class="sp-form-group">
                <label class="sp-label"><?php echo t('admin_index_shoutout_username'); ?></label>
                <input class="sp-input" type="text" id="shoutout-username" placeholder="<?php echo t('admin_index_shoutout_username_placeholder'); ?>">
                <small id="shoutout-helper-text" class="sp-text-muted"><?php echo t('admin_index_select_channel_above'); ?></small>
            </div>
            <div class="sp-form-group">
                <button class="sp-btn sp-btn-primary" type="button" id="send-shoutout" disabled><?php echo t('admin_index_send_shoutout_btn'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Localized strings injected from PHP
    const adminI18n = {
        chartAdmins: <?php echo json_encode(t('admin_index_chart_admins')); ?>,
        chartBeta: <?php echo json_encode(t('admin_index_chart_beta_users')); ?>,
        chartPremium: <?php echo json_encode(t('admin_index_chart_premium_users')); ?>,
        chartRegular: <?php echo json_encode(t('admin_index_chart_regular_users')); ?>,
        noUserData: <?php echo json_encode(t('admin_index_no_user_data')); ?>,
        commandFailed: <?php echo json_encode(t('admin_index_command_failed')); ?>,
        checkLogs: <?php echo json_encode(t('admin_index_check_logs')); ?>,
        errorTitle: <?php echo json_encode(t('admin_index_error_title')); ?>,
        invalidJson: <?php echo json_encode(t('admin_index_invalid_json')); ?>,
        okBtn: <?php echo json_encode(t('admin_index_ok')); ?>,
        confirmTitle: <?php echo json_encode(t('admin_index_confirm_title')); ?>,
        confirmStopText: <?php echo json_encode(t('admin_index_confirm_stop_text')); ?>,
        confirmStopBtn: <?php echo json_encode(t('admin_index_confirm_stop_btn')); ?>,
        confirmRestartText: <?php echo json_encode(t('admin_index_confirm_restart_text')); ?>,
        confirmRestartBtn: <?php echo json_encode(t('admin_index_confirm_restart_btn')); ?>,
        restartingBot: <?php echo json_encode(t('admin_index_restarting_bot')); ?>,
        botRestarted: <?php echo json_encode(t('admin_index_bot_restarted')); ?>,
        failRestartBot: <?php echo json_encode(t('admin_index_fail_restart_bot')); ?>,
        netErrRestartBot: <?php echo json_encode(t('admin_index_net_err_restart_bot')); ?>,
        spotifyRefreshed: <?php echo json_encode(t('admin_index_spotify_refreshed')); ?>,
        failRefreshTokens: <?php echo json_encode(t('admin_index_fail_refresh_tokens')); ?>,
        refreshing: <?php echo json_encode(t('admin_index_refreshing')); ?>,
        refreshSpotifyBtn: <?php echo json_encode(t('admin_index_refresh_spotify_tokens')); ?>,
        refreshStreamElementsBtn: <?php echo json_encode(t('admin_index_refresh_streamelements_tokens')); ?>,
        refreshDiscordBtn: <?php echo json_encode(t('admin_index_refresh_discord_tokens')); ?>,
        refreshCustomBotBtn: <?php echo json_encode(t('admin_index_refresh_custom_bot_tokens')); ?>,
        twitchAppTokenService: <?php echo json_encode(t('admin_index_twitch_app_token')); ?>,
        refreshTwitchAppBtn: <?php echo json_encode(t('admin_index_refresh_twitch_app_token')); ?>,
        exchangerateCheckNow: <?php echo json_encode(t('admin_index_exchangerate_check_now')); ?>,
        exchangerateChecking: <?php echo json_encode(t('admin_index_exchangerate_checking')); ?>,
        exchangerateSynced: <?php echo json_encode(t('admin_index_exchangerate_synced')); ?>,
        exchangerateSyncFailed: <?php echo json_encode(t('admin_index_exchangerate_sync_failed')); ?>,
        noDataYet: <?php echo json_encode(t('admin_index_no_data_yet')); ?>,
        connecting: <?php echo json_encode(t('admin_index_connecting')); ?>,
        liveOutputSuffix: <?php echo json_encode(t('admin_index_live_output_suffix')); ?>,
        closeBtn: <?php echo json_encode(t('admin_index_close')); ?>,
        anErrorOccurred: <?php echo json_encode(t('admin_index_an_error_occurred')); ?>,
        processDone: <?php echo json_encode(t('admin_index_process_done')); ?>,
        success: <?php echo json_encode(t('admin_index_success')); ?>,
        failed: <?php echo json_encode(t('admin_index_failed')); ?>,
        statusError: <?php echo json_encode(t('admin_index_status_error')); ?>,
        none: <?php echo json_encode(t('admin_index_none')); ?>,
        errLoadBotOverview: <?php echo json_encode(t('admin_index_err_load_bot_overview')); ?>,
        updatedJustNow: <?php echo json_encode(t('admin_index_updated_just_now')); ?>,
        updatedPrefix: <?php echo json_encode(t('admin_index_updated_prefix')); ?>,
        agoSeconds: <?php echo json_encode(t('admin_index_ago_seconds')); ?>,
        agoMinutes: <?php echo json_encode(t('admin_index_ago_minutes')); ?>,
        agoHours: <?php echo json_encode(t('admin_index_ago_hours')); ?>,
        msgsysDiscordBot: <?php echo json_encode(t('admin_index_msgsys_discord_bot')); ?>,
        msgsysChatBotStable: <?php echo json_encode(t('admin_index_msgsys_chat_bot_stable')); ?>,
        msgsysChatBotBeta: <?php echo json_encode(t('admin_index_msgsys_chat_bot_beta')); ?>,
        msgsysChatBotCustom: <?php echo json_encode(t('admin_index_msgsys_chat_bot_custom')); ?>,
        notCountingYet: <?php echo json_encode(t('admin_index_not_counting_yet')); ?>,
        lastUpdatedPrefix: <?php echo json_encode(t('admin_index_last_updated_prefix')); ?>,
        failLoadAiStats: <?php echo json_encode(t('admin_index_fail_load_ai_stats')); ?>,
        charCountSuffix: <?php echo json_encode(t('admin_index_char_count_suffix')); ?>,
        selectChannelAbove: <?php echo json_encode(t('admin_index_select_channel_above')); ?>,
        channelWillShoutout: <?php echo json_encode(t('admin_index_channel_will_shoutout')); ?>,
        usernameValidated: <?php echo json_encode(t('admin_index_username_validated')); ?>,
        invalidUsernameFormat: <?php echo json_encode(t('admin_index_invalid_username_format')); ?>,
        validatingUsername: <?php echo json_encode(t('admin_index_validating_username')); ?>,
        usernameValidationFailed: <?php echo json_encode(t('admin_index_username_validation_failed')); ?>,
        unableValidateUsername: <?php echo json_encode(t('admin_index_unable_validate_username')); ?>,
        noChannelsFound: <?php echo json_encode(t('admin_index_no_channels_found')); ?>,
        noOnlineChannels: <?php echo json_encode(t('admin_index_no_online_channels')); ?>,
        chooseChannel: <?php echo json_encode(t('admin_index_choose_channel')); ?>,
        offlineSuffix: <?php echo json_encode(t('admin_index_offline_suffix')); ?>,
        errLoadingChannels: <?php echo json_encode(t('admin_index_err_loading_channels')); ?>,
        errBotsInventory: <?php echo json_encode(t('admin_index_err_bots_inventory')); ?>,
        sending: <?php echo json_encode(t('admin_index_sending')); ?>,
        failSendShoutout: <?php echo json_encode(t('admin_index_fail_send_shoutout')); ?>,
        netErrorPrefix: <?php echo json_encode(t('admin_index_net_error_prefix')); ?>,
        serviceLabels: <?php echo json_encode([
            'discordbot.service' => t('admin_index_svc_discord_bot'),
            'bots-api.service' => t('admin_index_svc_bots_api'),
            'bots-caddy.service' => t('admin_index_svc_bots_caddy'),
            'fastapi.service' => 'FastAPI',
            'api-caddy.service' => t('admin_index_svc_api_caddy'),
            'websocket.service' => 'WebSocket',
            'mysql.service' => 'MySQL',
            'export_queue_worker.service' => t('admin_index_svc_export_queue_worker'),
            'twitch-recorder.service' => t('admin_index_svc_twitch_recorder'),
            'caddy.service' => t('admin_index_svc_web_caddy')
        ]); ?>,
        actionLabels: <?php echo json_encode([
            'start' => t('admin_index_action_started'),
            'stop' => t('admin_index_action_stopped'),
            'restart' => t('admin_index_action_restarted')
        ]); ?>,
        serviceActionSuccess: <?php echo json_encode(t('admin_index_service_action_success')); ?>
    };
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
                    labels: [adminI18n.chartAdmins, adminI18n.chartBeta, adminI18n.chartPremium, adminI18n.chartRegular],
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
            noDataMsg.textContent = adminI18n.noUserData;
            ctx.parentNode.appendChild(noDataMsg);
        }
    }
    const serviceConfig = {
        'discordbot.service': { statusKey: 'discordbot', statusId: 'discord-status', pidId: 'discord-pid', buttonsId: 'discord-buttons' },
        'bots-api.service': { statusKey: 'bots_api', statusId: 'bots-api-status', pidId: 'bots-api-pid', buttonsId: 'bots-api-buttons' },
        'bots-caddy.service': { statusKey: 'bots_caddy', statusId: 'bots-caddy-status', pidId: 'bots-caddy-pid', buttonsId: 'bots-caddy-buttons' },
        'fastapi.service': { statusKey: 'fastapi', statusId: 'api-status', pidId: 'api-pid', buttonsId: 'api-buttons' },
        'api-caddy.service': { statusKey: 'api_caddy', statusId: 'api-caddy-status', pidId: 'api-caddy-pid', buttonsId: 'api-caddy-buttons' },
        'websocket.service': { statusKey: 'websocket', statusId: 'websocket-status', pidId: 'websocket-pid', buttonsId: 'websocket-buttons' },
        'mysql.service': { statusKey: 'mysql', statusId: 'mysql-status', pidId: 'mysql-pid', buttonsId: 'mysql-buttons' },
        'export_queue_worker.service': { statusKey: 'export_queue_worker', statusId: 'export-queue-status', pidId: 'export-queue-pid', buttonsId: 'export-queue-buttons' },
        'twitch-recorder.service': { statusKey: 'twitch_recorder', statusId: 'twitch-recorder-status', pidId: 'twitch-recorder-pid', buttonsId: 'twitch-recorder-buttons' },
        'caddy.service': { statusKey: 'web_caddy', statusId: 'web-caddy-status', pidId: 'web-caddy-pid', buttonsId: 'web-caddy-buttons' }
    };
    function scheduleStatusRefresh(meta, action) {
        if (!meta) return;
        // Bots API /health is down while the process is still binding. Wait longer
        // and retry so a successful restart is not painted as Error/Failed.
        const waitForHttp = meta.statusKey === 'bots_api' && (action === 'start' || action === 'restart');
        const delay = waitForHttp ? 2000 : 500;
        const retries = waitForHttp ? 6 : 0;
        setTimeout(() => {
            updateServiceStatus(meta.statusKey, meta.statusId, meta.pidId, meta.buttonsId, retries);
        }, delay);
    }
    const serviceLabels = adminI18n.serviceLabels;
    const actionLabels = adminI18n.actionLabels;
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
                    const svcLabel = serviceLabels[service] || service;
                    const actLabel = actionLabels[action] || (action + 'ed');
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: adminI18n.serviceActionSuccess.replace('{svc}', svcLabel).replace('{act}', actLabel),
                        showConfirmButton: false,
                        timer: 3500,
                        timerProgressBar: true
                    });
                    if (output) {
                        console.log('[admin control] stdout/stderr:', output);
                    }
                    scheduleStatusRefresh(meta, action);
                } else {
                    console.error('[admin control] command failed output:', output);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: adminI18n.commandFailed,
                        text: output || adminI18n.checkLogs,
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
                    scheduleStatusRefresh(meta, action);
                }
            } catch (e) {
                // Not valid JSON - log raw text for diagnosis and show it to user
                console.error('[admin control] invalid JSON response:', text);
                Swal.fire({
                    title: adminI18n.errorTitle,
                    html: '<p>' + adminI18n.invalidJson + '</p><pre style="text-align:left; white-space:pre-wrap;">' + text + '</pre>',
                    icon: 'error',
                    confirmButtonText: adminI18n.okBtn,
                    width: 800
                });
            }
            buttons.forEach(btn => btn.disabled = false);
        })
        .catch(error => {
            console.error('[admin control] fetch error:', error);
            Swal.fire({
                title: adminI18n.errorTitle,
                text: adminI18n.netErrorPrefix + ' ' + error.message,
                icon: 'error',
                confirmButtonText: adminI18n.okBtn
            });
            buttons.forEach(btn => btn.disabled = false);
        });
    };
    // Function to stop bot (bots API — channel + bot_type; pid is diagnostic only)
    window.stopBot = function(pid, element, username, botType) {
        Swal.fire({
            title: adminI18n.confirmTitle,
            text: adminI18n.confirmStopText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: adminI18n.confirmStopBtn
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('stop_bot', '1');
                formData.append('pid', pid);
                if (username) { formData.append('username', username); }
                if (botType) { formData.append('bot_type', botType); }
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        element.remove();
                    } else {
                        console.error('Stop bot failed:', data.error || data);
                    }
                })
                .catch(error => {
                    console.error('Error stopping bot:', error);
                });
            }
        });
    };
    
    // Function to restart bot
    window.restartBot = function(username, botType, pid, element) {
        Swal.fire({
            title: adminI18n.confirmTitle,
            text: adminI18n.confirmRestartText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: adminI18n.confirmRestartBtn
        }).then((result) => {
            if (result.isConfirmed) {
                // Log the restart details for debugging
                console.log('Restarting bot:', {username: username, botType: botType, pid: pid});
                
                // Show loading toast
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: adminI18n.restartingBot.replace('{type}', botType),
                    showConfirmButton: false,
                    timer: 2000
                });
                const formData = new FormData();
                formData.append('restart_bot', '1');
                formData.append('username', username);
                formData.append('bot_type', botType);
                formData.append('pid', pid);
                // Log what we're sending
                console.log('FormData contents:', {
                    restart_bot: '1',
                    username: username,
                    bot_type: botType,
                    pid: pid
                });
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: data.message || adminI18n.botRestarted,
                            showConfirmButton: false,
                            timer: 3000
                        });
                    } else {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'error',
                            title: data.message || adminI18n.failRestartBot,
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                })
                .catch(error => {
                    console.error('Error restarting bot:', error);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: adminI18n.netErrRestartBot,
                        showConfirmButton: false,
                        timer: 3000
                    });
                });
            }
        });
    };
    function formatExchangerateUpdated(raw) {
        if (!raw) return adminI18n.noDataYet;
        const parsed = new Date(String(raw).replace(' ', 'T'));
        if (isNaN(parsed.getTime())) {
            return adminI18n.lastUpdatedPrefix + ' ' + raw;
        }
        const formatted = parsed.toLocaleString('en-US', {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        return adminI18n.lastUpdatedPrefix + ' ' + formatted;
    }
    function setExchangerateQuotaDisplay(data) {
        const remainingEl = document.getElementById('exchangerate-remaining');
        const resetEl = document.getElementById('exchangerate-reset-day');
        const daysEl = document.getElementById('exchangerate-days-remaining');
        const updatedEl = document.getElementById('exchangerate-updated');
        if (remainingEl && data.requests_remaining !== null && data.requests_remaining !== undefined) {
            remainingEl.textContent = Number(data.requests_remaining).toLocaleString();
        }
        if (resetEl && data.reset_day !== null && data.reset_day !== undefined) {
            resetEl.textContent = String(data.reset_day);
        }
        if (daysEl && data.days_remaining !== null && data.days_remaining !== undefined) {
            daysEl.textContent = String(data.days_remaining);
        }
        if (updatedEl) {
            updatedEl.textContent = formatExchangerateUpdated(data.updated);
        }
    }
    const exchangerateCheckBtn = document.getElementById('exchangerate-check-now');
    if (exchangerateCheckBtn) {
        exchangerateCheckBtn.addEventListener('click', function() {
            if (exchangerateCheckBtn.disabled) return;
            exchangerateCheckBtn.disabled = true;
            exchangerateCheckBtn.classList.add('sp-btn-loading');
            exchangerateCheckBtn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + adminI18n.exchangerateChecking + '</span>';
            const formData = new FormData();
            formData.append('check_exchangerate_quota', '1');
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data && data.success) {
                    setExchangerateQuotaDisplay(data);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: data.message || adminI18n.exchangerateSynced,
                        showConfirmButton: false,
                        timer: 3000
                    });
                } else {
                    Swal.fire({
                        title: adminI18n.errorTitle,
                        text: (data && data.message) ? data.message : adminI18n.exchangerateSyncFailed,
                        icon: 'error',
                        confirmButtonText: adminI18n.okBtn
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    title: adminI18n.errorTitle,
                    text: adminI18n.netErrorPrefix + ' ' + error.message,
                    icon: 'error',
                    confirmButtonText: adminI18n.okBtn
                });
            })
            .finally(() => {
                exchangerateCheckBtn.disabled = false;
                exchangerateCheckBtn.classList.remove('sp-btn-loading');
                exchangerateCheckBtn.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>' + adminI18n.exchangerateCheckNow + '</span>';
            });
        });
    }
    // Function to refresh Spotify tokens
    window.refreshSpotifyTokens = function() {
        const button = document.querySelector('button[onclick="refreshSpotifyTokens()"]');
        button.disabled = true;
        button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + adminI18n.refreshing + '</span>';
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
                    title: adminI18n.spotifyRefreshed,
                    html: '<pre style="text-align: left; white-space: pre-wrap;">' + output + '</pre>',
                    icon: 'success',
                    confirmButtonText: adminI18n.okBtn,
                    width: '600px'
                });
            } else {
                Swal.fire({
                    title: adminI18n.errorTitle,
                    text: adminI18n.failRefreshTokens + ' ' + output,
                    icon: 'error',
                    confirmButtonText: adminI18n.okBtn
                });
            }
            button.disabled = false;
            button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>' + adminI18n.refreshSpotifyBtn + '</span>';
        })
        .catch(error => {
            Swal.fire({
                title: adminI18n.errorTitle,
                text: adminI18n.netErrorPrefix + ' ' + error.message,
                icon: 'error',
                confirmButtonText: adminI18n.okBtn
            });
            button.disabled = false;
            button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>' + adminI18n.refreshSpotifyBtn + '</span>';
        });
    };
    // Generic function to stream command output via SSE
    function resetTokenRefreshButton(button, buttonSelector) {
        if (!button) return;
        button.disabled = false;
        let label = adminI18n.refreshDiscordBtn;
        if (buttonSelector.includes('Spotify')) label = adminI18n.refreshSpotifyBtn;
        else if (buttonSelector.includes('StreamElements')) label = adminI18n.refreshStreamElementsBtn;
        else if (buttonSelector.includes('CustomBot')) label = adminI18n.refreshCustomBotBtn;
        else if (buttonSelector.includes('TwitchApp')) label = adminI18n.refreshTwitchAppBtn;
        button.innerHTML = '<span class="icon"><i class="fas fa-sync"></i></span><span>' + label + '</span>';
    }
    function streamCommand(scriptKey, serviceName, buttonSelector) {
        const button = document.querySelector(buttonSelector);
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + adminI18n.refreshing + '</span>';
        }
        let outputHtml = '<div style="text-align:left; max-height:500px; overflow:auto; white-space:pre-wrap; font-family: monospace;" id="stream-output">' + adminI18n.refreshing + '\n</div>';
        Swal.fire({
            title: serviceName + ' - ' + adminI18n.liveOutputSuffix,
            html: outputHtml,
            showCancelButton: true,
            cancelButtonText: adminI18n.closeBtn,
            showConfirmButton: false,
            width: 800,
            didOpen: () => {
                const outputEl = document.getElementById('stream-output');
                const formData = new FormData();
                formData.append('refresh_token_script', scriptKey);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const text = await response.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        outputEl.textContent = text || adminI18n.invalidJson;
                        resetTokenRefreshButton(button, buttonSelector);
                        return;
                    }
                    const output = (data.output || '').trim();
                    const status = (data.success ? adminI18n.success : adminI18n.failed);
                    outputEl.textContent = (output ? output + '\n\n' : '') + adminI18n.processDone + ' ' + status + '\n';
                    outputEl.scrollTop = outputEl.scrollHeight;
                    resetTokenRefreshButton(button, buttonSelector);
                })
                .catch(error => {
                    outputEl.textContent = '[ERROR] ' + (error.message || adminI18n.anErrorOccurred) + '\n';
                    resetTokenRefreshButton(button, buttonSelector);
                });
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
    window.refreshTwitchAppToken = function() {
        streamCommand('twitch_app', adminI18n.twitchAppTokenService || 'Twitch App Token', 'button[onclick="refreshTwitchAppToken()"]');
    };
    function setBusy(el, busy) {
        if (!el) return;
        if (busy) el.setAttribute('aria-busy', 'true');
        else el.removeAttribute('aria-busy');
    }
    function skeletonServiceStatusHtml() {
        return '<span class="sp-skeleton-badge" aria-hidden="true" style="width:4.5rem;"></span>';
    }
    function skeletonServicePidHtml() {
        return '<span class="sp-skeleton-badge" aria-hidden="true" style="width:3.2rem;"></span>';
    }
    function skeletonBotOverviewHtml() {
        let html = '<div class="admin-bot-grid" id="bot-columns" aria-hidden="true">';
        for (let i = 0; i < 6; i++) {
            html += '<div class="admin-bot-card"><div class="admin-service-card">' +
                '<div class="admin-level-row" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;">' +
                '<span class="sp-skeleton-avatar"></span><span class="sp-skeleton-line w-60"></span></div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.5rem;">' +
                '<span class="sp-skeleton-badge"></span><span class="sp-skeleton-badge" style="width:4rem;"></span>' +
                '<span class="sp-skeleton-badge" style="width:3rem;"></span></div>' +
                '<div style="display:flex;gap:0.5rem;">' +
                '<span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span>' +
                '<span class="sp-skeleton-badge" style="width:2rem;height:1.75rem;"></span></div>' +
                '</div></div>';
        }
        return html + '</div>';
    }
    function skeletonAiStatsHtml() {
        return '<div class="sp-skeleton-stack" aria-hidden="true" style="gap:0.75rem;">' +
            '<div class="sp-stat-row" style="grid-template-columns:repeat(3,1fr);">' +
            '<div class="sp-skeleton-stat"><span class="sp-skeleton-line w-55"></span><span class="sp-skeleton-value"></span><span class="sp-skeleton-line w-40"></span></div>' +
            '<div class="sp-skeleton-stat"><span class="sp-skeleton-line w-55"></span><span class="sp-skeleton-value"></span><span class="sp-skeleton-line w-40"></span></div>' +
            '<div class="sp-skeleton-stat"><span class="sp-skeleton-line w-55"></span><span class="sp-skeleton-value"></span><span class="sp-skeleton-line w-40"></span></div>' +
            '</div><span class="sp-skeleton-line w-70"></span><span class="sp-skeleton-line w-90"></span>' +
            '<span class="sp-skeleton-line w-50"></span></div>';
    }
    // Function to update service status
    function updateServiceStatus(service, statusElementId, pidElementId, buttonsElementId, retriesLeft, skipSkeleton) {
        retriesLeft = retriesLeft || 0;
        const statusElement = document.getElementById(statusElementId);
        const pidElement = document.getElementById(pidElementId);
        const buttonsElement = document.getElementById(buttonsElementId);
        if (!skipSkeleton) {
            if (statusElement) {
                statusElement.className = 'admin-service-status';
                statusElement.innerHTML = skeletonServiceStatusHtml();
                setBusy(statusElement, true);
            }
            if (pidElement) {
                pidElement.innerHTML = skeletonServicePidHtml();
                setBusy(pidElement, true);
            }
        }
        fetch(`service_status.php?service=${service}`)
            .then(response => response.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error(`Raw response text for ${service}:`, text);
                    throw parseError;
                }
                const retryable = data.status === 'Error' || data.status === 'Starting' || data.status === 'Unknown';
                if (retriesLeft > 0 && retryable) {
                    if (statusElement) {
                        statusElement.textContent = 'Starting';
                        statusElement.className = 'admin-service-status sp-text-warning';
                        setBusy(statusElement, false);
                    }
                    if (pidElement) {
                        pidElement.textContent = 'PID: ' + (data.pid || 'N/A');
                        setBusy(pidElement, false);
                    }
                    setTimeout(() => {
                        updateServiceStatus(service, statusElementId, pidElementId, buttonsElementId, retriesLeft - 1, true);
                    }, 1000);
                    return;
                }
                // Update status with appropriate color
                statusElement.textContent = data.status;
                statusElement.className = 'admin-service-status';
                if (data.status === 'Running') {
                    statusElement.classList.add('sp-text-success');
                } else if (data.status === 'SHUTDOWN') {
                    statusElement.classList.add('sp-text-muted');
                } else if (data.status === 'Stopped' || data.status === 'Failed') {
                    statusElement.classList.add('sp-text-danger');
                } else {
                    statusElement.classList.add('sp-text-warning');
                }
                setBusy(statusElement, false);
                // Update PID
                pidElement.textContent = `PID: ${data.pid}`;
                setBusy(pidElement, false);
                // Enable/disable buttons based on status
                const startBtn = buttonsElement.querySelector('button[onclick*="start"]');
                const stopBtn = buttonsElement.querySelector('button[onclick*="stop"]');
                const restartBtn = buttonsElement.querySelector('button[onclick*="restart"]');
                if (data.status === 'Running') {
                    startBtn.disabled = true;
                    stopBtn.disabled = false;
                    restartBtn.disabled = false;
                } else if (data.status === 'SHUTDOWN') {
                    // Retired / intentionally offline — no controls
                    startBtn.disabled = true;
                    stopBtn.disabled = true;
                    restartBtn.disabled = true;
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
                if (retriesLeft > 0) {
                    if (statusElement) {
                        statusElement.textContent = 'Starting';
                        statusElement.className = 'admin-service-status sp-text-warning';
                        setBusy(statusElement, false);
                    }
                    setTimeout(() => {
                        updateServiceStatus(service, statusElementId, pidElementId, buttonsElementId, retriesLeft - 1, true);
                    }, 1000);
                    return;
                }
                if (statusElement) {
                    statusElement.textContent = adminI18n.statusError;
                    statusElement.className = 'admin-service-status sp-text-danger';
                    setBusy(statusElement, false);
                }
                if (pidElement) setBusy(pidElement, false);
            });
    }
    // Load service statuses after page load
    setTimeout(() => {
        updateServiceStatus('discordbot', 'discord-status', 'discord-pid', 'discord-buttons');
        updateServiceStatus('bots_api', 'bots-api-status', 'bots-api-pid', 'bots-api-buttons');
        updateServiceStatus('bots_caddy', 'bots-caddy-status', 'bots-caddy-pid', 'bots-caddy-buttons');
        updateServiceStatus('fastapi', 'api-status', 'api-pid', 'api-buttons');
        updateServiceStatus('api_caddy', 'api-caddy-status', 'api-caddy-pid', 'api-caddy-buttons');
        updateServiceStatus('websocket', 'websocket-status', 'websocket-pid', 'websocket-buttons');
        updateServiceStatus('mysql', 'mysql-status', 'mysql-pid', 'mysql-buttons');
        updateServiceStatus('export_queue_worker', 'export-queue-status', 'export-queue-pid', 'export-queue-buttons');
        updateServiceStatus('twitch_recorder', 'twitch-recorder-status', 'twitch-recorder-pid', 'twitch-recorder-buttons');
        updateServiceStatus('web_caddy', 'web-caddy-status', 'web-caddy-pid', 'web-caddy-buttons');
    }, 100);
    // Refresh all server overview statuses
    window.refreshServerOverview = function() {
        const btn = document.getElementById('refresh-server-overview');
        const icon = btn.querySelector('i');
        btn.disabled = true;
        icon.classList.add('fa-spin');
        Object.values(serviceConfig).forEach(meta => {
            updateServiceStatus(meta.statusKey, meta.statusId, meta.pidId, meta.buttonsId);
        });
        setTimeout(() => {
            btn.disabled = false;
            icon.classList.remove('fa-spin');
        }, 1000);
    };
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
            el.textContent = adminI18n.updatedJustNow;
        } else if (delta < 60) {
            el.textContent = adminI18n.updatedPrefix + ' ' + delta + adminI18n.agoSeconds;
        } else if (delta < 3600) {
            const mins = Math.floor(delta / 60);
            el.textContent = adminI18n.updatedPrefix + ' ' + mins + adminI18n.agoMinutes;
        } else {
            const hours = Math.floor(delta / 3600);
            el.textContent = adminI18n.updatedPrefix + ' ' + hours + adminI18n.agoHours;
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
        const iconColor = bot.type === 'beta' ? 'sp-text-warning' : (bot.type === 'custom' ? 'sp-text-muted' : 'sp-text-accent');
        const tagClass = bot.type === 'beta' ? 'sp-badge-amber' : (bot.type === 'custom' ? 'sp-badge-grey' : 'sp-badge-accent');
        const typeLabel = bot.type.charAt(0).toUpperCase() + bot.type.slice(1);
        const safeId = 'bot-' + sanitizeId(bot.channel);
        let html = '<div class="admin-bot-card" id="' + safeId + '" data-bot-id="' + safeId + '">';
        html += '<div class="admin-service-card">';
        html += '<div class="admin-level-row">';
        if (profileImage) {
            html += '<img src="' + profileImage + '" alt="Profile" class="admin-bot-avatar bot-profile-img">';
        } else {
            html += '<span class="icon ' + iconColor + '">';
            html += '<i class="fas fa-robot fa-lg"></i>';
            html += '</span>';
        }
        html += '<p class="bot-channel">' + bot.channel + '</p>';
        html += '</div>';
        html += '<div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom:0.5rem;">';
        html += '<span class="sp-badge bot-type-tag ' + tagClass + '">' + typeLabel + '</span>';
        html += '<span class="sp-badge sp-badge-grey bot-pid">PID: ' + bot.pid + '</span>';
        if (bot.version) {
            html += '<span class="sp-badge sp-badge-blue bot-version">v' + bot.version + '</span>';
        }
        if (bot.is_outdated) {
            html += '<span class="sp-badge sp-badge-red">OUTDATED</span>';
        }
        html += '</div>';
        html += '<div style="display: flex; gap: 0.5rem;">';
        html += '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm bot-stop-button" data-pid="' + bot.pid + '" data-username="' + bot.channel + '" data-bot-type="' + bot.type + '" title="Stop Bot">';
        html += '<span class="icon"><i class="fas fa-stop"></i></span>';
        html += '</button>';
        html += '<button type="button" class="sp-btn sp-btn-info sp-btn-sm bot-restart-button" data-username="' + bot.channel + '" data-bot-type="' + bot.type + '" data-pid="' + bot.pid + '" title="Restart Bot">';
        html += '<span class="icon"><i class="fas fa-sync-alt"></i></span>';
        html += '</button>';
        html += '</div>';
        html += '</div>';
        return html + '</div>';
    }
    // Load bot overview after page load (diffs DOM instead of replacing everything)
    const loadBotOverview = () => {
        // Check if bot-overview section is open before fetching
        const botContainer = document.getElementById('bot-overview-container');
        if (!botContainer) return;
        // Ensure columns wrapper exists
        let columns = document.getElementById('bot-columns');
        if (!columns) {
            columns = document.createElement('div');
            columns.id = 'bot-columns';
            columns.className = 'admin-bot-grid';
            botContainer.appendChild(columns);
        }
        if (!botHasLoadedOnce) {
            setBusy(botContainer, true);
        }
        const base = window.location.href.split('?')[0];
        fetch(base + '?ajax=bot_overview')
            .then(response => response.json())
            .then(data => {
                // Clear skeleton grid on first successful load
                if (!botHasLoadedOnce) {
                    botContainer.innerHTML = '';
                    columns = document.createElement('div');
                    columns.id = 'bot-columns';
                    columns.className = 'admin-bot-grid';
                    botContainer.appendChild(columns);
                }
                botHasLoadedOnce = true;
                setBusy(botContainer, false);
                // update the 'updated at' relative timestamp
                setBotUpdatedNow();
                if (!data.bots || data.bots.length === 0) {
                    // No bots: clear columns and show message (only after first success)
                    botHasLoadedOnce = true;
                    setBotUpdatedNow();
                    columns.innerHTML = '<div class="admin-bot-card"><p>' + (data.error || adminI18n.none) + '</p></div>';
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
                            tagEl.className = 'sp-badge bot-type-tag ' + (bot.type === 'beta' ? 'sp-badge-amber' : (bot.type === 'custom' ? 'sp-badge-grey' : 'sp-badge-accent'));
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
                                versionTag.className = 'sp-badge sp-badge-blue bot-version';
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
                                    outdatedTag.className = 'sp-badge sp-badge-red';
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
                            stopBtn.setAttribute('data-username', bot.channel);
                            stopBtn.setAttribute('data-bot-type', bot.type);
                            // Remove existing listeners to prevent duplicates
                            const newStopBtn = stopBtn.cloneNode(true);
                            stopBtn.parentNode.replaceChild(newStopBtn, stopBtn);
                            newStopBtn.addEventListener('click', function() {
                                const pid = this.getAttribute('data-pid');
                                const username = this.getAttribute('data-username');
                                const botType = this.getAttribute('data-bot-type');
                                const element = this.closest('.admin-bot-card');
                                stopBot(pid, element, username, botType);
                            });
                        }
                        // update restart button attributes
                        const restartBtn = existingEl.querySelector('.bot-restart-button');
                        if (restartBtn) {
                            restartBtn.setAttribute('data-pid', bot.pid);
                            restartBtn.setAttribute('data-username', bot.channel);
                            restartBtn.setAttribute('data-bot-type', bot.type);
                            // Remove existing listeners to prevent duplicates
                            const newRestartBtn = restartBtn.cloneNode(true);
                            restartBtn.parentNode.replaceChild(newRestartBtn, restartBtn);
                            newRestartBtn.addEventListener('click', function() {
                                const pid = this.getAttribute('data-pid');
                                const username = this.getAttribute('data-username');
                                const botType = this.getAttribute('data-bot-type');
                                const element = this.closest('.admin-bot-card');
                                restartBot(username, botType, pid, element);
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
                                        const username = this.getAttribute('data-username');
                                        const botType = this.getAttribute('data-bot-type');
                                        const element = this.closest('.admin-bot-card');
                                        stopBot(pid, element, username, botType);
                                    });
                                }
                                const restartButton = newEl.querySelector('.bot-restart-button');
                                if (restartButton) {
                                    restartButton.addEventListener('click', function() {
                                        const pid = this.getAttribute('data-pid');
                                        const username = this.getAttribute('data-username');
                                        const botType = this.getAttribute('data-bot-type');
                                        const element = this.closest('.admin-bot-card');
                                        restartBot(username, botType, pid, element);
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
                setBusy(botContainer, false);
                const cols = document.getElementById('bot-columns') || columns;
                if (cols) cols.innerHTML = '<div class="admin-bot-card"><p class="sp-text-danger">' + adminI18n.errLoadBotOverview + '</p></div>';
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
                        'discordbot': adminI18n.msgsysDiscordBot,
                        'twitch_stable': adminI18n.msgsysChatBotStable,
                        'twitch_beta': adminI18n.msgsysChatBotBeta,
                        'twitch_custom': adminI18n.msgsysChatBotCustom
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
                                    countElement.textContent = adminI18n.notCountingYet;
                                }
                            }
                            // Update timestamp
                            const timestampElement = document.querySelector(`[data-bot-system="${key}"] .bot-message-count-timestamp`);
                            if (timestampElement && stats.last_updated) {
                                const date = new Date(stats.last_updated);
                                const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
                                timestampElement.textContent = adminI18n.lastUpdatedPrefix + ' ' + formattedDate;
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
    const refreshAiStatsButton = document.getElementById('refresh-ai-stats');
    let aiStatsLoading = false;
    // Load AI platform stats after the page has rendered.
    function loadAiPlatformStats() {
        if (aiStatsLoading) return;
        const aiStatsContainer = document.getElementById('ai-platform-stats-content');
        if (!aiStatsContainer) return;
        aiStatsLoading = true;
        aiStatsContainer.innerHTML = skeletonAiStatsHtml();
        setBusy(aiStatsContainer, true);
        if (refreshAiStatsButton) {
            refreshAiStatsButton.disabled = true;
            refreshAiStatsButton.classList.add('sp-btn-loading');
        }
        fetch('index.php?ajax=ai_platform_stats', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
            .then(res => res.json())
            .then(data => {
                if (data && data.success && typeof data.html === 'string') {
                    aiStatsContainer.innerHTML = data.html;
                } else {
                    aiStatsContainer.innerHTML = '<p class="sp-text-danger">' + adminI18n.failLoadAiStats + '</p>';
                }
                setBusy(aiStatsContainer, false);
            })
            .catch(err => {
                console.error('Error loading AI platform stats:', err);
                aiStatsContainer.innerHTML = '<p class="sp-text-danger">' + adminI18n.failLoadAiStats + '</p>';
                setBusy(aiStatsContainer, false);
            })
            .finally(() => {
                aiStatsLoading = false;
                if (refreshAiStatsButton) {
                    refreshAiStatsButton.disabled = false;
                    refreshAiStatsButton.classList.remove('sp-btn-loading');
                }
            });
    }
    if (refreshAiStatsButton) {
        refreshAiStatsButton.addEventListener('click', loadAiPlatformStats);
    }
    window.addEventListener('load', function() {
        // Intentionally defer AI stats until everything else is fully loaded.
        setTimeout(loadAiPlatformStats, 1500);
    });
    // Populate online channels asynchronously and enable send button only when both a channel is selected and a message is entered.
    const messageTextarea = document.getElementById('message');
    const sendButton = document.getElementById('send');
    const channelSelect = document.getElementById('channel-select');
    const includeOfflineCheckbox = document.getElementById('include-offline');
    const charCountElement = document.getElementById('char-count');
    const shoutoutUsernameInput = document.getElementById('shoutout-username');
    const sendShoutoutButton = document.getElementById('send-shoutout');
    const shoutoutHelperText = document.getElementById('shoutout-helper-text');
    let shoutoutUsernameValid = false;
    let shoutoutValidationTimer = null;
    let shoutoutValidationRequestId = 0;
    // Templates map injected from server
    const templatesMap = <?php echo json_encode($message_templates, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const templateSelect = document.getElementById('message-template-select');
    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            const key = this.value;
            if (key && templatesMap[key]) {
                // Insert template into the message textarea (replace current contents)
                messageTextarea.value = templatesMap[key];
            } else if (!key) {
                // If user chooses blank, clear textarea
                // Do not auto-clear to avoid loss; keep current behavior: only clear if blank explicitly desired
            }
            updateCharCount();
            updateSendButtonState();
            messageTextarea.focus();
        });
    }
    function updateCharCount() {
        if (!messageTextarea || !charCountElement) return;
        const length = messageTextarea.value.length;
        charCountElement.textContent = length + adminI18n.charCountSuffix;
        if (length > 255) {
            charCountElement.className = 'sp-text-danger';
        } else if (length > 230) {
            charCountElement.className = 'sp-text-warning';
        } else {
            charCountElement.className = 'sp-text-muted';
        }
    }
    function updateSendButtonState() {
        if (!sendButton) return;
        const length = messageTextarea ? messageTextarea.value.length : 0;
        const hasMessage = messageTextarea && messageTextarea.value.trim() !== '' && length <= 255;
        const hasChannel = channelSelect && channelSelect.value && channelSelect.value !== '';
        sendButton.disabled = !(hasMessage && hasChannel);
    }
    function updateShoutoutButtonState() {
        if (!sendShoutoutButton) return;
        const hasChannel = channelSelect && channelSelect.value && channelSelect.value !== '';
        sendShoutoutButton.disabled = !(hasChannel && shoutoutUsernameValid);
    }
    function updateShoutoutHelperText() {
        if (!shoutoutHelperText) return;
        const hasChannel = channelSelect && channelSelect.value && channelSelect.value !== '';
        const username = shoutoutUsernameInput ? shoutoutUsernameInput.value.trim() : '';
        if (!hasChannel) {
            shoutoutHelperText.textContent = adminI18n.selectChannelAbove;
            return;
        }
        if (!username) {
            shoutoutHelperText.textContent = adminI18n.channelWillShoutout;
            return;
        }
        if (shoutoutUsernameValid) {
            shoutoutHelperText.textContent = adminI18n.usernameValidated;
        }
    }
    function setShoutoutInputState(state) {
        if (!shoutoutUsernameInput) return;
        shoutoutUsernameInput.classList.remove('is-success', 'is-danger', 'is-warning'); // CSS handles sp-input state
        if (state === 'success') {
            shoutoutUsernameInput.classList.add('is-success'); // sp-input.is-success handled in CSS
        } else if (state === 'error') {
            shoutoutUsernameInput.classList.add('is-danger');
        } else if (state === 'loading') {
            shoutoutUsernameInput.classList.add('is-warning');
        }
    }
    function validateShoutoutUsernameDebounced() {
        if (!shoutoutUsernameInput) return;
        if (shoutoutValidationTimer) {
            clearTimeout(shoutoutValidationTimer);
        }

        const username = shoutoutUsernameInput.value.trim();
        shoutoutUsernameValid = false;
        setShoutoutInputState('idle');

        if (!username) {
            updateShoutoutButtonState();
            updateShoutoutHelperText();
            return;
        }
        if (!/^[a-z0-9_]{3,25}$/i.test(username.replace(/^@+/, ''))) {
            setShoutoutInputState('error');
            if (shoutoutHelperText) shoutoutHelperText.textContent = adminI18n.invalidUsernameFormat;
            updateShoutoutButtonState();
            return;
        }

        setShoutoutInputState('loading');
        if (shoutoutHelperText) shoutoutHelperText.textContent = adminI18n.validatingUsername;

        const currentRequestId = ++shoutoutValidationRequestId;
        shoutoutValidationTimer = setTimeout(() => {
            const normalized = username.replace(/^@+/, '').toLowerCase();
            const base = window.location.href.split('?')[0];
            const url = base + '?ajax=validate_shoutout_user&login=' + encodeURIComponent(normalized);
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (currentRequestId !== shoutoutValidationRequestId) return;
                    shoutoutUsernameValid = !!data.valid;
                    if (shoutoutUsernameValid) {
                        setShoutoutInputState('success');
                    } else {
                        setShoutoutInputState('error');
                        if (shoutoutHelperText) {
                            shoutoutHelperText.textContent = data.message || adminI18n.usernameValidationFailed;
                        }
                    }
                    updateShoutoutButtonState();
                    updateShoutoutHelperText();
                })
                .catch(err => {
                    if (currentRequestId !== shoutoutValidationRequestId) return;
                    console.error('Shoutout username validation failed:', err);
                    shoutoutUsernameValid = false;
                    setShoutoutInputState('error');
                    if (shoutoutHelperText) shoutoutHelperText.textContent = adminI18n.unableValidateUsername;
                    updateShoutoutButtonState();
                });
        }, 1000);
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
                if (data.error) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = adminI18n.errBotsInventory;
                    channelSelect.appendChild(opt);
                    channelSelect.disabled = true;
                    updateSendButtonState();
                    updateShoutoutButtonState();
                    updateShoutoutHelperText();
                    return;
                }
                const channels = data.channels || [];
                if (channels.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = includeOffline ? adminI18n.noChannelsFound : adminI18n.noOnlineChannels;
                    channelSelect.appendChild(opt);
                    channelSelect.disabled = true;
                } else {
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = adminI18n.chooseChannel;
                    channelSelect.appendChild(placeholder);
                    channels.forEach(ch => {
                        const opt = document.createElement('option');
                        opt.value = ch.twitch_user_id;
                        const displayName = ch.twitch_display_name || ch.twitch_user_id;
                        const parts = [displayName];
                        if (ch.bot_type) {
                            parts.push('(' + ch.bot_type + ')');
                        }
                        if (!ch.is_online) {
                            parts.push(adminI18n.offlineSuffix);
                        }
                        opt.textContent = parts.join(' ');
                        channelSelect.appendChild(opt);
                    });
                    channelSelect.disabled = false;
                }
                updateSendButtonState();
                updateShoutoutButtonState();
                updateShoutoutHelperText();
            })
            .catch(err => {
                console.error('Failed to load channels:', err);
                if (channelSelect) {
                    channelSelect.innerHTML = '';
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = adminI18n.errLoadingChannels;
                    channelSelect.appendChild(opt);
                    channelSelect.disabled = true;
                }
                updateSendButtonState();
                updateShoutoutButtonState();
                updateShoutoutHelperText();
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
        channelSelect.addEventListener('change', function() {
            updateSendButtonState();
            updateShoutoutButtonState();
            updateShoutoutHelperText();
        });
    }
    if (shoutoutUsernameInput) {
        shoutoutUsernameInput.addEventListener('input', function() {
            validateShoutoutUsernameDebounced();
        });
    }
    // Initial updates
    updateCharCount();
    updateSendButtonState();
    updateShoutoutButtonState();
    updateShoutoutHelperText();
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
            sendButton.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + adminI18n.sending + '</span>';
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
                    title: adminI18n.netErrorPrefix + ' ' + error.message
                });
            })
            .finally(() => {
                sendButton.disabled = false;
                sendButton.innerHTML = originalText;
                updateSendButtonState();
                updateShoutoutButtonState();
            });
        });
    }
    if (sendShoutoutButton) {
        sendShoutoutButton.addEventListener('click', function() {
            const channelId = channelSelect ? channelSelect.value : '';
            const username = shoutoutUsernameInput ? shoutoutUsernameInput.value.trim() : '';
            if (!channelId || !username) {
                return;
            }
            const formData = new FormData();
            formData.append('send_shoutout', '1');
            formData.append('channel_id', channelId);
            formData.append('shoutout_username', username);
            const originalText = sendShoutoutButton.innerHTML;
            sendShoutoutButton.disabled = true;
            sendShoutoutButton.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + adminI18n.sending + '</span>';
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (shoutoutUsernameInput) {
                        shoutoutUsernameInput.value = '';
                    }
                    shoutoutUsernameValid = false;
                    setShoutoutInputState('idle');
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
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true,
                        icon: 'error',
                        title: data.message || adminI18n.failSendShoutout
                    });
                }
            })
            .catch(error => {
                console.error('Error sending shoutout:', error);
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 5000,
                    timerProgressBar: true,
                    icon: 'error',
                    title: adminI18n.netErrorPrefix + ' ' + error.message
                });
            })
            .finally(() => {
                sendShoutoutButton.innerHTML = originalText;
                updateShoutoutButtonState();
                updateShoutoutHelperText();
            });
        });
    }
    <?php if (!empty($openai_debug_info)): ?>
    try {
        const __openai_debug = <?php echo json_encode($openai_debug_info, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
        if (Array.isArray(__openai_debug) && __openai_debug.length > 0) {
            console.groupCollapsed('OpenAI Debug (' + __openai_debug.length + ')');
            __openai_debug.forEach((entry, idx) => {
                try {
                    // Plain line for quick scanning
                    const method = entry.method || 'GET';
                    const url = entry.url || '';
                    console.log('#' + idx + ' ' + method + ' ' + url);
                    // Emit a Fetch-like finished line
                    console.log('Fetch finished loading: ' + method + ' "' + url + '".');
                    // Detailed grouped info (collapsed)
                    const title = '#' + idx + ' ' + method + ' ' + url;
                    console.groupCollapsed(title);
                    if (entry.http_code !== null) console.error('HTTP code:', entry.http_code);
                    if (entry.curl_error) console.error('curl_error:', entry.curl_error);
                    if (entry.pages_fetched !== undefined) console.error('pages_fetched:', entry.pages_fetched);
                    if (entry.query_params) console.error('query_params:', entry.query_params);
                    if (entry.page_debug && Array.isArray(entry.page_debug)) {
                        console.groupCollapsed('Per-page summaries (' + entry.page_debug.length + ')');
                        entry.page_debug.forEach((p, pi) => {
                            try {
                                console.groupCollapsed('#' + pi + ' ' + (p.url || 'page'));
                                if (p.http_code !== undefined) console.error('HTTP code:', p.http_code);
                                if (p.curl_error) console.error('curl_error:', p.curl_error);
                                if (p.summary) console.error('summary:', p.summary);
                                console.groupEnd();
                            } catch (pe) { console.error('page debug render error', pe); }
                        });
                        console.groupEnd();
                    }
                    if (entry.response_summary) console.error('response_summary:', entry.response_summary);
                    if (entry.metrics) console.error('metrics:', entry.metrics);
                    console.groupEnd();
                } catch (innerErr) {
                    console.error('OpenAI debug render error:', innerErr);
                }
            });
            console.groupEnd();
        }
    } catch (e) {
        console.error('Failed to parse OpenAI debug info:', e);
    }
    <?php endif; ?>
    <?php if (!empty($client_console_logs)): ?>
    try {
        const __client_logs = <?php echo json_encode($client_console_logs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
        if (Array.isArray(__client_logs) && __client_logs.length > 0) {
            console.groupCollapsed('Server Logs (' + __client_logs.length + ')');
            __client_logs.forEach((entry, idx) => {
                try {
                    const title = '#' + idx + ' ' + (entry.level || 'error');
                    console.groupCollapsed(title);
                    console.error(entry.msg);
                    console.groupEnd();
                } catch (innerErr) {
                    console.error('Server log render error:', innerErr);
                }
            });
            console.groupEnd();
        }
    } catch (e) {
        console.error('Failed to parse server logs:', e);
    }
    <?php endif; ?>
});
</script>
<?php
$content .= ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>
