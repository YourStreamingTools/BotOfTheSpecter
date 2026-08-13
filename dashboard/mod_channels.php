<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('mod_channels_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        $modChannels = [];
        if ($username === 'botofthespecter') {
            // Global bot user should see every channel
            $allChannelsSTMT = $conn->prepare("
                SELECT id, twitch_user_id, twitch_display_name, profile_image, username
                FROM users
                ORDER BY id ASC
            ");
            $allChannelsSTMT->execute();
            $allResult = $allChannelsSTMT->get_result();
            while ($row = $allResult->fetch_assoc()) {
                $modChannels[] = $row;
            }
            $allChannelsSTMT->close();
        } else {
            $modSTMT = $conn->prepare("
                SELECT u.id, u.twitch_user_id, u.twitch_display_name, u.profile_image, u.username
                FROM users u
                INNER JOIN moderator_access ma ON u.twitch_user_id = ma.broadcaster_id
                WHERE ma.moderator_id = ?
                ORDER BY u.id ASC
            ");
            $modSTMT->bind_param("s", $twitchUserId);
            $modSTMT->execute();
            $modResult = $modSTMT->get_result();
            while ($row = $modResult->fetch_assoc()) {
                $modChannels[] = $row;
            }
            $modSTMT->close();
        }
        echo json_encode(['success' => true, 'channels' => $modChannels]);
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Start building the HTML content
ob_start();
?>
<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.9rem; font-weight:800; color:var(--text-primary); margin:0 0 0.25rem;"><?= t('mod_channels_heading') ?></h1>
    <p style="color:var(--text-secondary); margin:0;"><?= t('mod_channels_subtitle') ?></p>
</div>
<?php if (isset($_GET['act_as']) && $_GET['act_as'] === 'stopped'): ?>
    <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
        <?= t('mod_channels_act_as_stopped') ?>
    </div>
<?php elseif (isset($_GET['act_as']) && $_GET['act_as'] === 'denied'): ?>
    <div class="sp-alert sp-alert-danger" style="margin-bottom:1rem;">
        <?= t('mod_channels_act_as_denied') ?>
    </div>
<?php elseif (isset($_GET['act_as']) && $_GET['act_as'] === 'not_found'): ?>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1rem;">
        <?= t('mod_channels_act_as_not_found') ?>
    </div>
<?php endif; ?>
<div id="modChannelSearchWrap" class="sp-form-group" style="display:none;">
    <label class="sp-label" for="mod-channel-search"><?= t('mod_channels_search_label') ?></label>
    <input id="mod-channel-search" class="sp-input" type="text" placeholder="<?= htmlspecialchars(t('mod_channels_search_placeholder')) ?>" autocomplete="off">
</div>
<div id="modChannelsEmpty" class="sp-alert sp-alert-info" style="display:none;">
    <i class="fas fa-info-circle"></i> <?= t('mod_channels_none') ?>
</div>
<div id="modChannelsError" class="sp-alert sp-alert-danger" style="display:none;">
    <i class="fas fa-exclamation-circle"></i> <?= t('dashboard_js_load_error') ?>
</div>
<div id="modChannelsGrid" class="mod-channels-grid" aria-busy="true" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:1rem;">
    <?php for ($sk = 0; $sk < 6; $sk++): ?>
    <div class="sp-card" aria-hidden="true">
        <div class="sp-card-body">
            <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">
                <span class="sp-skeleton-avatar lg" style="width:64px;height:64px;"></span>
                <div class="sp-skeleton-stack" style="flex:1; min-width:0;">
                    <span class="sp-skeleton-line w-70"></span>
                    <span class="sp-skeleton-line w-40"></span>
                </div>
            </div>
            <span class="sp-skeleton-line w-90"></span>
        </div>
    </div>
    <?php endfor; ?>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const MC_I18N = {
    actAs: <?php echo json_encode(t('mod_channels_act_as_button')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function bindChannelSearch() {
    var searchInput = document.getElementById('mod-channel-search');
    if (!searchInput || searchInput.dataset.bound === '1') {
        return;
    }
    searchInput.dataset.bound = '1';
    searchInput.addEventListener('input', function () {
        var term = searchInput.value.trim().toLowerCase();
        Array.from(document.querySelectorAll('.mod-channel-card')).forEach(function (card) {
            var matches = !term || (card.dataset.search && card.dataset.search.includes(term));
            card.style.display = matches ? '' : 'none';
        });
    });
}

function renderModChannels(channels) {
    var host = document.getElementById('modChannelsGrid');
    var empty = document.getElementById('modChannelsEmpty');
    var error = document.getElementById('modChannelsError');
    var searchWrap = document.getElementById('modChannelSearchWrap');
    if (!host) return;
    host.setAttribute('aria-busy', 'false');
    if (error) error.style.display = 'none';
    if (!channels.length) {
        host.style.display = 'none';
        host.innerHTML = '';
        if (empty) empty.style.display = '';
        if (searchWrap) searchWrap.style.display = 'none';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (searchWrap) searchWrap.style.display = channels.length > 9 ? '' : 'none';
    host.style.display = 'grid';
    host.innerHTML = channels.map(function (channel) {
        var display = channel.twitch_display_name || '';
        var uname = channel.username || '';
        var search = (display + ' ' + uname).toLowerCase();
        var img = channel.profile_image || '';
        var uid = channel.twitch_user_id || '';
        return '<div class="sp-card mod-channel-card" data-search="' + escapeHtml(search) + '">' +
            '<div class="sp-card-body">' +
                '<div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">' +
                    '<img src="' + escapeHtml(img) + '" alt="' + escapeHtml(display) + '" style="width:64px; height:64px; border-radius:50%; flex-shrink:0; object-fit:cover;">' +
                    '<div style="min-width:0;">' +
                        '<p style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0 0 0.15rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + escapeHtml(display) + '</p>' +
                        '<p style="font-size:0.85rem; color:var(--text-muted); margin:0;">@' + escapeHtml(uname) + '</p>' +
                    '</div>' +
                '</div>' +
                '<a href="/api/switch_channel.php?user_id=' + encodeURIComponent(uid) + '" class="sp-btn sp-btn-primary" style="width:100%; justify-content:center;">' +
                    '<i class="fas fa-user-secret"></i>' +
                    '<span>' + escapeHtml(MC_I18N.actAs) + '</span>' +
                '</a>' +
            '</div>' +
        '</div>';
    }).join('');
    bindChannelSearch();
}

function renderModChannelsError() {
    var host = document.getElementById('modChannelsGrid');
    var empty = document.getElementById('modChannelsEmpty');
    var error = document.getElementById('modChannelsError');
    var searchWrap = document.getElementById('modChannelSearchWrap');
    if (host) {
        host.setAttribute('aria-busy', 'false');
        host.style.display = 'none';
        host.innerHTML = '';
    }
    if (empty) empty.style.display = 'none';
    if (searchWrap) searchWrap.style.display = 'none';
    if (error) error.style.display = '';
}

function loadModChannels() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                renderModChannelsError();
                return;
            }
            renderModChannels(Array.isArray(data.channels) ? data.channels : []);
        })
        .catch(function () {
            renderModChannelsError();
        });
}

document.addEventListener('DOMContentLoaded', loadModChannels);
</script>
<?php
$scripts = ob_get_clean();

// Include the layout template
include 'layout.php';
?>
