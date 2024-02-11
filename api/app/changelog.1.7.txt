#### **app.py**:

- Removed the **VERSION** variable.
- Imported the **VERSION** variable from **updates.py** to ensure consistency.
- Updated the function calls to **check_for_updates()** to use the one defined in **updates.py**.

#### **updates.py**:

- Created a new module for handling updates.
- Defined the **check_for_updates()** function to check for updates and display a message box if an update is available.
- Set the **VERSION** variable as **VERSION = "1.7"** in **updates.py**, removing it from **app.py**.

#### **server_communication.py**:

- Updated URLs like **SERVER_BASE_URL** and **AUTH_USERS_URL**.
- Improved error handling and return messages for functions like **run_bot()**, **check_bot_status()**, **stop_bot()**, and **restart_bot()**.
- Removed duplicate functions
- Added functionality to run, restart and stop the bot.

#### **about.py**:

- Created a new module for handling the about section of this app.
- Implemented text wrapping for the "About" window to improve the display of application information.

### Additional Notes

- The scripts have been organized to import necessary functions and variables from **updates.py** and **twitch_auth.py**.
- The **VERSION** variable is no longer defined in **app.py** but is set in **updates.py**, making it easier to maintain.
- Created an icon download script for the application's icon.
- Updated the main application window to use the downloaded icon.

These changes aim to improve the organization and maintainability of the code while addressing your specific requests.

> Download and run this application to view bot logs without the need to log into the website, you can now view if the bot is running with the "Check Bot Status" button. Please note that this executable is solely for viewing bot logs and checking the status of the bot if it's currently running. This is not the full bot application. Stay tuned for more versions and updates in the future.