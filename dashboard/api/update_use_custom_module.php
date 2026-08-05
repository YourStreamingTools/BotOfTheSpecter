<?php
require_once '/var/www/lib/session_bootstrap.php';
header('Content-Type: application/json');
require_once "/var/www/config/db_connect.php";

if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    $i18nPath = __DIR__ . '/../lang/i18n.php';
    if (file_exists($i18nPath)) {
        include_once $i18nPath;
    }
    if (!function_exists('t')) {
        function t($key, $replacements = [])
        {
            return $key;
        }
    }
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('update_use_custom_module_error_not_authenticated')]);
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('update_use_custom_module_error_method_not_allowed')]);
    exit();
}

$raw = file_get_contents('php://input');
parse_str($raw, $data);

if (!isset($data['use_custom_module'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('update_use_custom_module_error_no_valid_parameters')]);
    exit();
}

$use_custom_module = intval($data['use_custom_module']);
if ($use_custom_module !== 0 && $use_custom_module !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('update_use_custom_module_error_invalid')]);
    exit();
}

try {
    $stmt = $conn->prepare('UPDATE users SET use_custom_module = ? WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('ii', $use_custom_module, $user_id);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    $stmt->close();

    $_SESSION['use_custom_module'] = $use_custom_module;
    session_write_close();

    echo json_encode([
        'success' => true,
        'use_custom_module' => $use_custom_module,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
