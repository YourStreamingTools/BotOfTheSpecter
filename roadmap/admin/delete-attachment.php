<?php
/**
 * Roadmap Attachment Delete Handler
 * Handles deletion of file attachments for roadmap items
 */

session_start();

// Require admin authentication
if (!isset($_SESSION['username']) || !($_SESSION['admin'] ?? false)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

require_once 'database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['attachment_id'])) {
    $attachmentId = $_POST['attachment_id'] ?? 0;
    
    // Validate attachment ID
    if (!is_numeric($attachmentId) || $attachmentId <= 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Invalid attachment ID']));
    }
    
    $conn = getRoadmapConnection();
    
    // Get file path before deleting
    $stmt = $conn->prepare("SELECT file_path FROM roadmap_attachments WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $attachmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(404);
            die(json_encode(['success' => false, 'message' => 'Attachment not found']));
        }
        
        $row = $result->fetch_assoc();
        $filePath = $row['file_path'];
        $stmt->close();
        
        // Delete from database
        $stmt = $conn->prepare("DELETE FROM roadmap_attachments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $attachmentId);
            
            if ($stmt->execute()) {
                // Delete file from filesystem (ensure path is inside uploads directory)
                $uploadsDir = realpath(__DIR__ . '/../uploads/attachments');
                $resolved = realpath($filePath);
                if ($resolved && $uploadsDir && strpos($resolved, $uploadsDir) === 0 && file_exists($resolved)) {
                    unlink($resolved);
                }
                
                http_response_code(200);
                die(json_encode(['success' => true, 'message' => 'Attachment deleted successfully']));
            } else {
                http_response_code(500);
                die(json_encode(['success' => false, 'message' => 'Error deleting from database: ' . $stmt->error]));
            }
            $stmt->close();
        } else {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]));
        }
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]));
    }
    $conn->close();
} else {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid request']));
}
?>
