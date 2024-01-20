import os
import tkinter as tk
from tkinter import ttk, messagebox
import requests
import webbrowser
import threading
from server_communication import is_user_authorized, check_bot_status, \
                            run_bot, stop_bot, restart_bot, fetch_and_show_logs
import twitch_auth
import logging
import paramiko
from decouple import config

threading.Thread(target=twitch_auth.start_auth, daemon=True).start()

appdata_dir = os.path.join(os.getenv('APPDATA'), 'BotOfTheSpecter', 'logs')
os.makedirs(appdata_dir, exist_ok=True)
log_file_path = os.path.join(appdata_dir, 'main.log')

# Get variables
REMOTE_VERSION_URL="https://api.botofthespecter.com/version_control.txt"
VERSION="1.6.1"
    
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