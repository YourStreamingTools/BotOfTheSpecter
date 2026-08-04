# Product API (`api.botofthespecter.com`)

Public FastAPI app (`api.py`): commands, points, webhooks, bot control proxy, extension, etc.

## Edge model (Caddy + loopback)

Same pattern as `bots_api` / `sql_api`:

| Piece | Value |
| ----- | ----- |
| Public URL | `https://api.botofthespecter.com` |
| App bind | `127.0.0.1:8080` (`API_HOST` / `API_PORT`) |
| TLS | **Caddy** (HTTP-01), not uvicorn |
| systemd | `fastapi.service` (tracked as `./api/fastapi.service`) |
| Caddyfile | `./api/Caddyfile` → `/etc/caddy/Caddyfile` on the **API** host |

```text
Internet → Caddy :443 → 127.0.0.1:8080 → uvicorn (HTTP only)
```

Cert renewals reload Caddy; the Python process does not need a restart for TLS.

### Env (Python)

| Variable | Default | Notes |
| -------- | ------- | ----- |
| `API_HOST` | (see note) | Set `127.0.0.1` with Caddy. If unset with legacy LE certs present → `0.0.0.0` |
| `API_PORT` | (see note) | Set `8080` with Caddy. If unset with legacy LE certs → `443` |
| `API_FORWARDED_ALLOW_IPS` | `127.0.0.1` | Trusted proxy peers for `X-Forwarded-*` |
| `API_SSL_CERTFILE` / `API_SSL_KEYFILE` | empty / auto-legacy | Explicit override; otherwise auto-detect LE paths when host/port unset |
| `WEBSOCKET_HEALTH_URL` | `https://websocket…/health` | Used by `/system/uptime` |
| `BOTS_HEALTH_URL` | `{BOTS_API_BASE}/health` | Used by `/system/uptime` |
| `WEB1_HEALTH_URL` / `SQL_HEALTH_URL` | empty | Optional uptime probes |
| `HEALTH_HTTP_TIMEOUT` | `5` | Seconds |

### Health contract

`GET /health` (no auth) — same shape as bots / websocket:

```json
{
  "ok": true,
  "service": "api",
  "started_at": "YYYY-mm-dd HH:MM:SS",
  "started_at_utc": "<ISO8601>",
  "uptime_seconds": 12345
}
```

## API docs (themed explorer)

Stock Swagger UI is **not** the primary UI. A dark dashboard-matching explorer lives in `./api/docs_ui/` and is served at:

| URL | Notes |
| --- | ----- |
| `/docs`, `/v2/docs` | Explorer (default V2) |
| `/v1/docs` | Same SPA; prefers V1 schema |
| `/openapi.json` | V1 OpenAPI |
| `/v2/openapi.json` | V2 OpenAPI |
| `/docs-static/*` | CSS/JS assets |

**Keep in sync:** `./api/docs_ui/` and `./bot/bots_api/docs_ui/` share the same CSS/JS design (duplicate co-located copies — do not cross-link dashboard CSS).

## Deploy / cutover checklist

**Fresh host (greenfield):** full OS → user → Python → Caddy → DNS runbook is in **[INSTALL-NEW-HOST.md](./INSTALL-NEW-HOST.md)** (Ubuntu LTS, including 26.04).

**Same-host edge flip** (already running Python, switching to Caddy loopback):

1. Deploy code with `API_HOST`/`API_PORT` support and `/health` + proxy-safe IP handling.
2. Install Caddy on the API host (`apt install caddy`); install `./api/Caddyfile`.
3. Confirm Cloudflare for `api.botofthespecter.com` is **DNS-only** (grey-cloud).
4. Stop process holding **:443**; start `fastapi.service` on **127.0.0.1:8080**; start/reload Caddy.
5. Verify: `curl -sS https://api.botofthespecter.com/health`, docs, a keyed route, webhooks, `/bot/status`.
6. Confirm `/system/uptime` is **not** 403 from the public internet.

**Rollback:** stop Caddy; run with `API_SSL_CERTFILE` / `API_SSL_KEYFILE` pointing at Let’s Encrypt paths and `API_PORT=443` / `API_HOST=0.0.0.0` if needed.

## Admin

Dashboard admin already targets unit name **`fastapi.service`** on the API SSH host — keep that name.
