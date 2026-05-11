# Internal API Documentation

Documentation for BotOfTheSpecter's own API surface — the endpoints and events that overlays, the dashboard, bots, and external services call into.

This folder is the authoritative reference when building anything that talks to the platform rather than to a third-party service.

## Planned docs

| File | Subsystem | Status |
| ---- | --------- | ------ |
| `api-server.md` | FastAPI server — all 50+ REST endpoints, auth (V1 query key / V2 header), request/response models | Not yet written |
| `websocket-server.md` | SocketIO server — all 177 events, broadcast patterns, handler modules | Not yet written |
| `extension-api.md` | Twitch Extension endpoints (`/api/extension/*`) | Not yet written |

## When to write a doc here

- You are adding or modifying an endpoint in `./api/api.py` that other systems (overlays, bots, dashboards) need to call.
- You are adding a new WebSocket event type in `./websocket/server.py` or its handler modules.
- You need a reference for the extension API surface when working on `./extension/`.

## Doc structure to follow

Use the same structure as `../External/` docs:

1. **Overview** — what this subsystem does, port, base URL
2. **Authentication** — key types, how to pass credentials
3. **Endpoints / Events** — complete list with request/response schemas
4. **Error handling** — status codes, error shapes
5. **Callsites** — which files consume this API

## Related

- Master index: [../../INDEX.md](../../INDEX.md)
- External API docs: [../External/](../External/)
- System architecture memory files: [../../../memory/](../../../memory/)
  - `system_api.md` — FastAPI server architecture (existing high-level reference)
  - `system_websocket.md` — WebSocket server architecture (existing high-level reference)
