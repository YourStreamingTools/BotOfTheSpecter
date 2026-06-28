# Makers & Crafting Overlay — Plan

> Status: Implemented in `beta.py`, the dashboard, and the overlay. Last reconciled against the code on 2026-06-26.
> Originally drafted 2026-05-30; this revision brings the document in line with the design that actually went in.
> One piece is still outstanding: porting `!craft` + `MAKER_UPDATE` to `beta-v6.py` (see §13). Stable `bot.py` is deliberately left untouched.

## 0. Where the pieces live

The feature came together over roughly eleven commits (first landing in `fd72c7a9`, recency sort settling in `4218e898`) and evolved past the original draft along the way. Everything below describes the design as it now stands; subsections flag where it diverged from the 2026-05-30 draft.

| Surface | File | Notes |
|---|---|---|
| Per-user schema (3 tables) | `./dashboard/includes/usr_database.php` | In place; expanded beyond the draft |
| Overlay (OBS browser source) | `./overlay/maker.php` | In place |
| Overlay CSS | `./overlay/index.css` (`.maker-*`) | In place |
| Dashboard management page | `./dashboard/makers.php` | In place |
| Dashboard overlays card | `./dashboard/overlays.php` | In place |
| Menu registration | `./dashboard/menu.php` (`navbar_makers_crafting`) | In place, under the Stream Tools submenu |
| i18n keys | `./dashboard/lang/en.php` (93+ `makers_*` / `overlays_makers*`) | In place; de/fr coverage unverified |
| Bot `!craft` family + `MAKER_UPDATE` | `./bot/beta.py` | In place |
| `builtin_commands.json` entry | `./api/builtin_commands.json` (`craft`) | In place |
| WebSocket server | `./websocket/server.py` | No change needed — generic `else` branch carries it |
| Stable bot | `./bot/bot.py` | Untouched, by design |
| v6 port | `./bot/beta-v6.py` | Outstanding — `!craft`/`maker`/`MAKER_UPDATE` all absent |

**Headline divergences from the draft** (detail inline): the single mutually-exclusive `display_mode` became **four independently-toggleable boxes** (a new *featured* box joined current/finished/upcoming); the 4-corner `position` enum became **per-box x/y drag positioning**; both the old `display_mode` and `position` columns are now **dead/unused**.

## 1. Scope

A browser-source overlay for makers / crafting content that shows the streamer's **current project and its context**, updatable live from chat without leaving the workflow. As it stands it renders up to four independently-toggleable cards (featured / current / upcoming / finished), each positioned anywhere on the canvas, with either an image carousel or text-only context per project.

**Confirmed product decisions** (2026-05-30, all honoured):

1. **Images come from the unified media library.** Streamers upload project photos through the dashboard; files live in the same storage/CDN as alert media (`/var/www/media/{user}/` → `https://media.botofthespecter.com/{user}/{file}`). No raw files or URLs are typed in chat. Images are a content type this feature added to that shared storage. There is no `makers/` subdir — files land directly in `/var/www/media/{username}/` (decision §12.3 → shared).
2. **All display modes shipped in v1.** This became four toggleable boxes rather than three exclusive modes (see §4).
3. **Always-visible card.** The overlay stays on screen and updates in place. A `visible` master toggle plus per-box `show_*` flags default the featured box on and the rest off.
4. **Dashboard + chat hybrid management.** Project library, image upload/attach, and styling live in `makers.php`; `!craft` chat commands drive fast in-stream actions.

**Affected systems**

- `./bot/beta.py` — **primary implementation target.** The `!craft` chat command family and the `MAKER_UPDATE` websocket signal live here.
- `./bot/beta-v6.py` — **not yet ported.** No `craft`/`maker` code present. This is the remaining work (§13).
- `./bot/bot.py` (stable) — left untouched (no critical fix involved).
- `./websocket/server.py` — **no change.** `MAKER_UPDATE` is unrecognised and is handled by the generic `else` branch inside `notify_http`, which emits to the channel's `code` clients plus global listeners. The optional explicit-branch polish (§9) was left out.
- `./api/api.py` — **no change.** The overlay self-serves via PHP; bot and dashboard write per-user MySQL directly.
- `./overlay/maker.php` — new file. Server-renders initial state, exposes `?type=json`, and re-fetches on `MAKER_UPDATE`.
- `./overlay/index.css` — `.maker-*` classes (the overlay folder's own stylesheet).
- `./dashboard/makers.php` — new file. Project CRUD, image upload/attach, styling controls, drag-positioning editor.
- `./dashboard/overlays.php` — Makers card added (OBS URL + gear → `makers.php`).
- `./dashboard/menu.php` — registered under Stream Tools (`navbar_makers_crafting`, `fa-palette`).
- `./dashboard/includes/usr_database.php` — three per-user tables in the `$tables` schema array (auto-deploy via `CREATE TABLE IF NOT EXISTS`). **Path note:** the schema file moved under `includes/` (commit `1bd301d0`); the original draft referenced `./dashboard/usr_database.php`.
- Per-user MySQL DB — `maker_projects`, `maker_project_images`, `maker_overlay_settings`.

**Explicitly out of scope** (still out):

- Adding the Makers card into `all.php` (the master overlay is built around transient alerts — see §12.6).
- Per-project analytics (time spent, view counts).
- Pulling images from external sources (Etsy, Instagram).
- Viewer-submitted project ideas. The overlay is streamer-owned; chat commands are broadcaster/mod-only.

## 2. Current state inventory

What this plan built on. Both existing overlay patterns were reused as intended.

### Overlay patterns (two, both reused)

| Pattern | Example | Mechanism |
|---|---|---|
| **DB-persisted + JSON re-fetch** | `./overlay/counters.php` | PHP reads per-user DB on load, renders, exposes `?type=json`; JS keeps the value fresh. Survives OBS browser-source reload. |
| **WebSocket push** | `./overlay/deaths.php`, `all.php` | Socket.io 4.8.3 client registers with `?code=API_KEY`, listens for named events, re-renders. Auto-reconnect with backoff. |

This overlay combines both — the right fit for an always-visible card that only changes on command. PHP persists and renders the current state on load; a lightweight `MAKER_UPDATE` websocket signal tells the overlay to re-fetch its JSON and re-render. No constant polling, which satisfies [overlays.md](../rules/overlays.md) rule 2.

### Chat command → overlay path

1. The bot command handler in `beta.py` writes per-user MySQL directly (`mysql_connection()` → the channel's own DB).
2. The bot calls `websocket_notice(event="MAKER_UPDATE", additional_data={"action": subcommand})` (fire-and-forget via `safe_create_task`).
3. `websocket_notice` has a `MAKER_UPDATE` branch that does `params.update(additional_data)`, URL-encodes, and HTTP GETs `https://websocket.botofthespecter.com/notify?...`.
4. The WebSocket server's `notify_http` validates the key, then the generic `else` branch emits `MAKER_UPDATE` to every client under that `code` plus global listeners. No server-side change is required.
5. The overlay (Socket.io client) reacts by re-fetching `maker.php?type=json`.

**Also emitted from the dashboard:** `makers.php` calls `maker_notify_overlay($apiKey)` (GET `…/notify?code=…&event=MAKER_UPDATE`) after **every** state change (create/update/delete project, set_current, upload/attach/delete image, save_settings), so dashboard edits hot-update the overlay too.

### Unified media library (images reuse it)

- Storage (server): `/var/www/media/{username}/`. CDN: `https://media.botofthespecter.com/{username}/{file}`.
- Images (`png/jpg/jpeg/gif`) were added as a content type into that shared dir, validated via `./dashboard/includes/upload_helpers.php` (`upload_validate_extension_and_mime()`, `upload_sanitize_filename()`, `upload_unique_target()`), with storage-cap accounting. **Note vs draft:** `gif` is allowed; `webp` (named in the draft) is **not**.

### Schema deployment

`./dashboard/includes/usr_database.php` holds the `$tables` array of `CREATE TABLE IF NOT EXISTS` statements applied to every per-user DB. The three maker tables were added there and auto-provision across all users — the same path `walkons` used.

### Command namespace — collision avoided

`!project` is the viewer-scoped working/study command; the Makers surface is broadcaster/mod-only, so it uses **`!craft`** (with aliases `wip`, `projects`, etc.). Decision §12.1 → `!craft`, honoured.

## 3. Data model (per-user DB)

Three tables in `./dashboard/includes/usr_database.php`. All InnoDB, `utf8mb4_unicode_ci`, indexed, with no hard foreign keys — cascades are handled in app code.

### 3.1 `maker_projects` (matches the draft exactly)

- `id` — auto-increment primary key.
- `title` — `VARCHAR(255)`, required.
- `description` — `TEXT`, nullable; free-text context.
- `status` — `ENUM('current','finished','upcoming')`, default `current`. Drives which box a project appears in. **Multiple `current`-status rows are allowed** (a pool of WIPs — decision §12.5).
- `link_url` — `VARCHAR(500)`, nullable; validated `http(s)://`.
- `sort_order` — `INT`, default 0.
- `completed_at` — `DATETIME`, nullable; stamped on finish, sorts the finished box newest-first.
- `created_at` / `updated_at` — timestamps. `updated_at` auto-updates and doubles as the **recency signal** for auto-featuring: the featured project is the most-recently-updated `current` row unless a manual pin overrides it. Every bot mutation (`note`/`link`/`image`/`current`) stamps `updated_at = NOW()`.
- Indexed on `status`.

### 3.2 `maker_project_images` (matches the draft exactly)

- `id` — primary key.
- `project_id` — `INT`, required; indexed.
- `media_file` — `VARCHAR(255)`; a filename inside `/var/www/media/{username}/` (server). The overlay builds the CDN URL.
- `caption` — `VARCHAR(255)`, nullable.
- `sort_order` — `INT`, default 0. The overlay and dashboard read `ORDER BY sort_order ASC, id ASC`, **but no reorder UI shipped** (see §13).
- `created_at` — timestamp.

A project with zero image rows renders as a **text-only** card. Deleting a project deletes its image rows in app code (no DB cascade). Deleting an image row does **not** delete the file (shared/reusable).

### 3.3 `maker_overlay_settings` (singleton `id = 1`) — significantly expanded vs the draft

A single settings row holds the whole overlay's configuration:

- **Master / identity** — `id` (`TINYINT` PK, default 1); `visible` (`TINYINT(1)`, default 1) master on/off for the whole overlay; `updated_at` auto-update timestamp.
- **`current_project_id`** (`INT`, nullable) — the manual "Feature now" pin, set by the dashboard `set_current` action (which also forces that project to `status='current'`). When `NULL`, the overlay falls back to recency (most-recently-updated `current` project). The bot has **no `!craft pin`**; `!craft current <id>` works by recency (stamps `updated_at`), not by writing this column.
- **Per-box visibility** — `show_featured` (default 1), `show_current` (default 0), `show_finished` (default 0), `show_upcoming` (default 0). These (not `display_mode`) decide what renders, and **multiple boxes can show at once.**
- **Content toggles within a card** — `show_title` / `show_description` / `show_link`, all default 1.
- **Layout** — `box_layout` (`ENUM('positioned','stacked-left','stacked-right')`, default `positioned`).
- **Per-box placement** — `position_featured_x/y` (79.00 / 64.00), `position_current_x/y` (2.00 / 64.00), `position_upcoming_x/y` (79.00 / 3.00), `position_finished_x/y` (2.00 / 3.00). Each is `DECIMAL(5,2)`, a canvas percentage produced by the drag editor. Replaces the old 4-corner enum.
- **`preview_canvas`** (`VARCHAR(12)`, default `1920x1080`) — one of `1280x720` / `1920x1080` / `2560x1440`; scales the dashboard drag-editor preview only.
- **Timing** — `carousel_seconds` (default 6, range 2–60) advances images within a project; `project_rotate_seconds` (default 15, range 3–120) rotates between projects in a multi-project box.
- **Styling** — `accent_color` (default `#9146FF`) and `text_color` (default `#FFFFFF`), both validated `#RRGGBB`; `font_family` (default `Arial`) from an 8-font whitelist (Arial, Verdana, Georgia, Tahoma, Trebuchet MS, Times New Roman, Courier New, Inter).
- **Dead columns** — `display_mode` (`ENUM('current','finished','upcoming')`) and `position` (`ENUM('top-left','top-right','bottom-left','bottom-right')`) are carried over from the original design but never read by `maker.php`. They are candidates for removal once the system-DB migrations tooling ([2026-06-21-system-db-migrations.md](./2026-06-21-system-db-migrations.md)) exists; until then they stay — they're per-user, harmless, and not worth an ad-hoc schema sweep.

## 4. Display model (changed from the draft)

The draft proposed **one** `display_mode` showing exactly one of three categories. The design that went in instead uses four independent boxes, each toggled by its own `show_*` flag and placed independently, able to render simultaneously.

| Box | Source | Render |
|---|---|---|
| **featured** | the pinned `current_project_id`, else the most-recently-updated `current` project (+ its images) | Single hero card: title, context, image carousel (every `carousel_seconds`). Text-only if no images. Default on. |
| **current** | `status='current'` projects | Rotates through WIPs every `project_rotate_seconds`, each with its own image carousel. Default off. |
| **upcoming** | `status='upcoming'` ORDER BY `sort_order`, `id` | "Coming up" rotation. Default off. |
| **finished** | `status='finished'` ORDER BY `completed_at DESC` | Completed-projects rotation. Default off. |

- The bot's `!craft mode <featured|current|finished|upcoming>` is a convenience that flips to showing **only** that one box (sets its flag on, the others off) — the exclusive-mode idea from the draft survives as one command rather than as the core mechanism.
- `!craft show` / `!craft hide` toggle a single box (or the whole overlay's `visible` when given no category).
- Empty boxes render a muted placeholder rather than a blank rectangle.
- The `?type=json` endpoint returns `{ ok, settings, featured, current[], finished[], upcoming[] }`; the client renders whichever boxes are flagged on and runs the carousels/rotations from the timing fields.

## 5. Chat command surface (`!craft`)

Broadcaster/mod-only, gated by a single command-level check via the `builtin_commands` row (default `mod`, dashboard-configurable for status/permission/cooldown). Registered with `@commands.command(name='craft')` in `beta.py` plus a `craft` entry in `./api/builtin_commands.json`. **Per-subcommand enable toggles were not built** — gating is all-or-nothing at the command level (divergence from draft §5).

| Command | Aliases | Behaviour |
|---|---|---|
| `!craft new <title>` | `wip` | Create a `current` project; recency auto-features it; sets `show_featured=1`. |
| `!craft note <text>` | `desc`, `context` | Set the featured project's `description` (max 2000). `+<text>` appends. Stamps `updated_at`. |
| `!craft link <url>` | — | Set the featured project's `link_url` (validated `http(s)://`, max 500). |
| `!craft current <id>` | — | Make `<id>` the featured project by stamping `updated_at=NOW()` (recency), force `status='current'`, `show_featured=1`. |
| `!craft finish` | — | Mark the featured project `finished`, stamp `completed_at`. The overlay auto-falls to the next-most-recent `current`. |
| `!craft upcoming <title>` | — | Create an `upcoming` project (not featured). |
| `!craft mode <featured\|current\|finished\|upcoming>` | — | Show only that one box. |
| `!craft image <filename>` | — | Attach an uploaded image (by filename) to the featured project. **Validates extension (`png/jpg/jpeg/gif`) + char-set only — no file-existence check** (divergence from draft §8.3). |
| `!craft show [category]` / `!craft hide [category]` | — | Toggle one box on/off, or the whole overlay's `visible` if no category. |
| `!craft list` | `projects` | Reply in chat with projects + ids grouped by status (≤10 per message). |
| `!craft remove <id>` | `delete` | Delete a project (+ its image rows). The overlay auto-falls to the next `current` by recency. |

Every state-changing command writes per-user MySQL and then emits `MAKER_UPDATE` (`{action: subcommand}`). Replies are concise single lines.

## 6. Overlay rendering (`./overlay/maker.php`)

OBS browser source: `https://overlay.botofthespecter.com/maker.php?code=API_KEY`.

- Resolves `?code=` → username via `website.users` (`SELECT username FROM users WHERE api_key = ?`).
- `?type=json` → returns the full state object (above), with `Cache-Control: no-store` and `Access-Control-Allow-Origin: *`.
- Default → server-renders the boxes' initial HTML (positions injected as inline `left`/`top` %), then boots the JS.
- JS: Socket.io 4.8.3 to `wss://websocket.botofthespecter.com`, `REGISTER` with `{code, …}`; on `MAKER_UPDATE` it re-fetches `?type=json` and re-renders (no full reload). Auto-reconnect with backoff.
- Carousels (`carousel_seconds`) and project rotation (`project_rotate_seconds`) run client-side from the JSON.
- Honours `visible`, the per-box `show_*` flags, `box_layout`, x/y positions, colours (`--maker-accent`/`--maker-text`), and `font_family`.
- Stateless — all config comes from the per-user DB on load; `?code` is the only auth. CSS lives in `./overlay/index.css` (`.maker-*`).

## 7. Dashboard (`./dashboard/makers.php` + card in `overlays.php`)

**`makers.php`:**

- **Project library** — create / edit / delete; set title, description (≤2000), `link_url` (regex `^https?://`), status. Listed grouped by status, each group `ORDER BY updated_at DESC, id DESC`. A **"Feature now"** button (`set_current`) sets the sticky `current_project_id` pin. **No reorder UI** despite `sort_order` existing.
- **Image management** — `upload_image` writes to `/var/www/media/{username}/` (created if absent) with MIME+extension validation (`png/jpg/jpeg/gif`) via `upload_helpers.php`, filename sanitisation, collision-safe unique naming, and storage-cap accounting; `attach_image` links an existing media file (with caption); `delete_image` removes the row (the file persists). No image-reorder UI.
- **Quick settings** (`save_settings`) — `visible`, per-box `show_*` flags, content `show_title/description/link`, `carousel_seconds` (2–60), `project_rotate_seconds` (3–120), `accent_color`/`text_color`, `font_family` (8-font whitelist), `box_layout`, `preview_canvas`, and a **drag-positioning editor** that writes the eight `position_*_x/y` values. The legacy `position` enum is intentionally **not** written. There is no `display_mode` picker (display is bot/flag-driven).
- Every action calls `maker_notify_overlay()` → emits `MAKER_UPDATE` so the overlay hot-updates.
- Fully i18n'd via `t()` (93+ `makers_*` / `overlays_makers*` keys in `en.php`; de/fr coverage **unverified** — flagged for the i18n remediation pass). Registered in `menu.php` under Stream Tools as `navbar_makers_crafting` (`fa-palette`).

**`overlays.php`:** a `sp-card` "Makers & Crafting" (`overlays_makers_crafting`) with a gear linking to `makers.php` and the OBS URL `https://overlay.botofthespecter.com/maker.php?code=API_KEY_HERE`.

## 8. Bot side (`./bot/beta.py`)

- The `!craft` family lives in event/command processing, registered in `builtin_commands.json`, gated mod/broadcaster at the command level only (no per-subcommand gating).
- `websocket_notice` carries a `MAKER_UPDATE` branch (`params.update(additional_data)`). The payload is a lightweight `{action}` signal; the overlay re-fetches real state from `maker.php?type=json`.
- **Known limitation:** `!craft image` does not validate file existence under `/var/www/media/{channel}/` — only extension/char-set checks. The draft wanted an `ssh_manager.file_exists('WEB', …)` check; that hardening is deferred (§13).
- No token/refresh and no new background task. The DB is the source of truth.

## 9. WebSocket side (`./websocket/server.py`)

- **No change made or needed.** `MAKER_UPDATE` is unrecognised, so it falls through the generic `else` in `notify_http`, which emits to all clients under the channel `code` plus global listeners. Code-scoping is enforced there.
- The optional explicit-branch logging polish was **not** done (low priority; defer unless logging noise becomes an issue).
- No server-side ticker — carousel/rotation timing is client-side.

## 10. API side (`./api/api.py`)

- **No changes.** The overlay self-serves via `maker.php` (per-user DB direct); the dashboard reads/writes per-user DB via PHP; the bot writes per-user DB via `mysql_connection()`. (Future, out of scope: a `/api/extension/makers` endpoint if the Twitch Extension panel ever surfaces projects to viewers.)

## 11. Implementation order

The work broke down in this order, building up from data to surfaces:

1. Schema — three tables in `./dashboard/includes/usr_database.php`.
2. Dashboard `makers.php` — project library CRUD.
3. Overlay `maker.php` — featured/current rendering, `?type=json`, and the Socket.io `MAKER_UPDATE` refetch.
4. Bot `!craft` commands + the `websocket_notice` branch.
5. Image management — upload/attach (MIME validation), overlay carousel, `!craft image`.
6. The four boxes — project rotation, `!craft mode` / `upcoming` / `finish`, and per-box `show_*` flags.
7. Styling + drag-positioning editor in the dashboard, and the card in `overlays.php`.
8. **Port to `beta-v6.py`** — the remaining piece (§13).

Items 1–7 deliver a fully usable feature on the `beta.py` bot; item 8 is what's left.

## 12. Open decisions — settled

1. **Command name** → **`!craft`** (with aliases `wip`/`projects`/`desc`/`context`/`delete`).
2. **File names** → overlay `maker.php` (singular), dashboard `makers.php` (plural).
3. **Image storage** → shared `/var/www/media/{username}/`, **no `makers/` subdir**.
4. **`!craft finish` + featured pointer** → no manual pointer to clear; recency auto-falls to the next `current` project (or empty state).
5. **Multiple `current` projects** → **pool + featured pointer**: many `current` rows allowed; featured = the sticky pin (`current_project_id`) or the most-recent by recency.
6. **Add to `all.php`?** → **No**, kept standalone (revisit only on demand).
7. **Image count / size caps** → enforced via the shared storage cap + per-file `upload_max_filesize`; **no explicit per-project image-count cap** was added (optional follow-up if abuse appears).
8. **Carousel transition style** → client-side, cosmetic; not separately configurable.

## 13. Remaining work / follow-ups

Ordered by value:

1. **Port `!craft` + `MAKER_UPDATE` to `./bot/beta-v6.py`.** The only feature-completeness gap. TwitchIO 3.2.2 differs from 2.10, so re-verify command registration, the `commands` API, and the `websocket_notice` equivalent; don't assume the `beta.py` block drops in verbatim ([bot-versions.md](../rules/bot-versions.md)). Stable `bot.py` stays untouched.
2. **`!craft image` file-existence validation** — add the `ssh_manager.file_exists('WEB', …)` check the draft intended, so attaching a non-existent filename fails fast instead of producing a broken overlay image. Low effort; do it in both `beta.py` and the v6 port.
3. **Image-reorder UI** in `makers.php` — `sort_order` exists and is honoured by queries, but there's no drag-to-reorder control. Carousels currently order by `sort_order, id` with no way to change `sort_order`.
4. **i18n de/fr coverage** — confirm the `makers_*` / `overlays_makers*` keys exist in `de.php` and `fr.php`; fold into the dashboard i18n remediation pass if not.
5. **Per-subcommand enable toggles** — the draft wanted each `!craft` subcommand individually lockable; only command-level gating went in. Decide whether to build this or formally drop it.
6. **Retire dead columns** `display_mode` and `position` from `maker_overlay_settings` — defer to the system-DB migrations tooling ([2026-06-21-system-db-migrations.md](./2026-06-21-system-db-migrations.md)) rather than an ad-hoc per-user schema sweep.
7. **webp support** (optional) — the draft listed `webp`; only `png/jpg/jpeg/gif` went in. Add it to the upload allowlist + the `!craft image` check if wanted.
