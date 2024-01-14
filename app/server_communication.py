import requests

# Base URL for the server
SERVER_BASE_URL = 'https://api.botofthespecter.com/logs'

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
        return response.text
    else:
        # If the page is not found, it might mean the username is incorrect or there are no logs
        return {'error': f'No logs found or access denied, status code {response.status_code}'}