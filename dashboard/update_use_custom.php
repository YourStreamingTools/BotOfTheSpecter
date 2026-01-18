<?php
session_start();
header('Content-Type: application/json');
// Require DB connection
require_once "/var/www/config/db_connect.php";

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$raw = file_get_contents('php://input');
parse_str($raw, $data);
$raw = file_get_contents('php://input');
parse_str($raw, $data);

// Accept either or both settings: use_custom and use_self
$updates = [];
$params = [];
$types = '';

if (isset($data['use_custom'])) {
    $use_custom = intval($data['use_custom']);
    if ($use_custom !== 0 && $use_custom !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid value for use_custom']);
        exit();
    }
    $updates[] = 'use_custom = ?';
    $params[] = $use_custom;
    $types .= 'i';
}

if (isset($data['use_self'])) {
    $use_self = intval($data['use_self']);
    if ($use_self !== 0 && $use_self !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid value for use_self']);
        exit();
    }
    $updates[] = 'use_self = ?';
    $params[] = $use_self;
    $types .= 'i';
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid parameters provided']);
    exit();
}

try {
    $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? LIMIT 1';
    $params[] = $user_id;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // bind_param requires references
    $bind_names[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    $stmt->close();

    // Update session copy for any changed values
    if (isset($use_custom)) {
        $_SESSION['use_custom'] = $use_custom;
    }
    if (isset($use_self)) {
        $_SESSION['use_self'] = $use_self;
    }

    $result = ['success' => true];
    if (isset($use_custom)) $result['use_custom'] = $use_custom;
    if (isset($use_self)) $result['use_self'] = $use_self;
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
