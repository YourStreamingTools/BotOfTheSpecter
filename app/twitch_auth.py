from flask import Flask, request, redirect
import requests

app = Flask(__name__)

CLIENT_ID = "" # CHANGE TO MAKE THIS WORK
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
REDIRECT_URI = "http://localhost:5000/auth"

@app.route('/')
def home():
    return redirect(f"https://id.twitch.tv/oauth2/authorize?response_type=code&client_id={CLIENT_ID}&redirect_uri={REDIRECT_URI}&scope=user:read:email")

@app.route('/auth')
def auth():
    code = request.args.get('code')
    payload = {
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'code': code,
        'grant_type': 'authorization_code',
        'redirect_uri': REDIRECT_URI
    }

    # Exchange code for token
    token_response = requests.post("https://id.twitch.tv/oauth2/token", data=payload)
    access_token = token_response.json().get('access_token')

    # Use token to get user information
    headers = {
        'Authorization': f'Bearer {access_token}',
        'Client-ID': CLIENT_ID
    }
    user_response = requests.get("https://api.twitch.tv/helix/users", headers=headers)
    user_data = user_response.json()
    username = user_data['data'][0]['login']

    return f'Logged in as {username}!'

if __name__ == '__main__':
    app.run(debug=True)
