<?php
// GET ?reveal=<service>: one key, fetched only when Reveal / Copy is pressed (audited).
// GET: the table without the key values (the old page printed them into the HTML).
ob_end_clean();
header('Content-Type: application/json');
if (isset($_GET['reveal'])) {
    $svc = (string) $_GET['reveal'];
    $stmt = $conn->prepare('SELECT api_key FROM admin_api_keys WHERE service = ? LIMIT 1');
    $stmt->bind_param('s', $svc);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    admin_audit_log('admin_api_key_reveal', $row ? 'info' : 'not_found', ['service' => $svc], 'admin_api_key', $svc);
    echo json_encode($row ? ['success' => true, 'api_key' => $row['api_key']] : ['success' => false, 'message' => 'Not found']);
    exit;
}
$api_keys = [];
$api_keys_error = '';
$result = $conn->query("SELECT service, websocket_access, websocket_global FROM admin_api_keys ORDER BY service");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $api_keys[] = ['service' => $row['service'], 'websocket_access' => (int) $row['websocket_access'], 'websocket_global' => (int) $row['websocket_global']];
    }
    $result->free();
} else {
    $api_keys_error = $conn->error;
}
echo json_encode(['success' => true, 'keys' => $api_keys, 'error' => $api_keys_error]);
exit;
