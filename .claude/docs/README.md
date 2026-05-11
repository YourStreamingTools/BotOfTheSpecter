# .claude/docs — Documentation Library

Local reference documentation for the BotOfTheSpecter project. Read these files instead of fetching upstream docs during development.

## Structure

```
docs/
  README.md          ← you are here
  INDEX.md           ← master index of all docs with one-line summaries and line counts
  API/
    External/        ← comprehensive copies of third-party API docs (30 files)
    Internal/        ← documentation for BotOfTheSpecter's own APIs (in progress)
```

## How to use

- **Start with [INDEX.md](./INDEX.md)** — lists every doc with a one-line summary so you can find the right file without opening each one.
- **External API docs** live in `API/External/`. Each file is a complete local copy of the upstream API scoped to what this project uses (auth, endpoints, request/response schemas, rate limits, gotchas, and repo callsites).
- **Internal API docs** live in `API/Internal/`. These document BotOfTheSpecter's own API server endpoints and WebSocket events — useful when building integrations or overlays that call back into the platform.

## When to update

- A new third-party API is integrated → create a file in `API/External/`, add a row to `INDEX.md`.
- An existing integration changes → update the relevant doc and the `INDEX.md` line count.
- A new internal endpoint or WebSocket event is added → document it in `API/Internal/`, add a row to `INDEX.md`.

## Project rules that apply everywhere

- PHP never reads `.env` — see `.claude/rules/php-config.md`
- Secrets stay in env vars or `./config/*.php` — see `.claude/rules/secrets.md`
- Bot version policy (stable/beta/v6) — see `.claude/rules/bot-versions.md`
