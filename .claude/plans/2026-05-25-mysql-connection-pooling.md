# MySQL Connection Pooling Implementation Plan

**Goal:** Migrate every dial-and-hang-up `aiomysql.connect(...)` call site across `bot/beta.py`, `websocket/server.py`, and `api/api.py` to a shared pool-per-process pattern, with `bot/beta.py` shipped first as the pilot and the other two systems waiting on beta's monitoring window before they ship.

**Architecture:** One `aiomysql.Pool` per Python process, bound to the `website` database (the hot path used 95%+ of the time). Per-user databases (where the database name is the channel/username) keep the existing one-shot `aiomysql.connect` pattern — low traffic, varied DB names, not worth pool-per-name complexity. The pool is initialised at process startup and closed during shutdown.

**Tech Stack:** `aiomysql` (already a dependency in all three systems), `asyncio`, no new packages.

**Verification model:** This codebase has no automated test suite. Each task verifies by running the actual service and observing real behavior — startup logs, DB query success, stable connections to MySQL, clean shutdown. Treat every verification step as load-bearing.

**Hard rules — apply to every system:**
- `pool_recycle=3600` is mandatory. The aiomysql default of `-1` means connections never recycle, and MySQL's `wait_timeout` will silently close idle ones — first query through a dead connection returns "MySQL server has gone away" and pooling looks flaky. This is the #1 reason pooling fails in practice.
- Always use `async with pool.acquire() as conn:` — never raw `acquire`/`release`. Context manager guarantees release even on exception.
- `autocommit=True` on the pool to match current per-call behaviour.
- Pool is created **before** any code path that could query the DB. In FastAPI/aiohttp this means the startup hook, not module-level.
- Pool is closed in the shutdown hook with `pool.close()` then `await pool.wait_closed()`.

**Rollout discipline:**
- **Phase 1:** Ship `bot/beta.py` first. Monitor for 7 days minimum. If stable, proceed.
- **Phase 2:** Ship `websocket/server.py`. Monitor for 7 days minimum. If stable, proceed.
- **Phase 3:** Ship `api/api.py`. Monitor.
- **Never modify `bot/bot.py`.** When beta promotes to stable, this pooling work goes with it.

---

## File Structure

**Files modified:**
- `bot/beta.py` — Modify `mysql_connection()` and lifecycle hooks. Existing `DirectConnection` wrapper stays for the per-user-DB code path.
- `websocket/server.py` — Modify `BotOfTheSpecter_WebsocketServer.__init__`, `on_startup`, `on_shutdown`, `get_database_connection`, `execute_query`, `test_database_connection`.
- `api/api.py` — Modify FastAPI lifecycle, `get_mysql_connection`, `verify_admin_key` (it currently creates a per-call pool — anti-pattern), and ~60 call sites that use the dial-and-hang-up pattern.

**Files created:** None. Pool lives on existing module/class scope in each system.

---

## Phase 1: `bot/beta.py` (pilot)

### Task 1: Add module-level db pool variable

**Files:**
- Modify: `bot/beta.py:474` (right after `mysql_handler = _MySQLCompat()`)

- [ ] **Step 1: Add pool import + module global**

Find `mysql_handler = _MySQLCompat()` at `bot/beta.py:474`. Immediately after that line, add:

```python
# Shared aiomysql pool for the 'website' database. Initialized at bot startup
# (see twitch_eventsub() / event_ready()) and closed during shutdown.
# Per-user DBs (db_name=CHANNEL_NAME or similar) still use one-shot connections
# via the existing mysql_connection() helper - low volume, varied DB names.
_db_pool = None
```

- [ ] **Step 2: Verify the file still parses**

Run: `python -c "import ast; ast.parse(open('bot/beta.py', encoding='utf-8-sig').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add bot/beta.py
git commit -m "beta: add module-level _db_pool stub for connection pooling"
```

---

### Task 2: Add pool initialisation helper

**Files:**
- Modify: `bot/beta.py` — add `init_db_pool()` async function next to the new `_db_pool` global from Task 1.

- [ ] **Step 1: Add the init helper**

Immediately below the `_db_pool = None` line you added in Task 1, add:

```python
async def init_db_pool():
    # Create the shared aiomysql pool for the website DB. Idempotent - if the
    # pool already exists, this is a no-op so reconnects don't duplicate pools.
    global _db_pool
    if _db_pool is not None:
        return
    try:
        _db_pool = await aiomysql.create_pool(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db='website',
            port=int(SQL_PORT) if SQL_PORT else 3306,
            minsize=2,
            maxsize=20,
            autocommit=True,
            pool_recycle=3600,
            connect_timeout=10,
        )
        bot_logger.info(f"[DB POOL] website pool created (min=2 max=20 recycle=3600s)")
    except Exception as e:
        bot_logger.error(f"[DB POOL] Failed to create website pool: {e}")
        _db_pool = None
```

- [ ] **Step 2: Add the close helper directly below**

```python
async def close_db_pool():
    global _db_pool
    if _db_pool is None:
        return
    try:
        _db_pool.close()
        await _db_pool.wait_closed()
        bot_logger.info("[DB POOL] website pool closed cleanly")
    except Exception as e:
        bot_logger.error(f"[DB POOL] Error during pool close: {e}")
    finally:
        _db_pool = None
```

- [ ] **Step 3: Confirm `aiomysql` is imported**

Run: `grep -n '^import aiomysql\|^from aiomysql' bot/beta.py`
Expected: at least one match. If none, add `import aiomysql` near the top of the imports block (next to `from aiomysql import connect as sql_connect`).

- [ ] **Step 4: Parse-check**

Run: `python -c "import ast; ast.parse(open('bot/beta.py', encoding='utf-8-sig').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 5: Commit**

```bash
git add bot/beta.py
git commit -m "beta: add init_db_pool / close_db_pool helpers"
```

---

### Task 3: Initialise the pool at bot startup

**Files:**
- Modify: `bot/beta.py:3311` (the `event_ready` method on `TwitchBot`)

- [ ] **Step 1: Locate event_ready**

Run: `grep -n 'async def event_ready' bot/beta.py`
Expected: one match around line 3311. Read 20 lines starting there to confirm the structure.

- [ ] **Step 2: Add pool init at the top of event_ready**

Find the first line of `event_ready`'s body (after the `async def` and any docstring). Insert as the very first action in the body:

```python
        # Initialize the shared DB pool before any other startup work touches the DB.
        await init_db_pool()
        if _db_pool is None:
            bot_logger.error("[DB POOL] Pool creation failed - bot will fall back to one-shot connections")
```

The indentation should match the existing method body (likely 8 spaces).

- [ ] **Step 3: Parse-check**

Run: `python -c "import ast; ast.parse(open('bot/beta.py', encoding='utf-8-sig').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add bot/beta.py
git commit -m "beta: initialize DB pool at bot startup (event_ready)"
```

---

### Task 4: Close the pool at bot shutdown

**Files:**
- Modify: `bot/beta.py:388` (the `async_signal_cleanup` async function)

- [ ] **Step 1: Locate the shutdown path**

Run: `grep -n 'async def async_signal_cleanup' bot/beta.py`
Expected: one match around line 388. Read the function body to find where other cleanup runs.

- [ ] **Step 2: Add pool close at the end of cleanup**

In `async_signal_cleanup`, just before the function returns (or at the end of the function body before any final logging), add:

```python
    # Close the DB pool last - after all in-flight queries have a chance to complete.
    await close_db_pool()
```

Indentation: 4 spaces (matches function body).

- [ ] **Step 3: Parse-check**

Run: `python -c "import ast; ast.parse(open('bot/beta.py', encoding='utf-8-sig').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add bot/beta.py
git commit -m "beta: close DB pool during graceful shutdown"
```

---

### Task 5: Route mysql_connection() through the pool for website DB

**Files:**
- Modify: `bot/beta.py:477-488` (the `mysql_connection` function and the `DirectConnection` returns)

- [ ] **Step 1: Add a pool-backed connection wrapper class**

Immediately above `class DirectConnection:` (around line 426), add:

```python
# Pool-backed connection wrapper. Releases the connection back to the pool on
# context exit instead of closing it. Same interface as DirectConnection so
# callers don't need to know which one they got.
class PooledConnection:
    def __init__(self, pool, connection):
        self._pool = pool
        self._connection = connection
        self._released = False

    def cursor(self, *args, **kwargs):
        return self._connection.cursor(*args, **kwargs)

    async def commit(self):
        if self._connection and not self._released:
            await self._connection.commit()

    async def rollback(self):
        if self._connection and not self._released:
            await self._connection.rollback()

    async def close(self):
        # Return the connection to the pool rather than actually closing it.
        if self._connection and not self._released:
            self._released = True
            try:
                self._pool.release(self._connection)
            except Exception as e:
                bot_logger.error(f"[DB POOL] Error releasing pool connection: {e}")

    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        await self.close()
        return False
```

- [ ] **Step 2: Modify mysql_connection() to prefer the pool**

Replace the body of `mysql_connection` (currently lines 477-488) with:

```python
async def mysql_connection(db_name=None):
    if db_name is None:
        db_name = CHANNEL_NAME
    # Pool path: only for the 'website' DB and only if the pool is initialized.
    # Per-user DBs (db_name == CHANNEL_NAME) stay on the one-shot path since
    # we don't pool by varying DB name.
    if db_name == 'website' and _db_pool is not None:
        conn = await _db_pool.acquire()
        return PooledConnection(_db_pool, conn)
    # Fallback / per-user DB: original one-shot connection
    conn = await sql_connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=db_name,
        autocommit=True,
        connect_timeout=10
    )
    return DirectConnection(conn)
```

- [ ] **Step 3: Parse-check**

Run: `python -c "import ast; ast.parse(open('bot/beta.py', encoding='utf-8-sig').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add bot/beta.py
git commit -m "beta: route website-DB calls through pool, keep per-user DB one-shot"
```

---

### Task 6: Verify locally before deploying

**Files:** none (verification only)

- [ ] **Step 1: Start the bot locally (or in a staging channel)**

Run the bot with its normal startup command (per the project's existing dev workflow).

- [ ] **Step 2: Confirm pool creation in logs**

Watch `bot.log` (or wherever `bot_logger` writes) for:

```
[DB POOL] website pool created (min=2 max=20 recycle=3600s)
```

Expected: present within 1-2 seconds of startup. If you see `[DB POOL] Pool creation failed`, the bot has fallen back to one-shot — fix the underlying error before continuing.

- [ ] **Step 3: Fire commands that hit the website DB**

In the test channel, run a few commands that touch global state — e.g. `!points`, `!convert`, `!ai`. Each should succeed and log normally.

Expected: no `MySQL server has gone away` errors. No `[DB POOL] Error releasing pool connection`. Commands return data as before.

- [ ] **Step 4: Fire commands that hit the per-user DB**

In the test channel, run commands that touch channel-local state — e.g. `!quote`, `!deaths`, `!todolist`. These use `db_name=CHANNEL_NAME`, which stays one-shot.

Expected: works as before (no change in behaviour, no pool logs for these — they go through the fallback path).

- [ ] **Step 5: Trigger graceful shutdown (Ctrl+C or SIGTERM)**

Stop the bot.

Expected: log line `[DB POOL] website pool closed cleanly` appears during shutdown. No errors during cleanup.

- [ ] **Step 6: Inspect MySQL session list while bot is running**

In a separate terminal, connect to MySQL and run:

```sql
SHOW PROCESSLIST;
```

Expected: 2 idle connections from the bot's IP/user (matches `minsize=2`). After firing several commands, may grow up to 20. After 60 seconds idle, drops back toward 2.

- [ ] **Step 7: Commit verification notes (if any) and move on**

No code commit unless step 1-6 surfaced fixes.

---

### Task 7: Deploy beta.py to prod and monitor for 7 days

**Files:** none (operational task)

- [ ] **Step 1: Deploy beta.py to the bot server**

Use your existing deploy mechanism for beta.

- [ ] **Step 2: Confirm pool initialization in production logs**

Tail the bot log on prod for the `[DB POOL] website pool created` line on next bot restart. Expected within 1-2 seconds of process start.

- [ ] **Step 3: Set up a daily check for `MySQL server has gone away`**

Run daily for 7 days:

```bash
grep -c "MySQL server has gone away\|Lost connection during query" /path/to/bot.log
```

Expected: 0 for each day. If any appear, `pool_recycle=3600` may be too high for your MySQL `wait_timeout` — drop it to 600 and redeploy.

- [ ] **Step 4: Watch MySQL connection count from beta**

After 24 hours, confirm steady state via `SHOW PROCESSLIST` on the SQL server. Expect 2-20 connections from the beta bot's IP. If you see the count climbing past 20 over hours, you have a leak — investigate.

- [ ] **Step 5: After 7 days clean, proceed to Phase 2**

If no `MySQL server has gone away` errors, no hangs, no connection-count growth: beta is stable. Move on.

If problems surfaced: revert the pool commits in beta and re-evaluate before applying to websocket or api.

---

## Phase 2: `websocket/server.py`

Only proceed if Phase 1 has been stable in production for 7 days minimum.

### Task 8: Add db pool attribute to the server class

**Files:**
- Modify: `websocket/server.py:93` (`BotOfTheSpecter_WebsocketServer.__init__`)

- [ ] **Step 1: Add self.db_pool**

Find `self.script_dir = ...` on line 97. Immediately above it, add:

```python
        # Shared aiomysql pool for the website DB. Initialized in on_startup,
        # closed in on_shutdown.
        self.db_pool = None
```

- [ ] **Step 2: Parse-check**

Run: `python -c "import ast; ast.parse(open('websocket/server.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add websocket/server.py
git commit -m "websocket: add self.db_pool attribute for connection pooling"
```

---

### Task 9: Initialise the pool in on_startup

**Files:**
- Modify: `websocket/server.py:1098` (the `on_startup` method)

- [ ] **Step 1: Add pool init at the top of on_startup**

Find `async def on_startup(self, app):` and insert as the **first action in the method body** (before the existing `self.logger.info("Starting application startup tasks...")`):

```python
        # Create the DB pool before anything else - test_database_connection,
        # any handler that queries the DB, etc., all rely on it.
        try:
            db_host = os.getenv('SQL_HOST')
            db_user = os.getenv('SQL_USER')
            db_password = os.getenv('SQL_PASSWORD')
            db_port = os.getenv('SQL_PORT')
            if all([db_host, db_user, db_password, db_port]):
                self.db_pool = await aiomysql.create_pool(
                    host=db_host,
                    user=db_user,
                    password=db_password,
                    db='website',
                    port=int(db_port),
                    minsize=2,
                    maxsize=20,
                    autocommit=True,
                    pool_recycle=3600,
                    connect_timeout=10,
                )
                self.logger.info("✓ DB pool created (db=website min=2 max=20 recycle=3600s)")
            else:
                self.logger.error("✗ Cannot create DB pool: SQL_* env vars missing")
        except Exception as e:
            self.logger.error(f"✗ DB pool creation failed: {e}")
```

- [ ] **Step 2: Parse-check**

Run: `python -c "import ast; ast.parse(open('websocket/server.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add websocket/server.py
git commit -m "websocket: create DB pool in on_startup before other startup work"
```

---

### Task 10: Close the pool in on_shutdown

**Files:**
- Modify: `websocket/server.py:1078` (the `on_shutdown` method)

- [ ] **Step 1: Add pool close after existing cleanup**

Find `async def on_shutdown(self, app):` and locate the end of the method body (after `self.logger.info("All clients disconnected.")`). Add immediately after that line:

```python
        # Close the DB pool last so any in-flight queries finish first.
        if self.db_pool is not None:
            try:
                self.db_pool.close()
                await self.db_pool.wait_closed()
                self.logger.info("✓ DB pool closed cleanly")
            except Exception as e:
                self.logger.error(f"Error closing DB pool: {e}")
            finally:
                self.db_pool = None
```

- [ ] **Step 2: Parse-check**

Run: `python -c "import ast; ast.parse(open('websocket/server.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add websocket/server.py
git commit -m "websocket: close DB pool cleanly in on_shutdown"
```

---

### Task 11: Refactor execute_query to use the pool

**Files:**
- Modify: `websocket/server.py:1249-1267` (the `execute_query` method)

- [ ] **Step 1: Replace the method body**

Replace the entire `async def execute_query(...)` method with:

```python
    async def execute_query(self, query, params=None, database_name='website'):
        # Pool path for the website DB. Other databases fall back to a one-shot
        # connection - rarely used in the websocket process.
        if database_name == 'website' and self.db_pool is not None:
            try:
                async with self.db_pool.acquire() as conn:
                    async with conn.cursor(aiomysql.DictCursor) as cursor:
                        await cursor.execute(query, params)
                        if query.strip().upper().startswith('SELECT'):
                            return await cursor.fetchall()
                        return cursor.rowcount
            except Exception as e:
                self.logger.error(f"Database query error: {e}")
                return None
        # Fallback: one-shot connection (for non-website DBs or if pool init failed)
        conn = None
        try:
            conn = await self.get_database_connection(database_name)
            if not conn:
                return None
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(query, params)
                if query.strip().upper().startswith('SELECT'):
                    return await cursor.fetchall()
                return cursor.rowcount
        except Exception as e:
            self.logger.error(f"Database query error: {e}")
            return None
        finally:
            if conn:
                conn.close()
```

- [ ] **Step 2: Parse-check**

Run: `python -c "import ast; ast.parse(open('websocket/server.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add websocket/server.py
git commit -m "websocket: route execute_query through pool for website DB"
```

---

### Task 12: Refactor test_database_connection to use the pool

**Files:**
- Modify: `websocket/server.py:1274-1295` (the `test_database_connection` method)

- [ ] **Step 1: Replace the method body**

Replace the entire `async def test_database_connection(self):` method with:

```python
    async def test_database_connection(self):
        self.logger.info("Testing database connection...")
        if self.db_pool is None:
            self.logger.error("✗ Database connection test failed - pool not initialized")
            return False
        try:
            async with self.db_pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute("SELECT 1")
                    result = await cursor.fetchone()
            if result:
                self.logger.info("✓ Database connection test successful")
                return True
            self.logger.error("✗ Database connection test returned no result")
            return False
        except Exception as e:
            self.logger.error(f"✗ Database connection test error: {e}")
            return False
```

- [ ] **Step 2: Parse-check**

Run: `python -c "import ast; ast.parse(open('websocket/server.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Note: test_database_connection is currently called in run_app BEFORE on_startup**

Run: `grep -n 'test_database_connection\|on_startup' websocket/server.py`

Expected output should include `run_app` calling `test_database_connection` before the aiohttp loop starts. With the pool now created in `on_startup`, the early call in `run_app` (line ~1146) will fail because `self.db_pool` is still None at that point.

- [ ] **Step 4: Remove the early test_database_connection call from run_app**

In `run_app` (around line 1146), delete the early DB test:

```python
        db_test_result = self.loop.run_until_complete(self.test_database_connection())
        if not db_test_result:
            self.logger.warning("⚠ Database connection test failed, but server will continue starting...")
```

Replace with:

```python
        # DB connection test now runs in on_startup after the pool is created.
```

Then in `on_startup`, immediately after the pool creation try/except block, add:

```python
        # Verify the pool actually works
        db_test_result = await self.test_database_connection()
        if not db_test_result:
            self.logger.warning("⚠ Database connection test failed, but server will continue starting...")
```

- [ ] **Step 5: Parse-check**

Run: `python -c "import ast; ast.parse(open('websocket/server.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add websocket/server.py
git commit -m "websocket: route test_database_connection through pool and move call to on_startup"
```

---

### Task 13: Verify websocket locally

**Files:** none (verification only)

- [ ] **Step 1: Start the websocket server**

Run with `--insecure` for local dev (no certs):

```bash
python websocket/server.py --insecure
```

Expected logs in order:
```
=== Starting BotOfTheSpecter Websocket Server ===
Starting application startup tasks...
✓ DB pool created (db=website min=2 max=20 recycle=3600s)
✓ Database connection test successful
... (SSH cleanup task started, TTS processing task started, etc)
```

- [ ] **Step 2: Trigger /notify with a valid API key**

From a whitelisted IP:

```bash
curl 'http://localhost:80/notify?code=YOUR_API_KEY&event=TWITCH_FOLLOW'
```

Expected: `{"success": 1, ...}` response. No errors in logs. The handler called `verify_user_key` which called `execute_query` which used the pool.

- [ ] **Step 3: Inspect MySQL connections**

```sql
SHOW PROCESSLIST;
```

Expected: 2-3 connections from the websocket process. After firing several /notify requests, may peak at 4-5 (still below maxsize=20).

- [ ] **Step 4: Stop the websocket server (Ctrl+C)**

Expected shutdown logs:
```
Shutting down...
... (existing cleanup logs)
All clients disconnected.
✓ DB pool closed cleanly
```

- [ ] **Step 5: No commit unless verification surfaced issues**

---

### Task 14: Deploy websocket to prod and monitor for 7 days

**Files:** none (operational task)

- [ ] **Step 1: Deploy websocket/server.py to the websocket host**

Per your existing deploy mechanism.

- [ ] **Step 2: Daily check for 7 days**

```bash
grep -c "MySQL server has gone away\|Lost connection during query" /home/botofthespecter/logs/noti_server.log
```

Expected: 0 daily. If any appear, lower `pool_recycle` and redeploy.

- [ ] **Step 3: After 7 days clean, proceed to Phase 3**

---

## Phase 3: `api/api.py`

Only proceed if Phase 2 has been stable in production for 7 days minimum.

### Task 15: Audit DB callsites in api.py

**Files:** none (research only)

- [ ] **Step 1: Count and categorise the DB callsites**

```bash
grep -n 'await get_mysql_connection\|aiomysql\.connect\|aiomysql\.create_pool' api/api.py | wc -l
```

Expected: ~64 matches. Save this number — you'll touch all of them.

- [ ] **Step 2: Categorise each match**

```bash
grep -n 'await get_mysql_connection\|aiomysql\.connect\|aiomysql\.create_pool' api/api.py > /tmp/api-db-callsites.txt
```

Open the file and tag each one as:
- `WEBSITE` — uses `get_mysql_connection()` or connects to `db="website"`. Migrate to pool.
- `PER_USER` — uses `get_mysql_connection_user(username)` or connects to `db=<channel>`. Keep one-shot.
- `BAD_POOL` — uses `aiomysql.create_pool(...)` inline (anti-pattern, creates a new pool per call). Migrate to shared pool. Known offender: `verify_admin_key` at line ~1142.

- [ ] **Step 3: No code change yet**

This task is research. Proceed to Task 16 with the categorised list in hand.

---

### Task 16: Add app-level db pool via FastAPI lifecycle

**Files:**
- Modify: `api/api.py` — add lifecycle handlers and a module-level pool global

- [ ] **Step 1: Find the FastAPI app instantiation**

```bash
grep -n '^app = FastAPI\|^app: FastAPI' api/api.py
```

Note the line number.

- [ ] **Step 2: Add module-level pool global**

Directly above the `app = FastAPI(...)` line, add:

```python
# Shared aiomysql pool for the 'website' database. Created at app startup,
# closed at shutdown. Per-user DBs (db=<username>) still use one-shot
# connections via get_mysql_connection_user() because varied DB names don't
# pool cleanly.
_db_pool = None
```

- [ ] **Step 3: Add startup/shutdown handlers below the app definition**

After the `app = FastAPI(...)` line, add:

```python
@app.on_event("startup")
async def _startup_db_pool():
    global _db_pool
    try:
        _db_pool = await aiomysql.create_pool(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db='website',
            port=SQL_PORT,
            minsize=2,
            maxsize=20,
            autocommit=True,
            pool_recycle=3600,
            connect_timeout=10,
        )
        logging.info("[DB POOL] website pool created (min=2 max=20 recycle=3600s)")
    except Exception as e:
        logging.error(f"[DB POOL] Pool creation failed: {e}")
        _db_pool = None


@app.on_event("shutdown")
async def _shutdown_db_pool():
    global _db_pool
    if _db_pool is None:
        return
    try:
        _db_pool.close()
        await _db_pool.wait_closed()
        logging.info("[DB POOL] website pool closed cleanly")
    except Exception as e:
        logging.error(f"[DB POOL] Error during pool close: {e}")
    finally:
        _db_pool = None
```

- [ ] **Step 4: Parse-check**

Run: `python -c "import ast; ast.parse(open('api/api.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 5: Commit**

```bash
git add api/api.py
git commit -m "api: add app-level DB pool via FastAPI startup/shutdown events"
```

---

### Task 17: Refactor get_mysql_connection to acquire from pool

**Files:**
- Modify: `api/api.py:1091-1098` (the `get_mysql_connection` function)

- [ ] **Step 1: Replace the function**

Replace the entire `async def get_mysql_connection():` function with:

```python
# Acquire a website-DB connection from the shared pool. Caller MUST call
# conn.close() (or use try/finally) to return it to the pool. To avoid the
# release-on-close confusion, prefer using `async with _db_pool.acquire()`
# directly in new code.
async def get_mysql_connection():
    if _db_pool is None:
        # Pool init failed - fall back to one-shot so the API can still serve
        # requests in degraded mode rather than 500-ing everything.
        logging.warning("[DB POOL] Pool unavailable - falling back to one-shot connection")
        return await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            port=SQL_PORT,
            db="website",
        )
    return await _db_pool.acquire()
```

Note: this changes the behaviour of `conn.close()` slightly. With a pooled connection, `close()` is a no-op (the connection stays alive in the pool) — but the pool reclaims it on garbage collection of the wrapper. In aiomysql specifically, calling `close()` on an acquired pool connection releases it back to the pool. So existing callers that do `try: ... finally: conn.close()` will Just Work — the connection returns to the pool instead of being torn down.

- [ ] **Step 2: Parse-check**

Run: `python -c "import ast; ast.parse(open('api/api.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add api/api.py
git commit -m "api: route get_mysql_connection through the shared pool"
```

---

### Task 18: Fix verify_admin_key (creates a new pool per call — anti-pattern)

**Files:**
- Modify: `api/api.py:1142-1170-ish` (the `verify_admin_key` function)

- [ ] **Step 1: Locate the function and the inline create_pool**

```bash
grep -n 'async def verify_admin_key\|aiomysql.create_pool' api/api.py
```

Confirm the inline `create_pool` lives inside `verify_admin_key`.

- [ ] **Step 2: Replace the inline pool with a shared-pool acquire**

Inside `verify_admin_key`, find the block:

```python
        async with aiomysql.create_pool(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db="website",
            port=SQL_PORT,
            autocommit=True
        ) as pool:
            async with pool.acquire() as conn:
                async with conn.cursor(aiomysql.DictCursor) as cur:
                    ...
```

Replace with:

```python
        if _db_pool is None:
            logging.error("[DB POOL] verify_admin_key called before pool ready")
            return False
        async with _db_pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                ...
```

Preserve everything inside the `async with conn.cursor(...)` block — the query logic is unchanged.

- [ ] **Step 3: Parse-check**

Run: `python -c "import ast; ast.parse(open('api/api.py').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add api/api.py
git commit -m "api: fix verify_admin_key to use shared pool instead of per-call pool"
```

---

### Task 19: Refactor remaining WEBSITE callsites in batches of 10

**Files:**
- Modify: `api/api.py` — every `WEBSITE`-tagged callsite from Task 15's audit

This task repeats: pick 10 callsites from the audit, refactor each, commit, repeat.

- [ ] **Step 1: Pick the next 10 WEBSITE callsites**

From `/tmp/api-db-callsites.txt`, pick the next 10 WEBSITE-tagged lines that still use the dial-and-hang-up pattern.

- [ ] **Step 2: Refactor each in place**

Each callsite typically looks like:

```python
conn = await get_mysql_connection()
try:
    async with conn.cursor(aiomysql.DictCursor) as cur:
        await cur.execute("SELECT ...", params)
        result = await cur.fetchone()
finally:
    conn.close()
```

After Task 17, this code already works through the pool transparently (because `get_mysql_connection` returns a pooled connection, and `conn.close()` releases it). **So existing call patterns continue to work without modification.**

The cleanup is purely cosmetic — moving to `async with` form for readability and to avoid future hand-rolled errors:

```python
async with _db_pool.acquire() as conn:
    async with conn.cursor(aiomysql.DictCursor) as cur:
        await cur.execute("SELECT ...", params)
        result = await cur.fetchone()
```

For batches that span function boundaries, you can leave callers untouched and just let them keep using `get_mysql_connection()`. Only refactor to the explicit `async with _db_pool.acquire()` form if the function is small and the refactor is obviously safe.

- [ ] **Step 3: Parse-check after every 10 callsites**

```bash
python -c "import ast; ast.parse(open('api/api.py').read()); print('OK')"
```

- [ ] **Step 4: Commit after every batch of 10**

```bash
git add api/api.py
git commit -m "api: migrate batch of website DB callsites to explicit pool acquire"
```

- [ ] **Step 5: Repeat until all WEBSITE callsites done**

Track progress in the audit file. When complete, only PER_USER callsites should remain on the one-shot path (intentional).

---

### Task 20: Verify api.py locally

**Files:** none (verification only)

- [ ] **Step 1: Start the API server locally**

Per existing dev workflow.

Expected startup logs:
```
[DB POOL] website pool created (min=2 max=20 recycle=3600s)
... (FastAPI startup output)
```

- [ ] **Step 2: Hit a few endpoints that query the website DB**

```bash
curl 'http://localhost:8000/system/uptime'
curl -H 'X-API-KEY: YOUR_USER_KEY' 'http://localhost:8000/some/endpoint-that-hits-db'
```

Expected: 200 OK responses. No errors in logs.

- [ ] **Step 3: Hit an endpoint that requires verify_admin_key**

Test a FreeStuff or GitHub admin-keyed endpoint. Confirm it works — this exercises the formerly-broken `verify_admin_key` pool path.

Expected: no `[DB POOL] verify_admin_key called before pool ready` error (means pool init happened in the right order).

- [ ] **Step 4: Check MySQL connection count**

```sql
SHOW PROCESSLIST;
```

Expected: 2-20 connections from the API process. After steady traffic, hovers near peak. No growth past 20.

- [ ] **Step 5: Stop the server**

Expected: `[DB POOL] website pool closed cleanly`.

- [ ] **Step 6: No commit unless verification surfaced issues**

---

### Task 21: Deploy api.py to prod and monitor for 7 days

**Files:** none (operational task)

- [ ] **Step 1: Deploy api/api.py to the API host**

- [ ] **Step 2: Daily check for 7 days**

```bash
grep -c "MySQL server has gone away\|Lost connection during query" /path/to/api.log
```

Expected: 0 daily.

- [ ] **Step 3: Watch the SQL server's overall connection count**

After Phases 1-3 all deployed: total connections across all systems = 60 max (3 systems × 20). Far below your 10K cap.

- [ ] **Step 4: After 7 days clean, the migration is complete**

Update the project memory: pool migration done across beta + websocket + api. `bot.py` (stable) inherits when beta promotes.

---

## Rollback procedure (per system)

If any phase shows instability:

- [ ] **Revert the commits for that phase only**

```bash
git revert <first-pool-commit>..<last-pool-commit>
```

Each phase is in its own commit series, so you can revert one without affecting the others.

- [ ] **Redeploy**

- [ ] **Investigate the root cause before re-trying**

Don't re-attempt the migration in the same phase until you understand why it failed. The five common failure modes are documented in the project memory at `[[websocket-pooling-notes]]` (to be written after the migration completes, or now if you prefer).

---

## Post-migration cleanup

After all three phases are stable for at least 30 days:

- [ ] **Delete `get_mysql_connection`'s fallback path** (the `if _db_pool is None` branch in api.py) — pool init has proven reliable, the fallback adds complexity without benefit.

- [ ] **Delete `get_database_connection` in websocket/server.py** — only called by the fallback path of `execute_query`. Once pool is proven, drop the fallback.

- [ ] **Delete `mysql_connection`'s fallback path in beta.py for `db_name='website'`** — same logic.

- [ ] **Delete `pool_recycle=3600` justification comments** — by then, the team knows. Or keep them, they're cheap.

These cleanups are optional. They reduce code surface but lose a safety net that hasn't been exercised. Skip if you prefer belt-and-suspenders.
