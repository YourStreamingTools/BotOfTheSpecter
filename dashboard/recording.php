<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    // For AJAX requests, return JSON error instead of redirecting
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode([
            'error' => 'Session expired',
            'remoteFileError' => 'Your session has expired. Please refresh the page to log in again.',
            'remoteFileSections' => []
        ]);
        exit();
    }
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

function isSafeRecorderFileName($fileName) {
    if (!is_string($fileName) || $fileName === '') {
        return false;
    }
    if (strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false || strpos($fileName, "\0") !== false) {
        return false;
    }
    if ($fileName === '.' || $fileName === '..') {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1) {
        return false;
    }
    return basename($fileName) === $fileName;
}

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
        if (substr($fileName, -10) === '.ytdlp.log') {
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

// AJAX response handler - set header early and wrap logic in try-catch
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    // remoteFileSections and remoteFileError will be populated later in the script
    // ensure we run the connection logic even for ajax so they exist
}

// Wrap SSH connection logic in try-catch for AJAX error handling
try {
if (!function_exists('ssh2_connect')) {
    $remoteFileError = 'SSH2 extension is not installed on the server.';
} elseif (empty($recorderHost) || empty($recorderSshUser) || empty($recorderSshPassword)) {
    $remoteFileError = 'Recorder server connection details are missing.';
} else {
    $connection = @ssh2_connect($recorderHost, 22);
    if (!$connection) {
        $remoteFileError = 'Could not connect to recorder server. Try refreshing the page or check back later.';
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
            if (!$remoteFileError && isset($_GET['download']) && $_GET['download'] === '1') {
                $requestedFileName = isset($_GET['file']) ? (string)$_GET['file'] : '';
                if (!isSafeRecorderFileName($requestedFileName)) {
                    http_response_code(400);
                    echo 'Invalid file name.';
                    exit;
                }
                $isMp4 = strtolower((string)pathinfo($requestedFileName, PATHINFO_EXTENSION)) === 'mp4';
                if (!$isMp4) {
                    http_response_code(400);
                    echo 'Only MP4 files can be downloaded.';
                    exit;
                }
                $fullRemotePath = rtrim($userStorageDir, '/') . '/' . $requestedFileName;
                $stat = @ssh2_sftp_stat($sftp, $fullRemotePath);
                if (!$stat) {
                    http_response_code(404);
                    echo 'File not found.';
                    exit;
                }
                $isDirectory = isset($stat['mode']) && (($stat['mode'] & 0x4000) === 0x4000);
                $isPartial = substr($requestedFileName, -5) === '.part';
                if ($isDirectory || $isPartial) {
                    http_response_code(400);
                    echo 'This file is not available for download.';
                    exit;
                }
                $streamPath = 'ssh2.sftp://' . intval($sftp) . $fullRemotePath;
                $streamHandle = @fopen($streamPath, 'rb');
                if (!$streamHandle) {
                    http_response_code(500);
                    echo 'Unable to open file for download.';
                    exit;
                }
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Description: File Transfer');
                header('Content-Type: video/mp4');
                header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($requestedFileName)) . '"');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                if (isset($stat['size'])) {
                    header('Content-Length: ' . (int)$stat['size']);
                }
                $chunkSize = 8192;
                while (!feof($streamHandle)) {
                    echo fread($streamHandle, $chunkSize);
                }
                fclose($streamHandle);
                exit;
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
} catch (Exception $e) {
    // Catch any errors during SSH connection for AJAX requests
    if (isset($_GET['ajax'])) {
        $remoteFileError = 'An error occurred while connecting to the recorder server. Please try again later.';
        error_log('Recording.php AJAX error: ' . $e->getMessage());
    } else {
        // Re-throw for non-AJAX requests to show proper error page
        throw $e;
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
    <div class="sp-alert <?= $saveStatus['success'] ? 'sp-alert-success' : 'sp-alert-danger' ?> mb-4">
        <?= htmlspecialchars($saveStatus['message']) ?>
    </div>
<?php endif; ?>
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-video"></i></span>
            Recording
        </p>
    </header>
    <div class="sp-card-body">
        <div class="content mb-5">
            <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:0.75rem;">
                <span class="icon mr-2"><i class="fas fa-info-circle"></i></span>
                Recording Overview
            </h2>
            <ul>
                <li>Enable auto-recording to capture your Twitch stream while you are live.</li>
                <li>Your recordings are handled automatically based on your channel setting below.</li>
            </ul>
            <h3 style="font-size:0.95rem;font-weight:700;margin:1.25rem 0 0.5rem;">Auto Record from Twitch</h3>
            <p>When enabled, your Twitch stream is recorded automatically while you are live.</p>
            <ul>
                <li>Everything sent to Twitch during your live stream is included in the recording.</li>
            </ul>
            <h3 style="font-size:0.95rem;font-weight:700;margin:1.25rem 0 0.5rem;">Storage Info</h3>
            <ul>
                <li><?= t('streaming_storage_info_retention') ?></li>
                <li><?= t('streaming_storage_info_deletion') ?></li>
                <li><?= t('streaming_auto_record_vod_speed') ?></li>
            </ul>
            <h3 style="font-size:0.95rem;font-weight:700;margin:1.25rem 0 0.5rem;">Audio Info</h3>
            <ul>
                <li>Audio Track 1 (live audio) is recorded.</li>
                <li>If you use a separate audio stream for Twitch VODs and Clips, it is not captured here.</li>
                <li>Support for downloading VOD audio through Twitch is planned for a future update.</li>
            </ul>
            <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">
            <form method="post" action="">
                <div class="sp-form-group" style="display:flex;align-items:center;gap:1rem;">
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin:0;">
                        <input type="checkbox" name="auto_record" <?= $autoRecordEnabled ? 'checked' : '' ?>>
                        Enable channel recording
                    </label>
                    <button type="submit" name="save_recording_settings" class="sp-btn sp-btn-primary sp-btn-sm">
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save</span>
                    </button>
                </div>
            </form>
            <div style="display:flex;align-items:center;justify-content:space-between;margin:1.25rem 0 0.5rem;">
                <h3 style="font-size:0.95rem;font-weight:700;margin:0;">Files on Recorder Server</h3>
                <button type="button" id="refresh-remote-files-btn" class="sp-btn sp-btn-secondary sp-btn-sm">
                    <span class="icon"><i class="fas fa-sync-alt"></i></span>
                    <span>Refresh</span>
                </button>
            </div>
            <div id="remote-files-container">
                <?php if ($remoteFileError): ?>
                    <div class="sp-alert sp-alert-warning">
                        <?= htmlspecialchars($remoteFileError) ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($remoteFileSections as $section): ?>
                        <div class="sp-table-wrap mb-4">
                            <table class="sp-table">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Modified</th>
                                        <th>Action</th>
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
                                            <td>
                                                <?php if (!$file['is_directory'] && empty($file['is_partial']) && strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION)) === 'mp4'): ?>
                                                    <a class="sp-btn sp-btn-primary sp-btn-sm download-link" data-download-link="1" href="recording.php?download=1&amp;file=<?= rawurlencode($file['name']) ?>">
                                                        <span class="icon"><i class="fas fa-download"></i></span>
                                                        <span class="download-label">Download</span>
                                                    </a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
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
        tableContainer.className = 'sp-table-wrap mb-4';
        var table = document.createElement('table');
        table.className = 'sp-table';
        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');
        ['File', 'Type', 'Size', 'Modified', 'Action'].forEach(function (heading) {
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
            var actionCell = document.createElement('td');
            var fileName = String(file.name || '');
            var lowerName = fileName.toLowerCase();
            var canDownload = !file.is_directory && !file.is_partial && lowerName.endsWith('.mp4');
            if (canDownload) {
                var link = document.createElement('a');
                link.className = 'sp-btn sp-btn-primary sp-btn-sm download-link';
                link.setAttribute('data-download-link', '1');
                link.href = window.location.pathname + '?download=1&file=' + encodeURIComponent(fileName);
                var icon = document.createElement('span');
                icon.className = 'icon';
                var iconElement = document.createElement('i');
                iconElement.className = 'fas fa-download';
                icon.appendChild(iconElement);
                var label = document.createElement('span');
                label.className = 'download-label';
                label.textContent = 'Download';
                link.appendChild(icon);
                link.appendChild(label);
                actionCell.appendChild(link);
            } else {
                actionCell.textContent = '-';
            }
            row.appendChild(actionCell);
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
            notice.className = 'sp-alert sp-alert-warning';
            notice.textContent = data.remoteFileError;
            container.appendChild(notice);
            return;
        }
        var sections = data && Array.isArray(data.remoteFileSections) ? data.remoteFileSections : [];
        if (!sections.length) {
            var empty = document.createElement('div');
            empty.className = 'sp-alert sp-alert-warning';
            empty.textContent = 'No files found in recorder directories for this user yet.';
            container.appendChild(empty);
            return;
        }
        sections.forEach(function (section) {
            container.appendChild(buildTable(section));
        });
    }
    var isLoading = false;
    var refreshBtn = document.getElementById('refresh-remote-files-btn');
    function setRefreshLoading(loading) {
        if (!refreshBtn) { return; }
        var icon = refreshBtn.querySelector('.icon i');
        if (loading) {
            refreshBtn.setAttribute('disabled', 'disabled');
            if (icon) { icon.className = 'fas fa-sync-alt fa-spin'; }
        } else {
            refreshBtn.removeAttribute('disabled');
            if (icon) { icon.className = 'fas fa-sync-alt'; }
        }
    }
    function refreshRemoteFiles() {
        if (isLoading) {
            return;
        }
        isLoading = true;
        setRefreshLoading(true);
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
                return response.json().catch(function () {
                    throw new Error('HTTP ' + response.status);
                }).then(function (errorData) {
                    if (errorData && errorData.error === 'Session expired') {
                        throw new Error('SESSION_EXPIRED');
                    }
                    throw new Error('HTTP ' + response.status);
                });
            }
            return response.json().catch(function () {
                throw new Error('INVALID_JSON');
            });
        })
        .then(function (data) {
            if (data && typeof data === 'object') {
                renderRemoteFiles(data);
            } else {
                throw new Error('INVALID_RESPONSE');
            }
        })
        .catch(function (error) {
            if (error && error.message === 'SESSION_EXPIRED') {
                var notice = document.createElement('div');
                notice.className = 'sp-alert sp-alert-warning';
                notice.innerHTML = 'Your session has expired. <a href="' + window.location.pathname + '" style="text-decoration:underline;">Click here to reload the page</a> and log in again.';
                container.innerHTML = '';
                container.appendChild(notice);
            }
            // All other errors: keep existing content visible, user can retry with the Refresh button
        })
        .finally(function () {
            isLoading = false;
            setRefreshLoading(false);
        });
    }
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshRemoteFiles();
        });
    }
    container.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var link = target.closest('a[data-download-link="1"]');
        if (!link || !(link instanceof HTMLAnchorElement)) {
            return;
        }
        if (link.classList.contains('sp-btn-loading')) {
            event.preventDefault();
            return;
        }
        link.classList.add('sp-btn-loading');
        link.setAttribute('aria-busy', 'true');
        var label = link.querySelector('.download-label');
        if (label) {
            label.textContent = 'Preparing...';
        }
        window.setTimeout(function () {
            link.classList.remove('sp-btn-loading');
            link.removeAttribute('aria-busy');
            if (label) {
                label.textContent = 'Download';
            }
        }, 12000);
    });
    window.setInterval(refreshRemoteFiles, 60000);
});
</script>
<?php
$content = ob_get_clean();
include 'layout.php';
?>