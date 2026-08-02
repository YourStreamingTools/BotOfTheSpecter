---
name: network-architecture
description: All BotOfTheSpecter services use Cloudflare DNS-only (no proxy). Every webfacing server faces the public internet directly with no reverse proxy in front.
metadata: 
  node_type: memory
  type: project
  originSessionId: dee89d11-1795-4625-883b-5d8bdad8a2ca
  modified: 2026-07-28T23:26:45.410Z
---

All BotOfTheSpecter public services (`websocket.botofthespecter.com`, `api.botofthespecter.com`, the dashboard/website, etc.) use **Cloudflare in DNS-only mode** — Cloudflare resolves the hostname, but traffic goes straight to the origin server. There is **no reverse proxy** in front of any service.

**Why this matters:**
- `X-Forwarded-For` and `X-Real-IP` headers on inbound requests are **attacker-controlled** — they're not set by any legitimate proxy because there is no proxy in the path.
- Any code that reads these headers to determine the "real" client IP for auth or rate-limiting is spoofable. The correct source of truth is the direct TCP peer (`request.remote` in aiohttp, `request.client.host` in FastAPI/Starlette).
- The established fix pattern is to **reject** requests carrying these headers with a 403 + WARN log, so spoof attempts are visible in logs.

**How to apply:** When adding or auditing any inbound-request-handling code:
- Use the direct TCP peer attribute, not forwarded headers.
- If the existing whitelist/auth code reads X-Forwarded-For, treat it as a security bug — see the fix that landed in `./websocket/security_manager.py` for the pattern.
- Same pattern applies to `./api/api.py`, dashboard PHP, and any future web service.
- **When debugging any "stale content served" issue, do NOT float Cloudflare caching/proxying as a hypothesis.** It is DNS-only, full stop — there is nothing there to cache or intercept. Look at origin-server caching (opcache, app-level cache files, response headers) or the client (browser/extension/proxy) instead.

Confirmed by user 2026-05-24 during the websocket security fix pass. Re-confirmed emphatically by user 2026-07-29 after this got raised again during a status.php maintenance-banner caching investigation — treat as settled, do not re-litigate.
