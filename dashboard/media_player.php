<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('media_player_title');

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include "mod_access.php";
include 'user_db.php';
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

$overlayUrl = "https://overlay.botofthespecter.com/mediaplayer.php?code=" . urlencode($api_key);

ob_start();
?>
<div class="sp-card mb-4">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-play-circle"></i> <?php echo t('media_player_title'); ?></span>
    </header>
    <div class="sp-card-body">
        <p style="margin-bottom:1rem;"><?php echo t('media_player_intro'); ?></p>
        <label class="sp-label"><?php echo t('media_player_overlay_url'); ?></label>
        <input class="sp-input" readonly value="<?php echo htmlspecialchars($overlayUrl); ?>" onclick="this.select();">
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
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
