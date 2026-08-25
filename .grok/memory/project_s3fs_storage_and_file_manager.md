---
name: project_s3fs_storage_and_file_manager
description: "rclone MEGA S4 mounts for 6 durable static dirs (NOT tts); admin CDN file manager; public HTTP is Caddy→S4, not the mount"
metadata:
  node_type: memory
  type: project
---

# Object storage mounts + admin file manager

Filename is historical. **s3fs was replaced by rclone** on web1. Public HTTP for the durable hosts is still Caddy → MEGA S4 (not FUSE) — see [[project_megas4_public_serving]].

The GeoIP/CF-Worker CDN idea was **dropped** (goal was freeing disk; that code was built then fully reverted).

## What lives where

**rclone FUSE (PHP uploads, listing, migrate scripts)** — bucket `botofthespecter`, remote `megas4:` in `/root/.config/rclone/rclone.conf`:

- `cdn` / `media` / `usermusic` / `walkons` / `soundalerts` / `videoalerts` → matching prefixes under `/var/www/<dir>`
- systemd: `rclone-botofthespecter-<dir>.service`, enabled. Shared flags: `--allow-other --uid 33 --gid 33 --dir-perms 0775 --file-perms 0664 --cache-dir /var/cache/rclone-vfs`. Hot hosts (soundalerts 256M / videoalerts 1G / media 768M): `--vfs-cache-mode full` + those max-size caps. Other mounts: `--vfs-cache-mode writes`. Never let the VFS cache sit on `/tmp` (tmpfs). Do not drop `--uid/--gid` or folders show up as `root:root` `755` again.
- **TTS is not a mount** and must never be remounted. `/var/www/tts` is a normal local directory. Leave `rclone-botofthespecter-tts.service` disabled; do not uncomment the s3fs tts fstab line.

**Admin file manager** (`dashboard/admin/cdn_files.php` + `includes/megas4_s3.php`): `Aws\S3\S3Client`, creds in `config/megas4.php`, list/upload/rename/delete/mkdir, store switcher. Signed SDK access, prefix-confined. Super-admin + `admin_audit_log`. Do not point this manager at TTS as if it were durable S4.

**Public GET:** Caddy `reverse_proxy` to the S4 public-token URL. OBS never reads the rclone mount.

## Other S3 (do not conflate)

`config/object_storage.php` is the **paid Persistent Storage** recordings bucket (`botofthespecter-au` / `us-persistent`), browsed in `persistent_storage.php`. Not Mega S4 static assets.

AWS SDK for PHP is vendored at `/var/www/vendor/aws-autoloader.php`.

## Status (web1, 2026-08-25)

- File manager: shipped (`0241cc3b` 2026-06-30).
- s3fs mounts: deployed 2026-06-30, **replaced by rclone** (units live; processes `rclone mount megas4:botofthespecter/…`).
- TTS: local disk only as of 2026-08-07.
- fstab still lists `fuse.s3fs` for the six durable dirs (and a commented tts line). Those generator units are stale; rclone services own the mounts. Clean fstab when convenient so a reboot cannot try s3fs.

## Endpoint

- `https://s3.g.megas4.com` (GLOBAL, region `"g"`)
- rclone remote name: `megas4`
- SDK creds: `/var/www/config/megas4.php` (never commit real values; never log them)
- Historical s3fs passwd file `/etc/passwd-s3fs` is unused while rclone is live

## Gotchas that still matter

1. **rclone `--vfs-cache-mode writes` does not cache public reads.** Only upload write-back. Switching a host to Caddy `file_server` on the mount without `full` + a size cap would put HTTP back on uncached FUSE — the s3fs OOM class of failure.
2. **`mount`/`systemctl start` success is not proof the FUSE fs is healthy.** Verify with `findmnt` / `ls` on the path.
3. **Uploads are www-data through `allow_other`.** "Could not save" on makers/media/alerts is still first a perms/FUSE question — [[project_media_upload_dir_perms]].
4. **`esc()` XSS** in `cdn_files.php`: textContent→innerHTML does not escape quotes; attribute values need `&<>"'` escaped.
5. An rclone-populated bucket has implicit dirs (no marker objects). That used to break s3fs sub-path mounts without `compat_dir`. rclone does not need `compat_dir`.

Related: [[project_megas4_public_serving]], [[project_media_upload_dir_perms]], [[project_caddy_deploy_path]], `.grok/specs/2026-08-25-hot-media-cache-design.md`.
