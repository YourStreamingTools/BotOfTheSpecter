# Import necessary libraries
import os
import asyncio
import aiohttp
from dotenv import load_dotenv
import argparse

# Load environment variables from the .env file
load_dotenv()

# Parse command-line arguments for channel information
parser = argparse.ArgumentParser(description="Bot Setup Script")
parser.add_argument("-channelid", dest="channel_id", required=True, help="Twitch user ID")
parser.add_argument("-token", dest="channel_auth_token", required=True, help="Auth Token for authentication")
args = parser.parse_args()

# Twitch bot settings
CHANNEL_ID = args.channel_id
TWITCH_CHANNEL_AUTH = args.channel_auth_token
TWITCH_CLIENT_ID = os.getenv('CLIENT_ID')
BOT_USER_ID = "971436498"

# Function to mod the bot via Twitch API
async def mod_bot():
    url = f"https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={CHANNEL_ID}&user_id={BOT_USER_ID}"
    headers = {
        'Authorization': f"Bearer {TWITCH_CHANNEL_AUTH}",
        'Client-Id': TWITCH_CLIENT_ID,
    }
    async with aiohttp.ClientSession() as session:
        async with session.post(url, headers=headers) as response:
            if response.status == 204:
                print(f"The bot (user_id: {BOT_USER_ID}) is now a moderator in the channel (broadcaster_id: {CHANNEL_ID}).")
            else:
                print(f"Failed to mod the bot. Status: {response.status} - {await response.text()}")

if __name__ == "__main__":
    # Run the database setup and then mod the bot
    asyncio.run(mod_bot())