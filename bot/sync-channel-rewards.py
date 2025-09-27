# Import necessary libraries
import os
import aiomysql
from dotenv import load_dotenv
import argparse
import aiohttp
import asyncio

# Load environment variables from the .env file
load_dotenv()

# Parse command-line arguments for channel information
parser = argparse.ArgumentParser(description="Channel Points Syncing Script")
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

async def channel_point_rewards():
    print("Starting channel point rewards sync...")
    # Check the broadcaster's type
    user_api_url = f"https://api.twitch.tv/helix/users?id={CHANNEL_ID}"
    headers = {
        "Client-Id": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    try:
        # Get MySQL connection
        conn = await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db=CHANNEL_NAME
        )
        async with conn.cursor() as cursor:
            async with aiohttp.ClientSession() as session:
                # Fetch broadcaster info
                async with session.get(user_api_url, headers=headers) as user_response:
                    if user_response.status == 200:
                        user_data = await user_response.json()
                        broadcaster_type = user_data["data"][0].get("broadcaster_type", "")
                        if broadcaster_type not in ["affiliate", "partner"]:
                            print("Broadcaster is not an affiliate or partner. Skipping sync.")
                            return
                    else:
                        print(f"Failed to fetch broadcaster info. Status: {user_response.status}")
                        return
                # If the broadcaster is an affiliate or partner, proceed with fetching rewards
                print("Fetching channel point rewards from Twitch...")
                api_url = f"https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={CHANNEL_ID}"
                async with session.get(api_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        rewards = data.get("data", [])
                        added = 0
                        updated = 0
                        for reward in rewards:
                            reward_id = reward.get("id")
                            reward_title = reward.get("title")
                            reward_cost = reward.get("cost")
                            # Check if the reward already exists in the database
                            await cursor.execute("SELECT COUNT(*) FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
                            count_result = await cursor.fetchone()
                            if count_result[0] == 0:
                                # Insert new reward
                                await cursor.execute(
                                    "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost) "
                                    "VALUES (%s, %s, %s)",
                                    (reward_id, reward_title, reward_cost)
                                )
                                print(f"Added reward: {reward_title} (Cost: {reward_cost})")
                                added += 1
                            else:
                                # Update existing reward
                                await cursor.execute(
                                    "UPDATE channel_point_rewards SET reward_title = %s, reward_cost = %s "
                                    "WHERE reward_id = %s",
                                    (reward_title, reward_cost, reward_id)
                                )
                                print(f"Updated reward: {reward_title} (Cost: {reward_cost})")
                                updated += 1
                        await conn.commit()
                        print(f"Sync completed. Added: {added}, Updated: {updated}")
                    else:
                        print(f"Failed to fetch rewards. Status: {response.status}")
    except Exception as e:
        print(f"Error during sync: {str(e)}")
    finally:
        if conn:
            conn.close()

# Run the async function
async def main():
    await channel_point_rewards()

# Start the asyncio loop
if __name__ == "__main__":
    asyncio.run(main())