#!/usr/bin/env bash
# Create isolated Python virtualenvs for each bot process family.
#
# Run on the bot host as the botofthespecter user from the bot code directory
# (server: typically /home/botofthespecter, which mirrors ./bot/ in the repo).
#
# Layout (server):
#   /home/botofthespecter/venvs/stable   → bot.py          (TwitchIO 2.10)
#   /home/botofthespecter/venvs/beta     → beta.py         (TwitchIO 2.10)
#   /home/botofthespecter/venvs/v6       → beta-v6.py      (TwitchIO 3.x)
#   /home/botofthespecter/venvs/discord  → specterdiscord.py
#   /home/botofthespecter/venvs/kick     → kick.py
#
# Usage:
#   ./setup_venvs.sh              # create/update all
#   ./setup_venvs.sh stable v6    # only named envs
#   ./setup_venvs.sh --force      # recreate even if present
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Prefer cwd = bot home on server; fall back to script dir (repo ./bot/).
if [[ -f "${PWD}/bot.py" || -f "${PWD}/requirements.txt" ]]; then
  BOT_HOME="${PWD}"
else
  BOT_HOME="${SCRIPT_DIR}"
fi

VENV_ROOT="${BOT_HOME}/venvs"
PYTHON_BIN="${PYTHON_BIN:-python3}"
FORCE=0
ONLY=()

usage() {
  cat <<EOF
Usage: $0 [--force] [stable|beta|v6|discord|kick ...]

Creates (or updates) isolated venvs under:
  ${VENV_ROOT}/<name>

Requirements mapping:
  stable  → requirements.txt
  beta    → requirements.txt          (same pin set as stable; separate tree)
  v6      → v6_requirements.txt       (TwitchIO 3.x — never mix with stable/beta)
  discord → discord_requirements.txt
  kick    → kick_requirements.txt

Also creates a compat symlink:
  ${BOT_HOME}/beta_env → venvs/v6
  (old dashboard/API paths that still reference beta_env)

Environment:
  PYTHON_BIN   Python used to create venvs (default: python3)
EOF
}

for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    --force) FORCE=1 ;;
    stable|beta|v6|discord|kick) ONLY+=("$arg") ;;
    *)
      echo "Unknown argument: $arg" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ ${#ONLY[@]} -eq 0 ]]; then
  ONLY=(stable beta v6 discord kick)
fi

req_for() {
  case "$1" in
    stable|beta) echo "requirements.txt" ;;
    v6)          echo "v6_requirements.txt" ;;
    discord)     echo "discord_requirements.txt" ;;
    kick)        echo "kick_requirements.txt" ;;
  esac
}

# Fall back to legacy beta_requirements.txt if v6 file missing (older checkouts).
resolve_req() {
  local name="$1"
  local file
  file="$(req_for "$name")"
  if [[ -f "${BOT_HOME}/${file}" ]]; then
    echo "${BOT_HOME}/${file}"
    return
  fi
  if [[ "$name" == "v6" && -f "${BOT_HOME}/beta_requirements.txt" ]]; then
    echo "${BOT_HOME}/beta_requirements.txt"
    return
  fi
  echo ""
}

mkdir -p "${VENV_ROOT}"

echo "BOT_HOME=${BOT_HOME}"
echo "VENV_ROOT=${VENV_ROOT}"
echo "PYTHON_BIN=${PYTHON_BIN}"
echo "targets: ${ONLY[*]}"
echo

for name in "${ONLY[@]}"; do
  venv_dir="${VENV_ROOT}/${name}"
  req_file="$(resolve_req "$name")"
  if [[ -z "${req_file}" ]]; then
    echo "ERROR: no requirements file for '${name}' under ${BOT_HOME}" >&2
    exit 1
  fi

  if [[ -d "${venv_dir}" && "${FORCE}" -eq 1 ]]; then
    echo "==> recreating ${name} (${venv_dir})"
    rm -rf "${venv_dir}"
  fi

  # A venv dir can exist but be half-built (e.g. `python3 -m venv` failed partway
  # through because ensurepip/python3-venv wasn't installed) — bin/pip never got
  # written. Treat that as broken rather than "existing" so the next run self-heals
  # instead of failing later at `pip install` with a confusing "No such file".
  if [[ -d "${venv_dir}" && ! -x "${venv_dir}/bin/pip" ]]; then
    echo "==> ${name} exists but has no bin/pip (broken/partial venv) — removing and recreating"
    rm -rf "${venv_dir}"
  fi

  if [[ ! -d "${venv_dir}" ]]; then
    echo "==> creating venv ${name}"
    "${PYTHON_BIN}" -m venv "${venv_dir}"
    if [[ ! -x "${venv_dir}/bin/pip" ]]; then
      PYVER="$("${PYTHON_BIN}" -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")' 2>/dev/null || echo "3")"
      echo "ERROR: venv created but bin/pip is still missing for '${name}'." >&2
      echo "       The matching venv/ensurepip system package is likely missing. Try:" >&2
      echo "         sudo apt install python${PYVER}-venv" >&2
      echo "       or: sudo ./install.sh --system-packages" >&2
      exit 1
    fi
  else
    echo "==> using existing venv ${name}"
  fi

  echo "    pip install -r $(basename "${req_file}")"
  "${venv_dir}/bin/pip" install --upgrade pip
  "${venv_dir}/bin/pip" install -r "${req_file}"
  echo "    ok: ${venv_dir}/bin/python"
  echo
done

# Compat symlink for pre-multi-venv launch paths (v6 only used beta_env before).
if [[ -d "${VENV_ROOT}/v6" ]]; then
  if [[ -L "${BOT_HOME}/beta_env" || ! -e "${BOT_HOME}/beta_env" ]]; then
    ln -sfn "${VENV_ROOT}/v6" "${BOT_HOME}/beta_env"
    echo "compat: ${BOT_HOME}/beta_env -> ${VENV_ROOT}/v6"
  else
    echo "warn: ${BOT_HOME}/beta_env exists and is not a symlink; left untouched" >&2
  fi
fi

echo
echo "Done. Example launches (server paths):"
echo "  ${VENV_ROOT}/stable/bin/python -u ${BOT_HOME}/bot.py ..."
echo "  ${VENV_ROOT}/beta/bin/python -u ${BOT_HOME}/beta.py ..."
echo "  ${VENV_ROOT}/v6/bin/python -u ${BOT_HOME}/beta-v6.py ..."
echo "  ${VENV_ROOT}/discord/bin/python -u ${BOT_HOME}/specterdiscord.py"
echo "  ${VENV_ROOT}/kick/bin/python -u ${BOT_HOME}/kick.py ..."
echo
echo "Helpers (status / running list) should use the stable venv (has psutil):"
echo "  ${VENV_ROOT}/stable/bin/python ${BOT_HOME}/status.py -system stable -channel <user>"
echo "  ${VENV_ROOT}/stable/bin/python ${BOT_HOME}/running_bots.py"
