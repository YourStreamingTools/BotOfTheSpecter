<?php
require_once '/var/www/config/database.php';
$dbname = $_SESSION['username'] ?? '';

$commands = [];
$builtinCommands = [];
$typos = [];
$lurkers = [];
$watchTimeData = [];
$totalDeaths = [];
$gameDeaths = [];
$totalHugs = 0;
$hugCounts = [];
$totalKisses = 0;
$kissCounts = [];
$highfiveCounts = [];
$customCounts = [];
$userCounts = [];
$rewardCounts = [];
$seenUsersData = [];
$timedMessagesData = [];
$channelPointRewards = [];
$profileData = [];
$todos = [];
$todoCategories = [];
$quotesData = [];

// Only use $db for user dashboard queries
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

$commands = $db->query("SELECT * FROM custom_commands")->fetch_all(MYSQLI_ASSOC);
$builtinCommands = $db->query("SELECT * FROM builtin_commands")->fetch_all(MYSQLI_ASSOC);
$lurkers = $db->query("SELECT user_id, start_time FROM lurk_times")->fetch_all(MYSQLI_ASSOC);
$watchTimeData = $db->query("SELECT * FROM watch_time")->fetch_all(MYSQLI_ASSOC);
$typos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC")->fetch_all(MYSQLI_ASSOC);
$totalDeaths = $db->query("SELECT death_count FROM total_deaths")->fetch_assoc();
$gameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC")->fetch_all(MYSQLI_ASSOC);
$totalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts")->fetch_assoc();
$hugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC")->fetch_all(MYSQLI_ASSOC);
$totalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts")->fetch_assoc();
$kissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC")->fetch_all(MYSQLI_ASSOC);
$highfiveCounts = $db->query("SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC")->fetch_all(MYSQLI_ASSOC);
$customCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC")->fetch_all(MYSQLI_ASSOC);
$userCounts = $db->query("SELECT command, user, count FROM user_counts")->fetch_all(MYSQLI_ASSOC);
$rewardCounts = $db->query("SELECT rc.reward_id, rc.user, rc.count, c.reward_title FROM reward_counts AS rc LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id ORDER BY rc.count DESC")->fetch_all(MYSQLI_ASSOC);
$seenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$timedMessagesData = $db->query("SELECT * FROM timed_messages ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
$channelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC")->fetch_all(MYSQLI_ASSOC);
$todos = $db->query("SELECT * FROM todos ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
$todoCategories = $db->query("SELECT * FROM categories")->fetch_all(MYSQLI_ASSOC);
$quotesData = $db->query("SELECT * FROM quotes ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
$profileData = $db->query("SELECT * FROM profile")->fetch_all(MYSQLI_ASSOC);

?>
