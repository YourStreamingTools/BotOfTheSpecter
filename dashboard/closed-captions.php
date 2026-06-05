<?php
ob_start(); // Capture include output so it can't corrupt POST JSON responses
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
session_write_close();

$pageTitle = t('closed_captions_page_title');

$overlayLink = 'https://overlay.botofthespecter.com/closed-captions.php';
$overlayLinkWithCode = $overlayLink . '?code=' . rawurlencode($api_key);
// Masked form shown by default so the key isn't exposed on screen-share; reveal/copy in JS.
$overlayLinkMasked = $overlayLink . '?code=' . str_repeat('•', 24);

$allowedPositions = ['top', 'center', 'bottom'];
$allowedBackgrounds = ['box', 'outline', 'none'];
$allowedLanguages = ['en-US', 'en-GB', 'en-AU', 'de-DE', 'fr-FR', 'es-ES', 'it-IT', 'pt-BR', 'nl-NL', 'ja-JP'];

// Settings load + save
$cc = [
    'enabled' => 1,
    'language' => 'en-US',
    'font_size' => 32,
    'text_color' => '#FFFFFF',
    'background_style' => 'box',
    'position' => 'bottom',
    'max_lines' => 2,
    'fade_seconds' => 5,
    'profanity_filter' => 0,
    'action_tags_enabled' => 0,
];
$ccStmt = $db->prepare("SELECT enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter, action_tags_enabled FROM closed_captions_settings WHERE id = 1");
if ($ccStmt) {
    $ccStmt->execute();
    $ccResult = $ccStmt->get_result();
    if ($ccResult->num_rows > 0) {
        $cc = array_merge($cc, $ccResult->fetch_assoc());
    }
    $ccStmt->close();
}

// Handle settings save (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cc_save'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $language = in_array($_POST['language'] ?? 'en-US', $allowedLanguages, true) ? $_POST['language'] : 'en-US';
    $fontSize = max(12, min(120, intval($_POST['font_size'] ?? 32)));
    $textColor = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['text_color'] ?? '') ? $_POST['text_color'] : '#FFFFFF';
    $background = in_array($_POST['background_style'] ?? 'box', $allowedBackgrounds, true) ? $_POST['background_style'] : 'box';
    $position = in_array($_POST['position'] ?? 'bottom', $allowedPositions, true) ? $_POST['position'] : 'bottom';
    $maxLines = max(1, min(5, intval($_POST['max_lines'] ?? 2)));
    $fadeSeconds = max(0, min(60, intval($_POST['fade_seconds'] ?? 5)));
    $profanity = !empty($_POST['profanity_filter']) ? 1 : 0;
    $actionTags = !empty($_POST['action_tags_enabled']) ? 1 : 0;
    $saveStmt = $db->prepare("INSERT INTO closed_captions_settings (id, enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter, action_tags_enabled) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), language = VALUES(language), font_size = VALUES(font_size), text_color = VALUES(text_color), background_style = VALUES(background_style), position = VALUES(position), max_lines = VALUES(max_lines), fade_seconds = VALUES(fade_seconds), profanity_filter = VALUES(profanity_filter), action_tags_enabled = VALUES(action_tags_enabled)");
    if (!$saveStmt) {
        echo json_encode(['success' => false, 'error' => $db->error]);
        exit;
    }
    $saveStmt->bind_param("isisssiiii", $enabled, $language, $fontSize, $textColor, $background, $position, $maxLines, $fadeSeconds, $profanity, $actionTags);
    if ($saveStmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $saveStmt->error]);
    }
    $saveStmt->close();
    exit;
}

// Discard any stray include output before rendering the page body
while (ob_get_level()) { ob_end_clean(); }

$languageLabels = [
    'en-US' => 'English (US)',
    'en-GB' => 'English (UK)',
    'en-AU' => 'English (Australia)',
    'de-DE' => 'Deutsch (Deutschland)',
    'fr-FR' => 'Français (France)',
    'es-ES' => 'Español (España)',
    'it-IT' => 'Italiano (Italia)',
    'pt-BR' => 'Português (Brasil)',
    'nl-NL' => 'Nederlands',
    'ja-JP' => '日本語',
];

ob_start();
?>
<div class="sp-page-header">
    <h1><i class="fas fa-closed-captioning"></i> <?= t('closed_captions_page_title') ?></h1>
    <p><?= t('closed_captions_intro_description') ?></p>
</div>

<!-- Overlay URL (top of page; API key masked by default) -->
<div class="sp-card cc-url-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-link"></i> <?= t('closed_captions_overlay_url_title') ?></div>
    </div>
    <div class="sp-card-body">
        <p class="cc-help-text"><?= t('closed_captions_overlay_url_desc') ?></p>
        <div class="cc-url-row">
            <code class="info-box cc-url-box" id="ccOverlayUrl"><?= htmlspecialchars($overlayLinkMasked) ?></code>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-secondary" id="ccUrlReveal" aria-pressed="false"><i class="fas fa-eye"></i> <span class="cc-url-reveal-label"><?= t('closed_captions_overlay_url_show') ?></span></button>
            <button type="button" class="sp-btn sp-btn-sm sp-btn-primary" id="ccUrlCopy"><i class="fas fa-copy"></i> <span class="cc-url-copy-label"><?= t('closed_captions_overlay_url_copy') ?></span></button>
        </div>
    </div>
</div>

<div class="sp-alert sp-alert-info cc-browser-note">
    <span class="cc-browser-note-icon"><i class="fas fa-circle-info"></i></span>
    <div>
        <p class="cc-browser-note-title"><?= t('closed_captions_browser_note_title') ?></p>
        <p class="cc-browser-note-body"><?= t('closed_captions_browser_note_body') ?></p>
    </div>
</div>

<div class="cc-layout">
    <!-- Captioner control -->
    <div class="sp-card">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-microphone"></i> <?= t('closed_captions_captioner_title') ?></div>
            <span class="status-indicator offline" id="ccMicStatus"><?= t('closed_captions_status_idle') ?></span>
        </div>
        <div class="sp-card-body">
            <p class="cc-help-text"><?= t('closed_captions_captioner_desc') ?></p>
            <div id="ccUnsupported" class="sp-alert sp-alert-warning cc-hidden">
                <span class="cc-browser-note-icon"><i class="fas fa-triangle-exclamation"></i></span>
                <div><?= t('closed_captions_unsupported') ?></div>
            </div>
            <div class="cc-control-row">
                <button type="button" id="ccStartBtn" class="sp-btn sp-btn-success sp-btn-block">
                    <i class="fas fa-play"></i> <?= t('closed_captions_start') ?>
                </button>
                <button type="button" id="ccStopBtn" class="sp-btn sp-btn-danger sp-btn-block" disabled>
                    <i class="fas fa-stop"></i> <?= t('closed_captions_stop') ?>
                </button>
            </div>
            <div class="cc-sound-status cc-hidden" id="ccSoundStatus"></div>
            <div class="cc-preview-wrap">
                <div class="cc-preview-label"><?= t('closed_captions_live_preview') ?></div>
                <div class="cc-preview" id="ccPreview">
                    <span class="cc-preview-placeholder"><?= t('closed_captions_preview_placeholder') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Appearance & behaviour -->
    <div class="sp-card">
        <div class="sp-card-header">
            <div class="sp-card-title"><i class="fas fa-sliders"></i> <?= t('closed_captions_appearance_title') ?></div>
            <a href="<?= htmlspecialchars($overlayLinkWithCode) ?>" target="_blank" rel="noopener" class="sp-btn sp-btn-sm sp-btn-secondary" title="<?= htmlspecialchars(t('closed_captions_open_overlay')) ?>"><i class="fas fa-external-link-alt"></i></a>
        </div>
        <div class="sp-card-body">
            <form id="ccSettingsForm">
                <div class="sp-form-group">
                    <label class="switch">
                        <input type="checkbox" id="ccEnabled" name="enabled" value="1" <?= $cc['enabled'] ? 'checked' : '' ?>>
                        <span><?= t('closed_captions_enabled_label') ?></span>
                    </label>
                    <span class="sp-help"><?= t('closed_captions_enabled_help') ?></span>
                </div>
                <div class="cc-form-grid">
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccLanguage"><?= t('closed_captions_language_label') ?></label>
                        <select id="ccLanguage" name="language" class="sp-select">
                            <?php foreach ($allowedLanguages as $langCode): ?>
                                <option value="<?= htmlspecialchars($langCode) ?>" <?= ($cc['language'] === $langCode) ? 'selected' : '' ?>><?= htmlspecialchars($languageLabels[$langCode] ?? $langCode) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sp-help"><?= t('closed_captions_language_help') ?></span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccFontSize"><?= t('closed_captions_font_size_label') ?></label>
                        <input type="number" id="ccFontSize" name="font_size" class="sp-input" min="12" max="120" value="<?= intval($cc['font_size']) ?>">
                        <span class="sp-help"><?= t('closed_captions_font_size_help') ?></span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccTextColor"><?= t('closed_captions_text_color_label') ?></label>
                        <input type="color" id="ccTextColor" name="text_color" class="cc-color-input" value="<?= htmlspecialchars($cc['text_color']) ?>">
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccBackground"><?= t('closed_captions_background_label') ?></label>
                        <select id="ccBackground" name="background_style" class="sp-select">
                            <option value="box" <?= ($cc['background_style'] === 'box') ? 'selected' : '' ?>><?= t('closed_captions_background_box') ?></option>
                            <option value="outline" <?= ($cc['background_style'] === 'outline') ? 'selected' : '' ?>><?= t('closed_captions_background_outline') ?></option>
                            <option value="none" <?= ($cc['background_style'] === 'none') ? 'selected' : '' ?>><?= t('closed_captions_background_none') ?></option>
                        </select>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccPosition"><?= t('closed_captions_position_label') ?></label>
                        <select id="ccPosition" name="position" class="sp-select">
                            <option value="bottom" <?= ($cc['position'] === 'bottom') ? 'selected' : '' ?>><?= t('closed_captions_position_bottom') ?></option>
                            <option value="center" <?= ($cc['position'] === 'center') ? 'selected' : '' ?>><?= t('closed_captions_position_center') ?></option>
                            <option value="top" <?= ($cc['position'] === 'top') ? 'selected' : '' ?>><?= t('closed_captions_position_top') ?></option>
                        </select>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccMaxLines"><?= t('closed_captions_max_lines_label') ?></label>
                        <input type="number" id="ccMaxLines" name="max_lines" class="sp-input" min="1" max="5" value="<?= intval($cc['max_lines']) ?>">
                        <span class="sp-help"><?= t('closed_captions_max_lines_help') ?></span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccFadeSeconds"><?= t('closed_captions_fade_seconds_label') ?></label>
                        <input type="number" id="ccFadeSeconds" name="fade_seconds" class="sp-input" min="0" max="60" value="<?= intval($cc['fade_seconds']) ?>">
                        <span class="sp-help"><?= t('closed_captions_fade_seconds_help') ?></span>
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="switch">
                        <input type="checkbox" id="ccProfanity" name="profanity_filter" value="1" <?= $cc['profanity_filter'] ? 'checked' : '' ?>>
                        <span><?= t('closed_captions_profanity_label') ?></span>
                    </label>
                    <span class="sp-help"><?= t('closed_captions_profanity_help') ?></span>
                </div>
                <div class="sp-form-group">
                    <label class="switch">
                        <input type="checkbox" id="ccActionTags" name="action_tags_enabled" value="1" <?= $cc['action_tags_enabled'] ? 'checked' : '' ?>>
                        <span><?= t('closed_captions_action_tags_label') ?></span>
                    </label>
                    <span class="sp-help"><?= t('closed_captions_action_tags_help') ?></span>
                </div>
                <div class="cc-save-row">
                    <span id="ccSaveStatus" class="cc-save-status"></span>
                    <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-save"></i> <?= t('closed_captions_save') ?></button>
                </div>
            </form>
        </div>
    </div>

</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
<script>
(function () {
    const apiKey = <?php echo json_encode($api_key); ?>;
    const ccActionTagsEnabled = <?php echo json_encode((bool)$cc['action_tags_enabled']); ?>;
    const ccLang = {
        listening: <?php echo json_encode(t('closed_captions_status_listening')); ?>,
        idle: <?php echo json_encode(t('closed_captions_status_idle')); ?>,
        starting: <?php echo json_encode(t('closed_captions_status_starting')); ?>,
        micDenied: <?php echo json_encode(t('closed_captions_error_mic_denied')); ?>,
        noSpeech: <?php echo json_encode(t('closed_captions_error_no_speech')); ?>,
        networkErr: <?php echo json_encode(t('closed_captions_error_network')); ?>,
        notConnected: <?php echo json_encode(t('closed_captions_error_not_connected')); ?>,
        saved: <?php echo json_encode(t('closed_captions_saved')); ?>,
        saveError: <?php echo json_encode(t('closed_captions_save_error')); ?>,
        previewPlaceholder: <?php echo json_encode(t('closed_captions_preview_placeholder')); ?>,
        urlShow: <?php echo json_encode(t('closed_captions_overlay_url_show')); ?>,
        urlHide: <?php echo json_encode(t('closed_captions_overlay_url_hide')); ?>,
        urlCopied: <?php echo json_encode(t('closed_captions_overlay_url_copied')); ?>,
        soundLoading: <?php echo json_encode(t('closed_captions_sound_loading')); ?>,
        soundOn: <?php echo json_encode(t('closed_captions_sound_on')); ?>,
        soundOff: <?php echo json_encode(t('closed_captions_sound_off')); ?>
    };

    // WebSocket (emit captions to the overlay)
    const socketUrl = 'wss://websocket.botofthespecter.com';
    let socket = null;
    let socketReady = false;
    let attempts = 0;
    const scheduleReconnect = () => {
        attempts += 1;
        const delay = Math.min(5000 * attempts, 30000);
        if (socket) { socket.removeAllListeners(); socket = null; }
        setTimeout(connect, delay);
    };
    function connect() {
        socket = io(socketUrl, { reconnection: false });
        socketReady = false;
        socket.on('connect', () => {
            attempts = 0;
            socketReady = true;
            socket.emit('REGISTER', { code: apiKey, channel: 'Dashboard', name: 'Closed Captions Dashboard' });
        });
        socket.on('disconnect', () => { socketReady = false; scheduleReconnect(); });
        socket.on('connect_error', () => { socketReady = false; scheduleReconnect(); });
    }
    connect();
    const emitCaption = (text, isFinal) => {
        if (socket && socketReady && socket.connected) {
            socket.emit('CLOSED_CAPTION', { code: apiKey, text: text, isFinal: isFinal });
        }
    };
    const emitClear = () => {
        if (socket && socketReady && socket.connected) {
            socket.emit('CLOSED_CAPTION_CLEAR', { code: apiKey });
        }
    };
    const emitActionTag = (tag) => {
        if (socket && socketReady && socket.connected) {
            socket.emit('CLOSED_CAPTION', { code: apiKey, text: tag, isFinal: true, action: true });
        }
    };

    // ---- Sound action-tag detection (YAMNet via TensorFlow.js) --------------
    // Entirely opt-in: when ccActionTagsEnabled is false, NONE of the TF.js / model
    // download / AudioWorklet code below ever runs (zero cost). The detector reuses
    // the proven POC recipe but upgrades capture from ScriptProcessorNode to an
    // AudioWorklet (processor defined via a Blob URL so this page stays self-contained).
    const soundDetector = (function () {
        const MODEL_URL = 'https://tfhub.dev/google/tfjs-model/yamnet/tfjs/1';
        const TARGET_SR = 16000;          // YAMNet requires 16 kHz mono
        const FRAME_SAMPLES = 15360;      // 0.96 s at 16 kHz (one YAMNet frame)
        const INFER_EVERY_MS = 500;       // run inference on the most recent ~0.96s every ~0.5s
        const TARGET_THRESHOLD = 0.4;     // fire threshold for target events
        const DEBOUNCE_MS = 1200;         // at most one tag per event per ~1.2s
        // YAMNet AudioSet class indices -> bracketed caption tag.
        const TARGETS = [
            { idx: 13, tag: '[LAUGHING]' },
            { idx: 42, tag: '[COUGH]' },
            { idx: 44, tag: '[SNEEZE]' },
            { idx: 62, tag: '[APPLAUSE]' }
        ];
        // AudioWorklet processor source: forwards mono Float32 frames to the main thread.
        const WORKLET_SRC = `
            class CCCaptureProcessor extends AudioWorkletProcessor {
                process(inputs) {
                    const input = inputs[0];
                    if (input && input[0] && input[0].length) {
                        this.port.postMessage(input[0].slice(0));
                    }
                    return true;
                }
            }
            registerProcessor('cc-capture-processor', CCCaptureProcessor);
        `;

        let tfLoading = null;             // promise for the one-time tf.min.js script load
        let model = null;
        let audioContext = null;
        let mediaStream = null;
        let sourceNode = null;
        let workletNode = null;
        let muteNode = null;
        let workletUrl = null;
        let ringBuffer = new Float32Array(FRAME_SAMPLES);
        let ringFilled = 0;
        let inferTimer = null;
        let inferBusy = false;
        let running = false;
        const lastFired = {};

        const statusEl = document.getElementById('ccSoundStatus');
        const setSoundStatus = (text, show) => {
            if (!statusEl) return;
            statusEl.textContent = text;
            statusEl.classList.toggle('cc-hidden', !show);
        };

        // Lazy-load TensorFlow.js (only ever called when action tags are enabled).
        const loadTf = () => {
            if (window.tf) return Promise.resolve();
            if (tfLoading) return tfLoading;
            tfLoading = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4/dist/tf.min.js';
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('tfjs load failed'));
                document.head.appendChild(s);
            });
            return tfLoading;
        };

        const ensureModel = async () => {
            if (model) return model;
            await loadTf();
            try { await tf.ready(); } catch (e) { /* backend will still init */ }
            model = await tf.loadGraphModel(MODEL_URL, { fromTFHub: true });
            // Warmup: first predict() compiles WebGL shaders. Dispose everything.
            try {
                const warm = tf.zeros([FRAME_SAMPLES], 'float32');
                const out = model.predict(warm);
                if (Array.isArray(out)) out.forEach(t => t.dispose()); else out.dispose();
                warm.dispose();
            } catch (e) { /* non-fatal */ }
            return model;
        };

        // Slide new samples into the ring buffer (keep the most recent FRAME_SAMPLES).
        const pushSamples = (input) => {
            const n = input.length;
            if (n >= FRAME_SAMPLES) {
                ringBuffer.set(input.subarray(n - FRAME_SAMPLES));
                ringFilled = FRAME_SAMPLES;
                return;
            }
            ringBuffer.copyWithin(0, n);
            ringBuffer.set(input, FRAME_SAMPLES - n);
            ringFilled = Math.min(FRAME_SAMPLES, ringFilled + n);
        };

        const runInference = async () => {
            if (!running || !model || inferBusy) return;
            if (ringFilled < FRAME_SAMPLES) return;
            inferBusy = true;
            const frame = ringBuffer.slice(0, FRAME_SAMPLES);
            let waveform = null, scores = null, embeddings = null, spectrogram = null, classScores = null;
            try {
                waveform = tf.tensor1d(frame, 'float32');
                const out = model.predict(waveform); // [scores, embeddings, log_mel_spectrogram]
                scores = out[0]; embeddings = out[1]; spectrogram = out[2];
                classScores = scores.mean(0);          // [521]
                const data = await classScores.data(); // Float32Array(521)
                const now = Date.now();
                for (const t of TARGETS) {
                    const score = data[t.idx] || 0;
                    if (score >= TARGET_THRESHOLD) {
                        if (!lastFired[t.tag] || now - lastFired[t.tag] > DEBOUNCE_MS) {
                            lastFired[t.tag] = now;
                            emitActionTag(t.tag);
                        }
                    }
                }
            } catch (e) {
                /* inference error: skip this frame */
            } finally {
                if (waveform) waveform.dispose();
                if (scores) scores.dispose();
                if (embeddings) embeddings.dispose();
                if (spectrogram) spectrogram.dispose();
                if (classScores) classScores.dispose();
                inferBusy = false;
            }
        };

        const start = async () => {
            if (!ccActionTagsEnabled || running) return;
            running = true;
            ringFilled = 0;
            setSoundStatus(ccLang.soundLoading, true);
            try {
                await ensureModel();
            } catch (e) {
                running = false;
                setSoundStatus(ccLang.soundOff, true);
                return;
            }
            if (!running) return; // stopped while the model was loading
            try {
                audioContext = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: TARGET_SR });
                mediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: { channelCount: 1, echoCancellation: true, noiseSuppression: false, autoGainControl: false }
                });
                if (!running) { teardown(); return; }
                if (audioContext.state === 'suspended') { try { await audioContext.resume(); } catch (e) {} }
                workletUrl = URL.createObjectURL(new Blob([WORKLET_SRC], { type: 'application/javascript' }));
                await audioContext.audioWorklet.addModule(workletUrl);
                sourceNode = audioContext.createMediaStreamSource(mediaStream);
                workletNode = new AudioWorkletNode(audioContext, 'cc-capture-processor');
                workletNode.port.onmessage = (e) => { if (running) pushSamples(e.data); };
                muteNode = audioContext.createGain();
                muteNode.gain.value = 0;
                sourceNode.connect(workletNode);
                workletNode.connect(muteNode);
                muteNode.connect(audioContext.destination);
                inferTimer = setInterval(runInference, INFER_EVERY_MS);
                setSoundStatus(ccLang.soundOn, true);
            } catch (e) {
                teardown();
                setSoundStatus(ccLang.soundOff, true);
            }
        };

        const teardown = () => {
            if (inferTimer) { clearInterval(inferTimer); inferTimer = null; }
            if (workletNode) { try { workletNode.port.onmessage = null; workletNode.disconnect(); } catch (e) {} workletNode = null; }
            if (sourceNode) { try { sourceNode.disconnect(); } catch (e) {} sourceNode = null; }
            if (muteNode) { try { muteNode.disconnect(); } catch (e) {} muteNode = null; }
            if (mediaStream) { mediaStream.getTracks().forEach(tr => tr.stop()); mediaStream = null; }
            if (audioContext) { try { audioContext.close(); } catch (e) {} audioContext = null; }
            if (workletUrl) { try { URL.revokeObjectURL(workletUrl); } catch (e) {} workletUrl = null; }
            ringFilled = 0;
            inferBusy = false;
        };

        const stop = () => {
            if (!running) { setSoundStatus('', false); return; }
            running = false;
            teardown();
            setSoundStatus(ccLang.soundOff, true);
        };

        return { start, stop };
    })();

    // DOM
    const startBtn = document.getElementById('ccStartBtn');
    const stopBtn = document.getElementById('ccStopBtn');
    const micStatus = document.getElementById('ccMicStatus');
    const preview = document.getElementById('ccPreview');
    const unsupported = document.getElementById('ccUnsupported');
    const langSelect = document.getElementById('ccLanguage');

    const setStatus = (text, state) => {
        if (!micStatus) return;
        micStatus.textContent = text;
        micStatus.className = 'status-indicator ' + state;
    };
    const setPreview = (committed, interim) => {
        if (!preview) return;
        const clean = (committed + ' ' + interim).trim();
        if (!clean) {
            preview.innerHTML = '<span class="cc-preview-placeholder">' + ccLang.previewPlaceholder + '</span>';
            return;
        }
        preview.textContent = '';
        if (committed) {
            const c = document.createElement('span');
            c.className = 'cc-preview-final';
            c.textContent = committed + ' ';
            preview.appendChild(c);
        }
        if (interim) {
            const i = document.createElement('span');
            i.className = 'cc-preview-interim';
            i.textContent = interim;
            preview.appendChild(i);
        }
    };

    // Web Speech API
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let runState = 'stopped'; // 'stopped' | 'started'
    let committedText = '';

    // Heuristic punctuation for finalized phrases (browser Web Speech adds none for free):
    // capitalize the first letter, and end with ? when the phrase opens with a question
    // word, otherwise a full stop. Applied to finals only, not the live interim text.
    const QUESTION_STARTERS = new Set([
        'what','whats','who','whos','whom','whose','where','wheres','when','whens',
        'why','whys','how','hows','which','is','are','am','was','were','do','does',
        'did','can','could','will','would','should','shall','may','might','have',
        'has','had','must','isnt','arent','dont','doesnt','didnt','cant','couldnt',
        'wont','wouldnt','shouldnt'
    ]);
    const punctuateFinal = (text) => {
        let t = String(text || '').trim();
        if (!t) return t;
        t = t.charAt(0).toUpperCase() + t.slice(1);
        if (/[.?!…]$/.test(t)) return t; // already ends with terminal punctuation
        const m = t.toLowerCase().match(/[a-z’']+/);
        const w = m ? m[0].replace(/['’]/g, '') : '';
        t += QUESTION_STARTERS.has(w) ? '?' : '.';
        return t;
    };

    if (!SR) {
        if (unsupported) unsupported.classList.remove('cc-hidden');
        if (startBtn) startBtn.disabled = true;
        return;
    }

    const buildRecognition = () => {
        const r = new SR();
        r.continuous = true;
        r.interimResults = true;
        r.lang = (langSelect && langSelect.value) ? langSelect.value : 'en-US';
        r.onstart = () => { setStatus(ccLang.listening, 'online'); };
        r.onresult = (event) => {
            let interim = '';
            let finalChunk = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;
                if (event.results[i].isFinal) {
                    finalChunk += transcript;
                } else {
                    interim += transcript;
                }
            }
            if (finalChunk.trim()) {
                committedText = punctuateFinal(finalChunk.trim());
                emitCaption(committedText, true);
                setPreview(committedText, '');
            }
            if (interim.trim()) {
                emitCaption(interim.trim(), false);
                setPreview(committedText, interim.trim());
            }
        };
        r.onerror = (event) => {
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                setStatus(ccLang.micDenied, 'offline');
                stop();
            } else if (event.error === 'no-speech') {
                setStatus(ccLang.noSpeech, 'warn');
            } else if (event.error === 'network') {
                setStatus(ccLang.networkErr, 'warn');
            } else if (event.error === 'aborted') {
                /* expected on manual stop — ignore */
            } else {
                setStatus(event.error, 'warn');
            }
        };
        r.onend = () => {
            // The Web Speech API auto-stops; restart while the user wants it running.
            if (runState === 'started') {
                try { recognition.start(); } catch (e) { /* already starting */ }
            } else {
                setStatus(ccLang.idle, 'offline');
            }
        };
        return r;
    };

    const start = async () => {
        setStatus(ccLang.starting, 'warn');
        // Prompt for mic permission explicitly so denial surfaces clearly.
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                stream.getTracks().forEach(track => track.stop());
            } catch (e) {
                setStatus(ccLang.micDenied, 'offline');
                return;
            }
        }
        committedText = '';
        recognition = buildRecognition();
        runState = 'started';
        try {
            recognition.start();
        } catch (e) {
            // start() throws if already started — treat as running.
        }
        setStatus(ccLang.listening, 'online');
        if (startBtn) startBtn.disabled = true;
        if (stopBtn) stopBtn.disabled = false;
        // Opt-in sound action-tag detection: only loads YAMNet/TF.js when enabled.
        if (ccActionTagsEnabled) { soundDetector.start(); }
    };

    const stop = () => {
        runState = 'stopped';
        if (recognition) {
            try { recognition.stop(); } catch (e) { /* noop */ }
        }
        soundDetector.stop();
        emitClear();
        committedText = '';
        setPreview('', '');
        setStatus(ccLang.idle, 'offline');
        if (startBtn) startBtn.disabled = false;
        if (stopBtn) stopBtn.disabled = true;
    };

    if (startBtn) startBtn.addEventListener('click', start);
    if (stopBtn) stopBtn.addEventListener('click', stop);
    if (langSelect) langSelect.addEventListener('change', () => {
        if (runState === 'started' && recognition) {
            recognition.lang = langSelect.value;
            // SpeechRecognition only reads .lang at start(), so restart to apply the new
            // language (and its spelling, e.g. en-AU "colour") immediately. onend restarts it.
            try { recognition.stop(); } catch (e) { /* onend will restart with the new lang */ }
        }
    });
    window.addEventListener('beforeunload', () => { if (runState === 'started') stop(); });

    // Settings save
    const form = document.getElementById('ccSettingsForm');
    const saveStatus = document.getElementById('ccSaveStatus');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            fd.append('cc_save', '1');
            // Unchecked checkboxes are absent from FormData; normalise.
            fd.set('enabled', document.getElementById('ccEnabled').checked ? '1' : '0');
            fd.set('profanity_filter', document.getElementById('ccProfanity').checked ? '1' : '0');
            fd.set('action_tags_enabled', document.getElementById('ccActionTags').checked ? '1' : '0');
            if (saveStatus) { saveStatus.textContent = ''; saveStatus.className = 'cc-save-status'; }
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!saveStatus) return;
                    if (data.success) {
                        saveStatus.textContent = ccLang.saved;
                        saveStatus.classList.add('is-success');
                    } else {
                        saveStatus.textContent = ccLang.saveError;
                        saveStatus.classList.add('is-error');
                    }
                })
                .catch(() => {
                    if (!saveStatus) return;
                    saveStatus.textContent = ccLang.saveError;
                    saveStatus.classList.add('is-error');
                });
        });
    }

    // Overlay URL: masked by default, reveal toggle, copy the real URL
    const ccUrlReal = <?php echo json_encode($overlayLinkWithCode); ?>;
    const ccUrlMasked = <?php echo json_encode($overlayLinkMasked); ?>;
    const ccUrlEl = document.getElementById('ccOverlayUrl');
    const ccUrlReveal = document.getElementById('ccUrlReveal');
    const ccUrlCopy = document.getElementById('ccUrlCopy');
    let ccUrlShown = false;
    if (ccUrlReveal && ccUrlEl) {
        ccUrlReveal.addEventListener('click', () => {
            ccUrlShown = !ccUrlShown;
            ccUrlEl.textContent = ccUrlShown ? ccUrlReal : ccUrlMasked;
            ccUrlReveal.setAttribute('aria-pressed', ccUrlShown ? 'true' : 'false');
            const lbl = ccUrlReveal.querySelector('.cc-url-reveal-label');
            if (lbl) lbl.textContent = ccUrlShown ? ccLang.urlHide : ccLang.urlShow;
            const ico = ccUrlReveal.querySelector('i');
            if (ico) ico.className = ccUrlShown ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }
    if (ccUrlCopy) {
        ccUrlCopy.addEventListener('click', () => {
            navigator.clipboard.writeText(ccUrlReal).then(() => {
                const lbl = ccUrlCopy.querySelector('.cc-url-copy-label');
                if (!lbl) return;
                const orig = lbl.textContent;
                lbl.textContent = ccLang.urlCopied;
                setTimeout(() => { lbl.textContent = orig; }, 1500);
            }).catch(() => {});
        });
    }
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
