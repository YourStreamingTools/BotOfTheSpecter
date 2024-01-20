import os
import webbrowser
from http.server import HTTPServer, BaseHTTPRequestHandler
import threading
import requests
import urllib.parse as urlparse
import logging
from decouple import config
import json

# Construct the log file path in the user's AppData directory
appdata_dir = os.path.join(os.getenv('APPDATA'), 'BotOfTheSpecter', 'logs')
os.makedirs(appdata_dir, exist_ok=True)
log_file_path = os.path.join(appdata_dir, 'authentication.log')

# Initialize a logger
logging.basicConfig(filename=log_file_path, level=logging.INFO)

# Get variables
CLIENT_ID="" # CHANGE TO MAKE THIS WORK
CLIENT_SECRET="" # CHANGE TO MAKE THIS WORK
REDIRECT_URI = "http://localhost:5000/auth"
AUTH_URL = f"https://id.twitch.tv/oauth2/authorize?response_type=code&client_id={CLIENT_ID}&redirect_uri={REDIRECT_URI}&scope=openid user:read:email moderator:manage:shoutouts chat:read chat:edit moderation:read moderator:read:followers channel:read:vips channel:read:subscriptions moderator:read:chatters bits:read"

class AuthHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'Authentication complete. You may close this window now.')
        url = self.path
        code = urlparse.parse_qs(urlparse.urlparse(url).query).get('code', None)
        if code:
            code = code[0]
            exchange_code_for_token(code)

def exchange_code_for_token(code):
    payload = {
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'code': code,
        'grant_type': 'authorization_code',
        'redirect_uri': REDIRECT_URI
    }
    response = requests.post("https://id.twitch.tv/oauth2/token", data=payload)
    access_token = response.json().get('access_token')
    
    # Use the access token to get the user's information
    headers = {
        'Authorization': f'Bearer {access_token}',
        'Client-ID': CLIENT_ID
    }
    user_response = requests.get("https://api.twitch.tv/helix/users", headers=headers)
    user_info = user_response.json().get('data', [{}])[0]
    username = user_info.get('login')
    display_name = user_info.get('display_name')
    twitch_id = user_info.get('id')

    # Store Twitch credentials as global variables
    global global_username
    global global_display_name
    global global_twitch_id
    global global_auth_token
    global_username = username
    global_display_name = display_name
    global_twitch_id = twitch_id
    global_auth_token = access_token

    # Log both the username and display name
    logging.info(f"Authenticated Twitch user: {username} (User ID: {twitch_id} | Display Name: {display_name})")
    print(f"Authenticated Twitch user: {username} (User ID: {twitch_id} | Display Name: {display_name})")

def run_server():
    server_address = ('0.0.0.0', 5000)
    httpd = HTTPServer(server_address, AuthHandler)
    httpd.handle_request()

def start_auth():
    threading.Thread(target=run_server, daemon=True).start()
    webbrowser.open(AUTH_URL)

# Initialize the logger
logger = logging.getLogger(__name__)

if __name__ == "__main__":
    # Redirect logging output to the specified log file
    logger.addHandler(logging.FileHandler(log_file_path))
    # Start the authentication process
    start_auth()