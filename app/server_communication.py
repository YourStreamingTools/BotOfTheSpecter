import os
import time
import tkinter as tk
from tkinter import ttk
import requests
import paramiko
import twitch_auth
import mysql.connector
from decouple import config
import sqlite3
import datetime

# Define the window
window = tk.Tk()
counter_tree = ttk.Treeview(window)
counter_type_label = tk.Label(window, text="")

# Get variables
REMOTE_SSH_HOST="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_PORT="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_USERNAME="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_PASSWORD="" # CHANGE TO MAKE THIS WORK
STATUS_COMMAND_TEMPLATE="" # CHANGE TO MAKE THIS WORK
BOT_COMMAND_TEMPLATE="" # CHANGE TO MAKE THIS WORK
LOGS_SERVER_URL="" # CHANGE TO MAKE THIS WORK
AUTH_USERS_URL="" # CHANGE TO MAKE THIS WORK

# Get Username
def get_global_username():
    return twitch_auth.global_username

# Get Display Name
def get_global_display_name():
    return twitch_auth.global_display_name

# Get Twitch ID
def get_global_user_id():
    return twitch_auth.global_twitch_id

# Get Twitch Auth Token
def get_global_auth_token():
    return twitch_auth.global_auth_token

# Get Workhook from database
def get_webhook_port():
    db_config = {
        "host": "", # CHANGE TO MAKE THIS WORK
        "user": "", # CHANGE TO MAKE THIS WORK
        "password": "", # CHANGE TO MAKE THIS WORK
        "database": "" # CHANGE TO MAKE THIS WORK
    }

    username = get_global_username()

    try:
        # Create a database connection
        connection = mysql.connector.connect(**db_config)

        if connection.is_connected():
            cursor = connection.cursor()

            # Query to fetch webhook_port based on username
            query = "SELECT webhook_port FROM users WHERE username = %s"
            cursor.execute(query, (username,))
            result = cursor.fetchone()

            if result:
                webhook_port = result[0]
                print(f"Webhook port found as: {webhook_port}")
                return webhook_port

    except mysql.connector.Error as error:
        print("Error: ", error)
    finally:
        if connection.is_connected():
            cursor.close()
            connection.close()

# Function to run the bot
def run_bot(status_label):
    username = get_global_username()
    display_name = get_global_display_name()
    twitchUserId = get_global_user_id()
    authToken = get_global_auth_token()
    webhookPort = get_webhook_port()

    if not display_name:
        status_label.config(text="User is not authenticated.", fg="red")
        return

    if not is_user_authorized(display_name):
        status_label.config(text=f"{display_name} is not authorized to access this application.", fg="red")
        return
    
    remote_status_command = f"{STATUS_COMMAND_TEMPLATE} -channel {username}"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
        stdin, stdout, stderr = ssh.exec_command(remote_status_command)

        status_output = stdout.read().decode("utf-8")
        ssh.close()

        if "Bot is running with process ID" in status_output:
            pid_start_index = status_output.find(":") + 1
            pid = status_output[pid_start_index:].strip()
            print(f"Bot is already running with PID: {pid}")
            status_label.config(text=f"Bot is already running with PID: {pid}", fg="blue")
        else:
            remote_start_command = f"{BOT_COMMAND_TEMPLATE} -channel {username} -channelid {twitchUserId} -token {authToken} -port {webhookPort} > /dev/null 2>&1 &"
            ssh = paramiko.SSHClient()
            ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
            ssh.exec_command(remote_start_command)
            time.sleep(3)  # Wait for a few seconds to ensure the bot starts
            stdin, stdout, stderr = ssh.exec_command(remote_status_command)

            run_status = stdout.read().decode("utf-8")
            ssh.close()

            pid_start_index = run_status.find(":") + 1
            pid = run_status[pid_start_index:].strip()
            print(f"Bot started successfully. Process ID: {pid}")
            status_label.config(text=f"Bot started successfully. Process ID: {pid}", fg="blue")
    except Exception as e:
        status_label.config(text=f"Error: {str(e)}", fg="red", wraplength=400)

# Function to check bot status
def check_bot_status(status_label):
    display_name = get_global_display_name()
    username = get_global_username()

    if not display_name:
        status_label.config(text="User is not authenticated.", fg="red")
        return

    if not is_user_authorized(display_name):
        status_label.config(text=f"{display_name} is not authorized to access this application.", fg="red")
        return

    remote_command = f"{STATUS_COMMAND_TEMPLATE} -channel {username}"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)

        stdin, stdout, stderr = ssh.exec_command(remote_command)

        output = stdout.read().decode("utf-8")
        error = stderr.read().decode("utf-8")

        ssh.close()

        if error:
            print(error)
            status_label.config(text=f"Error: {error}", fg="red")
        else:
            print(output)
            status_label.config(text=output, fg="blue")
    except Exception as e:
        print(e)
        status_label.config(text=f"Error: {str(e)}", fg="red", wraplength=400)

# Function to stop the bot
def stop_bot(status_label):
    display_name = get_global_display_name()
    username = get_global_username()

    if not display_name:
        status_label.config(text="User is not authenticated.", fg="red")
        return

    if not is_user_authorized(display_name):
        status_label.config(text=f"{display_name} is not authorized to access this application.", fg="red")
        return

    try:
        remote_status_command = f"{STATUS_COMMAND_TEMPLATE} -channel {username}"
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
        stdin, stdout, stderr = ssh.exec_command(remote_status_command)
        status_output = stdout.read().decode("utf-8")
        ssh.close()

        if "Bot is running with process ID" in status_output:
            try:
                remote_kill_command = f"kill {status_output.split(':')[-1].strip()} > /dev/null 2>&1 &"
                ssh = paramiko.SSHClient()
                ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
                ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
                ssh.exec_command(remote_kill_command)
                time.sleep(3)
                stdin, stdout, stderr = ssh.exec_command(remote_status_command)
                new_status_output = stdout.read().decode("utf-8")
                ssh.close()

                if "Bot is running with process ID" not in new_status_output:
                    status_label.config(text="Bot stopped successfully.", fg="blue")
                else:
                    status_label.config(text="Failed to stop the bot.", fg="red")
            except Exception as e:
                status_label.config(text=f"Error: {str(e)}", fg="red", wraplength=400)
        else:
            status_label.config(text="Bot is not running.", fg="red")
    except Exception as e:
        status_label.config(text=f"Error: {str(e)}", fg="red", wraplength=400)

# Function to restart the bot
def restart_bot(status_label):
    username = get_global_username()
    display_name = get_global_display_name()
    twitchUserId = get_global_user_id()
    authToken = get_global_auth_token()
    webhookPort = get_webhook_port()

    if not display_name:
        status_label.config(text="User is not authenticated.", fg="red")
        return

    if not is_user_authorized(display_name):
        status_label.config(text=f"{display_name} is not authorized to access this application.", fg="red")
        return

    try:
        remote_status_command = f"{STATUS_COMMAND_TEMPLATE} -channel {username}"
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
        stdin, stdout, stderr = ssh.exec_command(remote_status_command)
        status_output = stdout.read().decode("utf-8")
        ssh.close()

        if "Bot is running with process ID" in status_output:
            try:
                remote_kill_command = f"kill {status_output.split(':')[-1].strip()} > /dev/null 2>&1 &"
                ssh = paramiko.SSHClient()
                ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
                ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
                ssh.exec_command(remote_kill_command)
                time.sleep(3)
                stdin, stdout, stderr = ssh.exec_command(remote_status_command)
                new_status_output = stdout.read().decode("utf-8")
                ssh.close()

                if "Bot is running with process ID" not in new_status_output:
                    remote_start_command = f"{BOT_COMMAND_TEMPLATE} -channel {username} -channelid {twitchUserId} -token {authToken} -port {webhookPort} > /dev/null 2>&1 &"
                    ssh = paramiko.SSHClient()
                    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
                    ssh.connect(REMOTE_SSH_HOST, int(REMOTE_SSH_PORT), REMOTE_SSH_USERNAME, REMOTE_SSH_PASSWORD)
                    ssh.exec_command(remote_start_command)
                    time.sleep(3)
                    stdin, stdout, stderr = ssh.exec_command(remote_status_command)

                    run_status = stdout.read().decode("utf-8")
                    ssh.close()

                    pid_start_index = run_status.find(":") + 1
                    pid = run_status[pid_start_index:].strip()
                    print(f"Bot successfully restarted. Process ID: {pid}")
                    status_label.config(text=f"Bot successfully restarted. Process ID: {pid}", fg="blue")
                else:
                    status_label.config(text="Failed to stop the bot. Can't restart.", fg="red")
            except Exception as e:
                status_label.config(text=f"Error: {str(e)}", fg="red", wraplength=400)
        else:
            status_label.config(text="Bot is not running. Can't restart.", fg="red")
    except Exception as e:
        status_label.config(text=f"Error: {str(e)}", fg="red", wraplength=400)

# Is ther user authorized to use this app
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
            # print(f"{display_name} is authorized to access this application.")
            return True
        else:
            # print(f"{display_name} is not authorized to access this application.")
            return False
    else:
        # Handle the error or return False
        print(f"Error fetching authorized users: {response.status_code}")
        return False
    
# Get logs from the server
def fetch_and_show_logs(log_type, text_area):
    display_name = get_global_display_name()
    username = get_global_username()

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
    elif log_type =='api':
        logs = get_api_logs()
    else:
        logs = "Invalid log type"

    print(f"Fetching {log_type} logs for user {username}")
    # print(logs)

    # Insert the logs into the text_area widget
    text_area.delete(1.0, tk.END)  # Clear the existing text
    text_area.insert(tk.END, logs)  # Insert the fetched logs

    return logs

def get_bot_logs():
    username = get_global_username()
    url = f"{LOGS_SERVER_URL}/bot/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_chat_logs():
    username = get_global_username()
    url = f"{LOGS_SERVER_URL}/chat/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_twitch_logs():
    username = get_global_username()
    url = f"{LOGS_SERVER_URL}/twitch/{username}.txt"
    response = requests.get(url)
    return handle_response(response)

def get_api_logs():
    username = get_global_username()
    url = f"{LOGS_SERVER_URL}/api/{username}.txt"
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

# Function to fetch counters from the SQLite database using SSH
def fetch_counters_from_db(counter_type):
    conn = None  # Initialize the connection variable
    try:
        # Establish SSH connection
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(REMOTE_SSH_HOST, port=REMOTE_SSH_PORT, username=REMOTE_SSH_USERNAME, password=REMOTE_SSH_PASSWORD)
        
        # Get the username
        username = get_global_username()

        # Path to the SQLite database file on the server
        db_file_path = f"/var/www/bot/commands/{username}.db"
        
        # Path to save the SQLite database file locally
        appdata_path = os.getenv('APPDATA')
        db_directory = os.path.join(appdata_path, 'BotOfTheSpecter')
        os.makedirs(db_directory, exist_ok=True)
        local_db_file_path = os.path.join(db_directory, f"{username}.db")

        # Download the database file
        sftp = ssh.open_sftp()
        sftp.get(db_file_path, local_db_file_path)
        sftp.close()

        # Connect to the downloaded database file
        conn = sqlite3.connect(local_db_file_path)
        cursor = conn.cursor()

        # Depending on the counter_type, fetch the corresponding data
        if counter_type == "Typo Counts":
            cursor.execute("SELECT * FROM user_typos ORDER BY typo_count DESC")
            typos = cursor.fetchall()
            return typos
        elif counter_type == "Currently Lurking Users":
            cursor.execute("SELECT user_id, start_time FROM lurk_times")
            lurkers = cursor.fetchall()
            return lurkers
        elif counter_type == "Death Counts":
            cursor.execute("SELECT game_name, death_count FROM game_deaths")
            game_deaths = cursor.fetchall()
            return game_deaths
        elif counter_type == "Hug Counts":
            cursor.execute("SELECT username, hug_count FROM hug_counts")
            hug_counts = cursor.fetchall()
            return hug_counts
        elif counter_type == "Kiss Counts":
            cursor.execute("SELECT username, kiss_count FROM kiss_counts")
            kiss_counts = cursor.fetchall()
            return kiss_counts
        else:
            return None

    except Exception as e:
        print("Error fetching counters from the SQLite database:", e)
        return None
    finally:
        # Close connections and clean up resources
        if conn:
            conn.close()
        if ssh:
            ssh.close()

# Function to fetch counters and display in the counter_text_area
def fetch_and_display_counters(counter_type):
    headings = get_table_headings(counter_type)
    counters = fetch_counters_from_db(counter_type)  # Fetch counters based on counter_type
    
    # Clear existing data in the treeview
    for item in counter_tree.get_children():
        counter_tree.delete(item)
    
    # Update the counter type label to reflect the loading message after a short delay
    counter_type_label.config(text=f"Loading {counter_type}...")
    window.update()  # Update the GUI to ensure label change is visible
    
    # Schedule a function to update the label and process the data after a delay
    window.after(500, process_counters, counter_type, counters, headings)

# Function to process counters and update UI
def process_counters(counter_type, counters, headings):
    if counters is not None:
        if counter_type == "Currently Lurking Users":
            processed_counters = []
            for user_id, start_time in counters:
                username = get_username_from_user_id(user_id)
                duration = calculate_duration(start_time)
                processed_counters.append((username, duration))
            counters = processed_counters
        
        # Insert counter data into the treeview
        for counter in counters:
            counter_tree.insert('', 'end', values=counter)
        
        # Update table headings
        counter_tree['columns'] = headings
        for col in headings:
            counter_tree.heading(col, text=col, anchor="w")
        
        # Resize columns to fit content
        for col in headings:
            counter_tree.column(col, width=100)
            counter_tree.column(col, anchor="w")
        
        # Update the counter type label to indicate viewing after loading
        counter_type_label.config(text=f"Viewing {counter_type}")
    else:
        counter_tree.insert('', 'end', values=[f"No data available for {counter_type}"])

# Function to get table headings based on counter type
def get_table_headings(counter_type):
    if counter_type == "Currently Lurking Users":
        return ['Username', 'Lurk Duration']
    elif counter_type == "Typo Counts":
        return ['Username', 'Typo Count']
    elif counter_type == "Death Counts":
        return ['Category', 'Count']
    elif counter_type == "Hug Counts":
        return ['Username', 'Hug Count']
    elif counter_type == "Kiss Counts":
        return ['Username', 'Kiss Count']
    else:
        return ['Total', 'Count']

# Function to convert Twitch user ID to username
def get_username_from_user_id(user_id):
    AuthToken = twitch_auth.global_auth_token
    ClientID = twitch_auth.CLIENT_ID
    
    # Construct the URL with user ID
    url = f"https://api.twitch.tv/helix/users?id={user_id}"
    
    # Set headers
    headers = {
        'Client-ID': ClientID,
        'Authorization': 'Bearer ' + AuthToken
    }
    
    # Make request to Twitch API
    response = requests.get(url, headers=headers)
    
    # Check if the request was successful
    if response.status_code == 200:
        data = response.json()
        if 'data' in data and data['data']:
            return data['data'][0]['display_name']
    else:
        print("API Request failed with status code:", response.status_code)
    return None

# Function to calculate duration
def calculate_duration(start_time):
    start_datetime = datetime.datetime.fromisoformat(start_time)
    duration = datetime.datetime.now() - start_datetime
    
    # Calculate total number of days, hours, and minutes
    total_days = duration.days
    total_hours, remaining_minutes = divmod(duration.seconds, 3600)
    total_minutes, _ = divmod(remaining_minutes, 60)

    # Construct the duration string based on the duration
    if total_days >= 30:
        total_months = total_days // 30
        days_remaining = total_days % 30
        if total_months == 1:
            month_string = f"{total_months} month"
        else:
            month_string = f"{total_months} months"
        if days_remaining == 1:
            day_string = f"{days_remaining} day"
        else:
            day_string = f"{days_remaining} days"
        return f"{month_string}, {day_string}, {total_hours} hours, {total_minutes} minutes"
    elif total_days > 0:
        if total_days == 1:
            return f"{total_days} day, {total_hours} hours, {total_minutes} minutes"
        else:
            return f"{total_days} days, {total_hours} hours, {total_minutes} minutes"
    elif total_hours > 0:
        if total_hours == 1:
            return f"{total_hours} hour, {total_minutes} minutes"
        else:
            return f"{total_hours} hours, {total_minutes} minutes"
    elif total_minutes > 1:
        return f"{total_minutes} minutes"
    else:
        return "Just now"