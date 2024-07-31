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
        // Execute the shell command
        $command = "curl -X GET https://websocket.botofthespecter.com:8080/notify?code={$api_key}&event={$event}";
        $output = shell_exec($command);
        // Check if the command executed successfully
        if ($output === null) {
            $response['message'] = 'Failed to execute the command.';
        } else {
            $response['success'] = true;
            $response['message'] = 'Event sent successfully.';
            $response['output'] = $output;
        }
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