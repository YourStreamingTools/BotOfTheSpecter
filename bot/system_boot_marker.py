#!/usr/bin/env python3
import argparse
import os
from datetime import datetime, timezone

def main():
    parser = argparse.ArgumentParser(description="Write a boot marker file for uptime reporting")
    parser.add_argument("--name", choices=["web1", "sql", "bots", "custom"], default="custom", help="Server marker name")
    parser.add_argument("--path", default="", help="Marker file path (required when --name custom)")
    args = parser.parse_args()
    default_paths = {
        "web1": "/home/botofthespecter/web1_uptime",
        "sql": "/home/botofthespecter/sql_uptime",
        "bots": "/home/botofthespecter/bots_uptime",
    }
    if args.name == "custom":
        if not args.path:
            raise ValueError("--path is required when --name custom")
        marker_path = args.path
    else:
        marker_path = default_paths[args.name]
    marker_dir = os.path.dirname(marker_path)
    if marker_dir:
        os.makedirs(marker_dir, exist_ok=True)
    timestamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
    with open(marker_path, "w", encoding="utf-8") as marker_file:
        marker_file.write(timestamp)
    os.utime(marker_path, None)
    print(f"Boot marker written: {marker_path}")

if __name__ == "__main__":
    main()