<?php
$tz = new DateTimeZone("Australia/Sydney");
$launchDate = new DateTime("2023-10-17 11:54:58", $tz);
$now = new DateTime("now", $tz);
$interval = $launchDate->diff($now);
echo "Project has been running since 17th October 2023, 11:54:58 AEDT";
echo "<br>";
echo "As of now, ";
// Build a list of non-zero time parts to avoid showing "0 months" or "0 hours"
$parts = array();
if ($interval->y) {
	$parts[] = $interval->y . ' year' . ($interval->y != 1 ? 's' : '');
}
if ($interval->m) {
	$parts[] = $interval->m . ' month' . ($interval->m != 1 ? 's' : '');
}
if ($interval->d) {
	$parts[] = $interval->d . ' day' . ($interval->d != 1 ? 's' : '');
}
if ($interval->h) {
	$parts[] = $interval->h . ' hour' . ($interval->h != 1 ? 's' : '');
}
if ($interval->i) {
	$parts[] = $interval->i . ' minute' . ($interval->i != 1 ? 's' : '');
}
// Only show the "As of now..." message if there's at least one non-zero part.
if (!empty($parts)) {
    echo "it's been " . implode(', ', $parts) . " since launch.<br>";
}
?>