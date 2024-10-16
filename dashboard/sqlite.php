<?php
// User Specter Database
$mysqlhost = "sql.botofthespecter.com";
$mysqlusername = ""; // CHANGE TO MAKE THIS WORK
$mysqlpassword = ""; // CHANGE TO MAKE THIS WORK
$dbname = $username;

// Initialize all variables as empty arrays or values
$commands = [];
$builtinCommands = [];
$typos = [];
$lurkers = [];
$totalDeaths = [];
$gameDeaths = [];
$totalHugs = 0;
$hugCounts = [];
$totalKisses = 0;
$kissCounts = [];
$customCounts = [];
$seenUsersData = [];
$timedMessagesData = [];
$channelPointRewards = [];
$profileData = [];

try {
    // Create connection
    $usrDBconn = new mysqli($mysqlhost, $mysqlusername, $mysqlpassword);
    // Check connection
    if ($usrDBconn->connect_error) {
        die();
    }
    // Create the database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS `$dbname`";
    if ($usrDBconn->query($sql) === TRUE) {
    } else {
        die();
    }
    // Select the database
    if (!$usrDBconn->select_db($dbname)) {
        die();
    }
    // List of table creation statements
    $tables = [
        'everyone' => "
            CREATE TABLE IF NOT EXISTS everyone (
                username VARCHAR(255),
                group_name VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (username)
            ) ENGINE=InnoDB",
        'groups' => "
            CREATE TABLE IF NOT EXISTS `groups` (
                id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(255),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
        'custom_commands' => "
            CREATE TABLE IF NOT EXISTS custom_commands (
                command VARCHAR(255),
                response TEXT,
                status VARCHAR(255),
                PRIMARY KEY (command)
            ) ENGINE=InnoDB",
        'builtin_commands' => "
            CREATE TABLE IF NOT EXISTS builtin_commands (
                command VARCHAR(255),
                status VARCHAR(255),
                PRIMARY KEY (command)
            ) ENGINE=InnoDB",
        'user_typos' => "
            CREATE TABLE IF NOT EXISTS user_typos (
                username VARCHAR(255),
                typo_count INT DEFAULT 0,
                PRIMARY KEY (username)
            ) ENGINE=InnoDB",
        'lurk_times' => "
            CREATE TABLE IF NOT EXISTS lurk_times (
                user_id VARCHAR(255),
                start_time VARCHAR(255) NOT NULL,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB",
        'hug_counts' => "
            CREATE TABLE IF NOT EXISTS hug_counts (
                username VARCHAR(255),
                hug_count INT DEFAULT 0,
                PRIMARY KEY (username)
            ) ENGINE=InnoDB",
        'kiss_counts' => "
            CREATE TABLE IF NOT EXISTS kiss_counts (
                username VARCHAR(255),
                kiss_count INT DEFAULT 0,
                PRIMARY KEY (username)
            ) ENGINE=InnoDB",
        'total_deaths' => "
            CREATE TABLE IF NOT EXISTS total_deaths (
                death_count INT DEFAULT 0
            ) ENGINE=InnoDB",
        'game_deaths' => "
            CREATE TABLE IF NOT EXISTS game_deaths (
                game_name VARCHAR(255),
                death_count INT DEFAULT 0,
                PRIMARY KEY (game_name)
            ) ENGINE=InnoDB",
        'custom_counts' => "
            CREATE TABLE IF NOT EXISTS custom_counts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                command VARCHAR(255) NOT NULL,
                count INT NOT NULL
            ) ENGINE=InnoDB",
        'bits_data' => "
            CREATE TABLE IF NOT EXISTS bits_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id VARCHAR(255),
                user_name VARCHAR(255),
                bits INT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
        'subscription_data' => "
            CREATE TABLE IF NOT EXISTS subscription_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id VARCHAR(255),
                user_name VARCHAR(255),
                sub_plan VARCHAR(255),
                months INT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
        'followers_data' => "
            CREATE TABLE IF NOT EXISTS followers_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id VARCHAR(255),
                user_name VARCHAR(255),
                followed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
        'raid_data' => "
            CREATE TABLE IF NOT EXISTS raid_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                raider_name VARCHAR(255),
                raider_id VARCHAR(255),
                viewers INT,
                raid_count INT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
        'quotes' => "
            CREATE TABLE IF NOT EXISTS quotes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                quote TEXT
            ) ENGINE=InnoDB",
        'seen_users' => "
            CREATE TABLE IF NOT EXISTS seen_users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255),
                welcome_message VARCHAR(255) DEFAULT NULL,
                status VARCHAR(255)
            ) ENGINE=InnoDB",
        'seen_today' => "
            CREATE TABLE IF NOT EXISTS seen_today (
                user_id VARCHAR(255),
                username VARCHAR(255),
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB",
        'timed_messages' => "
            CREATE TABLE IF NOT EXISTS timed_messages (
                id INT PRIMARY KEY AUTO_INCREMENT,
                interval_count INT,
                message TEXT
            ) ENGINE=InnoDB",
        'profile' => "
            CREATE TABLE IF NOT EXISTS profile (
                id INT PRIMARY KEY AUTO_INCREMENT,
                timezone VARCHAR(255) DEFAULT NULL,
                weather_location VARCHAR(255) DEFAULT NULL,
                discord_alert VARCHAR(255) DEFAULT NULL,
                discord_mod VARCHAR(255) DEFAULT NULL,
                discord_alert_online VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB",
        'protection' => "
            CREATE TABLE IF NOT EXISTS protection (
                url_blocking VARCHAR(255),
                profanity VARCHAR(255)
            ) ENGINE=InnoDB",
        'link_whitelist' => "
            CREATE TABLE IF NOT EXISTS link_whitelist (
                link VARCHAR(255),
                PRIMARY KEY (link)
            ) ENGINE=InnoDB",
        'link_blacklisting' => "
            CREATE TABLE IF NOT EXISTS link_blacklisting (
                link VARCHAR(255),
                PRIMARY KEY (link)
            ) ENGINE=InnoDB",
        'stream_credits' => "
            CREATE TABLE IF NOT EXISTS stream_credits (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255),
                event VARCHAR(255),
                data VARCHAR(255)
            ) ENGINE=InnoDB",
        'message_counts' => "
            CREATE TABLE IF NOT EXISTS message_counts (
                username VARCHAR(255),
                message_count INT NOT NULL,
                user_level VARCHAR(255) NOT NULL,
                PRIMARY KEY (username)
            ) ENGINE=InnoDB",
        'bot_points' => "
            CREATE TABLE IF NOT EXISTS bot_points (
                user_id VARCHAR(50),
                user_name VARCHAR(50),
                points INT DEFAULT 0,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB",
        'bot_settings' => "
            CREATE TABLE IF NOT EXISTS bot_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                point_name TEXT,
                point_amount_chat VARCHAR(50),
                point_amount_follower VARCHAR(50),
                point_amount_subscriber VARCHAR(50),
                point_amount_cheer VARCHAR(50),
                point_amount_raid VARCHAR(50),
                subscriber_multiplier VARCHAR(50),
                excluded_users TEXT
            ) ENGINE=InnoDB",
        'channel_point_rewards' => "
            CREATE TABLE IF NOT EXISTS channel_point_rewards (
                reward_id VARCHAR(255),
                reward_title VARCHAR(255),
                reward_cost VARCHAR(255),
                custom_message TEXT,
                PRIMARY KEY (reward_id)
            ) ENGINE=InnoDB",
        'active_timers' => "
            CREATE TABLE IF NOT EXISTS active_timers (
                user_id BIGINT NOT NULL,
                end_time DATETIME NOT NULL,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB",
        'poll_results' => "
            CREATE TABLE IF NOT EXISTS poll_results (
                poll_id VARCHAR(255),
                poll_name VARCHAR(255),
                poll_option_one VARCHAR(255),
                poll_option_two VARCHAR(255),
                poll_option_three VARCHAR(255),
                poll_option_four VARCHAR(255),
                poll_option_five VARCHAR(255),
                poll_option_one_results INT,
                poll_option_two_results INT,
                poll_option_three_results INT,
                poll_option_four_results INT,
                poll_option_five_results INT,
                bits_used INT,
                channel_points_used INT,
                started_at DATETIME,
                ended_at DATETIME
            ) ENGINE=InnoDB",
        'tipping_settings' => "
            CREATE TABLE IF NOT EXISTS tipping_settings (
                StreamElements TEXT DEFAULT NULL,
                StreamLabs TEXT DEFAULT NULL
            ) ENGINE=InnoDB",
        'tipping' => "
            CREATE TABLE IF NOT EXISTS tipping (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255),
                amount DECIMAL(10, 2),
                message TEXT,
                source VARCHAR(255),
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
        'categories' => "
            CREATE TABLE IF NOT EXISTS categories (
                id INT(11) NOT NULL AUTO_INCREMENT,
                category VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'quote_category' => "
            CREATE TABLE IF NOT EXISTS quote_category (
                id INT(11) NOT NULL AUTO_INCREMENT,
                quote_id INT(11),
                category_id INT(11),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    // Execute each table creation query
    foreach ($tables as $table_name => $sql) {
        if ($usrDBconn->query($sql) === TRUE) {} else { echo "<script>console.error('Error creating table \'$table_name\': " . $usrDBconn->error . "');</script>"; }}
    // Close the connection
    $usrDBconn->close();
} catch (Exception $e) {
    echo "<script>console.error('Exception caught: " . $e->getMessage() . "');</script>";
}

try {
    // Connect to MySQL database
    $db = new PDO("mysql:host=$mysqlhost;dbname=$dbname", $mysqlusername, $mysqlpassword);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch all custom commands
    $getCommands = $db->query("SELECT * FROM custom_commands");
    $commands = $getCommands->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all built-in commands
    $getBuiltinCommands = $db->query("SELECT * FROM builtin_commands");
    $builtinCommands = $getBuiltinCommands->fetchAll(PDO::FETCH_ASSOC);

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

    // Fetch custom counts
    $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
    $customCounts = $getCustomCounts->fetchAll(PDO::FETCH_ASSOC);

    // Fetch seen users data
    $getSeenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id");
    $seenUsersData = $getSeenUsersData->fetchAll(PDO::FETCH_ASSOC);

    // Fetch timed messages
    $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
    $timedMessagesData = $getTimedMessages->fetchAll(PDO::FETCH_ASSOC);

    // Fetch channel point rewards sorted by cost (low to high)
    $getChannelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
    $channelPointRewards = $getChannelPointRewards->fetchAll(PDO::FETCH_ASSOC);

    // Fetch profile data
    $getProfileSettings = $db->query("SELECT * FROM profile");
    $profileData = $getProfileSettings->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage();
}
?>