# Custom Command Aliases (BETA) - Design Spec

**Date:** 2026-06-28
**Scope:** the beta Twitch bots (`bot/beta.py`, `bot/beta-v6.py`), the custom-commands dashboard page, the per-user schema manager, and the dashboard language files. Stable `bot/bot.py` is intentionally left alone.
**Status:** Design decisions agreed; ready to turn into an implementation plan.

## Problem

A streamer who wants two names for the same custom command - say `!book` should behave exactly like `!books` - has no clean way to do it today. The only workaround is to create a second command whose response is the `(command.books)` placeholder. That approach is poor for several reasons:

- It duplicates the command as a real row with its own response, cooldown, and permission, all of which can drift away from the target over time.
- It emits the target's output as a *separate, appended* "additional" message rather than running as the real command.
- It carries recursion-guard baggage and is unintuitive to set up.

There is no first-class alias concept for custom commands. (A hardcoded `builtin_aliases` set exists for built-in commands, but that is unrelated and not user-editable.)

## Goal

Let a streamer attach one or more **aliases to a custom command**. Typing any alias should run that command exactly as if the canonical name had been typed - a true alias, not a copy. Aliases are configured through a new comma-separated input on the **Edit** form of the custom-commands dashboard page, marked clearly as a BETA feature.

## Design decisions

1. **True alias, shared cooldown.** An alias uses the target command's response, permission, and cooldown - and using an alias also cools down the canonical command. We achieve this by redirecting the dispatch to the canonical command name *before* the existing permission, cooldown, and processing logic runs, so everything downstream operates on the canonical command.
2. **Beta bots only.** The resolution logic ships in `beta.py` and `beta-v6.py`. Stable `bot.py` is untouched and simply ignores the new column. There is no `(command.<target>)` fallback message.
3. **Aliases live on the target command** as a comma-separated list. Because an alias name is never its own table row, alias *chains* are structurally impossible, so we don't need chain or loop handling beyond a single defensive redirect.

## Data model

A single new column on the per-user `custom_commands` table:

```
aliases TEXT
```

- Stored normalized: lowercase, no spaces, comma-separated, each token sanitized the same way a command name is (e.g. `book,bk`). `NULL` or empty means no aliases.
- Declared in the `custom_commands` definition inside the per-user schema manager (`dashboard/includes/usr_database.php`). That manager already auto-migrates: it compares the declared columns against `INFORMATION_SCHEMA.COLUMNS` and issues an `ALTER TABLE … ADD` for anything missing on the next dashboard page load. So adding the column to the schema definition propagates it to every existing user database with no standalone migration script.
- The primary key stays `command(255)`. The `aliases` column is matched at runtime with `FIND_IN_SET`, which needs no index.

## Runtime resolution

The canonical command lookup stays first. Alias resolution is a **fallback that only runs when the typed token is not itself a command**, so a real command always shadows an alias.

When the direct lookup misses, the bot runs one additional query to find a command that lists the typed token among its aliases:

```sql
SELECT command, response, status, cooldown, permission
FROM custom_commands
WHERE FIND_IN_SET(%s, aliases)
LIMIT 1
```

On a hit, the dispatch reassigns the working command name to the returned canonical command and continues down the existing path using that canonical row's response, status, cooldown, and permission. The consequences are all intended:

- The cooldown is keyed on the canonical name, so the alias and the command share a single cooldown.
- Dynamic-variable processing runs with the canonical name and canonical response, so it behaves byte-for-byte like the real command (including `(command.)`, `(user)`, and the rest).
- Usage tracking records under the canonical name.
- Status is checked downstream rather than filtered in SQL, so a disabled canonical command disables its aliases too - correct for a true alias.

### Placement in each bot

- **`beta.py` (TwitchIO 2.10):** the fallback slots into the dispatch chain after the direct `custom_commands` lookup misses and before the `custom_user_commands` check. Final order: direct command → alias → custom user command. On an alias hit, the working command name and the command-data structure are rebuilt from the canonical row.
- **`beta-v6.py` (TwitchIO 3.2.2):** the fallback slots in immediately after the direct lookup result is fetched and before the existing "command found" block, which then runs unchanged against the canonical row. v6's own conventions (its chatter/permission handling, its combined cursor context, its inline switch processing) stay as they are.

The extra lookup is small enough to inline at each insert point; it reuses the cursor already open in the dispatch path rather than opening a second connection. It's wrapped defensively so that if the `aliases` column hasn't migrated yet on a given database, the bot logs a warning and degrades to "no alias" instead of aborting the whole command lookup.

## Dashboard changes (`custom_commands.php`)

### Edit form (the primary surface)

Add an **Aliases** field after the permission selector, carrying a BETA badge. It's a single text input taking a comma-separated list, with help text explaining that the names are alternates for this command, that they work on beta bots only, and that each alias runs the command exactly and shares its cooldown. The field is populated from the selected command when the edit combobox changes - the command data exposed to the page already includes every column, so the alias value is available client-side.

### Save handler

When an edit is submitted, read the aliases input and normalize it: split on commas, trim, strip a leading `!`, sanitize each token like a command name, lowercase, drop empties, drop any token equal to the command's own (new) name, and dedupe.

Then run a conflict check against the other rows: gather every other command's name and every other command's aliases, and drop any token that collides. If any tokens were dropped, surface a translated warning that lists them, but still save the surviving subset. Finally, the existing UPDATE is extended to write the `aliases` column alongside the other fields.

### Command list

In the command table, show a command's aliases subtly beneath its name (for example, a muted `!book, !bk` line). This is read-only - editing happens through the Edit form - but it lets a streamer see aliases at a glance.

### Add form

Left unchanged. New commands default to no aliases; the streamer edits a command to add them. This keeps the Add form simple and matches the decision to make Edit the single configuration surface.

### Internationalization

The new labels - the field label, the BETA tag, the placeholder, the help text, and the conflict warning - go through the dashboard's `t()` translation layer. Keys are added to the English base and to the German and French files per the project's i18n rule, with French apostrophes escaped.

## Out of scope (deliberately)

- Chat-command management of aliases (`!addcommand` / `!editcommand`) - the dashboard is the configuration surface.
- Porting to stable `bot.py` or to the Discord/Kick companion bots.
- Aliases on `custom_user_commands` - only `custom_commands`.
- Per-alias cooldown or permission overrides - rejected in favor of the true-alias model.

## Risks and edge cases

- **Alias collides with a built-in command name.** Built-ins are checked first in both bots, so such an alias would never fire. Acceptable; the dashboard may note it but does not hard-block it.
- **Casing and spacing in `FIND_IN_SET`.** Mitigated by storing a normalized, lowercase, space-free list; the default collation also makes the match case-insensitive.
- **Stable users on the shared dashboard.** They can set aliases that do nothing until they run a beta bot. Accepted under the "beta only" decision; the BETA badge signals it.
- **Renaming a command that has aliases.** The aliases column travels with the row, so a rename keeps them intact.
- **Deleting a command.** Aliases are a column on the row and are removed with it - no orphans.

## How we'll know it's right

The decisive check is a resolution trace: a command `books` with `aliases = 'book'`, and a viewer typing `!book`. The direct lookup for `book` misses, the alias lookup finds the `books` row, the working command becomes `books`, and the enabled/permission/cooldown checks and response processing all run against `books`. A real command named `book` would still win, because the direct lookup runs first. On stable `bot.py`, the alias query never runs, so the alias is simply inert there. Each touched file should also pass its language's syntax check before the change is considered done.

## Areas of the system affected

| Area | Change |
| ---- | ------ |
| Per-user schema manager (`dashboard/includes/usr_database.php`) | Add the `aliases TEXT` column to the `custom_commands` definition so it auto-migrates everywhere |
| `bot/beta.py` | Alias-resolution fallback in the message dispatch path |
| `bot/beta-v6.py` | Alias-resolution fallback in the message dispatch path |
| `dashboard/custom_commands.php` | Edit-form field, client-side populate, save-time normalize/validate/UPDATE, command-list display |
| Dashboard language files (`en` / `de` / `fr`) | New alias-related translation keys |
