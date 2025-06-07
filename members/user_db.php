<?php
// User Specter Database
include '/var/www/config/database.php';

// Initialize all variables as empty arrays or values
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

try {
    // Connect to MySQLi database
    $db = new mysqli($db_servername, $db_username, $db_password, $dbname);
    if ($db->connect_error) {
        throw new Exception("Connection failed: " . $db->connect_error);
    }

    // Fetch all custom commands
    $getCommands = $db->query("SELECT * FROM custom_commands");
    if ($getCommands) $commands = $getCommands->fetch_all(MYSQLI_ASSOC);

    // Fetch all built-in commands
    $getBuiltinCommands = $db->query("SELECT * FROM builtin_commands");
    if ($getBuiltinCommands) $builtinCommands = $getBuiltinCommands->fetch_all(MYSQLI_ASSOC);

    // Fetch lurkers
    $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times");
    if ($getLurkers) $lurkers = $getLurkers->fetch_all(MYSQLI_ASSOC);

    // Fetch watch time from the database
    $getWatchTime = $db->query("SELECT * FROM watch_time");
    if ($getWatchTime) $watchTimeData = $getWatchTime->fetch_all(MYSQLI_ASSOC);

    // Fetch typo counts
    $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
    if ($getTypos) $typos = $getTypos->fetch_all(MYSQLI_ASSOC);

    // Fetch total deaths
    $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
    if ($getTotalDeaths) $totalDeaths = $getTotalDeaths->fetch_assoc();

    // Fetch game-specific deaths
    $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC");
    if ($getGameDeaths) $gameDeaths = $getGameDeaths->fetch_all(MYSQLI_ASSOC);

    // Fetch total hug counts
    $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
    if ($getTotalHugs) $totalHugs = $getTotalHugs->fetch_assoc();

    // Fetch hug username-specific counts
    $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
    if ($getHugCounts) $hugCounts = $getHugCounts->fetch_all(MYSQLI_ASSOC);

    // Fetch total kiss counts
    $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
    if ($getTotalKisses) $totalKisses = $getTotalKisses->fetch_assoc();

    // Fetch kiss counts
    $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
    if ($getKissCounts) $kissCounts = $getKissCounts->fetch_all(MYSQLI_ASSOC);

    // Fetch highfive counts
    $getHighfiveCounts = $db->query("SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC");
    if ($getHighfiveCounts) $highfiveCounts = $getHighfiveCounts->fetch_all(MYSQLI_ASSOC);

    // Fetch custom counts
    $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
    if ($getCustomCounts) $customCounts = $getCustomCounts->fetch_all(MYSQLI_ASSOC);

    // Fetch Custom User Counts
    $getUserCounts = $db->query("SELECT command, user, count FROM user_counts");
    if ($getUserCounts) $userCounts = $getUserCounts->fetch_all(MYSQLI_ASSOC);

    // Fetch reward counts
    $getRewardCounts = $db->query("SELECT rc.reward_id, rc.user, rc.count, c.reward_title FROM reward_counts AS rc LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id ORDER BY rc.count DESC");
    if ($getRewardCounts) $rewardCounts = $getRewardCounts->fetch_all(MYSQLI_ASSOC);

    // Fetch seen users data
    $getSeenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id");
    if ($getSeenUsersData) $seenUsersData = $getSeenUsersData->fetch_all(MYSQLI_ASSOC);

    // Fetch timed messages
    $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
    if ($getTimedMessages) $timedMessagesData = $getTimedMessages->fetch_all(MYSQLI_ASSOC);

    // Fetch channel point rewards sorted by cost (low to high)
    $getChannelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
    if ($getChannelPointRewards) $channelPointRewards = $getChannelPointRewards->fetch_all(MYSQLI_ASSOC);

    // Fetch todos
    $getTodos = $db->query("SELECT * FROM todos ORDER BY id DESC");
    if ($getTodos) $todos = $getTodos->fetch_all(MYSQLI_ASSOC);

    // Fetch todo categories
    $getTodoCategories = $db->query("SELECT * FROM categories");
    if ($getTodoCategories) $todoCategories = $getTodoCategories->fetch_all(MYSQLI_ASSOC);

    // Fetch quotes data
    $getQuotes = $db->query("SELECT * FROM quotes ORDER BY id DESC");
    if ($getQuotes) $quotesData = $getQuotes->fetch_all(MYSQLI_ASSOC);

    // Fetch profile data
    $getProfileSettings = $db->query("SELECT * FROM profile");
    if ($getProfileSettings) $profileData = $getProfileSettings->fetch_all(MYSQLI_ASSOC);

    $db->close();

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>