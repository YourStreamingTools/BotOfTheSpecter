<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
$today = new DateTime(); // Initialize today's date

// Include database connection
require_once "/var/www/config/db_connect.php";

// Get reset days from database
$resetDaysStmt = $conn->prepare("SELECT type, reset_day FROM api_counts");
$resetDaysStmt->execute();
$resetDaysResult = $resetDaysStmt->get_result();
$resetDays = [];
while ($row = $resetDaysResult->fetch_assoc()) {
    $resetDays[$row['type']] = $row['reset_day'];
}
$resetDaysStmt->close();

// Shazam Section
$shazam_reset_day = isset($resetDays['shazam']) ? $resetDays['shazam'] : 23; // Default to 23 if not found
if ($today->format('d') >= $shazam_reset_day) {
    $shazam_next_reset = new DateTime('first day of next month');
    $shazam_next_reset->setDate($shazam_next_reset->format('Y'), $shazam_next_reset->format('m'), $shazam_reset_day);
} else {
    $shazam_next_reset = new DateTime($today->format('Y-m') . "-$shazam_reset_day");
}
$days_until_reset_shazam = $today->diff($shazam_next_reset)->days;
$reset_date_shazam = $shazam_next_reset->format('F j, Y');

// Get Shazam count from database
$shazamStmt = $conn->prepare("SELECT count, updated FROM api_counts WHERE type = 'shazam'");
$shazamStmt->execute();
$shazamResult = $shazamStmt->get_result();
$shazamData = $shazamResult->num_rows > 0 ? $shazamResult->fetch_assoc() : ["count" => "N/A", "updated" => null];
$shazam_requests_remaining = $shazamData['count'];
$shazam_last_updated = $shazamData['updated'];
$last_modified_shazam = $shazam_last_updated ? date("F j, Y, g:i A T", strtotime($shazam_last_updated)) : "N/A";
$shazamStmt->close();

// Exchange Rate Section
$exchangerate_reset_day = isset($resetDays['exchangerate']) ? $resetDays['exchangerate'] : 14; // Default to 14 if not found
if ($today->format('d') >= $exchangerate_reset_day) {
    $exchangerate_next_reset = new DateTime('first day of next month');
    $exchangerate_next_reset->setDate($exchangerate_next_reset->format('Y'), $exchangerate_next_reset->format('m'), $exchangerate_reset_day);
} else {
    $exchangerate_next_reset = new DateTime($today->format('Y-m') . "-$exchangerate_reset_day");
}
$days_until_reset_exchangerate = $today->diff($exchangerate_next_reset)->days;
$reset_date_exchangerate = $exchangerate_next_reset->format('F j, Y');

// Get Exchange Rate count from database
$exchangerateStmt = $conn->prepare("SELECT count, updated FROM api_counts WHERE type = 'exchangerate'");
$exchangerateStmt->execute();
$exchangerateResult = $exchangerateStmt->get_result();
$exchangerateData = $exchangerateResult->num_rows > 0 ? $exchangerateResult->fetch_assoc() : ["count" => "N/A", "updated" => null];
$exchangerate_requests_remaining = $exchangerateData['count'];
$exchangerate_last_updated = $exchangerateData['updated'];
$last_modified_exchangerate = $exchangerate_last_updated ? date("F j, Y, g:i A T", strtotime($exchangerate_last_updated)) : "N/A";
$exchangerateStmt->close();

// Weather Section
$midnight = new DateTime('tomorrow midnight');
$time_until_midnight = $today->diff($midnight);
$hours_until_midnight = $time_until_midnight->h;
$minutes_until_midnight = $time_until_midnight->i;

// Get Weather count from database
$weatherStmt = $conn->prepare("SELECT count, updated FROM api_counts WHERE type = 'weather'");
$weatherStmt->execute();
$weatherResult = $weatherStmt->get_result();
$weatherData = $weatherResult->num_rows > 0 ? $weatherResult->fetch_assoc() : ["count" => "N/A", "updated" => null];
$weather_requests_remaining = $weatherData['count'];
$weather_last_updated = $weatherData['updated'];
$last_modified_weather = $weather_last_updated ? date("F j, Y, g:i A T", strtotime($weather_last_updated)) : "N/A";
$weatherStmt->close();

// Close the database connection
$conn->close();

// Return the data as JSON
echo json_encode([
    "shazam" => [
        "requests_remaining" => $shazam_requests_remaining,
        "days_until_reset" => $days_until_reset_shazam,
        "reset_date" => $reset_date_shazam,
        "last_modified" => $last_modified_shazam,
        "last_updated" => $shazam_last_updated
    ],
    "exchangerate" => [
        "requests_remaining" => $exchangerate_requests_remaining,
        "days_until_reset" => $days_until_reset_exchangerate,
        "reset_date" => $reset_date_exchangerate,
        "last_modified" => $last_modified_exchangerate,
        "last_updated" => $exchangerate_last_updated
    ],
    "weather" => [
        "requests_remaining" => $weather_requests_remaining,
        "hours_until_midnight" => $hours_until_midnight,
        "minutes_until_midnight" => $minutes_until_midnight,
        "last_modified" => $last_modified_weather,
        "last_updated" => $weather_last_updated
    ]
]);
?>
