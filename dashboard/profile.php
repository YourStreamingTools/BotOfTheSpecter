<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Include necessary files
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Fetch user data from database
$userId = $_SESSION['user_id'] ?? 0;
$userQuery = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $userQuery);
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
$isTechnical = isset($user['is_technical']) ? (bool)$user['is_technical'] : false;

// Include language file based on user preference
$userLanguage = isset($user['language']) ? $user['language'] : 'EN';
if (isset($_SESSION['language'])) {
    $userLanguage = $_SESSION['language'];
    $user['language'] = $_SESSION['language'];
}
include_once __DIR__ . '/lang/i18n.php';

// Page title
$pageTitle = t('profile_title');

// Fetch profile data
$profileQuery = "SELECT * FROM profile";
$stmt = mysqli_prepare($db, $profileQuery);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$profileData = mysqli_fetch_assoc($result);

// Fetch heart rate code independently
$heartrateCode = null;
$heartrateStmt = mysqli_prepare($db, "SELECT heartrate_code FROM profile");
mysqli_stmt_execute($heartrateStmt);
$heartrateResult = mysqli_stmt_get_result($heartrateStmt);
if ($row = mysqli_fetch_assoc($heartrateResult)) {
    $heartrateCode = $row['heartrate_code'];
}

// Format join and last login times
function formatUserDate($datetime, $timezone) {
    if (!$datetime) return 'Unknown';
    try {
        $serverTz = new DateTimeZone('Australia/Sydney');
        $dt = new DateTime($datetime, $serverTz);
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format('M j, Y - g:ia ') . $dt->format('T');
    } catch (Exception $e) {
        return htmlspecialchars($datetime);
    }
}

// Determine user's timezone preference (from profile, fallback to UTC)
$userTimezone = isset($profileData['timezone']) && $profileData['timezone'] ? $profileData['timezone'] : 'UTC';
$joinedFormatted = isset($user['signup_date']) ? formatUserDate($user['signup_date'], $userTimezone) : 'Unknown';
$lastLoginFormatted = isset($user['last_login']) ? formatUserDate($user['last_login'], $userTimezone) : 'Unknown';

// Handle profile update
$message = '';
$alertClass = '';

// Show session message after redirect (e.g. after language change)
if (isset($_SESSION['profile_message'])) {
    // Always reload the language file with the session language for the message
    $langForMsg = $_SESSION['language'] ?? $userLanguage ?? 'EN';
    include_once __DIR__ . '/lang/i18n.php';
    $message = t('language_updated_success');
    $alertClass = $_SESSION['profile_alert_class'] ?? '';
    unset($_SESSION['profile_message'], $_SESSION['profile_alert_class']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_timezone') {
        $timezone = $_POST['timezone'] ?? 'UTC';
        $updateQuery = "UPDATE profile SET timezone = ?";
        $stmt = mysqli_prepare($db, $updateQuery);
        mysqli_stmt_bind_param($stmt, 's', $timezone);
        if (mysqli_stmt_execute($stmt)) {
            $message = t('timezone_updated_success');
            $alertClass = 'is-success';
            $_SESSION['timezone'] = $timezone;
            // Reload profile data
            $stmt = mysqli_prepare($db, $profileQuery);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $profileData = mysqli_fetch_assoc($result);
        } else {
            $message = t('timezone_update_error') . ': ' . mysqli_error($db);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_weather_location') {
        $weatherLocation = $_POST['weather_location'] ?? '';
        $updateQuery = "UPDATE profile SET weather_location = ?";
        $stmt = mysqli_prepare($db, $updateQuery);
        mysqli_stmt_bind_param($stmt, 's', $weatherLocation);
        if (mysqli_stmt_execute($stmt)) {
            $message = t('weather_location_updated_success');
            $alertClass = 'is-success';
            // Reload profile data
            $stmt = mysqli_prepare($db, $profileQuery);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $profileData = mysqli_fetch_assoc($result);
        } else {
            $message = t('weather_location_update_error') . ': ' . mysqli_error($db);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'regenerate_api_key') {
        // Handle API key regeneration
        $newApiKey = bin2hex(random_bytes(16));
        $updateQuery = "UPDATE users SET api_key = ? WHERE id = ?";
        $stmt = mysqli_prepare($db, $updateQuery);
        mysqli_stmt_bind_param($stmt, 'si', $newApiKey, $userId);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['api_key'] = $newApiKey;
            $user['api_key'] = $newApiKey;
            $message = t('api_key_regenerated_success');
            $alertClass = 'is-success';
        } else {
            $message = t('api_key_regenerate_error') . ': ' . mysqli_error($db);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_heartrate_code') {
        // Handle heart rate code update
        $newCode = trim($_POST['heartrate_code'] ?? '');
        if ($newCode !== '') {
            $updateQuery = "UPDATE users SET heartrate_code = ? WHERE id = ?";
            $stmt = mysqli_prepare($db, $updateQuery);
            mysqli_stmt_bind_param($stmt, 'si', $newCode, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $message = t('heartrate_code_updated_success');
                $alertClass = 'is-success';
                // Refresh user data to get the latest code from DB
                $userQuery = "SELECT heartrate_code, api_key, created_at, last_login FROM users WHERE id = ?";
                $stmt2 = mysqli_prepare($conn, $userQuery);
                mysqli_stmt_bind_param($stmt2, 'i', $userId);
                mysqli_stmt_execute($stmt2);
                $result2 = mysqli_stmt_get_result($stmt2);
                $userRow = mysqli_fetch_assoc($result2);
            } else {
                $message = t('heartrate_code_update_error') . ': ' . mysqli_error($db);
                $alertClass = 'is-danger';
            }
        } else {
            $message = t('heartrate_code_update_empty_error');
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_technical_mode') {
        $isTechnicalNew = isset($_POST['is_technical']) ? (int)$_POST['is_technical'] : 0;
        $updateQuery = "UPDATE users SET is_technical = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $updateQuery);
        mysqli_stmt_bind_param($stmt, 'ii', $isTechnicalNew, $userId);
        if (mysqli_stmt_execute($stmt)) {
            $message = t('technical_mode_updated_success');
            $alertClass = 'is-success';
            $isTechnical = (bool)$isTechnicalNew;
            $_SESSION['is_technical'] = $isTechnical;
            $user['is_technical'] = $isTechnicalNew;
        } else {
            $message = t('technical_mode_update_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_language') {
        $language = $_POST['language'] ?? 'EN';
        $updateUserQuery = "UPDATE users SET language = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $updateUserQuery);
        mysqli_stmt_bind_param($stmt, 'si', $language, $userId);
        $userSuccess = mysqli_stmt_execute($stmt);
        if ($userSuccess) {
            $_SESSION['language'] = $language;
            // Store the message in session and reload
            $_SESSION['profile_message'] = t('language_updated_success');
            $_SESSION['profile_alert_class'] = 'is-success';
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        } else {
            $message = t('language_update_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    }
}

// Get timezone options
$timezoneOptions = DateTimeZone::listIdentifiers();

// Check if Discord is linked
$discordLinked = false;
$discord_userSTMT = $conn->prepare("SELECT 1 FROM discord_users");
$discord_userSTMT->execute();
$discord_userResult = $discord_userSTMT->get_result();
$discordLinked = ($discord_userResult->num_rows > 0);

// Check if Spotify is linked
$spotifyLinked = false;
$spotifySTMT = $conn->prepare("SELECT 1 FROM spotify_tokens");
$spotifySTMT->execute();
$spotifyResult = $spotifySTMT->get_result();
$spotifyLinked = ($spotifyResult->num_rows > 0);

// Check if StreamElements is linked and token is valid
$streamelementsLinked = false;
if (isset($_SESSION['twitchUserId'])) {
    $streamelementsSTMT = $conn->prepare("SELECT access_token FROM streamelements_tokens WHERE twitch_user_id = ?");
    $streamelementsSTMT->bind_param("s", $_SESSION['twitchUserId']);
    $streamelementsSTMT->execute();
    $streamelementsResult = $streamelementsSTMT->get_result();
    if ($streamelementsRow = $streamelementsResult->fetch_assoc()) {
        $accessToken = $streamelementsRow['access_token'];
        // Validate the token with StreamElements API
        $ch = curl_init("https://api.streamelements.com/oauth2/validate");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
        $validate_response = curl_exec($ch);
        $validate_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $validate_data = json_decode($validate_response, true);
        if ($validate_code === 200 && isset($validate_data['channel_id'])) {
            $streamelementsLinked = true;
        }
    }
}

// Calculate total storage used and max storage using storage_used.php
$username = $_SESSION['username'] ?? '';
ob_start();
include 'storage_used.php';
ob_end_clean();

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
$storageUsedFormatted = formatBytes($current_storage_used ?? 0);
$storageMaxFormatted = formatBytes($max_storage_size ?? 0);
// Always show percentage as a number between 0 and 100 with up to 2 decimals
$storagePercent = isset($storage_percentage) ? max(0, min(100, round($storage_percentage, 2))) : 0;

// Fetch all supported languages from the languages table
$languages = [];
$langQuery = "SELECT name, code FROM languages";
$langResult = $conn->query($langQuery);
if ($langResult && $langResult->num_rows > 0) {
    while ($row = $langResult->fetch_assoc()) {
        $languages[] = $row;
    }
}

// Start output buffering for layout
ob_start();
?>
<?php if ($message): ?>
<div class="notification <?php echo $alertClass; ?>">
    <button class="delete"></button>
    <?php echo $message; ?>
</div>
<?php endif; ?>
<div class="columns is-multiline">
    <div class="column is-12">
        <div class="box" id="general">
            <h2 class="title is-4 mb-4"><?php echo t('profile_title'); ?></h2>
            <div class="columns is-vcentered">
                <div class="column is-3 has-text-centered" style="align-items: center; justify-content: center;">
                    <figure class="image is-square has-text-centered" style="width:100%;max-width:250px;max-height:250px;aspect-ratio:1/1;margin-left:auto;margin-right:auto;">
                        <img class="is-rounded" style="width:100%;height:100%;object-fit:cover;max-width:250px;max-height:250px;min-width:128px;min-height:128px;" src="<?php echo $_SESSION['profile_image'] ?? 'https://bulma.io/images/placeholders/128x128.png'; ?>" alt="Profile">
                    </figure>
                </div>
                <div class="column">
                    <div class="columns is-multiline">
                        <div class="column is-6-desktop is-12-mobile">
                            <div class="field">
                                <label class="label"><?php echo t('username'); ?></label>
                                <div class="control">
                                    <input class="input" type="text" value="<?php echo $_SESSION['username']; ?>" disabled>
                                </div>
                                <p class="help"><?php echo t('twitch_username_help'); ?></p>
                            </div>
                            <div class="field">
                                <label class="label"><?php echo t('display_name'); ?></label>
                                <div class="control">
                                    <input class="input" type="text" value="<?php echo $_SESSION['display_name'] ?? $_SESSION['username']; ?>" disabled>
                                </div>
                                <p class="help"><?php echo t('twitch_display_name_help'); ?></p>
                            </div>
                            <div class="field">
                                <label class="label"><?php echo t('you_joined'); ?></label>
                                <div class="control">
                                    <input class="input" type="text" value="<?php echo $joinedFormatted; ?>" disabled>
                                </div>
                                <p class="help"><?php echo t('joined_help'); ?></p>
                            </div>
                            <div class="field">
                                <label class="label"><?php echo t('last_login'); ?></label>
                                <div class="control">
                                    <input class="input" type="text" value="<?php echo $lastLoginFormatted; ?>" disabled>
                                </div>
                                <p class="help"><?php echo t('last_login_help'); ?></p>
                            </div>
                        </div>
                        <div class="column is-6-desktop is-12-mobile">
                            <div class="field">
                                <form method="post" action="" id="technical-mode-form" style="margin-bottom:0;">
                                    <input type="hidden" name="action" value="update_technical_mode">
                                    <label class="label" for="is-technical-select" style="font-weight: 500;"><?php echo t('technical_terms'); ?></label>
                                    <div class="control">
                                        <div class="select is-fullwidth">
                                            <select name="is_technical" id="is-technical-select" onchange="document.getElementById('technical-mode-form').submit();">
                                                <option value="1" <?php echo $isTechnical ? 'selected' : ''; ?>><?php echo t('yes'); ?></option>
                                                <option value="0" <?php echo !$isTechnical ? 'selected' : ''; ?>><?php echo t('no'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="help">
                                        <?php echo t('technical_help'); ?>
                                    </p>
                                </form>
                            </div>
                            <div class="field">
                                <form method="post" action="" id="language-form" style="margin-bottom:0;">
                                    <input type="hidden" name="action" value="update_language">
                                    <label class="label" for="language-select" style="font-weight: 500;"><?php echo t('language'); ?></label>
                                    <div class="control">
                                        <div class="select is-fullwidth">
                                            <select name="language" id="language-select" onchange="document.getElementById('language-form').submit();">
                                                <?php foreach ($languages as $lang): ?>
                                                    <option value="<?php echo htmlspecialchars($lang['code']); ?>" <?php if ($userLanguage === $lang['code']) echo 'selected'; ?>>
                                                        <?php echo htmlspecialchars($lang['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="help">
                                        <?php echo t('language_help'); ?>
                                    </p>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <div class="box h-100" id="cookie-consent-info" style="height:100%; position:relative; overflow:visible;">
            <div class="is-flex is-align-items-center mb-2">
                <span class="icon is-large mr-2"><i class="fas fa-cookie-bite fa-2x has-text-warning"></i></span>
                <div>
                    <h2 class="title is-5 mb-0"><?php echo t('cookie_consent_title'); ?></h2>
                    <p class="help mb-0"><?php echo t('cookie_consent_help'); ?></p>
                </div>
            </div>
            <div class="content" style="position:relative;">
                <?php
                $cookieConsent = isset($_COOKIE['cookie_consent']) ? $_COOKIE['cookie_consent'] : null;
                $tagHtml = '';
                if ($cookieConsent === 'accepted') {
                    $tagHtml = '<span class="tag is-success is-medium">' . t('cookie_accepted') . '</span>';
                    $infoText = t('cookie_accepted_info');
                } elseif ($cookieConsent === 'declined') {
                    $tagHtml = '<span class="tag is-danger is-medium">' . t('cookie_declined') . '</span>';
                    $infoText = t('cookie_declined_info');
                } else {
                    $tagHtml = '<span class="tag is-warning is-medium">' . t('cookie_not_set') . '</span>';
                    $infoText = t('cookie_not_set_info');
                }
                // Determine button label and id
                $cookieBtnLabel = ($cookieConsent === 'declined') ? t('cookie_accept_btn') : t('cookie_decline_btn');
                $cookieBtnId = ($cookieConsent === 'declined') ? 'accept-cookies-btn' : 'decline-cookies-btn';
                ?>
                <span>
                    <?php echo $infoText; ?>
                </span>
                <p class="help mt-2"><?php echo t('cookie_change_help'); ?></p>
                <button class="button is-small mt-2 <?php echo ($cookieConsent === 'declined') ? 'is-success' : 'is-danger'; ?>"
                    id="<?php echo $cookieBtnId; ?>" type="button" style="position:relative; z-index:3;">
                    <?php echo $cookieBtnLabel; ?>
                </button>
                <div style="
                    position: absolute;
                    right: -0.5rem;
                    bottom: -0.5rem;
                    z-index: 2;
                    margin: 0;
                    pointer-events: none;
                ">
                    <?php echo $tagHtml; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <div class="box h-100" id="timezone-box" style="height:100%;">
            <div class="is-flex is-align-items-center mb-2">
                <span class="icon is-large mr-2"><i class="fas fa-globe fa-2x has-text-link"></i></span>
                <div>
                    <h2 class="title is-5 mb-0"><?php echo t('timezone_title'); ?></h2>
                    <p class="help mb-0"><?php echo t('timezone_help'); ?></p>
                </div>
            </div>
            <form method="post" action="">
                <input type="hidden" name="action" value="update_timezone">
                <div class="field mb-3">
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="timezone">
                                <?php foreach ($timezoneOptions as $tz): ?>
                                    <option value="<?php echo $tz; ?>" <?php echo ($profileData['timezone'] ?? 'UTC') === $tz ? 'selected' : ''; ?>>
                                        <?php echo $tz; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button type="submit" class="button is-primary is-fullwidth"><?php echo t('save_timezone'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="column is-6">
        <div class="box h-100" id="weather-location-box" style="height:100%;">
            <div class="is-flex is-align-items-center mb-2">
                <span class="icon is-large mr-2"><i class="fas fa-cloud-sun fa-2x has-text-info"></i></span>
                <div>
                    <h2 class="title is-5 mb-0"><?php echo t('weather_location_title'); ?></h2>
                    <p class="help mb-0"><?php echo t('weather_location_help'); ?></p>
                </div>
            </div>
            <form method="post" action="">
                <input type="hidden" name="action" value="update_weather_location">
                <div class="field mb-3">
                    <div class="control has-icons-right">
                        <input class="input" type="text" name="weather_location" id="weather-location-input" value="<?php echo $profileData['weather_location'] ?? ''; ?>">
                        <span class="icon is-small is-right" id="weather-location-status" style="display:none;"></span>
                    </div>
                    <p class="help" id="weather-location-help"><?php echo t('weather_location_input_help'); ?></p>
                </div>
                <div class="field">
                    <div class="control">
                        <button type="submit" class="button is-primary is-fullwidth"><?php echo t('save_weather_location'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="column is-6">
        <div class="box h-100" id="api" style="height:100%;">
            <div class="is-flex is-align-items-center mb-2">
                <span class="icon is-large mr-2"><i class="fas fa-key fa-2x has-text-primary"></i></span>
                <div>
                    <h2 class="title is-4 mb-0"><?php echo t('api_access_title'); ?></h2>
                    <p class="help mb-3"><?php echo t('api_access_help'); ?></p>
                </div>
            </div>
            <div class="field mb-3">
                <label class="label"><?php echo t('api_key_label'); ?></label>
                <div class="control">
                    <div class="is-flex">
                        <input class="input" type="text" value="<?php echo $user['api_key'] ?? ''; ?>" id="api-key-field" readonly>
                        <button class="button ml-2" onclick="copyApiKey()" title="<?php echo t('copy_api_key'); ?>" id="copy-api-key-btn" disabled style="height: 2.5em; width: 2.5em; display: flex; align-items: center; justify-content: center;">
                            <span class="icon is-medium"><i class="fas fa-copy" id="copy-icon"></i></span>
                        </button>
                        <button class="button ml-2" onclick="toggleApiKeyVisibility()" title="<?php echo t('show_hide_api_key'); ?>" style="height: 2.5em; width: 2.5em; display: flex; align-items: center; justify-content: center;">
                            <span class="icon is-medium"><i class="fas fa-eye" id="visibility-icon"></i></span>
                        </button>
                        <form method="post" action="" id="regenerate-api-key-form" style="margin-left: 0.5rem;">
                            <input type="hidden" name="action" value="regenerate_api_key">
                            <button type="button" class="button is-warning" id="regenerate-api-key-btn" title="<?php echo t('regenerate_api_key'); ?>" style="height: 2.5em; width: 2.5em; display: flex; align-items: center; justify-content: center;">
                                <span class="icon is-medium"><i class="fas fa-sync-alt"></i></span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="column is-6">
        <div class="box h-100" id="storage-usage" style="height:100%; display: flex; flex-direction: column; justify-content: stretch;">
            <div class="is-flex is-align-items-center mb-2">
                <span class="icon is-large mr-2">
                    <i class="fas fa-database fa-2x has-text-info"></i>
                </span>
                <div>
                    <h2 class="title is-5 mb-0"><?php echo t('storage_usage_title'); ?></h2>
                    <p class="help mb-0"><?php echo t('storage_usage_help'); ?></p>
                </div>
            </div>
            <div class="mb-2">
                <span class="has-text-weight-bold"><?php echo $storageUsedFormatted; ?></span>
                <span class="has-text-white">/ <?php echo $storageMaxFormatted; ?></span>
                <span class="has-text-white" style="float:right;"><?php echo number_format($storagePercent, 2); ?><?php echo t('percent_used'); ?></span>
            </div>
            <div style="margin-bottom:3px;">
                <progress class="progress is-info" value="<?php echo $storagePercent; ?>" max="100" style="height: 10px; margin-bottom:0;">
                    <?php echo $storagePercent; ?>%
                </progress>
            </div>
            <div class="is-flex is-justify-content-space-between is-size-7" style="margin-top:0;">
                <span>0%</span>
                <span>25%</span>
                <span>50%</span>
                <span>75%</span>
                <span>100%</span>
            </div>
            <?php if ($storagePercent >= 100): ?>
                <div class="notification is-danger is-light mt-3 mb-0">
                    <strong><?php echo t('storage_limit_reached'); ?></strong>
                </div>
            <?php elseif ($storagePercent >= 90): ?>
                <div class="notification is-warning is-light mt-3 mb-0">
                    <strong><?php echo t('storage_almost_full'); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="column is-6">
        <div class="box h-100" id="heartrate" style="height:100%;">
            <div class="is-flex is-align-items-center mb-2">
                <span class="icon is-large mr-2"><i class="fas fa-heartbeat fa-2x has-text-danger"></i></span>
                <div>
                    <h2 class="title is-5 mb-0"><?php echo t('heartrate_code_title'); ?></h2>
                    <p class="help mb-0">
                        <?php echo t('heartrate_code_help'); ?>
                        <a href="https://www.hyperate.io/" target="_blank" rel="noopener noreferrer">HypeRate.io</a>
                    </p>
                </div>
            </div>
            <div class="content">
                <form method="post" action="" style="margin-bottom: 1em;">
                    <input type="hidden" name="action" value="update_heartrate_code">
                    <div class="field has-addons">
                        <div class="control is-expanded">
                            <input class="input" type="text" name="heartrate_code" value="<?php echo htmlspecialchars($heartrateCode ?? '', ENT_QUOTES); ?>" placeholder="<?php echo t('heartrate_code_placeholder'); ?>">
                        </div>
                        <div class="control">
                            <button type="submit" class="button is-primary"><?php echo t('heartrate_save'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="column is-12">
        <div class="box" id="connections">
            <h2 class="title is-4 mb-4"><?php echo t('connected_accounts_title'); ?></h2>
            <div class="columns is-multiline">
                <div class="column is-3">
                    <div class="box has-background-dark">
                        <div class="level">
                            <div class="level-left">
                                <div class="level-item">
                                    <span class="icon is-large" style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;">
                                        <i class="fab fa-twitch fa-2x has-text-primary" style="font-size:2.5em;width:2.5em;height:2.5em;line-height:2.5em;text-align:center;"></i>
                                    </span>
                                </div>
                                <div class="level-item">
                                    <div>
                                        <p class="heading"><?php echo t('twitch'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="level-right">
                                <div class="level-item">
                                    <span class="tag is-success"><?php echo t('connected'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="box has-background-dark">
                        <div class="level">
                            <div class="level-left">
                                <div class="level-item">
                                    <span class="icon is-large" style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;">
                                        <i class="fab fa-discord fa-2x has-text-info" style="font-size:2.5em;width:2.5em;height:2.5em;line-height:2.5em;text-align:center;"></i>
                                    </span>
                                </div>
                                <div class="level-item">
                                    <div>
                                        <p class="heading"><?php echo t('discord'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="level-right">
                                <div class="level-item">
                                    <?php if ($discordLinked): ?>
                                        <span class="tag is-success"><?php echo t('connected'); ?></span>
                                    <?php else: ?>
                                        <a href="discordbot.php" class="button is-small"><?php echo t('connect'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="box has-background-dark">
                        <div class="level">
                            <div class="level-left">
                                <div class="level-item">
                                    <span class="icon is-large" style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;">
                                        <i class="fab fa-spotify fa-2x has-text-success" style="font-size:2.5em;width:2.5em;height:2.5em;line-height:2.5em;text-align:center;"></i>
                                    </span>
                                </div>
                                <div class="level-item">
                                    <div>
                                        <p class="heading"><?php echo t('spotify'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="level-right">
                                <div class="level-item">
                                    <?php if ($spotifyLinked): ?>
                                        <span class="tag is-success"><?php echo t('connected'); ?></span>
                                    <?php else: ?>
                                        <a href="spotifylink.php" class="button is-small"><?php echo t('connect'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="box has-background-dark">
                        <div class="level">
                            <div class="level-left">
                                <div class="level-item">
                                    <span class="icon is-large" style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;">
                                        <img src="https://cdn.brandfetch.io/idj4DI2QBL/w/400/h/400/theme/dark/icon.png?c=1dxbfHSJFAPEGdCLU4o5B" alt="StreamElements" style="width:2.5em;height:2.5em;object-fit:cover;border-radius:50%;background:#222;display:block;">
                                    </span>
                                </div>
                                <div class="level-item">
                                    <div>
                                        <p class="heading"><?php echo t('streamelements'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="level-right">
                                <div class="level-item">
                                    <?php if ($streamelementsLinked): ?>
                                        <span class="tag is-success"><?php echo t('connected'); ?></span>
                                    <?php else: ?>
                                        <a href="streamelements.php" class="button is-small"><?php echo t('connect'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Example for a 4th account slot, add more as needed -->
                <!--
                <div class="column is-3">
                    <div class="box has-background-dark">
                        <div class="level">
                            <div class="level-left">
                                <div class="level-item">
                                    <span class="icon is-large">
                                        <i class="fab fa-example fa-2x"></i>
                                    </span>
                                </div>
                                <div class="level-item">
                                    <div>
                                        <p class="heading">Example</p>
                                    </div>
                                </div>
                            </div>
                            <div class="level-right">
                                <div class="level-item">
                                    <span class="tag is-danger">Not Connected</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>-->
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
function copyApiKey() {
    const apiKeyField = document.getElementById('api-key-field');
    const copyBtn = document.getElementById('copy-api-key-btn');
    if (apiKeyField.type === 'password') {
        return; // Don't copy if key is hidden
    }
    // Try modern clipboard API first, fall back to older method
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(apiKeyField.value).then(() => {
            showCopyNotification();
            showCopyFeedback();
        }).catch(err => {
            console.error('Failed to copy: ', err);
            fallbackCopy();
        });
    } else {
        fallbackCopy();
    }
    function fallbackCopy() {
        // Create a temporary textarea to copy the text
        const tempTextarea = document.createElement('textarea');
        tempTextarea.value = apiKeyField.value;
        tempTextarea.style.position = 'fixed';
        tempTextarea.style.left = '-999999px';
        tempTextarea.style.top = '-999999px';
        document.body.appendChild(tempTextarea);
        tempTextarea.focus();
        tempTextarea.select();
        try {
            document.execCommand('copy');
            showCopyNotification();
            showCopyFeedback();
        } catch (err) {
            console.error('Fallback copy failed: ', err);
        }
        
        document.body.removeChild(tempTextarea);
    }
    function showCopyFeedback() {
        // Visual feedback on button
        const copyIcon = document.getElementById('copy-icon');
        copyIcon.classList.remove('fa-copy');
        copyIcon.classList.add('fa-check');
        copyBtn.classList.add('is-success');
        setTimeout(() => {
            copyIcon.classList.remove('fa-check');
            copyIcon.classList.add('fa-copy');
            copyBtn.classList.remove('is-success');
        }, 2000);
    }
}

function showCopyNotification() {
    // Remove any existing notifications
    const existingNotifications = document.querySelectorAll('.copy-notification');
    existingNotifications.forEach(notification => notification.remove());
    // Create notification
    const notification = document.createElement('div');
    notification.className = 'notification is-success is-light copy-notification';
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideInRight 0.3s ease-out;';
    notification.innerHTML = `
        <button class="delete" onclick="this.parentElement.remove()"></button>
        <strong><i class="fas fa-check-circle mr-2"></i><?php echo t('api_key_copied'); ?></strong>
    `;
    document.body.appendChild(notification);
    // Auto-remove after 3 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 300);
        }
    }, 3000);
}

function toggleApiKeyVisibility() {
    const apiKeyField = document.getElementById('api-key-field');
    const visibilityIcon = document.getElementById('visibility-icon');
    const copyBtn = document.getElementById('copy-api-key-btn');
    if (apiKeyField.type === 'password') {
        Swal.fire({
            title: <?php echo json_encode(t('sensitive_info_title')); ?>,
            text: <?php echo json_encode(t('sensitive_info_text')); ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: <?php echo json_encode(t('show_api_key')); ?>,
            cancelButtonText: <?php echo json_encode(t('cancel')); ?>
        }).then((result) => {
            if (result.isConfirmed) {
                apiKeyField.type = 'text';
                visibilityIcon.classList.remove('fa-eye');
                visibilityIcon.classList.add('fa-eye-slash');
                copyBtn.disabled = false; // Enable copy button
            }
        });
    } else {
        apiKeyField.type = 'password';
        visibilityIcon.classList.remove('fa-eye-slash');
        visibilityIcon.classList.add('fa-eye');
        copyBtn.disabled = true; // Disable copy button
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const apiKeyField = document.getElementById('api-key-field');
    if (apiKeyField) {
        apiKeyField.type = 'password';
    }
    const deleteButtons = Array.prototype.slice.call(document.querySelectorAll('.notification .delete'), 0);
    deleteButtons.forEach(function(deleteButton) {
        const notification = deleteButton.parentNode;
        deleteButton.addEventListener('click', function() {
            notification.parentNode.removeChild(notification);
        });
    });
    const regenBtn = document.getElementById('regenerate-api-key-btn');
    if (regenBtn) {
        regenBtn.addEventListener('click', function(e) {
            Swal.fire({
                title: <?php echo json_encode(t('regenerate_api_key_title')); ?>,
                text: <?php echo json_encode(t('regenerate_api_key_text')); ?>,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: <?php echo json_encode(t('yes_regenerate')); ?>,
                cancelButtonText: <?php echo json_encode(t('cancel')); ?>
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('regenerate-api-key-form').submit();
                }
            });
        });
    }

    // Weather location validation
    const weatherInput = document.getElementById('weather-location-input');
    const statusIcon = document.getElementById('weather-location-status');
    const helpText = document.getElementById('weather-location-help');
    const apiKey = <?php echo json_encode($user['api_key'] ?? ''); ?>;
    let weatherTimeout = null;
    // Helper to get cookie value by name
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }
    // Helper to set cookie
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + (days*24*60*60*1000));
        document.cookie = name + "=" + encodeURIComponent(value) + ";expires=" + d.toUTCString() + ";path=/";
    }
    // Check if user has accepted cookies
    function hasCookieConsent() {
        return getCookie('cookie_consent') === 'accepted';
    }
    function validateWeatherLocation() {
        const location = weatherInput.value.trim();
        statusIcon.style.display = 'none';
        helpText.textContent = <?php echo json_encode(t('weather_location_input_help')); ?>;
        if (!location) return;
        // Only validate if cookies are accepted
        if (!hasCookieConsent()) {
            // Don't check API, just allow user to save
            statusIcon.style.display = 'none';
            helpText.textContent = <?php echo json_encode(t('weather_location_input_help')); ?>;
            return;
        }
        // If user accepted cookies, check for cached validation
        const cached = getCookie('weather_location_valid');
        if (cached) {
            try {
                const cachedObj = JSON.parse(cached);
                if (cachedObj.location === location) {
                    // Use cached result
                    if (cachedObj.valid) {
                        statusIcon.innerHTML = '<i class="fas fa-check has-text-success"></i>';
                        helpText.textContent = cachedObj.message || <?php echo json_encode(t('location_is_valid')); ?>;
                    } else {
                        statusIcon.innerHTML = '<i class="fas fa-times has-text-danger"></i>';
                        helpText.textContent = cachedObj.message || <?php echo json_encode(t('location_not_found')); ?>;
                    }
                    statusIcon.style.display = '';
                    return;
                }
            } catch (e) { /* ignore parse errors */ }
        }
        statusIcon.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        statusIcon.style.display = '';
        fetch(`https://api.botofthespecter.com/weather/location?api_key=${encodeURIComponent(apiKey)}&location=${encodeURIComponent(location)}`)
            .then(res => {
                return res.json().then(data => {
                    let valid = false, msg = "";
                    if (res.ok && data && data.message) {
                        statusIcon.innerHTML = '<i class="fas fa-check has-text-success"></i>';
                        helpText.textContent = data.message;
                        valid = true;
                        msg = data.message;
                    } else if (data && data.detail && data.detail.includes("not found")) {
                        statusIcon.innerHTML = '<i class="fas fa-times has-text-danger"></i>';
                        helpText.textContent = <?php echo json_encode(t('weather_location_not_found')); ?>;
                        valid = false;
                        msg = <?php echo json_encode(t('weather_location_not_found')); ?>;
                    } else {
                        statusIcon.innerHTML = '<i class="fas fa-exclamation-triangle has-text-warning"></i>';
                        helpText.textContent = <?php echo json_encode(t('weather_location_could_not_validate')); ?>;
                        valid = false;
                        msg = <?php echo json_encode(t('weather_location_could_not_validate')); ?>;
                    }
                    statusIcon.style.display = '';
                    if (hasCookieConsent()) {
                        setCookie('weather_location_valid', JSON.stringify({
                            location: location,
                            valid: valid,
                            message: msg
                        }), 7);
                    }
                });
            })
            .catch(() => {
                statusIcon.innerHTML = '<i class="fas fa-exclamation-triangle has-text-warning"></i>';
                helpText.textContent = <?php echo json_encode(t('weather_location_could_not_validate')); ?>;
                statusIcon.style.display = '';
            });
    }
    if (weatherInput) {
        weatherInput.addEventListener('input', function() {
            clearTimeout(weatherTimeout);
            weatherTimeout = setTimeout(validateWeatherLocation, 700);
        });
        // Validate on page load if value exists
        if (weatherInput.value.trim()) {
            validateWeatherLocation();
        }
    }

    // Cookie Consent Button
    const declineBtn = document.getElementById('decline-cookies-btn');
    if (declineBtn) {
        declineBtn.addEventListener('click', function() {
            // Set cookie_consent to declined
            document.cookie = "cookie_consent=declined; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/";
            // Remove specific cookies
            ["weather_location_valid", "selectedBot"].forEach(function(name) {
                document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
            });
            location.reload();
        });
    }
    const acceptBtn = document.getElementById('accept-cookies-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            // Set cookie_consent to accepted
            document.cookie = "cookie_consent=accepted; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/";
            location.reload();
        });
    }
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>