# BotOfTheSpecter - Grok project config

This folder is the **project-local Grok home** (formerly `.claude/`).

| Path | Purpose |
| ---- | ------- |
| `rules/` | Auto-loaded project rules (`*.md`) |
| `memory/` | Architecture notes for bot / API / websocket / secondary systems |
| `skills/` | Project skills (`*/SKILL.md`) |
| `agents/` | Project agent personas |
| `docs/` | API and integration reference docs |
| `specs/` | Design specs |
| `plans/` | Implementation plans |
| `CLAUDE.md` | Legacy long-form project overview (kept for history; prefer root `AGENTS.md`) |

Root **`AGENTS.md`** is the primary project instruction file Grok loads each session. It points into `memory/` and `rules/`.
