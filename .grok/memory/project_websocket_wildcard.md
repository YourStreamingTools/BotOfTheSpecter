---
name: websocket-wildcard-is-intentional
description: "The websocket server's '*' catch-all handler is a deliberate extensibility design, not a bug. Do not \"fix\" the (sid, event, data) signature."
metadata: 
  node_type: memory
  type: project
  originSessionId: dee89d11-1795-4625-883b-5d8bdad8a2ca
---

The `('*', self.event)` handler in `./websocket/server.py:236` with method signature `async def event(self, sid, event, data)` is **intentional** and **working in production**.

**Why:** Adding new event types to the system would otherwise require restarting the websocket server, which causes all connected clients (overlays, bots, dashboards) to reconnect — and sometimes they time out and never reconnect at all. The wildcard forwards any unhandled event to global listeners (Discord bot is the main one) so new events can be added end-to-end without touching the websocket server.

**How to apply:** Do not change the wildcard handler signature, do not remove the wildcard registration, do not "consolidate" it into specific handlers. The arg order `(sid, event, data)` matches what the installed python-socketio version actually delivers on the prod server, regardless of what the published docs say about `(event, sid, data)`. Empirical evidence: the Discord bot's client-side catch-all at `./bot/specterdiscord.py:765` logs received events with sensible names, confirming the server emits under correct event names.

Verified 2026-05-23 during a websocket bug-hunt pass — initially flagged as a bug, walked back after user confirmed prod behavior.

**IMPORTANT scope limit (found 2026-06-05, Closed Captions):** the wildcard forwards unhandled events to **global listeners** (the Discord bot) — it does NOT reliably relay a **client-emitted** event back to the **same-code overlay clients**. Empirical proof: the dashboard captioner emitted `CLOSED_CAPTION` (client `socket.emit`, both dashboard+overlay registered on the same code), the overlay was connected, yet it never received the event. So **do NOT assume a new dashboard→overlay (or overlay→overlay) event "rides the wildcard, no server change needed."** For a client-emitted event that must reach same-code overlay clients, add an **explicit handler** mirroring `handle_chat_message` (`./websocket/server.py`): resolve `code = self.get_code_by_sid(sid)`, then `await self.broadcast_event_with_globals(EVENT, payload, code=code, source_sid=sid)`, and register it in `setup_event_handlers()` (this also adds it to `explicit_event_handlers`). Done for `CLOSED_CAPTION`/`CLOSED_CAPTION_CLEAR`. **Any server.py change needs a websocket-server restart to take effect.** Note: bot-emitted events via `websocket_notice` (HTTP `/notify`) are a *different* path and may relay differently — this limit is specifically about client `socket.emit`. This also applies to the PNG/VTuber plan's dashboard-emitted `AVATAR_STATE` (see [[project_working_study_expansion_build]] for the contrast: USER_POMO_* are server-emitted, not client-emitted).
