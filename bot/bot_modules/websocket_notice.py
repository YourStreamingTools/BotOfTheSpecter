import os
import aiomysql
from bot_modules.database import get_mysql_connection
from bot_modules.logger import bot_logger
from aiohttp import ClientSession
from urllib.parse import urlencode

# Unified function to connect to the websocket server and push notices
async def websocket_notice(
    event, user=None, death=None, game=None, weather=None, cheer_amount=None,
    sub_tier=None, sub_months=None, raid_viewers=None, text=None, sound=None,
    video=None, additional_data=None, CHANNEL_NAME=None, API_TOKEN=None
):
    sqldb = await get_mysql_connection(CHANNEL_NAME)
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            async with ClientSession() as session:
                params = {
                    'code': API_TOKEN,
                    'event': event
                }
                # Event-specific parameter handling
                if event == "WALKON" and user:
                    walkon_file_path = f"/var/www/walkons/{CHANNEL_NAME}/{user}.mp3"
                    if os.path.exists(walkon_file_path):
                        params['channel'] = CHANNEL_NAME
                        params['user'] = user
                    else:
                        return
                elif event == "DEATHS" and death and game:
                    params['death-text'] = death
                    params['game'] = game
                elif event in ["STREAM_ONLINE", "STREAM_OFFLINE"]:
                    pass  # No additional parameters needed
                elif event == "WEATHER" and weather:
                    params['location'] = weather
                elif event == "TWITCH_FOLLOW" and user:
                    params['twitch-username'] = user
                elif event == "TWITCH_CHEER" and user and cheer_amount:
                    params['twitch-username'] = user
                    params['twitch-cheer-amount'] = cheer_amount
                elif event == "TWITCH_SUB" and user and sub_tier and sub_months:
                    params['twitch-username'] = user
                    params['twitch-tier'] = sub_tier
                    params['twitch-sub-months'] = sub_months
                elif event == "TWITCH_RAID" and user and raid_viewers:
                    params['twitch-username'] = user
                    params['twitch-raid'] = raid_viewers
                elif event == "TTS" and text:
                    # Make a database query to fetch additional information for TTS
                    try:
                        query = "SELECT voice, language FROM tts_settings WHERE user = %s"
                        await cursor.execute(query, (user,))
                        result = await cursor.fetchone()
                        if result:
                            params['voice'] = result.get('voice', 'default')
                            params['language'] = result.get('language', 'en')
                        else:
                            params['voice'] = 'default'
                            params['language'] = 'en'
                    except aiomysql.Error as e:
                        bot_logger.error(f"Database error while fetching TTS settings for user '{user}': {e}")
                        params['voice'] = 'default'
                        params['language'] = 'en'
                    params['text'] = text
                elif event in ["SUBATHON_START", "SUBATHON_STOP", "SUBATHON_PAUSE", "SUBATHON_RESUME", "SUBATHON_ADD_TIME"]:
                    if additional_data:
                        params.update(additional_data)
                    else:
                        bot_logger.error(f"Event '{event}' requires additional parameters.")
                        return
                elif event == "SOUND_ALERT" and sound:
                    params['sound'] = f"https://soundalerts.botofthespecter.com/{CHANNEL_NAME}/{sound}"
                elif event == "VIDEO_ALERT" and video:
                    params['video'] = f"https://videoalerts.botofthespecter.com/{CHANNEL_NAME}/{video}"
                else:
                    bot_logger.error(f"Event '{event}' requires additional parameters or is not recognized")
                    return
                # URL-encode the parameters
                encoded_params = urlencode(params)
                url = f'https://websocket.botofthespecter.com/notify?{encoded_params}'
                # Send the HTTP request
                async with session.get(url) as response:
                    if response.status == 200:
                        bot_logger.info(f"HTTP event '{event}' sent successfully with params: {params}")
                    else:
                        bot_logger.error(f"Failed to send HTTP event '{event}'. Status: {response.status}")
    except Exception as e:
        bot_logger.error(f"Error while processing websocket notice: {e}")
    finally:
        await sqldb.ensure_closed()