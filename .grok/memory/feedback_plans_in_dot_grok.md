---
name: feedback_plans_in_dot_grok
description: "Design docs and plans go in .grok/specs and .grok/plans, NEVER docs/"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 60566c19-8e4c-457e-9458-269d58585b9c
---

When brainstorming or writing plans, save the design spec to `.grok/specs/YYYY-MM-DD-<topic>-design.md` and any implementation plan to `.grok/plans/YYYY-MM-DD-<topic>.md`. Both folders already exist in the repo with prior examples (e.g. `.grok/specs/2026-06-11-task-projects-design.md`, `.grok/plans/2026-06-11-task-projects.md`).

NEVER write plans/specs under `docs/` — that folder is the project's PUBLIC version documentation (`docs/5.7.md`, `docs/CNAME`, `docs/discord/`, etc.). The `brainstorming` skill's default path `docs/superpowers/specs/` is WRONG for this project; override it to `.grok/specs/`.

**Why:** the user explicitly and angrily called this out ("stop putting it into our docs folder, you have a .grok folder for a reason"). `docs/` is shipped/public docs; `.grok/` is the AI working area (specs, plans, memory, rules).

**How to apply:** ignore the skill's `docs/` default — write the spec straight to `.grok/specs/` and plans to `.grok/plans/`. Related: [[feedback_no_commits]].

**PUBLIC-FACING — write as a human dev, no AI tells (2026-06-21):** `.grok/specs` and `.grok/plans` are untracked-but-not-ignored, so they land in the PUBLIC GitHub repo on the next `git add`. The repo must read as dev-maintained, NOT AI-generated. So these files must contain ZERO AI/agentic/process meta: no "For agentic workers / REQUIRED SUB-SKILL / subagent-driven-development", no "Git is the user's responsibility / Do NOT commit / leave uncommitted", no "Self-Review (completed during planning)", no "NOT placeholders", no `Author: Claude` / `Created By: Multi-agent` / model names (Claude/Sonnet/Opus/Haiku/AI). Write the spec/plan in a neutral human engineering voice from the start. Keep all real technical content (design, DDL, code, verification commands, deploy steps). The user flagged this directly. **Decision (2026-06-21): keep these files in-repo (committed as design history), written human-voiced — do NOT gitignore them.** So the discipline is permanent: every spec/plan must be clean from the first draft.
