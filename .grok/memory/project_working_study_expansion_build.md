---
name: project_working_study_expansion_build
description: Working & Study expansion — all 7 phases built & SHIPPED (committed 2026-06/07 batch); architecture + deploy shape
metadata: 
  node_type: memory
  type: project
  originSessionId: d4535b42-54e1-402f-9d82-0ce83a3c4802
---

The Working & Study co-working/focus system expansion (plan: `.grok/plans/working-and-study.md`) was fully implemented across this session — **all 7 phases, built + adversarially reviewed in order 1→6→5→2→4→3→7. SHIPPED — committed in the 2026-06/07 batch (was uncommitted at build time 2026-06-04).**

**What each phase added:**
- P1 chat commands (`!task/!done/!rename/!remove/!mytasks`); P2 backlog model (`backlog_position` col, `!now/!later/!soon/!backlog/!done <n>/!done next`, `!task` auto-queues, uncapped); P4 per-viewer projects (`project` col + `user_active_project` table, `!project/!projects/!project clear`, NULL-safe `project <=> %s` scoping); P3 personal pomos; P5 themes (5: dark/peachy/ocean/forest/midnight); P6 streamer cycle badge; P7 unified task-list view.

**Non-obvious architecture (persists beyond the commit):**
- **Personal pomos use a SERVER-SIDE single writer.** The bot (`beta.py`) only EMITS `USER_POMO_START`/`USER_POMO_CANCEL` and does a read-only `!pomo` (no-arg). `websocket/server.py` is the sole writer of the per-user `user_pomos` table and runs a `pomo_ticker()` background coroutine (launched in `on_startup`, 1s tick) that owns all phase/cycle transitions and emits `USER_POMO_UPDATE`(~10s)/`USER_POMO_PHASE`/`USER_POMO_COMPLETE`. `phase_ends_at` is absolute DATETIME → restart-safe with a catch-up loop. Ticker iterates all per-user DBs (60s-cached list from `website.users`), per-DB try/except isolation, and `USER_POMO_START` injects its DB into the active set immediately. Broadcast reuses `broadcast_to_task_clients_only` (no new scope).
- Overlay theme + list-view-mode + cycle badge are all driven by `working_study_overlay_settings` columns and hot-swap live via `SPECTER_SETTINGS_UPDATE` (server-rendered initial `data-*` attr to avoid flash). Overlay pomo badge counts down from server `remaining_seconds` (NOT browser-clock diffing the ISO `Z` timestamp — server-clock TZ assumption).

**Deploy shape:** restart BOTH the bot (`beta.py`) AND the websocket server (`server.py`, new ticker). Per-user schema (new cols `backlog_position`/`project`/`theme`/`cycle_count`/`show_cycle_badge`/`list_view_mode`; new tables `user_active_project`/`user_pomos`) auto-migrates via `dashboard/includes/usr_database.php` `$tables` (runs on dashboard visit; index adds for pre-existing `user_tasks` use guarded `SHOW INDEX` migrations). Dashboard + overlay PHP + `dashboard/lang/{en,de,fr}.php` deploy to the web hosts. Per [[bot-versions.md]] only `beta.py` was touched — port to `beta-v6.py` later. `bot.py` (stable), `api/api.py` untouched.

**One overridable product choice:** unified view OMITS backlog tasks via `const UNIFIED_OMIT_BACKLOG = true` in `overlay/working-or-study.php` — flip to `false` to render backlog rows muted (`.is-backlog`) instead. The plan flagged this as the only unlocked sub-decision; I chose omit. See [[project_per_user_schema]] (schema lives in usr_database.php $tables) and [[feedback_no_commits]] (left uncommitted).
