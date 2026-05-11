# Internal API Docs — Index

Documentation for BotOfTheSpecter's own API surface. This folder is currently empty — docs will be added here as they are written.

## Planned

| Doc | Subsystem | Status |
| --- | --------- | ------ |
| `api-server.md` | FastAPI server — 50+ REST endpoints, V1/V2 auth, Pydantic models, webhook receivers | Not written |
| `websocket-server.md` | SocketIO server — 177 events, 8 handler modules, broadcast patterns | Not written |
| `extension-api.md` | Twitch Extension endpoints (`/api/extension/*`) | Not written |

## Existing high-level references

While the detailed endpoint docs haven't been written yet, high-level architecture references exist in `.claude/memory/`:

- [`system_api.md`](../../../memory/system_api.md) — FastAPI server architecture, auth system, endpoint categories
- [`system_websocket.md`](../../../memory/system_websocket.md) — WebSocket server architecture, event types, handler modules

Use those as a starting point; write a proper `Internal/` doc when you need full endpoint-level detail.
