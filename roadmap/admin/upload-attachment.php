<?php
/**
 * Roadmap Attachment Upload Handler
 * Handles file and image uploads for roadmap items
 */

session_start();

// Require admin authentication
if (!isset($_SESSION['username']) || !($_SESSION['admin'] ?? false)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

require_once 'database.php';

// Define upload directory
$uploadDir = '../uploads/attachments/';

// Ensure directory exists with proper permissions
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Failed to create upload directory']));
    }
}

// Check if directory is writable
if (!is_writable($uploadDir)) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Upload directory is not writable. Path: ' . realpath($uploadDir)]));
}

// Allowed file types (disallow SVG to avoid inline-SVG XSS vectors)
$allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$allowedDocTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
$allowedFileTypes = array_merge($allowedImageTypes, $allowedDocTypes);

// Max file size: 10MB
$maxFileSize = 10 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $itemId = $_POST['item_id'] ?? 0;
    $file = $_FILES['file'];
    // Validate item ID
    if (!is_numeric($itemId) || $itemId <= 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Invalid item ID']));
    }
    // Ensure the referenced roadmap item exists
    $checkConn = getRoadmapConnection();
    $checkStmt = $checkConn->prepare("SELECT id FROM roadmap_items WHERE id = ?");
    if (!$checkStmt) {
        $checkConn->close();
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Database error']));
    }
    $checkStmt->bind_param("i", $itemId);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        $checkStmt->close();
        $checkConn->close();
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Roadmap item not found']));
    }
    $checkStmt->close();
    $checkConn->close();
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'File upload error: ' . $file['error']]));
    }
    // Check file size
    if ($file['size'] > $maxFileSize) {
        http_response_code(413);
        die(json_encode(['success' => false, 'message' => 'File size exceeds 10MB limit']));
    }
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowedFileTypes)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'File type not allowed. Allowed types: Images (JPG, PNG, GIF, WebP, SVG) and Documents (PDF, Word, Excel, TXT)']));
    }
    // Determine if it's an image
    $isImage = in_array($mimeType, $allowedImageTypes) ? 1 : 0;
    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $file['name']);
    $filePath = $uploadDir . $uniqueName;
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        $error = 'Failed to save file to: ' . $filePath;
        if (is_file($filePath)) {
            $error .= ' (file already exists)';
        }
        if (!is_writable(dirname($filePath))) {
            $error .= ' (directory not writable)';
        }
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => $error]));
    }
    // Store in database
    $conn = getRoadmapConnection();
    $stmt = $conn->prepare("INSERT INTO roadmap_attachments (item_id, file_name, file_path, file_type, file_size, is_image, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $relativeFilePath = str_replace('\\', '/', $filePath);
        // types: item_id (i), file_name (s), file_path (s), file_type (s), file_size (i), is_image (i), uploaded_by (s)
        $stmt->bind_param("isssiis", $itemId, $file['name'], $relativeFilePath, $mimeType, $file['size'], $isImage, $_SESSION['username']);
        if ($stmt->execute()) {
            $attachmentId = $conn->insert_id;
            http_response_code(200);
            die(json_encode([
                'success' => true,
                'message' => 'File uploaded successfully',
                'attachment_id' => $attachmentId,
                'file_name' => $file['name'],
                'is_image' => $isImage,
                'file_path' => $relativeFilePath
            ]));
        } else {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Error saving to database: ' . $stmt->error]));
        }
        $stmt->close();
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
