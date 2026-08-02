# Pet Overlay — Stage 0+1 Design

> First implementation slice of the pet overlay feature. Full product context and the complete roadmap live in `.grok/plans/pet-overlay.md`; this spec scopes down to what actually ships in this round: schema, the rendering engine, and chat/command reactions. Twitch events, channel-point redemptions, and stateful stats (Stages 2 and 3 of that roadmap) are separate follow-up specs.

## Goal

A streamer can enable the pet, upload sprite art for a few states, map a chat keyword and a bot command to animations, drop the OBS URL into a browser source, and watch the pet react live. No stat effects, no stat bars, no Twitch-event or redemption reactions in this slice — those come later. This is the smallest version of the feature that's actually visible and fun in a stream.

## Data model

All four tables from the full roadmap are created now rather than migrated in later — the schema is cheap and stable, only the UI/behavior built on top of it grows over subsequent stages. All four go into `dashboard/usr_database.php`'s `$tables` array so they auto-provision per channel the same way every other per-user table does.

- **`pet_settings`** — singleton row (`id = 1`): `enabled` (off by default), `pet_name` (default "Pet"), `idle_animation` (default "idle"), `position` (one of four corners, default bottom-right), `scale`, `flip`, `show_stats`, `visible_stats`, `bubble_enabled` (on by default), `sound_enabled` (off by default), per-stat decay rates (happiness 2.0/hr, hunger 3.0/hr, energy 1.0/hr), `updated_at`. The decay rates and `show_stats` exist now but aren't acted on until Stage 3.

- **`pet_animations`** — one row per sprite-sheet state: `name` (unique — idle, happy, hype, sad, sleep, eat, or a custom state), `sprite_file` (path under the user's media `pet/` subdir), `frame_width`, `frame_height`, `frame_count` (default 1), `fps` (default 12), `loop` (on by default), `created_at`.

- **`pet_triggers`** — full schema built now, but the dashboard's trigger CRUD in this slice only surfaces `chat_keyword` and `command` as trigger types. Columns: `trigger_type`, `trigger_value`, `animation` (matches `pet_animations.name`), `bubble_text` (supports `{user}`), `effect_happiness`/`effect_hunger`/`effect_energy` (default 0), `xp`, `cooldown_seconds` (default 5), `enabled`. Indexed on `(trigger_type, trigger_value)`. The effect/xp columns are stored starting now but not applied to `pet_state` until Stage 3 — no code path reads them yet.

- **`pet_state`** — singleton row (`id = 1`): `happiness`/`hunger`/`energy` (0–100, default 80), `level` (default 1), `xp`, `last_interaction_at`, `updated_at`. Created and defaulted on enable so the schema is there for Stage 3, but nothing in this slice reads or writes it meaningfully.

## Event flow

Chat keyword and command matching happens in the bot, not the overlay, so trigger config and cooldowns stay in one place and the overlay stays a thin renderer:

```
Chat message / command (beta.py)
  → PetManager: in-memory cached trigger dict for the channel, refreshed on PET_SETTINGS_UPDATE
  → match found + cooldown OK (existing bucket system)
  → websocket_notice("PET_REACT", {animation, bubble_text?})
  → WebSocket server's generic broadcast-to-channel fallback (no server.py change needed — PET_REACT
    is unrecognized by the server and falls through the existing else branch)
  → overlay/pet.php (Socket.io client): queues the reaction, plays it, returns to idle
```

`PET_SETTINGS_UPDATE` rides the same path in the other direction — dashboard saves trigger a broadcast so the bot's in-memory cache and the overlay's sprite manifest both refresh without an OBS reload.

The chat-keyword check is a per-message in-memory dict lookup, not a DB hit — this matters because chat is the highest-volume event the bot processes. The overlay never sees raw chat; it only receives the targeted `PET_REACT` events the bot decides to send, so bandwidth to the browser source stays tiny even during busy chat.

## Dashboard (`dashboard/pet.php`)

- Enable toggle. Turning it on creates the default `pet_settings`/`pet_state` rows and seeds a small starter trigger set (a couple of common keywords, `!feed` → `eat`) so the pet does something before the streamer has configured anything.
- Sprite/animation manager: upload a sprite sheet per state, enter frame width/height/count/fps/loop, live preview plays the sheet before saving. Upload validation: MIME + extension allowlist (png, webp only), max dimensions, max file size, re-encoded and stripped of metadata server-side. Files land in `/var/www/media/{username}/pet/`, served through the existing `media.botofthespecter.com` CDN path, and count against the existing `storage_used.php` accounting.
- Trigger table, scoped to `chat_keyword` and `command` types for this slice: value, animation, optional bubble text, cooldown, enabled flag. Effect/xp fields are not shown in this slice's UI (they're Stage 3).
- Position/scale/flip/bubble styling controls.
- "Test reaction" button: fires a real `PET_REACT` at the live overlay so the streamer can check timing and art without waiting for a real trigger.
- A Pet card added to `dashboard/overlays.php` with the OBS browser-source URL and a link into `pet.php`, matching every other overlay's card pattern.

## Overlay (`overlay/pet.php`)

New file, following the existing overlay conventions: PHP resolves `?code=` to the channel's per-user DB, connects via Socket.io, and otherwise plays what it's told.

- Preloads all sprite frames on page load.
- Animates via `requestAnimationFrame` with a frame accumulator — not per-frame `setInterval` — using only `transform`/`opacity` for GPU-friendly rendering.
- Idle loop by default; one-shot reactions play to completion and return to idle.
- Reaction queue: one active reaction at a time, bounded queue. Identical reactions queued back-to-back coalesce into a single play; once still over a ~5-item cap, oldest queued items are dropped. This is what keeps a raid-triggered flood of the same keyword from turning into a jittery backlog.
- Auto-reconnect on WebSocket drop (per the overlay auto-reconnect rule every browser source follows).
- Transparent background, resolution-independent layout.
- No stat bars in this slice — `PET_STATE` isn't emitted yet, so there's nothing to render.

New CSS lives in `overlay/index.css` under `.pet-overlay-page-*` classes, per the overlay folder's own-stylesheet rule (no cross-folder CSS linking).

## Security & isolation

Nothing new here beyond what the roadmap already establishes and what this slice actually touches: `channel_code` WebSocket routing prevents cross-channel leakage (already enforced), the overlay reads only the DB matching its `?code=`, uploaded sprites go through the validation described above, bubble text is escaped on render, and trigger values are length-capped and typed at the DB layer.

## Explicitly out of scope for this slice

- Redemption and Twitch-event (follow/sub/raid/cheer/first-chat) trigger types — schema supports them, dashboard UI and bot hooks don't yet.
- Stat effects, XP/level, decay, and stat bars on the overlay — Stage 3.
- Per-viewer attribution, magnitude scaling, operator templates, sound on reactions, mini-games — Phase 2/Later per the full roadmap.
- Greeting-scope decision (`FIRST_CHAT` vs every returning viewer) — deferred to the Stage 2 spec, since first-chat greeting is a Stage 2 item.

## Decisions carried over from the full roadmap's open questions

These were resolved during brainstorming for this slice and apply to the whole feature going forward, not just this stage:

- Stat set: keep all three (happiness/hunger/energy) plus level/xp — lazy decay makes the extra stats cheap even though they're inert until Stage 3.
- Sprite subdir: `/media/{user}/pet/`, isolated from alert sounds/videos.
- Seed default triggers on enable: yes, so the pet reacts to something immediately.
- Reaction queue overflow: coalesce identical reactions, then drop oldest beyond the cap.
- Overlay filename: `pet.php` (matching the rest of the overlay folder's naming).
