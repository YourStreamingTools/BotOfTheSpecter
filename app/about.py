import tkinter as tk
from tkinter import messagebox
import updates

def show_about_message():
    about_window = tk.Toplevel()
    about_window.title("About")
    about_window.geometry("500x350")

    about_label = tk.Label(about_window, text="BotOfTheSpecter", font=("Arial", 16))
    about_label.pack(pady=10)

    version_label = tk.Label(about_window, text="Version: " + updates.VERSION, font=("Arial", 12))
    version_label.pack(pady=5)

    description_text = (
        "This application allows you to view bot logs without the need to log into the website.\n\n"
        "You can also check if the bot is currently running using the 'Check Bot Status' button.\n\n"
        "This executable is solely for viewing bot logs and checking the status of the bot if it's currently running."
    )
    
    description_label = tk.Label(about_window, text=description_text, font=("Arial", 12), justify="left")
    description_label.pack(pady=10)

    close_button = tk.Button(about_window, text="Close", command=about_window.destroy)
    close_button.pack(pady=10)