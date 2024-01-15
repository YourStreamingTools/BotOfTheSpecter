import requests
import twitch_auth

# Base URL for the server
SERVER_BASE_URL = "" # CHANGE TO MAKE THIS WORK
AUTH_USERS_URL = "" # CHANGE TO MAKE THIS WORK

def get_global_username():
    return twitch_auth.global_username

def is_user_authorized(username):
    # Fetch the list of authorized users
    response = requests.get(AUTH_USERS_URL)
    
    # Check for a successful response
    if response.status_code == 200:
        auth_users = response.json()
        # Check if the username is in the list of authorized users
        return username in auth_users
    else:
        # Handle the error by raising an exception
        raise Exception(f"Error fetching authorized users: {response.status_code}")

def get_bot_logs(username):
    url = f"{SERVER_BASE_URL}/bot/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_chat_logs(username):
    url = f"{SERVER_BASE_URL}/chat/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_twitch_logs(username):
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
        # Handle non-200 status codes by raising an exception
        raise Exception(f'Error: No logs found or access denied, status code {response.status_code}')

# Get the global username
username = get_global_username()

try:
    if is_user_authorized(username):
        print(f"User {username} is authorized.")
        # Now you can safely fetch logs
        bot_logs = get_bot_logs(username)
        chat_logs = get_chat_logs(username)
        twitch_logs = get_twitch_logs(username)
    else:
        print(f"User {username} is not authorized.")
except Exception as e:
    print(str(e))
