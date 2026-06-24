<?php
include '/var/www/config/database.php';

$api_key = $_GET['code'] ?? '';
$type    = strtolower($_GET['type'] ?? 'html');
if ($type !== 'json') { $type = 'html'; }

$username = '';
$conn = @new mysqli($db_servername, $db_username, $db_password, 'website');
if (!$conn->connect_error && $api_key !== '') {
    $stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
    $stmt->bind_param('s', $api_key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $username = $row['username'] ?? '';
}
if (!$conn->connect_error) { $conn->close(); }

$default_settings = [
    'display_mode'           => 'current',
    'current_project_id'     => null,
    'visible'                => 1,
    'carousel_seconds'       => 6,
    'project_rotate_seconds' => 15,
    'accent_color'           => '#9146FF',
    'text_color'             => '#FFFFFF',
    'font_family'            => 'Arial',
    'position'               => 'bottom-right',
    'show_title'             => 1,
    'show_description'        => 1,
];

// Build the snapshot used by both the JSON endpoint and the initial render.
function maker_load_state($host, $user, $pass, $username, $default_settings) {
    $state = [
        'ok'       => true,
        'settings' => $default_settings,
        'current'  => null,
        'finished' => [],
        'upcoming' => [],
    ];
    if ($username === '') { $state['ok'] = false; return $state; }
    $db = @new mysqli($host, $user, $pass, $username);
    if ($db->connect_error) { $state['ok'] = false; return $state; }

    // Settings (singleton id = 1)
    if ($res = @$db->query("SELECT * FROM maker_overlay_settings WHERE id = 1 LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            foreach ($default_settings as $k => $v) {
                if (array_key_exists($k, $row) && $row[$k] !== null) {
                    $state['settings'][$k] = $row[$k];
                }
            }
            $state['settings']['current_project_id'] = $row['current_project_id'] ?? null;
        }
        $res->free();
    }
    // Normalise numeric fields
    $s = &$state['settings'];
    $s['visible']                = (int)$s['visible'];
    $s['show_title']             = (int)$s['show_title'];
    $s['show_description']       = (int)$s['show_description'];
    $s['carousel_seconds']       = max(2, (int)$s['carousel_seconds']);
    $s['project_rotate_seconds'] = max(3, (int)$s['project_rotate_seconds']);
    if ($s['current_project_id'] !== null) { $s['current_project_id'] = (int)$s['current_project_id']; }
    unset($s);

    // Load all projects, then attach images in a second pass.
    $projects = [];
    if ($res = @$db->query("SELECT id, title, description, status, link_url, completed_at, updated_at FROM maker_projects ORDER BY sort_order ASC, id ASC")) {
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['images'] = [];
            $projects[$row['id']] = $row;
        }
        $res->free();
    }
    if (!empty($projects)) {
        if ($res = @$db->query("SELECT project_id, media_file, caption FROM maker_project_images ORDER BY sort_order ASC, id ASC")) {
            while ($img = $res->fetch_assoc()) {
                $pid = (int)$img['project_id'];
                if (isset($projects[$pid])) {
                    $file = $img['media_file'];
                    $projects[$pid]['images'][] = [
                        'media_file' => $file,
                        'caption'    => $img['caption'],
                        'url'        => 'https://media.botofthespecter.com/' . rawurlencode($username) . '/' . rawurlencode($file),
                    ];
                }
            }
            $res->free();
        }
    }

    foreach ($projects as $p) {
        if ($p['status'] === 'finished') {
            $state['finished'][] = $p;
        } elseif ($p['status'] === 'upcoming') {
            $state['upcoming'][] = $p;
        } elseif ($p['status'] === 'current') {
            // Auto-track: the featured "current" card is the current-status project
            // worked on most recently (newest updated_at; newer id breaks ties).
            $cur = $state['current'];
            if ($cur === null
                || strcmp((string)$p['updated_at'], (string)$cur['updated_at']) > 0
                || ((string)$p['updated_at'] === (string)$cur['updated_at'] && $p['id'] > $cur['id'])) {
                $state['current'] = $p;
            }
        }
    }
    $db->close();
    return $state;
}

$state = maker_load_state($db_servername, $db_username, $db_password, $username, $default_settings);

if ($type === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($state);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Makers &amp; Crafting Overlay</title>
<link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
</head>
<body>
<div id="makerOverlay" class="maker-overlay-page" style="display:none;"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var urlParams = new URLSearchParams(window.location.search);
    var code = urlParams.get('code');
    var jsonUrl = 'maker.php?code=' + encodeURIComponent(code || '') + '&type=json';

    var el = document.getElementById('makerOverlay');
    var state = <?php echo json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    var carouselTimer = null;
    var rotateTimer = null;
    var imgIndex = 0;
    var projIndex = 0;

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function clearTimers() {
        if (carouselTimer) { clearInterval(carouselTimer); carouselTimer = null; }
        if (rotateTimer) { clearInterval(rotateTimer); rotateTimer = null; }
    }

    function applyShell(s) {
        el.className = 'maker-overlay-page pos-' + (s.position || 'bottom-right');
        el.style.setProperty('--maker-accent', s.accent_color || '#9146FF');
        el.style.setProperty('--maker-text', s.text_color || '#FFFFFF');
        el.style.color = s.text_color || '#FFFFFF';
        el.style.fontFamily = (s.font_family || 'Arial') + ', sans-serif';
    }

    function renderProjectCard(p, s, label) {
        var html = '<div class="maker-overlay-page-content">';
        if (label) {
            html += '<div class="maker-overlay-page-eyebrow">' + escapeHtml(label) + '</div>';
        }
        if (parseInt(s.show_title, 10) === 1 && p.title) {
            html += '<div class="maker-overlay-page-title">' + escapeHtml(p.title) + '</div>';
        }
        if (p.images && p.images.length) {
            html += '<div class="maker-overlay-page-carousel">';
            for (var i = 0; i < p.images.length; i++) {
                html += '<img class="maker-overlay-page-image' + (i === 0 ? ' is-active' : '') +
                        '" data-caption="' + escapeHtml(p.images[i].caption || '') +
                        '" src="' + escapeHtml(p.images[i].url) + '" alt="">';
            }
            html += '</div>';
            html += '<div class="maker-overlay-page-caption">' + escapeHtml(p.images[0].caption || '') + '</div>';
        }
        if (parseInt(s.show_description, 10) === 1 && p.description) {
            html += '<div class="maker-overlay-page-desc">' + escapeHtml(p.description) + '</div>';
        }
        if (p.link_url) {
            html += '<div class="maker-overlay-page-link">' + escapeHtml(p.link_url) + '</div>';
        }
        html += '</div>';
        return html;
    }

    function startCarousel(seconds) {
        var imgs = el.querySelectorAll('.maker-overlay-page-image');
        if (imgs.length <= 1) { return; }
        var capEl = el.querySelector('.maker-overlay-page-caption');
        carouselTimer = setInterval(function () {
            imgs[imgIndex].classList.remove('is-active');
            imgIndex = (imgIndex + 1) % imgs.length;
            imgs[imgIndex].classList.add('is-active');
            if (capEl) { capEl.textContent = imgs[imgIndex].getAttribute('data-caption') || ''; }
        }, Math.max(2, seconds) * 1000);
    }

    function showProject(list, idx, s, label) {
        imgIndex = 0;
        if (carouselTimer) { clearInterval(carouselTimer); carouselTimer = null; }
        el.innerHTML = renderProjectCard(list[idx], s, label);
        startCarousel(parseInt(s.carousel_seconds, 10) || 6);
    }

    function render() {
        clearTimers();
        // Bad API key / DB error -> hide entirely rather than show a misleading empty card.
        if (!state || !state.ok) { el.style.display = 'none'; return; }
        imgIndex = 0;
        var s = state.settings || {};

        if (parseInt(s.visible, 10) !== 1) {
            el.style.display = 'none';
            return;
        }
        applyShell(s);

        var mode = s.display_mode || 'current';
        var list = [];
        var label = '';
        if (mode === 'finished') {
            list = state.finished || [];
            label = 'Finished';
        } else if (mode === 'upcoming') {
            list = state.upcoming || [];
            label = 'Coming up';
        } else {
            if (state.current) { list = [state.current]; }
            label = '';
        }

        if (!list.length) {
            el.style.display = 'block';
            var emptyMsg = mode === 'current' ? 'No current project set'
                         : mode === 'finished' ? 'No finished projects yet'
                         : 'No upcoming ideas yet';
            el.innerHTML = '<div class="maker-overlay-page-content"><div class="maker-overlay-page-empty">' +
                           escapeHtml(emptyMsg) + '</div></div>';
            return;
        }

        el.style.display = 'block';
        projIndex = projIndex % list.length;
        showProject(list, projIndex, s, label);

        if (list.length > 1) {
            rotateTimer = setInterval(function () {
                projIndex = (projIndex + 1) % list.length;
                showProject(list, projIndex, s, label);
            }, (parseInt(s.project_rotate_seconds, 10) || 15) * 1000);
        }
    }

    function refetch() {
        fetch(jsonUrl, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.settings) return;
                state = data;
                projIndex = 0;
                render();
            })
            .catch(function () { /* keep last good state */ });
    }

    var socket;
    var retryInterval = 5000;
    var reconnectAttempts = 0;

    function connect() {
        if (!code) { return; }
        socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
        socket.on('connect', function () {
            reconnectAttempts = 0;
            socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Makers' });
        });
        socket.on('disconnect', attemptReconnect);
        socket.on('connect_error', attemptReconnect);
        socket.on('MAKER_UPDATE', function () { refetch(); });
    }
    function attemptReconnect() {
        reconnectAttempts++;
        var delay = Math.min(retryInterval * reconnectAttempts, 30000);
        setTimeout(connect, delay);
    }

    render();
    connect();
});
</script>
</body>
</html>
