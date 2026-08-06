---
name: project_v6_module_host_parity
description: "BETA→V6 custom channel module host call-site parity checklist (websocket_notice intercept, CP, chat, Stream Bingo)"
metadata:
  node_type: memory
  type: project
---

# BETA → V6 custom module host parity

**Last live verify**: 2026-08-06 against `./bot/beta.py` and `./bot/beta-v6.py`.

Custom channel modules (`custom_channel_modules/{channel}.py`, opt-in via `-load-custom-module` + dashboard `users.use_custom_module`) only work if the **bot host** dispatches events and commands into loaded modules. Load/helpers looking “done” is not enough — **runtime call sites** must stay in sync between beta and v6.

## Host call sites that must stay in sync

| Site | What modules need | beta / beta-v6 pattern |
| ---- | ----------------- | ---------------------- |
| `websocket_notice` | Fan-out + optional intercept | Params `interceptable=False`, `_http_only=False`. If not `_http_only`: build `_mod_kwargs` (incl. `additional_data` merge), then either `await dispatch_module_event` (interceptable → return handled + re-enter with `_http_only=True`) or `safe_create_task(dispatch_module_event)` (fire-and-forget). |
| `process_channel_point_rewards` | CP redeem intercept (bingo / Jester / etc.) | On `channel.channel_points_custom_reward_redemption.add`: `await websocket_notice(event="TWITCH_CHANNELPOINTS", rewards_data=..., additional_data={"username", "reward_title"}, interceptable=True)` and **return early if handled**. |
| Chat path (`event_message` / equivalent) | Chat hooks + module commands | `dispatch_module_event("chat_message", username=..., message=..., is_vip=..., broadcaster_id=...)` early; later `await dispatch_module_command(message=AuthorMessage, username=..., broadcaster_id=CHANNEL_ID)`. |
| `process_stream_bingo_message` | Stream Bingo overlay/module events | `websocket_notice` for at least `STREAM_BINGO_WINNER`; also `STREAM_BINGO_STARTED` / `STREAM_BINGO_ENDED` (+ EVENT_CALLED / EXTRA_CARD as beta does). |
| `stream_bingo_websocket` | PascalCase API keys | After `json.loads`: `data = {k.lower(): v for k, v in data.items()}` before process. |
| `_discover_channel_module_classes` | Single facade instance | Prefer `MODULE_CLASSES` / `CHANNEL_MODULE` / names **endswith `Module`**; do **not** instantiate every `claims_channel` class (submodules would double-load). |
| `dispatch_module_event` / `dispatch_module_command` | Handler routing | `handle_{event}` return-truthy = handled; commands via `is_module_command`/`handle_module_command` and bureau aliases. |

Also keep: module ready dispatch on connect, FIRST_CHAT interceptable path if beta has it, and bots-api `load_custom_module` CLI flag wiring.

## Why name-only audits fail

1. **Feature-shaped greps** (“does load_custom_module exist?”) miss **integration-shaped** call sites (`dispatch_module_*`, interceptable `websocket_notice`, CP early-return, chat hooks, Stream Bingo body).
2. **Same function names with stub bodies** read as present (especially Stream Bingo historically) — must open the body for `websocket_notice` / `k.lower()` / real event strings.
3. **Helper parity ≠ host parity**: `_discover_*` and dispatch helpers can match while event_message / CP / bingo never call them.
4. **TwitchIO 2 vs 3** renames (`handle_commands` → `process_commands`, `message.author` → `message.chatter`) can drop a dispatch line during ports without failing a symbol search for the helper name alone.

## Smoke tests (gfaundead)

Channel: **gfaundead**. Start **v6** with custom module load (dashboard opt-in + file `custom_channel_modules/gfaundead.py` on bot host; bots API start with `load_custom_module` when both true).

1. **Channel points (CP)** — Redeem a custom reward the module claims (title/username path). Expect module handler (`handle_twitch_channelpoints` / equivalent) to run; if it returns handled, default bot CP follow-through should not double-fire. Logs: module dispatch + interceptable TWITCH_CHANNELPOINTS.
2. **Bingo / module ready** — On bot ready with module loaded, expect module-ready / stream-online style dispatch (and any first-chat intercept if configured). Confirm module loaded once (*Module facade), not double-instantiated submodules.
3. **Stream Bingo winner** — With Stream Bingo API key in profile, run a game through **BINGO_REGISTERED**. Expect chat “BINGO! @…” and `websocket_notice` → `STREAM_BINGO_WINNER` (and ideally STARTED/ENDED on game lifecycle). If events never match types, check key lowercasing on inbound WS JSON.

## Verification greps (post-edit)

```
websocket_notice + interceptable + _http_only + dispatch_module_event
process_channel_point_rewards + interceptable + reward_title
dispatch_module_event("chat_message" + dispatch_module_command
process_stream_bingo_message + STREAM_BINGO_WINNER (+ STARTED/ENDED)
stream_bingo_websocket + k.lower()
_discover_channel_module_classes + endswith("Module")
python -c "import ast; ast.parse(open('bot/beta-v6.py', encoding='utf-8').read())"
```

Do **not** treat stable `bot.py` as module-host parity target unless product says otherwise (custom modules are beta/v6 opt-in).
