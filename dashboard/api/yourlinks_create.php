<?php
/**
 * YourLinks.click Backend API Proxy
 * Server-to-server proxy for the user's YourLinks short-link creation.
 * The API key is taken from the logged-in session — never trust a key
 * supplied in the request body.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

require_once '/var/www/lib/session_bootstrap.php';

if (empty($_SESSION['access_token']) || empty($_SESSION['api_key'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Cross-site request guard. SameSite=Lax on the session cookie blocks the
// cookie from being sent on cross-origin POSTs, but belt-and-braces: only
// accept JSON from same-origin callers (the dashboard JS).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
$sourceHost = '';
if ($origin !== '') {
    $sourceHost = parse_url($origin, PHP_URL_HOST) ?? '';
} elseif ($referer !== '') {
    $sourceHost = parse_url($referer, PHP_URL_HOST) ?? '';
}
if ($sourceHost === '' || strcasecmp($sourceHost, $host) !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Cross-origin request rejected']);
    exit;
}

// Cap inbound payload at 4 KB — link metadata, not file uploads.
$rawBody = file_get_contents('php://input', false, null, 0, 4096);
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty request body']);
    exit;
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$link_name = isset($input['link_name']) ? trim((string)$input['link_name']) : '';
$destination = isset($input['destination']) ? trim((string)$input['destination']) : '';
$title = isset($input['title']) ? trim((string)$input['title']) : '';

if ($link_name === '' || $destination === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $link_name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Link name must be 1-50 characters of letters, digits, hyphen, or underscore']);
    exit;
}

if (strlen($destination) > 2048 || !filter_var($destination, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Destination must be a valid URL (max 2048 chars)']);
    exit;
}

$destinationScheme = strtolower((string)parse_url($destination, PHP_URL_SCHEME));
if ($destinationScheme !== 'http' && $destinationScheme !== 'https') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Destination must use http or https']);
    exit;
}

if (strlen($title) > 100) {
    $title = substr($title, 0, 100);
}

$params = [
    'api' => $_SESSION['api_key'],
    'link_name' => $link_name,
    'destination' => $destination,
];
if ($title !== '') {
    $params['title'] = $title;
}

$url = 'https://yourlinks.click/services/api.php?' . http_build_query($params);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

$response = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false || $curl_error !== '') {
    http_response_code(502);
    error_log('yourlinks_create curl failure: ' . $curl_error);
    echo json_encode(['success' => false, 'message' => 'Could not reach the link service. Please try again.']);
    exit;
}

$data = json_decode((string)$response, true);
if (!is_array($data)) {
    http_response_code(502);
    error_log('yourlinks_create: non-JSON response from upstream (HTTP ' . $http_code . ')');
    echo json_encode(['success' => false, 'message' => 'Unexpected response from the link service.']);
    exit;
}

http_response_code($http_code >= 200 && $http_code < 600 ? $http_code : 502);
echo json_encode($data);
