import twitch_auth
import server_communication

def main():
    username = twitch_auth.auth()

    if username:
        bot_logs = server_communication.get_bot_logs(username)
        chat_logs = server_communication.get_chat_logs(username)
        twitch_logs = server_communication.get_twitch_logs(username)

        # Display the logs
        print("Bot Logs:", bot_logs)
        print("Chat Logs:", chat_logs)
        print("Twitch Logs:", twitch_logs)
    else:
        print("Login failed or was cancelled.")

if __name__ == "__main__":
    main()
