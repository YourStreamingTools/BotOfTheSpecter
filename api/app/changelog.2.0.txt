#### **app.py**

#### Added
- **Modules**:
  - `datetime`: Module for handling date and time operations.
  - `requests`: Module for making HTTP requests.
- **Functionality**:
  - Integration of a new function `fetch_counters_from_db` from the `server_communication` module, enabling database operations.

#### Modified
- **Imports**: Expanded import statement to include `fetch_counters_from_db` from `server_communication` module.

#### Extended
- **UI Components**: Added a new "Counters" tab within the application's GUI, enhancing user interface with additional data and analytics.

#### **server_communication.py**

##### Added
- **Modules**:
  - `os`: Module for operating system interface functionalities.
  - `sqlite3`: Module for SQLite database interactions.

#### **updates.py**

- Set the **VERSION** variable as **"2.0"**.

These updates aim to improve code organization, enhance functionality, and provide better user experience. 

> Download and run this application to conveniently monitor the bot's activity and status logs without accessing the website. The included functions allow you to start, stop, restart, and check the bot's status. Please note that this executable is solely for monitoring logs and managing the bot's operation; it does not encompass the full bot functionality. Stay tuned for future updates.