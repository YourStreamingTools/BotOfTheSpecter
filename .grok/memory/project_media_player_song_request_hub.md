---
name: project_media_player_song_request_hub
description: dashboard/media_player.php is the unified song-request hub (YouTube + Spotify analytics) + Spotify player control via dashboard/api/spotify_player.php; plus overlay/spotify.php multi-theme now-playing OBS overlay (polls overlay/spotify_nowplaying.php, deliberately not WebSocket)
metadata: 
  node_type: memory
  type: project
  originSessionId: d4535b42-54e1-402f-9d82-0ce83a3c4802
---

`dashboard/media_player.php` is the unified **song-request hub**. It contains: media request settings, the live **YouTube** queue (WebSocket `MEDIA_QUEUE_UPDATE`), the ban list, and two analytics cards:

- **Spotify Song Requests** — reads the `song_request_analytics` per-user table (bot `INSERT`s on each `!songrequest`). **Moved here from `spotifylink.php` on 2026-06-01** (the OAuth-link page now only handles linking). Always shown (table-exists guard, with empty-state message).
- **YouTube Song Requests** — reads the `media_queue` per-user table (which retains `status='played'` rows). Shown **only when there is data** (`$ytTableExists && $ytTotal > 0`).

The two subsystems are asymmetric: Spotify requests are logged historically to `song_request_analytics`; YouTube requests live in `media_queue` (live queue + retained played rows). Analytics labels reuse the generic `spotifylink_*` lang keys plus new `media_player_sr_*` / `media_player_th_video|uploader` keys (en/de/fr).

**Spotify player control (implemented 2026-06-01, MVP):** when Spotify is linked & authorized, a "Spotify Player" card shows now-playing (album art, track/artist, a smooth progress bar that ticks locally + re-syncs each 5s poll) plus controls (play/pause/next/previous/volume). It is **PHP-direct, NOT via the FastAPI server**: `dashboard/api/spotify_player.php` is a session-auth proxy that reads the system-refreshed `spotify_tokens.access_token` (it never refreshes — a Spotify 401 is surfaced as a soft `{success:false,error:'expired'}` with HTTP 200, never a real 401, so `dashboard.js` doesn't bounce to /login) and calls the Spotify Web API (`/v1/me/player*`). Premium-only (free accounts 403); needs an active device (204/404). The shared `modern-volume` slider style + `.sp-progress*` bar live in `dashboard.css`; labels use `media_player_spotify_*` lang keys (en/de/fr). Phase-2 (not built): shuffle/repeat, seek, device picker, add-to-queue/search, optional `expires_at` proactive refresh. See per-user table management in [[project_per_user_schema]].

**Spotify now-playing OBS overlay (added 2026-06-01):** `overlay/spotify.php` is a multi-theme now-playing overlay — `?code=` auth, `?theme=terminal|pill|card|macwindow` (default `terminal`); all four themes share one poll loop + `render()` into `data-sp` hooks + a local progress tick. It gets data by **polling `overlay/spotify_nowplaying.php`** — a code-authed, same-origin JSON endpoint that reads the system-refreshed `spotify_tokens.access_token` and proxies Spotify `currently-playing` (token never reaches the browser; non-200 → `{active:false}` → overlay fades out). This **deliberately polls instead of using the WebSocket** — a conscious deviation from the overlays-use-WebSocket rule (see [[overlays]]), chosen to keep it self-contained (no bot/WS dependency). **Don't "fix" it to WebSocket.** Styles live in the `.spotify-overlay-page-*` section of `overlay/index.css`; registered as a card in `dashboard/overlays.php`; `overlays_spotify*` lang keys in en/de/fr.
