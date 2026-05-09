# PHP Config Convention (HARD RULE)

## The rule

**PHP code NEVER reads from `.env` files.** PHP always loads config from `./config/{name}.php` where `{name}` matches the service or subsystem.

## Why

`.env` is a Python/Node convention. The PHP side of this project (dashboard, overlays, admin pages) standardised on per-service `.php` config files years ago. Mixing in `.env` parsing breaks deployment, breaks the "config lives in one place" model on the server, and means secrets end up in two locations instead of one.

## How to apply

1. **Need a credential or service config in PHP?** It belongs in `./config/{service}.php`. Examples already in the repo:
   - `./config/twitch.php` — all Twitch API config (OAuth client ID/secret, tokens)
   - `./config/database.php`, `./config/db_connect.php` — MySQL
   - `./config/spotify.php` — Spotify
   - `./config/discord.php` — Discord
   - `./config/streamelements.php`, `./config/streamlabs.php` — tipping platforms
   - `./config/openai.php`, `./config/ai.php` — AI services
   - `./config/cloudflare.php`, `./config/object_storage.php` — infrastructure
   - `./config/admin_actions.php`, `./config/main.php`, `./config/ssh.php`, `./config/iplocate.php`, `./config/brandfetch.php`, `./config/project-time.php`
2. **One service per file.** All Twitch settings go in `twitch.php` — don't split them across `twitch_oauth.php` and `twitch_api.php`. If you're adding a new service, create `{service}.php` and put everything for it there.
3. **Include via `require_once`** with the right relative path (`/var/www/config/{name}.php` on server, `./config/{name}.php` in dev — see [paths.md](./paths.md)).
4. **Never write `parse_ini_file('.env')`, `getenv()` for app secrets, `Dotenv` library calls, or any other `.env`-style loader in PHP.** If you see one in existing code, flag it.
5. **Python/Node code is unaffected.** The bot, API server, WebSocket server, and stream server still use `.env` — that's the right convention for those runtimes. This rule only governs PHP.
6. **New PHP file needs a credential?** First check if a matching `./config/{service}.php` already exists and extend it. Only create a new config file when the service genuinely doesn't have one yet.
