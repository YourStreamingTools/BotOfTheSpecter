import psutil
import os

# Define the script names for stable and beta
stable_script = "bot.py"
beta_script = "beta.py"

# Dictionaries to hold running bots
stable_bots = []
beta_bots = []

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
            stable_bots.append((channel, process.info['pid']))
    # If it's a beta bot, find the channel
    if script_match_beta:
        channel_index = -1
        if "-channel" in cmdline:
            channel_index = cmdline.index("-channel")
        if channel_index >= 0 and channel_index + 1 < len(cmdline):
            channel = cmdline[channel_index + 1]
            beta_bots.append((channel, process.info['pid']))

# Print results
print("Stable bots running:")
if stable_bots:
    for channel, pid in stable_bots:
        print(f"- Channel: {channel}, PID: {pid}")
    print(f"Total: {len(stable_bots)}")
else:
    print("None")

print("\nBeta bots running:")
if beta_bots:
    for channel, pid in beta_bots:
        print(f"- Channel: {channel}, PID: {pid}")
    print(f"Total: {len(beta_bots)}")
else:
    print("None")
