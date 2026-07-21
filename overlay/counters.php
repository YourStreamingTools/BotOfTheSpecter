<?php
// Counter overlay/API - returns the value of one custom counter for a streamer.
//
// URL:
//   https://overlay.botofthespecter.com/counters.php?code=API_KEY&counter=frog
//
// Params:
//   code     (required) - the streamer's API key
//   counter  (required) - the counter command name (e.g. "frog")
//   type     (optional) - output format. Default: html (OBS-friendly overlay).
//                          html   → styled HTML page with auto-contrast text,
//                                   polls for updates every 3 seconds.
//                          text   → "frog: 5"
//                          number → "5"
//                          name   → "frog"
//                          json   → {"counter":"frog","count":5}
//   color    (optional, html only) - text colour: a hex value (with or without
//                                    leading #) or a named CSS colour. If omitted,
//                                    JS picks black or white based on the body
//                                    background's luminance.
//   bg       (optional, html only) - body background colour. Defaults to
//                                    transparent (OBS-friendly).

include '/var/www/config/database.php';

$api_key      = $_GET['code']    ?? '';
$counter_name = $_GET['counter'] ?? '';
$type         = strtolower($_GET['type'] ?? 'html');

if (!in_array($type, ['json', 'text', 'number', 'name', 'html'], true)) {
    $type = 'html';
}

// Validate a colour string. Returns a CSS-safe colour ('#abc', '#abcdef', or
// a named colour from the whitelist) or null when nothing usable was passed.
function validate_color($input) {
    if ($input === null) return null;
    $input = trim((string)$input);
    if ($input === '') return null;
    // Hex (with or without leading #), 3 or 6 digits
    if (preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $input, $m)) {
        return '#' . $m[1];
    }
    // Common CSS named colours - whitelist to avoid CSS injection
    $named = [
        'white','black','red','green','blue','yellow','orange','purple','pink',
        'cyan','magenta','gray','grey','brown','transparent','aqua','lime',
        'maroon','navy','olive','silver','teal','gold','indigo','violet',
    ];
    $lower = strtolower($input);
    if (in_array($lower, $named, true)) return $lower;
    return null;
}
$colorParam = validate_color($_GET['color'] ?? null);
$bgParam    = validate_color($_GET['bg']    ?? null);

if ($type === 'html') {
    header('Content-Type: text/html; charset=utf-8');
} elseif ($type === 'json') {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

function respond_error($type, $message) {
    if ($type === 'json') {
        echo json_encode(['error' => $message]);
    } elseif ($type === 'html') {
        echo '<!doctype html><meta charset="utf-8"><title>Counter error</title><body style="font:14px/1.4 sans-serif;color:#f87171;background:#000;padding:1rem">' . htmlspecialchars($message) . '</body>';
    } else {
        echo $message;
    }
    exit;
}

if ($api_key === '' || $counter_name === '') {
    respond_error($type, 'Missing code or counter parameter');
}

// Restrict counter name to command-like characters (matches how the bot stores them)
$counter_safe = preg_replace('/[^A-Za-z0-9_-]/', '', $counter_name);
if ($counter_safe === '') {
    respond_error($type, 'Invalid counter name');
}

// Resolve API key → username (per-user DB name)
$conn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    respond_error($type, 'Database error');
}
$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param('s', $api_key);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
$username = $user['username'] ?? '';
if ($username === '') {
    respond_error($type, 'Invalid code');
}

// Look up the count in the streamer's database
$user_db = new mysqli($db_servername, $db_username, $db_password, $username);
if ($user_db->connect_error) {
    respond_error($type, 'Database error');
}

// Built-in counters mapped to their per-user tables. Keys must be lowercase
// because we case-fold the requested name before lookup. Queries mirror what
// the bot's !deaths / !hug / !kiss / !highfive commands actually report:
//   - `deaths`        → lifetime total across all games (game_deaths is the
//                       source of truth; the legacy total_deaths table exists
//                       in the schema but the bot no longer writes to it).
//   - `stream_deaths` → this stream's total across all games.
//   - hugs/kisses/highfives → sum across all chatters.
//   - typos / lurkers → channel-wide totals.
$builtinCounters = [
    'deaths'        => "SELECT COALESCE(SUM(death_count), 0)    AS n FROM game_deaths",
    'stream_deaths' => "SELECT COALESCE(SUM(death_count), 0)    AS n FROM per_stream_deaths",
    'hugs'          => "SELECT COALESCE(SUM(hug_count), 0)      AS n FROM hug_counts",
    'kisses'        => "SELECT COALESCE(SUM(kiss_count), 0)     AS n FROM kiss_counts",
    'highfives'     => "SELECT COALESCE(SUM(highfive_count), 0) AS n FROM highfive_counts",
    'typos'         => "SELECT COALESCE(SUM(typo_count), 0)     AS n FROM user_typos",
    'lurkers'       => "SELECT COUNT(*)                         AS n FROM lurk_times",
];

$count = 0;
$counter_lower = strtolower($counter_safe);
if (isset($builtinCounters[$counter_lower])) {
    // Built-in counter - run the predefined query (no user input in SQL)
    if ($res = $user_db->query($builtinCounters[$counter_lower])) {
        $row = $res->fetch_assoc();
        if ($row) $count = (int)$row['n'];
        $res->free();
    }
} else {
    // Fall through to user-defined custom counters
    $stmt = $user_db->prepare("SELECT count FROM custom_counts WHERE command = ?");
    if ($stmt) {
        $stmt->bind_param('s', $counter_safe);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $count = (int)$row['count'];
        $stmt->close();
    }
}
$user_db->close();

if ($type !== 'html') {
    switch ($type) {
        case 'json':
            echo json_encode(['counter' => $counter_safe, 'count' => $count]);
            break;
        case 'number':
            echo $count;
            break;
        case 'name':
            echo $counter_safe;
            break;
        case 'text':
        default:
            echo $counter_safe . ': ' . $count;
            break;
    }
    exit;
}

// HTML overlay: transparent body by default, polls for live updates, picks a
// contrasting text colour from the body's computed background unless ?color=
// was passed explicitly.
$bgCss     = $bgParam ?? 'transparent';
$colorCss  = $colorParam ?? '#ffffff';
$autoColor = ($colorParam === null);   // when user didn't pin a colour, JS auto-picks
$counterLabel = $counter_safe;
$apiUrl = '?' . http_build_query([
    'code'    => $api_key,
    'counter' => $counter_safe,
    'type'    => 'json',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Counter: <?php echo htmlspecialchars($counterLabel); ?></title>
<style>
    html, body { margin: 0; padding: 0; height: 100vh; width: 100vw; }
    body {
        background: <?php echo $bgCss; ?>;
        color: <?php echo $colorCss; ?>;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        font-weight: 800;
        font-size: 8vh;
        text-shadow: 0 2px 8px rgba(0,0,0,0.55), 0 0 2px rgba(0,0,0,0.4);
        overflow: hidden;
    }
    #counter {
        line-height: 1;
        white-space: nowrap;
        transform-origin: center center;
        will-change: transform;
    }
</style>
</head>
<body>
<div id="counter"><?php echo htmlspecialchars($counter_safe . ': ' . $count); ?></div>
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
<script>
(function () {
    var endpoint   = <?php echo json_encode($apiUrl); ?>;
    var label      = <?php echo json_encode($counter_safe); ?>;
    var autoColor  = <?php echo $autoColor ? 'true' : 'false'; ?>;
    var el = document.getElementById('counter');

    // Pick a text colour that contrasts with the body background.
    // Transparent → fall back to white (overlays usually sit on dark streams).
    function applyAutoContrast() {
        if (!autoColor) return;
        var bg = getComputedStyle(document.body).backgroundColor || '';
        var m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
        if (!m) return;
        var alpha = m[4] !== undefined ? parseFloat(m[4]) : 1;
        if (alpha < 0.1) {
            document.body.style.color = '#ffffff';
            return;
        }
        var r = +m[1], g = +m[2], b = +m[3];
        // Rec. 601 luma - good enough for picking light vs dark
        var lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        document.body.style.color = lum > 0.6 ? '#000000' : '#ffffff';
    }
    applyAutoContrast();

    // Scale the text to fit whatever browser-source size the streamer set in
    // OBS. We measure the element's natural size with the transform cleared,
    // then apply a uniform scale so the text fills the window (minus ~5%
    // margin) on whichever axis is the tighter fit. Re-runs on every refresh
    // because the count value can change the text width.
    function fitText() {
        if (!el) return;
        el.style.transform = 'none';
        var w = el.offsetWidth;
        var h = el.offsetHeight;
        if (!w || !h) return;
        var pad = 0.92; // ~4% margin each side
        var scale = Math.min(
            (window.innerWidth  * pad) / w,
            (window.innerHeight * pad) / h
        );
        if (!isFinite(scale) || scale <= 0) return;
        el.style.transform = 'scale(' + scale + ')';
    }
    function setText(t) {
        el.textContent = t;
        // Defer to next frame so layout reflects the new text before we measure
        requestAnimationFrame(fitText);
    }

    // Initial fit + react to OBS browser-source resizes
    requestAnimationFrame(fitText);
    window.addEventListener('resize', fitText);
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(fitText).observe(document.body);
    }

    // Live updates - counters tick during a stream
    function refresh() {
        fetch(endpoint, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || data.error) return;
                setText(label + ': ' + data.count);
            })
            .catch(function () { /* silent - keep showing the last good value */ });
    }
    setInterval(refresh, 3000);

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

    var params = new URLSearchParams(location.search);
    var code = params.get('code');
    if (!code) {
        showOverlayError('No code provided in the URL', 'danger');
    }

    // WebSocket - listen for dashboard refresh signal
    (function () {
        if (!code) return;
        var socket;
        var reconnectAttempts = 0;
        function connectWS() {
            setConnectionStatus('Connecting…', 'connecting');
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
            socket.on('connect', function () {
                setConnectionStatus('Connected', 'connected');
                reconnectAttempts = 0;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Counter' });
            });
            socket.on('OVERLAY_REFRESH', function (data) {
                var meta = document.createElement('meta');
                meta.setAttribute('http-equiv', 'refresh');
                meta.setAttribute('content', '0');
                document.head.appendChild(meta);
            });
            socket.on('disconnect', function () {
                setConnectionStatus('Disconnected', 'error');
                scheduleReconnect();
            });
            socket.on('connect_error', function () {
                setConnectionStatus('Connection error', 'error');
                scheduleReconnect();
            });
        }
        function scheduleReconnect() {
            reconnectAttempts++;
            setConnectionStatus('Reconnecting…', 'connecting');
            setTimeout(connectWS, Math.min(5000 * reconnectAttempts, 30000));
        }
        connectWS();
    })();
})();
</script>
</body>
</html>
