# Project Rules (Grok)

Short, project-specific rules under `.grok/rules/`. Grok auto-loads every `*.md` here. Read the matching rule before making changes in that area.

| Rule | When it applies |
| ---- | --------------- |
| [bot-versions.md](./bot-versions.md) | Editing anything under `./bot/` |
| [paths.md](./paths.md) | Writing or referencing file paths in code, docs, or memory |
| [data-flow.md](./data-flow.md) | Adding a new event, endpoint, or cross-system integration |
| [database.md](./database.md) | Any code that touches MySQL |
| [secrets.md](./secrets.md) | Anything involving credentials, API keys, or `.env` |
| [php-config.md](./php-config.md) | **HARD RULE.** Any PHP file that needs configuration or credentials |
| [overlays.md](./overlays.md) | Adding or modifying browser-source overlays |

Rules in this folder are concise on purpose. Detailed architecture lives in `.grok/memory/`.
