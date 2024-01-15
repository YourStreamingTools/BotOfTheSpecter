import webbrowser
from http.server import HTTPServer, BaseHTTPRequestHandler
import threading
import requests
import urllib.parse as urlparse

CLIENT_ID = "" # CHANGE TO MAKE THIS WORK
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
REDIRECT_URI = "http://localhost:5000/auth"
AUTH_URL = f"https://id.twitch.tv/oauth2/authorize?response_type=code&client_id={CLIENT_ID}&redirect_uri={REDIRECT_URI}&scope=user:read:email"

class AuthHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'Authentication complete. You may close this window.')
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

    global global_username
    global global_display_name 
    global_username = username
    global_display_name = display_name

    # Print both the username and display name to the console
    print(f"Authenticated Twitch user: {username} (Display Name: {display_name})")

def run_server():
    server_address = ('', 5000)
    httpd = HTTPServer(server_address, AuthHandler)
    httpd.handle_request()

def start_auth():
    threading.Thread(target=run_server, daemon=True).start()
    webbrowser.open(AUTH_URL)