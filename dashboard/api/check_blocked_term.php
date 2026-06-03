<?php
// Initialize session and check authentication
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
require_once '/var/www/lib/require_auth_ajax.php';

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

// Set JSON header
header('Content-Type: application/json');

// Get the term from POST data
$term = isset($_POST['term']) ? trim($_POST['term']) : '';

if (empty($term)) {
    echo json_encode(['valid' => true]);
    exit();
}

// Database connections
require_once "/var/www/config/database.php";
$dbname = $_SESSION['username'];

// Connect to spam_pattern database
$spam_db = new mysqli($db_servername, $db_username, $db_password, 'spam_pattern');
if ($spam_db->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Connect to user database
$user_db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($user_db->connect_error) {
    $spam_db->close();
    http_response_code(500);
    echo json_encode(['error' => 'User database connection failed']);
    exit();
}

try {
    $validation_result = [
        'valid' => true,
        'reason' => '',
        'message' => ''
    ];
    
    // Check spam patterns
    $stmt = $spam_db->prepare("SELECT spam_pattern FROM spam_patterns");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $pattern = $row['spam_pattern'];
        $escaped_pattern = preg_quote($pattern, '/');
        
        if (preg_match('/' . $escaped_pattern . '/i', $term)) {
            $validation_result['valid'] = false;
            $validation_result['reason'] = 'spam_pattern';
            $validation_result['message'] = t('check_blocked_term_msg_spam_pattern');
            break;
        }
    }
    $stmt->close();
    
    // Check whitelist if still valid
    if ($validation_result['valid']) {
        $stmt = $user_db->prepare("SELECT link FROM link_whitelist");
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $link = $row['link'];
            
            // Check if term is contained in whitelist link (case-insensitive)
            if (stripos($link, $term) !== false || stripos($term, $link) !== false) {
                $validation_result['valid'] = false;
                $validation_result['reason'] = 'whitelist';
                $validation_result['message'] = t('check_blocked_term_msg_whitelist');
                break;
            }
        }
        $stmt->close();
    }
    
    // Check blacklist if still valid
    if ($validation_result['valid']) {
        $stmt = $user_db->prepare("SELECT link FROM link_blacklisting");
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $link = $row['link'];
            
            // Check if term is contained in blacklist link (case-insensitive)
            if (stripos($link, $term) !== false || stripos($term, $link) !== false) {
                $validation_result['valid'] = false;
                $validation_result['reason'] = 'blacklist';
                $validation_result['message'] = t('check_blocked_term_msg_blacklist');
                break;
            }
        }
        $stmt->close();
    }
    
    $spam_db->close();
    $user_db->close();
    
    echo json_encode($validation_result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
