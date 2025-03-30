#!/bin/bash
echo "Installing system dependencies..."
sudo apt update
sudo apt install -y python3 python3-pip ffmpeg
echo "Installing Python dependencies..."
pip3 install -r requirements.txt --break-system-packages
echo "Setup complete. You can now run the RTMP server."