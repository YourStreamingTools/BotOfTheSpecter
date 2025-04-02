<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Persistent Storage";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
$billing_conn = new mysqli($servername, $username, $password, "fossbilling");
include "/var/www/config/object_storage.php";
include 'userdata.php';
include 'user_db.php';
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
}
date_default_timezone_set($timezone);

// Include S3-compatible API library
require_once '/var/www/vendor/aws-autoloader.php';

// Check connection
if ($billing_conn->connect_error) {
    die("Billing connection failed: " . $billing_conn->connect_error);
}

// Check subscription status
$is_subscribed = false;
$subscription_status = 'Inactive';
$suspend_reason = '';

// Get user email from the profile data
if (isset($email)) {
    // First, get the client ID from the client table
    $stmt = $billing_conn->prepare("SELECT id FROM client WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $client_id = $row['id'];
        // Check if the client has an active Persistent Storage Membership
        $stmt = $billing_conn->prepare("
            SELECT co.status, co.reason
            FROM client_order co
            WHERE co.client_id = ? 
            AND co.title LIKE '%Persistent Storage%'
            ORDER BY co.id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $subscription_status = ucfirst($row['status']);
            $is_subscribed = ($row['status'] === 'active');
            $suspend_reason = $row['reason'] ?? '';
        }
    }
    $stmt->close();
}
$billing_conn->close();

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Initialize S3 client for AWS
$s3Client = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'endpoint' => "https://" . $bucket_url,
    'credentials' => [
        'key' => $access_key,
        'secret' => $secret_key
    ]
]);

// Function to fetch files from S3 bucket
function getS3Files($bucketName) {
    global $s3Client;
    $files = [];
    try {
        $result = $s3Client->listObjectsV2([
            'Bucket' => $bucketName
        ]);
        if (!empty($result['Contents'])) {
            foreach ($result['Contents'] as $object) {
                $key = $object['Key'];
                $sizeBytes = $object['Size'];
                $lastModified = $object['LastModified']->getTimestamp();
                // Format file size
                $size = $sizeBytes < 1024 * 1024 ? round($sizeBytes / 1024, 2) . ' KB' :
                    ($sizeBytes < 1024 * 1024 * 1024 ? round($sizeBytes / (1024 * 1024), 2) . ' MB' :
                    round($sizeBytes / (1024 * 1024 * 1024), 2) . ' GB');
                // Format creation date
                $createdAt = date('d-m-Y H:i:s', $lastModified);
                $files[] = [
                    'name' => basename($key),
                    'size' => $size,
                    'created_at' => $createdAt,
                    'path' => $key,
                    'duration' => 'N/A'
                ];
            }
        }
    } catch (AwsException $e) {
        return ['error' => $e->getMessage()];
    }
    return $files;
}

// Fetch persistent storage files
$persistent_storage_files = [];
$persistent_storage_error = null;
$total_used_storage = 0; // Initialize total used storage

$result = getS3Files($username);
if (isset($result['error'])) {
    $persistent_storage_error = "Persistent storage is not available at the moment. Please try again later.";
} else {
    $persistent_storage_files = $result;
    // Calculate total used storage
    foreach ($persistent_storage_files as $file) {
        if (isset($file['size'])) {
            // Convert size to bytes for calculation
            $size = $file['size'];
            if (strpos($size, 'KB') !== false) {
                $total_used_storage += floatval($size) * 1024;
            } elseif (strpos($size, 'MB') !== false) {
                $total_used_storage += floatval($size) * 1024 * 1024;
            } elseif (strpos($size, 'GB') !== false) {
                $total_used_storage += floatval($size) * 1024 * 1024 * 1024;
            }
        }
    }
    // Convert total used storage to GB for display
    $total_used_storage = round($total_used_storage / (1024 * 1024 * 1024), 2);
}

// Handle file deletion if requested
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $fileToDelete = $_GET['delete'];
    try {
        $s3Client->deleteObject([
            'Bucket' => $username,
            'Key' => $fileToDelete
        ]);
        $_SESSION['delete_success'] = true;
        header('Location: persistent_storage_page.php');
        exit();
    } catch (AwsException $e) {
        $delete_error = "Error deleting file: " . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
    <head>
        <!-- Header -->
        <?php include('header.php'); ?>
        <!-- /Header -->
    </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <h1 class="title">Persistent Storage</h1>
    
    <?php if (isset($_SESSION['delete_success'])): ?>
        <?php unset($_SESSION['delete_success']); ?>
        <div class="notification is-success">
            File has been successfully deleted.
        </div>
    <?php endif; ?>

    <?php if (isset($delete_error)): ?>
        <div class="notification is-danger">
            <?php echo htmlspecialchars($delete_error); ?>
        </div>
    <?php endif; ?>

    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-10 bot-box">
            <div class="columns is-vcentered">
                <div class="column is-half">
                    <h2 class="subtitle has-text-white">Your Persistent Storage</h2>
                </div>
                <div class="column is-half has-text-right">
                    <p class="has-text-white">
                        <span class="has-text-weight-bold has-text-white">Subscription Status:</span> 
                        <span class="tag is-medium 
                            <?php if ($subscription_status === 'Active'): ?>
                                is-success has-text-black
                            <?php elseif ($subscription_status === 'Suspended'): ?>
                                is-warning
                            <?php else: ?>
                                is-danger
                            <?php endif; ?>">
                            <?php echo htmlspecialchars($subscription_status); ?>
                        </span>
                    </p>
                    <?php if ($is_subscribed): ?>
                        <p class="has-text-white">
                            <span class="has-text-weight-bold has-text-white">Total Used Storage:</span> <?php echo $total_used_storage; ?> GB
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="notification is-info">
                <p class="has-text-weight-bold has-text-black">Persistent Storage Information</p>
                <p class="has-text-black">This storage keeps your files safe and accessible for as long as your subscription is active:</p>
                <ul style="list-style-type: disc; padding-left: 20px;">
                    <li class="has-text-black">Files uploaded here will not expire automatically.</li>
                    <li class="has-text-black">You can upload files from your temporary stream recordings.</li>
                    <li class="has-text-black">Manage your content with options to download, watch, or delete files.</li>
                </ul>
                <p class="has-text-black">Need to upload a new file from the streaming page? You can do so directly from your recorded streams.</p>
                <p class="has-text-black mt-2">
                    <a href="streaming.php" class="button is-primary">
                        <span class="icon"><i class="fas fa-video"></i></span>
                        <span>Go to Streaming</span>
                    </a>
                </p>
            </div>

            <?php if ($is_subscribed): ?>
            <div class="table-container">
                <table class="table is-fullwidth">
                    <thead>
                        <tr>
                            <th class="has-text-centered">File Name</th>
                            <th class="has-text-centered">Duration</th>
                            <th class="has-text-centered">Upload Date</th>
                            <th class="has-text-centered">Size</th>
                            <th class="has-text-centered">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($persistent_storage_error): ?>
                            <tr>
                                <td colspan="5" class="has-text-centered has-text-danger"><?php echo htmlspecialchars($persistent_storage_error); ?></td>
                            </tr>
                        <?php elseif (empty($persistent_storage_files)): ?>
                            <tr>
                                <td colspan="5" class="has-text-centered">No files found in persistent storage</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($persistent_storage_files as $file): ?>
                            <tr>
                                <td class="has-text-centered"><?php echo htmlspecialchars($file['name']); ?></td>
                                <td class="has-text-centered"><?php echo htmlspecialchars($file['duration']); ?></td>
                                <td class="has-text-centered"><?php echo htmlspecialchars($file['created_at']); ?></td>
                                <td class="has-text-centered"><?php echo htmlspecialchars($file['size']); ?></td>
                                <td class="has-text-centered">
                                    <a href="<?php echo $s3Client->getObjectUrl($username, $file['path']); ?>" class="action-icon" title="Download the video file" target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="?delete=<?php echo urlencode($file['path']); ?>" class="action-icon" title="Delete the video file" onclick="return confirm('Are you sure you want to delete this file? This action cannot be undone.');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <a href="#" class="play-video action-icon" data-video-url="play_stream.php?persistent=true&file=<?php echo urlencode($file['path']); ?>" title="Watch the video">
                                        <i class="fas fa-play"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="notification is-danger">
                <span class="is-size-4">
                    <p class="has-text-weight-bold has-text-black">Subscription <?php echo htmlspecialchars($subscription_status); ?></p>
                    <?php if (!empty($suspend_reason)): ?>
                        <p class="has-text-black">Reason: <span class="has-text-weight-bold"><?php echo htmlspecialchars($suspend_reason); ?></span></p>
                    <?php endif; ?>
                    <p class="has-text-black">Access to persistent storage files requires an active subscription.</p>
                    <p class="mt-3">
                        <a href="https://billing.botofthespecter.com" class="button is-primary">
                            <span class="icon"><i class="fas fa-credit-card"></i></span>
                            <span>Manage Subscription</span>
                        </a>
                    </p>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Video Modal -->
<div id="videoModal" class="modal">
    <div class="modal-background"></div>
    <button class="modal-close is-large" aria-label="close"></button>
    <div class="modal-content" style="width:1280px; height:720px;">
        <iframe id="videoFrame" style="width:100%; height:100%;" frameborder="0" allowfullscreen></iframe>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Video modal functionality
    var videoModal = document.getElementById('videoModal');
    var videoFrame = document.getElementById('videoFrame');
    
    document.querySelectorAll('.play-video').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('data-video-url');
            videoFrame.src = url;
            videoModal.classList.add('is-active');
        });
    });
    
    document.querySelector('#videoModal .modal-background').addEventListener('click', function() {
        videoModal.classList.remove('is-active');
        videoFrame.src = '';
    });
    
    document.querySelector('#videoModal .modal-close').addEventListener('click', function() {
        videoModal.classList.remove('is-active');
        videoFrame.src = '';
    });
});
</script>
</body>
</html>