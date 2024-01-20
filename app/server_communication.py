import os
import json
import requests
import paramiko
import twitch_auth
import logging
from decouple import config

# Get variables
REMOTE_SSH_HOST="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_PORT="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_USERNAME="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_PASSWORD="" # CHANGE TO MAKE THIS WORK
STATUS_COMMAND_TEMPLATE="" # CHANGE TO MAKE THIS WORK
BOT_COMMAND_TEMPLATE="" # CHANGE TO MAKE THIS WORK
SERVER_BASE_URL="https://api.botofthespecter.com/logs"
AUTH_USERS_URL="https://api.botofthespecter.com/authusers.json"

# Get Username
def get_global_username():
    return twitch_auth.global_username

# Get Display Name
def get_global_display_name():
    return twitch_auth.global_display_name

# Get Twitch ID
def get_global_user_id():
    return twitch_auth.global_twitch_id

# Get Twitch Auth Token
def get_global_auth_token():
    return twitch_auth.global_auth_token

# Get Workhook from database
def get_webhook_port():
    return 5000

# Function to run the bot
def run_bot(username, pid):
    username = get_global_username()
    twitchUserId = get_global_user_id()
    authToken = get_global_auth_token()
    webhookPort = get_webhook_port()

    remote_start_command = f"{BOT_COMMAND_TEMPLATE} -channel {username} -channelid {twitchUserId} -token {authToken} -port {webhookPort} > /dev/null 2>&1 &"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)

        stdin, stdout, stderr = ssh.exec_command(remote_start_command)

        # Check for errors if needed

        ssh.close()
    except Exception as e:
        pass  # Handle the error as needed

# Function to kill the bot on the server
def kill_bot(pid):
    display_name = get_global_display_name()
    username = get_global_username()

    remote_kill_command = f"kill {pid} > /dev/null 2>&1 &"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)

        stdin, stdout, stderr = ssh.exec_command(remote_kill_command)

        # Check for errors if needed

        ssh.close()
    except Exception as e:
        pass  # Handle the error as needed

# Function to check bot status
def check_bot_status():
    display_name = get_global_display_name()
    username = get_global_username()

    if not display_name:
        return "User is not authenticated."

    if not is_user_authorized(display_name):
        return f"{display_name} is not authorized to access this application."

    remote_command = f"{STATUS_COMMAND_TEMPLATE} -channel {username}"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)

        stdin, stdout, stderr = ssh.exec_command(remote_command)

        output = stdout.read().decode("utf-8")
        error = stderr.read().decode("utf-8")

        if error:
            return f"Error: {error}"
        else:
            return output

        ssh.close()
    except Exception as e:
        return f"Error: {str(e)}"

# Function to stop the bot
def stop_bot():
    return "Bot can't be stopped from this app."

# Function to restart the bot
def restart_bot():
    display_name = get_global_display_name()
    username = get_global_username()

    if not display_name:
        return "User is not authenticated."

    if not is_user_authorized(display_name):
        return f"{display_name} is not authorized to access this application."

    # Get the PID of the currently running bot
    pid = get_bot_pid()

    if pid > 0:
        # Kill the bot process
        kill_bot(pid)

        # Start the bot process
        run_bot(username, pid)

        # Get the new PID of the bot process after restart
        new_pid = get_bot_pid()

        if new_pid > 0:
            return f"Bot restarted successfully. New Process ID: {new_pid}."
        else:
            return "Failed to restart the bot."
    else:
        return "Bot is not running."

# Function to get the PID of the currently running bot
def get_bot_pid():
    display_name = get_global_display_name()
    username = get_global_username()
    remote_status_command = f"{STATUS_COMMAND_TEMPLATE} -channel {username}"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)

        stdin, stdout, stderr = ssh.exec_command(remote_status_command)

        output = stdout.read().decode("utf-8")
        error = stderr.read().decode("utf-8")

        if not error:
            # Parse the output to extract the PID
            try:
                pid = int(output)
                return pid
            except ValueError:
                return -1  # Failed to parse PID
        else:
            return -1  # Error in retrieving PID
    except Exception as e:
        return f"Error: {str(e)}"

# Is ther user authorized to use this app
def is_user_authorized(display_name):
    display_name = get_global_display_name()
    # Fetch the list of authorized users
    response = requests.get(AUTH_USERS_URL)

    # Check for a successful response
    if response.status_code == 200:
        auth_data = response.json()
        auth_users = auth_data.get("users", [])

        # Check if the provided display_name is in the list of authorized users
        if display_name in auth_users:
            print(f"{display_name} is authorized to access this application.")
            return True
        else:
            print(f"{display_name} is not authorized to access this application.")
            return False
    else:
        # Handle the error or return False
        print(f"Error fetching authorized users: {response.status_code}")
        return False
    
# Get logs from the server
def fetch_and_show_logs(log_type):
    display_name = get_global_display_name()

    if not display_name:
        return "User is not authenticated."

    if not is_user_authorized(display_name):
        return f"{display_name} is not authorized to access this application."

    if log_type == 'bot':
        logs = get_bot_logs()
    elif log_type == 'chat':
        logs = get_chat_logs()
    elif log_type == 'twitch':
        logs = get_twitch_logs()
    else:
        logs = "Invalid log type"

    return logs

def get_bot_logs():
    username = get_global_username()
    url = f"{SERVER_BASE_URL}/bot/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_chat_logs():
    username = get_global_username()
    url = f"{SERVER_BASE_URL}/chat/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_twitch_logs():
    username = get_global_username()
    url = f"{SERVER_BASE_URL}/twitch/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def handle_response(response):
    if response.status_code == 200:
        # Split the response text by lines, reverse it, and join it back together
        lines = response.text.strip().split('\n')
        reversed_text = '\n'.join(reversed(lines))
        return reversed_text
    else:
        # If the page is not found, it might mean the username is incorrect or there are no logs
        return {'error': f'No logs found or access denied, status code {response.status_code}'}