---
name: project_specter_app_token_stale_env
description: "Chat-send paths must read the Specter app token from the bot_chat_token DB table, not the stale os.getenv copy"
metadata: 
  node_type: memory
  type: project
  originSessionId: a63aa15d-b016-4c5d-99b7-55383e4ca747
---

The botofthespecter Helix "Send Chat Message" path needs the **app access token**. `os.getenv("TWITCH_OAUTH_API_TOKEN")` is read once at process start and goes **stale** when the token is rotated centrally → `401 {"message":"Invalid OAuth token"}`. The fresh token lives in the `website` DB `bot_chat_token` table; beta's `get_website_twitch_app_credentials()` reads it (and beta's CUSTOM_MODE uses the same app token with `sender_id = custom bot's channel id` to post as a module bot).

**Why:** env var is a one-shot snapshot; the DB row is the canonical, rotating source. Any code that builds a Helix chat Bearer from `os.getenv` will 401 after the next rotation.

**How to apply:** source the bearer + client_id from a cached `bot_chat_token` read with a 60s TTL, fall back to env, and invalidate the cache on 401/403 so the next send re-reads. Column names are autodetected (token candidates incl. `twitch_oauth_api_token`/`twitch_access_token`; client_id often absent → falls back to env, which is fine since client_id doesn't rotate).

Fixed so far:
- `bot/bot.py` `send_chat_message` — added `get_website_twitch_app_credentials()` (stable fix, shipped as v5.7.14, see [[project_stable_version_bump]]).
- `bot/custom_channel_modules/gfaundead.py` — `send_module_message` (JesterCatBot) + `_send_as_specter`; added module-level `_get_website_app_credentials()` using `self.mysql_handler`.

Still on env (check before assuming fixed): `beta-v6.py`, other `custom_channel_modules/*`. Related: [[project_php_twitch_credentials]] (PHP side: client ID comes from config/twitch.php, NOT this DB table).
