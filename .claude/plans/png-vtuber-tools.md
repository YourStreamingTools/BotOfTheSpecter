# PNG / VTuber Tools — Feature Specification & Implementation Plan

> Status: **Draft**. Last revised 2026-06-05.
> Inspiration: PNGTuber apps (Veadotube mini, PNGTuber Plus, kuroiro) — a lightweight, "no rigging required" alternative to Live2D/3D VTubing: a static PNG that opens its mouth when you talk and blinks/bounces so it feels alive. We bring that into Specter as a browser-source overlay driven by the streamer's own infrastructure.

**Confirmed product decisions** (each is also listed in §9):

1. **Reactivity = the streamer's own MIC voice-activity.** The avatar reacts to *their voice*: a dashboard captioner-style page runs Web Audio (`getUserMedia` → `AudioContext` → `AnalyserNode`), computes RMS volume vs a tunable threshold with attack/release smoothing, derives a `talking` / `idle` (and optional `loud`) state, and emits it over the WebSocket scoped by `?code`. The OBS overlay is **display-only** and swaps the PNG. This mirrors the just-shipped Closed Captions feature exactly (mic lives in a real browser; OBS can't reach it).
2. **MVP = a genuinely usable 2-state PNG-tuber.** Mouth-closed/idle + mouth-open/talking PNGs swapped on the voice threshold, **plus** client-side JS blink (opacity swap on a randomized timer) and a gentle idle bounce/sway (CSS `transform`). Position + scale config. That alone is a usable product — "a good start."
3. **Separate feature, shared infra — NOT folded into the Pet system.** New `./overlay/avatar.php` + `./dashboard/avatar.php` reuse the Pet plan's plumbing (media-library upload, `?code` auth, per-channel settings table, wildcard WebSocket broadcast) but live as their own feature, because the mic-driven *source* is fundamentally different from the Pet's chat/event triggers. The two systems **converge** only at Phase 3 (event-driven expression reactions).
4. **Reuse the unified media library** for assets (`/var/www/media/{user}/avatar/`), `png`/`webp` only, with server-side validation (MIME + extension allowlist, size/dimension caps, re-encode + metadata strip) — same model as the Pet plan.
5. **Reuse the `?code=API_KEY` overlay URL.** Same per-channel browser-source auth as the other 20 overlays — no new token infra.

---

## 0. Affected systems (BotOfTheSpecter mapping)

Almost everything this feature needs is **already-solved infrastructure**. Where the Pet plan reused it, so do we — and the Closed Captions feature is the *direct* precedent for the mic-driven half.

| Requirement | Existing capability reused |
|---|---|
| Per-channel isolation | Per-user MySQL DB (DB name = username) |
| Mic capture in a real browser (OBS can't) | **Closed Captions dashboard page** — `getUserMedia` + Socket.io emit scoped by `?code` (`./dashboard/closed-captions.php`) |
| Real-time push + correct routing | WebSocket server routes by `channel_code`; unknown events fall through `notify_http`'s generic `else` branch to the channel's clients + global listeners |
| Per-channel overlay URL | `?code=API_KEY` browser-source pattern (same as closed-captions, walkons, etc.) |
| Streamer OAuth + config UI | Dashboard (Twitch OAuth, per-`.botofthespecter.com` session) |
| Asset storage / CDN | Unified media library `/var/www/media/{user}/` → `https://media.botofthespecter.com/{user}/{file}` |
| Live config hot-reload | `SPECTER_SETTINGS_UPDATE` push pattern (closed-captions / pet `*_SETTINGS_UPDATE`) |
| Twitch events for Phase 3 | Bot already broadcasts `TWITCH_FOLLOW/SUB/RAID/CHEER`, `TWITCH_CHANNELPOINTS`, `FIRST_CHAT` |

**New code:**

- `./overlay/avatar.php` — **new.** Display-only renderer: swaps mouth PNGs on `AVATAR_STATE`, runs client-side blink + idle bounce, applies position/scale. Loads its display config once on page load via a JSON endpoint (mirrors closed-captions `?action=get_..._settings`).
- `./overlay/avatar.css` (or a namespaced block in the overlay folder's own stylesheet) — `.avatar-overlay-page-*` classes only (overlay folder's own stylesheet, **no cross-folder CSS linking**, per the ui-theme rule).
- `./dashboard/avatar.php` — **new.** Two halves, like closed-captions: (a) a **mic engine** (Web Audio voice-activity → emit `AVATAR_STATE`) with a start/stop button + a live "mouth" preview; (b) an **appearance/asset config** form (upload talk-state PNGs, position, scale, mic threshold, smoothing, blink + bounce settings) saved to the per-user DB and pushed live via `AVATAR_SETTINGS_UPDATE`.
- `./dashboard/menu.php` — add an Avatar nav entry with a `t()` lang key (e.g. `navbar_avatar`), mirroring line 48's `navbar_closed_captions`.
- `./dashboard/overlays.php` — add an Avatar card (OBS URL + settings link), mirroring the Closed Captions card at lines 236–248.
- `./dashboard/lang/{en,de,fr}.php` — new i18n keys (base `en.php` + de/fr translations; escape French apostrophes).
- `./dashboard/includes/usr_database.php` — add `avatar_settings` to the `$tables` array (auto-provisioned by the `CREATE TABLE IF NOT EXISTS` loop) **and** an "ensure default row" `INSERT ... WHERE NOT EXISTS` (mirrors the closed-captions block).
- `./bot/beta.py` — **only for Phase 3** (event-driven expressions). New `AvatarManager` that maps Twitch events/redemptions → a temporary reaction expression and emits `AVATAR_STATE`. Per [bot-versions.md](../rules/bot-versions.md). **MVP needs no bot change at all** (see §3).
- `./bot/beta-v6.py` — port the Phase 3 hooks once stable.
- `./bot/bot.py` (stable) — **not changed.**
- `./websocket/server.py` — **no change required** (generic `else` broadcasts unknown `AVATAR_*` events to the channel + globals); optional explicit branches only for logging.
- `./api/api.py` — **no changes** (overlay self-serves via PHP; dashboard writes per-user MySQL directly, per [data-flow.md](../rules/data-flow.md)).

---

## 1. Project Vision & Scope

A customizable, multi-channel **PNG/VTuber avatar overlay**: a static-image avatar in the corner of the stream whose mouth opens when the streamer talks, that blinks and gently bobs so it reads as "alive," and (later) reacts to channel events with brief expression changes. It is the lightweight, zero-rigging entry point to VTubing — no Live2D model, no tracking software — built as a **modular feature** of BotOfTheSpecter: one backend serving all channels, each fully isolated, enabled and configured from the dashboard and dropped into OBS as a single browser source.

**The defining architectural fact:** OBS browser sources **cannot access the microphone**. So the avatar's voice analysis runs in the streamer's **real browser** on a dashboard page — *exactly* as the Closed Captions captioner runs `getUserMedia` + the Web Speech API there and emits over the WebSocket. The OBS overlay is a pure renderer that receives `AVATAR_STATE` and swaps the PNG. Anyone proposing to "just read the mic in the overlay" is fighting the platform; this plan does not.

**Success for the operator (you):** one backend serves every channel with zero per-streamer deployment; the MVP rides existing plumbing with **no bot change and no WebSocket-server change**; assets live in the existing media CDN; idle channels cost nothing (the avatar is static + a quiet WebSocket connection, and voice analysis only runs while the streamer has the dashboard mic page open).

**Success for streamers:** enable in the dashboard, upload two PNGs (mouth closed / mouth open), open the avatar dashboard page and click "Start mic," paste one browser-source URL into OBS, and immediately have a talking, blinking PNG-tuber — no VTube Studio, no webcam, no model rig.

**Out of scope (v1):**
- Webcam face-tracking / head-pose / eyebrow tracking (this is the *anti*-feature — PNG-tubing exists to avoid it).
- Live2D / Spine / 3D rigged models.
- True phoneme/viseme lip-sync (multiple mouth shapes per sound) — MVP is binary open/closed; visemes are a Later item.
- Multiple simultaneous avatars / scene-based avatar switching (Later).
- Props, accessories, hand-tracking, physics bones (Later).
- Per-viewer or chat-driven control of the streamer's avatar (the avatar is the *streamer's* face; chat does not puppet it). Event-driven *reactions* in Phase 3 are streamer-configured, not chat-puppeted.
- A separate revocable overlay token (cross-overlay project, not avatar-specific).

---

## 2. Core Features (Prioritized)

Priority tags: **[MVP]** (Phase 1), **[P2]** (Phase 2), **[L]** (Later).

### 2a. Per-Channel Configuration & Isolation
- **[MVP]** Each channel's avatar config and assets live in its **own per-user MySQL DB** — isolation is structural, not a `WHERE channel=` filter.
- **[MVP]** An `avatar_settings` singleton row per channel (enabled flag, asset references, position, scale, mic threshold/smoothing, blink + bounce config, active expression).
- **[MVP]** WebSocket `channel_code` routing guarantees channel A's `AVATAR_STATE` never reaches channel B's overlay.
- **MVP done:** enabling the feature for channel A creates its table/defaults; channel B is unaffected; A's overlay only ever receives A's state.

### 2b. Mic Voice-Activity Engine (dashboard, real browser)
- **[MVP]** A dashboard captioner-style page captures the mic with `getUserMedia({audio:true})`, builds an `AudioContext` + `AnalyserNode`, and on a `requestAnimationFrame` loop computes RMS volume.
- **[MVP]** Derives state with a **tunable threshold + attack/release smoothing** (open quickly when volume crosses up, hold briefly before closing — prevents jittery mouth flap on consonant gaps).
- **[MVP]** Emits `AVATAR_STATE { state: 'talking' | 'idle', expression: <active> }` over Socket.io scoped by `?code` only on change (debounced), exactly like `socket.emit('CLOSED_CAPTION', {code, …})`.
- **[P2]** Optional third `loud` state (a second, higher threshold) for an "excited/shouting" mouth or a shake effect.
- **[MVP]** Start/Stop button + a small live preview of the avatar reacting in the dashboard, so the streamer tunes the threshold before going live (mirrors the closed-captions live preview).
- **MVP done:** speaking into the mic on the dashboard page flips the OBS overlay's mouth within ~100 ms; silence closes it; the threshold slider visibly changes sensitivity.

### 2c. Avatar Visuals & Customization
- **[MVP]** Upload **two PNGs**: mouth-closed/idle and mouth-open/talking (`png`/`webp`, transparent background expected). Stored in the media library.
- **[MVP]** Position (4 corners or free X/Y), scale, optional horizontal flip.
- **[MVP]** **Blink**: an optional separate blink-overlay/frame or an opacity dip on a randomized timer (e.g. every 3–6 s, 120 ms closed) — pure client-side JS, no events.
- **[MVP]** **Idle bounce/sway**: a gentle, configurable `transform` animation (vertical bob + slight rotation) so a static PNG isn't dead-still; intensity slider.
- **[P2]** Uploadable dedicated **blink-frame PNGs** (eyes-closed art) rather than the opacity dip, for cleaner blinks.
- **[P2]** Multiple **expressions** (e.g. neutral, happy, surprised, angry) — each expression is its own pair of closed/open PNGs.
- **[L]** Accessory/prop layers, day/night variants, costume swaps.
- **MVP done:** a streamer uploads two PNGs, sees them in OBS at the chosen corner/scale, blinking and gently bobbing, mouth driven by their voice.

### 2d. Expression Switching
- **[P2]** **Manual / hotkey expression switch** — the dashboard mic page (which the streamer has open anyway) exposes expression buttons and optional keyboard hotkeys; switching changes the `expression` field in the emitted `AVATAR_STATE` so the overlay swaps to that expression's closed/open pair.
- **[L]** Time-based or random expression rotation.
- **P2 done:** the streamer presses a key/button and the overlay's avatar changes expression while still mouth-flapping correctly for the new expression's art.

### 2e. Event-Driven Expression Reactions (convergence with the Pet system)
- **[Phase 3 / borderline P2]** Map a Twitch event or channel-point reward → a temporary reaction expression for N seconds, then revert to the streamer's active expression. e.g. follow → `happy` for 4 s; raid → `surprised` for 6 s; a "make my avatar angry" reward → `angry` for 10 s.
- **[Phase 3]** This **borrows the Pet plan's `pet_triggers` model and its bot-resolves/overlay-renders pattern**: the bot matches the event against a trigger table, resolves the reaction expression + duration, and emits `AVATAR_STATE { state, expression, expression_hold_seconds }`. This is the only phase that touches the bot (`beta.py`, new `AvatarManager`).
- **[L]** Magnitude scaling (bigger cheer → longer/stronger reaction), combo reactions, sound on reaction.
- **Phase 3 done:** a follow/sub/raid/redemption flips the avatar to its mapped expression for the configured time, then it returns to normal mic-driven idle/talking.

### 2f. Overlay Frontend & Browser Source Delivery
- **[MVP]** Single browser source `https://overlay.botofthespecter.com/avatar.php?code=API_KEY`.
- **[MVP]** Display-only: listens for `AVATAR_STATE`, swaps the correct PNG (current expression × open/closed), runs blink + bounce locally, applies position/scale; transparent background; resolution-independent (overlay rule 7); auto-reconnect on WebSocket drop (rule 4); preloads all PNGs on load and animates via `requestAnimationFrame` + `transform`/`opacity` only.
- **[MVP]** Hot-reload on `AVATAR_SETTINGS_UPDATE` — refetch config without an OBS source reload (mirrors closed-captions `SPECTER_SETTINGS_UPDATE`).
- **MVP done:** the overlay survives OBS reload, shows the avatar mouth-flapping to the live mic state, blinks, bobs, and reconnects after a WebSocket drop.

### 2g. Streamer Configuration Interface
- **[MVP]** `dashboard/avatar.php`: enable toggle; upload closed/open PNGs with a live preview; position/scale/flip; mic threshold + smoothing (attack/release) sliders; blink toggle + interval; bounce toggle + intensity; the mic Start/Stop control; the OBS URL with a copy button; a **"Test"** control that fires a one-off `AVATAR_STATE` to the overlay (e.g. force "talking" for 1 s) so the streamer verifies art without speaking.
- **[MVP]** Nav entry in `menu.php` and a card in `overlays.php`.
- **[P2]** Expression manager (CRUD of named expressions, each with its closed/open pair) + hotkey binding UI.
- **[Phase 3]** Trigger table (event/reward → expression + hold seconds), reusing the Pet trigger-table UI pattern.
- **MVP done:** a streamer configures everything in 2g without editing files and can test the avatar live.

---

## 3. High-Level Architecture

**Multi-tenancy** is structural: every channel has its own MySQL DB; the avatar's settings table lives there. No shared avatar table with a channel column.

**The bot/overlay split — and why MVP needs no bot:**

```
  Streamer's MICROPHONE
        │  (OBS cannot read this — must be a real browser)
        ▼
  dashboard/avatar.php  (real browser tab the streamer keeps open)
        │  getUserMedia → AudioContext → AnalyserNode → RMS
        │  threshold + attack/release smoothing → 'talking' | 'idle' (| 'loud')
        ▼
  socket.emit('AVATAR_STATE', { code, state, expression })   ── on change, debounced
        │  (Socket.io, scoped by ?code — identical to CLOSED_CAPTION emit)
        ▼
  WebSocket server  ── unknown event → generic `else` in notify_http ──
        │  broadcasts to channel_code clients + global listeners
        ▼
  overlay/avatar.php  (OBS browser source, DISPLAY-ONLY)
        │  swaps PNG (expression × open/closed); blink + bounce run locally
        ▼  rendered avatar
```

For the **MVP, the bot is not in this loop at all.** The dashboard page is the producer, the overlay is the consumer, the WebSocket is the (unchanged) pipe. This is the single most important architectural point and the reason the MVP is shippable without `beta.py` work.

**Phase 3 adds the bot as a *second* producer** of `AVATAR_STATE` (for event-driven expressions), reusing the Pet plan's **bot-resolves / overlay-renders** pattern:

```
Twitch event / redemption ──▶ bot (beta.py) AvatarManager
   matches trigger table ──▶ resolves reaction expression + hold seconds
   ──▶ websocket_notice(AVATAR_STATE, {expression, expression_hold_seconds})
   ──▶ overlay shows reaction for N s, then reverts to live mic-driven state
```

**Real-time events (all unrecognized by the server → generic `else` → no server.py change):**
- `AVATAR_STATE` — dashboard (MVP) **and** bot (Phase 3) → overlay. `{ state: 'talking'|'idle'|'loud', expression: <name>, expression_hold_seconds?: int }`. The overlay treats mic-driven state and event-driven expression as orthogonal: state controls mouth, expression controls which art set is shown; a Phase-3 reaction sets `expression` for `expression_hold_seconds`, after which the overlay reverts to the streamer's `active_expression`.
- `AVATAR_SETTINGS_UPDATE` — dashboard → overlay: hot-reload appearance config without an OBS refresh (mirrors `SPECTER_SETTINGS_UPDATE` / `PET_SETTINGS_UPDATE`).

**Scoping:** `channel_code` routing (already enforced in `broadcast_event_with_globals`) guarantees one channel's avatar state never reaches another's overlay.

**Data storage:** per-user DB, **one** table (§3.1). Config is small and read-mostly. The overlay fetches it once on page load and on `AVATAR_SETTINGS_UPDATE`.

**Auth model:** dashboard via Twitch OAuth (existing); overlay access is the `?code=API_KEY` URL key (existing). No new auth surface.

**Lazy / no-ticker (inherited from the Pet plan):** there is no server-side animation loop. Blink and bounce are client-side timers/`rAF` in the overlay; the mouth state is event-driven; Phase-3 expression holds are a client-side timeout. Idle channels cost nothing — and when the streamer isn't actively streaming with the mic page open, *zero* `AVATAR_STATE` traffic flows.

### 3.1 Data model (per-user DB)

InnoDB, `utf8mb4_unicode_ci`, single-row settings table keyed `id = 1` — the proven `closed_captions_settings` pattern (`./dashboard/includes/usr_database.php` lines 892–905). MVP ships **one** table. Phase 2 (multiple expressions) adds a second `avatar_expressions` table; the MVP `talk_*` columns become the implicit "default" expression so no data migration is needed.

```sql
-- Singleton config (id = 1) — MVP
CREATE TABLE IF NOT EXISTS avatar_settings (
    id TINYINT PRIMARY KEY DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    -- Asset references (files in /var/www/media/{user}/avatar/)
    closed_image VARCHAR(255) DEFAULT NULL,   -- mouth-closed / idle PNG
    open_image   VARCHAR(255) DEFAULT NULL,   -- mouth-open / talking PNG
    blink_image  VARCHAR(255) DEFAULT NULL,   -- optional eyes-closed PNG (P2; null = opacity-dip blink)
    -- Placement
    position ENUM('top-left','top-right','bottom-left','bottom-right','custom') NOT NULL DEFAULT 'bottom-right',
    pos_x INT NOT NULL DEFAULT 0,             -- used when position = 'custom'
    pos_y INT NOT NULL DEFAULT 0,
    scale DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    flip TINYINT(1) NOT NULL DEFAULT 0,
    -- Mic voice-activity tuning (consumed by the dashboard engine; stored so it persists)
    mic_threshold DECIMAL(5,3) NOT NULL DEFAULT 0.080,  -- RMS level [0..1] above which = talking
    attack_ms INT NOT NULL DEFAULT 40,        -- how fast the mouth opens
    release_ms INT NOT NULL DEFAULT 180,      -- how long the mouth holds open after volume drops
    loud_threshold DECIMAL(5,3) NOT NULL DEFAULT 0.250, -- P2 optional 'loud' state (0 = disabled)
    -- Liveliness (client-side, overlay)
    blink_enabled TINYINT(1) NOT NULL DEFAULT 1,
    blink_interval_min INT NOT NULL DEFAULT 3,  -- seconds
    blink_interval_max INT NOT NULL DEFAULT 6,
    bounce_enabled TINYINT(1) NOT NULL DEFAULT 1,
    bounce_intensity TINYINT NOT NULL DEFAULT 5, -- 0..10
    -- Expression
    active_expression VARCHAR(60) NOT NULL DEFAULT 'default',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Add `avatar_settings` to the `$tables` array in `./dashboard/includes/usr_database.php`, and add an "ensure default row" insert alongside the closed-captions one:

```php
$usrDBconn->query("INSERT INTO avatar_settings (id, enabled) SELECT 1, 0 WHERE NOT EXISTS (SELECT 1 FROM avatar_settings WHERE id = 1)");
```

**Phase 2 — multiple expressions** (added when 2c/[P2] lands; MVP columns stay as the `default` row):

```sql
CREATE TABLE IF NOT EXISTS avatar_expressions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,             -- default, happy, surprised, angry, custom…
    closed_image VARCHAR(255) NOT NULL,
    open_image   VARCHAR(255) NOT NULL,
    blink_image  VARCHAR(255) DEFAULT NULL,
    hotkey VARCHAR(40) DEFAULT NULL,       -- optional dashboard hotkey binding
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Phase 3 — event reactions** reuses the Pet plan's `pet_triggers` shape (an `avatar_triggers` table: `trigger_type ENUM('event','redemption')`, `trigger_value`, `expression`, `hold_seconds`, `cooldown_seconds`, `enabled`). Defer its DDL to the Phase 3 design so we don't ship unused tables.

---

## 4. Technical Considerations

**Mic in a browser, not OBS (the load-bearing constraint).** OBS's CEF browser source does not expose `getUserMedia` to page mic access in a usable way for this, and the streamer would have to grant it per-source even if it did. Closed Captions already proved the answer: do audio in the dashboard tab the streamer keeps open. We copy its `getUserMedia` + explicit-permission-prompt flow (`./dashboard/closed-captions.php` `start()`), its reconnect/backoff (`scheduleReconnect`), and its `REGISTER` + scoped-emit shape. The only swap is Web **Audio** (`AnalyserNode` RMS) instead of Web **Speech**.

**Voice-activity smoothing.** Naive "volume > threshold" produces a strobing mouth on plosives and gaps. Use attack/release: open immediately when RMS crosses the threshold; keep open until RMS stays below for `release_ms`. Emit `AVATAR_STATE` only on a state *change* (debounced), not every frame — this keeps WebSocket traffic to a handful of messages per sentence, not 60/s.

**OBS browser source performance.** Preload both PNGs (and blink/expression art) on page load; drive blink and bounce with `requestAnimationFrame` / CSS animations using `transform`/`opacity` only (GPU-friendly, no layout); transparent `body`; never poll the API for live data (overlay rule 2). The overlay does almost nothing per frame — it swaps an image `src`/visibility on state change and runs two cheap CSS animations.

**Real-time push.** Reuse the WebSocket server (`channel_code` routing). `AVATAR_*` events are unknown to the server and ride the generic `else` branch — **no `server.py` change**. The overlay subscribes only to its own channel's `AVATAR_*` events; it never sees the chat firehose.

**Security & isolation.** `channel_code` routing prevents cross-channel state leakage; the overlay PHP reads only the DB matching its `?code=`. Uploaded PNGs are validated server-side: MIME + extension allowlist (`png`, `webp`), max dimensions, max file size, re-encoded + metadata-stripped to neutralize malicious payloads (same as the Pet plan). Any text rendered (none in MVP) would be escaped. The emitted `AVATAR_STATE` is a tiny enum payload — validate `state`/`expression` against known values on the overlay side and ignore anything else.

**Asset storage & delivery.** PNGs in the unified media library under `/var/www/media/{username}/avatar/` → served by `media.botofthespecter.com` (CDN, immutable filenames). Reuse `storage_used.php` accounting. The avatar uploader handles its own image validation (the library today centers on MP3/MP4; images are the same new media type the Pet plan introduces — coordinate so both features share one validated image-upload path).

**Bot versions.** Per [bot-versions.md](../rules/bot-versions.md): MVP touches **no** bot file. Phase 3 work goes in `./bot/beta.py` (new `AvatarManager`), then ports to `./bot/beta-v6.py`; `./bot/bot.py` (stable) is never touched for this feature.

**Deployment.** Single backend, multi-tenant — what BotOfTheSpecter already is. No per-streamer process, no new EventSub subscriptions (Phase 3 reuses the events the bot already receives), no server ticker.

---

## 5. Streamer Experience & Setup Flow

1. **Enable** — Dashboard → Avatar → toggle on. Creates the default `avatar_settings` row.
2. **Upload art** — Upload the mouth-closed and mouth-open PNGs (transparent background). A live preview shows them composited so the streamer confirms framing/alignment.
3. **Place it** — Choose a corner (or free X/Y), scale, optional flip. Toggle blink (with interval) and idle bounce (with intensity).
4. **Tune the mic** — Click **Start mic**, grant permission, talk: the dashboard preview flaps the mouth. Drag the **threshold** slider until idle silence keeps the mouth shut and normal speech opens it; adjust attack/release if it feels twitchy or laggy.
5. **Get the URL** — Copy `https://overlay.botofthespecter.com/avatar.php?code=YOUR_KEY` into an OBS browser source.
6. **Go live** — Keep the dashboard avatar tab open while streaming (same as the Closed Captions captioner). The "Test" button fires a forced talk/blink so the streamer can confirm the OBS source is wired before going live.

**MVP config UI must support:** enable toggle; closed/open PNG upload with live preview; position/scale/flip; mic threshold + attack/release sliders with a live mic preview; blink toggle + interval; bounce toggle + intensity; mic Start/Stop; the OBS URL with copy; a live "Test" reaction. A clear note (like the closed-captions browser note) explains *why* the dashboard tab must stay open (OBS can't read the mic).

---

## 6. Development Roadmap

**Stage 0 — Schema & scaffolding.** Add `avatar_settings` to `usr_database.php` `$tables` + default-row insert; stub `overlay/avatar.php` and `dashboard/avatar.php`; register in `menu.php` (+ `navbar_avatar` lang key) and `overlays.php` (Avatar card); seed en/de/fr keys.

**Stage 1 — MVP: 2-state mic-driven avatar (first and primary shippable value).**
- Dashboard mic engine: `getUserMedia` + `AnalyserNode` RMS + threshold/attack/release → emit `AVATAR_STATE` (copy the closed-captions connect/reconnect/emit scaffolding).
- Dashboard config form: upload closed/open PNGs (live preview), position/scale/flip, threshold/smoothing sliders, blink + bounce settings, Start/Stop, Test button, OBS URL. Save to `avatar_settings`; push `AVATAR_SETTINGS_UPDATE`.
- Overlay `avatar.php`: load config via JSON endpoint; listen for `AVATAR_STATE` (mouth swap) + `AVATAR_SETTINGS_UPDATE` (hot reload); client-side blink + bounce; auto-reconnect; transparent + resolution-independent.
- **Shippable:** a genuinely usable PNG-tuber — talking, blinking, bobbing — with **no bot change and no WebSocket-server change.** This is the "good start."

**Stage 2 — Expressions & nicer blinks (P2).**
- Uploadable blink-frame PNGs; `avatar_expressions` table; expression CRUD in the dashboard; manual/hotkey expression switching (changes the emitted `expression`); optional `loud` state.

**Stage 3 — Event-driven expression reactions (convergence with the Pet system).**
- New `AvatarManager` in `beta.py`; `avatar_triggers` table (Pet `pet_triggers` shape); map follow/sub/raid/cheer/redemption → reaction expression + hold seconds; bot emits `AVATAR_STATE` with `expression_hold_seconds`; overlay reverts to the live mic-driven `active_expression` after the hold.
- Port hooks to `beta-v6.py`.

**Later.** Phoneme/viseme lip-sync (multiple mouth shapes); scenes / multiple avatars; props & accessory layers; advanced idle physics (spring/inertia bob); sound on reaction; magnitude-scaled reactions; (separately) scoped revocable overlay tokens across all overlays.

---

## 7. Risks, Challenges & Mitigations

| Risk | Mitigation |
|---|---|
| **Streamers expect the mic to work *inside* OBS** | Front-load the constraint in the UI (a browser note like closed-captions') and the setup flow: the mic runs on the dashboard tab, which must stay open. Reuse the exact pattern users already accept for Closed Captions. |
| **Mouth strobes/jitters on speech** | Attack/release smoothing + emit-on-change debounce; tunable threshold with a live preview so each streamer/mic finds its own setting. |
| **WebSocket traffic from per-frame state** | Never emit per frame — only on a debounced state *change*; blink/bounce are local to the overlay and produce zero traffic. |
| **Dashboard tab closed / mic permission lost mid-stream** | Detect `getUserMedia`/track-ended; surface a clear "mic stopped" status (like closed-captions error states); avatar falls back to idle (mouth closed) so it never freezes mid-flap. |
| **Malicious / oversized uploads** | Server-side MIME + extension allowlist (`png`,`webp`), dimension/size caps, re-encode + metadata strip — shared with the Pet image-upload path. |
| **Cross-channel state leakage** | Structural per-DB isolation + `channel_code` routing (already enforced); overlay reads only its `?code=` DB and validates the `AVATAR_STATE` enum. |
| **Scope creep toward face-tracking / Live2D** | Explicitly out of scope (§1). The product *is* the no-tracking option; resist re-adding the complexity it exists to avoid. |
| **OBS performance on weak encoders** | Two tiny image swaps + GPU-only `transform`/`opacity` animations; preload art; transparent background. Cheaper than any video/canvas approach. |
| **Feature confusion with the Pet overlay** | Keep them separate files/menus; document that Avatar = the streamer's mic-driven face, Pet = a chat/event-driven companion. They share infra and converge only at Phase 3 (event reactions). |
| **API key in overlay URL is sensitive** (reused decision) | Document the risk; treat scoped revocable overlay tokens as a separate cross-overlay project (§6 Later), not an avatar-only fork. |

---

## 8. Opportunities for Differentiation

- **Zero-install VTubing inside a tool streamers already run.** No Veadotube/VTube Studio download, no separate window to capture — it's a browser source and a dashboard tab, both already part of Specter. The mic engine reuses the same pattern as Closed Captions, so the moving parts are already battle-tested.
- **One backend, every channel, fully isolated.** Like the Pet overlay: no per-streamer instance, idle channels cost nothing, assets ride the existing CDN.
- **Event-driven expressions via deep bot integration (Phase 3).** Because Specter is a full bot, the avatar can react to things a standalone PNG-tuber app can't see: subs, raids, channel-point redemptions, first-chat — flipping to a reaction expression on a real Twitch event. This is the strongest moat and where Avatar and Pet converge on one trigger model.
- **Bring-your-own-art, no lock-in.** Any two transparent PNGs make a working avatar; expressions and blink frames extend it without forcing a rig or a character.
- **Tunable, mic-honest liveliness.** Attack/release smoothing + client-side blink + idle bounce make a static image feel alive without faking a webcam — and the live dashboard preview makes tuning verifiable in seconds, not by trial-on-stream.
- **Shared infra with the Pet overlay.** Building the avatar second means the media-image upload path, the `*_SETTINGS_UPDATE` hot-reload, and the trigger-table model are all already designed — Avatar is mostly assembly, not invention.

---

## 9. Open decisions

(Each default below is the recommended starting point — listed here so it can be revisited before implementation.)

1. **Reactivity source — confirm mic voice-activity (recommended) vs a PNG "pet."** Recommendation: **mic voice-activity** (a true PNG-tuber), per the Closed Captions precedent. The chat/event-driven "pet" is a *separate* feature already specced in `pet-overlay.md`. If you'd rather the first deliverable be a chat-driven character, that's the Pet plan, not this one.
2. **MVP state breadth — 2-state + blink + bounce (recommended) vs adding blink-frames/expressions in v1.** Recommendation: ship the **2-state + opacity-blink + bounce** MVP first (genuinely usable, no bot, no server change); dedicated blink-frame PNGs and multiple expressions are Phase 2.
3. **Separate `avatar.php` (recommended) vs folding into the Pet system.** Recommendation: **separate feature, shared infra.** The mic source is fundamentally different; merging would muddy both. They converge only at Phase 3 (event-driven expressions reuse the Pet trigger model).
4. **File / feature name — `avatar.php` (recommended) vs `png-tuber.php` / `vtuber.php`.** Recommendation: **`avatar.php`** (short, neutral, future-proof if it grows beyond PNG). Open to `pngtuber.php` if you want the SEO/search match with what streamers call it. Pick before Stage 0 — it's the URL and the table prefix.
5. **Expression-switch mechanism (P2) — dashboard buttons + keyboard hotkeys (recommended) vs chat commands / channel-point only.** Recommendation: **dashboard buttons + hotkeys** on the mic page the streamer already has open (the streamer controls their own face); event/redemption-driven switching is the separate Phase 3 reaction system.
6. **Eventual convergence with the Pet system — share the trigger-table model at Phase 3 (recommended).** Recommendation: at Phase 3, reuse the Pet's `*_triggers` shape and bot-resolves/overlay-renders pattern for event-driven expressions; keep the runtime state paths (mic vs chat) separate. Confirm whether Avatar Phase 3 should wait until the Pet's `PetManager`/trigger UI ships so they share one bot-side trigger framework.
7. **Optional `loud` state — include in MVP or defer to P2?** Recommendation: **defer to P2.** Binary open/closed is the recognizable PNG-tuber look; a third threshold adds tuning burden for little MVP value.
8. **Asset subdir — `/var/www/media/{user}/avatar/` (recommended) vs the flat media dir.** Recommendation: dedicated `avatar/` subdir (mirrors the Pet's `pet/` subdir), isolates avatar art from alert sounds.
