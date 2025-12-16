import psutil
import os

# Define the script names for stable and beta
stable_script = "bot.py"
beta_script = "beta.py"
custom_script = "custom.py"

# Function to get version from version control files
def get_version(script_type, channel):
    if script_type == 'stable':
        file_path = f"/home/botofthespecter/logs/version/{channel}_version_control.txt"
    elif script_type == 'beta':
        file_path = f"/home/botofthespecter/logs/version/beta/{channel}_beta_version_control.txt"
    elif script_type == 'custom':
        file_path = f"/home/botofthespecter/logs/version/custom/{channel}_custom_version_control.txt"
    else:
        return 'Unknown'
    try:
        with open(file_path, 'r') as f:
            return f.read().strip()
    except:
        return 'Unknown'

# Dictionaries to hold running bots
stable_bots = []
beta_bots = []
custom_bots = []

# Iterate through all processes
for process in psutil.process_iter(attrs=['pid', 'name', 'cmdline']):
    cmdline = [arg.lower() for arg in (process.info['cmdline'] or [])]
    if not cmdline:
        continue
    # Check for stable bot (bot.py)
    script_match_stable = False
    for arg in cmdline:
        if arg.endswith(stable_script):
            base_name = os.path.basename(arg)
            if base_name == stable_script:
                script_match_stable = True
                break
    # Check for beta bot (beta.py)
    script_match_beta = False
    for arg in cmdline:
        if arg.endswith(beta_script):
            base_name = os.path.basename(arg)
            if base_name == beta_script:
                script_match_beta = True
                break
    # If it's a stable bot, find the channel
    if script_match_stable:
        channel_index = -1
        if "-channel" in cmdline:
            channel_index = cmdline.index("-channel")
        if channel_index >= 0 and channel_index + 1 < len(cmdline):
            channel = cmdline[channel_index + 1]
            version = get_version('stable', channel)
            stable_bots.append((channel, process.info['pid'], version))
    # If it's a beta bot, find the channel
    if script_match_beta:
        channel_index = -1
        if "-channel" in cmdline:
            channel_index = cmdline.index("-channel")
        if channel_index >= 0 and channel_index + 1 < len(cmdline):
            channel = cmdline[channel_index + 1]
            version = get_version('beta', channel)
            beta_bots.append((channel, process.info['pid'], version))
    # If it's a custom bot, find the channel (custom.py)
    if custom_script:
        script_match_custom = False
        for arg in cmdline:
            if arg.endswith(custom_script):
                base_name = os.path.basename(arg)
                if base_name == custom_script:
                    script_match_custom = True
                    break
        if script_match_custom:
            channel_index = -1
            if "-channel" in cmdline:
                channel_index = cmdline.index("-channel")
            if channel_index >= 0 and channel_index + 1 < len(cmdline):
                channel = cmdline[channel_index + 1]
                version = get_version('custom', channel)
                custom_bots.append((channel, process.info['pid'], version))

# Print results
print("Stable bots running:")
if stable_bots:
    for channel, pid, version in stable_bots:
        print(f"- Channel: {channel}, PID: {pid}, Version: {version}")
    print(f"Total: {len(stable_bots)}")
else:
    print("None")

print("\nBeta bots running:")
if beta_bots:
    for channel, pid, version in beta_bots:
        print(f"- Channel: {channel}, PID: {pid}, Version: {version}")
    print(f"Total: {len(beta_bots)}")
else:
    print("None")

print("\nCustom bots running:")
if custom_bots:
    for channel, pid, version in custom_bots:
        print(f"- Channel: {channel}, PID: {pid}, Version: {version}")
    print(f"Total: {len(custom_bots)}")
else:
    print("None")
