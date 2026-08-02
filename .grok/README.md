# BotOfTheSpecter - Grok project config

This folder is the **only** project-local AI assistant home for BotOfTheSpecter.
Legacy `.claude/`, `.codex/`, and `.agents/` folders have been retired; all rules,
memory, skills, agents, docs, specs, and plans live here.

| Path | Purpose |
| ---- | ------- |
| `rules/` | Auto-loaded project rules (`*.md`) |
| `memory/` | Architecture notes (`system_*.md`) plus project/feedback memories from prior AI sessions |
| `skills/` | Project skills (`*/SKILL.md`) |
| `agents/` | Project agent personas |
| `docs/` | API and integration reference docs |
| `specs/` | Design specs |
| `plans/` | Implementation plans |
| `CLAUDE.md` | Legacy long-form project overview (kept for history; prefer root `AGENTS.md`) |

Root **`AGENTS.md`** is the primary project instruction file Grok loads each session. It points into `memory/` and `rules/`.

**Grok workspace memory** (`~/.grok/memory/botofthespecter-*/MEMORY.md`) also points here: standing knowledge is in this folder; the home memory file holds session-consolidated extras only.
