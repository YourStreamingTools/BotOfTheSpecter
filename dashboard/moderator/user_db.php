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
    // Connect to MySQLi database
    $db = new mysqli($db_servername, $db_username, $db_password, $dbname);
    if ($db->connect_error) {
        throw new Exception('Connect Error (' . $db->connect_errno . ') ' . $db->connect_error);
    }

    // Fetch all custom commands
    $getCommands = $db->query("SELECT * FROM custom_commands");
    $commands = [];
    while ($row = $getCommands->fetch_assoc()) {
        $commands[] = $row;
    }

    // Fetch all built-in commands
    $getBuiltinCommands = $db->query("SELECT * FROM builtin_commands");
    $builtinCommands = [];
    while ($row = $getBuiltinCommands->fetch_assoc()) {
        $builtinCommands[] = $row;
    }

    // Fetch lurkers
    $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times");
    $lurkers = [];
    while ($row = $getLurkers->fetch_assoc()) {
        $lurkers[] = $row;
    }

    // Fetch watch time from the database
    $getWatchTime = $db->query("SELECT * FROM watch_time");
    $watchTimeData = [];
    while ($row = $getWatchTime->fetch_assoc()) {
        $watchTimeData[] = $row;
    }

    // Fetch typo counts
    $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
    $typos = [];
    while ($row = $getTypos->fetch_assoc()) {
        $typos[] = $row;
    }

    // Fetch total deaths
    $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
    $totalDeaths = $getTotalDeaths->fetch_assoc();

    // Fetch game-specific deaths
    $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC");
    $gameDeaths = [];
    while ($row = $getGameDeaths->fetch_assoc()) {
        $gameDeaths[] = $row;
    }

    // Fetch total hug counts
    $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
    $totalHugs = $getTotalHugs->fetch_assoc();

    // Fetch hug username-specific counts
    $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
    $hugCounts = [];
    while ($row = $getHugCounts->fetch_assoc()) {
        $hugCounts[] = $row;
    }

    // Fetch total kiss counts
    $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
    $totalKisses = $getTotalKisses->fetch_assoc();

    // Fetch kiss counts
    $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
    $kissCounts = [];
    while ($row = $getKissCounts->fetch_assoc()) {
        $kissCounts[] = $row;
    }

    // Fetch highfive counts
    $getHighfiveCounts = $db->query("SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC");
    $highfiveCounts = [];
    while ($row = $getHighfiveCounts->fetch_assoc()) {
        $highfiveCounts[] = $row;
    }

    // Fetch custom counts
    $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
    $customCounts = [];
    while ($row = $getCustomCounts->fetch_assoc()) {
        $customCounts[] = $row;
    }

    // Fetah Custom User Counts
    $getUserCounts = $db->query("SELECT command, user, count FROM user_counts");
    $userCounts = [];
    while ($row = $getUserCounts->fetch_assoc()) {
        $userCounts[] = $row;
    }

    // Fetch reward counts
    $getRewardCounts = $db->query("SELECT rc.reward_id, rc.user, rc.count, c.reward_title FROM reward_counts AS rc LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id ORDER BY rc.count DESC");
    $rewardCounts = [];
    while ($row = $getRewardCounts->fetch_assoc()) {
        $rewardCounts[] = $row;
    }

    // Fetch seen users data
    $getSeenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id");
    $seenUsersData = [];
    while ($row = $getSeenUsersData->fetch_assoc()) {
        $seenUsersData[] = $row;
    }

    // Fetch timed messages
    $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
    $timedMessagesData = [];
    while ($row = $getTimedMessages->fetch_assoc()) {
        $timedMessagesData[] = $row;
    }

    // Fetch channel point rewards sorted by cost (low to high)
    $getChannelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
    $channelPointRewards = [];
    while ($row = $getChannelPointRewards->fetch_assoc()) {
        $channelPointRewards[] = $row;
    }

    // Fetch todos
    $getTodos = $db->query("SELECT * FROM todos ORDER BY id DESC");
    $todos = [];
    while ($row = $getTodos->fetch_assoc()) {
        $todos[] = $row;
    }

    // Fetch todo categories
    $getTodoCategories = $db->query("SELECT * FROM categories");
    $todoCategories = [];
    while ($row = $getTodoCategories->fetch_assoc()) {
        $todoCategories[] = $row;
    }

    // Fetch quotes data
    $getQuotes = $db->query("SELECT * FROM quotes ORDER BY id DESC");
    $quotesData = [];
    while ($row = $getQuotes->fetch_assoc()) {
        $quotesData[] = $row;
    }

    // Fetch profile data
    $getProfileSettings = $db->query("SELECT * FROM profile");
    $profileData = [];
    while ($row = $getProfileSettings->fetch_assoc()) {
        $profileData[] = $row;
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>