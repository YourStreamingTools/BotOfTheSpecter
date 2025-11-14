<?php
/**
 * Get Roadmap Attachments
 * Fetches all attachments for a roadmap item
 */

session_start();

require_once 'database.php';

if ($_GET['item_id'] ?? false) {
    $itemId = $_GET['item_id'];
    
    // Validate item ID
    if (!is_numeric($itemId) || $itemId <= 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Invalid item ID']));
    }
    
    $conn = getRoadmapConnection();
    
    $stmt = $conn->prepare("SELECT id, file_name, file_path, file_type, file_size, is_image, uploaded_by, created_at FROM roadmap_attachments WHERE item_id = ? ORDER BY created_at DESC");
    
    if ($stmt) {
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $attachments = [];
        while ($row = $result->fetch_assoc()) {
            $row['file_size_formatted'] = formatBytes($row['file_size']);
            $row['can_delete'] = $_SESSION['admin'] ?? false;
            $attachments[] = $row;
        }
        
        http_response_code(200);
        die(json_encode(['success' => true, 'attachments' => $attachments]));
        
        $stmt->close();
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]));
    }
    $conn->close();
} else {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Item ID required']));
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>
