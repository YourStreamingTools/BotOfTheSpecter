# TwitchIO 3.x Stable — Reference

Local reference for **TwitchIO 3.x stable** as used by `./bot/beta-v6.py` (the v6 rewrite). For TwitchIO 2.10.0 used by `./bot/bot.py` and `./bot/beta.py`, see [TwitchIO-Historical.md](./TwitchIO-Historical.md). For the underlying Twitch HTTP API (Helix endpoints, OAuth, EventSub topic shapes), see [twitch.md](./twitch.md).

| File | TwitchIO version | requirements file | Class subclassed |
| ---- | ---------------- | ----------------- | ---------------- |
| `./bot/beta-v6.py` | 3.x stable | `./bot/beta_requirements.txt` | `commands.AutoBot` |

Upstream docs: <https://twitchio.dev/en/stable/>

---

## 1. Imports

```python
import twitchio
from twitchio.ext.commands import Context
from twitchio.ext import commands, routines, eventsub
```

Used at `./bot/beta-v6.py:32-34`. Note `eventsub` is now a first-party extension that the project actually imports — it's used to construct `eventsub.ChatMessageSubscription` for the chat subscription.

---

## 2. `commands.AutoBot` subclass

This project subclasses **`commands.AutoBot`** (the Conduit-backed variant), not `commands.Bot`. `AutoBot` adds Conduit-based EventSub load balancing on top of `Bot`. Both share the same constructor surface for the parameters this project uses.

Reference: <https://twitchio.dev/en/stable/exts/commands/index.html>, <https://twitchio.dev/en/stable/references/client.html>

Constructor parameters:

```python
commands.AutoBot(
    *,
    prefix: str | list[str] | Callable | Coroutine,
    client_id: str,
    client_secret: str,
    bot_id: str,                 # str in 3.x (was int in 2.x)
    owner_id: str | None = None,
    subscriptions: list[eventsub.SubscriptionPayload] | None = None,
    force_subscribe: bool = False,
    case_insensitive: bool = False,
    redirect_uri: str | None = None,
    scopes: list[str] | None = None,
    session: aiohttp.ClientSession | None = None,
    adapter: BaseAdapter | None = None,
    fetch_client_user: bool = True,
)
```

**Removed vs 2.10:** `token`, `initial_channels`, `heartbeat`, `retain_cache`, `loop`. There is no IRC channel join — chat presence is achieved by registering an `eventsub.ChatMessageSubscription`.

Project shape (`./bot/beta-v6.py:2624-2638`):

```python
class TwitchBot(commands.AutoBot):
    def __init__(self, prefix, client_id, client_secret, bot_id, owner_id, subscriptions, force_subscribe):
        super().__init__(
            prefix=prefix,
            case_insensitive=True,
            client_id=client_id,
            client_secret=client_secret,
            bot_id=bot_id,
            owner_id=owner_id,
            subscriptions=subscriptions,
            force_subscribe=force_subscribe,
        )
        self.channel_name = CHANNEL_NAME
        self.running_commands = set()
```

Bootstrap (`./bot/beta-v6.py:12746-12766`):

```python
async def main() -> None:
    if SELF_MODE:
        bot_user_id = CHANNEL_ID
    elif CUSTOM_MODE:
        bot_user_id = await _fetch_custom_bot_user_id()
    else:
        bot_user_id = "971436498"  # official Specter bot

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

Lifecycle differences from 2.10:

- 3.x supports **async context manager** (`async with TwitchBot(...) as bot:`). Resource cleanup is automatic on exit.
- `bot.start(...)` is async (vs 2.10's blocking `bot.run()`).
- `with_adapter=False` disables the built-in OAuth/webhook web server.
- `load_tokens=False, save_tokens=False` disables the on-disk `.tio.tokens.json` round-trip — this project keeps tokens in MySQL `twitch_bot_access`.
- `await bot.add_token(access_token, refresh_token)` registers a user token so Helix calls have credentials.

---

## 3. Event lifecycle

| Event | Signature | Project hooks it? |
| ----- | --------- | ----------------- |
| `setup_hook(self)` | no payload | Defined as no-op (`./bot/beta-v6.py:2640-2641`). Runs after login, before `event_ready`. |
| `event_ready(self)` | no payload | Yes — `./bot/beta-v6.py:2649`. Kicks off all background tasks. |
| `event_message(self, message: twitchio.ChatMessage)` | **`ChatMessage`**, not `Message` | Yes — `./bot/beta-v6.py:2740` |
| `event_command_error(self, payload: commands.CommandErrorPayload)` | **single payload arg** (was `(ctx, error)` in 2.10) | Yes — `./bot/beta-v6.py:2707`. Access via `payload.context` and `payload.exception` |
| `event_token_refreshed(self, payload: twitchio.TokenRefreshedPayload)` | refresh notice | Yes — `./bot/beta-v6.py:2643`. Updates `CHANNEL_AUTH` global. |
| `event_error(self, payload: twitchio.EventErrorPayload)` | uncaught error | Available |

**Removed in 3.x** (do not exist):

- `event_join`, `event_part` — IRC join/part. Gone with IRC.
- `event_channel_joined` — replaced by EventSub registration confirmation.
- `event_raw_data`, `event_raw_usernotice`, `event_usernotice_subscription` — IRC raw events.
- `event_token_expired` — replaced by `event_token_refreshed` (auto-refresh built in via `add_token`).

`event_command_error`'s **payload-style signature is the most common 2.10→3.x bug source.** A 2.10 handler `async def event_command_error(self, ctx, error):` will silently never fire in 3.x.

---

## 4. Command framework

Decorator: `@commands.command(name=..., aliases=...)` — same name as 2.10.

**In 3.x, command methods on a `Bot`/`AutoBot` subclass are instance methods and keep `self`.** The no-`self` form applies only to commands defined inside a **Component** (see §4.3).

```python
# Both 2.10 and 3.x — Bot subclass methods keep self
@commands.command(name='commands', aliases=['cmds'])
async def commands_command(self, ctx: commands.Context):
    ...
```

See `./bot/beta-v6.py:3587-3588` vs `./bot/beta.py:3587-3588`.

### 4.1 Context attributes (3.x)

| Attribute | Type | Notes |
| --------- | ---- | ----- |
| `ctx.author` | `Chatter` | Same alias as 2.10. `.name`, `.id`, `.is_mod`, `.is_broadcaster`, `.is_subscriber`, `.is_vip` |
| `ctx.chatter` | `Chatter` | New in 3.x — same object as `ctx.author` |
| `ctx.broadcaster` | `PartialUser` | Channel owner; new in 3.x |
| `ctx.channel` | `Channel` (deprecated semantics) | Use `ctx.broadcaster` instead |
| `ctx.message` | `ChatMessage` | **`.text`** in 3.x (not `.content`) |
| `ctx.prefix` | `str` | |
| `ctx.command` | `commands.Command` | |
| `await ctx.send(content: str)` | `None` | Helix-backed under the hood |
| `await ctx.reply(content: str)` | `None` | Reply with parent message ID |

### 4.2 CommandErrorPayload (3.x)

Passed to `event_command_error`:

| Attribute | Type |
| --------- | ---- |
| `payload.context` | `commands.Context` |
| `payload.exception` | `Exception` |
| `payload.command` | `commands.Command` |

Cooldown error attribute changed: `error.retry_after` (2.10) → `error.remaining` (3.x), both float seconds:

```python
# 2.10 (./bot/bot.py:1785)
retry_after = max(1, math.ceil(error.retry_after))
# 3.x (./bot/beta-v6.py:2712)
retry_after = max(1, math.ceil(error.remaining))
```

### 4.3 Components (3.x replacement for Cogs)

`commands.Component` is the 3.x way to organise commands and listeners outside the Bot subclass. Added with `await self.add_component(MyComponent())` from `setup_hook`.

In a Component, commands drop `self` and event listeners use `@commands.Component.listener()`:

```python
class MyComponent(commands.Component):
    @commands.command()
    async def my_command(ctx: commands.Context): ...

    @commands.Component.listener()
    async def event_message(self, message: twitchio.ChatMessage): ...
```

**Project doesn't use Components** — all commands are still methods on the `TwitchBot` subclass.

### 4.4 Guards (3.x permission decorators)

`@commands.is_owner()`, `@commands.is_broadcaster()`, `@commands.is_moderator()`, `@commands.is_vip()`. **Project uses its own `command_permissions(role, author)` check instead of built-in Guards.**

---

## 5. EventSub — mostly hand-rolled

3.x's selling point is native EventSub WebSocket: pass `eventsub.*Subscription` instances via `subscriptions=` to `AutoBot`, and the library opens the WebSocket, manages session ID, dispatches typed payloads to `event_*` handlers.

### 5.1 Available subscription classes (partial)

- `eventsub.ChatMessageSubscription(broadcaster_user_id, user_id)`
- `eventsub.StreamOnlineSubscription(broadcaster_user_id)` / `StreamOfflineSubscription`
- `eventsub.ChannelFollowSubscription(broadcaster_user_id, moderator_user_id)`
- `eventsub.ChannelSubscribeSubscription`, `ChannelSubscriptionGiftSubscription`, `ChannelSubscriptionMessageSubscription`
- `eventsub.ChannelRaidSubscription(to_broadcaster_user_id=...)` / `(from_broadcaster_user_id=...)`
- `eventsub.ChannelAdBreakBeginSubscription`
- `eventsub.ChannelPointsRedeemAddSubscription`, `ChannelPointsAutoRedeemAddSubscription`
- `eventsub.ChannelPollBeginSubscription` / `EndSubscription`
- `eventsub.ChannelHypeTrainBeginSubscription` / `EndSubscription`
- `eventsub.ChannelModerateSubscription`
- `eventsub.ChannelChatNotificationSubscription`
- `eventsub.ChannelBanSubscription`, `ChannelUnbanSubscription`
- `eventsub.AutomodMessageHoldSubscription`
- `eventsub.SuspiciousUserMessageSubscription`
- `eventsub.ChannelShoutoutCreateSubscription` / `ReceiveSubscription`
- `eventsub.SharedChatSessionBeginSubscription` / `Update` / `End`

Full list: <https://twitchio.dev/en/stable/references/eventsub/index.html>

### 5.2 Corresponding event names

- `event_message` ← `ChatMessageSubscription`
- `event_message_delete` ← `ChatMessageDelete`
- `event_stream_online` / `event_stream_offline`
- `event_follow`
- `event_subscription` / `event_subscription_gift` / `event_subscription_message`
- `event_raid`
- `event_ad_break_begin`
- `event_custom_redemption_add` / `event_automatic_redemption_add`
- `event_poll_begin` / `event_poll_end`
- `event_hype_train_begin` / `event_hype_train_end`
- `event_moderate`
- `event_chat_notification`
- `event_ban` / `event_unban`
- `event_shoutout_create` / `event_shoutout_receive`

### 5.3 How this project actually uses it

The v6 bot only uses **one** native subscription:

```python
# ./bot/beta-v6.py:12754
subs = [eventsub.ChatMessageSubscription(broadcaster_user_id=CHANNEL_ID, user_id=bot_user_id)]
```

That single registration drives `event_message` (and the entire command framework). **Every other EventSub topic** is registered by the same hand-rolled WebSocket that bot.py and beta.py use:

- Conduit lookup/creation: `./bot/beta-v6.py:548-587` (`get_or_create_conduit`)
- WebSocket loop: `./bot/beta-v6.py:589-616` (`twitch_eventsub`)
- Subscription POSTs: `./bot/beta-v6.py:618-738` (`subscribe_to_events`)
- Reconnect handling: `EventSubReconnect` at `./bot/beta-v6.py:543-546`

**What this means when adding a new topic:**

- Add it to `subscribe_to_events()` in beta-v6.py (same `topics` list shape as beta.py).
- Do **not** assume `event_follow`/`event_raid`/etc. will fire — they won't.
- If you want to switch a topic to native handling: add the `eventsub.*Subscription(...)` to the `subs` list in `main()`, write the `event_*` handler, and remove it from the hand-rolled list. Don't half-migrate — duplicate subscriptions cause duplicate dispatches.

---

## 6. Token storage

3.x has an opinionated token model:

- `Client.add_token(token: str, refresh: str)` — register a user token. Library refreshes before expiry, emits `event_token_refreshed`.
- `Client.remove_token(user_id: str)` — drop a token.
- `Client.load_tokens(path: str | None = None)` — reads `.tio.tokens.json` by default. Override to load from DB.
- `Client.save_tokens(path: str | None = None)` — writes `.tio.tokens.json` by default. Override to persist to DB.

This project **disables the file path mechanism** (`load_tokens=False, save_tokens=False`) and also keeps a parallel manual refresh (`twitch_token_refresh()` at `./bot/beta-v6.py:461-526`). Both run — the library's auto-refresh keeps Helix calls alive; the manual task keeps the MySQL `twitch_bot_access` row authoritative for overlays/dashboard.

To replace the manual task with a proper DB-backed adapter: subclass `Client`/`Bot`/`AutoBot`, override `load_tokens()` / `save_tokens()` to read/write `twitch_bot_access`, then drop the manual task.

---

## 7. Sending chat

3.x exposes `await ctx.send(...)`, `await partial_user.send_message(...)`, etc., backed by Helix. **This project still uses its own Helix `send_chat_message(...)` function** (`./bot/beta-v6.py:12437+`) — same shape as `./bot/bot.py:10547`, same endpoint, same reply-parent threading. Architectural consistency across versions takes priority over using the library's send helpers.

---

## 8. Helix access

```python
users = await self.fetch_users(logins=["someuser"])    # 3.x: logins= (was names=)
users = await self.fetch_users(ids=["971436498"])       # 3.x: ids are str (was int)
streams = await self.fetch_streams(user_logins=[...])   # returns HTTPAsyncIterator
channel = await self.fetch_channel(broadcaster_id="...")
partial = self.create_partialuser(user_id="...", user_login="...")
```

`fetch_streams` and other paginated helpers return `HTTPAsyncIterator`:

```python
# First page only — await returns a list (most common usage in this project)
users = await self.fetch_users(logins=["someuser"])

# Full async iteration across all pages
async for stream in self.fetch_streams(user_logins=[...]):
    ...
```

Common optional parameters: `first=` (page size, default 20), `max_results=` (cap total results). Token override: `token_for=user_id_str`.

`token_for=` parameter: pass a user-ID string or `PartialUser` to tell the client which managed token to use (replaces 2.10's `token=`).

Project usage in v6: `./bot/beta-v6.py:4113`, `./bot/beta-v6.py:4158`, `./bot/beta-v6.py:6794`, `./bot/beta-v6.py:7057` — all use `self.fetch_users(logins=[...])`.

---

## 9. Routines (3.x)

```python
from twitchio.ext import routines
from datetime import timedelta

@routines.routine(delta=timedelta(seconds=60), iterations=None, wait_first=False)
async def my_task(): ...

my_task.start()
```

Changes vs 2.10:

- **`delta: timedelta`** replaces `seconds=`/`minutes=`/`hours=` kwargs.
- `start_time` attribute removed; use `next_iteration()`, `last_iteration`, `current_iteration`.
- Lifecycle hooks (`@my_task.before_routine`, `@my_task.after_routine`, `@my_task.error`) unchanged.

Project usage in v6 (one-shot timers):

```python
@routines.routine(delta=timedelta(seconds=duration_seconds), iterations=1, wait_first=True)
```

Callsites: `./bot/beta-v6.py:9794`, `./bot/beta-v6.py:11931`.

---

## 10. Key types

### `twitchio.ChatMessage`

Passed to `event_message` and accessible via `ctx.message`:

| Attribute | Notes |
| --------- | ----- |
| `message.text` | Plain text body (was `.content` in 2.10) |
| `message.chatter` | `Chatter` — sender (was `.author`) |
| `message.broadcaster` | `PartialUser` — channel owner |
| `message.source_broadcaster` | `PartialUser \| None` — set when message arrived via shared chat. Project filters at `./bot/beta-v6.py:2744-2745`. |
| `message.id` | message id |
| `message.fragments` | structured chunks (text/cheermote/emote/mention) |
| `message.badges`, `message.reply` | extra metadata |
| `message.timestamp` | datetime |

### `twitchio.Chatter` (3.x)

`.name`, `.display_name`, `.id` (str), `.is_mod`, `.is_subscriber`, `.is_vip`, `.is_broadcaster`, `.colour`, `.badges`. Mostly the same names as 2.10's `Chatter`.

### `twitchio.PartialUser`

Lightweight user reference for API calls. `.id`, `.name`. Build with `bot.create_partialuser(user_id=..., user_login=...)`.

`send_message` signature (broadcaster is implicit — the `PartialUser` object you call it on):

```python
await partial_user.send_message(
    message: str,
    sender: str | int | PartialUser,
    *,
    token_for: str | PartialUser | None = None,
    reply_to_message_id: str | None = None,
    source_only: bool | None = None,
) -> SentMessage
```

`sender` = the bot's user ID or `PartialUser`. **This project doesn't use `send_message`** — all chat goes through `send_chat_message(...)` for consistency across bot versions.

### `twitchio.TokenRefreshedPayload`

Passed to `event_token_refreshed`: `.user_id` (str), `.token` (new access token), `.refresh_token`. Project hook: `./bot/beta-v6.py:2643-2647`.

---

## 11. Migration map: 2.10 → 3.x

The fastest reference for porting from `beta.py` to `beta-v6.py`. If a row says "no change," still re-read the line you copy — this codebase has subtle DB call differences (`mysql_connection()` → `mysql_handler.get_connection()`) alongside the TwitchIO ones.

### 11.1 Imports

| 2.10 | 3.x |
| ---- | --- |
| `import twitchio` | `import twitchio` |
| `from twitchio.ext.commands import Context` | same |
| `from twitchio.ext import commands, routines` | `from twitchio.ext import commands, routines, eventsub` |

### 11.2 Bot class

| 2.10 | 3.x |
| ---- | --- |
| `class TwitchBot(commands.Bot):` | `class TwitchBot(commands.AutoBot):` |
| `__init__(self, token, prefix, channel_name)` | `__init__(self, prefix, client_id, client_secret, bot_id, owner_id, subscriptions, force_subscribe)` |
| `super().__init__(token=..., prefix=..., initial_channels=[chan], case_insensitive=True)` | `super().__init__(prefix=..., case_insensitive=True, client_id=..., client_secret=..., bot_id=..., ...)` |
| `bot.run()` (blocking) | `async with bot: await bot.add_token(...); await bot.start(load_tokens=False, save_tokens=False, with_adapter=False)` |

### 11.3 Lifecycle / events

| 2.10 | 3.x | Notes |
| ---- | --- | ----- |
| `async def event_ready(self):` | `async def event_ready(self):` | Same name, same signature |
| _(no setup_hook)_ | `async def setup_hook(self):` | New optional hook between login and ready |
| `async def event_message(self, message):` | `async def event_message(self, message):` | Same name; `message` is now `ChatMessage` |
| `async def event_command_error(self, ctx, error):` | `async def event_command_error(self, payload):` | **Single payload arg.** Use `payload.context`, `payload.exception` |
| _(no event_token_refreshed)_ | `async def event_token_refreshed(self, payload):` | Library auto-refresh hook |
| `async def event_channel_joined(self, channel):` | _(removed)_ | No IRC join |
| `async def event_join(self, channel, user):` | _(removed)_ | No IRC |
| `async def event_part(self, channel, user):` | _(removed)_ | No IRC |
| `async def event_message_delete(self, message):` | `async def event_message_delete(self, payload):` | Now a `ChatMessageDelete` EventSub payload |
| `async def event_raw_data(self, data):` | _(removed)_ | No IRC |
| `async def event_token_expired(self):` | _(removed)_ | Replaced by `event_token_refreshed` |

### 11.4 Command method shape

| 2.10 | 3.x |
| ---- | --- |
| `async def cmd(self, ctx):` | `async def cmd(self, ctx: commands.Context):` — **`self` stays** on Bot/AutoBot subclass methods |
| `async def cmd(self, ctx, user: str, n: int):` | `async def cmd(self, ctx: commands.Context, user: str, n: int):` |
| `async def cmd(self, ctx, *, message: str):` | `async def cmd(self, ctx: commands.Context, *, message: str):` |
| _(Component only)_ `async def handler(self, ...)` | `async def handler(ctx: commands.Context):` — Component commands drop `self` |

### 11.5 Context

| 2.10 | 3.x |
| ---- | --- |
| `ctx.author` | `ctx.author` or `ctx.chatter` (same object) |
| `ctx.author.id` (int) | `ctx.author.id` (str) |
| `ctx.channel` (Channel) | `ctx.broadcaster` (PartialUser) preferred |
| `ctx.message.content` | `ctx.message.text` |
| `await ctx.send("...")` | `await ctx.send("...")` (same) |

### 11.6 Message object in `event_message`

| 2.10 (`twitchio.Message`) | 3.x (`twitchio.ChatMessage`) |
| ------------------------- | ---------------------------- |
| `message.content` | `message.text` |
| `message.author` | `message.chatter` |
| `message.author.id` (int) | `message.chatter.id` (str) |
| `message.channel` (Channel) | `message.broadcaster` (PartialUser) |
| `message.echo` | Id check: `if message.chatter.id == str(BOT_ID): return` (`./bot/beta-v6.py:2756`) |
| `message.tags['source-room-id']` | `message.source_broadcaster` — `None` for normal, set for shared-chat |

### 11.7 Helix fetches

| 2.10 | 3.x |
| ---- | --- |
| `self.fetch_users(names=[login])` | `self.fetch_users(logins=[login])` |
| `self.fetch_users(ids=[12345])` (int) | `self.fetch_users(ids=["12345"])` (str) |
| `self.fetch_users(token=user_token)` | `self.fetch_users(token_for=user_id_str)` |
| `self.fetch_streams(...)` → list | `self.fetch_streams(...)` → `HTTPAsyncIterator` |
| `bot.create_user(...)` | `bot.create_partialuser(user_id=..., user_login=...)` |
| `bot.wait_for_ready()` | `bot.wait_until_ready()` |

### 11.8 EventSub registration

| 2.10 | 3.x |
| ---- | --- |
| Hand-rolled WS + aiohttp POST to Helix | (a) **Native:** `subscriptions=[eventsub.*Subscription(...)]` — library handles WS + dispatch. (b) **This project:** keeps hand-rolled for all topics except chat (see §5.3). |

### 11.9 Error handling

| 2.10 | 3.x |
| ---- | --- |
| `event_command_error(self, ctx, error)` | `event_command_error(self, payload)` |
| `error.retry_after` (CommandOnCooldown) | `error.remaining` (CommandOnCooldown) |
| `commands.CommandNotFound` | same |

### 11.10 Routines

| 2.10 | 3.x |
| ---- | --- |
| `@routines.routine(seconds=60.0)` | `@routines.routine(delta=timedelta(seconds=60))` |
| `@routines.routine(minutes=5)` | `@routines.routine(delta=timedelta(minutes=5))` |
| `routine.start_time` | removed — use `routine.last_iteration`, `routine.next_iteration()` |

### 11.11 Cogs → Components

| 2.10 | 3.x |
| ---- | --- |
| `commands.Cog` | `commands.Component` |
| `bot.add_cog(MyCog(bot))` | `await bot.add_component(MyComponent())` (from `setup_hook`) |
| `Cog.__init__` calls `super().__init__()` | Component `__init__` must **NOT** call `super().__init__()` |
| `@commands.Cog.event()` | `@commands.Component.listener()` |

### 11.12 Removed / discontinued

| 2.10 | 3.x replacement |
| ---- | --------------- |
| `from twitchio.ext import pubsub` | **PubSub removed** — Twitch discontinued the API. Migrate to EventSub. |
| `from twitchio.ext import eventsub` (webhook/WS clients) | `from twitchio.ext import eventsub` is now subscription *models* only. |
| `bot.connect()`, `bot.join_channels(...)`, `bot.part_channels(...)` | Removed — IRC is gone. Use `ChatMessageSubscription`. |
| `bot.get_channel(name)` | Removed. Use `bot.create_partialuser(user_login=name)`. |
| `heartbeat`, `retain_cache`, `initial_channels` | Removed Bot kwargs. |
| `Client(loop=...)` | Removed — uses ambient asyncio loop. |

### 11.13 Identifier types

| 2.10 | 3.x |
| ---- | --- |
| User IDs are `int` | User IDs are `str` — **check every comparison throughout the codebase** |
| `bot_id: int` | `bot_id: str` |
| `fetch_users(ids=[12345])` | `fetch_users(ids=["12345"])` |

### 11.14 Python and dependency floor

| 2.10 | 3.x |
| ---- | --- |
| Python 3.7+ | Python 3.11+ |
| aiohttp loose pin | aiohttp 3.9.1+ |
| iso8601, typing-extensions in deps | both removed |

---

## 12. Gotchas

1. **CLI args drive everything.** Bot files take `-channel`, `-channelid`, `-token`, `-refresh`, optional `-apitoken`, `-custom`, `-botusername`, `-self`. In v6, `-token` is not passed to the constructor — it's passed to `await bot.add_token(BOT_OAUTH_TOKEN, REFRESH_TOKEN)` after the `async with` opens. Don't hardcode a token; don't bypass argparse.

2. **`event_command_error` single-arg signature is silent when wrong.** A 2.10-style `(self, ctx, error)` handler in v6 produces no error — the dispatcher just fails to call it. Always use the single-payload form.

3. **`message.content` vs `message.text`.** Raises `AttributeError: 'ChatMessage' object has no attribute 'content'`. Same for `message.author` vs `message.chatter`.

4. **`fetch_users(names=[...])` raises `TypeError` in v6.** Use `logins=`. If a port silently fetches no users, this is why.

5. **User IDs are `str` in v6.** `if message.chatter.id == str(BOT_ID)` is explicit at `./bot/beta-v6.py:2756`. Mirror this when porting.

6. **Two parallel token-refresh systems run in v6.** Library auto-refresh (from `add_token`) keeps Helix calls alive; manual `twitch_token_refresh()` task keeps `twitch_bot_access` MySQL row authoritative for overlays/dashboard. Don't swap to a fully library-driven model without overriding `Client.save_tokens` to write to MySQL and removing the manual task.

7. **EventSub typed events only fire for chat.** Only `eventsub.ChatMessageSubscription` is in the `subscriptions=` list. `event_follow`, `event_raid`, `event_subscription`, etc. won't fire from the library. Route those topics via the hand-rolled `process_twitch_eventsub_message(...)`.

8. **All chat send goes through Helix.** Don't introduce `await ctx.send(...)` for production output. Use `send_chat_message(...)`. Consistency across all three bot files matters more than using the library's send helpers.

9. **The v6 bot subclasses `AutoBot`, not `Bot`.** `AutoBot` adds Conduit support. The project looks up a Conduit at startup (`get_or_create_conduit()` at `./bot/beta-v6.py:548`) but the WebSocket is hand-rolled — the Conduit ID isn't consumed by the current WebSocket transport. The lookup is a future hook.

10. **Bot subclass commands keep `self`; Component commands don't.** Commands defined as methods on `TwitchBot` (an `AutoBot` subclass) are instance methods — `self` is correct. E.g., `addpoints_command(self, ctx, ...)` at `./bot/beta-v6.py:4088` is right, not a bug. Only commands defined inside a `commands.Component` class drop `self`.

11. **Keepalive loop is project code.** `asyncio.wait_for(ws.recv(), timeout=keepalive_timeout)` in `twitch_eventsub()` is project-written for the hand-rolled topics. The library's native keepalive does not run for those. Changing `keepalive_timeout` requires updating both the URL query string and the receive timeout.

12. **CUSTOM_MODE / SELF_MODE branching.** In v6, the bot user ID for `ChatMessageSubscription` is selected at runtime: broadcaster id (SELF_MODE), custom bot user id (CUSTOM_MODE), or hardcoded `971436498` (default Specter bot). Token sources differ accordingly. See `./bot/beta-v6.py:12746-12766`.

13. **Python version split.** 2.10 bots run on Python 3.7+; v6 needs 3.11+. Don't backport 3.10/3.11 syntax (`match`/`case`, `X | None` PEP 604) to the 2.10 bots without checking.

14. **Shared chat filtering.** 2.10 reads `message.tags.get('source-room-id')`. 3.x reads `message.source_broadcaster`. When changing one, change the other.

15. **Routines `delta=` vs `seconds=`.** `@routines.routine(seconds=N)` works in 2.10. `@routines.routine(delta=timedelta(seconds=N))` is the v6 form. Keep them separate.

---

## 13. Upstream doc anchors

- Root: <https://twitchio.dev/en/stable/>
- Client reference: <https://twitchio.dev/en/stable/references/client.html>
- Commands ext: <https://twitchio.dev/en/stable/exts/commands/index.html>
- Components: <https://twitchio.dev/en/stable/exts/commands/components.html>
- EventSub subscription/payload models: <https://twitchio.dev/en/stable/references/eventsub/index.html>
- Migration guide: <https://twitchio.dev/en/getting-started/migrating.html>
- Conduits guide: <https://twitchio.dev/en/stable/getting-started/conduits.html>
- Routines ext: <https://twitchio.dev/en/stable/exts/routines/index.html>

## 14. Cross-references

- TwitchIO 2.10.0 reference: [TwitchIO-Historical.md](./TwitchIO-Historical.md)
- Twitch HTTP API (Helix, OAuth, EventSub topics): [twitch.md](./twitch.md)
- Bot version policy: [bot-versions.md](../../../rules/bot-versions.md)
- Bot architecture: [system_bot.md](../../../memory/system_bot.md)
