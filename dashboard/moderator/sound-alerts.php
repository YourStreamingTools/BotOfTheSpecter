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
function formatFileName($fileName)
{
    return basename($fileName, '.mp3');
}

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
                                <i class="fas fa-bell fa-2x"></i>
                            </span>
                        </div>
                        <div class="column">
                            <p class="mb-2"><strong><?php echo t('sound_alerts_setup_title'); ?></strong></p>
                            <ul>
                                <li><span class="icon"><i class="fas fa-upload"></i></span>
                                    <?php echo t('sound_alerts_upload_instruction'); ?></li>
                                <li><span class="icon"><i class="fab fa-twitch"></i></span>
                                    <?php echo t('sound_alerts_rewards_instruction'); ?></li>
                                <li><span class="icon"><i class="fas fa-play-circle"></i></span>
                                    <?php echo t('sound_alerts_play_instruction'); ?></li>
                                <li><span class="icon"><i class="fas fa-headphones"></i></span>
                                    <?php echo t('sound_alerts_overlay_instruction'); ?></li>
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
                            <span class="icon mr-2"><i class="fas fa-volume-up"></i></span>
                            <?php echo t('sound_alerts_your_alerts'); ?>
                        </span>
                        <div class="buttons">
                            <button class="button is-danger" id="deleteSelectedBtn" disabled>
                                <span class="icon"><i class="fas fa-trash"></i></span>
                                <span><?php echo t('sound_alerts_delete_selected'); ?></span>
                            </button>
                            <button class="button is-primary" id="openUploadModal">
                                <span class="icon"><i class="fas fa-upload"></i></span>
                                <span><?php echo t('sound_alerts_upload_title'); ?></span>
                            </button>
                        </div>
                    </header>
                    <div class="card-content">
                        <?php if (!empty($soundalert_files)): ?>
                            <form action="" method="POST" id="deleteForm">
                                <div class="table-container">
                                    <table class="table is-fullwidth has-background-dark" id="soundAlertsTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 70px;" class="has-text-centered">
                                                    <?php echo t('sound_alerts_select'); ?></th>
                                                <th class="has-text-centered"><?php echo t('sound_alerts_file_name'); ?>
                                                </th>
                                                <th class="has-text-centered">
                                                    <?php echo t('sound_alerts_channel_point_reward'); ?></th>
                                                <th style="width: 80px;" class="has-text-centered">
                                                    <?php echo t('sound_alerts_action'); ?></th>
                                                <th style="width: 120px;" class="has-text-centered">
                                                    <?php echo t('sound_alerts_test_audio'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($soundalert_files as $file): ?>
                                                <tr>
                                                    <td class="has-text-centered is-vcentered"><input type="checkbox"
                                                            class="is-checkradio" name="delete_files[]"
                                                            value="<?php echo htmlspecialchars($file); ?>"></td>
                                                    <td class="is-vcentered">
                                                        <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                                    <td class="has-text-centered is-vcentered">
                                                        <?php
                                                        $current_reward_id = isset($soundAlertMappings[$file]) ? $soundAlertMappings[$file] : null;
                                                        $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id]) : t('sound_alerts_not_mapped');
                                                        ?>
                                                        <?php if ($current_reward_id): ?>
                                                            <em><?php echo $current_reward_title; ?></em>
                                                        <?php else: ?>
                                                            <em><?php echo t('sound_alerts_not_mapped'); ?></em>
                                                        <?php endif; ?>
                                                        <form action="" method="POST" class="mapping-form mt-2">
                                                            <input type="hidden" name="sound_file"
                                                                value="<?php echo htmlspecialchars($file); ?>">
                                                            <div class="select is-small is-fullwidth">
                                                                <select name="reward_id" class="mapping-select"
                                                                    style="background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                                                                    <?php if ($current_reward_id): ?>
                                                                        <option value="" class="has-text-danger">
                                                                            <?php echo t('sound_alerts_remove_mapping'); ?></option>
                                                                    <?php endif; ?>
                                                                    <option value="">
                                                                        <?php echo t('sound_alerts_select_reward'); ?></option>
                                                                    <?php
                                                                    foreach ($channelPointRewards as $reward):
                                                                        $isMapped = (in_array($reward['reward_id'], $soundAlertMappings) || in_array($reward['reward_id'], $videoMappedRewards));
                                                                        $isCurrent = ($current_reward_id === $reward['reward_id']);
                                                                        if ($isMapped && !$isCurrent)
                                                                            continue;
                                                                        ?>
                                                                        <option
                                                                            value="<?php echo htmlspecialchars($reward['reward_id']); ?>"
                                                                            <?php if ($isCurrent) {
                                                                                echo ' selected';
                                                                            } ?>>
                                                                            <?php echo htmlspecialchars($reward['reward_title']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </form>
                                                    </td>
                                                    <td class="has-text-centered is-vcentered">
                                                        <button type="button" class="delete-single button is-danger is-small"
                                                            data-file="<?php echo htmlspecialchars($file); ?>">
                                                            <span class="icon"><i class="fas fa-trash"></i></span>
                                                        </button>
                                                    </td>
                                                    <td class="has-text-centered is-vcentered">
                                                        <button type="button" class="test-sound button is-primary is-small"
                                                            data-file="<?php echo htmlspecialchars($file); ?>">
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
                                    <span><?php echo t('sound_alerts_delete_selected'); ?></span>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="has-text-centered py-6">
                                <h2 class="title is-5 has-text-grey-light">
                                    <?php echo t('sound_alerts_no_files_uploaded'); ?></h2>
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
                <?php echo t('sound_alerts_upload_title'); ?>
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
                                <?php echo t('sound_alerts_choose_files'); ?>
                            </span>
                        </span>
                        <span class="file-name" id="file-list"
                            style="text-align: center; background-color: #2b2f3a; border-color: #4a4a4a; color: white;">
                            <?php echo t('sound_alerts_no_files_selected'); ?>
                        </span>
                    </label>
                </div>
                <div class="mt-4" style="position: relative;">
                    <progress class="progress is-success" value="<?php echo $storage_percentage; ?>" max="100"
                        style="height: 1.25rem; border-radius: 0.75rem;"></progress>
                    <div class="has-text-centered"
                        style="margin-top: -1.7rem; margin-bottom: 0.7rem; font-size: 0.98rem; font-weight: 500; color: #fff; width: 100%; position: relative; z-index: 2;">
                        <?php echo round($storage_percentage, 2); ?>% &mdash;
                        <?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB
                        <?php echo t('sound_alerts_of'); ?> <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB
                        <?php echo t('sound_alerts_used'); ?>
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
                <span><?php echo t('sound_alerts_upload_btn'); ?></span>
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
            $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('sound_alerts_no_files_selected'); ?>');
        });

        // Add event listener for mapping select boxes
        $('.mapping-select').on('change', function () {
            // Submit the form via AJAX
            const form = $(this).closest('form');
            $.post('', form.serialize(), function (data) {
                // Reload the page after successful submission
                location.reload();
            });
        });

        // AJAX upload with progress bar
        $('#uploadForm').on('submit', function (e) {
            e.preventDefault();
            let formData = new FormData(this);
            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                xhr: function () {
                    let xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            let percentComplete = (e.loaded / e.total) * 100;
                            $('.upload-progress-bar').val(percentComplete).text(Math.round(percentComplete) + '%');
                        }
                    }, false);
                    return xhr;
                },
                success: function (response) {
                    location.reload();
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                }
            });
        });

        // Single delete button with SweetAlert2
        $('.delete-single').on('click', function () {
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
include 'mod_layout.php';
?>