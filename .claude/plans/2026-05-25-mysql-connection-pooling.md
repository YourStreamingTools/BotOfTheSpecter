# MySQL Connection Pooling Implementation Plan

## Goal

Move every dial-and-hang-up `aiomysql.connect(...)` call site across `bot/beta.py`, `websocket/server.py`, and `api/api.py` over to a shared pool-per-process pattern. `bot/beta.py` ships first as the pilot; the other two systems wait on beta's monitoring window before they go out.

## Architecture

One `aiomysql.Pool` per Python process, bound to the `website` database. That's the hot path — it's where the vast majority (95%+) of queries land. Per-user databases (the ones where the DB name is the channel/username) keep the existing one-shot `aiomysql.connect` pattern. They're low traffic, the DB names vary per channel, and pooling-by-name isn't worth the complexity. The pool gets created at process startup and closed during shutdown.

## Tech stack

`aiomysql` (already a dependency in all three systems), `asyncio`, no new packages.

## How we'll know it works

This codebase has no automated test suite, so verification means running the actual service and watching real behaviour: startup logs confirming the pool was created, DB queries succeeding, a stable connection count against MySQL, and a clean shutdown. I'm treating each of these observations as load-bearing rather than a formality.

## Hard rules (apply to every system)

- **`pool_recycle=3600` is mandatory.** The aiomysql default of `-1` means connections never recycle, and MySQL's `wait_timeout` will silently close idle ones. The first query through a dead connection then returns "MySQL server has gone away" and the whole thing looks flaky. This is the number-one reason pooling fails in practice, so it's not optional.
- **Always acquire with `async with pool.acquire() as conn:`** — never raw `acquire`/`release`. The context manager guarantees the connection is released even when an exception is thrown mid-query.
- **`autocommit=True` on the pool**, to match the current per-call behaviour.
- **The pool must exist before any code path that could query the DB.** In the FastAPI/aiohttp services that means creating it in the startup hook, not at module load.
- **The pool is closed in the shutdown hook** with `pool.close()` followed by `await pool.wait_closed()`.

The pool sizing I'm standardising on everywhere: `minsize=2`, `maxsize=20`, `connect_timeout=10`. That caps each process at 20 connections, which matters for the connection budget below.

## Rollout discipline

This goes out one system at a time, with a real soak window between each:

1. **Phase 1** — ship `bot/beta.py` first, monitor for at least 7 days. If stable, proceed.
2. **Phase 2** — ship `websocket/server.py`, monitor for at least 7 days. If stable, proceed.
3. **Phase 3** — ship `api/api.py`, monitor.

**`bot/bot.py` (stable) is never touched here.** When beta promotes to stable, this pooling work rides along with it.

---

## Files affected

No new files. The pool lives on existing module or class scope in each system.

| File | What changes |
| ---- | ------------ |
| `bot/beta.py` | `mysql_connection()` routes the `website` DB through the pool; new init/close helpers wired into the startup and shutdown paths. The existing `DirectConnection` wrapper stays for the per-user-DB path. |
| `websocket/server.py` | The server class gains a pool attribute, created in `on_startup` and closed in `on_shutdown`. `get_database_connection`, `execute_query`, and `test_database_connection` all move onto the pool. |
| `api/api.py` | FastAPI startup/shutdown lifecycle creates and closes a module-level pool; `get_mysql_connection` acquires from it; `verify_admin_key` (which currently builds a fresh pool on every call — an anti-pattern) is fixed; and roughly 60 dial-and-hang-up call sites get migrated. |

---

## Phase 1: `bot/beta.py` (pilot)

The pilot establishes the pattern the other two systems will copy. Work proceeds roughly in this order.

**Module-level pool global.** Introduce a `_db_pool` module global (initially `None`) alongside the existing `_MySQLCompat` handler. This is the single shared pool for the `website` DB. The intent, captured in a comment near the global, is that per-user DBs keep going through one-shot connections — only `website` traffic uses the pool.

**Init and close helpers.** Add an async `init_db_pool()` that creates the pool against `db='website'` using the host/user/password/port already configured in the module, with `minsize=2`, `maxsize=20`, `autocommit=True`, `pool_recycle=3600`, and `connect_timeout=10`. It must be idempotent: if `_db_pool` is already set, it's a no-op, so a reconnect doesn't leak a second pool. On success it logs a one-line confirmation (min/max/recycle); on failure it logs the error and leaves `_db_pool` as `None` so the code falls back to one-shot connections rather than crashing. A matching `close_db_pool()` does `close()` then `await wait_closed()`, logs cleanly, and resets the global to `None`. While doing this, confirm `aiomysql` itself is imported at the top (the module currently imports `connect as sql_connect`); add a plain `import aiomysql` if it isn't already there.

**Initialise the pool at startup.** The pool needs to come up before any other startup work touches the DB. The natural home is the very first action inside the bot's `event_ready` handler: call `await init_db_pool()`, and if it comes back `None`, log that the bot is falling back to one-shot connections so the failure is visible in the logs rather than silent.

**Close the pool at shutdown.** In the async signal-cleanup path, after the other in-flight cleanup has had a chance to finish, call `await close_db_pool()` as the last DB-related step.

**A pool-backed connection wrapper.** The callers expect the same interface that `DirectConnection` exposes today (`cursor`, `commit`, `rollback`, `close`, plus async context-manager support). Add a `PooledConnection` wrapper with that same surface, with one crucial difference: its `close()` releases the connection back to the pool instead of tearing it down. Guard against double-release so a connection isn't returned twice. Because the interface matches `DirectConnection`, callers don't need to know or care which kind of connection they were handed.

**Route `mysql_connection()` through the pool.** `mysql_connection(db_name=None)` defaults `db_name` to the channel name. The new logic: when `db_name == 'website'` and the pool exists, acquire from the pool and hand back a `PooledConnection`. Otherwise — per-user DB, or the pool failed to initialise — fall back to the original one-shot `sql_connect` wrapped in `DirectConnection`. This keeps the per-user path (which is where `db_name` equals the channel name) exactly as it is today.

### Verifying beta before deploy

Run the bot against a staging/test channel and read the behaviour rather than trusting the diff:

- The startup log should show the pool-created line within a second or two. If instead it shows the pool-creation failure line, the bot has dropped to one-shot mode — fix the underlying connection error before going further.
- Fire commands that hit global state (`!points`, `!convert`, `!ai`). They should succeed with no "MySQL server has gone away" and no release errors.
- Fire commands that hit channel-local state (`!quote`, `!deaths`, `!todolist`). These run against `db_name=CHANNEL_NAME`, so they stay on the one-shot path and should behave exactly as before — no pool log lines for these.
- Trigger a graceful shutdown (Ctrl+C / SIGTERM) and confirm the pool-closed-cleanly line appears with no errors during cleanup.
- With the bot running, `SHOW PROCESSLIST` on MySQL should show 2 idle connections from the bot (matching `minsize=2`), able to grow toward 20 under load and settle back toward 2 after a minute idle.

The file should of course still pass a Python syntax check after each change.

### Deploy beta and soak for 7 days

Deploy beta through the existing mechanism and confirm the pool-created line shows up in the production log on the next restart. Then watch for a week:

- Each day, the production log should contain zero occurrences of "MySQL server has gone away" or "Lost connection during query". If any show up, `pool_recycle=3600` is likely longer than the server's `wait_timeout` — drop it to 600 and redeploy.
- After the first 24 hours, `SHOW PROCESSLIST` should show a steady 2–20 connections from the beta bot. A count that keeps climbing past 20 over hours means a leak that needs investigating.

Seven clean days (no gone-away errors, no hangs, no connection growth) means beta is stable and Phase 2 can start. If problems surface, revert the beta pool changes and understand the cause before applying any of this to the websocket or API.

---

## Phase 2: `websocket/server.py`

Only start this once Phase 1 has been stable in production for at least 7 days.

**Pool attribute on the server class.** Add `self.db_pool = None` in `BotOfTheSpecter_WebsocketServer.__init__`, with a note that it's created in `on_startup` and closed in `on_shutdown`.

**Create the pool in `on_startup`.** As the first action in `on_startup`, before any of the existing startup tasks run, read `SQL_HOST` / `SQL_USER` / `SQL_PASSWORD` / `SQL_PORT` from the environment, and only build the pool if all four are present (otherwise log that the SQL env vars are missing). Pool parameters match the standard set: `db='website'`, `minsize=2`, `maxsize=20`, `autocommit=True`, `pool_recycle=3600`, `connect_timeout=10`. Log success or failure. Doing this first matters because `test_database_connection` and every handler that queries the DB depend on the pool already existing.

**Close the pool in `on_shutdown`.** After the existing shutdown cleanup (once all clients have disconnected), close the pool with `close()` + `await wait_closed()`, log cleanly, and reset the attribute to `None`.

**Route `execute_query` through the pool.** When `database_name == 'website'` and the pool exists, acquire from the pool and run the query through a `DictCursor`, returning `fetchall()` for SELECTs and `rowcount` otherwise. For any non-`website` database, or if the pool failed to initialise, fall back to the existing one-shot `get_database_connection` path. Non-`website` queries are rare in this process, so the fallback is genuinely the cold path.

**Route `test_database_connection` through the pool.** Rewrite it to run a `SELECT 1` through a pool connection. If the pool is `None`, it returns failure with a clear log line.

**Move the connection test to after pool creation.** This is the one ordering subtlety. Today `test_database_connection` is called inside `run_app`, before the aiohttp loop starts — which is before `on_startup` runs. Once the pool is created in `on_startup`, that early call would always see `self.db_pool is None` and fail. So the early call in `run_app` is removed (replaced by a comment noting the test now lives in startup), and a `test_database_connection` call is added in `on_startup` immediately after the pool-creation block. A failed test there logs a warning but lets the server continue starting, matching today's behaviour.

### Verifying the websocket locally

Start the server in local dev mode (no certs):

- The startup logs should appear in order: the server banner, the startup-tasks line, the pool-created line, then a successful DB connection test, then the usual background-task startup (SSH cleanup, TTS processing, etc.).
- From a whitelisted IP, hit `/notify` with a valid API key and a known event. It should return success with no errors — that path runs `verify_user_key`, which runs `execute_query`, which now uses the pool.
- `SHOW PROCESSLIST` should show a couple of connections from the websocket process, peaking at a handful under repeated `/notify` calls and staying well under 20.
- On Ctrl+C, the shutdown logs should end with the pool-closed-cleanly line.

The file should pass a Python syntax check.

### Deploy the websocket and soak for 7 days

Deploy through the existing mechanism, then check the noti server log each day for a week — zero "MySQL server has gone away" / "Lost connection during query". If any appear, lower `pool_recycle` and redeploy. Seven clean days clears Phase 3.

---

## Phase 3: `api/api.py`

Only start this once Phase 2 has been stable in production for at least 7 days. This is the largest surface — roughly 64 DB call sites.

**Audit the call sites first.** Pull every `await get_mysql_connection`, `aiomysql.connect`, and `aiomysql.create_pool` reference in `api.py` (expect around 64) into a working list, and tag each one:

- **WEBSITE** — uses `get_mysql_connection()` or connects to `db="website"`. These migrate to the pool.
- **PER_USER** — uses `get_mysql_connection_user(username)` or connects to a channel-named DB. These stay one-shot, deliberately.
- **BAD_POOL** — builds an inline `aiomysql.create_pool(...)` per call (the anti-pattern: a whole new pool every invocation). These migrate to the shared pool. The known offender is `verify_admin_key`.

This step is research only; nothing changes yet. The categorised list drives the rest of the phase.

**Add the app-level pool.** Introduce a module-level `_db_pool` global (with the same per-user-DBs-stay-one-shot note as the bot), and wire FastAPI `startup` / `shutdown` event handlers around the `app = FastAPI(...)` definition. Startup builds the pool with the standard parameters (`db='website'`, min 2 / max 20, `autocommit=True`, `pool_recycle=3600`, `connect_timeout=10`) and logs success or failure, leaving `_db_pool` as `None` on failure. Shutdown closes and waits, logs cleanly, and resets the global.

**Route `get_mysql_connection` through the pool.** When the pool exists, return a connection acquired from it. When it doesn't (init failed), log a warning and fall back to a one-shot connection so the API serves in a degraded mode rather than 500-ing every request.

The key behavioural detail that makes this low-risk: in aiomysql, calling `close()` on a connection that was acquired from a pool *releases it back to the pool* rather than tearing it down. So every existing caller written as `conn = await get_mysql_connection()` / `try: ... finally: conn.close()` keeps working unchanged — the connection just returns to the pool instead of being destroyed. New code is encouraged to use `async with _db_pool.acquire()` directly to avoid the release-on-close subtlety, but old code doesn't have to be rewritten to be correct.

**Fix `verify_admin_key`.** This is the BAD_POOL offender — it currently wraps its query in an inline `aiomysql.create_pool(...) as pool:` block, spinning up an entire pool on every admin-key check. Replace that wrapper with a guard (`if _db_pool is None`, log and return false) followed by `async with _db_pool.acquire() as conn:`. The cursor/query logic inside is unchanged; only the connection acquisition changes.

**Migrate the remaining WEBSITE call sites.** Work through the WEBSITE-tagged sites from the audit in manageable batches (about ten at a time), syntax-checking and committing per batch so progress is reviewable and revertable. Because `get_mysql_connection` now transparently returns a pooled connection and `conn.close()` releases it, most of these already work correctly after the `get_mysql_connection` change — the remaining work is largely cosmetic: converting the hand-rolled `try/finally: conn.close()` shape to the clearer `async with _db_pool.acquire()` form where the function is small and the rewrite is obviously safe. Call sites that span function boundaries can be left on `get_mysql_connection()` rather than forced into the explicit form. When the migration is done, only the PER_USER sites should remain on the one-shot path — which is intentional.

### Verifying the API locally

Start the API in dev and confirm the pool-created line shows on startup, then:

- Hit a couple of endpoints that read the `website` DB (e.g. system uptime, and a user-keyed endpoint that touches the DB). Both should return 200 with no log errors.
- Hit an endpoint that goes through `verify_admin_key` (a FreeStuff or GitHub admin-keyed endpoint). It should succeed with no "verify_admin_key called before pool ready" error — that error only appears if the pool wasn't ready in time, so its absence confirms startup ordering is right.
- `SHOW PROCESSLIST` should show 2–20 connections from the API process under traffic, with no growth past 20.
- Stopping the server should print the pool-closed-cleanly line.

The file should pass a Python syntax check after each batch.

### Deploy the API and soak for 7 days

Deploy through the existing mechanism, then check the API log each day for a week — zero gone-away / lost-connection errors. With all three phases live, the total connection ceiling across the platform is 3 systems × 20 = 60 connections, comfortably under the 10K server cap. Seven clean days means the migration is complete, and the project memory should be updated to record that pooling now covers beta + websocket + api, with `bot.py` (stable) inheriting it when beta promotes.

---

## Rollback procedure (per system)

Each phase is its own self-contained commit series, so any one of them can be reverted without disturbing the others. If a phase shows instability: revert just that phase's commits, redeploy, and — importantly — work out the root cause before re-attempting. Don't re-run a phase's migration until the failure is understood. The common failure modes (recycle longer than `wait_timeout`, connections not released on exception, pool created after a query path, etc.) are worth writing up in project memory once the migration is finished.

---

## Post-migration cleanup (optional)

After all three phases have been stable for at least 30 days, the fallback paths that exist purely as a safety net can be removed:

- The `if _db_pool is None` one-shot fallback in `api.py`'s `get_mysql_connection`.
- `get_database_connection` in `websocket/server.py`, which only exists to back `execute_query`'s fallback.
- The `db_name='website'` fallback branch in beta.py's `mysql_connection`.
- The `pool_recycle=3600` justification comments, once the rationale is common knowledge — though these are cheap and fine to leave.

These cleanups shrink the code surface but trade away a safety net that, by then, won't have been exercised. They're genuinely optional — keeping the belt-and-suspenders fallback is a reasonable choice.
