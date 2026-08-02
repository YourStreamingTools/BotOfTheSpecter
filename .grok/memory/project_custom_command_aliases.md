---
name: project-custom-command-aliases
description: "custom command aliases (BETA) — comma-separated `aliases` column resolved via FIND_IN_SET in beta.py + beta-v6.py only"
metadata: 
  node_type: memory
  type: project
  originSessionId: c55eb4fa-d591-47d3-8e21-c909c58c8d07
---

Custom command aliases feature, built 2026-06-28, **SHIPPED** (committed "Add beta aliases for custom commands" `621ee55b`; spec `.grok/specs/2026-06-28-custom-command-aliases-design.md`, plan `.grok/plans/2026-06-28-custom-command-aliases.md`).

**Model:** true alias — typing an alias runs the canonical command exactly, **sharing its cooldown** (dispatch reassigns `command` to the canonical name before the existing permission/cooldown/process_dynamic_variables path).

**Storage:** new `aliases TEXT` column on per-user `custom_commands` (added to `$tables` in dashboard/includes/usr_database.php → auto-migrates every user DB via the existing INFORMATION_SCHEMA ALTER loop). Aliases live **on the target command** as a normalized, lowercase, space-free, comma-separated list (e.g. `book,bk`) — so an alias name is never its own row and chains are impossible.

**Bot dispatch (BETA ONLY — beta.py ~L3613 `else`, beta-v6.py ~L3175):** when the direct `WHERE command=%s` lookup misses, run `SELECT command,response,status,cooldown,permission FROM custom_commands WHERE FIND_IN_SET(%s, aliases) LIMIT 1`, redirect to the returned canonical `command`. Wrapped in try/except so a not-yet-migrated `aliases` column degrades gracefully (no alias) instead of aborting the lookup. Stable `bot/bot.py` is intentionally NOT changed — aliases are inert there. A real command always shadows an alias (direct lookup wins).

**Dashboard (custom_commands.php):** aliases set on **both the Add and Edit forms**, via a BETA-tagged (`sp-badge-amber`) comma-separated `name="aliases"` input; populated in `showResponse()` from `commandData.aliases`; normalized + conflict-checked server-side (drops tokens already used as another command's name/alias, surfaces `custom_commands_alias_conflict_warning`); UPDATE bind string went `ssiss`→`ssisss`. Aliases shown muted under the command name in the list. i18n keys `custom_commands_aliases_*` added to en/de/fr.

**Deploy:** load any dashboard page once (runs the column migration) before/with the beta-bot deploy. See [[project_per_user_schema]] for the schema manager.
