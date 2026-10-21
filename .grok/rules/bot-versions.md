# Bot Version Policy

Three Twitch bot files exist for a reason. Pick the right target before editing.

## The three versions

| File | Status | Edit when... |
| ---- | ------ | ------------ |
| `./bot/bot.py` | **STABLE** (v5.9, TwitchIO 2.10.0) | **Critical bug fix only.** Never add features here. |
| `./bot/beta.py` | **BETA** (v5.9, same code generation as stable, TwitchIO 2.10.0) | No new features. 5.9 has shipped. Mirror stable critical fixes here. Channels still on beta, and beta custom-name launches, run this file. |
| `./bot/beta-v6.py` | **REWRITE / day-to-day** (v6.0.0, TwitchIO **3.3.2**) | New features and normal day-to-day work. TwitchIO 3.x (`from twitchio import eventsub`). Docs: https://twitchio.dev/en/stable/ |

## Companion bots (separate files, separate platforms)

- `./bot/specterdiscord.py` - Discord bot (discord.py)
- `./bot/kick.py` - Kick.com bot

These share the same MySQL database and WebSocket channel as the Twitch bot but run as their own processes.

## Version number rules (HARD)

| Track | Version string | When it changes |
| ----- | -------------- | --------------- |
| **Stable** (`bot.py`) | `5.9`, then patch bumps (`5.9` → `5.9.1`) | **Every** stable fix — see `project_stable_version_bump`. `docs/5.9.md` is the shipped 5.9 release (2026-10-21). Later patches get their own `docs/<ver>.md`. Do not append them to `docs/5.9.md`. |
| **Beta** (`beta.py`) | **frozen** at `5.9` | **NEVER.** 5.9 shipped as stable. Do not add features, and do not append new notes to `docs/5.9.md`. |
| **V6** (`beta-v6.py`) | **frozen** at `6.0.0` | Day-to-day work lives here. Do **not** invent `6.0.1` / `6.0.2` / etc. Do not mention v6 in a stable changelog. |

Also do **not** bump `api/versions.json` → `beta_version` or `v6_version` for reconnect/hotfix work. Stable-only bumps update `stable_version`. Companion bots (Discord/Kick) have their own version lines and may bump when those products ship.

## Rules

1. **Never copy a feature into `bot.py` unless it's a critical fix.** If unsure, ask.
2. **If a fix is needed in stable, also apply it to beta and beta-v6.** Stable bug fixes do not auto-propagate.
3. **TwitchIO API differs between 2.10 and 3.3.2.** Don't assume a beta.py change drops cleanly into beta-v6.py - check the TwitchIO version before porting. EventSub models are `from twitchio import eventsub` (not `twitchio.ext.eventsub`).
4. **Bot scripts take CLI args** (`-channel`, `-channelid`, `-token`, `-refresh`). Don't hardcode these.
5. **Token refresh for Twitch *user* tokens is in-process** (`twitch_token_refresh()` background task in bot.py). There is **no** `refresh_twitch_tokens.py` for user tokens. The shared Specter **app** access token (`website.bot_chat_token`, Helix Send Chat Message) is checked by `./bot/refresh_twitch_app_token.py` via `token_refresh_scheduler`. Companion scripts: `refresh_custom_bot_tokens.py`, `refresh_spotify_tokens.py`, `refresh_streamelements_tokens.py`, `refresh_discord_tokens.py`.
6. **Beta and V6 version strings never change** on routine fixes (see table above). Only stable gets patch bumps + public `docs/<ver>.md` releases.
7. **5.9 is the shipped stable release.** New features go in `./bot/beta-v6.py` only. A stable critical fix still lands in `./bot/bot.py` and is mirrored to `./bot/beta.py` and `./bot/beta-v6.py`.
