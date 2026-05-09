# Secrets & Credentials

This project handles a lot of OAuth tokens (Twitch, Spotify, Discord, Kick, StreamElements, StreamLabs, Patreon, Ko-fi, Fourthwall) and API keys (OpenAI, Weather, Steam, Shazam, ExchangeRate, HypeRate, Hetrixtools). Treat all of them as production secrets.

## Rules

1. **Never hardcode a key, token, password, or client secret in source files.** Read from environment variables (`os.getenv(...)`) or the appropriate database table. **PHP is the exception** — see [php-config.md](./php-config.md): PHP loads from `./config/{service}.php`, not `.env`.
2. **Never commit `.env` files.** They live on the server only — typically `/home/botofthespecter/.env` for the bot/api/websocket and equivalent paths for the stream server. PHP runtimes never read these.
3. **Never echo or log a token in full.** If you need to confirm a token loaded, log a length or last-4 only.
4. **`./config/*.php` files in this repo do not hold real secrets.** Production credentials live in `/var/www/config/` on the server. If you see real-looking values in `./config/` while editing, stop and warn the user before committing.
5. **OAuth refresh tokens are stored in the database** (`spotify_tokens`, `streamelements_tokens`, `discord_users`, `twitch_bot_access`, `bot_chat_token`). Don't move them to env vars or vice versa without coordinating — the refresh scripts assume the current location.
6. **Webhook signature verification is mandatory** for endpoints that support it (GitHub, Kick). Removing or bypassing the check is a security regression — never do it "to debug" without putting it back.
7. **Admin API keys have a `service` field.** Super-admin (`service='admin'`) gets everything; service-specific keys (e.g. `service='FreeStuff'`, `service='GitHub'`) only access their lane. Never widen a service-scoped key to admin without explicit approval.
8. **If a secret leaks** (committed by mistake, posted in a PR, shared in chat): tell the user immediately. Do not silently rotate or rewrite history.
