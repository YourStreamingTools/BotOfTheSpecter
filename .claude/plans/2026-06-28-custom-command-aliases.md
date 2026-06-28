# Custom Command Aliases (BETA) — Implementation Plan

**Goal:** let a streamer give a custom command a comma-separated list of aliases (set on the Edit form, marked BETA) so that typing any alias runs that command exactly — on `beta.py` and `beta-v6.py` only.

**Approach in one paragraph:** add a single `aliases TEXT` column to the per-user `custom_commands` table, which the dashboard's schema manager auto-migrates everywhere. At message dispatch, when a typed name is not a direct custom command, the beta bots run a `FIND_IN_SET(name, aliases)` fallback, redirect the working command to the owning canonical command, and reuse the existing permission, cooldown, and processing path — giving a true alias with a shared cooldown. The dashboard Edit form gains a normalized, conflict-checked aliases input and shows a command's aliases in the list.

**Touch points:** Python (aiomysql, TwitchIO 2.10 and 3.2.2), PHP (mysqli), and MySQL's `FIND_IN_SET`.

## Constraints to honor

- **Beta bots only.** Change `bot/beta.py` and `bot/beta-v6.py`. Do not touch stable `bot/bot.py` or the Discord/Kick bots.
- **Parameterized SQL only** — placeholders and bound parameters on both the Python and PHP sides; no string-built SQL.
- **Normalized alias storage** — lowercase, space-free, comma-separated, each token run through the existing command-name sanitizer.
- **i18n** — every new dashboard label is a `t()` key added to the English base plus German and French, with French apostrophes escaped.
- **No new test harness.** These dispatch paths and PHP pages have no existing automated tests; verification is a per-file syntax check plus the resolution trace described below. Don't scaffold a framework.

## Work items

### 1. Add the `aliases` column to the schema

In the per-user schema manager (`dashboard/includes/usr_database.php`), add `aliases TEXT` to the `custom_commands` table definition, after `permission` and before the primary key. Keep the file's existing indentation.

The manager's auto-migration parses each table definition column-by-column, skips constraint lines (PRIMARY/UNIQUE/etc.), and issues an `ALTER TABLE … ADD` for any column missing from a given database. `aliases TEXT` parses cleanly as a column and, like the existing `response`/`status` TEXT columns, needs no default — so it migrates onto every existing user database on the next dashboard load.

### 2. Alias-resolution fallback in `beta.py` (TwitchIO 2.10)

In `event_message`, the dispatch currently does: direct `custom_commands` lookup → (on miss) `custom_user_commands` lookup. Insert the alias step between those two, so the order becomes direct command → alias → custom user command.

When the direct lookup has missed, run the `FIND_IN_SET(typed_name, aliases)` query on the cursor already open in the dispatch path. On a hit, set the working command name to the returned canonical command and build the command-data structure from the canonical row (response/status/cooldown/permission, typed as a custom command). On a miss, fall through to the existing custom-user-command check unchanged. Wrap the alias query defensively so that if the `aliases` column hasn't migrated yet, the bot logs a warning and behaves as "no alias" rather than aborting the lookup.

Because the working command name is now the canonical one, the existing downstream code — cooldown check, dynamic-variable processing, usage tracking — all key on the canonical command automatically, which is what gives us the shared cooldown and identical behavior.

### 3. Alias-resolution fallback in `beta-v6.py` (TwitchIO 3.2.2)

Same idea, adapted to v6. In `event_message`, immediately after the direct lookup result is fetched and before the existing "command found" block, add the fallback: if the direct lookup missed, run the same `FIND_IN_SET` query, and on a hit set the working command name to the canonical command and assign the canonical row as the lookup result. The existing "command found" block then runs unchanged — it only reads response/status/cooldown/permission, so the extra `command` key on the row is harmless.

Leave v6's own conventions in place: its chatter-based permission handling, its combined cursor context, and its inline switch processing are not part of this change.

### 4. Dashboard Edit form, save handler, and list display

In `dashboard/custom_commands.php`:

- **Edit-form field.** After the permission group, add an Aliases text input with a BETA badge, an icon, a placeholder, and help text. Populate it when the edit combobox changes, alongside where the other fields (response, cooldown, permission) are set from the selected command. The command data already carries every column, so the alias value is available there.
- **Save-time normalization.** In the edit/update handler, read the aliases input and normalize it: split on commas, sanitize each token like a command name, lowercase, drop empties, drop any token equal to the command's own new name, and dedupe.
- **Conflict check.** Query the other commands' names and aliases, and drop any normalized token that collides with another command's name or alias. Keep the survivors. If anything was dropped, append a translated warning listing the skipped tokens to the success message — still saving the valid subset.
- **UPDATE.** Extend the existing update statement to also write the `aliases` column, with the bound-parameter list and type string updated to match.
- **List display.** In the command table's name cell, when a command has aliases, render them beneath the name as a muted, read-only line (each prefixed with `!`).

### 5. Translation keys

Add the new keys to the English base file and to the German and French files, near the existing `custom_commands_*` block:

- a label for the Aliases field,
- a placeholder (e.g. `book, bk`),
- help text explaining comma-separated alternates, beta-bots-only, and the shared-cooldown true-alias behavior,
- a conflict warning containing a `%s` for the list of skipped aliases.

Escape French apostrophes.

### 6. Verification

- Syntax-check every touched file in its own language (the Python bots and the four PHP files).
- Walk the resolution trace: command `books` with `aliases = 'book,bk'`, viewer types `!book`. Built-in check misses → direct `command = 'book'` lookup misses → `FIND_IN_SET('book', 'book,bk')` hits the `books` row → working command becomes `books`, row loaded → `books` status/permission/cooldown checked, cooldown keyed on `books`, response processed as `books`. A real command named `book` would shadow the alias because the direct lookup runs first. Stable `bot.py` never runs the alias query, so the alias is inert there.

## Deployment note

The `aliases` column has to exist before the beta bots try to resolve aliases. Loading any dashboard page once triggers the auto-migration, so do that before (or alongside) deploying the beta bots. The bots' defensive handling means a not-yet-migrated database is non-fatal in the meantime — aliases just won't resolve until the column lands.
