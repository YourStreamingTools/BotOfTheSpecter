# Path Conventions

The dev environment (Windows, this machine) and the production servers (Linux) lay files out differently. Don't bake one into the other.

## Rules

1. **Use `./` for repo paths in docs, memory, and comments.** Example: `./bot/bot.py`, not `P:\GitHub\BotOfTheSpecter\bot\bot.py` and not `/home/botofthespecter/bot/bot.py`.
2. **Server-side runtime paths must be labeled `(server)`.** These exist only on the production hosts:
   - `/home/botofthespecter/...` — bot/api/websocket configs, logs, AI history
   - `/var/www/config/` — dashboard config (dev equivalent: `./config/`)
   - `/var/www/cdn/`, `/var/www/usermusic/`, `/var/www/walkons/` — media storage
   - `/etc/letsencrypt/...` — SSL certs
   - `/mnt/s3/bots-stream/` — recording storage
3. **Dashboard config is split:** `./config/` exists in the repo for development; production reads from `/var/www/config/`. Never commit secrets into `./config/` files (see [secrets.md](./secrets.md)).
4. **In code, never assume an absolute path that hardcodes `P:\`, `C:\`, or a specific user home.** Use environment variables or relative paths.
5. **Logs are server-only.** Don't write code that expects `/home/botofthespecter/logs/` to exist locally — the dev box doesn't have it.
