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

def log(message: str):
    print(message, flush=True)

async def channel_point_rewards():
    conn = None
    log("Starting channel point rewards sync...")
    # Check the broadcaster's type
    user_api_url = f"https://api.twitch.tv/helix/users?id={CHANNEL_ID}"
    headers = {
        "Client-Id": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    try:
        log("Connecting to the channel database...")
        # Get MySQL connection
        conn = await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db=CHANNEL_NAME
        )
        async with conn.cursor() as cursor:
            log("Querying broadcaster metadata...")
            async with aiohttp.ClientSession() as session:
                # Fetch broadcaster info
                async with session.get(user_api_url, headers=headers) as user_response:
                    if user_response.status == 200:
                        user_data = await user_response.json()
                        broadcaster_type = user_data["data"][0].get("broadcaster_type", "")
                        if broadcaster_type not in ["affiliate", "partner"]:
                            log("Broadcaster is not an affiliate or partner. Skipping sync.")
                            return
                    else:
                        log(f"Failed to fetch broadcaster info. Status: {user_response.status}")
                        return

                # First, fetch manageable rewards (those specter can manage) - collect their IDs so we can mark managed_by='specter'
                manageable_ids = set()
                log("Fetching manageable rewards list (only_manageable_rewards=true)...")
                after = None
                while True:
                    m_url = f"https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={CHANNEL_ID}&only_manageable_rewards=true&first=50"
                    if after:
                        m_url += f"&after={after}"
                    async with session.get(m_url, headers=headers) as m_resp:
                        if m_resp.status != 200:
                            log(f"Failed to fetch manageable rewards. Status: {m_resp.status}")
                            break
                        m_data = await m_resp.json()
                        for r in m_data.get('data', []):
                            manageable_ids.add(r.get('id'))
                        after = m_data.get('pagination', {}).get('cursor')
                        if not after:
                            break

                # Now fetch all rewards (paginated) and upsert them. Mark managed_by if in manageable_ids
                log("Fetching all channel point rewards from Twitch (with pagination)...")
                after = None
                added = 0
                updated = 0
                upserted = 0
                total_fetched = 0
                while True:
                    api_url = f"https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={CHANNEL_ID}&first=50"
                    if after:
                        api_url += f"&after={after}"
                    async with session.get(api_url, headers=headers) as response:
                        if response.status != 200:
                            log(f"Failed to fetch rewards. Status: {response.status}")
                            break
                        data = await response.json()
                        rewards = data.get('data', [])
                        for reward in rewards:
                            total_fetched += 1
                            reward_id = reward.get("id")
                            reward_title = reward.get("title")
                            reward_cost = reward.get("cost") or 0
                            prompt = reward.get("prompt") or reward_title
                            managed_by = 'specter' if reward_id in manageable_ids else ''

                            # Upsert with preserving existing managed_by when managed_by is not provided
                            upsert_sql = (
                                "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost, custom_message, managed_by)"
                                " VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE"
                                " reward_title=VALUES(reward_title), reward_cost=VALUES(reward_cost), custom_message=VALUES(custom_message),"
                                " managed_by = IF(VALUES(managed_by) <> '', VALUES(managed_by), managed_by)"
                            )
                            await cursor.execute(upsert_sql, (reward_id, reward_title, reward_cost, prompt, managed_by))
                            affected = cursor.rowcount
                            if affected == 1:
                                added += 1
                            elif affected == 2:
                                # MySQL returns 2 for an update on duplicate key sometimes; treat as updated
                                updated += 1
                            upserted += 1

                        await conn.commit()

                        after = data.get('pagination', {}).get('cursor')
                        if not after:
                            break

                log(f"Sync completed. Fetched: {total_fetched}, Upserted: {upserted}, Added: {added}, Updated: {updated}, Managed count: {len(manageable_ids)}")
    except Exception as e:
        log(f"Error during sync: {str(e)}")
    finally:
        if conn:
            conn.close()
            await conn.wait_closed()
# Run the async function
async def main():
    await channel_point_rewards()

# Start the asyncio loop
if __name__ == "__main__":
    asyncio.run(main())