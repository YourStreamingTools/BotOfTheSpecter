<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('subathon_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);
$message = '';

// Use MySQLi for database connection
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Fetch the current subathon settings using MySQLi
$stmt = $db->prepare("SELECT * FROM subathon_settings ORDER BY id DESC LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();

// Default values if settings are not found
$starting_minutes = $settings['starting_minutes'] ?? 60;
$cheer_add = $settings['cheer_add'] ?? 5;
$sub_add_1 = $settings['sub_add_1'] ?? 10;
$sub_add_2 = $settings['sub_add_2'] ?? 20;
$sub_add_3 = $settings['sub_add_3'] ?? 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    // Get the submitted values
    $starting_minutes = $_POST['starting_minutes'];
    $cheer_add = $_POST['cheer_add'];
    $sub_add_1 = $_POST['sub_add_1'];
    $sub_add_2 = $_POST['sub_add_2'];
    $sub_add_3 = $_POST['sub_add_3'];
    if (!empty($settings['id'])) {
        // Update the latest settings row in the database
        $stmt = $db->prepare("UPDATE subathon_settings SET starting_minutes=?, cheer_add=?, sub_add_1=?, sub_add_2=?, sub_add_3=? WHERE id=?");
        $stmt->bind_param(
            "iiiiii",
            $starting_minutes, $cheer_add, $sub_add_1, $sub_add_2, $sub_add_3, $settings['id']
        );
    } else {
        // Insert initial settings row when none exists
        $stmt = $db->prepare("INSERT INTO subathon_settings (starting_minutes, cheer_add, sub_add_1, sub_add_2, sub_add_3) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "iiiii",
            $starting_minutes, $cheer_add, $sub_add_1, $sub_add_2, $sub_add_3
        );
    }
    $stmt->execute();
    $stmt->close();

    // Keep local settings state in sync with saved values
    $settings = [
        'id' => $settings['id'] ?? null,
        'starting_minutes' => $starting_minutes,
        'cheer_add' => $cheer_add,
        'sub_add_1' => $sub_add_1,
        'sub_add_2' => $sub_add_2,
        'sub_add_3' => $sub_add_3,
    ];
    // Set the success message
    $message = t('subathon_settings_update_success');
}

// Start output buffering for layout content
ob_start();
?>
<h1 style="font-size:1.6rem; font-weight:700; margin-bottom:1.25rem;"><?php echo htmlspecialchars($pageTitle); ?></h1>
<?php if ($message): ?>
    <div class="sp-alert sp-alert-success">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
    <div style="display:flex; align-items:flex-start; gap:1rem;">
        <span style="flex-shrink:0; font-size:1.5rem;"><i class="fas fa-hourglass-half"></i></span>
        <div>
            <p style="font-weight:700; margin-bottom:0.5rem;"><?php echo t('subathon_wip_title'); ?></p>
            <p><?php echo t('subathon_wip_desc'); ?></p>
            <p style="font-weight:700; margin-top:0.75rem; margin-bottom:0.25rem;"><?php echo t('subathon_howto_title'); ?></p>
            <ul>
                <li>
                    <span class="icon"><i class="fas fa-comment-dots"></i></span>
                    <code>!subathon addtime [minutes]</code> <?php echo t('subathon_howto_chat'); ?>
                </li>
                <li><?php echo t('subathon_howto_example'); ?></li>
            </ul>
            <p style="margin-top:0.75rem;"><?php echo t('subathon_wip_footer'); ?></p>
        </div>
    </div>
</div>
<div style="max-width:900px; margin:0 auto;">
    <div class="sp-card">
      <div class="sp-card-body">
        <form method="POST" action="">
            <div class="sp-form-group" style="margin-bottom:1.25rem;">
                <label class="sp-label" for="starting_minutes">
                    <?php echo t('subathon_starting_minutes'); ?>
                </label>
                <p style="color:var(--text-muted); margin-bottom:0.25rem;"><?php echo t('subathon_default'); ?>: 60</p>
                <input class="sp-input" type="number" name="starting_minutes" id="starting_minutes" value="<?php echo htmlspecialchars($starting_minutes); ?>" required>
                <small class="sp-help">
                    <?php echo t('subathon_starting_minutes_help'); ?>
                </small>
            </div>
            <div class="cc-form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr; margin-bottom:1.25rem;">
                <div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="cheer_add">
                            <?php echo t('subathon_cheer_add'); ?>
                        </label>
                        <p style="color:var(--text-muted); margin-bottom:0.25rem;"><?php echo t('subathon_default'); ?>: 5</p>
                        <input class="sp-input" type="number" name="cheer_add" id="cheer_add" value="<?php echo htmlspecialchars($cheer_add); ?>" required>
                        <small class="sp-help"><?php echo t('subathon_cheer_add_help'); ?></small>
                    </div>
                </div>
                <div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="sub_add_1">
                            <?php echo t('subathon_sub_add_1'); ?>
                        </label>
                        <p style="color:var(--text-muted); margin-bottom:0.25rem;"><?php echo t('subathon_default'); ?>: 10</p>
                        <input class="sp-input" type="number" name="sub_add_1" id="sub_add_1" value="<?php echo htmlspecialchars($sub_add_1); ?>" required>
                        <small class="sp-help"><?php echo t('subathon_sub_add_1_help'); ?></small>
                    </div>
                </div>
                <div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="sub_add_2">
                            <?php echo t('subathon_sub_add_2'); ?>
                        </label>
                        <p style="color:var(--text-muted); margin-bottom:0.25rem;"><?php echo t('subathon_default'); ?>: 20</p>
                        <input class="sp-input" type="number" name="sub_add_2" id="sub_add_2" value="<?php echo htmlspecialchars($sub_add_2); ?>" required>
                        <small class="sp-help"><?php echo t('subathon_sub_add_2_help'); ?></small>
                    </div>
                </div>
                <div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="sub_add_3">
                            <?php echo t('subathon_sub_add_3'); ?>
                        </label>
                        <p style="color:var(--text-muted); margin-bottom:0.25rem;"><?php echo t('subathon_default'); ?>: 30</p>
                        <input class="sp-input" type="number" name="sub_add_3" id="sub_add_3" value="<?php echo htmlspecialchars($sub_add_3); ?>" required>
                        <small class="sp-help"><?php echo t('subathon_sub_add_3_help'); ?></small>
                    </div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end;">
                <button class="sp-btn sp-btn-primary" type="submit" name="update_settings">
                    <span class="icon"><i class="fas fa-save"></i></span>
                    <span><?php echo t('subathon_update_settings_btn'); ?></span>
                </button>
            </div>
        </form>
      </div>
    </div>
</div>
<?php
// End buffering and assign to $content
$content = ob_get_clean();

// Use layout.php for rendering
include 'layout.php';
?>