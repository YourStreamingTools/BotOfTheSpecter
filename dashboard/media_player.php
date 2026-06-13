<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('media_player_title');

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include "mod_access.php";
include 'includes/user_db.php';
session_write_close();

// The media tables (media_queue / media_request_settings / media_banlist) are created
// centrally by usr_database.php. Here we only handle settings/ban-list edits and load
// current state. $db is the channel's own per-user database connection (same one music.php uses).
$mediaSettings = ['enabled' => 1, 'max_song_seconds' => 600, 'max_queue_length' => 20, 'per_viewer_limit' => 2, 'volume' => 30];
$banlist = [];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $en = isset($_POST['enabled']) ? 1 : 0;
        $mss = max(30, (int)($_POST['max_song_seconds'] ?? 600));
        $mql = max(1, (int)($_POST['max_queue_length'] ?? 20));
        $pvl = max(1, (int)($_POST['per_viewer_limit'] ?? 2));
        $vol = max(0, min(100, (int)($_POST['volume'] ?? 30)));
        $stmt = $db->prepare("INSERT INTO media_request_settings (id, enabled, max_song_seconds, max_queue_length, per_viewer_limit, volume) VALUES (1, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), max_song_seconds=VALUES(max_song_seconds), max_queue_length=VALUES(max_queue_length), per_viewer_limit=VALUES(per_viewer_limit), volume=VALUES(volume)");
        $stmt->bind_param("iiiii", $en, $mss, $mql, $pvl, $vol);
        $stmt->execute();
        $stmt->close();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ban'])) {
        $type = (($_POST['ban_type'] ?? '') === 'video_id') ? 'video_id' : 'keyword';
        $val = trim($_POST['ban_value'] ?? '');
        if ($val !== '') {
            $stmt = $db->prepare("INSERT INTO media_banlist (type, value, added_by) VALUES (?,?,?)");
            $stmt->bind_param("sss", $type, $val, $username);
            $stmt->execute();
            $stmt->close();
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ban'])) {
        $bid = (int)$_POST['del_ban'];
        $stmt = $db->prepare("DELETE FROM media_banlist WHERE id=?");
        $stmt->bind_param("i", $bid);
        $stmt->execute();
        $stmt->close();
    }

    $res = $db->query("SELECT enabled, max_song_seconds, max_queue_length, per_viewer_limit, volume FROM media_request_settings WHERE id=1");
    if ($res && ($row = $res->fetch_assoc())) {
        $mediaSettings = $row;
    }
    $res = $db->query("SELECT id, type, value FROM media_banlist ORDER BY id DESC");
    while ($res && ($row = $res->fetch_assoc())) {
        $banlist[] = $row;
    }
} catch (Throwable $e) {
    // Fall back to defaults if anything goes wrong (e.g. table just created)
}

$overlayBase = "https://overlay.botofthespecter.com/mediaplayer.php";
$overlayUrl = $overlayBase . "?code=" . rawurlencode($api_key);
// Masked form shown by default so the key isn't exposed on screen-share; reveal/copy in JS.
$overlayUrlMasked = $overlayBase . "?code=" . str_repeat('•', 24);

ob_start();
?>
<div class="sp-card mb-4">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-play-circle"></i> <?php echo t('media_player_title'); ?></span>
    </header>
    <div class="sp-card-body">
        <p style="margin-bottom:1rem;"><?php echo t('media_player_intro'); ?></p>
        <label class="sp-label"><?php echo t('media_player_overlay_url'); ?></label>
        <div class="cc-url-row">
            <code class="info-box cc-url-box" id="mp-overlay-url"><?php echo htmlspecialchars($overlayUrlMasked); ?></code>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" id="mp-url-reveal" aria-pressed="false"><i class="fas fa-eye"></i> <span class="mp-url-reveal-label"><?php echo t('media_player_overlay_url_show'); ?></span></button>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-primary" id="mp-url-copy"><i class="fas fa-copy"></i> <span class="mp-url-copy-label"><?php echo t('media_player_overlay_url_copy'); ?></span></button>
        </div>
        <form method="post" style="margin-top:1.25rem;display:flex;flex-direction:column;gap:0.75rem;max-width:440px;">
            <label class="sp-label"><input type="checkbox" name="enabled" <?php echo $mediaSettings['enabled'] ? 'checked' : ''; ?>>&nbsp;<?php echo t('media_player_enabled'); ?></label>
            <label class="sp-label"><?php echo t('media_player_max_len'); ?>
                <input class="sp-input" type="number" name="max_song_seconds" min="30" value="<?php echo (int)$mediaSettings['max_song_seconds']; ?>"></label>
            <label class="sp-label"><?php echo t('media_player_max_queue'); ?>
                <input class="sp-input" type="number" name="max_queue_length" min="1" value="<?php echo (int)$mediaSettings['max_queue_length']; ?>"></label>
            <label class="sp-label"><?php echo t('media_player_per_viewer'); ?>
                <input class="sp-input" type="number" name="per_viewer_limit" min="1" value="<?php echo (int)$mediaSettings['per_viewer_limit']; ?>"></label>
            <label class="sp-label"><?php echo t('media_player_volume'); ?>
                <input id="vol-range" class="modern-volume" type="range" min="0" max="100" value="<?php echo (int)$mediaSettings['volume']; ?>"></label>
            <button class="sp-btn sp-btn-primary" type="submit" name="save_settings" value="1"><?php echo t('media_player_save'); ?></button>
        </form>
    </div>
</div>

<div class="sp-card mb-4">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-list-ol"></i> <?php echo t('media_player_queue'); ?></span>
        <div style="display:flex;gap:0.5rem;">
            <button id="btn-skip" class="sp-btn sp-btn-ghost sp-btn-sm" type="button"><?php echo t('media_player_skip'); ?></button>
            <button id="btn-clear" class="sp-btn sp-btn-ghost sp-btn-sm" type="button"><?php echo t('media_player_clear'); ?></button>
        </div>
    </header>
    <div class="sp-card-body">
        <div id="now-playing" style="margin-bottom:0.75rem;font-weight:600;"></div>
        <ol id="queue-list" style="margin:0;padding-left:1.25rem;"></ol>
    </div>
</div>

<div class="sp-card mb-4">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-ban"></i> <?php echo t('media_player_banlist'); ?></span>
    </header>
    <div class="sp-card-body">
        <form method="post" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
            <select class="sp-select" name="ban_type">
                <option value="keyword"><?php echo t('media_player_ban_keyword'); ?></option>
                <option value="video_id"><?php echo t('media_player_ban_video'); ?></option>
            </select>
            <input class="sp-input" name="ban_value" placeholder="<?php echo t('media_player_ban_value'); ?>" style="flex:1;min-width:160px;">
            <button class="sp-btn sp-btn-primary" type="submit" name="add_ban" value="1"><?php echo t('media_player_add'); ?></button>
        </form>
        <ul style="margin:0;padding-left:1.25rem;">
            <?php foreach ($banlist as $b): ?>
                <li style="margin-bottom:0.35rem;">
                    <?php echo htmlspecialchars($b['type'] . ': ' . $b['value']); ?>
                    <form method="post" style="display:inline;">
                        <button class="sp-btn sp-btn-ghost sp-btn-sm" type="submit" name="del_ban" value="<?php echo (int)$b['id']; ?>">✕</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php
// Spotify player control card — only when Spotify is linked & authorized.
$spotifyConnected = false;
$spStmt = $conn->prepare("SELECT access_token, has_access, own_client FROM spotify_tokens WHERE user_id = ? LIMIT 1");
$spStmt->bind_param('i', $user_id);
$spStmt->execute();
$spRow = $spStmt->get_result()->fetch_assoc();
$spStmt->close();
if ($spRow && !empty($spRow['access_token'])
    && (((int)($spRow['has_access'] ?? 0) === 1) || ((int)($spRow['own_client'] ?? 0) === 1))) {
    $spotifyConnected = true;
}
$spotifyActAs = !empty($_SESSION['admin_act_as_active']);
?>
<?php if ($spotifyConnected): ?>
<div class="sp-card mb-4" id="spotify-player-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fab fa-spotify" style="color: var(--green);"></i> <?php echo t('media_player_spotify_control_title'); ?></span>
        <span style="background: var(--blue); color:#fff; font-size:0.7rem; font-weight:600; padding:2px 10px; border-radius:999px; letter-spacing:0.05em;">Beta 5.8</span>
    </header>
    <div class="sp-card-body">
        <?php if ($spotifyActAs): ?>
            <div class="sp-alert sp-alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo t('media_player_spotify_actas'); ?></div>
        <?php else: ?>
            <div id="sp-player-status" class="sp-alert sp-alert-info" style="display:none; margin-bottom:1rem;"></div>
            <div id="sp-now-playing" style="display:none; gap:1rem; align-items:center; margin-bottom:1rem;">
                <img id="sp-art" alt="" style="width:64px; height:64px; border-radius:var(--radius-sm); object-fit:cover; background:var(--bg-input);">
                <div style="min-width:0;">
                    <div id="sp-track" style="font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></div>
                    <div id="sp-artist" style="color:var(--text-secondary); font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></div>
                    <div id="sp-device" style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.25rem;"></div>
                </div>
            </div>
            <div id="sp-progress-row" class="sp-progress-row" style="display:none;">
                <span id="sp-time-cur" class="sp-progress-time">0:00</span>
                <div class="sp-progress"><div id="sp-progress-fill" class="sp-progress-fill"></div></div>
                <span id="sp-time-dur" class="sp-progress-time">0:00</span>
            </div>
            <div id="sp-controls" style="display:none; gap:0.5rem; align-items:center;">
                <button id="sp-prev" class="sp-btn sp-btn-ghost" type="button" aria-label="Previous">&#9198;</button>
                <button id="sp-playpause" class="sp-btn sp-btn-primary" type="button" aria-label="Play/Pause">&#9654;</button>
                <button id="sp-next" class="sp-btn sp-btn-ghost" type="button" aria-label="Next">&#9197;</button>
                <input id="sp-volume" type="range" min="0" max="100" value="0" class="modern-volume" style="flex:1; min-width:120px;" aria-label="Volume">
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php
// Song Request Analytics (Spotify) — moved here from spotifylink.php.
// Table is created by usr_database.php; the bot logs each !songrequest into song_request_analytics.
$analyticsTableExists = $db->query("SHOW TABLES LIKE 'song_request_analytics'")->num_rows > 0;
if ($analyticsTableExists):
    $srTotalResult = $db->query("SELECT COUNT(*) AS total FROM song_request_analytics");
    $srTotal = $srTotalResult ? (int)$srTotalResult->fetch_assoc()['total'] : 0;
    $srTopSongs = $db->query("SELECT song_name, artist_name, COUNT(*) AS request_count FROM song_request_analytics GROUP BY song_name, artist_name ORDER BY request_count DESC LIMIT 10");
    $srTopSongs = $srTopSongs ? $srTopSongs->fetch_all(MYSQLI_ASSOC) : [];
    $srRecentRequests = $db->query("SELECT song_name, artist_name, requested_by, requested_at FROM song_request_analytics ORDER BY requested_at DESC LIMIT 10");
    $srRecentRequests = $srRecentRequests ? $srRecentRequests->fetch_all(MYSQLI_ASSOC) : [];
endif;
?>
<?php if ($analyticsTableExists): ?>
<div class="sp-card mb-4">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fab fa-spotify" style="color: var(--green);"></i>
            <?php echo t('media_player_sr_spotify_title'); ?>
        </div>
        <span style="background: var(--blue); color: #fff; font-size: 0.7rem; font-weight: 600; padding: 2px 10px; border-radius: 999px; letter-spacing: 0.05em;">Beta 5.8</span>
    </div>
    <div class="sp-card-body">
        <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <div style="background: var(--bg-input); border-radius: var(--radius); padding: 1rem 1.5rem; flex: 1; min-width: 140px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--green);"><?php echo number_format($srTotal); ?></div>
                <div style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;"><?php echo t('spotifylink_stat_total_requests'); ?></div>
            </div>
            <div style="background: var(--bg-input); border-radius: var(--radius); padding: 1rem 1.5rem; flex: 1; min-width: 140px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--blue);"><?php echo count($srTopSongs); ?></div>
                <div style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;"><?php echo t('spotifylink_stat_unique_songs'); ?></div>
            </div>
        </div>
        <?php if (!empty($srTopSongs)): ?>
        <div class="sp-card" style="margin-bottom: 1.5rem;">
            <div class="sp-card-header">
                <div class="sp-card-title" style="font-size: 0.95rem;">
                    <i class="fas fa-trophy" style="color: var(--yellow, #f5c542);"></i>
                    <?php echo t('spotifylink_top_requested_songs'); ?>
                </div>
            </div>
            <div class="sp-card-body" style="padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;">#</th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_song'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_artist'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: right; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_requests'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($srTopSongs as $i => $row): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo $i + 1; ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-primary);"><?php echo htmlspecialchars($row['song_name']); ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo htmlspecialchars($row['artist_name']); ?></td>
                            <td style="padding: 0.6rem 1rem; text-align: right; color: var(--green); font-weight: 600;"><?php echo $row['request_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($srRecentRequests)): ?>
        <div class="sp-card">
            <div class="sp-card-header">
                <div class="sp-card-title" style="font-size: 0.95rem;">
                    <i class="fas fa-history" style="color: var(--text-secondary);"></i>
                    <?php echo t('spotifylink_recent_requests'); ?>
                </div>
            </div>
            <div class="sp-card-body" style="padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_song'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_artist'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_requested_by'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: right; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_time'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($srRecentRequests as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.6rem 1rem; color: var(--text-primary);"><?php echo htmlspecialchars($row['song_name']); ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo htmlspecialchars($row['artist_name']); ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo htmlspecialchars($row['requested_by']); ?></td>
                            <td style="padding: 0.6rem 1rem; text-align: right; color: var(--text-secondary); white-space: nowrap;"><?php echo htmlspecialchars($row['requested_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php if (empty($srTopSongs) && empty($srRecentRequests)): ?>
        <div class="sp-alert sp-alert-info">
            <i class="fas fa-info-circle"></i>
            <?php echo t('spotifylink_analytics_empty'); ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php
// YouTube Song Request Analytics — from media_queue (created by usr_database.php).
// Shown only when the user is actually using YouTube song requests (queue has rows).
$ytTableExists = $db->query("SHOW TABLES LIKE 'media_queue'")->num_rows > 0;
$ytTotal = 0; $ytTopVideos = []; $ytRecentRequests = [];
if ($ytTableExists) {
    $ytTotalResult = $db->query("SELECT COUNT(*) AS total FROM media_queue");
    $ytTotal = $ytTotalResult ? (int)$ytTotalResult->fetch_assoc()['total'] : 0;
    if ($ytTotal > 0) {
        $ytTopVideos = $db->query("SELECT title, uploader, COUNT(*) AS request_count FROM media_queue GROUP BY title, uploader ORDER BY request_count DESC LIMIT 10");
        $ytTopVideos = $ytTopVideos ? $ytTopVideos->fetch_all(MYSQLI_ASSOC) : [];
        $ytRecentRequests = $db->query("SELECT title, uploader, requested_by, requested_at FROM media_queue ORDER BY requested_at DESC LIMIT 10");
        $ytRecentRequests = $ytRecentRequests ? $ytRecentRequests->fetch_all(MYSQLI_ASSOC) : [];
    }
}
?>
<?php if ($ytTableExists && $ytTotal > 0): ?>
<div class="sp-card mb-4">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fab fa-youtube" style="color: #ff0000;"></i>
            <?php echo t('media_player_sr_youtube_title'); ?>
        </div>
        <span style="background: var(--blue); color: #fff; font-size: 0.7rem; font-weight: 600; padding: 2px 10px; border-radius: 999px; letter-spacing: 0.05em;">Beta 5.8</span>
    </div>
    <div class="sp-card-body">
        <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <div style="background: var(--bg-input); border-radius: var(--radius); padding: 1rem 1.5rem; flex: 1; min-width: 140px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--green);"><?php echo number_format($ytTotal); ?></div>
                <div style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;"><?php echo t('spotifylink_stat_total_requests'); ?></div>
            </div>
            <div style="background: var(--bg-input); border-radius: var(--radius); padding: 1rem 1.5rem; flex: 1; min-width: 140px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--blue);"><?php echo count($ytTopVideos); ?></div>
                <div style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;"><?php echo t('media_player_sr_unique_videos'); ?></div>
            </div>
        </div>
        <?php if (!empty($ytTopVideos)): ?>
        <div class="sp-card" style="margin-bottom: 1.5rem;">
            <div class="sp-card-header">
                <div class="sp-card-title" style="font-size: 0.95rem;">
                    <i class="fas fa-trophy" style="color: var(--yellow, #f5c542);"></i>
                    <?php echo t('media_player_sr_top_videos'); ?>
                </div>
            </div>
            <div class="sp-card-body" style="padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;">#</th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('media_player_th_video'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('media_player_th_uploader'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: right; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_requests'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ytTopVideos as $i => $row): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo $i + 1; ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-primary);"><?php echo htmlspecialchars($row['title']); ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo htmlspecialchars($row['uploader'] ?? ''); ?></td>
                            <td style="padding: 0.6rem 1rem; text-align: right; color: var(--green); font-weight: 600;"><?php echo $row['request_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($ytRecentRequests)): ?>
        <div class="sp-card">
            <div class="sp-card-header">
                <div class="sp-card-title" style="font-size: 0.95rem;">
                    <i class="fas fa-history" style="color: var(--text-secondary);"></i>
                    <?php echo t('spotifylink_recent_requests'); ?>
                </div>
            </div>
            <div class="sp-card-body" style="padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('media_player_th_video'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('media_player_th_uploader'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: left; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_requested_by'); ?></th>
                            <th style="padding: 0.6rem 1rem; text-align: right; color: var(--text-secondary); font-weight: 600;"><?php echo t('spotifylink_th_time'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ytRecentRequests as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.6rem 1rem; color: var(--text-primary);"><?php echo htmlspecialchars($row['title']); ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo htmlspecialchars($row['uploader'] ?? ''); ?></td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-secondary);"><?php echo htmlspecialchars($row['requested_by']); ?></td>
                            <td style="padding: 0.6rem 1rem; text-align: right; color: var(--text-secondary); white-space: nowrap;"><?php echo htmlspecialchars($row['requested_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
<script>
    const code = <?php echo json_encode($api_key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const socket = io('wss://websocket.botofthespecter.com', { reconnection: true });
    socket.on('connect', () => socket.emit('REGISTER', { code: code, channel: 'Dashboard', name: 'Media Controller' }));
    socket.on('SUCCESS', () => socket.emit('MEDIA_COMMAND', { command: 'request_state', code: code }));
    socket.on('MEDIA_QUEUE_UPDATE', (d) => {
        document.getElementById('now-playing').textContent = d.now_playing
            ? ('▶ ' + d.now_playing.title + ' (req by ' + d.now_playing.requested_by + ')')
            : '<?php echo t('media_player_idle'); ?>';
        const ol = document.getElementById('queue-list');
        ol.innerHTML = '';
        (d.queue || []).forEach((row) => {
            const li = document.createElement('li');
            li.textContent = row.title + ' (req by ' + row.requested_by + ') ';
            const btn = document.createElement('button');
            btn.className = 'sp-btn sp-btn-ghost sp-btn-sm';
            btn.type = 'button';
            btn.textContent = '✕';
            btn.onclick = () => socket.emit('MEDIA_COMMAND', { command: 'remove', code: code, id: row.id });
            li.appendChild(btn);
            ol.appendChild(li);
        });
    });
    document.getElementById('btn-skip').onclick = () => socket.emit('MEDIA_COMMAND', { command: 'skip', code: code });
    document.getElementById('btn-clear').onclick = () => socket.emit('MEDIA_COMMAND', { command: 'clear', code: code });
    const volRange = document.getElementById('vol-range');
    if (volRange) volRange.addEventListener('change', (e) => socket.emit('MEDIA_COMMAND', { command: 'volume', code: code, value: Number(e.target.value) }));

    // Overlay URL: masked by default, reveal toggle, copy the real URL. The real key only
    // enters the DOM on an explicit user action, so it's safe to show on screen during setup.
    const mpUrlReal = <?php echo json_encode($overlayUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const mpUrlMasked = <?php echo json_encode($overlayUrlMasked, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const mpLang = {
        show: <?php echo json_encode(t('media_player_overlay_url_show')); ?>,
        hide: <?php echo json_encode(t('media_player_overlay_url_hide')); ?>,
        copied: <?php echo json_encode(t('media_player_overlay_url_copied')); ?>,
    };
    const mpUrlEl = document.getElementById('mp-overlay-url');
    const mpUrlReveal = document.getElementById('mp-url-reveal');
    const mpUrlCopy = document.getElementById('mp-url-copy');
    let mpUrlShown = false;
    if (mpUrlReveal && mpUrlEl) {
        mpUrlReveal.addEventListener('click', () => {
            mpUrlShown = !mpUrlShown;
            mpUrlEl.textContent = mpUrlShown ? mpUrlReal : mpUrlMasked;
            mpUrlReveal.setAttribute('aria-pressed', mpUrlShown ? 'true' : 'false');
            const lbl = mpUrlReveal.querySelector('.mp-url-reveal-label');
            if (lbl) lbl.textContent = mpUrlShown ? mpLang.hide : mpLang.show;
            const ico = mpUrlReveal.querySelector('i');
            if (ico) ico.className = mpUrlShown ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }
    if (mpUrlCopy) {
        mpUrlCopy.addEventListener('click', () => {
            navigator.clipboard.writeText(mpUrlReal).then(() => {
                const lbl = mpUrlCopy.querySelector('.mp-url-copy-label');
                if (!lbl) return;
                const orig = lbl.textContent;
                lbl.textContent = mpLang.copied;
                setTimeout(() => { lbl.textContent = orig; }, 1500);
            }).catch(() => {});
        });
    }
</script>
<script>
(function () {
    const card = document.getElementById('spotify-player-card');
    if (!card) return;
    const controls = document.getElementById('sp-controls');
    if (!controls) return; // act-as mode: controls not rendered
    const endpoint = '/api/spotify_player.php';
    const ERR = {
        no_device: <?php echo json_encode(t('media_player_spotify_err_no_device')); ?>,
        premium: <?php echo json_encode(t('media_player_spotify_err_premium')); ?>,
        expired: <?php echo json_encode(t('media_player_spotify_err_expired')); ?>,
        rate_limited: <?php echo json_encode(t('media_player_spotify_err_rate')); ?>,
        not_connected: <?php echo json_encode(t('media_player_spotify_err_generic')); ?>,
        generic: <?php echo json_encode(t('media_player_spotify_err_generic')); ?>,
    };
    const $ = (id) => document.getElementById(id);
    let pollTimer = null, volDebounce = null, suppressVolUntil = 0;
    let progTick = null, progMs = 0, durMs = 0, isPlaying = false;

    function fmtTime(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }
    function renderProgress() {
        const row = $('sp-progress-row');
        if (!row) return;
        if (!durMs) { row.style.display = 'none'; return; }
        row.style.display = 'flex';
        $('sp-progress-fill').style.width = Math.min(100, (progMs / durMs) * 100) + '%';
        $('sp-time-cur').textContent = fmtTime(progMs);
        $('sp-time-dur').textContent = fmtTime(durMs);
    }

    function setStatus(msg) {
        const el = $('sp-player-status');
        if (!el) return;
        el.textContent = msg || '';
        el.style.display = msg ? 'block' : 'none';
    }

    async function call(action, params) {
        const body = new URLSearchParams(Object.assign({ action: action }, params || {}));
        const res = await fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' });
        return res.json();
    }

    function renderState(d) {
        const np = $('sp-now-playing'), ctl = $('sp-controls');
        if (!d || !d.success || !d.active || !d.track) {
            setStatus(ERR.no_device);
            if (np) np.style.display = 'none';
            if (ctl) ctl.style.display = 'none';
            durMs = 0; renderProgress();
            return;
        }
        setStatus('');
        if (np) np.style.display = 'flex';
        if (ctl) ctl.style.display = 'flex';
        $('sp-art').src = d.track.album_art || '';
        $('sp-track').textContent = d.track.name || '';
        $('sp-artist').textContent = d.track.artists || '';
        $('sp-device').textContent = (d.device && d.device.name) ? ('🔊 ' + d.device.name) : '';
        progMs = d.progress_ms || 0;
        durMs = (d.track && d.track.duration_ms) || 0;
        isPlaying = !!d.is_playing;
        renderProgress();
        $('sp-playpause').innerHTML = d.is_playing ? '&#9208;' : '&#9654;';
        const dis = d.disallows || {};
        $('sp-playpause').disabled = d.is_playing ? !!dis.pausing : !!dis.resuming;
        $('sp-next').disabled = !!dis.skipping_next;
        $('sp-prev').disabled = !!dis.skipping_prev;
        const vol = $('sp-volume');
        if (d.device && d.device.supports_volume) {
            vol.style.display = '';
            if (Date.now() > suppressVolUntil) vol.value = d.device.volume_percent;
        } else {
            vol.style.display = 'none';
        }
    }

    async function poll() {
        try {
            const res = await fetch(endpoint + '?action=state', { credentials: 'same-origin' });
            renderState(await res.json());
        } catch (e) { /* keep last rendered state on transient network errors */ }
    }

    function handleActionResult(r) {
        if (r && !r.success) setStatus(ERR[r.error] || ERR.generic);
    }

    $('sp-playpause').addEventListener('click', async () => {
        const playing = $('sp-playpause').textContent.charCodeAt(0) === 0x23F8; // pause glyph means currently playing
        handleActionResult(await call(playing ? 'pause' : 'play'));
        setTimeout(poll, 300);
    });
    $('sp-next').addEventListener('click', async () => { handleActionResult(await call('next')); setTimeout(poll, 400); });
    $('sp-prev').addEventListener('click', async () => { handleActionResult(await call('previous')); setTimeout(poll, 400); });
    $('sp-volume').addEventListener('input', () => { suppressVolUntil = Date.now() + 3000; });
    $('sp-volume').addEventListener('change', () => {
        clearTimeout(volDebounce);
        const v = Number($('sp-volume').value);
        volDebounce = setTimeout(async () => { handleActionResult(await call('volume', { value: v })); }, 250);
    });

    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
    poll();
    pollTimer = setInterval(() => { if (document.visibilityState === 'visible') poll(); }, 5000);
    progTick = setInterval(() => {
        if (isPlaying && durMs && document.visibilityState === 'visible') {
            progMs = Math.min(durMs, progMs + 1000);
            renderProgress();
        }
    }, 1000);
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
