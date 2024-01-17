import os
import json
import requests
import twitch_auth
import logging
from decouple import config

# Get variables
SERVER_BASE_URL="" # CHANGE TO MAKE THIS WORK
AUTH_USERS_URL="" # CHANGE TO MAKE THIS WORK

appdata_dir = os.path.join(os.getenv('APPDATA'), 'BotOfTheSpecter', 'logs')
os.makedirs(appdata_dir, exist_ok=True)
log_file_path = os.path.join(appdata_dir, 'server-communication.log')

def get_global_username():
    return twitch_auth.global_username
def get_global_display_name():
    return twitch_auth.global_display_name

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