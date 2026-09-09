<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('emote_vault_page_title');

require_once '/var/www/config/db_connect.php';
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/mod_access.php';
require_once __DIR__ . '/includes/emote_vault.php';
session_write_close();

$helixToken = emote_vault_bearer_token((string) ($oauth ?? ''));
if ($helixToken === '') {
    $helixToken = emote_vault_bearer_token((string) ($authToken ?? ''));
}
$helixClientId = trim((string) ($clientID ?? ''));
$channelTwitchId = (string) ($broadcasterID ?? $twitchUserId ?? '');
$channelLogin = (string) ($username ?? '');

$ajaxAction = (string) ($_GET['ajax_action'] ?? '');
if ($ajaxAction !== '') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

if ($ajaxAction === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = emote_vault_collect($channelTwitchId, $helixClientId, $helixToken, $channelLogin);
    echo json_encode([
        'success' => true,
        'emotes' => $payload['emotes'],
        'errors' => $payload['errors'],
        'zip_available' => class_exists('ZipArchive'),
        'zip_max' => EMOTE_VAULT_ZIP_MAX_ITEMS,
        'channel' => $channelLogin,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($ajaxAction === 'download') {
    $url = (string) ($_GET['url'] ?? '');
    $name = (string) ($_GET['name'] ?? 'emote');
    $ext = (string) ($_GET['ext'] ?? 'png');
    if (!emote_vault_stream_download($url, $name, $ext)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('emote_vault_download_failed')]);
    }
    exit();
}

if ($ajaxAction === 'zip') {
    ini_set('max_execution_time', '120');
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
    if ($items === []) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('emote_vault_zip_empty')]);
        exit();
    }
    if (count($items) > EMOTE_VAULT_ZIP_MAX_ITEMS) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('emote_vault_zip_too_many')]);
        exit();
    }
    if (!class_exists('ZipArchive')) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => t('emote_vault_zip_failed')]);
        exit();
    }
    $zipBase = emote_vault_safe_basename($channelLogin !== '' ? $channelLogin . '-emote-vault' : 'emote-vault');
    $zipPath = emote_vault_build_zip($items);
    if ($zipPath === null) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => t('emote_vault_zip_failed')]);
        exit();
    }
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . emote_vault_content_disposition($zipBase . '.zip'));
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    readfile($zipPath);
    @unlink($zipPath);
    exit();
}

ob_start();
?>
<div class="sp-page-header">
    <h1><?= htmlspecialchars(t('emote_vault_page_title'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars(t('emote_vault_intro'), ENT_QUOTES, 'UTF-8') ?></p>
</div>

<div id="emoteVaultErrors" class="emote-vault-errors" hidden></div>

<div class="sp-card emote-vault-card-wrap">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-icons"></i> <?= htmlspecialchars(t('emote_vault_page_title'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="sp-btn-group">
            <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="emoteVaultRefresh">
                <i class="fas fa-rotate"></i> <?= htmlspecialchars(t('emote_vault_refresh'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="emoteVaultZip" hidden>
                <i class="fas fa-file-zipper"></i> <?= htmlspecialchars(t('emote_vault_download_visible'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
    <div class="sp-card-body">
        <div class="sp-stat-row emote-vault-stats" id="emoteVaultStats" aria-busy="true">
            <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="sp-skeleton-stat" aria-hidden="true">
                <span class="sp-skeleton sp-skeleton-line w-55"></span>
                <span class="sp-skeleton sp-skeleton-value"></span>
            </div>
            <?php endfor; ?>
        </div>

        <div class="emote-vault-toolbar">
            <div class="sp-form-group emote-vault-search">
                <label class="sp-label" for="emoteVaultSearch"><?= htmlspecialchars(t('emote_vault_search'), ENT_QUOTES, 'UTF-8') ?></label>
                <input id="emoteVaultSearch" class="sp-input" type="search" autocomplete="off" placeholder="<?= htmlspecialchars(t('emote_vault_search_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="emote-vault-filters" role="tablist" aria-label="<?= htmlspecialchars(t('emote_vault_page_title'), ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" class="sp-btn sp-btn-primary sp-btn-sm emote-vault-filter is-active" data-source="all"><?= htmlspecialchars(t('emote_vault_filter_all'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm emote-vault-filter" data-source="twitch"><?= htmlspecialchars(t('emote_vault_filter_twitch'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm emote-vault-filter" data-source="7tv"><?= htmlspecialchars(t('emote_vault_filter_7tv'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm emote-vault-filter" data-source="bttv"><?= htmlspecialchars(t('emote_vault_filter_bttv'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm emote-vault-filter" data-source="ffz"><?= htmlspecialchars(t('emote_vault_filter_ffz'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
            <label class="emote-vault-animated">
                <input type="checkbox" id="emoteVaultAnimated">
                <span><?= htmlspecialchars(t('emote_vault_filter_animated'), ENT_QUOTES, 'UTF-8') ?></span>
            </label>
        </div>

        <p class="sp-text-muted emote-vault-status" id="emoteVaultStatus"><?= htmlspecialchars(t('emote_vault_loading'), ENT_QUOTES, 'UTF-8') ?></p>

        <div class="emote-vault-grid" id="emoteVaultGrid" aria-busy="true">
            <?php for ($i = 0; $i < 12; $i++): ?>
            <div class="emote-vault-item" aria-hidden="true">
                <div class="emote-vault-art"><span class="sp-skeleton-thumb emote-vault-skel-thumb"></span></div>
                <span class="sp-skeleton sp-skeleton-line w-70"></span>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="sp-modal-backdrop" id="emoteVaultModal">
    <div class="sp-modal emote-vault-modal" role="dialog" aria-modal="true" aria-labelledby="emoteVaultModalTitle">
        <div class="sp-modal-head">
            <h2 class="sp-modal-title" id="emoteVaultModalTitle"><?= htmlspecialchars(t('emote_vault_preview_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="sp-modal-close" id="emoteVaultModalClose" aria-label="<?= htmlspecialchars(t('emote_vault_close'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="sp-modal-body emote-vault-modal-body">
            <div class="emote-vault-modal-art" id="emoteVaultModalArt"></div>
            <p class="emote-vault-modal-name" id="emoteVaultModalName"></p>
            <p class="emote-vault-modal-meta" id="emoteVaultModalMeta"></p>
            <a class="sp-btn sp-btn-primary" id="emoteVaultModalDownload" href="#">
                <i class="fas fa-download"></i> <?= htmlspecialchars(t('emote_vault_download'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
(function () {
    const LANG = {
        loadFailed: <?= json_encode(t('emote_vault_load_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        empty: <?= json_encode(t('emote_vault_empty'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        none: <?= json_encode(t('emote_vault_none'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        loading: <?= json_encode(t('emote_vault_loading'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        download: <?= json_encode(t('emote_vault_download'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        downloading: <?= json_encode(t('emote_vault_downloading'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        downloadFailed: <?= json_encode(t('emote_vault_download_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        zipFailed: <?= json_encode(t('emote_vault_zip_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        zipEmpty: <?= json_encode(t('emote_vault_zip_empty'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        zipTooMany: <?= json_encode(t('emote_vault_zip_too_many'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        sourceError: <?= json_encode(t('emote_vault_source_error'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        statTotal: <?= json_encode(t('emote_vault_stat_total'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        statTwitch: <?= json_encode(t('emote_vault_stat_twitch'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        stat7tv: <?= json_encode(t('emote_vault_stat_7tv'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        statBttv: <?= json_encode(t('emote_vault_stat_bttv'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        statFfz: <?= json_encode(t('emote_vault_stat_ffz'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        kindSubscriptions: <?= json_encode(t('emote_vault_kind_subscriptions'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        kindFollower: <?= json_encode(t('emote_vault_kind_follower'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        kindBits: <?= json_encode(t('emote_vault_kind_bitstier'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        kindShared: <?= json_encode(t('emote_vault_kind_shared'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        kindChannel: <?= json_encode(t('emote_vault_kind_channel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        tier: <?= json_encode(t('emote_vault_tier'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        animated: <?= json_encode(t('emote_vault_animated'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        sourceTwitch: <?= json_encode(t('emote_vault_filter_twitch'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        source7tv: <?= json_encode(t('emote_vault_filter_7tv'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        sourceBttv: <?= json_encode(t('emote_vault_filter_bttv'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        sourceFfz: <?= json_encode(t('emote_vault_filter_ffz'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    };

    const SOURCE_LABELS = {
        twitch: LANG.sourceTwitch,
        '7tv': LANG.source7tv,
        bttv: LANG.sourceBttv,
        ffz: LANG.sourceFfz
    };
    const SOURCE_BADGE = {
        twitch: 'sp-badge-accent',
        '7tv': 'sp-badge-green',
        bttv: 'sp-badge-blue',
        ffz: 'sp-badge-amber'
    };

    const grid = document.getElementById('emoteVaultGrid');
    const statsEl = document.getElementById('emoteVaultStats');
    const statusEl = document.getElementById('emoteVaultStatus');
    const errorsEl = document.getElementById('emoteVaultErrors');
    const searchEl = document.getElementById('emoteVaultSearch');
    const animatedEl = document.getElementById('emoteVaultAnimated');
    const zipBtn = document.getElementById('emoteVaultZip');
    const refreshBtn = document.getElementById('emoteVaultRefresh');
    const modal = document.getElementById('emoteVaultModal');
    const modalTitle = document.getElementById('emoteVaultModalTitle');
    const modalArt = document.getElementById('emoteVaultModalArt');
    const modalName = document.getElementById('emoteVaultModalName');
    const modalMeta = document.getElementById('emoteVaultModalMeta');
    const modalDownload = document.getElementById('emoteVaultModalDownload');
    const modalClose = document.getElementById('emoteVaultModalClose');

    let allEmotes = [];
    let zipAvailable = false;
    let zipMax = 400;
    let channelName = <?= json_encode($channelLogin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    let activeSource = 'all';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function kindLabel(emote) {
        const kind = emote.kind || '';
        if (kind === 'subscriptions') {
            const tierRaw = String(emote.tier || '');
            const tierMap = { '1000': '1', '2000': '2', '3000': '3' };
            const n = tierMap[tierRaw] || (tierRaw ? String(parseInt(tierRaw, 10) || '') : '');
            if (n) return LANG.tier.replace('%s', n);
            return LANG.kindSubscriptions;
        }
        if (kind === 'follower') return LANG.kindFollower;
        if (kind === 'bitstier') return LANG.kindBits;
        if (kind === 'shared') return LANG.kindShared;
        return LANG.kindChannel;
    }

    function sourceLabel(source) {
        return SOURCE_LABELS[source] || source;
    }

    function downloadHref(emote) {
        const params = new URLSearchParams({
            ajax_action: 'download',
            url: emote.download_url || '',
            name: emote.name || 'emote',
            ext: emote.ext || 'png'
        });
        return 'emote-vault.php?' + params.toString();
    }

    function visibleEmotes() {
        const q = (searchEl.value || '').trim().toLowerCase();
        const animatedOnly = animatedEl.checked;
        return allEmotes.filter(function (emote) {
            if (activeSource !== 'all' && emote.source !== activeSource) return false;
            if (animatedOnly && !emote.animated) return false;
            if (q && String(emote.name || '').toLowerCase().indexOf(q) === -1) return false;
            return true;
        });
    }

    function setBusy(el, busy) {
        if (!el) return;
        if (busy) el.setAttribute('aria-busy', 'true');
        else el.removeAttribute('aria-busy');
    }

    function renderStats(list) {
        const counts = { twitch: 0, '7tv': 0, bttv: 0, ffz: 0 };
        list.forEach(function (emote) {
            if (counts[emote.source] != null) counts[emote.source]++;
        });
        const rows = [
            [LANG.statTotal, list.length],
            [LANG.statTwitch, counts.twitch],
            [LANG.stat7tv, counts['7tv']],
            [LANG.statBttv, counts.bttv],
            [LANG.statFfz, counts.ffz]
        ];
        statsEl.innerHTML = rows.map(function (row) {
            return '<div class="sp-stat"><span class="sp-stat-value">' + row[1] + '</span><span class="sp-stat-label">' + escapeHtml(row[0]) + '</span></div>';
        }).join('');
        setBusy(statsEl, false);
    }

    function renderErrors(errors) {
        errors = errors || {};
        const keys = Object.keys(errors);
        if (!keys.length) {
            errorsEl.hidden = true;
            errorsEl.innerHTML = '';
            return;
        }
        errorsEl.hidden = false;
        errorsEl.innerHTML = keys.map(function (source) {
            const label = sourceLabel(source);
            const msg = LANG.sourceError.replace('%s', label);
            return '<div class="sp-alert sp-alert-warning">' + escapeHtml(msg) + '</div>';
        }).join('');
    }

    function renderGrid() {
        const list = visibleEmotes();
        renderStats(allEmotes);
        if (!allEmotes.length) {
            statusEl.textContent = LANG.none;
            grid.innerHTML = '<p class="emote-vault-empty">' + escapeHtml(LANG.none) + '</p>';
            setBusy(grid, false);
            zipBtn.hidden = true;
            return;
        }
        if (!list.length) {
            statusEl.textContent = LANG.empty;
            grid.innerHTML = '<p class="emote-vault-empty">' + escapeHtml(LANG.empty) + '</p>';
            setBusy(grid, false);
            zipBtn.hidden = true;
            return;
        }
        statusEl.textContent = list.length + ' / ' + allEmotes.length;
        zipBtn.hidden = !zipAvailable || list.length === 0;
        grid.innerHTML = list.map(function (emote) {
            const badgeClass = SOURCE_BADGE[emote.source] || 'sp-badge-grey';
            const badges = [
                '<span class="sp-badge ' + badgeClass + '">' + escapeHtml(sourceLabel(emote.source)) + '</span>',
                '<span class="sp-badge sp-badge-grey">' + escapeHtml(kindLabel(emote)) + '</span>'
            ];
            if (emote.animated) {
                badges.push('<span class="sp-badge sp-badge-blue">' + escapeHtml(LANG.animated) + '</span>');
            }
            return '' +
                '<article class="emote-vault-item" data-id="' + escapeHtml(emote.id) + '">' +
                    '<button type="button" class="emote-vault-art" data-open="' + escapeHtml(emote.id) + '">' +
                        '<img src="' + escapeHtml(emote.preview_url) + '" alt="" loading="lazy">' +
                    '</button>' +
                    '<div class="emote-vault-name" title="' + escapeHtml(emote.name) + '">' + escapeHtml(emote.name) + '</div>' +
                    '<div class="emote-vault-badges">' + badges.join('') + '</div>' +
                    '<a class="sp-btn sp-btn-secondary sp-btn-sm emote-vault-dl" href="' + escapeHtml(downloadHref(emote)) + '">' +
                        '<i class="fas fa-download"></i> ' + escapeHtml(LANG.download) +
                    '</a>' +
                '</article>';
        }).join('');
        setBusy(grid, false);
    }

    function findEmote(id) {
        for (let i = 0; i < allEmotes.length; i++) {
            if (allEmotes[i].id === id) return allEmotes[i];
        }
        return null;
    }

    function openModal(emote) {
        modalTitle.textContent = emote.name;
        modalName.textContent = emote.name;
        const bits = [sourceLabel(emote.source), kindLabel(emote)];
        if (emote.animated) bits.push(LANG.animated);
        if (emote.width && emote.height) bits.push(emote.width + ' × ' + emote.height);
        modalMeta.textContent = bits.join(' · ');
        modalArt.innerHTML = '<img src="' + escapeHtml(emote.download_url) + '" alt="' + escapeHtml(emote.name) + '">';
        modalDownload.href = downloadHref(emote);
        modal.classList.add('is-active');
    }

    function closeModal() {
        modal.classList.remove('is-active');
        modalArt.innerHTML = '';
    }

    async function loadEmotes() {
        setBusy(grid, true);
        setBusy(statsEl, true);
        statusEl.textContent = LANG.loading;
        zipBtn.hidden = true;
        try {
            const res = await fetch('emote-vault.php?ajax_action=list', { credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.success) {
                throw new Error('bad payload');
            }
            allEmotes = Array.isArray(data.emotes) ? data.emotes : [];
            zipAvailable = !!data.zip_available;
            zipMax = parseInt(data.zip_max, 10) || 400;
            if (data.channel) channelName = data.channel;
            renderErrors(data.errors);
            renderGrid();
        } catch (err) {
            statusEl.textContent = LANG.loadFailed;
            grid.innerHTML = '<p class="emote-vault-empty">' + escapeHtml(LANG.loadFailed) + '</p>';
            setBusy(grid, false);
            setBusy(statsEl, false);
            if (typeof showNotification === 'function') {
                showNotification(LANG.loadFailed, 'danger');
            }
        }
    }

    document.querySelectorAll('.emote-vault-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.emote-vault-filter').forEach(function (other) {
                other.classList.remove('is-active', 'sp-btn-primary');
                other.classList.add('sp-btn-secondary');
            });
            btn.classList.add('is-active', 'sp-btn-primary');
            btn.classList.remove('sp-btn-secondary');
            activeSource = btn.getAttribute('data-source') || 'all';
            renderGrid();
        });
    });

    searchEl.addEventListener('input', renderGrid);
    animatedEl.addEventListener('change', renderGrid);
    refreshBtn.addEventListener('click', loadEmotes);

    grid.addEventListener('click', function (event) {
        const openBtn = event.target.closest('[data-open]');
        if (!openBtn) return;
        const emote = findEmote(openBtn.getAttribute('data-open'));
        if (emote) openModal(emote);
    });

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-active')) {
            closeModal();
        }
    });

    zipBtn.addEventListener('click', async function () {
        const list = visibleEmotes();
        if (!list.length) {
            if (typeof showNotification === 'function') showNotification(LANG.zipEmpty, 'warning');
            return;
        }
        if (list.length > zipMax) {
            if (typeof showNotification === 'function') showNotification(LANG.zipTooMany, 'warning');
            return;
        }
        zipBtn.disabled = true;
        const original = zipBtn.innerHTML;
        zipBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + escapeHtml(LANG.downloading);
        try {
            const res = await fetch('emote-vault.php?ajax_action=zip', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    items: list.map(function (emote) {
                        return { name: emote.name, url: emote.download_url, ext: emote.ext };
                    })
                })
            });
            const contentType = res.headers.get('Content-Type') || '';
            if (!res.ok || contentType.indexOf('application/json') !== -1) {
                let msg = LANG.zipFailed;
                try {
                    const payload = await res.json();
                    if (payload && payload.error) msg = payload.error;
                } catch (e) {}
                if (typeof showNotification === 'function') showNotification(msg, 'danger');
                return;
            }
            const blob = await res.blob();
            const href = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = href;
            a.download = (channelName || 'channel') + '-emote-vault.zip';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(href);
        } catch (err) {
            if (typeof showNotification === 'function') showNotification(LANG.zipFailed, 'danger');
        } finally {
            zipBtn.disabled = false;
            zipBtn.innerHTML = original;
        }
    });

    loadEmotes();
})();
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
