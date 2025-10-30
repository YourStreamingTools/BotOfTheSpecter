<?php
$tz = new DateTimeZone("Australia/Sydney");
$launchDate = new DateTime("2023-10-17 11:54:58", $tz);
$now = new DateTime("now", $tz);
$interval = $launchDate->diff($now);
echo "Project has been running since 17th October 2023, 11:54:58 AEDT";
echo "<br>";
echo "As of now, ";
echo "it's been {$interval->y} year" . ($interval->y != 1 ? "s" : "") . ", ";
echo "{$interval->m} month" . ($interval->m != 1 ? "s" : "") . ", ";
echo "{$interval->d} day" . ($interval->d != 1 ? "s" : "") . ", ";
echo "{$interval->h} hour" . ($interval->h != 1 ? "s" : "") . ", ";
echo "{$interval->i} minute" . ($interval->i != 1 ? "s" : "") . " since launch.<br>";
?>