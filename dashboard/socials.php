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

$platforms = ['twitch', 'twitter', 'youtube', 'instagram', 'tiktok', 'discord'];

// Handle saving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_socials'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    
    $success = true;
    $db->begin_transaction();
    try {
        foreach ($platforms as $platform) {
            $handle = trim($_POST[$platform . '_handle'] ?? '');
            $isActive = isset($_POST[$platform . '_active']) ? 1 : 0;
            
            // Only update or insert if the handle is not empty, otherwise delete or mark inactive
            if ($handle === '') {
                $stmt = $db->prepare("DELETE FROM user_socials WHERE platform = ?");
                $stmt->bind_param("s", $platform);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $db->prepare("INSERT INTO user_socials (platform, handle, is_active) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE handle = VALUES(handle), is_active = VALUES(is_active)");
                $stmt->bind_param("ssi", $platform, $handle, $isActive);
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

// Start output buffering for layout content
ob_start();
?>

<div class="sp-alert sp-alert-info" style="display:flex; gap:1.25rem; align-items:flex-start; margin-bottom:1.5rem;">
    <span style="font-size:1.75rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-info-circle"></i></span>
    <div>
        <p style="font-weight:700; margin-bottom:0.4rem;">Social Overlay Roller</p>
        <p style="margin-bottom:0.4rem;">Add this link as a Browser Source in OBS to use your Social Roller:</p>
        <div class="info-box" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0; font-family:monospace;">
            <code id="overlayLink" style="word-break:break-all;">https://overlay.botofthespecter.com/social-roller.php?code=<?= htmlspecialchars($profileData['api_key'] ?? 'YOUR_API_KEY') ?></code>
            <button class="sp-btn sp-btn-secondary sp-btn-sm" onclick="copyOverlayLink()"><i class="fas fa-copy"></i> Copy</button>
        </div>
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
                        if ($platform === 'youtube') $platformLabel = 'YouTube';
                        if ($platform === 'tiktok') $platformLabel = 'TikTok';
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

function copyOverlayLink() {
    const link = document.getElementById('overlayLink').textContent;
    navigator.clipboard.writeText(link).then(() => {
        alert('Overlay link copied to clipboard!');
    }).catch(err => {
        alert('Failed to copy link. Please select and copy manually.');
    });
}
</script>

<?php
$content = ob_get_clean();
require 'layout.php';
?>
