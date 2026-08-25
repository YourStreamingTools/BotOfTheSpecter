<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

require_once '/var/www/config/db_connect.php';
include 'includes/userdata.php';
include 'includes/mod_access.php';
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// Storage scan is the heavy work; paint the bar skeleton first, then fetch.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    include 'includes/storage_used.php';
    $petMediaDir = rtrim($media_path, '/\\') . '/pet';
    ensureDirectoryWritable($petMediaDir);
    echo json_encode([
        'success' => true,
        'storage_used' => (int) $current_storage_used,
        'max_storage' => (int) $max_storage_size,
        'storage_percentage' => (float) $storage_percentage,
    ]);
    exit();
}

include 'includes/file_paths.php';
require_once __DIR__ . '/includes/upload_helpers.php';

$pageTitle = t('pet_page_title');

$overlayLink = 'https://overlay.botofthespecter.com/pet.php';
$overlayLinkWithCode = $overlayLink . '?code=' . rawurlencode($api_key);
$overlayLinkMasked = $overlayLink . '?code=' . str_repeat('•', 24);

$petImageExts = ['png', 'webp'];
$petMediaDir = rtrim($media_path, '/\\') . '/pet';
$petMediaUrl = 'https://media.botofthespecter.com/' . rawurlencode($username) . '/pet/';
const PET_IMAGE_MIN_PX = 128;
const PET_IMAGE_MAX_PX = 4096;
const PET_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const PET_FRAME_COUNT_MAX = 64;

$petAllowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
$petTriggerTypes = ['chat_keyword', 'command', 'redemption', 'event', 'interaction'];
$petEventValues = ['follow', 'sub', 'raid', 'cheer', 'first_chat'];
$petInteractionValues = ['feed', 'play', 'sad', 'sleep'];
$petStatKeys = ['happiness', 'hunger', 'energy', 'xp', 'xp_next'];
$petStandardAnims = ['idle', 'happy', 'hype', 'sad', 'sleep', 'eat'];

$petNotifyApiKey = (isset($api_key) && $api_key) ? $api_key : (string) ($_SESSION['api_key'] ?? '');

function pet_json_exit(array $payload) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function pet_table_exists($db, $name) {
    $name = (string) $name;
    if (!preg_match('/^[a-z_]+$/', $name)) {
        return false;
    }
    $res = $db->query("SHOW TABLES LIKE '{$name}'");
    return $res && $res->num_rows > 0;
}

function pet_tables_ready($db) {
    foreach (['pet_settings', 'pet_animations', 'pet_triggers', 'pet_state'] as $table) {
        if (!pet_table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

function pet_notify_event($apiKey, $event, array $extra = []) {
    $apiKey = (string) $apiKey;
    $event = (string) $event;
    if ($apiKey === '' || $event === '') {
        return false;
    }
    $payload = array_merge(['event' => $event, 'api_key' => $apiKey], $extra);
    if (function_exists('curl_init')) {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'dashboard.botofthespecter.com';
        $notifyUrl = 'https://' . $host . '/api/notify_event.php';
        $ch = curl_init($notifyUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        if (!empty($_COOKIE) && is_array($_COOKIE)) {
            $pairs = [];
            foreach ($_COOKIE as $ck => $cv) {
                if (is_string($ck) && is_scalar($cv)) {
                    $pairs[] = $ck . '=' . $cv;
                }
            }
            if ($pairs) {
                curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
            }
        }
        $out = curl_exec($ch);
        curl_close($ch);
        $decoded = is_string($out) ? json_decode($out, true) : null;
        if (is_array($decoded) && !empty($decoded['success'])) {
            return true;
        }
    }
    $wsUrl = 'https://websocket.botofthespecter.com/notify?code=' . rawurlencode($apiKey) . '&event=' . rawurlencode($event);
    if ($extra) {
        $wsUrl .= '&' . http_build_query($extra);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($wsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_exec($ch);
        $ok = curl_errno($ch) === 0;
        curl_close($ch);
        return $ok;
    }
    @file_get_contents($wsUrl);
    return true;
}

function pet_sanitize_anim_name($raw) {
    $name = strtolower(trim((string) $raw));
    $name = preg_replace('/[^a-z0-9_-]+/', '-', $name);
    $name = trim((string) $name, '-_');
    if (strlen($name) > 50) {
        $name = substr($name, 0, 50);
    }
    return $name;
}

function pet_normalize_trigger_value($type, $value, array $eventValues, array $interactionValues) {
    $value = trim((string) $value);
    if ($type === 'chat_keyword') {
        $value = mb_strtolower($value);
    } elseif ($type === 'command') {
        $value = ltrim(mb_strtolower($value), '!');
    } elseif ($type === 'event') {
        $value = mb_strtolower($value);
        if (!in_array($value, $eventValues, true)) {
            return '';
        }
    } elseif ($type === 'interaction') {
        $value = mb_strtolower($value);
        if (!in_array($value, $interactionValues, true)) {
            return '';
        }
    }
    if (strlen($value) > 100) {
        $value = substr($value, 0, 100);
    }
    return $value;
}

function pet_seed_default_triggers($db) {
    $countRes = $db->query('SELECT COUNT(*) AS c FROM pet_triggers');
    $row = $countRes ? $countRes->fetch_assoc() : null;
    if (!$row || (int) $row['c'] > 0) {
        return false;
    }
    $seeds = [
        ['event', 'follow', 'hype', '', 0, 0, 0, 0],
        ['event', 'sub', 'hype', '', 0, 0, 0, 0],
        ['event', 'raid', 'hype', '', 0, 0, 0, 0],
        ['event', 'cheer', 'hype', '', 0, 0, 0, 0],
        ['event', 'first_chat', 'happy', 'Hi {user}!', 0, 0, 0, 0],
        ['interaction', 'feed', 'eat', '', 0, 15, 0, 0],
        ['interaction', 'play', 'happy', '', 10, 0, -5, 0],
        ['interaction', 'sad', 'sad', '', 0, 0, 0, 0],
        ['interaction', 'sleep', 'sleep', '', 0, 0, 15, 0],
        ['chat_keyword', 'pog', 'hype', '', 0, 0, 0, 0],
    ];
    $stmt = $db->prepare(
        'INSERT INTO pet_triggers (trigger_type, trigger_value, animation, bubble_text, effect_happiness, effect_hunger, effect_energy, xp, cooldown_seconds, enabled) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5, 1)'
    );
    if (!$stmt) {
        return false;
    }
    foreach ($seeds as $seed) {
        $seedType = $seed[0];
        $seedValue = $seed[1];
        $seedAnim = $seed[2];
        $seedBubble = $seed[3];
        $seedHappiness = (int) $seed[4];
        $seedHunger = (int) $seed[5];
        $seedEnergy = (int) $seed[6];
        $seedXp = (int) $seed[7];
        $stmt->bind_param(
            'ssssiiii',
            $seedType,
            $seedValue,
            $seedAnim,
            $seedBubble,
            $seedHappiness,
            $seedHunger,
            $seedEnergy,
            $seedXp
        );
        $stmt->execute();
    }
    $stmt->close();
    return true;
}

function pet_ensure_interaction_triggers($db) {
    $defaults = [
        ['feed', 'eat', '', 0, 15, 0, 0],
        ['play', 'happy', '', 10, 0, -5, 0],
        ['sad', 'sad', '', 0, 0, 0, 0],
        ['sleep', 'sleep', '', 0, 0, 15, 0],
    ];
    $check = $db->prepare("SELECT id FROM pet_triggers WHERE trigger_type = 'interaction' AND trigger_value = ? LIMIT 1");
    $ins = $db->prepare(
        'INSERT INTO pet_triggers (trigger_type, trigger_value, animation, bubble_text, effect_happiness, effect_hunger, effect_energy, xp, cooldown_seconds, enabled) '
        . "VALUES ('interaction', ?, ?, ?, ?, ?, ?, ?, 5, 1)"
    );
    if (!$check || !$ins) {
        if ($check) {
            $check->close();
        }
        if ($ins) {
            $ins->close();
        }
        return false;
    }
    foreach ($defaults as $row) {
        $value = $row[0];
        $check->bind_param('s', $value);
        if (!$check->execute()) {
            continue;
        }
        $res = $check->get_result();
        $exists = $res && $res->fetch_assoc();
        if ($res) {
            $res->free();
        }
        if ($exists) {
            continue;
        }
        $anim = $row[1];
        $bubble = $row[2];
        $happiness = (int) $row[3];
        $hunger = (int) $row[4];
        $energy = (int) $row[5];
        $xp = (int) $row[6];
        $ins->bind_param('sssiiii', $value, $anim, $bubble, $happiness, $hunger, $energy, $xp);
        $ins->execute();
    }
    $check->close();
    $ins->close();
    return true;
}

function pet_clamp_stat($value) {
    $n = (int) round((float) $value);
    if ($n < 0) {
        return 0;
    }
    if ($n > 100) {
        return 100;
    }
    return $n;
}

function pet_stream_is_online($db) {
    if (!($db instanceof mysqli) || !pet_table_exists($db, 'stream_status')) {
        return false;
    }
    $res = $db->query('SELECT status FROM stream_status LIMIT 1');
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    $res->free();
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    return in_array($status, ['true', '1', 'online'], true);
}

function pet_decay_hours($lastAt, $streamOnline = true) {
    if (!$streamOnline) {
        return 0.0;
    }
    if ($lastAt === null || $lastAt === '') {
        return 0.0;
    }
    $ts = strtotime((string) $lastAt);
    if ($ts === false) {
        return 0.0;
    }
    $hours = (time() - $ts) / 3600.0;
    return $hours < 0 ? 0.0 : $hours;
}

function pet_settings_decay($db) {
    $rates = ['happiness' => 2.0, 'hunger' => 3.0, 'energy' => 1.0];
    $res = $db->query('SELECT decay_happiness, decay_hunger, decay_energy FROM pet_settings WHERE id = 1');
    if ($res && ($row = $res->fetch_assoc())) {
        $rates['happiness'] = (float) ($row['decay_happiness'] ?? 2);
        $rates['hunger'] = (float) ($row['decay_hunger'] ?? 3);
        $rates['energy'] = (float) ($row['decay_energy'] ?? 1);
    }
    return $rates;
}

function pet_state_payload($happiness, $hunger, $energy, $level, $xp, $lastIso, array $rates, $streamOnline = false) {
    return [
        'happiness' => (int) $happiness,
        'hunger' => (int) $hunger,
        'energy' => (int) $energy,
        'level' => (int) $level,
        'xp' => (int) $xp,
        'last_interaction_at' => (string) $lastIso,
        'decay_happiness' => $rates['happiness'],
        'decay_hunger' => $rates['hunger'],
        'decay_energy' => $rates['energy'],
        'decay_rates' => json_encode([
            'happiness' => $rates['happiness'],
            'hunger' => $rates['hunger'],
            'energy' => $rates['energy'],
        ]),
        'stream_online' => $streamOnline ? '1' : '0',
    ];
}

function pet_apply_state_and_notify($db, $apiKey, $effectHappiness, $effectHunger, $effectEnergy, $xpAdd) {
    $rates = pet_settings_decay($db);
    $happiness = 80;
    $hunger = 80;
    $energy = 80;
    $xp = 0;
    $lastAt = null;
    $res = $db->query('SELECT happiness, hunger, energy, xp, last_interaction_at FROM pet_state WHERE id = 1');
    if ($res && ($row = $res->fetch_assoc())) {
        $happiness = $row['happiness'] !== null ? (float) $row['happiness'] : 80;
        $hunger = $row['hunger'] !== null ? (float) $row['hunger'] : 80;
        $energy = $row['energy'] !== null ? (float) $row['energy'] : 80;
        $xp = max(0, (int) ($row['xp'] ?? 0));
        $lastAt = $row['last_interaction_at'];
    }
    $hours = pet_decay_hours($lastAt, pet_stream_is_online($db));
    $happiness = pet_clamp_stat($happiness - $rates['happiness'] * $hours + (int) $effectHappiness);
    $hunger = pet_clamp_stat($hunger - $rates['hunger'] * $hours + (int) $effectHunger);
    $energy = pet_clamp_stat($energy - $rates['energy'] * $hours + (int) $effectEnergy);
    $xp = max(0, $xp + (int) $xpAdd);
    $level = min(99, 1 + intdiv($xp, 100));
    $now = gmdate('Y-m-d H:i:s');
    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $db->prepare(
        'INSERT INTO pet_state (id, happiness, hunger, energy, level, xp, last_interaction_at) VALUES (1, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE happiness = VALUES(happiness), hunger = VALUES(hunger), energy = VALUES(energy), '
        . 'level = VALUES(level), xp = VALUES(xp), last_interaction_at = VALUES(last_interaction_at)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiiiis', $happiness, $hunger, $energy, $level, $xp, $now);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return false;
    }
    pet_notify_event($apiKey, 'PET_STATE', pet_state_payload($happiness, $hunger, $energy, $level, $xp, $nowIso, $rates, pet_stream_is_online($db)));
    return true;
}

function pet_sprite_url($petMediaUrl, $filename) {
    $filename = basename((string) $filename);
    if ($filename === '' || strpos($filename, '..') !== false) {
        return '';
    }
    return $petMediaUrl . rawurlencode($filename);
}

function pet_unlink_if_unused($db, $petMediaDir, $filename) {
    $filename = basename((string) $filename);
    if ($filename === '' || strpos($filename, '..') !== false) {
        return;
    }
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM pet_animations WHERE sprite_file = ?');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $filename);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row && (int) $row['c'] > 0) {
        return;
    }
    $path = rtrim($petMediaDir, '/\\') . '/' . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

$pet = [
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
    'start_happiness' => 80,
    'start_hunger' => 80,
    'start_energy' => 80,
];
$petAnimations = [];
$petTriggers = [];
$petRewards = [];
$petState = [
    'happiness' => 80,
    'hunger' => 80,
    'energy' => 80,
    'level' => 1,
    'xp' => 0,
    'last_interaction_at' => null,
];

if (pet_table_exists($db, 'pet_settings')) {
    $st = $db->prepare(
        'SELECT enabled, pet_name, idle_animation, position, scale, flip, show_stats, visible_stats, bubble_enabled, '
        . 'decay_happiness, decay_hunger, decay_energy, start_happiness, start_hunger, start_energy FROM pet_settings WHERE id = 1'
    );
    if (!$st) {
        $st = $db->prepare(
            'SELECT enabled, pet_name, idle_animation, position, scale, flip, show_stats, visible_stats, bubble_enabled, '
            . 'decay_happiness, decay_hunger, decay_energy FROM pet_settings WHERE id = 1'
        );
    }
    if ($st) {
        $st->execute();
        $res = $st->get_result();
        if ($res && $res->num_rows > 0) {
            $pet = array_merge($pet, $res->fetch_assoc());
        }
        $st->close();
    }
}
if (!in_array((string) ($pet['position'] ?? ''), $petAllowedPositions, true)) {
    $pet['position'] = 'bottom-right';
}
$pet['scale'] = max(0.25, min(3.0, (float) ($pet['scale'] ?? 1)));
$pet['enabled'] = ((int) ($pet['enabled'] ?? 0)) === 1 ? 1 : 0;
$pet['flip'] = ((int) ($pet['flip'] ?? 0)) === 1 ? 1 : 0;
$pet['show_stats'] = ((int) ($pet['show_stats'] ?? 1)) === 1 ? 1 : 0;
$pet['bubble_enabled'] = ((int) ($pet['bubble_enabled'] ?? 1)) === 1 ? 1 : 0;
$pet['start_happiness'] = pet_clamp_stat($pet['start_happiness'] ?? 80);
$pet['start_hunger'] = pet_clamp_stat($pet['start_hunger'] ?? 80);
$pet['start_energy'] = pet_clamp_stat($pet['start_energy'] ?? 80);

$visibleStats = [];
foreach (explode(',', (string) ($pet['visible_stats'] ?? '')) as $stat) {
    $stat = strtolower(trim($stat));
    if (in_array($stat, $petStatKeys, true) && !in_array($stat, $visibleStats, true)) {
        $visibleStats[] = $stat;
    }
}


if (pet_table_exists($db, 'pet_animations')) {
    $anRes = $db->query(
        'SELECT id, name, sprite_file, frame_width, frame_height, frame_count, fps, `loop` FROM pet_animations ORDER BY name ASC'
    );
    if ($anRes) {
        while ($row = $anRes->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['frame_width'] = (int) $row['frame_width'];
            $row['frame_height'] = (int) $row['frame_height'];
            $row['frame_count'] = (int) $row['frame_count'];
            $row['fps'] = (int) $row['fps'];
            $row['loop'] = ((int) $row['loop']) === 1 ? 1 : 0;
            $row['url'] = pet_sprite_url($petMediaUrl, $row['sprite_file']);
            $petAnimations[] = $row;
        }
        $anRes->free();
    }
}

if (pet_table_exists($db, 'pet_triggers')) {
    pet_ensure_interaction_triggers($db);
    $trRes = $db->query(
        'SELECT id, trigger_type, trigger_value, animation, bubble_text, effect_happiness, effect_hunger, effect_energy, xp, cooldown_seconds, enabled '
        . 'FROM pet_triggers ORDER BY trigger_type ASC, id ASC'
    );
    if ($trRes) {
        while ($row = $trRes->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['effect_happiness'] = (int) $row['effect_happiness'];
            $row['effect_hunger'] = (int) $row['effect_hunger'];
            $row['effect_energy'] = (int) $row['effect_energy'];
            $row['xp'] = (int) $row['xp'];
            $row['cooldown_seconds'] = (int) $row['cooldown_seconds'];
            $row['enabled'] = ((int) $row['enabled']) === 1 ? 1 : 0;
            $petTriggers[] = $row;
        }
        $trRes->free();
    }
}

if (pet_table_exists($db, 'pet_state')) {
    $stRes = $db->query('SELECT happiness, hunger, energy, level, xp, last_interaction_at FROM pet_state WHERE id = 1');
    if ($stRes && ($stRow = $stRes->fetch_assoc())) {
        $petState['happiness'] = pet_clamp_stat($stRow['happiness'] ?? 80);
        $petState['hunger'] = pet_clamp_stat($stRow['hunger'] ?? 80);
        $petState['energy'] = pet_clamp_stat($stRow['energy'] ?? 80);
        $petState['level'] = max(1, (int) ($stRow['level'] ?? 1));
        $petState['xp'] = max(0, (int) ($stRow['xp'] ?? 0));
        $petState['last_interaction_at'] = $stRow['last_interaction_at'];
        $stRes->free();
    }
}

if (pet_table_exists($db, 'channel_point_rewards')) {
    $rwRes = $db->query('SELECT reward_id, reward_title FROM channel_point_rewards ORDER BY reward_title ASC');
    if ($rwRes) {
        while ($row = $rwRes->fetch_assoc()) {
            $petRewards[] = [
                'reward_id' => (string) $row['reward_id'],
                'reward_title' => (string) $row['reward_title'],
            ];
        }
        $rwRes->free();
    }
}

$petAnimNames = [];
foreach ($petStandardAnims as $stdName) {
    $petAnimNames[$stdName] = true;
}
foreach ($petAnimations as $animRow) {
    $petAnimNames[(string) $animRow['name']] = true;
}
if (!empty($pet['idle_animation'])) {
    $petAnimNames[(string) $pet['idle_animation']] = true;
}
$petAnimNameList = array_keys($petAnimNames);
sort($petAnimNameList);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pet_action'])) {
    $action = (string) $_POST['pet_action'];
    if (!pet_tables_ready($db)) {
        pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
    }

    if ($action === 'save_settings') {
        $petName = trim((string) ($_POST['pet_name'] ?? 'Pet'));
        if ($petName === '') {
            $petName = 'Pet';
        }
        if (mb_strlen($petName) > 50) {
            $petName = mb_substr($petName, 0, 50);
        }
        $idleAnimation = pet_sanitize_anim_name($_POST['idle_animation'] ?? 'idle');
        if ($idleAnimation === '' || !in_array($idleAnimation, $petAnimNameList, true)) {
            $idleAnimation = 'idle';
        }
        $position = in_array($_POST['position'] ?? '', $petAllowedPositions, true) ? $_POST['position'] : 'bottom-right';
        $scale = max(0.25, min(3.0, (float) ($_POST['scale'] ?? 1)));
        $flip = ((int) ($_POST['flip'] ?? 0)) === 1 ? 1 : 0;
        $bubbleEnabled = ((int) ($_POST['bubble_enabled'] ?? 0)) === 1 ? 1 : 0;
        $showStats = ((int) ($_POST['show_stats'] ?? 0)) === 1 ? 1 : 0;
        $visiblePosted = explode(',', (string) ($_POST['visible_stats'] ?? ''));
        $visibleClean = [];
        foreach ($visiblePosted as $stat) {
            $stat = strtolower(trim($stat));
            if (in_array($stat, $petStatKeys, true) && !in_array($stat, $visibleClean, true)) {
                $visibleClean[] = $stat;
            }
        }
        $visibleStatsStr = implode(',', $visibleClean);
        $decayHappiness = max(0, min(99.99, (float) ($_POST['decay_happiness'] ?? 2)));
        $decayHunger = max(0, min(99.99, (float) ($_POST['decay_hunger'] ?? 3)));
        $decayEnergy = max(0, min(99.99, (float) ($_POST['decay_energy'] ?? 1)));
        $startHappiness = pet_clamp_stat($_POST['happiness'] ?? 80);
        $startHunger = pet_clamp_stat($_POST['hunger'] ?? 80);
        $startEnergy = pet_clamp_stat($_POST['energy'] ?? 80);

        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO pet_settings (id, pet_name, idle_animation, position, scale, flip, show_stats, visible_stats, bubble_enabled, decay_happiness, decay_hunger, decay_energy, start_happiness, start_hunger, start_energy) '
                . 'VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE pet_name = VALUES(pet_name), idle_animation = VALUES(idle_animation), position = VALUES(position), '
                . 'scale = VALUES(scale), flip = VALUES(flip), show_stats = VALUES(show_stats), visible_stats = VALUES(visible_stats), '
                . 'bubble_enabled = VALUES(bubble_enabled), decay_happiness = VALUES(decay_happiness), decay_hunger = VALUES(decay_hunger), decay_energy = VALUES(decay_energy), '
                . 'start_happiness = VALUES(start_happiness), start_hunger = VALUES(start_hunger), start_energy = VALUES(start_energy)'
            );
            if (!$stmt) {
                throw new Exception($db->error);
            }
            $stmt->bind_param(
                'sssdiisidddiii',
                $petName,
                $idleAnimation,
                $position,
                $scale,
                $flip,
                $showStats,
                $visibleStatsStr,
                $bubbleEnabled,
                $decayHappiness,
                $decayHunger,
                $decayEnergy,
                $startHappiness,
                $startHunger,
                $startEnergy
            );
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            $stateRes = $db->query('SELECT happiness, hunger, energy FROM pet_state WHERE id = 1');
            $stateRow = $stateRes ? $stateRes->fetch_assoc() : null;
            if (!$stateRow) {
                $stateIns = $db->prepare('INSERT INTO pet_state (id, happiness, hunger, energy) VALUES (1, ?, ?, ?)');
                if (!$stateIns) {
                    throw new Exception($db->error);
                }
                $stateIns->bind_param('iii', $startHappiness, $startHunger, $startEnergy);
                if (!$stateIns->execute()) {
                    throw new Exception($stateIns->error);
                }
                $stateIns->close();
            } elseif (
                pet_clamp_stat($stateRow['happiness'] ?? 80) !== $startHappiness
                || pet_clamp_stat($stateRow['hunger'] ?? 80) !== $startHunger
                || pet_clamp_stat($stateRow['energy'] ?? 80) !== $startEnergy
            ) {
                $now = gmdate('Y-m-d H:i:s');
                $stateUp = $db->prepare(
                    'UPDATE pet_state SET happiness = ?, hunger = ?, energy = ?, last_interaction_at = ? WHERE id = 1'
                );
                if (!$stateUp) {
                    throw new Exception($db->error);
                }
                $stateUp->bind_param('iiis', $startHappiness, $startHunger, $startEnergy, $now);
                if (!$stateUp->execute()) {
                    throw new Exception($stateUp->error);
                }
                $stateUp->close();
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        pet_notify_event($petNotifyApiKey, 'PET_SETTINGS_UPDATE');
        pet_json_exit(['success' => true, 'message' => t('pet_saved')]);
    }

    if ($action === 'set_enabled') {
        $enabled = ((int) ($_POST['enabled'] ?? 0)) === 1 ? 1 : 0;
        $wasEnabled = 0;
        $prev = $db->query('SELECT enabled FROM pet_settings WHERE id = 1');
        if ($prev && ($prevRow = $prev->fetch_assoc())) {
            $wasEnabled = ((int) $prevRow['enabled']) === 1 ? 1 : 0;
        }
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO pet_settings (id, enabled) VALUES (1, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
            );
            if (!$stmt) {
                throw new Exception($db->error);
            }
            $stmt->bind_param('i', $enabled);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();
            if ($enabled === 1) {
                if (!$db->query('INSERT INTO pet_state (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id')) {
                    throw new Exception($db->error);
                }
                if ($wasEnabled === 0) {
                    pet_seed_default_triggers($db);
                }
                pet_ensure_interaction_triggers($db);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        pet_notify_event($petNotifyApiKey, 'PET_SETTINGS_UPDATE');
        pet_json_exit(['success' => true, 'enabled' => $enabled, 'message' => t('pet_saved')]);
    }

    if ($action === 'upload_animation') {
        include 'includes/storage_used.php';
        $animName = pet_sanitize_anim_name($_POST['name'] ?? '');
        if ($animName === '' || !in_array($animName, $petAnimNameList, true)) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_invalid')]);
        }
        $frameWidth = max(1, min(PET_IMAGE_MAX_PX, (int) ($_POST['frame_width'] ?? 0)));
        $frameHeight = max(1, min(PET_IMAGE_MAX_PX, (int) ($_POST['frame_height'] ?? 0)));
        $frameCount = max(1, min(PET_FRAME_COUNT_MAX, (int) ($_POST['frame_count'] ?? 1)));
        $fps = max(1, min(60, (int) ($_POST['fps'] ?? 12)));
        $loopFlag = ((int) ($_POST['loop'] ?? 1)) === 1 ? 1 : 0;
        if (!isset($_FILES['sprite']) || ($_FILES['sprite']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = (int) ($_FILES['sprite']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                pet_json_exit(['success' => false, 'error' => t('pet_upload_error_file_size', [(int) (PET_IMAGE_MAX_BYTES / (1024 * 1024))])]);
            }
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error', [t('pet_sprite_upload')])]);
        }
        $tmp = $_FILES['sprite']['tmp_name'];
        $orig = $_FILES['sprite']['name'] ?? 'sprite.png';
        $size = (int) ($_FILES['sprite']['size'] ?? 0);
        if ($size <= 0 || $size > PET_IMAGE_MAX_BYTES) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_file_size', [(int) (PET_IMAGE_MAX_BYTES / (1024 * 1024))])]);
        }
        if (!is_uploaded_file($tmp)) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_invalid')]);
        }
        $dims = @getimagesize($tmp);
        if (!$dims || empty($dims[0]) || empty($dims[1])) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_invalid')]);
        }
        $imgW = (int) $dims[0];
        $imgH = (int) $dims[1];
        if ($imgW < PET_IMAGE_MIN_PX || $imgH < PET_IMAGE_MIN_PX) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_invalid')]);
        }
        if ($imgW > PET_IMAGE_MAX_PX || $imgH > PET_IMAGE_MAX_PX) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_too_large', [PET_IMAGE_MAX_PX, $imgW, $imgH])]);
        }
        if ($frameWidth > $imgW || $frameHeight > $imgH) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_invalid')]);
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $petImageExts, true) || !upload_validate_extension_and_mime($tmp, $ext, $petImageExts)) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_type')]);
        }
        if (!ensureDirectoryWritable($petMediaDir)) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error', [t('pet_sprite_upload')])]);
        }
        $oldFilename = '';
        $oldSize = 0;
        $existStmt = $db->prepare('SELECT sprite_file FROM pet_animations WHERE name = ?');
        if ($existStmt) {
            $existStmt->bind_param('s', $animName);
            $existStmt->execute();
            $existRes = $existStmt->get_result();
            if ($existRes && ($existRow = $existRes->fetch_assoc())) {
                $oldFilename = basename((string) $existRow['sprite_file']);
                $oldPath = $petMediaDir . '/' . $oldFilename;
                $oldSize = ($oldFilename !== '' && is_file($oldPath)) ? (int) filesize($oldPath) : 0;
            }
            $existStmt->close();
        }
        $safeName = upload_sanitize_filename($orig, $ext);
        $target = upload_unique_target($petMediaDir, $safeName);
        if (!upload_reencode_image($tmp, $target['path'], $ext, PET_IMAGE_MAX_PX, PET_IMAGE_MIN_PX)) {
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_invalid')]);
        }
        $savedSize = (int) filesize($target['path']);
        $projectedUsed = $current_storage_used - $oldSize + $savedSize;
        if ($projectedUsed > $max_storage_size) {
            @unlink($target['path']);
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error_storage')]);
        }
        $db->begin_transaction();
        try {
            $saveUp = $db->prepare(
                'INSERT INTO pet_animations (name, sprite_file, frame_width, frame_height, frame_count, fps, `loop`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE sprite_file = VALUES(sprite_file), frame_width = VALUES(frame_width), '
                . 'frame_height = VALUES(frame_height), frame_count = VALUES(frame_count), fps = VALUES(fps), `loop` = VALUES(`loop`)'
            );
            if (!$saveUp) {
                throw new Exception($db->error);
            }
            $saveUp->bind_param('ssiiiii', $animName, $target['name'], $frameWidth, $frameHeight, $frameCount, $fps, $loopFlag);
            if (!$saveUp->execute()) {
                throw new Exception($saveUp->error);
            }
            $saveUp->close();
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            @unlink($target['path']);
            pet_json_exit(['success' => false, 'error' => t('pet_upload_error', [t('pet_save_error')])]);
        }
        if ($oldFilename !== '' && $oldFilename !== $target['name']) {
            pet_unlink_if_unused($db, $petMediaDir, $oldFilename);
        }
        pet_notify_event($petNotifyApiKey, 'PET_SETTINGS_UPDATE');
        pet_json_exit([
            'success' => true,
            'filename' => $target['name'],
            'url' => pet_sprite_url($petMediaUrl, $target['name']),
            'name' => $animName,
            'message' => t('pet_upload_success', [$target['name']]),
        ]);
    }

    if ($action === 'delete_animation') {
        $animId = (int) ($_POST['id'] ?? 0);
        if ($animId <= 0) {
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        $oldFilename = '';
        $db->begin_transaction();
        try {
            $sel = $db->prepare('SELECT sprite_file FROM pet_animations WHERE id = ?');
            if (!$sel) {
                throw new Exception($db->error);
            }
            $sel->bind_param('i', $animId);
            $sel->execute();
            $selRes = $sel->get_result();
            $selRow = $selRes ? $selRes->fetch_assoc() : null;
            $sel->close();
            if (!$selRow) {
                throw new Exception('missing');
            }
            $oldFilename = basename((string) $selRow['sprite_file']);
            $del = $db->prepare('DELETE FROM pet_animations WHERE id = ?');
            if (!$del) {
                throw new Exception($db->error);
            }
            $del->bind_param('i', $animId);
            if (!$del->execute()) {
                throw new Exception($del->error);
            }
            $del->close();
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        if ($oldFilename !== '') {
            pet_unlink_if_unused($db, $petMediaDir, $oldFilename);
        }
        pet_notify_event($petNotifyApiKey, 'PET_SETTINGS_UPDATE');
        pet_json_exit(['success' => true]);
    }

    if ($action === 'save_trigger') {
        $triggerId = (int) ($_POST['id'] ?? 0);
        $triggerType = (string) ($_POST['trigger_type'] ?? '');
        if (!in_array($triggerType, $petTriggerTypes, true)) {
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        $triggerValue = pet_normalize_trigger_value(
            $triggerType,
            $_POST['trigger_value'] ?? '',
            $petEventValues,
            $petInteractionValues
        );
        if ($triggerValue === '') {
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        $animation = pet_sanitize_anim_name($_POST['animation'] ?? '');
        if ($animation === '') {
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        $bubbleText = trim((string) ($_POST['bubble_text'] ?? ''));
        if (mb_strlen($bubbleText) > 255) {
            $bubbleText = mb_substr($bubbleText, 0, 255);
        }
        $bubbleParam = $bubbleText;
        $effectHappiness = max(-100, min(100, (int) ($_POST['effect_happiness'] ?? 0)));
        $effectHunger = max(-100, min(100, (int) ($_POST['effect_hunger'] ?? 0)));
        $effectEnergy = max(-100, min(100, (int) ($_POST['effect_energy'] ?? 0)));
        $xp = max(0, min(10000, (int) ($_POST['xp'] ?? 0)));
        $cooldown = max(0, min(86400, (int) ($_POST['cooldown_seconds'] ?? 5)));
        $trigEnabled = ((int) ($_POST['enabled'] ?? 1)) === 1 ? 1 : 0;

        $db->begin_transaction();
        try {
            if ($triggerId > 0) {
                $stmt = $db->prepare(
                    'UPDATE pet_triggers SET trigger_type = ?, trigger_value = ?, animation = ?, bubble_text = ?, '
                    . 'effect_happiness = ?, effect_hunger = ?, effect_energy = ?, xp = ?, cooldown_seconds = ?, enabled = ? '
                    . 'WHERE id = ?'
                );
                if (!$stmt) {
                    throw new Exception($db->error);
                }
                $stmt->bind_param(
                    'ssssiiiiiii',
                    $triggerType,
                    $triggerValue,
                    $animation,
                    $bubbleParam,
                    $effectHappiness,
                    $effectHunger,
                    $effectEnergy,
                    $xp,
                    $cooldown,
                    $trigEnabled,
                    $triggerId
                );
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO pet_triggers (trigger_type, trigger_value, animation, bubble_text, effect_happiness, effect_hunger, effect_energy, xp, cooldown_seconds, enabled) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new Exception($db->error);
                }
                $stmt->bind_param(
                    'ssssiiiiii',
                    $triggerType,
                    $triggerValue,
                    $animation,
                    $bubbleParam,
                    $effectHappiness,
                    $effectHunger,
                    $effectEnergy,
                    $xp,
                    $cooldown,
                    $trigEnabled
                );
            }
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            if ($triggerId <= 0) {
                $triggerId = (int) $stmt->insert_id;
            }
            $stmt->close();
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        pet_notify_event($petNotifyApiKey, 'PET_SETTINGS_UPDATE');
        pet_json_exit(['success' => true, 'id' => $triggerId, 'message' => t('pet_saved')]);
    }

    if ($action === 'delete_trigger') {
        $triggerId = (int) ($_POST['id'] ?? 0);
        if ($triggerId <= 0) {
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        $db->begin_transaction();
        try {
            $del = $db->prepare('DELETE FROM pet_triggers WHERE id = ?');
            if (!$del) {
                throw new Exception($db->error);
            }
            $del->bind_param('i', $triggerId);
            if (!$del->execute()) {
                throw new Exception($del->error);
            }
            $del->close();
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        pet_notify_event($petNotifyApiKey, 'PET_SETTINGS_UPDATE');
        pet_json_exit(['success' => true]);
    }

    if ($action === 'test_reaction') {
        $animation = pet_sanitize_anim_name($_POST['animation'] ?? '');
        if ($animation === '') {
            $animation = pet_sanitize_anim_name($pet['idle_animation'] ?? 'idle');
        }
        if ($animation === '') {
            $animation = 'idle';
        }
        $bubbleText = trim((string) ($_POST['bubble_text'] ?? ''));
        if (mb_strlen($bubbleText) > 255) {
            $bubbleText = mb_substr($bubbleText, 0, 255);
        }
        $hadUser = strpos($bubbleText, '{user}') !== false;
        $triggerType = strtolower(trim((string) ($_POST['trigger_type'] ?? '')));
        $triggerValue = strtolower(trim((string) ($_POST['trigger_value'] ?? '')));
        $isFirstChat = $triggerType === 'event' && $triggerValue === 'first_chat';
        $personalized = $hadUser || $isFirstChat
            || ((string) ($_POST['personalized'] ?? '') === '1')
            || ((int) ($_POST['personalized'] ?? 0) === 1);
        $extra = ['animation' => $animation];
        if ($bubbleText !== '') {
            $extra['bubble_text'] = str_replace('{user}', (string) $twitchDisplayName, $bubbleText);
        }
        if ($personalized) {
            $extra['personalized'] = '1';
        }
        $effectHappiness = max(-100, min(100, (int) ($_POST['effect_happiness'] ?? 0)));
        $effectHunger = max(-100, min(100, (int) ($_POST['effect_hunger'] ?? 0)));
        $effectEnergy = max(-100, min(100, (int) ($_POST['effect_energy'] ?? 0)));
        $xpAdd = max(0, min(10000, (int) ($_POST['xp'] ?? 0)));
        if ($effectHappiness !== 0 || $effectHunger !== 0 || $effectEnergy !== 0 || $xpAdd !== 0) {
            if (!pet_apply_state_and_notify($db, $petNotifyApiKey, $effectHappiness, $effectHunger, $effectEnergy, $xpAdd)) {
                pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
            }
        }
        pet_notify_event($petNotifyApiKey, 'PET_REACT', $extra);
        pet_json_exit(['success' => true]);
    }

    if ($action === 'pet_the_pet') {
        $effectHappiness = 10;
        $effectHunger = 0;
        $effectEnergy = -5;
        $xpAdd = 0;
        $animation = 'happy';
        $bubbleText = '';
        $playRes = $db->query(
            "SELECT animation, bubble_text, effect_happiness, effect_hunger, effect_energy, xp "
            . "FROM pet_triggers WHERE trigger_type = 'interaction' AND trigger_value = 'play' AND enabled = 1 LIMIT 1"
        );
        if ($playRes && ($playRow = $playRes->fetch_assoc())) {
            $mapped = pet_sanitize_anim_name($playRow['animation'] ?? '');
            if ($mapped !== '') {
                $animation = $mapped;
            }
            $bubbleText = trim((string) ($playRow['bubble_text'] ?? ''));
            $effectHappiness = max(-100, min(100, (int) ($playRow['effect_happiness'] ?? 10)));
            $effectHunger = max(-100, min(100, (int) ($playRow['effect_hunger'] ?? 0)));
            $effectEnergy = max(-100, min(100, (int) ($playRow['effect_energy'] ?? -5)));
            $xpAdd = max(0, min(10000, (int) ($playRow['xp'] ?? 0)));
        }
        if (!pet_apply_state_and_notify($db, $petNotifyApiKey, $effectHappiness, $effectHunger, $effectEnergy, $xpAdd)) {
            pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
        }
        $extra = ['animation' => $animation];
        if ($bubbleText !== '') {
            $hadUser = strpos($bubbleText, '{user}') !== false;
            $extra['bubble_text'] = str_replace('{user}', (string) $twitchDisplayName, $bubbleText);
            if ($hadUser) {
                $extra['personalized'] = '1';
            }
        }
        pet_notify_event($petNotifyApiKey, 'PET_REACT', $extra);
        pet_json_exit(['success' => true]);
    }

    pet_json_exit(['success' => false, 'error' => t('pet_save_error')]);
}

while (ob_get_level()) {
    ob_end_clean();
}

ob_start();
?>
<div class="sp-alert sp-alert-info media-storage-bar" id="petStorageBar" aria-busy="true">
    <div class="media-storage-header">
        <span><i class="fas fa-database"></i> <strong><?= t('alerts_storage_usage') ?>:</strong></span>
        <span id="petStorageText"><span class="sp-skeleton-line w-40" aria-hidden="true"></span></span>
    </div>
    <progress class="progress" id="petStorageProgress" value="0" max="100"></progress>
    <p class="pet-page-help pet-page-help-last av-storage-note"><?= t('pet_storage_note') ?></p>
</div>

<div class="sp-page-header">
    <h1><i class="fas fa-paw"></i> <?= t('pet_page_title') ?></h1>
    <p><?= t('pet_intro') ?></p>
</div>

<div class="sp-card pet-page-url-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-link"></i> <?= t('pet_overlay_url_title') ?></div>
    </div>
    <div class="sp-card-body">
        <p class="pet-page-help"><?= t('pet_overlay_url_desc') ?></p>
        <div class="pet-page-url-row">
            <code class="info-box pet-page-url-box" id="petOverlayUrl"><?= htmlspecialchars($overlayLinkMasked) ?></code>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" id="petUrlReveal" aria-pressed="false"><i class="fas fa-eye"></i> <span class="pet-page-url-reveal-label"><?= t('pet_overlay_url_show') ?></span></button>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-primary" id="petUrlCopy"><i class="fas fa-copy"></i> <span class="pet-page-url-copy-label"><?= t('pet_overlay_url_copy') ?></span></button>
        </div>
    </div>
</div>

<div class="sp-card pet-page-enable-card">
    <div class="sp-card-body">
        <div class="pet-page-enable-row">
            <label class="switch">
                <input type="checkbox" id="petEnabled" <?= $pet['enabled'] === 1 ? 'checked' : '' ?>>
                <span><?= t('pet_enable') ?></span>
            </label>
            <span class="pet-page-save-status" id="petEnableStatus"></span>
        </div>
        <p class="pet-page-help pet-page-help-last"><?= t('pet_enable_help') ?></p>
    </div>
</div>

<form id="petSettingsForm" class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-sliders"></i> <?= t('pet_page_title') ?></div>
    </div>
    <div class="sp-card-body">
        <div class="pet-page-form-grid">
            <div class="sp-form-group">
                <label class="sp-label" for="petName"><?= t('pet_name') ?></label>
                <input type="text" id="petName" name="pet_name" class="sp-input" maxlength="50" value="<?= htmlspecialchars((string) $pet['pet_name']) ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petIdleAnimation"><?= t('pet_idle_animation') ?></label>
                <select id="petIdleAnimation" name="idle_animation" class="sp-select">
                    <?php foreach ($petAnimNameList as $animName): ?>
                        <option value="<?= htmlspecialchars($animName) ?>" <?= ((string) $pet['idle_animation'] === (string) $animName) ? 'selected' : '' ?>><?= htmlspecialchars($animName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petPosition"><?= t('pet_position') ?></label>
                <select id="petPosition" name="position" class="sp-select">
                    <?php
                    $petPosKeys = [
                        'top-left' => 'pet_pos_top_left',
                        'top-right' => 'pet_pos_top_right',
                        'bottom-left' => 'pet_pos_bottom_left',
                        'bottom-right' => 'pet_pos_bottom_right',
                    ];
                    foreach ($petPosKeys as $posVal => $posKey):
                    ?>
                        <option value="<?= htmlspecialchars($posVal) ?>" <?= ((string) $pet['position'] === $posVal) ? 'selected' : '' ?>><?= t($posKey) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petScale"><?= t('pet_scale') ?> (<span id="petScaleVal"><?= htmlspecialchars(number_format((float) $pet['scale'], 2, '.', '')) ?></span>)</label>
                <input type="range" id="petScale" name="scale" min="0.25" max="3" step="0.05" value="<?= htmlspecialchars(number_format((float) $pet['scale'], 2, '.', '')) ?>" class="sp-input">
            </div>
        </div>
        <div class="pet-page-toggle-row">
            <label class="switch">
                <input type="checkbox" id="petFlip" <?= $pet['flip'] === 1 ? 'checked' : '' ?>>
                <span><?= t('pet_flip') ?></span>
            </label>
            <label class="switch">
                <input type="checkbox" id="petBubbleEnabled" <?= $pet['bubble_enabled'] === 1 ? 'checked' : '' ?>>
                <span><?= t('pet_bubble_enabled') ?></span>
            </label>
            <label class="switch">
                <input type="checkbox" id="petShowStats" <?= $pet['show_stats'] === 1 ? 'checked' : '' ?>>
                <span><?= t('pet_show_stats') ?></span>
            </label>
        </div>
        <div class="sp-form-group">
            <span class="sp-label"><?= t('pet_visible_stats') ?></span>
            <div class="pet-page-stat-checks">
                <?php
                $petStatLabels = [
                    'happiness' => 'pet_stat_happiness',
                    'hunger' => 'pet_stat_hunger',
                    'energy' => 'pet_stat_energy',
                    'xp' => 'pet_stat_xp',
                    'xp_next' => 'pet_stat_xp_next',
                ];
                foreach ($petStatLabels as $statKey => $statLabel):
                ?>
                    <label class="switch">
                        <input type="checkbox" class="pet-page-visible-stat" value="<?= htmlspecialchars($statKey) ?>" <?= in_array($statKey, $visibleStats, true) ? 'checked' : '' ?>>
                        <span><?= t($statLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="pet-page-form-grid">
            <div class="sp-form-group">
                <label class="sp-label" for="petStartHappiness"><?= t('pet_stat_happiness') ?></label>
                <input type="number" id="petStartHappiness" name="happiness" class="sp-input" min="0" max="100" step="1" value="<?= (int) ($pet['start_happiness'] ?? $petState['happiness']) ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petStartHunger"><?= t('pet_stat_hunger') ?></label>
                <input type="number" id="petStartHunger" name="hunger" class="sp-input" min="0" max="100" step="1" value="<?= (int) ($pet['start_hunger'] ?? $petState['hunger']) ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petStartEnergy"><?= t('pet_stat_energy') ?></label>
                <input type="number" id="petStartEnergy" name="energy" class="sp-input" min="0" max="100" step="1" value="<?= (int) ($pet['start_energy'] ?? $petState['energy']) ?>">
            </div>
        </div>
        <p class="sp-help"><?= t('pet_stats_stream_help') ?></p>
        <div class="pet-page-form-grid">
            <div class="sp-form-group">
                <label class="sp-label" for="petDecayHappiness"><?= t('pet_decay_happiness') ?></label>
                <input type="number" id="petDecayHappiness" name="decay_happiness" class="sp-input" min="0" max="99.99" step="0.25" value="<?= htmlspecialchars(number_format((float) $pet['decay_happiness'], 2, '.', '')) ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petDecayHunger"><?= t('pet_decay_hunger') ?></label>
                <input type="number" id="petDecayHunger" name="decay_hunger" class="sp-input" min="0" max="99.99" step="0.25" value="<?= htmlspecialchars(number_format((float) $pet['decay_hunger'], 2, '.', '')) ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="petDecayEnergy"><?= t('pet_decay_energy') ?></label>
                <input type="number" id="petDecayEnergy" name="decay_energy" class="sp-input" min="0" max="99.99" step="0.25" value="<?= htmlspecialchars(number_format((float) $pet['decay_energy'], 2, '.', '')) ?>">
            </div>
        </div>
        <div class="pet-page-save-row">
            <span class="pet-page-save-status" id="petSaveStatus"></span>
            <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-save"></i> <?= t('pet_save_settings') ?></button>
        </div>
    </div>
</form>

<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-film"></i> <?= t('pet_animations_title') ?></div>
    </div>
    <div class="sp-card-body">
        <div class="pet-page-anim-layout">
            <form id="petAnimForm" class="pet-page-anim-form">
                <div class="sp-form-group">
                    <label class="sp-label" for="petAnimName"><?= t('pet_animation_name') ?></label>
                    <select id="petAnimName" name="name" class="sp-select">
                        <?php foreach ($petAnimNameList as $animName): ?>
                            <option value="<?= htmlspecialchars($animName) ?>" <?= $animName === 'idle' ? 'selected' : '' ?>><?= htmlspecialchars($animName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sp-form-group">
                    <span class="sp-label"><?= t('pet_sprite_upload') ?></span>
                    <label class="media-drop-zone" id="petSpriteDropZone">
                        <i class="fas fa-cloud-upload-alt media-drop-zone-icon"></i>
                        <span class="file-list-label" id="petSpriteFileLabel"><?= t('media_no_files_selected') ?></span>
                        <div class="media-drop-zone-hint"><?= t('media_click_or_drag') ?></div>
                        <input type="file" id="petAnimSprite" name="sprite" accept="image/png,image/webp" hidden>
                    </label>
                    <span class="sp-help"><?= t('pet_upload_help', [PET_IMAGE_MIN_PX, PET_IMAGE_MAX_PX, (int) (PET_IMAGE_MAX_BYTES / (1024 * 1024))]) ?></span>
                </div>
                <div class="pet-page-form-grid">
                    <div class="sp-form-group">
                        <label class="sp-label" for="petFrameWidth"><?= t('pet_frame_width') ?></label>
                        <input type="number" id="petFrameWidth" name="frame_width" class="sp-input" min="1" max="4096" value="128" required>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="petFrameHeight"><?= t('pet_frame_height') ?></label>
                        <input type="number" id="petFrameHeight" name="frame_height" class="sp-input" min="1" max="4096" value="128" required>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="petFrameCount"><?= t('pet_frame_count') ?></label>
                        <input type="number" id="petFrameCount" name="frame_count" class="sp-input" min="1" max="64" value="1" required>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="petFps"><?= t('pet_fps') ?></label>
                        <input type="number" id="petFps" name="fps" class="sp-input" min="1" max="60" value="12" required>
                    </div>
                </div>
                <label class="switch pet-page-loop-switch">
                    <input type="checkbox" id="petAnimLoop" checked>
                    <span><?= t('pet_loop') ?></span>
                </label>
                <div class="pet-page-save-row">
                    <span class="pet-page-save-status" id="petAnimStatus"></span>
                    <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-plus"></i> <?= t('pet_animation_add') ?></button>
                </div>
            </form>
            <div class="pet-page-preview-wrap">
                <div class="pet-page-preview-label"><?= t('pet_animation_preview') ?></div>
                <div class="pet-page-preview-stage" id="petPreviewStage">
                    <canvas class="pet-page-preview-canvas" id="petPreviewCanvas" width="128" height="128"></canvas>
                    <div class="pet-page-preview-sheet pet-page-hidden" id="petPreviewSheet" aria-hidden="true"></div>
                    <span class="pet-page-preview-placeholder" id="petPreviewPlaceholder"><?= t('pet_animation_preview') ?></span>
                </div>
            </div>
        </div>
        <?php if (!$petAnimations): ?>
            <p class="pet-page-empty" id="petAnimEmpty"><?= t('pet_no_animations') ?></p>
        <?php endif; ?>
        <div class="pet-page-anim-grid" id="petAnimGrid">
            <?php foreach ($petAnimations as $animRow): ?>
                <div class="pet-page-anim-card" data-id="<?= (int) $animRow['id'] ?>">
                    <div class="pet-page-anim-card-head">
                        <strong><?= htmlspecialchars((string) $animRow['name']) ?></strong>
                        <span class="pet-page-anim-meta"><?= (int) $animRow['frame_width'] ?>×<?= (int) $animRow['frame_height'] ?> · <?= (int) $animRow['frame_count'] ?> · <?= (int) $animRow['fps'] ?>fps</span>
                    </div>
                    <div class="pet-page-anim-card-actions">
                        <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary pet-page-anim-preview-btn"
                            data-url="<?= htmlspecialchars((string) $animRow['url']) ?>"
                            data-frame-width="<?= (int) $animRow['frame_width'] ?>"
                            data-frame-height="<?= (int) $animRow['frame_height'] ?>"
                            data-frame-count="<?= (int) $animRow['frame_count'] ?>"
                            data-fps="<?= (int) $animRow['fps'] ?>"
                            data-loop="<?= (int) $animRow['loop'] ?>">
                            <i class="fas fa-play"></i> <?= t('pet_animation_preview') ?>
                        </button>
                        <button type="button" class="sp-btn sp-btn-sm sp-btn-danger pet-page-anim-delete-btn" data-id="<?= (int) $animRow['id'] ?>">
                            <i class="fas fa-trash"></i> <?= t('pet_animation_delete') ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-bolt"></i> <?= t('pet_triggers_title') ?></div>
        <div class="pet-page-row-actions">
            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" id="petTestIdle"><i class="fas fa-paw"></i> <?= t('pet_test_idle') ?></button>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" id="petTestPet"><i class="fas fa-hand-holding-heart"></i> <?= t('pet_type_interaction') ?></button>
        </div>
    </div>
    <div class="sp-card-body">
        <form id="petTriggerForm" class="pet-page-trigger-form">
            <div class="pet-page-form-grid pet-page-trigger-add-grid">
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerType"><?= t('pet_trigger_type') ?></label>
                    <select id="petTriggerType" name="trigger_type" class="sp-select">
                        <option value="chat_keyword"><?= t('pet_type_chat_keyword') ?></option>
                        <option value="command"><?= t('pet_type_command') ?></option>
                        <option value="redemption"><?= t('pet_type_redemption') ?></option>
                        <option value="event"><?= t('pet_type_event') ?></option>
                        <option value="interaction"><?= t('pet_type_interaction') ?></option>
                    </select>
                </div>
                <div class="sp-form-group" id="petTriggerValueWrap">
                    <label class="sp-label" for="petTriggerValue"><?= t('pet_trigger_value') ?></label>
                    <input type="text" id="petTriggerValue" class="sp-input" maxlength="100">
                    <select id="petTriggerValueEvent" class="sp-select pet-page-hidden">
                        <?php foreach ($petEventValues as $evVal): ?>
                            <option value="<?= htmlspecialchars($evVal) ?>"><?= htmlspecialchars($evVal) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="petTriggerValueInteraction" class="sp-select pet-page-hidden">
                        <?php foreach ($petInteractionValues as $inVal): ?>
                            <option value="<?= htmlspecialchars($inVal) ?>"><?= htmlspecialchars($inVal) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="petTriggerValueRedemption" class="sp-select pet-page-hidden">
                        <?php if ($petRewards): ?>
                            <?php foreach ($petRewards as $rw): ?>
                                <option value="<?= htmlspecialchars($rw['reward_id']) ?>"><?= htmlspecialchars($rw['reward_title'] !== '' ? $rw['reward_title'] : $rw['reward_id']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerAnimation"><?= t('pet_trigger_animation') ?></label>
                    <select id="petTriggerAnimation" name="animation" class="sp-select">
                        <?php foreach ($petAnimNameList as $animName): ?>
                            <option value="<?= htmlspecialchars($animName) ?>"><?= htmlspecialchars($animName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerBubble"><?= t('pet_trigger_bubble') ?></label>
                    <input type="text" id="petTriggerBubble" name="bubble_text" class="sp-input" maxlength="255">
                    <span class="sp-help"><?= t('pet_trigger_bubble_help') ?></span>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerCooldown"><?= t('pet_trigger_cooldown') ?></label>
                    <input type="number" id="petTriggerCooldown" name="cooldown_seconds" class="sp-input" min="0" max="86400" value="5">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerHappiness"><?= t('pet_trigger_happiness') ?></label>
                    <input type="number" id="petTriggerHappiness" name="effect_happiness" class="sp-input" min="-100" max="100" value="0">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerHunger"><?= t('pet_trigger_hunger') ?></label>
                    <input type="number" id="petTriggerHunger" name="effect_hunger" class="sp-input" min="-100" max="100" value="0">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerEnergy"><?= t('pet_trigger_energy') ?></label>
                    <input type="number" id="petTriggerEnergy" name="effect_energy" class="sp-input" min="-100" max="100" value="0">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="petTriggerXp"><?= t('pet_trigger_xp') ?></label>
                    <input type="number" id="petTriggerXp" name="xp" class="sp-input" min="0" max="10000" value="0">
                </div>
                <div class="sp-form-group pet-page-trigger-enabled-field">
                    <label class="switch">
                        <input type="checkbox" id="petTriggerEnabled" checked>
                        <span><?= t('pet_trigger_enabled') ?></span>
                    </label>
                </div>
            </div>
            <div class="pet-page-save-row">
                <span class="pet-page-save-status" id="petTriggerStatus"></span>
                <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-plus"></i> <?= t('pet_trigger_add') ?></button>
            </div>
        </form>
        <?php if (!$petTriggers): ?>
            <p class="pet-page-empty" id="petTriggerEmpty"><?= t('pet_no_triggers') ?></p>
        <?php endif; ?>
        <div class="sp-table-wrap pet-page-trigger-wrap<?= !$petTriggers ? ' pet-page-hidden' : '' ?>">
            <table class="sp-table" id="petTriggerTable">
                <thead>
                    <tr>
                        <th><?= t('pet_trigger_type') ?></th>
                        <th><?= t('pet_trigger_value') ?></th>
                        <th><?= t('pet_trigger_animation') ?></th>
                        <th><?= t('pet_trigger_bubble') ?></th>
                        <th><?= t('pet_trigger_cooldown') ?></th>
                        <th><?= t('pet_trigger_happiness') ?></th>
                        <th><?= t('pet_trigger_hunger') ?></th>
                        <th><?= t('pet_trigger_energy') ?></th>
                        <th><?= t('pet_trigger_xp') ?></th>
                        <th><?= t('pet_trigger_enabled') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="petTriggerBody">
                    <?php
                    $petTypeLabels = [
                        'chat_keyword' => t('pet_type_chat_keyword'),
                        'command' => t('pet_type_command'),
                        'redemption' => t('pet_type_redemption'),
                        'event' => t('pet_type_event'),
                        'interaction' => t('pet_type_interaction'),
                    ];
                    foreach ($petTriggers as $trig):
                    ?>
                    <tr data-id="<?= (int) $trig['id'] ?>">
                        <td>
                            <select class="sp-select pet-page-cell-input pet-trig-type">
                                <?php foreach ($petTriggerTypes as $tt): ?>
                                    <option value="<?= htmlspecialchars($tt) ?>" <?= $trig['trigger_type'] === $tt ? 'selected' : '' ?>><?= htmlspecialchars($petTypeLabels[$tt] ?? $tt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" class="sp-input pet-page-cell-input pet-trig-value" maxlength="100" value="<?= htmlspecialchars((string) $trig['trigger_value']) ?>"></td>
                        <td>
                            <select class="sp-select pet-page-cell-input pet-trig-animation">
                                <?php foreach ($petAnimNameList as $animName): ?>
                                    <option value="<?= htmlspecialchars($animName) ?>" <?= ((string) $trig['animation'] === (string) $animName) ? 'selected' : '' ?>><?= htmlspecialchars($animName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" class="sp-input pet-page-cell-input-wide pet-trig-bubble" maxlength="255" value="<?= htmlspecialchars((string) ($trig['bubble_text'] ?? '')) ?>"></td>
                        <td><input type="number" class="sp-input pet-page-cell-input-sm pet-trig-cooldown" min="0" max="86400" value="<?= (int) $trig['cooldown_seconds'] ?>"></td>
                        <td><input type="number" class="sp-input pet-page-cell-input-sm pet-trig-happiness" min="-100" max="100" value="<?= (int) $trig['effect_happiness'] ?>"></td>
                        <td><input type="number" class="sp-input pet-page-cell-input-sm pet-trig-hunger" min="-100" max="100" value="<?= (int) $trig['effect_hunger'] ?>"></td>
                        <td><input type="number" class="sp-input pet-page-cell-input-sm pet-trig-energy" min="-100" max="100" value="<?= (int) $trig['effect_energy'] ?>"></td>
                        <td><input type="number" class="sp-input pet-page-cell-input-sm pet-trig-xp" min="0" max="10000" value="<?= (int) $trig['xp'] ?>"></td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" class="pet-trig-enabled" <?= $trig['enabled'] === 1 ? 'checked' : '' ?>>
                                <span class="pet-page-sr-only"><?= t('pet_trigger_enabled') ?></span>
                            </label>
                        </td>
                        <td class="pet-page-row-actions">
                            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary pet-trig-test"><i class="fas fa-play"></i> <?= t('pet_trigger_test') ?></button>
                            <button type="button" class="sp-btn sp-btn-sm sp-btn-danger pet-trig-delete"><i class="fas fa-trash"></i> <?= t('pet_trigger_delete') ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
(function () {
    const petLang = {
        saved: <?php echo json_encode(t('pet_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        saveError: <?php echo json_encode(t('pet_save_error'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        urlShow: <?php echo json_encode(t('pet_overlay_url_show'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        urlHide: <?php echo json_encode(t('pet_overlay_url_hide'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        urlCopied: <?php echo json_encode(t('pet_overlay_url_copied'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        confirmDelete: <?php echo json_encode(t('pet_confirm_delete'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        noFile: <?php echo json_encode(t('media_no_files_selected'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>
    };
    const petUrlReal = <?php echo json_encode($overlayLinkWithCode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    const petUrlMasked = <?php echo json_encode($overlayLinkMasked, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    const petIdleFallback = <?php echo json_encode((string) $pet['idle_animation'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    const hasRedemptionOptions = <?php echo !empty($petRewards) ? 'true' : 'false'; ?>;

    const postPet = (fields) => {
        const fd = new FormData();
        Object.keys(fields).forEach((key) => {
            const val = fields[key];
            if (val !== undefined && val !== null) {
                fd.set(key, val);
            }
        });
        return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then((r) => r.json());
    };

    const setStatus = (el, ok, text) => {
        if (!el) return;
        el.textContent = text || (ok ? petLang.saved : petLang.saveError);
        el.className = 'pet-page-save-status ' + (ok ? 'is-success' : 'is-error');
    };

    const applyStorageBar = (data) => {
        if (!data || typeof data.storage_used !== 'number' || typeof data.max_storage !== 'number') return;
        const usedMb = (data.storage_used / 1024 / 1024).toFixed(2);
        const maxMb = (data.max_storage / 1024 / 1024).toFixed(2);
        const pct = typeof data.storage_percentage === 'number'
            ? data.storage_percentage.toFixed(2)
            : ((data.storage_used / data.max_storage) * 100).toFixed(2);
        const textEl = document.getElementById('petStorageText');
        const progEl = document.getElementById('petStorageProgress');
        const barEl = document.getElementById('petStorageBar');
        if (textEl) textEl.textContent = usedMb + 'MB / ' + maxMb + 'MB (' + pct + '%)';
        if (progEl) progEl.value = pct;
        if (barEl) barEl.setAttribute('aria-busy', 'false');
    };
    const loadStorageBar = () => {
        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax_action', 'list');
        fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then((r) => r.json())
            .then((data) => {
                if (data && data.success) {
                    applyStorageBar(data);
                    return;
                }
                const barEl = document.getElementById('petStorageBar');
                if (barEl) barEl.setAttribute('aria-busy', 'false');
            })
            .catch(() => {
                const barEl = document.getElementById('petStorageBar');
                if (barEl) barEl.setAttribute('aria-busy', 'false');
            });
    };
    loadStorageBar();

    const petUrlBox = document.getElementById('petOverlayUrl');
    const petUrlReveal = document.getElementById('petUrlReveal');
    const petUrlCopy = document.getElementById('petUrlCopy');
    let urlRevealed = false;
    if (petUrlReveal && petUrlBox) {
        petUrlReveal.addEventListener('click', () => {
            urlRevealed = !urlRevealed;
            petUrlBox.textContent = urlRevealed ? petUrlReal : petUrlMasked;
            petUrlReveal.setAttribute('aria-pressed', urlRevealed ? 'true' : 'false');
            const lbl = petUrlReveal.querySelector('.pet-page-url-reveal-label');
            if (lbl) lbl.textContent = urlRevealed ? petLang.urlHide : petLang.urlShow;
            const icon = petUrlReveal.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !urlRevealed);
                icon.classList.toggle('fa-eye-slash', urlRevealed);
            }
        });
    }
    if (petUrlCopy) {
        petUrlCopy.addEventListener('click', () => {
            const showCopied = () => {
                const lbl = petUrlCopy.querySelector('.pet-page-url-copy-label');
                if (!lbl) return;
                const orig = lbl.textContent;
                lbl.textContent = petLang.urlCopied;
                setTimeout(() => { lbl.textContent = orig; }, 1500);
            };
            const fallbackCopy = () => {
                const ta = document.createElement('textarea');
                ta.value = petUrlReal;
                ta.setAttribute('class', 'pet-page-clipboard-helper');
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { document.execCommand('copy'); showCopied(); } catch (err) {}
                document.body.removeChild(ta);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(petUrlReal).then(showCopied).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        });
    }

    const enabledEl = document.getElementById('petEnabled');
    const enableStatus = document.getElementById('petEnableStatus');
    if (enabledEl) {
        enabledEl.addEventListener('change', () => {
            postPet({ pet_action: 'set_enabled', enabled: enabledEl.checked ? '1' : '0' })
                .then((data) => {
                    if (data && data.success) {
                        window.location.reload();
                        return;
                    }
                    enabledEl.checked = !enabledEl.checked;
                    setStatus(enableStatus, false, (data && data.error) || petLang.saveError);
                })
                .catch(() => {
                    enabledEl.checked = !enabledEl.checked;
                    setStatus(enableStatus, false, petLang.saveError);
                });
        });
    }

    const scaleEl = document.getElementById('petScale');
    const scaleVal = document.getElementById('petScaleVal');
    if (scaleEl && scaleVal) {
        scaleEl.addEventListener('input', () => { scaleVal.textContent = scaleEl.value; });
    }

    const settingsForm = document.getElementById('petSettingsForm');
    const saveStatus = document.getElementById('petSaveStatus');
    if (settingsForm) {
        settingsForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const visible = Array.from(document.querySelectorAll('.pet-page-visible-stat'))
                .filter((el) => el.checked)
                .map((el) => el.value)
                .join(',');
            postPet({
                pet_action: 'save_settings',
                pet_name: document.getElementById('petName')?.value || 'Pet',
                idle_animation: document.getElementById('petIdleAnimation')?.value || 'idle',
                position: document.getElementById('petPosition')?.value || 'bottom-right',
                scale: scaleEl ? scaleEl.value : '1',
                flip: document.getElementById('petFlip')?.checked ? '1' : '0',
                bubble_enabled: document.getElementById('petBubbleEnabled')?.checked ? '1' : '0',
                show_stats: document.getElementById('petShowStats')?.checked ? '1' : '0',
                visible_stats: visible,
                decay_happiness: document.getElementById('petDecayHappiness')?.value || '2',
                decay_hunger: document.getElementById('petDecayHunger')?.value || '3',
                decay_energy: document.getElementById('petDecayEnergy')?.value || '1',
                happiness: document.getElementById('petStartHappiness')?.value || '80',
                hunger: document.getElementById('petStartHunger')?.value || '80',
                energy: document.getElementById('petStartEnergy')?.value || '80'
            }).then((data) => {
                setStatus(saveStatus, !!(data && data.success), (data && (data.message || data.error)) || '');
            }).catch(() => setStatus(saveStatus, false, petLang.saveError));
        });
    }

    const canvas = document.getElementById('petPreviewCanvas');
    const sheetEl = document.getElementById('petPreviewSheet');
    const placeholder = document.getElementById('petPreviewPlaceholder');
    let previewRaf = 0;
    let previewObjectUrl = '';
    let previewImg = null;
    let previewState = null;

    const stopPreview = () => {
        if (previewRaf) {
            cancelAnimationFrame(previewRaf);
            previewRaf = 0;
        }
        previewState = null;
    };

    const hidePlaceholder = () => {
        if (placeholder) placeholder.classList.add('pet-page-hidden');
        if (canvas) canvas.classList.add('is-active');
    };

    const stepPreview = (now) => {
        if (!previewState || !previewImg || !canvas) return;
        const st = previewState;
        if (!st.last) st.last = now;
        st.acc += now - st.last;
        st.last = now;
        const frameMs = 1000 / Math.max(1, st.fps);
        while (st.acc >= frameMs) {
            st.acc -= frameMs;
            st.frame += 1;
            if (st.frame >= st.count) {
                if (!st.loop) {
                    st.frame = st.count - 1;
                    drawPreviewFrame();
                    stopPreview();
                    return;
                }
                st.frame = 0;
            }
        }
        drawPreviewFrame();
        previewRaf = requestAnimationFrame(stepPreview);
    };

    const drawPreviewFrame = () => {
        if (!previewState || !previewImg || !canvas) return;
        const st = previewState;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        canvas.width = st.fw;
        canvas.height = st.fh;
        ctx.clearRect(0, 0, st.fw, st.fh);
        const cols = Math.max(1, Math.floor(previewImg.naturalWidth / st.fw));
        const col = st.frame % cols;
        const row = Math.floor(st.frame / cols);
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(previewImg, col * st.fw, row * st.fh, st.fw, st.fh, 0, 0, st.fw, st.fh);
        if (sheetEl) {
            sheetEl.style.width = st.fw + 'px';
            sheetEl.style.height = st.fh + 'px';
            sheetEl.style.backgroundImage = 'url(' + JSON.stringify(previewImg.src) + ')';
            sheetEl.style.backgroundRepeat = 'no-repeat';
            sheetEl.style.backgroundSize = previewImg.naturalWidth + 'px ' + previewImg.naturalHeight + 'px';
            sheetEl.style.backgroundPosition = (-col * st.fw) + 'px ' + (-row * st.fh) + 'px';
        }
    };

    const startPreview = (url, fw, fh, count, fps, loop) => {
        stopPreview();
        if (!url) return;
        const img = new Image();
        img.onload = () => {
            previewImg = img;
            previewState = {
                fw: Math.max(1, fw | 0),
                fh: Math.max(1, fh | 0),
                count: Math.max(1, Math.min(64, count | 0)),
                fps: Math.max(1, fps | 0),
                loop: !!loop,
                frame: 0,
                acc: 0,
                last: 0
            };
            hidePlaceholder();
            drawPreviewFrame();
            previewRaf = requestAnimationFrame(stepPreview);
        };
        img.src = url;
    };

    const readPreviewInputs = () => {
        const fileEl = document.getElementById('petAnimSprite');
        const file = fileEl && fileEl.files && fileEl.files[0];
        if (!file) return;
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = URL.createObjectURL(file);
        startPreview(
            previewObjectUrl,
            parseInt(document.getElementById('petFrameWidth')?.value || '128', 10),
            parseInt(document.getElementById('petFrameHeight')?.value || '128', 10),
            parseInt(document.getElementById('petFrameCount')?.value || '1', 10),
            parseInt(document.getElementById('petFps')?.value || '12', 10),
            !!(document.getElementById('petAnimLoop')?.checked)
        );
    };

    const spriteInput = document.getElementById('petAnimSprite');
    const spriteZone = document.getElementById('petSpriteDropZone');
    const spriteLabel = document.getElementById('petSpriteFileLabel');
    const setSpriteFile = (file) => {
        if (!file || !spriteInput) return;
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            spriteInput.files = dt.files;
        } catch (err) {
            /* DataTransfer may be unavailable; change handler still runs from the picker */
        }
        if (spriteLabel) spriteLabel.textContent = file.name;
        readPreviewInputs();
    };
    if (spriteInput) {
        spriteInput.addEventListener('change', () => {
            const file = spriteInput.files && spriteInput.files[0];
            if (spriteLabel) spriteLabel.textContent = file ? file.name : petLang.noFile;
            readPreviewInputs();
        });
    }
    if (spriteZone && spriteInput) {
        ['dragenter', 'dragover'].forEach((evt) => {
            spriteZone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                spriteZone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend'].forEach((evt) => {
            spriteZone.addEventListener(evt, (e) => {
                e.preventDefault();
                spriteZone.classList.remove('is-dragover');
            });
        });
        spriteZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            spriteZone.classList.remove('is-dragover');
            const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) setSpriteFile(file);
        });
    }

    ['petFrameWidth', 'petFrameHeight', 'petFrameCount', 'petFps', 'petAnimLoop'].forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener(id === 'petAnimLoop' ? 'change' : 'input', readPreviewInputs);
    });

    document.querySelectorAll('.pet-page-anim-preview-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            startPreview(
                btn.getAttribute('data-url') || '',
                parseInt(btn.getAttribute('data-frame-width') || '128', 10),
                parseInt(btn.getAttribute('data-frame-height') || '128', 10),
                parseInt(btn.getAttribute('data-frame-count') || '1', 10),
                parseInt(btn.getAttribute('data-fps') || '12', 10),
                btn.getAttribute('data-loop') === '1'
            );
        });
    });

    const animForm = document.getElementById('petAnimForm');
    const animStatus = document.getElementById('petAnimStatus');
    if (animForm) {
        animForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const fileEl = document.getElementById('petAnimSprite');
            const file = fileEl && fileEl.files && fileEl.files[0];
            if (!file) {
                setStatus(animStatus, false, petLang.noFile);
                return;
            }
            const fd = new FormData();
            fd.set('pet_action', 'upload_animation');
            fd.set('name', document.getElementById('petAnimName')?.value || '');
            fd.set('frame_width', document.getElementById('petFrameWidth')?.value || '128');
            fd.set('frame_height', document.getElementById('petFrameHeight')?.value || '128');
            fd.set('frame_count', document.getElementById('petFrameCount')?.value || '1');
            fd.set('fps', document.getElementById('petFps')?.value || '12');
            fd.set('loop', document.getElementById('petAnimLoop')?.checked ? '1' : '0');
            fd.set('sprite', file);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    if (data && data.success) {
                        window.location.reload();
                        return;
                    }
                    setStatus(animStatus, false, (data && data.error) || petLang.saveError);
                })
                .catch(() => setStatus(animStatus, false, petLang.saveError));
        });
    }

    document.querySelectorAll('.pet-page-anim-delete-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!window.confirm(petLang.confirmDelete)) return;
            postPet({ pet_action: 'delete_animation', id: btn.getAttribute('data-id') || '0' })
                .then((data) => {
                    if (data && data.success) {
                        window.location.reload();
                        return;
                    }
                    window.alert((data && data.error) || petLang.saveError);
                })
                .catch(() => window.alert(petLang.saveError));
        });
    });

    const typeEl = document.getElementById('petTriggerType');
    const valueText = document.getElementById('petTriggerValue');
    const valueEvent = document.getElementById('petTriggerValueEvent');
    const valueInteraction = document.getElementById('petTriggerValueInteraction');
    const valueRedemption = document.getElementById('petTriggerValueRedemption');

    const syncTriggerValueFields = () => {
        const type = typeEl ? typeEl.value : 'chat_keyword';
        [valueText, valueEvent, valueInteraction, valueRedemption].forEach((el) => {
            if (el) el.classList.add('pet-page-hidden');
        });
        if (type === 'event' && valueEvent) {
            valueEvent.classList.remove('pet-page-hidden');
        } else if (type === 'interaction' && valueInteraction) {
            valueInteraction.classList.remove('pet-page-hidden');
        } else if (type === 'redemption' && valueRedemption && hasRedemptionOptions) {
            valueRedemption.classList.remove('pet-page-hidden');
        } else if (valueText) {
            valueText.classList.remove('pet-page-hidden');
        }
    };
    if (typeEl) {
        typeEl.addEventListener('change', syncTriggerValueFields);
        syncTriggerValueFields();
    }

    const currentTriggerValue = () => {
        const type = typeEl ? typeEl.value : 'chat_keyword';
        if (type === 'event' && valueEvent && !valueEvent.classList.contains('pet-page-hidden')) return valueEvent.value;
        if (type === 'interaction' && valueInteraction && !valueInteraction.classList.contains('pet-page-hidden')) return valueInteraction.value;
        if (type === 'redemption' && valueRedemption && !valueRedemption.classList.contains('pet-page-hidden')) return valueRedemption.value;
        return valueText ? valueText.value : '';
    };

    const triggerForm = document.getElementById('petTriggerForm');
    const triggerStatus = document.getElementById('petTriggerStatus');
    if (triggerForm) {
        triggerForm.addEventListener('submit', (e) => {
            e.preventDefault();
            postPet({
                pet_action: 'save_trigger',
                id: '0',
                trigger_type: typeEl ? typeEl.value : 'chat_keyword',
                trigger_value: currentTriggerValue(),
                animation: document.getElementById('petTriggerAnimation')?.value || 'idle',
                bubble_text: document.getElementById('petTriggerBubble')?.value || '',
                cooldown_seconds: document.getElementById('petTriggerCooldown')?.value || '5',
                effect_happiness: document.getElementById('petTriggerHappiness')?.value || '0',
                effect_hunger: document.getElementById('petTriggerHunger')?.value || '0',
                effect_energy: document.getElementById('petTriggerEnergy')?.value || '0',
                xp: document.getElementById('petTriggerXp')?.value || '0',
                enabled: document.getElementById('petTriggerEnabled')?.checked ? '1' : '0'
            }).then((data) => {
                if (data && data.success) {
                    window.location.reload();
                    return;
                }
                setStatus(triggerStatus, false, (data && data.error) || petLang.saveError);
            }).catch(() => setStatus(triggerStatus, false, petLang.saveError));
        });
    }

    const collectRow = (row) => ({
        pet_action: 'save_trigger',
        id: row.getAttribute('data-id') || '0',
        trigger_type: row.querySelector('.pet-trig-type')?.value || 'chat_keyword',
        trigger_value: row.querySelector('.pet-trig-value')?.value || '',
        animation: row.querySelector('.pet-trig-animation')?.value || 'idle',
        bubble_text: row.querySelector('.pet-trig-bubble')?.value || '',
        cooldown_seconds: row.querySelector('.pet-trig-cooldown')?.value || '5',
        effect_happiness: row.querySelector('.pet-trig-happiness')?.value || '0',
        effect_hunger: row.querySelector('.pet-trig-hunger')?.value || '0',
        effect_energy: row.querySelector('.pet-trig-energy')?.value || '0',
        xp: row.querySelector('.pet-trig-xp')?.value || '0',
        enabled: row.querySelector('.pet-trig-enabled')?.checked ? '1' : '0'
    });

    const rowTimers = new WeakMap();
    const scheduleRowSave = (row) => {
        const prev = rowTimers.get(row);
        if (prev) clearTimeout(prev);
        rowTimers.set(row, setTimeout(() => {
            postPet(collectRow(row)).catch(() => {});
        }, 400));
    };

    document.querySelectorAll('#petTriggerBody tr').forEach((row) => {
        row.querySelectorAll('input, select').forEach((el) => {
            el.addEventListener('change', () => scheduleRowSave(row));
        });
        const testBtn = row.querySelector('.pet-trig-test');
        if (testBtn) {
            testBtn.addEventListener('click', () => {
                postPet({
                    pet_action: 'test_reaction',
                    animation: row.querySelector('.pet-trig-animation')?.value || 'idle',
                    bubble_text: row.querySelector('.pet-trig-bubble')?.value || '',
                    trigger_type: row.querySelector('.pet-trig-type')?.value || '',
                    trigger_value: row.querySelector('.pet-trig-value')?.value || '',
                    effect_happiness: row.querySelector('.pet-trig-happiness')?.value || '0',
                    effect_hunger: row.querySelector('.pet-trig-hunger')?.value || '0',
                    effect_energy: row.querySelector('.pet-trig-energy')?.value || '0',
                    xp: row.querySelector('.pet-trig-xp')?.value || '0'
                }).catch(() => {});
            });
        }
        const delBtn = row.querySelector('.pet-trig-delete');
        if (delBtn) {
            delBtn.addEventListener('click', () => {
                if (!window.confirm(petLang.confirmDelete)) return;
                postPet({ pet_action: 'delete_trigger', id: row.getAttribute('data-id') || '0' })
                    .then((data) => {
                        if (data && data.success) {
                            window.location.reload();
                            return;
                        }
                        window.alert((data && data.error) || petLang.saveError);
                    })
                    .catch(() => window.alert(petLang.saveError));
            });
        }
    });

    const testIdle = document.getElementById('petTestIdle');
    if (testIdle) {
        testIdle.addEventListener('click', () => {
            const idleName = document.getElementById('petIdleAnimation')?.value || petIdleFallback || 'idle';
            postPet({ pet_action: 'test_reaction', animation: idleName }).catch(() => {});
        });
    }

    const testPet = document.getElementById('petTestPet');
    if (testPet) {
        testPet.addEventListener('click', () => {
            postPet({ pet_action: 'pet_the_pet' }).catch(() => {});
        });
    }
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
