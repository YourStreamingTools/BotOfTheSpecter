# GitHub API & Webhooks — BotOfTheSpecter

Local reference for how this project ingests GitHub webhook deliveries and (does not) talk back to the GitHub REST API. Read this before editing the `/github` endpoint, the `GITHUB_EVENT` broadcast path, or anything that consumes repo/release data.

---

## 1. Overview

BotOfTheSpecter is **inbound-only** for GitHub at the runtime layer:

- **Inbound** — GitHub repo webhooks POST to `https://api.botofthespecter.com/github`, are validated against an admin API key, then forwarded to the WebSocket server, which fans them out to admin-authenticated global listeners (the Discord bot).
- **Outbound** — There are **no live REST calls to `api.github.com` from the bot, API server, WebSocket server, or PHP dashboard.** The only GitHub references in PHP are static `<a href="https://github.com/…">` links on the marketing pages. See section 4.

This makes the GitHub integration small, but the rules around webhook authenticity still matter.

### Data flow

```text
GitHub repo event
      │  POST  https://api.botofthespecter.com/github?api_key=<ADMIN_KEY>
      ▼
./api/api.py          @app.post("/github")  →  handle_github_webhook()
      │  GET   https://websocket.botofthespecter.com/notify
      │       ?code=<api_key>&event=GITHUB&data=<json-payload>
      ▼
./websocket/server.py  notify_http()  →  handle_github_event()
      │  socket.io  emit('GITHUB_EVENT', {event, delivery, data})
      ▼
Global listeners (admin-authenticated, e.g. Discord bot)
```

---

## 2. Inbound webhooks

### 2.1 Endpoint

| Field | Value |
| --- | --- |
| URL | `https://api.botofthespecter.com/github` |
| Method | `POST` |
| Auth | Admin API key in query string (`?api_key=...`) — **not** GitHub's `X-Hub-Signature-256` |
| Required admin scope | `service='GitHub'` or `service='admin'` (super-admin) |
| Response | `200 {"status":"success","message":"GitHub Webhook received"}` |
| Implementation | `./api/api.py` lines ~2018–2062 |
| OpenAPI tag | `Admin Only` |
| V2 routing | Listed in `_V2_WEBHOOK_PATHS` (`./api/api.py` line 718) — exempt from V2's `X-API-KEY` header rewrite, keeps `api_key` in URL |

### 2.2 How to configure the GitHub side

When adding a webhook to a repository under https://github.com/YourStreamingTools, fill in:

- **Payload URL:** `https://api.botofthespecter.com/github?api_key=<ADMIN_KEY>`  
  The admin key must exist in the `admin_api_keys` table with `service='GitHub'` (or `'admin'`).
- **Content type:** `application/json`
- **Secret:** Leave blank, or fill it in for future-proofing — see section 5.1, this project does **not** currently verify it.
- **SSL verification:** Enable.
- **Events:** Select the events you want forwarded (push, release, issues, etc.). The endpoint accepts everything; the consumer (Discord bot) decides what to react to.

### 2.3 Headers read by the endpoint

`./api/api.py` reads:

| Header | Used for |
| --- | --- |
| `X-GitHub-Event` | Event name (e.g. `push`, `release`) — stored in forwarded payload as `event` |
| `X-GitHub-Delivery` | Per-delivery UUID — forwarded as `delivery` for de-dup/logging |

Headers GitHub sends but **this endpoint does not read** (relevant if you ever harden it):

- `X-Hub-Signature-256` — HMAC-SHA256 of raw body, prefixed `sha256=` (see section 5.1)
- `X-GitHub-Hook-ID`
- `X-GitHub-Hook-Installation-Target-ID`
- `X-GitHub-Hook-Installation-Target-Type`
- `User-Agent` — always starts with `GitHub-Hookshot/`

### 2.4 Auth flow inside the endpoint

```python
# ./api/api.py, ~line 2028
async def handle_github_webhook(request: Request, api_key: str = Query(...)):
    key_info = await verify_key(api_key, service="GitHub")
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
    ...
```

`verify_key()` (line ~1180) calls `verify_admin_key()` (line ~1142), which:

1. Looks up `api_key` in `website.admin_api_keys`.
2. Reads the `service` column.
3. Returns `True` if `service='admin'` (super-admin) **or** `service='GitHub'`.
4. Returns `False` if the key is service-scoped to anything else (logged as a warning).

A bad key returns `401 Invalid Admin API Key`.

### 2.5 Payload forwarded to WebSocket

After parsing JSON, the API wraps the payload like this:

```python
payload = {
    "event": github_event,        # X-GitHub-Event header
    "delivery": github_delivery,  # X-GitHub-Delivery header
    "data": webhook_data          # parsed GitHub JSON body (the full event)
}
params = {"code": api_key, "event": "GITHUB", "data": json.dumps(payload)}
url = f"https://websocket.botofthespecter.com/notify?{urlencode(params)}"
async with session.get(url, timeout=10) as response: ...
```

Forwarding is HTTP `GET` to the WebSocket server's `/notify` route — same pattern used by the Ko-fi, Patreon, Fourthwall, FreeStuff handlers. A `timeout=10` applies; on timeout or non-200 response the API returns `500`.

### 2.6 WebSocket-side handling

`./websocket/server.py` `notify_http()` (line ~754):

- Inspects `event=GITHUB` (line 831).
- Calls `handle_github_event(code, data)`.

`handle_github_event()` (line ~1001):

1. Re-parses `data` (string → dict) — it is JSON-encoded inside the URL param.
2. Re-parses `data['data']` (the inner GitHub payload), with a fallback to `ast.literal_eval` for legacy stringified-dict inputs.
3. Pulls `event = webhook_data.get('event', 'unknown')`.
4. Iterates `self.global_listeners` (admin-authenticated SocketIO clients only) and emits:

```python
await self.sio.emit('GITHUB_EVENT', {
    'event':    github_event,                    # 'push' | 'release' | ...
    'delivery': webhook_data.get('delivery'),    # UUID
    'data':     webhook_data.get('data')         # full GitHub event payload
}, to=listener['sid'])
```

Note: `GITHUB_EVENT` is **only** sent to global listeners — it is not broadcast to per-channel `registered_clients`. End-users' overlays/dashboards do not receive GitHub events. Today the practical consumer is the Discord bot when it registers as a global listener.

### 2.7 Event types accepted

Every GitHub event type is accepted by the endpoint (it doesn't filter on `X-GitHub-Event`). Below are the events most relevant to the project's repos and what the inner payload looks like. The full reference is at https://docs.github.com/en/webhooks/webhook-events-and-payloads.

#### `ping`

Sent once when a webhook is created or "Redeliver" is clicked.

```json
{ "zen": "Speak like a human.",
  "hook_id": 12345,
  "hook": { "type": "Repository", "events": ["push"], "config": {...} },
  "repository": {...}, "sender": {...} }
```

Use the `ping` event to confirm wiring is correct. The endpoint will forward it like any other event.

#### `push`

```json
{ "ref": "refs/heads/main",
  "before": "<sha>", "after": "<sha>",
  "created": false, "deleted": false, "forced": false,
  "commits": [ { "id": "<sha>", "message": "...", "author": {...}, "url": "..." } ],
  "head_commit": {...},
  "pusher": {"name": "...", "email": "..."},
  "repository": {...}, "sender": {...} }
```

No `action` field. `commits` is capped at 2048 entries by GitHub.

#### `release`

```json
{ "action": "published",   // or created/edited/deleted/prereleased/released/unpublished
  "release": { "tag_name": "v1.0.0", "name": "...", "body": "...", "html_url": "...",
               "draft": false, "prerelease": false, "assets": [...] },
  "repository": {...}, "sender": {...} }
```

#### `issues`

```json
{ "action": "opened",      // opened/edited/closed/reopened/assigned/unassigned/labeled/unlabeled/...
  "issue": { "number": 42, "title": "...", "body": "...", "state": "open", "user": {...}, "labels": [...] },
  "repository": {...}, "sender": {...} }
```

#### `pull_request`

```json
{ "action": "opened",      // opened/edited/closed/reopened/synchronize/ready_for_review/...
  "number": 42,
  "pull_request": { "title": "...", "body": "...", "state": "open", "merged": false,
                    "head": {...}, "base": {...}, "user": {...} },
  "repository": {...}, "sender": {...} }
```

#### `issue_comment`

```json
{ "action": "created",     // created/edited/deleted
  "issue": { ... },        // issue this comment is on (note: PRs are issues here)
  "comment": { "body": "...", "user": {...}, "html_url": "..." },
  "repository": {...}, "sender": {...} }
```

#### `pull_request_review`

```json
{ "action": "submitted",   // submitted/edited/dismissed
  "review": { "state": "approved", "body": "...", "user": {...} },
  "pull_request": {...},
  "repository": {...}, "sender": {...} }
```

#### `star`

```json
{ "action": "created",     // created (starred) | deleted (unstarred)
  "starred_at": "2026-01-01T00:00:00Z",   // null when action=deleted
  "repository": {...}, "sender": {...} }
```

#### `fork`

```json
{ "forkee": {...},   // the new fork's repository object
  "repository": {...}, "sender": {...} }
```

#### `workflow_run`

```json
{ "action": "completed",   // requested/in_progress/completed
  "workflow_run": { "name": "...", "status": "completed", "conclusion": "success",
                    "head_branch": "...", "head_sha": "...", "html_url": "..." },
  "workflow": {...},
  "repository": {...}, "sender": {...} }
```

### 2.8 Common payload fields (every event)

- `repository` — repo object (full name, owner, default branch, etc.).
- `sender` — GitHub user who triggered the event.
- `organization` — present only for org-installed webhooks.
- `installation` — present only for GitHub App deliveries.

### 2.9 What downstream consumers see

The Discord bot (or any future global listener) receives a SocketIO `GITHUB_EVENT` with shape:

```json
{ "event":    "push",
  "delivery": "12345678-abcd-...",
  "data":     { "ref": "...", "commits": [...], "repository": {...}, "sender": {...} } }
```

`data` is the **full GitHub payload** unmodified — consumers can branch on `event` (push/release/issues/...) and read whatever fields they need. The `action` sub-discriminator (e.g. `release.action="published"`) lives inside `data`, not at the top level.

---

## 3. The handoff payload contract (API ↔ WebSocket)

This is internal but easy to break. Two layers of JSON encoding exist:

1. The API serialises `payload = {event, delivery, data}` once with `json.dumps()` and stuffs it into the `data` query param.
2. The WebSocket server's `notify_http` reads it as a query string, then `handle_github_event` does **two** parse passes:
   - first `json.loads(data)` to unwrap the URL-encoded JSON;
   - then `json.loads(webhook_data)` again because the inner `data` field can also arrive stringified.

If you change the wrapping on the API side, also adjust `handle_github_event` in `./websocket/server.py`. The current double-`literal_eval` fallback exists for backward compat with stringified-dict captures.

---

## 4. Outbound REST API calls

**There are none in the runtime code.**

Verified by grep across the repo (excluding `vendor/`):

- `api.github.com` — no matches.
- `github.com/repos` — no matches.
- `GITHUB_TOKEN` / `github_token` — no matches.
- `Authorization: Bearer` against GitHub — no matches.

The only `github.com` references in source are static frontend links:

- `./home/index.php` — link to https://github.com/YourStreamingTools/BotOfTheSpecter.
- `./dashboard/controllerapp.php` — link to the OBS-Connector releases page.
- Various `./dashboard/lang/*.php`, `./support/`, `./roadmap/`, `./help/` files — UI strings and footer links.

If outbound calls are ever added, follow these conventions (none of which currently apply):

| Concern | Recommendation |
| --- | --- |
| Token type | Fine-grained PAT or GitHub App installation token, **not** classic PAT. |
| Storage | Python: `os.getenv("GITHUB_TOKEN")` from `/home/botofthespecter/.env`. PHP: `./config/github.php` per [php-config.md](../../../rules/php-config.md). |
| Header | `Authorization: Bearer <token>` (use `Bearer`, not `token` — works for PAT and JWT). |
| Versioning | `X-GitHub-Api-Version: 2022-11-28`. |
| User-Agent | Required by GitHub. Use a stable identifier like `BotOfTheSpecter/1.0`. |
| Rate limit | 5,000 requests/hour authenticated, 60/hour unauthenticated, 15,000/hour for GitHub Apps on Enterprise. Track in `website.api_counts` if added (same pattern as Weather/Shazam). |
| Common endpoints | `GET /repos/{owner}/{repo}/releases/latest`, `GET /repos/{owner}/{repo}/releases`, `GET /repos/{owner}/{repo}` for metadata, `GET /repos/{owner}/{repo}/issues`. |

---

## 5. Common gotchas

### 5.1 Signature verification is currently **not** performed

GitHub supports HMAC-SHA256 signed deliveries via `X-Hub-Signature-256: sha256=<hex>`. This project does **not** verify it on `/github`. Authentication is enforced via the admin API key in the URL, and TLS protects the URL in transit.

If you decide to add signature verification (recommended for defence-in-depth), follow GitHub's spec exactly:

```python
import hmac, hashlib

async def handle_github_webhook(request: Request, api_key: str = Query(...)):
    raw_body = await request.body()           # MUST use raw bytes, before json.loads
    signature_header = request.headers.get("X-Hub-Signature-256", "")
    secret = os.getenv("GITHUB_WEBHOOK_SECRET", "").encode("utf-8")
    expected = "sha256=" + hmac.new(secret, raw_body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature_header):
        raise HTTPException(status_code=403, detail="Invalid GitHub signature")
    webhook_data = json.loads(raw_body)
    ...
```

Critical points:

1. **Compute HMAC over the raw request body bytes, not over a re-encoded JSON.** `await request.json()` parses and re-encodes; the bytes will not match. Capture `await request.body()` first, verify, then `json.loads(raw_body)`.
2. **Use `hmac.compare_digest()`** for constant-time comparison — never `==`. Standard equality leaks timing info.
3. The header value is `sha256=<hex>`; do not strip the prefix before comparing — include it in the expected value too.
4. Store the secret in `/home/botofthespecter/.env` as `GITHUB_WEBHOOK_SECRET` and re-use the same value in the GitHub repo's webhook configuration.
5. Per [secrets.md](../../../rules/secrets.md): never log the secret; never log the full signature header.

### 5.2 No automatic redelivery

GitHub does **not** automatically retry failed webhook deliveries. If `/github` returns non-2xx (or times out), the event is **lost** unless a human re-delivers it from the repo's webhook delivery log (3-day retention).

Implications:

- Returning `500` on transient WebSocket-forward failures (current behaviour) loses events.
- Consider returning `200` + queueing locally if the WebSocket server is down, especially for `release` events that drive Discord notifications.
- For backfills, the GitHub webhook UI offers per-delivery "Redeliver". For bulk replay, use the GitHub REST API redelivery endpoints under `/repos/{owner}/{repo}/hooks/{hook_id}/deliveries/{delivery_id}/attempts`.

### 5.3 Replay attacks

`X-GitHub-Delivery` is a UUID per delivery. If signature verification is added, also de-dup on `delivery` to guard against an attacker replaying a previously-captured (signed) request. Today, the admin-API-key gate makes replay roughly equivalent to having the key — already game over — so this isn't urgent, but worth doing alongside signature verification.

### 5.4 Issue comments fire on PRs too

The `issue_comment` event fires for comments on **both issues and pull requests** because GitHub treats PRs as a special kind of issue. If you add code that reacts only to issue comments, gate on `data.issue.pull_request` being absent. PRs include a `pull_request` sub-object inside the issue.

### 5.5 `push` payload caps at 2048 commits

For very large pushes (tag updates that span thousands of commits, mass merges), `commits[]` is truncated. Use `before`/`after` to fetch the full diff via REST if needed. Currently no consumer does this.

### 5.6 Stringified inner payloads

`./websocket/server.py` `handle_github_event` defends against `data` arriving as a stringified Python dict (with single quotes) by falling back to `ast.literal_eval`. This is legacy behaviour; the API today always sends valid JSON. Don't remove the fallback unless you're sure no in-flight callers rely on it.

### 5.7 Admin keys vs user keys

The `/github` endpoint **only** accepts admin keys with `service='GitHub'` or `service='admin'`. A regular user API key returns `401`. This is by design: GitHub events are internal/operational, not per-user. Don't soften this gate.

### 5.8 V2 webhook routing

`/github` is in `_V2_WEBHOOK_PATHS` (`./api/api.py` line 718). This list opts the path **out** of V2's automatic `?api_key` → `X-API-KEY` header migration, which is required because GitHub's webhook UI cannot send custom headers. Do not move `/github` out of this list.

---

## 6. Quick reference — file map

| What | Where |
| --- | --- |
| Webhook receiver | `./api/api.py` lines ~2018–2062, function `handle_github_webhook` |
| Admin key auth | `./api/api.py` lines ~1142–1187, `verify_admin_key` / `verify_key` |
| V2 routing exemption | `./api/api.py` line 718, `_V2_WEBHOOK_PATHS` |
| WebSocket entry point | `./websocket/server.py` line ~831, `notify_http` |
| Service map | `./websocket/server.py` line ~770, `service_map` |
| Event broadcaster | `./websocket/server.py` lines ~1001–1033, `handle_github_event` |
| Global listener registration | `./websocket/server.py` lines ~552–556 |
| Admin keys table | `website.admin_api_keys` (column `service`) |
| Webhook URL (production) | `https://api.botofthespecter.com/github?api_key=<ADMIN_KEY>` |
| Forward URL (internal) | `https://websocket.botofthespecter.com/notify?code=...&event=GITHUB&data=...` |
| SocketIO event emitted | `GITHUB_EVENT` (to global listeners only) |

---

## 7. Adding GitHub features later — checklist

If you add downstream handling (e.g. Discord posts a message on `release`):

1. Subscribe a global listener via the existing admin SocketIO auth flow.
2. Listen for `GITHUB_EVENT` and branch on `payload.event`.
3. For `release.action`, read `payload.data.action` (it lives inside `data`, not at the top).
4. Handle `ping` gracefully — log it and return; don't post to Discord.
5. De-dup on `payload.delivery` if at-least-once delivery would cause duplicate user-visible side effects.

If you add a new event type to react to:

1. No code change is needed at the API layer — `/github` accepts everything.
2. Add the GitHub event in the repo's webhook settings (or "Send all events").
3. Add the handler in the consumer (Discord bot, etc.).

If you add outbound REST calls:

1. Add `GITHUB_TOKEN` to `/home/botofthespecter/.env` per [secrets.md](../../../rules/secrets.md).
2. Send `Authorization: Bearer …`, `User-Agent: BotOfTheSpecter/1.0`, `X-GitHub-Api-Version: 2022-11-28`.
3. Track quota in `website.api_counts` if calls are frequent (5,000/hr ceiling).
4. Cache release/repo metadata — most consumers don't need fresh-every-request data.

---

## 8. References

- GitHub webhook events & payloads: https://docs.github.com/en/webhooks/webhook-events-and-payloads
- Validating webhook deliveries: https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries
- REST API authentication: https://docs.github.com/en/rest/overview/authenticating-to-the-rest-api
- REST API rate limits: https://docs.github.com/en/rest/rate-limit
- Project rules: [../rules/data-flow.md](../../../rules/data-flow.md), [../rules/secrets.md](../../../rules/secrets.md), [../rules/database.md](../../../rules/database.md), [../rules/php-config.md](../../../rules/php-config.md).
