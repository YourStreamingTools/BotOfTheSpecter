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
    echo "<div class='error'>Error fetching version data.</div>";
}

// Directly ping the servers
$apiPingStatus = pingServer('10.240.0.120', 443);
$apiServiceStatus = ['status' => $apiPingStatus >= 0 ? 'OK' : 'OFF'];

$websocketPingStatus = pingServer('10.240.0.254', 443);
$notificationServiceStatus = ['status' => $websocketPingStatus >= 0 ? 'OK' : 'OFF'];

$databasePingStatus = pingServer('10.240.0.40', 3306);
$databaseServiceStatus = ['status' => $databasePingStatus >= 0 ? 'OK' : 'OFF'];

function checkServiceStatus($serviceName, $serviceData) {
    if ($serviceData && $serviceData['status'] === 'OK') {
        return "<p><strong>$serviceName:</strong> <span class='heartbeat beating'>❤️</span></p>";
    } else {
        return "<p><strong>$serviceName:</strong> <span>💀</span></p>";
    }
}

// Determine overall system health
$overallHealth = ($heartbeatStatus === 'OK' && 
                  isset($apiServiceStatus['status']) && $apiServiceStatus['status'] === 'OK' && 
                  isset($databaseServiceStatus['status']) && $databaseServiceStatus['status'] === 'OK' && 
                  isset($notificationServiceStatus['status']) && $notificationServiceStatus['status'] === 'OK') ? 'OK' : 'OFF';

// Fetch song request data
$songData = fetchData('https://api.botofthespecter.com/api/song');
if ($songData) {
    $songDaysRemaining = $songData['days_remaining'];
    $songRequestsRemaining = $songData['requests_remaining'];
} else {
    echo "<div class='error'>Error fetching song request data.</div>";
}

// Fetch exchange rate request data
$exchangeRateData = fetchData('https://api.botofthespecter.com/api/exchangerate');
if ($exchangeRateData) {
    $exchangeRateDaysRemaining = $exchangeRateData['days_remaining'];
    $exchangeRateRequestsRemaining = $exchangeRateData['requests_remaining'];
} else {
    echo "<div class='error'>Error fetching exchange rate data.</div>";
}

// Fetch weather request data
$weatherData = fetchData('https://api.botofthespecter.com/api/weather');
if ($weatherData) {
    $weatherRequestsRemaining = $weatherData['requests_remaining'];
} else {
    echo "<div class='error'>Error fetching weather data.</div>";
}

// Calculate time remaining until midnight
$currentDateTime = new DateTime();
$midnight = new DateTime('tomorrow midnight');
$interval = $currentDateTime->diff($midnight);
$secondsUntilMidnight = $interval->h * 3600 + $interval->i * 60 + $interval->s;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="60"> <!-- Auto-refresh every 60 seconds -->
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
<div class="info">
    <?= checkServiceStatus('API Service', $apiServiceStatus); ?>
    <?= checkServiceStatus('Database Service', $databaseServiceStatus); ?>
    <?= checkServiceStatus('Notification Service', $notificationServiceStatus); ?>
</div>

<!-- Display Versions -->
<div class="info">
    <p><strong>Alpha Version:</strong> <?= isset($alphaVersion) ? $alphaVersion : 'N/A'; ?></p>
    <p><strong>Beta Version:</strong> <?= isset($betaVersion) ? $betaVersion : 'N/A'; ?></p>
    <p><strong>Stable Version:</strong> <?= isset($stableVersion) ? $stableVersion : 'N/A'; ?></p>
</div>

<!-- Display Song Request Info -->
<div class="info">
    <p><strong>Song Days Remaining:</strong> <?= isset($songDaysRemaining) ? $songDaysRemaining : 'N/A'; ?></p>
    <p><strong>Song Requests Remaining:</strong> <?= isset($songRequestsRemaining) ? $songRequestsRemaining : 'N/A'; ?></p>
</div>

<!-- Display Exchange Rate Request Info -->
<div class="info">
    <p><strong>Exchange Rate Days Remaining:</strong> <?= isset($exchangeRateDaysRemaining) ? $exchangeRateDaysRemaining : 'N/A'; ?></p>
    <p><strong>Exchange Rate Requests Remaining:</strong> <?= isset($exchangeRateRequestsRemaining) ? $exchangeRateRequestsRemaining : 'N/A'; ?></p>
</div>

<!-- Display Weather Request Info -->
<div class="info">
    <p><strong>Weather Requests Remaining Today:</strong> <?= isset($weatherRequestsRemaining) ? $weatherRequestsRemaining : 'N/A'; ?></p>
    <p><strong>Time Remaining Until Midnight:</strong><div id="countdown"><?php if (isset($secondsUntilMidnight)) { ?>
        <span id="countdown-time"><?= floor($secondsUntilMidnight / 3600) . 'h ' . floor(($secondsUntilMidnight % 3600) / 60) . 'm ' . ($secondsUntilMidnight % 60) . 's' ?></span>
        <?php } else { ?><?php } ?>
    </div>
    </p>
</div>

<script>
// Countdown Timer for Time Remaining Until Midnight
function startCountdown(timeRemainingInSeconds) {
    var countdownElement = document.getElementById("countdown-time");
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
}

// Start the countdown with the time in seconds calculated from PHP
<?php if (isset($secondsUntilMidnight)) { ?>
    var timeInSeconds = <?= $secondsUntilMidnight; ?>;
    startCountdown(timeInSeconds);
<?php } ?>
</script>
</body>
</html>