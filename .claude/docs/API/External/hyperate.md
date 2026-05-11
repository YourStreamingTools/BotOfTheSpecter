# HypeRate API — Comprehensive Reference

> Source: HypeRate official DevDocs (https://github.com/HypeRate/DevDocs), HypeRate marketing pages
> (https://www.hyperate.io/api), and reverse-engineering of the official C# / Godot / Elixir SDKs.
> Last verified: 2026-05-11.

HypeRate is a heart-rate-as-a-service platform. Wearable fitness devices push BPM data to the
HypeRate cloud, which re-publishes that data to subscribers in real time over a Phoenix WebSocket.
There is no polling REST API for live data — WebSocket is the only channel for BPM updates.

---

## 1. Authentication

HypeRate uses a **two-layer** authentication model:

### 1.1 Developer WebSocket key (application-level)

Issued once per application by HypeRate. Passed as a query parameter on the WebSocket upgrade
request. The same key is used for every channel the application subscribes to.

```
wss://app.hyperate.io/socket/websocket?token=<WEBSOCKET-KEY>
```

- Obtained by requesting access at https://www.hyperate.io/api
- Never expires passively; revoked only on plan change or terms breach
- Scoped to the **application**, not to an individual streamer
- Must be kept secret — it authenticates your whole integration, not a single user

### 1.2 Per-streamer channel code (channel-level)

Each streamer has a unique identifier that maps their HypeRate account to a Phoenix channel topic.
This value is set by the streamer themselves in the HypeRate mobile/desktop app.

- Format: an alphanumeric string chosen by the streamer (e.g. `abc123`, `streamername`)
- Used to form the Phoenix topic: `hr:<channel_code>` (e.g. `hr:abc123`)
- Not a secret — it is an identifier, not a credential. Knowing it lets you subscribe; it does not
  let you impersonate the streamer or write to their account
- Test value: `internal-testing` (see §5)

### 1.3 Key distinction

| Concept | What it is | Scope | Secret? |
|---|---|---|---|
| WebSocket key | Developer credential | One per application | Yes |
| Channel code | Streamer identifier | One per streamer | No |

---

## 2. REST Endpoints

**None documented.** HypeRate does not publish a REST API for BPM data. Historical REST endpoints
for Clips and Device-ID lookup are referenced in the C# SDK (`ClipCreated` event), but no public
documentation exists for them and this project does not call them.

Contact hello@hyperate.io for Clips API access if needed.

---

## 3. WebSocket Connection

### 3.1 Connection URL

```
wss://app.hyperate.io/socket/websocket?token=<WEBSOCKET-KEY>
```

This is a standard WebSocket upgrade. The `token` query parameter carries the developer key.
No HTTP headers beyond standard WebSocket handshake headers are required.

### 3.2 Protocol

The server runs the **Phoenix Framework** channel transport. This is **not** Socket.IO, Engine.IO,
or any other wrapper. Do not use a Socket.IO client — the framing is incompatible.

Phoenix messages are plain JSON objects with four fields:

| Field | Type | Description |
|---|---|---|
| `topic` | string | The channel being addressed. Format: `hr:<id>` for HR channels, `phoenix` for the socket-level keepalive |
| `event` | string | The message type (see §4) |
| `payload` | object | Event-specific data |
| `ref` | integer or null | Client-assigned reference ID for correlation. Server echoes it back in replies. Use `0` or a monotonic counter. Server-pushed events use `null` |

### 3.3 Connection lifecycle

```
Client                                  Server
  |                                       |
  |--- WebSocket upgrade (token= param) ->|
  |<-- 101 Switching Protocols -----------|
  |                                       |
  |--- phx_join (topic: "hr:<id>") ------>|
  |<-- phx_reply (status: "ok") ----------|
  |                                       |
  |   [every 10 seconds]                  |
  |--- heartbeat (topic: "phoenix") ----->|
  |<-- phx_reply (topic: "phoenix") ------|
  |                                       |
  |<-- hr_update (payload: {hr: N}) ------|  [repeated on each device update]
  |<-- hr_update (payload: {hr: null}) ---|  [device went offline]
  |                                       |
  |--- phx_leave (topic: "hr:<id>") ----->|  [optional graceful leave]
  |<-- phx_reply (status: "ok") ----------|
  |                                       |
  |--- close frame ---------------------->|  [or server closes]
```

---

## 4. Message Types

### 4.1 Client → Server: `phx_join`

Subscribe to a heart-rate channel. Must be sent before any `hr_update` events will arrive.

```json
{
  "topic": "hr:<channel_code>",
  "event": "phx_join",
  "payload": {},
  "ref": 0
}
```

Example joining `internal-testing`:

```json
{
  "topic": "hr:internal-testing",
  "event": "phx_join",
  "payload": {},
  "ref": 0
}
```

Notes:
- `payload` must be an empty object `{}`; additional fields are ignored
- `ref` is an integer; `0` is acceptable for simple single-channel clients
- The server responds with a `phx_reply` (see §4.4)
- Multiple channels can be joined on a single connection by sending multiple `phx_join` messages
  with different topics

### 4.2 Client → Server: `heartbeat` (keepalive)

Phoenix will close the connection if no keepalive arrives within its configured timeout. Send this
message every **10 seconds**.

```json
{
  "topic": "phoenix",
  "event": "heartbeat",
  "payload": {},
  "ref": 0
}
```

Critical notes:
- The topic is `"phoenix"` (the socket-level system topic), **not** the `hr:...` channel topic.
  Sending the keepalive to `hr:...` will not satisfy the server's keepalive requirement
- `payload` must be `{}`
- The server responds with a `phx_reply` on topic `"phoenix"` (see §4.4)

### 4.3 Client → Server: `phx_leave`

Gracefully unsubscribe from a channel without closing the underlying connection.

```json
{
  "topic": "hr:<channel_code>",
  "event": "phx_leave",
  "payload": {},
  "ref": 0
}
```

Example leaving `internal-testing`:

```json
{
  "topic": "hr:internal-testing",
  "event": "phx_leave",
  "payload": {},
  "ref": 0
}
```

Notes:
- Useful when multiplexing multiple channels on one connection and only removing one subscription
- The server responds with a `phx_reply` (see §4.4)
- If the connection is being torn down entirely, closing the WebSocket is sufficient; `phx_leave` is
  optional in that case

### 4.4 Server → Client: `phx_reply`

Server acknowledgement of any client-initiated message (`phx_join`, `phx_leave`, `heartbeat`).

```json
{
  "topic": "<same topic as the original message>",
  "event": "phx_reply",
  "payload": {
    "status": "ok",
    "response": {}
  },
  "ref": <same ref as the original message>
}
```

On success `status` is `"ok"`. On failure (e.g. invalid channel, authentication error) `status` is
`"error"` and `response` may contain an error description. The `ref` field echoes the ref sent by
the client in the originating message.

### 4.5 Server → Client: `hr_update`

Delivered each time the streamer's device reports a new BPM reading.

```json
{
  "topic": "hr:<channel_code>",
  "event": "hr_update",
  "payload": {
    "hr": 79
  },
  "ref": null
}
```

Payload schema:

| Field | Type | Description |
|---|---|---|
| `hr` | integer | Heart rate in BPM. Positive integer while the device is active |
| `hr` | null | Sentinel value meaning the device has gone offline or disconnected |

`ref` is always `null` for server-pushed events (not a response to a client message).

**The `hr: null` case is significant.** When `hr` is `null`, the streamer's device has stopped
sending data. This is a clean termination signal — not an error — and clients should treat it as
"monitoring ended". No further `hr_update` events will arrive until the device reconnects and the
streamer resumes sending.

### 4.6 Server → Client: `phx_close`

Sent by the server when it is closing a channel from its side (e.g. the channel is being
terminated). The client should handle this by either rejoining or closing the connection.

```json
{
  "topic": "hr:<channel_code>",
  "event": "phx_close",
  "payload": {},
  "ref": null
}
```

---

## 5. Test Channel

HypeRate provides a built-in test channel that does not require a real device:

| Property | Value |
|---|---|
| Channel code | `internal-testing` |
| Topic | `hr:internal-testing` |
| Behaviour | Emits a random BPM between 60 and 80 once per second, indefinitely |
| Purpose | Development and integration testing without a wearable |

To join:

```json
{
  "topic": "hr:internal-testing",
  "event": "phx_join",
  "payload": {},
  "ref": 0
}
```

The test channel never sends `hr: null`, so it cannot be used to test the device-offline code path.

---

## 6. Access Tiers and Rate Limits

HypeRate uses a tiered access model. As of the last check (2026-05), the tiers are:

| Tier | Price | Target |
|---|---|---|
| Research & Indie | Free | Small projects, hobbyists, researchers |
| Partner | ~€1,900/year | Mid-size companies and streaming integrations |
| Commercial | ~€3,800/year | Large-scale commercial deployments |

Access at any paid tier is granted by request (https://www.hyperate.io/api), not by self-service
sign-up. The free tier is also request-gated.

**Documented constraints:**
- No per-message rate limit is published for `phx_join`/`hr_update`
- Concurrent connection limits exist per tier but are not publicly documented; contact HypeRate
  for specifics
- The heartbeat interval is effectively a server-enforced constraint: miss it and the connection
  is closed. The required interval is **10 seconds or less**
- Rapid join/leave cycling on many topics simultaneously is treated as abuse regardless of tier

**Implications for multi-channel use:**
The Phoenix protocol supports multiple channel subscriptions on a single connection (one
`phx_join` per topic, all on the same WebSocket). If you need to subscribe to many streamers'
heart rates, multiplexing onto one connection is more efficient and less likely to hit concurrency
limits than opening one socket per streamer.

---

## 7. Full Connection Example (Python / websockets)

```python
import asyncio, json
import websockets

WEBSOCKET_KEY = "your-developer-key"
CHANNEL_CODE  = "internal-testing"

async def main():
    uri = f"wss://app.hyperate.io/socket/websocket?token={WEBSOCKET_KEY}"
    async with websockets.connect(uri) as ws:

        # 1. Join the channel
        await ws.send(json.dumps({
            "topic": f"hr:{CHANNEL_CODE}",
            "event": "phx_join",
            "payload": {},
            "ref": 0
        }))

        # 2. Start a keepalive loop (10-second interval)
        async def heartbeat():
            while True:
                await asyncio.sleep(10)
                await ws.send(json.dumps({
                    "topic": "phoenix",
                    "event": "heartbeat",
                    "payload": {},
                    "ref": 0
                }))

        asyncio.create_task(heartbeat())

        # 3. Receive messages
        async for raw in ws:
            msg = json.loads(raw)
            if msg.get("event") == "hr_update":
                hr = msg["payload"].get("hr")
                if hr is None:
                    print("Device offline — stopping")
                    break
                print(f"Heart rate: {hr} BPM")
            # phx_reply, phx_close, etc. can be safely ignored for basic use

asyncio.run(main())
```

---

## 8. Common Pitfalls

- **Wrong keepalive topic.** The heartbeat must go to topic `"phoenix"`, not `"hr:<id>"`. Phoenix
  tracks keepalives at the socket level; channel-level messages do not satisfy it
- **`hr: null` is not an error.** It is the server's way of saying the device disconnected cleanly.
  Do not retry the connection immediately — the device is offline and reconnecting will simply yield
  the same null immediately
- **Phoenix is not Socket.IO.** Socket.IO clients add a negotiation layer (Engine.IO transport
  handshake, namespace packets) that Phoenix does not speak. Use a plain WebSocket library
- **Single connection, multiple channels.** You can join `hr:alice`, `hr:bob`, and `hr:charlie`
  on one WebSocket by sending three `phx_join` messages. Each will deliver `hr_update` events with
  its own `topic` field for routing
- **The developer key authenticates the connection, not the channel.** There is no per-channel auth
  beyond knowing the channel code. Guard the developer key carefully; the channel code is not secret

---

## 9. BotOfTheSpecter Callsites

This section documents how BotOfTheSpecter uses the HypeRate API. It is not part of the HypeRate
specification.

### Configuration

| Item | Location | Notes |
|---|---|---|
| Developer key env var | `HYPERATE_API_KEY` in `(server) /home/botofthespecter/.env` | Misleadingly named — it is the application-level WebSocket key, not a per-user key |
| Per-streamer channel code storage | `users.heartrate_code` in the central `website` DB | One row per registered user; empty string = not configured |
| Dashboard UI for code entry | `./dashboard/profile.php:85-92, 222-249, 884-900` | Reads from `profile` table for display; writes to `users` table on save |

### Implementation (bot.py and beta.py)

Both `./bot/bot.py` and `./bot/beta.py` share an identical implementation. The relevant functions
are:

| Function | Location | Purpose |
|---|---|---|
| `hyperate_websocket_persistent()` | `./bot/bot.py:1395–1465`, `./bot/beta.py:2582–2651` | Outer reconnect loop + inner receive loop |
| `send_heartbeat()` | `./bot/bot.py:1467–1481`, `./bot/beta.py:2654–2667` | Sends `heartbeat` to topic `"phoenix"` every 10 s |
| `join_channel()` | `./bot/bot.py:1484–1499`, `./bot/beta.py:2670–2685` | Sends `phx_join` for the streamer's channel code |
| `heartrate_command` | `./bot/bot.py:6817–6866`, `./bot/beta.py:9104–9155` | Chat command `!heartrate`; lazily starts the WebSocket task |
| Task cleanup (stream offline) | `./bot/bot.py:7910–7911`, `./bot/beta.py:11452–11453` | Cancels the HypeRate task via `looped_tasks["hyperate_websocket"]` |

### Behaviour notes specific to this codebase

- The bot reads `heartrate_code` from the `profile` table (per-user DB), not from `users`. The
  dashboard writes to `users.heartrate_code` (website DB); a separate DB sync must propagate it
  to the per-user `profile` table before the bot can see it
- `hr: null` causes `hyperate_websocket_persistent()` to **return** (not just break the inner
  loop). The outer `while True:` reconnect loop is exited entirely. The task is only restarted the
  next time `!heartrate` is invoked
- Each running bot process has one global `HEARTRATE` variable. This is safe because each process
  serves exactly one channel. It would need to be per-channel if the bot were ever multi-tenanted
- The `redact()` helper (`./bot/bot.py:1391–1392`) replaces the developer key in any string before
  logging. If `HYPERATE_API_KEY` is `None` (env var missing), this call will raise `AttributeError`
  before the WebSocket is even opened
- `beta-v6.py` does not implement HypeRate. The integration exists only in the TwitchIO 2.x files
  (`bot.py` and `beta.py`)
