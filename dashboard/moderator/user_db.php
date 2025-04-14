<?php
// User Specter Database
include '/var/www/config/database.php';
$dbname = $_SESSION['editing_username'];

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

try {
    // Connect to MySQL database
    $db = new PDO("mysql:host=$db_servername;dbname=$dbname", $db_username, $db_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch all custom commands
    $getCommands = $db->query("SELECT * FROM custom_commands");
    $commands = $getCommands->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all built-in commands
    $getBuiltinCommands = $db->query("SELECT * FROM builtin_commands");
    $builtinCommands = $getBuiltinCommands->fetchAll(PDO::FETCH_ASSOC);

    // Fetch lurkers
    $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times");
    $lurkers = $getLurkers->fetchAll(PDO::FETCH_ASSOC);

    // Fetch watch time from the database
    $getWatchTime = $db->query("SELECT * FROM watch_time");
    $watchTimeData = $getWatchTime->fetchAll(PDO::FETCH_ASSOC);

    // Fetch typo counts
    $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
    $typos = $getTypos->fetchAll(PDO::FETCH_ASSOC);

    // Fetch total deaths
    $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
    $totalDeaths = $getTotalDeaths->fetch(PDO::FETCH_ASSOC);

    // Fetch game-specific deaths
    $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC");
    $gameDeaths = $getGameDeaths->fetchAll(PDO::FETCH_ASSOC);

    // Fetch total hug counts
    $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
    $totalHugs = $getTotalHugs->fetch(PDO::FETCH_ASSOC);

    // Fetch hug username-specific counts
    $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
    $hugCounts = $getHugCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetch total kiss counts
    $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
    $totalKisses = $getTotalKisses->fetch(PDO::FETCH_ASSOC);

    // Fetch kiss counts
    $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
    $kissCounts = $getKissCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetch highfive counts
    $getHighfiveCounts = $db->query("SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC");
    $highfiveCounts = $getHighfiveCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetch custom counts
    $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
    $customCounts = $getCustomCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetah Custom User Counts
    $getUserCounts = $db->query("SELECT command, user, count FROM user_counts");
    $userCounts = $getUserCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetch reward counts
    $getRewardCounts = $db->query("SELECT rc.reward_id, rc.user, rc.count, c.reward_title FROM reward_counts AS rc LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id ORDER BY rc.count DESC");
    $rewardCounts = $getRewardCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetch seen users data
    $getSeenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id");
    $seenUsersData = $getSeenUsersData->fetchAll(PDO::FETCH_ASSOC);

    // Fetch timed messages
    $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
    $timedMessagesData = $getTimedMessages->fetchAll(PDO::FETCH_ASSOC);

    // Fetch channel point rewards sorted by cost (low to high)
    $getChannelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
    $channelPointRewards = $getChannelPointRewards->fetchAll(PDO::FETCH_ASSOC);

    // Fetch todos
    $getTodos = $db->query("SELECT * FROM todos ORDER BY id DESC");
    $todos = $getTodos->fetchAll(PDO::FETCH_ASSOC);

    // Fetch todo categories
    $getTodoCategories = $db->query("SELECT * FROM categories");
    $todoCategories = $getTodoCategories->fetchAll(PDO::FETCH_ASSOC);

    // Fetch quotes data
    $getQuotes = $db->query("SELECT * FROM quotes ORDER BY id DESC");
    $quotesData = $getQuotes->fetchAll(PDO::FETCH_ASSOC);

    // Fetch profile data
    $getProfileSettings = $db->query("SELECT * FROM profile");
    $profileData = $getProfileSettings->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage();
}
?>