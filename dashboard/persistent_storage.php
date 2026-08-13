<?php
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('persistent_storage_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';
// Server selection handling (default to AU)
$selected_server = isset($_GET['server']) ? $_GET['server'] : ($cookieConsent && isset($_COOKIE['selectedPersistentServer']) ? $_COOKIE['selectedPersistentServer'] : 'australia');
if ($selected_server !== 'usa') {
    $selected_server = 'australia';
}
// Set the cookie if the server is selected from the dropdown
if (isset($_GET['server']) && $cookieConsent) {
    setcookie('selectedPersistentServer', $selected_server, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function persistent_storage_timezone(mysqli $db): string
{
    $timezone = 'UTC';
    $stmt = $db->prepare("SELECT timezone FROM profile");
    if ($stmt) {
        $stmt->execute();
        $channelData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $timezone = $channelData['timezone'] ?? 'UTC';
    }
    date_default_timezone_set($timezone);
    return $timezone;
}

function persistent_storage_s3_setup(string $selected_server): array
{
    include_once "/var/www/config/object_storage.php";
    require_once '/var/www/vendor/aws-autoloader.php';
    global $au_s3_bucket_url, $au_s3_access_key, $au_s3_secret_key;
    global $us_s3_bucket_url, $us_s3_access_key, $us_s3_secret_key;
    if ($selected_server === 'usa') {
        return [
            'bucket' => 'botofthespecter-us-persistent',
            'client' => new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => "https://" . $us_s3_bucket_url,
                'credentials' => ['key' => $us_s3_access_key, 'secret' => $us_s3_secret_key],
            ]),
        ];
    }
    return [
        'bucket' => 'botofthespecter-au-persistent',
        'client' => new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => "https://" . $au_s3_bucket_url,
            'credentials' => ['key' => $au_s3_access_key, 'secret' => $au_s3_secret_key],
        ]),
    ];
}

function persistent_storage_format_size($sizeBytes): string
{
    return $sizeBytes < 1024 * 1024 ? round($sizeBytes / 1024, 2) . ' KB' :
        ($sizeBytes < 1024 * 1024 * 1024 ? round($sizeBytes / (1024 * 1024), 2) . ' MB' :
        round($sizeBytes / (1024 * 1024 * 1024), 2) . ' GB');
}

function persistent_storage_size_to_bytes(string $size): float
{
    if (strpos($size, 'KB') !== false) {
        return floatval($size) * 1024;
    }
    if (strpos($size, 'MB') !== false) {
        return floatval($size) * 1024 * 1024;
    }
    if (strpos($size, 'GB') !== false) {
        return floatval($size) * 1024 * 1024 * 1024;
    }
    return 0.0;
}

function getS3Files($bucketName, $userFolder, $s3Client)
{
    $files = [];
    try {
        $result = $s3Client->listObjectsV2(['Bucket' => $bucketName, 'Prefix' => $userFolder . '/']);
        if (!empty($result['Contents'])) {
            foreach ($result['Contents'] as $object) {
                $key = $object['Key'];
                if (basename($key) === '.placeholder' || substr($key, -1) === '/') {
                    continue;
                }
                $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                if (!in_array($extension, ['mp4', 'avi', 'mov', 'mkv', 'flv', 'webm', 'm4v'])) {
                    continue;
                }
                $sizeBytes = $object['Size'];
                $lastModified = $object['LastModified']->getTimestamp();
                $files[] = [
                    'name' => pathinfo(basename($key), PATHINFO_FILENAME),
                    'size' => persistent_storage_format_size($sizeBytes),
                    'created_at' => date('d-m-Y H:i:s', $lastModified),
                    'path' => $key,
                    'download_url' => $s3Client->getObjectUrl($bucketName, $key),
                ];
            }
        }
    } catch (AwsException $e) {
        return ['error' => $e->getMessage()];
    }
    return $files;
}

function createUserFolder($bucketName, $userFolder, $s3Client)
{
    try {
        if (empty($userFolder)) {
            error_log('[S3 DEBUG] Username/userFolder is empty, cannot create folder.');
            return false;
        }
        $result = $s3Client->putObject([
            'Bucket' => $bucketName,
            'Key' => $userFolder . '/.placeholder',
            'Body' => 'This folder belongs to: ' . $userFolder,
            'ContentType' => 'text/plain',
        ]);
        error_log('[S3 DEBUG] putObject result: ' . print_r($result, true));
        return true;
    } catch (AwsException $e) {
        error_log('[S3 DEBUG] putObject exception: ' . $e->getMessage());
        return false;
    }
}

function userFolderExists($bucketName, $userFolder, $s3Client)
{
    try {
        $result = $s3Client->listObjectsV2(['Bucket' => $bucketName, 'Prefix' => $userFolder . '/', 'MaxKeys' => 1]);
        return !empty($result['Contents']);
    } catch (AwsException $e) {
        return false;
    }
}

function getS3FilesForStorage($bucketName, $userFolder, $s3ClientInstance)
{
    $files = [];
    try {
        $result = $s3ClientInstance->listObjectsV2(['Bucket' => $bucketName, 'Prefix' => $userFolder . '/']);
        if (!empty($result['Contents'])) {
            foreach ($result['Contents'] as $object) {
                $key = $object['Key'];
                if (basename($key) === '.placeholder' || substr($key, -1) === '/') {
                    continue;
                }
                $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                if (!in_array($extension, ['mp4', 'avi', 'mov', 'mkv', 'flv', 'webm', 'm4v'])) {
                    continue;
                }
                $files[] = ['size' => persistent_storage_format_size($object['Size'])];
            }
        }
    } catch (AwsException $e) {
        return ['error' => $e->getMessage()];
    }
    return $files;
}

function persistent_storage_billing_status($email, $timezone): array
{
    global $db_servername, $db_username, $db_password;
    $out = [
        'is_subscribed' => false,
        'is_canceled' => false,
        'subscription_status' => 'Inactive',
        'suspend_reason' => '',
        'canceled_at' => null,
        'deletion_time' => null,
        'has_billing_account' => false,
    ];
    if ($email === null || $email === '') {
        return $out;
    }
    $billing_conn = new mysqli($db_servername, $db_username, $db_password, "fossbilling");
    if ($billing_conn->connect_error) {
        throw new RuntimeException("Billing connection failed: " . $billing_conn->connect_error);
    }
    $stmt = $billing_conn->prepare("SELECT id FROM client WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $client_id = $row['id'];
        $out['has_billing_account'] = true;
        $stmt->close();
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
            $out['subscription_status'] = ucfirst($row['status']);
            $out['is_subscribed'] = ($row['status'] === 'active');
            $out['is_canceled'] = ($row['status'] === 'canceled');
            $out['suspend_reason'] = $row['reason'] ?? '';
            if ($row['status'] === 'canceled' && !empty($row['canceled_at'])) {
                $billing_utc_time = new DateTime($row['canceled_at'], new DateTimeZone('UTC'));
                $local_canceled_time = clone $billing_utc_time;
                $local_canceled_time->setTimezone(new DateTimeZone($timezone));
                $out['canceled_at'] = $local_canceled_time->getTimestamp();
                $deletion_utc_time = clone $billing_utc_time;
                $deletion_utc_time->modify('+24 hours');
                $local_deletion_time = clone $deletion_utc_time;
                $local_deletion_time->setTimezone(new DateTimeZone($timezone));
                $out['deletion_time'] = $local_deletion_time->getTimestamp();
            }
        }
    }
    $stmt->close();
    $billing_conn->close();
    return $out;
}

function persistent_storage_total_used($username): float
{
    include_once "/var/www/config/object_storage.php";
    require_once '/var/www/vendor/aws-autoloader.php';
    global $au_s3_bucket_url, $au_s3_access_key, $au_s3_secret_key;
    global $us_s3_bucket_url, $us_s3_access_key, $us_s3_secret_key;
    $au_s3Client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => "https://" . $au_s3_bucket_url,
        'credentials' => ['key' => $au_s3_access_key, 'secret' => $au_s3_secret_key],
    ]);
    $us_s3Client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => "https://" . $us_s3_bucket_url,
        'credentials' => ['key' => $us_s3_access_key, 'secret' => $us_s3_secret_key],
    ]);
    $au_result = getS3FilesForStorage('botofthespecter-au-persistent', $username, $au_s3Client);
    $us_result = getS3FilesForStorage('botofthespecter-us-persistent', $username, $us_s3Client);
    $all_files = [];
    if (!isset($au_result['error'])) {
        $all_files = array_merge($all_files, $au_result);
    }
    if (!isset($us_result['error'])) {
        $all_files = array_merge($all_files, $us_result);
    }
    $total_used_storage = 0;
    foreach ($all_files as $file) {
        if (isset($file['size'])) {
            $total_used_storage += persistent_storage_size_to_bytes($file['size']);
        }
    }
    return round($total_used_storage / (1024 * 1024 * 1024), 2);
}

// List endpoint first so the browser can paint skeletons, then fetch files/usage.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        $timezone = persistent_storage_timezone($db);
        $billing = persistent_storage_billing_status($email ?? '', $timezone);
        $setup = persistent_storage_s3_setup($selected_server);
        $s3Client = $setup['client'];
        $bucket_name = $setup['bucket'];
        $folder_created = false;
        if (!empty($username) && (!userFolderExists($bucket_name, $username, $s3Client))) {
            $folder_created = (bool) createUserFolder($bucket_name, $username, $s3Client);
        }
        $result = getS3Files($bucket_name, $username, $s3Client);
        $persistent_storage_error = null;
        $persistent_storage_files = [];
        $total_used_storage = 0;
        if (isset($result['error'])) {
            $persistent_storage_error = "Persistent storage is not available at the moment. Please try again later.";
        } else {
            $persistent_storage_files = $result;
            $total_used_storage = persistent_storage_total_used($username);
        }
        echo json_encode([
            'success' => true,
            'server' => $selected_server,
            'files' => $persistent_storage_files,
            'error' => $persistent_storage_error,
            'total_used_storage' => $total_used_storage,
            'folder_created' => $folder_created,
            'subscription' => $billing,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle file deletion if requested
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $fileToDelete = $_GET['delete'];
    try {
        $setup = persistent_storage_s3_setup($selected_server);
        $setup['client']->deleteObject(['Bucket' => $setup['bucket'], 'Key' => $fileToDelete]);
        session_start();
        $_SESSION['delete_success'] = true;
        session_write_close();
        header('Location: persistent_storage.php?server=' . urlencode($selected_server));
        exit();
    } catch (AwsException $e) {
        $delete_error = "Error deleting file: " . $e->getMessage();
    }
}

$deleteSuccess = false;
if (!empty($_SESSION['delete_success'])) {
    $deleteSuccess = true;
    session_start();
    unset($_SESSION['delete_success']);
    session_write_close();
}

// Start output buffering for layout
ob_start();
?>
<h1 class="title"><?php echo t('persistent_storage_title'); ?></h1>
<div class="notification is-danger is-light mb-4" style="max-width: 600px; margin: 50px auto;">
    <div class="is-flex is-align-items-center">
        <span class="icon mr-3"><i class="fas fa-exclamation-circle"></i></span>
        <div>
            <p class="has-text-weight-bold"><?php echo t('persistent_storage_terminated_title'); ?></p>
            <p class="mt-2"><?php echo t('persistent_storage_terminated_desc'); ?></p>
        </div>
    </div>
</div>
<div id="ps-flash-host">
<?php if ($deleteSuccess): ?>
    <div class="notification is-success">
        <?php echo t('persistent_storage_file_deleted_success'); ?>
    </div>
<?php endif; ?>
<?php if (isset($delete_error)): ?>
    <div class="notification is-danger">
        <?php echo htmlspecialchars($delete_error); ?>
    </div>
<?php endif; ?>
</div>
<div class="columns is-desktop is-multiline is-centered box-container" id="ps-main" aria-busy="true">
    <div class="column is-10 bot-box">
        <div class="notification is-info" style="position: relative;">
            <div style="position: absolute; top: 10px; right: 10px; text-align: right;">
                <p class="has-text-black">
                    <span class="has-text-weight-bold has-text-black"><?php echo t('persistent_storage_subscription_status'); ?></span>
                    <span id="ps-status-tag" class="tag is-medium"><span class="sp-skeleton-badge" aria-hidden="true"></span></span>
                </p>
                <div id="ps-usage-host">
                    <p class="has-text-black" id="ps-usage-row">
                        <span class="has-text-weight-bold has-text-black"><?php echo t('persistent_storage_total_used'); ?></span>
                        <span id="ps-usage-value"><span class="sp-skeleton-line w-40" aria-hidden="true"></span></span>
                    </p>
                    <p class="has-text-black mt-2" id="ps-manage-row" hidden>
                        <button type="button" class="button is-primary is-rounded billing-btn">
                            <span class="icon"><i class="fas fa-cog"></i></span>
                            <span><?php echo t('persistent_storage_manage_subscription'); ?></span>
                        </button>
                    </p>
                </div>
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
        <div id="ps-sub-notice" class="notification is-danger mb-5" hidden></div>
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
                <tbody id="ps-files-body" aria-busy="true">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                        <td class="has-text-centered"><span class="sp-skeleton-line w-70"></span></td>
                        <td class="has-text-centered"><span class="sp-skeleton-line w-50"></span></td>
                        <td class="has-text-centered"><span class="sp-skeleton-line w-40"></span></td>
                        <td class="has-text-centered"><span class="sp-skeleton-badge"></span></td>
                    </tr>
                    <?php endfor; ?>
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
    var PS_I18N = {
        noFiles: <?php echo json_encode(t('persistent_storage_no_files')); ?>,
        loadError: <?php echo json_encode('Persistent storage is not available at the moment. Please try again later.'); ?>,
        confirmDelete: <?php echo json_encode(t('persistent_storage_confirm_delete')); ?>,
        download: <?php echo json_encode(t('streaming_action_download_video')); ?>,
        deleteTitle: <?php echo json_encode(t('streaming_action_delete_video')); ?>,
        watch: <?php echo json_encode(t('streaming_action_watch_video')); ?>,
        folderCreated: <?php echo json_encode('Your persistent storage folder has been created successfully! You can now upload files to it.'); ?>,
        cancellationReason: <?php echo json_encode(t('persistent_storage_cancellation_reason')); ?>,
        filesDeleted: <?php echo json_encode(t('persistent_storage_files_deleted')); ?>,
        in24Hours: <?php echo json_encode(t('persistent_storage_in_24_hours')); ?>,
        reactivate: <?php echo json_encode(t('persistent_storage_reactivate_subscription')); ?>,
        reason: <?php echo json_encode(t('persistent_storage_reason')); ?>,
        suspendedNotice: <?php echo json_encode(t('persistent_storage_suspended_notice')); ?>,
        payInvoice: <?php echo json_encode(t('persistent_storage_pay_invoice')); ?>,
        requiresActive: <?php echo json_encode(t('persistent_storage_requires_active')); ?>,
        subscribe: <?php echo json_encode(t('persistent_storage_subscribe')); ?>,
        billingEmailNotice: <?php echo json_encode(t('persistent_storage_billing_email_notice')); ?>,
        subscriptionLabel: <?php echo json_encode(t('persistent_storage_subscription')); ?>,
        signupsUnavailable: <?php echo json_encode('Signups in our billing panel are currently unavailable. Please check back later or contact support for more information.'); ?>,
        imminent: <?php echo json_encode(t('persistent_storage_imminent')); ?>,
        inPrefix: <?php echo json_encode(t('persistent_storage_in')); ?>,
        hours: <?php echo json_encode(t('persistent_storage_hours')); ?>,
        minutes: <?php echo json_encode(t('persistent_storage_minutes')); ?>,
        seconds: <?php echo json_encode(t('persistent_storage_seconds')); ?>
    };
    var selectedServer = <?php echo json_encode($selected_server); ?>;

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function setBusy(busy) {
        var main = document.getElementById('ps-main');
        var tbody = document.getElementById('ps-files-body');
        if (main) main.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (tbody) tbody.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function bindBillingButtons() {
        document.querySelectorAll('.billing-btn').forEach(function(button) {
            button.addEventListener('click', function(e) {
                if (this.disabled || this.hasAttribute('disabled')) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }
                window.open('https://billing.botofthespecter.com', '_blank');
            });
        });
    }

    function startDeletionCountdown(el) {
        if (!el) return;
        var deletionTime = parseInt(el.getAttribute('data-deletion-time'), 10) * 1000;
        function updateCountdown() {
            var timeLeft = deletionTime - Date.now();
            if (timeLeft <= 0) {
                el.textContent = PS_I18N.imminent;
                return;
            }
            var hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
            var countdownStr = '';
            if (hours > 0) countdownStr += hours + ' ' + PS_I18N.hours + ' ';
            countdownStr += minutes + ' ' + PS_I18N.minutes + ' ' + seconds + ' ' + PS_I18N.seconds;
            el.textContent = PS_I18N.inPrefix + ' ' + countdownStr;
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    }

    function renderSubscription(sub) {
        var tag = document.getElementById('ps-status-tag');
        var usageRow = document.getElementById('ps-usage-row');
        var usageValue = document.getElementById('ps-usage-value');
        var manageRow = document.getElementById('ps-manage-row');
        var notice = document.getElementById('ps-sub-notice');
        if (!sub) return;
        var status = sub.subscription_status || 'Inactive';
        var statusClass = 'is-danger';
        if (status === 'Active') statusClass = 'is-success has-text-black';
        else if (status === 'Suspended') statusClass = 'is-warning';
        if (tag) {
            tag.className = 'tag is-medium ' + statusClass;
            tag.textContent = status;
        }
        if (sub.is_subscribed) {
            if (usageRow) usageRow.hidden = false;
            if (manageRow) manageRow.hidden = false;
        } else {
            if (usageRow) usageRow.hidden = true;
            if (manageRow) manageRow.hidden = true;
        }
        if (usageValue && sub.is_subscribed) {
            usageValue.textContent = String(sub.total_used_storage) + ' GB';
        }
        if (!notice) return;
        if (sub.is_subscribed || sub.is_canceled) {
            notice.hidden = true;
            notice.innerHTML = '';
            return;
        }
        var html = '<span class="is-size-4"><p class="has-text-weight-bold has-text-black">' +
            escapeHtml(PS_I18N.subscriptionLabel) + ' ' + escapeHtml(status) + '</p>';
        if (!sub.has_billing_account) {
            html += '<div class="notification is-warning mt-3 mb-3"><span class="icon"><i class="fas fa-exclamation-triangle"></i></span>' +
                '<span class="has-text-weight-bold">' + escapeHtml(PS_I18N.signupsUnavailable) + '</span></div>';
        }
        var statusLower = String(status).toLowerCase();
        if (statusLower === 'canceled') {
            if (sub.suspend_reason) {
                html += '<p class="has-text-black">' + escapeHtml(PS_I18N.cancellationReason) +
                    ' <span class="has-text-weight-bold">' + escapeHtml(sub.suspend_reason) + '</span></p>';
            }
            if (sub.deletion_time) {
                html += '<p class="has-text-black">' + escapeHtml(PS_I18N.filesDeleted) +
                    ' <span id="deletion-countdown" class="has-text-weight-bold" data-deletion-time="' +
                    escapeHtml(sub.deletion_time) + '">' + escapeHtml(PS_I18N.in24Hours) + '</span></p>';
            }
            html += '<p class="mt-3"><button type="button" class="button is-warning billing-btn" disabled style="pointer-events: none; opacity: 0.6;">' +
                '<span class="icon"><i class="fas fa-undo"></i></span><span>' + escapeHtml(PS_I18N.reactivate) + '</span></button></p>';
        } else if (statusLower === 'suspended') {
            if (sub.suspend_reason) {
                html += '<p class="has-text-black">' + escapeHtml(PS_I18N.reason) +
                    ' <span class="has-text-weight-bold">' + escapeHtml(sub.suspend_reason) + '</span></p>';
            }
            html += '<p class="has-text-black">' + escapeHtml(PS_I18N.suspendedNotice) + '</p>';
            html += '<p class="mt-3"><button type="button" class="button is-warning billing-btn" disabled style="pointer-events: none; opacity: 0.6;">' +
                '<span class="icon"><i class="fas fa-credit-card"></i></span><span>' + escapeHtml(PS_I18N.payInvoice) + '</span></button></p>';
        } else {
            html += '<p class="has-text-black">' + escapeHtml(PS_I18N.requiresActive) + '</p>';
            html += '<p class="mt-3"><button type="button" class="button is-primary billing-btn" disabled style="pointer-events: none; opacity: 0.6;">' +
                '<span class="icon"><i class="fas fa-shopping-cart"></i></span><span>' + escapeHtml(PS_I18N.subscribe) + '</span></button></p>';
            if (!sub.has_billing_account) {
                html += '<div class="mt-3 has-text-black"><p>' + PS_I18N.billingEmailNotice + '</p></div>';
            }
        }
        html += '</span>';
        notice.innerHTML = html;
        notice.hidden = false;
        startDeletionCountdown(document.getElementById('deletion-countdown'));
        bindBillingButtons();
    }

    function renderFiles(files, error) {
        var tbody = document.getElementById('ps-files-body');
        if (!tbody) return;
        tbody.setAttribute('aria-busy', 'false');
        if (error) {
            tbody.innerHTML = '<tr><td colspan="4" class="has-text-centered has-text-danger">' + escapeHtml(error) + '</td></tr>';
            return;
        }
        if (!files || !files.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="has-text-centered">' + escapeHtml(PS_I18N.noFiles) + '</td></tr>';
            return;
        }
        tbody.innerHTML = files.map(function(file) {
            var playUrl = '/api/play_stream.php?persistent=true&server=' + encodeURIComponent(selectedServer) +
                '&file=' + encodeURIComponent(file.path);
            var deleteUrl = '?delete=' + encodeURIComponent(file.path) + '&server=' + encodeURIComponent(selectedServer);
            return '<tr>' +
                '<td class="has-text-centered">' + escapeHtml(file.name) + '</td>' +
                '<td class="has-text-centered">' + escapeHtml(file.created_at) + '</td>' +
                '<td class="has-text-centered">' + escapeHtml(file.size) + '</td>' +
                '<td class="has-text-centered">' +
                    '<a href="' + escapeHtml(file.download_url) + '" class="action-icon" title="' + escapeHtml(PS_I18N.download) + '" target="_blank"><i class="fas fa-download"></i></a> ' +
                    '<a href="' + deleteUrl + '" class="action-icon ps-delete" title="' + escapeHtml(PS_I18N.deleteTitle) + '"><i class="fas fa-trash"></i></a> ' +
                    '<a href="#" class="play-video action-icon" data-video-url="' + escapeHtml(playUrl) + '" title="' + escapeHtml(PS_I18N.watch) + '"><i class="fas fa-play"></i></a>' +
                '</td></tr>';
        }).join('');
    }

    function renderError(message) {
        setBusy(false);
        renderFiles([], message || PS_I18N.loadError);
        var tag = document.getElementById('ps-status-tag');
        if (tag) {
            tag.className = 'tag is-medium is-danger';
            tag.textContent = '—';
        }
        var usageRow = document.getElementById('ps-usage-row');
        if (usageRow) usageRow.hidden = true;
        var manageRow = document.getElementById('ps-manage-row');
        if (manageRow) manageRow.hidden = true;
    }

    var videoModal = document.getElementById('videoModal');
    var customVideoPlayer = document.getElementById('customVideoPlayer');
    var customVideoSource = document.getElementById('customVideoSource');
    var filesBody = document.getElementById('ps-files-body');
    if (filesBody) {
        filesBody.addEventListener('click', function(e) {
            var play = e.target.closest('.play-video');
            if (play) {
                e.preventDefault();
                if (!customVideoSource || !customVideoPlayer || !videoModal) return;
                customVideoSource.src = play.getAttribute('data-video-url');
                customVideoPlayer.load();
                videoModal.classList.add('is-active');
                return;
            }
            var del = e.target.closest('.ps-delete');
            if (del && !confirm(PS_I18N.confirmDelete)) {
                e.preventDefault();
            }
        });
    }
    function closeVideoModal() {
        if (!videoModal) return;
        videoModal.classList.remove('is-active');
        if (customVideoPlayer) customVideoPlayer.pause();
        if (customVideoSource) customVideoSource.src = '';
        if (customVideoPlayer) customVideoPlayer.load();
    }
    if (videoModal) {
        var bg = videoModal.querySelector('.modal-background');
        var closeBtn = videoModal.querySelector('.modal-close');
        if (bg) bg.addEventListener('click', closeVideoModal);
        if (closeBtn) closeBtn.addEventListener('click', closeVideoModal);
    }

    bindBillingButtons();

    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    url.searchParams.set('server', selectedServer);
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            setBusy(false);
            if (!data || !data.success) {
                renderError(data && data.error ? data.error : PS_I18N.loadError);
                return;
            }
            if (data.folder_created) {
                var flash = document.getElementById('ps-flash-host');
                if (flash) {
                    var note = document.createElement('div');
                    note.className = 'notification is-success';
                    note.textContent = PS_I18N.folderCreated;
                    flash.appendChild(note);
                }
            }
            var sub = data.subscription || {};
            sub.total_used_storage = data.total_used_storage;
            renderSubscription(sub);
            renderFiles(Array.isArray(data.files) ? data.files : [], data.error);
        })
        .catch(function() {
            renderError(PS_I18N.loadError);
        });
});
</script>
<?php
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>
