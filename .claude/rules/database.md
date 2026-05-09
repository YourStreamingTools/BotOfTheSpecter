# Database Rules

MySQL via `aiomysql`. Two database scopes exist; mixing them up will leak data between users.

## The two scopes

1. **Central `website` database** — global state.
   - `users` (accounts, API keys, OAuth tokens)
   - `admin_api_keys` (admin keys with service restrictions)
   - `api_counts` (rate-limit counters for Weather/Shazam/ExchangeRate)
   - `system_metrics`, `freestuff_games`, `bot_chat_token`, `twitch_bot_access`, `handoff_tokens`

2. **Per-user database** — one MySQL DB **per username** (DB name = username).
   - `custom_commands`, `builtin_commands`, `custom_user_commands`
   - `bot_points`, `chat_history`, `tipping`, `seen_today`
   - `game_deaths`, `per_stream_deaths`, `stored_redeems`, `bits_data`
   - `typos`, `lurkers`, `hugs`, `kisses`, `highfives`, `counts`, `watch_time`, `todos`

## Rules

1. **Always `await` MySQL calls.** All connections are async via `aiomysql` — a missing await silently breaks ordering.
2. **Use the right helper for the right scope:**
   - API: `get_mysql_connection()` for `website`, `get_mysql_connection_user(username)` for per-user.
   - Bot: `mysql_connection(db_name=None)` — `None` defaults to the channel's own DB; pass `"website"` for global.
3. **Never run a query against `website` that should be per-user, or vice versa.** Per-user data must stay isolated.
4. **Use parameterized queries.** No f-string SQL. Pass values via the cursor's parameter argument.
5. **Close cursors and connections** — use `async with` blocks where possible.
6. **Schema changes need to land in code AND be applied per database.** Per-user tables exist in many DBs; if you add a column, the migration has to iterate.
7. **The bot has SSH connections** (`SSHConnectionManager`) for remote ops to BOT-SRV / SQL / WEB / STREAM hosts. Use them for cross-host file work, not for SQL — SQL goes through the direct MySQL connection.
