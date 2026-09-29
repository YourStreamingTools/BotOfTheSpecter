---
name: project_megas4_public_serving
description: "Durable media hosts are served by the storage server (syd1) from cached read-only Mega S4 mounts via web1 Caddy over the VPC, S4 public-token fallback; web1 mounts are write-only for PHP uploads; TTS is local on web1"
metadata:
  node_type: memory
  type: project
---

# Durable media serving (storage server + Mega S4)

Since 2026-09-29, **syd1.stream is the storage server**. RTMPS ingest, Twitch VOD pulls, and the YouTube/S3 uploaders are add-ons on the same host.

## Public HTTP (OBS, browsers)

`cdn.`, `media.`, `soundalerts.`, `videoalerts.`, `walkons.`, `music.botspecter.com` / `music.botofthespecter.com` on web1 all `import storage_origin <store>` in `web/Caddyfile`:

- `rewrite * /media/<store>{uri}` → `reverse_proxy 10.240.0.9:8092` (syd1 private IP, `specter-storage.service` = `stream/storage_http.py`).
- 404 / 503 from syd1 → falls back to the S4 public-token URL (`storage_fallback` snippet: `{env.STORAGE_PREFIX}/<store>{orig_uri}`). Unreachable syd1 (502/503/504) → same fallback via `handle_errors`.
- **HEAD goes to syd1 only** (`@head method HEAD`). S4's public URL returns **400 to HEAD**, which would turn a missing file's 404 into 400. The bots probe walk-ons with HEAD (`http_public_file_exists`: 200 = yes, 404 = no, anything else → ranged GET).
- `header` runs before `rewrite` in Caddy's order, so path matchers like `header /css/fontawesome* …` still see the original path (cdn font CORS works). The fallback strips S4's reflected CORS headers.

`vods.` uses the same syd1 service (legacy `/{user}/{file}` and `/vods/{user}/{file}` both work) with its own S4 `vods/` fallback. `tts.` is **local disk on web1** (`/var/www/tts`, websocket writes then deletes clips): never mount it, never proxy it to S4.

## syd1 storage side

- `specter-media-mount@<store>.service` (template, `stream/specter-media-mount@.service`) → `rclone mount megas4:botofthespecter/<store> /mnt/specter-media/<store> --read-only --vfs-cache-mode full --vfs-cache-max-size 50G --vfs-cache-max-age 8760h --use-server-modtime --dir-cache-time 30s`, cache in `/var/cache/specter-media-vfs` on the 1 TB disk.
- **Mount root must be under `/mnt`**: Ubuntu's AppArmor `fusermount3` profile only allows `@{HOME}`, `/mnt`, `/media`, `/tmp`, `/run/user`; `/srv/...` fails with "fusermount: mount failed: Permission denied" (`failed mntpnt match` in dmesg). web1 doesn't load that profile.
- New/changed uploads show up on syd1 within ~30s (dir cache); meanwhile GETs fall back to S4. First read of a file ~0.6–2.5s from S4, then ~2–40ms from syd1 disk.
- rclone config `/root/.config/rclone/rclone.conf` (remote `megas4`) was copied from web1; rclone is the Ubuntu package (v1.60).

## web1 PHP / upload side

`rclone-botofthespecter-<store>.service` mounts at `/var/www/<store>` are **write-only** (`--vfs-cache-mode writes --use-server-modtime`). No media read cache on web1's 20 GB SSD. Dashboard uploads write to S4 through them; syd1 serves the result. Backups of the pre-change units: `/root/rclone-units-bak-20260929/`.

**`--use-server-modtime` is required on every S3/S4 rclone mount.** Without it rclone does one HEAD per object to read mtime metadata on any stat, ~0.3s each to S4. `cdn/music` (960 files) took ~5 min and hung `music.php?ajax_action=list` with PHP-FPM workers stuck in D state. With it: 2.6s.

## Mega S4 facts

Objects are anonymously readable at `https://s3.g.megas4.com/<public-token>/<bucket>/<key>`. The token is server-side only (`/etc/caddy/caddy.env` `STORAGE_PREFIX`, `config/megas4.php`). Unsigned GET/Range work; **HEAD returns 400** (test with GET, not `curl -I`). Admin file manager uses the signed AWS SDK. Bucket ~3.75 GB total (cdn 3.4 GB / 28k files; everything else ~0.35 GB) as of 2026-09-29.

Cloudflare is DNS-only ([[project_network_architecture]]); no edge cache. Caddy deploy path: [[project_caddy_deploy_path]]. Walk-on "never plays" reports on 2026-09-29 were mostly viewers with no file in that channel's folder (the bot logs "no walk-on file"); found walk-ons were delivered in full to OBS.

**Leftover:** `/etc/fstab` on web1 still has old `fuse.s3fs` lines, and systemd-fstab-generator emits stale `var-www-*.mount` units. rclone services own the mounts; clean fstab when convenient.
