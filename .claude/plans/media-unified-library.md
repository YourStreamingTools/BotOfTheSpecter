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

## 3. UI refactor — 1:N mapping editor

Three tabs need the same restructure: Sound Alerts, Video Alerts, Twitch Events. Each file row gets:

```
[ ] shared.mp3          [1st ×] [I'm here ×]  [+ Add reward ▾]      [🗑] [▶]
```

### 3.1 Data-loading change

Replace overwrite-keyed dict with collect-into-list:

```php
// Before (line 53-60)
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping] = $reward_id;  // overwrites
}

// After
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping][] = $reward_id;  // collects
}
```

Same change for `$videoAlertMappings` (line 65-71) and `$twitchSoundAlertMappings` (line 75-81).

The exclusion lists (`$videoMappedRewards`, `$soundMappedRewards` at lines 99-106) need to be rebuilt to flatten across all values:

```php
$soundMappedRewards = [];
foreach ($soundAlertMappings as $rewards) {
    foreach ((array)$rewards as $rid) {
        $soundMappedRewards[] = $rid;
    }
}
```

### 3.2 POST handlers

Today there's a single combined handler that does "create if missing, update if present, delete if reward is empty". For 1:N we need three explicit verbs:

| Action | POST field | DB op |
|---|---|---|
| Add a new mapping | `media_type=sound_alert_mapping`, `action=add`, `sound_file=X`, `reward_id=R` | `INSERT INTO sound_alerts (reward_id, sound_mapping) VALUES (R, X)` (idempotent via `INSERT ... ON DUPLICATE KEY UPDATE sound_mapping = VALUES(sound_mapping)` since `reward_id` is PK) |
| Remove a mapping | `media_type=sound_alert_mapping`, `action=remove`, `reward_id=R` | `DELETE FROM sound_alerts WHERE reward_id = R` |
| (No "edit" needed) | — | A streamer changes the file a reward triggers by removing + adding |

Same shape for video_alerts (PK = reward_id) and twitch_sound_alerts (PK = twitch_alert_id).

### 3.3 Render changes per file row

Inside the `<tbody>` loop for each tab (lines 499-543 for sound alerts; same pattern for video and twitch):

```php
<?php
$current_mappings = $soundAlertMappings[$file] ?? [];
// Rewards still available to map (not already used by ANY file, sound or video)
$available_rewards = array_filter($channelPointRewards, function ($r) use ($soundMappedRewards, $videoMappedRewards) {
    return !in_array($r['reward_id'], $soundMappedRewards)
        && !in_array($r['reward_id'], $videoMappedRewards);
});
?>
<tr>
    <td><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
    <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
    <td>
        <div class="mapping-chips">
            <?php foreach ($current_mappings as $rid):
                $title = htmlspecialchars($rewardIdToTitle[$rid] ?? '(unknown reward)');
            ?>
                <span class="mapping-chip" data-reward-id="<?php echo htmlspecialchars($rid); ?>">
                    <?php echo $title; ?>
                    <button type="button" class="mapping-chip-remove"
                            data-file="<?php echo htmlspecialchars($file); ?>"
                            data-reward-id="<?php echo htmlspecialchars($rid); ?>"
                            data-kind="sound">×</button>
                </span>
            <?php endforeach; ?>
            <?php if (empty($current_mappings)): ?>
                <em class="mapping-empty"><?php echo t('sound_alerts_not_mapped'); ?></em>
            <?php endif; ?>
        </div>
        <?php if (!empty($available_rewards)): ?>
            <form class="mapping-add-form" data-kind="sound">
                <input type="hidden" name="media_type" value="sound_alert_mapping">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                <select name="reward_id" class="sp-select mapping-add-select">
                    <option value="">+ Add reward…</option>
                    <?php foreach ($available_rewards as $r): ?>
                        <option value="<?php echo htmlspecialchars($r['reward_id']); ?>">
                            <?php echo htmlspecialchars($r['reward_title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </td>
    <td>…delete/test buttons unchanged…</td>
</tr>
```

### 3.4 JS changes (`<script>` block at end of media.php)

Replace the existing `.mapping-select` change handler (line 961-966) with two handlers:

```js
// Add a mapping
$(document).on('change', '.mapping-add-select', function () {
    if (!this.value) return;
    $.post('', $(this).closest('form').serialize(), function () { location.reload(); });
});
// Remove a mapping (clicks the × on a chip)
$(document).on('click', '.mapping-chip-remove', function () {
    var kind = $(this).data('kind');
    var mediaType = kind === 'video' ? 'video_alert_mapping'
                  : kind === 'twitch' ? 'twitch_event_mapping'
                  : 'sound_alert_mapping';
    $.post('', {
        media_type: mediaType,
        action: 'remove',
        reward_id: $(this).data('reward-id')
    }, function () { location.reload(); });
});
```

### 3.5 CSS additions (overlay/dashboard scope)

Small chip styling in `./dashboard/css/dashboard.css`:

```css
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

### 4.2 Walkons tab rewrite

Replace the current Walkons tab (lines 638-689 in media.php) which lists raw files with delete/test buttons. New structure: same per-file row pattern as sound alerts, but the chips are Twitch users instead of channel-point rewards.

```
[ ] thanks_for_lurking.mp3      [@gfaundead ×] [@bandit ×]  [+ Add user ▾]   [🗑] [▶]
[ ] hello_world.mp3                                          [+ Add user ▾]   [🗑] [▶]
```

Data load — once per page render, build `$walkonsByFile = [media_file => [{user_id, user_name}, ...]]`:

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

The "+ Add user" picker has two modes (see §8 open decision):
- **Typeahead from `seen_users`** — fast common case, no Helix round trip.
- **Free-form Twitch username** — fallback that calls a small endpoint to resolve via Helix at save time.

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

## 8. Open decisions

1. **Migrate existing walkons into the new table** — auto-create `walkons` rows from `/var/www/walkons/{user}/{login}.mp3` files during migration, looking up `user_id` via Helix for any login not already in `seen_users`? Or leave migration as files-only and require the streamer to re-tag in the new UI? **Recommendation:** auto-create — Helix lookups are cheap and existing walkons should keep working without re-tagging.
2. **"+ Add user" picker for walkons** — typeahead from `seen_users` only, free-form Twitch username only, or both? **Recommendation:** both — typeahead for fast common case, free-form fallback with Helix lookup at save time so streamers can pre-set walkons for viewers who haven't chatted yet.
3. **Old-directory cleanup** — should a post-migration step delete the contents of `/var/www/soundalerts/{user}/`, `/var/www/videoalerts/{user}/`, `/var/www/walkons/{user}/` once we've verified the unified library is populated? **Recommendation:** not in this plan — too destructive to bundle with the rest. Add as a separate "verified migration, free up storage" button in the dashboard later.
4. **Storage-usage display post-migration** — `media.php:358` sums all four old paths plus the unified one, so post-migration the displayed total roughly doubles until cleanup (decision #3). Should the storage bar switch to "unified path only" when `media_migrated = 1`? **Recommendation:** yes, gate it on the flag — `calculateStorageUsed([$media_path])` when migrated, current four-path sum otherwise.
5. **Concurrency for chip add/remove** — should the UI optimistically update without a full `location.reload()` on every chip change, to avoid scroll-position loss on long file lists? **Recommendation:** out of scope for v1 — reload is simpler and the file lists are typically small. Revisit if it becomes annoying in practice.
