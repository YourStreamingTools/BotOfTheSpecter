# Known-Bots Registry — Phase 2 (Bot Consumers)

**Goal:** Make the Twitch bot consume the global `website.known_bots` registry — unioned with the existing per-channel exclusion lists — so known bots stop accruing watch time, earning points, and being welcomed or auto-shouted-out.

**Spec:** `.claude/specs/2026-06-19-admin-known-bots-registry-phase2.md`

## The idea

Phase 1 built the admin-managed registry. Phase 2 is the consuming side: each of `beta.py` and `beta-v6.py` gains a module-level loader, `get_known_bots()`, that reads `website.known_bots` and returns a lowercased set of active bot logins. That set is unioned into the per-channel exclusion lists at every place the bot already decides whether a user counts as "real": watch-time tracking, the points award, the `!points` lookup, and the welcome / auto-shoutout decision.

The per-channel lists keep working exactly as they do today — the global registry is purely additive. There's no schema change, no API change, and no dashboard change in this phase; we're only teaching the bot to read a table that already exists.

**Tech:** Python 3 / TwitchIO (2.10 for `beta.py`, 3.2.2 for `beta-v6.py`) / aiomysql via each bot's own MySQL helper.

## Constraints and ground rules

- **Only `beta.py` and `beta-v6.py` change.** Stable `bot.py` is off-limits under the bot-version policy. This feature lands in beta and v6 only.
- **The two files don't share an idiom.** TwitchIO 2.10 and 3.2.2 differ, and so does each file's DB-access style. The loader and the call-site edits have to follow whatever pattern each file already uses; a `beta.py` edit can't be pasted into `beta-v6.py` verbatim.
- **`message_counts` behaviour must not change.** Known bots are still *counted* when they chat. Phase 2 only stops them from being welcomed, shouted out, given points, or credited with watch time. This is the subtle one — getting the ordering wrong would silently stop counting bots, which we don't want.
- **The registry lives in the central `website` DB.** Read `known_bots` through the website-scoped connection, never per-user, and use a parameterized read.
- **Everything is lowercased.** The registry is stored lowercased; normalise both sides of every membership check to lowercase so comparisons are reliable.
- **Match code by content, not by position.** The call sites have drifted before; locate each one by the surrounding code (the function it lives in and the lines around it), not by a fixed offset.

## The loader

Both files get the same shape of helper, placed below the `# Functions for all the commands` marker as a module-level async function. It keeps a tiny module-level cache — a "loaded at" timestamp and a frozenset of bot logins — with a 300-second TTL. Within the TTL it returns the cached set; past it, it re-reads the table.

The read itself is a single parameterless query against the website DB:

```sql
SELECT bot_login FROM known_bots WHERE is_active = 1
```

Rows are stripped and lowercased into a set, the cache is refreshed, and the set is returned. The important reliability detail: on **any** exception the loader logs the failure and falls back to the last good cache (empty set on first failure) rather than raising. That way a transient DB hiccup degrades gracefully to per-channel-only exclusion — i.e. exactly today's behaviour — instead of breaking the call sites that depend on it.

The two files differ only in how they open the website connection:

- **`beta.py`** uses its existing `mysql_connection("website")` helper with a `DictCursor`, and cleans the connection up in a `finally` block, matching the close/release idiom `track_watch_time` already uses.
- **`beta-v6.py`** uses the v6 accessor, `async with await mysql_handler.get_connection(db_name="website")`, mirroring how `get_website_twitch_app_credentials` reads the website DB in that file.

In both cases the surrounding symbols — `DictCursor`, `time`, `bot_logger` — are ones the file already imports and uses, so before wiring the loader in, confirm those names match the live file (and adjust the connection-close idiom if the file's convention differs).

## Where the registry gets unioned in

### `beta.py` (TwitchIO 2.10)

There are five edits beyond the loader, all of the same flavour: take whatever exclusion set already exists at that point and union `await get_known_bots()` into it.

- **Watch-time tracking** (`track_watch_time`). The per-channel `excluded_users` string is split into a list as before; we then build a lowercased set from it, union the known-bots set in, and filter active users by lowercased `user_login` against that combined set. Known bots simply drop out of the non-excluded list, so they never accrue watch time.

- **Points award.** The award path already builds a lowercased `excluded_users` list from settings. We turn it into a set, union the known-bots set, and keep the existing "award only if author not excluded" guard. Known bots fall into the excluded set and earn nothing.

- **`!points` lookup.** The lookup builds the same lowercased exclusion list. We union the known-bots set in here too, and while we're at it normalise `target_user_name` to lowercase in the membership check — that fixes a latent case mismatch in the existing code, so a bot looked up by mixed-case name still reports as excluded.

- **Welcome / auto-shoutout, without disturbing counting** (`message_counting_and_welcome_messages`). This is the careful one. The `message_counts` INSERT-and-commit must run first and must still run for known bots. So the known-bots check goes in as an early `return` placed **after** the commit: the bot is counted, then we bail out before the welcome and auto-shoutout logic. The check must not move above the INSERT — doing so would stop counting the bot, which the constraints forbid. Before committing this, confirm nothing else after the commit (beyond the seen-today bookkeeping and the welcome/shoutout itself) needs to run for a known bot.

- **First-message welcome.** There's a separate first-message guard that already returns early for ignored usernames and for the channel owner. We extend that same condition to also return when the author is in the known-bots set. The enclosing function is async (it has to be, to await the loader); if a similar-looking guard appears elsewhere, only the welcome/first-message one gets this change.

That's five `await get_known_bots()` call sites in `beta.py`: watch-time, points award, `!points` lookup, the post-count welcome/shoutout skip, and the first-message guard.

### `beta-v6.py` (TwitchIO 3.2.2)

The watch-time, points-award, and `!points`-lookup edits are conceptually identical to `beta.py` — same union-the-set approach, same lowercasing — differing only in the v6 DB idiom for the loader.

The welcome path is simpler in v6. Welcoming lives in `send_first_command_welcome_if_needed`, which is **separate** from message counting, so there's no `message_counts` ordering concern here. We just extend that function's existing early-return guard to also fire for known bots, alongside the ignored-usernames and channel-owner checks. Before making the change, confirm in the live file that the `message_counts` INSERT is genuinely not gated by this same condition; if v6 turns out to share an early-return that precedes counting, fall back to the `beta.py` approach of skipping *after* the count instead.

That's four `await get_known_bots()` call sites in `beta-v6.py`: watch-time, points award, `!points` lookup, and the welcome guard.

## Files affected

| File | Change |
| ---- | ------ |
| `./bot/beta.py` | Add `get_known_bots()` loader; union the global registry at watch-time, points award, `!points` lookup, the post-count welcome/shoutout skip, and the first-message guard. |
| `./bot/beta-v6.py` | Add `get_known_bots()` loader (v6 DB idiom); union at watch-time, points award, `!points` lookup, and the welcome guard. |
| `./bot/bot.py` | **No change** — stable is out of scope. |

No schema, API, or dashboard files are touched.

## How we'll know it's right

Each touched file should pass a Python syntax check (`py_compile`) before anything else — there's no test framework here and we're not adding one. Beyond that, the loader should be the single definition in each file and each call site should actually be awaiting it (five awaits in `beta.py`, four in `beta-v6.py`), so a quick read-through confirming the wiring is part of the work.

Real confidence comes from a staging run on a beta/v6 channel, since the behaviour needs live bots and the DB:

1. Restart the `beta` (and/or `beta-v6`) process.
2. Using the Phase 1 admin page, add a test login that's actively present in the channel (e.g. a secondary account). Within the cache TTL (≤5 min) the bot should stop welcoming it, `!points <login>` should report 0, and its watch time should stop incrementing — while real users are unaffected.
3. Confirm `message_counts` for that login still increments when it chats. This is the regression guard: Phase 2 must not change counting.
4. Confirm a per-channel `watch_time_excluded_users` entry still works on its own, proving the union didn't replace the existing list.
5. Disable the registry entry and confirm behaviour returns to normal within the TTL.

## Open decision carried from the spec

Whether known bots should *also* be dropped from `message_counts`. This plan takes the recommended position — keep counting, skip only welcome/shoutout/points/watch-time. If we later decide counting should be suppressed too, the `beta.py` welcome change simplifies to moving the known-bots check to the shared early-return ahead of the INSERT. That's a deliberate choice to confirm before building, not a detail to flip silently.

## Deploy

Restart the `beta` and `beta-v6` bot processes. Nothing else moves — no DB, API, or dashboard deploy.
