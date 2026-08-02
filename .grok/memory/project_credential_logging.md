---
name: project_credential_logging
description: "The user's code/api_key is a live reusable auth credential — never log it in plaintext (found leaking in specterdiscord.py)"
metadata: 
  node_type: memory
  type: project
  originSessionId: 654307e4-31d5-4f8f-b2ce-7646d43ae6c2
  modified: 2026-07-23T04:00:31.222Z
---

The per-user `api_key` (a.k.a. the streamer's `code`/`channel_code`) is a **reusable authentication credential** — it authenticates the user across the platform (the `/notify` endpoint, websocket actions, `SELECT ... WHERE api_key = %s`). Treat it like a password: never write its raw value to logs, URLs, error messages, or query strings.

**Why:** anyone with log-file read access can harvest it and impersonate that streamer against the platform API. Parameterized SQL (`%s`) is fine — the leak is the log/URL output, not the query itself.

**How to apply:** in logs, print the resolved `user_id`/`username` or guild id (or a truncated/masked prefix), never the full key. Fixed real plaintext leaks 2026-07-23 in `bot/specterdiscord.py` (`get_user_id_from_api_key` + the stream-schedule handler). Relates to [[project_custom_inbound_webhooks]] (never forward the master key) and [[reference_twitch_oauth_token_semantics]].
