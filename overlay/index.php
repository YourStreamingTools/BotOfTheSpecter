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
    // Called, winner and ended cards were saved with the "game started" line.
    $bingoStartedLine = "Stream Bingo is starting!";
    $bingoLines = [
        "bingo_event = 'STREAM_BINGO_EVENT_CALLED'" => "Event called:\n{bingo_event_name}",
        "bingo_event = 'STREAM_BINGO_WINNER'" => "BINGO! {username}\ngot {rank_text}!",
        "bingo_event = 'STREAM_BINGO_ENDED'" => "Stream Bingo has ended!",
    ];
    $bingoFix = $db->prepare("UPDATE twitch_alerts SET message_template = ? WHERE id = ? AND message_template = ?");
    foreach ($rows as &$row) {
        if (($row['alert_category'] ?? '') !== 'stream_bingo' || ($row['message_template'] ?? '') !== $bingoStartedLine) {
            continue;
        }
        $bingoCond = trim((string) ($row['alert_condition'] ?? ''));
        if (!isset($bingoLines[$bingoCond])) {
            continue;
        }
        $row['message_template'] = $bingoLines[$bingoCond];
        $bingoFix->execute([$bingoLines[$bingoCond], $row['id'], $bingoStartedLine]);
    }
    unset($row);
    foreach ($rows as $row) {
        $alertConfigs[$row['alert_category']][] = $row;
    }
    // Weather's clock (ported from overlay/weather.php) needs the streamer's timezone.
    $tzStmt = $db->query("SELECT timezone FROM profile LIMIT 1");
    $tzRow = $tzStmt ? $tzStmt->fetch(PDO::FETCH_ASSOC) : null;
    $timezone = $tzRow['timezone'] ?? null;
}
// Voice for "Read message aloud": the same saved TTS settings the API's TTS trigger uses.
$ttsVoice = null;
if ($username) {
    try {
        $ttsStmt = $db->query("SELECT voice, language, style, expressive_voice FROM tts_settings LIMIT 1");
        $ttsVoice = $ttsStmt ? ($ttsStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    } catch (Throwable $e) {
        $ttsVoice = null;
    }
}
$alertDisplayScale = 1;
if ($username) {
    try {
        $canvasStmt = $db->query("SELECT canvas FROM twitch_alert_display WHERE id = 1");
        $canvasRow = $canvasStmt ? $canvasStmt->fetch(PDO::FETCH_ASSOC) : null;
        $canvasScales = ['1280x720' => 1.5, '1920x1080' => 1, '2560x1440' => 0.75];
        $canvasName = is_array($canvasRow) ? ($canvasRow['canvas'] ?? '1920x1080') : '1920x1080';
        if (isset($canvasScales[$canvasName])) {
            $alertDisplayScale = $canvasScales[$canvasName];
        }
    } catch (Throwable $e) {
        $alertDisplayScale = 1;
    }
}
// Ad break still running when the page loads (OBS refresh, or the dashboard
// reloading the overlay after a save). The bot only sends TWITCH_AD_BREAK once.
$activeAdBreak = null;
if ($username) {
    try {
        $adStmt = $db->query("SELECT started_at, duration_seconds, eta_end FROM ad_break_state WHERE id = 1 AND end_notice_sent = 0");
        $adRow = $adStmt ? $adStmt->fetch(PDO::FETCH_ASSOC) : null;
        if (is_array($adRow) && (int)$adRow['eta_end'] > time()) {
            $activeAdBreak = [
                'duration_seconds' => (int)$adRow['duration_seconds'],
                'started_at' => (int)$adRow['started_at'],
                'ends_at' => (int)$adRow['eta_end'],
            ];
        }
    } catch (Throwable $e) {
        $activeAdBreak = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Twitch Alerts Overlay - BotOfTheSpecter</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script src="js/specter-ws.js?v=<?php echo filemtime(__DIR__ . '/js/specter-ws.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
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
            function setConnectionStatus(text, state) {
                let status = document.getElementById('overlayConnectionStatus');
                if (!status) {
                    status = document.createElement('div');
                    status.id = 'overlayConnectionStatus';
                    status.className = 'overlay-connection-status';
                    document.body.appendChild(status);
                }
                status.textContent = text;
                status.dataset.state = state;
            }
            if (!code) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }
            const alertConfigs = <?php echo json_encode($alertConfigs); ?>;
            // Same scale the alerts visual editor applies for the chosen OBS canvas.
            const alertDisplayScale = <?php echo json_encode($alertDisplayScale); ?>;
            const username = <?php echo json_encode($username); ?>;
            if (!username) {
                showOverlayError('Invalid code provided in the URL', 'danger');
                return;
            }
            const weatherTimezone = <?php echo json_encode($timezone); ?>;
            const ttsVoice = <?php echo json_encode($ttsVoice); ?> || {};
            let alertSocket = null;
            const mediaBase = 'https://media.botofthespecter.com/' + username + '/';
            // One queue for every one-shot alert: cards, sounds, TTS, videos, walk-ons,
            // deaths and weather. The next job starts when the current one has finished.
            // Ad breaks stay outside this queue — they last the whole Twitch break.
            const jobQueue = [];
            let jobActive = false;
            let currentJob = null;
            let currentAudio = null;
            let fxAudio = null;
            let currentVideo = null;
            function enqueueJob(job, front) {
                if (front) jobQueue.unshift(job);
                else jobQueue.push(job);
                if (!jobActive) runNextJob();
            }
            function runNextJob() {
                const job = jobQueue.shift();
                if (!job) {
                    jobActive = false;
                    currentJob = null;
                    return;
                }
                jobActive = true;
                currentJob = job;
                let settled = false;
                const done = () => {
                    if (settled) return;
                    settled = true;
                    if (currentJob === job) currentJob = null;
                    jobActive = false;
                    runNextJob();
                };
                try {
                    job.cancel = job.start(done) || function () {};
                } catch (e) {
                    console.error('Alert job failed:', e);
                    done();
                }
            }
            // A new sub and a resub for the same person often arrive together.
            // Hold briefly, then keep the one with the higher month count.
            const SUB_COALESCE_MS = 3000;
            const pendingSubs = new Map();
            function subUser(eventData) {
                return String((eventData && eventData.username) || '').toLowerCase();
            }
            function subMonths(eventData) {
                const n = parseInt(eventData && eventData.months, 10);
                return n > 0 ? n : 1;
            }
            function enqueueSubscriptionJob(eventData, front) {
                const user = subUser(eventData);
                const job = {
                    kind: 'subscription',
                    user: user,
                    months: subMonths(eventData),
                    eventData: eventData,
                    cancel: function () {},
                    start(done) {
                        const config = getMatchingVariant('subscription', this.eventData);
                        if (!config) { done(); return; }
                        return renderAlert(config, this.eventData, done);
                    }
                };
                enqueueJob(job, front);
            }
            function queueSubscription(eventData) {
                const user = subUser(eventData);
                const months = subMonths(eventData);
                const pending = pendingSubs.get(user);
                if (pending) {
                    if (months >= pending.months) {
                        pending.months = months;
                        pending.eventData = eventData;
                    }
                    return;
                }
                const queued = jobQueue.find(function (j) { return j.kind === 'subscription' && j.user === user; });
                if (queued) {
                    if (months >= queued.months) {
                        queued.months = months;
                        queued.eventData = eventData;
                    }
                    return;
                }
                if (currentJob && currentJob.kind === 'subscription' && currentJob.user === user) {
                    if (months > currentJob.months) {
                        enqueueSubscriptionJob(eventData, true);
                        if (typeof currentJob.cancel === 'function') currentJob.cancel();
                    }
                    return;
                }
                const entry = { months: months, eventData: eventData };
                entry.timer = setTimeout(function () {
                    pendingSubs.delete(user);
                    enqueueSubscriptionJob(entry.eventData, false);
                }, SUB_COALESCE_MS);
                pendingSubs.set(user, entry);
            }
            // Sound alerts, video alerts, walk-ons and TTS use the volume set on the Alerts page.
            function mediaLevel(category, field, fallback) {
                const row = (alertConfigs[category] || [])[0];
                const n = row ? parseInt(row[field], 10) : NaN;
                return Number.isFinite(n) ? n : fallback;
            }
            // A saved 0 is a real value (silent, no padding); only a missing value falls back.
            function configNumber(value, fallback, min, max) {
                const n = parseInt(value, 10);
                return Math.max(min, Math.min(max, Number.isFinite(n) ? n : fallback));
            }
            function mediaVolume(category) {
                return Math.max(0, Math.min(100, mediaLevel(category, 'sound_volume', 50))) / 100;
            }
            function enqueueFxAudio(url, volume) {
                if (!url) return;
                const n = Number(volume);
                const vol = Number.isFinite(n) ? Math.max(0, Math.min(1, n)) : 0.3;
                enqueueJob({
                    kind: 'audio',
                    start(done) {
                        const audio = new Audio(SpecterOverlayWS.playbackUrl(url));
                        fxAudio = audio;
                        audio.volume = vol;
                        let settled = false;
                        const finish = () => {
                            if (settled) return;
                            settled = true;
                            if (fxAudio === audio) fxAudio = null;
                            done();
                        };
                        audio.addEventListener('ended', finish);
                        audio.addEventListener('error', (e) => {
                            console.error('FX audio error:', e, 'src=', audio.src);
                            finish();
                        });
                        audio.play().catch(err => {
                            console.warn('FX autoplay blocked:', err && err.name);
                            finish();
                        });
                        return () => { audio.pause(); finish(); };
                    }
                });
            }
            // OBS browser sources often block autoplay until a context is unlocked.
            (function unlockAudio() {
                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;
                    const ctx = new Ctx();
                    const buf = ctx.createBuffer(1, 1, 22050);
                    const src = ctx.createBufferSource();
                    src.buffer = buf;
                    src.connect(ctx.destination);
                    src.start(0);
                    ctx.resume().then(() => ctx.close()).catch(() => {});
                } catch (e) {}
            })();
            // Video size is a share of the screen, so it looks the same on any canvas.
            // No saved position means centred.
            function placeVideoAlert(vid) {
                const cfg = (alertConfigs.video_alerts || [])[0] || {};
                const size = configNumber(cfg.video_size, 70, 10, 100);
                const free = 100 - size;
                const hasPos = cfg.position_x !== null && cfg.position_x !== undefined && cfg.position_x !== ''
                    && cfg.position_y !== null && cfg.position_y !== undefined && cfg.position_y !== '';
                const x = hasPos ? Math.max(0, Math.min(free, parseFloat(cfg.position_x) || 0)) : free / 2;
                const y = hasPos ? Math.max(0, Math.min(free, parseFloat(cfg.position_y) || 0)) : free / 2;
                vid.style.width = size + 'vw';
                vid.style.height = size + 'vh';
                vid.style.left = x + 'vw';
                vid.style.top = y + 'vh';
            }
            // Video alerts (channel points, store) play with their own audio.
            // Same player as the retired video-alert.php browser source.
            function enqueueVideo(url) {
                if (!url) return;
                enqueueJob({
                    kind: 'video',
                    start(done) {
                        const vid = document.createElement('video');
                        currentVideo = vid;
                        vid.src = SpecterOverlayWS.playbackUrl(url);
                        vid.volume = mediaVolume('video_alerts');
                        vid.controls = false;
                        vid.className = 'video-alert-overlay-page-video video-alert-overlay-page-video-pending';
                        placeVideoAlert(vid);
                        vid.preload = 'auto';
                        document.body.appendChild(vid);
                        let settled = false;
                        const finish = () => {
                            if (settled) return;
                            settled = true;
                            if (vid.parentNode) vid.parentNode.removeChild(vid);
                            if (currentVideo === vid) currentVideo = null;
                            done();
                        };
                        const loadStall = setTimeout(finish, 20000);
                        vid.addEventListener('canplaythrough', () => {
                            clearTimeout(loadStall);
                            if (currentVideo !== vid || settled) return;
                            vid.classList.remove('video-alert-overlay-page-video-pending');
                            vid.play().catch(error => {
                                console.warn('Video autoplay blocked:', error && error.name);
                                finish();
                            });
                        }, { once: true });
                        vid.addEventListener('ended', () => { clearTimeout(loadStall); finish(); });
                        vid.addEventListener('error', (e) => {
                            console.error('Video alert failed to load:', e);
                            clearTimeout(loadStall);
                            finish();
                        });
                        return () => { clearTimeout(loadStall); vid.pause(); finish(); };
                    }
                });
            }
            // Sound, video and TTS keep playing when the category row does not exist yet,
            // so a stream that has not opened Alerts since this shipped does not go silent.
            // Once the row exists, the on/off switch is the only authority.
            function isPlaybackEnabled(category) {
                const variants = alertConfigs[category];
                if (!variants || variants.length === 0) return true;
                return variants.some(v => v.enabled == 1);
            }
            document.addEventListener('click', () => {
                if (fxAudio) {
                    fxAudio.play().catch(() => {});
                }
                if (currentVideo) {
                    currentVideo.play().catch(() => {});
                }
            }, { capture: true, once: true });
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
                // Watch streaks have one card and no condition: every shared streak alerts.
                if (category === 'watch_streak') return enabled[0];
                // Conditional variants first so a newer catch-all can't shadow them, then highest index first
                const sorted = enabled.slice().sort((a, b) =>
                    (!a.alert_condition - !b.alert_condition) || (b.variant_index - a.variant_index));
                for (const variant of sorted) {
                    const cond = variant.alert_condition;
                    if (!cond) {
                        // A channel-point variant with no reward must not catch every redemption.
                        if (category === 'channel_points') continue;
                        return variant; // No condition = matches all
                    }
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
                    } else if (category === 'channel_points') {
                        // Dashboard stores: reward_id = 'uuid'. Misses do not fall through.
                        const rewardMatch = cond.match(/reward_id\s*=\s*['"]?([^'"\s]+)['"]?/);
                        const wanted = rewardMatch ? String(rewardMatch[1]).toLowerCase() : '';
                        const got = String(eventData.reward_id || '').toLowerCase();
                        if (wanted && got && wanted === got) return variant;
                    } else {
                        return variant; // Unknown condition type, use variant
                    }
                }
                // Channel points must not fall through to a variant whose condition missed.
                if (category === 'channel_points') return null;
                // Fallback to first enabled variant
                return enabled[0];
            }
            function queueAlert(category, eventData) {
                if (category === 'subscription') {
                    queueSubscription(eventData);
                    return;
                }
                const config = getMatchingVariant(category, eventData);
                if (!config) return;
                enqueueJob({
                    kind: category,
                    start(done) {
                        return renderAlert(config, eventData, done);
                    }
                });
            }
            // rawTokens inject HTML after escaping (ad countdown spans). Other tokens are text.
            function composeAlertMessageHtml(template, eventData, rawTokens) {
                let message = (template || '').replace(/\\n/g, '\n');
                const tokens = rawTokens || {};
                Object.keys(tokens).forEach((key) => {
                    message = message.split('{' + key + '}').join('@@' + key + '@@');
                });
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
                    .replace(/\{events_count\}/g, eventData.events_count || '')
                    .replace(/\{duration\}/g, eventData.duration || '')
                    .replace(/\{seconds\}/g, eventData.seconds || '')
                    .replace(/\{description\}/g, eventData.description || '')
                    .replace(/\{current_amount\}/g, eventData.current_amount || '')
                    .replace(/\{target_amount\}/g, eventData.target_amount || '')
                    .replace(/\{goal_type\}/g, eventData.goal_type || '');
                const lines = message.split('\n');
                let messageHtml = '';
                if (lines.length > 1) {
                    messageHtml = `<span class="twitch-alert-accent">${escapeHtml(lines[0])}</span><br>${escapeHtml(lines.slice(1).join('\n')).replace(/\n/g, '<br>')}`;
                } else {
                    messageHtml = escapeHtml(message).replace(/\n/g, '<br>');
                }
                Object.keys(tokens).forEach((key) => {
                    messageHtml = messageHtml.split('@@' + key + '@@').join(tokens[key]);
                });
                return messageHtml;
            }
            // Ads, Discord, tips, streaks and goals keep a built-in message.
            function resolveAlertTemplate(config) {
                const tpl = config && config.message_template;
                if (tpl && String(tpl).trim() !== '') return tpl;
                const defaults = {
                    discord_join: "{username}\nhopped into the Discord!",
                    kofi: "{username}\nsupported on Ko-fi!",
                    patreon: "{username}\nbecame a patron!",
                    fourthwall: "{username}\nsupported on Fourthwall!",
                    ad_break: "Ads are playing\nBack in {duration}",
                    watch_streak: "{username}\nis on a {streak}-stream watch streak!",
                    goals: "{description}\n{current_amount} / {target_amount}"
                };
                return defaults[config && config.alert_category] || '';
            }
            function buildAlertImageHtml(config) {
                if (!config || !config.alert_image) return '';
                const imageScale = (config.image_scale || 100) / 100;
                const imgUrl = mediaBase + config.alert_image;
                const ext = String(config.alert_image).split('.').pop().toLowerCase();
                if (ext === 'webm') {
                    const vol = Math.max(0, Math.min(100, parseInt(config.image_volume, 10) || 0));
                    const muted = vol <= 0 ? ' muted' : '';
                    return `<video class="twitch-alert-image" src="${imgUrl}" autoplay loop${muted} data-volume="${vol / 100}" style="transform:scale(${imageScale})"></video>`;
                }
                return `<img class="twitch-alert-image" src="${imgUrl}" alt="" style="transform:scale(${imageScale})">`;
            }
            function armAlertImageAudio(container) {
                const vid = container.querySelector('video.twitch-alert-image');
                if (!vid) return;
                const vol = parseFloat(vid.getAttribute('data-volume') || '0');
                if (!(vol > 0)) return;
                vid.volume = vol;
                vid.muted = false;
                vid.play().catch(() => {
                    vid.muted = true;
                    vid.play().catch(() => {});
                });
            }
            function renderAlert(config, eventData, done) {
                const container = document.getElementById('alertContainer');
                let finished = false;
                let hideTimer = null;
                let outTimer = null;
                let celebrationGen = 0;
                const finish = (cancelled) => {
                    if (finished) return;
                    finished = true;
                    if (hideTimer) clearTimeout(hideTimer);
                    if (outTimer) clearTimeout(outTimer);
                    if (celebrationGen) celebration.stop(celebrationGen);
                    if (currentAudio) {
                        currentAudio.pause();
                        currentAudio = null;
                    }
                    if (container) {
                        container.querySelectorAll('video, audio').forEach((el) => { try { el.pause(); } catch (e) {} });
                        container.classList.remove('show');
                        container.style.animation = '';
                        if (cancelled) container.innerHTML = '';
                    }
                    if (typeof done === 'function') done();
                };
                if (!container) { finish(false); return; }
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
                const messageHtml = composeAlertMessageHtml(resolveAlertTemplate(config), eventData);
                // Build layout
                const layout = config.layout_preset || 'above';
                const imageHtml = buildAlertImageHtml(config);
                const boxStyles = [
                    `background:${bgRgba}`,
                    `padding:${configNumber(config.padding, 16, 0, 100)}px`,
                    `gap:${configNumber(config.gap, 16, 0, 100)}px`,
                    `border-radius:${config.rounded_corners == 1 ? '12px' : '0'}`,
                    `box-shadow:${config.drop_shadow == 1 ? '0 4px 20px rgba(0,0,0,0.5)' : 'none'}`
                ].join(';');
                // Single quotes: this string lands in a double-quoted style attribute.
                // A double-quoted font name closed that attribute and dropped colour.
                const fontFamily = String(config.font_family || 'Roboto').replace(/['"\\]/g, '');
                const textColor = hex6.test(config.text_color) ? config.text_color : '#FFFFFF';
                const textStyles = [
                    `font-family:'${fontFamily}',sans-serif`,
                    `font-weight:${cssWeight}`,
                    `font-size:${config.font_size || 24}px`,
                    `color:${textColor}`,
                    `text-align:${config.text_alignment || 'center'}`,
                    `text-shadow:${config.text_drop_shadow == 1 ? '0 2px 4px rgba(0,0,0,0.8)' : 'none'}`
                ];
                // Vertical alignment only has room to act when the image sits beside the text.
                if (layout === 'left' || layout === 'right') {
                    const valign = { top: 'flex-start', bottom: 'flex-end' }[config.text_vertical_alignment] || 'center';
                    textStyles.push(`align-self:${valign}`);
                }
                container.innerHTML = `
                    <div class="twitch-alert-box layout-${layout}" style="${boxStyles}">
                        ${imageHtml}
                        <div class="twitch-alert-text" style="${textStyles.join(';')}">${messageHtml}</div>
                    </div>
                `;
                // Apply accent color
                const accentEls = container.querySelectorAll('.twitch-alert-accent');
                accentEls.forEach(el => el.style.color = config.accent_color || '#A1C53A');
                armAlertImageAudio(container);
                if (config.tts_enabled == 1) speakAlertText(messageHtml);
                // Play alert sound
                if (config.alert_sound) {
                    const soundUrl = mediaBase + config.alert_sound;
                    currentAudio = new Audio(SpecterOverlayWS.playbackUrl(soundUrl));
                    currentAudio.volume = configNumber(config.sound_volume, 50, 0, 100) / 100;
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
                // Celebration effect - spawns over the alert's full duration.
                // stop() is generation-scoped so an ad break's longer effect is left alone.
                if (config.celebration_enabled == 1 && config.celebration_effect) {
                    celebrationGen = celebration.start(
                        config.celebration_effect,
                        config.celebration_intensity || 'medium',
                        duration
                    );
                }
                hideTimer = setTimeout(() => {
                    container.style.animation = `${config.animation_out || 'fadeOut'} ${animOutDur}s forwards`;
                    if (celebrationGen) celebration.stop(celebrationGen);
                    celebrationGen = 0;
                    outTimer = setTimeout(() => finish(false), animOutDur * 1000);
                }, duration);
                return () => finish(true);
            }
            // "Read message aloud": the TTS server voices the text and sends it back as a TTS event,
            // which joins the alert queue and plays at the Text to speech volume.
            function speakAlertText(messageHtml) {
                if (!alertSocket || !isPlaybackEnabled('tts')) return;
                const html = String(messageHtml || '').replace(/<br\s*\/?>/gi, ' ');
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
                if (!text) return;
                const payload = { text: text };
                if (ttsVoice.voice) payload.voice = ttsVoice.voice;
                if (ttsVoice.language) payload.language = ttsVoice.language;
                if (ttsVoice.style) payload.style = ttsVoice.style;
                if (ttsVoice.expressive_voice) payload.expressive_voice = ttsVoice.expressive_voice;
                alertSocket.emit('TTS', payload);
            }
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            // Nearest degree, halves away from zero. Matches api.py whole_degrees.
            function wholeDegreesText(text) {
                return String(text ?? '').replace(/-?\d+(?:\.\d+)?/g, (n) => {
                    const v = Number(n);
                    if (!Number.isFinite(v)) return n;
                    const sign = v < 0 ? -1 : 1;
                    return String(sign * Math.round(Math.abs(v)));
                });
            }
            // Ad card is outside the alert queue: a break lasts the full Twitch duration
            // and must not hold follows, subs, and cheers behind it.
            let adBreakGen = 0;
            let adBreakTimer = null;
            let adBreakHideTimer = null;
            let adBreakAudio = null;
            // Kept so a reload mid-break picks the countdown back up (test breaks too).
            const adBreakStorageKey = 'specterAdBreak:' + username;
            function saveAdBreakState(endMs) {
                try { localStorage.setItem(adBreakStorageKey, JSON.stringify({ ends_at: endMs })); } catch (e) {}
            }
            function clearAdBreakState() {
                try { localStorage.removeItem(adBreakStorageKey); } catch (e) {}
            }
            function loadAdBreakState() {
                try {
                    const raw = localStorage.getItem(adBreakStorageKey);
                    return raw ? JSON.parse(raw) : null;
                } catch (e) {
                    return null;
                }
            }
            function formatAdClock(totalSeconds) {
                const s = Math.max(0, Math.floor(totalSeconds));
                const m = Math.floor(s / 60);
                const r = s % 60;
                return m + ':' + String(r).padStart(2, '0');
            }
            function adBreakEndMs(data) {
                const ends = parseInt(data && data.ends_at, 10);
                if (ends > 1000000000000) return ends;
                if (ends > 1000000000) return ends * 1000;
                const dur = parseInt(data && data.duration_seconds, 10);
                if (dur > 0) return Date.now() + dur * 1000;
                return 0;
            }
            function paintAdClocks(container, endMs) {
                const left = Math.max(0, Math.ceil((endMs - Date.now()) / 1000));
                container.querySelectorAll('[data-ad="duration"]').forEach((el) => {
                    el.textContent = formatAdClock(left);
                });
                container.querySelectorAll('[data-ad="seconds"]').forEach((el) => {
                    el.textContent = String(left);
                });
                return left;
            }
            function clearAdBreakTimers() {
                if (adBreakTimer) { clearInterval(adBreakTimer); adBreakTimer = null; }
                if (adBreakHideTimer) { clearTimeout(adBreakHideTimer); adBreakHideTimer = null; }
            }
            function hideAdBreak(animated) {
                const gen = ++adBreakGen;
                clearAdBreakTimers();
                clearAdBreakState();
                if (adBreakAudio) {
                    adBreakAudio.pause();
                    adBreakAudio = null;
                }
                const container = document.getElementById('adBreakOverlay');
                if (container && container._adCelebration) {
                    celebration.stop(container._adCelebration);
                    container._adCelebration = 0;
                }
                if (!container || !container.classList.contains('show')) {
                    if (container) container.innerHTML = '';
                    return;
                }
                const config = container._adConfig || {};
                const animOutDur = parseFloat(config.animation_out_duration) || 1;
                const box = container.querySelector('.ad-break-frame');
                if (!animated || !box) {
                    container.classList.remove('show');
                    container.innerHTML = '';
                    return;
                }
                box.style.animation = `${config.animation_out || 'fadeOut'} ${animOutDur}s forwards`;
                adBreakHideTimer = setTimeout(() => {
                    if (gen !== adBreakGen) return;
                    container.classList.remove('show');
                    container.innerHTML = '';
                    adBreakHideTimer = null;
                }, animOutDur * 1000);
            }
            // resume: picking up a break already in progress, so no alert sound.
            function showAdBreak(data, resume) {
                data = data || {};
                const config = getMatchingVariant('ad_break', data);
                if (!config) return;
                const endMs = adBreakEndMs(data);
                if (!endMs || endMs - Date.now() < 400) return;
                hideAdBreak(false);
                saveAdBreakState(endMs);
                const gen = adBreakGen;
                const container = document.getElementById('adBreakOverlay');
                if (!container) return;
                container._adConfig = config;
                container.style.left = '0';
                container.style.top = '0';
                container.style.right = '0';
                container.style.bottom = '0';
                container.style.width = '100%';
                container.style.height = '100%';
                container.style.transform = 'none';
                container.style.zoom = '';
                container.innerHTML = `
                    <div class="ad-break-frame">
                        <div class="ad-break-pill"><span class="ad-break-clock" data-ad="duration">0:00</span></div>
                    </div>
                `;
                paintAdClocks(container, endMs);
                container.classList.add('show');
                const box = container.querySelector('.ad-break-frame');
                const animInDur = parseFloat(config.animation_in_duration) || 1;
                if (box) {
                    box.style.animation = `${config.animation_in || 'fadeIn'} ${animInDur}s forwards`;
                }
                if (config.alert_sound && !resume) {
                    adBreakAudio = new Audio(SpecterOverlayWS.playbackUrl(mediaBase + config.alert_sound));
                    adBreakAudio.volume = configNumber(config.sound_volume, 50, 0, 100) / 100;
                    adBreakAudio.play().catch((e) => console.error('Ad break audio error:', e));
                }
                container._adCelebration = 0;
                adBreakTimer = setInterval(() => {
                    if (gen !== adBreakGen) return;
                    if (paintAdClocks(container, endMs) <= 0) hideAdBreak(true);
                }, 250);
            }
            const celebration = (function () {
                let canvas = null, ctx = null, particles = [], rafId = null;
                let spawning = false, spawnTimer = null, stopTimer = null, generation = 0;
                const palette = ['#7c5cbf', '#9070d8', '#fbbf24', '#3ecf8e', '#5cb8ff', '#f87171', '#ffffff'];
                const intensityScale = { light: 0.5, medium: 1, heavy: 2 };
                function ensureCanvas() {
                    if (!canvas) {
                        canvas = document.createElement('canvas');
                        canvas.className = 'celebration-canvas';
                        document.body.appendChild(canvas);
                        ctx = canvas.getContext('2d');
                        window.addEventListener('resize', fitCanvas);
                    }
                    fitCanvas();
                }
                function fitCanvas() {
                    if (!canvas) return;
                    canvas.width = Math.max(1, window.innerWidth);
                    canvas.height = Math.max(1, window.innerHeight);
                }
                // Particle speeds were authored for a small preview. Scale them to this source.
                function span() {
                    return Math.max(1, Math.min(window.innerWidth, window.innerHeight) / 480);
                }
                function destroyCanvas() {
                    if (rafId) cancelAnimationFrame(rafId);
                    rafId = null;
                    if (canvas) {
                        canvas.remove();
                        window.removeEventListener('resize', fitCanvas);
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
                    const s = span();
                    const startX = W * (0.03 + Math.random() * 0.94);
                    const targetY = H * (0.05 + Math.random() * 0.8);
                    const color = palette[Math.floor(Math.random() * palette.length)];
                    const gravity = 0.18 * s;
                    return {
                        x: startX, y: H + 10,
                        vy: -(Math.sqrt(2 * gravity * Math.max(1, H - targetY))),
                        vx: (Math.random() - 0.5) * 0.8 * s,
                        gravity: gravity,
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
                                    const burst = Math.round(34 + Math.random() * 18);
                                    for (let i = 0; i < burst; i++) {
                                        const a = (i / burst) * Math.PI * 2;
                                        const speed = (2.2 + Math.random() * 3.4) * s;
                                        particles.push(makeSpark(this.x, this.y, Math.cos(a) * speed, Math.sin(a) * speed, this.color, 2.2 * s));
                                    }
                                    this.dead = true;
                                }
                            }
                        },
                        draw(c) {
                            c.fillStyle = this.color;
                            c.beginPath(); c.arc(this.x, this.y, 2.5 * s, 0, Math.PI * 2); c.fill();
                        }
                    };
                }
                function makeSpark(x, y, vx, vy, color, radius) {
                    const s = span();
                    return {
                        x, y, vx, vy, color,
                        radius: radius || (2.2 * s),
                        life: 1, gravity: 0.045 * s, drag: 0.988,
                        dead: false,
                        update() {
                            this.x += this.vx; this.y += this.vy;
                            this.vy += this.gravity;
                            this.vx *= this.drag; this.vy *= this.drag;
                            this.life -= 0.01;
                            if (this.life <= 0) this.dead = true;
                        },
                        draw(c) {
                            c.globalAlpha = Math.max(0, this.life);
                            c.fillStyle = this.color;
                            c.beginPath(); c.arc(this.x, this.y, this.radius, 0, Math.PI * 2); c.fill();
                            c.globalAlpha = 1;
                        }
                    };
                }
                function makeConfetti() {
                    const W = canvas.width;
                    const s = span();
                    const color = palette[Math.floor(Math.random() * palette.length)];
                    return {
                        x: Math.random() * W,
                        y: -20 - Math.random() * (canvas.height * 0.35),
                        vx: (Math.random() - 0.5) * 1.6 * s,
                        vy: (1.4 + Math.random() * 2.2) * s,
                        rot: Math.random() * Math.PI * 2,
                        vrot: (Math.random() - 0.5) * 0.25,
                        w: (7 + Math.random() * 6) * s,
                        h: (12 + Math.random() * 8) * s,
                        sway: Math.random() * Math.PI * 2,
                        color: color,
                        dead: false,
                        update() {
                            this.sway += 0.04;
                            this.x += this.vx + Math.sin(this.sway) * 0.8 * s;
                            this.y += this.vy;
                            this.rot += this.vrot;
                            if (this.y > canvas.height + 40) this.dead = true;
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
                    const s = span();
                    const r = (16 + Math.random() * 42) * Math.min(s, 2.4);
                    return {
                        x: Math.random() * W,
                        y: H + r,
                        r: r,
                        vy: -(0.7 + Math.random() * 1.4) * s,
                        sway: Math.random() * Math.PI * 2,
                        life: 1,
                        dead: false,
                        update() {
                            this.sway += 0.02;
                            this.x += Math.sin(this.sway) * 1.1 * s;
                            this.y += this.vy;
                            if (this.y < -this.r) { this.dead = true; return; }
                            if (this.y < canvas.height * 0.15) this.life -= 0.008;
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
                    if (!effect) return 0;
                    const gen = ++generation;
                    if (spawnTimer) { clearInterval(spawnTimer); spawnTimer = null; }
                    if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
                    ensureCanvas();
                    const scale = intensityScale[intensity] || 1;
                    spawning = true;
                    if (rafId === null) rafId = requestAnimationFrame(loop);
                    const burst = () => {
                        const width = canvas.width;
                        if (effect === 'fireworks') {
                            const n = Math.max(3, Math.round((width / 640) * scale));
                            for (let i = 0; i < n; i++) particles.push(makeFirework());
                        } else if (effect === 'confetti') {
                            const n = Math.max(10, Math.round((width / 80) * scale));
                            for (let i = 0; i < n; i++) particles.push(makeConfetti());
                        } else if (effect === 'bubbles') {
                            const n = Math.max(4, Math.round((width / 380) * scale));
                            for (let i = 0; i < n; i++) particles.push(makeBubble());
                        }
                    };
                    const interval = effect === 'fireworks' ? 450 : (effect === 'bubbles' ? 280 : 180);
                    burst();
                    spawnTimer = setInterval(burst, interval);
                    stopTimer = setTimeout(() => stop(gen), durationMs);
                    return gen;
                }
                // A generation only stops its own effect, so a short alert cannot
                // cut off an ad break that started afterwards (and the reverse).
                function stop(gen) {
                    if (gen != null && gen !== generation) return;
                    generation++;
                    spawning = false;
                    if (spawnTimer) { clearInterval(spawnTimer); spawnTimer = null; }
                    if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
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
                // Visual editor scales the whole alert for 720p / 2K. Zoom keeps that
                // size without fighting the slide/zoom animations, which own transform.
                el.style.zoom = (alertDisplayScale && alertDisplayScale !== 1) ? String(alertDisplayScale) : '';
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
            // Deaths and weather keep their own card. The Alerts page adds a sound,
            // the time on screen and a celebration.
            function playModuleCard(el, category, done) {
                const cfg = getEnabledVariant(category) || {};
                const shownMs = configNumber(cfg.duration, 10, 1, 99) * 1000;
                if (cfg.alert_sound) {
                    const audio = new Audio(SpecterOverlayWS.playbackUrl(mediaBase + cfg.alert_sound));
                    audio.volume = configNumber(cfg.sound_volume, 50, 0, 100) / 100;
                    audio.play().catch(e => console.error(category + ' sound error:', e));
                }
                let gen = 0;
                if (cfg.celebration_enabled == 1 && cfg.celebration_effect) {
                    gen = celebration.start(cfg.celebration_effect, cfg.celebration_intensity || 'medium', shownMs);
                }
                el.classList.remove('hide');
                el.classList.add('show');
                el.style.display = 'block';
                setTimeout(() => {
                    el.classList.remove('show');
                    el.classList.add('hide');
                    if (gen) celebration.stop(gen);
                }, shownMs);
                setTimeout(() => {
                    el.style.display = 'none';
                    done();
                }, shownMs + 1000);
            }
            function renderDeaths(data, done) {
                const deathOverlay = document.getElementById('deathOverlay');
                if (!deathOverlay) { done(); return; }
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
                playModuleCard(deathOverlay, 'deaths', done);
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
            function renderWeather(rawWeatherData, done) {
                let weather;
                try {
                    weather = JSON.parse(rawWeatherData);
                } catch (_) {
                    // Legacy payload: api.py used to send str(dict) via urlencode.
                    try { weather = JSON.parse(String(rawWeatherData).replace(/'/g, '"')); }
                    catch (e) { console.warn('WEATHER_DATA unparseable:', e); done(); return; }
                }
                const weatherOverlay = document.getElementById('weatherOverlay');
                if (!weatherOverlay) { done(); return; }
                applyScreenPosition(weatherOverlay, getEnabledVariant('weather') || {}, 'weather');
                weatherOverlay.innerHTML = `
                    <div class="weather-overlay-page-content">
                        <div class="weather-overlay-page-header">
                            ${weatherTimezone ? '<div id="currentTime" class="weather-overlay-page-time"></div>' : ''}
                            <div class="weather-overlay-page-location">${escapeHtml(weather.location)}</div>
                            <div class="weather-overlay-page-temperature">${escapeHtml(wholeDegreesText(weather.temperature))}</div>
                        </div>
                        <div class="weather-overlay-page-details">
                            <img src="${escapeHtml(weather.icon)}" alt="${escapeHtml(weather.status)}" class="weather-overlay-page-icon">
                            <div class="weather-overlay-page-status">${escapeHtml(weather.status)}</div>
                            <div class="weather-overlay-page-wind">${escapeHtml(weather.wind)}</div>
                            <div class="weather-overlay-page-humidity">${escapeHtml(weather.humidity)}</div>
                        </div>
                    </div>
                `;
                if (weatherTimezone) startWeatherClock(weatherTimezone);
                playModuleCard(weatherOverlay, 'weather', done);
            }
            // Walk-ons share the alert queue so they never talk over another alert.
            function playWalkonAudio(url, done) {
                if (!url) { done(); return; }
                const audio = new Audio(SpecterOverlayWS.playbackUrl(url));
                audio.volume = mediaVolume('walkons');
                let settled = false;
                const finish = () => { if (!settled) { settled = true; done(); } };
                audio.addEventListener('ended', finish);
                audio.addEventListener('error', finish);
                audio.play().catch(e => { console.error('Walk-on audio error:', e); finish(); });
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
            function showWalkonVideo(url, done) {
                const el = document.getElementById('walkonOverlay');
                let settled = false;
                const finish = () => {
                    if (settled) return;
                    settled = true;
                    if (el) { el.style.display = 'none'; el.innerHTML = ''; }
                    done();
                };
                if (!el) { done(); return; }
                applyScreenPosition(el, getEnabledVariant('walkons') || {}, 'walkons');
                el.innerHTML = `<video class="walkon-video" src="${url}" autoplay></video>`;
                el.style.display = 'block';
                const vid = el.querySelector('video');
                if (vid) { vid.volume = mediaVolume('walkons'); vid.addEventListener('ended', finish); vid.addEventListener('error', finish); }
                else finish();
            }
            function showWalkonCard(avatarUrl, name, done) {
                const el = document.getElementById('walkonOverlay');
                if (!el) { done(); return; }
                applyScreenPosition(el, getEnabledVariant('walkons') || {}, 'walkons');
                const avatar = avatarUrl
                    ? `<img class="walkon-card-avatar" src="${avatarUrl}" alt="">`
                    : `<div class="walkon-card-avatar"></div>`;
                el.innerHTML = `<div class="walkon-card">${avatar}<div class="walkon-card-name">${escapeHtml(name || '')}</div></div>`;
                el.style.display = 'block';
                setTimeout(() => { el.style.display = 'none'; el.innerHTML = ''; done(); }, 6000);
            }
            // Three walk-on modes (mode comes from the walkons table via the bot):
            //   sound          -> play audio only
            //   sound_overlay  -> play audio + show the viewer's picture & name
            //   video          -> play the assigned video (visual + its own audio)
            function handleWalkon(data) {
                enqueueJob({
                    kind: 'walkon',
                    start(done) {
                        const media = walkonMediaUrl(data);
                        const mode = data.mode || (media.ext === 'mp4' ? 'video' : 'sound');
                        if (mode === 'video' || media.ext === 'mp4') {
                            showWalkonVideo(media.url, done);
                            return;
                        }
                        if (mode === 'sound_overlay') {
                            let pending = 2;
                            const part = () => { if (--pending === 0) done(); };
                            showWalkonCard(data.avatar_url, data.display_name || data.user, part);
                            playWalkonAudio(media.url, part);
                            return;
                        }
                        playWalkonAudio(media.url, done);
                    }
                });
            }
            // Patreon/Fourthwall: api.py may forward Python-literal rather than strict JSON
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
            // Resume a break that was running before this page loaded. Latest end wins.
            (function resumeAdBreak() {
                let resumeData = null;
                [loadAdBreakState(), <?php echo json_encode($activeAdBreak); ?>].forEach((candidate) => {
                    if (!candidate || !candidate.ends_at) return;
                    if (!resumeData || adBreakEndMs(candidate) > adBreakEndMs(resumeData)) resumeData = candidate;
                });
                // showAdBreak saves it again only if the break is still live and enabled.
                clearAdBreakState();
                if (resumeData) showAdBreak(resumeData, true);
            })();
            // Specter bus: helper owns reconnect (SUCCESS-gated ready, dispose + progressive backoff)
            SpecterOverlayWS.create({
                code: code,
                channel: 'Overlay',
                name: 'Twitch Alerts',
                onStatus: setConnectionStatus,
                bind: function (socket) {
                    alertSocket = socket;
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
                    socket.on('TWITCH_AD_BREAK', (data) => {
                        console.log('TWITCH_AD_BREAK', data && data.duration_seconds, data && data.ends_at);
                        showAdBreak(data || {});
                    });
                    socket.on('TWITCH_AD_BREAK_END', () => {
                        console.log('TWITCH_AD_BREAK_END');
                        hideAdBreak(true);
                    });
                    socket.on('TWITCH_CHARITY', (data) => {
                        console.log('TWITCH_CHARITY event received:', data);
                        queueAlert('charity', {
                            username:     data['twitch-username'] || '',
                            amount:       data['twitch-charity-amount'] || '',
                            amount_value: parseFloat(data['twitch-charity-value'] || 0),
                            charity_name: data['twitch-charity-name'] || ''
                        });
                    });
                    // Bot / WS emit TWITCH_CHANNELPOINTS (no underscore between CHANNEL and POINTS).
                    // Accept the legacy underscore form too so older emitters still match.
                    function handleChannelPoints(data) {
                        console.log('TWITCH_CHANNELPOINTS event received:', data);
                        let rewards = data || {};
                        if (data && data.rewards != null) {
                            try {
                                rewards = (typeof data.rewards === 'string')
                                    ? JSON.parse(data.rewards)
                                    : data.rewards;
                            } catch (e) {
                                console.warn('Failed to parse channel points rewards payload:', e);
                                rewards = data;
                            }
                        }
                        const reward = (rewards && rewards.reward) || {};
                        queueAlert('channel_points', {
                            username:  rewards.user_name || rewards.user_login
                                       || data['twitch-username'] || '',
                            reward_id: reward.id || data['reward-id'] || data.reward_id || '',
                            message:   rewards.user_input || data.message || ''
                        });
                    }
                    socket.on('TWITCH_CHANNELPOINTS', handleChannelPoints);
                    socket.on('TWITCH_CHANNEL_POINTS', handleChannelPoints);

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

                    // Patreon - JSON:API envelope nested in data.data
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
                        if (isCategoryEnabled('deaths')) enqueueJob({
                            kind: 'deaths',
                            start(done) {
                                renderDeaths(data, done);
                            }
                        });
                    });
                    socket.on('WEATHER_DATA', (data) => {
                        console.log('WEATHER_DATA event received:', data);
                        if (isCategoryEnabled('weather')) enqueueJob({
                            kind: 'weather',
                            start(done) {
                                renderWeather(data.weather_data, done);
                            }
                        });
                    });
                    socket.on('WALKON', (data) => {
                        console.log('WALKON event received:', data);
                        if (isCategoryEnabled('walkons')) handleWalkon(data);
                    });

                    // Subathon time added is a normal alert card. The countdown clock
                    // stays on the subathon overlay; this is only the "minutes added" moment.
                    socket.on('SUBATHON_ADD_TIME', (data) => {
                        console.log('SUBATHON_ADD_TIME event received:', data);
                        const minutes = data && (data.added_minutes || data.minutes);
                        queueAlert('subathon', { added_minutes: minutes != null ? String(minutes) : '' });
                    });

                    // Watch streaks. Every shared streak shows the card.
                    socket.on('WATCH_STREAK', (data) => {
                        console.log('WATCH_STREAK event received:', data);
                        const streak = parseInt((data && (data.streak || data.streak_count)) || 0, 10);
                        queueAlert('watch_streak', {
                            username: (data && (data.username || data.user || data['twitch-username'])) || '',
                            streak: streak
                        });
                    });

                    // Goal started and goal completed. Progress is ignored so the card
                    // does not fire on every tick. Off until the Goals switch is on.
                    function handleGoal(data) {
                        if (!isCategoryEnabled('goals')) return;
                        data = data || {};
                        queueAlert('goals', {
                            description: data.description || '',
                            current_amount: data.current_amount != null ? String(data.current_amount) : '',
                            target_amount: data.target_amount != null ? String(data.target_amount) : '',
                            goal_type: data.goal_type || ''
                        });
                    }
                    socket.on('TWITCH_GOAL_BEGIN', (data) => {
                        console.log('TWITCH_GOAL_BEGIN event received:', data);
                        handleGoal(data);
                    });
                    socket.on('TWITCH_GOAL_END', (data) => {
                        console.log('TWITCH_GOAL_END event received:', data);
                        handleGoal(data);
                    });

                    // TTS - generated audio URL from websocket tts_handler (tts.botofthespecter.com).
                    socket.on('TTS', (data) => {
                        console.log('TTS event received:', data);
                        if (!isPlaybackEnabled('tts')) return;
                        const url = (data && (data.audio_file || data.audio_url || data.url)) || null;
                        if (!url) {
                            console.warn('TTS event missing audio_file:', data);
                            return;
                        }
                        enqueueFxAudio(url, mediaVolume('tts'));
                    });

                    // SOUND_ALERT - full URL already resolved by the bot (media or legacy CDN).
                    socket.on('SOUND_ALERT', (data) => {
                        console.log('SOUND_ALERT event received:', data);
                        if (!isPlaybackEnabled('sound_alerts')) return;
                        const url = (data && data.sound) || null;
                        if (!url) {
                            console.warn('SOUND_ALERT event missing sound:', data);
                            return;
                        }
                        enqueueFxAudio(url, mediaVolume('sound_alerts'));
                    });

                    // VIDEO_ALERT - full URL already resolved by the bot (media or legacy CDN).
                    socket.on('VIDEO_ALERT', (data) => {
                        console.log('VIDEO_ALERT event received:', data);
                        if (!isPlaybackEnabled('video_alerts')) return;
                        const url = (data && data.video) || null;
                        if (!url) {
                            console.warn('VIDEO_ALERT event missing video:', data);
                            return;
                        }
                        enqueueVideo(url);
                    });

                    // Dashboard "Refresh Overlay" - full page reload so PHP re-fetches configs
                    socket.on('OVERLAY_REFRESH', (data) => {
                        console.log('OVERLAY_REFRESH received - reloading browser source', data);
                        const meta = document.createElement('meta');
                        meta.setAttribute('http-equiv', 'refresh');
                        meta.setAttribute('content', '0');
                        document.head.appendChild(meta);
                    });

                    // Log all events
                    socket.onAny((event, ...args) => {
                        if (event.startsWith('CLOSED_CAPTION')) return;
                        console.log(`[onAny] Event: ${event}`, ...args);
                    });
                }
            }).connect();
        });
    </script>
</head>
<body>
    <div id="alertContainer" class="twitch-alert-container"></div>
    <div id="adBreakOverlay" class="twitch-alert-container ad-break-overlay"></div>
    <!-- Legacy overlays folded in (weather, deaths). Walk-ons is audio-only. -->
    <div id="deathOverlay" class="deaths-overlay-page"></div>
    <div id="weatherOverlay" class="weather-overlay-page hide"></div>
    <div id="walkonOverlay" class="walkon-overlay-page"></div>
</body>
</html>