---
trigger: always_on
---

# BotOfTheSpecter AI Coding Guidelines

## 🚨 Critical Operational Rules

- **NO Documentation Files**: Do NOT create `.md`, `.txt`, or other documentation/note files unless explicitly requested.
- **No "Thought" Files**: Do not create files to track your internal thought process or todos in the workspace.
- **Read Before Write**: Always read a file before modifying it.
- **Import Safety**: Check existing imports before adding new ones to avoid duplicates.
- **Thinking**: Always think before answering.
- **No Extra Spaces**: Avoid unnecessary blank lines and extra spacing in code. Keep code compact and clean.
- **No Function Docstrings**: Do NOT add docstrings or multi-line comments to functions. Code should be self-explanatory through clear naming and structure.

## 🚀 Deployment & Workflow

- **Remote Testing Only**: The local machine (Windows/Mac/Linux) is for development only. **Testing must be done on the dev server**.
- **Debug Checks**: Only syntax/linting or basic debug checks should be run locally.
- **Upload to Test**: Always assume code needs to be uploaded to the dev server to function correctly.

## 🛠️ Tech Stack & Environment

- **OS**: Windows (Local Dev Environment) / Linux (Target Server)
- **Core Languages**:
  - **Python 3.10+**: `asyncio` (REQUIRED for I/O), `twitchio`, `fastapi`, `aiohttp`, `aiomysql`.
  - **PHP 8.0+**: Web dashboard and configs.
  - **JavaScript**: `socket.io-client` for real-time updates.
- **Database**: MySQL (Async access via `aiomysql`).
- **Secrets**: Environment variables loaded via `python-dotenv`.

## 📂 Project Structure

```text
BotOfTheSpecter/
├── api/             # FastAPI server (webhooks, data endpoints)
│   └── api.py       # Main API entry point
├── bot/             # Core Twitch Bot
│   ├── bot.py       # Main Bot entry point
│   └── beta.py      # Beta Bot entry point
├── config/          # PHP Configuration files
│   └── main.php     # Core constants
├── dashboard/       # PHP Web Dashboard
│   └── dashboard.php
├── websocket/       # Real-time communication
│   └── server.py    # SocketIO Server
└── app/             # Desktop App
    └── app.py
```

## 🔄 Architecture & Data Flow

1.  **Twitch Events** (Chat/Subs) -> **Bot Service**
2.  **Bot Service** -> **API** (Storage/Lookups)
3.  **API** -> **Database** (User-specific DBs)
4.  **Webhooks** (Patreon/Ko-fi) -> **API** -> **WebSocket**
5.  **WebSocket** -> **Dashboard/Overlay** (Real-time alerts)

## 📝 Code Conventions

### Python (Async is King)

- **I/O**: MUST be `async`. Use `aiohttp` for requests, `aiomysql` for DB.
- **Error Handling**: Wrap async blocks in `try/except`. Log errors explicitly.
- **Imports**: `import asyncio`, `from asyncio import ...`.

### PHP

- **Config**: `require_once "/var/www/config/<file>.php"`
- **DB**: Standard PDO or project-specific wrappers.

## 🧱 Common Implementation Patterns

### 1. Command Permissions

```python
if not await command_permissions(level, user):
    return
```

### 2. Verify API Key

```python
username = await verify_api_key(api_key)
if not username:
    raise HTTPException(status_code=403, detail="Invalid API Key")
```

### 3. Database Connection (Async)

```python
async with aiomysql.create_pool(**db_config) as pool:
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT * FROM custom_commands WHERE ...")
```

## 🔌 Integration Points

- **Twitch**: `twitchio` (Chat), `aiohttp` (Helix API).
- **Discord**: `discord.py` independent bot instance.
- **Spotify**: OAuth2 token management via `refresh_spotify_tokens.py`.
- **Weather**: OpenWeatherMap API.
