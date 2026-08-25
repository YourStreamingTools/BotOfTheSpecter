---
name: project_megas4_public_serving
description: "6 durable static hosts reverse_proxy to MEGA S4 public-token URL; PHP/admin I/O via rclone FUSE; TTS is local /var/www/tts file_server. GET/Range=200, HEAD=400 on S4."
metadata:
  node_type: memory
  type: project
---

# Mega S4 public serving + rclone (live on web1)

Two paths share the same bucket. They are **not** interchangeable.

## Public HTTP (OBS, browsers)

**Hot mp3/mp4 hosts** (`soundalerts`, `videoalerts`, `media`) are served by Caddy `file_server` off the rclone mounts, with a **capped VFS disk cache** on ext4 (`/var/cache/rclone-vfs`, not `/tmp`/tmpfs). Caps: soundalerts 256M, videoalerts 1G, media 768M (~2G total). First GET after a miss still pulls from S4 into that cache; repeats are local (~20ms TTFB vs ~2.5s). LRU evicts when the cap is hit — S4 stays the library.

**Still S4 reverse_proxy:** `cdn`, `walkons`, `usermusic` / `music.botspecter.com`. Those are not the alert-buffer path.

**TTS is not Mega S4.** `tts.botofthespecter.com` serves ephemeral OpenAI clips from a **plain local directory** `/var/www/tts` via Caddy `file_server`. The websocket TTS handler writes MP3s there, overlays play them, then the files are deleted. Do **not** rclone-mount `/var/www/tts`, do **not** `import asset_origin` for that host, do **not** treat `tts` as a durable S4 store for serving. The leftover `rclone-botofthespecter-tts.service` unit must stay **disabled**. `config/megas4.php` `$megas4_stores` still lists a `tts` entry for historical admin-map reasons — public serving of that host is local disk only.

**Why S4 proxy for durable assets:** the old s3fs mounts had no disk cache, buffered every served read in RAM, and OOM-crashed web1 under concurrent media load. Public GET was moved off the FUSE mount onto Caddy→S4 on 2026-07-04. rclone later replaced s3fs for **PHP/upload** I/O (see below); public HTTP was **kept** on the S4 proxy so OBS/CDN reads still do not traverse FUSE.

Caddy: `asset_origin` in `web/Caddyfile` — `reverse_proxy {env.STORAGE_HOST}:443` + `header_up Host {env.STORAGE_HOST}` + `transport http { tls }`. A scheme like `https://{env.X}` is rejected ("placeholders not allowed when upstream has a scheme"). Each host `rewrite * {env.STORAGE_PREFIX}/<store>{uri}`. `STORAGE_HOST` + `STORAGE_PREFIX` live only in `/etc/caddy/caddy.env`. Deploy copy is `/etc/caddy/Caddyfile` — see [[project_caddy_deploy_path]].

**MEGA S4 anonymous public read:** objects are anonymously readable at `https://s3.g.megas4.com/<public-token>/<bucket>/<key>`. The public token is server-side only (`caddy.env` `STORAGE_PREFIX` and `config/megas4.php`). Path-style without the token returns 403 "Invalid URL segment". Unsigned GET and Range work (200 / 206). **HEAD returns 400** — `curl -I` is misleading; test with GET. The token grants anonymous read of the whole bucket, so Caddy must keep it off the public URL. Admin/upload/management still use signed AWS SDK access (`dashboard/includes/megas4_s3.php`); signed and public-token access coexist.

Cloudflare is **DNS-only** ([[project_network_architecture]]). There is no CF HTTP cache in front of these hosts. Origin `Cache-Control: public, max-age=31536000, immutable` on asset extensions does not get an edge CDN. Overlay players also append `?t=<timestamp>` on every play, which busts browser cache — see `.grok/specs/2026-08-25-hot-media-cache-design.md`.

## PHP / admin I/O (rclone, not s3fs)

s3fs was **replaced**. Live mounts on web1 (verified 2026-08-25):

| Path | Remote | How |
|------|--------|-----|
| `/var/www/cdn` | `megas4:botofthespecter/cdn` | `fuse.rclone`, systemd `rclone-botofthespecter-cdn.service` |
| `/var/www/media` | `…/media` | same |
| `/var/www/walkons` | `…/walkons` | same |
| `/var/www/soundalerts` | `…/soundalerts` | same |
| `/var/www/usermusic` | `…/usermusic` | same |
| `/var/www/videoalerts` | `…/videoalerts` | same |
| `/var/www/tts` | local ext4 | **not mounted**; rclone TTS unit disabled |

Units: `--allow-other --uid 33 --gid 33 --dir-perms 0775 --file-perms 0664 --cache-dir /var/cache/rclone-vfs`. **Must not** use the default cache dir (`/tmp` is tmpfs on web1 — that is RAM, the s3fs OOM class of bug). Hot hosts: `--vfs-cache-mode full` plus the size caps above. `cdn` / `walkons` / `usermusic`: `--vfs-cache-mode writes` (PHP uploads only). Folders present as `www-data:www-data` `775`. The admin file manager talks to S4 with the AWS SDK, not via FUSE.

**Leftover:** `/etc/fstab` still has the old `fuse.s3fs` lines. systemd-fstab-generator still emits `var-www-*.mount` units from those, but the **active** mounts are rclone. Do not re-enable s3fs. Cleaning fstab is ops, not app code.

Hot-media `file_server` is live for soundalerts/videoalerts/media (2026-08-25) with those caps. Do not raise caps without checking `df /`. Do not point `cdn` at `file_server`. Overlay durable URLs must not append `?t=` (`SpecterOverlayWS.playbackUrl`); TTS still cache-busts. See `.grok/specs/2026-08-25-hot-media-cache-design.md`.

## Related

- [[project_s3fs_storage_and_file_manager]] (filename is historical; live I/O is rclone)
- [[project_media_upload_dir_perms]]
- [[project_caddy_deploy_path]]
- Overlay music stall: `overlay/music.php` still advances only on `ended` — no `error`/stall skip. Separate bug from origin cache.

**Last verified:** 2026-08-25 on web1 (`mount` = `fuse.rclone`, Caddy still `asset_origin` for the six durable hosts).
