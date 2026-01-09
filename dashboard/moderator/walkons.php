<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$pageTitle = t('walkons_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
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

// Define empty variable for status
$status = '';

// Define user-specific storage limits
$remaining_storage = $max_storage_size - $current_storage_used;
$max_upload_size = $remaining_storage;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Storage limit exceeded.<br>";
            continue;
        }
        $targetFile = $walkon_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3" && $fileType != "mp4") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 and MP4 files are allowed.<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= "The file " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . " has been uploaded.<br>";
        } else {
            $status .= "Sorry, there was an error uploading " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ".<br>";
        }
    }
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100; // Update percentage after upload
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $file_to_delete = $walkon_path . '/' . basename($file_to_delete);
        if (is_file($file_to_delete) && unlink($file_to_delete)) {
            $status .= "The file " . htmlspecialchars(basename($file_to_delete)) . " has been deleted.<br>";
        } else {
            $status .= "Failed to delete " . htmlspecialchars(basename($file_to_delete)) . ".<br>";
        }
    }
    $current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path]);
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
}
$walkon_files = array_diff(scandir($walkon_path), array('.', '..'));
function formatFileName($fileName)
{
    return basename($fileName, '.mp3');
}
function formatFileNamewithEXT($fileName)
{
    $fileInfo = pathinfo($fileName);
    $name = basename($fileName, '.' . $fileInfo['extension']);
    $extenstion = strtoupper($fileInfo['extension']);
    return $name . " (" . $extenstion . ")";
}

// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="columns is-desktop is-multiline is-centered">
            <div class="column is-fullwidth" style="max-width: 1200px;">
                <div class="notification is-danger">
                    <div class="columns is-vcentered">
                        <div class="column is-narrow">
                            <span class="icon is-large">
                                <i class="fas fa-volume-up fa-2x"></i>
                            </span>
                        </div>
                        <div class="column">
                            <p class="mb-2"><strong><?php echo t('walkons_setup_title'); ?></strong></p>
                            <ul>
                                <li>
                                    <span class="icon"><i class="fas fa-upload"></i></span>
                                    <?php echo t('walkons_upload_instruction'); ?>
                                </li>
                                <li>
                                    <span class="icon"><i class="fas fa-headphones"></i></span>
                                    <?php echo t('walkons_overlay_instruction'); ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="columns is-desktop is-multiline is-centered">
            <div class="column is-fullwidth" style="max-width: 1200px;">
                <div class="card has-background-dark has-text-white"
                    style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                    <header class="card-header"
                        style="border-bottom: 1px solid #23272f; display: flex; justify-content: space-between; align-items: center;">
                        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                            <span class="icon mr-2"><i class="fas fa-door-open"></i></span>
                            <?php echo t('walkons_users_with_walkons'); ?>
                        </span>
                        <div class="buttons">
                            <button class="button is-danger" id="deleteSelectedBtn" disabled>
                                <span class="icon"><i class="fas fa-trash"></i></span>
                                <span><?php echo t('walkons_delete_selected'); ?></span>
                            </button>
                            <button class="button is-primary" id="openUploadModal">
                                <span class="icon"><i class="fas fa-upload"></i></span>
                                <span><?php echo t('walkons_upload_title'); ?></span>
                            </button>
                        </div>
                    </header>
                    <div class="card-content">
                        <?php if (!empty($walkon_files)): ?>
                            <form action="" method="POST" id="deleteForm">
                                <div class="table-container">
                                    <table class="table is-fullwidth has-background-dark" id="walkonsTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 70px;" class="has-text-centered">
                                                    <?php echo t('walkons_select'); ?>
                                                </th>
                                                <th class="has-text-centered"><?php echo t('walkons_file_name'); ?></th>
                                                <th style="width: 100px;" class="has-text-centered">
                                                    <?php echo t('walkons_action'); ?>
                                                </th>
                                                <th style="width: 150px;" class="has-text-centered">
                                                    <?php echo t('walkons_test_audio'); ?>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($walkon_files as $file): ?>
                                                <tr>
                                                    <td class="has-text-centered is-vcentered">
                                                        <input type="checkbox" class="is-checkradio" name="delete_files[]"
                                                            value="<?php echo htmlspecialchars($file); ?>">
                                                    </td>
                                                    <td class="is-vcentered">
                                                        <?php echo htmlspecialchars(formatFileNamewithEXT($file)); ?>
                                                    </td>
                                                    <td class="has-text-centered is-vcentered">
                                                        <button type="button" class="delete-single button is-danger is-small"
                                                            data-file="<?php echo htmlspecialchars($file); ?>">
                                                            <span class="icon"><i class="fas fa-trash"></i></span>
                                                        </button>
                                                    </td>
                                                    <td class="has-text-centered is-vcentered">
                                                        <button type="button" class="test-walkon button is-primary is-small"
                                                            data-file="<?php echo htmlspecialchars(formatFileName($file)); ?>">
                                                            <span class="icon"><i class="fas fa-play"></i></span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="submit" value="Delete Selected" class="button is-danger mt-3"
                                    name="submit_delete" style="display: none;">
                                    <span class="icon"><i class="fas fa-trash"></i></span>
                                    <span><?php echo t('walkons_delete_selected'); ?></span>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="has-text-centered py-6">
                                <h2 class="title is-5 has-text-grey-light"><?php echo t('walkons_no_files_uploaded'); ?>
                                </h2>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal" id="uploadModal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head has-background-dark">
            <p class="modal-card-title has-text-white">
                <span class="icon mr-2"><i class="fas fa-upload"></i></span>
                <?php echo t('walkons_upload_title'); ?>
            </p>
            <button class="delete" aria-label="close" id="closeUploadModal"></button>
        </header>
        <section class="modal-card-body has-background-dark has-text-white">
            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="file has-name is-fullwidth is-boxed mb-3">
                    <label class="file-label" style="width: 100%;">
                        <input class="file-input" type="file" name="filesToUpload[]" id="filesToUpload" multiple
                            accept=".mp3">
                        <span class="file-cta" style="background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                            <span class="file-label"
                                style="display: flex; align-items: center; justify-content: center; font-size: 1.15em;">
                                <?php echo t('walkons_choose_files'); ?>
                            </span>
                        </span>
                        <span class="file-name" id="file-list"
                            style="text-align: center; background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                            <?php echo t('walkons_no_files_selected'); ?>
                        </span>
                    </label>
                </div>
                <div class="mt-4" style="position: relative;">
                    <progress class="progress is-success" value="<?php echo $storage_percentage; ?>" max="100"
                        style="height: 1.25rem; border-radius: 0.75rem;"></progress>
                    <div class="has-text-centered"
                        style="margin-top: -1.7rem; margin-bottom: 0.7rem; font-size: 0.98rem; font-weight: 500; color: #fff; width: 100%; position: relative; z-index: 2;">
                        <?php echo round($storage_percentage, 2); ?>% &mdash;
                        <?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB <?php echo t('walkons_of'); ?>
                        <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB <?php echo t('walkons_used'); ?>
                    </div>
                </div>
                <?php if (!empty($status)): ?>
                    <article class="message is-info mt-4">
                        <div class="message-body">
                            <?php echo $status; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </form>
        </section>
        <footer class="modal-card-foot has-background-dark">
            <button class="button is-primary" type="submit" form="uploadForm" name="submit">
                <span class="icon"><i class="fas fa-upload"></i></span>
                <span><?php echo t('walkons_upload_btn'); ?></span>
            </button>
            <button class="button" id="cancelUploadModal"><?php echo t('cancel'); ?></button>
        </footer>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
    $(document).ready(function () {
        // Modal controls
        $('#openUploadModal').on('click', function () {
            $('#uploadModal').addClass('is-active');
        });
        $('#closeUploadModal, #cancelUploadModal, .modal-background').on('click', function () {
            $('#uploadModal').removeClass('is-active');
        });

        // Handle delete selected button
        $('#deleteSelectedBtn').on('click', function () {
            var checkedBoxes = $('input[name="delete_files[]"]:checked');
            if (checkedBoxes.length > 0) {
                Swal.fire({
                    title: '<?php echo t('walkons_delete_file_confirm_title'); ?>',
                    text: 'Are you sure you want to delete the selected ' + checkedBoxes.length + ' file(s)?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    confirmButtonText: '<?php echo t('walkons_delete_file_confirm_btn'); ?>',
                    cancelButtonText: '<?php echo t('walkons_delete_file_cancel_btn'); ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $('#deleteForm').submit();
                    }
                });
            }
        });

        // Monitor checkbox changes to enable/disable delete button
        $(document).on('change', 'input[name="delete_files[]"]', function () {
            var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
            $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
        });

        // Update file name display for Bulma file input
        $('#filesToUpload').on('change', function () {
            let files = this.files;
            let fileNames = [];
            for (let i = 0; i < files.length; i++) {
                fileNames.push(files[i].name);
            }
            $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('walkons_no_files_selected'); ?>');
        });

        // Single delete button with SweetAlert2
        $('.delete-single').on('click', function () {
            let fileName = $(this).data('file');
            Swal.fire({
                title: '<?php echo t('walkons_delete_file_confirm_title'); ?>',
                text: '<?php echo t('walkons_delete_file_confirm'); ?>'.replace(':file', fileName),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: '<?php echo t('walkons_delete_file_confirm_btn'); ?>',
                cancelButtonText: '<?php echo t('walkons_delete_file_cancel_btn'); ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'delete_files[]',
                        value: fileName
                    }).appendTo('#deleteForm');
                    $('#deleteForm').submit();
                }
            });
        });
    });

    document.addEventListener("DOMContentLoaded", function () {
        // Attach click event listeners to all Test buttons for walkons
        document.querySelectorAll(".test-walkon").forEach(function (button) {
            button.addEventListener("click", function () {
                const fileName = this.getAttribute("data-file");
                sendStreamEvent("WALKON", fileName);
            });
        });
    });

    // Function to send a stream event
    function sendStreamEvent(eventType, fileName) {
        const xhr = new XMLHttpRequest();
        const url = "notify_event.php";
        const params = `event=${eventType}&user=${encodeURIComponent(fileName)}&channel=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
        xhr.open("POST", url, true);
        xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        console.log(`${eventType} event for ${fileName} sent successfully.`);
                    } else {
                        console.error(`Error sending ${eventType} event: ${response.message}`);
                    }
                } catch (e) {
                    console.error("Error parsing JSON response:", e);
                    console.error("Response:", xhr.responseText);
                }
            } else if (xhr.readyState === 4) {
                console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
            }
        };
        xhr.send(params);
    }
</script>
<?php
$scripts = ob_get_clean();
include 'mod_layout.php';
?>