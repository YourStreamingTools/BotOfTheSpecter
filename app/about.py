import tkinter as tk
from icon import icon_path
import updates

def show_about_message():
    about_window = tk.Toplevel()
    about_window.title("About")
    about_window.iconbitmap(f"{icon_path}")
    about_window.geometry("500x350")

    about_label = tk.Label(about_window, text="BotOfTheSpecter", font=("Arial", 16))
    about_label.pack(pady=10)

    version_label = tk.Label(about_window, text="Version: " + updates.VERSION, font=("Arial", 12))
    version_label.pack(pady=5)

    description_text = (
        "This application allows you to view everything about the bot without the need to log into our website.\n\n"
        "You can check if the bot is currently running using the 'Check Bot Status' button.\n"
        "You can run the bot from this app if the bot isn't running by using the 'Run Bot' Button.\n"
        "You can stop the bot if with wish the bot to stop working.\n"
        "You can restart the bot if you need the bot to restart or to get the new version of the bot running."
    )
    
    description_message = tk.Message(about_window, text=description_text, font=("Arial", 12), width=400)
    description_message.pack(pady=10)

    close_button = tk.Button(about_window, text="Close", command=about_window.destroy)
    close_button.pack(pady=10)