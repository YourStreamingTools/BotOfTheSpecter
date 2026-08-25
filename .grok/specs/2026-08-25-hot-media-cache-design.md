# Hot media cache — sound alerts, video alerts, and other S4 playback

**Date:** 2026-08-25  
**Scope:** how OBS overlays fetch durable media from Mega S4 today, why alerts “buffer” (video worse than audio), and how we should cache hot files without putting HTTP back on an uncached FUSE mount.  
**Status:** Layer 1 overlay + Layer 2 origin cache for soundalerts/videoalerts/media shipped on web1 2026-08-25. Caps: 256M / 1G / 768M on `/var/cache/rclone-vfs` (ext4, not tmpfs). `cdn` / walkons / usermusic still S4 proxy.

Related live notes: `.grok/memory/project_megas4_public_serving.md`, `.grok/memory/project_s3fs_storage_and_file_manager.md`, `.grok/memory/project_network_architecture.md`.

## What is actually happening

Durable files live in Mega S4. Two different machines talk to that bucket:

1. **PHP on web1** writes and lists through **rclone FUSE** mounts (`/var/www/media`, `/var/www/soundalerts`, `/var/www/videoalerts`, …) with `--vfs-cache-mode writes`. That cache is for uploads. It does **not** help OBS.
2. **OBS / browsers** hit `soundalerts.botofthespecter.com`, `videoalerts.botofthespecter.com`, `media.botofthespecter.com`, `walkons.botofthespecter.com`. Caddy rewrites the path, then `reverse_proxy` to the S4 public-token origin. Every GET is web1 → Mega S4 unless something else caches it.

Cloudflare is DNS-only, so there is no edge CDN. Caddy already sends `Cache-Control: public, max-age=31536000, immutable` on mp3/mp4, but that only helps a cache that actually sees a stable URL.

The bot already resolves a full URL per event (unified library → `media.botofthespecter.com/{channel}/{file}`, otherwise the legacy `soundalerts` / `videoalerts` hosts). The overlay does not fetch a mapping at play time; it just plays that URL.

## Why it feels late — three stacked delays

### 1. Every play cache-busts

These overlays append a unique query string on **every** play:

- `overlay/video-alert.php` — `video.src = url + '?t=' + Date.now()`
- `overlay/sound-alert.php`, `alert.php`, `all.php`, `walkons.php`
- Specter Alerts `overlay/index.php` `enqueueFxAudio` — same `?t=` on SOUND_ALERT and TTS
- `overlay/tts.php` — same pattern

For **TTS** that is reasonable: files are ephemeral and names get reused. For **durable** mp3/mp4 it is the opposite of what we want. The browser treats each redeem as a brand-new object. The origin `immutable` header never gets a chance. A disk cache keyed on the full URI would also miss every time.

### 2. Origin has no read cache on the HTTP path

OBS never touches rclone. First byte of a sound or video is a live GET to Mega S4 from web1, then on to the streamer. Range requests for mp4 make that worse: the browser will ask for several chunks before it believes it can play.

### 3. Video is painted before it can play

`video-alert.php` creates a `<video>`, sets `src`, **appends it to `document.body` immediately**, then waits for `canplaythrough` before `play()`.

`canplaythrough` means “I think I can finish without stalling.” On a remote S4 object that is a long wait. Meanwhile the element is already on the OBS canvas — first frame, poster, or a black rectangle — which matches “the video shows on the streamer screen but doesn’t play until it’s buffered.”

Audio overlays wait on `canplaythrough` too, but they are not visible, so the same wait only feels like a late sound.

`overlay/index.php` FX audio is slightly different: it calls `play()` without waiting for `canplaythrough`, still with `?t=`.

## What we are not doing

- **Not orange-clouding these hosts on Cloudflare.** DNS-only is settled. Do not use CF HTTP cache as the fix.
- **Not pointing Caddy `file_server` at the rclone mounts as they are today.** `--vfs-cache-mode writes` does not cache reads. That is the s3fs OOM pattern again if concurrent OBS GETs go through uncached FUSE.
- **Not caching TTS** on disk or in the browser for long. Short `max-age=120` on `tts.botofthespecter.com` stays.
- **Not changing Mega S4 layout or the public-token proxy** for cdn/fonts. The hot problem is alert/walkon/media playback, not Font Awesome.

## Goal

When a channel-point sound or video fires, OBS should start audible/visible playback from local data (browser cache and/or web1 disk), not from a cold S4 GET. First play after an OBS restart can still touch S4 once; **repeat** plays of the same file in the same stream must not.

A replaced file (same name, new bytes) should still be pickable without waiting for a year of `immutable` — overlay refresh / a version query we control, not `Date.now()` on every play.

## Design — two layers

Layer 1 is overlay behaviour. It is independent, safe, and likely most of the “video is stuck on screen” feeling. Layer 2 is origin read-cache for the first play and for overlay processes that have a cold CEF cache.

### Layer 1 — stop fighting our own cache headers

**Durable URLs stay stable.** Drop `?t=Date.now()` for hosts we treat as immutable: `soundalerts.`, `videoalerts.`, `media.`, `walkons.`, `cdn.`. Keep `?t=` (or a short cache) only for `tts.botofthespecter.com`.

If a streamer overwrites a file in place, `OVERLAY_REFRESH` already reloads the browser source. Optionally a weak cache-buster that changes **only when the dashboard save happens** (mtime or an object etag from the upload path) is fine. A new random value on every redeem is not.

**Do not put the video on the canvas until it can play.** Create the element, set `src`, `preload='auto'`, keep it off-document or `opacity:0` / `visibility:hidden` until `canplaythrough` (or `playing`), then show and `play()`. If load errors, skip to the next queued URL instead of leaving a frozen frame.

**Warm the OBS browser at overlay boot.** Specter Alerts already has `twitch_alerts` rows in PHP when `index.php` renders. Sound/video/walkon overlays can load that channel’s mapped file URLs the same way. Emit `link rel=preload` (or a small JS `fetch`/off-DOM `audio`/`video` preload) for those URLs after connect. OBS sources stay open for hours; paying S4 once at “start stream” is acceptable. The set is per-channel and small.

Walk-ons and user music have the same `?t=` and S4 path. Include them in the durable-URL rule even if v1 preload only covers the alerts overlay.

### Layer 2 — origin disk cache for hot hosts only

rclone’s FUSE cache does **not** see public GETs while Caddy still `reverse_proxy`s to S4. To cache origin reads we must either serve those hosts from a local tree that *is* cached, or put an HTTP cache on the proxy.

**Preferred: rclone read cache + `file_server` on the hot hosts only.**

Candidates: `soundalerts`, `videoalerts`, then `walkons` / `media` if soak looks good. Leave `cdn` on the S4 proxy (large, many tiny files, not the alert delay).

For those rclone units, change `--vfs-cache-mode writes` to `--vfs-cache-mode full` with a hard cap, for example `--vfs-cache-max-size` in the 4–8G range (web1 has limited free disk) and a multi-day `--vfs-cache-max-age`. Then point those Caddy blocks at `root * /var/www/<dir>` + `file_server` instead of `asset_origin`.

Effects:

- First GET after a cache miss still pulls from S4, but through rclone’s **disk** cache, not an unbounded RAM buffer.
- Repeat GETs, including Range, should come off local disk.
- PHP uploads and HTTP reads share the same files. No second copy of the bucket.
- Query strings must not be required for correctness; Layer 1 already removes `?t=`. `file_server` ignores unknown query strings.

Soak **videoalerts** first (worst symptom, fewer files than `media`). Watch RSS, disk, and a timed `curl -w` of TTFB vs today’s S4 proxy.

**Fallback if FUSE-in-HTTP still looks dangerous:** keep `reverse_proxy` to S4 and add a small HTTP cache that keys on path **without** query string (and handles Range). Standard Caddy has no built-in cache; that means a plugin or a sidecar. Only go there if `full` + `file_server` fails a soak. Do not invent a second object store.

### Out of scope for this pass

- Rewriting the music overlay stall-on-error behaviour (still real; `ended`-only). Can ship with the overlay error skip in Layer 1 if we touch `music.php` anyway.
- Preloading every file in `/media/{user}/` (too large). Preload **mapped** alert/walkon files only.
- Changing how the bot builds URLs.

## How we will know it worked

- Same video redeem twice in one OBS session: second play starts without a visible freeze frame and without a new S4 GET in Caddy’s videoalerts log (Layer 1 at least hits CEF cache; Layer 2 hits disk).
- First redeem after overlay load: if preload ran, should already be cached; if not, one S4/rclone fill then play, element not visible until `canplaythrough`.
- Overwrite a mapped mp4 on the dashboard, hit overlay refresh, new bytes play (no year-long stale `immutable` surprise without refresh).
- web1 memory stays flat during a burst of alerts (the original s3fs failure mode).

## Suggested order of work

1. Overlay durable URLs + hide video until ready + error skip. No storage change. Easy to revert.
2. Preload mapped files on the alerts / sound / video overlay pages.
3. rclone `full` + Caddy `file_server` on `videoalerts`, measure, then `soundalerts`.
4. Only then consider walkons/media, or an HTTP-cache fallback.

Do not flip all six durable hosts in one change.
