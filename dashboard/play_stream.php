<?php
session_start();
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}
require_once "/var/www/config/db_connect.php";
include "/var/www/config/ssh.php";
include 'userdata.php';

// Validate and get parameters
if (!isset($_GET['server']) || !isset($_GET['file'])) {
    header('Location: streaming.php');
    exit();
}
$selected_server = $_GET['server'];
$filename = $_GET['file'];
if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || in_array($filename, ['.', '..'])) {
    header('Location: streaming.php');
    exit();
}

$from_persistent = isset($_GET['persistent']) && $_GET['persistent'] === 'true';

if ($from_persistent) {
    require_once '/var/www/vendor/aws-autoloader.php';
    use Aws\S3\S3Client;

    $s3Client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => "https://" . $bucket_url,
        'credentials' => [
            'key' => $access_key,
            'secret' => $secret_key
        ]
    ]);

    try {
        $bucketName = $username; // Assuming bucket name matches username
        $object = $s3Client->getObject([
            'Bucket' => $bucketName,
            'Key' => $filename
        ]);

        header('Content-Type: ' . $object['ContentType']);
        header('Content-Disposition: inline; filename="' . basename($filename) . '"');
        header('Content-Length: ' . $object['ContentLength']);
        echo $object['Body'];
        exit();
    } catch (Aws\Exception\AwsException $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Error retrieving file from persistent storage: " . htmlspecialchars($e->getMessage());
        exit();
    }
}

// Set server details based on selection
switch ($selected_server) {
    case 'au-east-1':
        $server_host = $storage_server_au_east_1_host;
        $server_username = $storage_server_au_east_1_username;
        $server_password = $storage_server_au_east_1_password;
        break;
    default:
        header('Location: streaming.php');
        exit();
}
if (!function_exists('ssh2_connect')) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "SSH2 extension not installed.";
    exit();
}
$connection = @ssh2_connect($server_host, 22);
if (!$connection) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Cannot connect to server.";
    exit();
}
if (!@ssh2_auth_password($connection, $server_username, $server_password)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Authentication failed.";
    exit();
}
$sftp = @ssh2_sftp($connection);
if (!$sftp) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "SFTP initialization failed.";
    exit();
}
$file_path = "/root/{$username}/{$filename}";
$sftp_path = "ssh2.sftp://" . intval($sftp) . $file_path;
if (!file_exists($sftp_path)) {
    header('HTTP/1.1 404 Not Found');
    echo "File not found.";
    exit();
}
$filesize = filesize($sftp_path);
header('Content-Type: video/mp4');
header('Content-Disposition: inline; filename="' . basename($filename) . '"');
header('Content-Length: ' . $filesize);
$handle = @fopen($sftp_path, 'r');
if (!$handle) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Cannot open file.";
    exit();
}
$buffer_size = 8192;
while (!feof($handle)) {
    echo fread($handle, $buffer_size);
    ob_flush();
    flush();
}
fclose($handle);
exit();
?>
