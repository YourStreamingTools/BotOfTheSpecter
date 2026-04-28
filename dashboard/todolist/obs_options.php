<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ../login.php');
    exit();
}

// Page Title
$pageTitle = t('todolist_obs_options_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
include '../bot_control.php';
include "../mod_access.php";
include '../user_db.php';
include '../storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Retrieve font, color, list, shadow, bold, and font_size data for the user from the showobs table
$stmt = $db->prepare("SELECT * FROM showobs LIMIT 1");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

$font = isset($result['font']) && $result['font'] !== '' ? $result['font'] : 'Not set';
$color_raw = isset($result['color']) && $result['color'] !== '' ? $result['color'] : 'Not set';
if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color_raw)) {
    $color = $color_raw;
} else {
    $allowed_named_colors = ['Black','White','Red','Blue'];
    $color = in_array($color_raw, $allowed_named_colors) ? $color_raw : ($color_raw === 'Not set' ? 'Not set' : 'Other');
}
$list = isset($result['list']) && $result['list'] !== '' ? $result['list'] : 'Bullet';
$shadow = isset($result['shadow']) && $result['shadow'] == 1 ? true : false;
$bold = isset($result['bold']) && $result['bold'] == 1 ? true : false;
$show_completed = isset($result['show_completed']) && $result['show_completed'] == 1 ? true : false;
// Normalize font size to integer pixels
$font_size_raw = isset($result['font_size']) ? $result['font_size'] : '';
$font_size = intval(preg_replace('/\D/', '', $font_size_raw));
if ($font_size <= 0) $font_size = 12; 

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate and sanitize the input
    $selectedFont = isset($_POST["font"]) ? $_POST["font"] : '';
    $selectedColor = isset($_POST["color"]) ? $_POST["color"] : '';
    $selectedList = isset($_POST["list"]) ? $_POST["list"] : 'Bullet';
    $selectedShadow = isset($_POST["shadow"]) ? 1 : 0;
    $selectedBold = isset($_POST["bold"]) ? 1 : 0;
    $selectedShowCompleted = isset($_POST["show_completed"]) ? 1 : 0;
    // Normalize font size to integer pixels
    $selectedFontSize = isset($_POST["font_size"]) ? intval(preg_replace('/\D/', '', $_POST["font_size"])) : 12;
    if ($selectedFontSize <= 0) $selectedFontSize = 12;

    // Check if the user has selected "Other" color option
    if ($selectedColor === 'Other') {
        $customColor = isset($_POST["custom_color"]) ? trim($_POST["custom_color"]) : '';
        if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $customColor)) {
            $selectedColor = $customColor;
        } else {
            // fallback to white if invalid
            $selectedColor = 'White';
        }
    }

    // Use prepared statements with correct types (s = string, i = integer)
    if ($result) {
        $stmt = $db->prepare("UPDATE showobs SET font = ?, color = ?, list = ?, shadow = ?, bold = ?, font_size = ?, show_completed = ? LIMIT 1");
        $stmt->bind_param("sssiiii", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize, $selectedShowCompleted);
        if ($stmt->execute()) {
            header("Location: obs_options.php");
        } else {
            echo "Error updating settings: " . htmlspecialchars($stmt->error);
        }
    } else {
        $stmt = $db->prepare("INSERT INTO showobs (font, color, list, shadow, bold, font_size, show_completed) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiiii", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize, $selectedShowCompleted);
        if ($stmt->execute()) {
            header("Location: obs_options.php");
        } else {
            echo "Error inserting settings: " . htmlspecialchars($stmt->error);
        }
    }
} 

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-cog"></i> OBS Font &amp; Color Settings</div>
    </div>
    <div class="sp-card-body">
        <button type="button" class="sp-btn sp-btn-primary" style="margin-bottom:1.25rem;" onclick="showOBSInfo()">
            <i class="fas fa-info-circle"></i> How to put on your stream
        </button>
        <!-- OBS Info Modal -->
        <div id="obsInfoModal" class="db-modal-backdrop hidden">
            <div class="db-modal">
                <div class="db-modal-head">
                    <span class="db-modal-title"><i class="fas fa-info-circle"></i> How to use the ToDo List in OBS</span>
                    <button class="db-modal-close" aria-label="close" onclick="closeOBSInfoModal()">&times;</button>
                </div>
                <div class="db-modal-body">
                    <p>The ToDo List is fully compatible with any streaming software:<br>OBS, SLOBS, xSplit, Wirecast, etc.</p>
                    <p>All you have to do is add the following link (with your API key from your profile page) into a browser source and it works:</p>
                    <pre style="background:var(--bg-base); color:var(--text-primary); padding:0.75rem; border-radius:var(--radius); margin-bottom:0.5rem; font-size:0.85rem; overflow-x:auto; white-space:pre-wrap;">https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE</pre>
                    <p>If you wish to define a working category, add it like this:</p>
                    <pre style="background:var(--bg-base); color:var(--text-primary); padding:0.75rem; border-radius:var(--radius); margin-bottom:0.5rem; font-size:0.85rem; overflow-x:auto; white-space:pre-wrap;">todolist.php?code=API_KEY_HERE&amp;category=1</pre>
                    <p style="font-size:0.8rem; margin-bottom:0.75rem;">(where ID 1 is called Default, defined on the <a href='categories.php' style="color:var(--accent-hover);" target="_blank">categories</a> page.)</p>
                    <p>To add a styled box around your list (useful if your stream overlay makes it hard to read), add <code>&amp;theme=true</code>:</p>
                    <pre style="background:var(--bg-base); color:var(--text-primary); padding:0.75rem; border-radius:var(--radius); margin-bottom:0.5rem; font-size:0.85rem; overflow-x:auto; white-space:pre-wrap;">todolist.php?code=API_KEY_HERE&amp;theme=true</pre>
                    <p style="font-size:0.8rem; margin-bottom:0;">This wraps the list in a dark semi-transparent box with rounded corners, helping it stand out over any stream overlay. You can combine it with a category too: <code>&amp;category=1&amp;theme=true</code></p>
                </div>
                <div class="db-modal-foot">
                    <button class="sp-btn sp-btn-danger" onclick="closeOBSInfoModal()">Close</button>
                </div>
            </div>
        </div>
        <!-- End OBS Info Modal -->
        <h3 style="font-size:1.05rem; font-weight:700; margin-bottom:1rem;">Font &amp; Color Settings:</h3>
        <div style="margin-bottom:1.25rem;">
            <?php if ($font !== 'Not set' || $color !== 'Not set'): ?>
                <div style="display:flex; flex-wrap:wrap; gap:1rem 2rem; padding:1rem 0; border-bottom:1px solid var(--border); margin-bottom:1.25rem;">
                    <div><strong>Font:</strong> <span><?php echo htmlspecialchars($font); ?></span></div>
                    <div>
                        <strong>Color:</strong>
                        <span style="display:inline-block;width:16px;height:16px;background-color:<?php echo htmlspecialchars($color); ?>;margin:0 3px;vertical-align:middle;border-radius:3px;"></span>
                        <span><?php echo htmlspecialchars($color); ?></span>
                    </div>
                    <div><strong>List Type:</strong> <span><?php echo htmlspecialchars($list); ?></span></div>
                    <div><strong>Font Size:</strong> <span><?php echo htmlspecialchars($font_size); ?>px</span></div>
                    <div><strong>Text Shadow:</strong> <span><?php echo $shadow ? 'Enabled' : 'Disabled'; ?></span></div>
                    <div><strong>Text Bold:</strong> <span><?php echo $bold ? 'Enabled' : 'Disabled'; ?></span></div>
                    <div><strong>Show Completed:</strong> <span><?php echo $show_completed ? 'Yes' : 'No'; ?></span></div>
                </div>
            <?php else: ?>
                <div class="sp-alert sp-alert-info" style="display:flex; gap:1rem; align-items:flex-start; margin-bottom:1.25rem;">
                    <span style="font-size:1.5rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-palette"></i></span>
                    <div>
                        <p style="font-weight:700; margin-bottom:0.25rem;">Customize your lists!</p>
                        <p style="margin-bottom:0;">No font and color settings have been set. Use the controls below to personalize the look of your lists.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <form method="post">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div class="sp-form-group">
                    <label class="sp-label" for="font">Font</label>
                    <select name="font" class="sp-select">
                        <option value="Arial"<?php if ($font === 'Arial') echo ' selected'; ?>>Arial</option>
                        <option value="Arial Narrow"<?php if ($font === 'Arial Narrow') echo ' selected'; ?>>Arial Narrow</option>
                        <option value="Verdana"<?php if ($font === 'Verdana') echo ' selected'; ?>>Verdana</option>
                        <option value="Times New Roman"<?php if ($font === 'Times New Roman') echo ' selected'; ?>>Times New Roman</option>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="color">Color</label>
                    <select name="color" id="color-select" class="sp-select">
                        <option value="Black"<?php if ($color === 'Black') echo ' selected'; ?>>Black</option>
                        <option value="White"<?php if ($color === 'White') echo ' selected'; ?>>White</option>
                        <option value="Red"<?php if ($color === 'Red') echo ' selected'; ?>>Red</option>
                        <option value="Blue"<?php if ($color === 'Blue') echo ' selected'; ?>>Blue</option>
                        <option value="Other"<?php if ($color === 'Other') echo ' selected'; ?>>Other</option>
                    </select>
                    <div class="sp-form-group" id="custom-color-group" style="margin-top:0.75rem;<?php if ($color !== 'Other') echo ' display:none;'; ?>">
                        <label class="sp-label" for="custom_color">Custom Color</label>
                        <input type="text" name="custom_color" id="custom-color-input" class="sp-input" value="<?php echo htmlspecialchars($color_raw); ?>">
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="list">List Type</label>
                    <select name="list" class="sp-select">
                        <option value="Bullet"<?php if ($list === 'Bullet') echo ' selected'; ?>>Bullet List</option>
                        <option value="Numbered"<?php if ($list === 'Numbered') echo ' selected'; ?>>Numbered List</option>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="font_size">Font Size</label>
                    <input type="number" name="font_size" class="sp-input" value="<?php echo htmlspecialchars($font_size); ?>" min="8" max="72">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="shadow">Text Shadow</label>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" name="shadow" value="1" <?php if ($shadow) echo 'checked'; ?>>
                        <span>Enable shadow</span>
                    </label>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="bold">Text Bold</label>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" name="bold" value="1" <?php if ($bold) echo 'checked'; ?>>
                        <span>Enable bold</span>
                    </label>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="show_completed">Show Completed Tasks</label>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" name="show_completed" value="1" <?php if ($show_completed) echo 'checked'; ?>>
                        <span>Show completed tasks</span>
                    </label>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:1.25rem;">
                <button type="submit" class="sp-btn sp-btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
function showOBSInfo() {
    var modal = document.getElementById('obsInfoModal');
    if (modal) modal.classList.remove('hidden');
}
function closeOBSInfoModal() {
    var modal = document.getElementById('obsInfoModal');
    if (modal) modal.classList.add('hidden');
}
</script>
<script>
    var colorSelect = document.getElementById("color-select");
    var customColorGroup = document.getElementById("custom-color-group");
    colorSelect.addEventListener("change", function() {
        if (colorSelect.value === "Other") {
            customColorGroup.style.display = "block";
        } else {
            customColorGroup.style.display = "none";
        }
    });
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var customColorInput = document.getElementById('custom-color-input');
    var colorGroup = document.getElementById('custom-color-group');
    customColorInput.addEventListener('input', function() {
        if (customColorInput.value && !customColorInput.value.startsWith('#')) {
            customColorInput.value = '#' + customColorInput.value;
        }
    });
    if (customColorInput.value && customColorInput.value.startsWith('#')) {
        colorGroup.style.display = 'block';
    }
});
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>