<?php
include '/var/www/config/database.php';
require_once '/var/www/dashboard/includes/pet_templates.php';

$error_html = null;
$user_id = null;
$username = null;
$conn = null;
$user_db = null;
$api_key = null;

$allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
$allowedStatKeys = ['happiness', 'hunger', 'energy', 'xp', 'xp_next'];

$pet_settings = [
    'enabled' => 0,
    'pet_name' => 'Pet',
    'idle_animation' => 'idle',
    'position' => 'bottom-right',
    'scale' => 1.00,
    'flip' => 0,
    'show_stats' => 1,
    'visible_stats' => 'happiness,hunger,energy',
    'bubble_enabled' => 1,
    'decay_happiness' => 2.00,
    'decay_hunger' => 3.00,
    'decay_energy' => 1.00,
];
$pet_animations = [];
$pet_state = [
    'happiness' => 80,
    'hunger' => 80,
    'energy' => 80,
    'level' => 1,
    'xp' => 0,
    'last_interaction_at' => null,
];

function pet_overlay_table_exists($db, $table) {
    if (!$db instanceof mysqli) {
        return false;
    }
    $escaped = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$escaped}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function pet_overlay_stream_is_online($db) {
    if (!pet_overlay_table_exists($db, 'stream_status')) {
        return false;
    }
    $res = $db->query('SELECT status FROM stream_status LIMIT 1');
    if (!($res instanceof mysqli_result)) {
        return false;
    }
    $row = $res->fetch_assoc();
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    return in_array($status, ['true', '1', 'online'], true);
}

function pet_overlay_decay_stat($stored, $rate, $lastUnix, $streamOnline = true) {
    $value = (float) $stored;
    if ($value < 0) {
        $value = 0;
    }
    if ($value > 100) {
        $value = 100;
    }
    $rate = (float) $rate;
    if (!$streamOnline || $lastUnix === null || $rate == 0.0) {
        return $value;
    }
    $hours = (time() - (int) $lastUnix) / 3600.0;
    if ($hours < 0) {
        $hours = 0;
    }
    $current = $value - ($rate * $hours);
    if ($current < 0) {
        $current = 0;
    }
    if ($current > 100) {
        $current = 100;
    }
    return $current;
}

$primary_db_name = 'website';
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
if ($conn->connect_error) {
    die('Connection to primary database failed: ' . $conn->connect_error);
}

if (isset($_GET['code']) && $_GET['code'] !== '') {
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
    $error_html = "<p>Please provide your API key in the URL like this: <strong>pet.php?code=API_KEY</strong></p>"
        . "<p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>";
}

if (!$error_html) {
    $user_db = new mysqli($db_servername, $db_username, $db_password, $username);
    if ($user_db->connect_error) {
        $error_html = 'Connection to user database failed: ' . htmlspecialchars($user_db->connect_error);
        $user_db = null;
    }
}

if ($user_db && pet_overlay_table_exists($user_db, 'pet_settings')) {
    $settingsStmt = $user_db->prepare(
        'SELECT enabled, pet_name, idle_animation, position, scale, flip, show_stats, visible_stats, '
        . 'bubble_enabled, decay_happiness, decay_hunger, decay_energy '
        . 'FROM pet_settings WHERE id = 1'
    );
    if ($settingsStmt && $settingsStmt->execute()) {
        $row = $settingsStmt->get_result()->fetch_assoc();
        if ($row) {
            $pet_settings = array_merge($pet_settings, $row);
        }
        $settingsStmt->close();
    }
}

if ($user_db && pet_overlay_table_exists($user_db, 'pet_animations')) {
    $animRes = $user_db->query(
        'SELECT name, sprite_file, frame_width, frame_height, frame_count, fps, `loop` FROM pet_animations'
    );
    if ($animRes instanceof mysqli_result) {
        while ($row = $animRes->fetch_assoc()) {
            $name = trim((string) $row['name']);
            $spriteFile = (string) $row['sprite_file'];
            $url = pet_resolve_sprite_url($username, $spriteFile);
            $catalogSpec = pet_template_spec_for_sprite($spriteFile);
            $frameWidth = (int) ($catalogSpec['frame_width'] ?? $row['frame_width']);
            $frameHeight = (int) ($catalogSpec['frame_height'] ?? $row['frame_height']);
            if ($name === '' || $url === '' || $frameWidth < 1 || $frameHeight < 1) {
                continue;
            }
            $fps = (int) ($catalogSpec['fps'] ?? $row['fps']);
            if ($fps < 1) {
                $fps = 12;
            }
            if ($fps > 60) {
                $fps = 60;
            }
            $frameCount = (int) ($catalogSpec['frame_count'] ?? $row['frame_count']);
            if ($frameCount < 1) {
                $frameCount = 1;
            }
            $pet_animations[$name] = [
                'name' => $name,
                'url' => $url,
                'kind' => pet_sprite_kind($spriteFile),
                'frame_width' => $frameWidth,
                'frame_height' => $frameHeight,
                'frame_count' => $frameCount,
                'fps' => $fps,
                'loop' => ((int) $row['loop']) ? 1 : 0,
            ];
        }
    }
}

if ($user_db && pet_overlay_table_exists($user_db, 'pet_state')) {
    $stateStmt = $user_db->prepare(
        'SELECT happiness, hunger, energy, level, xp, last_interaction_at FROM pet_state WHERE id = 1'
    );
    if ($stateStmt && $stateStmt->execute()) {
        $row = $stateStmt->get_result()->fetch_assoc();
        if ($row) {
            $pet_state = array_merge($pet_state, $row);
        }
        $stateStmt->close();
    }
}

$position = in_array((string) $pet_settings['position'], $allowedPositions, true)
    ? (string) $pet_settings['position']
    : 'bottom-right';
$scale = (float) $pet_settings['scale'];
if ($scale <= 0 || $scale > 5) {
    $scale = 1.0;
}

$visibleStats = [];
foreach (explode(',', (string) $pet_settings['visible_stats']) as $part) {
    $key = strtolower(trim($part));
    if (in_array($key, $allowedStatKeys, true) && !in_array($key, $visibleStats, true)) {
        $visibleStats[] = $key;
    }
}


$lastUnix = null;
if (!empty($pet_state['last_interaction_at'])) {
    $rawLast = (string) $pet_state['last_interaction_at'];
    if (ctype_digit($rawLast)) {
        $parsed = (int) $rawLast;
    } elseif (substr(strtoupper($rawLast), -1) === 'Z' || strpos($rawLast, '+') !== false) {
        $parsed = strtotime($rawLast);
    } else {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawLast, new DateTimeZone('UTC'));
        $parsed = $dt instanceof DateTimeImmutable ? $dt->getTimestamp() : strtotime($rawLast . ' UTC');
    }
    if ($parsed !== false && $parsed > 0) {
        $lastUnix = (int) $parsed;
    }
}

$happinessStored = max(0, min(100, (float) $pet_state['happiness']));
$hungerStored = max(0, min(100, (float) $pet_state['hunger']));
$energyStored = max(0, min(100, (float) $pet_state['energy']));
$decayHappiness = (float) $pet_settings['decay_happiness'];
$decayHunger = (float) $pet_settings['decay_hunger'];
$decayEnergy = (float) $pet_settings['decay_energy'];
$streamOnline = $user_db ? pet_overlay_stream_is_online($user_db) : false;

$pet_manifest = [
    'settings' => [
        'enabled' => ((int) $pet_settings['enabled']) ? 1 : 0,
        'pet_name' => (string) $pet_settings['pet_name'],
        'idle_animation' => (string) $pet_settings['idle_animation'] !== ''
            ? (string) $pet_settings['idle_animation']
            : 'idle',
        'position' => $position,
        'scale' => $scale,
        'flip' => ((int) $pet_settings['flip']) ? 1 : 0,
        'show_stats' => ((int) $pet_settings['show_stats']) ? 1 : 0,
        'visible_stats' => $visibleStats,
        'bubble_enabled' => ((int) $pet_settings['bubble_enabled']) ? 1 : 0,
        'decay_happiness' => $decayHappiness,
        'decay_hunger' => $decayHunger,
        'decay_energy' => $decayEnergy,
    ],
    'animations' => $pet_animations ? $pet_animations : new stdClass(),
    'state' => [
        'happiness' => pet_overlay_decay_stat($happinessStored, $decayHappiness, $lastUnix, $streamOnline),
        'hunger' => pet_overlay_decay_stat($hungerStored, $decayHunger, $lastUnix, $streamOnline),
        'energy' => pet_overlay_decay_stat($energyStored, $decayEnergy, $lastUnix, $streamOnline),
        'happiness_stored' => $happinessStored,
        'hunger_stored' => $hungerStored,
        'energy_stored' => $energyStored,
        'level' => max(1, (int) $pet_state['level']),
        'xp' => max(0, (int) $pet_state['xp']),
        'last_interaction_at' => $lastUnix,
        'decay_happiness' => $decayHappiness,
        'decay_hunger' => $decayHunger,
        'decay_energy' => $decayEnergy,
        'stream_online' => $streamOnline ? 1 : 0,
    ],
];
$pet_schedules = [];
if ($user_db && pet_overlay_table_exists($user_db, 'pet_schedules')) {
    $schRes = $user_db->query(
        'SELECT id, message, animation, interval_minutes, enabled FROM pet_schedules WHERE enabled = 1 ORDER BY id ASC'
    );
    if ($schRes instanceof mysqli_result) {
        while ($row = $schRes->fetch_assoc()) {
            $msg = trim((string) ($row['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $msg = mb_substr($msg, 0, 56);
            } else {
                $msg = substr($msg, 0, 56);
            }
            $interval = (int) ($row['interval_minutes'] ?? 15);
            if ($interval < 5) {
                $interval = 5;
            }
            if ($interval > 180) {
                $interval = 180;
            }
            $pet_schedules[] = [
                'id' => (int) $row['id'],
                'message' => $msg,
                'animation' => (string) ($row['animation'] ?? 'happy'),
                'interval_minutes' => $interval,
            ];
        }
    }
}
$pet_manifest['schedules'] = $pet_schedules;
$manifestJsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Specter Pet</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script src="js/specter-ws.js"></script>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body class="pet-overlay-page">
    <div class="pet-overlay-page-status" id="connectionStatus" data-state="connecting">Connecting&hellip;</div>
    <div class="pet-overlay-page-root" id="petRoot" data-enabled="false" data-position="bottom-right" data-show-stats="false">
        <div class="pet-overlay-page-stats" id="petStats" hidden>
            <div class="pet-overlay-page-stats-header">
                <span class="pet-overlay-page-name" id="petName">Pet</span>
                <span class="pet-overlay-page-level" id="petLevel">Lv 1</span>
            </div>
            <div class="pet-overlay-page-stat" data-stat="happiness">
                <span class="pet-overlay-page-stat-label">Happy</span>
                <div class="pet-overlay-page-stat-track">
                    <div class="pet-overlay-page-stat-fill" data-stat-fill="happiness"></div>
                </div>
            </div>
            <div class="pet-overlay-page-stat" data-stat="hunger">
                <span class="pet-overlay-page-stat-label">Hunger</span>
                <div class="pet-overlay-page-stat-track">
                    <div class="pet-overlay-page-stat-fill" data-stat-fill="hunger"></div>
                </div>
            </div>
            <div class="pet-overlay-page-stat" data-stat="energy">
                <span class="pet-overlay-page-stat-label">Energy</span>
                <div class="pet-overlay-page-stat-track">
                    <div class="pet-overlay-page-stat-fill" data-stat-fill="energy"></div>
                </div>
            </div>
            <div class="pet-overlay-page-stat" data-stat="xp">
                <span class="pet-overlay-page-stat-label">XP</span>
                <div class="pet-overlay-page-stat-track">
                    <div class="pet-overlay-page-stat-fill" data-stat-fill="xp"></div>
                </div>
                <span class="pet-overlay-page-stat-value" data-stat-value="xp">0</span>
            </div>
            <div class="pet-overlay-page-stat" data-stat="xp_next">
                <span class="pet-overlay-page-stat-label">Next</span>
                <div class="pet-overlay-page-stat-track">
                    <div class="pet-overlay-page-stat-fill" data-stat-fill="xp_next"></div>
                </div>
                <span class="pet-overlay-page-stat-value" data-stat-value="xp_next">100</span>
            </div>
        </div>
        <div class="pet-overlay-page-stage" id="petStage">
            <div class="pet-overlay-page-bubble" id="petBubble" hidden>
                <span class="pet-overlay-page-bubble-text" id="petBubbleText"></span>
            </div>
            <div class="pet-overlay-page-pet" id="petNode">
                <canvas class="pet-overlay-page-canvas" id="petCanvas" width="1" height="1" aria-hidden="true"></canvas>
                <img class="pet-overlay-page-clip" id="petClip" alt="" hidden>
            </div>
        </div>
    </div>
    <script>
        const overlayApiKey = <?php echo json_encode($api_key ?? null, $manifestJsonFlags); ?>;
        const overlayUserName = <?php echo json_encode($username ?? null, $manifestJsonFlags); ?>;
        const petManifest = <?php echo json_encode($pet_manifest, $manifestJsonFlags); ?>;

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
            if (!overlayApiKey) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }
            if (!overlayUserName) {
                showOverlayError('Invalid code provided in the URL', 'danger');
                return;
            }

            const root = document.getElementById('petRoot');
            const petNode = document.getElementById('petNode');
            const canvas = document.getElementById('petCanvas');
            const clip = document.getElementById('petClip');
            const bubble = document.getElementById('petBubble');
            const bubbleText = document.getElementById('petBubbleText');
            const statsEl = document.getElementById('petStats');
            const petNameEl = document.getElementById('petName');
            const petLevelEl = document.getElementById('petLevel');
            const connectionStatus = document.getElementById('connectionStatus');
            if (!root || !petNode || !canvas || !clip || !bubble || !bubbleText || !statsEl) {
                return;
            }
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }

            const allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
            const decayStatKeys = ['happiness', 'hunger', 'energy'];
            const overlayStatKeys = ['happiness', 'hunger', 'energy', 'xp', 'xp_next'];
            const allowedStatKeys = decayStatKeys;
            const XP_PER_LEVEL = 100;
            const XP_MAX_LEVEL = 99;
            const settings = {
                enabled: false,
                petName: 'Pet',
                idleAnimation: 'idle',
                position: 'bottom-right',
                scale: 1,
                flip: false,
                showStats: true,
                visibleStats: overlayStatKeys.slice(),
                bubbleEnabled: true,
            };
            const stats = {
                happiness: 80,
                hunger: 80,
                energy: 80,
                level: 1,
                xp: 0,
                lastInteractionAt: null,
                decayHappiness: 2,
                decayHunger: 3,
                decayEnergy: 1,
                streamOnline: false,
            };
            const sheets = Object.create(null);
            const queue = [];
            let booted = false;
            let playing = null;
            let animName = '';
            let frame = 0;
            let acc = 0;
            let lastTs = 0;
            let rafId = 0;
            let bubbleVisible = false;

            const toNumber = (value, fallback) => {
                const n = Number(value);
                return Number.isFinite(n) ? n : fallback;
            };

            const clampStat = (value) => {
                const n = toNumber(value, 0);
                if (n < 0) return 0;
                if (n > 100) return 100;
                return n;
            };

            const truthy = (value) => value === true || value === 1 || value === '1' || value === 'true' || value === 'True';

            const parseTime = (value) => {
                if (value === null || value === undefined || value === '') return null;
                if (typeof value === 'number' && Number.isFinite(value)) {
                    return value > 1e12 ? value : value * 1000;
                }
                const raw = String(value).trim();
                if (!raw) return null;
                if (/^\d+(\.\d+)?$/.test(raw)) {
                    const n = Number(raw);
                    if (!Number.isFinite(n)) return null;
                    return n > 1e12 ? n : n * 1000;
                }
                const normalized = raw.indexOf('T') === -1 ? raw.replace(' ', 'T') : raw;
                const ms = Date.parse(normalized);
                return Number.isFinite(ms) ? ms : null;
            };

            const unwrapPayload = (data) => {
                if (!data || typeof data !== 'object') return {};
                const out = {};
                Object.keys(data).forEach((key) => {
                    let value = data[key];
                    if (typeof value === 'string') {
                        const trimmed = value.trim();
                        if ((trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}')
                            || (trimmed.charAt(0) === '[' && trimmed.charAt(trimmed.length - 1) === ']')) {
                            try { value = JSON.parse(trimmed); } catch (e) { /* keep string */ }
                        }
                    }
                    out[key] = value;
                });
                return out;
            };

            const isPersonalized = (payload) => {
                if (!payload) return false;
                if (truthy(payload.personalized) || truthy(payload.greeting) || truthy(payload.first_chat)) {
                    return true;
                }
                const triggerValue = String(payload.trigger_value || payload.trigger || '').toLowerCase();
                if (triggerValue === 'first_chat') return true;
                const triggerType = String(payload.trigger_type || '').toLowerCase();
                return triggerType === 'event' && triggerValue === 'first_chat';
            };

            const reactionKey = (item) => {
                const animation = String(item.animation || '');
                if (item.personalized) {
                    return animation + '\0' + String(item.bubble_text || '');
                }
                return animation;
            };

            const resolveAnimName = (name) => {
                const raw = String(name || '');
                if (!raw) return '';
                if (sheets[raw] && sheets[raw].img) return raw;
                const lower = raw.toLowerCase();
                const keys = Object.keys(sheets);
                for (let i = 0; i < keys.length; i++) {
                    if (keys[i].toLowerCase() === lower && sheets[keys[i]].img) {
                        return keys[i];
                    }
                }
                return '';
            };

            const manifestAnimName = (name) => {
                const loaded = resolveAnimName(name);
                if (loaded) return loaded;
                const raw = String(name || '');
                if (!raw) return '';
                const animations = (petManifest && petManifest.animations) || {};
                if (animations[raw]) return raw;
                const lower = raw.toLowerCase();
                const keys = Object.keys(animations);
                for (let i = 0; i < keys.length; i++) {
                    if (keys[i].toLowerCase() === lower) return keys[i];
                }
                return '';
            };

            const truthyFlag = (value) => {
                if (value === true || value === 1) return true;
                const text = String(value == null ? '' : value).trim().toLowerCase();
                return text === '1' || text === 'true' || text === 'online';
            };

            const currentStat = (name) => {
                const stored = clampStat(stats[name]);
                if (!stats.streamOnline) return stored;
                const rateKey = 'decay' + name.charAt(0).toUpperCase() + name.slice(1);
                const rate = toNumber(stats[rateKey], 0);
                if (!stats.lastInteractionAt || !rate) return stored;
                const hours = (Date.now() - stats.lastInteractionAt) / 3600000;
                if (hours <= 0) return stored;
                return clampStat(stored - (rate * hours));
            };

            const applyPetTransform = () => {
                const scale = Math.max(0.1, Math.min(5, toNumber(settings.scale, 1) || 1));
                const flip = settings.flip ? -1 : 1;
                petNode.style.transform = 'scale(' + scale + ') scaleX(' + flip + ')';
            };

            const applyPlacement = () => {
                const position = allowedPositions.indexOf(settings.position) !== -1
                    ? settings.position
                    : 'bottom-right';
                settings.position = position;
                root.dataset.position = position;
                applyPetTransform();
            };

            const setConnectionStatus = (text, state) => {
                if (!connectionStatus) return;
                connectionStatus.textContent = text;
                connectionStatus.dataset.state = state;
            };

            const idleReady = () => !!resolveAnimName(settings.idleAnimation);

            const petVisible = () => settings.enabled && idleReady();

            const applyVisibility = () => {
                const show = petVisible();
                root.dataset.enabled = show ? 'true' : 'false';
                const showStats = show && settings.showStats;
                root.dataset.showStats = showStats ? 'true' : 'false';
                statsEl.hidden = !showStats;
                if (show) {
                    startLoop();
                } else {
                    hideBubble(true);
                    stopLoop();
                    setClip(null, false);
                    if (ctx) {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                    }
                }
            };

            const applyStatVisibility = () => {
                overlayStatKeys.forEach((key) => {
                    const row = statsEl.querySelector('[data-stat="' + key + '"]');
                    if (row) {
                        row.hidden = settings.visibleStats.indexOf(key) === -1;
                    }
                });
            };

            const fillEls = {};
            const valueEls = {};
            overlayStatKeys.forEach((key) => {
                fillEls[key] = statsEl.querySelector('[data-stat-fill="' + key + '"]');
                valueEls[key] = statsEl.querySelector('[data-stat-value="' + key + '"]');
            });
            let lastNameText = '';
            let lastLevelText = '';
            let lastXpText = '';
            let lastXpNextText = '';

            const xpProgress = (total) => {
                const xp = Math.max(0, Math.floor(toNumber(total, 0)));
                const level = Math.min(XP_MAX_LEVEL, 1 + Math.floor(xp / XP_PER_LEVEL));
                if (level >= XP_MAX_LEVEL) {
                    return { xp: xp, level: level, into: XP_PER_LEVEL, toNext: 0 };
                }
                const into = xp % XP_PER_LEVEL;
                return { xp: xp, level: level, into: into, toNext: XP_PER_LEVEL - into };
            };

            const updateStatLabels = () => {
                const name = settings.petName || 'Pet';
                if (petNameEl && name !== lastNameText) {
                    petNameEl.textContent = name;
                    lastNameText = name;
                }
                const progress = xpProgress(stats.xp);
                const levelText = 'Lv ' + progress.level;
                if (petLevelEl && levelText !== lastLevelText) {
                    petLevelEl.textContent = levelText;
                    lastLevelText = levelText;
                }
                if (valueEls.xp) {
                    const xpText = String(progress.xp);
                    if (xpText !== lastXpText) {
                        valueEls.xp.textContent = xpText;
                        lastXpText = xpText;
                    }
                }
                if (valueEls.xp_next) {
                    const nextText = String(progress.toNext);
                    if (nextText !== lastXpNextText) {
                        valueEls.xp_next.textContent = nextText;
                        lastXpNextText = nextText;
                    }
                }
            };

            const updateStatFills = () => {
                const progress = xpProgress(stats.xp);
                overlayStatKeys.forEach((key) => {
                    const fill = fillEls[key];
                    if (!fill) return;
                    let ratio = 0;
                    if (key === 'xp') {
                        ratio = progress.into / XP_PER_LEVEL;
                    } else if (key === 'xp_next') {
                        ratio = progress.toNext / XP_PER_LEVEL;
                    } else {
                        ratio = currentStat(key) / 100;
                    }
                    fill.style.transform = 'scaleX(' + ratio + ')';
                });
            };

            const updateStats = () => {
                updateStatLabels();
                updateStatFills();
            };

            const hideBubble = (immediate) => {
                if (!bubbleVisible && bubble.hidden) return;
                bubbleVisible = false;
                bubble.classList.remove('is-visible');
                if (immediate) {
                    bubble.classList.remove('is-hiding');
                    bubble.hidden = true;
                    bubbleText.textContent = '';
                    return;
                }
                bubble.classList.add('is-hiding');
            };

            const BUBBLE_MAX_CHARS = 56;
            const bubbleHoldMs = (text) => {
                const n = String(text || '').trim().length;
                if (!n) return 0;
                return Math.min(9000, Math.max(5000, 3200 + n * 80));
            };

            const showBubble = (text) => {
                if (!settings.bubbleEnabled) {
                    hideBubble(true);
                    return;
                }
                let clean = String(text || '').replace(/\s+/g, ' ').trim();
                if (!clean) {
                    hideBubble(true);
                    return;
                }
                if (clean.length > BUBBLE_MAX_CHARS) {
                    clean = clean.slice(0, BUBBLE_MAX_CHARS);
                }
                bubble.hidden = false;
                bubble.classList.remove('is-hiding');
                bubbleText.textContent = clean;
                bubble.classList.add('is-visible');
                bubbleVisible = true;
            };

            bubble.addEventListener('animationend', (event) => {
                if (event.animationName !== 'fadeOut') return;
                if (!bubbleVisible) {
                    bubble.classList.remove('is-hiding');
                    bubble.hidden = true;
                    bubbleText.textContent = '';
                }
            });

            const frameRect = (entry, index) => {
                const img = entry.img;
                const fw = entry.frame_width;
                const fh = entry.frame_height;
                const cols = Math.max(1, Math.floor((img.naturalWidth || img.width) / fw) || 1);
                const i = Math.max(0, index);
                return {
                    sx: (i % cols) * fw,
                    sy: Math.floor(i / cols) * fh,
                    sw: fw,
                    sh: fh,
                };
            };

            const isClip = (entry) => !!(entry && (entry.kind === 'gif' || (entry.url && /\.gif(\?|#|$)/i.test(entry.url))));

            const setClip = (entry, restart) => {
                if (!entry || !isClip(entry)) {
                    clip.hidden = true;
                    if (clip.getAttribute('src')) {
                        clip.removeAttribute('src');
                    }
                    clip.dataset.url = '';
                    canvas.hidden = false;
                    return;
                }
                canvas.hidden = true;
                clip.hidden = false;
                const w = Math.max(1, entry.frame_width || 1);
                clip.style.width = w + 'px';
                const url = entry.url || '';
                if (!url) return;
                if (restart || clip.dataset.url !== url) {
                    clip.dataset.url = url;
                    clip.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
                }
            };

            const syncCanvasSize = (entry) => {
                const dpr = window.devicePixelRatio || 1;
                const fw = entry.frame_width;
                const fh = entry.frame_height;
                const pixelW = Math.max(1, Math.round(fw * dpr));
                const pixelH = Math.max(1, Math.round(fh * dpr));
                if (canvas.width !== pixelW || canvas.height !== pixelH) {
                    canvas.width = pixelW;
                    canvas.height = pixelH;
                }
                canvas.style.width = fw + 'px';
                canvas.style.height = 'auto';
                canvas.style.aspectRatio = fw + ' / ' + fh;
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            };

            const draw = () => {
                const name = resolveAnimName(animName || settings.idleAnimation);
                const entry = name ? sheets[name] : null;
                if (!entry || !entry.img || !petVisible()) {
                    setClip(null, false);
                    ctx.setTransform(1, 0, 0, 1, 0, 0);
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    return;
                }
                if (isClip(entry)) {
                    setClip(entry, false);
                    ctx.setTransform(1, 0, 0, 1, 0, 0);
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    return;
                }
                setClip(null, false);
                syncCanvasSize(entry);
                const rect = frameRect(entry, frame);
                ctx.imageSmoothingEnabled = false;
                ctx.clearRect(0, 0, entry.frame_width, entry.frame_height);
                ctx.drawImage(
                    entry.img,
                    rect.sx, rect.sy, rect.sw, rect.sh,
                    0, 0, entry.frame_width, entry.frame_height
                );
            };

            const startIdle = () => {
                playing = null;
                animName = resolveAnimName(settings.idleAnimation);
                frame = 0;
                acc = 0;
                hideBubble(false);
                const entry = animName ? sheets[animName] : null;
                if (entry && isClip(entry)) {
                    setClip(entry, true);
                }
            };

            const startReaction = (item) => {
                const name = resolveAnimName(item.animation);
                if (!name) {
                    playing = null;
                    pumpQueue();
                    return;
                }
                const bubbleTextValue = item.bubble_text ? String(item.bubble_text) : '';
                playing = {
                    animation: item.animation,
                    bubble_text: bubbleTextValue,
                    personalized: !!item.personalized,
                    startedAt: performance.now(),
                    holdMs: bubbleHoldMs(bubbleTextValue),
                };
                animName = name;
                frame = 0;
                acc = 0;
                showBubble(bubbleTextValue);
                const entry = sheets[name];
                if (entry && isClip(entry)) {
                    setClip(entry, true);
                }
            };

            const maybeHideBubble = (entry) => {
                if (!bubbleVisible || !playing) return;
                if (playing.holdMs > 0) {
                    const left = playing.holdMs - (performance.now() - playing.startedAt);
                    if (left <= 450) {
                        hideBubble(false);
                    }
                    return;
                }
                const fps = Math.max(1, entry.fps);
                const remainingMs = (Math.max(1, entry.frame_count) - frame) * (1000 / fps);
                const cycleMs = Math.max(1, entry.frame_count) * (1000 / fps);
                if (cycleMs > 500 && remainingMs <= 250) {
                    hideBubble(false);
                }
            };

            const endReaction = () => {
                playing = null;
                hideBubble(false);
                if (!pumpQueue()) {
                    startIdle();
                }
            };

            const pumpQueue = () => {
                if (playing) return true;
                while (queue.length) {
                    const next = queue.shift();
                    if (resolveAnimName(next.animation)) {
                        startReaction(next);
                        return true;
                    }
                }
                return false;
            };

            const enqueueReaction = (payload) => {
                if (!settings.enabled) return;
                if (booted && !idleReady()) return;
                const animation = manifestAnimName(payload.animation);
                if (!animation) return;
                const item = {
                    animation: animation,
                    bubble_text: payload.bubble_text ? String(payload.bubble_text) : '',
                    personalized: !!payload.personalized,
                };
                const key = reactionKey(item);
                if (playing && reactionKey(playing) === key) return;
                for (let i = 0; i < queue.length; i++) {
                    if (reactionKey(queue[i]) === key) return;
                }
                queue.push(item);
                if (idleReady()) {
                    pumpQueue();
                }
            };

            const step = (dt) => {
                if (!petVisible()) return;
                const name = resolveAnimName(animName || settings.idleAnimation);
                const entry = name ? sheets[name] : null;
                if (!entry) return;
                const fps = Math.max(1, entry.fps);
                const interval = 1000 / fps;
                acc += dt;
                let advances = 0;
                while (acc >= interval && advances < 3) {
                    acc -= interval;
                    advances += 1;
                    frame += 1;
                    if (playing) {
                        maybeHideBubble(entry);
                        if (frame >= Math.max(1, entry.frame_count)) {
                            const hold = playing.holdMs || 0;
                            const elapsed = performance.now() - (playing.startedAt || 0);
                            if (hold > 0 && elapsed < hold) {
                                frame = 0;
                                acc = 0;
                            } else {
                                acc = 0;
                                endReaction();
                                return;
                            }
                        }
                    } else if (frame >= Math.max(1, entry.frame_count)) {
                        frame = 0;
                    }
                }
                if (acc > interval * 2) {
                    acc = 0;
                }
            };

            const loop = (now) => {
                rafId = requestAnimationFrame(loop);
                let dt = now - lastTs;
                lastTs = now;
                if (dt < 0) dt = 0;
                if (dt > 100) dt = 100;
                step(dt);
                draw();
                if (settings.showStats) {
                    updateStatFills();
                }
            };

            const startLoop = () => {
                if (rafId) return;
                lastTs = performance.now();
                rafId = requestAnimationFrame(loop);
            };

            const stopLoop = () => {
                if (!rafId) return;
                cancelAnimationFrame(rafId);
                rafId = 0;
            };

            const applySettingsFromManifest = (manifest) => {
                const s = (manifest && manifest.settings) || {};
                settings.enabled = !!s.enabled;
                settings.petName = String(s.pet_name || 'Pet');
                settings.idleAnimation = String(s.idle_animation || 'idle');
                settings.position = allowedPositions.indexOf(s.position) !== -1 ? s.position : 'bottom-right';
                settings.scale = toNumber(s.scale, 1) || 1;
                settings.flip = !!s.flip;
                settings.showStats = s.show_stats === undefined ? true : truthyFlag(s.show_stats);
                const visible = Array.isArray(s.visible_stats) ? s.visible_stats : String(s.visible_stats || '').split(',');
                settings.visibleStats = [];
                visible.forEach((part) => {
                    const key = String(part || '').trim().toLowerCase();
                    if (overlayStatKeys.indexOf(key) !== -1 && settings.visibleStats.indexOf(key) === -1) {
                        settings.visibleStats.push(key);
                    }
                });
                settings.bubbleEnabled = s.bubble_enabled === undefined ? true : !!s.bubble_enabled;
                applyPlacement();
                applyStatVisibility();
            };

            const applyStatePayload = (raw) => {
                const p = unwrapPayload(raw);
                const nested = (p.decay_rates && typeof p.decay_rates === 'object') ? p.decay_rates : null;
                const readStored = (name) => {
                    const storedKey = name + '_stored';
                    if (p[storedKey] != null && p[storedKey] !== '') {
                        return clampStat(p[storedKey]);
                    }
                    if (p[name] != null && p[name] !== '') {
                        return clampStat(p[name]);
                    }
                    return null;
                };
                allowedStatKeys.forEach((key) => {
                    const value = readStored(key);
                    if (value !== null) stats[key] = value;
                });
                if (p.level != null && p.level !== '') {
                    stats.level = Math.max(1, Math.floor(toNumber(p.level, stats.level)));
                }
                if (p.xp != null && p.xp !== '') {
                    stats.xp = Math.max(0, Math.floor(toNumber(p.xp, stats.xp)));
                }
                if (p.last_interaction_at !== undefined) {
                    stats.lastInteractionAt = parseTime(p.last_interaction_at);
                }
                const decayH = p.decay_happiness != null ? p.decay_happiness
                    : (nested && nested.happiness != null ? nested.happiness : null);
                const decayU = p.decay_hunger != null ? p.decay_hunger
                    : (nested && nested.hunger != null ? nested.hunger : null);
                const decayE = p.decay_energy != null ? p.decay_energy
                    : (nested && nested.energy != null ? nested.energy : null);
                if (decayH != null) stats.decayHappiness = toNumber(decayH, stats.decayHappiness);
                if (decayU != null) stats.decayHunger = toNumber(decayU, stats.decayHunger);
                if (decayE != null) stats.decayEnergy = toNumber(decayE, stats.decayEnergy);
                if (p.stream_online !== undefined) {
                    stats.streamOnline = truthyFlag(p.stream_online);
                }
                updateStats();
            };

            const handlePetReact = (raw) => {
                const p = unwrapPayload(raw);
                const animation = String(p.animation || p.anim || '').trim();
                if (!animation) return;
                const bubbleValue = p.bubble_text != null ? p.bubble_text
                    : (p.bubble != null ? p.bubble : '');
                enqueueReaction({
                    animation: animation,
                    bubble_text: bubbleValue == null ? '' : String(bubbleValue),
                    personalized: isPersonalized(p),
                });
            };

            const reloadOverlay = (reason, data) => {
                console.log(reason + ' received - reloading', data);
                window.location.reload();
            };

            const preloadManifest = async (manifest) => {
                const animations = (manifest && manifest.animations) || {};
                const names = Object.keys(animations);
                await Promise.all(names.map((name) => {
                    const spec = animations[name] || {};
                    const url = spec.url || '';
                    if (!url) return Promise.resolve();
                    return new Promise((resolve) => {
                        const img = new Image();
                        img.onload = () => {
                            sheets[name] = {
                                name: name,
                                img: img,
                                url: url,
                                kind: spec.kind === 'gif' || /\.gif(\?|#|$)/i.test(url) ? 'gif' : 'sheet',
                                frame_width: Math.max(1, toNumber(spec.frame_width, 1)),
                                frame_height: Math.max(1, toNumber(spec.frame_height, 1)),
                                frame_count: Math.max(1, toNumber(spec.frame_count, 1)),
                                fps: Math.max(1, Math.min(60, toNumber(spec.fps, 12))),
                                loop: spec.loop ? 1 : 0,
                            };
                            resolve();
                        };
                        img.onerror = () => resolve();
                        img.src = url;
                    });
                }));
            };

            const startScheduleTicker = () => {
                const list = Array.isArray(petManifest && petManifest.schedules) ? petManifest.schedules : [];
                if (!list.length) return;
                const lastFire = {};
                const bootAt = performance.now();
                list.forEach((row) => {
                    lastFire[row.id] = bootAt;
                });
                window.setInterval(() => {
                    if (!petVisible() || !settings.bubbleEnabled) return;
                    const now = performance.now();
                    list.forEach((row) => {
                        const minutes = Math.max(5, Number(row.interval_minutes) || 15);
                        const waitMs = minutes * 60 * 1000;
                        if (now - (lastFire[row.id] || bootAt) < waitMs) return;
                        lastFire[row.id] = now;
                        const text = String(row.message || '').trim();
                        if (!text) return;
                        enqueueReaction({
                            animation: manifestAnimName(row.animation) || settings.idleAnimation,
                            bubble_text: text,
                            personalized: true,
                        });
                    });
                }, 5000);
            };

            const boot = async () => {
                applySettingsFromManifest(petManifest);
                applyStatePayload((petManifest && petManifest.state) || {});
                await preloadManifest(petManifest);
                startIdle();
                booted = true;
                applyVisibility();
                updateStats();
                draw();
                if (idleReady()) {
                    pumpQueue();
                } else {
                    queue.length = 0;
                }
                startScheduleTicker();
            };

            const session = SpecterOverlayWS.create({
                code: overlayApiKey,
                channel: 'Overlay',
                name: 'Pet Overlay',
                onStatus: setConnectionStatus,
                bind: (socket) => {
                    socket.on('PET_REACT', handlePetReact);
                    socket.on('PET_STATE', applyStatePayload);
                    socket.on('STREAM_ONLINE', () => { stats.streamOnline = true; });
                    socket.on('STREAM_OFFLINE', () => {
                        allowedStatKeys.forEach((key) => { stats[key] = currentStat(key); });
                        stats.lastInteractionAt = Date.now();
                        stats.streamOnline = false;
                    });
                    socket.on('PET_SETTINGS_UPDATE', (data) => reloadOverlay('PET_SETTINGS_UPDATE', data));
                    socket.on('OVERLAY_REFRESH', (data) => reloadOverlay('OVERLAY_REFRESH', data));
                },
            });
            boot();
            session.connect();
        })();
    </script>
</body>
</html>
