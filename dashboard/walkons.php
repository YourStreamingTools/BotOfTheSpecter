<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
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
session_write_close();
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
function formatFileName($fileName) { return basename($fileName, '.mp3'); }
function formatFileNamewithEXT($fileName) {
    $fileInfo = pathinfo($fileName);
    $name = basename($fileName, '.' . $fileInfo['extension']);
    $extenstion = strtoupper($fileInfo['extension']);
    return $name . " (" . $extenstion . ")";
}

// Start output buffering for layout
ob_start();
?>
<!-- Setup info banner -->
<div class="sp-alert sp-alert-danger" style="display:flex; align-items:center; gap:1.25rem; margin-bottom:1.5rem;">
    <i class="fas fa-volume-up fa-2x" style="flex-shrink:0;"></i>
    <div>
        <p style="font-weight:700; margin-bottom:0.5rem;"><?php echo t('walkons_setup_title'); ?></p>
        <ul style="list-style:disc; padding-left:1.25rem; margin:0;">
            <li style="margin-bottom:0.25rem;">
                <i class="fas fa-upload" style="margin-right:0.35rem;"></i>
                <?php echo t('walkons_upload_instruction'); ?>
            </li>
            <li>
                <i class="fas fa-headphones" style="margin-right:0.35rem;"></i>
                <?php echo t('walkons_overlay_instruction'); ?>
            </li>
        </ul>
    </div>
</div>
<!-- Upload Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <i class="fas fa-upload"></i>
            <?php echo t('walkons_upload_title'); ?>
        </p>
    </header>
    <div class="sp-card-body">
        <!-- Storage Usage Info -->
        <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                <span><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)</span>
            </div>
            <progress class="progress" value="<?php echo $storage_percentage; ?>" max="100" style="width:100%;"></progress>
        </div>
        <?php if (!empty($status)) : ?>
            <div class="sp-alert sp-alert-info" id="uploadStatusMsg" style="margin-bottom:1rem;">
                <?php echo $status; ?>
            </div>
        <?php endif; ?>
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <!-- File chooser -->
            <div style="margin-bottom:0.75rem;">
                <label for="filesToUpload" id="fileDropLabel" style="display:block; border:2px dashed var(--border); border-radius:var(--radius); padding:1.75rem 1.25rem; text-align:center; cursor:pointer; background:var(--bg-input); transition:border-color var(--transition);">
                    <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3,.mp4" style="display:none;">
                    <i class="fas fa-cloud-upload-alt" style="font-size:1.75rem; color:var(--text-muted); margin-bottom:0.5rem; display:block;"></i>
                    <span style="font-weight:600; color:var(--text-secondary);"><?php echo t('walkons_choose_files'); ?></span><br>
                    <span id="file-list" style="font-size:0.82rem; color:var(--text-muted);"><?php echo t('walkons_no_files_selected'); ?></span>
                </label>
            </div>
            <!-- Upload Status Container -->
            <div id="uploadStatusContainer" style="display:none; margin-bottom:0.75rem;">
                <div class="sp-alert sp-alert-info">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                        <strong id="uploadStatusText">Preparing upload...</strong>
                        <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                    </div>
                    <progress class="progress progress-info" id="uploadProgress" value="0" max="100" style="height:1.25rem; border-radius:0.625rem;">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary" type="submit" name="submit" id="uploadBtn" style="width:100%; font-size:1rem; padding:0.65rem 1.1rem;">
                <i class="fas fa-upload"></i>
                <span id="uploadBtnText"><?php echo t('walkons_upload_btn'); ?></span>
            </button>
        </form>
    </div>
</div>
<!-- File Management Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <i class="fas fa-door-open"></i>
            <?php echo t('walkons_users_with_walkons'); ?>
        </p>
        <button class="sp-btn sp-btn-danger" id="deleteSelectedBtn" disabled>
            <i class="fas fa-trash"></i>
            <span><?php echo t('walkons_delete_selected'); ?></span>
        </button>
    </header>
    <div class="sp-card-body">
        <?php if (!empty($walkon_files)) : ?>
        <form action="" method="POST" id="deleteForm">
            <div class="sp-table-wrap">
                <table class="sp-table" id="walkonsTable">
                    <thead>
                        <tr>
                            <th style="width:70px; text-align:center;"><?php echo t('walkons_select'); ?></th>
                            <th style="text-align:center;"><?php echo t('walkons_file_name'); ?></th>
                            <th style="width:100px; text-align:center;"><?php echo t('walkons_action'); ?></th>
                            <th style="width:150px; text-align:center;"><?php echo t('walkons_test_audio'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($walkon_files as $file): ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>">
                            </td>
                            <td><?php echo htmlspecialchars(formatFileNamewithEXT($file)); ?></td>
                            <td style="text-align:center;">
                                <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="test-walkon sp-btn sp-btn-primary sp-btn-sm" data-file="<?php echo htmlspecialchars(formatFileName($file)); ?>">
                                    <i class="fas fa-play"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" value="Delete Selected" class="sp-btn sp-btn-danger" name="submit_delete" style="display:none;">
                <i class="fas fa-trash"></i>
                <span><?php echo t('walkons_delete_selected'); ?></span>
            </button>
        </form>
        <?php else: ?>
            <div style="text-align:center; padding:3rem 0;">
                <p style="font-size:1.05rem; color:var(--text-muted);"><?php echo t('walkons_no_files_uploaded'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
$(document).ready(function() {
    // Auto-dismiss status messages after 15 seconds
    if ($('#uploadStatusMsg').length) {
        setTimeout(function() {
            $('#uploadStatusMsg').fadeOut(500, function() {
                $(this).remove();
            });
        }, 15000);
    }
    // Handle select all checkbox
    $('#selectAll').on('change', function() {
        $('input[name="delete_files[]"]').prop('checked', this.checked);
        var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
        $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
    });
    // AJAX upload with progress bar
    $('#uploadForm').on('submit', function(e) {
        e.preventDefault();
        var files = $('#filesToUpload')[0].files;
        if (files.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Files Selected',
                text: 'Please select at least one file to upload.',
                confirmButtonColor: '#3273dc'
            });
            return;
        }
        var formData = new FormData(this);
        // Show upload status and update UI
        $('#uploadStatusContainer').show();
        $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> Uploading ' + files.length + ' file(s)...');
        $('#uploadProgressPercent').text('0%');
        $('#uploadProgress').val(0);
        // Update button state
        $('#uploadBtn').prop('disabled', true).addClass('sp-btn-loading');
        $('#uploadBtnText').text('Uploading...');
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percentComplete = Math.round((e.loaded / e.total) * 100);
                        $('#uploadProgress').val(percentComplete);
                        $('#uploadProgressPercent').text(percentComplete + '%');
                        if (percentComplete < 100) {
                            $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> Uploading... (' + percentComplete + '%)');
                        } else {
                            $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> Processing files on server...');
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> Upload completed successfully!');
                $('#uploadProgressPercent').text('100%');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            },
            error: function() {
                $('#uploadStatusContainer').hide();
                $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading');
                $('#uploadBtnText').text('<?php echo t("walkons_upload_btn"); ?>');
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: '<?php echo t("walkons_upload_error"); ?>',
                    confirmButtonColor: '#3273dc'
                });
            }
        });
    });
    // Handle delete selected button
    $('#deleteSelectedBtn').on('click', function() {
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
    $(document).on('change', 'input[name="delete_files[]"]', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
        $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
    });
    // Update file name display
    $('#filesToUpload').on('change', function() {
        let files = this.files;
        let fileNames = [];
        for (let i = 0; i < files.length; i++) {
            fileNames.push(files[i].name);
        }
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('walkons_no_files_selected'); ?>');
    });
    // Single delete button with SweetAlert2
    $('.delete-single').on('click', function() {
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
require 'layout.php';
?>