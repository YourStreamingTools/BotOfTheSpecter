---
name: project_stable_version_bump
description: "When bot.py (stable) gets a fix, bump the version in 5 places and write a docs/<ver>.md changelog"
metadata: 
  node_type: memory
  type: project
  originSessionId: 51fee23f-85f4-4f37-9597-34bff233937b
---

Whenever the **stable** bot (`./bot/bot.py`) is changed (it only gets critical fixes — see [[feedback_bot_builtin_command_registration]] and the bot-versions rule), the stable version number must be bumped and a changelog written. The version string lives in **5 places** — update all of them:

1. `./bot/bot.py` — the `VERSION = "x.y.z"` constant near the top (~line 63).
2. `./api/versions.json` — the `"stable_version"` field.
3. `./docs/<new-version>.md` — NEW changelog file. Use the **previous** version's `docs/<prev>.md` as the template (sections: Version Information, Bug Fixes, optionally Changes, Technical Details, thank-you footer). `docs/` is the public version-docs folder, so changelogs DO belong there (this is the one exception to [[feedback_plans_in_dot_claude]]). **Do NOT write "for the Stable bot" / "Hotfix for the Stable bot" in the summary line** — these docs are stable-only, so the qualifier is redundant. Use a plain `Fix:` / `Hotfix:` label instead. (The older templates contain this phrasing; strip it when copying.)
4. `./README.md` — the `### Version x.y.z` heading and the `https://changelog.botofthespecter.com/x.y.z.html` link (~lines 7,9).
5. `./docs/README.md` — prepend `[Version x.y.z](x.y.z.md)` to the top of the ChangeLog list.

Patch increments (5.7.12 → 5.7.13) for fixes. Beta version is tracked separately in versions.json (`beta_version`) and is NOT bumped for a stable fix. After editing, `python -m py_compile bot/bot.py` and validate `versions.json` parses. Leave it all uncommitted ([[feedback_no_commits]]).

**Changelog scope (HARD):** A stable dump/`docs/<ver>.md` is the public note for that fix. Do **not** also append the same item to `docs/5.8.md` (or invent a v6 version note). `5.8.md` is only for beta-only / day-to-day beta-track work that is **not** shipping as a stable patch.

**Never name the reporting channel** (or a channel-specific DB like `<channel>.twitch_bot_access`) in public version notes. Describe the failure generically.
