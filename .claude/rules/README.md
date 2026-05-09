# Project Rules for Claude

Short, project-specific rules that override generic defaults. Read the file matching the area you're working in before making changes.

| Rule | When it applies |
| ---- | --------------- |
| [bot-versions.md](./bot-versions.md) | Editing anything under `./bot/` |
| [paths.md](./paths.md) | Writing or referencing file paths in code, docs, or memory |
| [data-flow.md](./data-flow.md) | Adding a new event, endpoint, or cross-system integration |
| [database.md](./database.md) | Any code that touches MySQL |
| [secrets.md](./secrets.md) | Anything involving credentials, API keys, or `.env` |
| [php-config.md](./php-config.md) | **HARD RULE.** Any PHP file that needs configuration or credentials |
| [overlays.md](./overlays.md) | Adding or modifying browser-source overlays |

Rules in this folder are concise on purpose. Detailed architecture lives in `.claude/memory/`.
