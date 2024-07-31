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

// Get the event type from the request
if (isset($_POST['event'])) {
    $event = $_POST['event'];
    $api_key = $_SESSION['api_key'];

    // Execute the shell command
    $command = "curl -X GET https://websocket.botofthespecter.com:8080/notify?code={$api_key}&event={$event}";
    $output = shell_exec($command);

    // Check if the command executed successfully
    if ($output === null) {
        echo json_encode(['success' => false, 'message' => 'Failed to execute the command.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Event sent successfully.', 'output' => $output]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No event specified.']);
}
?>