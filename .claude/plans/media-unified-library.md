# Unified Media Library — Finish Build Plan

> Status: **draft**, awaiting decisions (see end of doc).
> Owner: TBD. Last revised 2026-05-25.

## 1. Scope

The unified media library is partially built. Files are stored once at `/var/www/media/{username}/` and reused across triggers (sound alerts, video alerts, twitch event sounds, alert media, walkons). The schema already supports a single file being mapped to many triggers, but the UI and walkon system have gaps that prevent streamers from actually using that capability.

**In scope** (the work this plan covers):

- Convert `./dashboard/media.php` from a 1:1 file→trigger UI to a 1:N file→[triggers] UI for Sound Alerts, Video Alerts, and Twitch Events tabs.
- Add a new `walkons` table to `./dashboard/usr_database.php` and rewrite the Walkons tab to use it, replacing the current filename-matching scheme (`{twitch_username}.mp3`) with database-driven user→file tags.
- Extend `./dashboard/migrate_media.php` to also copy MP4 video alerts and walkon files, and (optionally) auto-create `walkons` rows from existing filename matches.

**Explicitly out of scope** (separate work, flagged):

- Bot-side changes in `./bot/beta.py` and `./bot/bot.py` to read walkon mappings from the new `walkons` table instead of building URLs from filename matching. See §6.
- Storage-usage cleanup (deleting old soundalerts/walkons/videoalerts dirs once migration is complete). The migration script currently copies, so disk usage roughly doubles per migrated user until cleanup is done.
- Re-running migration after the first success (no UI path today, schema is idempotent — fine for v1).

## 2. Current state inventory

What's already in place, so the plan extends rather than reinvents.

### Database (per-user DB)

| Table | Schema | Today's UI behaviour |
|---|---|---|
| `sound_alerts` | `reward_id PK, sound_mapping TEXT` | PK on `reward_id` — schema **already** allows the same `sound_mapping` value across many rows. UI ignores this. |
| `video_alerts` | `reward_id PK, video_mapping TEXT` | Same — PK on reward, file column is free-form. |
| `twitch_sound_alerts` | `twitch_alert_id PK, sound_mapping TEXT` | Same — PK on event id, file column is free-form. |
| `walkons` | **does not exist** | Walkons are inferred from files named `/var/www/walkons/{channel}/{twitch_username}.mp3`. No DB. |
| `profile.media_migrated` | `TINYINT(1) DEFAULT 0` | Gates the migrate button vs the "Using Unified Media Library" notice. |

### Filesystem

- **Old (pre-migration)** — separate per-trigger dirs:
  - `/var/www/soundalerts/{user}/` — MP3 channel-point sounds
  - `/var/www/soundalerts/{user}/twitch/` — MP3 twitch event sounds
  - `/var/www/videoalerts/{user}/` — MP4 video alerts
  - `/var/www/walkons/{user}/` — MP3 walkons named after Twitch usernames
- **New (post-migration)** — single unified dir:
  - `/var/www/media/{user}/` — all MP3 + MP4 files

### `./dashboard/media.php` — the UI bug

```php
// Line 53-60
$soundAlertMappings = [];
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping] = $reward_id;  // ← overwrites!
}
```

The dict is keyed by filename, so if the DB has two rows pointing at `shared.mp3` (one for the "1st" reward and one for the "I'm here" reward), the second row's `reward_id` overwrites the first. The UI then shows `shared.mp3` mapped to only one of the two rewards. The other mapping is invisible and unmanageable from the dashboard.

The "+ Add another reward to this file" capability doesn't exist in the UI today — each file row has a single `<select>` for one reward. To map `shared.mp3` to both "1st" and "I'm here", a streamer currently has to upload it twice with different filenames.

### `./dashboard/migrate_media.php` — what it covers today

- Copies `*.mp3` from `/var/www/soundalerts/{user}/` and `/var/www/soundalerts/{user}/twitch/` into `/var/www/media/{user}/`.
- Sets `profile.media_migrated = 1`.
- **Does not** copy MP4 video alerts.
- **Does not** copy walkon files.
- **Does not** create any walkon DB rows.

## 3. UI refactor — master/detail with per-file modal

Replace the five-tab table structure entirely. New shape:

- **Single compact file list** (no category tabs).
- **Click a filename → opens a modal** scoped to that one file. The modal is where all mappings (rewards, events, walkons, alert builder) are managed.
- Filename click + modal is the **only** path to add/remove mappings. The list itself stays uncluttered.

### 3.1 Main view layout

```
Storage bar          (existing, unchanged)
Upload card          (existing, unchanged)
Migration card       (existing, button gets wired up — see §5)
Filter bar           [All] [With rewards] [With events] [Walkons] [Unused] [Videos]   🔍 [Search files…]
File list:
  ☐  shared.mp3       MP3 · 2.4 MB  ·  3 mappings        [🗑] [▶]
  ☐  hello.mp3        MP3 · 1.1 MB  ·  Unused             [🗑] [▶]
  ☐  intro.mp4        MP4 · 8.7 MB  ·  1 mapping          [🗑] [▶]
  …
[Delete selected]    (enabled when ≥2 checked, existing pattern)
```

- **Filename is a `<button class="media-file-open">`** — keyboard-accessible, opens the modal.
- **"3 mappings" summary** — sum across all trigger types: rewards + events + walkons. Hover/title attribute breaks it down ("2 rewards, 1 event"). For zero mappings, show `Unused` as a muted badge so streamers can find orphan files for cleanup.
- **Filter bar** acts as lenses over the same file list:
  - `All` — every file (default)
  - `With rewards` — files mapped to ≥1 channel point reward (sound or video)
  - `With events` — files mapped to ≥1 twitch event
  - `Walkons` — files used as ≥1 user's walkon
  - `Unused` — zero mappings anywhere
  - `Videos` — `.mp4` only
  - Plus a text input for substring search on filename.
- **Inline action buttons** (delete one, test playback) stay on the row — they don't need the modal.

### 3.2 The per-file modal

Triggered by clicking the filename. Single source of truth for "what does this file do?". Section visibility adapts to file type:

```
┌─────────────────────────────────────────────────────────────┐
│  shared.mp3                                            [×] │
│  MP3 · 2.4 MB                                              │
│  ─────────────────────────────────────────────────────────  │
│                                                             │
│  Channel Point Rewards                                      │
│    [1st ×] [I'm here ×]                                    │
│    [+ Add reward ▾]                                        │
│                                                             │
│  Twitch Events                                              │
│    [Follow ×]                                              │
│    [+ Add event ▾]                                         │
│                                                             │
│  Walkons                                                    │
│    [@bandit ×]                                             │
│    [+ Add user ▾]                                          │
│                                                             │
│  Used by Alert Builder         (read-only)                  │
│    Sub alert · Default variant                              │
│                                                             │
│                                          [▶ Test] [Close]  │
└─────────────────────────────────────────────────────────────┘
```

**Section adaptation by file type:**
- **MP3 file**: shows Channel Point Rewards (`sound_alerts`), Twitch Events (`twitch_sound_alerts`), Walkons, Alert Builder.
- **MP4 file**: shows Channel Point Rewards via `video_alerts` (relabel section header to "Channel Point Rewards (Video)" so it's unambiguous), Alert Builder. No Twitch Events section (no MP4 events today), no Walkons section (walkons are audio).
- Empty sections (file is MP3 but has zero reward mappings) still render the `[+ Add reward ▾]` so the streamer can attach. Only the "Used by Alert Builder" section hides entirely when empty (it's read-only — nothing to do with an empty slot).

**Modal mechanics:**
- Bulma-style or sp-modal pattern already used elsewhere in the dashboard.
- Open via JS, no full page navigation.
- Add/remove operations POST via AJAX; on success, refresh **only the modal contents** (re-fetch this file's mappings) and the "3 mappings" summary on the underlying row. No `location.reload()`.
- Closes on `×`, Escape key, or backdrop click.

### 3.3 Data-loading change

`media.php` still loads all mapping tables on page render — the modal uses pre-loaded data on open (no extra round trip):

```php
// Before (line 53-60)
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping] = $reward_id;  // overwrites!
}

// After — collect into list per file
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping][] = $reward_id;
}
```

Same flip for `$videoAlertMappings` and `$twitchSoundAlertMappings`. Plus a new load for walkons (see §4).

All mapping data ships to the page as JSON so the modal can render without an extra AJAX hop on open:

```php
echo '<script>window.__MEDIA_MAPPINGS = ' . json_encode([
    'sound_alerts'   => $soundAlertMappings,
    'video_alerts'   => $videoAlertMappings,
    'twitch_events'  => $twitchSoundAlertMappings,
    'walkons'        => $walkonsByFile,
    'alert_builder'  => $alertMediaFiles,
    'rewards'        => $channelPointRewards,
    'twitch_events_list' => $allEvents,
    'reward_titles'  => $rewardIdToTitle,
]) . ';</script>';
```

### 3.4 POST handlers

Three explicit verbs per trigger type (replacing today's "if exists update, else insert, else delete" mega-handler):

| Trigger | Add | Remove |
|---|---|---|
| Channel point reward (sound) | `media_type=sound_alert_mapping&action=add&sound_file=X&reward_id=R` | `media_type=sound_alert_mapping&action=remove&reward_id=R` |
| Channel point reward (video) | `media_type=video_alert_mapping&action=add&video_file=X&reward_id=R` | `media_type=video_alert_mapping&action=remove&reward_id=R` |
| Twitch event | `media_type=twitch_event_mapping&action=add&sound_file=X&twitch_alert_id=E` | `media_type=twitch_event_mapping&action=remove&twitch_alert_id=E` |
| Walkon | `media_type=walkon_mapping&action=add&media_file=X&twitch_user_id=U&twitch_user_name=N` | `media_type=walkon_mapping&action=remove&twitch_user_id=U` |

Each ADD uses `INSERT ... ON DUPLICATE KEY UPDATE` keyed by the PK (`reward_id` / `twitch_alert_id` / `twitch_user_id`) so the same call also handles "change which file this trigger uses" atomically.

All four handlers return JSON: `{success: bool, mappings: {updated mapping list for this file}, error: null}`. The modal uses the returned `mappings` to re-render its sections without reloading the page.

### 3.5 CSS additions

In `./dashboard/css/dashboard.css`:

```css
/* File list */
.media-file-row { display: grid; grid-template-columns: auto 1fr auto auto auto auto; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.media-file-name {
    background: transparent; border: none; padding: 0; cursor: pointer;
    color: var(--accent); text-decoration: underline; font-family: inherit;
    font-size: inherit; text-align: left;
}
.media-file-name:hover { color: var(--accent-hover); }
.media-file-meta { color: var(--text-muted); font-size: 0.85em; }
.media-file-summary { color: var(--text-secondary); font-size: 0.9em; }
.media-file-summary.is-unused { color: var(--text-muted); }

/* Filter bar */
.media-filter-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 12px 0; }
.media-filter-btn { /* uses sp-btn-ghost base */ }
.media-filter-btn.is-active { background: var(--accent-light); color: var(--accent); border-color: var(--accent); }
.media-search-input { max-width: 260px; }

/* Modal mapping chips */
.media-modal-section { margin-bottom: 18px; }
.media-modal-section-title { font-weight: 600; color: var(--text-primary); margin-bottom: 6px; font-size: 0.95em; }
.mapping-chips { display: inline-flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.mapping-chip {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--accent-light); color: var(--accent);
    padding: 3px 10px; border-radius: var(--radius-pill);
    font-size: 0.85em;
}
.mapping-chip-remove {
    background: transparent; border: none; color: inherit;
    cursor: pointer; font-size: 1.1em; line-height: 1; padding: 0 2px;
}
.mapping-chip-remove:hover { color: var(--red); }
.mapping-add-select { max-width: 240px; }
.mapping-empty { color: var(--text-muted); display: inline-block; margin-bottom: 6px; }
.media-modal-section-readonly .mapping-chip { background: var(--bg-card-hover); color: var(--text-secondary); cursor: default; }
```

## 4. Walkons rebuild

### 4.1 New table

Add to `./dashboard/usr_database.php` in the `$tables` array (alongside the other CREATE TABLE statements around line 423-440):

```sql
'walkons' => "
    CREATE TABLE IF NOT EXISTS walkons (
        twitch_user_id VARCHAR(50) NOT NULL,
        twitch_user_name VARCHAR(100) NOT NULL,
        media_file VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (twitch_user_id),
        INDEX idx_media_file (media_file)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
```

Semantics:
- PK on `twitch_user_id` — **one walkon per user** (correct: a viewer has one greeting sound).
- `media_file` is just an index — **one file can be the walkon for many users** (the 1:N benefit the streamer is asking for).
- `twitch_user_name` denormalized for display so the walkons tab can render without a cross-DB join to `seen_users` on every page render.

### 4.2 Walkons in the per-file modal

The current Walkons tab (lines 638-689 in media.php) is **removed entirely**. Walkons become one section of the per-file modal (see §3.2). The streamer clicks a file in the unified list → modal opens → Walkons section shows current user chips + an "Add user" picker.

Data load — once per page render, build `$walkonsByFile = [media_file => [{user_id, user_name}, ...]]` and ship it with the rest of the mapping data via `window.__MEDIA_MAPPINGS` (§3.3):

```php
$walkonsByFile = [];
$walkonsResult = $db->query("SELECT twitch_user_id, twitch_user_name, media_file FROM walkons");
if ($walkonsResult) {
    while ($row = $walkonsResult->fetch_assoc()) {
        $walkonsByFile[$row['media_file']][] = [
            'user_id' => $row['twitch_user_id'],
            'user_name' => $row['twitch_user_name'],
        ];
    }
    $walkonsResult->free();
}
```

The "+ Add user" picker is a **combined typeahead + free-form** input (decided):
- As the streamer types, autocomplete from `seen_users` (cached locally; users who have chatted in the channel).
- If their input doesn't match any cached user, an "Use this exact Twitch username" affordance appears that triggers a Helix lookup at submit time to resolve `login → user_id` and inserts the row.
- Both paths post to the same `walkon_mapping&action=add` handler with the resolved `twitch_user_id` and `twitch_user_name`.

The Walkons filter at the top of the unified list lets a streamer narrow to "files used as ≥1 walkon" — that's the only place the old "walkons tab" concept survives.

### 4.3 POST handlers

```php
// Add walkon
if ($mediaType === 'walkon_mapping' && ($_POST['action'] ?? '') === 'add') {
    // payload: media_file, twitch_user_id (resolved client-side or via Helix), twitch_user_name
    $stmt = $db->prepare("INSERT INTO walkons (twitch_user_id, twitch_user_name, media_file) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE media_file = VALUES(media_file), twitch_user_name = VALUES(twitch_user_name)");
    // ...
}
// Remove walkon
if ($mediaType === 'walkon_mapping' && ($_POST['action'] ?? '') === 'remove') {
    $stmt = $db->prepare("DELETE FROM walkons WHERE twitch_user_id = ?");
    // ...
}
```

The `ON DUPLICATE KEY UPDATE` handles the "change a user's walkon to a different file" case in a single statement.

### 4.4 Helix lookup endpoint (for free-form username add)

Small new endpoint (or piggyback on an existing one) `./dashboard/lookup_twitch_user.php?login=...` that returns `{user_id, user_name}` via Helix. Used by the add-user typeahead when the streamer types a name not in `seen_users`. Auth: the streamer's existing bot/app OAuth token (same auth as other dashboard Helix calls).

## 5. Migration script extensions (`./dashboard/migrate_media.php`)

### 5.1 Copy additional file types

Add MP4 video alert files and walkon MP3 files to the copy loop (lines 44-63). The extension allowlist becomes both MP3 and MP4 depending on source dir, and the source dirs list extends:

```php
$sourceDirs = [
    ['path' => $soundalert_path,    'exts' => ['mp3']],
    ['path' => $twitch_sound_path,  'exts' => ['mp3']],
    ['path' => "/var/www/videoalerts/{$username}", 'exts' => ['mp4']],
    ['path' => "/var/www/walkons/{$username}",     'exts' => ['mp3']],
];
foreach ($sourceDirs as $src) {
    if (!is_dir($src['path'])) continue;
    foreach (scandir($src['path']) as $file) {
        // ... existing logic, using $src['exts'] instead of hardcoded ['mp3']
    }
}
```

### 5.2 Auto-create walkon rows (see §8 open decision)

If the streamer opts in, scan `/var/www/walkons/{username}/` and for each `{login}.mp3`:

1. Look up `{login}` in `seen_users` to grab `user_id` if we already have it cached.
2. If not in `seen_users`, call Helix to resolve `login → user_id`.
3. Insert into `walkons (twitch_user_id, twitch_user_name, media_file)`.

This is best-effort — failures are warnings, not fatal errors. The streamer can finish tagging manually in the new UI.

## 6. Bot-side impact (flagged, out of scope for this plan)

When this lands, the bot's walkon player needs to query the new table instead of building URLs from filename matching. Currently:

```python
# bot/beta.py (paraphrased)
audio_url = f"https://walkons.botofthespecter.com/{channel}/{user_login}.mp3"
```

After this change:

```python
async with cursor:
    await cursor.execute("SELECT media_file FROM walkons WHERE twitch_user_id = %s", (user_id,))
    row = await cursor.fetchone()
    if row:
        audio_url = f"https://media.botofthespecter.com/{channel}/{row['media_file']}"
```

This needs to land in `beta.py` and `bot.py` (the latter likely as a critical-fix-style port once stable). The dashboard work in this plan is safe to ship first — the bot will keep using filename-based walkons for un-migrated users; for migrated users, the dashboard becomes the source of truth.

Sound alert / video alert / twitch event mapping reads on the bot side already query their respective tables, so no changes needed there beyond not assuming `(reward_id, sound_mapping)` is unique on `sound_mapping`.

## 7. Implementation order

1. **Schema first** — add `walkons` table to `./dashboard/usr_database.php`. Auto-deploys to all per-user DBs via the existing CREATE TABLE IF NOT EXISTS loop.
2. **Chip UI for sound alerts** — single tab end-to-end (data load, POST handlers, render, JS, CSS). Validate the pattern on one tab before applying to two more.
3. **Apply chip UI to video alerts and twitch events** — mechanical copy of the sound alerts pattern.
4. **Walkons tab rewrite** — depends on schema (#1).
5. **Migration script extensions** — MP4 + walkons file copy, and (if confirmed in §8) auto-create walkon rows.
6. **Bot-side port** — separate session; needs the dashboard side live first.

Each stage is independently shippable. Steps 1+2 alone deliver the headline win (one upload, many rewards).

## 8. Decisions log

Resolved during planning:

1. ✅ **Auto-create walkon rows during migration** — yes. Migration scans `/var/www/walkons/{user}/{login}.mp3`, looks up `login → user_id` via Helix (or `seen_users` cache), and inserts a `walkons` row per file. Existing walkons keep working without the streamer re-tagging.
2. ✅ **"+ Add user" picker** — combined typeahead + free-form. Autocomplete against `seen_users` for common case; free-form Twitch username with Helix-lookup-at-save-time fallback so streamers can pre-set walkons for viewers who haven't chatted yet.
3. ✅ **Tabs vs unified list** — no tabs. Single file list with filter bar (lenses, not categories). All mappings for a file managed inside a per-file modal opened by clicking the filename. Removes the divided mental model the new system is meant to escape.
4. ✅ **Modal for editing vs inline/side-panel** — modal. Clicking a filename opens a focused mapping editor scoped to that one file. Keeps the main list compact and uncluttered, supports the "click → assign → close" pattern naturally.

Still open:

5. **Old-directory cleanup** — should a post-migration step delete the contents of `/var/www/soundalerts/{user}/`, `/var/www/videoalerts/{user}/`, `/var/www/walkons/{user}/` once the unified library is populated? **Recommendation:** not in this plan — too destructive to bundle with the rest. Add as a separate "verified migration, free up storage" button in the dashboard later.
6. **Storage-usage display post-migration** — `media.php:358` sums all four old paths plus the unified one, so post-migration the displayed total roughly doubles until cleanup (decision #5). Should the storage bar switch to "unified path only" when `media_migrated = 1`? **Recommendation:** yes, gate it on the flag — `calculateStorageUsed([$media_path])` when migrated, current four-path sum otherwise.
7. **Filter set at launch** — proposed filters are `All / With rewards / With events / Walkons / Unused / Videos`. Add anything else (e.g. file-type breakouts for `MP3 / MP4`, sortable columns for size or date uploaded)? **Recommendation:** ship the six listed; add more on demand.
