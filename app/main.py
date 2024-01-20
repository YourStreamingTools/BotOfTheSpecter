import os
import tkinter as tk
from tkinter import ttk, messagebox
import requests
import threading
import server_communication
import twitch_auth
import logging
import paramiko
import webbrowser
from decouple import config

threading.Thread(target=twitch_auth.start_auth, daemon=True).start()

appdata_dir = os.path.join(os.getenv('APPDATA'), 'BotOfTheSpecter', 'logs')
os.makedirs(appdata_dir, exist_ok=True)
log_file_path = os.path.join(appdata_dir, 'main.log')

# Get variables
REMOTE_SSH_HOST="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_PORT="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_USERNAME="" # CHANGE TO MAKE THIS WORK
REMOTE_SSH_PASSWORD="" # CHANGE TO MAKE THIS WORK
REMOTE_COMMAND_TEMPLATE="" # CHANGE TO MAKE THIS WORK
REMOTE_VERSION_URL="https://api.botofthespecter.com/version_control.txt"
VERSION="1.6.1"

# Function to run the bot
def run_bot():
    status_label.config(text="Bot can't be started form this app.")

# Function to check bot status
def check_bot_status():
    display_name = get_global_display_name()
    username = get_global_username()

    if not display_name:
        status_label.config(text="User is not authenticated.")
        return

    if not server_communication.is_user_authorized(display_name):
        status_label.config(text=f"{display_name} is not authorized to access this application.")
        return

    ssh_host = REMOTE_SSH_HOST
    ssh_port = REMOTE_SSH_PORT
    ssh_username = REMOTE_SSH_USERNAME
    ssh_password = REMOTE_SSH_PASSWORD
    remote_command_template = REMOTE_COMMAND_TEMPLATE

    remote_command = f"{remote_command_template} -channel {username}"

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        ssh.connect(ssh_host, int(ssh_port), ssh_username, ssh_password)

        stdin, stdout, stderr = ssh.exec_command(remote_command)

        output = stdout.read().decode("utf-8")
        error = stderr.read().decode("utf-8")

        if error:
            status_label.config(text=f"Error: {error}")
        else:
            status_label.config(text=output)

        ssh.close()
    except Exception as e:
        status_label.config(text=f"Error: {str(e)}")

# Function to stop the bot
def stop_bot():
    status_label.config(text="Bot can't be stopped form this app.")

# Function to restart the bot
def restart_bot():
    status_label.config(text="Bot can't be restarted form this app.")

def get_global_username():
    return twitch_auth.global_username
def get_global_display_name():
    return twitch_auth.global_display_name

def fetch_and_show_logs(log_type):
    display_name = get_global_display_name()

    if not display_name:
        text_area.delete(1.0, tk.END)
        text_area.insert(tk.END, "User is not authenticated.")
        return

    if not server_communication.is_user_authorized(display_name):
        text_area.delete(1.0, tk.END)
        text_area.insert(tk.END, f"{display_name} is not authorized to access this application.")
        return

    if log_type == 'bot':
        logs = server_communication.get_bot_logs()
    elif log_type == 'chat':
        logs = server_communication.get_chat_logs()
    elif log_type == 'twitch':
        logs = server_communication.get_twitch_logs()
    else:
        logs = "Invalid log type"

    text_area.delete(1.0, tk.END)
    text_area.insert(tk.END, logs)
    
# Function to check for updates
def custom_messagebox(title, message, buttons):
    result = None

    top = tk.Toplevel()
    top.title(title)
    top.geometry("400x150")  # Set the width and height as needed
    top.grab_set()  # Make the dialog modal

    message_label = tk.Label(top, text=message)
    message_label.pack(padx=20, pady=10)

    button_frame = tk.Frame(top)
    button_frame.pack(padx=20, pady=10)

    for label in buttons:
        button = tk.Button(button_frame, text=label, command=lambda l=label: set_result(l))
        button.pack(side=tk.LEFT, padx=10)

    def set_result(label):
        nonlocal result
        result = label
        top.destroy()

    top.wait_window()

    return result

def check_for_updates(user_initiated=False):
    try:
        response = requests.get(REMOTE_VERSION_URL)
        remote_version = response.text.strip()

        if remote_version != VERSION:
            message = f"A new update ({remote_version}) is available."
            button_clicked = custom_messagebox("Update Available", message, ["Download", "OK"])

            if button_clicked == "Download":
                webbrowser.open("https://dashboard.botofthespecter.com/app.php")
        elif user_initiated:
            messagebox.showinfo("No Updates", "No new updates available.")
    except Exception as e:
        messagebox.showerror("Error", f"Failed to check for updates: {str(e)}")

def check_updates():
    check_for_updates(user_initiated=True)

window = tk.Tk()
window.title(f"BotOfTheSpecter V{VERSION}")
tab_control = ttk.Notebook(window)

# Create a "Help" menu with "Check for Updates" option
check_for_updates()
menu_bar = tk.Menu(window)
window.config(menu=menu_bar)
help_menu = tk.Menu(menu_bar, tearoff=0)
menu_bar.add_cascade(label="Help", menu=help_menu)
help_menu.add_command(label="Check for Updates", command=check_updates)

# Create a "Bot" tab
bot_tab = ttk.Frame(tab_control)
tab_control.add(bot_tab, text='Bot')

# Frame for holding buttons and status in the "Bot" tab
bot_tab_frame = tk.Frame(bot_tab)
bot_tab_frame.pack(pady=5)

# Create buttons for the "Bot" tab with similar style to the "Logs" tab buttons
run_button = tk.Button(bot_tab_frame, text="Run Bot", command=run_bot)
run_button.pack(side=tk.LEFT, padx=5, pady=5)

check_status_button = tk.Button(bot_tab_frame, text="Check Bot Status", command=check_bot_status)
check_status_button.pack(side=tk.LEFT, padx=5, pady=5)

stop_button = tk.Button(bot_tab_frame, text="Stop Bot", command=stop_bot)
stop_button.pack(side=tk.LEFT, padx=5, pady=5)

restart_button = tk.Button(bot_tab_frame, text="Restart Bot", command=restart_bot)
restart_button.pack(side=tk.LEFT, padx=5, pady=5)

# Create a label to display status
status_label = tk.Label(bot_tab, text="", fg="blue", font=("Arial", 14))
status_label.pack(pady=5)

# Create a "Logs" tab
logs_tab = ttk.Frame(tab_control)
tab_control.add(logs_tab, text='Logs')
tab_control.pack(expand=1, fill='both')

# Frame for holding the buttons in the "Logs" tab
buttons_frame = tk.Frame(logs_tab)
buttons_frame.pack(pady=5)

# Creating individual buttons for each log type and packing them side by side
bot_button = tk.Button(buttons_frame, text="Bot Logs", command=lambda: fetch_and_show_logs('bot'))
bot_button.pack(side=tk.LEFT, padx=5, pady=5)

chat_button = tk.Button(buttons_frame, text="Chat Logs", command=lambda: fetch_and_show_logs('chat'))
chat_button.pack(side=tk.LEFT, padx=5, pady=5)

twitch_button = tk.Button(buttons_frame, text="Twitch Logs", command=lambda: fetch_and_show_logs('twitch'))
twitch_button.pack(side=tk.LEFT, padx=5, pady=5)

text_area = tk.Text(logs_tab)
text_area.pack(expand=1, fill='both')

# Label to display authentication status
auth_status_label = tk.Label(logs_tab, text="", fg="red")
auth_status_label.pack(pady=5)

window.mainloop()