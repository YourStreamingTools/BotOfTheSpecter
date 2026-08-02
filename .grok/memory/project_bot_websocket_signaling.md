---
name: project_bot_websocket_signaling
description: Two bot→WebSocket channels — websocket_notice() /notify is whitelisted; specterSocket.emit() sends arbitrary events
metadata: 
  node_type: memory
  type: project
  originSessionId: d4b44555-49eb-42df-9fc2-ea389e3a05cb
---

The bot signals the WebSocket server two different ways, and the distinction matters when adding new real-time features:

1. **`websocket_notice(event=…, additional_data=…)`** (`bot/beta.py` ~12945) does an **HTTP GET to `/notify`** with urlencoded params. The event name is validated against a **server-side whitelist** (a big if/elif chain: WALKON, DEATHS, TWITCH_*, SOUND_ALERT, etc.). **Unknown/new event names are rejected** and silently dropped. Don't use this for a brand-new event type without also adding it to the whitelist on both ends.

2. **`specterSocket.emit(event, payload)`** — the bot's python-socketio `AsyncClient` (`specterSocket`, declared `bot/beta.py:252`, registered via `REGISTER`). This sends **arbitrary socket event names** over the live socket, routed by `sio.on(...)` in `websocket/server.py` `setup_event_handlers()`. Existing examples: `specterSocket.emit('CHAT_MESSAGE', …)` (~3506), `specterSocket.emit('TASK_REWARD_CONFIRM', …)` (~2569).

**Rule of thumb:** for a NEW real-time bot→server event (like the media-player `MEDIA_COMMAND`), use `specterSocket.emit` + register a handler in `setup_event_handlers()` — NOT `websocket_notice`. The bot registers with `code: API_TOKEN`, which equals the dashboard/overlay `code` (= `users.api_key`), so the server resolves all three under one code. See the wildcard `("*", self.event)` catch-all in [[project_websocket_wildcard]].
