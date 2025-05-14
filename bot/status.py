import psutil
import argparse
import os

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

for process in psutil.process_iter(attrs=['pid', 'name', 'cmdline']):
    cmdline = [arg.lower() for arg in (process.info['cmdline'] or [])]
    if not cmdline:
        continue
    script_match = False
    for arg in cmdline:
        if arg.endswith(script_name):
            base_name = os.path.basename(arg)
            if base_name == script_name:
                script_match = True
                break
    channel_index = -1
    if "-channel" in cmdline:
        channel_index = cmdline.index("-channel")
    channel_value_match = False
    if channel_index >= 0 and channel_index + 1 < len(cmdline):
        if cmdline[channel_index + 1] == channel_username:
            channel_value_match = True
    if script_match and channel_value_match:
        print(f"Bot is running with process ID: {process.info['pid']}")
        break
else:
    print(f"Bot not running")
