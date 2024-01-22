import requests
import os

# URL of the .ico file
icon_url = 'https://api.botofthespecter.com/BotOfTheSpecter.ico'

# Path to the %APPDATA% directory
appdata_path = os.getenv('APPDATA')

# Create the directory if it doesn't exist
icon_directory = os.path.join(appdata_path, 'BotOfTheSpecter')
if not os.path.exists(icon_directory):
    os.makedirs(icon_directory)

# Full path to where you want to save the .ico file
icon_path = os.path.join(icon_directory, 'BotOfTheSpecter.ico')

# Check if the file already exists
if not os.path.isfile(icon_path):
    # File does not exist, download the file
    try:
        response = requests.get(icon_url)
        response.raise_for_status()

        with open(icon_path, 'wb') as file:
            file.write(response.content)
        print(f"Icon downloaded and saved to {icon_path}")
    except requests.exceptions.HTTPError as err:
        print(f"HTTP Error: {err}")
    except requests.exceptions.RequestException as e:
        print(f"Error downloading the icon: {e}")
else:
    print(f"Icon already exists at {icon_path}")
