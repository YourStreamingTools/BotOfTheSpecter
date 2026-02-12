<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Require login
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page setup
$pageTitle = 'Twitch Schedule';

// Includes used across dashboard pages
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';

// Get user's timezone from profile (fallback to UTC)
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Fetch schedule from Twitch Helix
$schedule = null;
$error = null;
$broadcasterId = $_SESSION['twitchUserId'] ?? null;
if (empty($broadcasterId)) {
    $error = 'Broadcaster ID not available. Please re-login.';
} else {
    $url = 'https://api.twitch.tv/helix/schedule?broadcaster_id=' . urlencode($broadcasterId) . '&first=25';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $headers = [
        'Client-ID: ' . $clientID,
        'Authorization: Bearer ' . $_SESSION['access_token']
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        $error = 'Twitch API request failed: ' . htmlspecialchars($curlErr);
    } elseif ($httpCode === 200) {
        $data = json_decode($resp, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['data'])) {
            $schedule = $data['data'];
        } else {
            $error = 'Unable to parse Twitch response.';
        }
    } elseif ($httpCode === 401) {
        $error = 'Unauthorized. Your Twitch session may need re-linking.';
    } elseif ($httpCode === 404) {
        $error = 'No schedule found for this broadcaster.';
    } else {
        $error = 'Twitch API returned HTTP ' . $httpCode . '.';
    }
}

// Helper: format RFC3339 -> localized date/time string
function fmt_dt($rfc3339, $tz, $format = 'D, j M Y - g:ia T') {
    if (empty($rfc3339)) return '—';
    try {
        $dt = new DateTime($rfc3339, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($format);
    } catch (Exception $e) {
        return htmlspecialchars($rfc3339);
    }
}

// Render page content with output buffering
ob_start();
?>
<div class="hero is-small has-background-dark">
    <div class="hero-body">
        <div class="container">
            <h1 class="title is-3 has-text-white"><i class="fas fa-calendar-days"></i> Twitch Schedule</h1>
            <p class="subtitle has-text-grey-light">Displays the broadcaster's official Twitch schedule (read-only).</p>
        </div>
    </div>
</div>
<section class="section">
    <div class="container">
        <?php if ($error): ?>
            <div class="notification is-danger is-light">
                <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                <strong>Notice:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if (empty($schedule) || empty($schedule['segments'])): ?>
            <div class="box has-background-dark">
                <div class="content has-text-centered">
                    <p class="title is-5 has-text-white">No scheduled segments</p>
                    <p class="has-text-grey-light">You don't have any scheduled stream segments on Twitch. Use the Twitch Creator Dashboard to add schedule entries.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="columns is-multiline">
                <?php foreach ($schedule['segments'] as $seg):
                    $start = fmt_dt($seg['start_time'] ?? null, $timezone);
                    $end = fmt_dt($seg['end_time'] ?? null, $timezone);
                    $category = $seg['category']['name'] ?? null;
                    $isRecurring = !empty($seg['is_recurring']) ? true : false;
                    $canceled = !empty($seg['canceled_until']);
                ?>
                <div class="column is-6-tablet is-4-desktop">
                    <div class="card">
                        <header class="card-header">
                            <p class="card-header-title">
                                <?php echo htmlspecialchars($seg['title'] ?: 'Untitled'); ?>
                            </p>
                            <span class="card-header-icon has-text-grey-light" aria-hidden="true">
                                <?php if ($isRecurring): ?>
                                    <span class="tag is-small is-info">Recurring</span>
                                <?php endif; ?>
                                <?php if ($canceled): ?>
                                    <span class="tag is-small is-danger">Canceled</span>
                                <?php endif; ?>
                            </span>
                        </header>
                        <div class="card-content">
                            <div class="content">
                                <p class="mb-2"><strong>When</strong><br><?php echo $start; ?> &ndash; <?php echo $end; ?></p>
                                <p class="mb-2"><strong>Category</strong><br><?php echo $category ? htmlspecialchars($category) : '<em>Not specified</em>'; ?></p>
                                <?php if (!empty($seg['is_recurring'])): ?>
                                    <p class="mb-0 has-text-grey">This segment is part of a recurring series.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <footer class="card-footer">
                            <a class="card-footer-item" href="https://www.twitch.tv/<?php echo htmlspecialchars($schedule['broadcaster_login'] ?? ($_SESSION['username'] ?? '')); ?>" target="_blank">View channel</a>
                            <a class="card-footer-item" href="https://www.twitch.tv/<?php echo htmlspecialchars($schedule['broadcaster_login'] ?? ($_SESSION['username'] ?? '')); ?>/schedule" target="_blank">Open schedule</a>
                        </footer>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($schedule['vacation'])): ?>
                <div class="box mt-4 has-background-dark">
                    <p class="title is-6 has-text-white">Vacation / Off dates</p>
                    <p class="has-text-grey-light">From <?php echo fmt_dt($schedule['vacation']['start_time'] ?? null, $timezone); ?> to <?php echo fmt_dt($schedule['vacation']['end_time'] ?? null, $timezone); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
include 'layout.php';
?>