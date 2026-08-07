# Raid Ad Snooze — Implementation Plan

**Spec:** `.grok/specs/2026-08-06-raid-ad-snooze-design.md`  
**Goal:** on an incoming raid, if the next scheduled mid-roll is within a configurable window (default 10 minutes) and Twitch still has snoozes available, beta and v6 call Helix **once** to snooze the next ad; streamers control this under Modules → Ad Notices (default on) with a separate chat message.

**Approach in one paragraph:** extend `ad_notice_settings` and the Ad Notices UI first, then add a raid-time helper in each beta bot that reads the ad schedule, optionally POSTs snooze, suppresses the poller’s generic “ads snoozed” chat for that intentional drop, and never blocks the raid welcome path.

**Touch points:** MySQL per-user schema, dashboard Modules (PHP + i18n), `bot/beta.py`, `bot/beta-v6.py`. Stable `bot/bot.py` stays untouched.

## Constraints

- **Beta + v6 only.** Do not change stable for this feature.
- **Parameterized SQL** on PHP and Python; no string-built queries with user input.
- **PHP never reads `.env`** — dashboard already uses DB config.
- **Broadcaster token** (`CHANNEL_AUTH`) for Helix ads endpoints; scopes already on login (`channel:read:ads`, `channel:manage:ads`).
- **One successful snooze per raid event.** Optional single retry only on transient GET/POST failure, still capped at one success.
- **Full dashboard i18n.** New strings land in every language pack that already has the ad-notice keys: `en.php`, `de.php`, `fr.php`, `es.php`, and `zh.php`.
- **No new test harness.** Verification is a resolution walk-through and manual live checks, not a new framework.

## File map

| Area | Files |
|------|--------|
| Schema + migrate | `dashboard/includes/usr_database.php` |
| Load settings UI | `dashboard/modules.php` |
| Save settings | `dashboard/api/module_data_post.php` |
| API mirror (if used) | `dashboard/api/module_data.php` |
| i18n | `dashboard/lang/en.php`, `de.php`, `fr.php`, `es.php`, `zh.php` |
| Bot runtime | `bot/beta.py`, `bot/beta-v6.py` |

## Work items

### 1. Schema: four new columns on `ad_notice_settings`

In `dashboard/includes/usr_database.php`:

- Add to the CREATE TABLE definition for `ad_notice_settings`: `enable_raid_ad_snooze` (TINYINT default 1), `raid_ad_snooze_window_minutes` (INT default 10), `enable_raid_ad_snooze_message` (TINYINT default 1), `raid_ad_snooze_message` (VARCHAR 255).
- Extend the default-row INSERT so new databases seed those four values (message default: `Snoozed the next ad for the raid from (user).`).
- Add SHOW COLUMNS + ALTER migrations in the same style as the existing 1-minute / granular ad toggles, plus a backfill for empty `raid_ad_snooze_message` like the generic snoozed message.

### 2. Dashboard load + form UI

In `dashboard/modules.php`:

- Defaults for the four fields when load fails (feature and message on, window 10, English default message).
- Extend the SELECT / bind / assignment that already loads ad notice settings.
- After the generic Ad Snoozed Message block and before the global Enable Ad Notice toggle, add a Raid auto-snooze subsection: feature toggle, window number input (1–30), message toggle + textarea (max 255, char-count), help via `t()`.

Match existing Modules markup and `sp-*` classes.

### 3. Dashboard save handler

In `dashboard/api/module_data_post.php`, in the ad-notice POST branch:

- Read the four fields (checkboxes as 0/1 when unset).
- Clamp window to 1–30 (invalid or missing → 10); truncate message to 255.
- Extend the INSERT … ON DUPLICATE KEY UPDATE and bound parameters so insert and update halves stay aligned.

If `module_data.php` exposes ad notice fields, keep those four keys in sync with the same defaults.

### 4. Translation keys

Add the raid auto-snooze keys next to the existing `modules_ad_*` block in **all five** language files: `en.php`, `de.php`, `fr.php`, `es.php`, `zh.php`.

Keys cover: section title, enable + help, window + help, message + placeholder, variables note for `(user)` / `(viewers)` / `(minutes)`, and a longer note about one charge and ~5 minute push. French and Spanish follow the same apostrophe / wording conventions as the rest of those files.

### 5. Bot settings cache

In both `bot/beta.py` and `bot/beta-v6.py`, extend every path that builds `ad_settings_cache` (DB hit, empty-row defaults, exception fallback) with the four raid keys and defaults from the spec. Coerce the window to int and clamp 1–30 when reading.

### 6. Bot-initiated snooze marker

Near the other ad globals: a UTC “until” timestamp plus mark / is-active helpers (about 60 seconds). In `check_and_handle_ads`, when `snooze_count` drops, skip the generic `ad_snoozed_message` while the marker is active, but still update the last snooze count and return path as today.

### 7. Core helper `maybe_snooze_ad_for_raid`

Module-level async helper beside the other ad helpers in both beta files.

1. Settings off or stream offline → return.
2. Clamp window; GET ad schedule with the same headers as the poller.
3. No next ad, no snoozes, or outside window → return (no retry).
4. POST snooze once; on 200 mark bot-initiated, log, optionally chat with `(user)`, `(viewers)`, and pre-snooze `(minutes)`.
5. 400 / 429 → log, no chat, no retry.
6. Network / 5xx → close the HTTP session, wait ~15 seconds, one full retry.
7. Never raise into the raid task; never hold the raid MySQL connection across Helix calls.

Mirror behaviour in v6; use each file’s task helper and logger conventions. Add `normalize_next_ad_at` in v6 if it is missing there.

### 8. Wire into `process_raid_event`

At the end of successful **incoming** raid handling, schedule the helper (beta: tracked background task; v6: same local task style as other raid work). Outgoing raids must not call it.

### 9. Public changelog (optional)

If recording beta behaviour for the frozen tracks, a short bullet in `docs/5.8.md` (and any matching v6 public notes) is enough. Do not bump version strings.

## Verification

- Schema appears after a dashboard load; defaults match the spec.
- Ad Notices can save and reload all four settings; out-of-range window values clamp.
- Raid with next ad outside the window: skip only, no snooze POST.
- Live success path: one Helix snooze, one raid chat line if enabled, no generic snoozed chat for that drop, poller follows the new `next_ad_at`.
- Feature off and 429 paths: no crash, no chat spam.
- Stable bot unchanged; raid auto-snooze copy present in en/de/fr/es/zh.

## Deployment note

Ship dashboard schema and Modules UI before or with the bots so columns and user toggles exist. Loading any dashboard page runs the schema manager migrations. Prefer migrating first so bot defaults do not override a streamer’s saved off state after the columns land. Recycle beta / v6 processes after upload; re-auth only if a token predates the ads scopes.
