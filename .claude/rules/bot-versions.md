# Bot Version Policy

Three Twitch bot files exist for a reason. Pick the right target before editing.

## The three versions

| File | Status | Edit when... |
| ---- | ------ | ------------ |
| `./bot/bot.py` | **STABLE** (v5.7.7, TwitchIO 2.10.0) | **Critical bug fix only.** Never add features here. |
| `./bot/beta.py` | **BETA** (v5.8, TwitchIO 2.10.0) | New features, normal day-to-day work. |
| `./bot/beta-v6.py` | **REWRITE** (v6.0, TwitchIO 3.2.2) | Forward-looking work using the new TwitchIO native EventSub. |

## Companion bots (separate files, separate platforms)

- `./bot/specterdiscord.py` — Discord bot (discord.py)
- `./bot/kick.py` — Kick.com bot

These share the same MySQL database and WebSocket channel as the Twitch bot but run as their own processes.

## Rules

1. **Never copy a feature into `bot.py` unless it's a critical fix.** If unsure, ask.
2. **If a fix is needed in stable, also apply it to beta and beta-v6.** Stable bug fixes do not auto-propagate.
3. **TwitchIO API differs between 2.10 and 3.2.2.** Don't assume a beta.py change drops cleanly into beta-v6.py — check the TwitchIO version before porting.
4. **Bot scripts take CLI args** (`-channel`, `-channelid`, `-token`, `-refresh`). Don't hardcode these.
5. **Token refresh for Twitch is in-process** (`twitch_token_refresh()` background task in bot.py). There is **no** `refresh_twitch_tokens.py` — only `refresh_custom_bot_tokens.py`, `refresh_spotify_tokens.py`, `refresh_streamelements_tokens.py`, `refresh_discord_tokens.py`.
