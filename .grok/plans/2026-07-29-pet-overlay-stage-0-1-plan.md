# Pet Overlay — Stage 0+1 Implementation Plan

Executes the design in `.grok/specs/2026-07-29-pet-overlay-stage-0-1-design.md`: schema, the overlay rendering engine, and chat-keyword/command reactions. No stat effects, no stat bars, no Twitch-event or redemption reactions in this round.

Six tasks, in build order. Each one produces something checkable on its own before moving to the next — schema first, then the dashboard config surface, then the overlay, then the bot side that actually drives it.

---

## Task 1 — Per-user schema

**Files:** `dashboard/includes/usr_database.php`

Add four entries to the `$tables` array, following the existing singleton-row pattern used by `avatar_settings` and `working_study_overlay_settings`:

- `pet_settings` — singleton (`id TINYINT PRIMARY KEY DEFAULT 1`): `enabled` (default 0), `pet_name` (default `'Pet'`), `idle_animation` (default `'idle'`), `position` (`ENUM('top-left','top-right','bottom-left','bottom-right')` default `'bottom-right'`), `scale` (`DECIMAL(3,2)` default `1.00`), `flip` (`TINYINT(1)` default 0), `show_stats` (default 1), `visible_stats` (`VARCHAR(100)` default `'happiness,hunger,energy'`), `bubble_enabled` (default 1), `sound_enabled` (default 0), `decay_happiness`/`decay_hunger`/`decay_energy` (`DECIMAL(5,2)`, defaults 2.00/3.00/1.00 — points per hour, unused until Stage 3), `updated_at`.
- `pet_animations` — `id INT PRIMARY KEY AUTO_INCREMENT`, `name VARCHAR(50) UNIQUE`, `sprite_file VARCHAR(255)`, `frame_width INT`, `frame_height INT`, `frame_count INT DEFAULT 1`, `fps INT DEFAULT 12`, `loop TINYINT(1) DEFAULT 1`, `created_at`.
- `pet_triggers` — `id INT PRIMARY KEY AUTO_INCREMENT`, `trigger_type ENUM('chat_keyword','command','redemption','event','interaction')`, `trigger_value VARCHAR(100)`, `animation VARCHAR(50)`, `bubble_text VARCHAR(255) NULL`, `effect_happiness INT DEFAULT 0`, `effect_hunger INT DEFAULT 0`, `effect_energy INT DEFAULT 0`, `xp INT DEFAULT 0`, `cooldown_seconds INT DEFAULT 5`, `enabled TINYINT(1) DEFAULT 1`, plus an index on `(trigger_type, trigger_value)` for the cheap lookup the bot will do. This slice's dashboard UI only ever writes `chat_keyword` and `command` rows, but the full column set is created now so Stage 2/3 don't need a migration.
- `pet_state` — singleton (`id TINYINT PRIMARY KEY DEFAULT 1`): `happiness`/`hunger`/`energy` (`TINYINT UNSIGNED`, default 80), `level INT DEFAULT 1`, `xp INT DEFAULT 0`, `last_interaction_at TIMESTAMP NULL`, `updated_at`.

Since `usr_database.php`'s migration engine drops any live table not listed in `$tables` and drops any live column not present in a table's parsed definition, all four must go in during the same edit — don't land `pet_settings` alone and add the others in a later task, or the auto-migration will treat the missing ones as never having existed (harmless, since they don't exist yet) but you also risk a half-registered feature if the edit is split across commits that land separately in production.

No default-row `INSERT` block is needed for `pet_settings`/`pet_state` beyond what `id TINYINT PRIMARY KEY DEFAULT 1` already gives — a row is created when `dashboard/pet.php` (Task 2) first writes to it on enable, exactly like `avatar_settings` does.

**Verify:** Load any dashboard page as a test account (this file runs on every dashboard request) and confirm via a database client that `pet_settings`, `pet_animations`, `pet_triggers`, and `pet_state` now exist in that user's per-user database with the columns above, and that no existing tables were affected.

---

## Task 2 — Dashboard: page shell, enable toggle, sprite/animation manager

**Files:** `dashboard/pet.php` (new)

Follow `dashboard/socials.php`'s structure: `session_bootstrap.php` → i18n → `require_auth.php` → `db_connect.php` → `includes/userdata.php` → `includes/user_db.php` → `session_write_close()`, page content built into `$content` via output buffering, then `require 'layout.php'`.

Build:
- An enable toggle (`sp-switch`) that writes `pet_settings.enabled`. On the transition from off to on, seed a small starter trigger set into `pet_triggers` — a couple of common chat keywords mapped to a generic animation, so the pet does something before the streamer configures anything. Guard the seed with a `WHERE NOT EXISTS` check (or a check for zero existing rows) so re-toggling never duplicates it.
- An animation manager: list existing `pet_animations` rows, a form to add one (name, sprite-sheet file upload, frame width/height/count, fps, loop checkbox), and a live preview that plays the uploaded sheet client-side before the row is saved (canvas or CSS `background-position` stepping — no server round-trip needed for the preview itself, just read the file the browser already has via `<input type="file">` and `URL.createObjectURL`).
- Upload handling follows `dashboard/media.php`'s pipeline exactly: extension allowlist restricted to `png`/`webp` only (tighter than media.php's general allowlist, since sprites are the only image type this feature accepts), then `upload_validate_extension_and_mime()` from `dashboard/includes/upload_helpers.php` for the MIME re-check, `upload_sanitize_filename()`, `upload_unique_target()` to avoid overwrites, `move_uploaded_file()`, and finally `upload_reencode_image()` to strip metadata (this is already written for PNG/WebP in `upload_helpers.php`, just not currently called from `media.php` — Pet is the first caller). Destination directory is `/var/www/media/{username}/pet/` (create it if missing, matching the existing per-user media directory convention), served publicly at `https://media.botofthespecter.com/{username}/pet/{file}`. Count the file size against the same storage-tier cap `media.php` already enforces.
- Position, scale, flip, and bubble-enabled controls, saved to `pet_settings`.

**Depends on:** Task 1's tables.
**Feeds into:** Task 3 reads/writes the same `pet_settings` row and `pet_animations` table on the same page; Task 5's overlay reads whatever's saved here.

**Verify:** As a test account, enable the feature, upload a small PNG as a sprite sheet for an "idle" animation, confirm the live preview plays it, save, and confirm the row appears correctly in `pet_animations` and the file lands at `/var/www/media/{username}/pet/` (or the local dev equivalent path). Try uploading a non-image file and confirm it's rejected before it reaches disk.

---

## Task 3 — Dashboard: trigger table, styling, test reaction

**Files:** `dashboard/pet.php` (continued)

Add to the same page:
- A trigger table CRUD scoped to `chat_keyword` and `command` types only (the type selector shows just those two options in this slice): trigger value, animation dropdown (populated from Task 2's `pet_animations`), optional bubble text (documented as supporting a `{user}` placeholder, even though nothing substitutes it until the overlay renders it in Task 5), cooldown in seconds, enabled flag. Standard add/edit/delete against `pet_triggers`.
- Save handler mirrors `socials.php`'s pattern: `POST` handler, JSON response, `$db->begin_transaction()` around the writes, `commit()`, `exit()`.
- After any save that changes `pet_settings`, `pet_animations`, or `pet_triggers`, push a `PET_SETTINGS_UPDATE` event so the bot's in-memory cache (Task 6) and the live overlay (Task 5) both pick up the change without an OBS reload. Do this the same way `notify_event.php` is invoked elsewhere in the dashboard: a `curl` call to `https://websocket.botofthespecter.com/notify?code={api_key}&event=PET_SETTINGS_UPDATE`.
- A "Test reaction" control per trigger row (and one general one for the idle animation) that fires a live `PET_REACT` at the overlay via the same `notify_event.php` mechanism — this needs a new branch added to `dashboard/api/notify_event.php`'s big `if/elseif` chain: `PET_REACT` expects `animation` and optionally `bubble_text`, forwarded as query params the same way `TWITCH_FOLLOW` forwards `twitch-username`.

**Depends on:** Task 2's page shell and `pet_animations` table.
**Feeds into:** Task 5 (overlay listens for `PET_REACT`/`PET_SETTINGS_UPDATE`), Task 6 (bot listens for `PET_SETTINGS_UPDATE`).

**Verify:** Map a chat keyword to the uploaded idle animation, save, and confirm the save request succeeds and a `PET_SETTINGS_UPDATE` notify call goes out (visible in the websocket server's logs or via the browser network tab). Hit "Test reaction" and confirm the notify call carries a `PET_REACT` event with the right animation name.

---

## Task 4 — Dashboard wiring: overlays card, menu, translations

**Files:** `dashboard/overlays.php`, `dashboard/menu.php`, `dashboard/lang/en.php`, `dashboard/lang/de.php`, `dashboard/lang/fr.php`, `dashboard/lang/es.php`, `dashboard/lang/zh.php`

- Add a Pet card to `overlays.php` matching the Avatar card's shape (title with icon, a settings-gear link to `pet.php`, description text, and the `https://overlay.botofthespecter.com/pet.php?code=API_KEY_HERE` info-box). Keys: `overlays_pet`, `overlays_pet_desc`, `overlays_pet_settings_title`.
- Add a `navbar_pet` entry to the `navbar_stream_tools` submenu in `menu.php`, using the `t('navbar_pet')` pattern (not the hardcoded-label outlier the Social Roller entry uses).
- Add every new key to `en.php` first (it's the fallback base every other locale merges over), then translate into `de.php`, `fr.php`, `es.php`, `zh.php`. Needed keys beyond the three above: whatever labels Task 2/3's form ends up needing (enable toggle, sprite manager fields, trigger table columns, position/scale/styling labels, "Test reaction" button) — follow the existing `media_*`/`overlays_*`/`avatar_*` naming precedent so keys read as `pet_enable`, `pet_sprite_upload`, `pet_trigger_add`, and so on rather than generic strings. Escape French apostrophes in `fr.php` the way the rest of that file already does.

**Depends on:** `pet.php` existing (Task 2/3) so the labels it needs are known.

**Verify:** Load `overlays.php` and confirm the Pet card renders with a working link to `pet.php` and the correct OBS URL. Switch the dashboard language to German, French, Spanish, and Chinese and confirm no key falls back to a raw `pet_*` string (which would mean a locale file is missing an entry `en.php` has).

---

## Task 5 — Overlay: `pet.php` rendering engine

**Files:** `overlay/pet.php` (new), `overlay/index.css` (add `.pet-overlay-page-*` classes)

`?code=` resolution follows `overlay/social-roller.php`'s exact pattern: look up `username` from the `website` database via mysqli using the raw `code` query param, then open a PDO connection to that per-user database. Guard every query with a `SHOW TABLES LIKE '...'` check first, in case a streamer drops the OBS URL in before ever visiting `pet.php` in the dashboard (so the per-user tables haven't been auto-migrated in yet).

On page load, the PHP side reads `pet_settings` (position/scale/flip/idle animation/bubble-enabled) and all `pet_animations` rows, and emits them into the page as a JS sprite manifest (an object keyed by animation name, each entry holding sprite file URL + frame width/height/count/fps/loop). The `pet_triggers` table is **not** read by the overlay at all in this slice — trigger matching is bot-side (Task 6); the overlay only ever receives targeted `PET_REACT` events telling it which animation to play.

Socket.io client follows the same skeleton every overlay uses (`social-roller.php` is the closest recent example): connect to `wss://websocket.botofthespecter.com`, emit `REGISTER` with `{code, channel: 'Overlay', name: 'Pet Overlay'}` on connect, an `attemptReconnect()` loop with capped backoff on `disconnect`, and listen for:
- `PET_REACT` — `{animation, bubble_text?}`. Push onto the reaction queue described below.
- `PET_SETTINGS_UPDATE` — re-fetch the page (same `OVERLAY_REFRESH`-style forced reload other overlays use) so a changed sprite manifest or repositioned pet takes effect without the streamer touching OBS.

Animation engine:
- Preload every sprite sheet referenced in the manifest on page load (`Image()` objects), so the first reaction never stalls on a network fetch.
- Step frames via `requestAnimationFrame` with a time accumulator gated by each animation's `fps`, not `setInterval` — this is what keeps CPU/GPU use low enough for a background OBS source.
- Idle animation loops continuously by default. A `PET_REACT` interrupts it, plays the named animation once (honoring its own `loop` flag — if an animation's `loop` is off, play through its frames once and return to idle; if a reaction names an animation whose `loop` is on, still treat it as one cycle before returning to idle, since a reaction is inherently one-shot regardless of how the sheet itself is authored), and returns to idle when done.
- Reaction queue: only one reaction plays at a time. Incoming reactions queue up to a 5-item cap. Before appending, check whether the new reaction is identical (same animation + bubble text) to one already queued — if so, drop the incoming one instead of appending (this is the "coalesce identical" behavior — a burst of the same keyword during a raid collapses to a single play rather than five). Once still at the cap after coalescing, drop the oldest queued item to make room, never the newest.
- Optional bubble text renders as a speech-bubble element positioned relative to the pet, sized to fit, auto-dismissed slightly before the reaction ends.
- Positioning: absolute, anchored to whichever of the four corners `pet_settings.position` specifies, transformed by `scale` and mirrored horizontally when `flip` is set.
- Transparent `<body>` background (standard for every browser-source overlay in this repo).

CSS lives in `overlay/index.css`'s `.pet-overlay-page-*` namespace, per the overlay folder's own-stylesheet convention — no linking to `dashboard/dashboard.css` or anything cross-folder.

**Depends on:** Task 1's tables (reads `pet_settings`/`pet_animations`), Task 3's `PET_REACT` event shape.
**Feeds into:** Task 6 is the only thing that ever emits `PET_REACT` in production (Task 3's dashboard test button is the other emitter, already covered).

**Verify:** Open `pet.php?code=` with a valid key in a browser (or an OBS browser source) after Task 2's setup, confirm the idle animation loops, use Task 3's "Test reaction" button and confirm the named animation plays once and returns to idle, and confirm dropping the WebSocket connection (e.g. briefly blocking the host) triggers a visible reconnect rather than a dead page.

---

## Task 6 — Bot: `PetManager`, chat/command hooks, `PET_REACT` emission

**Files:** `bot/beta.py`

Add a per-channel in-memory trigger cache — a module-level dict keyed by the channel's API token (matching how other per-channel caches in this file are keyed), each entry holding the parsed `pet_settings.enabled` flag plus two lookup dicts built from `pet_triggers`: one keyed by lowercased `trigger_value` for `trigger_type = 'chat_keyword'` rows, one for `trigger_type = 'command'` rows. Populate it lazily on first use per channel (a DB read via the existing per-channel `mysql_connection()` pattern) and invalidate/reload it whenever a `PET_SETTINGS_UPDATE` event arrives.

Add a `@specterSocket.event async def PET_SETTINGS_UPDATE(data):` handler following the exact shape of the existing `STREAM_ONLINE`/`WEATHER_DATA`/`KOFI` handlers just above it in the file (log receipt, call into a small processing function, catch and log exceptions) — its job is just to drop this channel's cache entry so the next lookup reloads it.

Hook trigger matching into `event_message`:
- Chat-keyword matching happens after the existing `if message.echo: return` guard and after `handle_commands` has already run (so it never interferes with actual command dispatch), checking the lowercased message content against the cached chat-keyword dict. This is a plain dict lookup per message, not a query, since chat is the highest-volume path in the bot.
- Command-type matching reuses the same `if messageContent.startswith('!')` branch that already exists for custom-command-override handling — extract the command word the way that code already does, and check it against the cached command-type dict. This does **not** register any new `@commands.command` — a "command" trigger in this slice matches on the literal text a streamer already types (whether that's a real registered command or just a word starting with `!`), so no `builtin_commands` set/JSON registration is needed for this stage. (Stage 3's dedicated `!feed`/`!play` interaction commands will need that full registration path — set membership in `builtin_commands`/`per_user_cooldown_commands`, a `builtin_commands` DB row via the existing seeding routine, and a catalog entry in `api/builtin_commands.json` — but that's out of scope here.)

On a match (either path), enforce the trigger's own cooldown before reacting — call `check_cooldown()` with a bucket namespaced to the trigger itself (for example `command=f"pet_trigger_{trigger_id}"`, a bucket key derived from the channel rather than per-viewer, since a pet reaction cooldown is about not spamming the shared overlay, not rate-limiting individual chatters) and `send_message=False` (a skipped pet reaction should never post a chat message). On success, call `add_usage()` with the same key, then fire `safe_create_task(websocket_notice(event="PET_REACT", additional_data={"animation": ..., "bubble_text": ...}))` — following the existing fire-and-forget call shape used for `SOUND_ALERT`, `MOD_GRANTED`, and the rest. Substitute `{user}` in `bubble_text` with the triggering chatter's display name before sending, since the overlay does no templating itself.

**Depends on:** Task 1's `pet_triggers` schema, Task 3's `PET_SETTINGS_UPDATE` push, Task 5's `PET_REACT` consumer.

**Verify:** With a trigger configured in the dashboard (Task 3) and the overlay open (Task 5), type the mapped keyword/command in the channel's chat and confirm the pet reacts within about a second, confirm typing it again immediately is silently ignored until the configured cooldown elapses, and confirm editing the trigger in the dashboard changes the bot's behavior without a bot restart (proving the `PET_SETTINGS_UPDATE` cache invalidation actually works).

---

## Explicitly not in this plan

Everything Stage 2/3 owns per the design: Twitch-event and redemption trigger types, stat effects/XP/level/decay, stat bars on the overlay, the dedicated `!feed`/`!play` interaction commands (and their `builtin_commands` registration), and the `FIRST_CHAT` greeting-scope decision. Those get their own spec once this ships.
