---
name: project_point_store
description: Point Store — loyalty store spending BOT points (not Twitch channel points); dashboard catalog + members shopfront + !store chat + STORE websocket. SHIPPED 2026-07-09.
metadata: 
  node_type: memory
  type: project
  originSessionId: 0c90faa6-50ba-4832-b308-c1116eaddd64
---

**Point Store** is a streamer-run loyalty store where viewers spend **bot points** (`bot_points`, Specter's own currency — NOT Twitch channel points) on streamer-approved items (sound alert / video alert / TTS / chat message). **SHIPPED 2026-07-09**, commit `3a7b294e` "Add Point Store: dashboard catalog, members shop, bot !store, and STORE websocket." Spec (scoping doc, marks Phase 1–3 done): `.grok/specs/2026-07-09-point-store-scope.md`.

**Four surfaces:**
- **Dashboard** `./dashboard/point_store.php` — catalog CRUD + global settings (enabled/paused/stream-online-only/cooldowns). Menu: Settings → Point Store ([[feedback_dashboard_page_menu_registration]]). Streamer/mod auth.
- **Members shopfront** `./members/store.php` at `https://members.botofthespecter.com/{channel}/store` (routed from `members/index.php` dispatch). Shows balance + catalog; buy POST. Members CSS only (`members/style.css`).
- **Bot chat** `!store` (list) / `!store <item>` (buy) — **beta.py only** ([[bot-versions.md]]); registered as builtin `store` ([[feedback_bot_builtin_command_registration]]). Port to beta-v6 is Phase 4 (pending).
- **WebSocket** new `STORE` event in `websocket/server.py` + `event_handler.py` (broadcast to channel `code` + globals) → **needs a WS restart** to take effect.

**Per-user DB tables** (in `dashboard/includes/usr_database.php` `$tables`, [[project_per_user_schema]]): `point_store_settings` (singleton `id=1`), `point_store_items`, `point_store_purchases` (purchase log, snapshots title/type/cost).

**Checkout invariants (design — shared by members PHP + bot Python):**
- **Atomic debit:** `UPDATE bot_points SET points = points - ? WHERE (user_id = ? OR user_name = ?) AND points >= ?` and require `affected_rows == 1`. Never fulfill media before the debit commits; never double-debit (members and bot each own one entry path).
- **Notify uses the STREAMER's API key server-side** (from `website.users`) — never exposed to the browser (same rule as [[project_custom_inbound_webhooks]]: /notify authenticates `code`).
- Buyer identity always from the session (members) or chat author (bot), never client-supplied.
- **Hybrid fulfillment:** checkout emits `STORE` **and** the companion existing event (`SOUND_ALERT`/`VIDEO_ALERT`/`TTS`) so day-one overlays play media without new overlay JS. Double-post guard: bot's `STORE` handler **skips** the chat announcement when `source == "chat"` (chat buy already announced).
- Gates: store `enabled` + not `paused` + optional stream-online + cooldowns (global + per-item + builtin command cooldown).
- **Checkout is serialized per-user** (fixed 2026-07-10, uncommitted): `point_store_checkout` in beta.py wraps its cooldown/cap checks **and** the debit transaction in `async with _store_purchase_locks[str(user_id)]` (module-level `defaultdict(asyncio.Lock)`). WHY: the cooldown + per-user/per-item `COUNT(*)` caps are lock-free SELECTs run BEFORE `START TRANSACTION`, so two rapid concurrent `!store` messages (TwitchIO gives each its own asyncio task + own DB connection) could both pass and bypass caps/cooldown (a TOCTOU — the review found it). Points & stock stay correct regardless (atomic conditional `UPDATE … WHERE points >= cost` / `WHERE stock > 0` — no double-spend). The app-level `asyncio.Lock` only covers ONE process; the pending **beta-v6 port** and any **members-portal/API checkout** path hitting the same per-user DB must re-apply serialization — cross-process needs a DB advisory lock (`GET_LOCK`) or a conditional-INSERT guard, not just the asyncio lock.

**Pending (Phase 4):** TTS/chat_message item types polish, cooldown/stock/max-per-stream enforcement polish, failed-notify retry, **beta-v6 port**, members empty/affordability/mobile states, optional `!shop` alias. Uses `bot_settings.point_name` for all copy (never hardcode "points"). Independent of Twitch channel-point rewards.
