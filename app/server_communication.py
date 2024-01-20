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
REMOTE_COMMAND_TEMPLATE = "python /var/www/bot/status.py"
SERVER_BASE_URL = "https://api.botofthespecter.com/logs"
AUTH_USERS_URL = "https://api.botofthespecter.com/authusers.json"

def get_global_username():
    return twitch_auth.global_username

def get_global_display_name():
    return twitch_auth.global_display_name

# Function to run the bot
def run_bot():
    return "Bot can't be started from this app."

# Function to check bot status
def check_bot_status():
    display_name = get_global_display_name()
    username = get_global_username()

    if not display_name:
        return "User is not authenticated."

    if not is_user_authorized(display_name):
        return f"{display_name} is not authorized to access this application."

    ssh_host = REMOTE_SSH_HOST
    ssh_port = REMOTE_SSH_PORT
    ssh_username = REMOTE_SSH_USERNAME
    ssh_password = REMOTE_SSH_PASSWORD
    remote_command_template = REMOTE_COMMAND_TEMPLATE

    remote_command = f"{remote_command_template} -channel {username}"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(ssh_host, int(ssh_port), ssh_username, ssh_password)

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
    return "Bot can't be restarted from this app."

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