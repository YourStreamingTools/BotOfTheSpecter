---
name: bot-builtin-command-registration
description: "Adding a builtin bot chat command needs set registration + DB-config gating + json, not just the decorator"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 512bed90-6d7e-48c5-8c96-1be0bdf3a74c
---

Defining an `@commands.command(name='x')` method in `./bot/beta.py` is NOT enough to register a builtin command. A new builtin must also be wired into the registration chain or it runs un-gated and is invisible/unconfigurable.

Required when adding a builtin command (`x`):
1. Add `"x"` to the `builtin_commands` set (everyone-level) **or** `mod_commands` set (mod-level) near the top of `beta.py` (~line 146/153). `builtin_commands_creation()` (~line 13153) seeds the per-user `builtin_commands` DB table from `mod_commands ∪ builtin_commands` at startup; a command in neither set never gets a row.
2. In the command body, mirror `deaths_command` (~line 7659): `SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s`; honor `status=='Disabled'` (bypass for `bot_owner`); use the DB `permission` (not a hardcoded level); compute `bucket_key` + `check_cooldown(...)`; call `add_usage('x', bucket_key, cooldown_bucket)` on success.
3. Add an entry to `./api/builtin_commands.json` (mod commands carry `"force_level": "mod"`) — this feeds descriptions to `dashboard/builtin.php`.

**Cooldown bucket gotcha (per-viewer commands):** the `builtin_commands` table defaults `cooldown_time = 15` and `cooldown_bucket = 'default'`, and the gate maps `'default'` → `bucket_key='global'`. So a freshly-seeded command has a **15-second GLOBAL cooldown** — one chatter using it locks it for everyone. The dashboard bucket values are `default`=global, `user`=per-user (`bucket_key=str(ctx.author.id)`), `mod`. For commands that act on each chatter's OWN state (e.g. the Working & Study `task/done/now/later/soon/backlog/rename/remove/mytasks/project/projects/pomo`), seed them with `cooldown_bucket='user'`. The default seeding INSERT only writes `(command, status, permission)` — extend it to also write `cooldown_bucket` for the per-user set (see `per_user_cooldown_commands` + `builtin_commands_creation()` in beta.py). Note: seeding only inserts NEW command rows; rows already in a DB keep their bucket, so a test DB seeded before the fix needs a one-time `UPDATE builtin_commands SET cooldown_bucket='user' WHERE command IN (...)`.

**Why:** Without set registration the command (a) never reaches the per-user `builtin_commands` table, so the dashboard can't enable/disable it or set its permission/cooldown; (b) is skipped by `event_message`'s disable-gating (~line 3548), so it runs even when "disabled"; (c) is absent from `!commands` and the `COMMANDS_LIST` event. The command still *executes* (twitchio auto-registers the decorated method), which masks the gap.

**How to apply:** When adding any builtin command, do all three steps above, choosing the set by permission level. This was the exact miss on the `!craft` Makers command — defined but never registered. Stable-vs-beta still applies ([[project_websocket_wildcard]] is unrelated; see `.grok/rules/bot-versions.md`): new features land in `beta.py`; porting to `beta-v6.py` is optional, `bot.py` untouched.
