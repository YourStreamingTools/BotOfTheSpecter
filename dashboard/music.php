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
$pageTitle = "Music Dashboard";

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

// Read persisted music source preference (defaults to 'system')
$music_source = 'system';
try {
    $prefRes = $db->query("SELECT music_source FROM streamer_preferences WHERE id = 1");
    if ($prefRes && ($row = $prefRes->fetch_assoc()) && isset($row['music_source'])) {
        $music_source = $row['music_source'];
        // enforce allowed values
        if (!in_array($music_source, ['system', 'user'])) $music_source = 'system';
    }
} catch (Exception $e) {
    // keep default if column missing or query fails
}

// Fetch the files from the local music directory with metadata
function getLocalMusicFiles() {
    $musicDir = '/var/www/cdn/music';
    $files = [];
    if (is_dir($musicDir)) {
        $musicFiles = scandir($musicDir);
        foreach ($musicFiles as $file) {
            if (str_ends_with($file, '.mp3')) {
                $fullPath = $musicDir . '/' . $file;
                $files[] = [
                    'filename' => $file,
                    'title' => pathinfo($file, PATHINFO_FILENAME),
                    'size' => file_exists($fullPath) ? filesize($fullPath) : 0,
                ];
            }
        }
    }
    // Sort alphabetically by title
    usort($files, function($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });
    return $files;
}

// Fetch music files from local directory
$musicFiles = getLocalMusicFiles();

// ---------------------------
// User-uploaded music handling
// ---------------------------
// $user_music_path is provided by storage_used.php (e.g. /var/www/private/music_user/<username>)
$userMusicStatus = '';
// Handle uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['userMusicFiles'])) {
    foreach ($_FILES['userMusicFiles']['tmp_name'] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;
        $origName = $_FILES['userMusicFiles']['name'][$key];
        $fileSize = $_FILES['userMusicFiles']['size'][$key];
        $fileError = $_FILES['userMusicFiles']['error'][$key];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'mp3') {
            $userMusicStatus .= "Failed to upload " . htmlspecialchars($origName) . ". Only MP3 files are allowed.<br>";
            continue;
        }
        // Check storage limits
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $userMusicStatus .= "Failed to upload " . htmlspecialchars($origName) . ". Storage limit exceeded.<br>";
            continue;
        }
        if ($fileError !== 0) {
            $userMusicStatus .= "Error uploading " . htmlspecialchars($origName) . ". Error code: $fileError<br>";
            continue;
        }
        // Ensure user music path exists (storage_used.php creates it but double-check)
        if (!is_dir($user_music_path)) {
            mkdir($user_music_path, 0755, true);
        }
        // Prevent overwrites: append timestamp when filename exists
        $safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', basename($origName));
        $target = $user_music_path . '/' . $safeName;
        if (is_file($target)) {
            $base = pathinfo($safeName, PATHINFO_FILENAME);
            $target = $user_music_path . '/' . $base . '-' . time() . '.mp3';
        }
        if (move_uploaded_file($tmp_name, $target)) {
            $current_storage_used += filesize($target);
            $userMusicStatus .= "Uploaded: " . htmlspecialchars(basename($target)) . "<br>";
            // Ensure a public copy/symlink exists under /var/www/usermusic/<username> so
            // overlays and external players can fetch the file via music.botspecter.com
            if (isset($public_user_music_path)) {
                $publicTarget = $public_user_music_path . '/' . basename($target);
                // Create or replace existing public entry
                if (is_link($publicTarget) || is_file($publicTarget)) {
                    @unlink($publicTarget);
                }
                // Prefer a symlink to avoid duplicating storage; fall back to copy if symlink not allowed
                if (!@symlink($target, $publicTarget)) {
                    // Copy file to public dir as fallback
                    @copy($target, $publicTarget);
                }
                @chmod($publicTarget, 0644);
            }
        } else {
            $userMusicStatus .= "Failed to move uploaded file " . htmlspecialchars($origName) . ".<br>";
        }
    }
    // Recalculate storage percentage
    if ($max_storage_size > 0) {
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
    }
}

// Handle deletion of user-uploaded music
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_music'])) {
    $toDelete = (array) $_POST['delete_user_music'];
    foreach ($toDelete as $d) {
        $file = basename($d);
        $full = $user_music_path . '/' . $file;
        $fileSizeBefore = is_file($full) ? filesize($full) : 0;
        if (is_file($full) && unlink($full)) {
            $userMusicStatus .= "Deleted: " . htmlspecialchars($file) . "<br>";
            $current_storage_used -= $fileSizeBefore;
            if ($current_storage_used < 0) $current_storage_used = 0;
            // Also remove public copy/symlink if present
            if (isset($public_user_music_path)) {
                $publicFile = $public_user_music_path . '/' . $file;
                if (is_link($publicFile) || is_file($publicFile)) {
                    @unlink($publicFile);
                }
            }
        } else {
            $userMusicStatus .= "Failed to delete " . htmlspecialchars($file) . ".<br>";
        }
    }
    if ($max_storage_size > 0) {
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
    }
}

// Fetch user-uploaded music files (private to uploader)
function getUserMusicFiles($dir) {
    $files = [];
    if (!is_dir($dir)) return $files;
    $entries = scandir($dir);
    foreach ($entries as $f) {
        if (str_ends_with($f, '.mp3')) {
            $full = $dir . '/' . $f;
            $files[] = [
                'filename' => $f,
                'title' => pathinfo($f, PATHINFO_FILENAME),
                'size' => file_exists($full) ? filesize($full) : 0,
            ];
        }
    }
    usort($files, function($a, $b) { return strcasecmp($a['title'], $b['title']); });
    return $files;
}

$userMusicFiles = [];
if (isset($user_music_path)) {
    $userMusicFiles = getUserMusicFiles($user_music_path);
    // Reconcile public user music directory so existing uploads are reachable at
    // https://music.botspecter.com/<username>/<file.mp3>
    if (isset($public_user_music_path) && is_dir($public_user_music_path)) {
        foreach ($userMusicFiles as $f) {
            $privateFile = $user_music_path . '/' . $f['filename'];
            $publicFile = $public_user_music_path . '/' . $f['filename'];
            if (!file_exists($publicFile) && file_exists($privateFile)) {
                // Prefer symlink, fall back to copy
                if (!@symlink($privateFile, $publicFile)) {
                    @copy($privateFile, $publicFile);
                }
                @chmod($publicFile, 0644);
            }
        }
    }
}

// Build playlist for client: prefix user files with "USER:" so JS serves them via secure endpoint
$playlistForJs = [];
foreach ($userMusicFiles as $f) { $playlistForJs[] = 'USER:' . $f['filename']; }
foreach ($musicFiles as $f) { $playlistForJs[] = $f['filename']; }

// Server-side visible counts & initial visibility (so page load matches DB preference)
$serverVisibleCount = ($music_source === 'user') ? count($userMusicFiles) : count($musicFiles);
$visibleIndex = 0;

ob_start();
?>
<!-- Page Header -->
<div style="text-align:center;margin-bottom:2rem;">
    <h1 style="font-size:2rem;font-weight:700;color:var(--text-primary);display:inline-flex;align-items:center;gap:0.5rem;">
        <i class="fas fa-music"></i>
        <?php echo t('music_dashboard_title'); ?>
    </h1>
    <p style="color:var(--text-muted);margin-top:0.5rem;"><?php echo t('music_dashboard_subtitle'); ?></p>
</div>
<!-- Player Card -->
<div class="sp-card mb-4">
    <div class="sp-card-body">
        <div style="display:flex;flex-wrap:wrap;gap:2rem;align-items:center;justify-content:center;">
            <!-- Now Playing -->
            <div style="flex:1;min-width:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:0.5rem;"><?php echo t('music_now_playing'); ?></h2>
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
                    <p id="now-playing" style="margin:0;"><?php echo t('music_no_song_playing'); ?></p>
                    <button id="refresh-now-playing" class="sp-btn sp-btn-ghost sp-btn-sm" title="<?php echo t('music_refresh_now_playing'); ?>">
                        <span class="icon"><i class="fas fa-sync-alt"></i></span>
                    </button>
                </div>
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.875rem;">
                    <input type="checkbox" id="local-playback-toggle">
                    <?php echo t('music_play_locally'); ?>
                </label>
            </div>
            <!-- Controls -->
            <div style="flex:1;min-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
                <!-- Transport buttons -->
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <button id="prev-btn" class="sp-btn sp-btn-ghost" style="border-radius:50%;width:2.5rem;height:2.5rem;padding:0;display:flex;align-items:center;justify-content:center;">
                        <span class="icon"><i class="fas fa-step-backward"></i></span>
                    </button>
                    <button id="play-pause-btn" class="sp-btn sp-btn-success" style="border-radius:50%;width:3rem;height:3rem;padding:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
                        <span class="icon"><i id="play-pause-icon" class="fas fa-play"></i></span>
                    </button>
                    <button id="next-btn" class="sp-btn sp-btn-ghost" style="border-radius:50%;width:2.5rem;height:2.5rem;padding:0;display:flex;align-items:center;justify-content:center;">
                        <span class="icon"><i class="fas fa-step-forward"></i></span>
                    </button>
                </div>
                <!-- Repeat / Shuffle -->
                <div style="display:flex;gap:0.5rem;">
                    <button id="repeat-btn" class="sp-btn sp-btn-ghost sp-btn-sm" type="button" style="border-radius:1rem;">
                        <span class="icon is-small"><i class="fas fa-redo"></i></span>
                        <span>Repeat 1</span>
                    </button>
                    <button id="shuffle-btn" class="sp-btn sp-btn-ghost sp-btn-sm" type="button" style="border-radius:1rem;">
                        <span class="icon is-small"><i class="fas fa-random"></i></span>
                        <span><?php echo t('music_shuffle'); ?></span>
                    </button>
                </div>
                <!-- Volume -->
                <div style="display:flex;align-items:center;gap:0.75rem;width:100%;max-width:360px;">
                    <label class="sp-label" style="min-width:60px;margin-bottom:0;"><?php echo t('music_volume'); ?></label>
                    <input id="volume-range" type="range" min="0" max="100" value="0" class="modern-volume" style="flex:1;">
                    <input id="volume-percentage" type="number" min="0" max="100" class="sp-input" value="0" style="width:60px;text-align:center;padding:0.25rem 0.4rem;">
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Upload Card -->
<div class="sp-card mb-4">
    <header class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-upload"></i>
            <?php echo t('music_upload_file'); ?>
        </span>
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <label class="sp-label" style="margin-bottom:0;white-space:nowrap;">Music source</label>
            <select id="music-source-select" class="sp-select" style="width:auto;">
                <option value="system" <?php echo ($music_source === 'system') ? 'selected' : ''; ?>>Built-in (DMCA-free)</option>
                <option value="user" <?php echo ($music_source === 'user') ? 'selected' : ''; ?>>Use my uploads</option>
            </select>
        </div>
    </header>
    <div class="sp-card-body">
        <!-- Disclaimer -->
        <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
            <i class="fas fa-exclamation-triangle"></i>
            You are responsible for all files you upload and must have the legal rights to use and share them. We do not verify or guarantee rights clearance.
        </div>
        <!-- Storage Usage Info -->
        <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                <span><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)</span>
            </div>
            <progress class="progress" value="<?php echo $storage_percentage; ?>" max="100" style="width:100%;"></progress>
        </div>
        <?php if (!empty($userMusicStatus)) : ?>
            <div class="sp-alert sp-alert-info sp-notif" style="margin-bottom:1rem;">
                <?php echo $userMusicStatus; ?>
            </div>
        <?php endif; ?>
        <form id="userMusicUploadForm" action="" method="POST" enctype="multipart/form-data">
            <div class="sp-form-group">
                <label for="userMusicFiles" style="display:block;border:2px dashed var(--border);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;cursor:pointer;background:var(--bg-input);transition:border-color var(--transition);color:var(--text-secondary);">
                    <i class="fas fa-cloud-upload-alt" style="font-size:2rem;margin-bottom:0.5rem;display:block;"></i>
                    <span id="user-music-file-list"><?php echo t('sound_alerts_no_files_selected'); ?></span>
                    <div style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);"><?php echo t('sound_alerts_choose_files'); ?></div>
                    <input type="file" name="userMusicFiles[]" id="userMusicFiles" multiple accept=".mp3" style="display:none;">
                </label>
            </div>
            <!-- Upload Status Container -->
            <div id="userUploadProgressContainer" style="display:none;margin-bottom:1rem;">
                <div class="sp-alert sp-alert-info">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                        <strong id="userUploadStatusText">Preparing upload...</strong>
                        <span id="userUploadProgressPercent" style="font-weight:600;">0%</span>
                    </div>
                    <progress class="progress" id="userUploadProgress" value="0" max="100" style="width:100%;">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary" type="submit" id="userUploadBtn" style="width:100%;font-size:1.1rem;">
                <i class="fas fa-upload"></i>
                <span id="userUploadBtnText"><?php echo t('music_upload_file'); ?></span>
            </button>
        </form>
        <div id="userUploadResponse" style="display:none;margin-top:0.75rem;"></div>
    </div>
</div>
<!-- Playlist Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-list-music"></i></span>
            <?php echo t('music_playlist'); ?>
        </p>
        <span id="playlistCountTag" class="sp-badge sp-badge-blue" data-label="<?php echo t('music_songs'); ?>"><?php echo $serverVisibleCount; ?> <?php echo t('music_songs'); ?></span>
    </header>
    <div class="sp-card-body">
        <!-- Search -->
        <div class="sp-form-group" style="margin-bottom:0.75rem;">
            <div style="position:relative;">
                <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;">
                    <i class="fas fa-search"></i>
                </span>
                <input id="searchInput" class="sp-input" type="text" placeholder="<?php echo t('music_search_playlist'); ?>" style="padding-left:2.25rem;">
            </div>
        </div>
        <div class="sp-table-wrap playlist-container">
            <table class="sp-table" id="playlistTable">
                <thead>
                    <tr>
                        <th style="width:3rem;text-align:center;">#</th>
                        <th>
                            <span class="icon is-small mr-1"><i class="fas fa-music"></i></span>
                            <?php echo t('music_title'); ?>
                        </th>
                        <th style="text-align:right;white-space:nowrap;"><?php echo t('music_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="playlistBody">
                    <?php /* Render user uploads first (private to uploader) */ ?>
                    <?php foreach ($userMusicFiles as $uIndex => $fileData):
                        $index = $uIndex;
                        $isVisible = ($music_source === 'user');
                        if ($isVisible) { $visibleIndex++; }
                        $displayNumber = $isVisible ? $visibleIndex : '';
                        $displayStyle = $isVisible ? 'cursor:pointer;' : 'cursor:pointer;display:none;'; ?>
                        <tr data-index="<?php echo $index; ?>"
                            data-file="<?php echo htmlspecialchars('USER:' . $fileData['filename']); ?>"
                            data-title="<?php echo htmlspecialchars(strtolower($fileData['title'])); ?>"
                            class="playlist-row user-upload" style="<?php echo $displayStyle; ?>">
                            <td style="text-align:center;font-weight:600;color:var(--text-muted);">
                                <span class="row-number"><?php echo $displayNumber; ?></span>
                                <span class="now-playing-icon" style="display:none;">
                                    <i class="fas fa-play-circle" style="color:var(--green);"></i>
                                </span>
                            </td>
                            <td class="song-title">
                                <?php echo htmlspecialchars($fileData['title']); ?> <span class="sp-badge sp-badge-grey" style="margin-left:0.5rem;font-size:0.75rem;">Your upload</span>
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <button class="sp-btn sp-btn-ghost sp-btn-sm play-song-btn" data-index="<?php echo $index; ?>" title="Play">
                                    <span class="icon is-small"><i class="fas fa-play"></i></span>
                                </button>
                                <button class="sp-btn sp-btn-danger sp-btn-sm delete-user-music" data-file="<?php echo htmlspecialchars($fileData['filename']); ?>" title="Delete">
                                    <span class="icon is-small"><i class="fas fa-trash"></i></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php /* Now render global DMCA-free tracks */ ?>
                    <?php foreach ($musicFiles as $gIndex => $fileData):
                        $index = count($userMusicFiles) + $gIndex;
                        $isVisible = ($music_source !== 'user');
                        if ($isVisible) { $visibleIndex++; }
                        $displayNumber = $isVisible ? $visibleIndex : '';
                        $displayStyle = $isVisible ? 'cursor:pointer;' : 'cursor:pointer;display:none;'; ?>
                        <tr data-index="<?php echo $index; ?>"
                            data-file="<?php echo htmlspecialchars($fileData['filename']); ?>"
                            data-title="<?php echo htmlspecialchars(strtolower($fileData['title'])); ?>"
                            class="playlist-row" style="<?php echo $displayStyle; ?>">
                            <td style="text-align:center;font-weight:600;color:var(--text-muted);">
                                <span class="row-number"><?php echo $displayNumber; ?></span>
                                <span class="now-playing-icon" style="display:none;">
                                    <i class="fas fa-play-circle" style="color:var(--green);"></i>
                                </span>
                            </td>
                            <td class="song-title">
                                <?php echo htmlspecialchars($fileData['title']); ?>
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <button class="sp-btn sp-btn-ghost sp-btn-sm play-song-btn" data-index="<?php echo $index; ?>" title="Play">
                                    <span class="icon is-small"><i class="fas fa-play"></i></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Hidden audio element for local playback -->
<audio id="audio-player" style="display:none;"></audio>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    // ===== STATE MANAGEMENT =====
    // Current uploader name (used to build public user-music URLs)
    const uploaderName = '<?php echo addslashes($_SESSION['username'] ?? ''); ?>';
    const MusicPlayer = {
        socket: null,
        reconnectAttempts: 0,
        retryInterval: 5000,
        maxRetryDelay: 30000,
        // Player state
        state: {
            isPlaying: false,
            volume: 10,
            volumeInitialized: false,
            repeat: false,
            shuffle: false,
            localPlayback: false,
            currentIndex: -1,
            playlist: <?php echo json_encode($playlistForJs); ?>,
            currentSong: null,
        },
        // DOM elements cache
        elements: {},
        // Timeouts
        timeouts: {
            settings: null,
            volumeDebounce: null,
        },
        // Event listeners tracker
        listenersInitialized: false,
    };
    // ===== UTILITY FUNCTIONS =====
    const Utils = {
        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        formatTitle(filename) {
            if (typeof filename === 'string' && filename.startsWith('USER:')) {
                filename = filename.replace(/^USER:/, '');
            }
            return filename.replace('.mp3', '').replace(/_/g, ' ');
        },
        getNextIndex(currentIndex, playlist, shuffle) {
            if (shuffle) {
                let next;
                do {
                    next = Math.floor(Math.random() * playlist.length);
                } while (playlist.length > 1 && next === currentIndex);
                return next;
            }
            return (currentIndex + 1) % playlist.length;
        },
        getPrevIndex(currentIndex, playlist) {
            return (currentIndex - 1 + playlist.length) % playlist.length;
        },
    };
    // ===== DOM FUNCTIONS =====
    const DOM = {
        cacheElements() {
            MusicPlayer.elements = {
                audioPlayer: document.getElementById('audio-player'),
                nowPlaying: document.getElementById('now-playing'),
                playPauseBtn: document.getElementById('play-pause-btn'),
                playPauseIcon: document.getElementById('play-pause-icon'),
                prevBtn: document.getElementById('prev-btn'),
                nextBtn: document.getElementById('next-btn'),
                repeatBtn: document.getElementById('repeat-btn'),
                shuffleBtn: document.getElementById('shuffle-btn'),
                volumeRange: document.getElementById('volume-range'),
                volumePercentage: document.getElementById('volume-percentage'),
                localPlaybackToggle: document.getElementById('local-playback-toggle'),
                refreshBtn: document.getElementById('refresh-now-playing'),
                searchInput: document.getElementById('searchInput'),
                playlistBody: document.getElementById('playlistBody'),
                musicSourceSelect: document.getElementById('music-source-select'),
            };
        },
        updateNowPlaying(title, isPlaying = true) {
            MusicPlayer.elements.nowPlaying.textContent = title ? `🎵 ${title}` : '<?php echo t('music_no_song_playing'); ?>';
            MusicPlayer.state.isPlaying = isPlaying;
            this.updatePlayPauseIcon(isPlaying);
        },
        updatePlayPauseIcon(isPlaying) {
            const icon = MusicPlayer.elements.playPauseIcon;
            if (isPlaying) {
                icon.classList.remove('fa-play');
                icon.classList.add('fa-pause');
            } else {
                icon.classList.remove('fa-pause');
                icon.classList.add('fa-play');
            }
        },
        updateVolume(value) {
            MusicPlayer.elements.volumeRange.value = value;
            MusicPlayer.elements.volumePercentage.value = value;
            MusicPlayer.state.volume = value;
            // Update range slider gradient
            const percent = (value / 100) * 100;
            MusicPlayer.elements.volumeRange.style.background = 
                `linear-gradient(to right, #3273dc 0%, #3273dc ${percent}%, #dbdbdb ${percent}%, #dbdbdb 100%)`;
        },
        updateButtonState(button, isActive) {
            button.classList.toggle('sp-btn-primary', isActive);
            button.classList.toggle('sp-btn-ghost', !isActive);
        },
        highlightCurrentSong(index) {
            // Remove previous highlight
            document.querySelectorAll('.playlist-row').forEach(row => {
                row.classList.remove('is-active');
                row.style.background = '';
            });
            // Add highlight to current song
            if (index >= 0) {
                const row = document.querySelector(`.playlist-row[data-index="${index}"]`);
                if (row) {
                    row.classList.add('is-active');
                    row.style.background = 'var(--bg-card-hover)';
                    // Scroll into view if needed
                    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
            MusicPlayer.state.currentIndex = index;
        },
        filterPlaylist(searchTerm) {
            const term = searchTerm.toLowerCase().trim();
            const rows = document.querySelectorAll('.playlist-row');
            rows.forEach(row => {
                const title = row.getAttribute('data-title');
                if (title.includes(term)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        },
        /* Show/hide playlist rows depending on selected music source and renumber visible rows. */
        updatePlaylistForSource(source) {
            const rows = Array.from(document.querySelectorAll('.playlist-row'));
            let visibleCount = 0;
            rows.forEach(row => {
                const isUser = row.classList.contains('user-upload');
                let shouldShow = true;
                if (source === 'user') {
                    shouldShow = isUser;
                } else {
                    // 'system' => hide user uploads
                    shouldShow = !isUser;
                }
                row.style.display = shouldShow ? '' : 'none';
                if (shouldShow) {
                    visibleCount++;
                    // update visible numbering (do NOT change data-index)
                    const numEl = row.querySelector('.row-number');
                    if (numEl) numEl.textContent = visibleCount;
                }
            });
            // If nothing is visible, show a placeholder row
            const tbody = document.getElementById('playlistBody');
            const existingPlaceholder = document.querySelector('.playlist-row.placeholder');
            if (visibleCount === 0) {
                if (!existingPlaceholder) {
                    const tr = document.createElement('tr');
                    tr.className = 'playlist-row placeholder';
                    tr.style.color = 'var(--text-muted)';
                    tr.innerHTML = `<td colspan="3" style="padding:1.25rem; text-align:center;">No tracks for the selected music source.</td>`;
                    tbody.prepend(tr);
                }
            } else if (existingPlaceholder) {
                existingPlaceholder.remove();
            }
        },
        rebuildPlaylistStateFromDOM() {
            const rows = Array.from(document.querySelectorAll('.playlist-row'));
            MusicPlayer.state.playlist = rows.map(r => r.getAttribute('data-file'));
        }
    };
    // ===== AUDIO PLAYER FUNCTIONS =====
    const AudioPlayer = {
        play(index) {
            if (index < 0 || index >= MusicPlayer.state.playlist.length) return;
            const song = MusicPlayer.state.playlist[index];
            const title = Utils.formatTitle(song);
            MusicPlayer.state.currentIndex = index;
            MusicPlayer.state.currentSong = song;
            DOM.updateNowPlaying(title, true);
            DOM.highlightCurrentSong(index);
            const audio = MusicPlayer.elements.audioPlayer;
            // Build songData to broadcast to overlays (include public URL for user uploads)
            let songData = { title };
            if (typeof song === 'string' && song.startsWith('USER:')) {
                const userFile = song.replace(/^USER:/, '');
                audio.src = `serve_user_music.php?file=${encodeURIComponent(userFile)}`;
                songData.file = userFile;
                if (uploaderName) {
                    songData.url = `https://music.botspecter.com/${encodeURIComponent(uploaderName)}/${encodeURIComponent(userFile)}`;
                }
            } else {
                audio.src = `https://cdn.botofthespecter.com/music/${encodeURIComponent(song)}`;
                songData.file = song;
            }
            // Emit NOW_PLAYING so overlays/controllers receive the full song info (including .url for user songs)
            try {
                if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                    MusicPlayer.socket.emit('NOW_PLAYING', { song: songData });
                }
            } catch (e) { console.warn('Failed to emit NOW_PLAYING', e); }
            audio.volume = MusicPlayer.state.volume / 100;
            audio.play().catch(err => console.error('Playback error:', err));
        },
        pause() {
            MusicPlayer.elements.audioPlayer.pause();
            MusicPlayer.state.isPlaying = false;
            DOM.updatePlayPauseIcon(false);
        },
        resume() {
            MusicPlayer.elements.audioPlayer.play();
            MusicPlayer.state.isPlaying = true;
            DOM.updatePlayPauseIcon(true);
        },
        togglePlayPause() {
            const audio = MusicPlayer.elements.audioPlayer;
            if (audio.paused || !MusicPlayer.state.isPlaying) {
                if (MusicPlayer.state.currentIndex === -1 && MusicPlayer.state.playlist.length > 0) {
                    // No song playing, start with first song
                    this.play(0);
                } else {
                    this.resume();
                }
            } else {
                this.pause();
            }
        },
        next() {
            const nextIndex = Utils.getNextIndex(
                MusicPlayer.state.currentIndex,
                MusicPlayer.state.playlist,
                MusicPlayer.state.shuffle
            );
            this.play(nextIndex);
        },
        previous() {
            const prevIndex = Utils.getPrevIndex(
                MusicPlayer.state.currentIndex,
                MusicPlayer.state.playlist
            );
            this.play(prevIndex);
        },
        handleEnded() {
            if (MusicPlayer.state.repeat) {
                this.play(MusicPlayer.state.currentIndex);
            } else {
                const nextIndex = Utils.getNextIndex(
                    MusicPlayer.state.currentIndex,
                    MusicPlayer.state.playlist,
                    MusicPlayer.state.shuffle
                );
                // Check if we've reached the end of the playlist in non-shuffle mode
                if (!MusicPlayer.state.shuffle && nextIndex === 0 && MusicPlayer.state.currentIndex === MusicPlayer.state.playlist.length - 1) {
                    MusicPlayer.state.isPlaying = false;
                    DOM.updatePlayPauseIcon(false);
                    DOM.highlightCurrentSong(-1);
                } else {
                    this.play(nextIndex);
                }
            }
        },
    };
    // ===== WEBSOCKET FUNCTIONS =====
    const WebSocket = {
        connect() {
            MusicPlayer.socket = io('wss://websocket.botofthespecter.com', {
                reconnection: false
            });
            MusicPlayer.socket.on('connect', () => {
                console.log('Connected to WebSocket server');
                MusicPlayer.reconnectAttempts = 0;
                
                MusicPlayer.socket.emit('REGISTER', {
                    code: '<?php echo $api_key; ?>',
                    channel: 'Dashboard',
                    name: 'Music Controller'
                });
                // Set timeout for default volume
                MusicPlayer.timeouts.settings = setTimeout(() => {
                    if (!MusicPlayer.state.volumeInitialized) {
                        DOM.updateVolume(10);
                        MusicPlayer.socket.emit('MUSIC_COMMAND', { command: 'volume', value: 10 });
                        console.log('No MUSIC_SETTINGS received, defaulting volume to 10%');
                        MusicPlayer.state.volumeInitialized = true;
                    }
                }, 3000);
            });
            // Log all events for debugging
            MusicPlayer.socket.onAny((event, ...args) => {
                console.log('WebSocket Event:', event, ...args);
            });
            MusicPlayer.socket.on('disconnect', () => {
                console.log('Disconnected from WebSocket server');
                this.attemptReconnect();
            });
            MusicPlayer.socket.on('connect_error', (error) => {
                console.error('Connection error:', error);
                this.attemptReconnect();
            });
            MusicPlayer.socket.on('WELCOME', (data) => {
                console.log('Server says:', data.message);
            });
            MusicPlayer.socket.on('MUSIC_SETTINGS', (settings) => {
                this.applySettings(settings);
                if (MusicPlayer.timeouts.settings) {
                    clearTimeout(MusicPlayer.timeouts.settings);
                }
            });
            MusicPlayer.socket.on('NOW_PLAYING', (data) => {
                this.handleNowPlaying(data);
            });
            MusicPlayer.socket.on('SUCCESS', () => {
                MusicPlayer.socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS' });
            });
        },
        attemptReconnect() {
            MusicPlayer.reconnectAttempts++;
            const delay = Math.min(MusicPlayer.retryInterval * MusicPlayer.reconnectAttempts, MusicPlayer.maxRetryDelay);
            console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
            setTimeout(() => this.connect(), delay);
        },
        applySettings(settings) {
            if (!settings) return;
            if (typeof settings.volume !== 'undefined') {
                DOM.updateVolume(settings.volume);
                MusicPlayer.state.volumeInitialized = true;
            }
            if (settings.now_playing) {
                DOM.updateNowPlaying(settings.now_playing.title || settings.now_playing, true);
            }
            if (typeof settings.repeat !== 'undefined') {
                MusicPlayer.state.repeat = !!settings.repeat;
                DOM.updateButtonState(MusicPlayer.elements.repeatBtn, MusicPlayer.state.repeat);
            }
            if (typeof settings.shuffle !== 'undefined') {
                MusicPlayer.state.shuffle = !!settings.shuffle;
                DOM.updateButtonState(MusicPlayer.elements.shuffleBtn, MusicPlayer.state.shuffle);
            }
            if (typeof settings.music_source !== 'undefined') {
                const sel = document.getElementById('music-source-select');
                if (sel) sel.value = settings.music_source;
                if (typeof DOM !== 'undefined' && DOM.updatePlaylistForSource) {
                    DOM.updatePlaylistForSource(settings.music_source);
                }
            }
        },
        handleNowPlaying(data) {
            MusicPlayer.elements.refreshBtn.classList.remove('sp-btn-loading');
            if (data && data.song) {
                const title = data.song.title || data.song.file || data.song;
                DOM.updateNowPlaying(title, true);
                
                // Try to find and highlight the song in playlist
                const songFile = data.song.file || data.song;
                const index = MusicPlayer.state.playlist.findIndex(f => f === songFile);
                if (index >= 0) {
                    DOM.highlightCurrentSong(index);
                }
            } else if (data && data.error) {
                DOM.updateNowPlaying(data.error, false);
            } else {
                DOM.updateNowPlaying(null, false);
                DOM.highlightCurrentSong(-1);
            }
        },
        sendCommand(command, params = {}) {
            if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                MusicPlayer.socket.emit('MUSIC_COMMAND', { command, ...params });
            }
        },
    };
    // ===== EVENT HANDLERS =====
    const Events = {
        initializeAll() {
            if (MusicPlayer.listenersInitialized) return;
            this.initPlaylistEvents();
            this.initControlEvents();
            this.initVolumeEvents();
            this.initKeyboardShortcuts();
            this.initAudioEvents();
            this.initSearchEvents();
            this.initUploadEvents();
            // File input preview for user uploads
            const userFileInput = document.getElementById('userMusicFiles');
            const userFileList = document.getElementById('user-music-file-list');
            if (userFileInput && userFileList) {
                userFileInput.addEventListener('change', (e) => {
                    const files = Array.from(e.target.files).map(f => f.name).join(', ');
                    userFileList.textContent = files || 'No files selected';
                });
            }
            MusicPlayer.listenersInitialized = true;
        },
        initPlaylistEvents() {
            // Click on playlist rows
            document.querySelectorAll('.playlist-row').forEach((row) => {
                row.addEventListener('click', (e) => {
                    // Don't trigger if clicking the play button
                    if (e.target.closest('.play-song-btn')) return;
                    
                    const index = parseInt(row.getAttribute('data-index'));
                    this.playSongAtIndex(index);
                });
            });
            // Play buttons
            document.querySelectorAll('.play-song-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const index = parseInt(btn.getAttribute('data-index'));
                    this.playSongAtIndex(index);
                });
            });
            // Delete user-uploaded music (only visible to uploader)
            document.querySelectorAll('.delete-user-music').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const file = btn.getAttribute('data-file');
                    if (!confirm(`Delete ${file}? This cannot be undone.`)) return;
                    const form = new FormData();
                    form.append('delete_user_music[]', file);
                    try {
                        const resp = await fetch(window.location.pathname, { method: 'POST', body: form });
                        if (resp.ok) {
                            location.reload();
                        } else {
                            alert('Failed to delete file');
                        }
                    } catch (err) {
                        console.error(err);
                        alert('Delete failed');
                    }
                });
            });
        },
        playSongAtIndex(index) {
            if (MusicPlayer.state.localPlayback) {
                AudioPlayer.play(index);
            } else {
                const song = MusicPlayer.state.playlist[index];
                const title = Utils.formatTitle(song);
                // Use file-based NOW_PLAYING for all tracks to avoid index mismatches
                // between dashboard and overlay playlist ordering.
                const songPayload = {
                    title: title,
                    file: song
                };
                if (typeof song === 'string' && song.startsWith('USER:')) {
                    const userFile = song.replace(/^USER:/, '');
                    songPayload.file = userFile;
                    if (uploaderName) {
                        songPayload.url = `https://music.botspecter.com/${encodeURIComponent(uploaderName)}/${encodeURIComponent(userFile)}`;
                    }
                }
                if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                    MusicPlayer.socket.emit('NOW_PLAYING', { song: songPayload });
                }
                DOM.updateNowPlaying(title, true);
                DOM.highlightCurrentSong(index);
            }
        },
        initControlEvents() {
            // Local playback toggle
            MusicPlayer.elements.localPlaybackToggle.addEventListener('change', (e) => {
                MusicPlayer.state.localPlayback = e.target.checked;
                if (!MusicPlayer.state.localPlayback && MusicPlayer.socket) {
                    WebSocket.sendCommand('MUSIC_SETTINGS');
                }
            });
            // Persisted music source selector (built-in vs user uploads)
            const musicSourceSelect = document.getElementById('music-source-select');
            if (musicSourceSelect) {
                musicSourceSelect.addEventListener('change', async (ev) => {
                    const val = ev.target.value;
                    const form = new FormData();
                    form.append('section_save', 'music');
                    form.append('music_source', val);
                    try {
                        const resp = await fetch('module_data_post.php', { method: 'POST', body: form });
                        const json = await resp.json();
                        if (json.success) {
                            // Persisted to DB OK - propagate live via websocket so overlays/controllers pick it up immediately
                            WebSocket.sendCommand('MUSIC_SETTINGS', { music_source: val, repeat: MusicPlayer.state.repeat, shuffle: MusicPlayer.state.shuffle, volume: MusicPlayer.state.volume });
                            // Also emit explicit MUSIC_SETTINGS event so the websocket relayer forwards music_source right away
                            if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                                MusicPlayer.socket.emit('MUSIC_SETTINGS', { music_source: val, repeat: MusicPlayer.state.repeat, shuffle: MusicPlayer.state.shuffle, volume: MusicPlayer.state.volume });
                            }
                            const toast = document.createElement('div');
                            toast.className = 'sp-alert sp-alert-success';
                            toast.style.position = 'fixed';
                            toast.style.bottom = '1rem';
                            toast.style.right = '1rem';
                            toast.style.zIndex = 10000;
                            toast.innerText = 'Music source saved';
                            document.body.appendChild(toast);
                            setTimeout(() => toast.remove(), 2200);
                            // Update the playlist display immediately for the new source
                            if (typeof DOM !== 'undefined' && DOM.updatePlaylistForSource) {
                                DOM.updatePlaylistForSource(val);
                            }
                        } else {
                            alert('Failed to save music source');
                        }
                    } catch (err) {
                        console.error(err);
                        alert('Error saving music source');
                    }
                });
            }
            // Play/Pause
            MusicPlayer.elements.playPauseBtn.addEventListener('click', () => {
                if (MusicPlayer.state.localPlayback) {
                    AudioPlayer.togglePlayPause();
                } else {
                    const command = MusicPlayer.state.isPlaying ? 'pause' : 'play';
                    WebSocket.sendCommand(command);
                    MusicPlayer.state.isPlaying = !MusicPlayer.state.isPlaying;
                    DOM.updatePlayPauseIcon(MusicPlayer.state.isPlaying);
                }
            });
            // Previous
            MusicPlayer.elements.prevBtn.addEventListener('click', () => {
                if (MusicPlayer.state.localPlayback) {
                    AudioPlayer.previous();
                } else {
                    WebSocket.sendCommand('prev');
                }
            });
            // Next
            MusicPlayer.elements.nextBtn.addEventListener('click', () => {
                if (MusicPlayer.state.localPlayback) {
                    AudioPlayer.next();
                } else {
                    WebSocket.sendCommand('next');
                }
            });
            // Repeat
            MusicPlayer.elements.repeatBtn.addEventListener('click', () => {
                MusicPlayer.state.repeat = !MusicPlayer.state.repeat;
                DOM.updateButtonState(MusicPlayer.elements.repeatBtn, MusicPlayer.state.repeat);
                WebSocket.sendCommand('MUSIC_SETTINGS', {
                    repeat: MusicPlayer.state.repeat,
                    shuffle: MusicPlayer.state.shuffle,
                    volume: MusicPlayer.state.volume
                });
            });
            // Shuffle
            MusicPlayer.elements.shuffleBtn.addEventListener('click', () => {
                MusicPlayer.state.shuffle = !MusicPlayer.state.shuffle;
                DOM.updateButtonState(MusicPlayer.elements.shuffleBtn, MusicPlayer.state.shuffle);
                WebSocket.sendCommand('MUSIC_SETTINGS', {
                    repeat: MusicPlayer.state.repeat,
                    shuffle: MusicPlayer.state.shuffle,
                    volume: MusicPlayer.state.volume
                });
            });
            // Refresh now playing
            MusicPlayer.elements.refreshBtn.addEventListener('click', () => {
                MusicPlayer.elements.refreshBtn.classList.add('sp-btn-loading');
                WebSocket.sendCommand('WHAT_IS_PLAYING');
            });
        },
        initVolumeEvents() {
            const debouncedVolumeUpdate = Utils.debounce((value) => {
                if (MusicPlayer.state.localPlayback) {
                    MusicPlayer.elements.audioPlayer.volume = value / 100;
                } else if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                    WebSocket.sendCommand('volume', { value });
                }
            }, 100);
            const handleVolumeChange = (value) => {
                DOM.updateVolume(value);
                debouncedVolumeUpdate(value);
            };
            MusicPlayer.elements.volumeRange.addEventListener('input', (e) => {
                handleVolumeChange(parseInt(e.target.value));
            });
            MusicPlayer.elements.volumePercentage.addEventListener('input', (e) => {
                let value = parseInt(e.target.value);
                if (isNaN(value)) value = 0;
                if (value < 0) value = 0;
                if (value > 100) value = 100;
                handleVolumeChange(value);
            });
        },
        initKeyboardShortcuts() {
            document.addEventListener('keydown', (e) => {
                // Ignore if typing in an input field
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                switch(e.code) {
                    case 'Space':
                        e.preventDefault();
                        MusicPlayer.elements.playPauseBtn.click();
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        MusicPlayer.elements.prevBtn.click();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        MusicPlayer.elements.nextBtn.click();
                        break;
                    case 'KeyR':
                        if (e.ctrlKey || e.metaKey) return; // Don't interfere with browser refresh
                        e.preventDefault();
                        MusicPlayer.elements.repeatBtn.click();
                        break;
                    case 'KeyS':
                        if (e.ctrlKey || e.metaKey) return; // Don't interfere with browser save
                        e.preventDefault();
                        MusicPlayer.elements.shuffleBtn.click();
                        break;
                }
            });
        },
        initAudioEvents() {
            MusicPlayer.elements.audioPlayer.addEventListener('ended', () => {
                AudioPlayer.handleEnded();
            });
            MusicPlayer.elements.audioPlayer.addEventListener('error', (e) => {
                console.error('Audio playback error:', e);
                DOM.updateNowPlaying('Error loading song', false);
            });
        },
        initSearchEvents() {
            MusicPlayer.elements.searchInput.addEventListener('input', (e) => {
                DOM.filterPlaylist(e.target.value);
            });
        },
        initUploadEvents() {
            const form = document.getElementById('userMusicUploadForm');
            const fileInput = document.getElementById('userMusicFiles');
            const fileListLabel = document.getElementById('user-music-file-list');
            const progressContainer = document.getElementById('userUploadProgressContainer');
            const progressBar = document.getElementById('userUploadProgress');
            const progressPercent = document.getElementById('userUploadProgressPercent');
            const responseEl = document.getElementById('userUploadResponse');
            if (!form || !fileInput) return;
            form.addEventListener('submit', (ev) => {
                ev.preventDefault();
                const files = fileInput.files;
                if (!files || files.length === 0) {
                    alert('Please select one or more MP3 files to upload.');
                    return;
                }
                const fd = new FormData();
                for (let i = 0; i < files.length; i++) {
                    fd.append('userMusicFiles[]', files[i]);
                }
                // UI: disable submit, show progress
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('sp-btn-loading'); }
                const statusText = document.getElementById('userUploadStatusText');
                if (statusText) { statusText.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Uploading ' + files.length + ' file(s)...'; }
                progressContainer.style.display = 'block';
                progressBar.value = 0; progressPercent.textContent = '0%';
                responseEl.style.display = 'none'; responseEl.innerHTML = '';
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.pathname, true);
                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        progressBar.value = percentComplete;
                        progressPercent.textContent = percentComplete + '%';
                    }
                };
                xhr.onload = function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('sp-btn-loading'); }
                    progressContainer.style.display = 'none';
                    if (xhr.status >= 200 && xhr.status < 300) {
                        // Replace playlist tbody with server-rendered version from response
                        try {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(xhr.responseText, 'text/html');
                            const newTbody = doc.getElementById('playlistBody');
                            if (newTbody) {
                                document.getElementById('playlistBody').innerHTML = newTbody.innerHTML;
                                // rebind playlist event handlers and rebuild client playlist state
                                Events.initPlaylistEvents();
                                DOM.rebuildPlaylistStateFromDOM();
                                const ms = (document.getElementById('music-source-select') || {}).value || 'system';
                                DOM.updatePlaylistForSource(ms);
                            }
                            // show any server messages for uploads
                            const serverMsg = doc.querySelector('.sp-alert.sp-alert-info');
                            if (serverMsg) {
                                responseEl.innerHTML = serverMsg.innerHTML;
                                responseEl.style.display = 'block';
                            }
                        } catch (err) {
                            console.warn('Failed to parse upload response', err);
                        }
                        // reset input
                        fileInput.value = '';
                        if (fileListLabel) fileListLabel.textContent = 'No files selected';
                    } else {
                        responseEl.innerHTML = 'Upload failed';
                        responseEl.style.display = 'block';
                    }
                };
                xhr.onerror = function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('sp-btn-loading'); }
                    progressContainer.style.display = 'none';
                    responseEl.innerHTML = 'Upload failed (network error)';
                    responseEl.style.display = 'block';
                };
                xhr.send(fd);
            });
        },
    };
    // ===== INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', () => {
        console.log('Initializing Music Player...');
        DOM.cacheElements();
        Events.initializeAll();
        WebSocket.connect();
        // Ensure playlist reflects persisted music source on load
        const initialSource = (document.getElementById('music-source-select') || {}).value || 'system';
        if (typeof DOM !== 'undefined' && DOM.updatePlaylistForSource) {
            DOM.updatePlaylistForSource(initialSource);
        }
        // Update the visible playlist count tag to match server-side initial value
        const countTag = document.getElementById('playlistCountTag');
        if (countTag) {
            const visible = document.querySelectorAll('.playlist-row:not([style*="display:none"])').length;
            countTag.textContent = visible + ' ' + (countTag.getAttribute('data-label') || 'songs');
        }
        // Initialize button states
        DOM.updateButtonState(MusicPlayer.elements.repeatBtn, false);
        DOM.updateButtonState(MusicPlayer.elements.shuffleBtn, false);
        console.log('Music Player initialized successfully');
    });
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>