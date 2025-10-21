# BotOfTheSpecter AI Coding Guidelines

## Architecture Overview

BotOfTheSpecter is a multi-service Twitch bot system with the following components:

- **bot/**: Core Python bot using twitchio for Twitch IRC/chat handling, command processing, and external integrations (Discord, Spotify, StreamElements).
- **api/**: FastAPI server providing REST endpoints for webhooks, weather data, quotes, and bot management.
- **dashboard/**: PHP web dashboard for remote configuration, analytics, and user management.
- **websocket/**: SocketIO server for real-time communication between services and live updates.
- **config/**: PHP configuration files for database connections, API keys, and service settings.

Data flows from Twitch events → bot processing → API storage → dashboard/app display, with WebSocket enabling real-time updates.

## Conventions

### Async Programming
- Use `asyncio` throughout Python code for all I/O operations
- Import pattern: `import asyncio`, `from asyncio import ...`
- Database operations use `aiomysql` for async MySQL connections
- HTTP requests use `aiohttp` for async calls

### Configuration Management
- PHP configs loaded with `require_once "/var/www/config/<file>.php"`
- Environment variables for secrets (loaded via `python-dotenv`)
- Database connections: `await mysql_connection(db_name)` for user-specific DBs

### Error Handling
- Try/catch blocks around async operations
- Log errors with dedicated loggers (e.g., `bot_logger.error()`)
- HTTP exceptions in API with `raise HTTPException(status_code=..., detail=...)`

### Database Patterns
- User-specific databases (one per Twitch channel)
- Tables: custom_commands, builtin_commands, bot_points, etc.
- Queries use parameterized statements to prevent SQL injection

### WebSocket Communication
- Events prefixed by service (e.g., "WEATHER_DATA", "STREAM_ONLINE")
- Payloads include API keys for verification
- Real-time updates for weather, TTS, walk-ons, deaths

### Version Control
- Versions stored in `versions.json`, `version_control.txt`
- Update via `update_version_control()` function
- Semantic versioning: major.minor.micro

## Integration Points

### External APIs
- Twitch: twitchio for chat, aiohttp for Helix API
- Discord: discord.py for bot integration
- Spotify: OAuth2 token refresh via `refresh_spotify_tokens.py`
- Weather: OpenWeatherMap API with rate limiting
- StreamElements/StreamLabs: WebSocket for donation events

### Webhooks
- Patreon, Ko-fi, FourthWall: POST endpoints in API server
- Forward to WebSocket for processing

### File Storage
- Logs: Rotating file handlers in `logs/` with channel-specific subdirs
- Static assets: CDN-hosted files referenced by URL
- User uploads: Stored in database or object storage

## Key Files to Reference

- `bot/bot.py`: Main bot event loop and command handling
- `api/api.py`: FastAPI endpoints and webhook processing  
- `dashboard/dashboard.php`: Web interface structure
- `config/main.php`: Core configuration constants
- `websocket/server.py`: SocketIO event handling
- `app/app.py`: Desktop app UI and controls

## Common Patterns

- Command permissions: Check `await command_permissions(level, user)` before execution
- API key verification: `await verify_api_key(api_key)` returns username or None
- Premium features: Check `await check_premium_feature()` for subscription-gated functionality
- Timezone handling: Use `pytz` for user-specific timezone conversions
- Unit conversions: `pint.UnitRegistry` for measurements in commands

## Security Notes

- API keys validated on every request
- SSH connections for server management (use `paramiko`)
- Environment variables for all secrets
- Input sanitization for custom commands and user messages

## AI Assistant Guidelines

- Never make notes outside of the code base
- Never make MD note files unless asked to
- Always think before answering
- Always read the file before making any helpful changes
- Be aware of any imports for Python files before adding imports, ensure that we don't already have it covered