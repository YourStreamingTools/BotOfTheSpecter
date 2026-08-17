# Product API (`api.botofthespecter.com`)

Public FastAPI app (`api.py`): commands, points, webhooks, bot-control proxy, extension, and related routes.

## Public URLs

| URL | Notes |
| --- | ----- |
| `https://api.botofthespecter.com` | Product API |
| `/docs`, `/v2/docs` | Themed explorer (default V2) |
| `/v1/docs` | Same explorer; prefers V1 schema |
| `/openapi.json` | V1 OpenAPI |
| `/v2/openapi.json` | V2 OpenAPI |
| `/health` | Liveness (`ok`, `started_at`, `uptime_seconds`) |

V2 authenticated routes use the `X-API-KEY` header. V1 accepts `?api_key=` on legacy paths. Public and webhook routes do not require a key.

**Keep in sync:** `./api/docs_ui/` and `./bot/bots_api/docs_ui/` share the same CSS/JS design (duplicate co-located copies — do not cross-link dashboard CSS).

Operator host layout, secrets, and cutover steps are not documented in this repository.
