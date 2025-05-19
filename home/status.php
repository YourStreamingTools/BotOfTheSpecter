<?php
$heartbeatStatus = '';

function fetchData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function pingServer($host, $port) {
    $starttime = microtime(true);
    $file = @fsockopen($host, $port, $errno, $errstr, 2);
    $stoptime = microtime(true);
    $status = 0;

    if (!$file) {
        $status = -1;  // Site is down
    } else {
        fclose($file);
        $status = ($stoptime - $starttime) * 1000;
        $status = floor($status);
    }
    return $status;
}

// Fetch version data
$versionData = fetchData('https://api.botofthespecter.com/versions');
if ($versionData) {
    $alphaVersion = $versionData['alpha_version'];
    $betaVersion = $versionData['beta_version'];
    $stableVersion = $versionData['stable_version'];
} else {
    $alphaVersion = $betaVersion = $stableVersion = null;
}

// Directly ping the servers
$apiPingStatus = pingServer('10.240.0.120', 443);
$apiServiceStatus = ['status' => $apiPingStatus >= 0 ? 'OK' : 'OFF'];

$websocketPingStatus = pingServer('10.240.0.254', 443);
$notificationServiceStatus = ['status' => $websocketPingStatus >= 0 ? 'OK' : 'OFF'];

$databasePingStatus = pingServer('10.240.0.40', 3306);
$databaseServiceStatus = ['status' => $databasePingStatus >= 0 ? 'OK' : 'OFF'];

// Fetch song request data
$songData = fetchData('https://api.botofthespecter.com/api/song');
if ($songData) {
    $songDaysRemaining = $songData['days_remaining'];
    $songRequestsRemaining = $songData['requests_remaining'];
} else {
    $songDaysRemaining = $songRequestsRemaining = null;
}

// Fetch exchange rate request data
$exchangeRateData = fetchData('https://api.botofthespecter.com/api/exchangerate');
if ($exchangeRateData) {
    $exchangeRateDaysRemaining = $exchangeRateData['days_remaining'];
    $exchangeRateRequestsRemaining = $exchangeRateData['requests_remaining'];
} else {
    $exchangeRateDaysRemaining = $exchangeRateRequestsRemaining = null;
}

// Fetch weather request data
$weatherData = fetchData('https://api.botofthespecter.com/api/weather');
if ($weatherData) {
    $weatherRequestsRemaining = $weatherData['requests_remaining'];
} else {
    $weatherRequestsRemaining = null;
}

// Calculate time remaining until midnight
$currentDateTime = new DateTime();
$midnight = new DateTime('tomorrow midnight');
$interval = $currentDateTime->diff($midnight);
$secondsUntilMidnight = $interval->h * 3600 + $interval->i * 60 + $interval->s;

// AJAX endpoint for JS polling
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'apiServiceStatus' => $apiServiceStatus,
        'databaseServiceStatus' => $databaseServiceStatus,
        'notificationServiceStatus' => $notificationServiceStatus,
        'alphaVersion' => $alphaVersion,
        'betaVersion' => $betaVersion,
        'stableVersion' => $stableVersion,
        'songDaysRemaining' => $songDaysRemaining,
        'songRequestsRemaining' => $songRequestsRemaining,
        'exchangeRateDaysRemaining' => $exchangeRateDaysRemaining,
        'exchangeRateRequestsRemaining' => $exchangeRateRequestsRemaining,
        'weatherRequestsRemaining' => $weatherRequestsRemaining,
        'secondsUntilMidnight' => $secondsUntilMidnight
    ]);
    exit;
}

function checkServiceStatus($serviceName, $serviceData) {
    if ($serviceData && $serviceData['status'] === 'OK') {
        return "<p><strong>$serviceName:</strong> <span class='heartbeat beating'>❤️</span></p>";
    } else {
        return "<p><strong>$serviceName:</strong> <span>💀</span></p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Status</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 16px; color: #ffffff; height: 100vh; overflow: hidden; display: flex; flex-direction: column; align-items: flex-start; justify-content: flex-start; padding: 20px; }
        .info, .heartbeat-container, .error { font-size: 26px; margin: 10px 0; }
        .heartbeat-container { display: flex; align-items: center; margin-bottom: 20px; }
        .heartbeat { color: #ff4d4d; transition: transform 0.2s ease; }
        .heartbeat.beating { color: #76ff7a; animation: beat 1s infinite; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .error { color: #ff4d4d; }
        .countdown { margin-top: 10px; color: #ffffff; }
    </style>
</head>
<body>
<!-- Display Additional Service Statuses -->
<div class="info" id="service-status">
    <?= checkServiceStatus('API Service', $apiServiceStatus); ?>
    <?= checkServiceStatus('Database Service', $databaseServiceStatus); ?>
    <?= checkServiceStatus('Notification Service', $notificationServiceStatus); ?>
</div>

<!-- Display Versions -->
<div class="info" id="version-info">
    <p><strong>Alpha Version:</strong> <span id="alpha-version"><?= isset($alphaVersion) ? $alphaVersion : 'N/A'; ?></span></p>
    <p><strong>Beta Version:</strong> <span id="beta-version"><?= isset($betaVersion) ? $betaVersion : 'N/A'; ?></span></p>
    <p><strong>Stable Version:</strong> <span id="stable-version"><?= isset($stableVersion) ? $stableVersion : 'N/A'; ?></span></p>
</div>

<!-- Display Song Request Info -->
<div class="info" id="song-info">
    <p><strong>Song Days Remaining:</strong> <span id="song-days"><?= isset($songDaysRemaining) ? $songDaysRemaining : 'N/A'; ?></span></p>
    <p><strong>Song Requests Remaining:</strong> <span id="song-requests"><?= isset($songRequestsRemaining) ? $songRequestsRemaining : 'N/A'; ?></span></p>
</div>

<!-- Display Exchange Rate Request Info -->
<div class="info" id="exchange-info">
    <p><strong>Exchange Rate Days Remaining:</strong> <span id="exchange-days"><?= isset($exchangeRateDaysRemaining) ? $exchangeRateDaysRemaining : 'N/A'; ?></span></p>
    <p><strong>Exchange Rate Requests Remaining:</strong> <span id="exchange-requests"><?= isset($exchangeRateRequestsRemaining) ? $exchangeRateRequestsRemaining : 'N/A'; ?></span></p>
</div>

<!-- Display Weather Request Info -->
<div class="info" id="weather-info">
    <p><strong>Weather Requests Remaining Today:</strong> <span id="weather-requests"><?= isset($weatherRequestsRemaining) ? $weatherRequestsRemaining : 'N/A'; ?></span></p>
    <p><strong>Time Remaining Until Midnight:</strong>
        <div id="countdown">
            <?php if (isset($secondsUntilMidnight)) { ?>
                <span id="countdown-time"><?= floor($secondsUntilMidnight / 3600) . 'h ' . floor(($secondsUntilMidnight % 3600) / 60) . 'm ' . ($secondsUntilMidnight % 60) . 's' ?></span>
            <?php } ?>
        </div>
    </p>
</div>

<script>
// Countdown Timer for Time Remaining Until Midnight
function startCountdown(timeRemainingInSeconds) {
    var countdownElement = document.getElementById("countdown-time");
    if (!countdownElement) return;
    var countdownInterval = setInterval(function() {
        if (timeRemainingInSeconds <= 0) {
            countdownElement.innerHTML = "Time's up!";
            clearInterval(countdownInterval);
        } else {
            var hours = Math.floor(timeRemainingInSeconds / 3600);
            var minutes = Math.floor((timeRemainingInSeconds % 3600) / 60);
            var seconds = timeRemainingInSeconds % 60;
            countdownElement.innerHTML = `${hours}h ${minutes}m ${seconds}s`;
            timeRemainingInSeconds--;
        }
    }, 1000);
    // Store interval so we can clear it on update
    window._countdownInterval = countdownInterval;
}

// Helper to update service status HTML
function renderServiceStatus(name, status) {
    if (status === 'OK') {
        return `<p><strong>${name}:</strong> <span class='heartbeat beating'>❤️</span></p>`;
    } else {
        return `<p><strong>${name}:</strong> <span>💀</span></p>`;
    }
}

// Fetch and update data every 60 seconds
function fetchAndUpdateStatus() {
    fetch(window.location.pathname + '?ajax=1')
        .then(res => res.json())
        .then(data => {
            // Update service statuses
            document.getElementById('service-status').innerHTML =
                renderServiceStatus('API Service', data.apiServiceStatus.status) +
                renderServiceStatus('Database Service', data.databaseServiceStatus.status) +
                renderServiceStatus('Notification Service', data.notificationServiceStatus.status);

            // Update versions
            document.getElementById('alpha-version').textContent = data.alphaVersion ?? 'N/A';
            document.getElementById('beta-version').textContent = data.betaVersion ?? 'N/A';
            document.getElementById('stable-version').textContent = data.stableVersion ?? 'N/A';

            // Update song info
            document.getElementById('song-days').textContent = data.songDaysRemaining ?? 'N/A';
            document.getElementById('song-requests').textContent = data.songRequestsRemaining ?? 'N/A';

            // Update exchange info
            document.getElementById('exchange-days').textContent = data.exchangeRateDaysRemaining ?? 'N/A';
            document.getElementById('exchange-requests').textContent = data.exchangeRateRequestsRemaining ?? 'N/A';

            // Update weather info
            document.getElementById('weather-requests').textContent = data.weatherRequestsRemaining ?? 'N/A';

            // Update countdown
            if (typeof window._countdownInterval !== 'undefined') {
                clearInterval(window._countdownInterval);
            }
            var countdownTime = document.getElementById('countdown-time');
            if (countdownTime) {
                countdownTime.textContent = '';
            }
            if (data.secondsUntilMidnight !== undefined) {
                if (!countdownTime) {
                    // If the element doesn't exist, create it
                    var cd = document.createElement('span');
                    cd.id = 'countdown-time';
                    document.getElementById('countdown').appendChild(cd);
                }
                startCountdown(data.secondsUntilMidnight);
            }
        });
}

// Start the countdown with the time in seconds calculated from PHP
<?php if (isset($secondsUntilMidnight)) { ?>
    var timeInSeconds = <?= $secondsUntilMidnight; ?>;
    startCountdown(timeInSeconds);
<?php } ?>

// Poll every 60 seconds
setInterval(fetchAndUpdateStatus, 60000);
// Also fetch immediately on load
fetchAndUpdateStatus();
</script>
</body>
</html>