# Bot Version Policy

Three Twitch bot files exist for a reason. Pick the right target before editing.

## The three versions

| File | Status | Edit when... |
| ---- | ------ | ------------ |
| `./bot/bot.py` | **STABLE** (v5.7.17, TwitchIO 2.10.0) | **Critical bug fix only.** Never add features here. |
| `./bot/beta.py` | **BETA** (v5.8, TwitchIO 2.10.0) | New features, normal day-to-day work. |
| `./bot/beta-v6.py` | **REWRITE / beta track** (v6.0.0, TwitchIO **3.3.2**) | Forward-looking work using TwitchIO 3.x (`from twitchio import eventsub`). Docs: https://twitchio.dev/en/stable/ |

## Companion bots (separate files, separate platforms)

- `./bot/specterdiscord.py` - Discord bot (discord.py)
- `./bot/kick.py` - Kick.com bot

These share the same MySQL database and WebSocket channel as the Twitch bot but run as their own processes.

## Version number rules (HARD)

| Track | Version string | When it changes |
| ----- | -------------- | --------------- |
| **Stable** (`bot.py`) | patch bumps (`5.7.15` → `5.7.16`) | **Every** stable fix — see `project_stable_version_bump` |
| **Beta** (`beta.py`) | **frozen** at `5.8` | **NEVER** for day-to-day work. Changelog notes append to `docs/5.8.md` only. |
| **V6** (`beta-v6.py`) | **frozen** at `6.0.0` | **NEVER** for day-to-day work. Same rule as beta — it is a beta track, not a release train. Do **not** invent `6.0.1` / `6.0.2` / etc. |

Also do **not** bump `api/versions.json` → `beta_version` or `v6_version` for reconnect/hotfix work. Stable-only bumps update `stable_version`. Companion bots (Discord/Kick) have their own version lines and may bump when those products ship.

## Rules

1. **Never copy a feature into `bot.py` unless it's a critical fix.** If unsure, ask.
2. **If a fix is needed in stable, also apply it to beta and beta-v6.** Stable bug fixes do not auto-propagate.
3. **TwitchIO API differs between 2.10 and 3.3.2.** Don't assume a beta.py change drops cleanly into beta-v6.py - check the TwitchIO version before porting. EventSub models are `from twitchio import eventsub` (not `twitchio.ext.eventsub`).
4. **Bot scripts take CLI args** (`-channel`, `-channelid`, `-token`, `-refresh`). Don't hardcode these.
5. **Token refresh for Twitch is in-process** (`twitch_token_refresh()` background task in bot.py). There is **no** `refresh_twitch_tokens.py` - only `refresh_custom_bot_tokens.py`, `refresh_spotify_tokens.py`, `refresh_streamelements_tokens.py`, `refresh_discord_tokens.py`.
6. **Beta and V6 version strings never change** on routine fixes (see table above). Only stable gets patch bumps + public `docs/<ver>.md` releases.
