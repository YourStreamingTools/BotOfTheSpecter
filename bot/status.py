import psutil
import argparse

# Parse command-line arguments
parser = argparse.ArgumentParser(description="Check if a bot process is running for a specific channel username and system")
parser.add_argument(
    "-system",
    dest="system",
    required=True,
    choices=["stable", "alpha", "beta", "discord"],
    help="System to check (stable, alpha, beta, discord)"
)
parser.add_argument("-channel", dest="channel_username", required=True, help="Channel username to check")
args = parser.parse_args()

channel_username = args.channel_username.lower()

# Map system to script names
script_map = {
    "stable": "bot.py",
    "alpha": "alpha.py",
    "beta": "beta.py",
    "discord": "discordbot.py"
}

script_name = script_map[args.system]
channel_arg = f"-channel {channel_username}"

for process in psutil.process_iter(attrs=['pid', 'name', 'cmdline']):
    process_cmdline = ' '.join(process.info['cmdline']).lower() if process.info['cmdline'] else ""
    if script_name in process_cmdline and channel_arg in process_cmdline:
        print(f"Bot is running with process ID: {process.info['pid']}")
        break
else:
    print(f"Bot not running")
