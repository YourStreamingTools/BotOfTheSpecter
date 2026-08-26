---
name: help-folder-removed
description: "The ./help folder was deleted 2026-08-26. User-facing docs are the static support portal."
type: project
---

The old `./help` PHP docs site is **gone** (removed 2026-08-26). Do not recreate it.

- User-facing documentation is `./support/` (`support.botofthespecter.com`), a **static** docs site (`support/index.php` hash panels). Do not resurrect the CMS / `ai_docs.php` path.
- `help.botofthespecter.com` stays as a Caddy 308 to `support.botofthespecter.com{uri}`. Retired filenames (`spotify_setup.php`, `tts_setup.php`, …) are mapped on the support host to the matching `#` guide.
- Schedule “markdown guide” links go to Discord’s markdown article (those embeds are Discord markdown, not a Specter guide).
- CDN path `cdn.botofthespecter.com/help/` (audio samples, etc.) is unrelated — do not delete it.
