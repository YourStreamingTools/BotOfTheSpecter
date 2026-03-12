<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = t('video_alerts_page_title');

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

// Use MySQLi for all DB operations
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Fetch video alert mappings for the current user
$videoAlertMappings = [];
$videoAlerts = [];
if ($result = $db->query("SELECT video_mapping, reward_id FROM video_alerts")) {
    while ($row = $result->fetch_assoc()) {
        $videoAlerts[] = $row;
        $videoAlertMappings[$row['video_mapping']] = $row['reward_id'];
    }
    $result->free();
}

// Query sound alerts to exclude mapped rewards
$soundMappedRewards = [];
if ($result = $db->query("SELECT DISTINCT reward_id FROM sound_alerts")) {
    while ($row = $result->fetch_assoc()) {
        $soundMappedRewards[] = $row['reward_id'];
    }
    $result->free();
}

// Create an associative array for reward_id => reward_title for easy lookup
$rewardIdToTitle = [];
foreach ($channelPointRewards as $reward) {
    $rewardIdToTitle[$reward['reward_id']] = $reward['reward_title'];
}

$status = '';

// Handle channel point reward mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['video_file'], $_POST['reward_id'])) {
    $status = "";
    $videoFile = $_POST['video_file'];
    $rewardId = $_POST['reward_id'];
    $videoFile = htmlspecialchars($videoFile);

    // Check if a mapping already exists for this video file
    $stmt = $db->prepare("SELECT 1 FROM video_alerts WHERE video_mapping = ?");
    $stmt->bind_param("s", $videoFile);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if ($exists) {
        if ($rewardId) {
            $stmt = $db->prepare("UPDATE video_alerts SET reward_id = ? WHERE video_mapping = ?");
            $stmt->bind_param("ss", $rewardId, $videoFile);
            if (!$stmt->execute()) {
                $status .= t('video_alerts_mapping_update_failed', ['file' => $videoFile, 'error' => $stmt->error]) . "<br>";
            } else {
                $status .= t('video_alerts_mapping_updated', ['file' => $videoFile]) . "<br>";
            }
            $stmt->close();
        } else {
            $stmt = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = ?");
            $stmt->bind_param("s", $videoFile);
            if (!$stmt->execute()) {
                $status .= t('video_alerts_mapping_remove_failed', ['file' => $videoFile, 'error' => $stmt->error]) . "<br>";
            } else {
                $status .= t('video_alerts_mapping_removed', ['file' => $videoFile]) . "<br>";
            }
            $stmt->close();
        }
    } else {
        if ($rewardId) {
            $stmt = $db->prepare("INSERT INTO video_alerts (video_mapping, reward_id) VALUES (?, ?)");
            $stmt->bind_param("ss", $videoFile, $rewardId);
            if (!$stmt->execute()) {
                $status .= t('video_alerts_mapping_create_failed', ['file' => $videoFile, 'error' => $stmt->error]) . "<br>";
            } else {
                $status .= t('video_alerts_mapping_created', ['file' => $videoFile]) . "<br>";
            }
            $stmt->close();
        }
    }
    // Re-fetch updated video alert mappings after mapping changes
    $videoAlertMappings = [];
    $videoAlerts = [];
    if ($result = $db->query("SELECT video_mapping, reward_id FROM video_alerts")) {
        while ($row = $result->fetch_assoc()) {
            $videoAlerts[] = $row;
            $videoAlertMappings[$row['video_mapping']] = $row['reward_id'];
        }
        $result->free();
    }
}

$remaining_storage = $max_storage_size - $current_storage_used;
$max_upload_size = $remaining_storage;

function translateUploadError($code) {
    switch ($code) {
        case UPLOAD_ERR_OK: return t('video_alerts_upload_error_ok');
        case UPLOAD_ERR_INI_SIZE: return t('video_alerts_upload_error_ini_size');
        case UPLOAD_ERR_FORM_SIZE: return t('video_alerts_upload_error_form_size');
        case UPLOAD_ERR_PARTIAL: return t('video_alerts_upload_error_partial');
        case UPLOAD_ERR_NO_FILE: return t('video_alerts_upload_error_no_file');
        case UPLOAD_ERR_NO_TMP_DIR: return t('video_alerts_upload_error_no_tmp_dir');
        case UPLOAD_ERR_CANT_WRITE: return t('video_alerts_upload_error_cant_write');
        case UPLOAD_ERR_EXTENSION: return t('video_alerts_upload_error_extension');
        default: return t('video_alerts_upload_error_unknown');
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    $status = "";
    if (empty($_FILES["filesToUpload"]["tmp_name"]) || !$_FILES["filesToUpload"]["tmp_name"]) {
        $status .= t('video_alerts_no_file_received') . "<br>";
    }
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $errorCode = $_FILES["filesToUpload"]["error"][$key];
        if ($errorCode !== UPLOAD_ERR_OK) {
            $status .= t('video_alerts_upload_error', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])),
                'error' => translateUploadError($errorCode)
            ]) . "<br>";
            continue;
        }
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= t('video_alerts_upload_storage_limit', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
            ]) . "<br>";
            continue;
        }
        $targetFile = $videoalert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp4") {
            $status .= t('video_alerts_upload_only_mp4', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
            ]) . "<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= t('video_alerts_file_uploaded', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
            ]) . "<br>";
        } else {
            $status .= t('video_alerts_upload_error', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])),
                'error' => ''
            ]) . "<br>";
        }
    }
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    $status = "";
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $filename = basename($file_to_delete);
        $full_path = $videoalert_path . '/' . $filename;
        if (is_file($full_path) && unlink($full_path)) {
            $status .= t('video_alerts_file_deleted', ['file' => htmlspecialchars($filename)]) . "<br>";
            $stmt = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = ?");
            $stmt->bind_param("s", $filename);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $status .= t('video_alerts_mapping_removed', ['file' => htmlspecialchars($filename)]) . "<br>";
                }
            } else {
                $status .= t('video_alerts_mapping_remove_failed', ['file' => htmlspecialchars($filename), 'error' => '']) . "<br>";
            }
            $stmt->close();
        } else {
            $status .= t('video_alerts_file_delete_failed', ['file' => htmlspecialchars($filename)]) . "<br>";
        }
    }
    $current_storage_used = calculateStorageUsed([$walkon_path, $videoalert_path]);
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
}

$videoalert_files = array_diff(scandir($videoalert_path), array('.', '..'));
function formatFileName($fileName) { return basename($fileName, '.mp4'); }

// Start output buffering for content
ob_start();
?>
<!-- How-to info panel -->
<div class="sp-card mb-4">
    <div style="display:flex;align-items:flex-start;gap:1rem;padding:1.25rem;">
        <span style="font-size:1.5rem;color:var(--red);flex-shrink:0;margin-top:0.1rem;">
            <i class="fas fa-bell"></i>
        </span>
        <div>
            <p style="margin-bottom:0.5rem;"><strong><?php echo t('video_alerts_howto_title'); ?></strong></p>
            <ul style="margin:0.25rem 0 0 1.25rem;list-style:disc;padding:0;">
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fas fa-upload"></i></span>
                    <?php echo t('video_alerts_howto_upload'); ?>
                </li>
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fab fa-twitch"></i></span>
                    <?php echo t('video_alerts_howto_rewards'); ?>
                </li>
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fas fa-play-circle"></i></span>
                    <?php echo t('video_alerts_howto_play'); ?>
                </li>
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fas fa-headphones"></i></span>
                    <?php echo t('video_alerts_howto_overlay'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>
<!-- Upload Card -->
<div class="sp-card mb-4">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-upload"></i></span>
            <?php echo t('video_alerts_upload_title'); ?>
        </p>
    </header>
    <div class="sp-card-body">
        <!-- Storage Usage Info -->
        <div class="sp-alert sp-alert-info mb-4">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <span>
                    <span class="icon mr-2"><i class="fas fa-database"></i></span>
                    <strong><?php echo t('alerts_storage_usage'); ?>:</strong>
                </span>
                <span><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)</span>
            </div>
            <progress value="<?php echo $storage_percentage; ?>" max="100" style="width:100%;height:0.75rem;border-radius:0.5rem;accent-color:var(--green);"></progress>
        </div>
        <?php if (!empty($status)) : ?>
            <div class="sp-alert sp-alert-info sp-notif mb-4">
                <?php echo $status; ?>
            </div>
        <?php endif; ?>
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="sp-form-group">
                <label class="sp-label" for="filesToUpload"><?php echo t('video_alerts_choose_files'); ?></label>
                <input class="sp-input" type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp4">
                <div id="file-list" style="margin-top:0.4rem;font-size:0.875rem;color:var(--text-muted);"><?php echo t('video_alerts_no_files_selected'); ?></div>
            </div>
            <!-- Upload Status Container -->
            <div id="uploadStatusContainer" style="display:none;" class="mb-4">
                <div class="sp-alert sp-alert-info">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                        <span>
                            <span class="icon mr-2"><i class="fas fa-spinner fa-pulse"></i></span>
                            <strong id="uploadStatusText">Preparing upload...</strong>
                        </span>
                        <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                    </div>
                    <progress id="uploadProgress" value="0" max="100" style="width:100%;height:1.25rem;border-radius:0.5rem;accent-color:var(--blue);">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary" style="width:100%;font-weight:600;font-size:1.05rem;" type="submit" name="submit" id="uploadBtn">
                <span class="icon"><i class="fas fa-upload"></i></span>
                <span id="uploadBtnText"><?php echo t('video_alerts_upload_btn'); ?></span>
            </button>
        </form>
    </div>
</div>
<!-- File Management Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-film"></i></span>
            <?php echo t('video_alerts_your_alerts'); ?>
        </p>
        <button class="sp-btn sp-btn-danger sp-btn-sm" id="deleteSelectedBtn" disabled>
            <span class="icon"><i class="fas fa-trash"></i></span>
            <span><?php echo t('video_alerts_delete_selected'); ?></span>
        </button>
    </header>
    <div class="sp-card-body">
        <?php if (!empty($videoalert_files)) : ?>
            <form action="" method="POST" id="deleteForm">
                <div class="sp-table-wrap">
                    <table class="sp-table" id="videoAlertsTable">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('video_alerts_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('video_alerts_file_name'); ?></th>
                                <th style="text-align:center;"><?php echo t('video_alerts_channel_point_reward'); ?></th>
                                <th style="width:80px;text-align:center;"><?php echo t('video_alerts_action'); ?></th>
                                <th style="width:120px;text-align:center;"><?php echo t('video_alerts_test_video'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($videoalert_files as $file): 
                                $fileName = htmlspecialchars(pathinfo($file, PATHINFO_FILENAME));
                                $current_reward_id = isset($videoAlertMappings[$file]) ? $videoAlertMappings[$file] : null;
                                $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id]) : t('video_alerts_not_mapped');
                            ?>
                            <tr>
                                <td style="text-align:center;vertical-align:middle;">
                                    <input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>">
                                </td>
                                <td style="vertical-align:middle;"><?php echo $fileName; ?></td>
                                <td style="text-align:center;vertical-align:middle;">
                                    <?php if ($current_reward_id): ?>
                                        <em><?php echo $current_reward_title; ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('video_alerts_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form" style="margin-top:0.5rem;">
                                        <input type="hidden" name="video_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="reward_id" class="sp-select mapping-select" style="width:100%;">
                                            <?php if ($current_reward_id): ?>
                                                <option value="" style="color:var(--red);"><?php echo t('video_alerts_remove_mapping'); ?></option>
                                            <?php endif; ?>
                                            <option value=""><?php echo t('video_alerts_select_reward'); ?></option>
                                            <?php
                                            foreach ($channelPointRewards as $reward):
                                                $isMapped = (in_array($reward['reward_id'], $videoAlertMappings) || in_array($reward['reward_id'], $soundMappedRewards));
                                                $isCurrent = ($current_reward_id === $reward['reward_id']);
                                                if ($isMapped && !$isCurrent) continue;
                                            ?>
                                                <option value="<?php echo htmlspecialchars($reward['reward_id']); ?>"<?php if ($isCurrent) { echo ' selected';}?>>
                                                    <?php echo htmlspecialchars($reward['reward_title']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td style="text-align:center;vertical-align:middle;">
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>">
                                        <span class="icon"><i class="fas fa-trash"></i></span>
                                    </button>
                                </td>
                                <td style="text-align:center;vertical-align:middle;">
                                    <button type="button" class="test-video sp-btn sp-btn-primary sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>">
                                        <span class="icon"><i class="fas fa-play"></i></span>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" value="Delete Selected" class="sp-btn sp-btn-danger mt-3" name="submit_delete" style="display:none;">
                    <span class="icon"><i class="fas fa-trash"></i></span>
                    <span><?php echo t('video_alerts_delete_selected'); ?></span>
                </button>
            </form>
        <?php else: ?>
            <div style="text-align:center;padding:3rem 0;">
                <h2 style="font-size:1rem;color:var(--text-muted);"><?php echo t('video_alerts_no_files_uploaded'); ?></h2>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
$(document).ready(function() {
    // Auto-dismiss status messages after 15 seconds
    if ($('.sp-notif').length) {
        setTimeout(function() {
            $('.sp-notif').fadeOut(500, function() {
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
                $('#uploadBtnText').text('<?php echo t("video_alerts_upload_btn"); ?>');
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: '<?php echo t("video_alerts_upload_error"); ?>',
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
                title: '<?php echo t('video_alerts_delete_file_title'); ?>',
                text: 'Are you sure you want to delete the selected ' + checkedBoxes.length + ' file(s)?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: '<?php echo t('video_alerts_delete_file_confirm_btn'); ?>',
                cancelButtonText: '<?php echo t('video_alerts_delete_file_cancel_btn'); ?>'
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
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('video_alerts_no_files_selected'); ?>');
    });
    // Add event listener for mapping select boxes
    $('.mapping-select').on('change', function() {
        // Submit the form via AJAX
        const form = $(this).closest('form');
        $.post('', form.serialize(), function(data) {
            // Reload the page after successful submission
            location.reload();
        });
    });
    // Single delete button with SweetAlert2
    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        Swal.fire({
            title: '<?php echo t('video_alerts_delete_file_title'); ?>',
            text: '<?php echo t('video_alerts_delete_file_confirm'); ?>'.replace(':file', fileName),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?php echo t('video_alerts_delete_file_confirm_btn'); ?>',
            cancelButtonText: '<?php echo t('video_alerts_delete_file_cancel_btn'); ?>'
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
    // Attach click event listeners to all Test buttons
    document.querySelectorAll(".test-video").forEach(function (button) {
        button.addEventListener("click", function () {
            const fileName = this.getAttribute("data-file");
            sendStreamEvent("VIDEO_ALERT", fileName);
        });
    });
});
// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "notify_event.php";
    const params = `event=${eventType}&video=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
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