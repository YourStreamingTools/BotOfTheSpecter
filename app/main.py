# Main Imports
import os
import tkinter as tk
from tkinter import ttk, messagebox
import requests
import webbrowser
import threading
import threading
import logging
import paramiko
from decouple import config
# App Imports
from server_communication import check_bot_status, run_bot, stop_bot, restart_bot, fetch_and_show_logs
import twitch_auth
import updates
import about

def exit_app():
    window.destroy()

# Threading for app
threading.Thread(target=twitch_auth.start_auth, daemon=True).start()
threading.Thread(target=updates.check_for_updates, daemon=True).start()

appdata_dir = os.path.join(os.getenv('APPDATA'), 'BotOfTheSpecter', 'logs')
os.makedirs(appdata_dir, exist_ok=True)
log_file_path = os.path.join(appdata_dir, 'main.log')

window = tk.Tk()
window.title(f"BotOfTheSpecter V{updates.VERSION}")
tab_control = ttk.Notebook(window)

# Create a "File" & "Help" menu. Create Options, "About", "Exit" & "Check for Updates"
menu_bar = tk.Menu(window)
window.config(menu=menu_bar)
file_menu = tk.Menu(menu_bar, tearoff=0)
menu_bar.add_cascade(label="File", menu=file_menu)
file_menu.add_command(label="About", command=about.show_about_message)
file_menu.add_command(label="Exit", command=exit_app)
help_menu = tk.Menu(menu_bar, tearoff=0)
menu_bar.add_cascade(label="Help", menu=help_menu)
help_menu.add_command(label="Check for Updates", command=updates.check_for_updates)

# Create a "Bot" tab
bot_tab = ttk.Frame(tab_control)
tab_control.add(bot_tab, text='Bot')

# Frame for holding buttons and status in the "Bot" tab
bot_tab_frame = tk.Frame(bot_tab)
bot_tab_frame.pack(pady=5)

# Create buttons for the "Bot" tab with similar style to the "Logs" tab buttons
run_button = tk.Button(bot_tab_frame, text="Run Bot", command=lambda: run_bot(status_label))
run_button.pack(side=tk.LEFT, padx=5, pady=5)

check_status_button = tk.Button(bot_tab_frame, text="Check Bot Status", command=lambda: check_bot_status(status_label))
check_status_button.pack(side=tk.LEFT, padx=5, pady=5)

stop_button = tk.Button(bot_tab_frame, text="Stop Bot", command=lambda: stop_bot(status_label))
stop_button.pack(side=tk.LEFT, padx=5, pady=5)

restart_button = tk.Button(bot_tab_frame, text="Restart Bot", command=lambda: restart_bot(status_label))
restart_button.pack(side=tk.LEFT, padx=5, pady=5)

# Create a Label widget to display status
status_label = tk.Label(bot_tab, text="", fg="blue", font=("Arial", 14))
status_label.pack(pady=5)

# Create a "Logs" tab
logs_tab = ttk.Frame(tab_control)
tab_control.add(logs_tab, text='Logs')
tab_control.pack(expand=1, fill='both')

# Frame for holding the buttons in the "Logs" tab
buttons_frame = tk.Frame(logs_tab)
buttons_frame.pack(side=tk.TOP, pady=5)

# Creating individual buttons for each log type and packing them side by side
bot_logs_button = tk.Button(buttons_frame, text="Bot Logs", command=lambda: fetch_and_show_logs('bot', text_area))
bot_logs_button.pack(side=tk.LEFT, padx=5, pady=5)

chat_logs_button = tk.Button(buttons_frame, text="Chat Logs", command=lambda: fetch_and_show_logs('chat', text_area))
chat_logs_button.pack(side=tk.LEFT, padx=5, pady=5)

twitch_logs_button = tk.Button(buttons_frame, text="Twitch Logs", command=lambda: fetch_and_show_logs('twitch', text_area))
twitch_logs_button.pack(side=tk.LEFT, padx=5, pady=5)

# Create a Text widget for displaying logs
text_area = tk.Text(logs_tab)
text_area.pack(expand=1, fill='both')

# Label to display authentication status
auth_status_label = tk.Label(logs_tab, text="", fg="red")
auth_status_label.pack(pady=5)

window.mainloop()