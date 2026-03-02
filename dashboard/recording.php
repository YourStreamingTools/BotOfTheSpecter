<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$pageTitle = 'Recording';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
// $billing_conn = new mysqli($servername, $username, $password, "fossbilling");
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

$recorderUsername = isset($username) && $username !== '' ? $username : ($_SESSION['username'] ?? 'unknown');
$userStorageDir = "/mnt/blockstorage/{$recorderUsername}";

$saveStatus = null;
$autoRecordEnabled = 0;
$remoteFileSections = [];
$remoteFileError = null;

function formatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

function listRemoteDirectoryFiles($sftp, $directoryPath) {
    $items = [];
    $sftpPath = "ssh2.sftp://" . intval($sftp) . $directoryPath;
    $handle = @opendir($sftpPath);
    if (!$handle) {
        return $items;
    }
    while (($fileName = readdir($handle)) !== false) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }
        $fullPath = rtrim($directoryPath, '/') . '/' . $fileName;
        $stat = @ssh2_sftp_stat($sftp, $fullPath);
        if (!$stat) {
            continue;
        }
        $isDirectory = isset($stat['mode']) && (($stat['mode'] & 0x4000) === 0x4000);
        $isPartial = !$isDirectory && (substr($fileName, -5) === '.part');
        $items[] = [
            'name' => $fileName,
            'path' => $fullPath,
            'size' => $isDirectory ? null : ($stat['size'] ?? 0),
            'modified' => $stat['mtime'] ?? null,
            'is_directory' => $isDirectory,
            'is_partial' => $isPartial,
        ];
    }
    closedir($handle);
    usort($items, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function ensureRemoteDirectory($sftp, $directoryPath) {
    $sftpPath = "ssh2.sftp://" . intval($sftp) . $directoryPath;
    $handle = @opendir($sftpPath);
    if ($handle) {
        closedir($handle);
        return true;
    }
    return @ssh2_sftp_mkdir($sftp, $directoryPath, 0775, true);
}

$loadStmt = $db->prepare("SELECT enabled FROM auto_record_settings WHERE id = 1 LIMIT 1");
if ($loadStmt) {
    $loadStmt->execute();
    $loadResult = $loadStmt->get_result();
    if ($loadResult && $row = $loadResult->fetch_assoc()) {
        $autoRecordEnabled = (int)($row['enabled'] ?? 0);
    }
    $loadStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recording_settings'])) {
    $autoRecordEnabled = isset($_POST['auto_record']) ? 1 : 0;
    $saveStmt = $db->prepare("REPLACE INTO auto_record_settings (id, enabled) VALUES (1, ?)");
    if ($saveStmt) {
        $saveStmt->bind_param("i", $autoRecordEnabled);
        if ($saveStmt->execute()) {
            $saveStatus = [
                'success' => true,
                'message' => 'Recording setting updated successfully.'
            ];
        } else {
            $saveStatus = [
                'success' => false,
                'message' => 'Unable to save recording setting.'
            ];
        }
        $saveStmt->close();
    } else {
        $saveStatus = [
            'success' => false,
            'message' => 'Unable to prepare recording setting update.'
        ];
    }
}

$recorderHost = $recorder_ssh_host ?? '';
$recorderSshUser = $recorder_ssh_username ?? '';
$recorderSshPassword = $recorder_ssh_password ?? '';

// AJAX response handler – return JSON when called with ?ajax=1
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    // remoteFileSections and remoteFileError will be populated later in the script
    // ensure we run the connection logic even for ajax so they exist
}

if (!function_exists('ssh2_connect')) {
    $remoteFileError = 'SSH2 extension is not installed on the server.';
} elseif (empty($recorderHost) || empty($recorderSshUser) || empty($recorderSshPassword)) {
    $remoteFileError = 'Recorder server connection details are missing.';
} else {
    $connection = @ssh2_connect($recorderHost, 22);
    if (!$connection) {
        $remoteFileError = 'Could not connect to recorder server.';
    } elseif (!@ssh2_auth_password($connection, $recorderSshUser, $recorderSshPassword)) {
        $remoteFileError = 'Authentication failed while connecting to recorder server.';
    } else {
        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {
            $remoteFileError = 'Could not initialize SFTP for recorder server.';
        } else {
            $requiredDirectories = [
                $userStorageDir,
            ];
            foreach ($requiredDirectories as $requiredDirectory) {
                if (!ensureRemoteDirectory($sftp, $requiredDirectory)) {
                    $remoteFileError = 'Could not create one or more recorder directories for this user.';
                    break;
                }
            }
            $directoryCandidates = [
                $userStorageDir,
            ];
            if (!$remoteFileError) {
                foreach ($directoryCandidates as $candidate) {
                    $files = listRemoteDirectoryFiles($sftp, $candidate);
                    if (!empty($files)) {
                        $remoteFileSections[] = [
                            'directory' => $candidate,
                            'files' => $files,
                        ];
                    }
                }
                if (empty($remoteFileSections)) {
                    $remoteFileError = 'No files found in recorder directories for this user yet.';
                }
            }
        }
    }
}
// if this is an ajax request, return the JSON now and exit
if (isset($_GET['ajax'])) {
    echo json_encode([
        'remoteFileError' => $remoteFileError,
        'remoteFileSections' => $remoteFileSections
    ]);
    exit;
}
ob_start();
?>
<?php if ($saveStatus): ?>
    <div class="notification <?= $saveStatus['success'] ? 'is-success' : 'is-danger' ?> is-light mb-4">
        <?= htmlspecialchars($saveStatus['message']) ?>
    </div>
<?php endif; ?>
<div class="card">
    <header class="card-header">
        <p class="card-header-title is-size-5">
            <span class="icon mr-2"><i class="fas fa-video"></i></span>
            Recording
        </p>
    </header>
    <div class="card-content">
        <div class="content mb-5">
            <h2 class="is-size-5 mb-3">
                <span class="icon mr-2"><i class="fas fa-info-circle"></i></span>
                Recording Overview
            </h2>
            <ul>
                <li>Enable auto-recording to capture your Twitch stream while you are live.</li>
                <li>Your recordings are handled automatically based on your channel setting below.</li>
            </ul>
            <h3 class="is-size-6 has-text-weight-semibold mt-4 mb-2">Auto Record from Twitch</h3>
            <p>When enabled, your Twitch stream is recorded automatically while you are live.</p>
            <ul>
                <li>Everything sent to Twitch during your live stream is included in the recording.</li>
            </ul>
            <h3 class="is-size-6 has-text-weight-semibold mt-4 mb-2">Storage Info</h3>
            <ul>
                <li><?= t('streaming_storage_info_retention') ?></li>
                <li><?= t('streaming_storage_info_deletion') ?></li>
                <li><?= t('streaming_auto_record_vod_speed') ?></li>
            </ul>
            <h3 class="is-size-6 has-text-weight-semibold mt-4 mb-2">Audio Info</h3>
            <ul>
                <li>Audio Track 1 (live audio) is recorded.</li>
                <li>If you use a separate audio stream for Twitch VODs and Clips, it is not captured here.</li>
                <li>Support for downloading VOD audio through Twitch is planned for a future update.</li>
            </ul>
            <hr>
            <form method="post" action="">
                <div class="field">
                    <div class="control">
                        <label class="checkbox">
                            <input type="checkbox" name="auto_record" <?= $autoRecordEnabled ? 'checked' : '' ?>>
                            Enable channel recording
                        </label>
                    </div>
                </div>
                <div class="field is-grouped is-grouped-right mt-4">
                    <div class="control">
                        <button type="submit" name="save_recording_settings" class="button is-primary">
                            <span class="icon"><i class="fas fa-save"></i></span>
                            <span>Save</span>
                        </button>
                    </div>
                </div>
            </form>
            <h3 class="is-size-6 has-text-weight-semibold mt-4 mb-2">Files on Recorder Server</h3>
            <div id="remote-files-container">
                <?php if ($remoteFileError): ?>
                    <div class="notification is-warning is-light">
                        <?= htmlspecialchars($remoteFileError) ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($remoteFileSections as $section): ?>
                        <div class="table-container mb-4">
                            <table class="table is-fullwidth is-striped is-hoverable">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($section['files'] as $file): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($file['name']) ?></code></td>
                                            <td>
                                                <?php if ($file['is_directory']): ?>
                                                    Directory
                                                <?php elseif (!empty($file['is_partial'])): ?>
                                                    Recording (In Progress)
                                                <?php else: ?>
                                                    File
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $file['is_directory'] ? '-' : htmlspecialchars(formatBytes($file['size'])) ?></td>
                                            <td>
                                                <?= $file['modified'] ? htmlspecialchars(date('d-m-Y H:i:s', (int)$file['modified'])) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('remote-files-container');
    if (!container) {
        return;
    }
    function formatBytes(bytes) {
        var value = Number(bytes) || 0;
        if (value < 1024) {
            return value + ' B';
        }
        if (value < 1024 * 1024) {
            return (Math.round((value / 1024) * 100) / 100) + ' KB';
        }
        if (value < 1024 * 1024 * 1024) {
            return (Math.round((value / (1024 * 1024)) * 100) / 100) + ' MB';
        }
        return (Math.round((value / (1024 * 1024 * 1024)) * 100) / 100) + ' GB';
    }
    function formatDate(unixTimestamp) {
        var timestamp = Number(unixTimestamp);
        if (!timestamp) {
            return '-';
        }
        var date = new Date(timestamp * 1000);
        if (Number.isNaN(date.getTime())) {
            return '-';
        }
        var pad = function (value) {
            return String(value).padStart(2, '0');
        };
        return pad(date.getDate()) + '-' + pad(date.getMonth() + 1) + '-' + date.getFullYear() + ' ' +
            pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }
    function buildTable(section) {
        var tableContainer = document.createElement('div');
        tableContainer.className = 'table-container mb-4';
        var table = document.createElement('table');
        table.className = 'table is-fullwidth is-striped is-hoverable';
        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');
        ['File', 'Type', 'Size', 'Modified'].forEach(function (heading) {
            var th = document.createElement('th');
            th.textContent = heading;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        var tbody = document.createElement('tbody');
        (section.files || []).forEach(function (file) {
            var row = document.createElement('tr');
            var fileCell = document.createElement('td');
            var code = document.createElement('code');
            code.textContent = file.name || '';
            fileCell.appendChild(code);
            row.appendChild(fileCell);
            var typeCell = document.createElement('td');
            if (file.is_directory) {
                typeCell.textContent = 'Directory';
            } else if (file.is_partial) {
                typeCell.textContent = 'Recording (In Progress)';
            } else {
                typeCell.textContent = 'File';
            }
            row.appendChild(typeCell);
            var sizeCell = document.createElement('td');
            sizeCell.textContent = file.is_directory ? '-' : formatBytes(file.size);
            row.appendChild(sizeCell);
            var modifiedCell = document.createElement('td');
            modifiedCell.textContent = formatDate(file.modified);
            row.appendChild(modifiedCell);
            tbody.appendChild(row);
        });
        table.appendChild(thead);
        table.appendChild(tbody);
        tableContainer.appendChild(table);
        return tableContainer;
    }
    function renderRemoteFiles(data) {
        container.innerHTML = '';
        if (data && data.remoteFileError) {
            var notice = document.createElement('div');
            notice.className = 'notification is-warning is-light';
            notice.textContent = data.remoteFileError;
            container.appendChild(notice);
            return;
        }
        var sections = data && Array.isArray(data.remoteFileSections) ? data.remoteFileSections : [];
        if (!sections.length) {
            var empty = document.createElement('div');
            empty.className = 'notification is-warning is-light';
            empty.textContent = 'No files found in recorder directories for this user yet.';
            container.appendChild(empty);
            return;
        }
        sections.forEach(function (section) {
            container.appendChild(buildTable(section));
        });
    }
    var isLoading = false;
    function refreshRemoteFiles() {
        if (isLoading) {
            return;
        }
        isLoading = true;
        var url = new URL(window.location.href);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('_ts', String(Date.now()));
        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                renderRemoteFiles(data);
            })
            .catch(function () {
                var notice = document.createElement('div');
                notice.className = 'notification is-warning is-light';
                notice.textContent = 'Unable to refresh recorder files right now.';
                container.innerHTML = '';
                container.appendChild(notice);
            })
            .finally(function () {
                isLoading = false;
            });
    }
    window.setInterval(refreshRemoteFiles, 60000);
});
</script>
<?php
$content = ob_get_clean();
include 'layout.php';
?>