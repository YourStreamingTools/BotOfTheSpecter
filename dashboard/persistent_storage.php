<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('persistent_storage_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
$billing_conn = new mysqli($servername, $username, $password, "fossbilling");
include_once "/var/www/config/ssh.php";
include "/var/www/config/object_storage.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check connection
if ($billing_conn->connect_error) {
    die("Billing connection failed: " . $billing_conn->connect_error);
}

// Check subscription status
$is_subscribed = false;
$is_canceled = false;
$subscription_status = 'Inactive';
$suspend_reason = null;
$canceled_at = null;
$deletion_time = null;
$has_billing_account = false;

// Get user email from the profile data
if (isset($email)) {
    // First, get the client ID from the client table
    $stmt = $billing_conn->prepare("SELECT id FROM client WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $client_id = $row['id'];
        $has_billing_account = true;
        // Check if the client has an active Persistent Storage Membership
        $stmt = $billing_conn->prepare("
            SELECT co.status, co.reason, co.canceled_at
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
            $is_canceled = ($row['status'] === 'canceled');
            $suspend_reason = $row['reason'] ?? '';
            // Handle canceled status and set deletion time
            if ($row['status'] === 'canceled' && !empty($row['canceled_at'])) {
                // The canceled_at time from billing system is in UTC
                $billing_utc_time = new DateTime($row['canceled_at'], new DateTimeZone('UTC'));
                // Convert UTC time to user's local timezone for display purposes
                $local_canceled_time = clone $billing_utc_time;
                $local_canceled_time->setTimezone(new DateTimeZone($timezone));
                $canceled_at = $local_canceled_time->getTimestamp();
                // Calculate deletion time (24 hours after cancellation), maintaining UTC for consistency
                $deletion_utc_time = clone $billing_utc_time;
                $deletion_utc_time->modify('+24 hours');
                // Convert deletion time to user's local timezone for display countdown
                $local_deletion_time = clone $deletion_utc_time;
                $local_deletion_time->setTimezone(new DateTimeZone($timezone));
                $deletion_time = $local_deletion_time->getTimestamp();
            }
        }
    }
    $stmt->close();
}
$billing_conn->close();

// Include S3-compatible API library
require_once '/var/www/vendor/aws-autoloader.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Initialize S3 client and fetch files only if the user has an active subscription or canceled subscription
$persistent_storage_files = [];
$persistent_storage_error = null;
$total_used_storage = 0; // Initialize total used storage

// Only initialize S3 client and attempt operations if user has an active or canceled subscription
if ($is_subscribed || $is_canceled) {
    // Check for cookie consent to determine preferred server region
    $cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';
    // Server selection handling (default to AU)
    $selected_server = isset($_GET['server']) ? $_GET['server'] : ($cookieConsent && isset($_COOKIE['selectedPersistentServer']) ? $_COOKIE['selectedPersistentServer'] : 'australia');
    // Set the cookie if the server is selected from the dropdown
    if (isset($_GET['server']) && $cookieConsent) { setcookie('selectedPersistentServer', $_GET['server'], time() + (86400 * 30), "/"); } // Cookie for 30 days
    // Define bucket names and S3 configuration based on selected region
    if ($selected_server == 'australia') {
        $bucket_name = 'botofthespecter-au-persistent';
        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => "https://" . $au_s3_bucket_url,
            'credentials' => ['key' => $au_s3_access_key,'secret' => $au_s3_secret_key]
        ]);
    } else {
        // USA servers
        $bucket_name = 'botofthespecter-us-persistent';
        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => "https://" . $us_s3_bucket_url,
            'credentials' => ['key' => $us_s3_access_key,'secret' => $us_s3_secret_key]
        ]);
    }
    // Function to fetch files from S3 bucket
    function getS3Files($bucketName, $userFolder) {
        global $s3Client;
        $files = [];
        try {
            $result = $s3Client->listObjectsV2(['Bucket' => $bucketName,'Prefix' => $userFolder . '/']);
            if (!empty($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $key = $object['Key'];
                    // Skip placeholder files and folder markers
                    if (basename($key) === '.placeholder' || substr($key, -1) === '/') { continue; }
                    // Only show actual content files (videos, etc.)
                    $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['mp4', 'avi', 'mov', 'mkv', 'flv', 'webm', 'm4v'])) { continue; }
                    $sizeBytes = $object['Size'];
                    $lastModified = $object['LastModified']->getTimestamp();
                    // Format file size
                    $size = $sizeBytes < 1024 * 1024 ? round($sizeBytes / 1024, 2) . ' KB' :
                        ($sizeBytes < 1024 * 1024 * 1024 ? round($sizeBytes / (1024 * 1024), 2) . ' MB' :
                        round($sizeBytes / (1024 * 1024 * 1024), 2) . ' GB');
                    // Format creation date
                    $createdAt = date('d-m-Y H:i:s', $lastModified);
                    $files[] = ['name' => pathinfo(basename($key), PATHINFO_FILENAME),'size' => $size,'created_at' => $createdAt,'path' => $key];
                }
            }
        } catch (AwsException $e) { return ['error' => $e->getMessage()]; }
        return $files;
    }
    // Function to create user folder in S3 bucket
    function createUserFolder($bucketName, $userFolder) {
        global $s3Client;
        try {
            // Create a placeholder file to establish the folder structure
            $s3Client->putObject(['Bucket' => $bucketName,'Key' => $userFolder . '/.placeholder','Body' => 'This folder belongs to: ' . $userFolder,'ContentType' => 'text/plain']);
            return true;
        } catch (AwsException $e) { return false; }
    }
    // Function to check if user folder exists
    function userFolderExists($bucketName, $userFolder) {
        global $s3Client;
        try {
            $result = $s3Client->listObjectsV2(['Bucket' => $bucketName,'Prefix' => $userFolder . '/','MaxKeys' => 1]);
            return !empty($result['Contents']);
        } catch (AwsException $e) { return false; }
    }
    // Function to get files for storage calculation (separate from display function)
    function getS3FilesForStorage($bucketName, $userFolder, $s3ClientInstance) {
        $files = [];
        try {
            $result = $s3ClientInstance->listObjectsV2(['Bucket' => $bucketName,'Prefix' => $userFolder . '/']);
            if (!empty($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $key = $object['Key'];
                    // Skip placeholder files and folder markers
                    if (basename($key) === '.placeholder' || substr($key, -1) === '/') { continue; }
                    // Only count actual content files (videos, etc.)
                    $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['mp4', 'avi', 'mov', 'mkv', 'flv', 'webm', 'm4v'])) { continue; }
                    $sizeBytes = $object['Size'];
                    // Format file size
                    $size = $sizeBytes < 1024 * 1024 ? round($sizeBytes / 1024, 2) . ' KB' :
                        ($sizeBytes < 1024 * 1024 * 1024 ? round($sizeBytes / (1024 * 1024), 2) . ' MB' :
                        round($sizeBytes / (1024 * 1024 * 1024), 2) . ' GB');
                    $files[] = ['size' => $size];
                }
            }
        } catch (AwsException $e) { return ['error' => $e->getMessage()]; }
        return $files;
    }
    // Check if user folder exists and create it if it doesn't (only for active subscribers)
    if ($is_subscribed && !userFolderExists($bucket_name, $username)) {
        $folder_created = createUserFolder($bucket_name, $username);
        if ($folder_created) {
            $_SESSION['folder_created'] = true;
        }
    }
    // Also ensure folder exists for canceled subscriptions (for access before deletion)
    if ($is_canceled && !userFolderExists($bucket_name, $username)) {
        $folder_created = createUserFolder($bucket_name, $username);
        if ($folder_created) {
            $_SESSION['folder_created'] = true;
        }
    }
    // Fetch persistent storage files
    $result = getS3Files($bucket_name, $username);
    if (isset($result['error'])) {
        $persistent_storage_error = "Persistent storage is not available at the moment. Please try again later.";
    } else {
        $persistent_storage_files = $result;
        // Calculate total used storage from BOTH regions (AU and US)
        $total_used_storage = 0;
        // Get files from Australia region
        $au_s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => "https://" . $au_s3_bucket_url,
            'credentials' => ['key' => $au_s3_access_key,'secret' => $au_s3_secret_key]
        ]);
        $au_result = getS3FilesForStorage('botofthespecter-au-persistent', $username, $au_s3Client);
        // Get files from USA region
        $us_s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => "https://" . $us_s3_bucket_url,
            'credentials' => ['key' => $us_s3_access_key,'secret' => $us_s3_secret_key]
        ]);
        $us_result = getS3FilesForStorage('botofthespecter-us-persistent', $username, $us_s3Client);
        // Combine storage from both regions
        $all_files = [];
        if (!isset($au_result['error'])) { $all_files = array_merge($all_files, $au_result); }
        if (!isset($us_result['error'])) { $all_files = array_merge($all_files, $us_result); }
        // Calculate total used storage from all files
        foreach ($all_files as $file) {
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
            $s3Client->deleteObject(['Bucket' => $bucket_name,'Key' => $fileToDelete]);
            $_SESSION['delete_success'] = true;
            header('Location: persistent_storage.php?server=' . $selected_server);
            exit();
        } catch (AwsException $e) {
            $delete_error = "Error deleting file: " . $e->getMessage();
        }
    }
}

// Start output buffering for layout
ob_start();
?>
<h1 class="title"><?php echo t('persistent_storage_title'); ?></h1>
<?php if (isset($_SESSION['delete_success'])): ?>
    <?php unset($_SESSION['delete_success']); ?>
    <div class="notification is-success">
        <?php echo t('persistent_storage_file_deleted_success'); ?>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['folder_created'])): ?>
    <?php unset($_SESSION['folder_created']); ?>
    <div class="notification is-success">
        Your persistent storage folder has been created successfully! You can now upload files to it.
    </div>
<?php endif; ?>
<?php if (isset($delete_error)): ?>
    <div class="notification is-danger">
        <?php echo htmlspecialchars($delete_error); ?>
    </div>
<?php endif; ?>
<div class="columns is-desktop is-multiline is-centered box-container">
    <div class="column is-10 bot-box">
        <div class="notification is-info" style="position: relative;">
            <div style="position: absolute; top: 10px; right: 10px; text-align: right;">
                <p class="has-text-black">
                    <span class="has-text-weight-bold has-text-black"><?php echo t('persistent_storage_subscription_status'); ?></span> 
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
                <?php if ($is_subscribed): ?>                    <p class="has-text-black">
                        <span class="has-text-weight-bold has-text-black">
                            <?php echo t('persistent_storage_total_used'); ?>
                        </span> 
                        <?php echo $total_used_storage; ?> GB
                    </p>
                    <p class="has-text-black mt-2">
                        <button type="button" class="button is-primary is-rounded billing-btn">
                            <span class="icon"><i class="fas fa-cog"></i></span>
                            <span><?php echo t('persistent_storage_manage_subscription'); ?></span>
                        </button>
                    </p>
                <?php endif; ?>
            </div>
            <div style="position:absolute; right:1.5rem; bottom:1.5rem; z-index:2;">
                <form method="get" id="server-selection-form">
                    <div class="field is-grouped is-align-items-center mb-0">
                        <label class="label mr-2 mb-0 has-text-black"><?= t('streaming_server_label') ?></label>
                        <div class="control">
                            <div class="select">
                                <select id="server-location" name="server" onchange="document.getElementById('server-selection-form').submit();">
                                    <option value="australia" <?php echo $selected_server == 'australia' ? 'selected' : ''; ?>>Australia</option>
                                    <option value="usa" <?php echo $selected_server == 'usa' ? 'selected' : ''; ?>>USA</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <p class="has-text-weight-bold has-text-black"><?php echo t('persistent_storage_info_title'); ?></p>
            <p class="has-text-black"><?php echo t('persistent_storage_info_desc'); ?></p>
            <ul style="list-style-type: disc; padding-left: 20px;">
                <li class="has-text-black"><?php echo t('persistent_storage_info_no_expiry'); ?></li>
                <li class="has-text-black"><?php echo t('persistent_storage_info_upload_from_stream'); ?></li>
                <li class="has-text-black"><?php echo t('persistent_storage_info_manage_content'); ?></li>
            </ul>
            <!-- Important warning about upload location -->
            <div class="notification is-warning mt-4 mb-4">
                <p class="has-text-weight-bold has-text-black">
                    <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                    Important Notice:
                </p>
                <p class="has-text-black">
                    Files are automatically uploaded to the persistent storage region that matches your streaming server location. 
                    If you stream to AU servers, files go to Australia persistent storage. If you stream to US servers, files go to USA persistent storage.
                    Use the dropdown below to view files from different regions.
                </p>
            </div>
            <p class="has-text-black"><?php echo t('persistent_storage_info_upload_hint'); ?></p>
            <p class="has-text-black mt-2">
                <a href="streaming.php" class="button is-primary is-rounded">
                    <span class="icon"><i class="fas fa-video"></i></span>
                    <span><?php echo t('persistent_storage_go_to_streaming'); ?></span>
                </a>
            </p>
        </div>
        <?php if (!$is_subscribed && !$is_canceled): ?>
        <div class="notification is-danger mb-5">
            <span class="is-size-4">
                <p class="has-text-weight-bold has-text-black"><?php echo t('persistent_storage_subscription'); ?> <?php echo htmlspecialchars($subscription_status); ?></p>
                <?php if (!$is_subscribed && !$is_canceled && !$has_billing_account): ?>
                    <div class="notification is-warning mt-3 mb-3">
                        <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                        <span class="has-text-weight-bold">Signups in our billing panel are currently unavailable. Please check back later or contact support for more information.</span>
                    </div>
                <?php endif; ?>
                <?php if (strtolower($subscription_status) === 'canceled'): ?>
                    <?php if (!empty($suspend_reason)): ?>
                        <p class="has-text-black">
                            <?php echo t('persistent_storage_cancellation_reason'); ?> 
                            <span class="has-text-weight-bold">
                                <?php echo htmlspecialchars($suspend_reason); ?>
                            </span>
                        </p>
                    <?php endif; ?>
                    <?php if ($deletion_time): ?>
                        <p class="has-text-black">
                            <?php echo t('persistent_storage_files_deleted'); ?> 
                            <span id="deletion-countdown" class="has-text-weight-bold" data-deletion-time="<?php echo $deletion_time; ?>">
                                <?php echo t('persistent_storage_in_24_hours'); ?>
                            </span>
                        </p>
                    <?php endif; ?>
                    <p class="mt-3">
                        <button type="button" class="button is-warning billing-btn" disabled style="pointer-events: none; opacity: 0.6;">
                            <span class="icon"><i class="fas fa-undo"></i></span>
                            <span><?php echo t('persistent_storage_reactivate_subscription'); ?></span>
                        </button>
                    </p>
                <?php elseif (strtolower($subscription_status) === 'suspended'): ?>
                    <?php if (!empty($suspend_reason)): ?>
                        <p class="has-text-black">
                            <?php echo t('persistent_storage_reason'); ?> 
                            <span class="has-text-weight-bold">
                                <?php echo htmlspecialchars($suspend_reason); ?>
                            </span>
                        </p>
                    <?php endif; ?>
                    <p class="has-text-black">
                        <?php echo t('persistent_storage_suspended_notice'); ?>
                    </p>
                    <p class="mt-3">
                        <button type="button" class="button is-warning billing-btn" disabled style="pointer-events: none; opacity: 0.6;">
                            <span class="icon"><i class="fas fa-credit-card"></i></span>
                            <span><?php echo t('persistent_storage_pay_invoice'); ?></span>
                        </button>
                    </p>
                <?php else: ?>
                    <p class="has-text-black"><?php echo t('persistent_storage_requires_active'); ?></p>
                    <p class="mt-3">
                        <button type="button" class="button is-primary billing-btn" disabled style="pointer-events: none; opacity: 0.6;">
                            <span class="icon"><i class="fas fa-shopping-cart"></i></span>
                            <span><?php echo t('persistent_storage_subscribe'); ?></span>
                        </button>
                    </p>
                    <?php if (!$has_billing_account): ?>
                    <div class="mt-3 has-text-black">
                        <p><?php echo t('persistent_storage_billing_email_notice'); ?></p>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>
        <div class="table-container">
            <table class="table is-fullwidth">
                <thead>
                    <tr>
                        <th class="has-text-centered"><?php echo t('persistent_storage_table_file_name'); ?></th>
                        <th class="has-text-centered"><?php echo t('persistent_storage_table_upload_date'); ?></th>
                        <th class="has-text-centered"><?php echo t('persistent_storage_table_size'); ?></th>
                        <th class="has-text-centered"><?php echo t('persistent_storage_table_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($persistent_storage_error): ?>
                        <tr>
                            <td colspan="4" class="has-text-centered has-text-danger"><?php echo htmlspecialchars($persistent_storage_error); ?></td>
                        </tr>
                    <?php elseif (empty($persistent_storage_files)): ?>
                        <tr>
                            <td colspan="4" class="has-text-centered"><?php echo t('persistent_storage_no_files'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($persistent_storage_files as $file): ?>
                        <tr>
                            <td class="has-text-centered"><?php echo htmlspecialchars($file['name']); ?></td>
                            <td class="has-text-centered"><?php echo htmlspecialchars($file['created_at']); ?></td>
                            <td class="has-text-centered"><?php echo htmlspecialchars($file['size']); ?></td>
                            <td class="has-text-centered">
                                <a href="<?php echo $s3Client->getObjectUrl($bucket_name, $file['path']); ?>" class="action-icon" title="<?php echo t('streaming_action_download_video'); ?>" target="_blank">
                                    <i class="fas fa-download"></i>
                                </a>
                                <a href="?delete=<?php echo urlencode($file['path']); ?>&server=<?php echo urlencode($selected_server); ?>" class="action-icon" title="<?php echo t('streaming_action_delete_video'); ?>" onclick="return confirm('<?php echo t('persistent_storage_confirm_delete'); ?>');">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <a href="#" class="play-video action-icon" data-video-url="play_stream.php?persistent=true&server=<?php echo urlencode($selected_server); ?>&file=<?php echo urlencode($file['path']); ?>" title="<?php echo t('streaming_action_watch_video'); ?>">
                                    <i class="fas fa-play"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Video Modal -->
<div id="videoModal" class="modal">
    <div class="modal-background"></div>
    <button class="modal-close is-large" aria-label="close"></button>
    <div class="modal-content" style="width:100%; max-width:900px; min-width:320px;">
        <div id="customPlayerContainer" style="background:#181c24; border-radius:12px; box-shadow:0 4px 32px rgba(0,0,0,0.4); padding:24px; display:flex; flex-direction:column; align-items:center;">
            <video id="customVideoPlayer" style="width:100%; max-width:800px; border-radius:8px; background:#000; outline:none;" controls poster="/cdn/BotOfTheSpecter.png">
                <source id="customVideoSource" src="" type="video/mp4">
                Your browser does not support the video tag.
            </video>
            <div style="margin-top:12px; text-align:center;">
                <span style="color:#fff; font-weight:bold; font-size:1.1em;">BotOfTheSpecter Video Player</span>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Video modal functionality
    var videoModal = document.getElementById('videoModal');
    var customVideoPlayer = document.getElementById('customVideoPlayer');
    var customVideoSource = document.getElementById('customVideoSource');
    document.querySelectorAll('.play-video').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('data-video-url');
            // Set the video source and load
            customVideoSource.src = url;
            customVideoPlayer.load();
            videoModal.classList.add('is-active');
        });
    });
    document.querySelector('#videoModal .modal-background').addEventListener('click', function() {
        videoModal.classList.remove('is-active');
        customVideoPlayer.pause();
        customVideoSource.src = '';
        customVideoPlayer.load();
    });
    document.querySelector('#videoModal .modal-close').addEventListener('click', function() {
        videoModal.classList.remove('is-active');
        customVideoPlayer.pause();
        customVideoSource.src = '';
        customVideoPlayer.load();
    });
    // Deletion countdown timer functionality
    var countdownElement = document.getElementById('deletion-countdown');
    if (countdownElement) {
        var deletionTime = parseInt(countdownElement.getAttribute('data-deletion-time')) * 1000;
        function updateCountdown() {
            var now = new Date().getTime();
            var timeLeft = deletionTime - now;
            if (timeLeft <= 0) {
                countdownElement.innerHTML = "<?php echo t('persistent_storage_imminent'); ?>";
                return;
            }
            // Calculate time units
            var hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
            // Create countdown string
            var countdownStr = "";
            if (hours > 0) {
                countdownStr += hours + " <?php echo t('persistent_storage_hours'); ?> ";
            }
            countdownStr += minutes + " <?php echo t('persistent_storage_minutes'); ?> ";
            countdownStr += seconds + " <?php echo t('persistent_storage_seconds'); ?>";
            countdownElement.innerHTML = "<?php echo t('persistent_storage_in'); ?> " + countdownStr;
        }
        // Update countdown immediately and then every second
        updateCountdown();
        setInterval(updateCountdown, 1000);
    }
    // Billing buttons functionality - handles all billing buttons with class 'billing-btn'
    const billingButtons = document.querySelectorAll('.billing-btn');
    billingButtons.forEach(function(button) {
        console.log('Billing button found, disabled state:', button.disabled);
        button.addEventListener('click', function(e) {
            console.log('Billing button clicked, disabled:', this.disabled, 'hasAttribute:', this.hasAttribute('disabled'));
            if (this.disabled || this.hasAttribute('disabled')) {
                console.log('Billing button is disabled, preventing action');
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                return false;
            }
            console.log('Opening billing window');
            window.open('https://billing.botofthespecter.com', '_blank');
        });
    });
});
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>