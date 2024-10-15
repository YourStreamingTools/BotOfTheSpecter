# Import necessary libraries
import os
import aiomysql
import requests
from dotenv import load_dotenv
import argparse

# Load environment variables from the .env file
load_dotenv()

# Parse command-line arguments for channel information
parser = argparse.ArgumentParser(description="Bot Setup Script")
parser.add_argument("-channel", dest="target_channel", required=True, help="Target Twitch channel name")
parser.add_argument("-channelid", dest="channel_id", required=True, help="Twitch user ID")
parser.add_argument("-token", dest="channel_auth_token", required=True, help="Auth Token for authentication")
args = parser.parse_args()

# Twitch bot settings
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
CLIENT_ID = os.getenv('CLIENT_ID')
CLIENT_SECRET = os.getenv('CLIENT_SECRET')
BOT_USER_ID = "971436498"

async def setup_database():
    try:
        conn = await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD
        )
        async with conn.cursor() as cursor:
            # Create MySQL database named after the channel, if it doesn't exist
            await cursor.execute("CREATE DATABASE IF NOT EXISTS `{}`".format(CHANNEL_NAME))
            await cursor.execute("USE `{}`".format(CHANNEL_NAME))
            # List of table creation statements
            tables = {
                'everyone': '''
                    CREATE TABLE IF NOT EXISTS everyone (
                        username VARCHAR(255),
                        group_name VARCHAR(255) DEFAULT NULL,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'groups': '''
                    CREATE TABLE IF NOT EXISTS `groups` (
                        id INT NOT NULL AUTO_INCREMENT,
                        name VARCHAR(255),
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
                ''',
                'custom_commands': '''
                    CREATE TABLE IF NOT EXISTS custom_commands (
                        command VARCHAR(255),
                        response TEXT,
                        status VARCHAR(255),
                        PRIMARY KEY (command)
                    ) ENGINE=InnoDB
                ''',
                'builtin_commands': '''
                    CREATE TABLE IF NOT EXISTS builtin_commands (
                        command VARCHAR(255),
                        status VARCHAR(255),
                        PRIMARY KEY (command)
                    ) ENGINE=InnoDB
                ''',
                'user_typos': '''
                    CREATE TABLE IF NOT EXISTS user_typos (
                        username VARCHAR(255),
                        typo_count INT DEFAULT 0,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'lurk_times': '''
                    CREATE TABLE IF NOT EXISTS lurk_times (
                        user_id VARCHAR(255),
                        start_time VARCHAR(255) NOT NULL,
                        PRIMARY KEY (user_id)
                    ) ENGINE=InnoDB
                ''',
                'hug_counts': '''
                    CREATE TABLE IF NOT EXISTS hug_counts (
                        username VARCHAR(255),
                        hug_count INT DEFAULT 0,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'kiss_counts': '''
                    CREATE TABLE IF NOT EXISTS kiss_counts (
                        username VARCHAR(255),
                        kiss_count INT DEFAULT 0,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'total_deaths': '''
                    CREATE TABLE IF NOT EXISTS total_deaths (
                        death_count INT DEFAULT 0
                    ) ENGINE=InnoDB
                ''',
                'game_deaths': '''
                    CREATE TABLE IF NOT EXISTS game_deaths (
                        game_name VARCHAR(255),
                        death_count INT DEFAULT 0,
                        PRIMARY KEY (game_name)
                    ) ENGINE=InnoDB
                ''',
                'custom_counts': '''
                    CREATE TABLE IF NOT EXISTS custom_counts (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        command VARCHAR(255) NOT NULL,
                        count INT NOT NULL
                    ) ENGINE=InnoDB
                ''',
                'bits_data': '''
                    CREATE TABLE IF NOT EXISTS bits_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id VARCHAR(255),
                        user_name VARCHAR(255),
                        bits INT,
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'subscription_data': '''
                    CREATE TABLE IF NOT EXISTS subscription_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id VARCHAR(255),
                        user_name VARCHAR(255),
                        sub_plan VARCHAR(255),
                        months INT,
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'followers_data': '''
                    CREATE TABLE IF NOT EXISTS followers_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id VARCHAR(255),
                        user_name VARCHAR(255),
                        followed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'raid_data': '''
                    CREATE TABLE IF NOT EXISTS raid_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        raider_name VARCHAR(255),
                        raider_id VARCHAR(255),
                        viewers INT,
                        raid_count INT,
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'quotes': '''
                    CREATE TABLE IF NOT EXISTS quotes (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        quote TEXT
                    ) ENGINE=InnoDB
                ''',
                'seen_users': '''
                    CREATE TABLE IF NOT EXISTS seen_users (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(255),
                        welcome_message VARCHAR(255) DEFAULT NULL,
                        status VARCHAR(255)
                    ) ENGINE=InnoDB
                ''',
                'seen_today': '''
                    CREATE TABLE IF NOT EXISTS seen_today (
                        user_id VARCHAR(255),
                        username VARCHAR(255),
                        PRIMARY KEY (user_id)
                    ) ENGINE=InnoDB
                ''',
                'timed_messages': '''
                    CREATE TABLE IF NOT EXISTS timed_messages (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        interval_count INT,
                        message TEXT
                    ) ENGINE=InnoDB
                ''',
                'profile': '''
                    CREATE TABLE IF NOT EXISTS profile (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        timezone VARCHAR(255) DEFAULT NULL,
                        weather_location VARCHAR(255) DEFAULT NULL,
                        discord_alert VARCHAR(255) DEFAULT NULL,
                        discord_mod VARCHAR(255) DEFAULT NULL,
                        discord_alert_online VARCHAR(255) DEFAULT NULL
                    ) ENGINE=InnoDB
                ''',
                'protection': '''
                    CREATE TABLE IF NOT EXISTS protection (
                        url_blocking VARCHAR(255),
                        profanity VARCHAR(255)
                    ) ENGINE=InnoDB
                ''',
                'link_whitelist': '''
                    CREATE TABLE IF NOT EXISTS link_whitelist (
                        link VARCHAR(255),
                        PRIMARY KEY (link)
                    ) ENGINE=InnoDB
                ''',
                'link_blacklisting': '''
                    CREATE TABLE IF NOT EXISTS link_blacklisting (
                        link VARCHAR(255),
                        PRIMARY KEY (link)
                    ) ENGINE=InnoDB
                ''',
                'stream_credits': '''
                    CREATE TABLE IF NOT EXISTS stream_credits (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(255),
                        event VARCHAR(255),
                        data VARCHAR(255)
                    ) ENGINE=InnoDB
                ''',
                'message_counts': '''
                    CREATE TABLE IF NOT EXISTS message_counts (
                        username VARCHAR(255),
                        message_count INT NOT NULL,
                        user_level VARCHAR(255) NOT NULL,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'bot_points': '''
                    CREATE TABLE IF NOT EXISTS bot_points (
                        user_id VARCHAR(50),
                        user_name VARCHAR(50),
                        points INT DEFAULT 0,
                        PRIMARY KEY (user_id)
                    ) ENGINE=InnoDB
                ''',
                'bot_settings': '''
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
                    ) ENGINE=InnoDB
                ''',
                'channel_point_rewards': '''
                    CREATE TABLE IF NOT EXISTS channel_point_rewards (
                        reward_id VARCHAR(255),
                        reward_title VARCHAR(255),
                        reward_cost VARCHAR(255),
                        custom_message TEXT,
                        PRIMARY KEY (reward_id)
                    ) ENGINE=InnoDB
                ''',
                'active_timers': '''
                    CREATE TABLE IF NOT EXISTS active_timers (
                        user_id BIGINT NOT NULL,
                        end_time DATETIME NOT NULL,
                        PRIMARY KEY (user_id)
                    ) ENGINE=InnoDB
                ''',
                'poll_results': '''
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
                    ) ENGINE=InnoDB
                ''',
                'tipping_settings': '''
                    CREATE TABLE IF NOT EXISTS tipping_settings (
                        StreamElements TEXT DEFAULT NULL,
                        StreamLabs TEXT DEFAULT NULL
                    ) ENGINE=InnoDB
                ''',
                'tipping': '''
                    CREATE TABLE IF NOT EXISTS tipping (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(255),
                        amount DECIMAL(10, 2),
                        message TEXT,
                        source VARCHAR(255),
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'categories': '''
                    CREATE TABLE IF NOT EXISTS categories (
                        id INT(11) NOT NULL AUTO_INCREMENT,
                        category VARCHAR(255) NOT NULL,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ''',
                'showobs': '''
                    CREATE TABLE IF NOT EXISTS showobs (
                        id INT(11) NOT NULL AUTO_INCREMENT,
                        font VARCHAR(50) NOT NULL DEFAULT 'Arial',
                        color VARCHAR(50) NOT NULL DEFAULT 'Black',
                        list VARCHAR(10) NOT NULL DEFAULT 'Bullet',
                        shadow TINYINT(1) NOT NULL DEFAULT 0,
                        bold TINYINT(1) NOT NULL DEFAULT 0,
                        font_size INT(11) NOT NULL DEFAULT 22,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1
                ''',
                'todos': '''
                    CREATE TABLE IF NOT EXISTS todos (
                        id INT(255) NOT NULL AUTO_INCREMENT,
                        objective VARCHAR(255) NOT NULL,
                        category VARCHAR(255) DEFAULT NULL,
                        completed VARCHAR(3) NOT NULL DEFAULT 'No',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1
                ''',
                'sound_alerts': '''
                    CREATE TABLE IF NOT EXISTS sound_alerts (
                        reward_id VARCHAR(255),
                        sound_mapping TEXT, 
                        PRIMARY KEY (reward_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1
                ''',
                'subathon': '''
                    CREATE TABLE IF NOT EXISTS subathon (
                        id INT AUTO_INCREMENT,
                        start_time DATETIME,
                        end_time DATETIME,
                        starting_minutes INT,
                        paused BOOLEAN DEFAULT FALSE,
                        remaining_minutes INT DEFAULT 0,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1
                ''',
                'subathon_settings': '''
                    CREATE TABLE IF NOT EXISTS subathon_settings (
                        id INT AUTO_INCREMENT,
                        starting_minutes INT,
                        cheer_add INT,
                        sub_add_1 INT,
                        sub_add_2 INT,
                        sub_add_3 INT,
                        donation_add INT,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1
                '''
            }
            # Create tables
            for table_schema in tables.items():
                try:
                    await cursor.execute(table_schema)
                except aiomysql.Error:
                    pass
            await conn.commit()
            # Ensure 'Default' category exists
            await cursor.execute("INSERT INTO categories (category) SELECT 'Default' WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category = 'Default')")
            await conn.commit()
            # Ensure default options for showobs exist
            await cursor.execute('''
                INSERT INTO showobs (font, color, list, shadow, bold, font_size)
                SELECT 'Arial', 'Black', 'Bullet', 0, 0, 22
                WHERE NOT EXISTS (SELECT 1 FROM showobs)
            ''')
            await conn.commit()
            await cursor.execute('''
                INSERT INTO bot_settings (point_name, point_amount_chat, point_amount_follower, point_amount_subscriber, point_amount_cheer, point_amount_raid, subscriber_multiplier, excluded_users)
                SELECT 'Points', '10', '300', '500', '350', '50', '2', CONCAT('botofthespecter,', %s)
                WHERE NOT EXISTS (SELECT 1 FROM bot_settings)
            ''', (CHANNEL_NAME,))
            await conn.commit()
            await cursor.execute('''
                INSERT INTO subathon_settings (starting_minutes, cheer_add, sub_add_1, sub_add_2, sub_add_3)
                SELECT 60, 5, 10, 20, 30
                WHERE NOT EXISTS (SELECT 1 FROM subathon_settings)
            ''')
            await conn.commit()
    except aiomysql.Error as err:
        return
    finally:
        conn.close()

# Function to mod the bot via Twitch API
def mod_bot():
    url = f"https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={CHANNEL_ID}&user_id={BOT_USER_ID}"
    headers = {
        'Authorization': f"Bearer {CHANNEL_AUTH}",
        'Client-Id': CLIENT_ID,
    }
    response = requests.post(url, headers=headers)
    if response.status_code == 204:
        print(f"The bot (user_id: {BOT_USER_ID}) is now a moderator in the channel (broadcaster_id: {CHANNEL_ID}).")
    else:
        print(f"Failed to mod the bot. Status: {response.status_code} - {response.text}")

if __name__ == "__main__":
    # Run the database setup and then mod the bot
    import asyncio
    asyncio.run(setup_database())
    mod_bot()