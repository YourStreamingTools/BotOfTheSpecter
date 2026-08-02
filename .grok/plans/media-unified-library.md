# Unified Media Library ΓÇö Finish Build Plan

Status: Draft. Last revised 2026-05-25.

## 1. Scope

The unified media library is partially built. Files are stored once at `/var/www/media/{username}/` (server) and reused across triggers (sound alerts, video alerts, twitch event sounds, alert media, walkons). The schema already supports a single file being mapped to many triggers, but the UI and walkon system have gaps that stop streamers from actually using that capability.

**In scope** (the work this plan covers):

- Convert `./dashboard/media.php` from a 1:1 fileΓåÆtrigger UI to a 1:N fileΓåÆ[triggers] UI for Sound Alerts, Video Alerts, and Twitch Events.
- Add a new `walkons` table to `./dashboard/usr_database.php` and rewrite the Walkons experience to use it, replacing the current filename-matching scheme (`{twitch_username}.mp3`) with database-driven userΓåÆfile tags.
- Extend `./dashboard/migrate_media.php` to also copy MP4 video alerts and walkon files, and (optionally) auto-create `walkons` rows from existing filename matches.

**Explicitly out of scope** (separate work, flagged):

- Bot-side changes in `./bot/beta.py` and `./bot/bot.py` to read walkon mappings from the new `walkons` table instead of building URLs from filename matching. See ┬º6.
- Storage-usage cleanup (deleting old soundalerts/walkons/videoalerts dirs once migration is complete). The migration script copies rather than moves, so disk usage roughly doubles per migrated user until cleanup happens.
- Re-running migration after the first success. There's no UI path for it today, and the schema is idempotent, so this is fine for v1.

## 2. Current state inventory

A snapshot of what's already in place, so the plan extends rather than reinvents.

### Database (per-user DB)

| Table | Schema | Today's UI behaviour |
|---|---|---|
| `sound_alerts` | `reward_id PK, sound_mapping TEXT` | PK is on `reward_id`, so the schema **already** allows the same `sound_mapping` value across many rows. The UI ignores this. |
| `video_alerts` | `reward_id PK, video_mapping TEXT` | Same ΓÇö PK on reward, file column is free-form. |
| `twitch_sound_alerts` | `twitch_alert_id PK, sound_mapping TEXT` | Same ΓÇö PK on event id, file column is free-form. |
| `walkons` | **does not exist** | Walkons are inferred from files named `/var/www/walkons/{channel}/{twitch_username}.mp3` (server). No DB backing. |
| `profile.media_migrated` | `TINYINT(1) DEFAULT 0` | Gates the migrate button vs the "Using Unified Media Library" notice. |

### Filesystem (server)

- **Old (pre-migration)** ΓÇö separate per-trigger dirs:
  - `/var/www/soundalerts/{user}/` ΓÇö MP3 channel-point sounds
  - `/var/www/soundalerts/{user}/twitch/` ΓÇö MP3 twitch event sounds
  - `/var/www/videoalerts/{user}/` ΓÇö MP4 video alerts
  - `/var/www/walkons/{user}/` ΓÇö MP3 walkons named after Twitch usernames
- **New (post-migration)** ΓÇö single unified dir:
  - `/var/www/media/{user}/` ΓÇö all MP3 + MP4 files

### `./dashboard/media.php` ΓÇö the UI bug

The page builds its sound-alert mappings into a dictionary keyed by filename, e.g. `$soundAlertMappings[$sound_mapping] = $reward_id;`. Because the key is the filename, if the DB holds two rows pointing at `shared.mp3` (one for the "1st" reward and one for the "I'm here" reward), the second row's `reward_id` overwrites the first. The UI then shows `shared.mp3` mapped to only one of the two rewards. The other mapping is invisible and unmanageable from the dashboard.

There's also no "+ Add another reward to this file" capability today ΓÇö each file row carries a single `<select>` for one reward. To map `shared.mp3` to both "1st" and "I'm here", a streamer currently has to upload it twice under different filenames.

### `./dashboard/migrate_media.php` ΓÇö what it covers today

- Copies `*.mp3` from `/var/www/soundalerts/{user}/` and `/var/www/soundalerts/{user}/twitch/` into `/var/www/media/{user}/`.
- Sets `profile.media_migrated = 1`.
- **Does not** copy MP4 video alerts.
- **Does not** copy walkon files.
- **Does not** create any walkon DB rows.

## 3. UI refactor ΓÇö master/detail with per-file modal

The plan replaces the five-tab table structure entirely. The new shape:

- A **single compact file list** with no category tabs.
- **Clicking a filename opens a modal** scoped to that one file. The modal is where every mapping (rewards, events, walkons, alert builder) is managed.
- The filename-click + modal is the **only** path to add/remove mappings, which keeps the list itself uncluttered.

### 3.1 Main view layout

```
Storage bar          (existing, unchanged)
Upload card          (existing, unchanged)
Migration card       (existing, button gets wired up ΓÇö see ┬º5)
Filter bar           [All] [With rewards] [With events] [Walkons] [Unused] [Videos]   ≡ƒöì [Search filesΓÇª]
File list:
  ΓÿÉ  shared.mp3       MP3 ┬╖ 2.4 MB  ┬╖  3 mappings        [≡ƒùæ] [Γû╢]
  ΓÿÉ  hello.mp3        MP3 ┬╖ 1.1 MB  ┬╖  Unused             [≡ƒùæ] [Γû╢]
  ΓÿÉ  intro.mp4        MP4 ┬╖ 8.7 MB  ┬╖  1 mapping          [≡ƒùæ] [Γû╢]
  ΓÇª
[Delete selected]    (enabled when ΓëÑ2 checked, existing pattern)
```

- The **filename is a button** (keyboard-accessible) that opens the modal.
- The **"3 mappings" summary** sums across all trigger types: rewards + events + walkons. A hover/title attribute breaks it down ("2 rewards, 1 event"). For zero mappings, the row shows `Unused` as a muted badge so streamers can spot orphan files for cleanup.
- The **filter bar** acts as lenses over the same file list rather than separate categories:
  - `All` ΓÇö every file (default)
  - `With rewards` ΓÇö files mapped to ΓëÑ1 channel point reward (sound or video)
  - `With events` ΓÇö files mapped to ΓëÑ1 twitch event
  - `Walkons` ΓÇö files used as ΓëÑ1 user's walkon
  - `Unused` ΓÇö zero mappings anywhere
  - `Videos` ΓÇö `.mp4` only
  - Plus a text input for substring search on filename.
- **Inline action buttons** (delete one, test playback) stay on the row; they don't need the modal.

### 3.2 The per-file modal

The modal is the single source of truth for "what does this file do?". Section visibility adapts to the file type.

```
ΓöîΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÉ
Γöé  shared.mp3                                            [├ù] Γöé
Γöé  MP3 ┬╖ 2.4 MB                                              Γöé
Γöé  ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ  Γöé
Γöé                                                             Γöé
Γöé  Channel Point Rewards                                      Γöé
Γöé    [1st ├ù] [I'm here ├ù]                                    Γöé
Γöé    [+ Add reward Γû╛]                                        Γöé
Γöé                                                             Γöé
Γöé  Twitch Events                                              Γöé
Γöé    [Follow ├ù]                                              Γöé
Γöé    [+ Add event Γû╛]                                         Γöé
Γöé                                                             Γöé
Γöé  Walkons                                                    Γöé
Γöé    [@bandit ├ù]                                             Γöé
Γöé    [+ Add user Γû╛]                                          Γöé
Γöé                                                             Γöé
Γöé  Used by Alert Builder         (read-only)                  Γöé
Γöé    Sub alert ┬╖ Default variant                              Γöé
Γöé                                                             Γöé
Γöé                                          [Γû╢ Test] [Close]  Γöé
ΓööΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÿ
```

**Section adaptation by file type:**

- **MP3 file**: shows Channel Point Rewards (`sound_alerts`), Twitch Events (`twitch_sound_alerts`), Walkons, and Alert Builder.
- **MP4 file**: shows Channel Point Rewards via `video_alerts` ΓÇö relabel that section header "Channel Point Rewards (Video)" so it's unambiguous ΓÇö plus Alert Builder. There's no Twitch Events section (no MP4 events exist today) and no Walkons section (walkons are audio).
- Empty sections still render their `[+ Add reward Γû╛]` so the streamer can attach to a file that has zero mappings. Only the "Used by Alert Builder" section hides entirely when empty, because it's read-only and there's nothing to attach.

**Modal mechanics:**

- Use the existing Bulma-style / sp-modal pattern already present in the dashboard.
- Open via JS with no full page navigation.
- Add/remove operations POST via AJAX. On success, refresh **only** the modal contents (re-render this file's mappings from the handler response) and the "3 mappings" summary on the underlying row. No `location.reload()`.
- Close on `├ù`, the Escape key, or a backdrop click.

### 3.3 Data-loading change

`media.php` keeps loading all mapping tables on page render so the modal can open against pre-loaded data without an extra round trip. The key fix is to stop overwriting: instead of assigning a single `reward_id` per filename, collect a list per filename (the same flip applies to video alerts and twitch events, plus the new walkons load described in ┬º4). Conceptually that's the move from `$mappings[$file] = $id` to `$mappings[$file][] = $id`, which is what unlocks the 1:N behaviour.

All mapping data ships to the page as a single JSON blob so the modal renders without an extra AJAX hop on open. The shape is roughly:

```
window.__MEDIA_MAPPINGS = {
  sound_alerts, video_alerts, twitch_events, walkons,
  alert_builder, rewards, twitch_events_list, reward_titles
}
```

Each of those is keyed by file (for the mapping lists) or is a lookup table (rewards, event list, reward-idΓåÆtitle) the modal needs to render friendly labels.

### 3.4 POST handlers

Rather than today's "if exists update, else insert, else delete" mega-handler, use three explicit verbs per trigger type. The request contract:

| Trigger | Add | Remove |
|---|---|---|
| Channel point reward (sound) | `media_type=sound_alert_mapping&action=add&sound_file=X&reward_id=R` | `media_type=sound_alert_mapping&action=remove&reward_id=R` |
| Channel point reward (video) | `media_type=video_alert_mapping&action=add&video_file=X&reward_id=R` | `media_type=video_alert_mapping&action=remove&reward_id=R` |
| Twitch event | `media_type=twitch_event_mapping&action=add&sound_file=X&twitch_alert_id=E` | `media_type=twitch_event_mapping&action=remove&twitch_alert_id=E` |
| Walkon | `media_type=walkon_mapping&action=add&media_file=X&twitch_user_id=U&twitch_user_name=N` | `media_type=walkon_mapping&action=remove&twitch_user_id=U` |

Each ADD is an upsert (`INSERT ... ON DUPLICATE KEY UPDATE`) keyed by the PK (`reward_id` / `twitch_alert_id` / `twitch_user_id`), so the same call also handles "change which file this trigger uses" atomically.

All four handlers return JSON of the form `{success, mappings, error}`, where `mappings` is the updated mapping list for this file. The modal uses that returned list to re-render its sections without reloading the page.

### 3.5 CSS additions

The new file list, filter bar, and mapping chips need styling in `./dashboard/css/dashboard.css`, reusing the existing theme tokens rather than inventing colours. The pieces:

- A **file-row** grid layout (checkbox, filename button, meta, summary, action buttons) with a bottom border separator.
- The **filename button** styled as an accent-coloured, underlined, transparent button so it reads as a link but stays keyboard-accessible; muted styling for the meta line and for the "Unused" summary state.
- A **filter bar** row of ghost-style buttons with an active state (accent background/border) and a constrained-width search input.
- **Mapping chips** in the modal: pill-shaped accent chips with a small inline remove button that turns red on hover, an "empty" muted placeholder for sections with no mappings yet, and a read-only chip variant (neutral background, no pointer cursor) for the Alert Builder section.

## 4. Walkons rebuild

### 4.1 New table

Add a `walkons` table to the `$tables` array in `./dashboard/usr_database.php`, alongside the other per-user CREATE TABLE statements, so it auto-deploys to every per-user DB:

```sql
CREATE TABLE IF NOT EXISTS walkons (
    twitch_user_id VARCHAR(50) NOT NULL,
    twitch_user_name VARCHAR(100) NOT NULL,
    media_file VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (twitch_user_id),
    INDEX idx_media_file (media_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

Semantics:

- PK on `twitch_user_id` means **one walkon per user**, which is correct ΓÇö a viewer has one greeting sound.
- `media_file` is only indexed, not unique, so **one file can be the walkon for many users**. That's the 1:N benefit the streamer is asking for.
- `twitch_user_name` is denormalized for display, so the walkons UI can render without a cross-DB join to `seen_users` on every page render.

### 4.2 Walkons in the per-file modal

The current standalone Walkons tab in `media.php` is **removed entirely**. Walkons become one section of the per-file modal (see ┬º3.2): the streamer clicks a file in the unified list, the modal opens, and the Walkons section shows the current user chips plus an "Add user" picker.

The data load happens once per page render, building a `media_file ΓåÆ [{user_id, user_name}, ΓÇª]` map from the `walkons` table and shipping it with the rest of the mapping data via `window.__MEDIA_MAPPINGS` (┬º3.3).

The "+ Add user" picker is a **combined typeahead + free-form** input (decided):

- As the streamer types, autocomplete from `seen_users` (cached locally ΓÇö users who have chatted in the channel).
- If the input doesn't match any cached user, a "Use this exact Twitch username" affordance appears that triggers a Helix lookup at submit time to resolve `login ΓåÆ user_id` and insert the row.
- Both paths post to the same `walkon_mapping&action=add` handler with the resolved `twitch_user_id` and `twitch_user_name`.

The Walkons filter at the top of the unified list narrows to "files used as ΓëÑ1 walkon" ΓÇö that's the only place the old "walkons tab" concept survives.

### 4.3 POST handlers

The add handler is an upsert: `INSERT INTO walkons (...) VALUES (...) ON DUPLICATE KEY UPDATE media_file = VALUES(media_file), twitch_user_name = VALUES(twitch_user_name)`. Because the PK is `twitch_user_id`, that single statement also covers "change a user's walkon to a different file". The remove handler is a `DELETE FROM walkons WHERE twitch_user_id = ?`. Both use parameterized queries.

### 4.4 Helix lookup endpoint (for free-form username add)

Add a small endpoint (or piggyback on an existing one), `./dashboard/lookup_twitch_user.php?login=...`, that returns `{user_id, user_name}` via Helix. The add-user typeahead calls it when the streamer types a name not present in `seen_users`. Auth reuses the streamer's existing bot/app OAuth token, the same as other dashboard Helix calls.

## 5. Migration script extensions (`./dashboard/migrate_media.php`)

### 5.1 Copy additional file types

Extend the copy loop so it also pulls in MP4 video alerts and walkon MP3s. Rather than a hardcoded MP3 allowlist, drive the loop from a list of source dirs each carrying its own allowed extensions:

| Source dir (server) | Extensions |
|---|---|
| `/var/www/soundalerts/{user}/` | mp3 |
| `/var/www/soundalerts/{user}/twitch/` | mp3 |
| `/var/www/videoalerts/{user}/` | mp4 |
| `/var/www/walkons/{user}/` | mp3 |

The existing copy logic stays the same per file; it just iterates this list and respects each entry's extension allowlist, skipping any source dir that doesn't exist.

### 5.2 Auto-create walkon rows (see ┬º8)

If the streamer opts in, scan `/var/www/walkons/{username}/` and, for each `{login}.mp3`:

1. Look up `{login}` in `seen_users` to grab a cached `user_id` if we already have it.
2. If it's not in `seen_users`, call Helix to resolve `login ΓåÆ user_id`.
3. Insert a row into `walkons (twitch_user_id, twitch_user_name, media_file)`.

This is best-effort: failures are warnings, not fatal errors. The streamer can finish tagging manually in the new UI.

## 6. Bot-side impact (flagged, out of scope for this plan)

When this lands, the bot's walkon player needs to query the new table instead of building URLs from filename matching. Today it constructs the audio URL directly from the channel and the user login (`.../{channel}/{user_login}.mp3`). After this change it should look the user up in the `walkons` table by `twitch_user_id`, and, if a row exists, build the media URL from the stored `media_file` against the unified media host.

This needs to land in `beta.py` and `bot.py` (the latter likely as a critical-fix-style port once stable). The dashboard work in this plan is safe to ship first: the bot keeps using filename-based walkons for un-migrated users, and for migrated users the dashboard becomes the source of truth.

Sound alert / video alert / twitch event reads on the bot side already query their respective tables, so nothing changes there beyond no longer assuming `(reward_id, sound_mapping)` is unique on `sound_mapping`.

## 7. Implementation order

The work is sequenced so each stage is independently shippable, and so the riskiest pattern gets validated once before it's repeated.

1. **Schema first** ΓÇö add the `walkons` table to `./dashboard/usr_database.php`. It auto-deploys to all per-user DBs via the existing CREATE TABLE IF NOT EXISTS loop.
2. **Chip UI for sound alerts** ΓÇö take a single trigger type end-to-end (data load, POST handlers, render, JS, CSS) to validate the pattern before applying it more widely.
3. **Apply the chip UI to video alerts and twitch events** ΓÇö a mechanical copy of the sound-alerts pattern once it's proven.
4. **Walkons rewrite** ΓÇö depends on the schema from step 1.
5. **Migration script extensions** ΓÇö MP4 + walkon file copy, and (if confirmed in ┬º8) auto-creation of walkon rows.
6. **Bot-side port** ΓÇö a separate session, after the dashboard side is live.

Steps 1 and 2 alone deliver the headline win: one upload, many rewards.

## 8. Decisions log

Resolved:

1. **Auto-create walkon rows during migration** ΓÇö yes. Migration scans `/var/www/walkons/{user}/{login}.mp3`, resolves `login ΓåÆ user_id` via Helix (or the `seen_users` cache), and inserts a `walkons` row per file. Existing walkons keep working without the streamer re-tagging.
2. **"+ Add user" picker** ΓÇö combined typeahead + free-form. Autocomplete against `seen_users` for the common case, with a free-form Twitch username and Helix-lookup-at-save-time fallback so streamers can pre-set walkons for viewers who haven't chatted yet.
3. **Tabs vs unified list** ΓÇö no tabs. A single file list with a filter bar (lenses, not categories), and all of a file's mappings managed inside a per-file modal opened by clicking the filename. This removes the divided mental model the new system is meant to escape.
4. **Modal vs inline/side-panel editing** ΓÇö modal. Clicking a filename opens a focused mapping editor scoped to that one file, which keeps the main list compact and supports the "click ΓåÆ assign ΓåÆ close" flow naturally.

Still open:

5. **Old-directory cleanup** ΓÇö should a post-migration step delete the contents of `/var/www/soundalerts/{user}/`, `/var/www/videoalerts/{user}/`, and `/var/www/walkons/{user}/` once the unified library is populated? Recommendation: not in this plan ΓÇö too destructive to bundle with the rest. Add it later as a separate "verified migration, free up storage" button in the dashboard.
6. **Storage-usage display post-migration** ΓÇö the storage bar currently sums all four old paths plus the unified one, so after migration the displayed total roughly doubles until cleanup (decision 5) happens. Should the bar switch to "unified path only" once `media_migrated = 1`? Recommendation: yes, gate it on the flag ΓÇö count only the unified media path when migrated, and the four-path sum otherwise.
7. **Filter set at launch** ΓÇö the proposed filters are `All / With rewards / With events / Walkons / Unused / Videos`. Should we add more (file-type breakouts for `MP3 / MP4`, sortable columns for size or upload date)? Recommendation: ship the six listed and add more on demand.
