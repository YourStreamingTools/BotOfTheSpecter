<?php
// Database
include '/var/www/config/database.php';
$dbname = $_SESSION['username'];

try {
    // Create connection
    $usrDBconn = new mysqli($db_servername, $db_username, $db_password);
    // Check connection
    if ($usrDBconn->connect_error) {
        die("Connection failed: " . $usrDBconn->connect_error);
    }
    // Check if the database exists, if not, create it
    $sql = "CREATE DATABASE IF NOT EXISTS `$dbname`";
    if ($usrDBconn->query($sql) === TRUE) {
        echo "<script>console.log('Database $dbname created or already exists.');</script>
        ";
    } else {
        die("Error creating database: " . $usrDBconn->error);
    }
    // Close the connection after creating the database
    $usrDBconn->close();
    // Reconnect to the server specifying the database
    $usrDBconn = new mysqli($db_servername, $db_username, $db_password, $dbname);
    // Check connection again
    if ($usrDBconn->connect_error) {
        die("Reconnection failed: " . $usrDBconn->connect_error);
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
                command TEXT,
                response TEXT,
                status TEXT,
                cooldown INT DEFAULT '15',
                PRIMARY KEY (command(255))
            ) ENGINE=InnoDB",
        'builtin_commands' => "
            CREATE TABLE IF NOT EXISTS builtin_commands (
                command VARCHAR(255),
                status VARCHAR(255),
                permission VARCHAR(255),
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
        'highfive_counts' => "
            CREATE TABLE IF NOT EXISTS highfive_counts (
                username VARCHAR(255),
                highfive_count INT DEFAULT 0,
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
        'per_stream_deaths' => "
            CREATE TABLE IF NOT EXISTS per_stream_deaths (
                game_name VARCHAR(255),
                death_count INT DEFAULT 0,
                PRIMARY KEY (game_name)
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
        'user_counts' => "
            CREATE TABLE IF NOT EXISTS user_counts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                command VARCHAR(255) NOT NULL,
                user VARCHAR(255) NOT NULL,
                count INT DEFAULT 0,
                UNIQUE (command, user)
            ) ENGINE=InnoDB",
        'reward_counts' => "
            CREATE TABLE IF NOT EXISTS reward_counts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                reward_id VARCHAR(255) NOT NULL,
                user VARCHAR(255) NOT NULL,
                count INT DEFAULT 0,
                UNIQUE (reward_id, user)
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
                chat_line_trigger INT DEFAULT 5,
                message TEXT,
                status VARCHAR(10) DEFAULT True
            ) ENGINE=InnoDB",
        'profile' => "
            CREATE TABLE IF NOT EXISTS profile (
                id INT PRIMARY KEY AUTO_INCREMENT,
                timezone VARCHAR(255) DEFAULT NULL,
                weather_location VARCHAR(255) DEFAULT NULL,
                discord_alert VARCHAR(255) DEFAULT NULL,
                discord_mod VARCHAR(255) DEFAULT NULL,
                discord_alert_online VARCHAR(255) DEFAULT NULL,
                heartrate_code VARCHAR(8) DEFAULT NULL
            ) ENGINE=InnoDB",
        'protection' => "
            CREATE TABLE IF NOT EXISTS protection (
                url_blocking VARCHAR(255)
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
        'showobs' => "
            CREATE TABLE IF NOT EXISTS showobs (
                id INT(11) NOT NULL AUTO_INCREMENT,
                font VARCHAR(50) NOT NULL DEFAULT 'Arial',
                color VARCHAR(50) NOT NULL DEFAULT 'Black',
                list VARCHAR(10) NOT NULL DEFAULT 'Bullet',
                shadow TINYINT(1) NOT NULL DEFAULT 0,
                bold TINYINT(1) NOT NULL DEFAULT 0,
                font_size INT(11) NOT NULL DEFAULT 22,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        'todos' => "
            CREATE TABLE IF NOT EXISTS todos (
                id INT(255) NOT NULL AUTO_INCREMENT,
                objective VARCHAR(255) NOT NULL,
                category VARCHAR(255) DEFAULT NULL,
                completed VARCHAR(3) NOT NULL DEFAULT 'No',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        'quote_category' => "
            CREATE TABLE IF NOT EXISTS quote_category (
                id INT(11) NOT NULL AUTO_INCREMENT,
                quote_id INT(11),
                category_id INT(11),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'sound_alerts' => "
            CREATE TABLE IF NOT EXISTS sound_alerts (
                reward_id VARCHAR(255) NOT NULL,
                sound_mapping TEXT,
                PRIMARY KEY (reward_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'twitch_sound_alerts' => "
            CREATE TABLE IF NOT EXISTS twitch_sound_alerts (
                twitch_alert_id VARCHAR(255) NOT NULL,
                sound_mapping TEXT,
                PRIMARY KEY (twitch_alert_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'video_alerts' => "
            CREATE TABLE IF NOT EXISTS video_alerts (
                reward_id VARCHAR(255) NOT NULL,
                video_mapping TEXT,
                PRIMARY KEY (reward_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'joke_settings' => "
            CREATE TABLE IF NOT EXISTS joke_settings (
                id INT(11) NOT NULL AUTO_INCREMENT,
                blacklist TEXT,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'watch_time' => "
            CREATE TABLE IF NOT EXISTS watch_time (
                user_id VARCHAR(255) NOT NULL,
                username VARCHAR(255) NOT NULL,
                total_watch_time_live INT DEFAULT 0,
                total_watch_time_offline INT DEFAULT 0,
                last_active VARCHAR(255),
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'watch_time_excluded_users' => "
            CREATE TABLE IF NOT EXISTS watch_time_excluded_users (
                excluded_users VARCHAR(255) DEFAULT NULL,
                UNIQUE (excluded_users)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'subathon_settings' => "
            CREATE TABLE IF NOT EXISTS subathon_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                starting_minutes INT DEFAULT NULL,
                cheer_add INT DEFAULT NULL,
                sub_add_1 INT DEFAULT NULL,
                sub_add_2 INT DEFAULT NULL,
                sub_add_3 INT DEFAULT NULL,
                donation_add INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'tts_settings' => "
            CREATE TABLE IF NOT EXISTS tts_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                voice VARCHAR(50),
                language VARCHAR(10)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'streamer_preferences' => "
            CREATE TABLE IF NOT EXISTS streamer_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                send_welcome_messages TINYINT(1),
                default_welcome_message TEXT,
                new_default_welcome_message TEXT,
                default_vip_welcome_message TEXT,
                new_default_vip_welcome_message TEXT,
                default_mod_welcome_message TEXT,
                new_default_mod_welcome_message TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'stream_lotto' => "
            CREATE TABLE IF NOT EXISTS stream_lotto (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255),
                winning_numbers VARCHAR(255),
                supplementary_numbers VARCHAR(255),
                UNIQUE (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'stream_lotto_winning_numbers' => "
            CREATE TABLE IF NOT EXISTS stream_lotto_winning_numbers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                winning_numbers VARCHAR(255),
                supplementary_numbers VARCHAR(255),
                UNIQUE (winning_numbers)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'ad_notice_settings' => "
            CREATE TABLE IF NOT EXISTS ad_notice_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ad_start_message VARCHAR(255),
                ad_end_message VARCHAR(255),
                ad_upcoming_message VARCHAR(255),
                enable_ad_notice TINYINT(1) DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'streaming_settings' => "
            CREATE TABLE IF NOT EXISTS streaming_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                twitch_key VARCHAR(255),
                forward_to_twitch TINYINT(1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'twitch_chat_alerts' => "
            CREATE TABLE IF NOT EXISTS twitch_chat_alerts (
                alert_type VARCHAR(255) PRIMARY KEY,
                alert_message TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'reward_streaks' => "
            CREATE TABLE IF NOT EXISTS reward_streaks (
                reward_id VARCHAR(255) PRIMARY KEY,
                current_user VARCHAR(255),
                streak INT DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    // List of columns to check for each table (table_name => columns)
    $columns = [
        'everyone' => ['group_name' => "VARCHAR(255) DEFAULT NULL"],
        'groups' => ['name' => "VARCHAR(255)"],
        'custom_commands' => ['response' => "TEXT",'status' => "TEXT",'cooldown' => "INT DEFAULT '15'"],
        'builtin_commands' => ['status' => "VARCHAR(255)",'permission' => "VARCHAR(255)"],
        'user_typos' => ['typo_count' => "INT DEFAULT 0"],
        'lurk_times' => ['start_time' => "VARCHAR(255) NOT NULL"],
        'hug_counts' => ['hug_count' => "INT DEFAULT 0"],
        'kiss_counts' => ['kiss_count' => "INT DEFAULT 0"],
        'total_deaths' => ['death_count' => "INT DEFAULT 0"],
        'game_deaths' => ['death_count' => "INT DEFAULT 0"],
        'custom_counts' => ['command' => "VARCHAR(255) NOT NULL",'count' => "INT NOT NULL"],
        'bits_data' => ['user_id' => "VARCHAR(255)",'user_name' => "VARCHAR(255)",'bits' => "INT"],
        'subscription_data' => ['user_id' => "VARCHAR(255)",'user_name' => "VARCHAR(255)",'sub_plan' => "VARCHAR(255)",'months' => "INT"],
        'followers_data' => ['user_id' => "VARCHAR(255)",'user_name' => "VARCHAR(255)"],
        'raid_data' => ['raider_name' => "VARCHAR(255)",'raider_id' => "VARCHAR(255)",'viewers' => "INT",'raid_count' => "INT"],
        'quotes' => ['quote' => "TEXT"],
        'seen_users' => ['username' => "VARCHAR(255)",'welcome_message' => "VARCHAR(255) DEFAULT NULL",'status' => "VARCHAR(255)"],
        'seen_today' => ['user_id' => "VARCHAR(255)",'username' => "VARCHAR(255)"],
        'timed_messages' => ['interval_count' => "INT",'chat_line_trigger' => 'INT DEFAULT 5','message' => "TEXT",'status' => "VARCHAR(10) DEFAULT True"],
        'profile' => ['timezone' => "VARCHAR(255) DEFAULT NULL",'weather_location' => "VARCHAR(255) DEFAULT NULL",'discord_alert' => "VARCHAR(255) DEFAULT NULL",'discord_mod' => "VARCHAR(255) DEFAULT NULL",'discord_alert_online' => "VARCHAR(255) DEFAULT NULL",'heartrate_code' => 'VARCHAR(8) DEFAULT NULL'],
        'protection' => ['url_blocking' => "VARCHAR(255)"],
        'link_whitelist' => ['link' => "VARCHAR(255)"],
        'link_blacklisting' => ['link' => "VARCHAR(255)"],
        'stream_credits' => ['username' => "VARCHAR(255)",'event' => "VARCHAR(255)",'data' => "VARCHAR(255)"],
        'message_counts' => ['username' => "VARCHAR(255)",'message_count' => "INT NOT NULL",'user_level' => "VARCHAR(255) NOT NULL"],
        'bot_points' => ['user_id' => "VARCHAR(50)",'user_name' => "VARCHAR(50)",'points' => "INT DEFAULT 0"],
        'bot_settings' => ['point_name' => "TEXT",'point_amount_chat' => "VARCHAR(50)",'point_amount_follower' => "VARCHAR(50)",'point_amount_subscriber' => "VARCHAR(50)",'point_amount_cheer' => "VARCHAR(50)",'point_amount_raid' => "VARCHAR(50)",'subscriber_multiplier' => "VARCHAR(50)",'excluded_users' => "TEXT"],
        'channel_point_rewards' => ['reward_id' => "VARCHAR(255)",'reward_title' => "VARCHAR(255)",'reward_cost' => "VARCHAR(255)",'custom_message' => "TEXT"],
        'active_timers' => ['user_id' => "BIGINT NOT NULL",'end_time' => "DATETIME NOT NULL"],
        'poll_results' => ['poll_id' => "VARCHAR(255)",'poll_name' => "VARCHAR(255)",'poll_option_one' => "VARCHAR(255)",'poll_option_two' => "VARCHAR(255)",'poll_option_three' => "VARCHAR(255)",'poll_option_four' => "VARCHAR(255)",'poll_option_five' => "VARCHAR(255)",'poll_option_one_results' => "INT",'poll_option_two_results' => "INT",'poll_option_three_results' => "INT",'poll_option_four_results' => "INT",'poll_option_five_results' => "INT",'bits_used' => "INT",'channel_points_used' => "INT",'started_at' => "DATETIME",'ended_at' => "DATETIME"],
        'tipping_settings' => ['StreamElements' => "TEXT DEFAULT NULL",'StreamLabs' => "TEXT DEFAULT NULL"],
        'tipping' => ['username' => "VARCHAR(255)",'amount' => "DECIMAL(10, 2)",'message' => "TEXT",'source' => "VARCHAR(255)"],
        'categories' => ['category' => "VARCHAR(255)"],
        'quote_category' => ['quote_id' => "INT(11)",'category_id' => "INT(11)"],
        'joke_settings' => ['blacklist' => 'TEXT'],
        'watch_time' => ['user_id' => 'VARCHAR(255) NOT NULL', 'username' => 'VARCHAR(255) NOT NULL', 'total_watch_time_live' => 'INT DEFAULT 0', 'total_watch_time_offline' => 'INT DEFAULT 0', 'last_active' =>'VARCHAR(255)'],
        'watch_time_excluded_users' => ['excluded_users' => 'VARCHAR(255) DEFAULT NULL'],
        'streamer_preferences' => ['send_welcome_messages' => 'TINYINT(1)', 'default_welcome_message' => 'TEXT', 'new_default_welcome_message' => 'TEXT', 'default_vip_welcome_message' => 'TEXT', 'new_default_vip_welcome_message' => 'TEXT', 'default_mod_welcome_message' => 'TEXT', 'new_default_mod_welcome_message' => 'TEXT'],
        'stream_lotto' => ['winning_numbers' => 'VARCHAR(255)', 'supplementary_numbers' => 'VARCHAR(255)'],
        'stream_lotto_winning_numbers' => ['winning_numbers' => 'VARCHAR(255)', 'supplementary_numbers' => 'VARCHAR(255)'],
        'ad_notice_settings' => ['ad_start_message' => 'VARCHAR(255)', 'ad_end_message' => 'VARCHAR(255)', 'ad_upcoming_message' => 'VARCHAR(255)', 'enable_ad_notice' => 'TINYINT(1) DEFAULT 1'],
    ];
    // Execute each table creation and validation
    foreach ($tables as $table_name => $sql) {
        // Check if the table exists
        $tableExists = $usrDBconn->query("SHOW TABLES LIKE '$table_name'")->num_rows > 0;
        // Create the table if it doesn't exist
        if (!$tableExists) {
            echo "<script>console.log('Table $table_name does not exist, creating it...');</script>
            ";
            if ($usrDBconn->query($sql) === TRUE) {
                echo "<script>console.log('Table $table_name created successfully.');</script>
                ";
            } else {
                echo "<script>console.error('Error creating table $table_name: " . addslashes($usrDBconn->error) . "');</script>
                ";
                continue;
            }
        }
        // Check for columns that need to be added
        if (isset($columns[$table_name])) {
            foreach ($columns[$table_name] as $column_name => $column_definition) {
                $result = $usrDBconn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$table_name' AND COLUMN_NAME = '$column_name'");
                if (!$result) {
                    echo "<script>console.error('Error checking column existence in table $table_name: " . addslashes($usrDBconn->error) . "');</script>";
                    continue;
                }
                if ($result->num_rows == 0) {
                    // Column doesn't exist, log and alter table to add it
                    $alter_sql = "ALTER TABLE `$table_name` ADD `$column_name` $column_definition";
                    echo "<script>console.log('Column $column_name does not exist, adding it to table $table_name...');</script>
                    ";
                    if ($usrDBconn->query($alter_sql) === TRUE) { } else {
                        echo "<script>console.error('Error adding column $column_name to table $table_name: " . addslashes($usrDBconn->error) . "');</script>
                        ";
                    }
                }
            }
        }
    }
    // Function to log messages asynchronously
    function async_log($message) {
        echo "<script>setTimeout(function() { console.log('$message'); }, 0);</script>
        ";
    }
    // Ensure 'Default' category exists
    if ($usrDBconn->query("INSERT INTO categories (category) SELECT 'Default' WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category = 'Default')") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default category ensured.');
    }
    // Ensure default options for showobs exist
    if ($usrDBconn->query("INSERT INTO showobs (font, color, list, shadow, bold, font_size) SELECT 'Arial', 'Black', 'Bullet', 0, 0, 22 WHERE NOT EXISTS (SELECT 1 FROM showobs)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default showobs options ensured.');
    }
    // Ensure default options for bot_settings exist
    if ($usrDBconn->query("INSERT INTO bot_settings (point_name, point_amount_chat, point_amount_follower, point_amount_subscriber, point_amount_cheer, point_amount_raid, subscriber_multiplier, excluded_users) SELECT 'Points', '10', '300', '500', '350', '50', '2', CONCAT('botofthespecter,', '$dbname') WHERE NOT EXISTS (SELECT 1 FROM bot_settings)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default bot_settings options ensured.');
    }
    // Ensure default options for subathon_settings exist
    if ($usrDBconn->query("INSERT INTO subathon_settings (starting_minutes, cheer_add, sub_add_1, sub_add_2, sub_add_3) SELECT 60, 5, 10, 20, 30 WHERE NOT EXISTS (SELECT 1 FROM subathon_settings)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default subathon_settings options ensured.');
    }
    // Ensure default options for chat protection
    if ($usrDBconn->query("INSERT INTO protection (url_blocking) SELECT 'False' WHERE NOT EXISTS (SELECT 1 FROM protection)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default protection options ensured.');
    }
    // Ensure default options for joke command
    $jokeBlacklist = '["nsfw", "religious", "political", "racist", "sexist"]';
    $jokeInsertQuery = "INSERT INTO joke_settings (id, blacklist) SELECT 1, '$jokeBlacklist' WHERE NOT EXISTS (SELECT 1 FROM joke_settings LIMIT 1);";
    if ($usrDBconn->query($jokeInsertQuery) === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default joke_settings options ensured.');
    }
    // Ensure default options for watch_time
    if ($usrDBconn->query("INSERT INTO watch_time_excluded_users (excluded_users) SELECT CONCAT('botofthespecter,', '$dbname') WHERE NOT EXISTS (SELECT 1 FROM watch_time_excluded_users)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default watch_time_excluded_users options ensured.');
    }
    // Ensure default groups exist
    $group_names = ["MOD", "VIP", "Subscriber T1", "Subscriber T2", "Subscriber T3", "Normal"];
    foreach ($group_names as $group_name) {
        if ($usrDBconn->query("INSERT INTO `groups` (name) SELECT '$group_name' WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = '$group_name')") === TRUE && $usrDBconn->affected_rows > 0) {
            async_log("Default group $group_name ensured.");
        }
    }
    if ($usrDBconn->query("INSERT INTO ad_notice_settings (ad_start_message, ad_end_message, ad_upcoming_message, enable_ad_notice) SELECT 'Ads are running for (duration). We''ll be right back after these ads.', 'Thanks for sticking with us through the ads! Welcome back, everyone!', 'Ads will be starting in (minutes).', 1 WHERE NOT EXISTS (SELECT 1 FROM ad_notice_settings)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default ad_notice_settings options ensured.');
    }
    // Ensure default options for streamer_preferences exist
    if ($usrDBconn->query("INSERT INTO streamer_preferences (send_welcome_messages, default_welcome_message, new_default_welcome_message, default_vip_welcome_message, new_default_vip_welcome_message, default_mod_welcome_message, new_default_mod_welcome_message) 
    SELECT 1, 'Welcome back, (user)! It''s great to see you again!', '(user) is new to the community, let''s give them a warm welcome!', 'ATTENTION! A very important person has entered the chat, welcome back (user)', 'ATTENTION! A very important person has entered the chat, welcome (user)', 'MOD ON DUTY! Welcome back (user), the power of the sword has increased!', 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!' 
    WHERE NOT EXISTS (SELECT 1 FROM streamer_preferences)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default streamer_preferences options ensured.');
    }
    // Close the connection
    $usrDBconn->close();
} catch (Exception $e) {
    echo "<script>console.error('Connection failed: " . addslashes($e->getMessage()) . "');</script>";
}
?>