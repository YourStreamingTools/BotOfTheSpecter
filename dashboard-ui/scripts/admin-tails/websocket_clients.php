<?php
// Keys stay on the server: rows carry an opaque ref (sha1 of the key); the key itself is
// only sent by ?reveal=<ref> (audited), and ?user=<ref> returns that key's clients.
// Handle AJAX request to disconnect client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_client'])) {
    header('Content-Type: application/json');
    $sid = $_POST['sid'] ?? '';
    if (empty($sid)) {
        echo json_encode(['success' => false, 'message' => t('admin_websocket_clients_msg_sid_required')]);
        exit;
    }
    // Send disconnect command to the websocket server
    $disconnectUrl = 'https://websocket.botofthespecter.com/admin/disconnect?admin_key=' . urlencode($admin_key);
    $postData = json_encode(['sid' => $sid]);
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'User-Agent: BotOfTheSpecter Admin Panel'
            ],
            'content' => $postData
        ]
    ]);
    $response = @file_get_contents($disconnectUrl, false, $context);
    if ($response === false) {
        echo json_encode(['success' => false, 'message' => t('admin_websocket_clients_msg_connect_failed')]);
        exit;
    }
    $result = json_decode($response, true);
    if ($result && isset($result['success']) && $result['success']) {
        echo json_encode(['success' => true, 'message' => t('admin_websocket_clients_msg_disconnect_success')]);
    } else {
        $errorMsg = $result['message'] ?? t('admin_websocket_clients_msg_unknown_error');
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
    exit;
}

header('Content-Type: application/json');
$data = fetchWebsocketClients($conn);
$byRef = [];
foreach ($data['registered_clients'] as $k => $u) { $byRef[sha1((string) $k)] = $u; }
// Refresh Overlays: OVERLAY_REFRESH on that user's key reloads every overlay page they have open.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_overlays'])) {
    $u = $byRef[(string) ($_POST['ref'] ?? '')] ?? null;
    if (!$u) { echo json_encode(['success' => false, 'message' => t('admin_websocket_clients_user_not_found')]); exit; }
    $ch = curl_init('https://websocket.botofthespecter.com/notify?event=OVERLAY_REFRESH');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-API-KEY: ' . str_replace(["\r", "\n"], '', (string) $u['api_key']), 'User-Agent: BotOfTheSpecter Admin Panel'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $out !== false && $status === 200;
    admin_audit_log('admin_ws_overlay_refresh', $ok ? 'info' : 'warning', ['display_name' => $u['twitch_display_name']], 'websocket_client', $u['twitch_display_name']);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Refresh sent. Their overlays will reload shortly.' : 'Could not reach the websocket server.']);
    exit;
}
if (isset($_GET['reveal']) || isset($_GET['user'])) {
    $ref = (string) ($_GET['reveal'] ?? $_GET['user']);
    $u = $byRef[$ref] ?? null;
    if (!$u) { echo json_encode(['success' => false, 'error' => t('admin_websocket_clients_user_not_found')]); exit; }
    if (isset($_GET['reveal'])) {
        admin_audit_log('admin_ws_client_key_reveal', 'info', ['display_name' => $u['twitch_display_name']], 'websocket_client', $u['twitch_display_name']);
        echo json_encode(['success' => true, 'api_key' => $u['api_key']]);
        exit;
    }
    unset($u['api_key']);
    $u['ref'] = $ref;
    $u['success'] = true;
    echo json_encode($u);
    exit;
}
$apiError = false;
if (empty($data['registered_clients']) && empty($data['global_listeners'])) {
    $headers = @get_headers('https://websocket.botofthespecter.com/clients', 1);
    if (!$headers || strpos($headers[0], '200') === false) { $apiError = true; }
}
// Registered users are listed by their users.id (same order as the Users page); keys with no user row go last.
$idStmt = $conn->prepare("SELECT id FROM users WHERE api_key = ? LIMIT 1");
$rows = [];
foreach ($data['registered_clients'] as $k => $u) {
    $first = $u['clients'][0] ?? [];
    $uid = PHP_INT_MAX;
    if ($idStmt) {
        $key = (string) $k;
        $idStmt->bind_param('s', $key);
        $idStmt->execute();
        $res = $idStmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) { $uid = (int) $row['id']; }
    }
    $rows[] = ['ref' => sha1((string) $k), 'key_len' => min(strlen((string) $k), 32), 'twitch_display_name' => $u['twitch_display_name'], 'client_count' => $u['client_count'],
        'key_type' => (string) ($first['key_type'] ?? ''), 'is_admin' => !empty($first['is_admin']), 'service' => (string) ($first['service'] ?? ''), 'uid' => $uid];
}
usort($rows, fn($a, $b) => $a['uid'] <=> $b['uid']);
foreach ($rows as &$r) { unset($r['uid']); }
unset($r);
echo json_encode(['success' => true, 'api_error' => $apiError, 'last_updated' => date('c'), 'registered' => $rows, 'global_listeners' => $data['global_listeners'], 'L' => badm_lang('admin_websocket_clients_')], JSON_UNESCAPED_UNICODE);
exit;
