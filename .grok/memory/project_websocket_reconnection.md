---
name: project_websocket_reconnection
description: Internal Specter Socket.IO reconnection model across the bots — single reconnection authority; never set reconnection=False on Discord
metadata: 
  node_type: memory
  type: project
  originSessionId: e16f7f5e-4789-4c87-9314-0a848712ed9b
---

The bots' connection to the **internal Specter system** (`specterSocket` → `wss://websocket.botofthespecter.com`, server = `websocket/server.py`) is python-socketio (`from socketio import AsyncClient`). This is SEPARATE from: `streamelements_socket` (also `AsyncClient`, external — do not touch when fixing the internal socket) and the `from websockets import ...` raw-WS services (Twitch EventSub, HypeRate, Stream Bingo, Tanggle).

**The reconnection bug (fixed 2026-06-08, ships in stable 5.7.12):** `specterSocket = AsyncClient()` defaults to `reconnection=True`, so python-socketio auto-reconnected **and** the bot's manual `specter_websocket()` `while True` loop reconnected — two authorities on one client. Each reconnect re-registers under the same `name`, and `server.py register()` evicts a duplicate registration by name (`"Disconnected: Duplicate session for name ..."`) → the two authorities knocked each other offline in a self-sustaining flap, pinning the `websocket_connected` flag off. That flag gates `websocket_notice()` (drops ALL overlay/alert events when off) and some commands (e.g. `!weather` refuses) → "most commands stop working." bot.py also had no `connect()` timeout → could hang forever if the rebooting server accepted TCP but never finished the Socket.IO handshake.

**The fix / the rule going forward — ONE reconnection authority per client:**
- **bot.py, beta.py, beta-v6.py, kick.py** have a manual `specter_websocket()` loop → that loop is the sole authority → `AsyncClient(reconnection=False)`. Because there is no socketio fallback, the manual `connect()` MUST stay wrapped in `asyncio_wait_for(..., timeout=30)`, and the loop force-disconnects before each reconnect.
- **specterdiscord.py** `WebsocketListener` has **NO manual loop** — `start()` calls `connect()` once (`asyncio.create_task`) and relies entirely on socketio's built-in reconnection (already single-authority). **NEVER set `reconnection=False` here** — Discord would never reconnect. It's set to `reconnection=True, reconnection_attempts=0, reconnection_delay=5, reconnection_delay_max=60`.

Two open follow-ups: `python-socketio` is **unpinned** in `bot/requirements.txt` (reconnection semantics are version-dependent — pin it); and the server-side `versions.json` / deploy must catch up for releases. See [[project_bot_websocket_signaling]].
