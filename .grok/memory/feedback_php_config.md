---
name: PHP must use ./config/{service}.php, never .env
description: Hard project rule — PHP code in BotOfTheSpecter never reads .env files; all credentials and service config load from per-service .php files in the config directory
type: feedback
originSessionId: eb3ecf15-5ee2-4c06-b4fc-9d58fa290fcc
---
PHP code in this project NEVER uses `.env` files. All PHP configuration and credentials must come from `./config/{service}.php` (production: `/var/www/config/{service}.php`), with one file per service named after the service itself — e.g. `twitch.php` holds all Twitch API config, `spotify.php` holds all Spotify config, `database.php` holds DB config.

**Why:** The PHP side of BotOfTheSpecter standardised on per-service `.php` config files. Mixing in `.env` parsing breaks the deployment model and splits secrets across two locations on the server. The user explicitly called this out as "one big rule" in 2026-05-09.

**How to apply:**
- When adding/modifying PHP code that needs a credential or service setting, load it via `require_once` from `./config/{service}.php`. Never `parse_ini_file('.env')`, `getenv()` for app secrets, or use any Dotenv-style library in PHP.
- One service per file — keep all of a service's config in its single `{service}.php`, don't fragment it.
- If a matching config file doesn't exist, create `./config/{newservice}.php` rather than reaching for `.env`.
- Python/Node code (bot, api, websocket, stream) is unaffected — those still use `.env` correctly.
- Full rule lives at `.grok/rules/php-config.md` in the repo.
