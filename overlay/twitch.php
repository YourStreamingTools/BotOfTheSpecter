<?php
include '/var/www/config/database.php';
$primary_db_name = 'website';

$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'] ?? '';

$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param("s", $api_key);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'] ?? '';

$alertConfigs = [];
$mediaMigrated = false;
if ($username) {
    $db = new PDO("mysql:host=$db_servername;dbname=$username", $db_username, $db_password);

    // Fetch alert settings
    $stmt = $db->prepare("SELECT * FROM twitch_alerts ORDER BY alert_category, variant_index");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $alertConfigs[$row['alert_category']][] = $row;
    }

    // Check media migration status
    $stmt = $db->prepare("SELECT media_migrated FROM profile LIMIT 1");
    $stmt->execute();
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    $mediaMigrated = !empty($profile['media_migrated']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Twitch Alerts Overlay - BotOfTheSpecter</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            const alertConfigs = <?php echo json_encode($alertConfigs); ?>;
            const username = <?php echo json_encode($username); ?>;
            const mediaMigrated = <?php echo json_encode($mediaMigrated); ?>;
            const mediaBase = mediaMigrated
                ? 'https://media.botofthespecter.com/' + username + '/'
                : 'https://soundalerts.botofthespecter.com/' + username + '/';

            const alertQueue = [];
            let isShowingAlert = false;
            let currentAudio = null;
            const loadedFonts = {};

            function loadGoogleFont(fontName) {
                if (loadedFonts[fontName]) return;
                loadedFonts[fontName] = true;
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontName) + ':wght@300;400;500;600;700;800&display=swap';
                document.head.appendChild(link);
            }

            function getMatchingVariant(category, eventData) {
                const variants = alertConfigs[category];
                if (!variants || variants.length === 0) return null;

                // Filter enabled variants
                const enabled = variants.filter(v => v.enabled == 1);
                if (enabled.length === 0) return null;

                // For categories with conditions, find best match (highest index first = most specific)
                const sorted = enabled.slice().sort((a, b) => b.variant_index - a.variant_index);

                for (const variant of sorted) {
                    const cond = variant.alert_condition;
                    if (!cond) return variant; // No condition = matches all

                    // Parse simple conditions
                    if (category === 'bits') {
                        const amount = parseInt(eventData.amount) || 0;
                        if (cond === 'is_first = 1' && eventData.is_first) return variant;
                        const bitsMatch = cond.match(/bits\s*>=\s*(\d+)/);
                        if (bitsMatch && amount >= parseInt(bitsMatch[1])) return variant;
                    } else if (category === 'subscription') {
                        const months = parseInt(eventData.months) || 1;
                        if (cond === 'months = 1' && months === 1) return variant;
                        const monthsMatch = cond.match(/months\s*>=\s*(\d+)/);
                        if (monthsMatch && months >= parseInt(monthsMatch[1])) return variant;
                    } else if (category === 'gift_subscription') {
                        const giftCount = parseInt(eventData.amount) || 1;
                        const giftMatch = cond.match(/gift_count\s*>=\s*(\d+)/);
                        if (giftMatch && giftCount >= parseInt(giftMatch[1])) return variant;
                        if (!giftMatch) return variant;
                    } else if (category === 'raid') {
                        const viewers = parseInt(eventData.viewers) || 0;
                        const viewerMatch = cond.match(/viewers\s*>=\s*(\d+)/);
                        if (viewerMatch && viewers >= parseInt(viewerMatch[1])) return variant;
                    } else {
                        return variant; // Unknown condition type, use variant
                    }
                }

                // Fallback to first enabled variant
                return enabled[0];
            }

            function queueAlert(category, eventData) {
                const config = getMatchingVariant(category, eventData);
                if (!config) return;
                alertQueue.push({ config, eventData });
                if (!isShowingAlert) processAlertQueue();
            }

            function processAlertQueue() {
                if (alertQueue.length === 0) {
                    isShowingAlert = false;
                    return;
                }
                isShowingAlert = true;
                const { config, eventData } = alertQueue.shift();
                renderAlert(config, eventData);
            }

            function renderAlert(config, eventData) {
                const container = document.getElementById('alertContainer');
                const weightMap = {'Light':'300','Regular':'400','Medium':'500','Semi-Bold':'600','Bold':'700','Extra-Bold':'800'};
                const cssWeight = weightMap[config.font_weight] || '600';

                // Load font
                if (config.font_family) loadGoogleFont(config.font_family);

                // Parse background color
                const bgColor = config.bg_color || '#000000';
                const bgOpacity = (config.bg_opacity || 0) / 100;
                const r = parseInt(bgColor.substr(1,2), 16);
                const g = parseInt(bgColor.substr(3,2), 16);
                const b = parseInt(bgColor.substr(5,2), 16);
                const bgRgba = `rgba(${r},${g},${b},${bgOpacity})`;

                // Process message template
                let message = (config.message_template || '').replace(/\\n/g, '\n');
                message = message.replace(/\{username\}/g, eventData.username || '')
                    .replace(/\{amount\}/g, eventData.amount || '')
                    .replace(/\{months\}/g, eventData.months || '')
                    .replace(/\{viewers\}/g, eventData.viewers || '')
                    .replace(/\{tier\}/g, eventData.tier || '');

                // Split message into lines, first line uses accent color
                const lines = message.split('\n');
                let messageHtml = '';
                if (lines.length > 1) {
                    messageHtml = `<span class="twitch-alert-accent">${escapeHtml(lines[0])}</span><br>${escapeHtml(lines.slice(1).join('\n')).replace(/\n/g, '<br>')}`;
                } else {
                    messageHtml = escapeHtml(message).replace(/\n/g, '<br>');
                }

                // Build layout
                const layout = config.layout_preset || 'above';
                const imageScale = (config.image_scale || 100) / 100;

                let imageHtml = '';
                if (config.alert_image) {
                    const imgUrl = mediaBase + config.alert_image;
                    const ext = config.alert_image.split('.').pop().toLowerCase();
                    if (ext === 'webm') {
                        imageHtml = `<video class="twitch-alert-image" src="${imgUrl}" autoplay loop muted style="transform:scale(${imageScale})"></video>`;
                    } else {
                        imageHtml = `<img class="twitch-alert-image" src="${imgUrl}" alt="" style="transform:scale(${imageScale})">`;
                    }
                }

                const boxStyles = [
                    `background:${bgRgba}`,
                    `padding:${config.padding || 16}px`,
                    `gap:${config.gap || 16}px`,
                    `border-radius:${config.rounded_corners == 1 ? '12px' : '0'}`,
                    `box-shadow:${config.drop_shadow == 1 ? '0 4px 20px rgba(0,0,0,0.5)' : 'none'}`
                ].join(';');

                const textStyles = [
                    `font-family:"${config.font_family || 'Roboto'}",sans-serif`,
                    `font-weight:${cssWeight}`,
                    `font-size:${config.font_size || 24}px`,
                    `color:${config.text_color || '#FFFFFF'}`,
                    `text-align:${config.text_alignment || 'center'}`,
                    `text-shadow:${config.text_drop_shadow == 1 ? '0 2px 4px rgba(0,0,0,0.8)' : 'none'}`
                ].join(';');

                container.innerHTML = `
                    <div class="twitch-alert-box layout-${layout}" style="${boxStyles}">
                        ${imageHtml}
                        <div class="twitch-alert-text" style="${textStyles}">${messageHtml}</div>
                    </div>
                `;

                // Apply accent color
                const accentEls = container.querySelectorAll('.twitch-alert-accent');
                accentEls.forEach(el => el.style.color = config.accent_color || '#A1C53A');

                // Play alert sound
                if (config.alert_sound) {
                    const soundUrl = mediaBase + config.alert_sound;
                    currentAudio = new Audio(soundUrl + '?t=' + Date.now());
                    currentAudio.volume = (config.sound_volume || 50) / 100;
                    currentAudio.play().catch(e => console.error('Audio play error:', e));
                }

                // Animate in
                const animInDur = parseFloat(config.animation_in_duration) || 1;
                const animOutDur = parseFloat(config.animation_out_duration) || 1;
                const duration = (parseInt(config.duration) || 8) * 1000;

                container.style.animation = 'none';
                container.offsetHeight; // Trigger reflow
                container.classList.add('show');
                container.style.animation = `${config.animation_in || 'fadeIn'} ${animInDur}s forwards`;

                setTimeout(() => {
                    container.style.animation = `${config.animation_out || 'fadeOut'} ${animOutDur}s forwards`;
                    setTimeout(() => {
                        container.classList.remove('show');
                        container.style.animation = '';
                        if (currentAudio) {
                            currentAudio.pause();
                            currentAudio = null;
                        }
                        processAlertQueue();
                    }, animOutDur * 1000);
                }, duration);
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Twitch Alerts' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    attemptReconnect();
                });

                socket.on('WELCOME', (data) => {
                    console.log('Server says:', data.message);
                });

                // Twitch events
                socket.on('TWITCH_FOLLOW', (data) => {
                    console.log('TWITCH_FOLLOW event received:', data);
                    queueAlert('follow', { username: data['twitch-username'] });
                });

                socket.on('TWITCH_CHEER', (data) => {
                    console.log('TWITCH_CHEER event received:', data);
                    queueAlert('bits', {
                        username: data['twitch-username'],
                        amount: data['twitch-cheer-amount'],
                        is_first: data['twitch-cheer-first'] || false
                    });
                });

                socket.on('TWITCH_SUB', (data) => {
                    console.log('TWITCH_SUB event received:', data);
                    queueAlert('subscription', {
                        username: data['twitch-username'],
                        tier: data['twitch-tier'],
                        months: data['twitch-sub-months']
                    });
                });

                socket.on('TWITCH_RAID', (data) => {
                    console.log('TWITCH_RAID event received:', data);
                    queueAlert('raid', {
                        username: data['twitch-username'],
                        viewers: data['twitch-raid']
                    });
                });

                socket.on('TWITCH_GIFT_SUB', (data) => {
                    console.log('TWITCH_GIFT_SUB event received:', data);
                    queueAlert('gift_subscription', {
                        username: data['twitch-username'],
                        amount: data['twitch-gift-count'] || 1
                    });
                });

                socket.on('TWITCH_HYPE_TRAIN', (data) => {
                    console.log('TWITCH_HYPE_TRAIN event received:', data);
                    queueAlert('hype_train', {
                        username: data['twitch-username'] || ''
                    });
                });

                socket.on('TWITCH_CHARITY', (data) => {
                    console.log('TWITCH_CHARITY event received:', data);
                    queueAlert('charity', {
                        username: data['twitch-username'] || ''
                    });
                });

                socket.on('TWITCH_CHANNEL_POINTS', (data) => {
                    console.log('TWITCH_CHANNEL_POINTS event received:', data);
                    queueAlert('channel_points', {
                        username: data['twitch-username'] || ''
                    });
                });

                // Log all events
                socket.onAny((event, ...args) => {
                    console.log(`[onAny] Event: ${event}`, ...args);
                });
            }

            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
                setTimeout(() => {
                    connectWebSocket();
                }, delay);
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="alertContainer" class="twitch-alert-container"></div>
</body>
</html>
