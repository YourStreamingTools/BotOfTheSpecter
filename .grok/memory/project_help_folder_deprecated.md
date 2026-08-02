---
name: help-folder-deprecated
description: "The ./help folder is dead/legacy — never read or edit it; help docs live in ./support, now a STATIC docs site (the custom CMS was removed 2026-07-10)"
metadata: 
  node_type: memory
  type: project
  originSessionId: 9645b054-c25f-457e-ae61-3dc31ba25da1
---

The `./help` folder is **old and no longer used**. Treat it as dead code.

- **Never read the `./help` folder** and never include it in searches, exploration sweeps, or agent fan-outs. Exclude it.
- **Never change any code in `./help`** — no edits, no fixes, no migrations there.
- All help documentation lives in the **`./support` folder**. As of **2026-07-10** the support portal is a **static docs site**: `support/index.php` renders hardcoded guides (First Time Setup, Main Features, Spotify/TTS, Variables/Commands, Channel Points, Custom API, OBS Audio Monitoring, Run Yourself/self-hosting, etc.). The **old custom CMS was removed** — commit `194c187c` "Remove support portal docs CMS in favor of static docs" deleted `support/docs.php`, `support/api/ai_docs.php`, and `support/css/style.css`'s CMS styles. **Do not resurrect the CMS / AI-docs path**; add or edit guides directly in the static `support/index.php`.
- **Tab nav mechanics** (`support/index.php`): each guide is a `<div class="sp-tab-panel" data-panel="X">`; navigation is via `.sp-doc-card[data-goto="X"]`, inline `<a data-goto="X">`, and sidebar `layout.php` `href="/index.php#X"` — resolved by the inline `extraScripts` handler + `hashchange` (there is NO `.sp-tab` tab bar; `app.js initTabs()` no-ops). Removing/renaming a panel means repointing all three plus the FAQ links and the file's top comment.
- **2026-07-11: "Custom Variables" + "Module Variables" tabs were MERGED into ONE `Variables` tab** (still `#variables`). Structure: Universal Variables → Channel Point Reward Variables → Event Alert Variables. The `#module-variables` anchor/panel no longer exists. `(if.*)` now lives under Universal.
- **Source of truth for the Variables guide** = the bot code, not the guide. The **canonical beta variable list is `beta.py`'s `DYNAMIC_VARIABLE_SWITCHES` tuple** (~line 12772, drives `process_dynamic_variables`); stable's set is the `switches` list in `bot.py`'s command handler (~line 1894) + its reward block (~9309) + event-alert `.replace()` calls (~8555–8897) + ad notices (~10354). When updating the guide, diff against these. Convention: purple `#c813e0` code + "Beta" chip = beta-only (in beta.py, not bot.py); blue `#3273dc` = works in stable. On 2026-07-11 audited both: stable had NOTHING undocumented; added 14 beta-only vars to the guide — `(count.name)`, `(clearcount.name)`, `(shoutout.username)`, `(call.name.arg)`, `(todo.add.cat.[desc])`, `(json.path)`, `(redeem.input/title/cost/prompt/id/status/redeemed_at)`, `(storeredeem)` — and retagged 7 reward vars (`usercount/userstreak/track/tts/tts.message/lotto/fortune`) from Beta to stable since bot.py's reward block handles them too.
- **Dashboard still hard-links to the dead `help.botofthespecter.com` site.** The 3 variables links (`custom_commands.php`, `timed_messages.php`, `modules.php`) were repointed to `https://support.botofthespecter.com/index.php#variables` on 2026-07-11. STILL STALE (not yet fixed): `help.botofthespecter.com/markdown.php` (lang en/de/fr), `.../tts_setup.php` (lang en/de/fr), `.../spotify_setup.php` (`spotifylink.php`). These should eventually be repointed to the matching `support.botofthespecter.com` guide too. NOTE: `help/specter_module_variables.php` 301-redirects to the now-dead `#module-variables` anchor — but per the rule above, do NOT edit `./help`; flag it instead.

**Why:** the `./help` folder is legacy and superseded; touching it or reading it wastes effort and risks editing the wrong (defunct) copy of a doc. The `./support` CMS was likewise retired in favor of simpler static pages.

**How to apply:** if a task involves help/support content, work in `./support` (Specter Support CMS), not `./help`. If something genuinely seems to require `./help`, stop and ask first rather than assuming it's still active. Relates to [[feedback_no_commits]].
