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
                'SELECT enabled, closed_image, open_image, blink_image, position, pos_x, pos_y, scale, flip, '
                . 'blink_enabled, blink_interval_min, blink_interval_max, bounce_enabled, bounce_intensity, active_expression '
                . 'FROM avatar_settings WHERE id = 1'
            );
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
        $closedFile = $av_settings['closed_image'] ? basename((string) $av_settings['closed_image']) : '';
        $openFile = $av_settings['open_image'] ? basename((string) $av_settings['open_image']) : '';
        echo json_encode([
            'success' => true,
            'data' => [
                'enabled' => (int) $av_settings['enabled'],
                'closed_url' => $closedFile !== '' ? $mediaBase . rawurlencode($closedFile) : '',
                'open_url' => $openFile !== '' ? $mediaBase . rawurlencode($openFile) : '',
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
                <img class="avatar-overlay-page-img avatar-overlay-page-img--closed" id="avatarClosed" alt="" decoding="async">
                <img class="avatar-overlay-page-img avatar-overlay-page-img--open" id="avatarOpen" alt="" decoding="async">
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
            const imgClosed = document.getElementById('avatarClosed');
            const imgOpen = document.getElementById('avatarOpen');
            const connectionStatus = document.getElementById('connectionStatus');
            if (!root || !stage || !imgClosed || !imgOpen) return;

            const allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'custom'];
            const settings = {
                enabled: false,
                closedUrl: '',
                openUrl: '',
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

            const applyImages = async () => {
                await Promise.all([preload(settings.closedUrl), preload(settings.openUrl)]);
                if (settings.closedUrl) imgClosed.src = settings.closedUrl;
                if (settings.openUrl) imgOpen.src = settings.openUrl;
                root.dataset.enabled = settings.enabled && (settings.closedUrl || settings.openUrl) ? 'true' : 'false';
            };

            const renderMouth = () => {
                const talking = mouthState === 'talking' || mouthState === 'loud';
                imgClosed.classList.toggle('is-visible', !talking);
                imgOpen.classList.toggle('is-visible', talking);
                root.dataset.state = talking ? 'talking' : 'idle';
            };

            const scheduleBlink = () => {
                if (blinkTimer) clearTimeout(blinkTimer);
                if (!settings.blinkEnabled || !settings.enabled) return;
                const min = Math.max(1, Number(settings.blinkMin) || 3);
                const max = Math.max(min, Number(settings.blinkMax) || min);
                const delay = (min + Math.random() * (max - min)) * 1000;
                blinkTimer = setTimeout(() => {
                    if (!settings.enabled) return;
                    isBlinking = true;
                    stage.classList.add('is-blinking');
                    setTimeout(() => {
                        isBlinking = false;
                        stage.classList.remove('is-blinking');
                        scheduleBlink();
                    }, 120);
                }, delay);
            };

            const applySettings = async () => {
                applyPlacement();
                await applyImages();
                renderMouth();
                scheduleBlink();
            };

            const handleAvatarState = (payload) => {
                if (!payload || !settings.enabled) return;
                const state = String(payload.state || 'idle');
                if (state !== 'talking' && state !== 'idle' && state !== 'loud') return;
                if (mouthState === state) return;
                mouthState = state;
                renderMouth();
            };

            const settingsEndpoint = window.location.pathname + '?code=' + encodeURIComponent(overlayApiKey) + '&action=get_avatar_settings';
            const loadSettings = async () => {
                try {
                    const response = await fetch(settingsEndpoint, { cache: 'no-store' });
                    const data = await response.json();
                    if (!data.success || !data.data) return;
                    const d = data.data;
                    settings.enabled = Number(d.enabled) !== 0;
                    settings.closedUrl = d.closed_url || '';
                    settings.openUrl = d.open_url || '';
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