---
name: feedback_bot_file_function_placement
description: "bot/beta.py house style — ALL helper functions go below the \"# Functions for all the commands\" marker as module-level functions, never class methods near commands"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: d4535b42-54e1-402f-9d82-0ce83a3c4802
---

In `bot/beta.py` (and the bot files generally), commands are methods inside `class TwitchBot(commands.Bot)` (e.g. `async def craft_command(self, ctx)`), but **every shared/helper function lives BELOW the `# Functions for all the commands` marker** (around line 11080) as a **module-level function** — `def`/`async def` at column 0, no `self`, no leading underscore, each preceded by a short `# Function ...` comment, using `global` for module state. Commands call them BARE: `await command_permissions(...)`, `await check_cooldown(...)`, `await send_chat_message(...)`, `await mysql_connection()`.

**Why:** This is the user's stated, enforced organisation — "all functions of the system go below the comment." Helper functions wedged in between the command definitions (as class methods or `@staticmethod`) is wrong and was explicitly called out.

**How to apply:** When adding command logic that needs a helper, put the helper as a module-level function below the marker (matching `format_lurk_time`, `is_valid_twitch_user`, `command_permissions`, `check_cooldown`, `websocket_notice`, `send_chat_message` style) and call it without `self.` from the command method. Do NOT create class-method/`@staticmethod` helpers next to the commands. (Multi-agent build of the Working & Study expansion got this wrong — 13 helpers had to be relocated.) Also: the file writes `await cursor.execute("…", (args,))` on a single line, no `@staticmethod`.

**The command-permission/cooldown gate is INLINED in every command body — NEVER a shared helper.** The canonical shape (see `clip_command`): `global bot_owner` → `connection = await mysql_connection()` → `try:` → `async with connection.cursor(DictCursor) as cursor:` → `await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("<cmd>",))` → `result = await cursor.fetchone()` → `if result:` → 5 `result.get(...)` assignments → `if status == 'Disabled' and ctx.author.name != bot_owner: return` → `if not await command_permissions(permissions, ctx.author): … return` → `# Check cooldown` → `bucket_key = …` → `if not await check_cooldown('<cmd>', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time): return`. **The ENTIRE command body lives inside that `if result:` block** (so a command with no `builtin_commands` row is inert), and `add_usage('<cmd>', bucket_key, cooldown_bucket)` is called at the end. A factored `task_command_gate()`-style helper is WRONG even below the marker — the gate must be visible/inlined in each command. (I made this mistake twice; the W&S commands were rewritten to inline it.) See [[feedback_bot_builtin_command_registration]] and [[project_working_study_expansion_build]].
