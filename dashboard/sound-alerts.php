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

// Page Title
$pageTitle = t('sound_alerts_page_title');

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

// Define empty variables
$status = '';

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Fetch sound alert mappings for the current user
$soundAlertMappings = [];
$getSoundAlerts = $db->prepare("SELECT sound_mapping, reward_id FROM sound_alerts");
$getSoundAlerts->execute();
$getSoundAlerts->bind_result($sound_mapping, $reward_id);
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping] = $reward_id;
}
$getSoundAlerts->close();

// NEW: Query video alerts to exclude mapped rewards from sound alerts
$videoMappedRewards = [];
$getVideoAlertsForMapping = $db->prepare("SELECT DISTINCT reward_id FROM video_alerts");
$getVideoAlertsForMapping->execute();
$getVideoAlertsForMapping->bind_result($video_reward_id);
while ($getVideoAlertsForMapping->fetch()) {
    $videoMappedRewards[] = $video_reward_id;
}
$getVideoAlertsForMapping->close();

// Create an associative array for reward_id => reward_title for easy lookup
$rewardIdToTitle = [];
foreach ($channelPointRewards as $reward) {
    $rewardIdToTitle[$reward['reward_id']] = $reward['reward_title'];
}

// Handle channel point reward mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sound_file'], $_POST['reward_id'])) {
    $status = ""; // Initialize $status
    $soundFile = $_POST['sound_file'];
    $rewardId = $_POST['reward_id'];
    $soundFile = htmlspecialchars($soundFile); 
    $db->begin_transaction();  
    // Check if a mapping already exists for this sound file
    $checkExisting = $db->prepare("SELECT 1 FROM sound_alerts WHERE sound_mapping = ?");
    $checkExisting->bind_param('s', $soundFile);
    $checkExisting->execute();
    $checkExisting->store_result();
    if ($checkExisting->num_rows > 0) {
        // Update existing mapping
        if ($rewardId) {
            $updateMapping = $db->prepare("UPDATE sound_alerts SET reward_id = ? WHERE sound_mapping = ?");
            $updateMapping->bind_param('ss', $rewardId, $soundFile);
            if (!$updateMapping->execute()) {
                $status .= "Failed to update mapping for file '" . $soundFile . "'. Database error: " . $updateMapping->error . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $soundFile . "' has been updated successfully.<br>";
            }
            $updateMapping->close();
        } else {
            // Delete the mapping if no reward is selected (Remove Mapping option)
            $deleteMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = ?");
            $deleteMapping->bind_param('s', $soundFile);
            if (!$deleteMapping->execute()) {
                $status .= "Failed to remove mapping for file '" . $soundFile . "'. Database error: " . $deleteMapping->error . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $soundFile . "' has been removed.<br>";
            }
            $deleteMapping->close();
        }
    } else {
        // Create a new mapping if it doesn't exist
        if ($rewardId) {
            $insertMapping = $db->prepare("INSERT INTO sound_alerts (sound_mapping, reward_id) VALUES (?, ?)");
            $insertMapping->bind_param('ss', $soundFile, $rewardId);
            if (!$insertMapping->execute()) {
                $status .= "Failed to create mapping for file '" . $soundFile . "'. Database error: " . $insertMapping->error . "<br>"; 
            } else {
                $status .= "Mapping for file '" . $soundFile . "' has been created successfully.<br>";
            }
            $insertMapping->close();
        } 
    }
    $checkExisting->close();
    // Commit transaction
    $db->commit();
}

$remaining_storage = $max_storage_size - $current_storage_used;
$max_upload_size = $remaining_storage;
// ini_set('upload_max_filesize', $max_upload_size);
// ini_set('post_max_size', $max_upload_size);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    $status = "";
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Storage limit exceeded.<br>";
            continue;
        }
        $targetFile = $soundalert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 files are allowed.<br>";
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
    $status = "";
    $db->begin_transaction();
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $filename = basename($file_to_delete);
        $full_path = $soundalert_path . '/' . $filename;
        // First delete the physical file
        if (is_file($full_path) && unlink($full_path)) {
            $status .= "The file " . htmlspecialchars($filename) . " has been deleted.<br>";
            // Now delete any mapping for this file from the database
            $deleteMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = ?");
            $deleteMapping->bind_param('s', $filename);
            if ($deleteMapping->execute()) {
                if ($deleteMapping->affected_rows > 0) {
                    $status .= "The mapping for " . htmlspecialchars($filename) . " has also been removed.<br>";
                }
            } else {
                $status .= "Warning: Could not remove mapping for " . htmlspecialchars($filename) . ".<br>";
            }
            $deleteMapping->close();
        } else {
            $status .= "Failed to delete " . htmlspecialchars($filename) . ".<br>";
        }
    }
    $db->commit(); // Commit all database changes
    $current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path]);
    $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
}

$soundalert_files = array_diff(scandir($soundalert_path), array('.', '..', 'twitch'));
function formatFileName($fileName) { return basename($fileName, '.mp3'); }

ob_start();
?>
<!-- How-To Info Box -->
    <div class="sp-alert sp-alert-danger" style="margin-bottom:1.5rem;">
        <div style="display:flex;align-items:flex-start;gap:1rem;">
            <span style="font-size:1.5rem;flex-shrink:0;"><i class="fas fa-bell"></i></span>
            <div>
                <p style="font-weight:700;margin-bottom:0.5rem;"><?php echo t('sound_alerts_setup_title'); ?></p>
                <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:0.3rem;">
                    <li><i class="fas fa-upload" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_upload_instruction'); ?></li>
                    <li><i class="fab fa-twitch" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_rewards_instruction'); ?></li>
                    <li><i class="fas fa-play-circle" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_play_instruction'); ?></li>
                    <li><i class="fas fa-headphones" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_overlay_instruction'); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Upload Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title">
                <i class="fas fa-upload"></i>
                <?php echo t('sound_alerts_upload_title'); ?>
            </span>
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
                <div class="sp-alert sp-alert-info sp-notif" style="margin-bottom:1rem;">
                    <?php echo $status; ?>
                </div>
            <?php endif; ?>
            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="sp-form-group">
                    <label for="filesToUpload" style="display:block;border:2px dashed var(--border);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;cursor:pointer;background:var(--bg-input);transition:border-color var(--transition);color:var(--text-secondary);">
                        <i class="fas fa-cloud-upload-alt" style="font-size:2rem;margin-bottom:0.5rem;display:block;"></i>
                        <span id="file-list"><?php echo t('sound_alerts_no_files_selected'); ?></span>
                        <div style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);"><?php echo t('sound_alerts_choose_files'); ?></div>
                        <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3" style="display:none;">
                    </label>
                </div>
                <!-- Upload Status Container -->
                <div id="uploadStatusContainer" style="display:none;margin-bottom:1rem;">
                    <div class="sp-alert sp-alert-info">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                            <strong id="uploadStatusText">Preparing upload...</strong>
                            <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                        </div>
                        <progress class="progress" id="uploadProgress" value="0" max="100" style="width:100%;">0%</progress>
                    </div>
                </div>
                <button class="sp-btn sp-btn-primary" type="submit" name="submit" id="uploadBtn" style="width:100%;font-size:1.1rem;">
                    <i class="fas fa-upload"></i>
                    <span id="uploadBtnText"><?php echo t('sound_alerts_upload_btn'); ?></span>
                </button>
            </form>
        </div>
    </div>

    <!-- File Management Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title">
                <i class="fas fa-volume-up"></i>
                <?php echo t('sound_alerts_your_alerts'); ?>
            </span>
            <button class="sp-btn sp-btn-danger" id="deleteSelectedBtn" disabled>
                <i class="fas fa-trash"></i>
                <span><?php echo t('sound_alerts_delete_selected'); ?></span>
            </button>
        </header>
        <div class="sp-card-body">
            <?php if (!empty($soundalert_files)) : ?>
            <form action="" method="POST" id="deleteForm">
                <div class="sp-table-wrap">
                    <table class="sp-table" id="soundAlertsTable">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('sound_alerts_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('sound_alerts_file_name'); ?></th>
                                <th style="text-align:center;"><?php echo t('sound_alerts_channel_point_reward'); ?></th>
                                <th style="width:80px;text-align:center;"><?php echo t('sound_alerts_action'); ?></th>
                                <th style="width:120px;text-align:center;"><?php echo t('sound_alerts_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($soundalert_files as $file): ?>
                            <tr>
                                <td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td style="text-align:center;">
                                    <?php
                                    $current_reward_id = isset($soundAlertMappings[$file]) ? $soundAlertMappings[$file] : null;
                                    $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id]) : t('sound_alerts_not_mapped');
                                    ?>
                                    <?php if ($current_reward_id): ?>
                                        <em><?php echo $current_reward_title; ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('sound_alerts_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form" style="margin-top:0.5rem;">
                                        <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="reward_id" class="sp-select mapping-select" style="font-size:0.8rem;padding:0.35rem 2rem 0.35rem 0.6rem;">
                                            <?php if ($current_reward_id): ?>
                                                <option value=""><?php echo t('sound_alerts_remove_mapping'); ?></option>
                                            <?php endif; ?>
                                            <option value=""><?php echo t('sound_alerts_select_reward'); ?></option>
                                            <?php
                                            foreach ($channelPointRewards as $reward):
                                                $isMapped = (in_array($reward['reward_id'], $soundAlertMappings) || in_array($reward['reward_id'], $videoMappedRewards));
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
                                <td style="text-align:center;">
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="test-sound sp-btn sp-btn-primary sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" value="Delete Selected" class="sp-btn sp-btn-danger" name="submit_delete" style="display:none;margin-top:0.75rem;">
                    <i class="fas fa-trash"></i>
                    <span><?php echo t('sound_alerts_delete_selected'); ?></span>
                </button>
            </form>
            <?php else: ?>
                <div style="text-align:center;padding:3rem 0;">
                    <p style="color:var(--text-muted);font-size:1rem;"><?php echo t('sound_alerts_no_files_uploaded'); ?></p>
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
    if ($('.sp-alert.sp-alert-info').length) {
        setTimeout(function() {
            $('.sp-alert.sp-alert-info').fadeOut(500, function() {
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
    // Handle delete selected button
    $('#deleteSelectedBtn').on('click', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked');
        if (checkedBoxes.length > 0) {
            Swal.fire({
                title: '<?php echo t('sound_alerts_delete_file_title'); ?>',
                text: 'Are you sure you want to delete the selected ' + checkedBoxes.length + ' file(s)?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: '<?php echo t('sound_alerts_delete_file_confirm_btn'); ?>',
                cancelButtonText: '<?php echo t('sound_alerts_delete_file_cancel_btn'); ?>'
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
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('sound_alerts_no_files_selected'); ?>');
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
        let formData = new FormData(this);
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
            contentType: false,
            processData: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = Math.round((e.loaded / e.total) * 100);
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
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                $('#uploadStatusContainer').hide();
                $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading');
                $('#uploadBtnText').text('<?php echo t("sound_alerts_upload_btn"); ?>');
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: 'An error occurred during upload. Please try again.',
                    confirmButtonColor: '#3273dc'
                });
            }
        });
    });
    // Single delete button with SweetAlert2
    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        Swal.fire({
            title: '<?php echo t('sound_alerts_delete_file_title'); ?>',
            text: '<?php echo t('sound_alerts_delete_file_confirm'); ?>'.replace(':file', fileName),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?php echo t('sound_alerts_delete_file_confirm_btn'); ?>',
            cancelButtonText: '<?php echo t('sound_alerts_delete_file_cancel_btn'); ?>'
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
    document.querySelectorAll(".test-sound").forEach(function (button) {
        button.addEventListener("click", function () {
            const fileName = this.getAttribute("data-file");
            sendStreamEvent("SOUND_ALERT", fileName);
        });
    });
});
// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "notify_event.php";
    const params = `event=${eventType}&sound=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
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
include "layout.php";
?>