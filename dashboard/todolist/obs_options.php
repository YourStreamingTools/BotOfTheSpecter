<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
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
        $stmt = $db->prepare("UPDATE showobs SET font = ?, color = ?, list = ?, shadow = ?, bold = ?, font_size = ? LIMIT 1");
        $stmt->bind_param("sssiii", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize);
        if ($stmt->execute()) {
            header("Location: obs_options.php");
        } else {
            echo "Error updating settings: " . htmlspecialchars($stmt->error);
        }
    } else {
        $stmt = $db->prepare("INSERT INTO showobs (font, color, list, shadow, bold, font_size) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiii", $selectedFont, $selectedColor, $selectedList, $selectedShadow, $selectedBold, $selectedFontSize);
        if ($stmt->execute()) {
            header("Location: obs_options.php");
        } else {
            echo "Error inserting settings: " . htmlspecialchars($stmt->error);
        }
    }
} 

ob_start();
?>
<div class="columns is-centered">
    <div class="column">
        <div class="card" style="border-radius: 18px;">
            <header class="card-header">
                <p class="card-header-title is-size-4">
                    <span class="icon"><i class="fas fa-cog"></i></span>
                    <span class="ml-2">OBS Font & Color Settings</span>
                </p>
            </header>
            <div class="card-content" style="padding: 2.5rem;">
                <button type="button" class="button is-link mb-4" onclick="showOBSInfo()">
                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                    <span>How to put on your stream</span>
                </button>
                <!-- OBS Info Modal -->
                <div id="obsInfoModal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-card" style="max-width: 600px;">
                        <header class="modal-card-head">
                            <p class="modal-card-title">
                                <span class="icon"><i class="fas fa-info-circle"></i></span>
                                <span>How to use the ToDo List in OBS</span>
                            </p>
                            <button class="delete" aria-label="close" onclick="closeOBSInfoModal()"></button>
                        </header>
                        <section class="modal-card-body has-text-left">
                            <p>The ToDo List is fully compatible with any streaming software:<br>OBS, SLOBS, xSplit, Wirecast, etc.</p>
                            <p class="mt-3">All you have to do is add the following link (with your API key from your profile page) into a browser source and it works:</p>
                            <pre class="has-background-dark has-text-white p-2 mb-2" style="border-radius: 6px;">https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE</pre>
                            <p>If you wish to define a working category, add it like this:</p>
                            <pre class="has-background-dark has-text-white p-2 mb-2" style="border-radius: 6px;">todolist.php?code=API_KEY_HERE&amp;category=1</pre>
                            <p class="is-size-7 mb-0">(where ID 1 is called Default, defined on the <a href='categories.php' class="has-text-link" target="_blank">categories</a> page.)</p>
                        </section>
                        <footer class="modal-card-foot is-justify-content-flex-end">
                            <button class="button is-danger" onclick="closeOBSInfoModal()">Close</button>
                        </footer>
                    </div>
                </div>
                <!-- End OBS Info Modal -->
                <h3 class="title is-4">Font & Color Settings:</h3>
                <div class="mb-5">
                    <?php if ($font !== 'Not set' || $color !== 'Not set'): ?>
                        <div style="border-radius: 12px; padding: 1.25rem 0 1.25rem 0;">
                            <div class="columns is-multiline is-mobile">
                                <div class="column is-6-tablet is-4-desktop">
                                    <strong>Font:</strong> <span><?php echo htmlspecialchars($font); ?></span>
                                </div>
                                <div class="column is-6-tablet is-4-desktop">
                                    <strong>Color:</strong>
                                    <span style="display:inline-block;width:18px;height:18px;background-color:<?php echo htmlspecialchars($color); ?>;margin-right:3px;vertical-align:middle;border-radius:3px;"></span>
                                    <span><?php echo htmlspecialchars($color); ?></span>
                                </div>
                                <div class="column is-6-tablet is-4-desktop">
                                    <strong>List Type:</strong> <span><?php echo htmlspecialchars($list); ?></span>
                                </div>
                                <div class="column is-6-tablet is-4-desktop">
                                    <strong>Font Size:</strong> <span><?php echo htmlspecialchars($font_size); ?>px</span>
                                </div>
                                <div class="column is-6-tablet is-4-desktop">
                                    <strong>Text Shadow:</strong> <span><?php echo $shadow ? 'Enabled' : 'Disabled'; ?></span>
                                </div>
                                <div class="column is-6-tablet is-4-desktop">
                                    <strong>Text Bold:</strong> <span><?php echo $bold ? 'Enabled' : 'Disabled'; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="notification is-info is-light mb-5">
                            <div class="columns is-vcentered">
                                <div class="column is-narrow">
                                    <span class="icon is-large"><i class="fas fa-palette fa-2x"></i></span>
                                </div>
                                <div class="column">
                                    <p><strong>Customize your lists!</strong></p>
                                    <p>No font and color settings have been set. Use the controls below to personalize the look of your lists.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="post">
                    <div class="columns is-multiline">
                        <div class="column is-6">
                            <div class="field mb-4">
                                <label class="label" for="font">Font</label>
                                <div class="control has-icons-left">
                                    <div class="select is-fullwidth is-rounded">
                                        <select name="font">
                                            <option value="Arial"<?php if ($font === 'Arial') echo ' selected'; ?>>Arial</option>
                                            <option value="Arial Narrow"<?php if ($font === 'Arial Narrow') echo ' selected'; ?>>Arial Narrow</option>
                                            <option value="Verdana"<?php if ($font === 'Verdana') echo ' selected'; ?>>Verdana</option>
                                            <option value="Times New Roman"<?php if ($font === 'Times New Roman') echo ' selected'; ?>>Times New Roman</option>
                                        </select>
                                    </div>
                                    <span class="icon is-left"><i class="fas fa-font"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6">
                            <div class="field mb-4">
                                <label class="label" for="color">Color</label>
                                <div class="control has-icons-left">
                                    <div class="select is-fullwidth is-rounded">
                                        <select name="color" id="color-select">
                                            <option value="Black"<?php if ($color === 'Black') echo ' selected'; ?>>Black</option>
                                            <option value="White"<?php if ($color === 'White') echo ' selected'; ?>>White</option>
                                            <option value="Red"<?php if ($color === 'Red') echo ' selected'; ?>>Red</option>
                                            <option value="Blue"<?php if ($color === 'Blue') echo ' selected'; ?>>Blue</option>
                                            <option value="Other"<?php if ($color === 'Other') echo ' selected'; ?>>Other</option>
                                        </select>
                                    </div>
                                    <span class="icon is-left"><i class="fas fa-palette"></i></span>
                                </div>
                            </div>
                            <div class="field mb-4" id="custom-color-group"<?php if ($color !== 'Other') echo ' style="display: none;"'; ?>>
                                <label class="label" for="custom_color">Custom Color</label>
                                <div class="control">
                                    <input type="text" name="custom_color" id="custom-color-input" class="input is-rounded" value="<?php echo htmlspecialchars($color_raw); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="column is-6">
                            <div class="field mb-4">
                                <label class="label" for="list">List Type</label>
                                <div class="control has-icons-left">
                                    <div class="select is-fullwidth is-rounded">
                                        <select name="list">
                                            <option value="Bullet"<?php if ($list === 'Bullet') echo ' selected'; ?>>Bullet List</option>
                                            <option value="Numbered"<?php if ($list === 'Numbered') echo ' selected'; ?>>Numbered List</option>
                                        </select>
                                    </div>
                                    <span class="icon is-left"><i class="fas fa-list-ul"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6">
                            <div class="field mb-4">
                                <label class="label" for="font_size">Font Size</label>
                                <div class="control has-icons-left">
                                    <input type="number" name="font_size" value="<?php echo htmlspecialchars($font_size); ?>" class="input is-rounded" min="8" max="72">
                                    <span class="icon is-left"><i class="fas fa-text-height"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6">
                            <div class="field mb-4">
                                <label class="label" for="shadow">Text Shadow</label>
                                <div class="control">
                                    <input type="checkbox" name="shadow" value="1" <?php if ($shadow) echo 'checked'; ?>>
                                    <span class="ml-2">Enable shadow</span>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6">
                            <div class="field mb-4">
                                <label class="label" for="bold">Text Bold</label>
                                <div class="control">
                                    <input type="checkbox" name="bold" value="1" <?php if ($bold) echo 'checked'; ?>>
                                    <span class="ml-2">Enable bold</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="field is-grouped is-grouped-right mt-5">
                        <div class="control">
                            <button type="submit" class="button is-primary is-medium is-rounded px-5">Save</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
function showOBSInfo() {
    var modal = document.getElementById('obsInfoModal');
    if (modal) modal.classList.add('is-active');
}
function closeOBSInfoModal() {
    var modal = document.getElementById('obsInfoModal');
    if (modal) modal.classList.remove('is-active');
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