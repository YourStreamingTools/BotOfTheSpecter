<?php
ob_start();

$error_html = null;
$user_id = null;
$username = null;
$conn = null;
$user_db = null;
$api_key = null;

// Defaults mirror the closed_captions_settings table defaults.
$cc_settings = [
    'enabled' => 1,
    'language' => 'en-US',
    'font_size' => 32,
    'text_color' => '#FFFFFF',
    'background_style' => 'box',
    'position' => 'bottom',
    'max_lines' => 2,
    'fade_seconds' => 5,
    'profanity_filter' => 0,
    'font_family' => 'Inter',
];
// Caption typeface - curated Google Fonts (MUST match the dashboard's allowed list). 'Inter' is the default.
$allowedFonts = ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Oswald', 'Raleway', 'Ubuntu', 'Nunito'];

include '/var/www/config/database.php';

// Connect to primary database
$primary_db_name = "website";
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
if ($conn->connect_error) {
    ob_end_clean();
    die("Connection to primary database failed: " . $conn->connect_error);
}

// Validate API key and get user info
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = $_GET['code'];
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE api_key = ?");
    $stmt->bind_param("s", $api_key);
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
    $error_html = "<p>Please provide your API key in the URL like this: <strong>closed-captions.php?code=API_KEY</strong></p>"
        . "<p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>";
}

// Connect to secondary (user) database and load settings
if (!$error_html) {
    $secondary_db_name = $username;
    $user_db = new mysqli($db_servername, $db_username, $db_password, $secondary_db_name);
    if ($user_db->connect_error) {
        $error_html = "Connection to user database failed: " . htmlspecialchars($user_db->connect_error);
    } else {
        $table_exists = $user_db->query("SHOW TABLES LIKE 'closed_captions_settings'")->num_rows > 0;
        if ($table_exists) {
            $settingsStmt = $user_db->prepare("SELECT enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter, font_family FROM closed_captions_settings WHERE id = 1");
            if (!$settingsStmt) {
                // font_family column may not exist yet (the dashboard adds it via the schema
                // migration). Fall back to the other settings so the overlay never blanks them.
                $settingsStmt = $user_db->prepare("SELECT enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter FROM closed_captions_settings WHERE id = 1");
            }
            if ($settingsStmt && $settingsStmt->execute()) {
                $row = $settingsStmt->get_result()->fetch_assoc();
                if ($row) {
                    $cc_settings = array_merge($cc_settings, $row);
                }
                $settingsStmt->close();
            }
        }
    }
}

// JSON settings endpoint (fetched by the overlay JS on load).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    if ($error_html) {
        echo json_encode(['success' => false, 'error' => strip_tags($error_html)]);
        exit;
    }
    if ($_GET['action'] === 'get_closed_caption_settings') {
        $allowedPositions = ['top', 'center', 'bottom'];
        $allowedBackgrounds = ['box', 'outline', 'none'];
        $position = in_array($cc_settings['position'], $allowedPositions, true) ? $cc_settings['position'] : 'bottom';
        $background = in_array($cc_settings['background_style'], $allowedBackgrounds, true) ? $cc_settings['background_style'] : 'box';
        $textColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $cc_settings['text_color']) ? $cc_settings['text_color'] : '#FFFFFF';
        $fontFamily = in_array($cc_settings['font_family'], $allowedFonts, true) ? $cc_settings['font_family'] : 'Inter';
        echo json_encode([
            'success' => true,
            'data' => [
                'enabled' => (int) $cc_settings['enabled'],
                'language' => (string) $cc_settings['language'],
                'font_size' => (int) $cc_settings['font_size'],
                'text_color' => $textColor,
                'background_style' => $background,
                'position' => $position,
                'max_lines' => max(1, (int) $cc_settings['max_lines']),
                'fade_seconds' => max(0, (int) $cc_settings['fade_seconds']),
                'profanity_filter' => (int) $cc_settings['profanity_filter'],
                'font_family' => $fontFamily,
            ]
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
    <title>Specter Closed Captions</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php $headFont = in_array($cc_settings['font_family'], $allowedFonts, true) ? $cc_settings['font_family'] : 'Inter'; ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?php echo urlencode($headFont); ?>:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body class="closed-captions-overlay-page">
    <?php if ($error_html): ?>
        <div class="closed-captions-overlay-page-error-screen">
            <h1>Overlay unavailable</h1>
            <p id="overlayErrorMessage"><?php echo $error_html; ?></p>
        </div>
    <?php else: ?>
        <div class="closed-captions-overlay-page-status" id="connectionStatus" data-state="connecting">Connecting&hellip;</div>
        <div class="closed-captions-overlay-page-root" id="ccRoot" data-position="bottom" data-background="box">
            <div class="closed-captions-overlay-page-band" id="ccBand">
                <div class="closed-captions-overlay-page-content" id="ccContent">
                    <div class="closed-captions-overlay-page-lines" id="ccLines"></div>
                    <div class="closed-captions-overlay-page-interim" id="ccInterim"></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script>
        const overlayApiKey = <?php echo json_encode($api_key ?? null); ?>;
        const overlayErrorMessage = <?php echo json_encode($error_html ?? null); ?>;
        const overlayInitialFont = <?php echo json_encode(in_array($cc_settings['font_family'], $allowedFonts, true) ? $cc_settings['font_family'] : 'Inter'); ?>;

        function showOverlayError(message, type) {
            let banner = document.getElementById('overlayErrorBanner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'overlayErrorBanner';
                document.body.appendChild(banner);
            }
            banner.textContent = message;
            banner.className = 'overlay-error-banner ' + (type === 'warn' ? 'overlay-error-banner-warn' : 'overlay-error-banner-danger');
            banner.style.display = 'block';
        }

        (function () {
            if (overlayErrorMessage) {
                showOverlayError(overlayErrorMessage.replace(/<[^>]*>/g, ''), 'danger');
                return;
            }
            if (!overlayApiKey) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }
            const ccRoot = document.getElementById('ccRoot');
            const ccBand = document.getElementById('ccBand');
            const ccLines = document.getElementById('ccLines');
            const ccInterim = document.getElementById('ccInterim');
            const connectionStatus = document.getElementById('connectionStatus');
            if (!ccRoot || !ccBand || !ccLines || !ccInterim) {
                return;
            }
            // Settings
            const allowedPositions = ['top', 'center', 'bottom'];
            const allowedBackgrounds = ['box', 'outline', 'none'];
            // Curated caption fonts - MUST match the dashboard + overlay PHP allowed list.
            const allowedFonts = ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Oswald', 'Raleway', 'Ubuntu', 'Nunito'];
            // The initial font is already loaded by the server-rendered <head> link; track it so
            // we don't inject a duplicate, and lazily load any other font picked at runtime.
            const loadedFonts = new Set([overlayInitialFont]);
            const ensureFontLoaded = (fontName) => {
                if (!allowedFonts.includes(fontName) || loadedFonts.has(fontName)) {
                    return;
                }
                loadedFonts.add(fontName);
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://fonts.googleapis.com/css2?family=' + fontName.replace(/ /g, '+') + ':wght@400;500;600;700&display=swap';
                document.head.appendChild(link);
            };
            const settings = {
                enabled: true,
                fontSize: 32,
                textColor: '#FFFFFF',
                background: 'box',
                position: 'bottom',
                maxLines: 2,
                fadeSeconds: 5,
                fontFamily: overlayInitialFont
            };
            const applySettings = () => {
                ccRoot.style.setProperty('--cc-font-size', (Number(settings.fontSize) || 32) + 'px');
                ccRoot.style.setProperty('--cc-max-lines', String(Math.max(1, Number(settings.maxLines) || 2)));
                ccRoot.style.setProperty('--cc-text-color', settings.textColor || '#FFFFFF');
                // Caption typeface: load the chosen Google Font on demand, then apply it.
                const fontName = allowedFonts.includes(settings.fontFamily) ? settings.fontFamily : 'Inter';
                ensureFontLoaded(fontName);
                const fontStack = '"' + fontName + '", "Segoe UI", system-ui, sans-serif';
                ccRoot.style.setProperty('--cc-font-family', '"' + fontName + '"');
                ccBand.style.fontFamily = fontStack;
                ccRoot.dataset.position = allowedPositions.includes(settings.position) ? settings.position : 'bottom';
                ccRoot.dataset.background = allowedBackgrounds.includes(settings.background) ? settings.background : 'box';
                ccRoot.dataset.enabled = settings.enabled ? 'true' : 'false';
            };
            const settingsEndpoint = `${window.location.pathname}?code=${encodeURIComponent(overlayApiKey)}&action=get_closed_caption_settings`;
            const loadSettings = async () => {
                try {
                    const response = await fetch(settingsEndpoint, { cache: 'no-store' });
                    const data = await response.json();
                    if (data.success && data.data) {
                        const d = data.data;
                        settings.enabled = Number(d.enabled) !== 0;
                        settings.fontSize = Number(d.font_size) > 0 ? Number(d.font_size) : 32;
                        settings.textColor = /^#[0-9A-Fa-f]{6}$/.test(String(d.text_color || '')) ? d.text_color : '#FFFFFF';
                        settings.background = allowedBackgrounds.includes(d.background_style) ? d.background_style : 'box';
                        settings.position = allowedPositions.includes(d.position) ? d.position : 'bottom';
                        settings.maxLines = Number(d.max_lines) >= 1 ? Math.round(Number(d.max_lines)) : 2;
                        settings.fadeSeconds = Number(d.fade_seconds) >= 0 ? Math.round(Number(d.fade_seconds)) : 5;
                        settings.fontFamily = allowedFonts.includes(d.font_family) ? d.font_family : 'Inter';
                        applySettings();
                    }
                } catch (error) {
                    console.error('[CC Overlay] Unable to load settings:', error);
                }
            };
            // Caption rendering
            const committedLines = [];
            const committedActions = []; // parallel to committedLines: true => bracketed action tag
            let interimText = '';
            let fadeTimer = null;
            // A caption is an action tag when the emitter flags it, or (fallback) the
            // text is a single bracketed token like [LAUGHING].
            const isActionCaption = (payload, text) =>
                (payload && payload.action === true) || /^\[.+\]$/.test(String(text || '').trim());
            const escapeHtml = text => {
                const div = document.createElement('div');
                div.textContent = text == null ? '' : String(text);
                return div.innerHTML;
            };
            const scheduleFade = () => {
                if (fadeTimer) {
                    clearTimeout(fadeTimer);
                    fadeTimer = null;
                }
                const seconds = Number(settings.fadeSeconds);
                if (!Number.isFinite(seconds) || seconds <= 0) {
                    return;
                }
                fadeTimer = setTimeout(() => {
                    ccBand.classList.add('is-faded');
                }, seconds * 1000);
            };
            const wake = () => {
                ccBand.classList.remove('is-faded');
                scheduleFade();
            };
            // Render committed lines + the interim line so the TOTAL never exceeds maxLines
            // (the interim counts as one of the visible lines).
            const renderBand = () => {
                const max = Math.max(1, settings.maxLines);
                const hasInterim = interimText.length > 0;
                const committedSlots = hasInterim ? max - 1 : max;
                const startIdx = committedSlots > 0 ? Math.max(0, committedLines.length - committedSlots) : committedLines.length;
                const visible = committedLines.slice(startIdx);
                ccLines.innerHTML = visible.map((line, i) => {
                    const isAction = committedActions[startIdx + i] === true;
                    const cls = isAction
                        ? 'closed-captions-overlay-page-line closed-captions-overlay-page-line--action'
                        : 'closed-captions-overlay-page-line';
                    return `<div class="${cls}">${escapeHtml(line)}</div>`;
                }).join('');
                if (hasInterim) {
                    ccInterim.textContent = interimText;
                    ccInterim.classList.add('is-active');
                } else {
                    ccInterim.textContent = '';
                    ccInterim.classList.remove('is-active');
                }
            };
            const blankBand = () => {
                committedLines.length = 0;
                committedActions.length = 0;
                interimText = '';
                renderBand();
                if (fadeTimer) {
                    clearTimeout(fadeTimer);
                    fadeTimer = null;
                }
                ccBand.classList.add('is-faded');
            };
            const commitText = (text, isAction) => {
                const clean = String(text || '').trim();
                if (!clean) {
                    return;
                }
                committedLines.push(clean);
                committedActions.push(isAction === true);
                while (committedLines.length > Math.max(1, settings.maxLines)) {
                    committedLines.shift();
                    committedActions.shift();
                }
                interimText = '';
                renderBand();
                wake();
            };
            const showInterim = text => {
                interimText = String(text || '').trim();
                renderBand();
                if (interimText) {
                    wake();
                }
            };
            const handleCaption = payload => {
                if (!payload || settings.enabled === false) {
                    return;
                }
                const text = payload.text != null ? String(payload.text) : '';
                const isFinal = payload.isFinal === true || payload.isFinal === 1 || payload.is_final === true;
                const isAction = isActionCaption(payload, text);
                // If the band has faded out (a pause since the last caption), drop the previous
                // sentence first so it doesn't flash back when the user resumes speaking.
                if (ccBand.classList.contains('is-faded')) {
                    committedLines.length = 0;
                    committedActions.length = 0;
                    interimText = '';
                }
                // Action tags are always committed as their own caption line (they never
                // arrive as interim text), flagged so renderBand styles them distinctly.
                if (isFinal || isAction) {
                    commitText(text, isAction);
                } else {
                    showInterim(text);
                }
            };
            // WebSocket
            let socket = null;
            let reconnectAttempts = 0;
            const setConnectionStatus = (text, state) => {
                if (!connectionStatus) return;
                connectionStatus.textContent = text;
                connectionStatus.dataset.state = state;
            };
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
                    socket.emit('REGISTER', {
                        code: overlayApiKey,
                        channel: 'Overlay',
                        name: 'Closed Captions'
                    });
                    loadSettings();
                });
                socket.on('disconnect', reason => {
                    console.warn('[CC Overlay] Disconnected:', reason);
                    setConnectionStatus('Disconnected', 'error');
                    scheduleReconnect();
                });
                socket.on('connect_error', error => {
                    console.error('[CC Overlay] WebSocket error:', error);
                    setConnectionStatus('Connection error', 'error');
                    scheduleReconnect();
                });
                socket.on('CLOSED_CAPTION', payload => {
                    handleCaption(payload);
                });
                socket.on('CLOSED_CAPTION_CLEAR', () => {
                    blankBand();
                });
                socket.on('CLOSED_CAPTION_SETTINGS', () => {
                    // Dashboard saved new appearance settings - reload them live (font size,
                    // colour, position, background) without an OBS browser-source refresh.
                    loadSettings();
                });
                // Dashboard "Refresh Overlay" - full page reload so PHP re-fetches settings.
                socket.on('OVERLAY_REFRESH', (data) => {
                    console.log('OVERLAY_REFRESH received - reloading', data);
                    const meta = document.createElement('meta');
                    meta.setAttribute('http-equiv', 'refresh');
                    meta.setAttribute('content', '0');
                    document.head.appendChild(meta);
                });
            }
            applySettings();
            blankBand();
            loadSettings();
            connect();
        })();
    </script>
</body>
</html>