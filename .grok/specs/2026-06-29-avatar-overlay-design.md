# Avatar Overlay (PNG-tuber) - Design Spec

**Date:** 2026-06-29  
**Status:** Shipped (MVP). Replaces the draft plan in `.grok/plans/png-vtuber-tools.md`.  
**Product name:** **Avatar** (not “pngtuber” in URLs or tables).

## Summary

A per-channel PNG avatar overlay for OBS: four transparent frames (mouth × eyes), mouth driven by mic voice-activity from the **Avatar dashboard tab**, optional blink and idle bounce on the overlay. The OBS browser source is display-only. Avatar is **independent** of Closed Captions - each page captures its own mic; they may run at the same time.

## Problem

Streamers want a lightweight VTuber-style presence without Live2D, VTube Studio, or face tracking. OBS browser sources cannot reliably drive mic-based mouth sync themselves. Specter already solves “mic in a real browser → WebSocket → overlay” for Closed Captions; Avatar applies the same pattern to a corner PNG character.

## Goal (MVP - achieved)

- Upload four aligned PNG/WebP frames; mouth opens when the streamer talks.
- Blink swaps to dedicated eyes-closed frames (not opacity tricks).
- Configure position, scale, flip, mic threshold, attack/release, blink/bounce.
- One OBS browser source URL (`?code=API_KEY`).
- Keep a dashboard tab open with **Start mic** while streaming.
- Assets live in the unified media library and count toward storage quota.

## Architecture

```text
Streamer microphone
        │
        ▼
dashboard/avatar.php  (real browser tab - producer)
        │  getUserMedia → AudioContext → AnalyserNode → RMS
        │  threshold + release_ms smoothing → talking | idle
        │  emit AVATAR_STATE (on change + 1.5s heartbeat while mic on)
        ▼
websocket/server.py
        │  caches last AVATAR_STATE per channel_code
        │  broadcasts to overlay clients + replays on overlay register/request
        ▼
overlay/avatar.php  (OBS browser source - consumer)
        │  pickFrameUrl(mouthState × blinkState) → single <img>
        │  blink + bounce run locally; transparent background
        ▼
Rendered avatar on stream
```

**Not in the MVP loop:** Twitch bot (`beta.py` / `beta-v6.py`). Event-driven expression reactions remain future work.

**Not coupled to Closed Captions:** CC does not emit `AVATAR_STATE`. Avatar does not listen for `CAPTIONER_STATUS`. Both may use the mic simultaneously.

## Data model (per-user DB)

Singleton table `avatar_settings` (`id = 1`), provisioned in `./dashboard/includes/usr_database.php` with default-row seed and column migrations for `closed_blink_image` / `open_blink_image`.

| Column | Purpose |
|--------|---------|
| `enabled` | Overlay visible when true and frames exist |
| `closed_image` | Mouth closed, eyes open |
| `open_image` | Mouth open, eyes open |
| `closed_blink_image` | Mouth closed, eyes closed |
| `open_blink_image` | Mouth open, eyes closed |
| `blink_image` | Legacy column; unused by 4-frame UI |
| `position`, `pos_x`, `pos_y`, `scale`, `flip` | Placement |
| `mic_threshold`, `attack_ms`, `release_ms` | VAD tuning (dashboard) |
| `loud_threshold` | Reserved; not used in UI yet |
| `blink_enabled`, `blink_interval_min/max` | Overlay blink timing |
| `bounce_enabled`, `bounce_intensity` | Overlay idle bob |
| `active_expression` | Reserved for multi-expression (Phase 2) |

**Assets:** `/var/www/media/{username}/avatar/` (server) → `https://media.botofthespecter.com/{username}/avatar/{file}`. Included in `calculateStorageUsed()` via media path subdirs. Upload enforced against `$max_storage_size` after re-encode.

**Image rules:** PNG or WebP only; 128–4096 px per side; 5 MB per file; server re-encode via GD with alpha preserved (`imagealphablending` / `imagesavealpha`).

## WebSocket events

| Event | Direction | Purpose |
|-------|-----------|---------|
| `AVATAR_STATE` | Dashboard → server → overlay | `{ code, state: idle\|talking\|loud, expression }` |
| `AVATAR_STATE_REQUEST` | Overlay → server | Replay cached state; broadcast so dashboard re-pushes if mic active |
| `AVATAR_SETTINGS_UPDATE` | Dashboard → overlay | Trigger settings refetch after save |

Explicit handlers in `./websocket/server.py` cache `last_avatar_state[code]` and replay to overlay clients on `REGISTER` (channel `Overlay`, name `Avatar`).

## Overlay behaviour

- Loads settings via `GET avatar.php?code=…&action=get_avatar_settings` (JSON).
- Registers Socket.io client; requests state sync on connect.
- `pickFrameUrl()` selects one of four frame URLs from mouth + blink state.
- Auto-reconnect with backoff; connection status pill in corner.
- CSS: `.avatar-overlay-page-*` in `./overlay/index.css` (no cross-folder CSS).

## Dashboard behaviour

- `./dashboard/avatar.php` - mic engine, live preview, four-slot upload, appearance form, storage bar, OBS URL copy.
- `./dashboard/menu.php` - `navbar_avatar` entry.
- `./dashboard/overlays.php` - Avatar card.
- i18n: `dashboard/lang/{en,de,fr}.php` (`avatar_*` keys).
- Upload helpers: `./dashboard/includes/upload_helpers.php` (`upload_reencode_image`).

**Mic VAD:** RMS vs `mic_threshold`; open immediately when above; `release_ms` hold before emitting `idle`. State heartbeat every 1.5 s while mic running so overlays recover missed events. Re-push on socket reconnect and on `AVATAR_STATE_REQUEST` when mic is active.

## Streamer setup

1. Dashboard → Avatar → enable overlay.
2. Upload four frames (or at minimum idle-open + talk-open; blink frames optional).
3. Set position, scale, mic threshold; use live preview + **Start mic**.
4. Add `https://overlay.botofthespecter.com/avatar.php?code=API_KEY` to OBS.
5. Keep the **Avatar** dashboard tab open with mic started while streaming.

## Shipped vs deferred

| Shipped (MVP) | Deferred |
|---------------|----------|
| 4-frame mouth × eyes matrix | `loud` third mouth state in UI |
| Dedicated blink PNGs | `avatar_expressions` multi-expression table |
| Mic-driven mouth from Avatar tab only | Bot `AvatarManager` / event reactions (Phase 3) |
| Blink + CSS bounce | Hotkey expression switching (Phase 2) |
| Storage quota on upload | Phoneme / viseme lip-sync |
| WebSocket state cache + sync | Integration with Pet overlay triggers |
| Independent from Closed Captions | |

## Files

| Path | Role |
|------|------|
| `./overlay/avatar.php` | OBS overlay |
| `./overlay/index.css` | Avatar overlay styles |
| `./dashboard/avatar.php` | Config + mic producer |
| `./dashboard/includes/usr_database.php` | Schema + migrations |
| `./dashboard/includes/upload_helpers.php` | Image validation/re-encode |
| `./dashboard/includes/storage_used.php` | Quota (avatar subdir counted) |
| `./dashboard/menu.php`, `./dashboard/overlays.php` | Navigation |
| `./dashboard/lang/{en,de,fr}.php` | Strings |
| `./dashboard/css/dashboard.css` | `.av-*` dashboard styles |
| `./websocket/server.py` | `AVATAR_*` handlers + state cache |

**Unchanged for MVP:** `./api/api.py`, `./bot/beta.py`, `./bot/bot.py`.

## Risks (mitigated in ship)

| Risk | Mitigation |
|------|------------|
| OBS cannot read mic | Mic runs on Avatar dashboard tab; UI explains this |
| Missed WebSocket events | Server state cache, overlay request on connect, dashboard heartbeat |
| Overlay refresh mid-talk | Cached `AVATAR_STATE` replay on register |
| PNG transparency lost on upload | GD alpha preservation on re-encode |
| Storage exhaustion | Quota check after re-encode; storage bar on page |
| Mouth jitter | `release_ms` hold; threshold slider in UI |

## Verification

- Dashboard preview mouth opens/closes with speech.
- OBS overlay follows within ~1.5 s; **Test speech** button toggles talk for ~1 s.
- Overlay refresh while talking picks up open mouth after connect.
- Replacing a frame frees old file when unreferenced.
- Avatar + Closed Captions can both run mic without blocking each other.

## Future work (not in this spec’s implementation)

1. **Phase 2:** `avatar_expressions` table, expression CRUD, dedicated blink art per expression, `loud` state.
2. **Phase 3:** `avatar_triggers` + `AvatarManager` in `beta.py` - Twitch events/redemptions → temporary expression; port to `beta-v6.py`.
3. **Shared trigger UI** with Pet overlay when both exist.