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
        'auto_record_settings' => "
            CREATE TABLE IF NOT EXISTS auto_record_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                server_location VARCHAR(255) NOT NULL,
                enabled TINYINT(1) DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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
                permission VARCHAR(255) DEFAULT 'everyone',
                PRIMARY KEY (command(255))
            ) ENGINE=InnoDB",
        'custom_user_commands' => "
            CREATE TABLE IF NOT EXISTS custom_user_commands (
                command TEXT,
                response TEXT,
                status TEXT,
                cooldown INT DEFAULT '15',
                user_id VARCHAR(255),
                PRIMARY KEY (command(255))
            ) ENGINE=InnoDB",
        'builtin_commands' => "
            CREATE TABLE IF NOT EXISTS builtin_commands (
                command VARCHAR(255),
                status VARCHAR(255),
                permission VARCHAR(255),
                cooldown_rate INT DEFAULT 1,
                cooldown_time INT DEFAULT 15,
                cooldown_bucket VARCHAR(255) DEFAULT 'default',
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
        'game_deaths_settings' => "
            CREATE TABLE IF NOT EXISTS game_deaths_settings (
                game_name VARCHAR(255) PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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
        'analytic_raids' => "
            CREATE TABLE IF NOT EXISTS analytic_raids (
                id INT PRIMARY KEY AUTO_INCREMENT,
                raider_name VARCHAR(255) NOT NULL,
                viewers INT NOT NULL,
                source VARCHAR(20) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_raider_name (raider_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'quotes' => "
            CREATE TABLE IF NOT EXISTS quotes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                quote TEXT,
                added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
        'seen_users' => "
            CREATE TABLE IF NOT EXISTS seen_users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255),
                welcome_message VARCHAR(255) DEFAULT NULL,
                status VARCHAR(255),
                first_seen DATETIME DEFAULT NULL,
                last_seen DATETIME DEFAULT NULL
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
                heartrate_code VARCHAR(8) DEFAULT NULL,
                stream_bounty_api_key VARCHAR(255),
                tanggle_api_token VARCHAR(255) DEFAULT NULL,
                tanggle_community_uuid VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB",
        'protection' => "
            CREATE TABLE IF NOT EXISTS protection (
                url_blocking VARCHAR(500) DEFAULT 'False',
                term_blocking VARCHAR(500) DEFAULT 'False',
                block_first_message_commands VARCHAR(10) DEFAULT 'False'
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
        'blocked_terms' => "
            CREATE TABLE IF NOT EXISTS blocked_terms (
                term VARCHAR(255),
                PRIMARY KEY (term)
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
                usage_count INT DEFAULT 0,
                managed_by VARCHAR(50) DEFAULT 'twitch',
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
                poll_id VARCHAR(255) PRIMARY KEY,
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
                tip_id VARCHAR(255) DEFAULT NULL,
                currency VARCHAR(10) DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
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
        'vip_today' => "
            CREATE TABLE IF NOT EXISTS vip_today (
                user_id VARCHAR(255) NOT NULL,
                username VARCHAR(255) DEFAULT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'raffles' => "
            CREATE TABLE IF NOT EXISTS raffles (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                start_time DATETIME DEFAULT NULL,
                end_time DATETIME DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'scheduled',
                is_weighted TINYINT(1) DEFAULT 0,
                winner_username VARCHAR(255) DEFAULT NULL,
                winner_user_id VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'raffle_entries' => "
            CREATE TABLE IF NOT EXISTS raffle_entries (
                id INT PRIMARY KEY AUTO_INCREMENT,
                raffle_id INT NOT NULL,
                user_id VARCHAR(255),
                username VARCHAR(255),
                weight INT DEFAULT 1,
                entered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (raffle_id, username),
                FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'ad_notice_settings' => "
            CREATE TABLE IF NOT EXISTS ad_notice_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ad_start_message VARCHAR(255),
                ad_end_message VARCHAR(255),
                ad_upcoming_message VARCHAR(255),
                ad_snoozed_message VARCHAR(255),
                enable_ad_notice TINYINT(1) DEFAULT 1,
                enable_upcoming_ad_message TINYINT(1) DEFAULT 1,
                enable_start_ad_message TINYINT(1) DEFAULT 1,
                enable_end_ad_message TINYINT(1) DEFAULT 1,
                enable_snoozed_ad_message TINYINT(1) DEFAULT 1,
                enable_ai_ad_breaks TINYINT(1) DEFAULT 0
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
                `current_user` VARCHAR(255),
                streak INT DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'stream_status' => "
            CREATE TABLE IF NOT EXISTS stream_status (
                status VARCHAR(255),
                UNIQUE (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'chat_history' => "
            CREATE TABLE IF NOT EXISTS chat_history (
                author VARCHAR(255),
                message TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'member_streams' => "
            CREATE TABLE IF NOT EXISTS member_streams (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255) NOT NULL,
                stream_url VARCHAR(255) NOT NULL,
                UNIQUE (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "command_options" => "
            CREATE TABLE IF NOT EXISTS command_options (
                command TEXT,
                options JSON,
                PRIMARY KEY (command(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'bingo_games' => "
            CREATE TABLE IF NOT EXISTS bingo_games (
                game_id VARCHAR(255) PRIMARY KEY,
                start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                end_time DATETIME NULL,
                events_count INT DEFAULT 0,
                is_sub_only BOOLEAN DEFAULT FALSE,
                random_call_only BOOLEAN DEFAULT TRUE,
                status ENUM('active', 'completed') DEFAULT 'active'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'bingo_winners' => "
            CREATE TABLE IF NOT EXISTS bingo_winners (
                id INT PRIMARY KEY AUTO_INCREMENT,
                game_id VARCHAR(255) NOT NULL,
                player_name VARCHAR(255) NOT NULL,
                player_id VARCHAR(255) NOT NULL,
                `rank` INT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_game_id (game_id),
                INDEX idx_rank (`rank`),
                FOREIGN KEY (game_id) REFERENCES bingo_games(game_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'working_study_overlay_settings' => "
            CREATE TABLE IF NOT EXISTS working_study_overlay_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                focus_minutes INT DEFAULT 60,
                micro_break_minutes INT DEFAULT 5,
                recharge_break_minutes INT DEFAULT 30,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'working_study_overlay_tasks' => "
            CREATE TABLE IF NOT EXISTS working_study_overlay_tasks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255) NOT NULL,
                task_id VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                priority VARCHAR(20) DEFAULT 'medium',
                completed TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE (username, task_id),
                INDEX idx_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'automated_shoutout_settings' => "
            CREATE TABLE IF NOT EXISTS automated_shoutout_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                cooldown_minutes INT NOT NULL DEFAULT 60,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CHECK (cooldown_minutes >= 60)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'automated_shoutout_tracking' => "
            CREATE TABLE IF NOT EXISTS automated_shoutout_tracking (
                user_id VARCHAR(255) PRIMARY KEY,
                user_name VARCHAR(255) NOT NULL,
                shoutout_time DATETIME NOT NULL,
                INDEX idx_shoutout_time (shoutout_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'stream_session_stats' => "
            CREATE TABLE IF NOT EXISTS stream_session_stats (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ad_break_count INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'eventsub_sessions' => "
            CREATE TABLE IF NOT EXISTS eventsub_sessions (
                session_id VARCHAR(255) PRIMARY KEY,
                session_name VARCHAR(255) NOT NULL,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    // Build $columns mapping from the CREATE TABLE statements in $tables to keep definitions in sync automatically
    $columns = [];
    foreach ($tables as $tbl => $create_sql) {
        $columns[$tbl] = [];
        // Extract the column-definition block between the first opening parenthesis and the matching closing one
        $start = strpos($create_sql, '(');
        $end = strrpos($create_sql, ')');
        if ($start === false || $end === false || $end <= $start) {
            continue;
        }
        $inner = substr($create_sql, $start + 1, $end - $start - 1);
        // Split into lines by commas, but keep it simple: split on comma-newline which matches how statements are formatted here
        $parts = preg_split('/,\s*\n/', $inner);
        foreach ($parts as $part) {
            $line = trim($part);
            if ($line === '') continue;
            // Ignore constraints and index definitions
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i', $line)) continue;
            // Match column name and definition
            if (preg_match('/^(`?)([A-Za-z0-9_]+)\1\s+(.*)$/s', $line, $m)) {
                $col = $m[2];
                $def = trim(preg_replace('/\s+$/', '', $m[3]));
                // Normalize whitespace in definition
                $def = preg_replace('/\s+/', ' ', $def);
                $columns[$tbl][$col] = $def;
            }
        }
    }
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
            // Add missing columns
            foreach ($columns[$table_name] as $column_name => $column_definition) {
                $result = $usrDBconn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$table_name' AND COLUMN_NAME = '$column_name'");
                if (!$result) {
                    echo "<script>console.error('Error checking column existence in table $table_name: " . addslashes($usrDBconn->error) . "');</script>";
                    continue;
                }
                if ($result->num_rows == 0) {
                    // Column doesn't exist, log and alter table to add it
                    $alter_sql = "ALTER TABLE `$table_name` ADD `$column_name` $column_definition";
                    echo "<script>console.log('Column $column_name does not exist, adding it to table $table_name...');</script>";
                    if ($usrDBconn->query($alter_sql) === TRUE) {
                        echo "<script>console.log('Column $column_name added to $table_name successfully.');</script>";
                    } else {
                        echo "<script>console.error('Error adding column $column_name to table $table_name: " . addslashes($usrDBconn->error) . "');</script>";
                    }
                }
            }
            // Prune extra columns that aren't in the expected columns list (safe checks applied)
            $existingColsRes = $usrDBconn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$table_name'");
            if ($existingColsRes) {
                $existingCols = [];
                while ($row = $existingColsRes->fetch_assoc()) {
                    $existingCols[] = $row['COLUMN_NAME'];
                }
                $expectedCols = array_keys($columns[$table_name]);
                $extraCols = array_diff($existingCols, $expectedCols);
                foreach ($extraCols as $extraCol) {
                    // Safety checks: do not drop primary key columns
                    $pkCheck = $usrDBconn->query("SHOW INDEX FROM `$table_name` WHERE Key_name = 'PRIMARY' AND Column_name = '$extraCol'");
                    if ($pkCheck && $pkCheck->num_rows > 0) {
                        echo "<script>console.log('Skipping drop of primary key column $extraCol on $table_name');</script>";
                        continue;
                    }
                    // Don't drop columns that participate in foreign key constraints
                    $fkCheck = $usrDBconn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$table_name' AND COLUMN_NAME = '$extraCol' AND REFERENCED_TABLE_NAME IS NOT NULL");
                    if ($fkCheck && $fkCheck->num_rows > 0) {
                        echo "<script>console.log('Skipping drop of FK column $extraCol on $table_name');</script>";
                        continue;
                    }
                    // Prevent accidental removal of core audit columns if they exist in multiple places (id handled above)
                    $safe_to_drop = true;
                    $reserved = ['created_at','updated_at','timestamp'];
                    if (in_array($extraCol, $reserved)) {
                        echo "<script>console.log('Skipping drop of reserved column $extraCol on $table_name');</script>";
                        $safe_to_drop = false;
                    }
                    if (!$safe_to_drop) continue;
                    // Attempt to drop the column
                    $drop_sql = "ALTER TABLE `$table_name` DROP COLUMN `$extraCol`";
                    echo "<script>console.log('Extra column $extraCol found on $table_name, attempting to drop it...');</script>";
                    if ($usrDBconn->query($drop_sql) === TRUE) {
                        echo "<script>console.log('Dropped extra column $extraCol from $table_name successfully.');</script>";
                    } else {
                        echo "<script>console.error('Error dropping column $extraCol from table $table_name: " . addslashes($usrDBconn->error) . "');</script>";
                        // continue without failing entire migration
                    }
                }
            } else {
                echo "<script>console.error('Error fetching existing columns for table $table_name: " . addslashes($usrDBconn->error) . "');</script>";
            }
        }
    }
    // Special handling for chat_history table - remove primary key if it exists
    $checkPrimaryKey = $usrDBconn->query("SHOW INDEX FROM chat_history WHERE Key_name = 'PRIMARY'");
    if ($checkPrimaryKey && $checkPrimaryKey->num_rows > 0) {
        echo "<script>console.log('Primary key found on chat_history table, removing it...');</script>";
        if ($usrDBconn->query("ALTER TABLE chat_history DROP PRIMARY KEY") === TRUE) {
            echo "<script>console.log('Primary key removed from chat_history table successfully.');</script>";
        } else {
            echo "<script>console.error('Error removing primary key from chat_history table: " . addslashes($usrDBconn->error) . "');</script>";
        }
    }
    // Special handling for profile table - remove deprecated discord columns
    $deprecated_columns = ['discord_alert', 'discord_mod', 'discord_alert_online'];
    foreach ($deprecated_columns as $column) {
        $result = $usrDBconn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = 'profile' AND COLUMN_NAME = '$column'");
        if ($result && $result->num_rows > 0) {
            echo "<script>console.log('Deprecated column $column found in profile table, removing it...');</script>";
            if ($usrDBconn->query("ALTER TABLE profile DROP COLUMN `$column`") === TRUE) {
                echo "<script>console.log('Column $column removed from profile table successfully.');</script>";
            } else {
                echo "<script>console.error('Error removing column $column from profile table: " . addslashes($usrDBconn->error) . "');</script>";
            }
        }
    }
    // Function to log messages asynchronously
    function async_log($message)
    {
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
    if ($usrDBconn->query("INSERT INTO protection (url_blocking, term_blocking, block_first_message_commands) SELECT 'False', 'False', 'False' WHERE NOT EXISTS (SELECT 1 FROM protection)") === TRUE && $usrDBconn->affected_rows > 0) {
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
    // Ensure default status for stream status is False
    if ($usrDBconn->query("INSERT INTO stream_status (status) SELECT 'False' WHERE NOT EXISTS (SELECT 1 FROM stream_status)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default stream_status options ensured.');
    }
    // Ensure default groups exist
    $group_names = ["MOD", "VIP", "Subscriber T1", "Subscriber T2", "Subscriber T3", "Normal"];
    foreach ($group_names as $group_name) {
        if ($usrDBconn->query("INSERT INTO `groups` (name) SELECT '$group_name' WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = '$group_name')") === TRUE && $usrDBconn->affected_rows > 0) {
            async_log("Default group $group_name ensured.");
        }
    }
    if ($usrDBconn->query("INSERT INTO ad_notice_settings (ad_start_message, ad_end_message, ad_upcoming_message, ad_snoozed_message, enable_ad_notice, enable_upcoming_ad_message, enable_start_ad_message, enable_end_ad_message, enable_snoozed_ad_message, enable_ai_ad_breaks) SELECT 'Ads are running for (duration). We''ll be right back after these ads.', 'Thanks for sticking with us through the ads! Welcome back, everyone!', 'Ads will be starting in (minutes).', 'Ads have been snoozed.', 1, 1, 1, 1, 1, 0 WHERE NOT EXISTS (SELECT 1 FROM ad_notice_settings)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default ad_notice_settings options ensured.');
    }
    // Ensure default options for automated shoutout settings
    if ($usrDBconn->query("INSERT INTO automated_shoutout_settings (cooldown_minutes) SELECT 60 WHERE NOT EXISTS (SELECT 1 FROM automated_shoutout_settings)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default automated_shoutout_settings options ensured.');
    }
    // Ensure default options for working_study_overlay_settings
    if ($usrDBconn->query("INSERT INTO working_study_overlay_settings (focus_minutes, micro_break_minutes, recharge_break_minutes) SELECT 60, 5, 30 WHERE NOT EXISTS (SELECT 1 FROM working_study_overlay_settings)") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default working_study_overlay_settings options ensured.');
    }
    // Ensure default options for streamer_preferences exist
    if (
        $usrDBconn->query("INSERT INTO streamer_preferences (send_welcome_messages, default_welcome_message, new_default_welcome_message, default_vip_welcome_message, new_default_vip_welcome_message, default_mod_welcome_message, new_default_mod_welcome_message) 
    SELECT 1, 'Welcome back, (user)! It''s great to see you again!', '(user) is new to the community, let''s give them a warm welcome!', 'ATTENTION! A very important person has entered the chat, welcome back (user)', 'ATTENTION! A very important person has entered the chat, welcome (user)', 'MOD ON DUTY! Welcome back (user), the power of the sword has increased!', 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!' 
    WHERE NOT EXISTS (SELECT 1 FROM streamer_preferences)") === TRUE && $usrDBconn->affected_rows > 0
    ) {
        async_log('Default streamer_preferences options ensured.');
    }
    // Migration and maintenance for ad_snoozed_message column
    $check_column_query = "SHOW COLUMNS FROM ad_notice_settings LIKE 'ad_snoozed_message'";
    $column_exists = $usrDBconn->query($check_column_query);
    if ($column_exists->num_rows == 0) {
        $add_column_sql = "ALTER TABLE ad_notice_settings ADD ad_snoozed_message VARCHAR(255) DEFAULT 'Ads have been snoozed.' AFTER ad_upcoming_message";
        if ($usrDBconn->query($add_column_sql) === TRUE) {
            async_log('Successfully added ad_snoozed_message column to ad_notice_settings table.');
        } else {
            async_log('Error adding ad_snoozed_message column: ' . $usrDBconn->error);
        }
    }
    // Always ensure the ad_snoozed_message has a proper value (for both new and existing installations)
    $update_sql = "UPDATE ad_notice_settings SET ad_snoozed_message = 'Ads have been snoozed.' WHERE ad_snoozed_message IS NULL OR ad_snoozed_message = '' OR ad_snoozed_message = 'The streamer has snoozed the upcoming ad break.'";
    if ($usrDBconn->query($update_sql) === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Updated ad_snoozed_message to use the new default message.');
    }
    // Migration for granular ad notice enable settings
    $granular_ad_settings = [
        'enable_upcoming_ad_message',
        'enable_start_ad_message',
        'enable_end_ad_message',
        'enable_snoozed_ad_message'
    ];
    foreach ($granular_ad_settings as $column) {
        $check_column_query = "SHOW COLUMNS FROM ad_notice_settings LIKE '$column'";
        $column_exists = $usrDBconn->query($check_column_query);
        if ($column_exists->num_rows == 0) {
            $add_column_sql = "ALTER TABLE ad_notice_settings ADD $column TINYINT(1) DEFAULT 1";
            if ($usrDBconn->query($add_column_sql) === TRUE) {
                async_log("Successfully added $column column to ad_notice_settings table.");
            } else {
                async_log("Error adding $column column: " . $usrDBconn->error);
            }
        }
    }
    // Ensure default options for new BETA chat alert types
    if ($usrDBconn->query("INSERT INTO twitch_chat_alerts (alert_type, alert_message) SELECT 'gift_paid_upgrade', 'Thank you (user) for upgrading from a Gifted Sub to a paid (tier) subscription!' WHERE NOT EXISTS (SELECT 1 FROM twitch_chat_alerts WHERE alert_type = 'gift_paid_upgrade')") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default gift_paid_upgrade chat alert ensured.');
    }
    if ($usrDBconn->query("INSERT INTO twitch_chat_alerts (alert_type, alert_message) SELECT 'prime_paid_upgrade', 'Thank you (user) for upgrading from Prime Gaming to a paid (tier) subscription!' WHERE NOT EXISTS (SELECT 1 FROM twitch_chat_alerts WHERE alert_type = 'prime_paid_upgrade')") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default prime_paid_upgrade chat alert ensured.');
    }
    if ($usrDBconn->query("INSERT INTO twitch_chat_alerts (alert_type, alert_message) SELECT 'pay_it_forward', 'Thank you (user) for paying it forward! They received a (tier) gift from (gifter) and gifted a (tier) subscription in return!' WHERE NOT EXISTS (SELECT 1 FROM twitch_chat_alerts WHERE alert_type = 'pay_it_forward')") === TRUE && $usrDBconn->affected_rows > 0) {
        async_log('Default pay_it_forward chat alert ensured.');
    }
    // Close the connection
    $usrDBconn->close();
} catch (Exception $e) {
    echo "<script>console.error('Connection failed: " . addslashes($e->getMessage()) . "');</script>";
}
?>