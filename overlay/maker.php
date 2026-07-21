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
    'show_link'              => 1,
    'show_featured'          => 1,
    'show_current'           => 0,
    'show_finished'          => 0,
    'show_upcoming'          => 0,
    'box_layout'             => 'positioned',
    'position_featured_x'    => 79, 'position_featured_y' => 64,
    'position_current_x'     => 2,  'position_current_y'  => 64,
    'position_upcoming_x'    => 79, 'position_upcoming_y' => 3,
    'position_finished_x'    => 2,  'position_finished_y' => 3,
    'image_fit'              => 'blur',
];

// Build the snapshot used by both the JSON endpoint and the initial render.
function maker_load_state($host, $user, $pass, $username, $default_settings) {
    $state = [
        'ok'       => true,
        'settings' => $default_settings,
        'featured' => null,
        'current'  => [],
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
    $s['show_link']              = (int)$s['show_link'];
    $s['show_featured']          = (int)$s['show_featured'];
    $s['show_current']           = (int)$s['show_current'];
    $s['show_finished']          = (int)$s['show_finished'];
    $s['show_upcoming']          = (int)$s['show_upcoming'];
    foreach (['position_featured_x','position_featured_y','position_current_x','position_current_y',
              'position_finished_x','position_finished_y','position_upcoming_x','position_upcoming_y'] as $pk) {
        $s[$pk] = max(0, min(100, (float)$s[$pk]));
    }
    $s['carousel_seconds']       = max(2, (int)$s['carousel_seconds']);
    $s['project_rotate_seconds'] = max(3, (int)$s['project_rotate_seconds']);
    if ($s['current_project_id'] !== null) { $s['current_project_id'] = (int)$s['current_project_id']; }
    unset($s);

    // Load all projects, then attach images in a second pass. Newest-touched first
    // (updated_at DESC, id DESC) so each box rotates the most recently added/edited
    // project first; matches the dashboard library order.
    $projects = [];
    if ($res = @$db->query("SELECT id, title, description, status, link_url, completed_at, updated_at FROM maker_projects ORDER BY updated_at DESC, id DESC")) {
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
            // The Current box rotates through every current-status project.
            $state['current'][] = $p;
            // Auto-track: the Featured box highlights the current project worked on most
            // recently (newest updated_at; newer id breaks ties).
            $f = $state['featured'];
            if ($f === null
                || strcmp((string)$p['updated_at'], (string)$f['updated_at']) > 0
                || ((string)$p['updated_at'] === (string)$f['updated_at'] && $p['id'] > $f['id'])) {
                $state['featured'] = $p;
            }
        }
    }
    // Explicit pin wins: if the user pinned a project via "Feature now" and it is still a
    // live current project, show that as Featured instead of the auto-tracked newest one.
    $pinId = $state['settings']['current_project_id'] ?? null;
    if ($pinId !== null) {
        foreach ($state['current'] as $cp) {
            if ((int)$cp['id'] === (int)$pinId) { $state['featured'] = $cp; break; }
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
<div id="makerOverlay" style="display:none;"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var urlParams = new URLSearchParams(window.location.search);
    var code = urlParams.get('code');
    var jsonUrl = 'maker.php?code=' + encodeURIComponent(code || '') + '&type=json';

    var el = document.getElementById('makerOverlay');
    var state = <?php echo json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // Every interval created across the (up to four) boxes, cleared on each re-render.
    var regionTimers = [];

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function clearTimers() {
        regionTimers.forEach(function (t) { clearInterval(t); });
        regionTimers = [];
    }

    // Build one positioned box (a fixed card in its own corner). Styling is applied
    // inline on the card itself so the chosen font/colours win over the base CSS rule.
    function buildRegion(s) {
        var region = document.createElement('div');
        // Bare styled card. Positioning is applied by the caller: an inline left/top in
        // positioned layout, or the stack container in stacked layout.
        region.className = 'maker-overlay-page';
        region.style.setProperty('--maker-accent', s.accent_color || '#9146FF');
        region.style.setProperty('--maker-text', s.text_color || '#FFFFFF');
        region.style.color = s.text_color || '#FFFFFF';
        region.style.fontFamily = (s.font_family || 'Arial') + ', sans-serif';
        return region;
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
                var imgUrl = escapeHtml(p.images[i].url);
                var fitClass = escapeHtml(s.image_fit || 'blur');
                html += '<div class="maker-overlay-page-image-item fit-' + fitClass + (i === 0 ? ' is-active' : '') +
                        '" data-caption="' + escapeHtml(p.images[i].caption || '') + '">';
                html += '<div class="maker-overlay-page-image-blur" style="background-image: url(\'' + imgUrl + '\');"></div>';
                html += '<img class="maker-overlay-page-image-fg" src="' + imgUrl + '" alt="">';
                html += '</div>';
            }
            html += '</div>';
            html += '<div class="maker-overlay-page-caption">' + escapeHtml(p.images[0].caption || '') + '</div>';
        }
        if (parseInt(s.show_description, 10) === 1 && p.description) {
            html += '<div class="maker-overlay-page-desc">' + escapeHtml(p.description) + '</div>';
        }
        if (parseInt(s.show_link, 10) === 1 && p.link_url) {
            html += '<div class="maker-overlay-page-link">' + escapeHtml(p.link_url) + '</div>';
        }
        html += '</div>';
        return html;
    }

    // Drive a single box: render list[0], carousel its images, and (if more than one
    // project) rotate through the list. Each project renders into its own crossfade layer
    // and its first image is preloaded, so rotations fade smoothly with no blank-box blink.
    function runRegion(region, list, label, s) {
        var projIdx = 0;
        var carTimer = null;
        var activeLayer = null;
        // Match the CSS --maker-fade (10% of the per-image on-screen time) so the old layer
        // is removed only once it has finished fading out.
        var fadeMs = Math.max(2, parseInt(s.carousel_seconds, 10) || 6) * 100;

        function startCarousel(layer) {
            var imgs = layer.querySelectorAll('.maker-overlay-page-image-item');
            if (imgs.length <= 1) { return; }
            var capEl = layer.querySelector('.maker-overlay-page-caption');
            var imgIdx = 0;
            carTimer = setInterval(function () {
                imgs[imgIdx].classList.remove('is-active');
                imgIdx = (imgIdx + 1) % imgs.length;
                imgs[imgIdx].classList.add('is-active');
                if (capEl) { capEl.textContent = imgs[imgIdx].getAttribute('data-caption') || ''; }
            }, Math.max(2, parseInt(s.carousel_seconds, 10) || 6) * 1000);
            layer._carouselTimer = carTimer; // so the layer's carousel stops when it is removed
            regionTimers.push(carTimer);
        }

        // Preload the project's first (immediately shown) image into the browser cache, then
        // run cb. cb also fires on error, when the image is already cached, or after a short
        // ceiling so a slow/broken image can't stall the rotation.
        function preloadFirst(p, cb) {
            if (!p.images || !p.images.length) { cb(); return; }
            var probe = new Image();
            var done = false;
            function finish() { if (!done) { done = true; cb(); } }
            probe.onload = finish;
            probe.onerror = finish;
            probe.src = p.images[0].url;
            if (probe.complete) { finish(); }
            setTimeout(finish, 1500);
        }

        function showProj(idx) {
            // Stop the outgoing project's carousel before bringing in the next card.
            if (carTimer) { clearInterval(carTimer); carTimer = null; }
            var nextLayer = document.createElement('div');
            nextLayer.className = 'maker-overlay-page-layer';
            nextLayer.innerHTML = renderProjectCard(list[idx], s, label);
            region.appendChild(nextLayer);
            var prev = activeLayer;
            activeLayer = nextLayer;

            preloadFirst(list[idx], function () {
                // A full re-render (MAKER_UPDATE) detaches the region; bail so we don't
                // crossfade or start carousels on dead nodes.
                if (!region.isConnected) { return; }
                void nextLayer.offsetWidth; // commit opacity:0 so adding is-shown transitions
                nextLayer.classList.add('is-shown');
                startCarousel(nextLayer);
                if (prev) {
                    prev.classList.remove('is-shown');
                    setTimeout(function () {
                        if (prev._carouselTimer) { clearInterval(prev._carouselTimer); }
                        if (prev.parentNode) { prev.parentNode.removeChild(prev); }
                    }, fadeMs + 80);
                }
            });
        }

        showProj(0);
        if (list.length > 1) {
            var rot = setInterval(function () {
                projIdx = (projIdx + 1) % list.length;
                showProj(projIdx);
            }, (parseInt(s.project_rotate_seconds, 10) || 15) * 1000);
            regionTimers.push(rot);
        }
    }

    function render() {
        clearTimers();
        el.innerHTML = '';
        // Bad API key / DB error -> hide entirely rather than show a misleading empty card.
        if (!state || !state.ok) { el.style.display = 'none'; return; }
        var s = state.settings || {};

        if (parseInt(s.visible, 10) !== 1) {
            el.style.display = 'none';
            return;
        }
        el.style.display = 'block';

        // Crossfade duration = ~10% of the per-image on-screen time (min 2s -> 0.2s). Shared
        // by the image carousel and the project-rotation crossfade via the --maker-fade var.
        var fadeSeconds = Math.max(2, parseInt(s.carousel_seconds, 10) || 6) * 0.1;
        el.style.setProperty('--maker-fade', fadeSeconds + 's');

        // Assemble the enabled boxes in display order: Featured, Current, Upcoming, Finished.
        // Featured highlights the single auto-tracked project; the rest rotate their bucket.
        var showFeatured = parseInt(s.show_featured, 10) === 1 && state.featured;
        var boxes = [];

        if (showFeatured) {
            boxes.push({ list: [state.featured], label: 'Now working on', x: s.position_featured_x, y: s.position_featured_y });
        }
        if (parseInt(s.show_current, 10) === 1) {
            // The featured project has its own box, so drop it from the Current rotation
            // (when Featured is on) to avoid it appearing twice on screen.
            var currentList = (state.current || []).filter(function (p) {
                return !(showFeatured && state.featured && p.id === state.featured.id);
            });
            if (currentList.length) {
                boxes.push({ list: currentList, label: 'Current projects', x: s.position_current_x, y: s.position_current_y });
            }
        }
        if (parseInt(s.show_upcoming, 10) === 1 && (state.upcoming || []).length) {
            boxes.push({ list: state.upcoming, label: 'Coming up', x: s.position_upcoming_x, y: s.position_upcoming_y });
        }
        if (parseInt(s.show_finished, 10) === 1 && (state.finished || []).length) {
            boxes.push({ list: state.finished, label: 'Finished', x: s.position_finished_x, y: s.position_finished_y });
        }

        if (!boxes.length) { el.style.display = 'none'; return; }

        var layout = s.box_layout || 'positioned';
        if (layout === 'stacked-left' || layout === 'stacked-right') {
            // One vertical column on the chosen side; cards flow inside it (CSS makes them
            // static), so the per-box x/y positions are ignored in this layout.
            var stack = document.createElement('div');
            stack.className = 'maker-overlay-stack ' + (layout === 'stacked-right' ? 'stack-right' : 'stack-left');
            el.appendChild(stack);
            boxes.forEach(function (b) {
                var card = buildRegion(s);
                stack.appendChild(card);
                runRegion(card, b.list, b.label, s);
            });
        } else {
            // Each box is a fixed card anchored by its top-left at the stored x/y percent.
            boxes.forEach(function (b) {
                var card = buildRegion(s);
                card.style.left = (b.x != null ? b.x : 0) + '%';
                card.style.top = (b.y != null ? b.y : 0) + '%';
                el.appendChild(card);
                runRegion(card, b.list, b.label, s);
            });
        }
    }

    function refetch() {
        fetch(jsonUrl, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.settings) return;
                state = data;
                render();
            })
            .catch(function () { /* keep last good state */ });
    }

    function showOverlayError(message, type) {
        var banner = document.getElementById('overlayErrorBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'overlayErrorBanner';
            document.body.appendChild(banner);
        }
        banner.textContent = message;
        banner.className = 'overlay-error-banner ' + (type === 'warn' ? 'overlay-error-banner-warn' : 'overlay-error-banner-danger');
        banner.style.display = 'block';
        if (type === 'warn') {
            clearTimeout(banner._timeoutId);
            banner._timeoutId = setTimeout(function () { banner.style.display = 'none'; }, 6000);
        }
    }

    function setConnectionStatus(text, state) {
        var status = document.getElementById('overlayConnectionStatus');
        if (!status) {
            status = document.createElement('div');
            status.id = 'overlayConnectionStatus';
            status.className = 'overlay-connection-status';
            document.body.appendChild(status);
        }
        status.textContent = text;
        status.dataset.state = state;
    }

    var username = <?php echo json_encode($username); ?>;
    if (!code) {
        showOverlayError('No code provided in the URL', 'danger');
    } else if (!username) {
        showOverlayError('Invalid code provided in the URL', 'danger');
    }

    var socket;
    var retryInterval = 5000;
    var reconnectAttempts = 0;

    function connect() {
        if (!code) { return; }
        setConnectionStatus('Connecting…', 'connecting');
        socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
        socket.on('connect', function () {
            setConnectionStatus('Connected', 'connected');
            reconnectAttempts = 0;
            socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Makers' });
        });
        socket.on('disconnect', function () {
            setConnectionStatus('Disconnected', 'error');
            attemptReconnect();
        });
        socket.on('connect_error', function () {
            setConnectionStatus('Connection error', 'error');
            attemptReconnect();
        });
        socket.on('MAKER_UPDATE', function () { refetch(); });
        // Dashboard "Refresh Overlay" - full page reload so PHP re-fetches settings.
        socket.on('OVERLAY_REFRESH', function (data) {
            console.log('OVERLAY_REFRESH received - reloading', data);
            var meta = document.createElement('meta');
            meta.setAttribute('http-equiv', 'refresh');
            meta.setAttribute('content', '0');
            document.head.appendChild(meta);
        });
    }
    function attemptReconnect() {
        reconnectAttempts++;
        var delay = Math.min(retryInterval * reconnectAttempts, 30000);
        setConnectionStatus('Reconnecting…', 'connecting');
        setTimeout(connect, delay);
    }

    render();
    connect();
});
</script>
</body>
</html>
