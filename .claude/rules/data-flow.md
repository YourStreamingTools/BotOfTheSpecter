# Data Flow: WebSocket vs API vs Direct DB

Three transport layers exist. Picking the wrong one creates lag, duplicated logic, or race conditions.

## Decision tree

**Is the data real-time / event-driven?** (a follower joined, a death happened, OBS scene changed)
→ **WebSocket** (`./websocket/server.py`). Add a handler in the right module, broadcast via `broadcast_event_with_globals()`, `broadcast_to_timer_clients_only()`, or `broadcast_to_task_clients_only()`.

**Is the data on-demand / request-response?** (fetch user's commands, validate API key, get weather)
→ **API** (`./api/api.py`). Add a FastAPI endpoint with proper auth (V1 query `api_key=` or V2 header `X-API-KEY`).

**Is this a bot reading or writing its own per-channel state?** (custom commands, points, watch time)
→ **Direct MySQL** via `aiomysql` against the per-user database. See [database.md](./database.md).

## Rules

1. **Never call the API from the WebSocket server, or vice versa, when a direct DB query would do.** Both already have MySQL connections.
2. **Overlays talk only to the WebSocket** (Socket.io client). They do not call the API directly for live data.
3. **The Dashboard talks to the API** for configuration reads/writes, and emits to the WebSocket for live triggers (e.g., test sound alerts).
4. **The Bot talks to all three:** Twitch EventSub for chat events, the API for queries, the WebSocket for real-time fan-out.
5. **New WebSocket events go in the right handler module** — `event_handler.py`, `music_handler.py`, `tts_handler.py`, `obs_handler.py`, `donation_handler.py`. Don't dump everything in `server.py`.
6. **New API endpoints need Pydantic models** for request/response validation, plus auth via `verify_key()` and `resolve_username()`.
7. **Webhook endpoints (Ko-fi, Patreon, Fourthwall, GitHub, Kick) verify signatures** where the upstream supports it. Don't skip the check.
