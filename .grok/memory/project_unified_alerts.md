---
name: unified-alerts-system
description: "How the new Specter Alerts system fits together — configurator, unified overlay, the folded-in legacy categories, and walk-on modes"
metadata: 
  node_type: memory
  type: project
  originSessionId: 9d39b6f6-0f3d-4ebd-ac45-290b0dcb0706
---

The "new alert system" = `dashboard/alerts.php` (configurator) writing per-user `twitch_alerts` rows + `overlay/index.php` (the unified OBS browser-source overlay) reading them over the WebSocket and rendering. It supersedes the older standalone overlays (`overlay/all.php` and the individual `weather.php`/`deaths.php`/`walkons.php`), which still exist for back-compat but must NOT be loaded alongside `index.php` — both would fire and you'd get **double alerts**.

**Categories:** event categories get the full styling configurator. **Ads, Discord joins, Ko-fi, Patreon, Fourthwall, watch streaks, and goals** (`fixedMessageCategories`) get that same configurator except the message template — the wording is fixed (`resolveAlertTemplate` in `overlay/index.php` fills a blank template). **weather, deaths, walk-ons** stay enable/disable-only (`simpleCategories`) because they keep their own overlay theme. **sound alerts, video alerts, and TTS** are the same kind of switch, with no screen position — the file still comes from the Media page. Sound, video, and TTS keep playing if that row does not exist yet; once the row exists, the switch is the only authority. Goals are seeded **off** and only show goal started / goal completed (progress is ignored). Watch streaks fire from beta and v6 as `WATCH_STREAK` and honour `streak >= N`. Subathon "time added" is `SUBATHON_ADD_TIME` on the alert card; the countdown clock stays on `overlay/subathon.php`. **credits was deliberately left out** — it's a separate full-page scrolling scene, not an event alert. Video playback uses the existing `.video-alert-overlay-page-video` styles. Do not load `overlay/video-alert.php` beside `index.php` or the clip plays twice.

**Bingo copy:** a placeholder repair once wrote the first variant's text onto every variant in the category. Called, winner and ended cards that still say "Stream Bingo is starting!" are put back to their own lines when the alerts page or the overlay loads.

**Queue:** one-shot alerts (cards, sounds, TTS, videos, walk-ons, deaths, weather) play one at a time. A sub and a resub for the same person within 3 seconds collapse to the higher month count, so a resub does not also play the new-sub card. Ad breaks stay on their own layer for the length of the break.

**Screen position:** `twitch_alerts.screen_position` (`NULL` = category default → weather=left-top, deaths=left-bottom, else center-center). A 3×3 picker in the configurator; applied inline to `#alertContainer`/`#weatherOverlay`/`#deathOverlay`/`#walkonOverlay` at render time via `applyScreenPosition()`.

**Walk-on modes:** per-viewer, chosen on the **Media page** (`dashboard/media.php`) when tagging a file to a Twitch user → `walkons.mode` column (`sound` / `sound_overlay` / `video`; video auto for mp4). The bot (`beta.py` + `beta-v6.py`) reads `mode` in the WALKON enrichment inside `websocket_notice`, and for `sound_overlay` adds `display_name` + `avatar_url` via the `get_user_display_and_avatar()` helper. `overlay/index.php` `handleWalkon()` branches on `data.mode`.

**Ads:** category `ad_break` (one variant, seeded **enabled**). The card stays up for the Twitch ad-break length with a live `{duration}` / `{seconds}` countdown, in its own `#adBreakOverlay` so it does not block the alert queue. Layout, image, sound, and celebration apply the same way as a normal alert; celebration runs for the time left in the break. Beta and v6 emit `TWITCH_AD_BREAK` / `TWITCH_AD_BREAK_END` from the ad-break handler; the chat-notice toggle does not gate the overlay. The browser source must be refreshed after the variant is switched on.

**Gotcha that bit once:** the `save_alert` `bind_param` type string in alerts.php must stay aligned with the column order — it had a decimal-as-`i` truncation bug that was fixed when `screen_position` was added. See [[per-user-schema]] (schema lives in `dashboard/usr_database.php` `$tables`, auto-migrates columns) and [[dashboard-page-menu-registration]].
