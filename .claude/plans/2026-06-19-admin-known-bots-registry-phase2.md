# Known-Bots Registry — Phase 2 (Bot Consumers) Implementation Plan

**Goal:** Make the Twitch bot consume the global `website.known_bots` registry — unioned with the existing per-channel exclusion lists — so known bots stop accruing watch time, earning points, and being welcomed/auto-shouted-out.

**Architecture:** Each of `beta.py` and `beta-v6.py` gains a module-level lazy-TTL cached loader `get_known_bots()` that reads `website.known_bots` and returns a lowercased set. That set is unioned in at four call sites: watch-time tracking, points award, the `!points` lookup, and the welcome/auto-shoutout decision. The per-channel lists keep working; the global set is additive. No schema, API, or dashboard change.

**Tech Stack:** Python 3 / TwitchIO (2.10 for `beta.py`, 3.2.2 for `beta-v6.py`) / aiomysql via the bot's `mysql_connection` helper.

**Spec:** `.claude/specs/2026-06-19-admin-known-bots-registry-phase2.md`

## Global Constraints

- **No test framework exists.** Verify with `python -m py_compile <file>`. Do NOT add pytest.
- **Only `beta.py` and `beta-v6.py`.** `bot.py` (stable) must NOT be touched (bot-version policy).
- **TwitchIO API differs between the two files** (2.10 vs 3.2.2). Do NOT assume a `beta.py` edit drops into `beta-v6.py` verbatim — each task uses its file's own DB idiom and call sites.
- **Do NOT change `message_counts` behaviour.** Known bots must still be *counted* when they chat; Phase 2 only skips welcome/shoutout/points/watch-time for them.
- **DB rule:** parameterized read; the `known_bots` table is in the central `website` DB — read it via `mysql_connection("website")`, never per-user.
- **Apply edits by matching the shown current code by content** (line numbers below are approximate and may have drifted). Read the live function before editing.
- **The registry is lowercased**; normalise both sides of every membership check to lowercase.

---

### Task 1: `beta.py` (TwitchIO 2.10) — consume the global registry

**Files:**
- Modify: `./bot/beta.py` — add the loader (below the `# Functions for all the commands` marker, ~line 11115); union at `track_watch_time` (~16190), points award (~4152), `!points` lookup (~4980), and the welcome/auto-shoutout path (`message_counting_and_welcome_messages` ~3822 and the first-message check ~3965).

**Interfaces:**
- Consumes: `mysql_connection(db_name)` (beta.py:490), `DictCursor`, `time`, `bot_logger` — all already imported/used in this file.
- Produces: `get_known_bots() -> set` (module-level), used by the four call sites in this task.

- [ ] **Step 1: Add the cached loader**

Insert immediately below the `# Functions for all the commands` marker (~line 11115), as a module-level function:

```python
# Global known-bots registry (website.known_bots), cached. Phase 2: unioned into the
# per-channel exclusion lists for watch-time, points, and welcome/shoutout.
KNOWN_BOTS_CACHE_TTL = 300  # seconds
_known_bots_cache = {"loaded_at": 0.0, "bots": frozenset()}

async def get_known_bots():
    # Returns a lowercased set of active global bot logins from website.known_bots.
    # Lazy TTL cache; on any error returns the last good cache (or empty set) so callers
    # degrade gracefully to per-channel-only exclusion (i.e. today's behaviour).
    global _known_bots_cache
    now = time.time()
    if _known_bots_cache["loaded_at"] and (now - _known_bots_cache["loaded_at"]) < KNOWN_BOTS_CACHE_TTL:
        return set(_known_bots_cache["bots"])
    bots = set(_known_bots_cache["bots"])  # fall back to last good cache on error
    connection = None
    try:
        connection = await mysql_connection("website")
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT bot_login FROM known_bots WHERE is_active = 1")
            rows = await cursor.fetchall()
            bots = {str(r["bot_login"]).strip().lower() for r in rows if r.get("bot_login")}
        _known_bots_cache = {"loaded_at": now, "bots": frozenset(bots)}
    except Exception as e:
        bot_logger.error(f"[KNOWN BOTS] Failed to load known_bots from website DB: {e}")
    finally:
        if connection:
            await connection.close()
    return set(bots)
```

> Verify before moving on: confirm `DictCursor`, `time`, and `bot_logger` are the symbols this file already uses (they appear in `track_watch_time`). If the connection close idiom in this file differs (e.g. `connection.release()`), match whatever `track_watch_time` uses for its `finally` cleanup.

- [ ] **Step 2: Union into watch-time tracking**

In `track_watch_time` (~16188-16192), find:

```python
            excluded_users = excluded_users_data['excluded_users'] if excluded_users_data else ''
            excluded_users_list = excluded_users.split(',') if excluded_users else []
            # Filter active users to exclude those in the list
            non_excluded_users = [user for user in active_users if user['user_login'] not in excluded_users_list]
```

Replace with:

```python
            excluded_users = excluded_users_data['excluded_users'] if excluded_users_data else ''
            excluded_users_list = excluded_users.split(',') if excluded_users else []
            # Phase 2: union the per-channel list with the global known-bots registry
            excluded_logins = {u.strip().lower() for u in excluded_users_list if u.strip()} | await get_known_bots()
            non_excluded_users = [user for user in active_users if user['user_login'].lower() not in excluded_logins]
```

- [ ] **Step 3: Union into points award**

In the points-award path (~4152-4154), find:

```python
            excluded_users = [user.strip().lower() for user in settings['excluded_users'].split(',')]
            author_lower = messageAuthor.lower()
            if author_lower not in excluded_users:
```

Replace with:

```python
            excluded_users = [user.strip().lower() for user in settings['excluded_users'].split(',')]
            excluded_users = set(excluded_users) | await get_known_bots()  # Phase 2: + global known bots
            author_lower = messageAuthor.lower()
            if author_lower not in excluded_users:
```

- [ ] **Step 4: Union into the `!points` lookup**

In the `!points` command lookup (~4980-4981), find:

```python
                        if settings and 'excluded_users' in settings:
                            excluded_users = [u.strip().lower() for u in settings['excluded_users'].split(',')]
                            if target_user_name in excluded_users:
```

Replace with (also normalises `target_user_name` to lowercase, fixing a latent case mismatch):

```python
                        if settings and 'excluded_users' in settings:
                            excluded_users = set(u.strip().lower() for u in settings['excluded_users'].split(',')) | await get_known_bots()
                            if target_user_name.lower() in excluded_users:
```

- [ ] **Step 5: Skip welcome/auto-shoutout for known bots — WITHOUT affecting `message_counts`**

In `message_counting_and_welcome_messages`, the `message_counts` INSERT runs first and must keep
running for known bots. Find the INSERT + commit (~3817-3822):

```python
                await cursor.execute(
                    'INSERT INTO message_counts (username, message_count, user_level) VALUES (%s, 1, %s) '
                    'ON DUPLICATE KEY UPDATE message_count = message_count + 1, user_level = %s',
                    (messageAuthor, user_level, user_level)
                )
                await connection.commit()
```

Replace with (adds an early return AFTER counting, so the bot is counted but not welcomed/shouted-out):

```python
                await cursor.execute(
                    'INSERT INTO message_counts (username, message_count, user_level) VALUES (%s, 1, %s) '
                    'ON DUPLICATE KEY UPDATE message_count = message_count + 1, user_level = %s',
                    (messageAuthor, user_level, user_level)
                )
                await connection.commit()
                # Phase 2: known bots are counted above, but never welcomed or auto-shouted-out.
                if messageAuthor.lower() in await get_known_bots():
                    return
```

> Confirm this return is inside `message_counting_and_welcome_messages` and that nothing after the
> commit (other than seen_today bookkeeping + welcome/shoutout) must run for a known bot. The whole
> point is to skip the welcome + auto-shoutout that follow; returning here does that. Do NOT move the
> check above the INSERT — that would stop counting the bot (forbidden by the Global Constraints).

- [ ] **Step 6: Skip the first-message welcome for known bots**

In the first-message welcome check (~3965), find:

```python
        if not messageAuthor or messageAuthor.lower() in IGNORED_WELCOME_USERNAMES or messageAuthor.lower() == CHANNEL_NAME.lower():
            return
```

Replace with:

```python
        if not messageAuthor or messageAuthor.lower() in IGNORED_WELCOME_USERNAMES or messageAuthor.lower() in await get_known_bots() or messageAuthor.lower() == CHANNEL_NAME.lower():
            return
```

> Confirm the enclosing function is `async` (it must be, to `await`). If this exact condition appears
> in more than one place, apply it only in the welcome/first-message function identified by the
> surrounding context.

- [ ] **Step 7: Verify**

Run:
```bash
python -m py_compile bot/beta.py
```
Expected: exits 0, no output.

Then confirm the loader and all four call sites are wired:
```bash
grep -n "async def get_known_bots" bot/beta.py        # expect 1
grep -c "await get_known_bots()" bot/beta.py          # expect 5 (watch-time, points award, points lookup, welcome skip, first-message)
```

---

### Task 2: `beta-v6.py` (TwitchIO 3.2.2) — consume the global registry

**Files:**
- Modify: `./bot/beta-v6.py` — add the loader (below the `# Functions for all the commands` marker, ~line 9374); union at `track_watch_time` (~13555), points award (~3785), `!points` lookup (~4632), and `send_first_command_welcome_if_needed` (~3695).

**Interfaces:**
- Consumes: `mysql_handler.get_connection(db_name=...)` / `DirectConnection` (v6:358-422), `DictCursor`, `time`, `bot_logger` — all already used in this file (see `get_website_twitch_app_credentials` ~430-468).
- Produces: `get_known_bots() -> set` (module-level), used by the four v6 call sites.

- [ ] **Step 1: Add the cached loader (v6 DB idiom)**

Insert below the `# Functions for all the commands` marker (~line 9374), as a module-level function. Note the v6 connection idiom (`async with await mysql_handler.get_connection(...)`), which differs from `beta.py`:

```python
# Global known-bots registry (website.known_bots), cached. Phase 2: unioned into the
# per-channel exclusion lists for watch-time, points, and welcome/shoutout.
KNOWN_BOTS_CACHE_TTL = 300  # seconds
_known_bots_cache = {"loaded_at": 0.0, "bots": frozenset()}

async def get_known_bots():
    # Returns a lowercased set of active global bot logins from website.known_bots.
    # Lazy TTL cache; on any error returns the last good cache (or empty set) so callers
    # degrade gracefully to per-channel-only exclusion (i.e. today's behaviour).
    global _known_bots_cache
    now = time.time()
    if _known_bots_cache["loaded_at"] and (now - _known_bots_cache["loaded_at"]) < KNOWN_BOTS_CACHE_TTL:
        return set(_known_bots_cache["bots"])
    bots = set(_known_bots_cache["bots"])  # fall back to last good cache on error
    try:
        async with await mysql_handler.get_connection(db_name="website") as connection:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT bot_login FROM known_bots WHERE is_active = 1")
                rows = await cursor.fetchall()
                bots = {str(r["bot_login"]).strip().lower() for r in rows if r.get("bot_login")}
        _known_bots_cache = {"loaded_at": now, "bots": frozenset(bots)}
    except Exception as e:
        bot_logger.error(f"[KNOWN BOTS] Failed to load known_bots from website DB: {e}")
    return set(bots)
```

> Verify before moving on: confirm the website-DB read idiom matches `get_website_twitch_app_credentials`
> in this file (the symbol names `mysql_handler`, `DictCursor`, `bot_logger`). If that function uses a
> different accessor (e.g. `await mysql_connection("website")` directly), match it exactly instead.

- [ ] **Step 2: Union into watch-time tracking**

In `track_watch_time` (~13552-13557), find:

```python
            excluded_users = excluded_users_data['excluded_users'] if excluded_users_data else ''
            excluded_users_list = excluded_users.split(',') if excluded_users else []
            non_excluded_users = [user for user in active_users if user['user_login'] not in excluded_users_list]
```

Replace with:

```python
            excluded_users = excluded_users_data['excluded_users'] if excluded_users_data else ''
            excluded_users_list = excluded_users.split(',') if excluded_users else []
            # Phase 2: union the per-channel list with the global known-bots registry
            excluded_logins = {u.strip().lower() for u in excluded_users_list if u.strip()} | await get_known_bots()
            non_excluded_users = [user for user in active_users if user['user_login'].lower() not in excluded_logins]
```

- [ ] **Step 3: Union into points award**

In the points-award path (~3785-3787), find:

```python
            excluded_users = [user.strip().lower() for user in settings['excluded_users'].split(',')]
            author_lower = messageAuthor.lower()
            if author_lower not in excluded_users:
```

Replace with:

```python
            excluded_users = [user.strip().lower() for user in settings['excluded_users'].split(',')]
            excluded_users = set(excluded_users) | await get_known_bots()  # Phase 2: + global known bots
            author_lower = messageAuthor.lower()
            if author_lower not in excluded_users:
```

- [ ] **Step 4: Union into the `!points` lookup**

In the `!points` command lookup (~4631-4633), find:

```python
                        if settings and 'excluded_users' in settings:
                            excluded_users = [u.strip().lower() for u in settings['excluded_users'].split(',')]
                            if target_user_name.lower() in excluded_users:
```

Replace with:

```python
                        if settings and 'excluded_users' in settings:
                            excluded_users = set(u.strip().lower() for u in settings['excluded_users'].split(',')) | await get_known_bots()
                            if target_user_name.lower() in excluded_users:
```

- [ ] **Step 5: Skip welcome for known bots**

In `send_first_command_welcome_if_needed` (~3695) — which in v6 is separate from message counting, so no `message_counts` concern — find:

```python
            if not messageAuthor or messageAuthor.lower() in IGNORED_WELCOME_USERNAMES or messageAuthor.lower() == CHANNEL_NAME.lower():
                return
```

Replace with:

```python
            if not messageAuthor or messageAuthor.lower() in IGNORED_WELCOME_USERNAMES or messageAuthor.lower() in await get_known_bots() or messageAuthor.lower() == CHANNEL_NAME.lower():
                return
```

> Before editing, confirm in this file that the `message_counts` INSERT is NOT gated by this same
> condition (per the extraction it is not in v6). If you find a shared early-return that precedes the
> `message_counts` INSERT here too, apply the `beta.py` Step 5 approach instead (skip AFTER counting).

- [ ] **Step 6: Verify**

Run:
```bash
python -m py_compile bot/beta-v6.py
```
Expected: exits 0, no output.

Then confirm wiring:
```bash
grep -n "async def get_known_bots" bot/beta-v6.py     # expect 1
grep -c "await get_known_bots()" bot/beta-v6.py        # expect 4 (watch-time, points award, points lookup, welcome)
```

---

## Functional verification (after both tasks, on a staging beta/v6 channel)

Requires running bots + DB; not dev-box verifiable beyond `py_compile`:

1. Restart the `beta` (and/or `beta-v6`) bot process.
2. Via the Phase 1 admin page, add a test login that is actively in the channel (e.g. a secondary account). Within ≤5 min (cache TTL): the bot stops welcoming it, `!points <login>` reports 0, and its watch time stops incrementing. Real users are unaffected.
3. Confirm `message_counts` for that login STILL increments when it chats (Phase 2 must not change counting).
4. Confirm a per-channel `watch_time_excluded_users` entry still works (union preserved).
5. Disable the registry entry → behaviour returns within the TTL.

## Coverage notes

All planned call sites are accounted for: loader with TTL + fallback (T1S1/T2S1); watch-time union (T1S2/T2S2); points award union (T1S3/T2S3); `!points` lookup union (T1S4/T2S4); welcome/shoutout skip (T1S5-6 / T2S5); `message_counts` preserved (T1S5 places skip AFTER the INSERT; T2 welcome path is separate); beta-only + v6-only, stable untouched; website-DB read via `mysql_connection`/`mysql_handler`. Symbol names `get_known_bots` / `_known_bots_cache` / `KNOWN_BOTS_CACHE_TTL` are consistent across both tasks; `await get_known_bots()` call-count checks (5 in beta, 4 in v6) match the listed sites.

## Open decision carried from the spec

Whether known bots should also stop being counted in `message_counts`. This plan implements the
**recommended** option (keep counting; skip only welcome/shoutout/points/watch-time). If you instead
want counting suppressed, T1S5 simplifies to adding the check at the shared early-return — confirm
before execution.

## Deploy

Restart the `beta` and `beta-v6` bot processes. No DB/API/dashboard change.
