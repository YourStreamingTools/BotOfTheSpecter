# Bot-host control API

Internal process-control service on the bot host. Streamers do not call it directly.

Public clients (dashboard, mobile, scripts) use `https://api.botofthespecter.com/bot/*` with the **user** API key. The product API and server-side dashboard then call this host with an operator key. Start, stop, and status are HTTP — not SSH.

## Public surface (what streamers use)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `https://api.botofthespecter.com/bot/status` | Whether the chat bot is running |
| POST | `https://api.botofthespecter.com/bot/start` | Start the chat bot |
| POST | `https://api.botofthespecter.com/bot/stop` | Stop the chat bot |

Authenticate those routes with the streamer's own API key (`X-API-KEY`).

## Operator notes

- Create a dedicated operator key in Dashboard → Admin → API Keys. Do not reuse user keys.
- The product API and dashboard load that key from the database; do not put it in browser sessions or public config.
- Operator `/docs` on this host is not for end users.

Keep `docs_ui` CSS/JS in sync with `./api/docs_ui/` when changing the explorer.
