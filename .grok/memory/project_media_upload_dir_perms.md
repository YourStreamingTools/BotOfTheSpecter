---
name: project_media_upload_dir_perms
description: "media uploads need /var/www/media/<user> group-writable for www-data; root-owned 2755 dirs silently break uploads with \"Could not save\""
metadata: 
  node_type: memory
  type: project
  originSessionId: 0acad4cb-9aa8-42af-95a0-2343768ae61c
---

All dashboard upload pages (makers.php, media.php, alerts.php) write to `/var/www/media/<username>/` (server) via the same `includes/upload_helpers.php` + `move_uploaded_file()`. PHP runs as **www-data**, so that dir must be **group-writable** — `2775` with group `www-data`, OR owned by `www-data`. `migrate_media.php` creates them correctly (www-data-owned 0755 → owner-writable).

**Gotcha:** if a bulk/ops action pre-creates the dirs as `root:www-data` mode `2755` (`drwxr-sr-x` — group has no write), uploads fail with the generic **"Could not save"** (makers.php:219, the `move_uploaded_file` false branch) and the file never reaches disk. `includes/storage_used.php` `ensureDirectoryWritable()` CANNOT rescue this: the dir exists so it only tries `@chmod()`, which fails because www-data doesn't own a root-owned dir.

**Diagnosis shortcut:** any "Could not save" on an upload page → check `ls -la /var/www/media/<user>` perms first, NOT the page code (the upload code is identical across pages and was verified correct).

**Fix (needs root):** `find /var/www/media -type d -exec chmod 2775 {} \;` (also fixes the base dir, which must be group-writable so PHP can mkdir new users' folders). Prevent recurrence by making any bulk dir-provisioning use 2775 / www-data ownership.

Related: [[project_unified_alerts]] (alerts.php uploads here too), [[project_media_player_song_request_hub]].
