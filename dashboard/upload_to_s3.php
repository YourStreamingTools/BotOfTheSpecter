<?php
session_start();
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $server = $_POST['server'] ?? '';
    $file = $_POST['file'] ?? '';
    $username = $_POST['username'] ?? '';

    if (empty($server) || empty($file) || empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit();
    }

    // Server connection details
    $server_details = [
        'au-east-1' => [
            'host' => $storage_server_au_east_1_host,
            'username' => $storage_server_au_east_1_username,
            'password' => $storage_server_au_east_1_password,
            'recording_dir' => '/root/'
        ],
        'us-west-1' => [
            'host' => $storage_server_us_west_1_host,
            'username' => $storage_server_us_west_1_username,
            'password' => $storage_server_us_west_1_password,
            'recording_dir' => '/mnt/stream-us-west-1/'
        ]
    ];

    if (!isset($server_details[$server])) {
        echo json_encode(['success' => false, 'message' => 'Invalid server selected.']);
        exit();
    }

    $details = $server_details[$server];
    $connection = @ssh2_connect($details['host'], 22);

    if (!$connection || !@ssh2_auth_password($connection, $details['username'], $details['password'])) {
        echo json_encode(['success' => false, 'message' => 'Failed to connect to the server.']);
        exit();
    }

    $command = escapeshellcmd("python3 " . $details['recording_dir'] . "upload_to_persistent_storage.py " . escapeshellarg($username) . " " . escapeshellarg($file));
    $stream = @ssh2_exec($connection, $command);

    if (!$stream) {
        echo json_encode(['success' => false, 'message' => 'Failed to execute the upload script.']);
        exit();
    }

    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    fclose($stream);

    if (strpos($output, 'Success') !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Upload script failed: ' . $output]);
    }
}
?>
