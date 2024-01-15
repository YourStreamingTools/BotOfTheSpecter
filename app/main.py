import tkinter as tk
from tkinter import ttk
import threading
import server_communication
import twitch_auth

def fetch_logs(log_type, username):
    if log_type == 'bot':
        return server_communication.get_bot_logs(username)
    elif log_type == 'chat':
        return server_communication.get_chat_logs(username)
    elif log_type == 'twitch':
        return server_communication.get_twitch_logs(username)
    return "Invalid log type"

def show_logs():
    logs = fetch_logs(log_type_var.get(), "username")  # Replace "username" with actual username
    text_area.delete(1.0, tk.END)
    text_area.insert(tk.END, logs)

window = tk.Tk()
window.title("BotOfTheSpecter")

tab_control = ttk.Notebook(window)
logs_tab = ttk.Frame(tab_control)
tab_control.add(logs_tab, text='Logs')
tab_control.pack(expand=1, fill='both')

log_type_var = tk.StringVar(value='bot')
options = ['bot', 'chat', 'twitch']
log_type_menu = tk.OptionMenu(logs_tab, log_type_var, *options)
log_type_menu.pack()

fetch_button = tk.Button(logs_tab, text="Fetch Logs", command=show_logs)
fetch_button.pack()

text_area = tk.Text(logs_tab)
text_area.pack(expand=1, fill='both')

threading.Thread(target=twitch_auth.start_auth, daemon=True).start()
window.mainloop()