<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();

// Load translations so user-facing JSON messages are localized.
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

// Check if user is logged in
if (!isset($_SESSION['access_token']) || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('get_custom_embed_error_not_authenticated')]);
    exit();
}

// Include database connection
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

$user_id = $_SESSION['user_id'];

// Get parameters
$embed_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$server_id = isset($_GET['server_id']) ? trim($_GET['server_id']) : '';

if (!$embed_id || !$server_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('get_custom_embed_error_missing_params')]);
    exit();
}

try {
    // Connect to Discord bot database
    $discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
    if ($discord_conn->connect_error) {
        throw new Exception(t('get_custom_embed_error_db_connection'));
    }
    // Fetch embed
    $stmt = $discord_conn->prepare("SELECT * FROM custom_embeds WHERE id = ? AND server_id = ?");
    $stmt->bind_param("is", $embed_id, $server_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'embed' => $row
        ]);
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => t('get_custom_embed_error_not_found')]);
    }
    $stmt->close();
    $discord_conn->close();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('get_custom_embed_error_server', [$e->getMessage()])]);
}
?>