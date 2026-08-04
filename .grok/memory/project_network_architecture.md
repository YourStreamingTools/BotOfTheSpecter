---
name: network-architecture
description: All BotOfTheSpecter services use Cloudflare DNS-only (no proxy). Every webfacing server faces the public internet directly with no reverse proxy in front.
metadata: 
  node_type: memory
  type: project
  originSessionId: dee89d11-1795-4625-883b-5d8bdad8a2ca
  modified: 2026-07-28T23:26:45.410Z
---

All BotOfTheSpecter public services use **Cloudflare in DNS-only mode** — Cloudflare resolves the hostname, but traffic goes to the origin (not through CF’s HTTP proxy/cache).

**Origin reverse proxies (Caddy) do exist on some hosts** — they are not Cloudflare:
- `bots.botofthespecter.com` → Caddy → `127.0.0.1:8090` (bots-api)
- `sql.botofthespecter.com` → Caddy → `127.0.0.1:8091` (sql-api)
- `api.botofthespecter.com` → Caddy → `127.0.0.1:8080` (product API; preferred deploy)
- Web PHP host also uses Caddy for dashboard/static sites

**Why this matters for client IP:**
- From the public internet, `X-Forwarded-For` / `X-Real-IP` on a **direct** connection (no local Caddy peer) remain **attacker-controlled** — reject or ignore them.
- When the app binds **loopback only** and Caddy is the sole peer, trust `X-Forwarded-For` **only if `request.client.host` is loopback** (`127.0.0.1` / `::1`). See `_client_ip()` in `./api/api.py`.
- Cross-host uptime probes use each service’s public `GET /health` JSON (`started_at`, `uptime_seconds`) — not SSH markers.

**How to apply:** When adding or auditing inbound-request-handling code:
- Default: use the direct TCP peer; do not trust forwarded headers from non-loopback peers.
- Behind Caddy on loopback: use the loopback-only trust pattern above for rate limits / whitelist.
- **When debugging any "stale content served" issue, do NOT float Cloudflare caching/proxying as a hypothesis.** CF is DNS-only — look at origin caching or the client instead.

Confirmed by user 2026-05-24 during the websocket security fix pass. Re-confirmed emphatically by user 2026-07-29 after this got raised again during a status.php maintenance-banner caching investigation — treat as settled, do not re-litigate.
