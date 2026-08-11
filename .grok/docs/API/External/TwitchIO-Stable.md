# TwitchIO 3.x Stable — Reference (project pin 3.3.2)

> **Last Updated:** 2026-08-11  
> **Source doc root:** <https://twitchio.dev/en/stable/>  
> **Authoritative pin:** `twitchio==3.3.2` in `./bot/v6_requirements.txt` (+ `twitchio[starlette]==3.3.2`)  
> **Audience:** agents working on `./bot/beta-v6.py`  
> **Related:** [TwitchIO-Historical.md](./TwitchIO-Historical.md) (2.10.0 for `bot.py` / `beta.py`), [twitch.md](./twitch.md) (Helix / OAuth / hand-rolled EventSub)

Local library reference for **TwitchIO 3.x stable**, scoped to what matters when porting or extending the v6 rewrite. Upstream claims are drawn from the stable docs tree. **Project-specific facts** (what `beta-v6` actually does) are labeled **Project** so they are not confused with pure library behaviour.

| File | TwitchIO | Requirements | Base class |
| ---- | -------- | ------------ | ---------- |
| `./bot/beta-v6.py` | **3.3.2** | `./bot/v6_requirements.txt` | `commands.AutoBot` |
| `./bot/bot.py` | 2.10.0 | `./bot/requirements.txt` | `commands.Bot` (IRC) |
| `./bot/beta.py` | 2.10.0 | `./bot/requirements.txt` | `commands.Bot` (IRC) |

---

## Table of contents

1. [Quick architecture snapshot](#1-quick-architecture-snapshot)
2. [Installing & debugging](#2-installing--debugging)
3. [Imports](#3-imports)
4. [Client / Bot / AutoBot / AutoClient](#4-client--bot--autobot--autoclient)
5. [Tokens, OAuth & web adapters](#5-tokens-oauth--web-adapters)
6. [Lifecycle events](#6-lifecycle-events)
7. [EventSub subscriptions](#7-eventsub-subscriptions)
8. [Events catalog](#8-events-catalog)
9. [Commands extension](#9-commands-extension)
10. [Users / PartialUser / Chatter](#10-users--partialuser--chatter)
11. [Helix models, enums & utils](#11-helix-models-enums--utils)
12. [Routines & overlays ext](#12-routines--overlays-ext)
13. [Exceptions](#13-exceptions)
14. [Changelog notes 3.1 → 3.3.2](#14-changelog-notes-31--332)
15. [Migration map 2.10 → 3.x](#15-migration-map-210--3x)
16. [Project mapping (beta-v6)](#16-project-mapping-beta-v6)
17. [Gotchas](#17-gotchas)
18. [Upstream anchors](#18-upstream-anchors)

---

## 1. Quick architecture snapshot

| Concept | 2.10 (`bot.py` / `beta.py`) | 3.x (`beta-v6`) |
| ------- | --------------------------- | --------------- |
| Chat transport | IRC (`initial_channels`, join/part) | **EventSub only** (WS / webhook / **Conduits**) |
| Bot base | `commands.Bot` | Prefer **`commands.AutoBot`** (Bot + AutoClient) |
| EventSub models | `twitchio.ext.eventsub` (2.x ext; stable/beta hand-roll WS) | **`from twitchio import eventsub`** |
| Message text | IRC `.content` | **`ChatMessage.text`**; Context uses **`ctx.content`** for command text |
| IDs | Often `int` | **Always `str`** on models |
| Tokens | Constructor `token=` / manual refresh | `add_token` / `load_tokens` / `save_tokens`, auto-refresh |
| Start | `bot.run()` | `async with Bot() as bot: await bot.start()` |
| PubSub / sounds | Extensions | **Removed** (PubSub shut down by Twitch; sounds → future Overlays) |
| Min Python | 3.7+ | **3.11+** |

**Project:** `beta-v6` uses native TwitchIO only for **chat** (`ChatMessageSubscription` → `event_message` + `process_commands`). Follows, raids, subs, redeems, stream online/offline, etc. stay on the **hand-rolled** EventSub WebSocket (`twitch_eventsub` / `subscribe_to_events`), same control plane as stable/beta.

---

## 2. Installing & debugging

### Python support

| Python | Status |
| ------ | ------ |
| ≤ 3.10 | **Not supported** |
| 3.11–3.14 | Fully supported |
| ≥ 3.15 | May need custom pip index for prebuilt wheels |

### Base install

```bash
pip install -U twitchio
# Project pin:
pip install twitchio==3.3.2
pip install "twitchio[starlette]==3.3.2"   # optional ASGI adapter; beta-v6 starts with_adapter=False
```

### Optional extras

| Extra | Command | Purpose |
| ----- | ------- | ------- |
| **starlette** | `pip install -U twitchio[starlette]` | `twitchio.web.StarletteAdapter` (Starlette ≥1.0.0 as of 3.3.0) |
| **dev** / **docs** | `twitchio[dev]` / `twitchio[docs]` | Tooling / docs build |

Default web adapter is **AiohttpAdapter** (no extra). **Removed vs 2.x:** `[sounds]`, `[speed]`.

**Python ≥ 3.15 custom index:**

```bash
pip install -U twitchio --extra-index-url https://abstractumbra.github.io/pip/
```

**Version dump:**

```bash
# Windows
py -m twitchio --version
# Linux
python -m twitchio --version
```

### Debugging

```python
import logging
import twitchio

handler = logging.FileHandler(filename="twitchio.log", encoding="utf-8", mode="w")
twitchio.utils.setup_logging(level=logging.DEBUG, handler=handler)
```

Call `setup_logging()` once before start. Prefer logging over `print`.

---

## 3. Imports

```python
import twitchio
from twitchio import eventsub          # 3.x — NOT twitchio.ext.eventsub
from twitchio.ext.commands import Context
from twitchio.ext import commands, routines
```

**Project** (`./bot/beta-v6.py` imports):

```python
from twitchio import eventsub
from twitchio.ext.commands import Context
from twitchio.ext import commands, routines
```

Subscription constructors live on `twitchio.eventsub` (e.g. `eventsub.ChatMessageSubscription`). Event **payload** models are re-exported as top-level `twitchio.ChatMessage`, `twitchio.ChannelFollow`, etc.

---

## 4. Client / Bot / AutoBot / AutoClient

### Hierarchy

```text
twitchio.Client
  └── twitchio.ext.commands.Bot          # commands, Components, modules
        (AutoClient path:)
twitchio.AutoClient                      # Conduit + multi_subscribe
  └── twitchio.ext.commands.AutoBot      # Bot + AutoClient  ← beta-v6
```

| Class | Import | When to use |
| ----- | ------ | ----------- |
| `Client` | `twitchio.Client` | Helix/HTTP only, no chat commands |
| `Bot` | `from twitchio.ext import commands` → `commands.Bot` | Commands + manual `subscribe_websocket` / `subscribe_webhook` |
| `AutoBot` | `commands.AutoBot` | **Multi-channel / Conduit continuity** (docs preferred) |
| `AutoClient` | `twitchio.AutoClient` | Conduits without commands ext |

`Bot` subclasses `Client` — everything on `Client` is on `Bot`. `AutoBot` = `Bot` + `AutoClient`.

### Recommended start (upstream)

```python
import asyncio
import twitchio

if __name__ == "__main__":
    async def main() -> None:
        twitchio.utils.setup_logging()
        async with Bot() as bot:
            await bot.start()

    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        ...
```

- Prefer **async context manager** for clean shutdown + token save.
- `setup_hook()` runs after login, before full ready — load Components / modules / late subs here.
- App token is **auto-generated** on start; rarely pass one to `start()` / `login()` / `run()`.
- **Do not** call `login()` if using `start()`/`run()` — they call it.
- **`wait_until_ready()` inside `setup_hook` deadlocks.**

### `Client` constructor (key params)

| Param | Notes |
| ----- | ----- |
| `client_id` | Twitch Developer Portal app client ID |
| `client_secret` | Same app; optional only for **DCF** (3.3.0+) |
| `bot_id` | Bot account user ID; highly recommended; enables bot-owned tokens |
| `redirect_uri`, `scopes`, `session`, `adapter`, `fetch_client_user` | Options |

### `Bot` constructor

| Param | Notes |
| ----- | ----- |
| `client_id`, `client_secret` | App credentials (`client_secret` optional on Bot for DCF) |
| `bot_id` | **Required** on `Bot` (docs; DCF may relax) |
| `owner_id` | Optional owner user ID |
| `prefix` | `str` \| iterable \| **async** callable `(bot, message: ChatMessage) -> str \| list[str]` |

### `AutoBot` / `AutoClient` extra params

| Param | Default | Role |
| ----- | ------- | ---- |
| `subscriptions` | `[]` | List of `eventsub.SubscriptionPayload` |
| `force_subscribe` | `False` | If `True`, subscribe every start even when reusing Conduit (**3.1.0**) |
| `force_scale` | `False` | Force Conduit shard count to `len(shard_ids)` (**3.1.0**) |
| `conduit_id` | `None` | `None` = auto one-or-create; `str` = specific Conduit; `True` = always create new |
| `shard_ids` | auto | Explicit shard ownership for multi-process |
| `max_per_shard` | `1000` | Shard count ≈ `max(2, len(subs)/max_per_shard)` when `shard_ids` omitted |

**Conduit continuity:** subscriptions survive disconnect **up to 72 hours**. Reconnect within 72h → no resubscribe. After 72h → need `subscriptions` / `multi_subscribe` / `force_subscribe`.

**Constructor `subscriptions` auto-apply only when a NEW conduit is created** unless `force_subscribe=True`.

Conduit subscriptions use **App Access Tokens**. Users must still authorize the Client-ID with required scopes.

### Important methods

| Method | Class | Notes |
| ------ | ----- | ----- |
| `setup_hook()` | Client/Bot | Async setup after login |
| `start(load_tokens=True, save_tokens=True, with_adapter=True, ...)` | Client | Main entry; AutoBot loads conduit |
| `run(...)` | Client | Creates loop; **cannot** use inside running loop |
| `login()` / `login_dcf()` / `start_dcf()` | Client | DCF = Device Code Flow (**3.3.0**); DCF forbids `client_secret` |
| `add_token(token, refresh)` | Client | Both required; returns `ValidateTokenPayload`; always `await super().add_token` if overriding |
| `load_tokens()` / `save_tokens()` | Client | Default file **`.tio.tokens.json`**; runs load **before** `setup_hook` |
| `subscribe_websocket(payload, *, as_bot=..., token_for=...)` | Client/Bot | `as_bot` defaults **True** on Bot, **False** on bare Client |
| `subscribe_webhook(payload, ...)` | Client/Bot | Docs: **not** for chat messages |
| `multi_subscribe(subs, *, wait=True, stop_on_error=False)` | AutoClient/AutoBot | Bulk conduit subs → `MultiSubscribePayload` |
| `delete_websocket_subscription` | Client | **Not implemented** on AutoClient/AutoBot |
| `add_component(component)` | Bot | Loads commands + `@Component.listener()` |
| `load_module` / `unload_module` / `reload_module` | Bot | **Coroutines** in 3.x; need `async def setup(bot)` |
| `create_partialuser(user_id, user_login=None)` | Client | Renamed from `create_user` |
| `wait_until_ready()` | Client | Was `wait_for_ready` |
| `wait_for(event, *, predicate=..., timeout=...)` | Client | Event name **without** `event_` prefix; `predicate` **async**, keyword-only |
| `safe_dispatch(name, *, payload=None)` | Client | Dispatches as `event_safe_{name}`; 0 or 1 arg |

### Conduit models (brief)

| Type | Notes |
| ---- | ----- |
| `ConduitInfo` | Via `auto.conduit_info` — `update_shard_count(shard_count, *, assign_transports=True)` rewires AutoClient websockets |
| `Conduit` | `delete()`, `update(shard_count)` — **API-only** scale; does **not** rebind AutoClient websockets |
| `ConduitShard` | `id`, `method`, `session_id`, `status`, … |
| `MultiSubscribePayload` | `success: list[MultiSubscribeSuccess]`, `errors: list[MultiSubscribeError]` |

**Shard status values (selected):** `enabled`, `websocket_disconnected`, `websocket_failed_ping_pong`, `websocket_failed_to_reconnect`, `notification_failures_exceeded`, webhook verification states, etc.

**MissingConduit** if `multi_subscribe` / `update_shard_count` before conduit assignment.

### AutoClient setup cases

| Case | Config | Behaviour |
| ---- | ------ | --------- |
| **1 (common)** | `conduit_id=None` | Exactly 1 conduit → attach + keep subs; 0 → create. Single process. |
| **2** | `conduit_id="…"` + optional `shard_ids` | Pin conduit; multi-process split shards |
| **3** | `conduit_id=True` | Force-create new conduit |

---

## 5. Tokens, OAuth & web adapters

### Managed token API

| Piece | Names |
| ----- | ----- |
| Web adapters | `twitchio.web.AiohttpAdapter`, `twitchio.web.StarletteAdapter` |
| Token API | `Client.tokens`, `add_token`, `remove_token`, `load_tokens`, `save_tokens` |
| Events | `event_oauth_authorized(payload)`, `event_token_refreshed(...)` |
| Scopes helper | `twitchio.Scopes` (+ `Scopes.from_url()` in 3.2.0) |

| Item | Value |
| ---- | ----- |
| OAuth redirect (Dev Console) | `http://localhost:4343/oauth/callback` |
| Authorize URL | `http://localhost:4343/oauth?scopes=...` |
| Token file | `.tio.tokens.json` (on graceful close) |
| Helix auth | `token_for=` (user id / PartialUser); many methods accept **`token_for=None`** for app token (3.3.0+) |

### Adapter defaults

| Path | Purpose |
| ---- | ------- |
| `/oauth?scopes=…` | Start OAuth |
| `/oauth/callback` | Redirect URI |
| `/callback` | EventSub webhook |

Adapter starts by default; pass `with_adapter=False` to disable. Without public `domain`, webhooks unsupported. Persist `eventsub_secret` or it regenerates every restart.

### Custom token storage (upstream pattern)

Override `add_token` → `await super().add_token(token, refresh)` then write DB. On boot: load DB pairs, `await bot.add_token(*pair)`, then `await bot.start(load_tokens=False)`.

Force-kill during save can wipe `.tio.tokens.json` — prefer SQL in production.

### Device Code Flow (3.3.0)

| Method | Notes |
| ------ | ----- |
| `login_dcf(...)` | Call before `start_dcf`. Raises if `client_secret` present. |
| `start_dcf(...)` | Completes DCF. Refresh tokens single-use, ~30 days. |

### **Project** token behaviour

- CLI `-token` / `-refresh`; **no** `.tio.tokens.json`.
- Bootstrap: `await bot.add_token(BOT_OAUTH_TOKEN, REFRESH_TOKEN)` then `await bot.start(load_tokens=False, save_tokens=False, with_adapter=False)`.
- `event_token_refreshed` updates `CHANNEL_AUTH` when `user_id` matches channel or bot.
- Parallel task `twitch_token_refresh()` persists MySQL `website.twitch_bot_access` (same dual-refresh idea as stable/beta).

---

## 6. Lifecycle events

### Design rules (3.x)

| Rule | Detail |
| ---- | ------ |
| **Arity** | Almost all events take **exactly one** argument: payload (or `Context`). Exception: **`event_ready()` takes zero args**. |
| **Transport** | Chat and most “IRC-era” events are **EventSub only**. No `event_join` / `event_part` / `event_raw_*` / `event_usernotice_*`. |
| **Subscription required** | EventSub-backed events need `subscribe_websocket` / `subscribe_webhook` / Conduit `multi_subscribe` / AutoBot constructor subs **per broadcaster**. |
| **V1+V2 duals** | Some Twitch types share one event name (Automod Hold V1/V2 → `event_automod_message_hold`; Moderate V1/V2 → `event_mod_action`). |
| **wait_for** | Name **without** `event_` prefix (for `event_message` prefer `"message"`). `predicate` async + keyword-only. |

### Client events

| Event | Signature | Notes |
| ----- | --------- | ----- |
| `event_ready` | `async def event_ready() -> None` | After login; tokens loaded; `setup_hook` finished |
| `event_error` | `async def event_error(payload: EventErrorPayload)` | Exception in listener; errors **inside** this handler do not re-fire it |
| `event_token_refreshed` | `async def event_token_refreshed(payload: TokenRefreshedPayload)` | Persist new access/refresh |
| `event_oauth_authorized` | `async def event_oauth_authorized(payload: UserTokenPayload)` | Default calls `add_token` |
| `event_subscription_revoked` | `async def event_subscription_revoked(payload: SubscriptionRevoked)` | One-shot; statuses include `user_removed`, `authorization_revoked`, `version_removed`; webhook also `notification_failures_exceeded` |
| `event_websocket_welcome` | `async def event_websocket_welcome(payload: WebsocketWelcome)` | EventSub/Conduit WS connected |

**Payload attrs:**

| Class | Attributes |
| ----- | ---------- |
| `EventErrorPayload` | `error`, `listener`, `original` |
| `TokenRefreshedPayload` | `user_id`, `refresh_token`, `token`, `scopes`, `expires_in` |

### Commands events

| Event | Signature | Notes |
| ----- | --------- | ----- |
| `event_command_invoked` | `(ctx: Context)` | Command starts |
| `event_command_completed` | `(ctx: Context)` | Successful completion |
| `event_command_error` | `(payload: CommandErrorPayload)` | **Not** `(ctx, error)`. Use `payload.context` / `payload.exception` |

**Removed with IRC:** `event_join`, `event_part`, `event_raw_data`, `event_notice`, `event_usernotice_*`, `event_userstate`, `event_mode`, `event_reconnect`, `event_token_expired` (use managed refresh + `event_token_refreshed`).

---

## 7. EventSub subscriptions

Import: `from twitchio import eventsub`. All condition classes inherit `SubscriptionPayload`. IDs accept `str | PartialUser`.

### Chat / bits / power-ups

| Class | Twitch type | Ver | Condition keys | Scope notes |
| ----- | ----------- | --- | -------------- | ----------- |
| `ChatMessageSubscription` | `channel.chat.message` | 1 | `broadcaster_user_id`, `user_id` (bot) | `user:read:chat`; app: +`user:bot` and `channel:bot` or mod |
| `ChatNotificationSubscription` | `channel.chat.notification` | 1 | same | same |
| `ChatMessageDeleteSubscription` | `channel.chat.message_delete` | 1 | same | same |
| `ChatClearSubscription` | `channel.chat.clear` | 1 | same | same |
| `ChatClearUserMessagesSubscription` | `channel.chat.clear_user_messages` | 1 | same | same |
| `ChatSettingsUpdateSubscription` | `channel.chat_settings.update` | 1 | same | same |
| `ChatUserMessageHoldSubscription` | `channel.chat.user_message_hold` | 1 | same | `user:read:chat` |
| `ChatUserMessageUpdateSubscription` | `channel.chat.user_message_update` | 1 | same | same |
| `ChannelBitsUseSubscription` | `channel.bits.use` | 1 | `broadcaster_user_id` | **`bits:read`**; cheer · power_up · custom_power_up; **not** free streamer self power-up |
| `CustomPowerupRedeemAddSubscription` | `channel.custom_power_up_redemption.add` | 1 | broadcaster, optional `reward_id` | `bits:read` (**3.3.0**) |
| `ChannelCheerSubscription` | `channel.cheer` | 1 | broadcaster | `bits:read` |
| `WhisperReceivedSubscription` | `user.whisper.message` | 1 | user | whispers |

### Channel points

| Class | Type | Ver | Notes |
| ----- | ---- | --- | ----- |
| `ChannelPointsAutoRedeemSubscription` | `…automatic_reward_redemption.add` | **1** | **Power-ups** only on V1 |
| `ChannelPointsAutoRedeemV2Subscription` | same | **2** | Fragments; **does not** notify power-up types |
| `ChannelPointsRewardAdd/Update/RemoveSubscription` | custom reward lifecycle | 1 | optional `reward_id` on update/remove |
| `ChannelPointsRedeemAdd/UpdateSubscription` | redemption add/update | 1 | optional `reward_id` |

Scope: `channel:read:redemptions` or `channel:manage:redemptions`.

### Follows / stream / subs / raids / ads / update

| Class | Type | Ver | Condition / scope |
| ----- | ---- | --- | ----------------- |
| `ChannelFollowSubscription` | `channel.follow` | **2** | broadcaster + **`moderator_user_id`**; `moderator:read:followers` |
| `ChannelUpdateSubscription` | `channel.update` | **2** | broadcaster |
| `AdBreakBeginSubscription` | `channel.ad_break.begin` | 1 | `channel:read:ads` |
| `ChannelSubscribeSubscription` | `channel.subscribe` | 1 | **new subs only** (not resubs) |
| `ChannelSubscriptionEndSubscription` | `channel.subscription.end` | 1 | |
| `ChannelSubscriptionGiftSubscription` | `channel.subscription.gift` | 1 | |
| `ChannelSubscribeMessageSubscription` | `channel.subscription.message` | 1 | **resub chat** |
| `ChannelRaidSubscription` | `channel.raid` | 1 | **exactly one** of `to_` / `from_broadcaster_user_id` |
| `StreamOnlineSubscription` / `StreamOfflineSubscription` | `stream.online` / `stream.offline` | 1 | broadcaster |

### Moderation / VIP / warnings / automod / shared chat / engagement

| Area | Classes (summary) |
| ---- | ----------------- |
| Ban/unban | `ChannelBanSubscription`, `ChannelUnbanSubscription`, unban request create/resolve |
| Moderate | `ChannelModerateSubscription` (v1), `ChannelModerateV2Subscription` (**+ warnings scopes**) |
| Mod/VIP | Moderator add/remove, VIP add/remove |
| Warnings | `ChannelWarningSendSubscription`, `ChannelWarningAcknowledgementSubscription` |
| Suspicious | `SuspiciousUserMessageSubscription`, `SuspiciousUserUpdateSubscription` |
| Automod | Hold/Update V1+V2, Settings, Terms |
| Shared chat | Begin / Update / End |
| Polls / predictions | Begin / Progress / Lock / End as applicable |
| Hype train | Begin / Progress / End |
| Goals / charity / shield / shoutout | Begin/progress/end variants |
| User | `UserUpdateSubscription`, auth grant/revoke |

### Payload base helpers

| Attr / method | Notes |
| ------------- | ----- |
| `.timestamp`, `.metadata`, `.headers`, `.subscription_data` | Transport metadata |
| `async respond(content, *, me=False, token_for=...)` | **3.1+**. Needs `bot_id`; `user:write:chat` (+ bot scopes for app token); max 500 chars |

Many broadcaster-scoped subs set `default_auth` toward **broadcaster** token (`as_bot=False`) — wrong token fails at Twitch.

### **Project** EventSub usage

Native library subscription (only):

```python
# ./bot/beta-v6.py main()
subs = [eventsub.ChatMessageSubscription(broadcaster_user_id=CHANNEL_ID, user_id=bot_user_id)]
```

Hand-rolled path (everything else):

| Piece | Location |
| ----- | -------- |
| Conduit get/create | `get_or_create_conduit` in `./bot/beta-v6.py` |
| WebSocket loop | `twitch_eventsub` |
| Subscription POSTs | `subscribe_to_events` |
| Message processor | `process_twitch_eventsub_message` (and related) |

When adding a topic: add to `subscribe_to_events()` (same shape as beta). Do **not** assume `event_follow` / `event_raid` fire natively. Full native migration requires both an `eventsub.*Subscription` in `subs`/`multi_subscribe` **and** an `event_*` handler, with the hand-rolled topic removed (avoid duplicate dispatch).

---

## 8. Events catalog

Subscribe with classes under `twitchio.eventsub`. Handlers: `async def event_*(payload) -> None` unless noted.

### Automod

| Subscription | Event | Payload |
| ------------ | ----- | ------- |
| `AutomodMessageHold(Subscription|V2Subscription)` | `event_automod_message_hold` | `AutomodMessageHold` |
| `AutomodMessageUpdate(Subscription|V2Subscription)` | `event_automod_message_update` | `AutomodMessageUpdate` |
| `AutomodSettingsUpdateSubscription` | `event_automod_settings_update` | `AutomodSettingsUpdate` |
| `AutomodTermsUpdateSubscription` | `event_automod_terms_update` | `AutomodTermsUpdate` |

### Bans / channel / points / charity / goals / hype

| Type | Subscription | Event | Payload |
| ---- | ------------ | ----- | ------- |
| Ban / Unban | `ChannelBan` / `ChannelUnban` | `event_ban` / `event_unban` | `ChannelBan` / `ChannelUnban` |
| Unban request | Create / Resolve | `event_unban_request` / `event_unban_request_resolve` | request models |
| Channel update | `ChannelUpdateSubscription` | `event_channel_update` | `ChannelUpdate` |
| Follow | `ChannelFollowSubscription` | `event_follow` | `ChannelFollow` |
| Ad break | `AdBreakBeginSubscription` | **`event_ad_break`** | `ChannelAdBreakBegin` |
| Cheer | `ChannelCheerSubscription` | `event_cheer` | `ChannelCheer` |
| Raid | `ChannelRaidSubscription` | `event_raid` | `ChannelRaid` |
| Auto redeem V1/V2 | AutoRedeem* | `event_automatic_redemption_add` | `ChannelPointsAutoRedeemAdd` |
| Custom reward | Add/Update/Remove | `event_custom_reward_*` | reward models |
| Redemption | Add/Update | `event_custom_redemption_add` / `_update` | redemption models |
| Custom power-up | `CustomPowerupRedeemAddSubscription` | `event_custom_power_up_redemption_add` | `CustomPowerupRedemptionAdd` |
| Charity | donate/start/progress/stop | `event_charity_campaign_*` | charity models |
| Goal | begin/progress/end | `event_goal_*` | goal models |
| Hype train begin | `HypeTrainBeginSubscription` | **`event_hype_train`** (not `_begin`) | `HypeTrainBegin` |
| Hype train progress/end | Progress/End | `event_hype_train_progress` / `_end` | progress/end models |

### Chat

| Type | Subscription | Event | Payload |
| ---- | ------------ | ----- | ------- |
| Chat message | `ChatMessageSubscription` | **`event_message`** | `ChatMessage` |
| Message delete | `ChatMessageDeleteSubscription` | `event_message_delete` | `ChatMessageDelete` |
| Chat clear | clear / clear user | `event_chat_clear` / `event_chat_clear_user` | clear models |
| Notification | `ChatNotificationSubscription` | `event_chat_notification` | `ChatNotification` |
| Settings | `ChatSettingsUpdateSubscription` | `event_chat_settings_update` | `ChatSettingsUpdate` |
| User msg hold/update | hold/update | `event_chat_user_message_hold` / `_update` | hold/update models |
| Whisper | `WhisperReceivedSubscription` | `event_message_whisper` | `Whisper` |
| Bits use | `ChannelBitsUseSubscription` | `event_bits_use` | `ChannelBitsUse` |

### Moderation / VIP / polls / predictions / shared / shield / shoutouts / subs / streams / suspicious / user

| Type | Event |
| ---- | ----- |
| Moderate V1/V2 | **`event_mod_action`** (`ChannelModerate`) |
| Moderator add/remove | `event_moderator_add` / `_remove` |
| VIP add/remove | `event_vip_add` / `_remove` |
| Warning send/ack | `event_warning_send` / `event_warning_acknowledge` |
| Polls | `event_poll_begin` / `_progress` / `_end` |
| Predictions | `event_prediction_begin` / `_progress` / `_lock` / `_end` |
| Shared chat | `event_shared_chat_begin` / `_update` / `_end` |
| Shield | `event_shield_mode_begin` / `_end` |
| Shoutout | `event_shoutout_create` / `_receive` |
| Subscribe (new only) | `event_subscription` |
| Sub end / gift / resub message | `event_subscription_end` / `_gift` / `_message` |
| Stream online/offline | `event_stream_online` / `event_stream_offline` |
| Suspicious | `event_suspicious_user_message` / `_update` |
| User auth / update | `event_user_authorization_grant` / `_revoke` / `event_user_update` |

### Complete unique `event_*` names (library)

**Client (6):** `event_ready`, `event_error`, `event_token_refreshed`, `event_oauth_authorized`, `event_subscription_revoked`, `event_websocket_welcome`

**Commands (3):** `event_command_invoked`, `event_command_completed`, `event_command_error`

**EventSub (~70):** automod×4, ban/unban/unban_request×4, channel_update/follow/ad_break/cheer/raid, points×7 (+ power-up), charity×4, chat×10, goals×3, hype×3, mod_action + mod/vip/warn×6, poll×3, prediction×4, shared_chat×3, shield×2, shoutout×2, subscription×4, stream×2, suspicious×2, user auth×2, user_update.

---

## 9. Commands extension

Import: `from twitchio.ext import commands`.

### Bot vs AutoBot (commands surface)

| | `Bot` | `AutoBot` |
| - | ----- | -------- |
| Inheritance | `Client` + commands | Bot + `AutoClient` |
| `client_secret` | Optional (DCF) | **Required** |
| `bot_id` | Required (str) | Required |
| Conduit | Manual WS/webhook | `conduit_info`, `multi_subscribe` |
| `delete_websocket_subscription` | Client has it | **Not implemented** |

Chat commands need **`ChatMessageSubscription`** + transport. On Bot, `subscribe_websocket(..., as_bot=True)` by default. Webhooks discouraged for chat.

### Context (`commands.Context`)

Built from `ChatMessage` **or** channel-point redemption payloads.

| Property | Notes |
| -------- | ----- |
| **`content`** | Documented raw message / command text; for rewards = `user_input`. **Library Context field is `content` (not `.text`)** |
| `message` | `ChatMessage \| None` — body is **`message.text`** |
| `redemption` | Redemption payload if reward context |
| `payload` | Underlying message or redemption |
| `type` | `ContextType.MESSAGE` or `ContextType.REWARD` |
| `chatter` / `author` | Same object; reward redeemer may be bare `PartialUser` |
| `broadcaster` / `channel` | Channel owner (`channel` alias) |
| `source_broadcaster` | Shared-chat origin; usually `None` (shared msgs ignored by default) |
| `bot`, `prefix`, `command`, `invoked_with`, `args`, `kwargs`, `failed`, … | Invocation state |
| `await send` / `reply` / `send_translated` / `reply_translated` | Chat helpers |
| `await send_announcement` / `delete_message` / `clear_messages` | Moderation helpers |
| `is_owner()` / `is_valid()` | Checks |

**Reward contexts:** `type=REWARD`, `content=user_input`, `prefix=None`, no groups/aliases on `RewardCommand`.

### Command / Group / RewardCommand

| Symbol | Notes |
| ------ | ----- |
| `@commands.command()` | Chat command; aliases, converters, guards, cooldowns |
| `@commands.group()` | Nested groups; `invoke_fallback`, `apply_cooldowns`, `apply_guards` |
| `@commands.reward_command()` | Channel points; `RewardStatus`: `unfulfilled` / `fulfilled` / `canceled` / `all` |
| `@commands.cooldown(...)` | Cooldown decorator |
| `Command.signature` / `parameters` / `help` | **3.1+** |
| `Command.run_guards(ctx, *, with_cooldowns=False)` | Dry-run; `with_cooldowns=True` **mutates** buckets |

**Invoke hook order:** Bot → Component → command-local. `before_invoke` only after parse+guards; **`after_invoke` still runs if command body fails**.

**3.3.0:** commands can be invoked via **reply**.

### Components

| Item | Detail |
| ---- | ------ |
| Purpose | Cog-like groups of commands + listeners |
| Add | `await bot.add_component(...)` |
| `__init__` | **Do not call `super().__init__()`** if overriding |
| Listeners | `@Component.listener()` methods named `event_*` |
| Guards | `@Component.guard()` applied to all component commands |
| Errors | `component_command_error(payload)`; return **`False`** to stop propagation |
| Load fail | `component_load` error rolls back load |

### Guards

| Guard | Role |
| ----- | ---- |
| `commands.guard()` | Custom predicate → `GuardFailure` on fail |
| `is_owner()` | Bot owner |
| `is_staff()` | Twitch staff |
| `is_broadcaster()` | Channel owner |
| `is_lead_moderator()` | Lead mod (**3.2+**) |
| `is_moderator()` | Moderator |
| `is_vip()` | VIP |
| `is_elevated()` | Elevated bundle |
| `Bot.global_guard` | **Must be async**; runs first; bypass via `bypass_global_guards` |

`CommandOnCooldown` subclasses `GuardFailure`; **`.remaining`** is float seconds (was `.retry_after` in 2.10).

### Exception tree (commands)

```text
CommandError
├── ComponentLoadError
├── CommandInvokeError  → .original
│   └── CommandHookError
├── CommandNotFound
├── CommandExistsError
├── PrefixError
├── InputError
│   └── ArgumentError
│       ├── ConversionError → BadArgument
│       ├── MissingRequiredArgument
│       ├── UnexpectedQuoteError
│       ├── InvalidEndOfQuotedStringError
│       └── ExpectedClosingQuoteError
├── GuardFailure
│   └── CommandOnCooldown  # .cooldown, .remaining
└── TranslatorError  → .original

ModuleError
├── ModuleLoadFailure
├── ModuleAlreadyLoadedError
├── ModuleNotLoadedError
└── NoEntryPointError
```

`CommandErrorPayload(context, exception)` for `event_command_error`.

### Converters & translators

`Converter`, `UserConverter`, `ColourConverter` / `ColorConverter`, `Translator`, `@commands.translator(...)`, `send_translated` / `reply_translated`.

### **Project** commands

- Commands live as methods on `TwitchBot(commands.AutoBot)`, not Components.
- `setup_hook` **manually** walks class members for `commands.Command`, sets `_injected=self`, `add_command` (TwitchIO 3.x registration workaround).
- Permissions via project helpers, **not** built-in Guards.
- Chat out via project Helix helper `send_chat_message` (not `ctx.send` for production consistency with stable/beta).
- `event_message` uses `message.text` / `message.chatter`, shared-chat filter on `source_broadcaster`, then `await self.process_commands(message)`.
- Cooldown path uses `error.remaining`.

---

## 10. Users / PartialUser / Chatter

**Hierarchy:** `User` → bases `PartialUser`. `Chatter` → bases `PartialUser`. Equality by `.id` (**always `str`**).

### Attributes

| Class | Key attrs |
| ----- | --------- |
| `PartialUser` | `id`, `name`, `display_name`, `mention` |
| `User` | + `type`, `broadcaster_type`, `description`, `profile_image`/`offline_image` as **`Asset`**, `email` (needs scope), `created_at` |
| `Chatter` | + `channel`, badge bools: `staff`, `admin`, `broadcaster`, `moderator`, `lead_moderator`, `vip`, `artist`, `founder`, `subscriber`, `partner`, `turbo`, `prime`, `no_audio`/`no_video`, `colour`/`color`, `badges` |

Upgrade: `await partial.user()` → full `User`.

### Bot-critical `PartialUser` Helix methods

#### Chat send / announce

| Method | Key params / notes |
| ------ | ------------------ |
| `send_message` | `(message, sender, *, token_for=None, reply_to_message_id=None, source_only=None, pin=None)` → `SentMessage`. Object is **destination**. Scope `user:write:chat`. Max 500 chars. Raises **`MessageRejectedError`** if Twitch drops. `pin=True` needs pin scope; 20 min; no combine with reply/`source_only`. |
| `send_announcement` | mod announcements; colors blue/green/orange/purple/primary |
| `send_shoutout` | rate limits apply |
| `send_whisper` | whisper Helix |
| `update_chatter_color` | named or hex (Turbo/Prime) |

#### Moderation (channel = `self`)

`ban_user` / `timeout_user` / `unban_user` / `warn_user` / `delete_chat_messages` / block helpers / mod & VIP / blocked terms / suspicious users / automod / shield / unban requests.

**Dual API:** `Chatter.ban` / `timeout` / `warn` / `delete_message` / `block` act on **this chatter**. `PartialUser.ban_user` / `timeout_user` take an explicit target in **self’s** channel. Cannot ban the broadcaster.

Timeout default **600** s; max **1_209_600** s. Delete message: age ≤6h; not broadcaster/other mods.

#### Chatters / followers / settings / pins / ads / stream / rewards

| Method | Notes |
| ------ | ----- |
| `fetch_chatters(*, moderator, first=100, …)` | `moderator:read:chatters`; `first` up to **1000** |
| `fetch_followers` / `fetch_followed_channels` | follower scopes |
| `fetch_chat_settings` / `update_chat_settings` | slow 3–120s, etc. |
| `pin_message` / `unpin_message` / `update_pin_message` / `fetch_pinned_message` | pin suite (**3.3.0**) |
| `start_commercial` / `fetch_ad_schedule` / `snooze_next_ad` | ads; commercial length cap **180**; ~8 min between ads |
| `fetch_stream` / markers / schedule / `modify_channel` / clips / raids / polls / predictions | engagement |
| `fetch_custom_powerups` | bits power-ups (**3.3.0**) |
| `create_custom_reward` / fetch / update / delete | max **50** rewards; title ≤45; prompt ≤200; only **creating client_id** may update/delete |

**`token_for`:** omit → often MISSING → managed token for self/moderator; **`None` → app token** (many methods since 3.3.0); `str|PartialUser` → that user’s managed token.

Pagination: `HTTPAsyncIterator` — `await` first page or `async for` with `first` / `max_results` (max 100/page typical; chatters first up to 1000).

---

## 11. Helix models, enums & utils

### Selected models

| Model | Highlights |
| ----- | ---------- |
| `Stream` | `id`, `user`, `game_id`/`name` (nullable), `title`, `viewer_count`, `thumbnail: Asset`, tags, mature |
| `Clip` | `video_id` may be `""` until VOD ready; `thumbnail: Asset`; `fetch_video()` → None if empty |
| `AdSchedule` / `SnoozeAd` / `CommercialStart` | ads; `CommercialStart.length` capped 180; `retry_after` |
| `CustomReward` | `cooldown` / `max_per_stream` NamedTuples; `fulfil`/`color`; only creating app may update/delete |
| `CustomRewardRedemption` | `fulfill()` → FULFILLED; `refund()` → CANCELED (points returned) |
| `CustomPowerup` | bits-priced; cooldown/limit NamedTuples |
| `PinnedMessage` | `text`, `fragments`, `ends_at=None` means until stream end; `update_pin` / `unpin` |
| `HypeTrainStatus` | type `treasure`/`golden_kappa`/`regular`; shared train fields; contribution type casing differs Event vs Status |
| `ChannelInfo`, `ChatSettings`, `SentMessage`, `Chatters`, `Game`, `Goal`, `Poll`, `Prediction`, mod models, `ShieldModeStatus`, `SharedChatSession`, subscription models | See helix refs |

**No Helix class `UserAuthorisation` for auth tokens** — use `twitchio.authentication.UserTokenPayload` / `ValidateTokenPayload` / EventSub user.authorization.* (3.2 docs also mention a UserAuthorisation model in Helix for fetch_auth helpers).

### Enums

| Enum | Role |
| ---- | ---- |
| `eventsub.SubscriptionType` | Wire type strings (e.g. `ChannelChatMessage` → `channel.chat.message`) |
| `eventsub.TransportMethod` | `WEBHOOK` / `WEBSOCKET` / `CONDUIT` |
| `DeviceCodeRejection` | DCF failures; invalid refresh ~30d single-use |

Doc typos: enum names `ChannelPollProgres` / `ChannelPredictionProgres` (missing **s**) still map to `*.progress`.

### Utils

| Symbol | Role |
| ------ | ---- |
| `twitchio.Asset` | 3.0 media primitive; `set_dimensions` before save/read |
| `twitchio.Colour` / `Color` | hex/rgb; announcement helpers return **str** names |
| `twitchio.Scopes` | descriptors, `urlsafe()`, `all()`, `from_url()` |
| `setup_logging` | root logger helper |
| `HTTPAsyncIterator` | await = first page list; async for = pagination |
| `Route` | internal — do not construct casually |

**IRC legacy scopes** `chat:read` / `chat:edit` ≠ Helix chat scopes (`user:read:chat` / `user:write:chat` / `channel:bot` / `user:bot`).

### ChatMessage focus (EventSub model)

| Attr | Notes |
| ---- | ----- |
| **`text`** | Plain body — **not** `.content` |
| `chatter` | `Chatter` |
| `broadcaster` | `PartialUser` |
| `type` | includes `power_ups_message_effect`, `power_ups_gigantified_emote` |
| `fragments` | types: text/cheermote/emote/mention/**gif** |
| `reply`, `cheer`, `badges`, shared-chat `source_*` | |
| Methods | `respond`, `delete`, `pin` / `update_pin` / `unpin` (3.2–3.3) |

### ChannelBitsUse / power-ups

| Attr | Notes |
| ---- | ----- |
| `type` | `cheer` \| `power_up` \| `custom_power_up` |
| `power_up.type` | `message_effect` \| `celebration` \| `gigantify_an_emote` |

### ChatNotification

Nested: sub/resub/gift/raid/announcement/… plus **`watch_streak`**, **`modiversary`**, **`shared_modiversary`**, `source_only`, GIF fragments.

---

## 12. Routines & overlays ext

### Routines (`from twitchio.ext import routines`)

Create **only** via `@routines.routine(...)`. Exactly one of `delta=` or `time=`; both/neither → `RuntimeError`. Callback must be async.

| Parameter | Default | Meaning |
| --------- | ------- | ------- |
| `delta` | — | `datetime.timedelta` interval |
| `time` | — | daily wall-clock `datetime` |
| `iterations` | `None` | stop after N successful runs |
| `wait_first` | `False` | wait interval before first run |
| `wait_remainder` | `False` | delta-only: wait remaining time after work |
| `max_attempts` | `5` | consecutive errors then stop; reset on success; `None` = infinite |
| `stop_on_error` | `False` | immediate stop (overrides max_attempts) |

| Method | Behaviour |
| ------ | --------- |
| `start(*args, **kwargs)` | Start task; cannot re-enter running |
| `stop()` | Graceful after current iteration |
| `cancel()` | Abort mid-iteration |
| `restart(*, force=True)` | Restart; no-op if never started |
| `change_interval(*, delta/time, wait_first=False)` | Retune; default hard-cancels current iter |
| `next_iteration()` | seconds until next |

Hooks: `@before_routine`, `@after_routine`, `@error`.

**2.x → 3.x:** removed `seconds=`/`minutes=`/`hours=` and `Routine.start_time`. Use `delta=timedelta(...)`.

**Project:** short one-shots with `@routines.routine(delta=timedelta(...), iterations=1, wait_first=True)` (e.g. poll/ad delays). Long loops are `create_task` from `event_ready`.

### Overlays ext

**Docs stub only** — “planned to release in a near future version.” Sounds ext removed in v3. **Project-unused.** Product overlays live under `./overlay/*.php` + WebSocket, not TwitchIO.

---

## 13. Exceptions

```text
TwitchioException
├── HTTPException          # route, status, extra["message"]
│   ├── InvalidTokenException   # may hold secrets — never log full tokens
│   └── DeviceCodeFlowException # reason: DeviceCodeRejection
├── MessageRejectedError   # chat accepted by API but dropped by Twitch (not HTTP fail)
└── MissingConduit         # AutoClient action before conduit assigned
```

Plus full **commands** tree in §9.

---

## 14. Changelog notes 3.1 → 3.3.2

Matters for this bot pin (**3.3.2**). Full page: <https://twitchio.dev/en/stable/getting-started/changelog.html>

### 3.3.1 – 3.3.2

- Pin endpoints: parameters as **query params** (not JSON body).
- Pin **duration** set correctly.

### 3.3.0

- **DCF:** `login_dcf` / `start_dcf`; optional `client_secret` / `bot_id` for DCF.
- **Pins:** `PinnedMessage`; fetch/pin/update/unpin; `send_message(..., pin=)`; `ChatMessage.pin` / `update_pin` / `unpin`.
- **Suspicious users:** add/remove on PartialUser.
- **Custom power-ups:** models + EventSub `CustomPowerupRedeemAddSubscription` + `event_custom_power_up_redemption_add`.
- **ChatNotification:** gif, modiversary, watch_streak, source_only.
- **`token_for=None`** allowed on many mod/chat Helix methods (app token).
- `token_for` on `.respond` payloads.
- Starlette adapter ≥1.0.0.
- Commands invokable via **reply**.
- 409 errors include subscription id; `Client.http`; `conduit_id` on `fetch_eventsub_subscriptions`.
- Goal types `new_bit`, `new_cheerer`.

### 3.2.x

- Auth helpers (`fetch_auth` / `fetch_auth_by_users`); `PartialUser.fetch_stream`; `fetch_hype_train_status` (deprecates `fetch_hype_train_events`).
- `lead_moderator` + `is_lead_moderator` guard.
- `ChatMessage.delete` / `Chatter.delete_message`.
- `create_clip` title/duration (`has_delay` deprecated).
- `Scopes.from_url`; adapter `oauth_path` / `redirect_path`.
- Conduit WS reconnect fix; null-safe auto-redeem / bits-use message fields.
- 3.2.1–3.2.2: reward `background_color` HTML hex; empty `shared_train_participants` when not shared.

### 3.1.0

- `force_subscribe` / `force_scale` on AutoClient/AutoBot.
- Mass EventSub **`.respond()`**.
- Commands: Translator, converters, `Command.signature`/`parameters`/`help`, `run_guards`, Generic Context.
- Token save fix when `load_tokens=False`.
- PartialUser/User/Chatter **`__hash__` by id**.
- StarletteAdapter graceful shutdown timeouts (Windows).
- `python -m twitchio --create-new` boilerplate.

### 3.0.0 (breaking rewrite)

Min Python 3.11; IRC removed; managed tokens + adapters; Conduits; single-payload events; str IDs; `HTTPAsyncIterator`; `setup_hook`; `create_partialuser`; PubSub/sounds removed; routines use `timedelta`. See Migrating guide.

---

## 15. Migration map 2.10 → 3.x

Critical when porting `./bot/beta.py` → `./bot/beta-v6.py`.

### Imports

| 2.10 | 3.x |
| ---- | --- |
| `from twitchio.ext import commands, routines` | same + **`from twitchio import eventsub`** |
| `twitchio.ext.eventsub` | **`twitchio.eventsub` only** |

### Bot class & start

| 2.10 | 3.x |
| ---- | --- |
| `class TwitchBot(commands.Bot)` | `class TwitchBot(commands.AutoBot)` |
| `token=`, `initial_channels=[...]` | `client_id`, `client_secret`, `bot_id`, `owner_id`, `subscriptions=` |
| `bot.run()` | `async with bot: await bot.add_token(...); await bot.start(...)` |
| IRC join | EventSub `ChatMessageSubscription` |

### Chat & events

| 2.10 | 3.x |
| ---- | --- |
| `message.content` | **`message.text`** |
| `message.author` | **`message.chatter`** |
| Multi-arg events | Single payload (or Context / none for ready) |
| `event_command_error(ctx, error)` | `event_command_error(payload: CommandErrorPayload)` |
| `event_token_expired` | `event_token_refreshed` + managed refresh |
| `handle_commands` | **`process_commands`** |
| IRC join/part/raw/usernotice | **Removed** |

### Names renames

| 2.10 | 3.x |
| ---- | --- |
| `create_user` | `create_partialuser` |
| `wait_for_ready` | `wait_until_ready` |
| `fetch_chatters_colors` | `fetch_chatters_color` |
| `fetch_content_classification_labels` | `fetch_classifications` |
| Helix `token=` | **`token_for=`** |
| `error.retry_after` (cooldown) | **`error.remaining`** |
| `commands.CheckFailure` | `commands.GuardFailure` |
| Routines `seconds=`/`minutes=`/`hours=` | **`delta=timedelta(...)`** |

### Tokens & transport

| 2.10 | 3.x |
| ---- | --- |
| Manual / constructor token | `add_token` / load / save / auto-refresh |
| Multi-channel IRC | Conduits + shards (`AutoBot`) |
| PubSub | EventSub |
| IDs often int | **str** |

### Event name gotchas when porting handlers

| Wrong / 2.x habit | Correct 3.x |
| ----------------- | ----------- |
| `event_hype_train_begin` | `event_hype_train` |
| `event_ad_break_begin` | `event_ad_break` |
| `event_moderate` | `event_mod_action` |
| `event_chat_message` | `event_message` |

---

## 16. Project mapping (beta-v6)

> All line numbers verified against `./bot/beta-v6.py` as of this doc’s Last Updated date. Prefer symbol search if the file moves.

### Pin & class

| Fact | Detail |
| ---- | ------ |
| Pin | `twitchio==3.3.2` (+ starlette extra) in `./bot/v6_requirements.txt` |
| Class | `TwitchBot(commands.AutoBot)` — not bare `Bot` |
| Imports | `from twitchio import eventsub` + `commands`, `routines`, `Context` |

### Bootstrap (`main`)

```python
# Pattern in ./bot/beta-v6.py main()
if SELF_MODE:
    bot_user_id = CHANNEL_ID
elif CUSTOM_MODE:
    bot_user_id = await _fetch_custom_bot_user_id()
else:
    bot_user_id = "971436498"  # official Specter

subs = [eventsub.ChatMessageSubscription(broadcaster_user_id=CHANNEL_ID, user_id=bot_user_id)]
async with TwitchBot(
    prefix='!',
    client_id=CLIENT_ID,
    client_secret=CLIENT_SECRET,
    bot_id=BOT_ID,
    owner_id=OWNER_ID,
    subscriptions=subs,
    force_subscribe=True,
) as bot:
    await bot.add_token(BOT_OAUTH_TOKEN, REFRESH_TOKEN)
    await bot.start(load_tokens=False, save_tokens=False, with_adapter=False)
```

| Flag | Meaning for this project |
| ---- | ------------------------ |
| `force_subscribe=True` | Re-assert chat sub each start |
| `load_tokens=False` / `save_tokens=False` | Skip `.tio.tokens.json`; MySQL is source of truth |
| `with_adapter=False` | No local OAuth/webhook server |

### Key symbols (search these)

| Symbol | Role |
| ------ | ---- |
| `twitch_token_refresh` / `refresh_twitch_token` | Background MySQL token persistence |
| `get_or_create_conduit` | Hand-rolled Helix conduit for non-chat EventSub |
| `twitch_eventsub` | Hand-rolled EventSub WebSocket loop |
| `subscribe_to_events` | Topic subscription POSTs |
| `TwitchBot.setup_hook` | Registers `@commands.command` methods + `_injected=self` |
| `TwitchBot.event_token_refreshed` | Updates `CHANNEL_AUTH` |
| `TwitchBot.event_ready` | Starts background tasks including EventSub + token refresh |
| `TwitchBot.event_command_error` | Cooldown (`error.remaining`), custom-command not-found filter |
| `TwitchBot.event_message` | Shared-chat filter, history, overlay relay, `process_commands` |
| `send_chat_message` | Production Helix chat send |
| `@routines.routine` | One-shot timers (delta + iterations=1) |

### Differs from stock quickstart

| Quickstart | beta-v6 |
| ---------- | ------- |
| Full native subscription list | Chat-only native; rest hand-rolled WS |
| File tokens / OAuth adapter | MySQL + dual refresh; adapter off |
| Components | Commands on AutoBot subclass |
| `ctx.send` / `.respond` | `send_chat_message` Helix helper |
| Built-in Guards | Custom permission helpers |

### Component surface on the bot class

`TwitchBot` implements minimal Component hooks (`__all_guards__`, `component_before_invoke`, `component_after_invoke`, `component_command_error` returning `True`) so TwitchIO can treat the bot instance as command injection target without real Components.

---

## 17. Gotchas

1. **Min Python 3.11** for TwitchIO 3.x.
2. **IRC removed** — no `initial_channels` / join / part / `connected_channels`.
3. **Import** `from twitchio import eventsub` — never `twitchio.ext.eventsub` in 3.x.
4. **ChatMessage / Context text:** payload **`.text`**; Context documented **`.content`** for command string.
5. **All model `.id` values are `str`.**
6. **Events take one payload** (except `event_ready()`).
7. **`event_command_error(payload)`** — not `(ctx, error)`.
8. **`token_for=`** not raw `token=`; **`token_for=None`** = app token on many 3.3 methods.
9. **`create_partialuser`** / **`wait_until_ready`** renames.
10. **`wait_for`:** async keyword-only `predicate`; name without `event_` prefix.
11. **Conduit 72h continuity**; constructor subs only auto-apply on **new** conduit unless `force_subscribe`.
12. **AutoClient/AutoBot** do not implement `delete_websocket_subscription`.
13. **`wait_until_ready` in `setup_hook` deadlocks.**
14. **Modules** load/unload/reload are **coroutines**.
15. **Routines** take `timedelta` only (no seconds/minutes kwargs).
16. **`.tio.tokens.json`** can wipe on hard Ctrl+C — prefer DB + `load_tokens=False`.
17. **Always `await super().add_token`** in overrides; both access and refresh required.
18. **Hype train begin** event is `event_hype_train`; ads is `event_ad_break`; moderate is `event_mod_action`.
19. **Follow v2** needs `moderator_user_id` + `moderator:read:followers`.
20. **Auto-redeem V1 vs V2** power-up split; prefer `channel.bits.use` for power-ups.
21. **`MessageRejectedError`** is not `HTTPException`.
22. **Never log** full tokens from `InvalidTokenException` / `tokens` mapping.
23. **Project:** native EventSub is chat-only — non-chat topics will not fire library `event_*` unless migrated.
24. **Project:** use `process_commands` not `handle_commands`.
25. **PubSub / sounds / TwitchIO overlays** unavailable or stub — do not plan on them.

---

## 18. Upstream anchors

| Topic | URL |
| ----- | --- |
| Home | https://twitchio.dev/en/stable/ |
| Installing | https://twitchio.dev/en/stable/getting-started/installing.html |
| Migrating | https://twitchio.dev/en/stable/getting-started/migrating.html |
| Quickstart | https://twitchio.dev/en/stable/getting-started/quickstart.html |
| Changelog | https://twitchio.dev/en/stable/getting-started/changelog.html |
| FAQ / Debugging | https://twitchio.dev/en/stable/getting-started/faq.html , …/debugging.html |
| Client | https://twitchio.dev/en/stable/references/client.html |
| Conduits | https://twitchio.dev/en/stable/references/conduits/index.html |
| Events | https://twitchio.dev/en/stable/references/events/events.html |
| EventSub subscriptions | https://twitchio.dev/en/stable/references/eventsub_subscriptions.html |
| EventSub models | https://twitchio.dev/en/stable/references/eventsub/eventsub_models.html |
| Users | https://twitchio.dev/en/stable/references/users/index.html |
| Helix models | https://twitchio.dev/en/stable/references/helix/helix_models.html |
| Utils / enums | https://twitchio.dev/en/stable/references/utils.html , …/enums_etc.html |
| Exceptions | https://twitchio.dev/en/stable/references/exceptions.html |
| Web adapters | https://twitchio.dev/en/stable/references/web.html |
| Commands Bot / AutoBot | https://twitchio.dev/en/stable/exts/commands/bot.html , …/autobot.html |
| Components / core / exceptions | https://twitchio.dev/en/stable/exts/commands/components.html , …/core.html , …/exceptions.html |
| Routines / Overlays | https://twitchio.dev/en/stable/exts/routines/index.html , …/overlays/index.html |

### Cross-refs in this repo

| Doc | Role |
| --- | ---- |
| [TwitchIO-Historical.md](./TwitchIO-Historical.md) | TwitchIO 2.10.0 for stable + beta |
| [twitch.md](./twitch.md) | Helix REST, OAuth, hand-rolled EventSub topic matrix |
| `./.grok/rules/bot-versions.md` | Which bot file to edit |
| `./.grok/agents/twitch-expert.md` | Agent prompt pin 3.3.2 |
