import re
import random
import aiohttp
import aiomysql
from datetime import datetime
from dotenv import load_dotenv
load_dotenv()

from bot_modules.database import get_mysql_connection

command_last_used = {}

async def handle_custom_command(command, messageContent, messageAuthor, channel, tz, chat_logger, CHANNEL_NAME):
    sqldb = await get_mysql_connection(CHANNEL_NAME)
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('SELECT response, status, cooldown FROM custom_commands WHERE command = %s', (command,))
            cc_result = await cursor.fetchone()
            if cc_result:
                response = cc_result.get("response")
                status = cc_result.get("status")
                cooldown = cc_result.get("cooldown")
                if status == 'Enabled':
                    cooldown = int(cooldown)
                    # Check if the command is on cooldown
                    last_used = command_last_used.get(command, None)
                    if last_used:
                        time_since_last_used = (datetime.now() - last_used).total_seconds()
                        if time_since_last_used < cooldown:
                            remaining_time = cooldown - time_since_last_used
                            chat_logger.info(f"{command} is on cooldown. {int(remaining_time)} seconds remaining.")
                            await channel.send(f"The command {command} is on cooldown. Please wait {int(remaining_time)} seconds.")
                            return
                    command_last_used[command] = datetime.now()
                    # Define all supported switches
                    switches = [
                        '(customapi.', '(count)', '(daysuntil.', '(command.', '(user)', '(author)', 
                        '(random.percent)', '(random.number)', '(random.percent.', '(random.number.',
                        '(random.pick.', '(math.', '(usercount)', '(timeuntil.'
                    ]
                    responses_to_send = []
                    # Process all placeholders until none remain
                    while any(switch in response for switch in switches):
                        # Handle (count)
                        if '(count)' in response:
                            try:
                                await update_custom_count(command, CHANNEL_NAME, chat_logger)
                                get_count = await get_custom_count(command, CHANNEL_NAME, chat_logger)
                                response = response.replace('(count)', str(get_count))
                            except Exception as e:
                                chat_logger.error(f"Error handling (count): {e}")
                                response = response.replace('(count)', "Error")
                        # Handle (usercount)
                        if '(usercount)' in response:
                            try:
                                user_mention = re.search(r'@(\w+)', messageContent)
                                user_name = user_mention.group(1) if user_mention else messageAuthor
                                # Get the user count for the specific command
                                await cursor.execute('SELECT count FROM user_counts WHERE command = %s AND user = %s', (command, user_name))
                                result = await cursor.fetchone()
                                if result:
                                    user_count = result.get("count")
                                else:
                                    # If no entry found, initialize it to 0
                                    user_count = 0
                                    await cursor.execute('INSERT INTO user_counts (command, user, count) VALUES (%s, %s, %s)', (command, user_name, user_count))
                                    await cursor.connection.commit()
                                # Increment the count
                                user_count += 1
                                await cursor.execute('UPDATE user_counts SET count = %s WHERE command = %s AND user = %s', (user_count, command, user_name))
                                await cursor.connection.commit()
                                # Replace the (usercount) placeholder with the updated user count
                                response = response.replace('(usercount)', str(user_count))
                            except Exception as e:
                                chat_logger.error(f"Error while handling (usercount): {e}")
                                response = response.replace('(usercount)', "Error")
                        # Handle (daysuntil.)
                        if '(daysuntil.' in response:
                            get_date = re.search(r'\(daysuntil\.(\d{4}-\d{2}-\d{2})\)', response)
                            if get_date:
                                date_str = get_date.group(1)
                                event_date = datetime.strptime(date_str, "%Y-%m-%d").date()
                                current_date = datetime.now(tz).date()
                                days_left = (event_date - current_date).days
                                # If days_left is negative, try next year
                                if days_left < 0:
                                    next_year_date = event_date.replace(year=event_date.year + 1)
                                    days_left = (next_year_date - current_date).days
                                response = response.replace(f"(daysuntil.{date_str})", str(days_left))
                        # Handle (timeuntil.)
                        if '(timeuntil.' in response:
                            # Try first for full date-time format
                            get_datetime = re.search(r'\(timeuntil\.(\d{4}-\d{2}-\d{2}(?:-\d{1,2}-\d{2})?)\)', response)
                            if get_datetime:
                                datetime_str = get_datetime.group(1)
                                # Check if time components are included
                                if '-' in datetime_str[10:]:  # Full date-time format
                                    event_datetime = datetime.strptime(datetime_str, "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                                else:  # Date only format, default to midnight
                                    event_datetime = datetime.strptime(datetime_str + "-00-00", "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                                current_datetime = datetime.now(tz)
                                time_left = event_datetime - current_datetime
                                # If time_left is negative, try next year
                                if time_left.days < 0:
                                    event_datetime = event_datetime.replace(year=event_datetime.year + 1)
                                    time_left = event_datetime - current_datetime
                                days_left = time_left.days
                                hours_left, remainder = divmod(time_left.seconds, 3600)
                                minutes_left, _ = divmod(remainder, 60)
                                time_left_str = f"{days_left} days, {hours_left} hours, and {minutes_left} minutes"
                                # Replace the original placeholder with the calculated time
                                response = response.replace(f"(timeuntil.{datetime_str})", time_left_str)
                        # Handle (user) and (author)
                        if '(user)' in response:
                            user_mention = re.search(r'@(\w+)', messageContent)
                            user_name = user_mention.group(1) if user_mention else messageAuthor
                            response = response.replace('(user)', user_name)
                        if '(author)' in response:
                            response = response.replace('(author)', messageAuthor)
                        # Handle (command.)
                        if '(command.' in response:
                            command_match = re.search(r'\(command\.(\w+)\)', response)
                            if command_match:
                                sub_command = command_match.group(1)
                                await cursor.execute('SELECT response FROM custom_commands WHERE command = %s', (sub_command,))
                                sub_response = await cursor.fetchone()
                                if sub_response:
                                    response = response.replace(f"(command.{sub_command})", "")
                                    responses_to_send.append(sub_response["response"])
                                else:
                                    chat_logger.error(f"{sub_command} is no longer available.")
                                    await channel.send(f"The command {sub_command} is no longer available.")
                        # Handle random replacements
                        if '(random.percent' in response or '(random.number' in response or '(random.pick.' in response:
                            # Unified pattern for all placeholders
                            pattern = r'\((random\.(percent|number|pick))(?:\.(.+?))?\)'
                            matches = re.finditer(pattern, response)
                            for match in matches:
                                category = match.group(1)  # 'random.percent', 'random.number', or 'random.pick'
                                details = match.group(3)  # Range (x-y) or items for pick
                                replacement = ''  # Initialize the replacement string
                                if 'percent' in category or 'number' in category:
                                    # Default bounds for random.percent and random.number
                                    lower_bound, upper_bound = 0, 100
                                    if details:  # If range is specified, extract it
                                        range_match = re.match(r'(\d+)-(\d+)', details)
                                        if range_match:
                                            lower_bound, upper_bound = int(range_match.group(1)), int(range_match.group(2))
                                    random_value = random.randint(lower_bound, upper_bound)
                                    replacement = f'{random_value}%' if 'percent' in category else str(random_value)
                                elif 'pick' in category:
                                    # Split the details into items to pick from
                                    items = details.split('.') if details else []
                                    replacement = random.choice(items) if items else ''
                                # Replace the placeholder with the generated value
                                response = response.replace(match.group(0), replacement)
                        # Handle (math.x+y)
                        if '(math.' in response:
                            math_match = re.search(r'\(math\.(.+)\)', response)
                            if math_match:
                                math_expression = math_match.group(1)
                                try:
                                    math_result = eval(math_expression)
                                    response = response.replace(f'(math.{math_expression})', str(math_result))
                                except Exception as e:
                                    chat_logger.error(f"Math expression error: {e}")
                                    response = response.replace(f'(math.{math_expression})', "Error")
                        # Handle (customapi.)
                        if '(customapi.' in response:
                            url_match = re.search(r'\(customapi\.(\S+)\)', response)
                            if url_match:
                                url = url_match.group(1)
                                json_flag = False
                                if url.startswith('json.'):
                                    json_flag = True
                                    url = url[5:]  # Remove 'json.' prefix
                                api_response = await fetch_api_response(url, json_flag=json_flag)
                                response = response.replace(f"(customapi.{url})", api_response)
                    await channel.send(response)
                    for resp in responses_to_send:
                        chat_logger.info(f"{command} command ran with response: {resp}")
                        await channel.send(resp)
                else:
                    chat_logger.info(f"{command} not ran because it's disabled.")
            else:
                chat_logger.info(f"{command} not found in the database.")
    except Exception as e:
        chat_logger.error(f"Error in handle_custom_command: {e}")
    finally:
        await sqldb.ensure_closed()

async def update_custom_count(command, CHANNEL_NAME, chat_logger):
    sqldb = await get_mysql_connection(CHANNEL_NAME)
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                current_count = result.get("count")
                new_count = current_count + 1
                await cursor.execute('UPDATE custom_counts SET count = %s WHERE command = %s', (new_count, command))
                chat_logger.info(f"Updated count for command '{command}' to {new_count}.")
            else:
                await cursor.execute('INSERT INTO custom_counts (command, count) VALUES (%s, %s)', (command, 1))
                chat_logger.info(f"Inserted new command '{command}' with count 1.")
        await sqldb.commit()
    except Exception as e:
        chat_logger.error(f"Error updating count for command '{command}': {e}")
        await sqldb.rollback()
    finally:
        await sqldb.ensure_closed()

async def get_custom_count(command, CHANNEL_NAME, chat_logger):
    sqldb = await get_mysql_connection(CHANNEL_NAME)
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                count = result.get("count")
                chat_logger.info(f"Retrieved count for command '{command}': {count}")
                return count
            else:
                chat_logger.info(f"No count found for command '{command}', returning 0.")
                return 0
    except Exception as e:
        chat_logger.error(f"Error retrieving count for command '{command}': {e}")
        return 0
    finally:
        await sqldb.ensure_closed()

async def fetch_api_response(url, json_flag=False):
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                if response.status == 200:
                    if json_flag:
                        return await response.json()
                    else:
                        return await response.text()
                else:
                    return f"Status Error: {response.status}"
    except Exception as e:
        return f"Exception Error: {str(e)}"