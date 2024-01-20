import requests
import webbrowser
import tkinter as tk
from tkinter import messagebox
from decouple import config

REMOTE_VERSION_URL = "https://api.botofthespecter.com/version_control.txt"
VERSION = "1.7"

def custom_messagebox(title, message, buttons):
    result = None

    top = tk.Toplevel()
    top.title(title)
    top.geometry("400x150")
    top.grab_set()

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

if __name__ == "__main__":
    check_for_updates(user_initiated=True)
