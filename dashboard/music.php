<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

require_once '/var/www/lib/require_auth.php';

// Page Title and Initial Variables
$pageTitle = t('music_dashboard_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

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
    usort($files, function($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });
    return $files;
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

function music_load_preferences($db) {
    $music_source = 'system';
    $music_playlist_filter = [];
    try {
        $prefRes = $db->query("SELECT music_source, music_playlist_filter FROM streamer_preferences WHERE id = 1");
        if ($prefRes && ($row = $prefRes->fetch_assoc())) {
            if (isset($row['music_source'])) {
                $music_source = $row['music_source'];
                if (!in_array($music_source, ['system', 'user', 'both'])) $music_source = 'system';
            }
            if (!empty($row['music_playlist_filter'])) {
                $decoded = json_decode($row['music_playlist_filter'], true);
                if (is_array($decoded)) {
                    $music_playlist_filter = array_values(array_filter($decoded, fn($v) => is_string($v) && $v !== ''));
                }
            }
        }
    } catch (Exception $e) {
        // keep defaults if column missing or query fails
    }
    return ['music_source' => $music_source, 'music_playlist_filter' => $music_playlist_filter];
}

function music_collect_lists($user_music_path = null, $public_user_music_path = null) {
    $musicFiles = getLocalMusicFiles();
    $userMusicFiles = [];
    if (!empty($user_music_path)) {
        $userMusicFiles = getUserMusicFiles($user_music_path);
        // Reconcile public user music directory so existing uploads are reachable at
        // https://music.botspecter.com/<username>/<file.mp3>
        if (!empty($public_user_music_path) && is_dir($public_user_music_path)) {
            foreach ($userMusicFiles as $f) {
                $privateFile = $user_music_path . '/' . $f['filename'];
                $publicFile = $public_user_music_path . '/' . $f['filename'];
                if (!file_exists($publicFile) && file_exists($privateFile)) {
                    if (!@symlink($privateFile, $publicFile)) {
                        @copy($privateFile, $publicFile);
                    }
                    @chmod($publicFile, 0644);
                }
            }
        }
    }
    return ['user' => $userMusicFiles, 'system' => $musicFiles];
}

// List endpoint first so the browser can paint skeletons, then fetch rows + storage.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        include 'includes/storage_used.php';
        $prefs = music_load_preferences($db);
        $music_source = $prefs['music_source'];
        $music_playlist_filter = $prefs['music_playlist_filter'];
        $lists = music_collect_lists($user_music_path ?? null, $public_user_music_path ?? null);
        $userMusicFiles = $lists['user'];
        $musicFiles = $lists['system'];
        $visibleCount = 0;
        foreach ($userMusicFiles as $f) {
            $key = 'USER:' . $f['filename'];
            if (($music_source === 'user' || $music_source === 'both') && !in_array($key, $music_playlist_filter, true)) {
                $visibleCount++;
            }
        }
        foreach ($musicFiles as $f) {
            if (($music_source === 'system' || $music_source === 'both') && !in_array($f['filename'], $music_playlist_filter, true)) {
                $visibleCount++;
            }
        }
        echo json_encode([
            'success' => true,
            'storage_used' => (int) $current_storage_used,
            'max_storage' => (int) $max_storage_size,
            'storage_percentage' => (float) $storage_percentage,
            'music_source' => $music_source,
            'playlist_filter' => array_values($music_playlist_filter),
            'user_files' => $userMusicFiles,
            'system_files' => $musicFiles,
            'visible_count' => $visibleCount,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

$prefs = music_load_preferences($db);
$music_source = $prefs['music_source'];
$music_playlist_filter = $prefs['music_playlist_filter'];

// User-uploaded music handling
$userMusicStatus = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['userMusicFiles']) || isset($_POST['delete_user_music']) || isset($_POST['rename_user_music']))) {
    include 'includes/storage_used.php';
    require_once __DIR__ . '/includes/upload_helpers.php';
}
// Handle uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['userMusicFiles'])) {
    foreach ($_FILES['userMusicFiles']['tmp_name'] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;
        $origName = $_FILES['userMusicFiles']['name'][$key];
        $fileSize = $_FILES['userMusicFiles']['size'][$key];
        $fileError = $_FILES['userMusicFiles']['error'][$key];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'mp3') {
            $userMusicStatus .= t('music_upload_fail_not_mp3', [htmlspecialchars($origName)]) . "<br>";
            continue;
        }
        // Check storage limits
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $userMusicStatus .= t('music_upload_fail_storage', [htmlspecialchars($origName)]) . "<br>";
            continue;
        }
        if ($fileError !== 0) {
            $userMusicStatus .= t('music_upload_error_code', ['name' => htmlspecialchars($origName), 'code' => $fileError]) . "<br>";
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
            $userMusicStatus .= t('music_status_uploaded', [htmlspecialchars(basename($target))]) . "<br>";
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
            $userMusicStatus .= t('music_status_move_failed', [htmlspecialchars($origName)]) . "<br>";
        }
    }
    // Recalculate storage percentage
    if ($max_storage_size > 0) {
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
    }
}

// Handle rename of user-uploaded music (filename is the playlist title)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_user_music'])) {
    $result = upload_rename_file($user_music_path, $_POST['rename_user_music'], $_POST['new_name'] ?? '', 'title');
    if (!empty($result['ok'])) {
        upload_rename_music_public(
            $user_music_path,
            $public_user_music_path ?? '',
            $result['old_base'],
            $result['new_base']
        );
        upload_rename_music_filter($db, $result['old_base'], $result['new_base']);
        $userMusicStatus .= t('upload_rename_success', [htmlspecialchars($result['new_base'])]) . "<br>";
    } else {
        $userMusicStatus .= htmlspecialchars(upload_rename_error_message($result['error'] ?? 'failed')) . "<br>";
    }
    if (upload_rename_is_ajax()) {
        upload_rename_json($result);
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
            $userMusicStatus .= t('music_status_deleted', [htmlspecialchars($file)]) . "<br>";
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
            $userMusicStatus .= t('music_status_delete_failed', [htmlspecialchars($file)]) . "<br>";
        }
    }
    if ($max_storage_size > 0) {
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
    }
}

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
                        <span><?php echo t('music_repeat_one'); ?></span>
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
            <label class="sp-label" style="margin-bottom:0;white-space:nowrap;"><?php echo t('music_music_source'); ?></label>
            <select id="music-source-select" class="sp-select" style="width:auto;">
                <option value="system" <?php echo ($music_source === 'system') ? 'selected' : ''; ?>><?php echo t('music_source_builtin'); ?></option>
                <option value="user" <?php echo ($music_source === 'user') ? 'selected' : ''; ?>><?php echo t('music_source_uploads'); ?></option>
                <option value="both" <?php echo ($music_source === 'both') ? 'selected' : ''; ?>><?php echo t('music_source_both'); ?></option>
            </select>
        </div>
    </header>
    <div class="sp-card-body">
        <!-- Disclaimer -->
        <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo t('music_upload_disclaimer'); ?>
        </div>
        <!-- Storage Usage Info -->
        <div class="sp-alert sp-alert-info" id="musicStorageHost" style="margin-bottom:1rem;" aria-busy="true">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                <span id="musicStorageText"><span class="sp-skeleton-line w-40" aria-hidden="true"></span></span>
            </div>
            <progress class="progress" id="musicStorageProgress" value="0" max="100" style="width:100%;"></progress>
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
                        <strong id="userUploadStatusText"><?php echo t('music_preparing_upload'); ?></strong>
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
        <span id="playlistCountTag" class="sp-badge sp-badge-blue" data-label="<?php echo t('music_songs'); ?>" aria-busy="true"><span class="sp-skeleton-line w-40" aria-hidden="true"></span></span>
    </header>
    <div class="sp-card-body">
        <!-- Search + bulk filter -->
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;margin-bottom:0.75rem;">
            <div class="sp-form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                <div style="position:relative;">
                    <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;">
                        <i class="fas fa-search"></i>
                    </span>
                    <input id="searchInput" class="sp-input" type="text" placeholder="<?php echo t('music_search_playlist'); ?>" style="padding-left:2.25rem;">
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <button id="playlistSelectAll" type="button" class="sp-btn sp-btn-ghost sp-btn-sm">
                    <i class="fas fa-check-double"></i>
                    <span><?php echo t('music_playlist_select_all'); ?></span>
                </button>
                <button id="playlistDeselectAll" type="button" class="sp-btn sp-btn-ghost sp-btn-sm">
                    <i class="fas fa-ban"></i>
                    <span><?php echo t('music_playlist_deselect_all'); ?></span>
                </button>
            </div>
        </div>
        <div class="sp-table-wrap playlist-container">
            <table class="sp-table" id="playlistTable">
                <thead>
                    <tr>
                        <th style="width:2.5rem;text-align:center;" title="<?php echo htmlspecialchars(t('music_playlist_include')); ?>">
                            <span class="icon is-small"><i class="fas fa-filter"></i></span>
                        </th>
                        <th style="width:3rem;text-align:center;">#</th>
                        <th>
                            <span class="icon is-small mr-1"><i class="fas fa-music"></i></span>
                            <?php echo t('music_title'); ?>
                        </th>
                        <th style="text-align:right;white-space:nowrap;"><?php echo t('music_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="playlistBody" aria-busy="true">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                        <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-40"></span></td>
                        <td><span class="sp-skeleton-line w-80"></span></td>
                        <td style="text-align:right;"><span class="sp-skeleton-line w-40"></span></td>
                    </tr>
                    <?php endfor; ?>
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
    const MUSIC_I18N = {
        yourUpload: <?php echo json_encode(t('music_your_upload')); ?>,
        play: <?php echo json_encode(t('music_play')); ?>,
        delete: <?php echo json_encode(t('music_delete')); ?>,
        rename: <?php echo json_encode(t('upload_rename')); ?>,
        renameTitle: <?php echo json_encode(t('upload_rename_title')); ?>,
        renameHint: <?php echo json_encode(t('upload_rename_hint_music')); ?>,
        renameConfirm: <?php echo json_encode(t('upload_rename_confirm')); ?>,
        renameCancel: <?php echo json_encode(t('upload_rename_cancel')); ?>,
        renameEmpty: <?php echo json_encode(t('upload_rename_empty')); ?>,
        success: <?php echo json_encode(t('upload_rename_success')); ?>,
        failed: <?php echo json_encode(t('upload_rename_failed')); ?>,
        exists: <?php echo json_encode(t('upload_rename_exists')); ?>,
        invalid: <?php echo json_encode(t('upload_rename_invalid')); ?>,
        missing: <?php echo json_encode(t('upload_rename_missing')); ?>,
        same: <?php echo json_encode(t('upload_rename_same')); ?>,
        include: <?php echo json_encode(t('music_playlist_include')); ?>,
        noTracks: <?php echo json_encode(t('music_no_tracks_for_source')); ?>,
        songs: <?php echo json_encode(t('music_songs')); ?>,
    };
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
            playlist: [],
            currentSong: null,
            musicSource: <?php echo json_encode($music_source); ?>,
            excludedTracks: new Set(<?php echo json_encode(array_values($music_playlist_filter)); ?>),
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
        getEnabledIndices() {
            const source = MusicPlayer.state.musicSource || 'system';
            return Array.from(document.querySelectorAll('.playlist-row:not(.placeholder)'))
                .filter(row => {
                    const file = row.getAttribute('data-file');
                    if (!file || MusicPlayer.state.excludedTracks.has(file)) return false;
                    return DOM.rowVisibleForSource(row, source);
                })
                .map(row => parseInt(row.getAttribute('data-index'), 10))
                .filter(i => !isNaN(i));
        },
        getNextEnabledIndex(currentIndex, shuffle) {
            const enabled = this.getEnabledIndices();
            if (!enabled.length) return -1;
            if (currentIndex < 0 || !enabled.includes(currentIndex)) return enabled[0];
            if (shuffle) {
                if (enabled.length === 1) return enabled[0];
                let next;
                do {
                    next = enabled[Math.floor(Math.random() * enabled.length)];
                } while (enabled.length > 1 && next === currentIndex);
                return next;
            }
            const pos = enabled.indexOf(currentIndex);
            return enabled[(pos + 1) % enabled.length];
        },
        getPrevEnabledIndex(currentIndex) {
            const enabled = this.getEnabledIndices();
            if (!enabled.length) return -1;
            if (currentIndex < 0 || !enabled.includes(currentIndex)) return enabled[enabled.length - 1];
            const pos = enabled.indexOf(currentIndex);
            return enabled[(pos - 1 + enabled.length) % enabled.length];
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
        rowVisibleForSource(row, source) {
            if (row.classList.contains('placeholder')) return false;
            const isUser = row.classList.contains('user-upload');
            if (source === 'both') return true;
            if (source === 'user') return isUser;
            return !isUser;
        },
        isTrackEnabled(fileKey) {
            return fileKey && !MusicPlayer.state.excludedTracks.has(fileKey);
        },
        syncPlaylistFilterStyles() {
            document.querySelectorAll('.playlist-row:not(.placeholder)').forEach(row => {
                const file = row.getAttribute('data-file');
                const enabled = this.isTrackEnabled(file);
                row.classList.toggle('is-filtered-out', !enabled);
                const cb = row.querySelector('.playlist-filter-cb');
                if (cb) cb.checked = enabled;
            });
        },
        shouldShowRow(row, source, searchTerm) {
            if (row.classList.contains('placeholder')) return false;
            if (!this.rowVisibleForSource(row, source)) return false;
            if (searchTerm) {
                const title = row.getAttribute('data-title');
                if (!title || !title.includes(searchTerm)) return false;
            }
            return true;
        },
        refreshPlaylistDisplay() {
            const source = MusicPlayer.state.musicSource || 'system';
            const searchTerm = (MusicPlayer.elements.searchInput?.value || '').toLowerCase().trim();
            const rows = Array.from(document.querySelectorAll('.playlist-row:not(.placeholder)'));
            let activeCount = 0;
            let visibleCount = 0;
            rows.forEach(row => {
                const show = this.shouldShowRow(row, source, searchTerm);
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
                const file = row.getAttribute('data-file');
                const enabled = this.isTrackEnabled(file);
                row.classList.toggle('is-filtered-out', !enabled);
                if (show && enabled) {
                    activeCount++;
                    const numEl = row.querySelector('.row-number');
                    if (numEl) numEl.textContent = activeCount;
                } else {
                    const numEl = row.querySelector('.row-number');
                    if (numEl) numEl.textContent = '';
                }
            });
            const tbody = document.getElementById('playlistBody');
            const existingPlaceholder = document.querySelector('.playlist-row.placeholder');
            if (visibleCount === 0) {
                if (!existingPlaceholder) {
                    const tr = document.createElement('tr');
                    tr.className = 'playlist-row placeholder';
                    tr.style.color = 'var(--text-muted)';
                    tr.innerHTML = `<td colspan="4" style="padding:1.25rem; text-align:center;">${<?php echo json_encode(t('music_no_tracks_for_source')); ?>}</td>`;
                    tbody.prepend(tr);
                }
            } else if (existingPlaceholder) {
                existingPlaceholder.remove();
            }
            this.updatePlaylistCountTag(activeCount);
        },
        filterPlaylist(searchTerm) {
            this.refreshPlaylistDisplay();
        },
        /* Show/hide playlist rows depending on selected music source and renumber active rows. */
        updatePlaylistForSource(source) {
            MusicPlayer.state.musicSource = source;
            this.refreshPlaylistDisplay();
        },
        setAllPlaylistFilters(enabled, onlySourceVisible = true) {
            const source = MusicPlayer.state.musicSource || 'system';
            document.querySelectorAll('.playlist-row:not(.placeholder)').forEach(row => {
                if (onlySourceVisible && !this.rowVisibleForSource(row, source)) return;
                const file = row.getAttribute('data-file');
                if (!file) return;
                if (enabled) MusicPlayer.state.excludedTracks.delete(file);
                else MusicPlayer.state.excludedTracks.add(file);
            });
            this.refreshPlaylistDisplay();
            Events.persistPlaylistFilter();
        },
        setPlaylistFilter(fileKey, enabled) {
            if (!fileKey) return;
            if (enabled) MusicPlayer.state.excludedTracks.delete(fileKey);
            else MusicPlayer.state.excludedTracks.add(fileKey);
            this.refreshPlaylistDisplay();
            Events.persistPlaylistFilter();
        },
        updatePlaylistCountTag(activeCount) {
            const countTag = document.getElementById('playlistCountTag');
            if (!countTag) return;
            if (typeof activeCount !== 'number') {
                const source = MusicPlayer.state.musicSource || 'system';
                activeCount = Array.from(document.querySelectorAll('.playlist-row:not(.placeholder)'))
                    .filter(row => row.style.display !== 'none' && this.rowVisibleForSource(row, source) && this.isTrackEnabled(row.getAttribute('data-file')))
                    .length;
            }
            countTag.textContent = activeCount + ' ' + (countTag.getAttribute('data-label') || <?php echo json_encode(t('music_songs')); ?>);
        },
        rebuildPlaylistStateFromDOM() {
            const rows = Array.from(document.querySelectorAll('.playlist-row:not(.placeholder)'));
            MusicPlayer.state.playlist = rows.map(r => r.getAttribute('data-file')).filter(Boolean);
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
                audio.src = `/api/serve_user_music.php?file=${encodeURIComponent(userFile)}`;
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
                if (MusicPlayer.state.currentIndex === -1) {
                    const firstEnabled = Utils.getNextEnabledIndex(-1, false);
                    if (firstEnabled >= 0) this.play(firstEnabled);
                } else {
                    this.resume();
                }
            } else {
                this.pause();
            }
        },
        next() {
            const nextIndex = Utils.getNextEnabledIndex(
                MusicPlayer.state.currentIndex,
                MusicPlayer.state.shuffle
            );
            if (nextIndex >= 0) this.play(nextIndex);
        },
        previous() {
            const prevIndex = Utils.getPrevEnabledIndex(MusicPlayer.state.currentIndex);
            if (prevIndex >= 0) this.play(prevIndex);
        },
        handleEnded() {
            if (MusicPlayer.state.repeat) {
                this.play(MusicPlayer.state.currentIndex);
            } else {
                const enabled = Utils.getEnabledIndices();
                const nextIndex = Utils.getNextEnabledIndex(
                    MusicPlayer.state.currentIndex,
                    MusicPlayer.state.shuffle
                );
                if (nextIndex < 0 || (!MusicPlayer.state.shuffle && enabled.length > 0 && nextIndex === enabled[0] && MusicPlayer.state.currentIndex === enabled[enabled.length - 1])) {
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
                // Sync DB-persisted music source to websocket clients (overlays)
                const source = (document.getElementById('music-source-select') || {}).value || MusicPlayer.state.musicSource || 'system';
                MusicPlayer.socket.emit('MUSIC_SETTINGS', {
                    music_source: source,
                    repeat: MusicPlayer.state.repeat,
                    shuffle: MusicPlayer.state.shuffle,
                    volume: MusicPlayer.state.volume,
                    playlist_filter: Array.from(MusicPlayer.state.excludedTracks),
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
            // music_source is persisted in the DB and rendered server-side; do not let
            // stale websocket settings override the playlist on connect.
        },
        handleNowPlaying(data) {
            MusicPlayer.elements.refreshBtn.classList.remove('sp-btn-loading');
            if (data && data.song) {
                const title = data.song.title || data.song.file || data.song;
                DOM.updateNowPlaying(title, true);
                
                // Try to find and highlight the song in playlist
                const songFile = data.song.file || data.song;
                let index = MusicPlayer.state.playlist.findIndex(f => f === songFile);
                if (index < 0 && songFile) {
                    index = MusicPlayer.state.playlist.findIndex(f => f === 'USER:' + songFile);
                }
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
                    userFileList.textContent = files || <?php echo json_encode(t('sound_alerts_no_files_selected')); ?>;
                });
            }
            MusicPlayer.listenersInitialized = true;
        },
        persistPlaylistFilter: Utils.debounce(() => {
            const excluded = Array.from(MusicPlayer.state.excludedTracks);
            const form = new FormData();
            form.append('section_save', 'music');
            form.append('music_playlist_filter', JSON.stringify(excluded));
            fetch('/api/module_data_post.php', { method: 'POST', body: form }).catch(err => console.error(err));
            if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                const source = (document.getElementById('music-source-select') || {}).value || MusicPlayer.state.musicSource || 'system';
                MusicPlayer.socket.emit('MUSIC_SETTINGS', {
                    music_source: source,
                    repeat: MusicPlayer.state.repeat,
                    shuffle: MusicPlayer.state.shuffle,
                    volume: MusicPlayer.state.volume,
                    playlist_filter: excluded,
                });
            }
        }, 400),
        initPlaylistEvents() {
            const tbody = document.getElementById('playlistBody');
            if (tbody && !tbody.dataset.eventsBound) {
                tbody.dataset.eventsBound = '1';
                tbody.addEventListener('change', (e) => {
                    const cb = e.target.closest('.playlist-filter-cb');
                    if (!cb) return;
                    e.stopPropagation();
                    DOM.setPlaylistFilter(cb.getAttribute('data-file'), cb.checked);
                });
                tbody.addEventListener('click', async (e) => {
                    const renameBtn = e.target.closest('.rename-user-music');
                    if (renameBtn) {
                        e.stopPropagation();
                        const file = renameBtn.getAttribute('data-file');
                        if (!file || typeof specterPromptRename !== 'function') return;
                        const nextName = await specterPromptRename({
                            currentName: file,
                            title: MUSIC_I18N.renameTitle,
                            hint: MUSIC_I18N.renameHint,
                            confirmText: MUSIC_I18N.renameConfirm,
                            cancelText: MUSIC_I18N.renameCancel,
                            emptyError: MUSIC_I18N.renameEmpty,
                        });
                        if (!nextName) return;
                        try {
                            const data = await specterPostRename(window.location.pathname, {
                                rename_user_music: file,
                                new_name: nextName,
                            });
                            if (data && data.success) {
                                await loadMusicList();
                                if (typeof Events !== 'undefined' && Events.persistPlaylistFilter) {
                                    Events.persistPlaylistFilter();
                                }
                            } else {
                                Swal.fire({ icon: 'error', title: MUSIC_I18N.failed, text: specterRenameMessage(data, MUSIC_I18N) });
                            }
                        } catch (err) {
                            console.error(err);
                            Swal.fire({ icon: 'error', title: MUSIC_I18N.failed, text: MUSIC_I18N.failed });
                        }
                        return;
                    }
                    const delBtn = e.target.closest('.delete-user-music');
                    if (delBtn) {
                        e.stopPropagation();
                        const file = delBtn.getAttribute('data-file');
                        if (!confirm(<?php echo json_encode(t('music_confirm_delete_file')); ?>.replace('%s', file))) return;
                        const form = new FormData();
                        form.append('delete_user_music[]', file);
                        try {
                            const resp = await fetch(window.location.pathname, { method: 'POST', body: form });
                            if (resp.ok) location.reload();
                            else alert(<?php echo json_encode(t('music_delete_file_failed')); ?>);
                        } catch (err) {
                            console.error(err);
                            alert(<?php echo json_encode(t('music_delete_failed')); ?>);
                        }
                        return;
                    }
                    const playBtn = e.target.closest('.play-song-btn');
                    if (playBtn) {
                        e.stopPropagation();
                        this.playSongAtIndex(parseInt(playBtn.getAttribute('data-index'), 10));
                        return;
                    }
                    if (e.target.closest('.playlist-filter-cb') || e.target.closest('label.checkbox')) return;
                    const row = e.target.closest('.playlist-row:not(.placeholder)');
                    if (!row) return;
                    this.playSongAtIndex(parseInt(row.getAttribute('data-index'), 10));
                });
            }
            if (!MusicPlayer.filterControlsBound) {
                MusicPlayer.filterControlsBound = true;
                const selectAllBtn = document.getElementById('playlistSelectAll');
                const deselectAllBtn = document.getElementById('playlistDeselectAll');
                if (selectAllBtn) selectAllBtn.addEventListener('click', () => DOM.setAllPlaylistFilters(true));
                if (deselectAllBtn) deselectAllBtn.addEventListener('click', () => DOM.setAllPlaylistFilters(false));
            }
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
                        const resp = await fetch('/api/module_data_post.php', { method: 'POST', body: form });
                        const json = await resp.json();
                        if (json.success) {
                            // Persisted to DB OK - propagate live via websocket so overlays/controllers pick it up immediately
                            const playlistFilter = Array.from(MusicPlayer.state.excludedTracks);
                            WebSocket.sendCommand('MUSIC_SETTINGS', { music_source: val, repeat: MusicPlayer.state.repeat, shuffle: MusicPlayer.state.shuffle, volume: MusicPlayer.state.volume, playlist_filter: playlistFilter });
                            // Also emit explicit MUSIC_SETTINGS event so the websocket relayer forwards music_source right away
                            if (MusicPlayer.socket && MusicPlayer.socket.connected) {
                                MusicPlayer.socket.emit('MUSIC_SETTINGS', { music_source: val, repeat: MusicPlayer.state.repeat, shuffle: MusicPlayer.state.shuffle, volume: MusicPlayer.state.volume, playlist_filter: playlistFilter });
                            }
                            const toast = document.createElement('div');
                            toast.className = 'sp-alert sp-alert-success';
                            toast.style.position = 'fixed';
                            toast.style.bottom = '1rem';
                            toast.style.right = '1rem';
                            toast.style.zIndex = 10000;
                            toast.innerText = <?php echo json_encode(t('music_source_saved')); ?>;
                            document.body.appendChild(toast);
                            setTimeout(() => toast.remove(), 2200);
                            // Update the playlist display immediately for the new source
                            if (typeof DOM !== 'undefined' && DOM.updatePlaylistForSource) {
                                DOM.updatePlaylistForSource(val);
                            }
                        } else {
                            alert(<?php echo json_encode(t('music_source_save_failed')); ?>);
                        }
                    } catch (err) {
                        console.error(err);
                        alert(<?php echo json_encode(t('music_source_save_error')); ?>);
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
                    volume: MusicPlayer.state.volume,
                    playlist_filter: Array.from(MusicPlayer.state.excludedTracks),
                });
            });
            // Shuffle
            MusicPlayer.elements.shuffleBtn.addEventListener('click', () => {
                MusicPlayer.state.shuffle = !MusicPlayer.state.shuffle;
                DOM.updateButtonState(MusicPlayer.elements.shuffleBtn, MusicPlayer.state.shuffle);
                WebSocket.sendCommand('MUSIC_SETTINGS', {
                    repeat: MusicPlayer.state.repeat,
                    shuffle: MusicPlayer.state.shuffle,
                    volume: MusicPlayer.state.volume,
                    playlist_filter: Array.from(MusicPlayer.state.excludedTracks),
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
                DOM.updateNowPlaying(<?php echo json_encode(t('music_error_loading_song')); ?>, false);
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
                    alert(<?php echo json_encode(t('music_select_files_prompt')); ?>);
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
                if (statusText) { statusText.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> ' + <?php echo json_encode(t('music_uploading_files')); ?>.replace('%s', files.length); }
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
                        try {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(xhr.responseText, 'text/html');
                            const serverMsg = doc.querySelector('.sp-notif');
                            if (serverMsg) {
                                responseEl.innerHTML = serverMsg.innerHTML;
                                responseEl.style.display = 'block';
                            }
                        } catch (err) {
                            console.warn('Failed to parse upload response', err);
                        }
                        loadMusicList();
                        fileInput.value = '';
                        if (fileListLabel) fileListLabel.textContent = <?php echo json_encode(t('sound_alerts_no_files_selected')); ?>;
                    } else {
                        responseEl.innerHTML = <?php echo json_encode(t('music_upload_failed')); ?>;
                        responseEl.style.display = 'block';
                    }
                };
                xhr.onerror = function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('sp-btn-loading'); }
                    progressContainer.style.display = 'none';
                    responseEl.innerHTML = <?php echo json_encode(t('music_upload_failed_network')); ?>;
                    responseEl.style.display = 'block';
                };
                xhr.send(fd);
            });
        },
    };
    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function applyMusicStorageBar(data) {
        if (!data || typeof data.storage_used !== 'number' || typeof data.max_storage !== 'number') return;
        const usedMb = (data.storage_used / 1024 / 1024).toFixed(2);
        const maxMb = (data.max_storage / 1024 / 1024).toFixed(2);
        const pct = typeof data.storage_percentage === 'number'
            ? data.storage_percentage
            : ((data.storage_used / data.max_storage) * 100);
        const textEl = document.getElementById('musicStorageText');
        const progEl = document.getElementById('musicStorageProgress');
        const hostEl = document.getElementById('musicStorageHost');
        if (textEl) textEl.textContent = usedMb + 'MB / ' + maxMb + 'MB (' + Number(pct).toFixed(2) + '%)';
        if (progEl) progEl.value = pct;
        if (hostEl) hostEl.setAttribute('aria-busy', 'false');
    }
    function playlistRowHtml(index, fileKey, title, isUser, isEnabled) {
        const filteredClass = isEnabled ? '' : ' is-filtered-out';
        const userClass = isUser ? ' user-upload' : '';
        const safeKey = escapeHtml(fileKey);
        const safeTitle = escapeHtml(title);
        const safeTitleLower = escapeHtml(String(title).toLowerCase());
        const safeFile = isUser ? escapeHtml(String(fileKey).replace(/^USER:/, '')) : '';
        const badge = isUser
            ? ' <span class="sp-badge sp-badge-grey" style="margin-left:0.5rem;font-size:0.75rem;">' + escapeHtml(MUSIC_I18N.yourUpload) + '</span>'
            : '';
        const deleteBtn = isUser
            ? '<span class="file-row-actions">' +
              '<button class="sp-btn sp-btn-secondary sp-btn-sm rename-user-music" data-file="' + safeFile + '" title="' + escapeHtml(MUSIC_I18N.rename) + '">' +
              '<span class="icon is-small"><i class="fas fa-pencil-alt"></i></span></button>' +
              '<button class="sp-btn sp-btn-danger sp-btn-sm delete-user-music" data-file="' + safeFile + '" title="' + escapeHtml(MUSIC_I18N.delete) + '">' +
              '<span class="icon is-small"><i class="fas fa-trash"></i></span></button></span>'
            : '';
        return '<tr data-index="' + index + '" data-file="' + safeKey + '" data-title="' + safeTitleLower + '" class="playlist-row' + userClass + filteredClass + '" style="cursor:pointer;">' +
            '<td style="text-align:center;">' +
                '<label class="checkbox" style="display:inline-flex;margin:0;" title="' + escapeHtml(MUSIC_I18N.include) + '">' +
                    '<input type="checkbox" class="playlist-filter-cb" data-file="' + safeKey + '"' + (isEnabled ? ' checked' : '') + '>' +
                '</label></td>' +
            '<td style="text-align:center;font-weight:600;color:var(--text-muted);">' +
                '<span class="row-number"></span>' +
                '<span class="now-playing-icon" style="display:none;"><i class="fas fa-play-circle" style="color:var(--green);"></i></span>' +
            '</td>' +
            '<td class="song-title">' + safeTitle + badge + '</td>' +
            '<td style="text-align:right;white-space:nowrap;">' +
                '<button class="sp-btn sp-btn-ghost sp-btn-sm play-song-btn" data-index="' + index + '" title="' + escapeHtml(MUSIC_I18N.play) + '">' +
                    '<span class="icon is-small"><i class="fas fa-play"></i></span></button>' +
                deleteBtn +
            '</td></tr>';
    }
    function renderPlaylistFromList(data) {
        const tbody = document.getElementById('playlistBody');
        if (!tbody) return;
        const excluded = new Set(Array.isArray(data.playlist_filter) ? data.playlist_filter : []);
        MusicPlayer.state.excludedTracks = excluded;
        if (data.music_source) MusicPlayer.state.musicSource = data.music_source;
        const userFiles = Array.isArray(data.user_files) ? data.user_files : [];
        const systemFiles = Array.isArray(data.system_files) ? data.system_files : [];
        let html = '';
        let index = 0;
        userFiles.forEach((fileData) => {
            const filename = fileData.filename || '';
            const title = fileData.title || filename.replace(/\.mp3$/i, '');
            const fileKey = 'USER:' + filename;
            html += playlistRowHtml(index, fileKey, title, true, !excluded.has(fileKey));
            index++;
        });
        systemFiles.forEach((fileData) => {
            const filename = fileData.filename || '';
            const title = fileData.title || filename.replace(/\.mp3$/i, '');
            html += playlistRowHtml(index, filename, title, false, !excluded.has(filename));
            index++;
        });
        tbody.innerHTML = html;
        tbody.setAttribute('aria-busy', 'false');
        const countTag = document.getElementById('playlistCountTag');
        if (countTag) countTag.setAttribute('aria-busy', 'false');
        DOM.rebuildPlaylistStateFromDOM();
        DOM.syncPlaylistFilterStyles();
        const source = (document.getElementById('music-source-select') || {}).value || MusicPlayer.state.musicSource || 'system';
        DOM.updatePlaylistForSource(source);
    }
    function finishMusicListError() {
        const tbody = document.getElementById('playlistBody');
        if (tbody) {
            tbody.setAttribute('aria-busy', 'false');
            tbody.innerHTML = '';
        }
        const hostEl = document.getElementById('musicStorageHost');
        if (hostEl) hostEl.setAttribute('aria-busy', 'false');
        const countTag = document.getElementById('playlistCountTag');
        if (countTag) {
            countTag.setAttribute('aria-busy', 'false');
            countTag.textContent = '';
        }
    }
    function loadMusicList() {
        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax_action', 'list');
        return fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.success) {
                    finishMusicListError();
                    return;
                }
                applyMusicStorageBar(data);
                renderPlaylistFromList(data);
            })
            .catch(finishMusicListError);
    }
    // ===== INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', () => {
        console.log('Initializing Music Player...');
        DOM.cacheElements();
        Events.initializeAll();
        WebSocket.connect();
        DOM.updateButtonState(MusicPlayer.elements.repeatBtn, false);
        DOM.updateButtonState(MusicPlayer.elements.shuffleBtn, false);
        loadMusicList();
        console.log('Music Player initialized successfully');
    });
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>