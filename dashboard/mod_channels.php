<?php
// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = "Mod Channels";

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

// Fetch all channels the user can moderate for (moderator_access: moderator_id, broadcaster_id)
$modChannels = [];
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

// Start building the HTML content
ob_start();
?>
<div class="container">
    <h1 class="title is-2">Mod Channels</h1>
    <p class="subtitle">Channels you can moderate for</p>

    <?php if (empty($modChannels)): ?>
        <div class="notification is-info">
            <p><i class="fas fa-info-circle"></i> You are not currently a moderator for any channels.</p>
        </div>
    <?php else: ?>
        <div class="columns is-multiline">
            <?php foreach ($modChannels as $channel): ?>
                <div class="column is-one-third-desktop is-half-tablet is-full-mobile">
                    <div class="card">
                        <div class="card-content">
                            <div class="media">
                                <div class="media-left">
                                    <figure class="image is-64x64">
                                        <img src="<?php echo htmlspecialchars($channel['profile_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($channel['twitch_display_name']); ?>"
                                             style="border-radius: 50%;">
                                    </figure>
                                </div>
                                <div class="media-content">
                                    <p class="title is-4"><?php echo htmlspecialchars($channel['twitch_display_name']); ?></p>
                                    <p class="subtitle is-6 has-text-grey">@<?php echo htmlspecialchars($channel['username']); ?></p>
                                </div>
                            </div>
                            <div class="content">
                                <a href="switch_channel.php?user_id=<?php echo urlencode($channel['twitch_user_id']); ?>" 
                                   class="button is-primary is-fullwidth">
                                    <span class="icon">
                                        <i class="fas fa-exchange-alt"></i>
                                    </span>
                                    <span>Switch to this Channel</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();

// Include the layout template
include 'layout.php';
?>
