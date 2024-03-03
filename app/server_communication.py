import time
import tkinter as tk
import requests
import paramiko
import twitch_auth
import mysql.connector
from decouple import config

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