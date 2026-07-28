#!/usr/bin/env bash
# Host bootstrap helpers for the bot server.
# Prefer multi-venv layout via setup_venvs.sh (required for new servers).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

if [[ "${1:-}" == "--system-packages" ]]; then
  apt update
  apt-get install -y python3 python3-pip python3-venv python-is-python3
  # On some distros (e.g. a very new default python3 like 3.14) the generic
  # python3-venv metapackage doesn't pull in that exact version's ensurepip
  # support. Also install the versioned package explicitly; ignore failure if
  # the generic one above already covered it.
  PYVER="$(python3 -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")' 2>/dev/null || true)"
  if [[ -n "${PYVER}" ]]; then
    apt-get install -y "python${PYVER}-venv" || true
  fi
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

# Export queue worker + export_user_data.py deps (boto3/requests for S3 upload).
# export_queue_worker.py launches export_user_data.py via bare `python3`; the systemd
# unit below puts venvs/stable/bin first on PATH so that resolves into this venv.
if [[ -f requirements-export_user_data.txt && -x venvs/stable/bin/pip ]]; then
  echo "Installing export worker requirements into venvs/stable..."
  venvs/stable/bin/pip install -r requirements-export_user_data.txt
fi

if [[ ! -f .env && -f .env.example ]]; then
  echo "No .env found — copying .env.example to .env (fill in real secrets before starting any service)."
  cp .env.example .env
fi

echo
echo "Do NOT pip install -r requirements.txt into system Python on the bot host."
echo "Always use: venvs/<name>/bin/pip install -r <matching requirements file>"
echo

SERVICE_UNITS=(bots_api/bots-api.service discordbot.service export_queue_worker.service)
if [[ "$(id -u)" -eq 0 ]]; then
  echo "Installing systemd services (running as root)..."
  for unit in "${SERVICE_UNITS[@]}"; do
    if [[ -f "${unit}" ]]; then
      cp "${unit}" "/etc/systemd/system/$(basename "${unit}")"
      echo "  copied ${unit} -> /etc/systemd/system/$(basename "${unit}")"
    else
      echo "  WARNING: ${unit} not found, skipping" >&2
    fi
  done
  systemctl daemon-reload
  for unit in "${SERVICE_UNITS[@]}"; do
    name="$(basename "${unit}")"
    if [[ -f "/etc/systemd/system/${name}" ]]; then
      systemctl enable --now "${name}"
      echo "  enabled + started ${name}"
    fi
  done
  echo
  echo "Remember: reverse-proxy bots.botofthespecter.com → 127.0.0.1:8090 (Caddy/nginx) for bots-api."
else
  echo "Not running as root — systemd services not installed automatically. To finish setup, run as root:"
  for unit in "${SERVICE_UNITS[@]}"; do
    echo "  sudo cp ${unit} /etc/systemd/system/$(basename "${unit}")"
  done
  echo "  sudo systemctl daemon-reload"
  echo "  sudo systemctl enable --now bots-api discordbot export_queue_worker"
  echo "Also proxy bots.botofthespecter.com → 127.0.0.1:8090 (Caddy/nginx) for bots-api."
fi
