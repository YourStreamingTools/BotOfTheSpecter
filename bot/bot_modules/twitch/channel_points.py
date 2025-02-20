import aiohttp
import aiomysql
from bot_modules.logger import api_logger
from bot_modules.database import get_mysql_connection

# Functions for handling Twitch channel points
async def channel_point_rewards(CHANNEL_ID, CHANNEL_NAME, CLIENT_ID, CHANNEL_AUTH):
    # Check the broadcaster's type
    user_api_url = f"https://api.twitch.tv/helix/users?id={CHANNEL_ID}"
    headers = {
        "Client-Id": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    try:
        # Get MySQL connection
        sqldb = await get_mysql_connection(CHANNEL_NAME)
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            async with aiohttp.ClientSession() as session:
                # Fetch broadcaster info
                async with session.get(user_api_url, headers=headers) as user_response:
                    if user_response.status == 200:
                        user_data = await user_response.json()
                        broadcaster_type = user_data["data"][0].get("broadcaster_type", "")
                        if broadcaster_type not in ["affiliate", "partner"]:
                            api_logger.info(f"Broadcaster type '{broadcaster_type}' does not support channel points. Exiting.")
                            return
                    else:
                        api_logger.error(f"Failed to fetch broadcaster info: {user_response.status} {user_response.reason}")
                        return
                # If the broadcaster is an affiliate or partner, proceed with fetching rewards
                api_url = f"https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={CHANNEL_ID}"
                async with session.get(api_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        rewards = data.get("data", [])
                        for reward in rewards:
                            reward_id = reward.get("id")
                            reward_title = reward.get("title")
                            reward_cost = reward.get("cost")
                            # Check if the reward already exists in the database
                            await cursor.execute("SELECT COUNT(*) FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
                            count_result = await cursor.fetchone()
                            if count_result["COUNT(*)"] == 0:
                                # Insert new reward
                                api_logger.info(f"Inserting new reward: {reward_id}, {reward_title}, {reward_cost}")
                                await cursor.execute(
                                    "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost) "
                                    "VALUES (%s, %s, %s)",
                                    (reward_id, reward_title, reward_cost)
                                )
                            else:
                                # Update existing reward
                                await cursor.execute(
                                    "UPDATE channel_point_rewards SET reward_title = %s, reward_cost = %s "
                                    "WHERE reward_id = %s",
                                    (reward_title, reward_cost, reward_id)
                                )
                        api_logger.info("Rewards processed successfully.")
                    else:
                        api_logger.error(f"Failed to fetch rewards: {response.status} {response.reason}")
                        
        await sqldb.commit()
    except Exception as e:
        api_logger.error(f"An error occurred in channel_point_rewards: {str(e)}")
    finally:
        if sqldb:
            sqldb.close()
            await sqldb.ensure_closed()