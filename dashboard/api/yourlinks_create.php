<?php
/**
 * YourLinks.click Backend API Proxy
 * Handles server-to-server communication with YourLinks API to avoid CORS issues
 */

// Set response header
header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Get JSON payload
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['api']) || !isset($input['link_name']) || !isset($input['destination'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Extract data
$api_key = $input['api'];
$link_name = $input['link_name'];
$destination = $input['destination'];
$title = isset($input['title']) ? $input['title'] : '';

// Build URL for YourLinks API
$params = [
    'api' => $api_key,
    'link_name' => $link_name,
    'destination' => $destination
];

if (!empty($title)) {
    $params['title'] = $title;
}

$url = 'https://yourlinks.click/services/api.php?' . http_build_query($params);

// Make request to YourLinks API using cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// Handle cURL errors
if ($curl_error) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Failed to communicate with YourLinks API: ' . $curl_error]);
    exit;
}

// Parse response
$data = json_decode($response, true);

// Return the response from YourLinks API
http_response_code($http_code);
echo json_encode($data);
?>
