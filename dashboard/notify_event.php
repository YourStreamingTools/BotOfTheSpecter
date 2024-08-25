<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Prepare a response array
$response = ['success' => false, 'message' => '', 'output' => ''];

try {
    // Get the event type and api_key from the request
    if (isset($_POST['event']) && isset($_POST['api_key'])) {
        $event = $_POST['event'];
        $api_key = $_POST['api_key'];

        // Initialize curl
        $ch = curl_init();

        // Set the URL
        $url = "https://websocket.botofthespecter.com/notify?code=$api_key&event=$event";
        curl_setopt($ch, CURLOPT_URL, $url);

        // Return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Execute the curl session
        $output = curl_exec($ch);

        // Check for curl errors
        if ($output === false) {
            $response['message'] = 'Curl error: ' . curl_error($ch);
        } else {
            $response['success'] = true;
            $response['message'] = 'Event sent successfully.';
            $response['output'] = $output;
        }

        // Close the curl session
        curl_close($ch);
    } else {
        $response['message'] = 'No event or api_key specified.';
    }
} catch (Exception $e) {
    $response['message'] = 'Exception: ' . $e->getMessage();
}

// Output the JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>