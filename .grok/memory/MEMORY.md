## Project Memory Index - BotOfTheSpecter

- [BOT System](system_bot.md) - Twitch bot (stable/beta/v6): commands, **cooldowns**, **Working & Study tasks/timers**, EventSub, integrations, **bots-api process control**
- [API Server](system_api.md) - FastAPI data backbone: dual-auth, per-user DBs, webhooks, extension endpoints, **`/bot/*` → bots host**
- [WebSocket Server](system_websocket.md) - Real-time hub: 8 handlers, `TASK_*` / **`USER_POMO_*`**, **`OVERLAY_REFRESH`**, broadcasters
- [Secondary Systems](system_secondary.md) - Dashboard (**alerts**, builtin cooldowns), **~28 overlays**, RTMPS stream, Twitch extension, YourLinks, **bot control via bots API**

### Bot process control (quick pointer)

- **Private bots API** on bot host: `https://bots.botofthespecter.com` → `./bot/bots_api/`
- **Auth**: `website.admin_api_keys` service **`bots`** (not user API keys)
- **Dashboard**: `./dashboard/includes/bots_api_client.php` + `./config/bots_api.php`
- **Do not use SSH** for start/stop/status — see `.grok/rules/bots-api.md`
- **Username rename**: login stops old `-channel` via `bots_api_stop_all_for_channel` then renames per-user DB

**Last verified**: 2026-07-29
