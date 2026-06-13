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
// Caption (translation) target languages — Chrome on-device Translator SHORT (BCP47) codes.
// '' means "no translation / same as spoken".
$allowedTargetLanguages = ['', 'en', 'de', 'es', 'fr', 'it', 'pt', 'nl', 'ja', 'ko', 'zh', 'ru', 'pl', 'tr', 'uk', 'ar', 'hi', 'sv', 'da', 'fi', 'nb', 'cs', 'el', 'hu', 'ro', 'id', 'vi', 'th', 'bg', 'hr', 'sk', 'sl'];
// Caption typeface — curated Google Fonts (MUST match the overlay's allowed list). 'Inter' is the default.
$allowedFonts = ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Oswald', 'Raleway', 'Ubuntu', 'Nunito'];

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
    'target_language' => '',
    'font_family' => 'Inter',
];
$ccStmt = $db->prepare("SELECT enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter, action_tags_enabled, target_language, font_family FROM closed_captions_settings WHERE id = 1");
if (!$ccStmt) {
    // font_family is added by the schema migration in layout.php, which is included at the END
    // of this page — so on the very first load after deploy the column may not exist yet. Fall
    // back to reading without it so real saved settings still populate the form (never defaults).
    $ccStmt = $db->prepare("SELECT enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter, action_tags_enabled, target_language FROM closed_captions_settings WHERE id = 1");
}
if ($ccStmt) {
    $ccStmt->execute();
    $ccResult = $ccStmt->get_result();
    if ($ccResult->num_rows > 0) {
        $cc = array_merge($cc, $ccResult->fetch_assoc());
    }
    $ccStmt->close();
}

// Caption corrections (per-user glossary / fix-up dictionary) load
$ccCorrections = [];
$ccCorrStmt = $db->prepare("SELECT match_text, replace_text, match_mode, case_sensitive, enabled FROM closed_captions_corrections ORDER BY sort_order, id");
if ($ccCorrStmt) {
    $ccCorrStmt->execute();
    $ccCorrResult = $ccCorrStmt->get_result();
    while ($ccCorrRow = $ccCorrResult->fetch_assoc()) {
        $ccCorrections[] = [
            'match_text' => $ccCorrRow['match_text'],
            'replace_text' => $ccCorrRow['replace_text'],
            'match_mode' => ($ccCorrRow['match_mode'] === 'substring') ? 'substring' : 'word',
            'case_sensitive' => (int)$ccCorrRow['case_sensitive'] ? 1 : 0,
            'enabled' => (int)$ccCorrRow['enabled'] ? 1 : 0,
        ];
    }
    $ccCorrStmt->close();
}

// Handle caption corrections save (AJAX POST) — full-list replace-all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cc_corrections_save'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $rowsJson = $_POST['rows'] ?? '[]';
    $decoded = json_decode($rowsJson, true);
    if (!is_array($decoded)) {
        echo json_encode(['success' => false, 'error' => 'invalid_payload']);
        exit;
    }
    // Validate + normalise each row, capping the total processed to 300.
    $clean = [];
    foreach ($decoded as $row) {
        if (count($clean) >= 300) break;
        if (!is_array($row)) continue;
        $matchText = trim((string)($row['match_text'] ?? ''));
        $replaceText = trim((string)($row['replace_text'] ?? ''));
        if ($matchText === '' || $replaceText === '') continue;
        if (mb_strlen($matchText) > 255 || mb_strlen($replaceText) > 255) continue;
        $mode = (isset($row['match_mode']) && $row['match_mode'] === 'substring') ? 'substring' : 'word';
        $caseSensitive = !empty($row['case_sensitive']) ? 1 : 0;
        $enabled = (isset($row['enabled']) && !$row['enabled']) ? 0 : 1;
        $clean[] = [
            'match_text' => $matchText,
            'replace_text' => $replaceText,
            'match_mode' => $mode,
            'case_sensitive' => $caseSensitive,
            'enabled' => $enabled,
        ];
    }
    // Replace-all: clear existing rows, then insert the surviving set.
    if (!$db->query("DELETE FROM closed_captions_corrections")) {
        echo json_encode(['success' => false, 'error' => $db->error]);
        exit;
    }
    $insertStmt = $db->prepare("INSERT INTO closed_captions_corrections (match_text, replace_text, match_mode, case_sensitive, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$insertStmt) {
        echo json_encode(['success' => false, 'error' => $db->error]);
        exit;
    }
    $count = 0;
    foreach ($clean as $index => $entry) {
        $sortOrder = $index;
        // 6 placeholders / 6 vars / type string "sssiii": s,s,s,i,i,i
        $insertStmt->bind_param(
            "sssiii",
            $entry['match_text'],
            $entry['replace_text'],
            $entry['match_mode'],
            $entry['case_sensitive'],
            $entry['enabled'],
            $sortOrder
        );
        if ($insertStmt->execute()) {
            $count++;
        }
    }
    $insertStmt->close();
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
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
    // Caption (translation) target language: a Chrome Translator SHORT code or '' (off).
    $targetLang = in_array($_POST['target_language'] ?? '', $allowedTargetLanguages, true) ? ($_POST['target_language'] ?? '') : '';
    $fontFamily = in_array($_POST['font_family'] ?? 'Inter', $allowedFonts, true) ? $_POST['font_family'] : 'Inter';
    $saveStmt = $db->prepare("INSERT INTO closed_captions_settings (id, enabled, language, font_size, text_color, background_style, position, max_lines, fade_seconds, profanity_filter, action_tags_enabled, target_language, font_family) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), language = VALUES(language), font_size = VALUES(font_size), text_color = VALUES(text_color), background_style = VALUES(background_style), position = VALUES(position), max_lines = VALUES(max_lines), fade_seconds = VALUES(fade_seconds), profanity_filter = VALUES(profanity_filter), action_tags_enabled = VALUES(action_tags_enabled), target_language = VALUES(target_language), font_family = VALUES(font_family)");
    if (!$saveStmt) {
        echo json_encode(['success' => false, 'error' => $db->error]);
        exit;
    }
    // 12 placeholders / 12 vars / type string "isisssiiiiss" (12 chars):
    // i,s,i,s,s,s,i,i,i,i,s,s = enabled,language,font_size,text_color,background_style,position,max_lines,fade_seconds,profanity_filter,action_tags_enabled,target_language,font_family
    $saveStmt->bind_param("isisssiiiiss", $enabled, $language, $fontSize, $textColor, $background, $position, $maxLines, $fadeSeconds, $profanity, $actionTags, $targetLang, $fontFamily);
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

// Display labels for the caption (translation) target SHORT codes. '' is handled by the
// "Same as spoken" option in the markup, so it isn't listed here.
$targetLanguageLabels = [
    'en' => 'English',
    'de' => 'Deutsch',
    'es' => 'Español',
    'fr' => 'Français',
    'it' => 'Italiano',
    'pt' => 'Português',
    'nl' => 'Nederlands',
    'ja' => '日本語',
    'ko' => '한국어',
    'zh' => '中文',
    'ru' => 'Русский',
    'pl' => 'Polski',
    'tr' => 'Türkçe',
    'uk' => 'Українська',
    'ar' => 'العربية',
    'hi' => 'हिन्दी',
    'sv' => 'Svenska',
    'da' => 'Dansk',
    'fi' => 'Suomi',
    'nb' => 'Norsk (Bokmål)',
    'cs' => 'Čeština',
    'el' => 'Ελληνικά',
    'hu' => 'Magyar',
    'ro' => 'Română',
    'id' => 'Bahasa Indonesia',
    'vi' => 'Tiếng Việt',
    'th' => 'ไทย',
    'bg' => 'Български',
    'hr' => 'Hrvatski',
    'sk' => 'Slovenčina',
    'sl' => 'Slovenščina',
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
                        <label class="sp-label" for="ccLanguage"><?= t('closed_captions_spoken_language_label') ?></label>
                        <select id="ccLanguage" name="language" class="sp-select">
                            <?php foreach ($allowedLanguages as $langCode): ?>
                                <option value="<?= htmlspecialchars($langCode) ?>" <?= ($cc['language'] === $langCode) ? 'selected' : '' ?>><?= htmlspecialchars($languageLabels[$langCode] ?? $langCode) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sp-help"><?= t('closed_captions_spoken_language_help') ?></span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccTargetLanguage"><?= t('closed_captions_caption_language_label') ?></label>
                        <select id="ccTargetLanguage" name="target_language" class="sp-select">
                            <option value="" <?= ($cc['target_language'] === '') ? 'selected' : '' ?>><?= t('closed_captions_translate_off') ?></option>
                            <?php foreach ($allowedTargetLanguages as $tgtCode): ?>
                                <?php if ($tgtCode === '') continue; ?>
                                <option value="<?= htmlspecialchars($tgtCode) ?>" <?= ($cc['target_language'] === $tgtCode) ? 'selected' : '' ?>><?= htmlspecialchars($targetLanguageLabels[$tgtCode] ?? $tgtCode) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sp-help"><?= t('closed_captions_caption_language_help') ?></span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="ccFontFamily"><?= t('closed_captions_font_family_label') ?></label>
                        <select id="ccFontFamily" name="font_family" class="sp-select">
                            <?php foreach ($allowedFonts as $fontName): ?>
                                <option value="<?= htmlspecialchars($fontName) ?>" <?= ($cc['font_family'] === $fontName) ? 'selected' : '' ?>><?= htmlspecialchars($fontName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sp-help"><?= t('closed_captions_font_family_help') ?></span>
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

<!-- Caption corrections (per-user glossary / fix-up dictionary) -->
<div class="sp-card cc-corrections-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-spell-check"></i> <?= t('closed_captions_corrections_title') ?></div>
    </div>
    <div class="sp-card-body">
        <p class="cc-help-text"><?= t('closed_captions_corrections_help') ?></p>
        <div class="cc-corrections-table-wrap">
            <table class="cc-corrections-table">
                <thead>
                    <tr>
                        <th class="cc-corr-col-heard"><?= t('closed_captions_corrections_col_heard') ?></th>
                        <th class="cc-corr-arrow"></th>
                        <th class="cc-corr-col-correct"><?= t('closed_captions_corrections_col_correct') ?></th>
                        <th class="cc-corr-col-mode"><?= t('closed_captions_corrections_col_mode') ?></th>
                        <th class="cc-corr-col-toggle"><?= t('closed_captions_corrections_col_case') ?></th>
                        <th class="cc-corr-col-toggle"><?= t('closed_captions_corrections_col_on') ?></th>
                        <th class="cc-corr-col-actions"></th>
                    </tr>
                </thead>
                <tbody id="ccCorrBody"></tbody>
            </table>
            <div id="ccCorrEmpty" class="cc-corrections-empty cc-hidden"><?= t('closed_captions_corrections_empty') ?></div>
        </div>
        <div class="cc-corrections-actions">
            <button type="button" id="ccCorrAddBtn" class="sp-btn sp-btn-sm sp-btn-secondary">
                <i class="fas fa-plus"></i> <?= t('closed_captions_corrections_add_row') ?>
            </button>
            <div class="cc-corr-save-group">
                <span id="ccCorrSaveStatus" class="cc-save-status"></span>
                <button type="button" id="ccCorrSaveBtn" class="sp-btn sp-btn-primary">
                    <i class="fas fa-save"></i> <?= t('closed_captions_corrections_save') ?>
                </button>
            </div>
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
    let ccProfanityFilter = <?php echo json_encode((bool)$cc['profanity_filter']); ?>;
    // Curated caption fonts — derived from the PHP allow-list so this can't drift from
    // the overlay's copies. Used to load + preview the chosen typeface on the dashboard.
    const ccAllowedFonts = <?php echo json_encode($allowedFonts); ?>;
    // Caption (translation) target SHORT code; '' = no translation (same as spoken).
    // The spoken/source language is read live from the #ccLanguage select.
    const ccTargetLanguage = <?php echo json_encode($cc['target_language']); ?>;
    // Full corrections list (for the editor) and enabled-only subset (for the apply layer).
    const ccCorrections = <?php echo json_encode($ccCorrections); ?>;
    const ccCorrectionsEnabled = <?php echo json_encode(array_values(array_filter($ccCorrections, function ($c) { return !empty($c['enabled']); }))); ?>;
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
        soundOff: <?php echo json_encode(t('closed_captions_sound_off')); ?>,
        corrModeWord: <?php echo json_encode(t('closed_captions_corrections_mode_word')); ?>,
        corrModeSubstring: <?php echo json_encode(t('closed_captions_corrections_mode_substring')); ?>,
        corrHeardPlaceholder: <?php echo json_encode(t('closed_captions_corrections_heard_placeholder')); ?>,
        corrCorrectPlaceholder: <?php echo json_encode(t('closed_captions_corrections_correct_placeholder')); ?>,
        corrDeleteRow: <?php echo json_encode(t('closed_captions_corrections_delete_row')); ?>,
        corrSaved: <?php echo json_encode(t('closed_captions_corrections_saved')); ?>,
        corrSaveError: <?php echo json_encode(t('closed_captions_corrections_save_error')); ?>,
        translateDownloading: <?php echo json_encode(t('closed_captions_translate_downloading')); ?>,
        translateUnavailable: <?php echo json_encode(t('closed_captions_translate_unavailable')); ?>
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

    // Serialized final-caption emit. Each final is queued onto a single promise chain so a
    // slow translate (or an in-flight model download) can never reorder captions: finals
    // emit to the overlay in the exact order they were committed. When translation is off
    // or unavailable, the corrected text passes straight through. Action tags do NOT use
    // this path and are never translated.
    let translateChain = Promise.resolve();
    function emitFinalCaption(text) {
        translateChain = translateChain.then(async () => {
            let out = text;
            if (liveTranslator.isActive()) {
                out = await liveTranslator.translate(text);
            }
            emitCaption(out, true);
        });
    }

    // ---- Caption corrections (deterministic glossary / fix-up dictionary) ----
    // Builds at most two combined alternation regexes — one case-insensitive ('gi'),
    // one case-sensitive ('g') — from the enabled corrections, then rewrites finalised
    // captions verbatim to the stored replacement (canonical casing). Exact + whole-word
    // (or substring) matching only; no fuzzy/phonetic, no user regex. Cached; rebuilt only
    // when the source list changes.
    const correctionMatcher = (function () {
        const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        let cachedSig = null;
        let ciRegex = null, ciMap = null;   // case-insensitive group
        let csRegex = null, csMap = null;   // case-sensitive group

        const build = (list) => {
            const sig = JSON.stringify(list);
            if (sig === cachedSig) return; // unchanged: keep the cached matchers
            cachedSig = sig;
            ciRegex = csRegex = null;
            ciMap = new Map();
            csMap = new Map();
            const ciTerms = []; // { term, escaped }
            const csTerms = [];
            (list || []).forEach((c) => {
                if (!c) return;
                const matchText = String(c.match_text != null ? c.match_text : '');
                if (!matchText) return;
                const replaceText = String(c.replace_text != null ? c.replace_text : '');
                const mode = (c.match_mode === 'substring') ? 'substring' : 'word';
                const caseSensitive = !!(c.case_sensitive === 1 || c.case_sensitive === true || c.case_sensitive === '1');
                let escaped = escapeRegex(matchText);
                if (mode === 'word') escaped = '\\b' + escaped + '\\b';
                if (caseSensitive) {
                    const key = 'cs:' + matchText;
                    if (!csMap.has(key)) { csMap.set(key, replaceText); csTerms.push({ term: matchText, escaped: escaped }); }
                } else {
                    const key = 'ci:' + matchText.toLowerCase();
                    if (!ciMap.has(key)) { ciMap.set(key, replaceText); ciTerms.push({ term: matchText, escaped: escaped }); }
                }
            });
            // Longest match first so overlapping terms resolve to the most specific.
            const byLenDesc = (a, b) => b.term.length - a.term.length;
            csTerms.sort(byLenDesc);
            ciTerms.sort(byLenDesc);
            if (csTerms.length) {
                try { csRegex = new RegExp(csTerms.map(t => t.escaped).join('|'), 'g'); } catch (e) { csRegex = null; }
            }
            if (ciTerms.length) {
                try { ciRegex = new RegExp(ciTerms.map(t => t.escaped).join('|'), 'gi'); } catch (e) { ciRegex = null; }
            }
        };

        const apply = (text, list) => {
            build(list);
            if (!text) return text;
            let out = text;
            if (csRegex) {
                out = out.replace(csRegex, (m) => {
                    const v = csMap.get('cs:' + m);
                    return v != null ? v : m;
                });
            }
            if (ciRegex) {
                out = out.replace(ciRegex, (m) => {
                    const v = ciMap.get('ci:' + m.toLowerCase());
                    return v != null ? v : m;
                });
            }
            return out;
        };

        return { apply };
    })();

    // Active enabled corrections used by the apply layer. The editor swaps this in on save
    // so freshly-saved corrections take effect without a page reload.
    let ccActiveCorrections = ccCorrectionsEnabled.slice();
    const applyCorrections = (text) => {
        if (!ccActiveCorrections || !ccActiveCorrections.length) return text;
        return correctionMatcher.apply(text, ccActiveCorrections);
    };

    // ---- Live translation (Chrome/Edge on-device Translator API) ----------------
    // Translates the CORRECTED FINAL caption from the spoken language to the chosen
    // caption language, on-device, free, no API key (window.Translator, Chrome 138+/
    // Edge 148+). Gated: only active when ccTargetLanguage is set AND its SHORT code
    // differs from the spoken source's SHORT code. The overlay renders whatever text
    // arrives, so it is unchanged. If the API/model is missing, we emit the corrected
    // (untranslated) text so captions never stop, and surface a one-time notice.
    const shortCode = (lang) => String(lang || '').split('-')[0].toLowerCase();

    // Live target code: seeded from the saved setting, but updated in place when the user
    // saves a new caption language so translation can switch without a page reload (mirrors
    // how ccActiveCorrections is refreshed on save).
    let ccActiveTargetLanguage = ccTargetLanguage;

    // Reuse the sound-status area as the (non-blocking) translation notice surface.
    const ccTranslateStatusEl = document.getElementById('ccSoundStatus');
    const setTranslateNotice = (text, show) => {
        if (!ccTranslateStatusEl) return;
        if (show) {
            ccTranslateStatusEl.textContent = text;
            ccTranslateStatusEl.classList.remove('cc-hidden');
        } else {
            ccTranslateStatusEl.classList.add('cc-hidden');
        }
    };

    const liveTranslator = (function () {
        let translator = null;       // active Translator session
        let ready = false;           // true only when a session is live and usable
        let curSrc = null;           // SHORT source code the current session was built for
        let curTgt = null;           // SHORT target code the current session was built for
        let building = null;         // in-flight create() promise (prevents duplicate builds)
        let unavailableNoticeShown = false;

        const destroy = () => {
            if (translator) {
                try { if (typeof translator.destroy === 'function') translator.destroy(); } catch (e) { /* noop */ }
            }
            translator = null;
            ready = false;
            curSrc = null;
            curTgt = null;
        };

        // (Re)build a session for the given spoken-language region code (e.g. 'de-DE').
        // Returns a promise; on any failure leaves ready=false and falls back to passthrough.
        const ensure = async (sourceLang) => {
            const tgt = ccActiveTargetLanguage || '';
            const src = shortCode(sourceLang);
            // Gate: off when no target, or target equals source short code.
            if (!tgt || src === tgt) { destroy(); setTranslateNotice('', false); return; }
            // Already have a live session for this exact src/tgt pair.
            if (ready && translator && curSrc === src && curTgt === tgt) return;
            // Source or target changed: tear down the stale session before rebuilding.
            destroy();
            if (!('Translator' in self)) {
                ready = false;
                if (!unavailableNoticeShown) { unavailableNoticeShown = true; setTranslateNotice(ccLang.translateUnavailable, true); }
                return;
            }
            const params = { sourceLanguage: src, targetLanguage: tgt };
            building = (async () => {
                try {
                    const avail = await Translator.availability(params);
                    if (avail === 'unavailable') {
                        ready = false;
                        if (!unavailableNoticeShown) { unavailableNoticeShown = true; setTranslateNotice(ccLang.translateUnavailable, true); }
                        return;
                    }
                    // 'available' | 'downloadable' | 'downloading' -> create (downloads model if needed).
                    const session = await Translator.create({
                        sourceLanguage: src,
                        targetLanguage: tgt,
                        monitor(m) {
                            m.addEventListener('downloadprogress', (e) => {
                                const pct = Math.round((e.loaded || 0) * 100);
                                setTranslateNotice(ccLang.translateDownloading + pct + '%', true);
                            });
                        }
                    });
                    translator = session;
                    curSrc = src;
                    curTgt = tgt;
                    ready = true;
                    setTranslateNotice('', false); // model ready: clear the downloading notice
                } catch (e) {
                    destroy();
                    if (!unavailableNoticeShown) { unavailableNoticeShown = true; setTranslateNotice(ccLang.translateUnavailable, true); }
                }
            })();
            try { await building; } finally { building = null; }
        };

        // Translate one corrected final. Falls back to the input text on any error so
        // captions never stall; returns the (possibly translated) string.
        const translate = async (text) => {
            if (!ready || !translator) return text;
            try {
                const out = await translator.translate(text);
                return (out != null && out !== '') ? out : text;
            } catch (e) {
                return text;
            }
        };

        const isActive = () => ready && !!translator;
        const stop = () => { destroy(); };

        return { ensure, translate, isActive, stop };
    })();

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
        // AudioWorklet processor source: resamples the device-native rate (e.g. 48 kHz) down
        // to TARGET_SR (16 kHz) by continuous linear interpolation, then posts mono Float32
        // frames to the main thread. Resampling here (instead of forcing the AudioContext to
        // 16 kHz) keeps the shared mic at its native rate so the speech recognizer's capture
        // is never disturbed. `sampleRate` is the worklet global = the context's real rate.
        const WORKLET_SRC = `
            class CCCaptureProcessor extends AudioWorkletProcessor {
                constructor() {
                    super();
                    this._ratio = sampleRate / ${TARGET_SR}; // input samples per output sample
                    this._pos = 0;   // fractional read cursor (virtual index 0 => previous block's last sample)
                    this._prev = 0;  // last input sample of the previous block, for boundary interpolation
                }
                process(inputs) {
                    const input = inputs[0];
                    if (!input || !input[0] || !input[0].length) return true;
                    const ch = input[0];
                    const n = ch.length;
                    const out = [];
                    while (this._pos < n) {
                        const i = Math.floor(this._pos);
                        const frac = this._pos - i;
                        const a = i === 0 ? this._prev : ch[i - 1];
                        const b = ch[i];
                        out.push(a + (b - a) * frac);
                        this._pos += this._ratio;
                    }
                    this._pos -= n;
                    this._prev = ch[n - 1];
                    if (out.length) this.port.postMessage(Float32Array.from(out));
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

        const start = async (deviceId) => {
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
                // Native-rate context (NOT forced to 16 kHz) + a fully RAW tap (no AEC/NS/AGC),
                // pinned to the recognizer's mic. Both prevent a device-level reconfigure that
                // would make the speech recognizer drop words. Downsampling happens in the worklet.
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const detAudio = { channelCount: 1, echoCancellation: false, noiseSuppression: false, autoGainControl: false };
                if (deviceId) detAudio.deviceId = { exact: deviceId };
                mediaStream = await navigator.mediaDevices.getUserMedia({ audio: detAudio });
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
    const fontSelect = document.getElementById('ccFontFamily');

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

    const ccLoadedPreviewFonts = new Set();
    const ensurePreviewFontLoaded = (fontName) => {
        if (!ccAllowedFonts.includes(fontName) || ccLoadedPreviewFonts.has(fontName)) return;
        ccLoadedPreviewFonts.add(fontName);
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + fontName.replace(/ /g, '+') + ':wght@400;500;600;700&display=swap';
        document.head.appendChild(link);
    };
    const applyPreviewFont = () => {
        if (!preview || !fontSelect) return;
        const fontName = ccAllowedFonts.includes(fontSelect.value) ? fontSelect.value : 'Inter';
        ensurePreviewFontLoaded(fontName);
        preview.style.fontFamily = '"' + fontName + '", "Segoe UI", system-ui, sans-serif';
    };
    if (fontSelect) fontSelect.addEventListener('change', applyPreviewFont);
    applyPreviewFont();

    // Web Speech API
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let runState = 'stopped'; // 'stopped' | 'started'
    let committedText = '';
    let detectorDeviceId = null; // mic the recognizer locked onto; the detector pins the same one

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
        r.profanityFilter = ccProfanityFilter;
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
                // Apply the correction/glossary dictionary to FINAL captions only (interim
                // results get overwritten, so correcting them would only cause flicker).
                committedText = applyCorrections(committedText);
                // Emit through the serialized translate helper: the overlay receives the
                // translated text (when live translation is active) in committed order, while
                // the local preview shows the CORRECTED SOURCE text immediately.
                emitFinalCaption(committedText);
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
                const at = stream.getAudioTracks()[0];
                detectorDeviceId = (at && at.getSettings) ? (at.getSettings().deviceId || null) : null;
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
        // Build the on-device translation session for the current spoken language (the Start
        // click is the user gesture the Translator API needs). No-ops / falls back gracefully
        // when translation is off, target == source, or the API/model is unavailable.
        const srcLang = (langSelect && langSelect.value) ? langSelect.value : 'en-US';
        liveTranslator.ensure(srcLang);
        // Opt-in sound action-tag detection: only loads YAMNet/TF.js when enabled.
        if (ccActionTagsEnabled) { soundDetector.start(detectorDeviceId); }
    };

    const stop = () => {
        runState = 'stopped';
        if (recognition) {
            try { recognition.stop(); } catch (e) { /* noop */ }
        }
        soundDetector.stop();
        liveTranslator.stop();
        setTranslateNotice('', false);
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
            // Rebuild the translation session for the new spoken source (or tear it down if
            // the new source short code now matches the target).
            liveTranslator.ensure(langSelect.value);
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
            fd.set('target_language', document.getElementById('ccTargetLanguage').value);
            if (saveStatus) { saveStatus.textContent = ''; saveStatus.className = 'cc-save-status'; }
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        // Push the new appearance settings to the overlay so it updates live
                        // (font size, colour, position, background) without an OBS source refresh.
                        if (socket && socketReady && socket.connected) {
                            socket.emit('CLOSED_CAPTION_SETTINGS', { code: apiKey });
                        }
                        // Apply the saved caption language to the live translator without a
                        // reload: update the active target and rebuild the session if running.
                        ccActiveTargetLanguage = document.getElementById('ccTargetLanguage').value || '';
                        if (runState === 'started') {
                            const srcLang = (langSelect && langSelect.value) ? langSelect.value : 'en-US';
                            liveTranslator.ensure(srcLang);
                        }
                        // Update the profanity filter live. SpeechRecognition reads the flag
                        // only at start(), so transparently restart recognition to apply it;
                        // the onend handler auto-restarts when runState === 'started'.
                        const newProfanity = document.getElementById('ccProfanity').checked;
                        if (newProfanity !== ccProfanityFilter) {
                            ccProfanityFilter = newProfanity;
                            if (runState === 'started' && recognition) {
                                try { recognition.stop(); } catch (e) { /* onend restarts with the new flag */ }
                            }
                        }
                    }
                    if (!saveStatus) return;
                    if (data && data.success) {
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

    // Caption corrections editor (render / add / delete / save)
    (function () {
        const body = document.getElementById('ccCorrBody');
        const emptyEl = document.getElementById('ccCorrEmpty');
        const addBtn = document.getElementById('ccCorrAddBtn');
        const saveBtn = document.getElementById('ccCorrSaveBtn');
        const saveStatusEl = document.getElementById('ccCorrSaveStatus');
        if (!body) return;

        const buildRow = (row) => {
            row = row || {};
            const tr = document.createElement('tr');
            tr.className = 'cc-corr-row';

            const heardTd = document.createElement('td');
            const heardInput = document.createElement('input');
            heardInput.type = 'text';
            heardInput.className = 'sp-input cc-corr-heard';
            heardInput.maxLength = 255;
            heardInput.placeholder = ccLang.corrHeardPlaceholder;
            heardInput.value = row.match_text != null ? row.match_text : '';
            heardTd.appendChild(heardInput);

            const arrowTd = document.createElement('td');
            arrowTd.className = 'cc-corr-arrow';
            const arrowIcon = document.createElement('i');
            arrowIcon.className = 'fas fa-arrow-right';
            arrowTd.appendChild(arrowIcon);

            const correctTd = document.createElement('td');
            const correctInput = document.createElement('input');
            correctInput.type = 'text';
            correctInput.className = 'sp-input cc-corr-correct';
            correctInput.maxLength = 255;
            correctInput.placeholder = ccLang.corrCorrectPlaceholder;
            correctInput.value = row.replace_text != null ? row.replace_text : '';
            correctTd.appendChild(correctInput);

            const modeTd = document.createElement('td');
            const modeSelect = document.createElement('select');
            modeSelect.className = 'sp-select cc-corr-mode';
            const optWord = document.createElement('option');
            optWord.value = 'word';
            optWord.textContent = ccLang.corrModeWord;
            const optSub = document.createElement('option');
            optSub.value = 'substring';
            optSub.textContent = ccLang.corrModeSubstring;
            modeSelect.appendChild(optWord);
            modeSelect.appendChild(optSub);
            modeSelect.value = (row.match_mode === 'substring') ? 'substring' : 'word';
            modeTd.appendChild(modeSelect);

            const caseTd = document.createElement('td');
            caseTd.className = 'cc-corr-col-toggle';
            const caseInput = document.createElement('input');
            caseInput.type = 'checkbox';
            caseInput.className = 'cc-corr-case';
            caseInput.checked = !!(row.case_sensitive === 1 || row.case_sensitive === true || row.case_sensitive === '1');
            caseTd.appendChild(caseInput);

            const enabledTd = document.createElement('td');
            enabledTd.className = 'cc-corr-col-toggle';
            const enabledInput = document.createElement('input');
            enabledInput.type = 'checkbox';
            enabledInput.className = 'cc-corr-enabled';
            // Default new/undefined rows to enabled.
            enabledInput.checked = (row.enabled === undefined) ? true : !!(row.enabled === 1 || row.enabled === true || row.enabled === '1');
            enabledTd.appendChild(enabledInput);

            const actionsTd = document.createElement('td');
            actionsTd.className = 'cc-corr-col-actions';
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'cc-corr-delete';
            delBtn.title = ccLang.corrDeleteRow;
            delBtn.setAttribute('aria-label', ccLang.corrDeleteRow);
            const delIcon = document.createElement('i');
            delIcon.className = 'fas fa-trash';
            delBtn.appendChild(delIcon);
            delBtn.addEventListener('click', () => { tr.remove(); updateEmpty(); });
            actionsTd.appendChild(delBtn);

            tr.appendChild(heardTd);
            tr.appendChild(arrowTd);
            tr.appendChild(correctTd);
            tr.appendChild(modeTd);
            tr.appendChild(caseTd);
            tr.appendChild(enabledTd);
            tr.appendChild(actionsTd);
            return tr;
        };

        const updateEmpty = () => {
            if (!emptyEl) return;
            emptyEl.classList.toggle('cc-hidden', body.children.length > 0);
        };

        const addRow = (row) => {
            body.appendChild(buildRow(row));
            updateEmpty();
        };

        const collectRows = () => {
            const rows = [];
            body.querySelectorAll('.cc-corr-row').forEach((tr) => {
                const matchText = (tr.querySelector('.cc-corr-heard').value || '').trim();
                const replaceText = (tr.querySelector('.cc-corr-correct').value || '').trim();
                if (!matchText || !replaceText) return; // skip incomplete rows
                rows.push({
                    match_text: matchText,
                    replace_text: replaceText,
                    match_mode: (tr.querySelector('.cc-corr-mode').value === 'substring') ? 'substring' : 'word',
                    case_sensitive: tr.querySelector('.cc-corr-case').checked ? 1 : 0,
                    enabled: tr.querySelector('.cc-corr-enabled').checked ? 1 : 0
                });
            });
            return rows;
        };

        // Initial render from the server-provided list.
        (ccCorrections || []).forEach(addRow);
        updateEmpty();

        if (addBtn) addBtn.addEventListener('click', () => addRow({}));

        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                const rows = collectRows();
                const fd = new FormData();
                fd.append('cc_corrections_save', '1');
                fd.append('rows', JSON.stringify(rows));
                if (saveStatusEl) { saveStatusEl.textContent = ''; saveStatusEl.className = 'cc-save-status'; }
                saveBtn.disabled = true;
                fetch(window.location.pathname, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        saveBtn.disabled = false;
                        if (data && data.success) {
                            // Refresh the live apply layer with the enabled subset.
                            ccActiveCorrections = rows.filter(r => r.enabled === 1);
                            if (saveStatusEl) {
                                saveStatusEl.textContent = ccLang.corrSaved;
                                saveStatusEl.classList.add('is-success');
                            }
                        } else if (saveStatusEl) {
                            saveStatusEl.textContent = ccLang.corrSaveError;
                            saveStatusEl.classList.add('is-error');
                        }
                    })
                    .catch(() => {
                        saveBtn.disabled = false;
                        if (saveStatusEl) {
                            saveStatusEl.textContent = ccLang.corrSaveError;
                            saveStatusEl.classList.add('is-error');
                        }
                    });
            });
        }
    })();

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
