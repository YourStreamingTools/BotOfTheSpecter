import twitch_auth
import server_communication
import tkinter as tk
from tkinter import ttk

username = twitch_auth.auth()
def fetch_logs(log_type, username):
    if log_type == 'bot':
        return server_communication.get_bot_logs(username)
    elif log_type == 'chat':
        return server_communication.get_chat_logs(username)
    elif log_type == 'twitch':
        return server_communication.get_twitch_logs(username)
    return "Invalid log type"

def show_logs():
    log_type = log_type_var.get()
    logs = fetch_logs(log_type, username)
    text_area.delete(1.0, tk.END)
    text_area.insert(tk.END, logs)

# Tkinter window setup
window = tk.Tk()
window.title("BotOfTheSpecter")

# Tab setup
tab_control = ttk.Notebook(window)
logs_tab = ttk.Frame(tab_control)
tab_control.add(logs_tab, text='Logs')
tab_control.pack(expand=1, fill='both')

# Log type selection
log_type_var = tk.StringVar()
log_type_var.set('bot')  # default value
options = ['bot', 'chat', 'twitch']
log_type_menu = tk.OptionMenu(logs_tab, log_type_var, *options)
log_type_menu.pack()

# Button to fetch logs
fetch_button = tk.Button(logs_tab, text="Fetch Logs", command=show_logs)
fetch_button.pack()

# Text area for logs
text_area = tk.Text(logs_tab)
text_area.pack(expand=1, fill='both')

# Start the GUI
window.mainloop()