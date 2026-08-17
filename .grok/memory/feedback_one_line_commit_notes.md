---
name: feedback-one-line-commit-notes
description: "Clear commit title plus a short GitHub body; code comments stay one line"
---

**Commits:** Title must say what happened. Body is a few lines for GitHub — a quick desc, not an essay and not empty.

**Code comments:** One physical line only (`#`, `//`, `/* */`). No wrapped blocks.

**Why:** `git log` is scanned by title; GitHub needs a short body to see what changed; comments sit beside the line they mark.

**How:**
```
git commit -m "docs(api): hide v2-only routes from the V1 schema" -m "App-login and /channel/twitch/* still run as /v2/.... V1 /openapi.json no longer lists them."
```
In code: `# Hidden from V1 OpenAPI; still served as /v2<PATH> via the rewrite middleware.`
