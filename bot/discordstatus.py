import psutil
import argparse

# Parse command-line arguments
parser = argparse.ArgumentParser(description="Check if a bot process is running for a specific channel username")
parser.add_argument("-channel", dest="channel_username", required=True, help="Channel username to check")
args = parser.parse_args()

# Check if a process is running for the specified channel username
channel_username = args.channel_username.lower()  # Convert to lowercase for consistent comparison

# Define the expected command line for both bot versions
expected_command_v1 = f"python /var/www/bot/discordbot.py -channel {channel_username}"
expected_command_v2 = f"python discordbot.py -channel {channel_username}"

# Iterate through all running processes
for process in psutil.process_iter(attrs=['pid', 'name', 'cmdline']):
    process_cmdline = ' '.join(process.info['cmdline']).lower() if process.info['cmdline'] else ""
    if expected_command_v1 in process_cmdline or expected_command_v2 in process_cmdline:
        print(f"Bot is running with process ID: {process.info['pid']}")
        break
else:
    print(f"Bot not running")
