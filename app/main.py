import tkinter as tk
from tkinter import ttk
import threading
import server_communication
import twitch_auth

def get_global_username():
    return twitch_auth.global_username

def fetch_and_show_logs(log_type):
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

window = tk.Tk()
window.title("BotOfTheSpecter")

tab_control = ttk.Notebook(window)
logs_tab = ttk.Frame(tab_control)
tab_control.add(logs_tab, text='Logs')
tab_control.pack(expand=1, fill='both')

# Frame for holding the buttons
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

threading.Thread(target=twitch_auth.start_auth, daemon=True).start()
window.mainloop()