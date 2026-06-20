# Known-Bots Registry — Phase 2 (Bot Consumers) Design

**Date:** 2026-06-19
**Status:** Draft
**Depends on:** Phase 1 (`.claude/specs/2026-06-19-admin-known-bots-registry.md`) — the `website.known_bots` table + admin page must exist.

## Problem

Phase 1 made the dashboard leaderboards hide bots using the admin-managed `website.known_bots`
registry, but that only affects **display**. The bots themselves still: accrue watch time, earn
points, and get welcomed / auto-shouted-out by the Twitch bot, because the bot only consults the
**per-channel** exclusion lists (`watch_time_excluded_users`, `bot_settings.excluded_users`) plus a
tiny hardcoded `IGNORED_WELCOME_USERNAMES` set (just `botofthespecter` + the bot's own login).

Phase 2 makes the bot **consume the global registry** so a login added once in the admin page is
excluded everywhere, for every channel — the "registry everywhere" intent.

## Scope decision (locked in Phase 1 brainstorm)

- Full global registry, **phased**. This is Phase 2: bot consumers.
- Applies to **`beta.py` (TwitchIO 2.10)** and **`beta-v6.py` (TwitchIO 3.2.2)** only.
  **Stable `bot.py` is NOT touched** (bot-version policy: critical fixes only).

## Goals

- Each bot reads the active global registry (`SELECT bot_login FROM known_bots WHERE is_active = 1`)
  through a short-lived cache and **unions it with the existing per-channel lists** at three points:
  1. **Watch-time tracking** (`track_watch_time`) — union into the `watch_time_excluded_users` filter.
  2. **Points** — union into the `bot_settings.excluded_users` check, both where points are *awarded*
     and where the `!points` command *looks up* a target user.
  3. **Welcome + auto-shoutout** — skip welcoming / auto-shouting-out a known bot.
- The per-channel lists keep working exactly as today; the global set is *added*, never replaces them.
- Adding/removing a login in the admin page propagates to the bots within the cache TTL (~5 min),
  no redeploy.

## Non-goals

- **No `message_counts` change.** Phase 1 deliberately left chat-message counting untouched (the
  chat-leaders board hides bots at *display*; other features may read per-user counts). Phase 2 keeps
  that: a known bot that chats still increments `message_counts` — it's just not welcomed, not paid
  points, and hidden on the board. (See the one open decision below.)
- **No schema change.** The table already exists (Phase 1). The bot only **reads** it.
- **No new admin page or API endpoint.** Editing stays in Phase 1's `dashboard/admin/known_bots.php`.
- **Stable `bot.py` untouched.** Discord (`specterdiscord.py`) and Kick (`kick.py`) out of scope.

## Design

### Shared cached loader (one per bot file)

Mirror Phase 1's API cache and `beta-v6.py`'s existing `_website_twitch_creds_cache` pattern:

```text
_known_bots_cache = {"loaded_at": 0.0, "bots": frozenset()}
KNOWN_BOTS_CACHE_TTL = 300   # seconds

async def get_known_bots() -> set:
    # lazy TTL cache; reads website.known_bots WHERE is_active = 1 via mysql_connection("website");
    # returns a lowercased set; on any DB error returns the last good cache (or empty set) and logs a
    # warning — degrading to per-channel-only behaviour (i.e. exactly today's behaviour).
```

- **Lazy TTL cache** (checked on use), not a background task — simpler, matches the API and v6's
  creds cache, and avoids version-specific startup-registration differences. The first call after
  expiry does one website-DB read; all callers within the TTL get the cached set.
- **Fallback = empty set** on error. Unlike the API (which falls back to the 41-login seed), the bot
  does **not** carry its own copy of the seed — the DB is the single source of truth and the API
  guarantees the table is seeded. A transient read failure simply falls back to the per-channel
  lists (today's behaviour), never to a stale hardcoded list that could drift.
- **Placement:** module-level helper below the `# Functions for all the commands` marker
  (`beta.py:11115`, `beta-v6.py:9374`); cache constant + dict beside the other module-level caches.

### Consumption points & union semantics

All comparisons normalise to lowercase (the registry is stored lowercased).

| # | Point | Today reads | Phase 2 |
|---|-------|-------------|---------|
| 1 | `track_watch_time` | `watch_time_excluded_users` CSV → list, filter `active_users` by `user_login` | `excluded = {per-channel lowered} \| await get_known_bots()`; filter `user_login.lower() not in excluded` |
| 2 | points **award** (`user_points`) | `bot_settings.excluded_users` → lowered list; `if author_lower not in list` | union the list with `await get_known_bots()` before the check |
| 3 | `!points` **lookup** | same `bot_settings.excluded_users` list; `if target not in list` | same union before the check |
| 4 | welcome + auto-shoutout | `IGNORED_WELCOME_USERNAMES` (static set) | add `or name.lower() in await get_known_bots()` at the welcome-decision site |

`IGNORED_WELCOME_USERNAMES` is built at import time (before the event loop / DB), so we do **not**
mutate that constant — we add an async membership check at each welcome decision point.

### TwitchIO version differences (why two file-specific tasks)

- **DB idiom:** `beta.py` uses `conn = await mysql_connection("website")` then `conn.cursor(...)`;
  `beta-v6.py` uses `async with await mysql_handler.get_connection(db_name="website") as conn:` with
  `conn.cursor(DictCursor)` (DirectConnection). The loader must match each file's idiom.
- **Welcome vs counting placement:** in `beta.py`, the `IGNORED_WELCOME_USERNAMES` check sits at a
  **shared early-return** (`~3803`) that runs *before* the `message_counts` INSERT — so the
  known-bots welcome skip must be placed at the welcome-decision branch *after* counting, to honour
  the "no `message_counts` change" non-goal. In `beta-v6.py`, the welcome check
  (`send_first_command_welcome_if_needed`, `~3695`) is already separate from counting, so the union
  goes there directly.
- Helper-placement marker differs (`beta.py:11115` vs `beta-v6.py:9374`).

## Open decision (please confirm at review)

**`message_counts` for known bots.** Recommended: **leave counting unchanged** (skip welcome /
shoutout / points / watch-time, but still count their chat messages — they're hidden at display by
Phase 1). Alternative: also stop counting them in `message_counts`, which is simpler in `beta.py`
(one early-return) but changes counting semantics other features may rely on, and re-opens the Phase 1
decision. Recommendation: keep counting unchanged.

## Error handling

- `get_known_bots()` never raises to its callers: DB error → last-good cache or empty set + a logged
  warning. Watch-time/points/welcome therefore degrade gracefully to per-channel-only behaviour.
- No change to how the per-channel lists are read or to their failure handling.

## Verification

- `python -m py_compile bot/beta.py` and `python -m py_compile bot/beta-v6.py` pass.
- Functional (staging, on a beta/v6 channel): add a test login to the registry via the admin page;
  within ~5 min the bot stops welcoming it, stops crediting it points (`!points <bot>` → 0), and
  stops accruing its watch time; a per-channel `watch_time_excluded_users` entry still works; the
  bot still functions normally for real users. Disabling the registry entry restores behaviour.

## File change list (Phase 2)

| File | Change |
|------|--------|
| `./bot/beta.py` | Add `KNOWN_BOTS_CACHE_TTL` + `_known_bots_cache` + `get_known_bots()` (below the helper marker); union it at `track_watch_time`, points award, `!points` lookup, and the welcome/auto-shoutout decision (after `message_counts`). |
| `./bot/beta-v6.py` | Same, using the v6 DB idiom + helper placement; welcome union at `send_first_command_welcome_if_needed`. |

No changes to `bot.py`, the API, the dashboard, or any DB schema.

## Deploy

Requires **restarting the `beta` and `beta-v6` bot processes**. Nothing else.

## Relationship to the future Phase 3 (migrations)

Phase 1 created the table via a defensive `ensure_known_bots_table()` / `CREATE TABLE IF NOT EXISTS`.
A planned Phase 3 (admin `migrations.php` review-and-apply system) would retire that scattered
table-creation pattern. Phase 2 adds **no** new schema-creation code, so it is unaffected by and does
not block Phase 3.

## Constraints honoured

- Bot-version policy: only `beta.py` + `beta-v6.py`; TwitchIO API differences handled per file.
- DB rule: parameterized read; `website` scope via `mysql_connection("website")`; never per-user for this list.
- Secrets rule: no credentials touched per-bot; registry read is read-only from `website`.
