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
$timezone = null;
if ($username) {
    $db = new PDO("mysql:host=$db_servername;dbname=$username", $db_username, $db_password);
    $stmt = $db->prepare("SELECT * FROM twitch_alerts ORDER BY alert_category, variant_index");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $alertConfigs[$row['alert_category']][] = $row;
    }
    // Weather's clock (ported from overlay/weather.php) needs the streamer's timezone.
    $tzStmt = $db->query("SELECT timezone FROM profile LIMIT 1");
    $tzRow = $tzStmt ? $tzStmt->fetch(PDO::FETCH_ASSOC) : null;
    $timezone = $tzRow['timezone'] ?? null;
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
                if (type === 'warn') {
                    clearTimeout(banner._timeoutId);
                    banner._timeoutId = setTimeout(() => { banner.style.display = 'none'; }, 6000);
                }
            }
            if (!code) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }
            const alertConfigs = <?php echo json_encode($alertConfigs); ?>;
            const username = <?php echo json_encode($username); ?>;
            const weatherTimezone = <?php echo json_encode($timezone); ?>;
            const mediaBase = 'https://media.botofthespecter.com/' + username + '/';
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
                    } else if (category === 'hype_train') {
                        const level = parseInt(eventData.level) || 1;
                        const levelMatch = cond.match(/level\s*>=\s*(\d+)/);
                        if (levelMatch && level >= parseInt(levelMatch[1])) return variant;
                        const eqMatch = cond.match(/level\s*=\s*(\d+)/);
                        if (eqMatch && level === parseInt(eqMatch[1])) return variant;
                    } else if (category === 'charity') {
                        const donation = parseFloat(eventData.amount_value) || 0;
                        const amountMatch = cond.match(/amount\s*>=\s*(\d+(?:\.\d+)?)/);
                        if (amountMatch && donation >= parseFloat(amountMatch[1])) return variant;
                    } else if (category === 'stream_bingo') {
                        const bingoMatch = cond.match(/bingo_event\s*=\s*['"]?([^'"\s]+)['"]?/);
                        if (bingoMatch && eventData.bingo_event && bingoMatch[1] === eventData.bingo_event) return variant;
                    } else if (category === 'kofi') {
                        const typeMatch   = cond.match(/kofi_type\s*=\s*['"]([^'"]+)['"]/);
                        const amountMatch = cond.match(/amount\s*>=\s*(\d+(?:\.\d+)?)/);
                        const val = parseFloat(eventData.amount_value) || 0;
                        const typeOk   = !typeMatch   || (typeMatch[1] === eventData.kofi_type);
                        const amountOk = !amountMatch || (val >= parseFloat(amountMatch[1]));
                        if (typeOk && amountOk && (typeMatch || amountMatch)) return variant;
                    } else if (category === 'patreon') {
                        const typeMatch   = cond.match(/patreon_type\s*=\s*['"]([^'"]+)['"]/);
                        const amountMatch = cond.match(/amount\s*>=\s*(\d+(?:\.\d+)?)/);
                        const val = parseFloat(eventData.amount_value) || 0;
                        const typeOk   = !typeMatch   || (typeMatch[1] === eventData.patreon_type);
                        const amountOk = !amountMatch || (val >= parseFloat(amountMatch[1]));
                        if (typeOk && amountOk && (typeMatch || amountMatch)) return variant;
                    } else if (category === 'fourthwall') {
                        const typeMatch   = cond.match(/fourthwall_type\s*=\s*['"]([^'"]+)['"]/);
                        const amountMatch = cond.match(/amount\s*>=\s*(\d+(?:\.\d+)?)/);
                        const val = parseFloat(eventData.amount_value) || 0;
                        const typeOk   = !typeMatch   || (typeMatch[1] === eventData.fourthwall_type);
                        const amountOk = !amountMatch || (val >= parseFloat(amountMatch[1]));
                        if (typeOk && amountOk && (typeMatch || amountMatch)) return variant;
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
                applyScreenPosition(container, config, config.alert_category);
                const weightMap = {'Light':'300','Regular':'400','Medium':'500','Semi-Bold':'600','Bold':'700','Extra-Bold':'800'};
                const cssWeight = weightMap[config.font_weight] || '600';
                // Load font
                if (config.font_family) loadGoogleFont(config.font_family);
                // Parse background color - fall back to #000000 if the stored value
                // isn't a valid #RRGGBB literal (anything else produces NaN channels
                // and CSS silently drops the whole rgba()).
                const hex6 = /^#[0-9a-fA-F]{6}$/;
                const bgColor = hex6.test(config.bg_color) ? config.bg_color : '#000000';
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
                    .replace(/\{tier\}/g, eventData.tier || '')
                    .replace(/\{level\}/g, eventData.level || '')
                    .replace(/\{added_minutes\}/g, eventData.added_minutes || '')
                    .replace(/\{streak\}/g, eventData.streak || '')
                    .replace(/\{charity_name\}/g, eventData.charity_name || '')
                    .replace(/\{message\}/g, eventData.message || '')
                    .replace(/\{tier_name\}/g, eventData.tier_name || '')
                    .replace(/\{lifetime\}/g, eventData.lifetime || '')
                    .replace(/\{item\}/g, eventData.item || '')
                    .replace(/\{interval\}/g, eventData.interval || '')
                    .replace(/\{rank_text\}/g, eventData.rank_text || '')
                    .replace(/\{bingo_event_name\}/g, eventData.bingo_event_name || '')
                    .replace(/\{bingo_number\}/g, eventData.bingo_number || '')
                    .replace(/\{events_count\}/g, eventData.events_count || '');
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
                // Celebration effect - spawns over the alert's full duration
                if (config.celebration_enabled == 1 && config.celebration_effect) {
                    celebration.start(
                        config.celebration_effect,
                        config.celebration_intensity || 'medium',
                        duration
                    );
                }
                setTimeout(() => {
                    container.style.animation = `${config.animation_out || 'fadeOut'} ${animOutDur}s forwards`;
                    celebration.stop();
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
            const celebration = (function () {
                let canvas = null, ctx = null, particles = [], rafId = null;
                let spawning = false, spawnTimer = null;
                const palette = ['#7c5cbf', '#9070d8', '#fbbf24', '#3ecf8e', '#5cb8ff', '#f87171', '#ffffff'];
                const intensityScale = { light: 0.5, medium: 1, heavy: 2 };
                function ensureCanvas() {
                    if (canvas) return;
                    canvas = document.createElement('canvas');
                    canvas.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:9999;';
                    canvas.width = window.innerWidth;
                    canvas.height = window.innerHeight;
                    document.body.appendChild(canvas);
                    ctx = canvas.getContext('2d');
                    window.addEventListener('resize', onResize);
                }
                function onResize() {
                    if (!canvas) return;
                    canvas.width = window.innerWidth;
                    canvas.height = window.innerHeight;
                }
                function destroyCanvas() {
                    if (rafId) cancelAnimationFrame(rafId);
                    rafId = null;
                    if (canvas) {
                        canvas.remove();
                        window.removeEventListener('resize', onResize);
                    }
                    canvas = null; ctx = null; particles = [];
                }
                function loop() {
                    if (!ctx) return;
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    for (let i = particles.length - 1; i >= 0; i--) {
                        const p = particles[i];
                        p.update();
                        if (p.dead) particles.splice(i, 1);
                        else p.draw(ctx);
                    }
                    if (particles.length === 0 && !spawning) {
                        destroyCanvas();
                        return;
                    }
                    rafId = requestAnimationFrame(loop);
                }
                function makeFirework() {
                    const W = canvas.width, H = canvas.height;
                    const startX = W * (0.15 + Math.random() * 0.7);
                    const targetY = H * (0.15 + Math.random() * 0.35);
                    const color = palette[Math.floor(Math.random() * palette.length)];
                    return {
                        x: startX, y: H + 10,
                        vy: -(Math.sqrt(2 * 0.18 * (H - targetY))),
                        vx: (Math.random() - 0.5) * 0.6,
                        gravity: 0.18,
                        exploded: false,
                        color: color,
                        dead: false,
                        update() {
                            if (!this.exploded) {
                                this.x += this.vx;
                                this.y += this.vy;
                                this.vy += this.gravity;
                                if (this.vy >= 0) {
                                    this.exploded = true;
                                    const burst = 28 + Math.floor(Math.random() * 14);
                                    for (let i = 0; i < burst; i++) {
                                        const a = (i / burst) * Math.PI * 2;
                                        const speed = 2 + Math.random() * 3;
                                        particles.push(makeSpark(this.x, this.y, Math.cos(a) * speed, Math.sin(a) * speed, this.color));
                                    }
                                    this.dead = true;
                                }
                            }
                        },
                        draw(c) {
                            c.fillStyle = this.color;
                            c.beginPath(); c.arc(this.x, this.y, 2.5, 0, Math.PI * 2); c.fill();
                        }
                    };
                }
                function makeSpark(x, y, vx, vy, color) {
                    return {
                        x, y, vx, vy, color,
                        life: 1, gravity: 0.06, drag: 0.985,
                        dead: false,
                        update() {
                            this.x += this.vx; this.y += this.vy;
                            this.vy += this.gravity;
                            this.vx *= this.drag; this.vy *= this.drag;
                            this.life -= 0.012;
                            if (this.life <= 0) this.dead = true;
                        },
                        draw(c) {
                            c.globalAlpha = Math.max(0, this.life);
                            c.fillStyle = this.color;
                            c.beginPath(); c.arc(this.x, this.y, 2.4, 0, Math.PI * 2); c.fill();
                            c.globalAlpha = 1;
                        }
                    };
                }
                function makeConfetti() {
                    const W = canvas.width;
                    const color = palette[Math.floor(Math.random() * palette.length)];
                    return {
                        x: Math.random() * W,
                        y: -20 - Math.random() * 200,
                        vx: (Math.random() - 0.5) * 1.4,
                        vy: 1.5 + Math.random() * 2,
                        rot: Math.random() * Math.PI * 2,
                        vrot: (Math.random() - 0.5) * 0.25,
                        w: 6 + Math.random() * 5,
                        h: 10 + Math.random() * 6,
                        sway: Math.random() * Math.PI * 2,
                        color: color,
                        dead: false,
                        update() {
                            this.sway += 0.04;
                            this.x += this.vx + Math.sin(this.sway) * 0.6;
                            this.y += this.vy;
                            this.rot += this.vrot;
                            if (this.y > canvas.height + 30) this.dead = true;
                        },
                        draw(c) {
                            c.save();
                            c.translate(this.x, this.y);
                            c.rotate(this.rot);
                            c.fillStyle = this.color;
                            c.fillRect(-this.w / 2, -this.h / 2, this.w, this.h);
                            c.restore();
                        }
                    };
                }
                function makeBubble() {
                    const W = canvas.width, H = canvas.height;
                    const r = 8 + Math.random() * 22;
                    return {
                        x: Math.random() * W,
                        y: H + r,
                        r: r,
                        vy: -(0.6 + Math.random() * 1.2),
                        sway: Math.random() * Math.PI * 2,
                        life: 1,
                        dead: false,
                        update() {
                            this.sway += 0.02;
                            this.x += Math.sin(this.sway) * 0.8;
                            this.y += this.vy;
                            if (this.y < -this.r) { this.dead = true; return; }
                            if (this.y < canvas.height * 0.2) this.life -= 0.01;
                            if (this.life <= 0) this.dead = true;
                        },
                        draw(c) {
                            const grad = c.createRadialGradient(
                                this.x - this.r * 0.3, this.y - this.r * 0.3, this.r * 0.1,
                                this.x, this.y, this.r
                            );
                            grad.addColorStop(0, `rgba(255,255,255,${0.85 * this.life})`);
                            grad.addColorStop(0.4, `rgba(180,210,255,${0.35 * this.life})`);
                            grad.addColorStop(1, `rgba(124,92,191,${0.05 * this.life})`);
                            c.fillStyle = grad;
                            c.beginPath(); c.arc(this.x, this.y, this.r, 0, Math.PI * 2); c.fill();
                            c.strokeStyle = `rgba(255,255,255,${0.5 * this.life})`;
                            c.lineWidth = 1;
                            c.stroke();
                        }
                    };
                }
                function start(effect, intensity, durationMs) {
                    if (!effect) return;
                    ensureCanvas();
                    const scale = intensityScale[intensity] || 1;
                    spawning = true;
                    if (rafId === null) rafId = requestAnimationFrame(loop);
                    const burst = () => {
                        if (effect === 'fireworks') {
                            const n = Math.round(2 * scale);
                            for (let i = 0; i < Math.max(1, n); i++) particles.push(makeFirework());
                        } else if (effect === 'confetti') {
                            const n = Math.round(6 * scale);
                            for (let i = 0; i < n; i++) particles.push(makeConfetti());
                        } else if (effect === 'bubbles') {
                            const n = Math.round(3 * scale);
                            for (let i = 0; i < n; i++) particles.push(makeBubble());
                        }
                    };
                    const interval = effect === 'fireworks' ? 450 : (effect === 'bubbles' ? 280 : 180);
                    burst();
                    spawnTimer = setInterval(burst, interval);
                    setTimeout(stop, durationMs);
                }
                function stop() {
                    spawning = false;
                    if (spawnTimer) { clearInterval(spawnTimer); spawnTimer = null; }
                    // particles fade out naturally; loop tears down canvas when empty
                }
                return { start, stop };
            })();
            // Legacy overlays folded into the unified overlay
            // Weather, deaths and walk-ons keep their original look (styles already
            // live in index.css). They render independently of the alert queue and
            // only fire when their category has an enabled variant in twitch_alerts.
            function isCategoryEnabled(category) {
                const variants = alertConfigs[category];
                if (!variants || variants.length === 0) return false;
                return variants.some(v => v.enabled == 1);
            }
            const positionDefaults = { weather: 'left-top', deaths: 'left-bottom' };
            function defaultPositionFor(cat) { return positionDefaults[cat] || 'center-center'; }
            function getEnabledVariant(category) {
                const variants = alertConfigs[category];
                if (!variants) return null;
                return variants.find(v => v.enabled == 1) || null;
            }
            // Place a container at a position defined by the alert config.
            // cfg is the full config row (or {} / null). When cfg.position_x and
            // cfg.position_y are both set, those are used as % from the top-left
            // of the viewport (left/top anchored, transform:none) with a best-effort
            // clamp so the right/bottom edges stay on screen.  When absent, the
            // existing 9-preset screen_position string logic runs unchanged.
            function applyScreenPosition(el, cfg, category) {
                if (!el) return;
                const rx = cfg && cfg.position_x != null && cfg.position_x !== '' ? parseFloat(cfg.position_x) : null;
                const ry = cfg && cfg.position_y != null && cfg.position_y !== '' ? parseFloat(cfg.position_y) : null;
                if (rx !== null && ry !== null) {
                    let x = rx, y = ry;
                    // Clamp so right/bottom edges stay within the viewport.
                    const elW = el.offsetWidth;
                    const elH = el.offsetHeight;
                    const W = window.innerWidth;
                    const H = window.innerHeight;
                    if (elW > 0 && elH > 0 && W > 0 && H > 0) {
                        const maxLeft = ((W - elW) / W) * 100;
                        const maxTop  = ((H - elH) / H) * 100;
                        if (x > maxLeft) x = maxLeft;
                        if (y > maxTop)  y = maxTop;
                    }
                    el.style.left      = x + '%';
                    el.style.top       = y + '%';
                    el.style.right     = 'auto';
                    el.style.bottom    = 'auto';
                    el.style.transform = 'none';
                    return;
                }
                // Fall through: use the 9-preset screen_position string.
                const pos = cfg && cfg.screen_position ? cfg.screen_position : null;
                const parts = String(pos || defaultPositionFor(category)).split('-');
                const h = parts[0], v = parts[1];
                const M = '24px';
                if (h === 'left') { el.style.left = M; el.style.right = 'auto'; }
                else if (h === 'right') { el.style.right = M; el.style.left = 'auto'; }
                else { el.style.left = '50%'; el.style.right = 'auto'; }
                if (v === 'top') { el.style.top = M; el.style.bottom = 'auto'; }
                else if (v === 'bottom') { el.style.bottom = M; el.style.top = 'auto'; }
                else { el.style.top = '50%'; el.style.bottom = 'auto'; }
                const tx = (h === 'center') ? '-50%' : '0';
                const ty = (v === 'middle' || v === 'center') ? '-50%' : '0';
                el.style.transform = (tx === '0' && ty === '0') ? 'none' : `translate(${tx}, ${ty})`;
            }
            // Deaths - ported from overlay/deaths.php
            function renderDeaths(data) {
                const deathOverlay = document.getElementById('deathOverlay');
                if (!deathOverlay) return;
                applyScreenPosition(deathOverlay, getEnabledVariant('deaths') || {}, 'deaths');
                deathOverlay.innerHTML = `
                    <div class="deaths-overlay-page-content">
                        <div class="deaths-overlay-page-title">
                            <span class="deaths-overlay-page-emote"></span>
                            <span>Current Deaths</span>
                        </div>
                        <div>${escapeHtml(data.game)}</div>
                        <div>${escapeHtml(data['death-text'])}</div>
                    </div>
                `;
                deathOverlay.classList.remove('hide');
                deathOverlay.classList.add('show');
                deathOverlay.style.display = 'block';
                setTimeout(() => {
                    deathOverlay.classList.remove('show');
                    deathOverlay.classList.add('hide');
                }, 10000);
                setTimeout(() => { deathOverlay.style.display = 'none'; }, 11000);
            }
            // Weather - ported from overlay/weather.php (incl. the live clock)
            let weatherTimerId = null;
            function startWeatherClock(tz) {
                function updateTime() {
                    const el = document.getElementById('currentTime');
                    if (el) {
                        el.innerHTML = new Date().toLocaleTimeString('en-US', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    }
                }
                if (weatherTimerId !== null) clearInterval(weatherTimerId);
                updateTime();
                weatherTimerId = setInterval(updateTime, 1000);
            }
            function renderWeather(rawWeatherData) {
                let weather;
                try {
                    weather = JSON.parse(rawWeatherData);
                } catch (_) {
                    // Legacy payload: api.py used to send str(dict) via urlencode.
                    try { weather = JSON.parse(String(rawWeatherData).replace(/'/g, '"')); }
                    catch (e) { console.warn('WEATHER_DATA unparseable:', e); return; }
                }
                const weatherOverlay = document.getElementById('weatherOverlay');
                if (!weatherOverlay) return;
                applyScreenPosition(weatherOverlay, getEnabledVariant('weather') || {}, 'weather');
                weatherOverlay.innerHTML = `
                    <div class="weather-overlay-page-content">
                        <div class="weather-overlay-page-header">
                            ${weatherTimezone ? '<div id="currentTime" class="weather-overlay-page-time"></div>' : ''}
                            <div class="weather-overlay-page-location">${escapeHtml(weather.location)}</div>
                            <div class="weather-overlay-page-temperature">${escapeHtml(weather.temperature)}</div>
                        </div>
                        <div class="weather-overlay-page-details">
                            <img src="${escapeHtml(weather.icon)}" alt="${escapeHtml(weather.status)}" class="weather-overlay-page-icon">
                            <div class="weather-overlay-page-status">${escapeHtml(weather.status)}</div>
                            <div class="weather-overlay-page-wind">${escapeHtml(weather.wind)}</div>
                            <div class="weather-overlay-page-humidity">${escapeHtml(weather.humidity)}</div>
                        </div>
                    </div>
                `;
                weatherOverlay.classList.remove('hide');
                weatherOverlay.classList.add('show');
                weatherOverlay.style.display = 'block';
                if (weatherTimezone) startWeatherClock(weatherTimezone);
                setTimeout(() => {
                    weatherOverlay.classList.add('hide');
                    weatherOverlay.classList.remove('show');
                }, 10000);
                setTimeout(() => { weatherOverlay.style.display = 'none'; }, 11000);
            }
            // Walk-ons - ported from overlay/walkons.php (audio-only, own queue so it
            // never collides with alert sounds).
            let walkonAudio = null;
            const walkonQueue = [];
            function enqueueWalkon(url) {
                if (!url) return;
                walkonQueue.push(url);
                if (!walkonAudio) playNextWalkon();
            }
            function playNextWalkon() {
                if (walkonQueue.length === 0) { walkonAudio = null; return; }
                const url = walkonQueue.shift();
                walkonAudio = new Audio(`${url}?t=${Date.now()}`);
                walkonAudio.volume = 0.8;
                walkonAudio.addEventListener('ended', () => { walkonAudio = null; playNextWalkon(); });
                walkonAudio.addEventListener('error', () => { walkonAudio = null; playNextWalkon(); });
                walkonAudio.play().catch(e => console.error('Walk-on audio error:', e));
            }
            function walkonMediaUrl(data) {
                if (data.media_file) {
                    return {
                        url: `https://media.botofthespecter.com/${encodeURIComponent(data.channel)}/${encodeURIComponent(data.media_file)}`,
                        ext: (data.media_file.split('.').pop() || '').toLowerCase()
                    };
                }
                const e = data.ext && data.ext.startsWith('.') ? data.ext : '.mp3';
                return {
                    url: `https://walkons.botofthespecter.com/${encodeURIComponent(data.channel)}/${encodeURIComponent(data.user)}${e}`,
                    ext: e.replace('.', '').toLowerCase()
                };
            }
            function showWalkonVideo(url) {
                const el = document.getElementById('walkonOverlay');
                if (!el) return;
                applyScreenPosition(el, getEnabledVariant('walkons') || {}, 'walkons');
                el.innerHTML = `<video class="walkon-video" src="${url}" autoplay></video>`;
                el.style.display = 'block';
                const vid = el.querySelector('video');
                const done = () => { el.style.display = 'none'; el.innerHTML = ''; };
                if (vid) { vid.addEventListener('ended', done); vid.addEventListener('error', done); }
                else done();
            }
            function showWalkonCard(avatarUrl, name) {
                const el = document.getElementById('walkonOverlay');
                if (!el) return;
                applyScreenPosition(el, getEnabledVariant('walkons') || {}, 'walkons');
                const avatar = avatarUrl
                    ? `<img class="walkon-card-avatar" src="${avatarUrl}" alt="">`
                    : `<div class="walkon-card-avatar"></div>`;
                el.innerHTML = `<div class="walkon-card">${avatar}<div class="walkon-card-name">${escapeHtml(name || '')}</div></div>`;
                el.style.display = 'block';
                setTimeout(() => { el.style.display = 'none'; el.innerHTML = ''; }, 6000);
            }
            // Three walk-on modes (mode comes from the walkons table via the bot):
            //   sound          -> play audio only
            //   sound_overlay  -> play audio + show the viewer's picture & name
            //   video          -> play the assigned video (visual + its own audio)
            function handleWalkon(data) {
                const media = walkonMediaUrl(data);
                const mode = data.mode || (media.ext === 'mp4' ? 'video' : 'sound');
                if (mode === 'video' || media.ext === 'mp4') {
                    showWalkonVideo(media.url);
                } else if (mode === 'sound_overlay') {
                    showWalkonCard(data.avatar_url, data.display_name || data.user);
                    enqueueWalkon(media.url);
                } else {
                    enqueueWalkon(media.url);
                }
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
                        username: data['twitch-username'] || '',
                        level: parseInt(data['twitch-hype-level'] || data['level'] || 1)
                    });
                });
                socket.on('TWITCH_CHARITY', (data) => {
                    console.log('TWITCH_CHARITY event received:', data);
                    queueAlert('charity', {
                        username:     data['twitch-username'] || '',
                        amount:       data['twitch-charity-amount'] || '',     // formatted "100.00 USD" for display
                        amount_value: parseFloat(data['twitch-charity-value'] || 0), // numeric, for condition matching
                        charity_name: data['twitch-charity-name'] || ''
                    });
                });
                socket.on('TWITCH_CHANNEL_POINTS', (data) => {
                    console.log('TWITCH_CHANNEL_POINTS event received:', data);
                    queueAlert('channel_points', {
                        username:  data['twitch-username'] || '',
                        reward_id: data['reward-id'] || data['reward_id'] || ''
                    });
                });

                // Ko-fi - webhook payload comes wrapped as a JSON string in data.data
                socket.on('KOFI', (data) => {
                    console.log('KOFI event received:', data);
                    let payload = {};
                    try {
                        if (data && typeof data.data === 'string') payload = JSON.parse(data.data);
                        else if (data && typeof data.data === 'object' && data.data) payload = data.data;
                        else payload = data || {};
                    } catch (e) {
                        console.warn('Failed to parse KOFI payload:', e);
                        return;
                    }
                    const amt = payload.amount || '';
                    const cur = payload.currency || '';
                    queueAlert('kofi', {
                        kofi_type:    payload.type || '',
                        username:     payload.from_name || '',
                        amount:       amt && cur ? `${amt} ${cur}` : amt,
                        amount_value: parseFloat(amt) || 0,
                        message:      payload.message || '',
                        tier_name:    payload.tier_name || ''
                    });
                });

                // Patreon - JSON:API envelope nested in data.data; api.py forwards it
                // via urlencode(dict) so the payload may be Python-literal rather than
                // strict JSON (mirrors what overlay/patreon.php has to deal with).
                function _parsePatreonPayload(raw) {
                    if (!raw) return null;
                    if (typeof raw === 'object') return raw;
                    try { return JSON.parse(raw); } catch (_) {}
                    try {
                        const jsonStr = raw
                            .replace(/'/g, '"')
                            .replace(/\bNone\b/g, 'null')
                            .replace(/\bTrue\b/g, 'true')
                            .replace(/\bFalse\b/g, 'false');
                        return JSON.parse(jsonStr);
                    } catch (_) {}
                    return null;
                }
                socket.on('PATREON', (data) => {
                    console.log('PATREON event received:', data);
                    const parsed = _parsePatreonPayload(data && data.data !== undefined ? data.data : data);
                    if (!parsed) { console.warn('PATREON payload unparseable'); return; }
                    const member = (parsed.data || parsed || {});
                    const attrs  = member.attributes || {};
                    // Classify (matches overlay/patreon.php logic)
                    const status = String(attrs.patron_status || '').toLowerCase();
                    let patreon_type;
                    if (status === 'declined_patron' || status === 'former_patron') patreon_type = 'cancelled';
                    else if (attrs.last_charge_status === 'Paid' && attrs.campaign_lifetime_support_cents) patreon_type = 'update';
                    else patreon_type = 'pledge';
                    // Tier lookup from included array
                    let tier_name = '';
                    if (Array.isArray(parsed.included)) {
                        const t = parsed.included.find(r => r && r.type === 'tier' && r.attributes && r.attributes.title);
                        if (t) tier_name = t.attributes.title;
                    }
                    const currency = attrs.currency_code || 'USD';
                    const cents    = Number(attrs.currently_entitled_amount_cents) || 0;
                    const ltCents  = Number(attrs.campaign_lifetime_support_cents) || 0;
                    const dollars  = (cents / 100).toFixed(2);
                    const ltDollars= (ltCents / 100).toFixed(2);
                    queueAlert('patreon', {
                        patreon_type,
                        username:     attrs.full_name || attrs.email || '',
                        amount:       cents ? `${currency} ${dollars}` : '',
                        amount_value: cents ? cents / 100 : 0,
                        tier_name,
                        lifetime:     ltCents ? `${currency} ${ltDollars}` : ''
                    });
                });

                // Fourthwall - envelope { type, data } nested in event.data
                socket.on('FOURTHWALL', (data) => {
                    console.log('FOURTHWALL event received:', data);
                    const parsed = _parsePatreonPayload(data && data.data !== undefined ? data.data : data);
                    if (!parsed) { console.warn('FOURTHWALL payload unparseable'); return; }
                    const fwType    = parsed.type || '';
                    const eventData = parsed.data || {};
                    const totals = (eventData.amounts && eventData.amounts.total) ||
                                   (eventData.subscription && eventData.subscription.variant && eventData.subscription.variant.amount) ||
                                   null;
                    let amountStr = '';
                    let amountValue = 0;
                    if (totals) {
                        amountStr = `${totals.value} ${totals.currency || ''}`.trim();
                        amountValue = parseFloat(totals.value) || 0;
                    }
                    let username = eventData.username || eventData.nickname || '';
                    let item = '';
                    let interval = '';
                    if (fwType === 'ORDER_PLACED') {
                        const offer = (eventData.offers && eventData.offers[0]) || {};
                        const qty   = (offer.variant && offer.variant.quantity) || 1;
                        item = qty > 1 ? `${qty}× ${offer.name || 'item'}` : (offer.name || 'item');
                    } else if (fwType === 'GIVEAWAY_PURCHASED') {
                        item = (eventData.offer && eventData.offer.name) || 'giveaway';
                    } else if (fwType === 'SUBSCRIPTION_PURCHASED') {
                        const variant = (eventData.subscription && eventData.subscription.variant) || {};
                        interval = variant.interval || '';
                    }
                    queueAlert('fourthwall', {
                        fourthwall_type: fwType,
                        username,
                        amount:       amountStr,
                        amount_value: amountValue,
                        message:      eventData.message || '',
                        item,
                        interval
                    });
                });

                // Stream Bingo - four sub-events, each picks its own variant
                socket.on('STREAM_BINGO_STARTED', (data) => {
                    console.log('STREAM_BINGO_STARTED event received:', data);
                    queueAlert('stream_bingo', {
                        bingo_event:  'STREAM_BINGO_STARTED',
                        events_count: data.events_count || ''
                    });
                });
                socket.on('STREAM_BINGO_ENDED', (data) => {
                    console.log('STREAM_BINGO_ENDED event received:', data);
                    queueAlert('stream_bingo', { bingo_event: 'STREAM_BINGO_ENDED' });
                });
                socket.on('STREAM_BINGO_EVENT_CALLED', (data) => {
                    console.log('STREAM_BINGO_EVENT_CALLED event received:', data);
                    queueAlert('stream_bingo', {
                        bingo_event:      'STREAM_BINGO_EVENT_CALLED',
                        bingo_number:     data.display_number || '',
                        bingo_event_name: data.event_name || ''
                    });
                });
                socket.on('STREAM_BINGO_WINNER', (data) => {
                    console.log('STREAM_BINGO_WINNER event received:', data);
                    queueAlert('stream_bingo', {
                        bingo_event: 'STREAM_BINGO_WINNER',
                        username:    data.player_name || '',
                        rank_text:   data.rank_text || ''
                    });
                });

                // Discord member joins - rendered as a standard alert variant
                socket.on('DISCORD_JOIN', (data) => {
                    console.log('DISCORD_JOIN event received:', data);
                    queueAlert('discord_join', { username: data.member || '' });
                });

                // Legacy overlays folded in - each keeps its own theme and only fires
                // when its category has an enabled variant.
                socket.on('DEATHS', (data) => {
                    console.log('DEATHS event received:', data);
                    if (isCategoryEnabled('deaths')) renderDeaths(data);
                });
                socket.on('WEATHER_DATA', (data) => {
                    console.log('WEATHER_DATA event received:', data);
                    if (isCategoryEnabled('weather')) renderWeather(data.weather_data);
                });
                socket.on('WALKON', (data) => {
                    console.log('WALKON event received:', data);
                    if (isCategoryEnabled('walkons')) handleWalkon(data);
                });

                // Dashboard "Refresh Overlay" - full page reload (meta-refresh style)
                // so PHP re-fetches alert configs / settings from the DB.
                socket.on('OVERLAY_REFRESH', (data) => {
                    console.log('OVERLAY_REFRESH received - reloading browser source', data);
                    const meta = document.createElement('meta');
                    meta.setAttribute('http-equiv', 'refresh');
                    meta.setAttribute('content', '0');
                    document.head.appendChild(meta);
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
    <!-- Legacy overlays folded in (weather, deaths). Walk-ons is audio-only. -->
    <div id="deathOverlay" class="deaths-overlay-page"></div>
    <div id="weatherOverlay" class="weather-overlay-page hide"></div>
    <div id="walkonOverlay" class="walkon-overlay-page"></div>
</body>
</html>