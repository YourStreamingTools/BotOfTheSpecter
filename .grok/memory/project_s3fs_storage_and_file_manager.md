---
name: project_s3fs_storage_and_file_manager
description: "s3fs MEGA S4 mounts for 6 durable static dirs (NOT tts); admin CDN file manager; public serve via Caddy→S4 for durable hosts"
metadata:
  node_type: memory
  type: project
---

# s3fs storage + admin file manager

The GeoIP/CF-Worker CDN idea was **DROPPED entirely** (real goal = free server disk; all geo-CDN code was built then fully reverted, tree clean).

## Approach

Mount MEGA S4 bucket `botofthespecter` via **s3fs** at the **6 durable** static dirs (uploads/admin file manager still use the mounts even though public HTTP is Caddy→S4 reverse_proxy — see [[project_megas4_public_serving]]):

- `cdn` / `media` / `usermusic` / `walkons` / `soundalerts` / `videoalerts` → matching bucket prefixes

**TTS is NOT an s3fs mount and must never be remounted.** `/var/www/tts` is a normal local directory for ephemeral OpenAI clips (websocket publishes → Caddy `file_server` → delete after play). Unmount any existing `s3fs`/`rclone` on that path and remove fstab / `var-www-tts.mount` so it does not come back on reboot. See [[project_megas4_public_serving]].

Use `uid`/`gid`=www-data on durable mounts (upload-perms). Per-dir migration = rclone copy → verify → mv aside → mount → verify → reclaim, systemd `.mount` units.

Plus an admin full file manager (`dashboard/admin/cdn_files.php` + `includes/megas4_s3.php`, `Aws\S3\S3Client` modeled on `persistent_storage.php`, creds in `config/megas4.php`, list/upload/rename/delete/mkdir, store switcher cdn-default). **TTS is not a store in `$megas4_stores`.**

## Durable facts

- `config/object_storage.php` = **SEPARATE** paid "Persistent Storage" recordings S3 (buckets `botofthespecter-au` / `us-persistent`; `upload_to_s3.php`=SSH+python boto3; browsed in `persistent_storage.php`) — don't conflate
- AWS SDK for PHP IS vendored at `/var/www/vendor/aws-autoloader.php`
- Static hosts served by Caddy from `/var/www/<dir>`
- Admin pages gate via `dashboard/admin/admin_access.php` (defines `admin_audit_log`) + super_admin DB recheck; canonical pattern = `dashboard/admin/caddy.php`
- Existing S3 mount precedent = `/mnt/s3/bots-stream`

## Status

- **Part B (file manager) BUILT & SHIPPED** (committed `0241cc3b` 2026-06-30): `config/megas4.php`, `includes/megas4_s3.php`, `dashboard/admin/cdn_files.php` (+menu.php/lang×3/dashboard.css); store validated vs `$megas4_stores` every action, super_admin server-side per write + `admin_audit_log`, keys prefix-confined.
- **Part A (s3fs mounts) DEPLOYED & LIVE** on web1 (2026-06-30): durable static dirs are s3fs mounts of bucket `botofthespecter` prefixes, fstab-persisted. **2026-08-07:** TTS must be **local disk only** — unmount `/var/www/tts` if still s3fs, strip from fstab/systemd, Caddy serves with `file_server` (not `asset_origin`).

## Endpoint / creds

- Endpoint `https://s3.g.megas4.com` (GLOBAL, region `"g"`; dashboard shows bucket virtual-hosted as `botofthespecter.s3.g.megas4.com`; tooling uses path-style)
- Creds `/etc/passwd-s3fs`
- File manager (SDK) needs real creds in `/var/www/config/megas4.php`

## Critical gotchas

1. **`compat_dir` is required.** An rclone-populated bucket has implicit dirs (no marker objects), so s3fs sub-path mounts `bucket:/prefix` FAIL with "directory not found / specified key does not exist" unless `-o compat_dir` is set.
2. **`mount` returns exit 0 even when s3fs dies in the background** (false success). Verify with `mountpoint`/`ls`, never the exit code alone.
3. **fstab opts:** `_netdev,allow_other,use_path_request_style,compat_dir,uid=33(www-data),gid=33,umask=0022,stat_cache_expire=60` — **NO `use_cache`** (shared cache across same-bucket mounts conflicts).
4. **XSS in esc():** the textContent→innerHTML `esc()` idiom does NOT escape quotes, so values dropped into double-quoted HTML attributes allow attribute-breakout XSS — `cdn_files.php` `esc()` must explicitly escape `&<>\"'`.

## Migration recipe

`rclone copy /var/www/<d> → megas4:botofthespecter/<d>` + `rclone check`, then mv aside → mount → verify → rm `.bak`.

Related: [[project_media_upload_dir_perms]], [[project_megas4_public_serving]], [[project_caddy_deploy_path]].
