<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = 'Social Overlay Roller';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include 'includes/user_db.php';
session_write_close();

$platforms = [
    'twitch', 'twitter', 'youtube', 'instagram', 'tiktok', 'discord',
    'facebook', 'reddit', 'linkedin', 'snapchat', 'pinterest', 'threads',
    'bluesky', 'mastodon', 'kick', 'github', 'spotify', 'steam', 'patreon', 'kofi'
];

// Handle saving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_socials'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    
    $success = true;
    $db->begin_transaction();
    try {
        foreach ($platforms as $displayOrder => $platform) {
            $handle = trim($_POST[$platform . '_handle'] ?? '');
            $isActive = isset($_POST[$platform . '_active']) ? 1 : 0;
            
            // Only update or insert if the handle is not empty, otherwise delete or mark inactive
            if ($handle === '') {
                $stmt = $db->prepare("DELETE FROM user_socials WHERE platform = ?");
                $stmt->bind_param("s", $platform);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $db->prepare("INSERT INTO user_socials (platform, handle, is_active, display_order) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE handle = VALUES(handle), is_active = VALUES(is_active), display_order = VALUES(display_order)");
                $stmt->bind_param("ssii", $platform, $handle, $isActive, $displayOrder);
                $stmt->execute();
                $stmt->close();
            }
        }
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Fetch current socials
$currentSocials = [];
$result = $db->query("SELECT * FROM user_socials");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $currentSocials[$row['platform']] = $row;
    }
}

// API key for the overlay link (falls back to the session value).
$socialApiKey = isset($api_key) && $api_key ? $api_key : ($_SESSION['api_key'] ?? '');
$overlayUrlBase = 'https://overlay.botofthespecter.com/social-roller.php?code=';
$overlayUrl = $overlayUrlBase . urlencode($socialApiKey);
// Masked version for default (visible) display so the API key is never shown on screen.
$overlayKeyEncoded = urlencode($socialApiKey);
$overlayUrlMasked = $overlayUrlBase . str_repeat('•', max(12, min(strlen($overlayKeyEncoded), 32)));

// Start output buffering for layout content
ob_start();
?>

<div class="sp-alert sp-alert-info" style="display:flex; gap:1.25rem; align-items:flex-start; margin-bottom:1.5rem;">
    <span style="font-size:1.75rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-info-circle"></i></span>
    <div style="flex-grow:1; min-width:0;">
        <p style="font-weight:700; margin-bottom:0.4rem;">Social Overlay Roller</p>
        <p style="margin-bottom:0.4rem;">Add this link as a Browser Source in OBS to use your Social Roller:</p>
        <div id="socialOverlayUrlBox" data-full-url="<?= htmlspecialchars($overlayUrl) ?>" data-masked-url="<?= htmlspecialchars($overlayUrlMasked) ?>" data-revealed="false" style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.4rem;">
            <code id="socialOverlayUrlText" class="info-box" style="font-family:monospace; margin-bottom:0; flex:1 1 auto; overflow:auto; white-space:nowrap;"><?= htmlspecialchars($overlayUrlMasked) ?></code>
            <button type="button" id="socialCopyUrlBtn" class="sp-btn sp-btn-secondary" title="Copy URL" style="flex:0 0 auto; width:2.5rem; height:2.5rem; padding:0;">
                <i class="fas fa-copy" id="socialCopyUrlIcon"></i>
            </button>
            <button type="button" id="socialRevealUrlBtn" class="sp-btn sp-btn-secondary" title="Show URL" data-show-label="Show URL" data-hide-label="Hide URL" style="flex:0 0 auto; width:2.5rem; height:2.5rem; padding:0;">
                <i class="fas fa-eye" id="socialRevealUrlIcon"></i>
            </button>
        </div>
        <p style="font-size:0.85rem; color:var(--text-muted, #888); margin:0;"><i class="fas fa-shield-alt" style="margin-right:0.4rem;"></i>Keep your API key secret! Never show this URL on stream.</p>
    </div>
</div>

<div class="sp-card" style="margin-bottom:1.5rem;">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-users"></i> Social Accounts</div>
    </div>
    <div class="sp-card-body">
        <p style="margin-bottom:1.5rem; color:var(--text-muted);">Configure the social media handles that you want to display on your Social Overlay Roller. Leave the handle blank to hide that platform entirely.</p>
        
        <form id="socialsForm">
            <input type="hidden" name="save_socials" value="1">
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:1.5rem; margin-bottom:1.5rem;">
                <?php foreach ($platforms as $platform): ?>
                    <?php 
                        $platformLabel = ucfirst($platform);
                        if ($platform === 'twitter') $platformLabel = 'X / Twitter';
                        if ($platform === 'youtube') $platformLabel = 'YouTube';
                        if ($platform === 'tiktok') $platformLabel = 'TikTok';
                        if ($platform === 'linkedin') $platformLabel = 'LinkedIn';
                        if ($platform === 'github') $platformLabel = 'GitHub';
                        if ($platform === 'kofi') $platformLabel = 'Ko-fi';
                        $data = $currentSocials[$platform] ?? ['handle' => '', 'is_active' => 0];
                    ?>
                    <div class="info-box" style="margin-bottom:0; display:flex; flex-direction:column; gap:0.75rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong><?= htmlspecialchars($platformLabel) ?></strong>
                            <label class="sp-switch">
                                <input type="checkbox" name="<?= htmlspecialchars($platform) ?>_active" <?= $data['is_active'] ? 'checked' : '' ?> <?= empty($data['handle']) ? 'disabled' : '' ?> onchange="updateRowState(this)">
                                <span class="sp-slider round"></span>
                            </label>
                        </div>
                        <input type="text" class="sp-input" name="<?= htmlspecialchars($platform) ?>_handle" value="<?= htmlspecialchars($data['handle']) ?>" placeholder="@handle or username" oninput="updateRowState(this)">
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="sp-btn sp-btn-primary"><i class="fas fa-save"></i> Save Settings</button>
        </form>
    </div>
</div>

<script>
function updateRowState(el) {
    const container = el.closest('.info-box');
    const input = container.querySelector('input[type="text"]');
    const checkbox = container.querySelector('input[type="checkbox"]');
    
    if (input.value.trim() === '') {
        checkbox.checked = false;
        checkbox.disabled = true;
    } else {
        checkbox.disabled = false;
    }
}

document.getElementById('socialsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;

    fetch('socials.php', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
            btn.classList.replace('sp-btn-primary', 'sp-btn-success');
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.classList.replace('sp-btn-success', 'sp-btn-primary');
                btn.disabled = false;
            }, 2000);
        } else {
            alert('Error saving settings: ' + (data.error || 'Unknown error'));
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        alert('Network error saving settings.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
});

document.addEventListener('DOMContentLoaded', function () {
    // Overlay URL: masked display with copy + reveal (keeps the API key off-screen by default)
    var overlayUrlBox = document.getElementById('socialOverlayUrlBox');
    if (overlayUrlBox) {
        var overlayUrlText = document.getElementById('socialOverlayUrlText');
        var copyUrlBtn = document.getElementById('socialCopyUrlBtn');
        var copyUrlIcon = document.getElementById('socialCopyUrlIcon');
        var revealUrlBtn = document.getElementById('socialRevealUrlBtn');
        var revealUrlIcon = document.getElementById('socialRevealUrlIcon');
        var fullUrl = overlayUrlBox.dataset.fullUrl || '';
        var maskedUrl = overlayUrlBox.dataset.maskedUrl || '';

        if (revealUrlBtn) {
            revealUrlBtn.addEventListener('click', function () {
                var revealed = overlayUrlBox.dataset.revealed === 'true';
                if (revealed) {
                    overlayUrlText.textContent = maskedUrl;
                    overlayUrlBox.dataset.revealed = 'false';
                    revealUrlIcon.classList.remove('fa-eye-slash');
                    revealUrlIcon.classList.add('fa-eye');
                    revealUrlBtn.title = revealUrlBtn.dataset.showLabel || '';
                } else {
                    overlayUrlText.textContent = fullUrl;
                    overlayUrlBox.dataset.revealed = 'true';
                    revealUrlIcon.classList.remove('fa-eye');
                    revealUrlIcon.classList.add('fa-eye-slash');
                    revealUrlBtn.title = revealUrlBtn.dataset.hideLabel || '';
                }
            });
        }

        if (copyUrlBtn) {
            copyUrlBtn.addEventListener('click', function () {
                function showCopied() {
                    copyUrlIcon.classList.remove('fa-copy');
                    copyUrlIcon.classList.add('fa-check');
                    copyUrlBtn.classList.add('sp-btn-success');
                    var prevTitle = copyUrlBtn.title;
                    copyUrlBtn.title = 'URL copied!';
                    setTimeout(function () {
                        copyUrlIcon.classList.remove('fa-check');
                        copyUrlIcon.classList.add('fa-copy');
                        copyUrlBtn.classList.remove('sp-btn-success');
                        copyUrlBtn.title = prevTitle;
                    }, 2000);
                }
                function fallbackCopy() {
                    var ta = document.createElement('textarea');
                    ta.value = fullUrl;
                    ta.style.position = 'fixed';
                    ta.style.left = '-999999px';
                    ta.style.top = '-999999px';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    try { document.execCommand('copy'); showCopied(); }
                    catch (err) { console.error('Fallback copy failed: ', err); }
                    document.body.removeChild(ta);
                }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(fullUrl).then(showCopied).catch(function (err) {
                        console.error('Failed to copy: ', err);
                        fallbackCopy();
                    });
                } else {
                    fallbackCopy();
                }
            });
        }
    }
});
</script>

<?php
$content = ob_get_clean();
require 'layout.php';
?>
