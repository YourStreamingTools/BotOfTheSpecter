import psutil
import argparse
import os

# Parse command-line arguments
parser = argparse.ArgumentParser(description="Check if a bot process is running for a specific channel username and system")
parser.add_argument(
    "-system",
    dest="system",
    required=True,
    choices=["stable", "alpha", "beta", "custom"],
    help="System to check (stable, alpha, beta, custom)"
)
parser.add_argument("-channel", dest="channel_username", required=True, help="Channel username to check")
args = parser.parse_args()

channel_username = args.channel_username.lower()

# Map system to script names
script_map = {
    "stable": "bot.py",
    "alpha": "alpha.py",
    "beta": "beta.py",
    "custom": "custom.py"
}

script_name = script_map[args.system]

# Iterate through processes to find a match
for process in psutil.process_iter(attrs=['pid', 'name', 'cmdline']):
    try:
        cmdline = [arg.lower() for arg in (process.info['cmdline'] or [])]
        if not cmdline:
            continue
        # Check if script matches
        script_match = any(os.path.basename(arg) == script_name for arg in cmdline if arg.endswith(script_name))
        # Check if channel matches
        channel_match = False
        try:
            channel_index = cmdline.index("-channel")
            if channel_index + 1 < len(cmdline) and cmdline[channel_index + 1] == channel_username:
                channel_match = True
        except ValueError:
            pass  # -channel not found
        if script_match and channel_match:
            print(f"Bot is running with process ID: {process.info['pid']}")
            break
    except (psutil.AccessDenied, psutil.NoSuchProcess):
        continue  # Skip processes we can't access
else:
    print("Bot not running")
