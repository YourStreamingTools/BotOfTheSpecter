<?php
header('Content-Type: application/json');
$today = new DateTime(); // Initialize today's date

// Shazam Section
$shazamFile = "/var/www/api/shazam.txt";
$shazam_reset_day = 23;
if ($today->format('d') >= $shazam_reset_day) {
    $shazam_next_reset = new DateTime('first day of next month');
    $shazam_next_reset->setDate($shazam_next_reset->format('Y'), $shazam_next_reset->format('m'), $shazam_reset_day);
} else {
    $shazam_next_reset = new DateTime($today->format('Y-m') . "-$shazam_reset_day");
}
$days_until_reset_shazam = $today->diff($shazam_next_reset)->days;
$reset_date_shazam = $shazam_next_reset->format('F j, Y');
$shazam_requests_remaining = file_exists($shazamFile) ? file_get_contents($shazamFile) : "N/A";
$last_modified_shazam = file_exists($shazamFile) ? date("F j, Y, g:i A T", filemtime($shazamFile)) : "N/A";

// Exchange Rate Section
$exchangerateFile = "/var/www/api/exchangerate.txt";
$exchangerate_reset_day = 14;
if ($today->format('d') >= $exchangerate_reset_day) {
    $exchangerate_next_reset = new DateTime('first day of next month');
    $exchangerate_next_reset->setDate($exchangerate_next_reset->format('Y'), $exchangerate_next_reset->format('m'), $exchangerate_reset_day);
} else {
    $exchangerate_next_reset = new DateTime($today->format('Y-m') . "-$exchangerate_reset_day");
}
$days_until_reset_exchangerate = $today->diff($exchangerate_next_reset)->days;
$reset_date_exchangerate = $exchangerate_next_reset->format('F j, Y');
$exchangerate_requests_remaining = file_exists($exchangerateFile) ? file_get_contents($exchangerateFile) : "N/A";
$last_modified_exchangerate = file_exists($exchangerateFile) ? date("F j, Y, g:i A T", filemtime($exchangerateFile)) : "N/A";

// Weather Section
$weatherFile = "/var/www/api/weather.txt";
$midnight = new DateTime('tomorrow midnight');
$time_until_midnight = $today->diff($midnight);
$hours_until_midnight = $time_until_midnight->h;
$minutes_until_midnight = $time_until_midnight->i;
$seconds_until_midnight = $time_until_midnight->s;
$weather_requests_remaining = file_exists($weatherFile) ? file_get_contents($weatherFile) : "N/A";
$last_modified_weather = file_exists($weatherFile) ? date("F j, Y, g:i A T", filemtime($weatherFile)) : "N/A";

// Return the data as JSON
echo json_encode([
    "shazam" => [
        "requests_remaining" => $shazam_requests_remaining,
        "days_until_reset" => $days_until_reset_shazam,
        "reset_date" => $reset_date_shazam,
        "last_modified" => $last_modified_shazam
    ],
    "exchangerate" => [
        "requests_remaining" => $exchangerate_requests_remaining,
        "days_until_reset" => $days_until_reset_exchangerate,
        "reset_date" => $reset_date_exchangerate,
        "last_modified" => $last_modified_exchangerate
    ],
    "weather" => [
        "requests_remaining" => $weather_requests_remaining,
        "hours_until_midnight" => $hours_until_midnight,
        "minutes_until_midnight" => $minutes_until_midnight,
        "seconds_until_midnight" => $seconds_until_midnight,
        "last_modified" => $last_modified_weather
    ]
]);
?>
