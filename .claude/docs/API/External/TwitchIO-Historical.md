# TwitchIO 2.10.0 — Historical Reference

Local reference for **TwitchIO 2.10.0** as used by `./bot/bot.py` (stable) and `./bot/beta.py` (beta testing track). For the TwitchIO 3.x API used by `./bot/beta-v6.py`, see [TwitchIO-Stable.md](./TwitchIO-Stable.md). For the underlying Twitch HTTP API (Helix endpoints, OAuth, EventSub topic shapes), see [twitch.md](./twitch.md).

| File | TwitchIO version | requirements file | Class subclassed |
| ---- | ---------------- | ----------------- | ---------------- |
| `./bot/bot.py` | 2.10.0 (stable) | `./bot/requirements.txt` | `commands.Bot` |
| `./bot/beta.py` | 2.10.0 (beta) | `./bot/requirements.txt` | `commands.Bot` |

Upstream docs: <https://twitchio.dev/en/historical-2.10.0/>

---

## Why 2.10.0 is still in use

Per [bot-versions.md](../../../rules/bot-versions.md):

- **`bot.py`** is *stable*. Critical fixes only. TwitchIO version will not change here.
- **`beta.py`** is the *testing* track on the same 2.10.0 line. New features land here first.
- **`beta-v6.py`** is the *forward-looking rewrite* on TwitchIO 3.x — the IRC-driven chat is replaced by native EventSub WebSocket. See [TwitchIO-Stable.md](./TwitchIO-Stable.md).

The library APIs between 2.10.0 and 3.x are **not source-compatible**. [TwitchIO-Stable.md §4](./TwitchIO-Stable.md) is the side-by-side migration map for porting from `beta.py` to `beta-v6.py`.

---

## 1. Imports

```python
import twitchio
from twitchio.ext.commands import Context
from twitchio.ext import commands, routines
```

Used at:
- `./bot/bot.py:30-32`
- `./bot/beta.py:33-35`

There is no `from twitchio.ext import eventsub` in the 2.10 bots — the project never adopted the built-in `eventsub` extension on this version. EventSub WebSocket is hand-rolled with the `websockets` library (see §6).

---

## 2. `commands.Bot` subclass

Reference: <https://twitchio.dev/en/historical-2.10.0/exts/commands.html>

```python
class Bot(
    token: str,
    *,
    prefix: Union[str, list, tuple, set, Callable, Coroutine],
    client_secret: Optional[str] = None,
    initial_channels: Optional[Union[list, tuple, Callable]] = None,
    heartbeat: Optional[float] = 30.0,
    retain_cache: Optional[bool] = True,
    case_insensitive: bool = False,
)
```

Project shape (`./bot/bot.py:1752-1756`, `./bot/beta.py:3266-3272`):

```python
class TwitchBot(commands.Bot):
    def __init__(self, token, prefix, channel_name):
        super().__init__(
            token=token,
            prefix=prefix,
            initial_channels=[channel_name],
            case_insensitive=True,
        )
        self.channel_name = channel_name
        self.running_commands = set()
```

Bootstrap (`./bot/bot.py:10593-10609`):

```python
BOTS_TWITCH_BOT = TwitchBot(token=OAUTH_TOKEN, prefix='!', channel_name=CHANNEL_NAME)

def start_bot():
    BOTS_TWITCH_BOT.run()  # blocking
```

`bot.run()` is blocking and creates its own event loop. There is no `async with` lifecycle in 2.10 — the bot is constructed eagerly and `run()` blocks until shutdown.

---

## 3. Event lifecycle

All event hooks are coroutine methods on the `Bot` subclass (or registered with `@bot.event()`).

| Event | Signature | Project hooks it? |
| ----- | --------- | ----------------- |
| `event_ready(self)` | no payload | Yes — `./bot/bot.py:1759`, starts background tasks (`twitch_eventsub`, `twitch_token_refresh`, `specter_websocket`, etc.) |
| `event_message(self, message: twitchio.Message)` | full message | Yes — `./bot/bot.py:1813`. Calls `self.handle_commands(message)` then runs custom-command/AI/spam logic |
| `event_channel_joined(self, channel: twitchio.Channel)` | channel object | Yes — `./bot/bot.py:1777` |
| `event_command_error(self, ctx: Context, error: Exception)` | ctx + exc | Yes — `./bot/bot.py:1782` |
| `event_join(self, channel, user)` | chatter joined | Available, not hooked |
| `event_part(self, channel, user)` | chatter left | Available, not hooked |
| `event_message_delete(self, message)` | deletion | Available, not hooked |
| `event_raw_data(self, data: str)` | raw IRC line | Available, not hooked |
| `event_raw_usernotice(self, channel, tags)` | raw USERNOTICE | Available, not hooked |
| `event_usernotice_subscription(self, metadata)` | sub via IRC | Available, not hooked (project uses hand-rolled EventSub for subs) |
| `event_token_expired(self)` | refresh hint | Available, not hooked (project runs its own refresh task) |
| `event_reconnect(self)` | Twitch sent IRC RECONNECT notice | Available, not hooked |
| `event_channel_join_failure(self, channel: str)` | bot failed to join a channel | Available, not hooked |
| `event_userstate(self, user: twitchio.Chatter)` | USERSTATE IRC packet (own state update) | Available, not hooked |
| `event_mode(self, channel, user, status)` | MOD/UNMOD received | Available, not hooked |
| `event_notice(self, message, msg_id, channel)` | NOTICE packet (slow-mode changes, etc.) | Available, not hooked |
| `event_raw_notice(self, data: str)` | raw NOTICE IRC line | Available, not hooked |
| `event_error(self, error: Exception, data=None)` | uncaught error during event dispatch | Available, not hooked |

`event_ready` fires after IRC connection completes. Only after this point is `self.nick` reliable and is the bot a member of `initial_channels`.

`event_command_error` takes **two args** (`ctx`, `error`) in 2.10. In 3.x the signature becomes a single `payload` arg — the most common silent bug when porting. See [TwitchIO-Stable.md §3.3](./TwitchIO-Stable.md).

---

## 4. Command framework

Decorator: `@commands.command(name=..., aliases=..., no_global_checks=False)`

In 2.10, command methods inside a `Bot` subclass take **`self` as the first argument**:

```python
@commands.command(name='commands', aliases=['cmds'])
async def commands_command(self, ctx):
    ...
```

Examples: `./bot/bot.py:2643-2644`, `./bot/beta.py:3587-3588`.

### 4.1 Context attributes

`Context` (the `ctx` parameter) exposes:

| Attribute | Type | Description |
| --------- | ---- | ----------- |
| `ctx.author` | `twitchio.Chatter` | Sender — `.name`, `.id`, `.is_mod`, `.is_subscriber`, `.is_vip`, `.is_broadcaster`, `.badges` |
| `ctx.channel` | `twitchio.Channel` | Channel command was invoked in. `.name`, `.send(...)`, `.users` |
| `ctx.message` | `twitchio.Message` | Original message. **`ctx.message.content`** in 2.10 |
| `ctx.prefix` | `str` | The matched prefix |
| `ctx.command` | `commands.Command` | Command object |
| `ctx.cog` | `commands.Cog` | `None` unless inside a Cog |
| `await ctx.send(content: str)` | `None` | Send into the same channel |
| `await ctx.reply(content: str)` | `None` | Reply (mention) the invoker |
| `ctx.bot` | `commands.Bot` | The Bot instance |
| `ctx.args` | `list` | Positional arguments parsed from the command string |
| `ctx.kwargs` | `dict` | Keyword arguments parsed from the command string |
| `ctx.view` | `StringParser` | Raw command string parser — for advanced argument handling |
| `ctx.chatters` | `set[Chatter]` | Current chatters in the channel |
| `ctx.users` | `set[Chatter]` | Alias for `ctx.chatters` |
| `ctx.get_user(name: str)` | `Optional[Chatter]` | Retrieve a chatter from the channel cache by name |

### 4.2 Argument parsing

Type hints drive conversion:

```python
@commands.command()
async def addpoints_command(self, ctx, user: str, points_to_add: int):
    ...

@commands.command()
async def echo(self, ctx, *, message: str):  # rest-of-line
    await ctx.send(message)
```

Built-in converters: `str`, `int`, `bool`, `twitchio.PartialChatter` (cache-independent), `twitchio.Chatter` (cache-dependent), `twitchio.PartialUser` (makes Helix API call), `twitchio.User` (makes Helix API call), `twitchio.Channel`, `twitchio.Clip`. The keyword-only marker (`*,`) consumes the entire remainder of the message into one string argument.

Custom converters use `typing.Annotated`:

```python
from typing import Annotated

def my_converter(ctx: commands.Context, arg: str) -> MyType:
    return MyType(arg)  # raise commands.BadArgument on failure

@commands.command()
async def cmd(self, ctx, value: Annotated[MyType, my_converter]): ...
```

### 4.3 Cooldowns

`@commands.cooldown(rate=1, per=60.0, bucket=commands.Bucket.user)`. Confirmed buckets: `Bucket.user` (per-user globally), `Bucket.channel` (per-channel), `Bucket.member` (per-user per-channel), `Bucket.subscriber`, `Bucket.mod`. The exact identifier for a global-scope bucket is not confirmed for 2.10.0 — see upstream commands ext for the full enum.

This project does **not** use the built-in cooldown decorator — it uses its own DB-backed cooldown logic (`check_cooldown(...)`/`add_usage(...)` in `./bot/bot.py`).

### 4.4 Exceptions

**TwitchIO base exceptions** (import from `twitchio`):

| Exception | Base | Description |
| --------- | ---- | ----------- |
| `TwitchIOException` | `Exception` | Root exception for all TwitchIO errors |
| `AuthenticationError` | `TwitchIOException` | OAuth authentication failure |
| `InvalidContent` | `TwitchIOException` | Invalid data provided to the library |
| `IRCCooldownError` | `TwitchIOException` | IRC send rate limit exceeded |
| `EchoMessageWarning` | `TwitchIOException` | Bot received its own message unexpectedly |
| `NoClientID` | `TwitchIOException` | Client ID not provided |
| `NoToken` | `TwitchIOException` | OAuth token not provided |
| `HTTPException(message, reason=None, status=None, extra=None)` | `TwitchIOException` | HTTP request failed |
| `Unauthorized(message, reason=None, status=None, extra=None)` | `HTTPException` | 401/403 HTTP response |

**Commands ext exceptions** — all subclass `commands.TwitchCommandError`:

| Exception | Raised when | Used in this project? |
| --------- | ----------- | --------------------- |
| `commands.CommandNotFound` | prefix matches but command isn't registered | Yes — caught in `event_command_error` to fall through to DB custom commands (`./bot/bot.py:1789`) |
| `commands.CommandOnCooldown(retry_after=...)` | cooldown active | `error.retry_after` (float seconds) used at `./bot/bot.py:1785` |
| `commands.MissingRequiredArgument(name=...)` | required positional missing | Available |
| `commands.BadArgument` | converter raised | Available |
| `commands.ArgumentParsingFailed(message=...)` | parse failure | Available |
| `commands.MissingPermissions` | check failed | Available, not used |
| `commands.CheckFailure` | `@commands.check` returned False | Available, not used |

### 4.5 Cogs

TwitchIO 2.10 supports `commands.Cog` for grouping. **This project does not use Cogs.** All commands live as methods on `TwitchBot` directly.

---

## 5. Routines

Reference: <https://twitchio.dev/en/historical-2.10.0/exts/routines.html>

```python
from twitchio.ext import routines

@routines.routine(seconds=60.0, iterations=None, wait_first=False, stop_on_error=True)
async def my_task():
    ...

my_task.start()
```

Decorator parameters:

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `seconds` / `minutes` / `hours` | float | — | Interval (mutually exclusive with `time`) |
| `time` | `datetime.time` | — | Fixed time of day |
| `iterations` | `int \| None` | `None` | `None`/`0` = infinite |
| `wait_first` | bool | `False` | Sleep one interval before the first run |
| `stop_on_error` | bool | `True` | Abort loop on uncaught exception |

Lifecycle hooks:

```python
@my_task.before_routine
async def _before(): ...

@my_task.after_routine
async def _after(): ...

@my_task.error
async def _on_err(exc: Exception): ...
```

Control methods: `start(*args, **kwargs)`, `stop()`, `cancel()`, `restart(force=False)`, `change_interval(seconds=..., minutes=..., hours=..., time=...)`. State: `completed_iterations`, `remaining_iterations`, `start_time`.

Project usage (deliberately minimal — one-shot timers only):

```python
@routines.routine(seconds=duration_seconds, iterations=1, wait_first=True)
async def _delayed_thing(): ...
```

Callsites: `./bot/bot.py:8464`, `./bot/bot.py:10306`, `./bot/beta.py:6034`, `./bot/beta.py:12088`, `./bot/beta.py:14516`.

Most periodic background work uses raw `asyncio.create_task(...)` — see the `looped_tasks[...] = create_task(...)` pattern in `event_ready`.

**3.x rename:** `seconds=`/`minutes=`/`hours=` → `delta=timedelta(seconds=...)`. Do not mix the two forms.

---

## 6. EventSub — hand-rolled WebSocket

TwitchIO 2.10 ships `twitchio.ext.eventsub` (with `EventSubClient` for webhooks and `EventSubWSClient` for WebSockets). **This project does not use it.**

Instead, `./bot/bot.py:371-491` (and the equivalent block in `./bot/beta.py`) open `wss://eventsub.wss.twitch.tv/ws?keepalive_timeout_seconds=600` directly with the `websockets` library and POST subscriptions to `https://api.twitch.tv/helix/eventsub/subscriptions` by hand. For EventSub topic list and shapes, see [twitch.md](./twitch.md) §4.

Flow:

1. `event_ready` schedules `looped_tasks["twitch_eventsub"] = create_task(twitch_eventsub())`.
2. `twitch_eventsub()` connects to the WS, awaits the `session_welcome` frame, captures `session_id` + `keepalive_timeout`.
3. Calls `subscribe_to_events(session_id)` which `aiohttp.post`s one subscription per topic (via `gather()`).
4. `twitch_receive_messages(...)` loops, dispatching to `process_twitch_eventsub_message(...)` and reconnects on close/timeout.

None of TwitchIO 2.10's `event_eventsub_notification_*` events fire — the library's EventSub extension is never loaded. Custom routing in `process_twitch_eventsub_message` switches on `metadata.subscription_type`.

---

## 7. Sending chat

The library provides `await ctx.send(...)` and `await channel.send(...)` over IRC, but **this project does not use them for production chat output**. All outbound chat goes through Helix `/helix/chat/messages` via `send_chat_message(...)` (`./bot/bot.py:10547-10591`). This is a deliberate architectural choice — Helix send works the same in 2.10 and 3.x, gives reply-parent threading and `is_sent`/`drop_reason` feedback, and keeps the send path identical across all three bot files.

A few `await ctx.send(...)` calls remain for ad-hoc cases; treat `send_chat_message(...)` as the canonical sender.

---

## 8. Helix access

The `Bot` instance exposes a Helix wrapper:

```python
users = await self.fetch_users(names=["someuser"])     # 2.10 uses names=
streams = await self.fetch_streams(user_logins=[...])
channels = await self.fetch_channels(broadcaster_ids=[...])
```

Full `fetch_users` signature:

```python
async fetch_users(
    names: Optional[List[str]] = None,
    ids: Optional[List[int]] = None,   # int in 2.10
    token: Optional[str] = None,
    force: bool = False,
) -> List[twitchio.User]
```

**Critical 2.10 → 3.x rename:** `names=` becomes `logins=`, and `ids` accepts `str` instead of `int`. Project usage: `./bot/bot.py:5664`, `./bot/bot.py:5921`.

Full method reference on the Bot/Client instance:

| Method | Returns | Notes |
| ------ | ------- | ----- |
| `fetch_users(names=None, ids=None, token=None, force=False)` | `List[User]` | `names=` = login strings; `ids=` = int list in 2.10 |
| `fetch_streams(user_ids=None, user_logins=None, game_ids=None, type='all', language=None, first=20, after=None)` | `List[Stream]` | Paginate via `after` cursor |
| `fetch_channel(broadcaster, token=None)` | `ChannelInfo` | Single channel metadata (title, game, language, delay) |
| `fetch_channels(broadcaster_ids, token=None)` | `List[ChannelInfo]` | Multiple channels |
| `fetch_games(ids=None, names=None, igdb_ids=None)` | `List[Game]` | |
| `fetch_clips(ids)` | `List[Clip]` | |
| `fetch_videos(ids=None, user_id=None, game_id=None, ...)` | `List[Video]` | |
| `fetch_global_emotes()` | `List[GlobalEmote]` | |
| `fetch_global_chat_badges()` | `List[ChatBadge]` | |
| `fetch_cheermotes(user_id=None)` | `List[CheerEmote]` | |
| `fetch_chatters_colors(user_ids, token=None)` | `List[ChatterColor]` | |
| `fetch_top_games()` | `List[Game]` | |
| `search_channels(query, live_only=False)` | `List[SearchChannel]` | |
| `search_categories(query)` | `List[Game]` | |
| `create_user(user_id, user_name)` | `PartialUser` | Build lightweight user ref — no API call |
| `get_channel(name)` | `Optional[Channel]` | Retrieve cached `Channel` by login name |
| `wait_for_ready()` | `None` | Await until bot is connected and ready (use after `run()` starts) |
| `wait_for(event, predicate=None, timeout=60.0)` | event payload | Wait for a specific dispatched event by name |

---

## 9. Key types

### `twitchio.Message`

Passed to `event_message` and accessible via `ctx.message`:

| Attribute | Description |
| --------- | ----------- |
| `message.content` | Plain text body (str) |
| `message.author` | `Chatter` — sender |
| `message.channel` | `Channel` |
| `message.echo` | bool — True if this is the bot seeing its own message |
| `message.tags` | `dict[str, str]` of raw IRC tags. Project uses `tags.get('source-room-id')` to filter shared-chat at `./bot/bot.py:1816-1821` |
| `message.timestamp` | datetime |
| `message.id` | message id (str) |
| `message.first` | bool — `True` if this is the user's first message in the channel |
| `message.hype_chat_data` | `HypeChatData \| None` — hype chat payload (`.amount`, `.currency`, `.level`, `.is_system_message`) |
| `await message.channel.send(text)` | reply via IRC (prefer Helix `send_chat_message` instead) |

### `twitchio.Chatter`

`name`, `display_name`, `id`, `is_mod`, `is_subscriber`, `is_vip`, `is_broadcaster`, `is_turbo`, `colour`/`color`, `badges` (dict).

### `twitchio.Channel`

| Member | Description |
| ------ | ----------- |
| `.name` | Channel login name (str) |
| `.users` | Cached `set[Chatter]` currently in the channel |
| `await .send(content: str)` | Send an IRC message into the channel |
| `await .whisper(content: str)` | Send an IRC whisper to a user |
| `await .user(force=False)` | Fetch the full `User` object from Helix (cached unless `force=True`) |
| `.get_chatter(name: str)` | Return cached `Chatter` or `PartialChatter` by login name, or `None` |

### `twitchio.User`

Returned by `fetch_users()`. Key attributes: `.id` (int in 2.10), `.name`, `.display_name`, `.broadcaster_type` (`'partner'`/`'affiliate'`/`''`), `.description`, `.profile_image`, `.offline_image`, `.view_count`, `.created_at`.

### `twitchio.PartialUser`

Lightweight user reference — no cache, no API call on creation. Attributes: `.id` (int/None), `.name`. Built with `bot.create_user(user_id, user_name)` or automatically when used as a command arg type hint (makes a Helix call). Use `await partial_user.user()` to fetch the full `User` object.

### `twitchio.PartialChatter`

Cache-independent chatter reference (from IRC context). Attributes: `.name`, `.channel`. Safe to use as a command arg type hint when you only need the username — unlike `Chatter`, it doesn't require the chatter to be in the cache.

**Distinction summary:**

| Type | Origin | Has full attrs? |
| ---- | ------ | --------------- |
| `Chatter` | IRC (requires cache) | Yes — badges, colour, is_mod, etc. |
| `PartialChatter` | IRC (cache-independent) | Name + channel only |
| `User` | Helix API (`fetch_users`) | Yes — broadcaster_type, description, etc. |
| `PartialUser` | `create_user()` or converter | ID + name only |

Don't compare IRC objects with API objects — their `.id` types differ (int on both in 2.10, but sourced differently).

---

## 10. Gotchas

1. **CLI args drive everything.** All three bot files take `-channel`, `-channelid`, `-token`, `-refresh`, optional `-apitoken`, `-custom`, `-botusername`, `-self`. The 2.10 bots wire `-token` into `commands.Bot(token=...)`. Don't hardcode a token; don't bypass argparse.

2. **`event_command_error` signature is `(self, ctx, error)` in 2.10** — two args after `self`. Copying a 3.x handler that uses a single `payload` arg will silently do nothing. Mirror the correct form for the version you're editing.

3. **All chat send goes through Helix.** Don't introduce `await ctx.send(...)` for production output. Use `send_chat_message(...)`.

4. **EventSub events from the TwitchIO ext never fire.** The 2.10 extension is never loaded. Routing is entirely via the hand-rolled `process_twitch_eventsub_message(...)`.

5. **Keepalive loop is project code.** The `asyncio.wait_for(ws.recv(), timeout=keepalive_timeout)` loop in `twitch_eventsub()` is project-written, not library-driven. If `keepalive_timeout` changes, update both the URL query string and the receive timeout.

6. **Shared-chat filtering reads IRC tags.** `message.tags.get('source-room-id')` at `./bot/bot.py:1817-1821` filters messages forwarded from shared-chat partner channels. In 3.x this becomes `message.source_broadcaster`.

7. **`Bot.get_context()` and `Bot.process_commands()` renamed `message` → `payload` in 2.10.0.** If you subclass Bot and override either of these methods, update the parameter name. The built-in flow is unaffected — this only matters when overriding directly.

8. **`ctx.message` can be `None` in edge cases.** Don't access `ctx.message.content` unconditionally — guard with `if ctx.message` when it matters.

9. **`retain_cache=True` is the default.** The bot caches all seen chatters and channel state in memory across reconnects. Pass `retain_cache=False` to the constructor if you need a clean slate after restart (e.g., for accurate `channel.users` counts).

---

## 11. Upstream doc anchors

- Root: <https://twitchio.dev/en/historical-2.10.0/>
- Client / dataclass reference: <https://twitchio.dev/en/historical-2.10.0/twitchio.html>, <https://twitchio.dev/en/historical-2.10.0/reference.html>
- Commands ext: <https://twitchio.dev/en/historical-2.10.0/exts/commands.html>
- Routines ext: <https://twitchio.dev/en/historical-2.10.0/exts/routines.html>
- EventSub ext (webhook + WS — **not used**): <https://twitchio.dev/en/historical-2.10.0/exts/eventsub.html>
- PubSub ext (**discontinued in 3.x**): <https://twitchio.dev/en/historical-2.10.0/exts/pubsub.html>

## 12. Cross-references

- TwitchIO 3.x reference + migration map: [TwitchIO-Stable.md](./TwitchIO-Stable.md)
- Twitch HTTP API (Helix, OAuth, EventSub topics): [twitch.md](./twitch.md)
- Bot version policy: [bot-versions.md](../../../rules/bot-versions.md)
- Bot architecture: [system_bot.md](../../../memory/system_bot.md)
