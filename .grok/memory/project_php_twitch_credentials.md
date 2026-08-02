---
name: project_php_twitch_credentials
description: Where PHP gets the Twitch client ID + app token; bot_chat_token has NO client-id column and the column-guessing code is dead/misleading
metadata: 
  node_type: memory
  type: project
  originSessionId: 48978938-cd48-408f-b1a2-9f142e518ecc
---

For PHP that calls the Twitch API (Helix), the **client ID comes from `config/twitch.php` (`$clientID`)**, NOT from the database.

- `bot_chat_token` (central `website` DB) stores ONLY the app OAuth token. Real columns: `oauth`, `twitch_oauth_api_token`, `twitch_oauth_api_expires_at`. There is **no** `twitch_client_id` / `client_id` / `clientID` column.
- `config/twitch.php` provides `$clientID`/`$clientSecret` from the config file itself, and assembles `$oauth` (the bot app token) via its `botofthespecter_twitch_apply_db_override()` reading `bot_chat_token`. The token is an **app access token** minted by `client_credentials` using that same `$clientID`/`$clientSecret` (`dashboard/admin/twitch_tokens.php`), so `$clientID` + `$oauth` are a **matching pair** valid for Helix.
- Helix needs a **bare bearer token** — strip any IRC-style `oauth:` prefix.

**Why / the trap:** Both `config/twitch.php` (`botofthespecter_twitch_apply_db_override`) and (until fixed) `overlay/chat.php` guess client-id columns `twitch_client_id`/`client_id`/`clientID` that don't exist. The guess silently falls through, leaving the client ID empty. In `overlay/chat.php` this made the `if ($botClientId !== '' && $botOauth !== '')` Helix-badge gate always fail → `OVERLAY_BADGE_CACHE` shipped as `{}` → chat-overlay badges silently missing. The data path (bot sends `badges` as `"set_id/version,..."`) was fine.

**How to apply:** Need Twitch creds in a PHP page? `include '/var/www/config/twitch.php'` and use `$clientID` + `$oauth` — never query `bot_chat_token` for a client id. The dead column-guessing in `config/twitch.php` is harmless (no-ops to file values) but is a candidate for the DB-migrations cleanup [[project_db_migrations_admin_page]]. See also [[feedback_php_config]].
