# Pet Overlay — Feature Specification & Implementation Plan

> Status: **Draft**. See §7 for risks and §6 for roadmap decisions.
> Owner: TBD. Last revised 2026-05-30.
> Inspiration: Triiibe (formerly Kappamon) — a cute interactive pet/avatar browser source that reacts to chat and stream events to drive engagement.

**Confirmed product decisions**:

1. **Sprite sheets / frame-PNG animation.** Pet states are PNG frame sequences / sprite sheets animated client-side. Lightweight, smooth in OBS, swappable art.
2. **Custom upload at MVP.** Streamers upload their own pet art from day one (no locked-in character). Operator templates are a later nicety, not a blocker.
3. **Reactive *and* stateful at MVP.** The pet both reacts to triggers (animations) and carries persistent per-channel stats (happiness / hunger / energy / level) that viewers grow.
4. **Reuse the existing `?code=API_KEY` overlay URL.** Same per-channel browser-source auth as the other 20 overlays — no new token infra. (A scoped/revocable overlay-token system is a separate cross-overlay project, noted as future work.)

---

## 0. Affected systems (BotOfTheSpecter mapping)

Most "multi-channel" requirements in the brief are **already-solved infrastructure** here. The plan reuses it:

| Requirement | Existing capability reused |
|---|---|
| Per-channel isolation | Per-user MySQL DB (DB name = username) |
| Real-time push + correct routing | WebSocket server routes by `channel_code`; already broadcasts `TWITCH_FOLLOW/SUB/RAID/CHEER`, `TWITCH_CHANNELPOINTS`, `CHAT_MESSAGE`, `FIRST_CHAT` |
| Per-channel overlay URL | `?code=API_KEY` browser-source pattern |
| Multi-channel chat + EventSub | Bot already runs per channel with EventSub + chat client |
| Streamer OAuth + config UI | Dashboard (Twitch OAuth, per-`.botofthespecter.com` session) |
| Asset storage/CDN | Unified media library `/var/www/media/{user}/` → `https://media.botofthespecter.com/{user}/{file}` |

**New code:**

- `./overlay/pet.php` — **new.** Renders the pet, plays reactions, shows stat bars, interpolates decay locally.
- `./overlay/index.css` — new `.pet-overlay-page-*` classes (overlay folder's own stylesheet — no cross-folder CSS linking, per the ui-theme system).
- `./dashboard/pet.php` — **new.** Pet config: sprite upload, animation states, trigger mapping, stats tuning, styling.
- `./dashboard/overlays.php` — add a Pet card (OBS URL + link to `pet.php`).
- `./dashboard/usr_database.php` — new per-user tables (auto-deploy via the `CREATE TABLE IF NOT EXISTS` loop).
- `./bot/beta.py` — **primary target.** A `PetManager` that loads per-channel trigger config (cached), hooks chat/command/redemption/EventSub paths, emits `PET_REACT`/`PET_STATE`, applies stat effects. New `websocket_notice` branches. Per [bot-versions.md](../rules/bot-versions.md).
- `./bot/beta-v6.py` — port once stable.
- `./bot/bot.py` (stable) — **not changed.**
- `./websocket/server.py` — **no change strictly required** (the generic `else` in `notify_http` broadcasts unknown events to the channel + globals); optional explicit `PET_*` branches for logging.
- `./api/api.py` — **no changes** (overlay self-serves via PHP; bot/dashboard write per-user MySQL directly, per [data-flow.md](../rules/data-flow.md)).

---

## 1. Project Vision & Scope

A customizable, multi-channel **interactive pet overlay**: a persistent cute avatar in the corner of the stream that idles, reacts to chat keywords / commands / channel-point redemptions / Twitch events, greets viewers, and carries persistent stats viewers can nurture. It is a **modular feature** of BotOfTheSpecter — one backend serving all channels, each fully isolated — that streamers enable and configure from the dashboard and drop into OBS as a single browser source.

**Success for the operator (you):** one backend instance serves every channel with zero per-streamer deployment; the pet rides existing event plumbing (no new EventSub subscriptions, no per-channel processes); idle channels cost ~nothing (decay is computed client-side, not ticked server-side); assets live in the existing media CDN.

**Success for streamers:** enable in the dashboard, upload (or template) their pet art, map a handful of triggers, paste one browser-source URL into OBS, and immediately have a reactive companion that grows with their community — with full control over art, reactions, and stats, none of it locked to one character.

**Out of scope (v1):**
- Cross-channel pet "visiting"/social features.
- A shared template marketplace (operator templates as a Phase-2 convenience only).
- Live2D / rigged skeletal animation (decided against: heavy, hard to self-host/customize).
- Per-viewer pet ownership (the pet is channel-owned; per-viewer *attribution* is a later add).
- A separate revocable overlay token (cross-overlay project, not pet-specific).

---

## 2. Core Features (Prioritized)

Priority tags: **[MVP]**, **[P2]** (Phase 2), **[L]** (Later).

### 2a. Per-Channel Configuration & Isolation
- **[MVP]** Each channel's pet config, triggers, animations, and state live in its **own per-user MySQL DB** — isolation is structural, not a `WHERE channel=` filter.
- **[MVP]** A `pet_settings` singleton row per channel (enabled flag, name, position, scale, which stat bars to show, decay rates, idle animation).
- **[MVP]** WebSocket `channel_code` routing guarantees one channel's reactions never reach another's overlay (already enforced in `broadcast_event_with_globals`).
- **MVP done:** enabling the feature for channel A creates its tables/defaults; channel B is wholly unaffected; A's overlay only ever receives A's events.

### 2b. Pet Visuals, Templates & Customization
- **[MVP]** Upload sprite sheets / frame-PNG sets per **animation state** (idle, happy, hype, sad, sleep, eat, plus custom states), with frame size, frame count, fps, loop flag.
- **[MVP]** Position (4 corners), scale, flip, default idle animation, optional speech-bubble styling.
- **[P2]** Operator-provided starter templates a streamer can adopt and then tweak.
- **[L]** Accessory/skin layers, day/night idle variants, seasonal art.
- **MVP done:** a streamer uploads their own art for ≥3 states, sees it idling and animating in OBS, and can reposition/scale it.

### 2c. Chat Keyword & Command Integration (per channel)
- **[MVP]** Map chat **keywords** → animation (+ optional stat effect, speech bubble, cooldown). e.g. keyword `pog` → `hype` animation, `+2 happiness`.
- **[MVP]** Map bot **commands** → reaction (e.g. `!pet` shows current mood; `!feed` plays `eat` + raises hunger bar).
- **[MVP]** Per-trigger **cooldowns** (reuse the bot's existing cooldown-bucket system) so chat can't spam-animate.
- **MVP done:** typing a configured keyword in chat animates the pet within ~1s and nudges a stat.

### 2d. Twitch Event Reactions
- **[MVP]** Reactions to **follow / sub / raid / cheer** — the bot already fires these (`websocket_notice("TWITCH_*")`); the same handlers also resolve the pet reaction and emit `PET_REACT`.
- **[MVP]** **Greeting** on `FIRST_CHAT` (already emitted) — pet waves + speech bubble "Hi {user}!".
- **[P2]** Scale reactions by magnitude (bigger cheer → bigger hype; raid size → longer celebration).
- **MVP done:** a follow/sub/raid/cheer each triggers its mapped animation + bubble on the overlay.

### 2e. Channel Point Reward Mapping & Interactions
- **[MVP]** Map a **reward_id → pet reaction** (+ stat effect). The `TWITCH_CHANNELPOINTS` event already carries `rewards` JSON; the bot matches the reward and emits `PET_REACT`.
- **[MVP]** "Interaction" rewards: e.g. a "Feed the pet" reward raises hunger and plays `eat`.
- **[P2]** Reward-driven mini-actions (pet does a trick, changes outfit for N minutes).
- **MVP done:** redeeming a mapped reward animates the pet and applies its stat change.

### 2f. Overlay Frontend & Browser Source Delivery
- **[MVP]** Single browser source `https://overlay.botofthespecter.com/pet.php?code=API_KEY`.
- **[MVP]** Sprite animation engine (frame stepping, fps, loop, one-shot reactions returning to idle), **reaction queue** (overlay rule 3 — sequential, not overlapping), auto-reconnect (rule 4), resolution-independent (rule 7), transparent background.
- **[MVP]** Stat bars + level rendered from `PET_STATE`, with **client-side decay interpolation** (no polling).
- **MVP done:** the overlay survives OBS reload showing live state, plays queued reactions smoothly, and reconnects after a WebSocket drop.

### 2g. Streamer Configuration Interface
- **[MVP]** `dashboard/pet.php`: enable toggle, sprite/animation manager (upload + map states), trigger table (keyword/command/reward/event → animation + stat effect + cooldown + bubble), stats tuning (starting values, decay rates, which bars show), styling, and a **"Test reaction"** button that fires a `PET_REACT` to the live overlay.
- **[MVP]** Card in `overlays.php` with the OBS URL.
- **MVP done:** a streamer can configure everything above without editing files, and test each reaction live.

### 2h. Persistence & State (per channel)
- **[MVP]** `pet_state` singleton: happiness / hunger / energy (0–100) + level + xp + `last_interaction_at`. Interactions adjust stats; XP accrues; level derived from XP.
- **[MVP]** **Lazy decay**: store value + timestamp + per-stat decay rate; the overlay (and the bot, on read) computes the *current* value as `max(0, stored − rate × elapsed)`. No server ticker.
- **[P2]** Per-viewer attribution (`pet_contributors`): who fed/played most; leaderboards; "best friend" of the pet.
- **[L]** Mini-games, evolution/growth stages tied to level, mood-driven idle variations.
- **MVP done:** stats persist across stream sessions and OBS reloads, visibly decay over time, and rise on interaction.

---

## 3. High-Level Architecture

**Multi-tenancy** is structural: every channel has its own MySQL DB; the pet's four tables live there. No shared pet table with a channel column — isolation can't leak.

**Event flow (bot-resolved, overlay renders) — the core pattern:**

```
Twitch (chat / EventSub / redemption)
        │
        ▼
  Bot (beta.py)  ── PetManager (per-channel trigger config, cached in memory) ──┐
        │  matches trigger → resolves animation + stat effect + cooldown        │
        │  writes pet_state (per-user DB) for stateful effects                  │
        ▼                                                                        │
  websocket_notice(PET_REACT / PET_STATE, additional_data=…)                    │
        │  HTTP GET → websocket.botofthespecter.com/notify                      │
        ▼                                                                        │
  WebSocket server  ── routes by channel_code (generic else branch) ───────────┘
        │
        ▼
  pet.php overlay (Socket.io)  → plays animation from its sprite manifest,
                                  updates stat bars, queues concurrent reactions
```

Why **bot-resolves, overlay-renders** (not overlay-side matching): trigger config + cooldowns + stat math stay server-side (one source of truth, mod-controllable, no config duplication); the overlay is a thin renderer that loads its sprite manifest + display config once on page load and otherwise just plays what it's told. Stateful changes *must* go through the bot/DB anyway, so routing reactions the same way keeps one path.

**Real-time updates:** new events ride the existing WebSocket fabric:
- `PET_REACT` — bot → overlay: play `{animation, duration?, bubble?, sound?}` once, then return to idle.
- `PET_STATE` — bot → overlay: `{happiness, hunger, energy, level, xp, last_interaction_at, decay_rates}` so the overlay interpolates decay locally.
- `PET_SETTINGS_UPDATE` — dashboard → overlay: hot-reload config/sprite manifest without an OBS refresh (mirrors `SPECTER_SETTINGS_UPDATE`).

All three are unrecognised by the server and fall through `notify_http`'s generic `else` (broadcasts to the channel's clients + global listeners) — **no WebSocket-server change required**.

**Data storage:** per-user DB, four tables (§3.1). Config is small and read-mostly; the bot caches each channel's trigger set in memory and refreshes on `PET_SETTINGS_UPDATE`.

**Overlay URL/token strategy:** reuse `?code=API_KEY` (the key *is* the per-channel token). Consistent with all existing overlays; zero new infra. The overlay PHP resolves `code → username` and reads that DB only.

**Auth model:** streamers authenticate to the dashboard via Twitch OAuth (existing); the bot uses its own Twitch tokens (existing); overlay access is the URL key. No new auth surface.

**Realistic for solo/small team:** no new processes, no new EventSub subscriptions, no server ticker. The pet is a config + rendering layer over plumbing that already runs.

### 3.1 Data model (per-user DB)

Four tables, all living in the channel's own per-user DB. House style throughout: InnoDB, `utf8mb4_unicode_ci`, indexes rather than hard foreign keys (cascades handled in app code). All four are added to `./dashboard/usr_database.php`'s `$tables` array so they auto-provision across per-user DBs.

**`pet_settings`** — one singleton row per channel (`id = 1`) holding display and tuning config:
- `enabled` (off by default), `pet_name` (default "Pet"), `idle_animation` (default "idle").
- `position` — one of the four corners, default bottom-right; `scale` (a decimal, default 1.00); `flip`.
- `show_stats` (on by default) and `visible_stats` (default "happiness,hunger,energy").
- `bubble_enabled` (on by default), `sound_enabled` (off by default).
- Per-stat decay rates expressed as points per hour (decimals): happiness 2.0, hunger 3.0, energy 1.0.
- `updated_at`.

**`pet_animations`** — one row per animation state (a sprite sheet). `name` is unique (idle, happy, hype, sad, sleep, eat, or a custom state). Each row stores `sprite_file` (a file under the user's media `pet/` subdir), `frame_width`, `frame_height`, `frame_count` (default 1), `fps` (default 12), a `loop` flag (on by default), and `created_at`.

**`pet_triggers`** — the heart of customization, with one table covering every trigger kind. `trigger_type` is one of `chat_keyword`, `command`, `redemption`, `event`, or `interaction`; `trigger_value` holds the keyword / command name / reward_id / event name (follow|sub|raid|cheer|first_chat) / interaction name (feed|play). Each row maps to an `animation` (matching `pet_animations.name`), an optional `bubble_text` (supports the `{user}` placeholder), per-stat effects (`effect_happiness`, `effect_hunger`, `effect_energy`, default 0), `xp` gained, a `cooldown_seconds` (default 5), and an `enabled` flag. Indexed on `(trigger_type, trigger_value)` so a lookup is cheap.

**`pet_state`** — one singleton row per channel (`id = 1`) for the persistent stats: `happiness`, `hunger`, `energy` (each 0–100, default 80), `level` (default 1), `xp`, `last_interaction_at`, and `updated_at`. Decay is never ticked server-side — the current value is computed lazily on read as `max(0, stored − rate × elapsed)`.

---

## 4. Technical Considerations

**Multi-channel Twitch integration.** No new EventSub subscriptions or chat clients: the bot already subscribes per channel and already broadcasts `TWITCH_FOLLOW/SUB/RAID/CHEER`, `TWITCH_CHANNELPOINTS`, `CHAT_MESSAGE`, `FIRST_CHAT`. The pet hooks into those *existing* handlers in `beta.py`. Scaling pattern: the `PetManager` caches each channel's `pet_triggers` set in memory (refreshed on `PET_SETTINGS_UPDATE`) so a chat-keyword check is an in-memory dict lookup, not a DB hit per message — important because chat is the highest-volume event.

**Real-time push.** Reuse the WebSocket server (SocketIO, `channel_code` routing). New `PET_*` events need no server code (generic `else` branch). Chat-keyword reactions are resolved in the bot, so the overlay does **not** subscribe to the full `CHAT_MESSAGE` firehose — it only receives targeted `PET_REACT`s, keeping overlay bandwidth tiny.

**OBS browser source performance.** Preload all sprite frames on page load; animate via `requestAnimationFrame` with a frame accumulator (not per-frame `setInterval`); use GPU-friendly `transform`/`opacity` only; transparent body; one active reaction at a time with a bounded queue (drop or coalesce if the queue exceeds ~5 to survive raid spam); cap sprite-sheet dimensions (e.g. ≤ 4096px) and frame count at upload time.

**Security & isolation.** `channel_code` routing prevents cross-channel reaction leakage; the overlay PHP reads only the DB matching its `?code=`. Uploaded sprites are validated server-side: MIME + extension allowlist (`png`, `webp`), max dimensions, max file size, and re-encoded/stripped of metadata to neutralize malicious payloads. Bubble text is escaped on render (overlay XSS). Trigger values are length-capped and typed.

**Asset storage & delivery.** Sprites in the unified media library under `/var/www/media/{username}/pet/` → served by `media.botofthespecter.com` (CDN, cache-friendly, immutable filenames). Reuse `storage_used.php` accounting. Images are a *new* media type for the library (today MP3/MP4) — the pet uploader handles its own image validation rather than going through the alert-focused `media.php`.

**Deployment.** Single backend, multi-tenant (preferred and what BotOfTheSpecter already is). No per-streamer instance. Idle channels incur zero ongoing cost: no ticker, decay is interpolated client-side, config is cached, and the overlay is static + a quiet WebSocket connection.

---

## 5. Streamer Experience & Setup Flow

1. **Enable** — Dashboard → Pet Overlay → toggle on. Creates default `pet_settings`/`pet_state` rows.
2. **Upload art** — Add animation states (idle, happy, hype, sad, eat…), uploading a sprite sheet per state and entering frame width/height/count/fps/loop. A live preview plays the sheet so the streamer confirms framing before saving.
3. **Map reactions** — Build the trigger table: pick a type (chat keyword / command / channel-point reward / Twitch event / interaction), enter the value, choose an animation, optional speech bubble (`Hi {user}!`), stat effects, and cooldown.
4. **Tune stats** — Set starting happiness/hunger/energy, decay rates, and which bars are visible.
5. **Get the URL** — Copy `https://overlay.botofthespecter.com/pet.php?code=YOUR_KEY` into an OBS browser source.
6. **Test** — "Test reaction" buttons fire a live `PET_REACT` to the overlay so the streamer verifies art + timing without waiting for a real event. A "pet the pet" test also exercises a stat change.

**MVP config UI must support:** enable toggle; sprite/animation CRUD with preview; trigger CRUD across all five trigger types; stats tuning; position/scale/styling; per-reaction live test; the OBS URL with a copy button.

---

## 6. Development Roadmap

**Stage 0 — Schema & scaffolding.** Add the four tables to `usr_database.php`; stub `pet.php` (overlay) + `pet.php` (dashboard); add the `overlays.php` card.

**Stage 1 — Render + reactive core (first visible value).**
- Overlay sprite engine: idle loop + one-shot reactions + queue + reconnect.
- Dashboard sprite/animation manager (upload + preview) and trigger table for **chat keyword + command** types.
- Bot `PetManager`: cached trigger config; hook chat/command processing; emit `PET_REACT`; new `websocket_notice` branch.
- **Shippable:** pets animate to chat keywords and commands across all channels.

**Stage 2 — Event & redemption reactions.**
- Hook existing EventSub + `TWITCH_CHANNELPOINTS` handlers; map follow/sub/raid/cheer/first-chat + reward_id → reactions; speech-bubble greetings.

**Stage 3 — Stateful pet (completes the MVP target).**
- `pet_state` reads/writes; stat effects on triggers; XP/level; lazy decay (bot on read, overlay interpolates); stat bars on the overlay; `!feed`/`!play` interaction triggers; "Test" interactions.
- **MVP complete:** reactive **and** stateful, custom art, fully per-channel.

**Phase 2 (post-MVP).** Per-viewer attribution + leaderboards; magnitude-scaled reactions; operator starter templates; richer bubble/styling; sound on reactions.

**Later.** Mini-games; evolution/growth stages by level; accessories/skins; mood-driven idle variants; (separately) scoped revocable overlay tokens across all overlays.

---

## 7. Risks, Challenges & Mitigations

| Risk | Mitigation |
|---|---|
| **Chat keyword matching adds load** (highest-volume event path) | Resolve in-bot against an **in-memory cached** trigger dict (refresh on `PET_SETTINGS_UPDATE`); per-trigger cooldowns via the existing bucket system; never ship the chat firehose to the overlay. |
| **Reaction spam during raids** (overlapping animations, jank) | Single active reaction + bounded queue (coalesce/drop beyond ~5); cooldowns; magnitude coalescing in Phase 2. |
| **Setup too hard for non-artists** (custom upload at MVP) | Live sprite-sheet preview; sensible default frame values; sane default triggers seeded on enable; operator templates as a fast-follow (Phase 2) so art isn't a hard prerequisite. |
| **Malicious / oversized uploads** | Server-side MIME + extension allowlist, dimension/size caps, re-encode + metadata strip, frame-count cap. |
| **Cross-channel leakage** | Structural per-DB isolation + `channel_code` routing (already enforced); overlay reads only its `?code=` DB. |
| **Stateful decay cost at scale** | **No server ticker** — lazy decay (value + timestamp + rate), interpolated client-side; idle channels cost nothing. |
| **OBS performance on weak encoders** | Preload frames, `requestAnimationFrame` + transforms only, cap sheet size, transparent background, single active animation. |
| **API key in overlay URL is sensitive** (reused decision) | Document the risk; treat scoped revocable overlay tokens as a separate cross-overlay project (§6 Later) rather than a pet-only fork. |

---

## 8. Opportunities for Differentiation

- **Self-host parity for free.** Unlike a hosted-only Triiibe, this runs as a module of a backend a streamer can already self-host — one instance, all channels, no extra service.
- **Deep bot integration.** The pet reacts to things only a full bot knows: custom commands, points/economy, watch-time, death counters, first-chat — e.g. pet celebrates a new death-counter milestone or a viewer's watch-time rank. This is the strongest moat vs a standalone pet widget.
- **Unified trigger model.** One `pet_triggers` table treats keywords, commands, rewards, events, and interactions identically — streamers learn one mental model, and any future event source plugs in for free.
- **Zero-idle-cost stateful pet.** Client-side decay interpolation means persistent stats with no polling/ticker — cheaper and simpler than competitors that run per-pet timers.
- **Bring-your-own-art, no lock-in.** Sprite-sheet upload + per-state mapping means any character (mascot, emote-style blob, the streamer's OC) works; templates accelerate but never constrain.
- **Test-driven setup.** Live "Test reaction" buttons firing real overlay events make configuration verifiable in seconds — a notably smoother onboarding than trial-by-live-stream.

---

## 9. Open decisions

1. **Stat set.** Proposed happiness / hunger / energy + level/xp. Trim to just happiness + level for a leaner MVP, or keep all three bars? Recommendation: keep three (richer, cheap given lazy decay).
2. **Sprite subdir.** `/var/www/media/{user}/pet/` (recommended, isolates pet art) vs the flat media dir shared with alert sounds.
3. **Overlay file name.** `pet.php` (recommended) vs `pet-overlay.php`.
4. **Default seeded triggers on enable.** Ship a small default set (follow/sub/raid/cheer → generic hype, `!feed` → eat) so a streamer sees life immediately, or start empty? Recommendation: seed defaults.
5. **Greeting scope.** Greet on `FIRST_CHAT` only (recommended — once per viewer per stream) vs every returning viewer.
6. **Reaction queue overflow policy.** Drop oldest vs coalesce identical reactions during spam. Recommendation: coalesce identical, then drop oldest beyond cap.
