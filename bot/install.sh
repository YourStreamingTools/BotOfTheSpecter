#!/usr/bin/env bash
# Host bootstrap helpers for the bot server.
# Prefer multi-venv layout via setup_venvs.sh (required for new servers).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

if [[ "${1:-}" == "--system-packages" ]]; then
  apt update
  apt-get install -y python3 python3-pip python3-venv python-is-python3
  shift
fi

if [[ ! -x setup_venvs.sh ]]; then
  chmod +x setup_venvs.sh
fi

echo "Creating/updating bot virtualenvs (stable, beta, v6, discord, kick)..."
./setup_venvs.sh "$@"

# Private bots control API deps (into stable venv — has psutil + used by bots-api.service)
if [[ -f bots_api/requirements.txt && -x venvs/stable/bin/pip ]]; then
  echo "Installing bots control API requirements into venvs/stable..."
  venvs/stable/bin/pip install -r bots_api/requirements.txt
fi

echo
echo "Do NOT pip install -r requirements.txt into system Python on the bot host."
echo "Always use: venvs/<name>/bin/pip install -r <matching requirements file>"
echo "Bots control API: enable systemd unit bots_api/bots-api.service and proxy bots.botofthespecter.com → 127.0.0.1:8090"
