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
requested_system = args.system

# Helper to extract channel value from cmdline
def extract_channel(cmdline, channel_username):
    # Exact flag format: -channel <username> or -channel=<username>
    for i, arg in enumerate(cmdline):
        if arg in ("-channel", "--channel"):
            if i + 1 < len(cmdline) and cmdline[i + 1] == channel_username:
                return True
        if arg.startswith("-channel=") or arg.startswith("--channel="):
            parts = arg.split("=", 1)
            if len(parts) == 2 and parts[1] == channel_username:
                return True
    return False

# Iterate through processes to find a match
for process in psutil.process_iter(attrs=['pid', 'name', 'cmdline']):
    try:
        raw_cmdline = process.info.get('cmdline') or []
        cmdline = [str(arg).lower() for arg in raw_cmdline]
        if not cmdline:
            continue
        # Detect the invoked script name (first .py filename in the cmdline)
        script_name = None
        for arg in cmdline:
            if arg.endswith('.py') or '.py' in arg:
                base = os.path.basename(arg)
                if base:
                    script_name = base.lower()
                    break
        is_custom = any(a in ('-custom', '--custom') for a in cmdline)
        # If channel doesn't match, skip
        if not extract_channel(cmdline, channel_username):
            continue
        # System matching logic with clear priority
        match = False
        if requested_system == 'custom':
            # custom mode must include the custom flag and be one of the supported scripts
            if is_custom and script_name in ('bot.py', 'beta.py', 'alpha.py'):
                match = True
        elif requested_system == 'stable':
            if script_name == 'bot.py' and not is_custom:
                match = True
        elif requested_system == 'beta':
            if script_name == 'beta.py' and not is_custom:
                match = True
        elif requested_system == 'alpha':
            if script_name == 'alpha.py' and not is_custom:
                match = True
        if match:
            print(f"Bot is running with process ID: {process.info['pid']}")
            break
    except (psutil.AccessDenied, psutil.NoSuchProcess):
        continue  # Skip processes we can't access
else:
    print("Bot not running")