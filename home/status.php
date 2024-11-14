<?php
function fetchData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Fetch version data
$versionData = fetchData('https://api.botofthespecter.com/versions');
if ($versionData) {
    $betaVersion = $versionData['beta_version'];
    $stableVersion = $versionData['stable_version'];
} else {
    echo "<div class='error'>Error fetching version data.</div>";
}

// Fetch heartbeat data
$heartbeatData = fetchData('https://api.botofthespecter.com/websocket/heartbeat');
if ($heartbeatData && $heartbeatData['status'] === 'OK') {
    $heartbeatStatus = 'OK';
} else {
    $heartbeatStatus = 'OFF';
}

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
    <meta http-equiv="refresh" content="10"> <!-- Auto-refresh every 10 seconds -->
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
<!-- Display System Health with Heartbeat -->
<div class="heartbeat-container">
    <span id="Sydney_z60b" style="font-size:36px;color:FFFFFF"></span>
    <p><strong>System Health:</strong></p>
    <div class="heartbeat <?= ($heartbeatStatus === 'OK') ? 'beating' : ''; ?>">
        <?= ($heartbeatStatus === 'OK') ? '❤️' : '💔'; ?>
    </div>
</div>

<!-- Display Versions -->
<div class="info">
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

<script src="//widget.time.is/en.js"></script>
<script>time_is_widget.init({Sydney_z60b:{template:"TIME<br>DATE", time_format:"12hours:minutes:secondsAMPM", date_format:"dayname, dnum monthname year"}});</script>
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