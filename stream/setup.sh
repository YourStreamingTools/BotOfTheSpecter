#!/bin/bash
echo "Installing system dependencies..."
sudo apt update
sudo apt install -y python3 python3-pip ffmpeg
echo "Installing Python dependencies..."
pip3 install -r requirements.txt --break-system-packages
echo "Installing MediaMTX (phone camera relay)..."
MEDIAMTX_VERSION="v1.21.2"
# STREAM_SSL_DOMAIN is this region's cert domain, e.g. syd1.stream.botofthespecter.com
STREAM_SSL_DOMAIN="${STREAM_SSL_DOMAIN:-syd1.stream.botofthespecter.com}"
curl -fsSL -o /tmp/mediamtx.tar.gz "https://github.com/bluenviron/mediamtx/releases/download/${MEDIAMTX_VERSION}/mediamtx_${MEDIAMTX_VERSION}_linux_amd64.tar.gz"
sudo tar -xzf /tmp/mediamtx.tar.gz -C /usr/local/bin mediamtx
sed "s/STREAM_SSL_DOMAIN/${STREAM_SSL_DOMAIN}/g" mediamtx.yml | sudo tee /opt/specter-stream/mediamtx.yml > /dev/null
sudo cp mediamtx.service /etc/systemd/system/mediamtx.service
sudo systemctl daemon-reload
sudo systemctl enable --now mediamtx
echo "Open these ports for camera viewers: 8890/udp (SRT), 8889/tcp and 8189/udp (WebRTC)."
echo "Setup complete. You can now run the RTMP server."