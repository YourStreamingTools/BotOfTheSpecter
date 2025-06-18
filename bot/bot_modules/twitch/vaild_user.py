import aiohttp

# Function to check if the user is a real user on Twitch
async def is_valid_twitch_user(user_name, CLIENT_ID, CHANNEL_AUTH):
    url = f"https://api.twitch.tv/helix/users?login={user_name}"
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    async with aiohttp.ClientSession() as session:
        async with session.get(url, headers=headers) as response:
            if response.status == 200:
                data = await response.json()
                if data['data']:
                    return True  # User exists
                else:
                    return False  # User does not exist
            else:
                # If there's an error with the request or response, return False
                return False