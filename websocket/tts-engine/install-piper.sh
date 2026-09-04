#!/usr/bin/env bash
# Install Piper + four English voices on the websocket VM.
# Run on websocket as root (or a user that can write /var/lib/yourchat-piper).
# Does not run on web1. Streamers never run this.
set -euo pipefail

DEST="${NARRATOR_PIPER_ROOT:-/var/lib/yourchat-piper}"
PIPER_VER="${PIPER_VER:-2023.11.14-2}"
PIPER_TGZ="piper_linux_x86_64.tar.gz"
PIPER_URL="https://github.com/rhasspy/piper/releases/download/${PIPER_VER}/${PIPER_TGZ}"
HF_BASE="https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0"

VOICES=(
  "en/en_US/lessac/medium/en_US-lessac-medium"
  "en/en_US/hfc_female/medium/en_US-hfc_female-medium"
  "en/en_US/hfc_male/medium/en_US-hfc_male-medium"
  "en/en_GB/alba/medium/en_GB-alba-medium"
)

mkdir -p "${DEST}/voices" "${DEST}/tmp"
cd "${DEST}/tmp"

if [[ ! -x "${DEST}/piper/piper" ]]; then
  echo "Downloading Piper ${PIPER_VER}..."
  curl -fsSL -o "${PIPER_TGZ}" "${PIPER_URL}"
  tar -xzf "${PIPER_TGZ}" -C "${DEST}"
  rm -f "${PIPER_TGZ}"
  chmod +x "${DEST}/piper/piper"
else
  echo "Piper binary already present, skipping download."
fi

for rel in "${VOICES[@]}"; do
  base="$(basename "${rel}")"
  onnx="${DEST}/voices/${base}.onnx"
  json="${DEST}/voices/${base}.onnx.json"
  if [[ -f "${onnx}" && -f "${json}" ]]; then
    echo "Voice ${base} already present, skipping."
    continue
  fi
  echo "Downloading voice ${base}..."
  curl -fsSL -o "${onnx}" "${HF_BASE}/${rel}.onnx"
  curl -fsSL -o "${json}" "${HF_BASE}/${rel}.onnx.json"
done

if [[ ! -x "${DEST}/piper/piper" ]]; then
  echo "ERROR: ${DEST}/piper/piper is not executable" >&2
  exit 1
fi

echo "Smoke test (writes /tmp/yourchat-piper-smoke.wav)..."
echo "YourChat narrator is ready." | "${DEST}/piper/piper" \
  --model "${DEST}/voices/en_US-lessac-medium.onnx" \
  --output_file /tmp/yourchat-piper-smoke.wav \
  --length-scale 1.0
ls -l /tmp/yourchat-piper-smoke.wav

echo
echo "Done. Next:"
echo "  1. Put NARRATOR_HOP_SECRET in /home/botofthespecter/.env (websocket)"
echo "     and the same value in /var/www/config/yourchat.php on web1."
echo "  2. Install the unit: cp yourchat-piper.service /etc/systemd/system/"
echo "  3. systemctl daemon-reload && systemctl enable --now yourchat-piper"
echo "  4. From web1: curl -sS -o /dev/null -w '%{http_code}\\n' http://10.240.0.5:8094/health"
echo "     (must NOT be reachable on https://websocket.botofthespecter.com)"
