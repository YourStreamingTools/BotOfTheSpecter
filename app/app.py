# Main Imports
import os
import tkinter as tk
from tkinter import ttk
import threading
import datetime

# App Imports
from server_communication import check_bot_status, run_bot, stop_bot, restart_bot, fetch_and_show_logs, fetch_counters_from_db
import twitch_auth
import updates
import about
from icon import icon_path

# Function to exit the app
def exit_app():
    window.destroy()

# Threading for app
threading.Thread(target=twitch_auth.start_auth, daemon=True).start()
# threading.Thread(target=updates.check_for_updates, daemon=True).start()

appdata_dir = os.path.join(os.getenv('APPDATA'), 'BotOfTheSpecter', 'logs')
os.makedirs(appdata_dir, exist_ok=True)
log_file_path = os.path.join(appdata_dir, 'main.log')

window = tk.Tk()
window.title(f"BotOfTheSpecter V{updates.VERSION}")
window.iconbitmap(f"{icon_path}")
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

api_logs_button = tk.Button(buttons_frame, text="API Logs", command=lambda: fetch_and_show_logs('api', text_area))
api_logs_button.pack(side=tk.LEFT, padx=5, pady=5)

# Create a Text widget for displaying logs
text_area = tk.Text(logs_tab)
text_area.pack(expand=1, fill='both')

# Label to display authentication status
auth_status_label = tk.Label(logs_tab, text="", fg="red")
auth_status_label.pack(pady=5)

# Create a "Counters" tab
counters_tab = ttk.Frame(tab_control)
tab_control.add(counters_tab, text='Counters')

# Frame for holding the buttons in the "Counters" tab
counters_buttons_frame = tk.Frame(counters_tab)
counters_buttons_frame.pack(side=tk.TOP, pady=5)

# Create buttons for each counter type
counter_buttons = {}

counter_types = ["Currently Lurking Users", "Typo Counts", "Death Counts", "Hug Counts", "Kiss Counts"]
for counter_type in counter_types:
    counter_button = tk.Button(counters_buttons_frame, text=counter_type, command=lambda t=counter_type: fetch_and_display_counters(t))
    counter_button.pack(side=tk.LEFT, padx=5, pady=5)
    counter_buttons[counter_type] = counter_button

# Create a label to display the current counter type
counter_type_label = tk.Label(counters_tab, text="Not Viewing Anything", font=("Arial", 12))
counter_type_label.pack(pady=5, side=tk.TOP)

# Create the Treeview widget
counter_tree = ttk.Treeview(counters_tab, show='headings')
counter_tree.pack(expand=True, fill='both')

# Function to fetch counters and display in the counter_text_area
def fetch_and_display_counters(counter_type):
    headings = get_table_headings(counter_type)
    counters = fetch_counters_from_database(counter_type)  # Fetch counters based on counter_type
    
    # Clear existing data in the treeview
    for item in counter_tree.get_children():
        counter_tree.delete(item)
    
    # Process counters and update UI
    if counters is not None:
        if counter_type == "Currently Lurking Users":
            # Convert user IDs to usernames and durations to human-readable format
            processed_counters = []
            for user_id, start_time in counters:
                username = get_username_from_user_id(user_id)  # Function to get username from Twitch user ID
                duration = calculate_duration(start_time)  # Function to calculate duration
                processed_counters.append((username, duration))
            counters = processed_counters
        
        # Insert counter data into the treeview
        for counter in counters:
            counter_tree.insert('', 'end', values=counter)
        
        # Update table headings
        counter_tree['columns'] = headings
        for col in headings:
            counter_tree.heading(col, text=col)
        
        # Update counter type label
        get_table_headings(counter_type)
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

# Placeholder function to fetch counters from the database
def fetch_counters_from_database(counter_type):
    # Implement this function to fetch counters from the database
    # For now, let's return some dummy data for testing
    if counter_type == "Currently Lurking Users":
        return [("user_id_1", "2023-12-20T15:28:56.554343"), ("user_id_2", "2023-11-15T10:30:00.000000")]
    else:
        return []

# Placeholder function to convert Twitch user ID to username
def get_username_from_user_id(user_id):
    # Implement this function to fetch username from Twitch user ID
    # For now, let's return some dummy usernames for testing
    return f"Username {user_id}"

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

window.mainloop()