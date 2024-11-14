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
    $timeRemainingUntilMidnight = $weatherData['time_remaining'];  // Time in seconds
} else {
    echo "<div class='error'>Error fetching weather data.</div>";
}
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
        body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; color: #ffffff; overflow: hidden; }
        .container { text-align: center; }
        h1 { font-size: 2.5em; }
        .info { margin: 20px 0; }
        .heartbeat-container { display: flex; align-items: center; justify-content: center; font-size: 1.5em; }
        .heartbeat { font-size: 2.5em; color: #ff4d4d; transition: transform 0.2s ease; }
        .heartbeat.beating { color: #76ff7a; animation: beat 1s infinite; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .error { color: #ff4d4d; }
        .countdown { font-size: 1.2em; margin-top: 10px; }
    </style>
</head>
<body>

<div class="container">
    <!-- Display System Health with Heartbeat -->
    <div class="heartbeat-container">
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
        <p><strong>Time Remaining Until Midnight:</strong><br><div id="countdown" class="countdown"></div></p>
    </div>

</div>

<script>
// Countdown Timer for Time Remaining Until Midnight
function parseTimeRemaining(timeRemaining) {
    // Regular expression to extract hours, minutes, and seconds
    var regex = /(\d+)\s*hours?\,\s*(\d+)\s*minutes?\,\s*(\d+)\s*seconds?/;
    var match = timeRemaining.match(regex);
    if (match) {
        // Extract hours, minutes, and seconds
        var hours = parseInt(match[1], 10);
        var minutes = parseInt(match[2], 10);
        var seconds = parseInt(match[3], 10);
        // Convert all time into total seconds
        return (hours * 3600) + (minutes * 60) + seconds;
    }
    return 0; // If the time format is not recognized, return 0 seconds
}

function startCountdown(timeRemainingInSeconds) {
    var countdownElement = document.getElementById("countdown");
    var countdownInterval = setInterval(function() {
        if (timeRemainingInSeconds <= 0) {
            countdownElement.innerHTML = "Time's up!";
            clearInterval(countdownInterval);
        } else {
            var hours = Math.floor(timeRemainingInSeconds / 3600);
            var minutes = Math.floor((timeRemainingInSeconds % 3600) / 60);
            var seconds = timeRemainingInSeconds % 60;
            countdownElement.innerHTML = `${hours}h ${minutes}m ${seconds}s remaining until midnight`;
            timeRemainingInSeconds--;
        }
    }, 1000); // Update every second
}

// Start the countdown with the time in seconds
<?php if (isset($timeRemainingUntilMidnight) && is_string($timeRemainingUntilMidnight)) { ?>
    var timeInSeconds = parseTimeRemaining("<?= addslashes($timeRemainingUntilMidnight); ?>");
    startCountdown(timeInSeconds);
<?php } else { ?>
    document.getElementById("countdown").innerHTML = "Error: Invalid time remaining data.";
<?php } ?>
</script>
</body>
</html>