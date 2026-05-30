# Makers & Crafting Overlay — Plan

> Status: **draft**, awaiting review (see §12 open decisions).
> Owner: TBD. Last revised 2026-05-30.

## 1. Scope

A new browser-source overlay for makers / crafting content that shows the streamer's **current project and its context**, updatable live from chat without leaving the workflow. It supports three display modes and either an image carousel or text-only context per project.

**Confirmed product decisions** (from brainstorming, 2026-05-30):

1. **Images come from the unified media library.** Streamers upload project photos through the dashboard; files live in the same storage/CDN as alert media (`/var/www/media/{user}/` → `https://media.botofthespecter.com/{user}/{file}`). No raw files or URLs are typed in chat. **Note:** the media library is audio/video-only today — images are a new content type this plan adds to the same storage (see §3.3, §7).
2. **All three display modes ship in v1**: single current project, cycle finished projects, highlight upcoming ideas.
3. **Always-visible card.** The overlay stays on screen the whole stream and updates in place, so late-joining viewers always see what's being made. A streamer-controlled show/hide toggle exists but defaults to visible.
4. **Dashboard + chat hybrid management.** Project library, image upload/attach, and styling live in the dashboard; chat commands drive fast in-stream actions (new project, set note, switch mode, mark finished, show/hide).

**Affected systems**

- `./bot/beta.py` — **primary implementation target.** New `!craft` chat command family + a `MAKER_UPDATE` websocket signal land here first, per [bot-versions.md](../rules/bot-versions.md).
- `./bot/beta-v6.py` — port from beta once stable (TwitchIO 3.2.2 API differs — re-verify command registration).
- `./bot/bot.py` (stable) — **not changed** (no critical fix involved).
- `./websocket/server.py` — **no change strictly required**; the generic `else` branch in `notify_http` already broadcasts unknown events to the channel's clients + global listeners. Optionally add explicit `MAKER_UPDATE` handling for logging parity (see §9).
- `./api/api.py` — **no changes.** Overlay self-serves via PHP; bot and dashboard write per-user MySQL directly, per [data-flow.md](../rules/data-flow.md). See §10.
- `./overlay/maker.php` — **new file.** Always-visible card; PHP renders initial state + exposes a `?type=json` endpoint; JS re-fetches on `MAKER_UPDATE`.
- `./overlay/index.css` — new `.maker-overlay-page-*` classes (overlay CSS lives in its own folder — no cross-folder linking, per the ui-theme system).
- `./dashboard/makers.php` — **new file.** Project CRUD, image upload/attach, styling controls.
- `./dashboard/overlays.php` — add a Makers card (OBS URL + quick settings link).
- `./dashboard/usr_database.php` — three new per-user tables (auto-deploy via the existing `CREATE TABLE IF NOT EXISTS` loop).
- Per-user MySQL DB — `maker_projects`, `maker_project_images`, `maker_overlay_settings`.

**Explicitly out of scope** (separable, future work):

- Adding the Makers card into `all.php` (the master overlay is built around transient alerts, not a persistent card — see §12.6).
- Per-project analytics (time spent, view counts).
- Pulling images from external sources (Etsy, Instagram). Dashboard upload only for v1.
- Viewer-submitted project ideas. The overlay is streamer-owned; chat commands are broadcaster/mod-only.

## 2. Current state inventory

What exists today that this plan builds on, so we extend rather than reinvent.

### Overlay patterns (two, both reused)

| Pattern | Example | Mechanism |
|---|---|---|
| **DB-persisted + JSON re-fetch** | `./overlay/counters.php` | PHP reads per-user DB on load, renders, exposes `?type=json`; JS keeps the value fresh. Survives OBS browser-source reload. |
| **WebSocket push** | `./overlay/deaths.php`, `all.php` | Socket.io 4.8.3 client registers with `?code=API_KEY`, listens for named events, re-renders. Auto-reconnect with backoff. |

**This overlay combines both** (the right fit for an always-visible card that only changes on command): PHP persists + renders the current state on load; a lightweight `MAKER_UPDATE` websocket signal tells the overlay to re-fetch its JSON and re-render. No constant polling — satisfies [overlays.md](../rules/overlays.md) rule 2 ("all real-time data comes from the WebSocket; don't poll the API for live updates").

### Chat command → overlay path (already wired)

1. Bot command handler in `beta.py` writes per-user MySQL directly (`mysql_connection()` → channel's own DB).
2. Bot calls `websocket_notice(event="…", additional_data={…})` (`beta.py` ~line 12754).
3. `websocket_notice` URL-encodes params → HTTP GET `https://websocket.botofthespecter.com/notify?...` (~line 12945).
4. WebSocket server `notify_http` validates the key, then broadcasts. **Unknown events fall through to the generic `else` branch** (`server.py` ~line 812) which emits to every client registered under that `code` plus global listeners. So a brand-new event needs **no server-side change**.
5. Overlay (Socket.io client) reacts.

**One bot-side gotcha:** `websocket_notice`'s final `else` (`beta.py` ~line 12940) *rejects* unrecognised events ("requires additional parameters or is not recognized"). So the bot needs one new `elif event == "MAKER_UPDATE"` branch that passes `additional_data` through. Several existing events already do exactly `params.update(additional_data)` — copy that.

### Unified media library (images reuse it)

- Storage (server): `/var/www/media/{username}/`. CDN: `https://media.botofthespecter.com/{username}/{file}`.
- Today it holds **MP3/MP4 only** (sound alerts, video alerts, twitch event sounds, walkons) — see [media-unified-library.md](./media-unified-library.md).
- Images (`jpg/png/webp`) are a **new content type**. This plan uploads them into the same `/var/www/media/{username}/` dir (new feature, no migration concern — independent of the `users.new_media` alert-migration flag) through a dedicated Makers uploader, so makers images and alert media share one CDN host and storage-usage accounting.

### Dashboard overlay-config pattern

`overlays.php` per-overlay shape (e.g. credits, `overlays.php` ~lines 29-58, 110-130):
- A per-user settings table (e.g. `credits_overlay_settings`, singleton `WHERE id = 1`).
- A `sp-card` showing the OBS browser-source URL + a gear button opening an `sp-modal-backdrop` settings modal.
- A POST handler (`*_overlay_save`) doing `INSERT … ON DUPLICATE KEY UPDATE`, returning JSON.

For Makers, the *quick* settings (mode, visibility, styling) fit this modal; the richer project library + image management warrant a dedicated `makers.php` page linked from the card.

### Schema deployment

`./dashboard/usr_database.php` holds a `$tables` array (~line 40) of `CREATE TABLE IF NOT EXISTS` statements applied to every per-user DB. Adding entries there auto-provisions the new tables across all users — same path the `walkons` table used.

### Command namespace — collision to avoid

The [working-and-study.md](./working-and-study.md) plan defines a **viewer-scoped** `!project` command (each viewer partitions their own task backlog). The Makers overlay is **broadcaster/mod-only**. To avoid a confusing shared verb, this plan uses **`!craft`** as the canonical command (see §12.1 for the naming decision). `!project` is **not** available.

## 3. Data model (per-user DB)

Three new tables. Match the house style: InnoDB, `utf8mb4_unicode_ci`, indexes (not hard FKs — cascade handled in app code, consistent with the rest of the schema).

### 3.1 `maker_projects`

```sql
CREATE TABLE IF NOT EXISTS maker_projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('current','finished','upcoming') NOT NULL DEFAULT 'current',
    link_url VARCHAR(500) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

- `status` is the project's lifecycle stage and the basis for the three display modes: `current` = work-in-progress, `finished` = completed (shown in the finished carousel), `upcoming` = idea/backlog.
- `description` is the "related context" (materials, technique, notes) — free text.
- `link_url` optional (pattern, shop listing, reference). Rendered as a small link/QR-free label; validated to `http(s)://` only.
- `completed_at` set when a project is marked finished (used to sort the finished carousel newest-first).

### 3.2 `maker_project_images`

```sql
CREATE TABLE IF NOT EXISTS maker_project_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    media_file VARCHAR(255) NOT NULL,
    caption VARCHAR(255) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

- `media_file` is a filename inside `/var/www/media/{username}/`; the overlay builds `https://media.botofthespecter.com/{username}/{media_file}`.
- A project with zero image rows renders as a **text-only** card (the notes' "simple text updates when images are not needed").
- Deleting a project deletes its image rows in app code (no DB cascade). Deleting an image row does **not** delete the underlying file (it may be shared / reusable).

### 3.3 `maker_overlay_settings` (singleton, `id = 1`)

```sql
CREATE TABLE IF NOT EXISTS maker_overlay_settings (
    id TINYINT PRIMARY KEY DEFAULT 1,
    display_mode ENUM('current','finished','upcoming') NOT NULL DEFAULT 'current',
    current_project_id INT DEFAULT NULL,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    carousel_seconds INT NOT NULL DEFAULT 6,
    project_rotate_seconds INT NOT NULL DEFAULT 15,
    accent_color VARCHAR(7) DEFAULT '#9146FF',
    text_color VARCHAR(7) DEFAULT '#FFFFFF',
    font_family VARCHAR(50) DEFAULT 'Arial',
    position ENUM('top-left','top-right','bottom-left','bottom-right') NOT NULL DEFAULT 'bottom-right',
    show_title TINYINT(1) NOT NULL DEFAULT 1,
    show_description TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

- `display_mode` — which of the three modes the overlay renders right now.
- `current_project_id` — the featured WIP shown in `current` mode (points at a `maker_projects.id`; `NULL` → show an "idle" placeholder or nothing).
- `visible` — show/hide the whole card (default on; chat-toggleable).
- `carousel_seconds` — image auto-advance interval inside one project.
- `project_rotate_seconds` — cycle interval between projects in `finished`/`upcoming` modes.
- Styling fields (`accent_color`, `text_color`, `font_family`, `position`, `show_*`) drive the card appearance; colours validated to `#RRGGBB`, font against an allowlist (the `counters.php` / `todolist.php` pattern).

## 4. Display modes

The overlay reads `display_mode` and renders accordingly:

| Mode | Source query | Render |
|---|---|---|
| **current** | the one project where `id = current_project_id` (+ its images) | Single card: title, context, image carousel (auto-advance every `carousel_seconds`). Text-only if no images. |
| **finished** | `SELECT … WHERE status='finished' ORDER BY completed_at DESC` (+ images) | Cycle through finished projects every `project_rotate_seconds`; each project runs its own image carousel while shown. |
| **upcoming** | `SELECT … WHERE status='upcoming' ORDER BY sort_order, id` (+ images) | Highlight upcoming ideas — same rotation as finished, framed as "Coming up". |

Empty-state handling per mode (no current project set / no finished projects / no upcoming ideas) renders a muted placeholder rather than a blank card, so the browser source is never an empty rectangle.

## 5. Chat command surface (`!craft`)

Broadcaster/mod-only (reuse the existing `command_permissions("mod", …)` gate seen on `!deaths`). One parent command with subcommands, registered via the existing `builtin_commands` dict + handler in `beta.py` event processing — the same wiring the working/study plan uses.

| Command | Behaviour |
|---|---|
| `!craft new <title>` | Create a project (`status='current'`), set it as `current_project_id`, switch `display_mode='current'`. Bot confirms in chat with the new project id. |
| `!craft note <text>` | Set the featured project's `description` (the context line). `!craft note +<text>` appends. |
| `!craft link <url>` | Set the featured project's `link_url` (validated `http(s)://`). |
| `!craft current <id>` | Make project `<id>` the featured current one (and `display_mode='current'`). |
| `!craft finish` | Mark the featured project `status='finished'`, stamp `completed_at`. Clears `current_project_id` (or leaves it; see §12.4). |
| `!craft upcoming <title>` | Create a project with `status='upcoming'`. |
| `!craft mode <current\|finished\|upcoming>` | Switch the overlay display mode. |
| `!craft image <filename>` | Attach an already-uploaded media image (by filename) to the featured project. Validates the file exists under `/var/www/media/{channel}/`. (Primary image management is the dashboard; this is the in-stream convenience.) |
| `!craft show` / `!craft hide` | Toggle `visible`. |
| `!craft list` | Bot replies in chat with projects + ids, grouped by status (concise, single message). |
| `!craft remove <id>` | Delete a project (+ its image rows). |

Every state-changing command: **write per-user MySQL → emit `MAKER_UPDATE`** so the overlay re-fetches and re-renders instantly. Bot replies are concise single lines (chat-clutter is the main risk). Each subcommand gets an enable/disable toggle in the dashboard so a streamer can lock the surface down.

## 6. Overlay rendering (`./overlay/maker.php`)

OBS browser source: `https://overlay.botofthespecter.com/maker.php?code=API_KEY`.

**PHP (server-render + JSON endpoint), following `counters.php`:**
- Resolve `?code=` → username via `website.users` (prepared statement).
- `?type=json` → return the full overlay state: `settings` + the projects/images relevant to `display_mode`. `Cache-Control: no-store`.
- Default (no `type`) → render the initial card HTML (so a reload shows current state immediately) and boot the JS.
- All config loaded from the per-user DB on page load — overlay stays stateless ([overlays.md](../rules/overlays.md) rule 1). API key in the URL is the only auth (rule 9).

**JS (Socket.io 4.8.3 client), following `deaths.php`:**
- Connect to `wss://websocket.botofthespecter.com`, `REGISTER` with `{ code, channel:'Overlay', name:'Makers' }`.
- On `MAKER_UPDATE`: re-fetch `?type=json`, re-render the card (no full reload).
- Auto-reconnect with capped backoff (rule 4 — OBS sources stay open for hours).
- Image carousel + project rotation handled client-side from the fetched JSON (timers from `carousel_seconds` / `project_rotate_seconds`).
- Resolution-independent sizing (rule 7) — the `counters.php` fit-to-source approach is a good reference; use relative units, no hardcoded px.
- Honour `visible` (hide the card entirely when off) and `position`.

**CSS:** new `.maker-overlay-page-*` block in `./overlay/index.css` (the overlay folder's own stylesheet — never link dashboard CSS). Reuse existing token/animation conventions; do not invent new colours outside the configured `accent_color`/`text_color`.

## 7. Dashboard (`./dashboard/makers.php` + card in `overlays.php`)

**`makers.php` (new page):**
- **Project library** — list grouped by status; create/edit/delete; set title, description, link, status; reorder; "set as current" button. Uses the `sp-card` / `sp-modal` patterns and `dashboard.css` `sp-` classes (per the ui-theme system — dashboard pages load `dashboard.css` from their own folder).
- **Image management** — per project: upload (multi), attach existing media files, set captions, reorder, remove. Upload writes to `/var/www/media/{username}/` (creating the dir if absent), validating **image** MIME + extension (`jpg/jpeg/png/webp`) and a size cap; reuses `storage_used.php` accounting.
- **Quick settings** — display mode, visibility, carousel/rotation timing, styling (accent/text colour, font, position, show title/description), per-subcommand enable toggles. POST handler `maker_overlay_save` → `INSERT … ON DUPLICATE KEY UPDATE` on `maker_overlay_settings`, returns JSON. After save, emit `MAKER_UPDATE` (via the existing dashboard→websocket trigger path) so the overlay hot-updates.

**`overlays.php` (existing page):** add a `sp-card` for "Makers & Crafting" showing the OBS URL `https://overlay.botofthespecter.com/maker.php?code=API_KEY_HERE` and a link/gear to `makers.php`.

## 8. Bot gap analysis (`./bot/beta.py`)

1. **New command family** `!craft …` — handler in event message processing, registered in `builtin_commands`, gated to mod/broadcaster. Help text added to `builtin_commands` info.
2. **One new branch in `websocket_notice`** — `elif event == "MAKER_UPDATE": params.update(additional_data or {})` (the final `else` otherwise rejects unknown events). Payload is a lightweight signal (e.g. `{action, project_id}`); the overlay re-fetches the real state from `maker.php?type=json`, so we never stuff nested project/image data through the query string.
3. **`!craft image`** validates file existence under `/var/www/media/{channel}/` — reuse the existing `ssh_manager.file_exists('WEB', …)` helper the WALKON path already uses, or a direct check if the bot shares the media volume.
4. No token/refresh, no new background task. The bot stays stateless about overlay rendering — DB is the source of truth.

## 9. WebSocket gap analysis (`./websocket/server.py`)

- **No change strictly required.** `MAKER_UPDATE` is unrecognised → handled by the generic `else` in `notify_http` (~line 812), which emits to all clients under the channel `code` + global listeners. Code-scoping (one streamer's events don't leak to another) is already enforced there.
- **Optional polish:** add `MAKER_UPDATE` to an explicit branch for consistent logging (mirroring `STREAM_ONLINE` etc.) and, if desired, restrict to overlay clients. Low priority — defer unless logging noise matters.
- No new server-side ticker (carousel/rotation timing is client-side in the overlay).

## 10. API gap analysis (`./api/api.py`)

**No changes.** Every read/write path is covered:
- Overlay self-serves config + project data via `maker.php` PHP (per-user DB direct).
- Dashboard reads/writes the per-user DB directly via PHP.
- Bot writes the per-user DB directly via `mysql_connection()`, per [data-flow.md](../rules/data-flow.md).

Future (out of scope): if the Twitch Extension panel ever surfaces the project list to viewers, that would need a new `/api/extension/makers` endpoint. Not part of this plan.

## 11. Implementation order

Each step is independently shippable; together they deliver all three modes.

1. **Schema** — add the three tables to `./dashboard/usr_database.php` (`CREATE TABLE IF NOT EXISTS` auto-deploys to all per-user DBs).
2. **Dashboard `makers.php` — project library CRUD** (no images yet). Validates the data model end to end.
3. **Overlay `maker.php` — `current` mode** (PHP render + `?type=json` + Socket.io `MAKER_UPDATE` refetch). First visible win: a live single-project card.
4. **Bot `!craft` commands** + the `websocket_notice` branch. Wires chat → DB → overlay.
5. **Image management** — dashboard upload/attach (image MIME validation) + overlay carousel + `!craft image`.
6. **`finished` and `upcoming` modes** — project rotation in the overlay + `!craft mode` / `!craft upcoming` / `!craft finish`.
7. **Styling + quick-settings modal** in dashboard; add the card to `overlays.php`.
8. **Port to `beta-v6.py`** once stable (separate session; re-verify TwitchIO 3.2.2 command registration).

Steps 1–4 alone ship a usable "current project, live from chat" overlay.

## 12. Open decisions

1. **Command name.** Recommendation: **`!craft`** (short, on-theme; `!project` is taken by working/study's viewer-scoped command). Alternatives: `!making`, `!makers`, `!wip`, `!showcase`. Worth confirming since it's user-facing. Aliases can be added cheaply.
2. **Standalone file name** — `maker.php` (recommended, singular, matches `deaths.php`/`weather.php`) vs `makers.php`/`crafting.php`. Dashboard page proposed as `makers.php` (plural reads better for a library page) — fine to differ, but confirm.
3. **Image upload into the shared media library vs a dedicated `makers/` subdir.** Recommendation: shared `/var/www/media/{username}/` so storage accounting and the CDN host stay unified. Downside: makers photos and alert sounds share one flat dir. A `/var/www/media/{username}/makers/` subdir would isolate them — decide based on how `media.php`'s future file browser should treat images.
4. **`!craft finish` and `current_project_id`** — when the featured project is finished, clear `current_project_id` (overlay shows idle placeholder in `current` mode) or auto-promote the next `current`-status project? Recommendation: clear it and let the streamer pick the next with `!craft new`/`!craft current`.
5. **Multiple `current`-status projects.** The model allows many `status='current'` rows but only one is *featured* via `current_project_id`. Is that the intended mental model (a pool of WIPs, one featured), or should `current` be exactly one at a time? Recommendation: pool + featured pointer (more flexible; `!craft list` shows all WIPs).
6. **Add to `all.php`?** The master overlay is built for transient alerts, not a persistent card. Recommendation: ship standalone; revisit adding an always-visible Makers region to `all.php` only if streamers ask.
7. **Image count / size caps per project.** Recommendation: cap ~10 images/project and reuse the existing per-file size limit from `media.php` uploads; surface storage usage in `makers.php`.
8. **Carousel transition style** (crossfade vs slide) and whether captions overlay the image or sit beneath — cosmetic; pick during step 5, default to crossfade + caption beneath.
