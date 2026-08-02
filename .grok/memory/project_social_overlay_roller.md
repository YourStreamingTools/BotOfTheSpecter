---
name: project_social_overlay_roller
description: Social Overlay Roller — dashboard/socials.php config + overlay/social-roller.php rotating socials overlay; user_socials per-user table; 20-platform whitelist. SHIPPED 2026-07-10.
metadata: 
  node_type: memory
  type: project
  originSessionId: 0c90faa6-50ba-4832-b308-c1116eaddd64
---

**Social Overlay Roller** — a rotating "follow me on…" overlay that cycles the streamer's social handles. **SHIPPED 2026-07-10**, commit `0cfe7f14` "Expand social platforms and secure overlay URL".

**Files:**
- **Config page** `./dashboard/socials.php` ("Social Overlay Roller"). Uses **mysqli** (`$db`) with a `begin_transaction()` on save; empty handle ⇒ row deleted, otherwise UPSERT with active flag + display order.
- **Overlay** `./overlay/social-roller.php` — browser source. Uses **PDO**. Auth by `?code=API_KEY` ([[overlays.md]]) — resolves `username` from `website.users WHERE api_key = ?`, then reads that user's per-user DB. ("secure overlay URL" = the overlay keys off the API code, not a bare username.) Guards `SHOW TABLES LIKE 'user_socials'` before selecting (handles users who never opened the dashboard page). Reads `WHERE is_active = 1 ORDER BY display_order ASC, id ASC`.

**Per-user table `user_socials`** (`platform`, `handle`, `is_active`, `display_order`, `id`) — register in `dashboard/includes/usr_database.php` `$tables` ([[project_per_user_schema]]); overlay registration in `dashboard/overlays.php`.

**Platform whitelist (20, in `socials.php`)** — updates must stay in sync with the overlay's icon/URL map: `twitch, twitter, youtube, instagram, tiktok, discord, facebook, reddit, linkedin, snapchat, pinterest, threads, bluesky, mastodon, kick, github, spotify, steam, patreon, kofi`. This is a **separate overlay** from the [[project_unified_alerts]] system — don't fold it in.
