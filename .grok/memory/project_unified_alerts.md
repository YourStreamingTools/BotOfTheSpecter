---
name: unified-alerts-system
description: "How the new Specter Alerts system fits together — configurator, unified overlay, the folded-in legacy categories, and walk-on modes"
metadata: 
  node_type: memory
  type: project
  originSessionId: 9d39b6f6-0f3d-4ebd-ac45-290b0dcb0706
---

The "new alert system" = `dashboard/alerts.php` (configurator) writing per-user `twitch_alerts` rows + `overlay/index.php` (the unified OBS browser-source overlay) reading them over the WebSocket and rendering. It supersedes the older standalone overlays (`overlay/all.php` and the individual `weather.php`/`deaths.php`/`walkons.php`), which still exist for back-compat but must NOT be loaded alongside `index.php` — both would fire and you'd get **double alerts**.

**Categories:** the 15 event categories (follow … watch_streak) get the full styling configurator. **weather, deaths, walk-ons** were folded into `index.php` in their existing theme (their CSS already lives in `overlay/index.css`) and appear in the configurator as **enable/disable-only "simple" categories** (`simpleCategories` in alerts.php JS). **credits was deliberately left out** — it's a separate full-page scrolling scene, not an event alert.

**Screen position:** `twitch_alerts.screen_position` (`NULL` = category default → weather=left-top, deaths=left-bottom, else center-center). A 3×3 picker in the configurator; applied inline to `#alertContainer`/`#weatherOverlay`/`#deathOverlay`/`#walkonOverlay` at render time via `applyScreenPosition()`.

**Walk-on modes:** per-viewer, chosen on the **Media page** (`dashboard/media.php`) when tagging a file to a Twitch user → `walkons.mode` column (`sound` / `sound_overlay` / `video`; video auto for mp4). The bot (`beta.py` + `beta-v6.py`) reads `mode` in the WALKON enrichment inside `websocket_notice`, and for `sound_overlay` adds `display_name` + `avatar_url` via the `get_user_display_and_avatar()` helper. `overlay/index.php` `handleWalkon()` branches on `data.mode`.

**Gotcha that bit once:** the `save_alert` `bind_param` type string in alerts.php must stay aligned with the column order — it had a decimal-as-`i` truncation bug that was fixed when `screen_position` was added. See [[per-user-schema]] (schema lives in `dashboard/usr_database.php` `$tables`, auto-migrates columns) and [[dashboard-page-menu-registration]].
