<?php
ob_start();

$error_html = null;
$user_id = null;
$username = null;
$conn = null;
$user_db = null;
$api_key = null;

$av_settings = [
    'enabled' => 0,
    'closed_image' => null,
    'open_image' => null,
    'closed_blink_image' => null,
    'open_blink_image' => null,
    'blink_image' => null,
    'position' => 'bottom-right',
    'pos_x' => 0,
    'pos_y' => 0,
    'scale' => 1.00,
    'flip' => 0,
    'blink_enabled' => 1,
    'blink_interval_min' => 3,
    'blink_interval_max' => 6,
    'bounce_enabled' => 1,
    'bounce_intensity' => 5,
    'active_expression' => 'default',
];

include '/var/www/config/database.php';

$primary_db_name = 'website';
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
if ($conn->connect_error) {
    ob_end_clean();
    die('Connection to primary database failed: ' . $conn->connect_error);
}

if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = $_GET['code'];
    $stmt = $conn->prepare('SELECT id, username FROM users WHERE api_key = ?');
    $stmt->bind_param('s', $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if ($user) {
        $user_id = $user['id'];
        $username = $user['username'];
    } else {
        $error_html = "Invalid API key.<br>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.";
    }
    $stmt->close();
} else {
    $error_html = "<p>Please provide your API key in the URL like this: <strong>avatar.php?code=API_KEY</strong></p>"
        . "<p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>";
}

if (!$error_html) {
    $secondary_db_name = $username;
    $user_db = new mysqli($db_servername, $db_username, $db_password, $secondary_db_name);
    if ($user_db->connect_error) {
        $error_html = 'Connection to user database failed: ' . htmlspecialchars($user_db->connect_error);
    } else {
        $table_exists = $user_db->query("SHOW TABLES LIKE 'avatar_settings'")->num_rows > 0;
        if ($table_exists) {
            $settingsStmt = $user_db->prepare(
                'SELECT enabled, closed_image, open_image, closed_blink_image, open_blink_image, position, pos_x, pos_y, scale, flip, '
                . 'blink_enabled, blink_interval_min, blink_interval_max, bounce_enabled, bounce_intensity, active_expression '
                . 'FROM avatar_settings WHERE id = 1'
            );
            if (!$settingsStmt) {
                $settingsStmt = $user_db->prepare(
                    'SELECT enabled, closed_image, open_image, position, pos_x, pos_y, scale, flip, '
                    . 'blink_enabled, blink_interval_min, blink_interval_max, bounce_enabled, bounce_intensity, active_expression '
                    . 'FROM avatar_settings WHERE id = 1'
                );
            }
            if ($settingsStmt && $settingsStmt->execute()) {
                $row = $settingsStmt->get_result()->fetch_assoc();
                if ($row) {
                    $av_settings = array_merge($av_settings, $row);
                }
                $settingsStmt->close();
            }
        }
    }
}

$allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'custom'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    if ($error_html) {
        echo json_encode(['success' => false, 'error' => strip_tags($error_html)]);
        exit;
    }
    if ($_GET['action'] === 'get_avatar_settings') {
        $position = in_array($av_settings['position'], $allowedPositions, true) ? $av_settings['position'] : 'bottom-right';
        $scale = (float) $av_settings['scale'];
        if ($scale <= 0 || $scale > 5) {
            $scale = 1.0;
        }
        $mediaBase = 'https://media.botofthespecter.com/' . rawurlencode($username) . '/avatar/';
        $frameFile = function ($key) use ($av_settings) {
            if (empty($av_settings[$key])) {
                return '';
            }
            return basename((string) $av_settings[$key]);
        };
        $frameUrl = function ($key) use ($mediaBase, $frameFile) {
            $file = $frameFile($key);
            return $file !== '' ? $mediaBase . rawurlencode($file) : '';
        };
        echo json_encode([
            'success' => true,
            'data' => [
                'enabled' => (int) $av_settings['enabled'],
                'idle_open_url' => $frameUrl('closed_image'),
                'idle_blink_url' => $frameUrl('closed_blink_image'),
                'talk_open_url' => $frameUrl('open_image'),
                'talk_blink_url' => $frameUrl('open_blink_image'),
                'position' => $position,
                'pos_x' => (int) $av_settings['pos_x'],
                'pos_y' => (int) $av_settings['pos_y'],
                'scale' => $scale,
                'flip' => (int) $av_settings['flip'],
                'blink_enabled' => (int) $av_settings['blink_enabled'],
                'blink_interval_min' => max(1, (int) $av_settings['blink_interval_min']),
                'blink_interval_max' => max(1, (int) $av_settings['blink_interval_max']),
                'bounce_enabled' => (int) $av_settings['bounce_enabled'],
                'bounce_intensity' => max(0, min(10, (int) $av_settings['bounce_intensity'])),
                'expression' => (string) $av_settings['active_expression'],
            ],
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Specter Avatar</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body class="avatar-overlay-page">
    <?php if ($error_html): ?>
        <div class="avatar-overlay-page-error-screen">
            <h1>Overlay unavailable</h1>
            <p id="overlayErrorMessage"><?php echo $error_html; ?></p>
        </div>
    <?php else: ?>
        <div class="avatar-overlay-page-status" id="connectionStatus" data-state="connecting">Connecting&hellip;</div>
        <div class="avatar-overlay-page-root" id="avatarRoot" data-enabled="false" data-state="idle" data-position="bottom-right">
            <div class="avatar-overlay-page-stage" id="avatarStage">
                <img class="avatar-overlay-page-img is-visible" id="avatarFrame" alt="" decoding="async">
            </div>
        </div>
    <?php endif; ?>
    <script>
        const overlayApiKey = <?php echo json_encode($api_key ?? null); ?>;
        const overlayErrorMessage = <?php echo json_encode($error_html ?? null); ?>;
        (function () {
            if (overlayErrorMessage) return;
            if (!overlayApiKey) return;

            const root = document.getElementById('avatarRoot');
            const stage = document.getElementById('avatarStage');
            const imgFrame = document.getElementById('avatarFrame');
            const connectionStatus = document.getElementById('connectionStatus');
            if (!root || !stage || !imgFrame) return;

            const allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'custom'];
            const settings = {
                enabled: false,
                frames: { idle_open: '', idle_blink: '', talk_open: '', talk_blink: '' },
                position: 'bottom-right',
                posX: 0,
                posY: 0,
                scale: 1,
                flip: false,
                blinkEnabled: true,
                blinkMin: 3,
                blinkMax: 6,
                bounceEnabled: true,
                bounceIntensity: 5,
            };
            let mouthState = 'idle';
            let blinkTimer = null;
            let isBlinking = false;

            const pickFrameUrl = () => {
                const talking = mouthState === 'talking' || mouthState === 'loud';
                const f = settings.frames;
                if (talking) {
                    if (isBlinking && f.talk_blink) return f.talk_blink;
                    return f.talk_open || f.talk_blink || '';
                }
                if (isBlinking && f.idle_blink) return f.idle_blink;
                return f.idle_open || f.idle_blink || '';
            };

            const setConnectionStatus = (text, state) => {
                if (!connectionStatus) return;
                connectionStatus.textContent = text;
                connectionStatus.dataset.state = state;
            };

            const applyPlacement = () => {
                const scale = Math.max(0.1, Math.min(5, Number(settings.scale) || 1));
                const flip = settings.flip ? -1 : 1;
                root.style.transform = 'scale(' + scale + ') scaleX(' + flip + ')';
                root.dataset.position = allowedPositions.includes(settings.position) ? settings.position : 'bottom-right';
                if (settings.position === 'custom') {
                    root.style.setProperty('--av-pos-x', (Number(settings.posX) || 0) + 'px');
                    root.style.setProperty('--av-pos-y', (Number(settings.posY) || 0) + 'px');
                }
                const bounce = settings.bounceEnabled ? Math.max(0, Math.min(10, Number(settings.bounceIntensity) || 0)) : 0;
                root.style.setProperty('--av-bounce-px', (bounce * 0.35) + 'px');
                root.style.setProperty('--av-bounce-rot', (bounce * 0.35) + 'deg');
                root.dataset.bounce = bounce > 0 ? 'on' : 'off';
            };

            const preload = (url) => {
                if (!url) return Promise.resolve();
                return new Promise((resolve) => {
                    const img = new Image();
                    img.onload = () => resolve();
                    img.onerror = () => resolve();
                    img.src = url;
                });
            };

            const hasAnyFrame = () => Object.values(settings.frames).some((u) => !!u);

            const applyImages = async () => {
                const urls = Object.values(settings.frames).filter(Boolean);
                await Promise.all(urls.map(preload));
                root.dataset.enabled = settings.enabled && hasAnyFrame() ? 'true' : 'false';
                renderFrame();
            };

            const renderFrame = () => {
                const url = pickFrameUrl();
                const talking = mouthState === 'talking' || mouthState === 'loud';
                root.dataset.state = talking ? 'talking' : 'idle';
                if (!url) {
                    imgFrame.removeAttribute('src');
                    imgFrame.classList.remove('is-visible');
                    return;
                }
                if (imgFrame.getAttribute('src') !== url) {
                    imgFrame.src = url;
                }
                imgFrame.classList.add('is-visible');
            };

            const scheduleBlink = () => {
                if (blinkTimer) clearTimeout(blinkTimer);
                if (!settings.blinkEnabled || !settings.enabled) return;
                if (!settings.frames.idle_blink && !settings.frames.talk_blink) return;
                const min = Math.max(1, Number(settings.blinkMin) || 3);
                const max = Math.max(min, Number(settings.blinkMax) || min);
                const delay = (min + Math.random() * (max - min)) * 1000;
                blinkTimer = setTimeout(() => {
                    if (!settings.enabled) return;
                    isBlinking = true;
                    renderFrame();
                    setTimeout(() => {
                        isBlinking = false;
                        renderFrame();
                        scheduleBlink();
                    }, 120);
                }, delay);
            };

            const applySettings = async () => {
                applyPlacement();
                await applyImages();
                scheduleBlink();
            };

            const handleAvatarState = (payload) => {
                if (!payload) return;
                const state = String(payload.state || 'idle');
                if (state !== 'talking' && state !== 'idle' && state !== 'loud') return;
                if (mouthState === state && !isBlinking) return;
                mouthState = state;
                if (!settings.enabled) return;
                renderFrame();
            };

            const settingsEndpoint = window.location.pathname + '?code=' + encodeURIComponent(overlayApiKey) + '&action=get_avatar_settings';
            const loadSettings = async () => {
                try {
                    const response = await fetch(settingsEndpoint, { cache: 'no-store' });
                    const data = await response.json();
                    if (!data.success || !data.data) return;
                    const d = data.data;
                    settings.enabled = Number(d.enabled) !== 0;
                    settings.frames = {
                        idle_open: d.idle_open_url || '',
                        idle_blink: d.idle_blink_url || '',
                        talk_open: d.talk_open_url || '',
                        talk_blink: d.talk_blink_url || '',
                    };
                    settings.position = allowedPositions.includes(d.position) ? d.position : 'bottom-right';
                    settings.posX = Number(d.pos_x) || 0;
                    settings.posY = Number(d.pos_y) || 0;
                    settings.scale = Number(d.scale) > 0 ? Number(d.scale) : 1;
                    settings.flip = Number(d.flip) === 1;
                    settings.blinkEnabled = Number(d.blink_enabled) !== 0;
                    settings.blinkMin = Number(d.blink_interval_min) || 3;
                    settings.blinkMax = Number(d.blink_interval_max) || 6;
                    settings.bounceEnabled = Number(d.bounce_enabled) !== 0;
                    settings.bounceIntensity = Number(d.bounce_intensity) || 0;
                    await applySettings();
                } catch (e) {
                    console.error('[Avatar Overlay] Unable to load settings:', e);
                }
            };

            let socket = null;
            let reconnectAttempts = 0;
            const scheduleReconnect = () => {
                reconnectAttempts += 1;
                const delay = Math.min(5000 * reconnectAttempts, 30000);
                setConnectionStatus('Reconnecting…', 'connecting');
                if (socket) {
                    socket.removeAllListeners();
                    socket = null;
                }
                setTimeout(connect, delay);
            };

            function connect() {
                setConnectionStatus('Connecting…', 'connecting');
                socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
                socket.on('connect', () => {
                    reconnectAttempts = 0;
                    setConnectionStatus('Connected', 'connected');
                    socket.emit('REGISTER', { code: overlayApiKey, channel: 'Overlay', name: 'Avatar' });
                    loadSettings();
                });
                socket.on('disconnect', () => {
                    setConnectionStatus('Disconnected', 'error');
                    scheduleReconnect();
                });
                socket.on('connect_error', () => {
                    setConnectionStatus('Connection error', 'error');
                    scheduleReconnect();
                });
                socket.on('AVATAR_STATE', handleAvatarState);
                socket.on('AVATAR_SETTINGS_UPDATE', () => loadSettings());
            }

            loadSettings();
            connect();
        })();
    </script>
</body>
</html>